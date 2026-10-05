#!/usr/bin/env python3
"""Quick RX888 SoapySDR streaming smoke test.
Opens the device, pulls samples for ~5 seconds, reports overflow/error counts.
"""
import os
os.environ["SOAPY_SDR_PLUGIN_PATH"] = (
	"/usr/lib/aarch64-linux-gnu/SoapySDR/modules0.8:"
	"/usr/local/lib/SoapySDR/modules0.8:"
	"/usr/local/lib/aarch64-linux-gnu/SoapySDR/modules0.8"
)
import time
import numpy as np
import SoapySDR
from SoapySDR import SOAPY_SDR_RX, SOAPY_SDR_CF32

SAMPLE_RATE = 2_000_000  # conservative rate for the Pi's single-threaded FFT loop
RUN_SECONDS = 5

print(f"Opening driver=rx888 at {SAMPLE_RATE/1e6:.1f} MSps...")
# Device.make()'s implicit driver-resolution chokes when multiple factories
# (lime, remote, rx888) all match the same USB VID:PID — so enumerate
# explicitly with the driver filter and construct from that exact result
# instead of letting Device() resolve it internally.
devs = SoapySDR.Device.enumerate(dict(driver="rx888"))
if not devs:
	raise RuntimeError("No rx888 device found in filtered enumerate() — check lsusb/firmware state")
print(f"Matched device: {dict(devs[0])}")
sdr = SoapySDR.Device(devs[0])
sdr.setSampleRate(SOAPY_SDR_RX, 0, SAMPLE_RATE)
actual_rate = sdr.getSampleRate(SOAPY_SDR_RX, 0)
print(f"Device confirmed sample rate: {actual_rate/1e6:.3f} MSps")

stream = sdr.setupStream(SOAPY_SDR_RX, SOAPY_SDR_CF32)
sdr.activateStream(stream)

buff = np.zeros(8192, np.complex64)
total_samples = 0
overflows = 0
errors = 0
t0 = time.time()

while time.time() - t0 < RUN_SECONDS:
	sr = sdr.readStream(stream, [buff], len(buff), timeoutUs=1_000_000)
	if sr.ret > 0:
		total_samples += sr.ret
	elif sr.ret == -4:  # SOAPY_SDR_OVERFLOW
		overflows += 1
	elif sr.ret < 0:
		errors += 1
		print(f"  readStream error: {sr.ret}")

sdr.deactivateStream(stream)
sdr.closeStream(stream)

elapsed = time.time() - t0
print(f"\nRan {elapsed:.1f}s: {total_samples} samples "
	  f"({total_samples/elapsed/1e6:.3f} Msps actual), "
	  f"{overflows} overflows, {errors} other errors")
if overflows == 0 and errors == 0 and total_samples > 0:
	print("CLEAN STREAM — no overflows or errors.")
else:
	print("Stream had issues — see counts above.")