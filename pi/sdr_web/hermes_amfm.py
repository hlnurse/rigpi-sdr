"""Hermes-Lite AM and narrowband FM transmit modulation at 48 kHz."""
import numpy as np

class AmFmModulator:
    def __init__(self, mode, amplitude):
        self.mode = str(mode).upper()
        if self.mode not in ("AM", "FM"):
            raise ValueError("Expected AM or FM")
        self.amplitude = float(np.clip(amplitude, 0.0, 1.0))
        self.phase = 0.0

    @property
    def idle_amplitude(self):
        # AM carrier is one-quarter of the equivalent full-envelope power.
        return self.amplitude * (0.5 if self.mode == "AM" else 1.0)

    def convert(self, audio):
        audio = np.asarray(audio, dtype=np.float64).reshape(-1)
        audio = np.clip(np.nan_to_num(audio, nan=0.0, posinf=1.0, neginf=-1.0), -1.0, 1.0)
        if not audio.size:
            return np.empty(0, dtype=np.complex64)
        if self.mode == "AM":
            # Leave 10% modulation headroom; envelope never crosses zero.
            return (self.idle_amplitude * (1.0 + 0.9 * audio)).astype(np.complex64)
        # NFM: maximum deviation +/-2.5 kHz, phase continuous across frames.
        phase = self.phase + (2.0 * np.pi * 2500.0 / 48000.0) * np.cumsum(audio)
        self.phase = float(phase[-1] % (2.0 * np.pi))
        return (self.amplitude * np.exp(1j * phase)).astype(np.complex64)
