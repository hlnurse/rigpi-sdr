#!/usr/bin/env python3
"""
Minimal RigPi-SDR server — spectrum display + tune only.
No audio, no WebRTC, no KiwiSDR, no remote devices.
"""

import os
os.environ["SOAPY_SDR_PLUGIN_PATH"] = (
    "/usr/lib/aarch64-linux-gnu/SoapySDR/modules0.8:"
    "/usr/local/lib/SoapySDR/modules0.8:"
    "/usr/local/lib/aarch64-linux-gnu/SoapySDR/modules0.8"
)

import sys
import json
import time
import threading
import logging
import socket
import argparse
from typing import Optional

import asyncio
import scipy.signal
from collections import deque
from fractions import Fraction
from itertools import islice
import numpy as np
import av
from flask import Flask, jsonify, request, send_from_directory
from aiortc import RTCPeerConnection, RTCSessionDescription
from aiortc.mediastreams import AudioStreamTrack
import gevent
import gevent.queue
# KiwiSDR support
import sys as _sys
_sys.path.insert(0, '/home/pi/sdr_web/kiwiclient')
try:
    from kiwi import KiwiSDRStream, KiwiWorker
    KIWI_AVAILABLE = True
except ImportError:
    KIWI_AVAILABLE = False
import queue as _queue
import queue as _stdlib_queue
import SoapySDR
from SoapySDR import SOAPY_SDR_RX, SOAPY_SDR_CF32

# ---- Config ----
FFT_SIZE    = 2048
SAMPLE_RATE = 768_000
DEFAULT_HZ  = 14_074_000.0

logging.basicConfig(level=logging.INFO, format="%(asctime)s [%(levelname)s] %(message)s")
logger = logging.getLogger("sdr_web")

parser = argparse.ArgumentParser()
parser.add_argument("--port",    type=int, default=8001)
parser.add_argument("--device",  default=None)
parser.add_argument("--serial",  default="")
parser.add_argument("--rigport", type=int, default=4532)
parser.add_argument("--rigpi_url", default=None)
args = parser.parse_args()

SERVER_PORT = args.port
RIGCTL_PORT = args.rigport
RIGPI_URL   = args.rigpi_url

# Load instance config
_inst_cfg = {}
_cfg_path = f"/home/pi/sdr_web/config/instance_{SERVER_PORT}.json"
if os.path.exists(_cfg_path):
    try:
        with open(_cfg_path) as f:
            _inst_cfg = json.load(f)
    except Exception:
        pass

DEVICE_DRIVER = args.device  or _inst_cfg.get("device_driver", "")
DEVICE_SERIAL = args.serial  or _inst_cfg.get("device_serial", "")
print(f"[STARTUP] port={SERVER_PORT} driver={DEVICE_DRIVER} serial={DEVICE_SERIAL}", flush=True)
DEVICE_ARGS   = _inst_cfg.get("device_args", "")  # e.g. "direct_samp=2"
RIGPI_URL     = RIGPI_URL    or _inst_cfg.get("rigpi_url", None)
if not RIGCTL_PORT or RIGCTL_PORT == 4532:
    RIGCTL_PORT = _inst_cfg.get("rigport", 4532) or 4532
class KiwiWaterfallClient(KiwiSDRStream):
        """KiwiSDR waterfall stream that feeds engine.latest_mags."""
        def __init__(self, options, data_queue):
            super().__init__()
            self._options  = options
            self._queue    = data_queue
            self._type     = 'W/F'
            self._freq     = options.frequency
            self._zoom     = options.zoom
            self._num_skip = 2

        def _setup_rx_params(self):
            self._set_zoom_cf(self._zoom, self._freq)
            logger.info("[KIWI WF] setup rx freq=%s", self._freq)
            self._set_maxdb_mindb(-10, -130)
            self._set_wf_comp(False)
            self._set_wf_speed(3)
            self.set_name(self._options.user)

        def _process_waterfall_samples(self, seq, samples):
            if self._num_skip:
                if seq < 2:
                    self._num_skip -= 1
                    return
                self._num_skip = 0
            span_hz = self.zoom_to_span(self._zoom) * 1000
            self._queue.put({
                'center_hz': self._freq * 1000,
                'span_hz':   span_hz,
                'mags':      samples,   # numpy array, dBm values
            })

class KiwiAudioClient(KiwiSDRStream):
    """KiwiSDR SND stream — audio + waterfall in one connection."""
    def __init__(self, options, engine):
        super().__init__()
        self._options  = options
        self._engine   = engine
        self._type     = 'SND'
        self._freq     = options.frequency
        self._zoom     = getattr(options, 'zoom', 0)
        self._num_skip = 2
    def _setup_rx_params(self):
        self.set_name(self._options.user)
        mod = self._options.modulation or 'usb'
        lc  = self._options.lp_cut or 300
        hc  = self._options.hp_cut or 3000
        self.set_mod(mod, lc, hc, self._freq)
        self.set_agc(on=True)
        self._set_snd_comp(False)
        self._setup_wf_params()
        logger.info("[KIWI] SND+WF setup freq=%.3f zoom=%d kiwi_ver=%s", self._freq, self._zoom, self._kiwi_version)
    def _setup_wf_params(self):
        import threading, time
        def _do():
            for _ in range(10):
                if self._kiwi_version is not None:
                    break
                time.sleep(0.2)
            try:
                self._set_zoom_cf(self._zoom, self._freq)
                self._set_maxdb_mindb(-10, -130)
                self._set_wf_comp(False)
                self._set_wf_speed(3)
                logger.info("[KIWI] WF params sent zoom=%d freq=%.3f ver=%s", self._zoom, self._freq, self._kiwi_version)
            except Exception as e:
                logger.warning("[KIWI] WF params failed: %s", e)
        threading.Thread(target=_do, daemon=True).start()
    def _process_audio_samples(self, seq, samples, rssi, fmt):
        import numpy as np, scipy.signal
        audio = samples.astype(np.float32) / 32768.0
        audio48 = scipy.signal.resample_poly(audio, 4, 1).astype(np.float32)
        with self._engine.audio_lock:
            fifo = self._engine.audio_fifo
            overflow = (len(fifo) + len(audio48)) - self._engine._fifo_max
            if overflow > 0:
                for _ in range(min(overflow, len(fifo))):
                    fifo.popleft()
            fifo.extend(audio48.tolist())
            # Feed loopback fifo
            if _alsa_loopback_running and hasattr(self._engine, '_loopback_fifo'):
                lb = self._engine._loopback_fifo
                lb_overflow = (len(lb) + len(audio48)) - self._engine._fifo_max
                if lb_overflow > 0:
                    for _ in range(min(lb_overflow, len(lb))):
                        lb.popleft()
                lb.extend(audio48.tolist())
    def _process_waterfall_samples(self, seq, samples):
        if self._num_skip:
            if seq < 2:
                self._num_skip -= 1
                return
            self._num_skip = 0
        import numpy as np
        span_hz = self.zoom_to_span(self._zoom) * 1000
        center_hz = self._freq * 1000
        # Map 0..255 waterfall to dBm range
        mags = (samples.astype(np.float32) / 255.0) * 120.0 - 130.0
        with self._engine.lock:
            if self._engine.smoothing > 0 and hasattr(self._engine, '_kiwi_have_mags') and self._engine._kiwi_have_mags:
                self._engine.latest_mags = (self._engine.smoothing * mags +
                    (1 - self._engine.smoothing) * self._engine.latest_mags)
            else:
                self._engine.latest_mags = mags
                self._engine._kiwi_have_mags = True
            self._engine.center_freq = center_hz
            self._engine.span = span_hz
        logger.info("[KIWI WF] seq=%d bins=%d center=%.0f span=%.0f",
                     seq, len(mags), center_hz, span_hz)
class KiwiManager:
    _is_connected = False  # class-level default to prevent AttributeError
    """Manages a KiwiSDR waterfall connection and injects data into the SDR engine."""
    def __init__(self):
        self._thread  = None
        self._worker  = None
        self._queue   = _queue.Queue(maxsize=4)
        self._running = False
        self._host    = None
        self._hostname = None
        self._port    = 8073
        self._active  = False
        self._injector = None

    def connect(self, host, port, freq_khz, zoom, password, engine, hostname=None):
        self.disconnect()
        self._host   = host
        self._port   = port
        self._active = True
        self._is_connected = True
        logger.info("[KIWI] connect called host=%s port=%d", host, port)
        import types
        options = types.SimpleNamespace(
            frequency=freq_khz,
            zoom=zoom,
            user='ELMER',
            password=password,
            tlimit_password='',
            tlimit=None,
            admin=False,
            no_api=False,
            socket_timeout=10,
            rigctl_enabled=False,
            rigctl_port=4532,
            rigctl_address='',
            connect_retries=0,
            busy_retries=0,
            connect_timeout=0,
            busy_timeout=0,
            is_kiwi_tdoa=False,
            server_host=hostname or host,
            server_port=port,
            status=0,
            idx=0,
            multiple_connections=1,
            waterfall=True,
            sound=False,
            wideband=False,
            modulation='am',
            lp_cut=None,
            hp_cut=None,
            station=None,
            filename='',
            dir=None,
            resample=0,
            out_fmt='s16',
            nb=False,
            nb_test=False,
            nolocal=False,
            S_meter=-1,
            stats=False,
            tstamp=False,
            ws_timestamp=False,
            freq_pbc=False,
            rev_bin=False,
            sdt=0,
            test_mode=False,
            bad_cmd=False,
            ADC_OV=False,
            wf_cal=0,
            camp_allow_1ch=False,
            netcat=False,
            launch_delay=0,
            gc_stats=False,
        )
        # WF worker for spectrum
        q = _queue.Queue(maxsize=4)
        self._queue = q
        wf_client = KiwiWaterfallClient(options, q)
        self._wf_client = wf_client
        run_event = threading.Event()
        run_event.set()
        camp_wait_event = threading.Event()
        camp_wait_event.set()
        self._run_event = run_event
        self._worker = KiwiWorker(args=(wf_client, options, True, False, run_event, camp_wait_event))
        self._worker.daemon = True
        self._running = True
        self._worker.start()
        self._injector = threading.Thread(target=self._inject_loop, args=(q, engine), daemon=True)
        self._injector.start()
        # SND worker for audio
        if getattr(self, '_want_audio', True):
            snd_options = types.SimpleNamespace(**vars(options))
            snd_options.sound = True
            snd_options.waterfall = False
            snd_options.modulation = engine.mode.lower() if hasattr(engine, 'mode') else 'usb'
            snd_options.lp_cut = 300
            snd_options.hp_cut = 3000
            snd_client = KiwiAudioClient(snd_options, engine)
            self._snd_client = snd_client
            snd_run_event = threading.Event()
            snd_run_event.set()
            snd_camp_wait = threading.Event()
            snd_camp_wait.set()
            self._snd_run_event = snd_run_event
            self._snd_worker = KiwiWorker(args=(snd_client, snd_options, True, False, snd_run_event, snd_camp_wait))
            self._snd_worker.daemon = True
            self._snd_worker.start()
            kiwi_mgr._wf_count = 0
            logger.info("[KIWI] WF + SND workers started")
        else:
            self._snd_client = None
            self._snd_worker = None
            self._snd_run_event = None
            logger.info("[KIWI] WF only (no audio)")
    def _run_worker(self, worker):
        try:
            worker.run()
        except Exception as e:
            logger.error("[KIWI] worker error: %s", e)
        finally:
            self._active = False

    def _inject_loop(self, q, engine):
        import numpy as np
        logger.info("[KIWI] inject loop started")
        while self._running:
            try:
                data = q.get(timeout=2)
                logger.info("[KIWI] got waterfall data len=%d", len(data.get('mags',[])))
            except _queue.Empty:
                continue
            try:
                mags = np.array(data['mags'], dtype=np.float32)
                # KiwiSDR waterfall is 0..255, map to dBm (-130..-10)
                if mags.max() <= 255:
                    mags = mags / 255.0 * 120.0 - 130.0
                # Interpolate to FFT_SIZE bins if needed
                if len(mags) != FFT_SIZE:
                    import scipy.signal
                    mags = scipy.signal.resample(mags, FFT_SIZE).astype(np.float32)
                with engine.lock:
                    smoothing = getattr(engine, 'smoothing', 0.0)
                    if smoothing > 0 and hasattr(engine, '_kiwi_have_mags') and engine._kiwi_have_mags and len(engine.latest_mags) == len(mags):
                        engine.latest_mags = smoothing * engine.latest_mags + (1 - smoothing) * mags
                    else:
                        engine.latest_mags = mags
                        engine._kiwi_have_mags = True
                    _wf = getattr(kiwi_mgr, '_wf_client', None)
                    engine.center_freq = (_wf._freq * 1000) if _wf and _wf._freq else data['center_hz']
                    kiwi_mgr._wf_count = getattr(kiwi_mgr, '_wf_count', 0) + 1
                    engine.sample_rate = data['span_hz']
                    engine.span = data['span_hz']
            except Exception as e:
                logger.error("[KIWI] inject error: %s", e)

    def tune(self, freq_khz, mode=None, lc=300, hc=3000):
        """Tune KiwiSDR SND and WF connections to new frequency/mode."""
        try:
            mod = mode or 'usb'
            snd = getattr(self, '_snd_client', None)
            if snd:
                snd.set_mod(mod, lc, hc, freq_khz)
                snd._freq = freq_khz
            wf = getattr(self, '_wf_client', None)
            if wf:
                try:
                    wf._set_zoom_cf(wf._zoom, freq_khz)
                except Exception:
                    try:
                        wf.set_freq(freq_khz)
                    except Exception:
                        pass
                wf._freq = freq_khz
            logger.info("[KIWI] tuned to %.3f kHz %s", freq_khz, mod)
            return True
        except Exception as e:
            logger.warning("[KIWI] tune failed: %s", e)
            return False
    def disconnect(self):
        self._is_connected = False
        self._running = False
        self._active  = False
        if hasattr(self, '_run_event'):
            self._run_event.clear()
        if hasattr(self, '_snd_run_event') and self._snd_run_event and hasattr(self._snd_run_event, 'clear'):
            self._snd_run_event.clear()
        if self._worker:
            try:
                self._worker.close()
            except Exception:
                pass
            try:
                self._worker.join(timeout=2)
            except Exception:
                pass
            self._worker = None
        snd = getattr(self, '_snd_worker', None)
        if snd and hasattr(snd, 'close'):
            try:
                snd.close()
            except Exception:
                pass
            try:
                snd.join(timeout=2)
            except Exception:
                pass
        self._snd_worker = None
        self._snd_run_event = None
        self._snd_client = None
        self._wf_client = None
        self._injector = None
    @property
    def connected(self):
        return self._is_connected
kiwi_mgr = KiwiManager()

# ---------------------------------------------------------------------------
# SDRConnectClient — integrates nRSP-ST via SDRConnect headless WebSocket API
# ---------------------------------------------------------------------------
class SDRConnectClient:
    """Connect to SDRConnect headless on ws://127.0.0.1:5454/ and feed
    spectrum (type-3) and audio (type-1) into the ELMER engine."""

    SDRCONN_WS   = "ws://127.0.0.1:5454/"
    HEADLESS_BIN = "/home/pi/Downloads/SDRconnect_headless"
    WS_PORT      = 5454

    def __init__(self):
        self._running   = False
        self._thread    = None
        self._engine    = None
        self._device    = None          # display name e.g. "nRSP-ST (2405092f50) (Compact)"
        self._freq_hz   = 14074000
        self._mode      = "USB"
        self._is_connected = False
        self._proc      = None          # SDRConnect_headless subprocess

    # ------------------------------------------------------------------
    def connect(self, engine, device_name=None, freq_hz=14074000, mode="USB"):
        self.disconnect()
        self._engine   = engine
        self._device   = device_name    # None = auto-select first nRSP-ST
        self._freq_hz  = freq_hz
        self._mode     = mode
        self._running  = True
        self._thread   = threading.Thread(target=self._run, daemon=True)
        self._thread.start()
        logger.info("[SDRCONN] connect requested device=%s freq=%d", device_name, freq_hz)

    def disconnect(self):
        self._running      = False
        self._is_connected = False
        if self._thread:
            self._thread.join(timeout=3)
            self._thread = None
        logger.info("[SDRCONN] disconnected")

    @property
    def connected(self):
        return self._is_connected

    # ------------------------------------------------------------------
    def _ensure_headless(self):
        """Start SDRConnect_headless if not already running."""
        import subprocess, shutil
        # Check if already running
        try:
            result = subprocess.run(['pgrep', '-f', 'SDRconnect_headless'],
                                    capture_output=True, text=True)
            if result.returncode == 0:
                logger.info("[SDRCONN] headless already running pid=%s", result.stdout.strip())
                return True
        except Exception:
            pass
        # Start it
        bin_path = self.HEADLESS_BIN
        if not shutil.which(bin_path) and not __import__('os').path.exists(bin_path):
            logger.error("[SDRCONN] SDRconnect_headless not found at %s", bin_path)
            return False
        try:
            self._proc = subprocess.Popen(
                [bin_path, f'--websocket_port={self.WS_PORT}'],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
            )
            import time; time.sleep(3)
            logger.info("[SDRCONN] headless started pid=%d", self._proc.pid)
            return True
        except Exception as e:
            logger.error("[SDRCONN] failed to start headless: %s", e)
            return False

    # ------------------------------------------------------------------
    def _run(self):
        import asyncio, numpy as np
        asyncio.run(self._async_run())

    async def _async_run(self):
        import asyncio
        try:
            import websockets
        except ImportError:
            logger.error("[SDRCONN] websockets not installed")
            return

        import numpy as np, json, struct

        if not self._ensure_headless():
            return

        retry_delay = 2
        while self._running:
            try:
                async with websockets.connect(
                    self.SDRCONN_WS, open_timeout=5
                ) as ws:
                    self._is_connected = True
                    logger.info("[SDRCONN] WS connected")
                    retry_delay = 2

                    # Get valid devices
                    await ws.send(json.dumps({
                        "event_type": "get_property",
                        "property": "valid_devices",
                        "value": ""
                    }))

                    # Wait for device list
                    device_name = self._device
                    deadline = 5
                    t0 = __import__('time').time()
                    while not device_name and (__import__('time').time() - t0) < deadline:
                        msg = await asyncio.wait_for(ws.recv(), timeout=3)
                        if isinstance(msg, str):
                            d = json.loads(msg)
                            if d.get('property') == 'valid_devices':
                                devs = d.get('value', '').split(',')
                                # Prefer Compact mode
                                for dev in devs:
                                    if 'nRSP' in dev and 'Compact' in dev:
                                        device_name = dev.strip()
                                        break
                                if not device_name and devs:
                                    device_name = devs[0].strip()
                                logger.info("[SDRCONN] auto-selected device: %s", device_name)

                    if not device_name:
                        logger.error("[SDRCONN] no device found")
                        return

                    # Select device
                    await ws.send(json.dumps({
                        "event_type": "selected_device_name",
                        "property": "",
                        "value": device_name
                    }))
                    await asyncio.sleep(0.5)

                    # Enable streaming
                    await ws.send(json.dumps({
                        "event_type": "device_stream_enable",
                        "property": "",
                        "value": "true"
                    }))
                    await asyncio.sleep(0.5)

                    # Set initial frequency and mode
                    await ws.send(json.dumps({
                        "event_type": "set_property",
                        "property": "device_vfo_frequency",
                        "device": "primary",
                        "value": str(self._freq_hz)
                    }))
                    await ws.send(json.dumps({
                        "event_type": "set_property",
                        "property": "demodulator",
                        "device": "primary",
                        "value": self._mode
                    }))

                    # Enable spectrum + audio
                    await ws.send(json.dumps({
                        "event_type": "spectrum_enable",
                        "property": "",
                        "value": "true"
                    }))
                    await ws.send(json.dumps({
                        "event_type": "audio_stream_enable",
                        "property": "",
                        "value": "true"
                    }))
                    logger.info("[SDRCONN] streaming started freq=%d mode=%s", self._freq_hz, self._mode)

                    # Main receive loop
                    async for msg in ws:
                        if not self._running:
                            break
                        if isinstance(msg, bytes):
                            msg_type = struct.unpack_from('<H', msg, 0)[0]
                            payload  = msg[2:]

                            if msg_type == 3:
                                # Spectrum: 512 uint8 bins
                                bins = np.frombuffer(payload, dtype=np.uint8).astype(np.float32)
                                # Map 0-255 → dBm range (-130..-10)
                                mags = bins / 255.0 * 120.0 - 130.0
                                # Resample to FFT_SIZE
                                if len(mags) != FFT_SIZE:
                                    import scipy.signal
                                    mags = scipy.signal.resample(mags, FFT_SIZE).astype(np.float32)
                                eng = self._engine
                                if eng:
                                    with eng.lock:
                                        sm = getattr(eng, 'smoothing', 0.3)
                                        if sm > 0 and len(getattr(eng, 'latest_mags', [])) == len(mags):
                                            eng.latest_mags = sm * eng.latest_mags + (1 - sm) * mags
                                        else:
                                            eng.latest_mags = mags
                                        eng.center_freq = self._freq_hz
                                        eng.sample_rate = 10_000_000.0
                                        eng.span        = 10_000_000.0

                            elif msg_type == 1:
                                # Audio: signed int16 stereo 48kHz → mono float32
                                samples = np.frombuffer(payload, dtype=np.int16)
                                # Deinterleave L/R, take left channel
                                mono = samples[0::2].astype(np.float32) / 32768.0
                                eng = self._engine
                                if eng:
                                    eng.audio_fifo.extend(mono.tolist())
                                    if _alsa_loopback_running and hasattr(eng, '_loopback_fifo'):
                                        eng._loopback_fifo.extend(mono.tolist())

                        else:
                            # JSON property_changed — update freq if changed externally
                            try:
                                d = json.loads(msg)
                                if d.get('property') == 'device_vfo_frequency':
                                    self._freq_hz = int(d.get('value', self._freq_hz))
                            except Exception:
                                pass

            except Exception as e:
                if self._running:
                    logger.warning("[SDRCONN] connection lost: %s — retry in %ds", e, retry_delay)
                    self._is_connected = False
                    await asyncio.sleep(retry_delay)
                    retry_delay = min(retry_delay * 2, 30)

        self._is_connected = False
        logger.info("[SDRCONN] _async_run exited")

    # ------------------------------------------------------------------
    def tune(self, freq_hz, mode=None):
        """Tune to new frequency/mode. Thread-safe — sends via a new WS connection."""
        self._freq_hz = freq_hz
        if mode:
            self._mode = mode
        # Signal the running loop — it will pick up _freq_hz on next property send
        # For immediate effect, send directly
        import asyncio, json
        async def _send():
            try:
                import websockets
                async with websockets.connect(self.SDRCONN_WS, open_timeout=3) as ws:
                    await ws.send(json.dumps({
                        "event_type": "set_property",
                        "property": "device_vfo_frequency",
                        "device": "primary",
                        "value": str(freq_hz)
                    }))
                    if mode:
                        await ws.send(json.dumps({
                            "event_type": "set_property",
                            "property": "demodulator",
                            "device": "primary",
                            "value": mode
                        }))
                    logger.info("[SDRCONN] tuned to %d Hz %s", freq_hz, mode or '')
            except Exception as e:
                logger.warning("[SDRCONN] tune send failed: %s", e)
        try:
            loop = asyncio.new_event_loop()
            loop.run_until_complete(_send())
            loop.close()
        except Exception as e:
            logger.warning("[SDRCONN] tune error: %s", e)

sdrconn_client = SDRConnectClient()

def _kiwi_auto_reconnect():
    """Auto-reconnect to saved KiwiSDR host on startup."""
    import time as _time
    _time.sleep(5)  # wait for engine to start
    try:
        saved = load_user_settings("admin")
        hosts = saved.get("kiwi_hosts", [])
        if hosts:
            host_port = hosts[-1]
            host, port = host_port.split(":") if ":" in host_port else (host_port, "8073")
            import subprocess as _sp
            result = _sp.run(['getent', 'hosts', host], capture_output=True, text=True, timeout=5)
            if result.returncode == 0:
                resolved_ip = result.stdout.split()[0]
                kiwi_mgr.connect(resolved_ip, int(port), 14000, 7, "", engine)
                logger.info("[KIWI] auto-reconnected to %s", host_port)
    except Exception as e:
        logger.error("[KIWI] auto-reconnect failed: %s", e)
# threading.Thread(target=_kiwi_auto_reconnect, daemon=True).start()  # disabled
# ---- SDR Engine ----
class SdrEngine:
    def __init__(self):
        self.lock        = threading.Lock()
        self.center_freq = DEFAULT_HZ
        self.sample_rate = float(SAMPLE_RATE)
        self.supported_sample_rates = [float(SAMPLE_RATE)]
        self.smoothing   = 0.0
        self.mode        = "USB"
        self.bw          = 2700
        self.floor       = -120.0
        self.wf_floor    = -130.0
        self.span        = float(SAMPLE_RATE)
        self.device_driver = ""
        self.device_serial = ""
        self.sdr         = None
        self.rx_stream   = None
        self.running     = True
        self.switching   = False  # True during device switch
        self._fail_count_reset = False
        self.latest_mags = np.full(FFT_SIZE, -130.0, dtype=np.float32)
        self._have_mags  = False
        self.fft_window  = np.hanning(FFT_SIZE).astype(np.float32)
        self._fft_count  = 0
        # Audio FIFO
        self.audio_fifo   = deque(maxlen=int(2.0 * 48000))
        self.audio_lock   = threading.Lock()
        self._fifo_target = int(0.15 * 48000)
        self._fifo_max    = int(0.40 * 48000)
        self.agc_gain     = 50.0  # start high, AGC settles down
        self._build_filters()
        self._available_devices = []
        self._loopback_fifo = deque(maxlen=int(2.0 * 48000))
        self._open_device()
        self._available_devices = self._enumerate()
        self.thread = threading.Thread(target=self._run, daemon=True)
        self.thread.start()

    def _open_device(self):
        drivers = [DEVICE_DRIVER] if DEVICE_DRIVER and DEVICE_DRIVER != "fake" else []
        if not drivers:
            drivers = ["airspyhf", "rtlsdr"]
        for drv in drivers:
            try:
                all_devs = SoapySDR.Device.enumerate()
                found = [d for d in all_devs if dict(d).get("driver","").lower() == drv.lower()]
                if DEVICE_SERIAL:
                    matched = [d for d in found if dict(d).get("serial","") == DEVICE_SERIAL]
                    if matched:
                        found = matched
                if not found:
                    continue
                import concurrent.futures
                with concurrent.futures.ThreadPoolExecutor(max_workers=1) as ex:
                    self.sdr = ex.submit(SoapySDR.Device, found[0]).result(timeout=5.0)
                self.device_driver = drv
                self.device_serial = dict(found[0]).get("serial", "")
                target_rate = SAMPLE_RATE if drv == "airspyhf" else 1_024_000
                self.sdr.setSampleRate(SOAPY_SDR_RX, 0, target_rate)
                self.sdr.setFrequency(SOAPY_SDR_RX, 0, self.center_freq)
                try: self.sdr.setGainMode(SOAPY_SDR_RX, 0, True)
                except: pass
                self.rx_stream = self.sdr.setupStream(SOAPY_SDR_RX, SOAPY_SDR_CF32, [0])
                self.sdr.activateStream(self.rx_stream)
                actual = float(self.sdr.getSampleRate(SOAPY_SDR_RX, 0))
                self.sample_rate = actual
                self.span = actual
                try:
                    rates = self.sdr.listSampleRates(SOAPY_SDR_RX, 0)
                    self.supported_sample_rates = sorted([float(r) for r in rates])
                except Exception:
                    self.supported_sample_rates = [actual]
                # Apply device args (e.g. direct_samp=2 for RTL-SDR HF)
                if DEVICE_ARGS:
                    for _arg in DEVICE_ARGS.split(","):
                        _arg = _arg.strip()
                        if "=" in _arg:
                            _k, _v = _arg.split("=", 1)
                            try:
                                self.sdr.writeSetting(_k.strip(), _v.strip())
                                logger.info("[SDR] set %s=%s", _k.strip(), _v.strip())
                            except Exception as _e:
                                logger.warning("[SDR] writeSetting %s failed: %s", _k.strip(), _e)
                logger.info("[SDR] opened %s sr=%.0f rates=%s", drv, actual,
                            self.supported_sample_rates)
                return
            except Exception as e:
                logger.warning("[SDR] %s failed: %s", drv, e)
                self.sdr = None
        logger.warning("[SDR] no hardware — fake IQ")
        self.device_driver = "fake"

    def _read(self, n):
        """Read exactly n samples directly — _run is a real OS thread so this is safe."""
        if self.sdr is None or self.rx_stream is None:
            return None
        try:
            buf = np.empty(n, dtype=np.complex64)
            total = 0
            while total < n and self.running:
                res = self.sdr.readStream(self.rx_stream, [buf[total:]], n - total, timeoutUs=300000)
                ret = res.ret if hasattr(res, 'ret') else int(res[0])
                if ret > 0:
                    total += ret
                elif ret == SoapySDR.SOAPY_SDR_TIMEOUT:
                    continue
                else:
                    return None
            return buf if total > 0 else None
        except Exception as e:
            logger.warning("[SDR] readStream error: %s", e)
            return None

    def _fake(self, n):
        time.sleep(n / self.sample_rate)  # simulate hardware pacing
        t = np.arange(n) / self.sample_rate
        sig = 0.01 * np.exp(1j * 2 * np.pi * 12000 * t).astype(np.complex64)
        noise = (np.random.randn(n) + 1j * np.random.randn(n)).astype(np.complex64) * 0.001
        return sig + noise

    def _run(self):
        logger.info("[SDR] run loop started device=%s", self.device_driver)
        t_err = 0.0
        _fail_count = 0
        while self.running:
            if self._fail_count_reset:
                _fail_count = 0
                self._fail_count_reset = False
            try:
                rf_decim = max(1, int(self.sample_rate // 48000))
                _t0 = time.time()
                time.sleep(0.001)  # prevent tight loop
                n = 960 * rf_decim  # exactly one WebRTC frame worth
                samples = self._read(n) if self.device_driver != "fake" else self._fake(n)
                if samples is None or len(samples) < FFT_SIZE:
                    if self.switching:
                        time.sleep(0.1)
                        continue
                    _fail_count += 1
                    if _fail_count > 10:
                        logger.warning("[SDR] stream lost, attempting reopen...")
                        _fail_count = 0
                        try:
                            # Save current device info before closing
                            _drv = self.device_driver
                            _ser = self.device_serial
                            self._close_stream()
                            time.sleep(2.0)
                            # Reopen by driver+serial directly — no enumerate
                            self._reopen_device(_drv, _ser)
                            logger.info("[SDR] reopen complete: %s", self.device_driver)
                        except Exception as re:
                            logger.error("[SDR] reopen failed: %s", re)
                            # Fall back to fake so run loop can continue
                            self.device_driver = "fake"
                            self.sdr = None
                            self.rx_stream = None
                    time.sleep(0.05)
                    continue
                _fail_count = 0
                # FFT
                chunk = samples[:FFT_SIZE].copy()
                chunk -= np.mean(chunk)
                windowed = chunk * self.fft_window
                spectrum = np.fft.fftshift(np.fft.fft(windowed))
                mags = (20.0 * np.log10(np.abs(spectrum) / FFT_SIZE + 1e-12)).astype(np.float32)
                self._fft_count += 1
                # Update latest_mags every 3 frames (~15fps SP)
                if self._fft_count % 3 == 0:
                 with self.lock:
                    if not kiwi_mgr.connected:
                        if self.smoothing > 0 and self._have_mags:
                            self.latest_mags = self.smoothing * mags + (1 - self.smoothing) * self.latest_mags
                        else:
                            self.latest_mags = mags
                            self._have_mags = True

                if self._fft_count % 200 == 1:
                    logger.info("[FFT] min=%.1f max=%.1f mean=%.1f",
                                float(mags.min()), float(mags.max()), float(mags.mean()))
                # Demodulate audio every frame
                audio = None
                if self.device_driver != "fake":
                    m = self.mode
                    if m in ("USB", "PKTUSB"):
                        audio = self.demod_ssb(samples, upper=True)
                    elif m in ("LSB", "PKTLSB"):
                        audio = self.demod_ssb(samples, upper=False)
                    elif m in ("CW", "CWR"):
                        audio = self.demod_cw(samples, upper=(m=="CW"))
                    elif m == "AM":
                        audio = self.demod_am(samples)
                    elif m == "FM":
                        audio = self.demod_fm(samples)
                    elif m == "WFM":
                        audio = self.demod_wfm(samples)
                # Push audio OUTSIDE spectrum lock — matches copy_25 structure
                if audio is not None and len(audio) > 0:
                    with self.audio_lock:
                        overflow = (len(self.audio_fifo) + len(audio)) - self._fifo_max
                        if overflow > 0:
                            drop = min(overflow, len(self.audio_fifo))
                            for _ in range(drop):
                                self.audio_fifo.popleft()
                            fade_len = min(64, len(audio))
                            fade = np.linspace(0.0, 1.0, fade_len, dtype=np.float32)
                            audio = audio.copy()
                            audio[:fade_len] *= fade
                        self.audio_fifo.extend(audio)
                        # Also feed loopback fifo if running
                        if _alsa_loopback_running and hasattr(self, '_loopback_fifo'):
                            lb_overflow = (len(self._loopback_fifo) + len(audio)) - self._fifo_max
                            if lb_overflow > 0:
                                for _ in range(min(lb_overflow, len(self._loopback_fifo))):
                                    self._loopback_fifo.popleft()
                            self._loopback_fifo.extend(audio)
                            if len(self._loopback_fifo) % 48000 < len(audio):
                                logger.info("[LOOPBACK] fifo size: %d", len(self._loopback_fifo))
                # Throttle to ~20ms loop rate
                _elapsed = time.time() - _t0
                if _elapsed < 0.018:
                    time.sleep(0.018 - _elapsed)
            except Exception as e:
                now = time.time()
                if now - t_err > 2.0:
                    logger.error("[SDR] run error: %s", e)
                    t_err = now
                time.sleep(0.05)
        logger.warning("[SDR] run loop exited")

    def get_spectrum(self):
        with self.lock:
            return {
                "hw_center":   float(self.center_freq),
                "center":      float(self.center_freq),
                "sample_rate": float(self.sample_rate),
                "mags":        self.latest_mags.tolist(),
                "mode":        self.mode,
                "bw":          int(self.bw),
            }

    def get_state(self):
        with self.lock:
            return {
                "center_freq":   float(self.center_freq),
                "hw_center":     float(self.center_freq),
                "sample_rate":   self.sample_rate,
                "span":          float(self.span),
                "mode":          self.mode,
                "bw":            int(self.bw),
                "smoothing":     float(self.smoothing),
                "floor":         float(self.floor),
                "wf_floor":      float(self.wf_floor),
                "device_driver": self.device_driver,
                "device_serial": self.device_serial,
                "available_devices": self._available_devices,
                "fft_size":      FFT_SIZE,
                "supported_sample_rates": self.supported_sample_rates,
                "gain":          0.0,
                "fill":          "gradient",
                "wf_palette":    "classic",
                "use_rtlsdr":    self.device_driver == "rtlsdr",
                "use_kiwi":      False,
                "hw_gain":       20.0,
                "hw_agc":        True,
                "hw_gain_min":   0.0,
                "hw_gain_max":   49.6,
                "hw_gain_step":  0.5,
                "bias_tee":      False,
                "lo_offset_hz":  0.0,
                "audio_level":   50.0,
                "audio_view":    "scope",
                "memories":      [None] * 20,
                "band_slot":     {},
                "band_memories": {},
                "remote_hosts":  [],
                "kiwi_hosts":    [],
                "is_admin":      True,
            }

    def _enumerate(self):
        try:
            import concurrent.futures
            with concurrent.futures.ThreadPoolExecutor(max_workers=1) as ex:
                raw = ex.submit(SoapySDR.Device.enumerate).result(timeout=3.0)
            devs = []
            for d in raw:
                d = dict(d)
                if d.get("driver") != "remote":
                    devs.append({"driver": d.get("driver",""), "label": d.get("label", d.get("driver","")),
                                 "serial": d.get("serial",""), "remote": ""})
            devs.append({"driver":"fake","label":"Fake (No Hardware)","serial":"","remote":""})
            return devs
        except Exception:
            return [{"driver": self.device_driver or "fake", "label": self.device_driver or "Fake",
                     "serial": self.device_serial, "remote": ""}]

    def _reopen_device(self, driver, serial):
        """Reopen device by driver name, using enumerate to get device object."""
        # For RTL-SDR, try USB reset first to clear any bad state
        if driver == "rtlsdr":
            try:
                import subprocess
                subprocess.run(["usbreset", "0bda:2838"], capture_output=True, timeout=3)
                time.sleep(1.0)
            except Exception:
                pass
        import concurrent.futures
        # Enumerate to get device kwargs — required for RTL-SDR
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as ex:
            all_devs = ex.submit(SoapySDR.Device.enumerate).result(timeout=5.0)
        found = [d for d in all_devs if dict(d).get("driver","").lower() == driver.lower()]
        if serial:
            matched = [d for d in found if dict(d).get("serial","") == serial]
            if matched: found = matched
        if not found:
            raise RuntimeError(f"Device not found: driver={driver} serial={serial}")
        import concurrent.futures
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as ex:
            self.sdr = ex.submit(SoapySDR.Device, found[0]).result(timeout=5.0)
        self.device_driver = driver
        self.device_serial = serial
        target_rate = SAMPLE_RATE if driver == "airspyhf" else 1_024_000
        self.sdr.setSampleRate(SOAPY_SDR_RX, 0, target_rate)
        actual = float(self.sdr.getSampleRate(SOAPY_SDR_RX, 0))
        self.sample_rate = actual
        self.span = actual
        try:
            rates = self.sdr.listSampleRates(SOAPY_SDR_RX, 0)
            self.supported_sample_rates = sorted([float(r) for r in rates])
        except Exception:
            self.supported_sample_rates = [actual]
        self.sdr.setFrequency(SOAPY_SDR_RX, 0, self.center_freq)
        try: self.sdr.setGainMode(SOAPY_SDR_RX, 0, True)
        except: pass
        self.rx_stream = self.sdr.setupStream(SOAPY_SDR_RX, SOAPY_SDR_CF32, [0])
        self.sdr.activateStream(self.rx_stream)
        self._build_filters()  # rebuild filters for new sample rate

    def _close_stream(self):
        """Close SoapySDR stream and device cleanly."""
        try:
            if self.rx_stream and self.sdr:
                self.sdr.deactivateStream(self.rx_stream)
                self.sdr.closeStream(self.rx_stream)
        except Exception: pass
        try:
            if self.sdr:
                SoapySDR.Device.unmake(self.sdr)
        except Exception: pass
        self.rx_stream = None
        self.sdr = None
        # Note: keep device_driver/serial so recovery knows what to reopen


    def _build_filters(self):
        sr = self.sample_rate
        # RF channel filter — 15kHz BW to reject adjacent signals
        taps = 101
        t = np.arange(taps) - (taps-1)/2.0
        self.rf_fir = (np.sinc(2*15000.0/sr*t) * np.hamming(taps)).astype(np.float32)
        self.rf_fir /= np.sum(self.rf_fir)
        self.rf_zi  = np.zeros(len(self.rf_fir)-1, dtype=np.complex64)
        # AM RF filter (10kHz)
        self.am_rf_fir = scipy.signal.firwin(101, 10000.0, fs=sr).astype(np.float32)
        self.am_rf_zi  = np.zeros(len(self.am_rf_fir)-1, dtype=np.complex64)
        # Audio LPF (3kHz @ 48kHz)
        t2 = np.arange(63) - 31.0
        self.audio_fir = (np.sinc(2*3000.0/48000.0*t2) * np.hamming(63)).astype(np.float32)
        self.audio_fir /= np.sum(self.audio_fir)
        self.audio_zi_am = np.zeros(len(self.audio_fir)-1, dtype=np.float32)
        # AM audio LPF (5kHz)
        t3 = np.arange(63) - 31.0
        self.am_audio_fir = (np.sinc(2*5000.0/48000.0*t3) * np.hamming(63)).astype(np.float32)
        self.am_audio_fir /= np.sum(self.am_audio_fir)
        self.am_audio_zi  = np.zeros(len(self.am_audio_fir)-1, dtype=np.float32)
        self.deemph_zi = np.zeros(1, dtype=np.float32)
        # Anti-alias filter for WFM (15kHz @ RF rate)
        taps_aa = 101
        t_aa = np.arange(taps_aa) - (taps_aa-1)/2.0
        self.aa_fir = (np.sinc(2*15000.0/sr*t_aa) * np.hamming(taps_aa)).astype(np.float32)
        self.aa_fir /= np.sum(self.aa_fir)
        self.aa_zi = np.zeros(len(self.aa_fir)-1, dtype=np.float32)

    def _to_48k(self, iq):
        sr = int(self.sample_rate)
        if sr >= 48000:
            d = max(1, sr // 48000)
            return iq[::d]
        factor = max(1, int(round(48000.0 / sr)))
        return np.repeat(iq, factor)

    def _make_rf_lpf(self, bw_hz):
        """Build RF channel filter at bw_hz for current sample rate."""
        bw = min(bw_hz, self.sample_rate * 0.45)
        taps = 101
        t = np.arange(taps) - (taps-1)/2.0
        fir = (np.sinc(2*bw/self.sample_rate*t) * np.hamming(taps)).astype(np.float32)
        fir /= np.sum(fir)
        return fir

    def _make_audio_lpf(self, bw_hz):
        """Build a LPF at bw_hz for 48kHz sample rate."""
        bw = min(bw_hz, 23000.0)
        t = np.arange(63) - 31.0
        fir = (np.sinc(2*bw/48000.0*t) * np.hamming(63)).astype(np.float32)
        fir /= np.sum(fir)
        return fir

    def demod_ssb(self, iq, upper=True):
        if iq is None or len(iq) < 8: return None
        iq = iq.astype(np.complex64, copy=False)
        iq_f, self.rf_zi = scipy.signal.lfilter(self.rf_fir, [1.0], iq, zi=self.rf_zi)
        if not upper: iq_f = np.conj(iq_f)
        audio = np.real(self._to_48k(iq_f))
        audio, self.audio_zi_am = scipy.signal.lfilter(
            self.audio_fir, [1.0], audio, zi=self.audio_zi_am)
        rms = float(np.sqrt(np.mean(audio**2)))
        if rms > 1e-6:
            desired = 0.3 / rms
            if desired < self.agc_gain:
                self.agc_gain = 0.2 * desired + 0.8 * self.agc_gain
            else:
                self.agc_gain = 0.02 * desired + 0.98 * self.agc_gain
            self.agc_gain = min(self.agc_gain, 200.0)
        return (audio * self.agc_gain).astype(np.float32)

    def demod_cw(self, iq, upper=True):
        return self.demod_ssb(iq, upper=upper)

    def demod_am(self, iq):
        if iq is None or len(iq) < 8: return None
        iq = iq.astype(np.complex64, copy=False)
        iq_f, self.am_rf_zi = scipy.signal.lfilter(self.am_rf_fir, [1.0], iq, zi=self.am_rf_zi)
        iq_dec = self._to_48k(iq_f)
        audio = np.abs(iq_dec)
        power = np.mean(audio * audio)
        if power < 1e-5:
            audio *= power / 1e-5
        audio, self.am_audio_zi = scipy.signal.lfilter(self.am_audio_fir, [1.0], audio, zi=self.am_audio_zi)
        rms = np.sqrt(np.mean(audio**2)) + 1e-6
        audio *= 0.25 / rms
        return np.tanh(audio * 2.0).astype(np.float32)

    def demod_fm(self, iq):
        """Narrowband FM demod — same approach as WFM but narrower."""
        if iq is None or len(iq) < 8: return None
        iq = iq.astype(np.complex64, copy=False)
        # RF filter (use BW setting, default 12kHz for NFM)
        bw_rf = float(self.bw) if self.bw else 12000.0
        if not hasattr(self, "_fm_rf_bw") or self._fm_rf_bw != bw_rf:
            self._fm_rf_bw  = bw_rf
            self._fm_rf_fir = self._make_rf_lpf(bw_rf)
            self._fm_rf_zi  = np.zeros(len(self._fm_rf_fir)-1, dtype=np.complex64)
        iq_f, self._fm_rf_zi = scipy.signal.lfilter(self._fm_rf_fir, [1.0], iq, zi=self._fm_rf_zi)
        # Limiter
        mag = np.abs(iq_f)
        iq_f = iq_f / (mag + 1e-12)
        # Discriminator
        prod = iq_f[1:] * np.conj(iq_f[:-1])
        discr = np.angle(prod).astype(np.float32)
        discr = np.append(discr, discr[-1])
        audio48 = self._to_48k(discr)
        # FM audio filter — fixed 3kHz, independent of BW setting
        if not hasattr(self, "_fm_audio_fir"):
            t = np.arange(63) - 31.0
            self._fm_audio_fir = (np.sinc(2*3000.0/48000.0*t) * np.hamming(63)).astype(np.float32)
            self._fm_audio_fir /= np.sum(self._fm_audio_fir)
            self._fm_audio_zi = np.zeros(len(self._fm_audio_fir)-1, dtype=np.float32)
        audio48, self._fm_audio_zi = scipy.signal.lfilter(
            self._fm_audio_fir, [1.0], audio48, zi=self._fm_audio_zi)
        return np.tanh(audio48 * 0.5).astype(np.float32)

    def demod_wfm(self, iq):
        """Wideband FM demod — pushes directly to audio_fifo."""
        if iq is None or len(iq) < 8: return None
        iq = iq.astype(np.complex64, copy=False)
        iq -= np.mean(iq)
        mag = np.abs(iq)
        iq = iq / (mag + 1e-12)
        if not hasattr(self, "_wfm_rf_fir"):
            self._wfm_rf_fir = self._make_rf_lpf(150000.0)
            self._wfm_rf_zi  = np.zeros(len(self._wfm_rf_fir)-1, dtype=np.complex64)
        iq_f, self._wfm_rf_zi = scipy.signal.lfilter(self._wfm_rf_fir, [1.0], iq, zi=self._wfm_rf_zi)
        prod = iq_f[1:] * np.conj(iq_f[:-1])
        discr = np.angle(prod).astype(np.float32)
        discr = np.append(discr, discr[-1])
        discr_f, self.aa_zi = scipy.signal.lfilter(self.aa_fir, [1.0], discr, zi=self.aa_zi)
        audio = self._to_48k(discr_f)
        if audio.size == 0: return None
        tau = 75e-6; dt = 1.0/48000.0; alpha = dt/(tau+dt)
        audio, self.deemph_zi = scipy.signal.lfilter(
            [alpha], [1, -(1-alpha)], audio, zi=self.deemph_zi)
        audio = np.tanh(audio * 2.5).astype(np.float32)
        with self.audio_lock:
            overflow = (len(self.audio_fifo) + len(audio)) - self._fifo_max
            if overflow > 0:
                for _ in range(min(overflow, len(self.audio_fifo))):
                    self.audio_fifo.popleft()
            self.audio_fifo.extend(audio)
        return None  # already pushed to fifo

    def audio_fifo_len(self):
        with self.audio_lock:
            return len(self.audio_fifo)

    def pop_audio(self, n):
        with self.audio_lock:
            available = len(self.audio_fifo)
            take = min(n, available)
            if take == 0:
                return np.zeros(n, dtype=np.float32)
            out = np.fromiter(islice(self.audio_fifo, take), dtype=np.float32, count=take)
            for _ in range(take):
                self.audio_fifo.popleft()
        if take < n:
            # Underrun: fade out real samples, pad with silence
            fade = np.linspace(1.0, 0.0, take, dtype=np.float32)
            out = out * fade
            out = np.concatenate([out, np.zeros(n-take, dtype=np.float32)])
        return out

    def drop_audio(self, n):
        with self.audio_lock:
            drop = min(n, len(self.audio_fifo))
            if drop <= 0: return
            fade_len = min(128, len(self.audio_fifo) - drop)
            for _ in range(drop):
                self.audio_fifo.popleft()
            if fade_len > 0:
                head = [self.audio_fifo.popleft() for _ in range(fade_len)]
                fade = np.linspace(0.0, 1.0, fade_len, dtype=np.float32)
                faded = (np.array(head, dtype=np.float32) * fade).tolist()
                self.audio_fifo.extendleft(reversed(faded))

    def tune(self, hz):
        hz = float(hz)
        with self.lock:
            self.center_freq = hz
        if self.sdr:
            try: self.sdr.setFrequency(SOAPY_SDR_RX, 0, hz)
            except Exception as e: logger.error("[SDR] tune failed: %s", e)

    def set_center_only(self, hz):
        """Retune SDR hardware to follow VFO knob, without sending back to RigPi."""
        hz = float(hz)
        with self.lock:
            self.center_freq = hz
        if self.sdr:
            try: self.sdr.setFrequency(SOAPY_SDR_RX, 0, hz)
            except Exception as e: logger.error("[SDR] vfo follow tune failed: %s", e)

# ---- RigCtl ----
class RigCtl:
    def __init__(self, host, port, timeout=0.6):
        self.host = host; self.port = port; self.timeout = timeout
        self._lock = threading.Lock(); self._sock = None; self._fp = None

    def _connect(self):
        s = socket.create_connection((self.host, self.port), timeout=self.timeout)
        s.settimeout(self.timeout)
        self._sock = s; self._fp = s.makefile("rwb", buffering=0)

    def _cmd(self, cmd):
        with self._lock:
            try:
                if not self._sock: self._connect()
                self._fp.write(cmd)
                line = self._fp.readline()
                return line.decode(errors="replace").strip()
            except Exception as e:
                try:
                    if self._fp: self._fp.close()
                    if self._sock: self._sock.close()
                except: pass
                self._sock = self._fp = None
                raise

    def set_freq(self, hz):
        try:
            r = self._cmd(f"F {int(hz)}\n".encode())
            return "RPRT 0" in r or not r.startswith("RPRT")
        except Exception as e:
            logger.warning("[RIGCTL] set_freq failed: %s", e); return False

    def get_freq(self):
        try: return int(self._cmd(b"f\n"))
        except: return None

# ---- RigSync ----
class RigSync:
    def __init__(self, rigctl, engine, radio_id=1, rigpi_url=None):
        self.rigctl = rigctl; self.engine = engine
        self.radio_id = radio_id; self.rigpi_url = rigpi_url
        self.pending_freq = None; self.last_freq = None
        self._hold_until = 0.0
        self.lock = threading.Lock(); self.running = True
        threading.Thread(target=self._loop, daemon=True).start()

    def request_tune(self, hz, username="admin"):
        with self.lock:
            self.pending_freq = (int(hz), username)
            self.last_freq = int(hz)  # optimistic — prevents stale poll revert
            self._hold_until = time.time() + 3.0  # hold off VFO poll 3s for slow rigs

    def _send_freq(self, hz, username):
        if self.rigpi_url:
            try:
                from urllib.request import urlopen
                url = f"{self.rigpi_url}/flic.php?n=7&u={username}&p={int(hz)}"
                with urlopen(url, timeout=2.0) as r:
                    ok = r.status == 200
                logger.info("[RIGSYNC] radio %d flic %d -> %s", self.radio_id, hz, r.status)
                return ok
            except Exception as e:
                logger.warning("[RIGSYNC] flic failed: %s", e); return False
        return self.rigctl.set_freq(hz)

    def _loop(self):
        _fail = 0
        while self.running:
            try:
                with self.lock:
                    pending = self.pending_freq
                    self.pending_freq = None
                if pending:
                    hz, user = pending
                    ok = self._send_freq(hz, user)
                    if ok: self.last_freq = hz
                    self._hold_until = time.time() + 3.0  # extend hold after send
                # Read-only poll — skip if we just sent a tune (avoid revert)
                if time.time() > self._hold_until:
                    freq = self.rigctl.get_freq()
                    if freq and freq != self.last_freq:
                        # Sanity check: if last_freq was set by a tune request,
                        # ignore VFO poll if rig reports wildly different freq
                        # (handles dummy rigs that don't actually tune)
                        logger.info("[RIGSYNC] radio %d VFO -> %d", self.radio_id, freq)
                        self.last_freq = freq
                        # Only retune hardware if outside current bandwidth
                        hw = self.engine.center_freq
                        half_bw = self.engine.sample_rate / 2
                        if abs(freq - hw) > half_bw * 0.9:
                            self.engine.set_center_only(freq)
                _fail = 0
            except Exception:
                _fail += 1
                if _fail <= 3:
                    logger.warning("[RIGSYNC] radio %d poll failed (%d)", self.radio_id, _fail)
            time.sleep(min(30.0, 2.0 ** min(_fail, 4)) if _fail else 1.0)

# ---- Settings persistence ----
SETTINGS_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "config", "users")

def _settings_path(username):
    return os.path.join(SETTINGS_DIR, username, f"{username}.json")

def load_user_settings(username):
    """Load saved user settings, return defaults if not found."""
    try:
        path = _settings_path(username)
        if os.path.exists(path):
            with open(path) as f:
                return json.load(f)
    except Exception as e:
        logger.warning("[SETTINGS] load failed for %s: %s", username, e)
    return {}

def save_user_settings(username, state):
    """Save user settings to disk in background thread."""
    import copy
    snapshot = copy.deepcopy(state)
    path = _settings_path(username)
    def _write():
        try:
            os.makedirs(os.path.dirname(path), exist_ok=True)
            with open(path, "w") as f:
                json.dump(snapshot, f, indent=2)
        except Exception as e:
            logger.error("[SETTINGS] save failed for %s: %s", username, e)
    threading.Thread(target=_write, daemon=True).start()

# ---- Globals ----
engine    = None
rigsyncs  = {}  # radio_id -> RigSync
user_radio_map  = {}  # username -> radio_id
user_state      = {}  # username -> {mode, bw, span, floor, wf_floor, center_freq}
active_radio = 1
tune_notify  = {}  # ws_id -> payload
ws_id_counter = 0

def get_user_state(username):
    """Get or create per-user display state, loading from disk if needed."""
    if username not in user_state:
        saved = load_user_settings(username)
        user_state[username] = {
            "mode":        saved.get("mode", "USB"),
            "bw":          int(saved.get("bw", 2700)),
            "span":        float(saved.get("span", SAMPLE_RATE)),
            "floor":       float(saved.get("floor", -120.0)),
            "wf_floor":    float(saved.get("wf_floor", -130.0)),
            "center_freq": float(saved.get("center_freq", DEFAULT_HZ)),
            "smoothing":   float(saved.get("smoothing", 0.0)),
            "sample_rate": float(saved.get("sample_rate", 0.0)),
            "hw_agc":      bool(saved.get("hw_agc", True)),
            "hw_gain":     float(saved.get("hw_gain", 20.0)),
            "bias_tee":    bool(saved.get("bias_tee", False)),
            "lo_offset_hz": float(saved.get("lo_offset_hz", 0.0)),
            "wf_palette":  saved.get("wf_palette", "classic"),
            "fill":        saved.get("fill", "gradient"),
            "memories":    saved.get("memories", [None] * 20),
            "band_memories": saved.get("band_memories", {}),
            "band_slot":   saved.get("band_slot", {}),
            "kiwi_hosts":  saved.get("kiwi_hosts", []),
        }
    return user_state[username]

def get_rigsync(radio_id):
    if radio_id not in rigsyncs:
        port = 4530 + radio_id * 2
        rc = RigCtl("127.0.0.1", port)
        rigsyncs[radio_id] = RigSync(rc, engine, radio_id, RIGPI_URL)
    return rigsyncs[radio_id]

# ---- WebRTC Audio ----
class EngineAudioTrack(AudioStreamTrack):
    kind = "audio"
    def __init__(self, engine, frame_ms=20):
        super().__init__()
        self.engine = engine
        self.sample_rate = 48000
        self.frame_samples = int(self.sample_rate * frame_ms / 1000.0)
        self._samples_sent = 0

    async def recv(self):
        n = self.frame_samples
        MAX_WAIT, POLL = 0.15, 0.005
        waited = 0.0
        while self.engine.audio_fifo_len() < n and waited < MAX_WAIT:
            await asyncio.sleep(POLL)
            waited += POLL
        excess = self.engine.audio_fifo_len() - self.engine._fifo_max
        if excess > 0:
            self.engine.drop_audio(excess)
        audio_f32 = self.engine.pop_audio(n)
        pcm_i16 = (np.clip(audio_f32, -1.0, 1.0) * 32767).astype(np.int16)
        frame = av.AudioFrame(format="s16", layout="mono", samples=n)
        frame.sample_rate = self.sample_rate
        frame.planes[0].update(pcm_i16.tobytes())
        frame.pts = self._samples_sent
        frame.time_base = Fraction(1, self.sample_rate)
        self._samples_sent += n
        return frame

# ---- Flask ----
app = Flask(__name__)
pcs = set()
audio_loop = None

# ---- ALSA Loopback writer ----
_alsa_loopback_running = False
_alsa_loopback_device  = None
_tx_gain = 2.0  # TX audio gain multiplier
_tx_vu   = 0.0  # TX VU level 0.0-1.0   # set via /api/audio/loopback

def _tx_audio_writer(track, device):
    """Thread: receive audio frames from browser and write to ALSA output (TX)."""
    import sounddevice as sd, asyncio
    SR = 48000
    CHUNK = 960
    logger.info("[TX] writer started on device: %s", device)
    try:
        loop = asyncio.new_event_loop()
        with sd.OutputStream(
            device=device,
            samplerate=SR,
            channels=1,
            dtype="int16",
            blocksize=CHUNK,
            latency="low",
        ) as stream:
            while True:
                frame = loop.run_until_complete(track.recv())
                if frame is None:
                    break
                # Convert AudioFrame to int16
                audio_f = frame.to_ndarray().astype(np.float32)
                if audio_f.ndim > 1:
                    audio_f = audio_f.mean(axis=0)
                # Apply gain and convert to int16
                audio_i16 = np.clip(audio_f * _tx_gain, -32768, 32767).astype(np.int16)
                global _tx_vu; _tx_vu = min(1.0, float(np.abs(audio_i16).max()) / 32767.0)
                if len(audio_i16) < CHUNK:
                    audio_i16 = np.concatenate([audio_i16, np.zeros(CHUNK - len(audio_i16), dtype=np.int16)])
                elif len(audio_i16) > CHUNK:
                    audio_i16 = audio_i16[:CHUNK]
                stream.write(audio_i16.reshape(-1, 1))
    except Exception as e:
        logger.warning("[TX] writer stopped: %s", e)
    finally:
        logger.info("[TX] writer exited")

def _alsa_loopback_worker():
    """Thread: drain audio_fifo → ALSA loopback device."""
    import sounddevice as sd, time
    global _alsa_loopback_running, _alsa_loopback_device
    SR = 48000
    CHUNK = 4800  # 100ms chunks — smoother than 20ms for WSJTX
    PRE_BUFFER = SR // 2  # wait for 500ms of audio before starting
    logger.info("[LOOPBACK] writer started on device: %s", _alsa_loopback_device)
    try:
        # Pre-buffer — wait for audio to accumulate
        waited = 0.0
        while engine.audio_fifo_len() < PRE_BUFFER and waited < 2.0:
            time.sleep(0.05)
            waited += 0.05
        with sd.OutputStream(
            device=_alsa_loopback_device,
            samplerate=SR,
            channels=1,
            dtype="float32",
            blocksize=CHUNK,
            latency="high",
        ) as stream:
            while _alsa_loopback_running:
                # Use dedicated loopback fifo if populated, else silence
                fifo = engine._loopback_fifo
                with engine.audio_lock:
                    available = len(fifo)
                    take = min(CHUNK, available)
                    if take > 0:
                        audio = np.array(list(islice(fifo, take)), dtype=np.float32)
                        for _ in range(take):
                            fifo.popleft()
                    else:
                        audio = np.zeros(CHUNK, dtype=np.float32)
                if len(audio) < CHUNK:
                    audio = np.concatenate([audio, np.zeros(CHUNK - len(audio), dtype=np.float32)])
                try:
                    stream.write(audio.reshape(-1, 1))
                except Exception as we:
                    logger.warning("[LOOPBACK] write error: %s", we)
                    break
    except Exception as e:
        logger.warning("[LOOPBACK] writer stopped: %s", e)
    finally:
        _alsa_loopback_running = False
        logger.info("[LOOPBACK] writer exited")
@app.after_request
def add_cors(response):
    response.headers["Access-Control-Allow-Origin"] = "*"
    response.headers["Access-Control-Allow-Headers"] = "Content-Type"
    response.headers["Access-Control-Allow-Methods"] = "GET, POST, OPTIONS"
    return response

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
import pymysql



class QuietFilter(logging.Filter):
    def filter(self, r):
        msg = r.getMessage()
        return (
            "/api/state"        not in msg and
            "/api/spectrum"     not in msg and
            "/api/fft/snapshot" not in msg and
            "/api/spots"        not in msg
        )
@app.route("/")
def serve_root():
    global active_radio
    user     = request.args.get("user", "admin").strip().lower()
    radio_id = int(request.args.get("radio", 1))
    callsign = request.args.get("call", "ADMIN").strip().upper()
    user_radio_map[user] = radio_id
    active_radio = radio_id
    _register_instance(callsign)
    us = get_user_state(user)  # loads saved settings
    # Apply saved engine-level settings immediately
    engine.smoothing = us.get("smoothing", 0.0)
    engine.mode = us.get("mode", "USB")
    engine.bw   = us.get("bw", 2700)
    # Apply saved sample rate and AGC to engine
    threading.Thread(target=get_rigsync, args=(radio_id,), daemon=True).start()
    with open(os.path.join(BASE_DIR, "index.html")) as f:
        html = f.read()
    is_admin = True
    html = html.replace("</head>",
        f'<script>window._sdrBase="{("/sdr" if SERVER_PORT==8001 else "/sdr"+str(SERVER_PORT-8000))}";window._sdrUser="{user}";window._sdrIsAdmin={str(is_admin).lower()};'
        f'window._sdrCallsign="{callsign}";window._sdrRadio={radio_id};window.tQthLat=40.8142;window.tQthLon=-81.9387;</script></head>', 1)
    return html, 200, {"Content-Type": "text/html", "Cache-Control": "no-store"}
@app.route("/elmer")
def serve_elmer():
    return send_from_directory(BASE_DIR, "c1aud.html")


@app.route("/api/state")
def api_state():
    user = request.args.get("user", "admin").strip().lower()
    us = get_user_state(user)
    state = engine.get_state()
    # Override with per-user settings
    state.update({
        "mode":       us["mode"],
        "bw":         us["bw"],
        "span":       state["span"] if kiwi_mgr.connected else us["span"],
        "floor":      us["floor"],
        "wf_floor":   us["wf_floor"],
        "smoothing":  us["smoothing"],
        "hw_agc":     us.get("hw_agc", True),
        "hw_gain":    us.get("hw_gain", 20.0),
        "bias_tee":   us.get("bias_tee", False),
        "lo_offset_hz": us.get("lo_offset_hz", 0.0),
        "center_freq": us.get("center_freq", state["center_freq"]),
        "wf_palette":  us.get("wf_palette", "classic"),
        "fill":        us.get("fill", "gradient"),
        "memories":    us.get("memories", [None] * 20),
        "band_memories": us.get("band_memories", {}),
        "band_slot":   us.get("band_slot", {}),
        "kiwi_hosts":  us.get("kiwi_hosts", []),
        "use_kiwi":    kiwi_mgr.connected,
        "kiwi_wf_count": getattr(kiwi_mgr, "_wf_count", 0),
        "kiwi_host": f"{kiwi_mgr._hostname or kiwi_mgr._host}:{kiwi_mgr._port}" if kiwi_mgr._host else "",
        "kiwi_ok":     kiwi_mgr.connected,
        "tx_vu":       _tx_vu,
        "tx_gain":     _tx_gain,
    })
    return jsonify(state)
@app.route("/api/fft/snapshot")
def api_fft_snapshot():
    spec = engine.get_spectrum()
    bins = len(spec["mags"])
    sr   = spec["sample_rate"]
    cf   = spec["hw_center"]
    return jsonify({
        "timestamp":   time.time(),
        "center_freq": cf,
        "sample_rate": sr,
        "fft_size":    bins,
        "hz_per_bin":  sr / bins,
        "span":        engine.span,
        "freq_start":  cf - sr / 2.0,
        "freq_end":    cf + sr / 2.0,
        "mags":        spec["mags"],
        "mode":        spec["mode"],
        "bw":          spec["bw"],
    })
@app.route("/api/spots")
def api_spots():
    try:
        conn = pymysql.connect(
            host="localhost",
            user="ham",
            password="7388",
            database="station",
            charset="utf8mb4",
            cursorclass=pymysql.cursors.DictCursor,
            connect_timeout=3,
        )
        with conn:
            with conn.cursor() as cur:
                cur.execute("""SELECT id, DX, Spotter, Frequency, Band, Mode, Note, Country, Continent, DXCC, Webtime, Webdate, DXBearing, DXDistance, Backcolor, Forecolor, Latitude, Longitude FROM Spots WHERE Hide != '1' ORDER BY id DESC LIMIT 300""")
                spots = cur.fetchall()
        return jsonify({"ok": True, "spots": spots, "count": len(spots)})
    except Exception as e:
        logger.error("[SPOTS] db error: %s", e)
        return jsonify({"ok": False, "error": str(e)}), 500

@app.route("/api/kiwi", methods=["POST"])
def api_kiwi():
    if not KIWI_AVAILABLE:
        return jsonify({"ok": False, "error": "kiwiclient not installed"}), 500
    d = request.get_json(force=True)
    host     = d.get("host", "").strip()
    host = host.replace("http://", "").replace("https://", "").split("/")[0]
    port     = int(d.get("port", 8073))
    freq_khz = float(d.get("freq_khz", 14000))
    zoom     = int(d.get("zoom", 7))
    password = d.get("password", "")
    if not host:
        return jsonify({"ok": False, "error": "host required"}), 400
    try:
            import re as _re
            # If host is already an IP, skip DNS
            if _re.match(r'^\d+\.\d+\.\d+\.\d+$', host):
                resolved_ip = host
                logger.info("[KIWI] using IP directly: %s", host)
            else:
                # Resolve hostname in gevent context before passing to thread
                import subprocess as _sp
                try:
                    result = _sp.run(['getent', 'hosts', host], capture_output=True, text=True, timeout=5)
                    if result.returncode != 0:
                        return jsonify({"ok": False, "error": f"DNS failed for {host}"}), 400
                    resolved_ip = result.stdout.split()[0]
                    logger.info("[KIWI] resolved %s -> %s", host, resolved_ip)
                except Exception as e:
                    return jsonify({"ok": False, "error": f"DNS failed: {e}"}), 400
            sound = bool(d.get("sound", True))
            kiwi_mgr._want_audio = sound
            kiwi_mgr.connect(resolved_ip, port, freq_khz, zoom, password, engine, hostname=host)
            kiwi_mgr._hostname = host
            engine._pre_kiwi_driver = engine.device_driver
            engine.device_driver = "fake"  # pause local SDR while Kiwi active
            logger.info("[KIWI] connecting to %s:%d", host, port)
            # Save host to user settings
            username = request.args.get("user", "admin")
            us = load_user_settings(username)
            hosts = us.get("kiwi_hosts", [])
            host_port = f"{host}:{port}"
            # Deduplicate — remove any existing entry for same hostname
            hosts = [h for h in us.get("kiwi_hosts", []) if h.split(":")[0] != host]
            hosts.append(host_port)
            us["kiwi_hosts"] = hosts
            save_user_settings(username, us)
            # Update in-memory state
            if username in user_state:
                user_state[username]["kiwi_hosts"] = hosts
            logger.info("[KIWI] connecting to %s:%d", host, port)
            return jsonify({"ok": True, "kiwi_host": host_port, "kiwi_hosts": hosts})
    except Exception as e:
        logger.error("[KIWI] connect error: %s", e)
        return jsonify({"ok": False, "error": str(e)}), 500

@app.route("/api/kiwisdr_cached")
def api_kiwisdr_cached():
    """Serve pre-tested reachable KiwiSDR list."""
    import json as _json
    cache_file = os.path.join(BASE_DIR, "kiwi_reachable.json")
    try:
        with open(cache_file) as f:
            entries = _json.load(f)
        return jsonify({"ok": True, "entries": entries, "count": len(entries), "cached": True})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500

@app.route("/api/kiwisdr_list")
def api_kiwisdr_list():
    """Fetch and parse public KiwiSDR list."""
    import urllib.request, re
    try:
        req = urllib.request.Request(
            'http://kiwisdr.com/public/',
            headers={'User-Agent': 'Mozilla/5.0'}
        )
        html = urllib.request.urlopen(req, timeout=15).read().decode('utf-8')
        entries = []
        for block in re.findall(r'<div class=.cl-entry[^>]*>(.*?)</div>\s*</div>', html, re.DOTALL):
            url_m = re.search(r"href='(http://[^']+)'", block)
            name_m = re.search(r"<!-- name=(.*?) -->", block)
            gps_m = re.search(r"<!-- gps=\(([^,]+),\s*([^)]+)\) -->", block)
            users_m = re.search(r"<!-- users=(\d+) -->", block)
            users_max_m = re.search(r"<!-- users_max=(\d+) -->", block)
            offline_m = re.search(r"<!-- offline=(\w+) -->", block)
            snr_m = re.search(r"<!-- snr=(\d+)", block)
            if url_m and gps_m and offline_m and offline_m.group(1) == 'no':
                entries.append({
                    'url': url_m.group(1),
                    'name': name_m.group(1) if name_m else '',
                    'lat': float(gps_m.group(1)),
                    'lon': float(gps_m.group(2)),
                    'users': int(users_m.group(1)) if users_m else 0,
                    'users_max': int(users_max_m.group(1)) if users_max_m else 4,
                    'snr': int(snr_m.group(1)) if snr_m else 0,
                })
        return jsonify({"ok": True, "entries": entries, "count": len(entries)})
    except Exception as e:
        logger.error("[KIWI LIST] %s", e)
        return jsonify({"ok": False, "error": str(e)}), 500

@app.route("/api/kiwi_test")
def api_kiwi_test():
    """Quick TCP reachability test for a KiwiSDR host."""
    import socket as _socket
    host = request.args.get("host", "").strip()
    port = int(request.args.get("port", 8073))
    if not host:
        return jsonify({"ok": False, "error": "host required"}), 400
    try:
        sock = _socket.create_connection((host, port), timeout=3)
        sock.close()
        return jsonify({"ok": True, "reachable": True})
    except Exception as e:
        return jsonify({"ok": True, "reachable": False, "error": str(e)})

@app.route("/api/kiwi/clear_hosts", methods=["POST"])
def api_kiwi_clear_hosts():
    user = request.args.get("user", "admin").strip().lower()
    us = get_user_state(user)
    us["kiwi_hosts"] = []
    save_user_settings(user, us)
    return jsonify({"ok": True})

@app.route("/api/kiwi/remove_host", methods=["POST"])
def api_kiwi_remove_host():
    """Remove a failed KiwiSDR from saved hosts and kiwi_reachable.json."""
    import json as _json
    d = request.get_json(force=True)
    url = d.get("url", "").strip()  # full url e.g. http://host:port
    if not url:
        return jsonify({"ok": False, "error": "url required"}), 400
    # Normalize: extract host:port
    host_port = url.replace("http://", "").replace("https://", "").rstrip("/")
    host = host_port.split(":")[0]
    # Remove from user settings
    user = request.args.get("user", "admin").strip().lower()
    us = load_user_settings(user)
    before = us.get("kiwi_hosts", [])
    us["kiwi_hosts"] = [h for h in before if h.split(":")[0] != host]
    save_user_settings(user, us)
    if user in user_state:
        user_state[user]["kiwi_hosts"] = us["kiwi_hosts"]
    # Remove from kiwi_reachable.json
    cache_file = os.path.join(BASE_DIR, "kiwi_reachable.json")
    removed_cache = False
    try:
        with open(cache_file) as f:
            entries = _json.load(f)
        new_entries = [e for e in entries if host not in e.get("url", "")]
        if len(new_entries) != len(entries):
            with open(cache_file, "w") as f:
                _json.dump(new_entries, f)
            removed_cache = True
    except Exception as e:
        logger.warning("[KIWI] remove_host cache update failed: %s", e)
    logger.info("[KIWI] removed %s from saved hosts (cache=%s)", host, removed_cache)
    return jsonify({"ok": True, "kiwi_hosts": us["kiwi_hosts"], "removed_cache": removed_cache})

@app.route("/api/kiwi/disconnect", methods=["POST"])
def api_kiwi_disconnect():
    kiwi_mgr.disconnect()
    engine._kiwi_have_mags = False
    if hasattr(engine, '_pre_kiwi_driver'):
        engine.device_driver = engine._pre_kiwi_driver
    with engine.lock:
        engine.sample_rate = float(SAMPLE_RATE)
        engine.center_freq = float(engine.center_freq)  # keep current freq
    return jsonify({"ok": True})

# ---------------------------------------------------------------------------
# SDRConnect (nRSP-ST) API endpoints
# ---------------------------------------------------------------------------
@app.route("/api/sdrconn/connect", methods=["POST"])
def api_sdrconn_connect():
    d = request.get_json(force=True) or {}
    freq_hz  = int(d.get("freq_hz", 14074000))
    mode     = d.get("mode", "USB").upper()
    device   = d.get("device", None)   # None = auto
    try:
        sdrconn_client.connect(engine, device_name=device, freq_hz=freq_hz, mode=mode)
        engine.device_driver = "fake"   # pause local SDR while nRSP-ST active
        logger.info("[SDRCONN] /api/sdrconn/connect freq=%d mode=%s", freq_hz, mode)
        return jsonify({"ok": True, "freq_hz": freq_hz, "mode": mode})
    except Exception as e:
        logger.error("[SDRCONN] connect error: %s", e)
        return jsonify({"ok": False, "error": str(e)}), 500

@app.route("/api/sdrconn/disconnect", methods=["POST"])
def api_sdrconn_disconnect():
    sdrconn_client.disconnect()
    if hasattr(engine, '_pre_sdrconn_driver'):
        engine.device_driver = engine._pre_sdrconn_driver
    with engine.lock:
        engine.sample_rate = float(SAMPLE_RATE)
    return jsonify({"ok": True})

@app.route("/api/sdrconn/tune", methods=["POST"])
def api_sdrconn_tune():
    d = request.get_json(force=True) or {}
    freq_hz = int(d.get("freq_hz", 14074000))
    mode    = d.get("mode", None)
    sdrconn_client.tune(freq_hz, mode)
    return jsonify({"ok": True, "freq_hz": freq_hz})

@app.route("/api/sdrconn/status")
def api_sdrconn_status():
    return jsonify({
        "connected": sdrconn_client.connected,
        "freq_hz":   sdrconn_client._freq_hz,
        "mode":      sdrconn_client._mode,
        "device":    sdrconn_client._device,
    })

@app.after_request
def add_cors(response):
    response.headers["Access-Control-Allow-Origin"] = "*"
    response.headers["Access-Control-Allow-Headers"] = "Content-Type"
    response.headers["Access-Control-Allow-Methods"] = "GET, POST, OPTIONS"
    return response
@app.route("/api/tune", methods=["POST"])
def api_tune():
    data = request.get_json(force=True) or {}
    freq = data.get("freq")
    if not freq: return jsonify({"error": "missing freq"}), 400
    freq_hz = int(float(freq))
    tune_user     = data.get("user", "admin").strip().lower()
    tune_callsign = data.get("callsign", "ADMIN").strip().upper()
    radio_id = user_radio_map.get(tune_user, int(data.get("radio", 1)))
    logger.info("[TUNE] user=%s radio=%d freq=%d", tune_user, radio_id, freq_hz)
    global active_radio
    active_radio = radio_id
    # Update per-user center freq and persist
    get_user_state(tune_user)["center_freq"] = freq_hz
    save_user_settings(tune_user, get_user_state(tune_user))
    slice_only = bool(data.get("slice_only", False))
    # Update per-user center freq
    get_user_state(tune_user)["center_freq"] = freq_hz
    save_user_settings(tune_user, get_user_state(tune_user))
    if slice_only:
        # Within hardware BW — update display center only, no SDR retune
        engine.set_center_only(freq_hz)
    else:
        engine.tune(freq_hz)
        if kiwi_mgr.connected:
            _mode_map = {'usb':'usb','lsb':'lsb','am':'am','cw':'cw','cwu':'cw','cwl':'cw',
                         'nfm':'nfm','wfm':'am','pktusb':'usb','pktlsb':'lsb','dsb':'am'}
            _kiwi_mode = _mode_map.get(engine.mode.lower(), 'usb')
            kiwi_mgr.tune(freq_hz / 1000.0, _kiwi_mode)
    get_rigsync(radio_id).request_tune(freq_hz, tune_user)
    # Broadcast notify
    notify = {"callsign": tune_callsign, "freq_hz": freq_hz}
    for ws_id in list(tune_notify.keys()):
        tune_notify[ws_id] = notify
    return jsonify({"ok": True, "freq": freq_hz, "hw_retuned": True})

@app.route("/api/control", methods=["POST"])
def api_control():
    data = request.get_json(force=True) or {}
    user = data.get("user", request.args.get("user", "admin")).strip().lower()
    us = get_user_state(user)
    if "mode" in data: us["mode"] = str(data["mode"]).strip().upper()
    if "bw" in data: us["bw"] = int(float(data["bw"]))
    if "smoothing" in data: us["smoothing"] = float(data["smoothing"])
    if "span" in data: us["span"] = float(data["span"])
    if "floor" in data: us["floor"] = float(data["floor"])
    if "wf_floor" in data: us["wf_floor"] = float(data["wf_floor"])
    # Also update engine for shared state
    if "mode" in data: engine.mode = us["mode"]
    if "bw" in data:
        engine.bw = us["bw"]
        bw_hz = min(float(engine.bw), 10000.0)
        t = np.arange(63) - 31.0
        fir = (np.sinc(2*bw_hz/48000.0*t) * np.hamming(63)).astype(np.float32)
        fir /= np.sum(fir)
        engine.audio_fir = fir
        engine.audio_zi_am = np.zeros(len(fir)-1, dtype=np.float32)
        am_bw = min(float(engine.bw), 8000.0)
        t_am = np.arange(63) - 31.0
        am_fir = (np.sinc(2*am_bw/48000.0*t_am) * np.hamming(63)).astype(np.float32)
        am_fir /= np.sum(am_fir)
        engine.am_audio_fir = am_fir
        engine.am_audio_zi = np.zeros(len(am_fir)-1, dtype=np.float32)
        import scipy.signal as _ss
        rf_bw = min(float(engine.bw) * 2.0, 20000.0)
        engine.am_rf_fir = _ss.firwin(101, rf_bw, fs=engine.sample_rate).astype(np.float32)
        engine.am_rf_zi = np.zeros(len(engine.am_rf_fir)-1, dtype=np.complex64)
    if "smoothing" in data: engine.smoothing = us["smoothing"]
    if "hw_agc" in data:
        us["hw_agc"] = bool(data["hw_agc"])
        def _set_agc():
            try:
                if engine.sdr:
                    engine.sdr.setGainMode(SOAPY_SDR_RX, 0, us["hw_agc"])
            except Exception as e:
                logger.warning("[CONTROL] AGC failed: %s", e)
        threading.Thread(target=_set_agc, daemon=True).start()
    if "hw_gain" in data:
        us["hw_gain"] = float(data["hw_gain"])
        def _set_gain():
            try:
                if engine.sdr:
                    engine.sdr.setGainMode(SOAPY_SDR_RX, 0, False)
                    engine.sdr.setGain(SOAPY_SDR_RX, 0, us["hw_gain"])
            except Exception as e:
                logger.warning("[CONTROL] gain failed: %s", e)
        threading.Thread(target=_set_gain, daemon=True).start()
    if "bias_tee" in data:
        us["bias_tee"] = bool(data["bias_tee"])
        def _set_bias():
            try:
                if engine.sdr:
                    engine.sdr.writeSetting("biastee", "1" if us["bias_tee"] else "0")
            except Exception as e:
                logger.warning("[CONTROL] bias_tee failed: %s", e)
        threading.Thread(target=_set_bias, daemon=True).start()
    if "lo_offset_hz" in data:
        us["lo_offset_hz"] = float(data["lo_offset_hz"])
        def _set_lo():
            try:
                if engine.sdr:
                    engine.sdr.setFrequency(SOAPY_SDR_RX, 0, "OFFSET", us["lo_offset_hz"])
            except Exception as e:
                logger.warning("[CONTROL] lo_offset failed: %s", e)
        threading.Thread(target=_set_lo, daemon=True).start()
    if "wf_palette" in data:
        us["wf_palette"] = str(data["wf_palette"])
    if "fill" in data:
        us["fill"] = str(data["fill"])
    if "memories" in data:
        us["memories"] = data["memories"]
    if "band_memories" in data:
        us["band_memories"] = data["band_memories"]
    if "band_slot" in data:
        us["band_slot"] = data["band_slot"]
    # Persist user settings
    save_user_settings(user, us)
    if "sample_rate" in data:
        new_rate = float(data["sample_rate"])
        us["sample_rate"] = new_rate
        def _set_rate():
            try:
                if engine.sdr:
                    engine.sdr.setSampleRate(SOAPY_SDR_RX, 0, new_rate)
                    actual = float(engine.sdr.getSampleRate(SOAPY_SDR_RX, 0))
                    engine.sample_rate = actual
                    engine.span = actual
                    us["sample_rate"] = actual
                    save_user_settings(user, us)  # save after confirmed
                    logger.info("[CONTROL] sample_rate set to %.0f", actual)
            except Exception as e:
                logger.error("[CONTROL] sample_rate failed: %s", e)
        threading.Thread(target=_set_rate, daemon=True).start()
    state = engine.get_state()
    _kiwi_keys = ("mode","bw","floor","wf_floor","smoothing","memories","band_memories","band_slot")
    _all_keys = _kiwi_keys + ("span",)
    state.update({k: us[k] for k in (_kiwi_keys if kiwi_mgr.connected else _all_keys)})
    return jsonify({"ok": True, "state": state})

INSTANCES_FILE = "/home/pi/sdr_web/config/instances.json"
_active_callsign = ""

def _register_instance(callsign=""):
    global _active_callsign
    if callsign: _active_callsign = callsign
    try:
        instances = []
        if os.path.exists(INSTANCES_FILE):
            with open(INSTANCES_FILE) as f: instances = json.load(f)
        found = False
        for inst in instances:
            if inst["port"] == SERVER_PORT:
                inst.update({"label": f"SDR @ {SERVER_PORT}", "device": engine.device_driver, "device_serial": engine.device_serial,
                             "active": True, "last_seen": time.time(), "in_use_by": _active_callsign})
                found = True; break
        if not found:
            instances.append({"port": SERVER_PORT, "label": f"SDR @ {SERVER_PORT}",
                              "device": engine.device_driver, "active": True,
                              "last_seen": time.time(), "in_use_by": _active_callsign})
        with open(INSTANCES_FILE, "w") as f: json.dump(instances, f, indent=2)
    except Exception as e: logger.warning("[INST] register error: %s", e)

def _get_all_instances():
    try:
        if os.path.exists(INSTANCES_FILE):
            with open(INSTANCES_FILE) as f: return json.load(f)
    except Exception: pass
    return []

@app.route("/api/instances")
def api_instances():
    instances = _get_all_instances()
    result = []
    for inst in instances:
        entry = dict(inst)
        entry["current"] = (inst["port"] == SERVER_PORT)
        result.append(entry)
    if not any(i["port"] == SERVER_PORT for i in result):
        result.append({"port": SERVER_PORT, "label": f"SDR @ {SERVER_PORT}",
                        "device": engine.device_driver, "current": True,
                        "in_use_by": _active_callsign, "last_seen": time.time()})
    return jsonify(result)

@app.route("/api/device", methods=["POST"])
def api_device():
    data = request.get_json(force=True) or {}
    driver = data.get("driver", "").strip().lower()
    serial = data.get("serial", "").strip()
    logger.info("[DEVICE] switch to driver=%s serial=%s", driver, serial)
    if not driver:
        return jsonify({"error": "missing driver"}), 400
    # Run device switch in background thread — SoapySDR C calls block GIL
    def _do_switch():
        try:
            engine.switching = True
            engine._close_stream()
            time.sleep(2.0)  # wait for SoapySDR to fully release device
            if driver == "fake":
                engine.device_driver = "fake"
                engine.sdr = None
                engine.rx_stream = None
            else:
                engine._reopen_device(driver, serial)
            logger.info("[DEVICE] switched to %s", engine.device_driver)
        except Exception as e:
            logger.error("[DEVICE] switch failed: %s", e)
            engine.device_driver = "fake"
            engine.sdr = None
            engine.rx_stream = None
        finally:
            engine._fail_count_reset = True  # signal run loop to reset fail count
            engine.switching = False
    threading.Thread(target=_do_switch, daemon=True).start()
    return jsonify({"ok": True, "sample_rate": engine.sample_rate,
                    "sample_rates": [engine.sample_rate]})

@app.route("/api/audio/state")
def api_audio_state():
    active = any(
        getattr(pc, "connectionState", "closed") in ("connected", "connecting", "new")
        for pc in pcs
    )
    return jsonify({"running": active, "count": len(pcs)})

@app.route("/api/audio/devices")
def api_audio_devices():
    """Return available ALSA source (input) and sink (output) devices."""
    try:
        import sounddevice as sd
        sources = []
        sinks   = []
        for i, d in enumerate(sd.query_devices()):
            name = d["name"]
            if d["max_input_channels"] > 0:
                sources.append({"index": i, "name": name})
            if d["max_output_channels"] > 0:
                sinks.append({"index": i, "name": name})
        return jsonify({"sources": sources, "sinks": sinks})
    except Exception as e:
        logger.warning("[AUDIO] query_devices failed: %s", e)
        return jsonify({"sources": [], "sinks": []})

@app.route("/api/audio/loopback", methods=["GET", "POST"])
def api_audio_loopback():
    global _alsa_loopback_running, _alsa_loopback_device
    if request.method == "GET":
        import sounddevice as sd
        devs = []
        try:
            for i, d in enumerate(sd.query_devices()):
                if d["max_output_channels"] > 0:
                    devs.append({"index": i, "name": d["name"]})
        except Exception as e:
            logger.warning("[LOOPBACK] query_devices failed: %s", e)
        return jsonify({
            "running": _alsa_loopback_running,
            "device": _alsa_loopback_device,
            "devices": devs,
        })
    # POST — start or stop
    data = request.get_json(force=True) or {}
    action = data.get("action", "start")
    if action == "stop":
        _alsa_loopback_running = False
        return jsonify({"ok": True, "running": False})
    # Start
    device = data.get("device", None)
    _alsa_loopback_device = device
    if _alsa_loopback_running:
        _alsa_loopback_running = False
        import time; time.sleep(0.1)
    _alsa_loopback_running = True
    import threading
    threading.Thread(target=_alsa_loopback_worker, daemon=True).start()
    return jsonify({"ok": True, "running": True, "device": device})

@app.route("/api/audio/tx_gain", methods=["POST"])
def api_audio_tx_gain():
    global _tx_gain
    data = request.get_json(force=True) or {}
    _tx_gain = float(data.get("gain", 8.0))
    logger.info("[TX] gain set to %.1f", _tx_gain)
    return jsonify({"ok": True, "gain": _tx_gain})

import queue as _queue_mod

def _tx_audio_writer_q(q, device):
    """Thread: consume audio frames from queue and write to ALSA output (TX)."""
    import sounddevice as sd
    SR = 48000
    CHUNK = 960
    frame_count = 0
    logger.info("[TX] writer_q started on device: %s", device)
    try:
        with sd.OutputStream(device=device, samplerate=SR, channels=1,
                             dtype="int16", blocksize=CHUNK, latency="low") as stream:
            while True:
                try:
                    frame = q.get(timeout=30.0)
                except _queue_mod.Empty:
                    logger.info("[TX] queue empty, continuing...")
                    continue
                audio_f = frame.to_ndarray().astype(float)
                if audio_f.ndim > 1:
                    audio_f = audio_f.mean(axis=0)
                audio_i16 = np.clip(audio_f * _tx_gain, -32768, 32767).astype(np.int16)
                global _tx_vu; _tx_vu = min(1.0, float(np.abs(audio_i16).max()) / 32767.0)
                if len(audio_i16) < CHUNK:
                    audio_i16 = np.concatenate([audio_i16, np.zeros(CHUNK - len(audio_i16), dtype=np.int16)])
                elif len(audio_i16) > CHUNK:
                    audio_i16 = audio_i16[:CHUNK]
                stream.write(audio_i16.reshape(-1, 1))
                frame_count += 1
                if frame_count % 500 == 0:
                    logger.info("[TX] frames=%d max=%d", frame_count, abs(audio_i16).max())
    except Exception as e:
        logger.warning("[TX] writer_q stopped: %s", e)
    finally:
        logger.info("[TX] writer_q exited frames=%d", frame_count)

def _start_tx_pipeline(track, device):
    """Start TX pipeline from within event loop."""
    q = _queue_mod.Queue(maxsize=100)
    import threading
    threading.Thread(target=_tx_audio_writer_q, args=(q, device), daemon=True).start()
    async def _feed():
        logger.info("[TX] _feed started")
        count = 0
        while True:
            try:
                frame = await asyncio.wait_for(track.recv(), timeout=10.0)
                count += 1
                try: q.put_nowait(frame)
                except _queue_mod.Full: pass
            except asyncio.TimeoutError:
                logger.warning("[TX] _feed recv timeout after %d frames", count)
                break
            except Exception as e:
                logger.info("[TX] _feed ended after %d frames: %s", count, e)
                break
    asyncio.ensure_future(_feed())

@app.route("/api/audio/tx_offer", methods=["POST"])
def api_audio_tx_offer():
    """Receive-only peer connection for browser mic → Pi CODEC (TX)."""
    params     = request.get_json(force=True) or {}
    offer_sdp  = params.get("sdp", "")
    offer_type = params.get("type", "")
    tx_device  = params.get("tx_device", None)
    logger.info("[TX] tx_offer received tx_device=%s", tx_device)
    if not offer_sdp or offer_type != "offer":
        return jsonify({"error": "bad offer"}), 400
    async def handle_tx():
        from aiortc import RTCConfiguration, RTCIceServer
        pc = RTCPeerConnection(RTCConfiguration(
            iceServers=[RTCIceServer(urls="stun:stun.l.google.com:19302")]))
        pcs.add(pc)
        @pc.on("connectionstatechange")
        async def on_state():
            if pc.connectionState in ("failed", "closed"):
                await pc.close(); pcs.discard(pc)
        @pc.on("track")
        def on_track(track):
            logger.info("[TX] on_track kind=%s device=%s", track.kind, tx_device)
            if track.kind == "audio" and tx_device is not None:
                _start_tx_pipeline(track, tx_device)
        await pc.setRemoteDescription(RTCSessionDescription(sdp=offer_sdp, type=offer_type))
        answer = await pc.createAnswer()
        await pc.setLocalDescription(answer)
        return {"sdp": pc.localDescription.sdp, "type": pc.localDescription.type}
    try:
        future = asyncio.run_coroutine_threadsafe(handle_tx(), audio_loop)
        return jsonify(future.result(timeout=20))
    except Exception as e:
        logger.exception("[TX] tx_offer failed")
        return jsonify({"error": repr(e)}), 500

@app.route("/api/audio/offer", methods=["POST"])
def api_audio_offer():
    params = request.get_json(force=True) or {}
    offer_sdp  = params.get("sdp", "")
    offer_type = params.get("type", "")
    tx_device  = params.get("tx_device", None)  # ALSA device index for TX output
    if not offer_sdp or offer_type != "offer":
        return jsonify({"error": "bad offer"}), 400
    async def handle_offer():
        from aiortc import RTCConfiguration, RTCIceServer
        pc = RTCPeerConnection(RTCConfiguration(
            iceServers=[RTCIceServer(urls="stun:stun.l.google.com:19302")]))
        pcs.add(pc)
        @pc.on("connectionstatechange")
        async def on_state():
            if pc.connectionState in ("failed", "closed"):
                await pc.close(); pcs.discard(pc)

        # Handle incoming audio track from browser (TX)
        @pc.on("track")
        def on_track(track):
            if track.kind == "audio" and tx_device is not None:
                import threading
                threading.Thread(
                    target=_tx_audio_writer,
                    args=(track, tx_device),
                    daemon=True
                ).start()
                logger.info("[TX] Incoming audio track, writing to device %s", tx_device)

        pc.addTrack(EngineAudioTrack(engine))
        await pc.setRemoteDescription(RTCSessionDescription(sdp=offer_sdp, type=offer_type))
        answer = await pc.createAnswer()
        await pc.setLocalDescription(answer)
        return {"sdp": pc.localDescription.sdp, "type": pc.localDescription.type}
    try:
        future = asyncio.run_coroutine_threadsafe(handle_offer(), audio_loop)
        return jsonify(future.result(timeout=20))
    except Exception as e:
        logger.exception("[AUDIO] offer failed")
        return jsonify({"error": repr(e)}), 500

@app.route("/api/span", methods=["POST"])
def api_span():
    data = request.get_json(force=True) or {}
    if "span" in data:
        engine.span = float(data["span"])
    return jsonify({"ok": True})

@app.route("/DS-DIGIT.ttf")
def font(): return send_from_directory(BASE_DIR, "DS-DIGIT.ttf")

@app.route("/three.min.js")
def three(): return send_from_directory(BASE_DIR, "three.min.js")
@app.route("/world_map.jpg")
def world_map(): return send_from_directory(BASE_DIR, "world_map.jpg")

@app.route("/proxy/spot_filter")
def proxy_spot_filter():
    """Proxy GetSpotFilter.php to avoid CORS issues."""
    try:
        import urllib.request
        with urllib.request.urlopen("http://localhost/programs/GetSpotFilter.php", timeout=3) as r:
            data = r.read()
        resp = app.response_class(data, mimetype="application/json")
        resp.headers["Access-Control-Allow-Origin"] = "*"
        return resp
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500

@app.route("/proxy/spot_list", methods=["POST"])
def proxy_spot_list():
    """Proxy getSpotList.php to avoid CORS issues."""
    try:
        import urllib.request, urllib.parse
        params = request.get_data()
        params += b"&format=json"
        req = urllib.request.Request(
            "http://localhost/programs/getSpotList.php",
            data=params,
            headers={"Content-Type": "application/x-www-form-urlencoded"}
        )
        with urllib.request.urlopen(req, timeout=5) as r:
            data = r.read()
        resp = app.response_class(data, mimetype="application/json")
        resp.headers["Access-Control-Allow-Origin"] = "*"
        return resp
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500
# ---- WebSocket ----
def spectrum_ws(ws):
    global ws_id_counter
    from urllib.parse import parse_qs
    qs = parse_qs(ws.environ.get("QUERY_STRING", ""))
    callsign = qs.get("call", ["ADMIN"])[0].upper()
    ws_user = qs.get("user", ["admin"])[0].lower()
    ws_id = ws_id_counter; ws_id_counter += 1
    tune_notify[ws_id] = None
    print(f"[WS] connected: {callsign} id={ws_id}")
    try:
        while True:
            spec = engine.get_spectrum()
            full = spec["mags"]
            mags = [round(v, 1) for v in full]  # send all bins
            us = get_user_state(ws_user)
            ws_radio = user_radio_map.get(ws_user, 1)
            rig_freq = rigsyncs[ws_radio].last_freq if ws_radio in rigsyncs else None
            payload = {
                "hw_center":   spec["hw_center"],
                "center":      spec["center"],
                "sample_rate": spec["sample_rate"],
                "fft_size":    len(full),
                "mags":        mags,
                "mode":        us["mode"],
                "bw":          us["bw"],
                "rig_freq":    rig_freq,
            }
            notify = tune_notify.get(ws_id)
            if notify:
                tune_notify[ws_id] = None
                payload.update({
                    "tune_notify": True,
                    "callsign":    notify["callsign"],
                    "freq_hz":     notify["freq_hz"],
                    "self_tune":   (callsign == notify["callsign"]),
                })
            ws.send(json.dumps(payload))
            gevent.sleep(0.02)
    except Exception as e:
        print(f"[WS] closed: {e}")
    finally:
        tune_notify.pop(ws_id, None)

def application(environ, start_response):
    if environ.get("PATH_INFO") == "/ws/spectrum":
        ws = environ.get("wsgi.websocket")
        if ws:
            spectrum_ws(ws)
            return []
    return app(environ, start_response)

# ---- Main ----
def _heartbeat():
    """Periodically re-enumerate devices so list stays current."""
    while True:
        time.sleep(30)
        try:
            engine._available_devices = engine._enumerate()
            _register_instance()
        except Exception:
            pass

if __name__ == "__main__":
    engine = SdrEngine()
    # Start asyncio loop for WebRTC audio
    import asyncio as _asyncio
    audio_loop = _asyncio.new_event_loop()
    _asyncio.set_event_loop(audio_loop)
    threading.Thread(target=audio_loop.run_forever, daemon=True).start()
    # If configured device not found at startup, retry in background
    if DEVICE_DRIVER and DEVICE_DRIVER != "fake" and engine.device_driver == "fake":
        def _retry_open():
            for attempt in range(10):
                time.sleep(5)
                if engine.device_driver != "fake":
                    return  # already opened by device switch
                try:
                    logger.info("[STARTUP] retry open %s attempt %d", DEVICE_DRIVER, attempt+1)
                    engine._reopen_device(DEVICE_DRIVER, DEVICE_SERIAL)
                    engine._fail_count_reset = True
                    logger.info("[STARTUP] retry succeeded: %s", engine.device_driver)
                    return
                except Exception as e:
                    logger.warning("[STARTUP] retry failed: %s", e)
                    engine.device_driver = "fake"
                    engine.sdr = None
                    engine.rx_stream = None
            logger.error("[STARTUP] gave up opening %s", DEVICE_DRIVER)
        threading.Thread(target=_retry_open, daemon=True).start()

    # Apply saved settings for default user at startup
    _default_user = _inst_cfg.get("default_user", "admin")
    _saved = load_user_settings(_default_user)
    if _saved.get("smoothing", 0.0) > 0:
        engine.smoothing = float(_saved["smoothing"])
        logger.info("[STARTUP] restored smoothing=%.4f", engine.smoothing)
    if _saved.get("sample_rate", 0) > 0:
        _saved_rate = float(_saved["sample_rate"])
        def _restore_rate():
            time.sleep(2.0)  # wait for device stream to stabilize
            try:
                if engine.sdr and abs(_saved_rate - engine.sample_rate) > 1000:
                    engine.sdr.setSampleRate(SOAPY_SDR_RX, 0, _saved_rate)
                    actual = float(engine.sdr.getSampleRate(SOAPY_SDR_RX, 0))
                    engine.sample_rate = actual
                    engine.span = actual
                    logger.info("[STARTUP] restored sample_rate=%.0f", actual)
            except Exception as e:
                logger.warning("[STARTUP] sample_rate restore failed: %s", e)
        threading.Thread(target=_restore_rate, daemon=True).start()
    if _saved.get("mode"):
        engine.mode = str(_saved["mode"])
        engine.agc_gain = 50.0
        logger.info("[STARTUP] restored mode=%s", engine.mode)
    if _saved.get("bw"):
        engine.bw = int(_saved["bw"])
        logger.info("[STARTUP] restored bw=%d", engine.bw)
    threading.Thread(target=_heartbeat, daemon=True).start()
    get_rigsync(1)  # pre-create radio 1

    from gevent.pywsgi import WSGIServer
    from geventwebsocket.handler import WebSocketHandler
    server = WSGIServer(("0.0.0.0", SERVER_PORT), application,
                        handler_class=WebSocketHandler)
    _register_instance()  # register immediately so instances.json is current before first request
    print(f"[SERVER] :{SERVER_PORT} device={engine.device_driver}")
    server.serve_forever()