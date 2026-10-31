#!/usr/bin/env python3
"""Native Hermes-Lite 2 Protocol 1 source for RigPi-SDR.

Receive is continuous.  Transmit remains silent unless explicitly armed, is
fed with 48 kS/s complex samples, and is protected by a heartbeat and maximum
duration timer inside the packet-producing thread.
"""

import socket
import struct
import threading
import time
from typing import Dict, List, Optional

import numpy as np
from hermes_external_cw import ExternalCWState


DISCOVERY_PORTS = (1025, 1024)
PROTOCOL_PORT = 1024
TX_BUFFER_LATENCY_MS = 60
COMMAND_ADDRESSES = tuple(range(17)) + (0x17,)
DISCOVERY_REQUEST = bytes((0xEF, 0xFE, 0x02)) + bytes(60)
START_RX = bytes((0xEF, 0xFE, 0x04, 0x01)) + bytes(60)
STOP = bytes((0xEF, 0xFE, 0x04, 0x00)) + bytes(60)


def _decode_discovery(packet: bytes, address: str, port: int) -> Optional[Dict]:
	if len(packet) < 20 or packet[:2] != b"\xef\xfe":
		return None
	status = int(packet[2])
	if status not in (2, 3):
		return None
	mac = ":".join(f"{byte:02x}" for byte in packet[3:9])
	major = int(packet[9]) if len(packet) > 9 else 0
	minor = int(packet[21]) if len(packet) > 21 else 0
	board_id = int(packet[10]) if len(packet) > 10 else 0
	return {
		"driver": "hermeslite",
		"label": f"Hermes Lite 2 [{address}]",
		"serial": address,
		"ip": address,
		"port": int(port),
		"mac": mac,
		"status": status,
		"streaming": status == 3,
		"gateware": f"{major}.{minor}",
		"board_id": board_id,
	}


def discover(timeout: float = 0.25, target: str = "255.255.255.255") -> List[Dict]:
	"""Discover Hermes/Hermes-Lite radios without starting or stopping one."""
	found = {}
	sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
	try:
		sock.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
		sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
		sock.bind(("", 0))
		sock.settimeout(max(0.02, float(timeout)))
		for port in DISCOVERY_PORTS:
			try:
				sock.sendto(DISCOVERY_REQUEST, (target, port))
			except OSError:
				continue
		deadline = time.monotonic() + max(0.02, float(timeout))
		while time.monotonic() < deadline:
			try:
				packet, peer = sock.recvfrom(2048)
			except socket.timeout:
				break
			info = _decode_discovery(packet, peer[0], peer[1])
			if info:
				previous = found.get(info["mac"])
				if previous is None or info["port"] == PROTOCOL_PORT:
					found[info["mac"]] = info
	finally:
		sock.close()
	return list(found.values())


class HermesLiteNativeSource:
	"""Protocol 1 receive source with guarded, half-duplex transmit support.

	One EP6 datagram contains two 512-byte USB frames.  With one receiver each
	frame carries 63 groups of signed 24-bit I, signed 24-bit Q, and 16-bit mic.
	"""

	sample_rate = 192_000.0
	supported_sample_rates = [48_000.0, 96_000.0, 192_000.0, 384_000.0]

	def __init__(self, ip: str, frequency: float = 14_074_000.0, port: int = PROTOCOL_PORT):
		self.ip = str(ip).strip()
		if not self.ip:
			raise ValueError("Hermes Lite IP address is required")
		self.port = int(port)
		self._frequency = int(round(float(frequency)))
		self._frequency_lock = threading.Lock()
		self._lna_gain_db = 20
		self._socket = None
		self._running = False
		self._command_thread = None
		self._command_sequence = 0
		self._command_address = 0
		self._pending = np.empty(0, dtype=np.complex64)
		self._last_ep6_sequence = None
		self._telemetry = {}
		self.packet_count = 0
		self.packet_drops = 0
		self._tx_lock = threading.Lock()
		self._tx_active = False
		self._tx_drive = 0
		self._tx_frequency = self._frequency
		self._tx_pending = np.empty(0, dtype=np.complex64)
		self._tx_last_heartbeat = 0.0
		self._tx_started_at = 0.0
		self._tx_maximum_seconds = 600.0
		self._tx_error = ""
		self._tx_underruns = 0
		self._cw = ExternalCWState(self._frequency)

	@property
	def frequency(self) -> int:
		with self._frequency_lock:
			return self._frequency

	def set_frequency(self, frequency: float) -> None:
		value = int(round(float(frequency)))
		if not 100_000 <= value <= 60_000_000:
			raise ValueError(f"Hermes Lite frequency out of range: {value}")
		with self._frequency_lock:
			self._frequency = value

	def set_lna_gain(self, gain_db: int) -> None:
		if type(gain_db) is not int or not -12 <= gain_db <= 48:
			raise ValueError("Hermes receive gain must be between -12 and +48 dB")
		self._lna_gain_db = gain_db

	def probe(self, timeout: float = 0.25) -> Dict:
		radios = discover(timeout=timeout, target=self.ip)
		if not radios:
			raise RuntimeError(f"No Hermes Lite response from {self.ip}")
		return radios[0]

	def start(self) -> None:
		if self._running:
			return
		info = self.probe()
		if info.get("streaming"):
			raise RuntimeError(
				f"Hermes Lite {self.ip} is already streaming to another client"
			)
		sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
		sock.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 4 * 1024 * 1024)
		sock.setsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF, 1024 * 1024)
		sock.settimeout(1.0)
		sock.bind(("", 0))
		sock.connect((self.ip, self.port))
		self._socket = sock
		self._running = True
		self._pending = np.empty(0, dtype=np.complex64)
		self._last_ep6_sequence = None
		self._command_thread = threading.Thread(
			target=self._command_loop,
			name="HermesLiteCommands",
			daemon=True,
		)
		self._command_thread.start()
		sock.send(START_RX)

	def stop(self) -> None:
		self.stop_tx()
		sock = self._socket
		self._running = False
		if sock is not None:
			try:
				sock.send(STOP)
			except OSError:
				pass
		thread = self._command_thread
		if thread is not None and thread is not threading.current_thread():
			thread.join(timeout=0.5)
		self._command_thread = None
		self._socket = None
		if sock is not None:
			try:
				sock.close()
			except OSError:
				pass

	def configure_external_cw(self, enabled, drive=32, maximum_seconds=600, frequency=None):
		with self._tx_lock:
			self._cw.configure(bool(enabled and self._running and not self._tx_active), drive, self.frequency if frequency is None else frequency, maximum_seconds)

	def start_tx(self, frequency: float, drive: int = 1,
				 maximum_seconds: float = 600.0, idle_amplitude: float = 0.0,
				 hold_phase: bool = False) -> None:
		value = int(round(float(frequency)))
		drive = int(drive)
		if not self._running:
			raise RuntimeError("Hermes Lite stream is not running")
		if not 100_000 <= value <= 60_000_000:
			raise ValueError(f"Hermes Lite TX frequency out of range: {value}")
		if not 0 <= drive <= 255:
			raise ValueError("Hermes Lite drive must be between 0 and 255")
		now = time.monotonic()
		with self._tx_lock:
			if self._tx_active:
				raise RuntimeError("Hermes Lite transmit is already active")
			self._tx_frequency = value
			self._tx_drive = drive
			self._tx_pending = np.empty(0, dtype=np.complex64)
			self._tx_last_heartbeat = now
			self._tx_started_at = now
			self._tx_maximum_seconds = max(5.0, min(3600.0, float(maximum_seconds)))
			self._tx_error = ""
			self._tx_underruns = 0
			self._tx_carrier_amplitude = float(np.clip(idle_amplitude, 0.0, 1.0))
			self._tx_hold_phase = bool(hold_phase)
			self._tx_idle_iq = complex(self._tx_carrier_amplitude)
			# Leave MOX clear while the command loop serializes the new TX
			# frequency and drive registers through a complete control cycle.
			self._tx_active = False
		time.sleep(0.06)
		with self._tx_lock:
			if not self._running:
				self._tx_drive = 0
				raise RuntimeError("Hermes Lite stream stopped while arming transmit")
			self._tx_last_heartbeat = time.monotonic()
			self._tx_started_at = self._tx_last_heartbeat
			self._tx_active = True

	def stop_tx(self, error: str = "") -> None:
		with self._tx_lock:
			self._tx_active = False
			self._cw.stop()
			self._tx_drive = 0
			self._tx_pending = np.empty(0, dtype=np.complex64)
			if error:
				self._tx_error = str(error)

	def tx_heartbeat(self) -> None:
		with self._tx_lock:
			if self._tx_active:
				self._tx_last_heartbeat = time.monotonic()

	def submit_tx_iq(self, samples: np.ndarray) -> None:
		values = np.asarray(samples, dtype=np.complex64).reshape(-1)
		if not values.size:
			return
		values = np.nan_to_num(values, nan=0.0, posinf=0.0, neginf=0.0)
		with self._tx_lock:
			if not self._tx_active:
				return
			self._tx_last_heartbeat = time.monotonic()
			self._telemetry["tx_iq_peak"] = float(np.max(np.abs(values)))
			self._telemetry["tx_iq_rms"] = float(np.sqrt(np.mean(np.abs(values) ** 2)))
			self._tx_pending = np.concatenate((self._tx_pending, values))
			# Bound latency even if the browser briefly outruns the network clock.
			if len(self._tx_pending) > 24_000:
				self._tx_pending = self._tx_pending[-24_000:]

	def _tx_snapshot(self):
		now = time.monotonic()
		with self._tx_lock:
			if self._tx_active:
				if now - self._tx_last_heartbeat > 5.0:
					self._tx_active = False
					self._tx_drive = 0
					self._tx_error = "Transmit heartbeat was lost."
				elif now - self._tx_started_at > self._tx_maximum_seconds:
					self._tx_active = False
					self._tx_drive = 0
					self._tx_error = "Transmit dead-man timer expired."
			if not self._tx_active:
				cw_ready, cw_drive, cw_frequency = self._cw.snapshot()
				if cw_ready:
					return False, cw_drive, cw_frequency
			return self._tx_active, self._tx_drive, self._tx_frequency

	def _take_tx_iq(self, count: int) -> np.ndarray:
		with self._tx_lock:
			if not self._tx_active:
				return np.zeros(count, dtype=np.complex64)
			available = min(count, len(self._tx_pending))
			result = np.zeros(count, dtype=np.complex64)
			if available:
				result[:available] = self._tx_pending[:available]
				self._tx_pending = self._tx_pending[available:]
			carrier = getattr(self, "_tx_carrier_amplitude", 0.0)
			if carrier and available and getattr(self, "_tx_hold_phase", False):
				last = result[available - 1]
				if abs(last) > 0.0:
					self._tx_idle_iq = carrier * last / abs(last)
			if available < count:
				self._tx_underruns += 1
				if carrier:
					result[available:] = self._tx_idle_iq
			return result

	def _command_data(self, address: int, tx_active: bool, tx_drive: int,
				  tx_frequency: int) -> int:
		if address == 0:
			# 192 kHz, one receiver; select the N2ADR HF low-pass filter.
			frequency = int(tx_frequency if (tx_active or tx_drive) else self.frequency)
			filter_mask = (
			    0x01 if frequency <= 2_000_000 else
			    0x02 if frequency <= 4_000_000 else
			    0x04 if frequency <= 7_300_000 else
			    0x08 if frequency <= 14_350_000 else
			    0x10 if frequency <= 21_450_000 else
			    0x20
			)
			return 0x02000000 | (filter_mask << 17)
		if address == 1:
			# Single-receiver simplex firmware uses TX1 NCO for receive too.
			return int(tx_frequency if (tx_active or tx_drive) else self.frequency) & 0xFFFFFFFF
		if address == 2:
			return self.frequency & 0xFFFFFFFF
		if address == 9:
			# C1 is the Hermes drive byte.  It may be staged briefly with MOX clear;
			# stop_tx and either watchdog always force it back to zero.
			with self._tx_lock:
				cw_ready = self._cw.snapshot()[0]
			return ((int(tx_drive) & 0xFF) << 24) | ((1 << 19) if (tx_active or cw_ready) else 0)
		if address == 16 and self._cw.requested:
			hang_ms = 250
			return ((hang_ms >> 2) << 24) | ((hang_ms & 3) << 16)
		if address == 0x17:
			# Preserve PTT hang while covering measured host scheduling gaps.
			return (12 << 8) | TX_BUFFER_LATENCY_MS
		if address == 14:
			# Hardware switches RX LNA to -12 dB during TX and restores address 10 in RX.
			return (1 << 15) | (1 << 14)
		if address == 10:
			# Hermes-Lite direct LNA control (encoded as gain + 12).
			return 0x40 | (self._lna_gain_db + 12)
		return 0

	@staticmethod
	def _encode_tx_samples(samples: np.ndarray) -> bytes:
		values = np.asarray(samples, dtype=np.complex64).reshape(63)
		i_value = np.clip(np.real(values), -0.999969, 0.999969)
		q_value = np.clip(np.imag(values), -0.999969, 0.999969)
		i16 = np.rint(i_value * 32767.0).astype(">i2")
		q16 = np.rint(q_value * 32767.0).astype(">i2")
		payload = np.zeros((63, 8), dtype=np.uint8)
		payload[:, 4:6] = i16.view(np.uint8).reshape(63, 2)
		payload[:, 6:8] = q16.view(np.uint8).reshape(63, 2)
		return payload.tobytes()

	def _usb_command_frame(self, address: int, samples: np.ndarray,
						   tx_active: bool, tx_drive: int, tx_frequency: int) -> bytes:
		command = ((int(address) & 0x7F) << 1) | (1 if tx_active else 0)
		control = bytes((command,)) + struct.pack(
			">I", self._command_data(address, tx_active, tx_drive, tx_frequency)
		)
		return b"\x7f\x7f\x7f" + control + self._encode_tx_samples(samples)

	def _next_ep2(self) -> bytes:
		tx_active, tx_drive, tx_frequency = self._tx_snapshot()
		samples = self._take_tx_iq(126) if tx_active else np.zeros(126, dtype=np.complex64)
		index = self._command_address
		first = COMMAND_ADDRESSES[index]
		second = COMMAND_ADDRESSES[(index + 1) % len(COMMAND_ADDRESSES)]
		self._command_address = (index + 2) % len(COMMAND_ADDRESSES)
		sequence = self._command_sequence & 0xFFFFFFFF
		self._command_sequence = (self._command_sequence + 1) & 0xFFFFFFFF
		return (
			b"\xef\xfe\x01\x02"
			+ struct.pack(">I", sequence)
			+ self._usb_command_frame(first, samples[:63], tx_active, tx_drive, tx_frequency)
			+ self._usb_command_frame(second, samples[63:], tx_active, tx_drive, tx_frequency)
		)

	def _command_loop(self) -> None:
		# EP2 contains 126 audio-time samples. At 48 kHz that is 381 frames/s.
		interval = 126.0 / 48_000.0
		deadline = time.monotonic()
		while self._running:
			sock = self._socket
			if sock is None:
				break
			try:
				sock.send(self._next_ep2())
			except OSError:
				if self._running:
					time.sleep(0.02)
				continue
			deadline += interval
			wait = deadline - time.monotonic()
			if wait > 0:
				time.sleep(wait)
			elif wait < -0.05:
				# Preserve the 48 kHz clock across ordinary scheduler delays.
				# Bound catch-up after long stalls.
				deadline = time.monotonic()

	@staticmethod
	def _decode_sample_block(packet: bytes, offset: int) -> np.ndarray:
		raw = np.frombuffer(packet, dtype=np.uint8, count=504, offset=offset)
		groups = raw.reshape(63, 8)
		i_value = (
			(groups[:, 0].astype(np.int32) << 16)
			| (groups[:, 1].astype(np.int32) << 8)
			| groups[:, 2].astype(np.int32)
		)
		q_value = (
			(groups[:, 3].astype(np.int32) << 16)
			| (groups[:, 4].astype(np.int32) << 8)
			| groups[:, 5].astype(np.int32)
		)
		i_value = (i_value ^ 0x800000) - 0x800000
		q_value = (q_value ^ 0x800000) - 0x800000
		scale = np.float32(1.0 / 8_388_608.0)
		return (i_value.astype(np.float32) * scale - 1j * q_value.astype(np.float32) * scale).astype(np.complex64)

	def _decode_ep6(self, packet: bytes) -> Optional[np.ndarray]:
		if len(packet) != 1032 or packet[:4] != b"\xef\xfe\x01\x06":
			return None
		if packet[8:11] != b"\x7f\x7f\x7f" or packet[520:523] != b"\x7f\x7f\x7f":
			return None
		sequence = struct.unpack_from(">I", packet, 4)[0]
		if self._last_ep6_sequence is not None:
			expected = (self._last_ep6_sequence + 1) & 0xFFFFFFFF
			if sequence != expected:
				self.packet_drops += (sequence - expected) & 0xFFFFFFFF
		self._last_ep6_sequence = sequence
		self.packet_count += 1
		with self._tx_lock:
		    for offset in (11, 523):
		        control = packet[offset]
		        if control & 0x80:
		            continue
		        self._cw.observe(control)
		        address = (control >> 3) & 0x0f
		        if address not in (0, 1, 2):
		            continue
		        data = int.from_bytes(packet[offset + 1:offset + 5], "big")
		        self._telemetry[f"control_{address}_hex"] = f"0x{data:08x}"
		        if address == 0:
		            self._telemetry["firmware_version"] = data & 0xff
		            self._telemetry["tx_inhibit_n"] = bool(data & (1 << 25))
		            self._telemetry["adc_overload"] = bool(data & (1 << 24))
		        elif address == 1:
		            self._telemetry["temperature_raw"] = data >> 16
		            self._telemetry["forward_power_raw"] = data & 0xffff
		        elif address == 2:
		            self._telemetry["reverse_power_raw"] = data >> 16
		            self._telemetry["pa_current_raw"] = data & 0xffff
		return np.concatenate((
			self._decode_sample_block(packet, 16),
			self._decode_sample_block(packet, 528),
		))

	def read(self, count: int) -> np.ndarray:
		if not self._running or self._socket is None:
			raise RuntimeError("Hermes Lite stream is not running")
		count = int(count)
		while len(self._pending) < count and self._running:
			try:
				packet = self._socket.recv(2048)
			except socket.timeout as exc:
				raise TimeoutError(f"Timed out receiving IQ from Hermes Lite {self.ip}") from exc
			samples = self._decode_ep6(packet)
			if samples is not None:
				self._pending = np.concatenate((self._pending, samples))
		if len(self._pending) < count:
			raise RuntimeError("Hermes Lite stream stopped")
		result = self._pending[:count].copy()
		self._pending = self._pending[count:]
		return result

	def stats(self) -> Dict:
		with self._tx_lock:
			tx = {
				"active": bool(self._tx_active),
				"drive": int(self._tx_drive),
				"frequency_hz": int(self._tx_frequency),
				"seconds": max(0.0, time.monotonic() - self._tx_started_at)
					if self._tx_active else 0.0,
				"maximum_seconds": float(self._tx_maximum_seconds),
				"queued_samples": int(len(self._tx_pending)),
				"underruns": int(self._tx_underruns),
				"error": self._tx_error,
                                "telemetry": dict(self._telemetry),
			}
			cw = self._cw.status()
			tx["cw"] = cw
			if cw["requested"]:
				tx.update({key: cw[key] for key in ("active", "drive", "frequency_hz", "seconds", "maximum_seconds", "error")})
				tx["mode"] = "CW"
		return {
			"packets": int(self.packet_count),
			"packet_drops": int(self.packet_drops),
			"frequency": int(self.frequency),
			"sample_rate": int(self.sample_rate),
			"running": bool(self._running),
			"tx": tx,
		}
