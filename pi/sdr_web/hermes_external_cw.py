"""External WinKeyer guard; caller serializes access with the native TX lock."""
import time

class ExternalCWState:
    def __init__(self, frequency):
        self.requested = self.armed = self.blocked = False
        self.key_down = self.hardware_ptt = False
        self.frequency = int(frequency)
        self.drive = 32
        self.maximum_seconds = 600.0
        self.heartbeat = self.response_at = self.started_at = 0.0
        self.error = ''

    def configure(self, enabled, drive, frequency, maximum_seconds):
        now = time.monotonic()
        enabled = bool(enabled)
        if not enabled:
            self.requested = self.armed = self.blocked = False
            self.started_at = 0.0
            self.error = ''
            return
        if not self.requested:
            self.blocked = False
            self.error = ''
        self.requested = True
        self.drive = max(0, min(240, int(drive))) & 0xf0
        self.frequency = int(frequency)
        self.maximum_seconds = max(5.0, min(3600.0, float(maximum_seconds)))
        self.heartbeat = now
        self.armed = not self.blocked

    def observe(self, control):
        now = time.monotonic()
        self.key_down = bool(control & 4)
        ptt = bool(control & 1)
        if ptt and not self.hardware_ptt:
            self.started_at = now
        elif not ptt:
            self.started_at = 0.0
        self.hardware_ptt = ptt
        self.response_at = now

    def stop(self, error='External CW stopped. Select a different mode, then CW, to re-arm.'):
        if self.requested:
            self.blocked = True
            self.error = str(error)
        self.armed = False

    def snapshot(self):
        now = time.monotonic()
        if self.armed:
            if now - self.heartbeat > 5.0:
                self.stop('External CW receive-loop heartbeat was lost.')
            elif self.response_at and now - self.response_at > 1.0:
                self.stop('External CW hardware telemetry was lost.')
            elif self.hardware_ptt and self.started_at and now - self.started_at >= self.maximum_seconds:
                self.stop('External CW maximum-duration timer expired.')
        ready = self.armed and self.response_at > 0 and now - self.response_at <= 1.0
        return ready, self.drive if ready else 0, self.frequency

    def status(self):
        ready, drive, frequency = self.snapshot()
        active = bool(ready and self.hardware_ptt)
        return {'configured_drive': self.drive, 'requested': self.requested, 'armed': ready, 'blocked': self.blocked,
                'active': active, 'key_down': self.key_down, 'hardware_ptt': self.hardware_ptt,
                'drive': drive if active else 0, 'frequency_hz': frequency,
                'maximum_seconds': self.maximum_seconds,
                'seconds': max(0.0, time.monotonic()-self.started_at) if active and self.started_at else 0.0,
                'error': self.error}
