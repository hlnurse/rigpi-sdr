#include <math.h>
#include <stdio.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

#define CIC_R 64
#define CIC_N 3
#define TWO_PI 6.283185307179586476925286766559

#define D1_TAPS 79
#define D1_R 5
#define D2_TAPS 63
#define D2_R 2
#define CH_TAPS 127

#define FS_CIC 1000000.0
#define FS_D1  200000.0
#define FS_D2  100000.0
#define FS_AUDIO 48000.0

typedef struct {
    double input_rate;
    double osc_re, osc_im;
    double step_re, step_im;
    double last_freq;
    uint64_t osc_count;

    int cic_phase;
    /* CIC arithmetic is modulo 2^64.  Unlike floating-point integrators,
       these bounded accumulators cannot lose precision as uptime grows. */
    uint64_t int_re[CIC_N], int_im[CIC_N];
    uint64_t comb_re[CIC_N], comb_im[CIC_N];

    float d1_coef[D1_TAPS];
    float d1_re[D1_TAPS], d1_im[D1_TAPS];
    int d1_pos, d1_phase;

    float d2_coef[D2_TAPS];
    float d2_re[D2_TAPS], d2_im[D2_TAPS];
    int d2_pos, d2_phase;

    float ch_coef[CH_TAPS];
    float ch_re[CH_TAPS], ch_im[CH_TAPS];
    int ch_pos;
    int last_mode;

    float dc_x1, dc_y1;
    float agc_gain;
    float agc_env;

    double rs_accum;
    float rs_prev;
    int rs_have_prev;
} rx888_audio_state;

static inline double now_ms(void)
{
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return ts.tv_sec * 1000.0 + ts.tv_nsec * 1e-6;
}

static void design_lowpass(float *coef, int taps, double fs, double cutoff) {
    const int m = taps - 1;
    double sum = 0.0;
    for (int i = 0; i < taps; ++i) {
        const double x = (double)i - 0.5 * (double)m;
        const double h = (fabs(x) < 1e-12)
            ? (2.0 * cutoff / fs)
            : sin(2.0 * M_PI * cutoff * x / fs) / (M_PI * x);
        const double w = 0.42 - 0.5 * cos(2.0 * M_PI * i / m)
                              + 0.08 * cos(4.0 * M_PI * i / m);
        coef[i] = (float)(h * w);
        sum += coef[i];
    }
    if (fabs(sum) > 1e-20) {
        for (int i = 0; i < taps; ++i) coef[i] = (float)(coef[i] / sum);
    }
}

static void reset_stream_state(rx888_audio_state *s) {
    s->osc_re = 1.0;
    s->osc_im = 0.0;
    s->osc_count = 0;
    s->cic_phase = 0;
    memset(s->int_re, 0, sizeof(s->int_re));
    memset(s->int_im, 0, sizeof(s->int_im));
    memset(s->comb_re, 0, sizeof(s->comb_re));
    memset(s->comb_im, 0, sizeof(s->comb_im));
    memset(s->d1_re, 0, sizeof(s->d1_re));
    memset(s->d1_im, 0, sizeof(s->d1_im));
    memset(s->d2_re, 0, sizeof(s->d2_re));
    memset(s->d2_im, 0, sizeof(s->d2_im));
    memset(s->ch_re, 0, sizeof(s->ch_re));
    memset(s->ch_im, 0, sizeof(s->ch_im));
    s->d1_pos = s->d1_phase = 0;
    s->d2_pos = s->d2_phase = 0;
    s->ch_pos = 0;
    s->dc_x1 = s->dc_y1 = 0.0f;
    s->agc_gain = 8.0f;
    s->agc_env = 0.01f;
    s->rs_accum = 0.0;
    s->rs_prev = 0.0f;
    s->rs_have_prev = 0;
}

rx888_audio_state *rx888_audio_create(double input_rate) {
    rx888_audio_state *s = (rx888_audio_state *)calloc(1, sizeof(*s));
    if (!s) return NULL;
    s->input_rate = input_rate;
    s->last_freq = -1.0;
    s->last_mode = -1;
    design_lowpass(s->d1_coef, D1_TAPS, FS_CIC, 30000.0);
    design_lowpass(s->d2_coef, D2_TAPS, FS_D1, 38000.0);
    design_lowpass(s->ch_coef, CH_TAPS, FS_D2, 3200.0);
    reset_stream_state(s);
    return s;
}

void rx888_audio_destroy(rx888_audio_state *s) { free(s); }

void rx888_audio_reset(rx888_audio_state *s) {
    if (!s) return;
    s->last_freq = -1.0;
    s->last_mode = -1;
    reset_stream_state(s);
}

static void set_frequency(rx888_audio_state *s, double freq) {
    if (fabs(freq - s->last_freq) <= 0.5) return;
    const double angle = -TWO_PI * freq / s->input_rate;
    s->step_re = cos(angle);
    s->step_im = sin(angle);
    s->last_freq = freq;
    reset_stream_state(s);
}

static void set_mode(rx888_audio_state *s, int mode) {
    if (mode == s->last_mode) return;
    double cutoff = 3200.0;
    if (mode == 3) cutoff = 6000.0;       /* AM */
    else if (mode == 0 || mode == 1) cutoff = 3200.0; /* USB/LSB */
    else cutoff = 1200.0;                 /* CW/CWR */
    design_lowpass(s->ch_coef, CH_TAPS, FS_D2, cutoff);
    memset(s->ch_re, 0, sizeof(s->ch_re));
    memset(s->ch_im, 0, sizeof(s->ch_im));
    s->ch_pos = 0;
    s->dc_x1 = s->dc_y1 = 0.0f;
    s->agc_env = 0.01f;
    s->agc_gain = 8.0f;
    s->rs_accum = 0.0;
    s->rs_have_prev = 0;
    s->last_mode = mode;
}

static inline void fir_decim_push(
    float xr, float xi,
    float *hist_re, float *hist_im, int taps, int *pos,
    const float *coef, int decim, int *phase,
    float *yr, float *yi, int *have_output
) {
    hist_re[*pos] = xr;
    hist_im[*pos] = xi;
    *pos = (*pos + 1) % taps;
    *phase += 1;
    if (*phase < decim) {
        *have_output = 0;
        return;
    }
    *phase = 0;
    double ar = 0.0, ai = 0.0;
    int idx = *pos;
    for (int t = 0; t < taps; ++t) {
        idx = (idx - 1 + taps) % taps;
        ar += (double)hist_re[idx] * coef[t];
        ai += (double)hist_im[idx] * coef[t];
    }
    *yr = (float)ar;
    *yi = (float)ai;
    *have_output = 1;
}

static inline void channel_filter(rx888_audio_state *s, float xr, float xi, float *yr, float *yi) {
    s->ch_re[s->ch_pos] = xr;
    s->ch_im[s->ch_pos] = xi;
    s->ch_pos = (s->ch_pos + 1) % CH_TAPS;
    double ar = 0.0, ai = 0.0;
    int idx = s->ch_pos;
    for (int t = 0; t < CH_TAPS; ++t) {
        idx = (idx - 1 + CH_TAPS) % CH_TAPS;
        ar += (double)s->ch_re[idx] * s->ch_coef[t];
        ai += (double)s->ch_im[idx] * s->ch_coef[t];
    }
    *yr = (float)ar;
    *yi = (float)ai;
}

static inline float demod_and_level(rx888_audio_state *s, float re, float im, int mode) {
    float x;
    float y;
    if (mode == 3) {
        x = hypotf(re, im);
    } else {
        x = re;
    }
    /* Remove carrier/DC. About 4.8 Hz corner at 100 kSPS. */
    y = x - s->dc_x1 + 0.9997f * s->dc_y1;
    s->dc_x1 = x;
    s->dc_y1 = y;
    const float a = fabsf(y);
    const float env_alpha = (a > s->agc_env) ? 0.010f : 0.00020f;
    s->agc_env += env_alpha * (a - s->agc_env);
    float target = 0.18f / fmaxf(s->agc_env, 1e-5f);
    if (target > 60.0f) target = 60.0f;
    if (target < 0.05f) target = 0.05f;
    const float gain_alpha = (target < s->agc_gain) ? 0.010f : 0.00020f;
    s->agc_gain += gain_alpha * (target - s->agc_gain);
    if (mode == 3) {
        return tanhf(y * s->agc_gain);
    }

     return tanhf(y * s->agc_gain);
}

static inline void resample_100k_to_48k(rx888_audio_state *s, float current, float *out, int out_cap, int *produced) {
    if (!s->rs_have_prev) {
        s->rs_prev = current;
        s->rs_have_prev = 1;
        return;
    }
    const double before = s->rs_accum;
    s->rs_accum += FS_AUDIO;
    if (s->rs_accum >= FS_D2 && *produced < out_cap) {
        const double fraction = (FS_D2 - before) / FS_AUDIO;
        const double f = fmin(1.0, fmax(0.0, fraction));
        out[(*produced)++] = (float)((1.0 - f) * s->rs_prev + f * current);
        s->rs_accum -= FS_D2;
    }
    s->rs_prev = current;
}

int rx888_audio_process(rx888_audio_state *s, const float *in, int n, double freq, int mode, float *out, int out_cap) {
    if (!s || !in || !out || n <= 0 || out_cap <= 0) return 0;

    const double process_start_ms = now_ms();
    set_frequency(s, freq);
    set_mode(s, mode);

    /* Quantize mixed IQ before the CIC and use modulo integer arithmetic.
       The comb output is converted back to float after removing CIC gain. */
    const double input_scale = 1048576.0; /* 2^20 */
    const double cic_scale = 1.0 / (input_scale * (double)(CIC_R * CIC_R * CIC_R));
    int produced = 0;

    /*
     * Sampled profiler. Timing every DSP call would add significant overhead,
     * so one CIC output in every 256 is measured and the measured average is
     * multiplied by the actual number of calls in this block.
     */
    uint64_t cic_outputs = 0;
    uint64_t d1_calls = 0, d2_calls = 0, ch_calls = 0;
    uint64_t demod_calls = 0, resamp_calls = 0;
    uint64_t d1_samples = 0, d2_samples = 0, ch_samples = 0;
    uint64_t demod_samples = 0, resamp_samples = 0;
    double d1_sample_ms = 0.0, d2_sample_ms = 0.0, ch_sample_ms = 0.0;
    double demod_sample_ms = 0.0, resamp_sample_ms = 0.0;

    for (int k = 0; k < n; ++k) {
        const double xr = (double)in[k] * s->osc_re;
        const double xi = (double)in[k] * s->osc_im;
        const double nr = s->osc_re * s->step_re - s->osc_im * s->step_im;
        const double ni = s->osc_re * s->step_im + s->osc_im * s->step_re;
        s->osc_re = nr;
        s->osc_im = ni;
        if ((++s->osc_count & 16383u) == 0) {
            const double norm = 1.0 / sqrt(s->osc_re * s->osc_re + s->osc_im * s->osc_im);
            s->osc_re *= norm;
            s->osc_im *= norm;
        }

        const int64_t qr = (int64_t)llrint(xr * input_scale);
        const int64_t qi = (int64_t)llrint(xi * input_scale);
        s->int_re[0] += (uint64_t)qr;
        s->int_im[0] += (uint64_t)qi;
        for (int j = 1; j < CIC_N; ++j) {
            s->int_re[j] += s->int_re[j - 1];
            s->int_im[j] += s->int_im[j - 1];
        }

        if (++s->cic_phase != CIC_R) continue;
        s->cic_phase = 0;
        ++cic_outputs;

        uint64_t cr_u = s->int_re[CIC_N - 1];
        uint64_t ci_u = s->int_im[CIC_N - 1];
        for (int j = 0; j < CIC_N; ++j) {
            const uint64_t tr = cr_u - s->comb_re[j];
            const uint64_t ti = ci_u - s->comb_im[j];
            s->comb_re[j] = cr_u;
            s->comb_im[j] = ci_u;
            cr_u = tr;
            ci_u = ti;
        }

        /* Reinterpret modulo results as signed two's-complement values. */
        int64_t cr_i, ci_i;
        memcpy(&cr_i, &cr_u, sizeof(cr_i));
        memcpy(&ci_i, &ci_u, sizeof(ci_i));

        /* Profile a sparse, rotating subset of CIC outputs. */
        const int profile_this = ((cic_outputs & 255u) == 0u);
        double stage_start_ms = 0.0;

        float d1r, d1i;
        int have1;
        ++d1_calls;
        if (profile_this) stage_start_ms = now_ms();
        fir_decim_push((float)((double)cr_i * cic_scale),
                       (float)((double)ci_i * cic_scale),
                       s->d1_re, s->d1_im, D1_TAPS, &s->d1_pos,
                       s->d1_coef, D1_R, &s->d1_phase, &d1r, &d1i, &have1);
        if (profile_this) {
            d1_sample_ms += now_ms() - stage_start_ms;
            ++d1_samples;
        }

        if (!have1) continue;

        float d2r, d2i;
        int have2;
        ++d2_calls;
        if (profile_this) stage_start_ms = now_ms();
        fir_decim_push(d1r, d1i,
                       s->d2_re, s->d2_im, D2_TAPS, &s->d2_pos,
                       s->d2_coef, D2_R, &s->d2_phase, &d2r, &d2i, &have2);
        if (profile_this) {
            d2_sample_ms += now_ms() - stage_start_ms;
            ++d2_samples;
        }

        if (!have2) continue;

        float chr, chi;
        ++ch_calls;
        if (profile_this) stage_start_ms = now_ms();
        channel_filter(s, d2r, d2i, &chr, &chi);
        if (profile_this) {
            ch_sample_ms += now_ms() - stage_start_ms;
            ++ch_samples;
        }

        ++demod_calls;
        if (profile_this) stage_start_ms = now_ms();
        const float audio = demod_and_level(s, chr, chi, mode);
        if (profile_this) {
            demod_sample_ms += now_ms() - stage_start_ms;
            ++demod_samples;
        }

        ++resamp_calls;
        if (profile_this) stage_start_ms = now_ms();
        resample_100k_to_48k(s, audio, out, out_cap, &produced);
        if (profile_this) {
            resamp_sample_ms += now_ms() - stage_start_ms;
            ++resamp_samples;
        }
    }

    const double process_total_ms = now_ms() - process_start_ms;

    /* Estimate full-block stage times from the sparse measurements. */
    const double d1_ms = d1_samples ? (d1_sample_ms / (double)d1_samples) * (double)d1_calls : 0.0;
    const double d2_ms = d2_samples ? (d2_sample_ms / (double)d2_samples) * (double)d2_calls : 0.0;
    const double ch_ms = ch_samples ? (ch_sample_ms / (double)ch_samples) * (double)ch_calls : 0.0;
    const double demod_ms = demod_samples ? (demod_sample_ms / (double)demod_samples) * (double)demod_calls : 0.0;
    const double resamp_ms = resamp_samples ? (resamp_sample_ms / (double)resamp_samples) * (double)resamp_calls : 0.0;
    double mix_cic_ms = process_total_ms - d1_ms - d2_ms - ch_ms - demod_ms - resamp_ms;
    if (mix_cic_ms < 0.0) mix_cic_ms = 0.0;

    /* Log at most once per second across all calls. */
    static double last_timing_log_ms = 0.0;
    const double log_now_ms = now_ms();
    if (log_now_ms - last_timing_log_ms >= 1000.0) {
        last_timing_log_ms = log_now_ms;
        fprintf(stderr,
                "[RX888 C TIMING] MIX+CIC=%6.3f D1=%6.3f D2=%6.3f "
                "CH=%6.3f DEMOD=%6.3f RESAMP=%6.3f TOTAL=%6.3f "
                "in=%d out=%d\n",
                mix_cic_ms, d1_ms, d2_ms, ch_ms, demod_ms,
                resamp_ms, process_total_ms, n, produced);
        fflush(stderr);
    }

    return produced;
}
