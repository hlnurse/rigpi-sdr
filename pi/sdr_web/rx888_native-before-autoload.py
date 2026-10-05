#!/usr/bin/env python3

import subprocess
import threading
from pathlib import Path
import time
import numpy as np


class RX888NativeSource:
    PROGRAM = Path(
        "/home/pi/SoapyRX888/build/rx888_tools/rx888_stream"
    )
    FIRMWARE = Path(
        "/home/pi/SoapyRX888/build/rx888_tools/"
        "firmware/SDDC_FX3.img"
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

    def start(self):
        with self.lock:
            if self.process is not None:
                if self.process.poll() is None:
                    return

                # A previous streamer exited; discard the stale process object.
                try:
                    if self.process.stdout is not None:
                        self.process.stdout.close()
                except Exception:
                    pass

                self.process = None

            command = [
                str(self.PROGRAM),
                "--firmware", str(self.FIRMWARE),
                "--samplerate", str(self.sample_rate),
                "--gainmode", self.gain_mode,
                "--gain", str(self.gain),
                "--att", str(self.attenuation),
                "--rand",
                "--dither",
            ]

            self.process = subprocess.Popen(
                command,
                stdout=subprocess.PIPE,
                stderr=None,       # send diagnostics to systemd journal
                bufsize=0,
            )

            if self.process.stdout is None:
                self.stop()
                raise RuntimeError(
                    "RX888 streamer did not create stdout"
                )
    @staticmethod
    def _usb_present(usb_id):
        result = subprocess.run(
            ["lsusb", "-d", usb_id],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=2.0,
            check=False,
        )
        return result.returncode == 0

    def usb_state(self):
        if self._usb_present(self.USB_ID_RUNNING):
            return "running"

        if self._usb_present(self.USB_ID_BOOTLOADER):
            return "bootloader"

        return "missing"

    def restart(self, settle_seconds=1.0):
        self.stop()
        time.sleep(float(settle_seconds))

        state = self.usb_state()

        if state == "missing":
            raise RuntimeError("RX888 is not present on USB")

        # start() passes --firmware, so rx888_stream will upload firmware
        # automatically when the device is in PID 00f3 bootloader mode.
        self.start()

        return state
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

        return (
            np.frombuffer(raw, dtype="<i2")
            .astype(np.float32)
            / 32768.0
        )

    def stop(self):
        with self.lock:
            process = self.process
            self.process = None

        if process is None:
            return

        if process.stdout is not None:
            process.stdout.close()

        process.terminate()

        try:
            process.wait(timeout=2.0)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=2.0)
