"""Bounded IQ FFT for SDRConnect; audio remains in SDRConnect."""
import math
import time
import threading
import numpy as np

class SDRConnectSpectrum:
    FFT_SIZE = 65536
    MAX_BINS = 4096
    INTERVAL = 0.1

    def __init__(self):
        self._lock = threading.Lock()
        self._window = np.hanning(self.FFT_SIZE).astype(np.float32)
        self._scale = float(self._window.sum())
        self.reset()

    def reset(self):
        with self._lock:
            self._geometry = None
            self._tail = b''
            self._frame = None
            self._last = -float('inf')

    def push(self, payload, rate, center, now=None, smoothing=0.0):
        now = time.monotonic() if now is None else float(now)
        rate, center = float(rate), float(center)
        if not math.isfinite(rate) or rate <= 0 or not math.isfinite(center):
            return False
        if not payload or len(payload) % 4:
            return False
        with self._lock:
            geometry = (rate, center)
            if geometry != self._geometry:
                self._geometry = geometry
                self._tail = b''
                self._frame = None
                self._last = -float('inf')
            if now - self._last < self.INTERVAL:
                # Never stitch samples across a deliberately skipped packet.
                self._tail = b''
                return False
            need = self.FFT_SIZE * 4
            block = (self._tail + payload)[-need:]
            if len(block) < need:
                self._tail = block
                return False
            self._tail = b''
            iq = np.frombuffer(block, dtype='<i2').astype(np.float32).view(np.complex64) / 32768.0
            transformed = np.fft.fftshift(np.fft.fft(iq * self._window))
            mags = (20 * np.log10(np.maximum(np.abs(transformed) / self._scale, 1e-12))).astype(np.float32)
            # Average new acquisitions once, independent of browser count.
            # Geometry changes above discard history from the previous capture.
            alpha = float(smoothing)
            alpha = min(0.95, max(0.0, alpha)) if math.isfinite(alpha) else 0.0
            if alpha > 0.0 and self._frame is not None:
                mags = alpha * self._frame[0] + (1.0 - alpha) * mags
            self._frame = (mags, rate, center, now)
            self._last = now
            return True

    def frame(self, span, center, now=None):
        now = time.monotonic() if now is None else float(now)
        with self._lock:
            frame = self._frame
        if frame is None or now - frame[3] > 1.0:
            return None
        mags, rate, hardware_center, _ = frame
        df = rate / self.FFT_SIZE
        span = min(rate, max(50000.0, float(span)))
        count = min(self.FFT_SIZE, int(math.ceil(span / df)))
        factor = 1 << max(0, int(math.ceil(math.log2(max(1, count / self.MAX_BINS)))))
        count = min(self.FFT_SIZE, int(math.ceil(count / factor)) * factor)
        midpoint = self.FFT_SIZE / 2 + (float(center) - hardware_center) / df
        start = max(0, min(self.FFT_SIZE - count, int(round(midpoint - count / 2))))
        visible = mags[start:start + count]
        if factor > 1:
            # Peak hold preserves narrow signals; report the coarser output bins honestly.
            visible = visible.reshape(-1, factor).max(axis=1)
        display_center = hardware_center + (start + count / 2 - self.FFT_SIZE / 2) * df
        return {'hw_center': display_center, 'center': display_center,
                'sample_rate': count * df, 'mags': visible.tolist(),
                'hz_per_bin': factor * df, 'zoom_active': False,
                'decimation': 1, 'spectrum_source': 'sdrconnect_iq',
                'fft_input_size': self.FFT_SIZE}
