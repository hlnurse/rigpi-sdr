#!/usr/bin/env python3
"""Low-level HackRF Pro transmit checkout for RigPi development.

This is intentionally not the production PTT path.  It emits nothing unless
both --transmit and --dummy-load-confirmed are supplied.  The production path
will use RigPi account permissions, its configurable dead-man timer, browser
disconnect detection, and visible PTT state.
"""

from __future__ import annotations

import argparse
import math
import sys
import time


DEFAULT_SERIAL = "0000000000000000645061de265d7313"
MAX_CHECKOUT_SECONDS = 10.0

# Conservative U.S. amateur allocations used only as a guardrail for this
# checkout utility.  The connected dummy load remains mandatory.
AMATEUR_RANGES_HZ = (
    (1_800_000, 2_000_000),
    (3_500_000, 4_000_000),
    (5_330_000, 5_407_000),
    (7_000_000, 7_300_000),
    (10_100_000, 10_150_000),
    (14_000_000, 14_350_000),
    (18_068_000, 18_168_000),
    (21_000_000, 21_450_000),
    (24_890_000, 24_990_000),
    (28_000_000, 29_700_000),
    (50_000_000, 54_000_000),
    (144_000_000, 148_000_000),
    (420_000_000, 450_000_000),
)


def in_amateur_range(frequency_hz: float) -> bool:
    return any(low <= frequency_hz <= high for low, high in AMATEUR_RANGES_HZ)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--serial", default=DEFAULT_SERIAL)
    parser.add_argument("--frequency-hz", type=float, required=True)
    parser.add_argument("--duration", type=float, default=2.0)
    parser.add_argument("--gain", type=float, default=0.0,
                        help="HackRF TX VGA gain in dB (checkout range 0-10)")
    parser.add_argument("--tone-hz", type=float, default=10_000.0,
                        help="Complex baseband tone offset from center")
    parser.add_argument("--amplitude", type=float, default=0.03,
                        help="Complex sample amplitude (0 < amplitude <= 0.10)")
    parser.add_argument("--transmit", action="store_true",
                        help="Actually open a TX stream; omission is dry-run")
    parser.add_argument("--dummy-load-confirmed", action="store_true",
                        help="Confirm the HackRF RF port is on a dummy load")
    return parser.parse_args()


def validate(args: argparse.Namespace) -> None:
    if not in_amateur_range(args.frequency_hz + args.tone_hz):
        raise ValueError("Checkout RF frequency must be inside a supported amateur allocation")
    if not (0.1 <= args.duration <= MAX_CHECKOUT_SECONDS):
        raise ValueError(f"Checkout duration must be 0.1-{MAX_CHECKOUT_SECONDS:g} seconds")
    if not (0.0 <= args.gain <= 10.0):
        raise ValueError("Checkout TX gain must be 0-10 dB")
    if not (0.0 < args.amplitude <= 0.10):
        raise ValueError("Checkout amplitude must be greater than 0 and at most 0.10")
    if abs(args.tone_hz) > 100_000:
        raise ValueError("Checkout tone offset must be within 100 kHz of center")
    if args.transmit and not args.dummy_load_confirmed:
        raise ValueError("--dummy-load-confirmed is required with --transmit")


def describe(args: argparse.Namespace) -> str:
    actual_hz = args.frequency_hz + args.tone_hz
    return (
        f"HackRF Pro checkout: center={args.frequency_hz:.0f} Hz, "
        f"test signal={actual_hz:.0f} Hz, duration={args.duration:.2f} s, "
        f"TX VGA={args.gain:.1f} dB, RF AMP=off, amplitude={args.amplitude:.3f}"
    )


def transmit(args: argparse.Namespace) -> None:
    import numpy as np
    import SoapySDR
    from SoapySDR import SOAPY_SDR_CF32, SOAPY_SDR_TX

    sample_rate = 2_000_000.0
    device = None
    stream = None
    try:
        matches = [
            item for item in SoapySDR.Device.enumerate({"driver": "hackrf"})
            if str(dict(item).get("serial", "")) == args.serial
        ]
        if not matches:
            raise RuntimeError(f"HackRF Pro serial not found: {args.serial}")
        device = SoapySDR.Device(matches[0])
        device.setSampleRate(SOAPY_SDR_TX, 0, sample_rate)
        device.setFrequency(SOAPY_SDR_TX, 0, args.frequency_hz)
        device.setGain(SOAPY_SDR_TX, 0, "VGA", args.gain)
        device.setGain(SOAPY_SDR_TX, 0, "AMP", 0.0)
        try:
            device.writeSetting("bias_tx", "false")
        except Exception:
            pass
        stream = device.setupStream(SOAPY_SDR_TX, SOAPY_SDR_CF32, [0], {"buffers": "15"})
        device.activateStream(stream)

        mtu = max(1024, int(device.getStreamMTU(stream)))
        phase = 0.0
        phase_step = 2.0 * math.pi * args.tone_hz / sample_rate
        deadline = time.monotonic() + args.duration
        sent = 0
        while time.monotonic() < deadline:
            count = min(mtu, max(1, int((deadline - time.monotonic()) * sample_rate)))
            phases = phase + phase_step * np.arange(count, dtype=np.float64)
            samples = (args.amplitude * np.exp(1j * phases)).astype(np.complex64)
            phase = float((phases[-1] + phase_step) % (2.0 * math.pi))
            result = device.writeStream(stream, [samples], count, timeoutUs=1_000_000)
            written = result.ret if hasattr(result, "ret") else int(result[0])
            if written <= 0:
                raise RuntimeError(f"HackRF TX stream write failed: {written}")
            sent += written
        print(f"Checkout complete: {sent} complex samples sent", flush=True)
    finally:
        if device is not None and stream is not None:
            try:
                device.deactivateStream(stream)
            except Exception:
                pass
            try:
                device.closeStream(stream)
            except Exception:
                pass
        if device is not None:
            try:
                SoapySDR.Device.unmake(device)
            except Exception:
                pass


def main() -> int:
    args = parse_args()
    try:
        validate(args)
    except ValueError as error:
        print(f"Refusing checkout: {error}", file=sys.stderr)
        return 2
    print(describe(args), flush=True)
    if not args.transmit:
        print("DRY RUN ONLY: no SDR device was opened and no RF was generated.", flush=True)
        return 0
    transmit(args)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
