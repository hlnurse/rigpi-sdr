#!/usr/bin/env python3

import subprocess
import sys
import threading
import time
from pathlib import Path

import numpy as np


class RX888NativeSource:
    PROGRAM = Path("/home/pi/SoapyRX888/build/rx888_tools/rx888_stream")
    FIRMWARE = Path(
        "/home/pi/SoapyRX888/build/rx888_tools/firmware/SDDC_FX3.img"
    )

    USB_ID_RUNNING = "04b4:00f1"
    USB_ID_BOOTLOADER = "04b4:00f3"

    def __init__(
        self,
        sample_rate=64_000_000,
        gain_mode="high",
        gain=40,
        attenuation=0,
    ):
        self.sample_rate = int(sample_rate)
        self.gain_mode = str(gain_mode)
        self.gain = int(gain)
        self.attenuation = int(attenuation)

        self.process = None
        self.lock = threading.RLock()

    @staticmethod
    def _log(message):
        print(f"[RX888 FW] {message}", file=sys.stderr, flush=True)

    @staticmethod
    def _usb_present(usb_id):
        try:
            result = subprocess.run(
                ["lsusb", "-d", usb_id],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                timeout=2.0,
                check=False,
            )
        except (OSError, subprocess.TimeoutExpired):
            return False
        return result.returncode == 0

    def usb_state(self):
        if self._usb_present(self.USB_ID_RUNNING):
            return "running"
        if self._usb_present(self.USB_ID_BOOTLOADER):
            return "bootloader"
        return "missing"

    def _validate_files(self):
        if not self.PROGRAM.is_file():
            raise RuntimeError(f"RX888 streamer not found: {self.PROGRAM}")
        if not self.FIRMWARE.is_file():
            raise RuntimeError(f"RX888 firmware not found: {self.FIRMWARE}")

    def _command(self):
        return [
            str(self.PROGRAM),
            "--firmware",
            str(self.FIRMWARE),
            "--samplerate",
            str(self.sample_rate),
            "--gainmode",
            self.gain_mode,
            "--gain",
            str(self.gain),
            "--att",
            str(self.attenuation),
            "--rand",
            "--dither",
        ]

    def set_gain(self, gain, settle_seconds=0.35):
        """Apply RX888 gain by restarting the native streamer."""

        new_gain = int(round(float(gain)))

        with self.lock:
            if new_gain == self.gain and self.process is not None:
                return self.gain

            old_gain = self.gain
            self.gain = new_gain

            try:
                self._log(
                    f"changing gain {old_gain} -> {new_gain}; "
                    "restarting native stream"
                )

                self.stop()
                time.sleep(float(settle_seconds))
                self.start()

                self._log(f"gain now {self.gain}")
                return self.gain

            except Exception:
                self.gain = old_gain

                try:
                    self.stop()
                    time.sleep(float(settle_seconds))
                    self.start()
                except Exception:
                    pass

                raise

    def _launch(self):
        self.process = subprocess.Popen(
            self._command(),
            stdout=subprocess.PIPE,
            stderr=None,  # diagnostics go to the systemd journal
            bufsize=0,
        )
        if self.process.stdout is None:
            self.stop()
            raise RuntimeError("RX888 streamer did not create stdout")

    def start(self):
        """Start streaming, uploading FX3 firmware first when required.

        rx888_stream accepts --firmware and performs the upload when the device
        is present as Cypress PID 00f3. Some builds exit once after the USB
        device re-enumerates as PID 00f1, so this method permits one automatic
        relaunch after firmware loading.
        """
        with self.lock:
            if self.process is not None and self.process.poll() is None:
                return

            if self.process is not None:
                try:
                    if self.process.stdout is not None:
                        self.process.stdout.close()
                except Exception:
                    pass
                self.process = None

            self._validate_files()
            initial_state = self.usb_state()
            if initial_state == "missing":
                raise RuntimeError("RX888 is not present on USB")

            if initial_state == "bootloader":
                self._log(
                    f"bootloader detected ({self.USB_ID_BOOTLOADER}); "
                    f"autoloading {self.FIRMWARE.name}"
                )
            else:
                self._log(f"running device detected ({self.USB_ID_RUNNING})")

            self._launch()

            # Give firmware upload / USB re-enumeration a short opportunity to
            # complete. If the streamer remains alive, it is already streaming.
            deadline = time.monotonic() + 4.0
            while time.monotonic() < deadline:
                if self.process is not None and self.process.poll() is None:
                    # A small grace period catches immediate launch failures
                    # without delaying normal startup noticeably.
                    time.sleep(0.20)
                    if self.process.poll() is None:
                        self._log("native stream started")
                        return
                time.sleep(0.10)

            return_code = None if self.process is None else self.process.poll()
            state_after = self.usb_state()

            # A firmware-only first launch may exit while the FX3 reconnects as
            # PID 00f1. Relaunch once against the now-running device.
            if initial_state == "bootloader" and state_after == "running":
                self._log(
                    "firmware upload completed and device re-enumerated; "
                    "restarting native stream"
                )
                self.stop()
                time.sleep(0.50)
                self._launch()
                time.sleep(0.35)
                if self.process.poll() is None:
                    self._log("native stream started after firmware autoload")
                    return
                return_code = self.process.poll()

            self.stop()
            raise RuntimeError(
                "RX888 streamer failed to remain running: "
                f"return_code={return_code}, usb_state={state_after}"
            )

    def set_gain(self, gain, settle_seconds=0.35):
        """Apply RX888 gain by restarting the native streamer."""

        new_gain = int(round(float(gain)))

        with self.lock:
            if new_gain == self.gain and self.process is not None:
                return self.gain

            old_gain = self.gain
            self.gain = new_gain

            try:
                self._log(
                    f"changing gain {old_gain} -> {new_gain}"
                )

                self.restart(settle_seconds=settle_seconds)

                self._log(
                    f"gain now {self.gain}"
                )

                return self.gain

            except Exception:
                self.gain = old_gain
                raise
    def restart(self, settle_seconds=1.0):
        self.stop()
        time.sleep(float(settle_seconds))
        previous_state = self.usb_state()
        if previous_state == "missing":
            raise RuntimeError("RX888 is not present on USB")
        self.start()
        return previous_state

    def read(self, sample_count):
        process = self.process
        if process is None or process.stdout is None:
            raise RuntimeError("RX888 streamer is not running")

        byte_count = int(sample_count) * 2
        raw = bytearray(byte_count)
        view = memoryview(raw)
        offset = 0

        while offset < byte_count:
            received = process.stdout.readinto(view[offset:])
            if received is None:
                continue
            if received == 0:
                try:
                    return_code = process.wait(timeout=0.2)
                except subprocess.TimeoutExpired:
                    return_code = process.poll()
                raise RuntimeError(
                    "RX888 streamer ended unexpectedly: "
                    f"return code {return_code}"
                )
            offset += received

        return np.frombuffer(raw, dtype="<i2").astype(np.float32) / 32768.0

    def stop(self):
        with self.lock:
            process = self.process
            self.process = None

        if process is None:
            return

        try:
            if process.stdout is not None:
                process.stdout.close()
        except Exception:
            pass

        if process.poll() is not None:
            return

        process.terminate()
        try:
            process.wait(timeout=2.0)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=2.0)
