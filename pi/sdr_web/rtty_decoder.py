"""RigPi receive-only Baudot RTTY prototype. Copyright 2026 Howard Nurse. MIT.
48 kHz mono audio in, bounded text and measured tuning-scope points out.
No transmit, radio tuning or FT8 state is changed by this module.
"""
import collections
import math
import queue
import threading
import time
import numpy as np
from scipy import signal

LETTERS = '\x00E\nA SIU\rDRJNFCKTZLWHYPQOBG\x00MXV\x00'
# Explicit ITA2 US figures (index 11 bell, 20 #); shift codes handled separately.
FIGURES = ['','3','\n','-',' ','\x07','8','7','\r','$','4','\x07',',','!',':','(','5','"',')','2','#','6','0','1','9','?','&','','.','/',';','']

class RTTYDecoder:
    INPUT_RATE = 48000
    RATE = 6000
    def __init__(self, worker=True):
        self.lock = threading.RLock()
        self.config = dict(enabled=False, baud=45.45, shift=170.0, center=2210.0,
                           reverse=False, afc=False, squelch=0.18)
        self.text = ''
        self.revision = 0
        self.received = 0
        self.errors = 0
        self.dropped = 0
        self.last_audio = 0.0
        self.last_signal = 0.0
        self.failure = ''
        self.scope = []
        self.spectrum = []
        self.quality = 0.0
        self.offset = 0.0
        self.afc_offset = 0.0
        self.frames = 0
        self._generation = 0
        self._queue = queue.Queue(maxsize=12)  # bounded; never stall the audio producer
        self._reset_dsp()
        if worker:
            threading.Thread(target=self._worker, name='RigPi-RTTY', daemon=True).start()

    def _reset_dsp(self):
        self._anti = signal.butter(4, 2400, fs=self.INPUT_RATE, output='sos')
        self._anti_zi = np.zeros((len(self._anti), 2))
        self._decim_phase = 0
        self._tone = signal.butter(2, self.config['baud'] * .85, fs=self.RATE, output='sos')
        self._tone_zi = [np.zeros((len(self._tone), 2), dtype=complex) for _ in range(2)]
        self._phase = [0.0, 0.0]
        self._previous_z = [None, None]
        self._stream = 0
        self._was_cr = False
        self._spectrum_history = np.zeros(2048)
        self._last_bit = 1
        self._mark_run = 0
        self._frame_start = None
        self._next_sample = 0.0
        self._bits = []
        self._figures = False
        self._drop_pending = False
        self.scope = []
        self.quality = 0.0
        self.offset = 0.0

    def configure(self, changes):
        unknown = set(changes) - set(self.config) - {'clear'}
        if unknown:
            raise ValueError('Unknown RTTY option: ' + ', '.join(sorted(unknown)))
        with self.lock:
            new = dict(self.config)
            for k in ('enabled', 'reverse', 'afc'):
                if k in changes:
                    if type(changes[k]) is not bool:
                        raise ValueError(k + ' must be true or false')
                    new[k] = changes[k]
            for k, lo, hi in [('baud', 40, 100), ('shift', 85, 850),
                              ('center', 300, 2300), ('squelch', 0, .95)]:
                if k in changes:
                    try: value = float(changes[k])
                    except (TypeError, ValueError): raise ValueError(k + ' must be numeric')
                    if not math.isfinite(value) or not lo <= value <= hi:
                        raise ValueError(f'{k} must be between {lo} and {hi}')
                    new[k] = value
            if new['center'] - new['shift']/2 < 100 or new['center'] + new['shift']/2 > 2700:
                raise ValueError('Both tones must fit between 100 and 2700 Hz')
            reset = any(new[k] != self.config[k] for k in ('enabled','baud','shift','center','reverse'))
            self.config = new
            if reset:
                self._generation += 1
                self.afc_offset = 0.0
                self._reset_dsp()
                while True:
                    try: self._queue.get_nowait()
                    except queue.Empty: break
            if changes.get('clear') is True:
                self.text = ''
                self.revision += 1
            return self.status()

    def feed(self, audio):
        if not self.config['enabled']:
            return
        block = np.asarray(audio, dtype=np.float32).reshape(-1)
        if not len(block):
            return
        # Split oversized remote bursts, limiting both memory and worker latency.
        for start in range(0, len(block), 4800):
            try: self._queue.put_nowait((self._generation, block[start:start+4800].copy()))
            except queue.Full:
                self.dropped += 1
                self._drop_pending = True
                break

    def _worker(self):
        while True:
            generation, audio = self._queue.get()
            try:
                with self.lock:
                    if self.config['enabled'] and generation == self._generation:
                        if self._drop_pending:
                            self._reset_dsp()
                        self.process(audio)
                        self.failure = ''
            except Exception as exc:
                with self.lock:
                    self.failure = str(exc)[:160]
                    self._reset_dsp()

    def _character(self, code):
        if code == 27:
            self._figures = True
            return
        if code == 31:
            self._figures = False
            return
        if code == 0:
            return
        ch = FIGURES[code] if self._figures else LETTERS[code]
        # CR/LF produces one readable newline, preserving repeated LF blank lines.
        if ch == '\r':
            ch = '\n'
            self._was_cr = True
        elif ch == '\n' and getattr(self, '_was_cr', False):
            self._was_cr = False
            return
        else:
            self._was_cr = False
        if ch == '\x07':  # no browser bell or control-character injection
            return
        if ch:
            self.text = (self.text + ch)[-32000:]
            self.received += 1
            self.revision += 1

    def process(self, audio):
        """Synchronous DSP entry point also used for deterministic recording tests."""
        if not self.config['enabled']:
            return
        audio = np.nan_to_num(np.asarray(audio, dtype=float), nan=0, posinf=0, neginf=0)
        if not len(audio): return
        self.last_audio = time.monotonic()
        filtered, self._anti_zi = signal.sosfilt(self._anti, audio, zi=self._anti_zi)
        x = filtered[self._decim_phase::8]
        self._decim_phase = (self._decim_phase - len(audio)) % 8
        if not len(x): return
        center = self.config['center'] + self.afc_offset
        tones = [center + self.config['shift']/2, center - self.config['shift']/2]
        zs = []
        carriers = []
        errors = []
        n = np.arange(len(x))
        for i, hz in enumerate(tones):
            phase = self._phase[i] + 2*np.pi*hz*n/self.RATE
            self._phase[i] = (phase[-1] + 2*np.pi*hz/self.RATE) % (2*np.pi)
            z, self._tone_zi[i] = signal.sosfilt(self._tone, x*np.exp(-1j*phase), zi=self._tone_zi[i])
            zs.append(z)
            carriers.append(z*np.exp(1j*phase))
            prev = self._previous_z[i]
            lag = np.r_[z[0] if prev is None else prev, z[:-1]]
            errors.append(np.angle(z*np.conj(lag))*self.RATE/(2*np.pi))
            self._previous_z[i] = z[-1]
        energy = [np.abs(z)**2 for z in zs]
        total = energy[0] + energy[1] + 1e-15
        contrast = (energy[0] - energy[1])/total
        rms = float(np.sqrt(np.mean(x*x)))
        purity = float(np.clip(4*np.mean(total)/(np.mean(x*x)+1e-15), 0, 1))
        self.quality = float(np.mean(np.abs(contrast))) * purity
        signal_ok = rms > 1e-5 and self.quality >= self.config['squelch']
        if signal_ok: self.last_signal = self.last_audio
        self.spectrum = self._audio_spectrum(x)
        selected = np.where(energy[0] >= energy[1], 0, 1)
        dominant_error = np.where(selected == 0, errors[0], errors[1])
        confident = np.abs(contrast) > .65
        if signal_ok and confident.any():
            self.offset = float(np.median(dominant_error[confident]))
            if self.config['afc']:
                # Gentle tracking, bounded to avoid jumping onto a neighboring signal.
                correction = np.clip(self.offset, -10, 10)*len(x)/self.RATE
                self.afc_offset = float(np.clip(self.afc_offset + correction, -self.config['shift']*.45, self.config['shift']*.45))
        elif not signal_ok:
            self.offset = 0.0
        self.scope = self._scope_points(carriers, selected, errors, contrast, signal_ok)
        bit_period = self.RATE/self.config['baud']
        if not signal_ok:
            self._frame_start = None
            self._last_bit = 1
            self._mark_run = 0
            self._stream += len(x)
            return
        for j, value in enumerate(contrast):
            pos = self._stream + j
            bit = int(value >= 0) ^ int(self.config['reverse'])
            if self._frame_start is None:
                if bit == 0 and self._last_bit == 1 and self._mark_run >= bit_period*.35:
                    self._frame_start = pos
                    self._next_sample = pos + bit_period*.5
                    self._bits = []
                self._mark_run = self._mark_run + 1 if bit else 0
            if self._frame_start is not None and pos >= self._next_sample:
                if not self._bits and bit != 0:
                    self._frame_start = None
                else:
                    self._bits.append(bit)
                    self._next_sample += bit_period
                    if len(self._bits) == 7:
                        if bit == 1:
                            code = sum(b << i for i,b in enumerate(self._bits[1:6]))
                            self._character(code)
                            self.frames += 1
                        else:
                            self.errors += 1
                        self._frame_start = None
                        self._mark_run = 1 if bit else 0
            self._last_bit = bit
        self._stream += len(x)

    def _scope_points(self, carriers, selected, errors, contrast, valid):
        if not valid: return []
        points = []
        scale = max(float(max(np.max(np.abs(c)) for c in carriers)), 1e-6)
        # Trace measured filtered samples; tone offset rotates each mark/space arm.
        # This is a frequency-derived crossed-ellipse aid, not a hardware XY capture.
        for j in range(0, len(selected), max(1,len(selected)//240)):
            if abs(contrast[j]) < .45: continue
            i = selected[j]
            detune = np.clip(errors[i][j]/(self.config['shift']/2), -1, 1)
            angle = (np.pi/4 if i == 0 else -np.pi/4) + detune*np.pi/4
            carrier = carriers[i][j]/scale
            width = min(.4,abs(detune)*.3)
            u,v = carrier.real, carrier.imag*width
            points.append([round(float(u*np.cos(angle)-v*np.sin(angle)),4),
                           round(float(u*np.sin(angle)+v*np.cos(angle)),4),int(i)^int(self.config['reverse'])])
        return points[-240:]

    def _audio_spectrum(self, x):
        n = 2048
        self._spectrum_history = np.r_[self._spectrum_history, x][-n:]
        data = self._spectrum_history
        power = np.abs(np.fft.rfft(data*np.hanning(n)))
        # 0–3000 Hz, 128 bins; normalize to current spectrum peak.
        bins = np.array_split(power,128)
        db = np.array([20*np.log10(max(float(np.max(b)),1e-12)) for b in bins])
        return np.clip(db - np.max(db), -70,0).round(1).tolist()

    def status(self):
        with self.lock:
            now = time.monotonic()
            live = self.config['enabled'] and now - self.last_audio < 2
            return dict(config=dict(self.config), text=self.text, revision=self.revision,
                        received=self.received, frames=self.frames, framing_errors=self.errors,
                        dropped_blocks=self.dropped, audio_live=live,
                        signal=live and now-self.last_signal < 1,
                        quality=round(self.quality,3) if live else 0,
                        offset_hz=round(self.offset,1), afc_offset_hz=round(self.afc_offset,1),
                        scope=self.scope if live else [], spectrum=self.spectrum if live else [],
                        error=self.failure)
