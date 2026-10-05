#!/usr/bin/env python3
from pathlib import Path
import shutil, sys, time

def fail(msg):
    raise SystemExit('ERROR: ' + msg)

if len(sys.argv) != 2:
    fail('Usage: add_rx888_flatten.py /path/to/sdr_web_server_minimal.py')

path = Path(sys.argv[1]).resolve()
if not path.is_file():
    fail(f'File not found: {path}')

text = path.read_text(encoding='utf-8')
if 'def _flatten_rx888_spectrum(' in text:
    fail('RX888 flattening is already present.')

a = '        self._fft_count  = 0\n'
b = '''        self._fft_count  = 0

        # RX888 display-only broad-shape flattening.
        self.rx888_flatten_enabled = (
            os.environ.get("RX888_FLATTEN", "1").strip().lower()
            not in ("0", "false", "no", "off")
        )
        try:
            self.rx888_flatten_strength = max(
                0.0,
                min(1.0, float(os.environ.get("RX888_FLATTEN_STRENGTH", "0.85"))),
            )
        except (TypeError, ValueError):
            self.rx888_flatten_strength = 0.85
'''
if a not in text:
    fail('Could not find FFT counter anchor.')
text = text.replace(a, b, 1)

anchor = '    def _latest_zoom_frame(self):\n'
method = '''    @staticmethod
    def _rx888_broad_curve(values, window_bins):
        """Return a broad, edge-safe response curve."""
        values = np.asarray(values, dtype=np.float32)
        count = int(values.size)
        if count < 16:
            return values.copy()

        window = int(max(9, min(window_bins, count - 1)))
        if window % 2 == 0:
            window += 1
        if window >= count:
            window = count - 1 if count % 2 == 0 else count - 2
        window = max(9, window)

        kernel = np.full(window, 1.0 / float(window), dtype=np.float32)
        pad = window // 2
        curve = values
        for _ in range(3):
            padded = np.pad(curve, (pad, pad), mode="edge")
            curve = np.convolve(padded, kernel, mode="valid").astype(
                np.float32, copy=False
            )
        return curve

    def _flatten_rx888_spectrum(self, mags):
        """Remove slowly varying, stationary RX888 display artifacts.

        Display only. Audio and raw samples are unchanged.
        """
        if (
            self.device_driver != "rx888"
            or not self.rx888_flatten_enabled
            or self.rx888_flatten_strength <= 0.0
        ):
            return mags

        values = np.asarray(mags, dtype=np.float32)
        count = len(values)

        if count < 64:
            return values

        geometry = (
            bool(self.zoom_active),
            int(self.zoom_decimation),
            round(float(self.zoom_center_hz), 1),
            round(float(self.span), 1),
            count,
        )

        # Start a fresh learned response whenever center/span/zoom changes.
        if getattr(self, "_rx888_flatten_geometry", None) != geometry:
            self._rx888_flatten_geometry = geometry
            self._rx888_flatten_baseline = values.copy()
            self._rx888_flatten_frames = 0
            return values

        baseline = self._rx888_flatten_baseline
        self._rx888_flatten_frames += 1

        # Learn quickly for the first few seconds, then very slowly.
        if self._rx888_flatten_frames < 100:
            alpha = 0.04
        else:
            alpha = 0.001

        # Estimate the local floor so strong signals are not learned into baseline.
        window = 31
        kernel = np.full(window, 1.0 / window, dtype=np.float32)
        padded = np.pad(values, window // 2, mode="edge")
        local = np.convolve(padded, kernel, mode="valid")

        # Only learn bins close to the surrounding floor.
        learn_mask = values < (local + 5.0)

        baseline[learn_mask] = (
            (1.0 - alpha) * baseline[learn_mask]
            + alpha * values[learn_mask]
        )

        self._rx888_flatten_baseline = baseline

        # Remove the learned shape but retain the overall median noise level.
        reference = float(np.median(baseline))
        correction = np.clip(baseline - reference, -18.0, 18.0)

        corrected = (
            values
            - self.rx888_flatten_strength * correction
        )

        return corrected.astype(np.float32, copy=False)

'''
if anchor not in text:
    fail('Could not find _latest_zoom_frame anchor.')
text = text.replace(anchor, method + anchor, 1)

a = '''                self._fft_count += 1

                # Update latest spectrum every three acquisition frames.
'''
b = '''                # RX888 display-only broad response correction.
                if self.device_driver == "rx888":
                    mags = self._flatten_rx888_spectrum(mags)

                self._fft_count += 1

                # Update latest spectrum every three acquisition frames.
'''
if a not in text:
    fail('Could not find FFT publication anchor.')
text = text.replace(a, b, 1)

a = '                "rx888_audio_overruns": int(self.rx888_audio_overruns),\n'
b = '''                "rx888_audio_overruns": int(self.rx888_audio_overruns),
                "rx888_flatten_enabled": bool(self.rx888_flatten_enabled),
                "rx888_flatten_strength": float(self.rx888_flatten_strength),
'''
if a in text:
    text = text.replace(a, b, 1)

stamp = time.strftime('%Y%m%d-%H%M%S')
backup = path.with_name(path.name + f'.before-rx888-flatten-{stamp}')
shutil.copy2(path, backup)
path.write_text(text, encoding='utf-8')

print('Patched:', path)
print('Backup: ', backup)
print('Defaults: RX888_FLATTEN=1, RX888_FLATTEN_STRENGTH=0.85')
print('Disable for comparison with RX888_FLATTEN=0')
