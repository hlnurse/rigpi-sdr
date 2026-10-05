#!/usr/bin/env python3
"""
ft8modem_manager.py — ELMER ft8modem integration
Manages ft8modem subprocess, parses D: decode lines,
inserts spots into station.Spots MySQL table.
Per-instance: each SDR instance uses its own UDP port and Radio ID.
UDP protocol: S16LE samples + 2-byte LE sequence number appended.
"""

import subprocess
import threading
import socket
import struct
import time
import re
import logging
import datetime
import numpy as np
import pymysql

logger = logging.getLogger('ft8modem')

# FT8 calling frequencies (Hz)
FT8_FREQS = {
    1.840e6:   "160m",
    3.573e6:   "80m",
    5.357e6:   "60m",
    7.074e6:   "40m",
    10.136e6:  "30m",
    14.074e6:  "20m",
    18.100e6:  "17m",
    21.074e6:  "15m",
    24.915e6:  "12m",
    28.074e6:  "10m",
    50.313e6:  "6m",
    144.174e6: "2m",
}
FT4_FREQS = {
    3.575e6:   "80m",
    7.0475e6:  "40m",
    10.140e6:  "30m",
    14.080e6:  "20m",
    18.104e6:  "17m",
    21.140e6:  "15m",
    24.919e6:  "12m",
    28.180e6:  "10m",
    50.318e6:  "6m",
}
FT8_TOLERANCE = 2000  # Hz

# UDP packet: payload (samples*2 bytes) must satisfy (payload_len) % 4 == 0
# 510 samples * 2 bytes = 1020 bytes; 1020 % 4 == 0. Plus 2-byte seq = 1022 total.
UDP_MAX_SAMPLES = 510
UDP_SAMPLE_RATE = 48000

# D: line pattern
_D_PAT = re.compile(
    r'^D:\s+(\d+)\s+([-\d]+)\s+([\d.]+)\s+(\d+)\s+[~+]\s+(.+)$'
)
_GRID_PAT = re.compile(r'\b[A-R]{2}\d{2}[a-x]{0,2}\b')
_CALL_PAT = re.compile(r'\b[A-Z0-9]{3,}(/[A-Z0-9]+)?\b')

DB_CFG = dict(host="localhost", user="ham", password="7388",
              database="station", connect_timeout=3,
              cursorclass=pymysql.cursors.DictCursor)

def _db_insert_spot(spot, radio_id=1):
    try:
        now = datetime.datetime.utcnow()
        webtime = now.strftime("%H%M")
        webdate = str(int(time.time()))
        note    = f"{spot.get('mode','FT8')} SNR{spot['snr']:+d} {spot['msg']}"[:40]
        conn = pymysql.connect(**DB_CFG)
        with conn.cursor() as cur:
            cur.execute("""
                INSERT INTO Spots
                  (Radio, Folder, Spotter, Frequency, Band, DX,
                   Webtime, Webdate, Note, Source, Mode, DXGrid)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            """, (
                radio_id, 'Inbox',
                spot.get('de_call') or spot.get('mode') or 'FT8',
                int(spot['freq_hz']),
                spot.get('band', ''),
                spot.get('dx_call') or '',
                webtime, webdate, note,
                'ft8modem',
                spot.get('mode', 'FT8'),
                spot.get('grid', ''),
            ))
            conn.commit()
        conn.close()
        logger.debug("[FT8MGR] spot inserted: %s radio=%d", spot.get('dx_call'), radio_id)
    except Exception as e:
        logger.warning("[FT8MGR] DB insert error: %s", e)


class FT8Manager:
    def __init__(self, udp_port=7001, radio_id=1, extra_callback=None):
        self._udp_port  = udp_port
        self._radio_id  = radio_id
        self._extra_cb  = extra_callback
        self._proc      = None
        self._thread    = None
        self._running   = False
        self._mode      = None
        self._band      = None
        self._dial_hz   = None
        self._lock      = threading.Lock()
        self._recent    = []
        self._recent_lock = threading.Lock()
        # UDP sender socket
        self._sock      = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self._seq       = 0
        logger.info("[FT8MGR] init udp_port=%d radio_id=%d", udp_port, radio_id)

    def get_ft8_mode(self, freq_hz):
        for f, band in FT8_FREQS.items():
            if abs(freq_hz - f) <= FT8_TOLERANCE:
                return ('FT8', band, f)
        for f, band in FT4_FREQS.items():
            if abs(freq_hz - f) <= FT8_TOLERANCE:
                return ('FT4', band, f)
        return None

    def on_freq_change(self, freq_hz):
        result = self.get_ft8_mode(freq_hz)
        if result:
            mode, band, dial = result
            if self._mode != mode or self._dial_hz != dial:
                logger.info("[FT8MGR] Starting %s %s (%.3f MHz) udp=%d radio=%d",
                            mode, band, dial/1e6, self._udp_port, self._radio_id)
                self.start(mode, band, dial)
        else:
            if self._running:
                logger.info("[FT8MGR] Off FT8 freq, stopping decoder")
                self.stop()

    def send_audio(self, audio_f32):
        """Send float32 audio samples to ft8modem via UDP. Call from audio pipeline."""
        if not self._running:
            return
        if not getattr(self, '_audio_logged', False):
            self._audio_logged = True
            rms = float(np.sqrt(np.mean(audio_f32**2))) if len(audio_f32) else 0.0
            peak = float(np.max(np.abs(audio_f32))) if len(audio_f32) else 0.0
            logger.info("[FT8MGR] send_audio: n=%d rms=%.4f peak=%.4f", len(audio_f32), rms, peak)
        # Convert to S16LE
        pcm = (np.clip(audio_f32, -1.0, 1.0) * 32767).astype(np.int16)
        # Send in chunks of UDP_MAX_SAMPLES
        offset = 0
        while offset < len(pcm):
            chunk = pcm[offset:offset + UDP_MAX_SAMPLES]
            offset += UDP_MAX_SAMPLES
            # Append 2-byte LE sequence number
            seq_bytes = struct.pack('<H', self._seq & 0xFFFF)
            self._seq += 1
            packet = chunk.tobytes() + seq_bytes
            try:
                self._sock.sendto(packet, ('127.0.0.1', self._udp_port))
            except Exception as e:
                logger.warning("[FT8MGR] UDP send error: %s", e)

    def start(self, mode, band, dial_hz):
        with self._lock:
            self._stop_internal()
            self._mode    = mode
            self._band    = band
            self._dial_hz = dial_hz
            self._seq     = 0
            self._audio_logged = False
            self._running = True
            self._thread  = threading.Thread(
                target=self._run, args=(mode,), daemon=True)
            self._thread.start()
            logger.info("[FT8MGR] thread started %s %s udp=%d radio=%d",
                        mode, band, self._udp_port, self._radio_id)

    def stop(self):
        with self._lock:
            self._stop_internal()

    def _stop_internal(self):
        self._running = False
        self._mode    = None
        self._band    = None
        if self._proc:
            try:
                if self._proc.stdin:
                    self._proc.stdin.close()
            except Exception:
                pass
            try:
                self._proc.terminate()
                self._proc.wait(timeout=3)
            except Exception:
                pass
            self._proc = None

    def _run(self, mode):
        # Use -e flag for unique temp folder per instance
        cmd = ['ft8modem', '-r', str(UDP_SAMPLE_RATE),
               mode, f'udp:{self._udp_port}']
        logger.info("[FT8MGR] cmd: %s", ' '.join(cmd))
        try:
            self._proc = subprocess.Popen(
                cmd,
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                bufsize=0,
                close_fds=True,
                start_new_session=True
            )
            logger.info("[FT8MGR] Popen started pid=%s", self._proc.pid if self._proc else None)
            for raw_line in self._proc.stdout:
                if not self._running:
                    break
                line = raw_line.decode('utf-8', errors='replace').strip()
                logger.info("[FT8MGR] stdout: %s", line)
                if line.startswith('D:'):
                    spot = self._parse_decode(line)
                    if spot:
                        with self._recent_lock:
                            self._recent.append(spot)
                            if len(self._recent) > 200:
                                self._recent.pop(0)
                        threading.Thread(
                            target=_db_insert_spot,
                            args=(spot, self._radio_id), daemon=True).start()
                        if self._extra_cb:
                            try: self._extra_cb(spot)
                            except Exception: pass
        except Exception as e:
            logger.error("[FT8MGR] process error: %s", e, exc_info=True)
        finally:
            self._running = False
            if self._proc:
                logger.info("[FT8MGR] decoder stopped, rc=%s", self._proc.poll())

    def _parse_decode(self, line):
        m = _D_PAT.match(line)
        if not m:
            return None
        unix_t, snr, dt, audio_hz, msg = m.groups()
        parts = msg.strip().split()
        dx_call = de_call = grid = None
        if parts[0] == 'CQ':
            for p in parts[1:]:
                if _GRID_PAT.match(p):
                    grid = p
                elif _CALL_PAT.match(p) and not dx_call:
                    dx_call = p
        elif len(parts) >= 2:
            de_call = parts[0]
            dx_call = parts[1]
            for p in parts[2:]:
                if _GRID_PAT.match(p):
                    grid = p; break
        return {
            'time': int(unix_t), 'snr': int(snr), 'dt': float(dt),
            'audio_hz': int(audio_hz),
            'freq_hz': (self._dial_hz or 0) + int(audio_hz),
            'msg': msg.strip(), 'dx_call': dx_call, 'de_call': de_call,
            'grid': grid or '', 'band': self._band, 'mode': self._mode,
            'dial_hz': self._dial_hz, 'source': 'ft8modem',
        }

    def recent_spots(self, n=50):
        with self._recent_lock:
            return list(self._recent[-n:])

    @property
    def is_running(self): return self._running

    @property
    def status(self):
        return {'running': self._running, 'mode': self._mode,
                'band': self._band, 'dial_hz': self._dial_hz,
                'udp_port': self._udp_port, 'radio_id': self._radio_id}


_ft8_manager = None

def get_ft8_manager(udp_port=7001, radio_id=1, extra_callback=None):
    global _ft8_manager
    if _ft8_manager is None:
        _ft8_manager = FT8Manager(udp_port=udp_port, radio_id=radio_id,
                                   extra_callback=extra_callback)
    return _ft8_manager


if __name__ == '__main__':
    logging.basicConfig(level=logging.INFO,
                        format='%(levelname)s:%(name)s:%(message)s')
    mgr = FT8Manager(udp_port=7001, radio_id=1)
    mgr.start('FT8', '20m', 14.074e6)
    try:
        while True: time.sleep(1)
    except KeyboardInterrupt:
        mgr.stop()
        print("Stopped.")
