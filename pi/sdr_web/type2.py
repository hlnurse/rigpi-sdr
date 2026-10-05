#!/usr/bin/env python3

import asyncio
import json
import struct
import numpy as np
import websockets


URI = "ws://127.0.0.1:5454/"


async def send(ws, event_type, value="", property_name=""):
	await ws.send(json.dumps({
		"event_type": event_type,
		"property": property_name,
		"value": str(value),
	}))


async def main():
	async with websockets.connect(
		URI,
		open_timeout=5,
		ping_interval=None,
	) as ws:
		# Ask for the exact ordered device list.
		await send(
			ws,
			"get_property",
			property_name="valid_devices",
		)

		devices = []

		while not devices:
			message = await asyncio.wait_for(ws.recv(), timeout=5)

			if not isinstance(message, str):
				continue

			data = json.loads(message)

			if data.get("property") == "valid_devices":
				devices = [
					item.strip()
					for item in data.get("value", "").split(",")
					if item.strip()
				]

		for index, name in enumerate(devices):
			print(f"{index}: {name}")

		full_iq_index = next(
			(
				index
				for index, name in enumerate(devices)
				if "Full IQ" in name
			),
			None,
		)

		if full_iq_index is None:
			raise RuntimeError("No Full IQ device entry found")

		print(
			f"Selecting index {full_iq_index}: "
			f"{devices[full_iq_index]}"
		)

		# Select the Full IQ entry by its actual list index.
		await send(
			ws,
			"selected_device",
			full_iq_index,
		)
		await asyncio.sleep(0.5)

		# Start the selected hardware stream.
		await send(
			ws,
			"device_stream_enable",
			"true",
		)
		await asyncio.sleep(0.5)

		# Begin at a known-safe rate.
		await send(
			ws,
			"set_property",
			500000,
			property_name="device_sample_rate",
		)
		await asyncio.sleep(0.5)

		# Request raw primary-device IQ.
		await send(
			ws,
			"iq_stream_enable",
			"true",
		)

		print("IQ requested; waiting for binary type 2...")

		async for message in ws:
			if not isinstance(message, bytes) or len(message) < 2:
				continue

			message_type = struct.unpack_from("<H", message, 0)[0]
			payload_size = len(message) - 2

			print(
				f"type={message_type} "
				f"payload={payload_size} bytes"
			)

			if message_type == 2:
				payload = message[2:]

				# Type 2 is signed little-endian int16:
				# I0, Q0, I1, Q1, ...
				raw = np.frombuffer(payload, dtype="<i2")

				if raw.size < 2:
					continue

				# Ensure complete I/Q pairs.
				if raw.size & 1:
					raw = raw[:-1]

				i_samples = raw[0::2].astype(np.float32)
				q_samples = raw[1::2].astype(np.float32)

				iq = (
					i_samples + 1j * q_samples
				).astype(np.complex64) / 32768.0

				print(
					f"SUCCESS: payload={payload_size} bytes, "
					f"complex_samples={len(iq)}"
				)

				print(
					"IQ range: "
					f"I={int(i_samples.min())}..{int(i_samples.max())}, "
					f"Q={int(q_samples.min())}..{int(q_samples.max())}"
				)

				fft_size = min(16384, len(iq))

				# Use an exact power-of-two FFT length.
				fft_size = 1 << (fft_size.bit_length() - 1)

				# Remove DC and apply a Hann window.
				block = iq[:fft_size].copy()
				block -= np.mean(block)

				window = np.hanning(fft_size).astype(np.float32)
				coherent_gain = float(np.sum(window))

				spectrum = np.fft.fftshift(
					np.fft.fft(block * window)
				)

				mags_db = (
					20.0 * np.log10(
						np.abs(spectrum) / max(coherent_gain, 1.0)
						+ 1e-12
					)
				).astype(np.float32)

				sample_rate = 62500.0
				hz_per_bin = sample_rate / fft_size

				print(
					f"FFT complete: size={fft_size}, "
					f"sample_rate={sample_rate:.0f} Hz, "
					f"resolution={hz_per_bin:.6f} Hz/bin"
				)

				print(
					f"FFT levels: min={float(mags_db.min()):.2f} dB, "
					f"median={float(np.median(mags_db)):.2f} dB, "
					f"max={float(mags_db.max()):.2f} dB"
				)

				# Show the ten strongest bins, excluding several bins around DC.
				center_bin = fft_size // 2
				search_mags = mags_db.copy()

				dc_guard = 4
				search_mags[
					center_bin - dc_guard:
					center_bin + dc_guard + 1
				] = -999.0

				strongest = np.argpartition(
					search_mags,
					-10,
				)[-10:]

				strongest = strongest[
					np.argsort(search_mags[strongest])[::-1]
				]

				print("Strongest FFT bins:")

				for bin_index in strongest:
					offset_hz = (
						bin_index - center_bin
					) * hz_per_bin

					print(
						f"  bin={bin_index:5d} "
						f"offset={offset_hz:+10.2f} Hz "
						f"level={mags_db[bin_index]:8.2f} dB"
					)

				break


asyncio.run(main())