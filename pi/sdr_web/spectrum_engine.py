#!/usr/bin/env python3
"""Reusable high-resolution spectrum processing for RigPi SDR.

Shared high-resolution zoom FFT for RX888 real ADC samples and Airspy HF+
complex IQ. The processor digitally translates the selected band to complex
baseband, filters and decimates it, then computes a high-resolution FFT.
"""

from __future__ import annotations

import math
import time
from dataclasses import dataclass
from typing import Optional

import numpy as np
import scipy.signal


@dataclass(frozen=True)
class SpectrumFrame:
    mags: np.ndarray
    center_hz: float
    sample_rate: float
    fft_size: int
    decimation: int
    process_ms: float

    @property
    def hz_per_bin(self) -> float:
        return float(self.sample_rate) / float(self.fft_size)


class ZoomSpectrumProcessor:
    """Streaming DDC/decimation FFT for real ADC or complex IQ input."""

    def __init__(
        self,
        input_rate: float,
        fft_size: int = 4096,
        max_zoom_span: float = 2_000_000.0,
        min_zoom_span: float = 25_000.0,
        max_decimation: int = 1024,
        input_is_complex: bool = False,
    ) -> None:
        self.input_rate = float(input_rate)
        self.fft_size = int(fft_size)
        self.max_zoom_span = float(max_zoom_span)
        self.min_zoom_span = float(min_zoom_span)
        self.max_decimation = max(1, int(max_decimation))
        self.input_is_complex = bool(input_is_complex)
        self._phase = 0.0
        self._window = np.hanning(self.fft_size).astype(np.float32)
        self._last_center: Optional[float] = None
        self._last_decimation: Optional[int] = None
        self._reduced_buffer = np.empty(0, dtype=np.complex64)
        self._mixed_buffer = np.empty(0, dtype=np.complex64)
        self._decim_taps = None
        self._decim_zi = None
        self._decim_phase = 0
        self._decim_filter_for = None
    def can_process(self, center_hz, requested_span):
        requested_span = float(requested_span)

        if self.input_is_complex:
            return (
                self.min_zoom_span <= requested_span
                <= self.max_zoom_span
            )

        center_hz = float(center_hz)
        nyquist = self.input_rate / 2.0
        half_span = requested_span / 2.0

        return (
            self.min_zoom_span <= requested_span <= self.max_zoom_span
            and center_hz - half_span >= 0.0
            and center_hz + half_span <= nyquist
        )

    def _select_decimation(self, requested_span: float) -> int:
        """Choose a power-of-two decimation with useful filter guard bandwidth.

        Keeping the reduced rate at least 1.5x the visible span avoids asking
        the polyphase transition band to sit directly on the display edge. It
        also reduces the number of acquisition blocks needed for one FFT, which
        improves narrow-span update rate.
        """
        requested_span = max(self.min_zoom_span, float(requested_span))
        guard_ratio = 1.5
        maximum = max(1, int(self.input_rate // (requested_span * guard_ratio)))
        decimation = 1 << int(math.floor(math.log2(maximum)))
        return max(1, min(decimation, self.max_decimation))

    def reset(self) -> None:
        self._phase = 0.0
        self._last_center = None
        self._last_decimation = None
        self._reduced_buffer = np.empty(0, dtype=np.complex64)
        self._mixed_buffer = np.empty(0, dtype=np.complex64)
        self._decim_taps = None
        self._decim_zi = None
        self._decim_phase = 0
        self._decim_filter_for = None
    def process(
        self,
        samples: np.ndarray,
        center_hz: float,
        requested_span: float,
        input_center_hz: float = 0.0,
    ) -> Optional[SpectrumFrame]:
        started_at = time.perf_counter()
        center_hz = float(center_hz)
        requested_span = float(requested_span)
        input_center_hz = float(input_center_hz)

        # RX888 real samples use an absolute 0-Hz input reference. Airspy
        # complex IQ is already centered on input_center_hz, so only the
        # offset between requested and hardware center is mixed to DC.
        mixer_hz = center_hz - input_center_hz
        if self.input_is_complex:
            nyquist = self.input_rate / 2.0
            half_span = requested_span / 2.0
            if not (
                self.min_zoom_span <= requested_span <= self.max_zoom_span
                and abs(mixer_hz) + half_span <= nyquist
            ):
                return None
            source = np.asarray(samples, dtype=np.complex64).reshape(-1)
        else:
            if not self.can_process(center_hz, requested_span):
                return None
            source = np.asarray(samples, dtype=np.float32).reshape(-1)

        decimation = self._select_decimation(requested_span)

        output_rate = self.input_rate / decimation

        if (
            self._last_center is None
            or abs(mixer_hz - self._last_center) > 0.5
            or decimation != self._last_decimation
        ):
            print(
                "[ZOOM RESET]",
                "mixer=", mixer_hz,
                "last=", self._last_center,
                "decim=", decimation,
                "last_decim=", self._last_decimation,
                flush=True,
            )
            self._phase = 0.0
            self._last_center = mixer_hz
            self._last_decimation = decimation
            self._reduced_buffer = np.empty(0, dtype=np.complex64)
            self._mixed_buffer = np.empty(0, dtype=np.complex64)

            self._decim_taps = None
            self._decim_zi = None
            self._decim_phase = 0
            self._decim_filter_for = None

        if abs(mixer_hz) < 0.5:
            mixed = source.astype(
                np.complex64,
                copy=False,
            )
        else:
            sample_index = np.arange(
                source.size,
                dtype=np.float64,
            )

            phase_step = (
                -2.0 * np.pi * mixer_hz / self.input_rate
            )

            phase = self._phase + phase_step * sample_index

            oscillator = np.exp(
                1j * phase
            ).astype(np.complex64)

            mixed = (
                source.astype(
                    np.complex64,
                    copy=False,
                )
                * oscillator
            )

            self._phase = float(
                (
                    self._phase
                    + phase_step * source.size
                )
                % (2.0 * np.pi)
            )


        # Stateful streaming decimator.
        #
        # resample_poly() operates on each supplied block independently. RTL-SDR
        # supplies relatively small blocks, which caused repeated filter transients
        # and the visible spectral comb. Keep both FIR history and decimation phase
        # continuous across acquisition blocks instead.

        if decimation > 1:
            reduced = scipy.signal.resample_poly(
                mixed,
                up=1,
                down=decimation,
                window=("kaiser", 9.0),
            ).astype(np.complex64, copy=False)
        else:
            reduced = mixed.astype(
                np.complex64,
                copy=False,
            )

        if self._reduced_buffer.size:
            reduced = np.concatenate(
                (self._reduced_buffer, reduced)
            )

        if reduced.size < self.fft_size:
            self._reduced_buffer = reduced.copy()
            return None

        chunk = reduced[-self.fft_size:].copy()
        self._reduced_buffer = np.empty(0, dtype=np.complex64)
        spectrum = np.fft.fftshift(np.fft.fft(chunk * self._window))

        requested_span = min(requested_span, float(output_rate))
        keep_bins = self.fft_size

        if keep_bins < self.fft_size:
            start_bin = (self.fft_size - keep_bins) // 2
            spectrum = spectrum[start_bin : start_bin + keep_bins]

        coherent_gain = max(float(np.sum(self._window)), 1.0)
        mags = (20.0 * np.log10(np.abs(spectrum) / coherent_gain + 1e-12)).astype(
            np.float32
        )
        represented_span = float(output_rate) * float(len(mags)) / float(self.fft_size)
        return SpectrumFrame(
            mags=mags,
            center_hz=center_hz,
            sample_rate=represented_span,
            fft_size=len(mags),
            decimation=decimation,
            process_ms=(time.perf_counter() - started_at) * 1000.0,
        )
