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
import collections
import logging
import socket
import argparse
from typing import Optional
import fcntl
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
from SoapySDR import SOAPY_SDR_RX, SOAPY_SDR_CF32, SOAPY_SDR_S16
from rx888_native import RX888NativeSource
from rx888_audio import RX888AudioProcessor
from spectrum_engine import ZoomSpectrumProcessor

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
        # Use Kaiser window for resampling — reduces ringing on CW key edges
        audio48 = scipy.signal.resample_poly(
            audio, 4, 1,
            window=('kaiser', 8.0)
        ).astype(np.float32)
        # Soft-clip to prevent resampler overshoot artifacts
        audio48 = np.tanh(audio48 * 1.5) / 1.5
        EngineAudioTrack.broadcast(audio48, self._engine)
        _m = _get_ft8_mgr()
        if _m is not None and _m.is_running:
            try: _m.send_audio(audio48)
            except Exception: pass
        # Feed loopback fifo (TX path — keep separate)
        if _alsa_loopback_running and hasattr(self._engine, '_loopback_fifo'):
            with self._engine.audio_lock:
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
            if self._engine.smoothing > 0 and hasattr(self._engine, '_kiwi_have_mags') and self._engine._kiwi_have_mags and len(self._engine.latest_mags) == len(mags):
                self._engine.latest_mags = (self._engine.smoothing * mags +
                    (1 - self._engine.smoothing) * self._engine.latest_mags)
            else:
                self._engine.latest_mags = mags
                self._engine._kiwi_have_mags = True
            self._engine.center_freq = center_hz
            if engine.device_driver != "rx888":
                self._engine.span = span_hz
        logger.info("[KIWI WF] seq=%d bins=%d center=%.0f span=%.0f",
                     seq, len(mags), center_hz, span_hz)

# ─────────────────────────────────────────────────────────────────────────────
# OpenWebRX+ waterfall client
# ─────────────────────────────────────────────────────────────────────────────

# IMA ADPCM decoder (ported from OpenWebRX AudioEngine.js)
_IMA_INDEX_TABLE = [-1,-1,-1,-1,2,4,6,8,-1,-1,-1,-1,2,4,6,8]
_IMA_STEP_TABLE = [
    7,8,9,10,11,12,13,14,16,17,19,21,23,25,28,31,34,37,41,45,
    50,55,60,66,73,80,88,97,107,118,130,143,157,173,190,209,230,
    253,279,307,337,371,408,449,494,544,598,658,724,796,876,963,
    1060,1166,1282,1411,1552,1707,1878,2066,2272,2499,2749,3024,
    3327,3660,4026,4428,4871,5358,5894,6484,7132,7845,8630,9493,
    10442,11487,12635,13899,15289,16818,18500,20350,22385,24623,
    27086,29794,32767
]

def _ima_adpcm_decode(data):
    """Decode IMA ADPCM bytes to int16 numpy array (plain decode, reset each frame)."""
    import numpy as np
    step_index = 0
    predictor = 0
    output = []

    def decode_nibble(nibble):
        nonlocal step_index, predictor
        step = _IMA_STEP_TABLE[step_index]
        step_index = max(0, min(len(_IMA_STEP_TABLE)-1, step_index + _IMA_INDEX_TABLE[nibble]))
        diff = step >> 3
        if nibble & 1: diff += step >> 2
        if nibble & 2: diff += step >> 1
        if nibble & 4: diff += step
        if nibble & 8: predictor -= diff
        else:          predictor += diff
        predictor = max(-32768, min(32767, predictor))
        return predictor

    for b in data:
        output.append(decode_nibble(b & 0x0F))
        output.append(decode_nibble((b >> 4) & 0x0F))

    return np.array(output, dtype=np.int16)

class OpenWebRXClient:
    """Connects to an OpenWebRX+ server and feeds waterfall data into engine."""

    def __init__(self):
        self._thread         = None
        self._engine         = None
        self._url            = None
        self._freq_hz        = 14074000
        self._mode           = "usb"
        self._running        = False
        self.connected       = False
        self._fft_size       = 1024
        self._center_hz      = 14074000
        self._span_hz        = 1000000
        self._demod_active   = False
        self._last_tune_time = 0.0
        self._adpcm_step_index = 0
        self._adpcm_predictor  = 0
        self._adpcm_sync_frames = 0

    def _adpcm_decode_stateful(self, data):
        """Decode IMA ADPCM maintaining state across frames."""
        output = []
        def decode_nibble(nibble):
            step = _IMA_STEP_TABLE[self._adpcm_step_index]
            self._adpcm_step_index = max(0, min(len(_IMA_STEP_TABLE)-1,
                                                self._adpcm_step_index + _IMA_INDEX_TABLE[nibble]))
            diff = step >> 3
            if nibble & 1: diff += step >> 2
            if nibble & 2: diff += step >> 1
            if nibble & 4: diff += step
            if nibble & 8: self._adpcm_predictor -= diff
            else:          self._adpcm_predictor += diff
            self._adpcm_predictor = max(-32768, min(32767, self._adpcm_predictor))
            return self._adpcm_predictor
        for b in data:
            output.append(decode_nibble(b & 0x0F))
            output.append(decode_nibble((b >> 4) & 0x0F))
        import numpy as np
        return np.array(output, dtype=np.int16)

    async def _keepalive(self, ws, mode):
        """Send periodic startdemodulator to keep DSP active."""
        import asyncio as _asyncio
        if getattr(self, '_keepalive_running', False):
            logger.debug("[OWRX] keepalive already running, skipping duplicate")
            return
        self._keepalive_running = True
        first = True
        try:
            while self._running and self.connected:
                # Sleep shorter if tune pending
                await _asyncio.sleep(1 if (first or getattr(self, '_pending_tune', False)) else 60)
                first = False
                if not self._running or not self.connected:
                    break
                if getattr(self, '_pending_tune', False):
                    await ws.send(json.dumps({
                        "type": "setfrequency",
                        "params": {"frequency": self._freq_hz, "key": ""}
                    }))
                    self._pending_tune = False
                    self._adpcm_step_index = 0
                    self._adpcm_predictor = 0
                    self._adpcm_sync_frames = 0
                    # Clear fifo to flush old-frequency audio
                    if self._engine is not None:
                        try: self._engine.audio_fifo.clear()
                        except Exception: pass
                    await ws.send(json.dumps({"type": "startdemodulator"}))
                else:
                    # No-op keepalive — dspcontrol resets DSP and causes audio glitch
                    pass
                logger.debug("[OWRX] keepalive sent")
        except Exception as e:
            logger.debug("[OWRX] keepalive ended: %s", e)
        finally:
            self._keepalive_running = False

    def set_freq(self, freq_hz):
        """Update frequency — will be sent to OWRX on next keepalive."""
        import time as _st, traceback as _tb
        self._freq_hz = freq_hz
        self._last_tune_time = _st.monotonic()
        self._pending_tune = True
        logger.debug("[OWRX] set_freq called freq=%d stack=%s", freq_hz, ''.join(_tb.format_stack()[-3:-1]).replace('\n',' '))

    def connect(self, engine, url, freq_hz=14074000, mode="usb"):
        self.disconnect()
        self._engine  = engine
        self._url     = url
        self._freq_hz = int(freq_hz)
        self._mode    = mode
        self._running = True
        self._thread  = threading.Thread(target=self._run, daemon=True)
        self._thread.start()
        logger.info("[OWRX] connect url=%s freq=%d", url, freq_hz)

    def disconnect(self):
        self._running = False
        self.connected = False
        self._demod_active = False
        self._thread = None
        if self._engine is not None:
            try: self._engine.audio_fifo.clear()
            except Exception: pass
        logger.info("[OWRX] disconnected")

    def tune(self, freq_hz, mode=None):
        self._freq_hz = int(freq_hz)
        if mode:
            self._mode = mode

    def _run(self):
        import asyncio
        asyncio.run(self._async_run())

    async def _async_run(self):
        import asyncio, json, struct, numpy as np
        try:
            import websockets
        except ImportError:
            logger.error("[OWRX] websockets not installed")
            return

        # Build WS URL from HTTP URL
        base = self._url.rstrip('/')
        ws_url = base.replace('https://', 'wss://').replace('http://', 'ws://') + '/ws/'
        logger.info("[OWRX] connecting to %s", ws_url)

        try:
            async with websockets.connect(
                ws_url,
                additional_headers={"Origin": self._url},
                ping_interval=None,
                open_timeout=15,
                close_timeout=5,
            ) as ws:
                self.connected = True
                logger.info("[OWRX] WS connected to %s", ws_url)
                # Required handshake - send first message
                await ws.send("SERVER DE CLIENT client=openwebrx.js type=receiver")
                # Small delay to let server process and start sending
                await asyncio.sleep(0.1)
                await ws.send(json.dumps({
                    "type": "connectionproperties",
                    "params": {"output_rate": 48000, "hd_output_rate": 48000}
                }))
                fft_compression = "none"
                fft_size = 1024
                logger.info("[OWRX] handshake sent, starting recv loop")
                import time as _owrx_time
                _connect_time = _owrx_time.monotonic()

                while self._running:
                    try:
                        msg = await asyncio.wait_for(ws.recv(), timeout=10.0)
                    except asyncio.TimeoutError:
                        logger.debug("[OWRX] recv timeout - still alive")
                        continue
                    except Exception as e:
                        logger.warning("[OWRX] recv error: %s", e)
                        break

                    # Fallback: activate demod after 3s if profiles/sdr_ready never arrives
                    _elapsed = _owrx_time.monotonic() - _connect_time
                    logger.debug("[OWRX] fallback check: demod=%s elapsed=%.1f", self._demod_active, _elapsed)
                    if not self._demod_active and _elapsed > 3.0:
                        logger.info("[OWRX] fallback demod activation")
                        _fmode = self._mode.lower()
                        if _fmode not in ("usb","lsb","am","fm","cw","nfm","wfm"): _fmode = "usb"
                        try:
                            await ws.send(json.dumps({"type": "setfrequency",
                                "params": {"frequency": self._freq_hz, "key": ""}}))
                            await ws.send(json.dumps({"type": "dspcontrol",
                                "params": {"output_rate": 48000, "hd_output_rate": 48000,
                                           "offset_freq": 0, "mod": _fmode,
                                           "low_cut": 300 if _fmode=="usb" else -3000,
                                           "high_cut": 3000 if _fmode!="lsb" else -300,
                                           "dmr_filter": 3}}))
                            await ws.send(json.dumps({"type": "startdemodulator"}))
                            self._demod_active = True
                            self._adpcm_step_index = 0
                            self._adpcm_predictor = 0
                            self._adpcm_sync_frames = 0
                            asyncio.ensure_future(self._keepalive(ws, _fmode))
                            logger.info("[OWRX] fallback demod active mode=%s", _fmode)
                        except Exception as _fe:
                            logger.warning("[OWRX] fallback demod error: %s", _fe)

                    if isinstance(msg, str):
                        # JSON config message
                        logger.debug("[OWRX] STR len=%d: %s", len(msg), msg[:150])
                        try:
                            d = json.loads(msg)
                            if isinstance(d, dict):
                                t = d.get('type', '')
                                if t == 'config':
                                    cfg = d.get('value', {})
                                    if cfg.get('fft_size') is not None:
                                        fft_size = int(cfg['fft_size'])
                                        self._fft_size = fft_size
                                    if cfg.get('fft_compression') is not None:
                                        fft_compression = cfg['fft_compression']
                                    if cfg.get('center_freq') is not None:
                                        self._center_hz = int(cfg['center_freq'])
                                    if cfg.get('samp_rate') is not None:
                                        self._span_hz = int(cfg['samp_rate'])
                                    logger.info("[OWRX] config fft_size=%d compression=%s center=%d span=%d",
                                                fft_size, fft_compression, self._center_hz, self._span_hz)
                                elif t == 'receiver_details':
                                    logger.info("[OWRX] receiver_details received")
                                elif t == 'profiles':
                                    logger.info("[OWRX] profiles received, activating demod")
                                    if not self._demod_active:
                                        _pmode = self._mode.lower()
                                        if _pmode not in ("usb","lsb","am","fm","cw","nfm","wfm"): _pmode = "usb"
                                        await ws.send(json.dumps({"type": "setfrequency",
                                            "params": {"frequency": self._freq_hz, "key": ""}}))
                                        await ws.send(json.dumps({"type": "dspcontrol",
                                            "params": {"output_rate": 48000, "hd_output_rate": 48000,
                                                       "offset_freq": 0, "mod": _pmode,
                                                       "low_cut": 300 if _pmode=="usb" else -3000,
                                                       "high_cut": 3000 if _pmode!="lsb" else -300,
                                                       "dmr_filter": 3}}))
                                        await ws.send(json.dumps({"type": "startdemodulator"}))
                                        self._demod_active = True
                                        self._adpcm_step_index = 0
                                        self._adpcm_predictor = 0
                                        self._adpcm_sync_frames = 0
                                        asyncio.ensure_future(self._keepalive(ws, _pmode))
                                        logger.info("[OWRX] demod active via profiles mode=%s", _pmode)
                                elif t == 'sdr_ready':
                                    logger.info("[OWRX] sdr_ready received")
                                    if not self._demod_active:
                                        _smode = self._mode.lower()
                                        if _smode not in ("usb","lsb","am","fm","cw","nfm","wfm"): _smode = "usb"
                                        await ws.send(json.dumps({"type": "setfrequency",
                                            "params": {"frequency": self._freq_hz, "key": ""}}))
                                        await ws.send(json.dumps({"type": "dspcontrol",
                                            "params": {"output_rate": 48000, "hd_output_rate": 48000,
                                                       "offset_freq": 0, "mod": _smode,
                                                       "low_cut": 300 if _smode=="usb" else -3000,
                                                       "high_cut": 3000 if _smode!="lsb" else -300,
                                                       "dmr_filter": 3}}))
                                        await ws.send(json.dumps({"type": "startdemodulator"}))
                                        self._demod_active = True
                                        self._adpcm_step_index = 0
                                        self._adpcm_predictor = 0
                                        self._adpcm_sync_frames = 0
                                        asyncio.ensure_future(self._keepalive(ws, _smode))
                                        logger.info("[OWRX] demod active via sdr_ready mode=%s", _smode)
                        except Exception as e:
                            logger.warning("[OWRX] JSON parse error: %s", e)

                    elif isinstance(msg, bytes):
                        if len(msg) < 2:
                            continue
                        msg_type = msg[0]
                        data = msg[1:]

                        if msg_type == 1:
                            # FFT data
                            logger.debug("[OWRX] binary FFT len=%d compression=%s", len(data), fft_compression)
                            try:
                                import numpy as np
                                if fft_compression == "none":
                                    if len(data) < 2:
                                        continue
                                    waterfall_i16 = np.frombuffer(data, dtype=np.int16)
                                    waterfall_f32 = waterfall_i16.astype(np.float32) / 100.0
                                elif fft_compression == "adpcm":
                                    raw_i16 = _ima_adpcm_decode(data)
                                    pad = 10
                                    if len(raw_i16) <= pad:
                                        continue
                                    samples = raw_i16[pad:]
                                    if getattr(self, '_demod_active', False):
                                        # Demod active: push as audio using stateful decoder
                                        # Discard first 5 frames while ADPCM decoder syncs
                                        self._adpcm_sync_frames += 1
                                        if self._adpcm_sync_frames <= 5:
                                            self._adpcm_decode_stateful(data)  # run decoder to build state
                                            # Update engine center to tuned freq for display
                                            pass  # don't override user freq
                                            waterfall_f32 = samples.astype(np.float32) / 100.0
                                            continue
                                        # Decode ADPCM using running state — don't reset from block header
                                        audio_i16 = np.array(self._adpcm_decode_stateful(data[4:] if len(data) >= 4 else data), dtype=np.int16)
                                        # Crossfade first 32 samples with last frame's tail to smooth boundary
                                        _xfade = 32
                                        if hasattr(self, '_adpcm_last_tail') and self._adpcm_last_tail is not None and len(audio_i16) > _xfade:
                                            fade_in  = np.linspace(0, 1, _xfade, dtype=np.float32)
                                            fade_out = 1.0 - fade_in
                                            audio_i16[:_xfade] = (audio_i16[:_xfade].astype(np.float32) * fade_in +
                                                                   self._adpcm_last_tail.astype(np.float32) * fade_out).astype(np.int16)
                                        self._adpcm_last_tail = audio_i16[-_xfade:].copy() if len(audio_i16) >= _xfade else None
                                        audio_f32 = audio_i16.astype(np.float32) / 32768.0
                                        # DC blocking filter — removes predictor bias from ADPCM
                                        if not hasattr(self, '_dc_zi') or self._dc_zi is None:
                                            self._dc_zi = np.zeros(1)
                                        R = 0.9997
                                        audio_f32, self._dc_zi = scipy.signal.lfilter(
                                            [1, -1], [1, -R], audio_f32, zi=self._dc_zi
                                        )
                                        audio_f32 = audio_f32.astype(np.float32)
                                        eng = self._engine
                                        if eng is not None and len(audio_f32) > 0:
                                            # We request output_rate=48000 so no resampling needed
                                            resamp = audio_f32
                                            EngineAudioTrack.broadcast(resamp, eng)
                                            _m = _get_ft8_mgr()
                                            if _m is not None and _m.is_running:
                                                try: _m.send_audio(resamp)
                                                except Exception: pass
                                        # Also update waterfall with same data
                                        waterfall_f32 = samples.astype(np.float32) / 100.0
                                    else:
                                        waterfall_f32 = samples.astype(np.float32) / 100.0
                                    logger.debug("[OWRX] FFT bins=%d min=%.1f max=%.1f",
                                               len(waterfall_f32), float(waterfall_f32.min()), float(waterfall_f32.max()))
                                else:
                                    continue

                                eng = self._engine
                                if eng and len(waterfall_f32) > 0:
                                    with eng.lock:
                                        sm = getattr(eng, 'smoothing', 0.3)
                                        prev = getattr(eng, 'latest_mags', None)
                                        if sm > 0 and prev is not None and len(prev) == len(waterfall_f32):
                                            eng.latest_mags = sm * prev + (1 - sm) * waterfall_f32
                                        else:
                                            eng.latest_mags = waterfall_f32
                                            eng._have_mags = True
                                        # Don't update eng.center_freq from OWRX
                                        # Keep it at user's tuned frequency
                                        pass
                                        # Keep local sample_rate for display scaling
                                        pass
                            except Exception as e:
                                logger.warning("[OWRX] FFT parse error: %s", e)

        except Exception as e:
            logger.error("[OWRX] connection error: %s", e)
        finally:
            self.connected = False
            logger.info("[OWRX] WS closed")

owrx_client = OpenWebRXClient()

# FT8 decoder integration — lazy init on first tune
# udp_port: 7001 + instance offset — ft8modem listens on this port
# radio_id: 1 + instance offset — tags MySQL Spots rows
_FT8_INSTANCE_OFFSET = SERVER_PORT - 8001
_FT8_UDP_PORT  = 7001 + _FT8_INSTANCE_OFFSET
_FT8_RADIO_ID  = 1    + _FT8_INSTANCE_OFFSET
_ft8_mgr = None
def _get_ft8_mgr():
    global _ft8_mgr
    if _ft8_mgr is None:
        try:
            from ft8modem_manager import FT8Manager
            _ft8_mgr = FT8Manager(udp_port=_FT8_UDP_PORT, radio_id=_FT8_RADIO_ID)
            logger.info("[FT8] ft8modem_manager initialized udp_port=%d radio_id=%d",
                        _FT8_UDP_PORT, _FT8_RADIO_ID)
        except Exception as _e:
            logger.warning("[FT8] ft8modem_manager unavailable: %s", _e)
    return _ft8_mgr

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
                logger.debug("[KIWI] got waterfall data len=%d", len(data.get('mags',[])))
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
                    if engine.device_driver != "rx888":
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
        self._sample_rate = 2_000_000
        self._pending_sample_rate = None
        self._rate_lock = False
        self._cmd_queue = []
        self._antennas = []
        self._active_antenna = ""
        self._lna = 0
        self._is_connected = False
        self._proc      = None          # SDRConnect_headless subprocess

    # ------------------------------------------------------------------
    def connect(self, engine, device_name=None, freq_hz=14074000, mode="USB", sample_rate=2_000_000):
        self.disconnect()
        self._engine   = engine
        self._device   = device_name    # None = auto-select first nRSP-ST
        self._freq_hz  = freq_hz
        self._mode     = mode
        self._sample_rate = int(sample_rate)
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
        """Start SDRConnect headless service if not already running."""
        import subprocess
        # Check if already running
        try:
            result = subprocess.run(['pgrep', '-f', 'SDRconnect_headless'],
                                    capture_output=True, text=True)
            if result.returncode == 0:
                logger.info("[SDRCONN] headless already running pid=%s", result.stdout.strip())
                return True
        except Exception:
            pass
        # Start via systemd
        try:
            subprocess.run(['sudo', 'systemctl', 'start', 'sdrconnect-headless'],
                           capture_output=True, text=True, timeout=10)
            import time; time.sleep(3)
            logger.info("[SDRCONN] headless service started")
            return True
        except Exception as e:
            logger.error("[SDRCONN] failed to start headless service: %s", e)
            return False

    def _stop_headless(self):
        """Stop SDRConnect headless service."""
        import subprocess
        try:
            subprocess.run(['sudo', 'systemctl', 'stop', 'sdrconnect-headless'],
                           capture_output=True, text=True, timeout=10)
            logger.info("[SDRCONN] headless service stopped")
        except Exception as e:
            logger.error("[SDRCONN] failed to stop headless service: %s", e)

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

        import numpy as np, json, struct, time

        if not self._ensure_headless():
            return

        retry_delay = 2
        while self._running:
            try:
                async with websockets.connect(
                    self.SDRCONN_WS,
                    open_timeout=5,
                ) as ws:
                    self._is_connected = True
                    logger.info("[SDRCONN] WS connected")

                    if self._engine:
                        if self._device:
                            self._engine.device_serial = self._device

                    retry_delay = 2

                    # Get valid devices
                    await ws.send(json.dumps({
                        "event_type": "get_property",
                        "property": "valid_devices",
                        "value": ""
                    }))

                    # Wait for device list
                    device_name = self._device
                    deadline = 8
                    t0 = __import__('time').time()
                    while not device_name and (__import__('time').time() - t0) < deadline:
                        try:
                            msg = await asyncio.wait_for(ws.recv(), timeout=2)
                        except asyncio.TimeoutError:
                            await ws.send(json.dumps({"event_type":"get_property","property":"valid_devices","value":""}))
                            continue
                        if isinstance(msg, str):
                            try:
                                d = json.loads(msg)
                            except Exception:
                                continue
                            if d.get('property') == 'valid_devices':
                                devs = [
                                    x.strip()
                                    for x in d.get("value", "").split(",")
                                    if x.strip()
                                    and (
                                        "nRSP" in x
                                        or "IQ" in x
                                        or "File" in x
                                        or "Compact" in x
                                    )
                                ]
                                for dev in devs:
                                    if 'nRSP' in dev and 'IQ Lite' in dev:
                                        device_name = dev
                                        break
                                if not device_name and devs:
                                    device_name = devs[0]
                                logger.info("[SDRCONN] auto-selected device: %s", device_name)
                                self._device = device_name

                                if self._engine:
                                    self._engine.device_serial = device_name
                                if self._engine:
                                    self._engine.device_serial = device_name
                    if not device_name:
                        logger.error("[SDRCONN] no device found after %.1fs", __import__('time').time() - t0)
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

                    # Set sample rate first (TandemSDR pattern)
                    await ws.send(json.dumps({
                        "event_type": "set_property",
                        "property": "device_sample_rate",
                        "value": str(int(self._sample_rate))
                    }))
                    await asyncio.sleep(0.5)

                    # Set frequency and mode
                    await ws.send(json.dumps({
                        "event_type": "set_property",
                        "property": "device_center_frequency",
                        "value": str(self._freq_hz)
                    }))
                    await ws.send(json.dumps({
                        "event_type": "set_property",
                        "property": "device_vfo_frequency",
                        "value": str(self._freq_hz)
                    }))
                    await ws.send(json.dumps({
                        "event_type": "set_property",
                        "property": "demodulator",
                        "value": self._mode
                    }))

                    # Enable spectrum + audio
                    await ws.send(json.dumps({
                        "event_type": "spectrum_enable",
                        "property": "",
                        "value": "true"
                    }))
                    # Enable spectrum
                    await ws.send(json.dumps({
                        "event_type": "spectrum_enable",
                        "property": "",
                        "value": "true",
                    }))

                    # Enable raw primary-device IQ
                    # await ws.send(json.dumps({
                    #     "event_type": "iq_stream_enable",
                    #     "property": "",
                    #     "value": "true",
                    # }))

                    # Enable audio
                    await ws.send(json.dumps({
                        "event_type": "audio_stream_enable",
                        "property": "",
                        "value": "true",
                    }))
                    await ws.send(json.dumps({
                        "event_type": "audio_stream_enable",
                        "property": "",
                        "value": "true"
                    }))
                    logger.info("[SDRCONN] streaming started freq=%d mode=%s sr=%d",
                                self._freq_hz, self._mode, self._sample_rate)

                    # Queue antenna/lna info requests
                    self._cmd_queue.extend([
                        {"event_type": "get_property", "property": "valid_antennas", "value": ""},
                        {"event_type": "get_property", "property": "active_antenna", "value": ""},
                        {"event_type": "get_property", "property": "lna_state", "value": ""},
                        {"event_type": "get_property", "property": "lna_state_min", "value": ""},
                        {"event_type": "get_property", "property": "lna_state_max", "value": ""},
                    ])
                    # Main receive loop
                    async for msg in ws:
                        if not self._running:
                            break
                        while self._cmd_queue:
                            cmd = self._cmd_queue.pop(0)
                            await ws.send(json.dumps(cmd))
                            logger.info("[SDRCONN] sent cmd: %s=%s", cmd.get("property",""), cmd.get("value",""))
                        if isinstance(msg, bytes):
                            if len(msg) < 2:
                                continue

                            msg_type = struct.unpack_from("<H", msg, 0)[0]
                            payload = msg[2:]

                            # Temporary diagnostic, throttled.
                            now = time.monotonic()
                            last_log = getattr(
                                self,
                                "_binary_log_at",
                                0.0,
                            )
                            if now - last_log >= 5.0:
                                self._binary_log_at = now
                                logger.info(
                                    "[SDRCONN] binary type=%d total=%d payload=%d",
                                    msg_type,
                                    len(msg),
                                    len(payload),
                                )

                            if msg_type == 3:
                                # Spectrum: unsigned 8-bit FFT bins.
                                bins = np.frombuffer(
                                    payload,
                                    dtype=np.uint8,
                                ).astype(np.float32)

                                mags = (
                                    bins / 255.0 * 120.0 - 130.0
                                )

                                eng = self._engine
                                if eng:
                                    with eng.lock:
                                        sm = getattr(
                                            eng,
                                            "smoothing",
                                            0.3,
                                        )
                                        prev = getattr(
                                            eng,
                                            "latest_mags",
                                            None,
                                        )

                                        if (
                                            sm > 0
                                            and prev is not None
                                            and len(prev) == len(mags)
                                        ):
                                            eng.latest_mags = (
                                                sm * prev
                                                + (1.0 - sm) * mags
                                            )
                                        else:
                                            eng.latest_mags = mags
                                            eng._have_mags = True

                                        eng.center_freq = float(
                                            self._freq_hz
                                        )
                                        eng.sample_rate = float(
                                            self._sample_rate
                                        )

                                        eng.span = min(
                                            float(
                                                getattr(
                                                    eng,
                                                    "span",
                                                    self._sample_rate,
                                                )
                                            ),
                                            float(self._sample_rate),
                                        )

                            elif msg_type == 1:
                                # Audio: signed int16 stereo 48 kHz LRLR.
                                samples = np.frombuffer(
                                    payload,
                                    dtype="<i2",
                                )

                                mono = (
                                    samples[0::2].astype(np.float32)
                                    / 32768.0
                                )

                                eng = self._engine
                                if eng:
                                    EngineAudioTrack.broadcast(
                                        mono,
                                        eng,
                                    )

                                    ft8_manager = _get_ft8_mgr()
                                    if (
                                        ft8_manager is not None
                                        and ft8_manager.is_running
                                    ):
                                        try:
                                            ft8_manager.send_audio(
                                                mono
                                            )
                                        except Exception:
                                            pass

                                    if (
                                        _alsa_loopback_running
                                        and hasattr(
                                            eng,
                                            "_loopback_fifo",
                                        )
                                    ):
                                        eng._loopback_fifo.extend(
                                            mono.tolist()
                                        )

                            elif msg_type == 2:
                                # Primary-device IQ:
                                # signed little-endian int16, interleaved I,Q,I,Q...
                                iq_i16 = np.frombuffer(
                                    payload,
                                    dtype="<i2",
                                )

                                # A valid packet must contain complete I/Q pairs.
                                if iq_i16.size >= 2:
                                    if iq_i16.size & 1:
                                        iq_i16 = iq_i16[:-1]

                                    iq_pairs = iq_i16.size // 2

                                    now = time.monotonic()
                                    last_log = getattr(
                                        self,
                                        "_iq_measure_log_at",
                                        0.0,
                                    )

                                    if now - last_log >= 5.0:
                                        self._iq_measure_log_at = now

                                        i_samples = iq_i16[0::2]
                                        q_samples = iq_i16[1::2]

                                        logger.info(
                                            "[SDRCONN IQ] payload=%d bytes pairs=%d "
                                            "Imin=%d Imax=%d Qmin=%d Qmax=%d rate=%d",
                                            len(payload),
                                            iq_pairs,
                                            int(i_samples.min()),
                                            int(i_samples.max()),
                                            int(q_samples.min()),
                                            int(q_samples.max()),
                                            int(self._sample_rate),
                                        )

                        elif isinstance(msg, str):
                            # JSON property_changed / response.
                            try:
                                d = json.loads(msg)
                                prop = d.get("property", "")

                                if prop == "device_vfo_frequency":
                                    self._freq_hz = int(
                                        d.get(
                                            "value",
                                            self._freq_hz,
                                        )
                                    )

                                elif prop == "device_sample_rate":
                                    new_rate = int(
                                        float(
                                            d.get(
                                                "value",
                                                self._sample_rate,
                                            )
                                        )
                                    )

                                    self._sample_rate = new_rate
                                    self._pending_sample_rate = None
                                    self._rate_lock = False

                                    eng = self._engine
                                    if eng:
                                        with eng.lock:
                                            eng.sample_rate = float(
                                                new_rate
                                            )
                                            eng.span = min(
                                                float(
                                                    getattr(
                                                        eng,
                                                        "span",
                                                        new_rate,
                                                    )
                                                ),
                                                float(new_rate),
                                            )
                                            eng._last_spectrum_signature = None

                                    logger.info(
                                        "[SDRCONN] sample_rate confirmed %d",
                                        new_rate,
                                    )

                                elif prop == "valid_antennas":
                                    self._antennas = [
                                        antenna.strip()
                                        for antenna in d.get(
                                            "value",
                                            "",
                                        ).split(",")
                                        if antenna.strip()
                                    ]
                                    logger.info(
                                        "[SDRCONN] antennas: %s",
                                        self._antennas,
                                    )

                                elif prop == "active_antenna":
                                    self._active_antenna = d.get(
                                        "value",
                                        "",
                                    )
                                    logger.info(
                                        "[SDRCONN] active_antenna: %s",
                                        self._active_antenna,
                                    )

                                elif prop == "lna_state":
                                    self._lna = int(
                                        d.get("value", 0)
                                    )

                                elif prop == "lna_state_min":
                                    self._lna_min = int(
                                        d.get("value", 0)
                                    )

                                elif prop == "lna_state_max":
                                    self._lna_max = int(
                                        d.get("value", 19)
                                    )

                            except Exception as error:
                                logger.warning(
                                    "[SDRCONN] prop handler error: %s",
                                    error,
                                )

            except Exception as e:
                if self._running:
                    logger.warning("[SDRCONN] connection lost: %s — retry in %ds", e, retry_delay)
                    self._is_connected = False
                    await asyncio.sleep(retry_delay)
                    retry_delay = min(retry_delay * 2, 30)

        self._is_connected = False
        logger.info("[SDRCONN] _async_run exited")

    # ------------------------------------------------------------------
    def set_sample_rate(self, rate):
        """Queue sample rate change on persistent WS."""
        self._sample_rate = int(rate)
        self._cmd_queue.append({
            "event_type": "set_property",
            "property": "device_sample_rate",
            "value": str(int(rate))
        })
        logger.info("[SDRCONN] queued sample_rate=%d", rate)

    def set_mode(self, mode):
        """Queue demodulator mode change on persistent WS."""
        self._mode = str(mode).upper()
        self._cmd_queue.append({
            "event_type": "set_property",
            "property": "demodulator",
            "value": self._mode,
        })
        logger.info("[SDRCONN] queued mode=%s", self._mode)

    def set_lna(self, lna):
        """Queue LNA gain change."""
        self._lna = int(lna)
        self._cmd_queue.append({
            "event_type": "set_property",
            "property": "lna_state",
            "value": str(int(lna)),
        })
        logger.info("[SDRCONN] queued lna=%d", lna)

    def set_antenna(self, antenna):
        """Queue antenna change."""
        self._active_antenna = str(antenna)
        self._cmd_queue.append({
            "event_type": "set_property",
            "property": "active_antenna",
            "value": str(antenna)
        })
        logger.info("[SDRCONN] queued antenna=%s", antenna)

    def tune(self, freq_hz, mode=None):
        """Queue a frequency/mode change on the persistent SDRconnect WebSocket."""

        freq_hz = int(freq_hz)
        self._freq_hz = freq_hz

        if mode:
            self._mode = str(mode)

        logger.info(
            "[TIMING] SDRConnectClient.tune QUEUE t=%.3f freq=%d",
            time.monotonic(),
            freq_hz,
        )

        # For a band change, move both the SDR center and the demodulator VFO.
        self._cmd_queue.append({
            "event_type": "set_property",
            "property": "device_center_frequency",
            "value": str(freq_hz),
        })

        self._cmd_queue.append({
            "event_type": "set_property",
            "property": "device_vfo_frequency",
            "value": str(freq_hz),
        })

        if mode:
            self._cmd_queue.append({
                "event_type": "set_property",
                "property": "demodulator",
                "value": str(mode),
            })

        logger.info(
            "[TIMING] SDRConnectClient.tune RETURN t=%.3f freq=%d",
            time.monotonic(),
            freq_hz,
        )

        return True

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
                engine._audio_from_kiwi = True
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
        # Hardware input rate can differ from displayed spectrum width.
        # RX888 sends real samples: 64 MSPS represents 0 through 32 MHz.
        self.input_sample_rate = float(SAMPLE_RATE)
        self.real_adc_stream = False
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
        self.rx888_source = None
        self.running     = True
        self.switching   = False  # True during device switch
        self._fail_count_reset = False
        self.latest_mags = np.full(FFT_SIZE, -130.0, dtype=np.float32)
        self._have_mags  = False
        self.fft_window  = np.hanning(FFT_SIZE).astype(np.float32)
        self._fft_count  = 0
        # Optional shared server-side DDC/zoom FFT. Wideband processing remains
        # the fallback. RX888_ZOOM_FFT=0 remains supported for compatibility.
        self.zoom_fft_enabled = os.environ.get("RX888_ZOOM_FFT", "1").strip().lower() not in ("0", "false", "no", "off")
        self.zoom_processor = None
        self.zoom_active = False
        self.zoom_decimation = 1
        self.zoom_hz_per_bin = 0.0
        self.zoom_process_ms = 0.0
        self.zoom_fps = 0.0
        self._zoom_last_frame_at = 0.0
        self._last_rx888_audio_zoom_submit = 0.0
        self.zoom_center_hz = float(self.center_freq)
        # Actual demodulator frequency.  This is intentionally separate from
        # zoom_center_hz because the visible spectrum may be panned or may lag
        # briefly while a new zoom frame is generated.
        self.tuned_freq_hz = float(self.center_freq)
        self._zoom_queue = _stdlib_queue.Queue(maxsize=1)
        self._zoom_result_lock = threading.Lock()
        self._zoom_result = None
        self._zoom_worker_thread = None
        # Geometry signature of the spectrum currently in latest_mags.
        # A tune/span/zoom change must seed the next trace directly instead of
        # smoothing bins from the previous frequency into the new spectrum.
        self._last_spectrum_signature = None
        # Continuous RX888 audio path. DSP runs in a compiled helper so the
        # 64-MSPS USB stream can be drained without stalling acquisition.
        self.rx888_audio_processor = None
        self._rx888_audio_queue = _stdlib_queue.Queue(maxsize=2)
        self._rx888_audio_thread = None
        self.rx888_audio_active = False
        self.rx888_audio_process_ms = 0.0
        self.rx888_audio_overruns = 0
        # Audio FIFO
        self.audio_fifo   = deque(maxlen=int(2.0 * 48000))
        self.audio_lock   = threading.Lock()
        self._fifo_target = int(0.15 * 48000)
        self._fifo_max    = int(0.40 * 48000)
        self.agc_gain     = 50.0  # start high, AGC settles down
        self._build_filters()
        self._available_devices = []
        self._loopback_fifo = deque(maxlen=int(2.0 * 48000))
        self.sleeping = False
        self.idle_since = None
        self._open_device()
        if self.device_driver in ("rx888", "airspyhf") and self.zoom_processor is not None:
            self._zoom_worker_thread = threading.Thread(
                target=self._zoom_worker,
                daemon=True,
                name="sdr-zoom-fft",
            )
            self._zoom_worker_thread.start()
        if self.device_driver == "rx888":
            self._start_rx888_audio()

        if self.device_driver == "rx888":
            self._available_devices = [{
                "driver": "rx888",
                "label": "RX888 MkII",
                "serial": self.device_serial,
                "remote": "",
            }]
        elif self.device_driver == "airspyhf":
            # Avoid probing every Airspy while another instance is streaming.
            self._available_devices = [{
                "driver": "airspyhf",
                "label": f"AirSpy HF+ [{self.device_serial}]",
                "serial": self.device_serial,
                "remote": "",
            }]
        else:
            self._available_devices = self._enumerate()

        self.thread = threading.Thread(
            target=self._run,
            daemon=True,
        )
        self.thread.start()

    def _start_rx888_audio(self):
        if self.rx888_audio_processor is None:
            try:
                self.rx888_audio_processor = RX888AudioProcessor(
                    input_rate=float(self.input_sample_rate)
                )
            except Exception as error:
                logger.error("[RX888 AUDIO] initialization failed: %s", error)
                return
        if self._rx888_audio_thread is None or not self._rx888_audio_thread.is_alive():
            self._rx888_audio_thread = threading.Thread(
                target=self._rx888_audio_worker,
                daemon=True,
                name="rx888-audio-dsp",
            )
            self._rx888_audio_thread.start()

    def _submit_rx888_audio(self, samples):
        if self.device_driver != "rx888" or self.rx888_audio_processor is None:
            return
        item = (
            samples,
            float(self.tuned_freq_hz),
            str(self.mode),
        )
        try:
            self._rx888_audio_queue.put_nowait(item)
        except _stdlib_queue.Full:
            self.rx888_audio_overruns += 1
            try:
                self._rx888_audio_queue.get_nowait()
                self._rx888_audio_queue.put_nowait(item)
            except _stdlib_queue.Empty:
                pass
            except _stdlib_queue.Full:
                pass

    def _rx888_audio_worker(self):
        while self.running:
            try:
                samples, center_hz, mode = self._rx888_audio_queue.get(timeout=0.25)
            except _stdlib_queue.Empty:
                continue
            try:
                t0 = time.perf_counter()

                audio = self.rx888_audio_processor.process(samples, center_hz, mode)
                t1 = time.perf_counter()
                now = time.perf_counter()
                last_freq_log = getattr(self, "_rx888_freq_last_log", 0.0)
                if now - last_freq_log >= 1.0:
                    self._rx888_freq_last_log = now
                    logger.info(
                        "[RX888 AUDIO FREQ] tuned_hz=%.1f zoom_center_hz=%.1f mode=%s",
                        center_hz,
                        self.zoom_center_hz,
                        mode,
                    )
                self.rx888_audio_process_ms = float(
                    self.rx888_audio_processor.process_ms
                )
                self.rx888_audio_active = bool(len(audio))
                t2 = time.perf_counter()

                if len(audio):
                    EngineAudioTrack.broadcast(audio, self)
                    t3 = time.perf_counter()

                    now = time.perf_counter()
                    last_log = getattr(self, "_rx888_timing_last_log", 0.0)
                    if now - last_log >= 1.0:
                        self._rx888_timing_last_log = now
                        logger.info(
                            "[RX888 TIMING] process_call=%.2f c_reported=%.2f "
                            "state=%.3f broadcast=%.3f total=%.2f",
                            (t1 - t0) * 1000.0,
                            self.rx888_audio_process_ms,
                            (t2 - t1) * 1000.0,
                            (t3 - t2) * 1000.0,
                            (t3 - t0) * 1000.0,
                        )

                    ft8_manager = _get_ft8_mgr()
                    if ft8_manager is not None and ft8_manager.is_running:
                        try:
                            ft8_manager.send_audio(audio)
                        except Exception:
                            pass

                    with self.audio_lock:
                        if _alsa_loopback_running and hasattr(self, "_loopback_fifo"):
                            overflow = len(self._loopback_fifo) + len(audio) - self._fifo_max
                            for _ in range(max(0, min(overflow, len(self._loopback_fifo)))):
                                self._loopback_fifo.popleft()
                            self._loopback_fifo.extend(audio)
            except Exception as error:
                self.rx888_audio_active = False
                logger.warning("[RX888 AUDIO] worker error: %s", error)

    def _zoom_worker(self):
        """Process only the newest RX888 block so acquisition never stalls."""
        while self.running:
            try:
                samples, center_hz, span_hz, input_center_hz = self._zoom_queue.get(timeout=0.25)
            except _stdlib_queue.Empty:
                continue
            try:
                frame = self.zoom_processor.process(
                    samples, center_hz, span_hz, input_center_hz=input_center_hz
                )
                if frame is not None:
                    now = time.perf_counter()
                    if self._zoom_last_frame_at > 0.0:
                        instant_fps = 1.0 / max(1e-6, now - self._zoom_last_frame_at)
                        self.zoom_fps = (0.85 * self.zoom_fps + 0.15 * instant_fps) if self.zoom_fps else instant_fps
                    self._zoom_last_frame_at = now
                    self.zoom_process_ms = float(frame.process_ms)
                    with self._zoom_result_lock:
                        self._zoom_result = frame
            except Exception as error:
                logger.warning("[SDR ZOOM] worker error: %s", error)

    def _submit_zoom_block(self, samples):
        if self.zoom_processor is None:
            return

        # RX888 zoom processing takes roughly 165 ms per full 64-MSPS block.
        # Limit submissions while RX888 audio is active so the two DSP workers
        # do not continuously compete for CPU and memory bandwidth.
        if (
            self.device_driver == "rx888"
            and self.rx888_audio_processor is not None
        ):
            now = time.monotonic()
            if now - self._last_rx888_audio_zoom_submit < 0.5:
                return
            self._last_rx888_audio_zoom_submit = now

        input_center_hz = (
            float(self.center_freq) if self.device_driver == "airspyhf" else 0.0
        )
        item = (
            samples,
            float(self.zoom_center_hz),
            float(self.span),
            input_center_hz,
        )
        try:
            self._zoom_queue.put_nowait(item)
        except _stdlib_queue.Full:
            # Drop the stale queued block and replace it with the newest one.
            try:
                self._zoom_queue.get_nowait()
            except _stdlib_queue.Empty:
                pass
            try:
                self._zoom_queue.put_nowait(item)
            except _stdlib_queue.Full:
                pass


    def _latest_zoom_frame(self):
        with self._zoom_result_lock:
            frame = self._zoom_result
        if frame is None:
            return None
        if abs(frame.center_hz - float(self.zoom_center_hz)) > 0.5:
            return None
        # Cropping can represent slightly less than the requested span because
        # the FFT can only be cut on whole-bin boundaries.  Accept a result
        # that is short by at most two bins; the previous 1-Hz tolerance
        # rejected otherwise-valid narrow frames (notably around 128 kHz).
        span_shortfall = float(self.span) - float(frame.sample_rate)
        allowed_shortfall = max(2.0, 2.0 * float(frame.hz_per_bin))
        if span_shortfall > allowed_shortfall:
            return None
        return frame

    def _open_device(self):
        drivers = [DEVICE_DRIVER] if DEVICE_DRIVER and DEVICE_DRIVER != "fake" else []
        if not drivers:
            # Check instance config — if explicitly set to fake, don't auto-discover
            try:
                with open(_cfg_path) as _f:
                    _cfg = json.load(_f)
                if _cfg.get("device_driver", "") == "fake":
                    return  # user explicitly chose no device
            except Exception:
                pass
            drivers = ["rx888", "airspyhf", "rtlsdr"]
        for drv in drivers:
            try:
                if drv == "rx888":
                    self.rx888_source = RX888NativeSource(
                        sample_rate=64_000_000,
                        gain_mode="high",
                        gain=40,
                        attenuation=0,
                    )
                    self.hw_gain = float(self.rx888_source.gain)
                    self.hw_agc = False
                    self.rx888_source.start()

                    self.sdr = None
                    self.rx_stream = None
                    self.device_driver = "rx888"
                    self.device_serial = DEVICE_SERIAL

                    self.real_adc_stream = True
                    self.input_sample_rate = 64_000_000.0

                    # Browser axis: center 16 MHz, span 32 MHz.
                    self.sample_rate = 32_000_000.0
                    self.span = 32_000_000.0
                    self.center_freq = 16_000_000.0

                    # The RX888 ADC remains fixed at 64 MSPS. These are
                    # display-span presets retained for the current UI.
                    self.supported_sample_rates = [
                        250_000.0,
                        500_000.0,
                        1_000_000.0,
                        2_000_000.0,
                        4_000_000.0,
                        8_000_000.0,
                        16_000_000.0,
                        32_000_000.0,
                    ]
                    self.zoom_processor = ZoomSpectrumProcessor(
                        input_rate=self.input_sample_rate,
                        fft_size=4096,
                        max_zoom_span=2_000_000.0,
                    )

                    logger.info(
                        "[RX888] native stream started "
                        "input=%.0f display_span=%.0f",
                        self.input_sample_rate,
                        self.span,
                    )
                    return
                all_devs = SoapySDR.Device.enumerate({
                    "driver": drv,
                })

                found = [
                    d for d in all_devs
                    if dict(d).get("driver", "").lower() == drv.lower()
                    and not dict(d).get("remote", "")
                ]

                if DEVICE_SERIAL:
                    wanted = str(DEVICE_SERIAL).strip().lower()

                    matched = [
                        d for d in found
                        if str(dict(d).get("serial", "")).strip().lower()
                        == wanted
                    ]

                    if not matched:
                        raise RuntimeError(
                            f"Requested {drv} serial not found: "
                            f"{DEVICE_SERIAL}; available="
                            f"{[dict(d).get('serial', '') for d in found]}"
                        )

                    found = matched

                if not found:
                    continue

                selected = dict(found[0])

                logger.info(
                    "[SDR] opening exact device "
                    "driver=%s requested_serial=%s selected_serial=%s",
                    drv,
                    DEVICE_SERIAL,
                    selected.get("serial", ""),
                )

                import concurrent.futures

                with concurrent.futures.ThreadPoolExecutor(
                    max_workers=1
                ) as ex:
                    self.sdr = ex.submit(
                        SoapySDR.Device,
                        found[0],
                    ).result(timeout=5.0)

                self.device_driver = drv
                self.device_serial = selected.get("serial", "")

                if drv == "rx888":
                    target_rate = 64_000_000
                    self.real_adc_stream = True

                    self.sdr.setSampleRate(
                        SOAPY_SDR_RX,
                        0,
                        target_rate,
                    )

                    self.rx_stream = self.sdr.setupStream(
                        SOAPY_SDR_RX,
                        SOAPY_SDR_S16,
                        [0],
                        {
                            "bufflen": "1048576",
                            "buffers": "16",
                        },
                    )

                    self.sdr.activateStream(self.rx_stream)

                else:
                    target_rate = (
                        768_000.0
                        if drv == "airspyhf"
                        else 1_024_000
                    )
                    self.real_adc_stream = False

                    self.sdr.setSampleRate(
                        SOAPY_SDR_RX,
                        0,
                        target_rate,
                    )

                    self.sdr.setFrequency(
                        SOAPY_SDR_RX,
                        0,
                        self.center_freq,
                    )

                    try:
                        self.sdr.setGainMode(
                            SOAPY_SDR_RX,
                            0,
                            True,
                        )
                    except Exception:
                        pass

                    self.rx_stream = self.sdr.setupStream(
                        SOAPY_SDR_RX,
                        SOAPY_SDR_CF32,
                        [0],
                    )

                    self.sdr.activateStream(self.rx_stream)

                    if drv == "airspyhf":
                        time.sleep(0.5)
                        self.sdr.setFrequency(
                            SOAPY_SDR_RX,
                            0,
                            self.center_freq,
                        )

                # This must be outside both branches.
                actual = float(
                    self.sdr.getSampleRate(SOAPY_SDR_RX, 0)
                )
                self.input_sample_rate = actual

                if drv == "rx888":
                    self.sample_rate = actual / 2.0
                    self.span = actual / 2.0
                    self.center_freq = actual / 4.0
                else:
                    self.sample_rate = actual
                    self.span = actual
                try:
                    rates = self.sdr.listSampleRates(SOAPY_SDR_RX, 0)
                    self.supported_sample_rates = sorted([float(r) for r in rates])
                except Exception:
                    self.supported_sample_rates = [actual]
                self.zoom_center_hz = float(self.center_freq)
                if drv == "airspyhf":
                    self.zoom_processor = ZoomSpectrumProcessor(
                        input_rate=actual,
                        fft_size=4096,
                        max_zoom_span=min(500_000.0, actual),
                        min_zoom_span=50_000.0,
                        max_decimation=16,
                        input_is_complex=True,
                    )
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


    def _fake(self, n):
        time.sleep(n / self.sample_rate)  # simulate hardware pacing
        t = np.arange(n) / self.sample_rate
        sig = 0.01 * np.exp(1j * 2 * np.pi * 12000 * t).astype(np.complex64)
        noise = (np.random.randn(n) + 1j * np.random.randn(n)).astype(np.complex64) * 0.001
        return sig + noise

    def _read(self, n):
        """Read exactly n samples, accumulating driver-sized blocks."""
        if self.device_driver == "rx888":
            source = getattr(self, "rx888_source", None)

            if source is None:
                logger.error(
                    "[RX888] native source is not initialized"
                )
                return None

            try:
                return source.read(n)
            except Exception:
                return None
        try:
            if self.real_adc_stream:
                buf = np.empty(n, dtype=np.int16)
                max_request = 65_536
            else:
                buf = np.empty(n, dtype=np.complex64)
                max_request = n

            total = 0
            timeout_count = 0

            while total < n and self.running:
                request = min(max_request, n - total)

                res = self.sdr.readStream(
                    self.rx_stream,
                    [buf[total:total + request]],
                    request,
                    timeoutUs=1_000_000,
                )

                ret = (
                    res.ret
                    if hasattr(res, "ret")
                    else int(res[0])
                )

                if ret > 0:
                    total += ret
                    timeout_count = 0

                elif ret == SoapySDR.SOAPY_SDR_TIMEOUT:
                    timeout_count += 1
                    if timeout_count >= 5:
                        logger.warning(
                            "[SDR] five consecutive timeouts "
                            "total=%d/%d real_adc=%s",
                            total,
                            n,
                            self.real_adc_stream,
                        )
                        return None

                elif ret == SoapySDR.SOAPY_SDR_OVERFLOW:
                    logger.warning(
                        "[SDR] overflow total=%d/%d",
                        total,
                        n,
                    )
                    continue

                else:
                    logger.warning(
                        "[SDR] readStream ret=%d total=%d/%d "
                        "request=%d dtype=%s",
                        ret,
                        total,
                        n,
                        request,
                        buf.dtype,
                    )
                    return None

            if total < n:
                logger.warning(
                    "[SDR] short accumulated read %d/%d",
                    total,
                    n,
                )
                return None

            if self.real_adc_stream:
                return buf.astype(np.float32) / 32768.0

            return buf

        except Exception:
            logger.exception("[SDR] readStream exception")
            return None

        return sig + noise

    def _run(self):
        logger.info(
            "[SDR] run loop started device=%s",
            self.device_driver,
        )

        t_err = 0.0
        _fail_count = 0

        while self.running:

            viewers = len(active_ws_users)

            if viewers == 0:
                if self.idle_since is None:
                    self.idle_since = time.time()

                if time.time() - self.idle_since > 30:
                    time.sleep(0.25)
                    continue

            else:
                self.idle_since = None

            #
            # Normal SDR processing
            if self._fail_count_reset:
                _fail_count = 0
                self._fail_count_reset = False

            try:
                processing_rate = (
                    self.input_sample_rate
                    if self.real_adc_stream
                    else self.sample_rate
                )

                rf_decim = max(
                    1,
                    int(processing_rate // 48000),
                )

                _t0 = time.time()
                time.sleep(0.001)

                if self.device_driver == "rx888":
                    # Approximately 20 ms of 64-MSPS real S16 data.
                    # The full-rate stream must be drained continuously.
                    n = 1_280_000
                elif self.real_adc_stream:
                    n = 262_144
                else:
                    n = 960 * rf_decim

                # SDRConnect supplies its own spectrum/audio.
                if sdrconn_client.connected:
                    time.sleep(0.02)
                    continue

                samples = (
                    self._read(n)
                    if self.device_driver != "fake"
                    else self._fake(n)
                )

                if samples is None or len(samples) < FFT_SIZE:
                    if self.switching:
                        time.sleep(0.1)
                        continue

                    _fail_count += 1
                    if self.device_driver == "rx888" and _fail_count == 1:
                        logger.error("[RX888] native stream read failed")
                    # RX888 native process and firmware recovery.
                    if self.device_driver == "rx888":
                        if _fail_count >= 10:
                            source = getattr(
                                self,
                                "rx888_source",
                                None,
                            )

                            if source is None:
                                logger.error(
                                    "[RX888] recovery impossible: "
                                    "native source missing"
                                )
                                _fail_count = 0
                                time.sleep(2.0)
                                continue

                            try:
                                usb_before = source.usb_state()

                                logger.warning(
                                    "[RX888] %d consecutive read failures; "
                                    "USB state=%s, restarting native stream",
                                    _fail_count,
                                    usb_before,
                                )

                                previous_state = source.restart(
                                    settle_seconds=1.0
                                )

                                logger.info(
                                    "[RX888] native stream restarted; "
                                    "previous USB state=%s, "
                                    "current USB state=%s",
                                    previous_state,
                                    source.usb_state(),
                                )

                            except Exception as recovery_error:
                                logger.error(
                                    "[RX888] automatic recovery failed: %s",
                                    recovery_error,
                                )

                            _fail_count = 0
                            time.sleep(0.50)
                        else:
                            time.sleep(0.05)

                        continue

                    # Existing recovery for ordinary Soapy devices.
                    if _fail_count > 100:
                        logger.warning(
                            "[SDR] stream lost, attempting reopen..."
                        )

                        _fail_count = 0
                        driver = self.device_driver
                        serial = self.device_serial

                        try:
                            self._close_stream()
                            time.sleep(2.0)
                            self._reopen_device(driver, serial)

                            logger.info(
                                "[SDR] reopen complete: %s",
                                self.device_driver,
                            )

                        except Exception as reopen_error:
                            logger.error(
                                "[SDR] reopen failed: %s",
                                reopen_error,
                            )

                            self.device_driver = "fake"
                            self.real_adc_stream = False
                            self.sdr = None
                            self.rx_stream = None

                    time.sleep(0.05)
                    continue

                # Successful read.
                _fail_count = 0

                if self.device_driver == "rx888":
                    self._submit_rx888_audio(samples)

                # FFT
                zoom_frame = None
                if self.device_driver == "airspyhf":
                    zoom_offset_hz = (
                        float(self.zoom_center_hz) - float(self.center_freq)
                    )
                    half_span = float(self.span) / 2.0
                    nyquist = float(self.sample_rate) / 2.0

                    zoom_can_process = (
                        self.zoom_processor is not None
                        and self.zoom_processor.min_zoom_span
                        <= float(self.span)
                        <= self.zoom_processor.max_zoom_span
                        and abs(zoom_offset_hz) + half_span <= nyquist
                    )
                else:
                    zoom_can_process = (
                        self.zoom_processor is not None
                        and self.zoom_processor.can_process(
                            self.zoom_center_hz,
                            self.span,
                        )
                    )

                zoom_requested = (
                    self.device_driver in ("rx888", "airspyhf")
                    and self.zoom_fft_enabled
                    and zoom_can_process
                )
                if zoom_requested:
                    self._submit_zoom_block(samples)
                    zoom_frame = self._latest_zoom_frame()

                if zoom_frame is not None:
                    mags = zoom_frame.mags
                    self.zoom_active = True
                    self.zoom_decimation = zoom_frame.decimation
                    self.zoom_hz_per_bin = zoom_frame.hz_per_bin
                    self.zoom_center_hz = zoom_frame.center_hz
                elif zoom_requested:
                    # The newest zoom request is still being processed.  Keep
                    # publishing the last valid zoom trace rather than replacing
                    # it with the 0-32 MHz wideband FFT and stretching that data
                    # across a narrow Memory Scan viewport.  The matching zoom
                    # frame will replace it on the next completed worker result.
                    self.zoom_active = True
                    continue
                elif self.real_adc_stream:
                    self.zoom_active = False
                    self.zoom_decimation = 1
                    # A real-sampled 64-MSPS ADC represents 0–32 MHz.
                    # A 4096-point rFFT gives 2049 positive-frequency
                    # bins; retain 2048 bins for the browser.
                    real_fft_size = FFT_SIZE * 2

                    if len(samples) < real_fft_size:
                        continue

                    chunk = samples[:real_fft_size].astype(
                        np.float32,
                        copy=True,
                    )
                    chunk -= np.mean(chunk)

                    if (
                        not hasattr(self, "_real_fft_window")
                        or len(self._real_fft_window) != real_fft_size
                    ):
                        self._real_fft_window = np.hanning(
                            real_fft_size
                        ).astype(np.float32)

                    windowed = chunk * self._real_fft_window
                    spectrum = np.fft.rfft(windowed)

                    mags = (
                        20.0
                        * np.log10(
                            np.abs(spectrum[:FFT_SIZE])
                            / real_fft_size
                            + 1e-12
                        )
                    ).astype(np.float32)
                    self.zoom_hz_per_bin = 32_000_000.0 / len(mags)

                else:
                    self.zoom_active = False
                    self.zoom_decimation = 1
                    chunk = samples[:FFT_SIZE].copy()
                    chunk -= np.mean(chunk)
                    windowed = chunk * self.fft_window

                    spectrum = np.fft.fftshift(
                        np.fft.fft(windowed)
                    )

                    mags = (
                        20.0
                        * np.log10(
                            np.abs(spectrum) / FFT_SIZE
                            + 1e-12
                        )
                    ).astype(np.float32)

                self._fft_count += 1
                # Update latest spectrum every three acquisition frames.
                if self._fft_count % 3 == 0:
                    with self.lock:
                        remote_spectrum = (
                            sdrconn_client.connected
                            or kiwi_mgr.connected
                            or owrx_client.connected
                        )

                        if not remote_spectrum:
                            if self.zoom_active:
                                spectrum_signature = (
                                    "zoom",
                                    round(float(self.zoom_center_hz), 1),
                                    round(float(self.span), 1),
                                    int(self.zoom_decimation),
                                    len(mags),
                                )
                            elif self.real_adc_stream:
                                spectrum_signature = (
                                    "wide-real",
                                    round(float(self.sample_rate), 1),
                                    len(mags),
                                )
                            else:
                                spectrum_signature = (
                                    "wide-iq",
                                    round(float(self.center_freq), 1),
                                    round(float(self.sample_rate), 1),
                                    len(mags),
                                )

                            geometry_changed = (
                                spectrum_signature
                                != self._last_spectrum_signature
                            )

                            if (
                                not geometry_changed
                                and self.smoothing > 0
                                and self._have_mags
                                and len(self.latest_mags) == len(mags)
                            ):
                                self.latest_mags = (
                                    self.smoothing * mags
                                    + (1.0 - self.smoothing)
                                    * self.latest_mags
                                )
                            else:
                                # First frame at a new center/span seeds the
                                # display directly.  Never blend spectra from
                                # two different Memory Scan channels.
                                self.latest_mags = mags.copy()
                                self._have_mags = True

                            self._last_spectrum_signature = spectrum_signature

                if self._fft_count % 200 == 1:
                    logger.info(
                        "[FFT] min=%.1f max=%.1f mean=%.1f",
                        float(mags.min()),
                        float(mags.max()),
                        float(mags.mean()),
                    )

                # Current demodulators require complex IQ.
                # RX888 currently supplies real wideband ADC samples,
                # so spectrum processing only is performed here.
                audio = None

                if (
                    not self.real_adc_stream
                    and self.device_driver != "fake"
                ):
                    mode = self.mode

                    if mode in ("USB", "PKTUSB"):
                        audio = self.demod_ssb(
                            samples,
                            upper=True,
                        )
                    elif mode in ("LSB", "PKTLSB"):
                        audio = self.demod_ssb(
                            samples,
                            upper=False,
                        )
                    elif mode in ("CW", "CWR"):
                        audio = self.demod_cw(
                            samples,
                            upper=(mode == "CW"),
                        )
                    elif mode == "AM":
                        audio = self.demod_am(samples)
                    elif mode == "FM":
                        audio = self.demod_fm(samples)
                    elif mode == "WFM":
                        audio = self.demod_wfm(samples)

                if audio is not None and len(audio) > 0:
                    if (
                        not hasattr(self, "_dc_zi")
                        or self._dc_zi is None
                    ):
                        self._dc_zi = np.zeros(
                            1,
                            dtype=np.float32,
                        )

                    dc_r = 0.9997

                    audio, self._dc_zi = scipy.signal.lfilter(
                        [1, -1],
                        [1, -dc_r],
                        audio,
                        zi=self._dc_zi,
                    )

                    audio = audio.astype(np.float32)
                    EngineAudioTrack.broadcast(audio, self)

                    ft8_manager = _get_ft8_mgr()
                    if (
                        ft8_manager is not None
                        and ft8_manager.is_running
                    ):
                        try:
                            ft8_manager.send_audio(audio)
                        except Exception:
                            pass

                    with self.audio_lock:
                        if (
                            _alsa_loopback_running
                            and hasattr(self, "_loopback_fifo")
                        ):
                            overflow = (
                                len(self._loopback_fifo)
                                + len(audio)
                                - self._fifo_max
                            )

                            if overflow > 0:
                                for _ in range(
                                    min(
                                        overflow,
                                        len(self._loopback_fifo),
                                    )
                                ):
                                    self._loopback_fifo.popleft()

                            self._loopback_fifo.extend(audio)

                elapsed = time.time() - _t0

                if elapsed < 0.018:
                    time.sleep(0.018 - elapsed)

            except Exception as error:
                now = time.time()

                if now - t_err > 2.0:
                    logger.exception(
                        "[SDR] run error: %s",
                        error,
                    )
                    t_err = now

                time.sleep(0.05)

    def get_spectrum(self):
        with self.lock:
            if self.zoom_active:
                display_center = float(self.zoom_center_hz)
                display_rate = float(self.zoom_hz_per_bin * len(self.latest_mags))
            elif self.device_driver == "rx888":
                display_center = 16_000_000.0
                display_rate = 32_000_000.0
            else:
                display_center = float(self.center_freq)
                display_rate = float(self.sample_rate)

            return {
                "hw_center":   display_center,
                "center":      display_center,
                "sample_rate": display_rate,
                "mags":        self.latest_mags.tolist(),
                "mode":        self.mode,
                "bw":          int(self.bw),
                "zoom_active": bool(self.zoom_active),
                "decimation": int(self.zoom_decimation),
                "hz_per_bin": float(display_rate / max(1, len(self.latest_mags))),
            }

    def get_state(self):
        with self.lock:

            if self.device_driver == "rx888":
                state_center = float(self.zoom_center_hz) if self.zoom_active else 16_000_000.0
                state_span = float(self.span)
                state_sample_rate = 32_000_000.0
            else:
                state_center = float(self.zoom_center_hz) if self.zoom_active else float(self.center_freq)
                state_span = float(self.span)
                state_sample_rate = float(self.sample_rate)

            return {
                "hw_center":     state_center,
                "center_freq":   state_center,
                "sample_rate":   state_sample_rate,
                "span":          state_span,
                "mode":          self.mode,
                "bw":            int(self.bw),
                "smoothing":     float(self.smoothing),
                "floor":         float(self.floor),
                "wf_floor":      float(self.wf_floor),
                "device_driver": self.device_driver,
                "device_serial": self.device_serial,
                "device_open": bool(
                    self.rx888_source is not None
                    if self.device_driver == "rx888"
                    else self.sdr is not None
                ),
                "available_devices": self._available_devices,
                "taken_devices": [
                    {
                        "driver": i.get("device", ""),
                        "serial": i.get("device_serial", ""),
                        "port": i.get("port"),
                        "in_use_by": i.get("in_use_by", ""),
                    }
                    for i in _get_all_instances()
                    if i.get("port") != SERVER_PORT
                    and time.time() - i.get("last_seen", 0) < 15
                    and i.get("device", "") not in ("", "fake")
                ],
                "fft_size":      len(self.latest_mags),
                "supported_sample_rates": self.supported_sample_rates,
                "zoom_fft_enabled": bool(self.zoom_fft_enabled),
                "zoom_active": bool(self.zoom_active),
                "zoom_decimation": int(self.zoom_decimation),
                "hz_per_bin": float(self.zoom_hz_per_bin),
                "zoom_process_ms": float(self.zoom_process_ms),
                "zoom_fps": float(self.zoom_fps),
                "rx888_audio_active": bool(self.rx888_audio_active),
                "rx888_audio_process_ms": float(self.rx888_audio_process_ms),
                "rx888_audio_overruns": int(self.rx888_audio_overruns),
                "gain":          0.0,
                "fill":          "gradient",
                "wf_palette":    "classic",
                "use_rtlsdr":    self.device_driver == "rtlsdr",
                "use_kiwi":      False,
                "hw_gain": float(
                    getattr(self, "hw_gain", 40.0)
                ),
                "hw_agc": bool(
                    getattr(self, "hw_agc", False)
                ),
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
                "sdr_status":    "QRV",
            }
            logger.info(
                "[SDR] opened %s serial=%s rate=%.0f mtu=%d",
                self.device_driver,
                self.device_serial,
                self.sample_rate,
                self.sdr.getStreamMTU(self.rx_stream),
            )

    def _enumerate(self):
        try:
            import concurrent.futures
            with concurrent.futures.ThreadPoolExecutor(max_workers=1) as ex:
                raw = ex.submit(SoapySDR.Device.enumerate).result(timeout=3.0)
            devs = []
            for d in raw:
                d = dict(d)
                if d.get("driver") == "remote" or d.get("remote", ""):
                    continue  # skip Docker/SoapyRemote entries
                devs.append({"driver": d.get("driver",""), "label": d.get("label", d.get("driver","")),
                             "serial": d.get("serial",""), "remote": ""})
            devs.append({"driver":"fake","label":"Fake (No Hardware)","serial":"","remote":""})
            return devs
        except Exception:
            return [{"driver": self.device_driver or "fake", "label": self.device_driver or "Fake",
                     "serial": self.device_serial, "remote": ""}]

    def _reopen_device(self, driver, serial):
        """Reopen the exact physical SDR selected by driver and serial."""

        driver = str(driver or "").strip().lower()
        serial = str(serial or "").strip()

        # RTL-SDR sometimes benefits from a USB reset before reopening.
        if driver == "rtlsdr":
            try:
                import subprocess
                subprocess.run(
                    ["usbreset", "0bda:2838"],
                    capture_output=True,
                    timeout=3,
                    check=False,
                )
                time.sleep(1.0)
            except Exception:
                pass

        import concurrent.futures

        # Enumerate physical devices.
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as ex:
            all_devs = ex.submit(
                SoapySDR.Device.enumerate
            ).result(timeout=5.0)

        found = [
            d for d in all_devs
            if dict(d).get("driver", "").strip().lower() == driver
            and not dict(d).get("remote", "")
        ]

        # Select the exact serial when supplied.
        if serial:
            wanted = serial.lower()

            matched = [
                d for d in found
                if str(dict(d).get("serial", "")).strip().lower() == wanted
            ]

            if not matched:
                raise RuntimeError(
                    f"Requested {driver} serial not found: {serial}; "
                    f"available="
                    f"{[dict(d).get('serial', '') for d in found]}"
                )

            found = matched

        if not found:
            raise RuntimeError(
                f"Device not found: driver={driver} serial={serial}"
            )

        selected_args = found[0]
        selected = dict(selected_args)
        selected_serial = str(selected.get("serial", "")).strip()

        logger.info(
            "[SDR] reopening exact device "
            "driver=%s requested_serial=%s selected_serial=%s",
            driver,
            serial,
            selected_serial,
        )

        # Open only once. The old code constructed the device twice.
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as ex:
            self.sdr = ex.submit(
                SoapySDR.Device,
                selected_args,
            ).result(timeout=10.0)

        self.device_driver = driver
        self.device_serial = selected_serial

        # Choose a sample rate actually supported by each device.
        if driver == "airspyhf":
            target_rate = 768_000.0
        elif driver == "rx888":
            target_rate = 16_000_000.0
        else:
            target_rate = 1_024_000.0

        # Read supported rates before selecting one.
        try:
            rates = self.sdr.listSampleRates(SOAPY_SDR_RX, 0)
            self.supported_sample_rates = sorted(
                float(rate) for rate in rates
            )
        except Exception as exc:
            logger.warning(
                "[SDR] could not list sample rates for %s: %s",
                driver,
                exc,
            )
            self.supported_sample_rates = []

        # Protect against an unsupported default rate.
        if (
            self.supported_sample_rates
            and target_rate not in self.supported_sample_rates
        ):
            target_rate = min(
                self.supported_sample_rates,
                key=lambda rate: abs(rate - target_rate),
            )

        logger.info(
            "[SDR] setting %s sample rate to %.0f",
            driver,
            target_rate,
        )

        self.sdr.setSampleRate(
            SOAPY_SDR_RX,
            0,
            target_rate,
        )

        actual = float(
            self.sdr.getSampleRate(SOAPY_SDR_RX, 0)
        )

        self.sample_rate = actual
        self.span = actual

        if not self.supported_sample_rates:
            self.supported_sample_rates = [actual]

        # RX888 is a direct-sampling receiver and its Soapy driver reports no
        # ordinary RF tuning range. A setFrequency call may therefore fail or
        # have no useful effect.
        if driver != "rx888":
            self.sdr.setFrequency(
                SOAPY_SDR_RX,
                0,
                self.center_freq,
            )
        else:
            logger.info(
                "[SDR] RX888 direct-sampling mode: "
                "skipping hardware center-frequency setting"
            )

        try:
            self.sdr.setGainMode(
                SOAPY_SDR_RX,
                0,
                True,
            )
        except Exception:
            pass

        # CF32 is supported by the RX888 Soapy driver, although its native
        # hardware format is S16.
        self.rx_stream = self.sdr.setupStream(
            SOAPY_SDR_RX,
            SOAPY_SDR_CF32,
            [0],
        )

        self.sdr.activateStream(self.rx_stream)

        self.stream_mtu = int(
            self.sdr.getStreamMTU(self.rx_stream)
        )

        logger.info(
            "[SDR] stream active: driver=%s rate=%.0f MTU=%d",
            driver,
            actual,
            self.stream_mtu,
        )

        # AirSpy HF+ needs settling and frequency reapplication.
        if driver == "airspyhf":
            time.sleep(0.5)
            self.sdr.setFrequency(
                SOAPY_SDR_RX,
                0,
                self.center_freq,
            )

        self._build_filters()

    def _close_stream(self):
        """Close SoapySDR stream and device cleanly."""
        if self.rx888_source is not None:
            try:
                self.rx888_source.stop()
            except Exception:
                pass
            self.rx888_source = None
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
                self.agc_gain = 0.5 * desired + 0.5 * self.agc_gain  # fast attack
            else:
                self.agc_gain = 0.005 * desired + 0.995 * self.agc_gain  # slow decay
            self.agc_gain = min(self.agc_gain, 50.0)
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
        """Wideband FM demod with proper anti-aliasing and de-emphasis."""
        if iq is None or len(iq) < 8: return None
        iq = iq.astype(np.complex64, copy=False)
        sr = int(self.sample_rate)  # e.g. 768000

        # FM discriminator on full-rate signal
        prod = iq[1:] * np.conj(iq[:-1])
        discr = np.arctan2(prod.imag, prod.real).astype(np.float32)
        discr = np.append(discr, discr[-1])

        # Resample to 48kHz with stateful anti-alias filter (no boundary clicks)
        decim = max(1, sr // 48000)  # e.g. 768000//48000 = 16
        if not hasattr(self, '_wfm_aa_zi') or getattr(self, '_wfm_aa_sr', 0) != sr:
            self._wfm_aa_sr = sr
            # Low-pass at 15kHz (FM audio bandwidth) with 63 taps
            _h = scipy.signal.firwin(63, 15000.0 / (sr / 2.0),
                                     window=('kaiser', 8.0)).astype(np.float32)
            self._wfm_aa_h = _h
            self._wfm_aa_zi = np.zeros(len(_h) - 1, dtype=np.float32)
        # Filter then decimate — maintain state across frames
        filtered, self._wfm_aa_zi = scipy.signal.lfilter(
            self._wfm_aa_h, [1.0], discr, zi=self._wfm_aa_zi)
        audio = filtered[::decim].astype(np.float32)

        if audio.size == 0: return None

        # De-emphasis filter (75us for Americas, 50us for Europe)
        tau = 75e-6; dt = 1.0/48000.0; alpha = dt/(tau+dt)
        if not hasattr(self, 'deemph_zi_wfm'):
            self.deemph_zi_wfm = np.zeros(1, dtype=np.float32)
        audio, self.deemph_zi_wfm = scipy.signal.lfilter(
            [alpha], [1.0, -(1.0-alpha)], audio, zi=self.deemph_zi_wfm)

        # Scale and soft clip
        audio = np.tanh(audio * 3.0).astype(np.float32)
        EngineAudioTrack.broadcast(audio, self)
        return None  # already broadcast

    def audio_fifo_len(self):
        with self.audio_lock:
            return len(self.audio_fifo)

    def pop_audio(self, n):
        with self.audio_lock:
            available = len(self.audio_fifo)
            take = min(n, available)
            if take == 0:
                # Full underrun — comfort noise at -60dB to avoid hard silence click
                return (np.random.randn(n) * 0.001).astype(np.float32)
            out = np.fromiter(islice(self.audio_fifo, take), dtype=np.float32, count=take)
            for _ in range(take):
                self.audio_fifo.popleft()
        if take < n:
            # Partial underrun: crossfade real samples into comfort noise
            real_rms = float(np.sqrt(np.mean(out[-min(64,take):]**2))) if take > 0 else 0.001
            noise_level = max(real_rms * 0.1, 0.0005)
            fade = np.linspace(1.0, 0.0, take, dtype=np.float32)
            out = out * fade
            pad = (np.random.randn(n - take) * noise_level).astype(np.float32)
            out = np.concatenate([out, pad])
        # Spike suppression using median absolute deviation (robust to CW peaks)
        mad = float(np.median(np.abs(out))) + 1e-9  # median absolute value
        threshold = min(0.95, mad * 10.0)  # allow 10x median before clipping
        out = np.clip(out, -threshold, threshold)
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
            self.zoom_center_hz = hz
            self.tuned_freq_hz = hz
        if self.sdr:
            try: self.sdr.setFrequency(SOAPY_SDR_RX, 0, hz)
            except Exception as e: logger.error("[SDR] tune failed: %s", e)

    def set_center_only(self, hz):
        """Retune SDR hardware to follow VFO knob, without sending back to RigPi."""
        hz = float(hz)
        with self.lock:
            self.center_freq = hz
            self.zoom_center_hz = hz
            self.tuned_freq_hz = hz
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
    def get_mode(self):
        m, _bw = self.get_mode_bw()
        return m

    def set_mode_bw(self, mode, bw=None):
        """Set rig mode/passband with Hamlib's extended M command."""
        mode = str(mode or "").strip().upper()
        if not mode:
            return False
        try:
            passband = int(float(bw)) if bw is not None else 0
        except (TypeError, ValueError):
            passband = 0
        try:
            r = self._cmd(f"M {mode} {passband}\n".encode())
            return "RPRT 0" in r or not r.startswith("RPRT")
        except Exception as e:
            logger.warning("[RIGCTL] set_mode_bw failed: %s", e)
            return False

    def get_mode_bw(self):
        # rigctl 'm' returns TWO lines: mode name, then passband width (Hz).
        # _cmd() reads only one line, which would leave the passband line in the
        # socket buffer — the next get_freq() would then read that stale number
        # as the frequency ("bw interpreted as freq" bug). So read both lines
        # here under the same lock and return mode + passband together.
        with self._lock:
            try:
                if not self._sock: self._connect()
                self._fp.write(b"m\n")
                mode_line = self._fp.readline()      # e.g. b"PKTUSB\n"
                bw_line = self._fp.readline()        # e.g. b"3000\n"
                m = mode_line.decode(errors="replace").strip()
                bw_str = bw_line.decode(errors="replace").strip()
                bw = int(bw_str) if bw_str.lstrip("-").isdigit() else None
                return (m or None), bw
            except Exception as e:
                logger.warning("[RIGCTL] get_mode_bw failed: %s", e)
                try:
                    if self._fp: self._fp.close()
                    if self._sock: self._sock.close()
                except: pass
                self._sock = self._fp = None
                return None, None

# ---- RigSync ----
class RigSync:
    def __init__(self, rigctl, engine, radio_id=1, rigpi_url=None):
        self._expected_freq = None
        self._expected_freq_until = 0.0
        self.rigctl = rigctl; self.engine = engine
        self.radio_id = radio_id; self.rigpi_url = rigpi_url
        self.pending_freq = None; self.last_freq = None
        self.pending_mode = None
        self.last_rig_mode = None
        self.last_rig_bw = None
        self._mode_hold_until = 0.0
        self._hold_until = 0.0
        self.lock = threading.Lock(); self.running = True
        threading.Thread(target=self._loop, daemon=True).start()

    def request_tune(self, hz, username="admin", mode=None, bw=None):
        with self.lock:
            logger.info(
                "[TIMING] SDR tune requested t=%.3f freq=%d",
                time.monotonic(),
                int(hz),
            )


            # Always store a 4-tuple so _loop can unpack uniformly; mode/bw are
            # None for plain freq tunes (drag, memory recall) and only set when
            # a spot-click carries the spot's mode. Backward compatible.
            self.pending_freq = (int(hz), username, mode, bw)
            self.last_freq = int(hz)  # optimistic — prevents stale poll revert
            self._hold_until = time.time() + 3.0  # hold off VFO poll 3s for slow rigs

    def request_mode(self, mode, bw=None, username="admin"):
        """Queue one user-originated mode change for the physical rig."""
        requested = str(mode or "").strip().upper()
        logger.info(

            "[MODE TIMING] request_mode "

            "t=%.3f radio=%d mode=%s bw=%s user=%s",

            time.monotonic(),

            self.radio_id,

            requested,

            bw,

            username,

        )
        if not requested:
            return
        # WFM is an SDR-only receive mode. A conventional rig gets FM with a
        # normal communications passband, while the SDR remains WFM/200 kHz.
        rig_mode = "FM" if requested == "WFM" else requested
        rig_bw = 15000 if requested == "WFM" else (
            int(float(bw)) if bw is not None else 0
        )
        with self.lock:
            self.pending_mode = (rig_mode, rig_bw, username)
            # Treat the requested rig state as the expected acknowledgement so
            # the following poll cannot echo it back and replace SDR-only WFM.
            self.last_rig_mode = rig_mode
            self.last_rig_bw = rig_bw if rig_bw > 0 else self.last_rig_bw
            self._mode_hold_until = time.time() + 2.0

    def _send_freq(self, hz, username, mode=None, bw=None):
        # Use rigctl_handler.php for atomic read-then-write — no RigDo needed
        try:
            import urllib.request, urllib.parse
            # Use radio-specific rigctld port: radio 1 = inst config, others = 4530 + radio*2
            if self.radio_id == 1:
                rigport = _inst_cfg.get("rigport", 4532)
            else:
                rigport = 4530 + self.radio_id * 2
            port_str = f"127.0.0.1:{rigport}"
            # freq always sent; mode/bw only when a spot carried them. When
            # absent, rigctl_handler runs the freq-only command exactly as before.
            params = {"port": port_str, "freq": int(hz)}
            if mode:
                params["mode"] = mode
            if bw:
                params["bw"] = int(bw)
            data = urllib.parse.urlencode(params).encode()
            url = f"{RIGPI_URL}/programs/rigctl_handler.php"
            req = urllib.request.Request(url, data=data, method="POST")
            logger.info(
                "[TIMING] rigctl_handler send t=%.3f freq=%d",
                time.monotonic(),
                int(hz),
            )

            with urllib.request.urlopen(req, timeout=3.0) as r:
                status = r.status
                body = r.read().decode(
                    "utf-8",
                    errors="replace",
                ).strip()

            logger.info(
                "[TIMING] rigctl_handler returned "
                "t=%.3f freq=%d status=%d body=%r",
                time.monotonic(),
                int(hz),
                status,
                body,
            )
            verified = False
            actual = None

            for attempt in range(5):
                time.sleep(0.2)
                actual = self.rigctl.get_freq()

                logger.info(
                    "[TIMING] post-command verification "
                    "attempt=%d requested=%d actual=%s",
                    attempt + 1,
                    int(hz),
                    actual,
                )

                if (
                    actual is not None
                    and abs(int(actual) - int(hz)) <= 10
                ):
                    verified = True
                    break
            return verified
        except Exception as e:
            logger.warning("[RIGSYNC] rigctl_handler failed: %s", e)
            return self.rigctl.set_freq(hz)

    def _loop(self):
        _fail = 0
        while self.running:
            try:
                with self.lock:
                    pending = self.pending_freq
                    self.pending_freq = None
                    pending_mode = self.pending_mode
                    self.pending_mode = None
                if pending:
                    hz, user, mode, bw = pending

                    logger.info(
                        "[TIMING] pending tune dequeued t=%.3f freq=%d",
                        time.monotonic(),
                        int(hz),
                    )

                    ok = self._send_freq(hz, user, mode, bw)

                    logger.info(
                        "[TIMING] rig tune completed t=%.3f freq=%d ok=%s",
                        time.monotonic(),
                        int(hz),
                        ok,
                    )

                    self._hold_until = time.time() + 1.0

                if pending_mode:
                    mode, bw, user = pending_mode

                    logger.info(
                        "[MODE TIMING] pending mode dequeued "
                        "t=%.3f radio=%d mode=%s bw=%d",
                        time.monotonic(),
                        self.radio_id,
                        mode,
                        int(bw),
                    )

                    hz = self.last_freq
                    if hz is None:
                        hz = self.rigctl.get_freq()

                    if hz:
                        ok = self._send_freq(
                            int(hz),
                            user,
                            mode,
                            int(bw) if bw else None,
                        )

                        logger.info(
                            "[MODE TIMING] mode send completed "
                            "t=%.3f radio=%d freq=%d mode=%s bw=%d ok=%s",
                            time.monotonic(),
                            self.radio_id,
                            int(hz),
                            mode,
                            int(bw),
                            ok,
                        )

                        if ok:
                            self.last_rig_mode = mode
                            if bw > 0:
                                self.last_rig_bw = int(bw)

                            self._mode_hold_until = time.time() + 3.0
                    else:
                        logger.warning(
                            "[RIGSYNC] radio %d cannot send mode=%s: "
                            "current frequency unavailable",
                            self.radio_id,
                            mode,
                        )

                # Read-only poll — skip if we just sent a tune
                if time.time() > self._hold_until:

                    # Read-only poll — skip if we just sent a tune (avoid revert)
                    freq = self.rigctl.get_freq()
                    if freq and self._expected_freq is not None:
                        if abs(int(freq) - self._expected_freq) <= 10:
                            logger.info(
                                "[TIMING] rig acknowledged tune t=%.3f freq=%d",
                                time.monotonic(),
                                int(freq),
                            )
                            self.last_freq = int(freq)
                            self._expected_freq = None
                            self._expected_freq_until = 0.0

                        elif time.time() < self._expected_freq_until:
                            logger.info(
                                "[TIMING] ignoring stale rig readback "
                                "t=%.3f got=%d expected=%d",
                                time.monotonic(),
                                int(freq),
                                self._expected_freq,
                            )
                            freq = None

                        else:
                            logger.warning(
                                "[TIMING] rig tune acknowledgement timed out "
                                "got=%d expected=%d",
                                int(freq),
                                self._expected_freq,
                            )
                            self._expected_freq = None
                            self._expected_freq_until = 0.0
                    if freq and freq != self.last_freq:
                        logger.info(
                            "[TIMING] rig readback changed t=%.3f freq=%d previous=%s",
                            time.monotonic(),
                            int(freq),
                            self.last_freq,
                        )

                        logger.info(
                            "[RIGSYNC] radio %d VFO -> %d",
                            self.radio_id,
                            freq,
                        )

                        self.last_freq = freq
                        # RX888 always captures 0-32 MHz, so an external RigPi
                        # tune never falls outside its hardware bandwidth.  The
                        # visible narrow spectrum is produced by the zoom DDC,
                        # however, and must follow every VFO change.  Ordinary
                        # IQ receivers retain the previous outside-band retune
                        # behavior so in-band tuning can remain display-only.
                        if self.engine.device_driver == "rx888":
                            with self.engine.lock:
                                self.engine.zoom_center_hz = float(freq)
                                self.engine.tuned_freq_hz = float(freq)
                            # Do not let a completed frame for the previous VFO
                            # become visible while the new zoom request is queued.
                            with self.engine._zoom_result_lock:
                                self.engine._zoom_result = None
                            logger.info(
                                "[RIGSYNC] radio %d RX888 zoom center -> %d",
                                self.radio_id,
                                freq,
                            )
                        else:
                            hw = self.engine.center_freq
                            half_bw = self.engine.sample_rate / 2

                            if abs(freq - hw) > half_bw * 0.9:
                                if sdrconn_client.connected:
                                    # Physical-rig band change: retune SDRconnect immediately.
                                    logger.info(
                                        "[TIMING] SDRconnect.tune() t=%.3f freq=%d",
                                        time.monotonic(),
                                        int(freq),
                                    )
                                    sdrconn_client.tune(
                                        freq,
                                        self.engine.mode,
                                    )

                                    if sdrconn_client.connected:

                                        with self.engine.lock:
                                            self.engine.center_freq = float(freq)
                                            self.engine.zoom_center_hz = float(freq)
                                            self.engine.tuned_freq_hz = float(freq)

                                        logger.info(
                                            "[RIGSYNC] radio %d SDRconnect center -> %d",
                                            self.radio_id,
                                            freq,
                                        )
                                else:
                                    self.engine.set_center_only(freq)
                    # Import mode/BW only when the physical rig's report CHANGES.
                    # Merely polling an unchanged Dummy/FM passband must never overwrite an
                    # SDR-only WFM/200-kHz selection. The first successful sample establishes
                    # a baseline; later changes are treated as physical-rig events.
                    if time.time() > self._mode_hold_until:
                        rmode, rbw = self.rigctl.get_mode_bw()
                        if rmode:
                            rmode = str(rmode).strip().upper()
                            rbw = int(rbw) if rbw is not None else None
                            if self.last_rig_mode is None:
                                self.last_rig_mode = rmode
                                self.last_rig_bw = rbw
                            elif rmode != self.last_rig_mode or (
                                rbw is not None and rbw != self.last_rig_bw
                            ):
                                self.last_rig_mode = rmode
                                self.last_rig_bw = rbw
                                sdr_mode = rmode
                                # Hamlib aliases used by several rigs.
                                if sdr_mode == "PKTUSB":
                                    sdr_mode = "PKTUSB"
                                elif sdr_mode == "PKTLSB":
                                    sdr_mode = "LSB"
                                with self.engine.lock:
                                    self.engine.mode = sdr_mode
                                    if rbw is not None and rbw > 0:
                                        self.engine.bw = rbw
                                logger.info(
                                    "[RIGSYNC] radio %d physical mode -> %s/%s",
                                    self.radio_id, sdr_mode, rbw,
                                )
                _fail = 0
            except Exception as exc:
                _fail += 1
                logger.exception(
                    "[RIGSYNC] radio %d poll failed (%d): %s",
                    self.radio_id,
                    _fail,
                    exc,
                )
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

_settings_lock = threading.Lock()
def save_user_settings(username, state):
    """Save user settings to disk in background thread."""
    import copy
    snapshot = copy.deepcopy(state)
    path = _settings_path(username)
    def _write():
        try:
            os.makedirs(os.path.dirname(path), exist_ok=True)
            with _settings_lock:
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
            "sdrconn_freq":        float(saved.get("sdrconn_freq", 14074000)),
            "sdrconn_sample_rate": int(saved.get("sdrconn_sample_rate", 2000000)),
            "sdrconn_lna":         int(saved.get("sdrconn_lna", 0)),
            "sdrconn_mode":        saved.get("sdrconn_mode", "USB"),
            "sdrconn_antenna":     saved.get("sdrconn_antenna", "Antenna A"),
            "sdrconn_device":      saved.get("sdrconn_device", ""),
            "sdr_status":           saved.get("sdr_status"),
        }
    return user_state[username]

def get_rigsync(radio_id):
    if radio_id not in rigsyncs:
        # Use instance rigport for radio 1, compute for others
        base_rigport = _inst_cfg.get("rigport", 4532)
        port = base_rigport if radio_id == 1 else 4530 + radio_id * 2
        rc = RigCtl("127.0.0.1", port)
        rigsyncs[radio_id] = RigSync(rc, engine, radio_id, RIGPI_URL)
        logger.info("[RIGSYNC] created radio=%d port=%d", radio_id, port)
    return rigsyncs[radio_id]

# ---- WebRTC Audio ----
# Per-engine shared ring buffers for lock-free audio broadcast
_engine_audio_rings = {}  # engine_id -> {'buf': np.array, 'write_pos': int}
_engine_audio_ring_lock = threading.Lock()
AUDIO_RING_SIZE = int(4.0 * 48000)  # 4-second ring buffer

def _get_or_create_ring(engine):
    eid = id(engine)
    with _engine_audio_ring_lock:
        if eid not in _engine_audio_rings:
            _engine_audio_rings[eid] = {
                'buf': np.zeros(AUDIO_RING_SIZE, dtype=np.float32),
                'write_pos': 0,
                'seq': 0
            }
        return _engine_audio_rings[eid]

def _broadcast_to_ring(audio_f32, engine):
    """Write audio to engine's ring buffer — O(n) memcpy, no per-track work."""
    if engine is None:
        return
    ring = _get_or_create_ring(engine)
    n = len(audio_f32)
    wp = ring['write_pos']
    # Wrap-around write
    end = wp + n
    if end <= AUDIO_RING_SIZE:
        ring['buf'][wp:end] = audio_f32
    else:
        first = AUDIO_RING_SIZE - wp
        ring['buf'][wp:] = audio_f32[:first]
        ring['buf'][:end - AUDIO_RING_SIZE] = audio_f32[first:]
    ring['write_pos'] = end % AUDIO_RING_SIZE
    ring['seq'] += n

class EngineAudioTrack(AudioStreamTrack):
    kind = "audio"
    _all_tracks = []
    _tracks_lock = threading.Lock()

    def __init__(self, engine, frame_ms=20):
        super().__init__()
        self.engine = engine
        self.sample_rate = 48000
        self.frame_samples = int(self.sample_rate * frame_ms / 1000.0)
        self._samples_sent = 0
        # Track read position in ring buffer
        ring = _get_or_create_ring(engine)
        self._read_pos = ring['write_pos']  # start reading from now
        with EngineAudioTrack._tracks_lock:
            EngineAudioTrack._all_tracks.append(self)

    def cleanup(self):
        with EngineAudioTrack._tracks_lock:
            try: EngineAudioTrack._all_tracks.remove(self)
            except ValueError: pass

    @classmethod
    def broadcast(cls, audio_f32, engine=None):
        """Write to ring buffer — fast, no per-track work."""
        if hasattr(audio_f32, 'astype'):
            _broadcast_to_ring(audio_f32.astype(np.float32), engine)
        else:
            _broadcast_to_ring(np.array(audio_f32, dtype=np.float32), engine)

    async def recv(self):
        n = self.frame_samples
        from_kiwi = getattr(self.engine, '_audio_from_kiwi', False)
        from_owrx = getattr(self.engine, 'device_driver', '') == 'fake' and getattr(owrx_client, 'connected', False)
        MAX_WAIT = 0.6 if (from_kiwi or from_owrx) else 0.15
        POLL = 0.005
        waited = 0.0
        ring = _get_or_create_ring(self.engine)
        # Wait for enough samples
        def available():
            return (ring['write_pos'] - self._read_pos) % AUDIO_RING_SIZE
        while available() < n and waited < MAX_WAIT:
            await asyncio.sleep(POLL)
            waited += POLL
        avail = available()
        if avail == 0:
            audio_f32 = (np.random.randn(n) * 0.001).astype(np.float32)
        else:
            take = min(n, avail)
            rp = self._read_pos
            end = rp + take
            if end <= AUDIO_RING_SIZE:
                audio_f32 = ring['buf'][rp:end].copy()
            else:
                first = AUDIO_RING_SIZE - rp
                audio_f32 = np.concatenate([ring['buf'][rp:], ring['buf'][:end - AUDIO_RING_SIZE]])
            self._read_pos = end % AUDIO_RING_SIZE
            if take < n:
                pad = (np.random.randn(n - take) * 0.001).astype(np.float32)
                audio_f32 = np.concatenate([audio_f32, pad])
        # Spike suppression
        mad = float(np.median(np.abs(audio_f32))) + 1e-9
        audio_f32 = np.clip(audio_f32, -min(0.95, mad*10), min(0.95, mad*10))
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
    _register_instance(user.upper(), callsign)
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
    # Override with per-user settings.
    # NOTE: "mode" and "bw" are intentionally NOT overridden here —
    # engine.get_state() already returns the live demod mode/bandwidth,
    # which RigSync keeps in sync with the physical rig. Overriding them
    # with the persisted us["mode"]/us["bw"] would mask rig-driven changes
    # (radio -> engine works, but the UI would keep showing the last
    # user-set values instead of the rig's actual mode/filter width).
    saved_span = float(
        us.get("span", state.get("span", 50_000.0))
    )

    if sdrconn_client.connected:
        current_rate = float(
            getattr(
                sdrconn_client,
                "_sample_rate",
                2_000_000,
            )
        )

        pending_value = getattr(
            sdrconn_client,
            "_pending_sample_rate",
            None,
        )

        pending_rate = (
            float(pending_value)
            if pending_value is not None
            else current_rate
        )

        device_max_span = max(
            50_000.0,
            current_rate,
            pending_rate,
        )

        safe_span = max(
            50_000.0,
            min(device_max_span, saved_span),
        )

    elif engine.device_driver == "rx888":
        safe_span = _snap_rx888_span(saved_span)

    else:
        device_max_span = max(
            50_000.0,
            float(engine.sample_rate),
        )

        safe_span = max(
            50_000.0,
            min(device_max_span, saved_span),
        )

    engine.span = safe_span
    sdrconn_rate = int(
        getattr(
            sdrconn_client,
            "_sample_rate",
            2_000_000,
        )
    )

    pending_rate = getattr(
        sdrconn_client,
        "_pending_sample_rate",
        None,
    )

    if pending_rate is not None:
        sdrconn_rate = int(pending_rate)
    state.update({
        "span": safe_span,
        "floor": us["floor"],
        "wf_floor": us["wf_floor"],
        "smoothing":  us["smoothing"],
        "squelch":    us.get("squelch", -140),
        "device_args": us.get("device_args", DEVICE_ARGS),
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
        "kiwi_active_host": kiwi_mgr._hostname if kiwi_mgr.connected else "",
        "tx_vu":       _tx_vu,
        "tx_gain":     _tx_gain,
        "owrx_active": owrx_client.connected,
        "owrx_url": owrx_client._url or "",
        "sdrconn_active": sdrconn_client.connected,
        "sdrconn_device": sdrconn_client._device or us.get("sdrconn_device", "nRSP-ST"),
        "sdrconn_sample_rate": sdrconn_rate,
        "sdrconn_supported_rates": [200000,500000,1000000,2000000,4000000,6000000,8000000,10000000]
        if sdrconn_client.connected else None,
        "sdrconn_freq": int(sdrconn_client._freq_hz) if sdrconn_client.connected else None,
        "sdrconn_saved_freq":        us.get("sdrconn_freq", 14074000),
        "sdrconn_saved_sample_rate": us.get("sdrconn_sample_rate", 2000000),
        "sdrconn_saved_lna":         us.get("sdrconn_lna", 0),
        "sdrconn_saved_antenna":     us.get("sdrconn_antenna", "Antenna A"),
        "sdrconn_saved_device":      us.get("sdrconn_device", ""),
        "sdrconn_saved_mode":        us.get("sdrconn_mode", "USB"),
    })

    if engine.device_driver == "rx888":
        state["hw_gain"] = float(
            getattr(engine, "hw_gain", 40.0)
        )
        state["hw_agc"] = False

    if engine.device_driver == "rx888":
        state["center_freq"] = (
            float(engine.center_freq)
            if engine.zoom_active
            else 16_000_000.0
        )
        state["hw_center"] = state["center_freq"]
        state["sample_rate"] = 32_000_000.0
        state["span"] = float(engine.span)
        state["zoom_active"] = bool(engine.zoom_active)
        state["zoom_decimation"] = int(engine.zoom_decimation)
        state["hz_per_bin"] = float(engine.zoom_hz_per_bin)
        state["zoom_process_ms"] = float(engine.zoom_process_ms)
        state["zoom_fps"] = float(engine.zoom_fps)

    # This must be independent of engine.device_driver. SDRConnect deliberately
    # sets the local engine driver to "fake" while the remote spectrum is active.
    if sdrconn_client.connected:
        raw_name = (
            sdrconn_client._device
            or us.get("sdrconn_device", "")
            or "SDRPlay RSP-ST"
        )

        # Friendly UI name only. Preserve raw_name for SDRConnect commands.
        display_name = (
            "nRSP-ST"
            if "RSP-ST" in raw_name
            else raw_name
        )
        sdr_rate = float(sdrconn_client._sample_rate)
        sdr_bins = max(1, len(engine.latest_mags))

        state["fft_size"] = sdr_bins
        state["hz_per_bin"] = sdr_rate / sdr_bins
        state["zoom_active"] = False
        state["zoom_decimation"] = 1
        state["zoom_process_ms"] = 0.0
        state["zoom_fps"] = 0.0
        state["sdrconn_active"] = True
        state["sdrconn_device"] = display_name
        state["sdr_status"] = "QRV"
        state["sample_rate"] = float(
            sdrconn_client._sample_rate
        )
        state["span"] = float(safe_span)
        # SDRConnect uses indexed LNA state rather than dB gain.
        # Present the live SDRConnect LNA through the normal SDR gain fields
        # so the main SDR panel can use the same slider.
        state["hw_gain"] = float(
            getattr(sdrconn_client, "_lna", 0)
        )
        state["hw_gain_min"] = float(
            getattr(sdrconn_client, "_lna_min", 0)
        )
        state["hw_gain_max"] = float(
            getattr(sdrconn_client, "_lna_max", 19)
        )
        state["hw_gain_step"] = 1.0
        state["hw_agc"] = False
    return jsonify(state)
@app.route("/api/fft/snapshot")
def api_fft_snapshot():
    spec = engine.get_spectrum()
    bins = len(spec["mags"])

    if sdrconn_client.connected:
        sr = float(sdrconn_client._sample_rate)
        cf = float(sdrconn_client._freq_hz)
    else:
        sr = float(spec["sample_rate"])
        cf = float(spec["hw_center"])

    return jsonify({
        "timestamp": time.time(),
        "center_freq": cf,
        "sample_rate": sr,
        "fft_size": bins,
        "hz_per_bin": sr / max(1, bins),
        "span": float(engine.span),
        "freq_start": cf - sr / 2.0,
        "freq_end": cf + sr / 2.0,
        "mags": spec["mags"],
        "mode": spec["mode"],
        "bw": spec["bw"],
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
                cur.execute("""SELECT id, DX, Spotter, Frequency, Band, Mode, Note, Country, Continent, DXCC, Webtime, Webdate, DXBearing, DXDistance, Backcolor, Forecolor, Latitude, Longitude, DXGrid FROM Spots WHERE (Hide IS NULL OR Hide != '1') ORDER BY id DESC LIMIT 300""")
                spots = cur.fetchall()
        import re as _re
        for s in spots:
            if not s.get('DXGrid'):
                note = s.get('Note') or ''
                m = _re.search(r'\b([A-R]{2}\d{2})', note, _re.I)
                if m: s['DXGrid'] = m.group(1).upper()
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
            if owrx_client.connected:
                owrx_client.disconnect()
            if owrx_client.connected:
                owrx_client.disconnect()
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


@app.route("/api/owrx/connect", methods=["POST"])
def api_owrx_connect():
    d = request.get_json(force=True) or {}
    url     = d.get("url", "")
    freq_hz = int(d.get("freq_hz", 14074000))
    mode    = d.get("mode", "usb")
    if not url:
        return jsonify({"ok": False, "error": "no url"})
    try:
        # Disconnect other sources first
        if kiwi_mgr.connected:
            kiwi_mgr.disconnect()
        engine._audio_from_kiwi = False
        if sdrconn_client.connected:
            sdrconn_client.disconnect()
        owrx_client.connect(engine, url, freq_hz=freq_hz, mode=mode)
        engine.device_driver = "fake"
        # Save to user's host list with owrx: prefix
        user = request.args.get("user", "admin").strip().lower()
        us = get_user_state(user)
        host_entry = "owrx:" + url.replace("http://","").replace("https://","").rstrip("/")
        hosts = us.get("kiwi_hosts", [])
        if host_entry not in hosts:
            hosts = hosts + [host_entry]
        us["kiwi_hosts"] = hosts
        save_user_settings(user, us)
        return jsonify({"ok": True, "url": url, "kiwi_hosts": hosts})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500

@app.route("/api/owrx/disconnect", methods=["POST"])
def api_owrx_disconnect():
    owrx_client.disconnect()
    if hasattr(engine, '_pre_owrx_driver'):
        engine.device_driver = engine._pre_owrx_driver
    return jsonify({"ok": True})

@app.route("/api/owrx/status")
def api_owrx_status():
    return jsonify({
        "connected": owrx_client.connected,
        "url":       owrx_client._url or "",
        "freq_hz":   owrx_client._freq_hz,
        "fft_size":  owrx_client._fft_size,
        "center_hz": owrx_client._center_hz,
        "span_hz":   owrx_client._span_hz,
    })

@app.route("/api/openwebrx_servers")
def api_openwebrx_servers():
    import json as _json
    path = os.path.join(BASE_DIR, "openwebrx_servers.json")
    try:
        with open(path) as f:
            data = _json.load(f)
        return jsonify({"ok": True, "entries": data, "count": len(data)})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e), "entries": []})

@app.route("/api/kiwisdr_servers")
def api_kiwisdr_servers():
    import json as _json
    path = os.path.join(BASE_DIR, "kiwisdr_servers.json")
    try:
        with open(path) as f:
            data = _json.load(f)
        return jsonify({"ok": True, "entries": data, "count": len(data)})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e), "entries": []})

@app.route("/api/call_latlon")
def api_call_latlon():
    """Resolve a callsign to lat/lon using prefix lookup."""
    call = request.args.get("call", "").strip().upper()
    if not call:
        return jsonify({"ok": False}), 400
    try:
        conn = pymysql.connect(host="localhost", user="ham", password="7388",
                               database="station", charset="utf8",
                               cursorclass=pymysql.cursors.DictCursor)
        with conn:
            with conn.cursor() as cur:
                base = call.split('/')[0]  # strip /P /R /M etc
                for plen in range(len(base), 0, -1):
                    pfx = base[:plen]
                    cur.execute("""
                        SELECT pr.Latitude, pr.Longitude, pr.Country
                        FROM PLink p JOIN Prefixes pr ON p.plink=pr.ID
                        WHERE p.Prefix=%s AND pr.Latitude IS NOT NULL
                        LIMIT 1
                    """, (pfx,))
                    row = cur.fetchone()
                    if row and row.get('Latitude') and row.get('Longitude'):
                        return jsonify({"ok": True, "lat": float(row['Latitude']),
                                       "lon": float(row['Longitude']),
                                       "country": row.get('Country','')})
        return jsonify({"ok": False})
    except Exception as e:
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
    """Return combined KiwiSDR + OpenWebRX list from local JSON."""
    entries = []
    for fname in ("kiwisdr_servers.json", "openwebrx_servers.json"):
        path = os.path.join(BASE_DIR, fname)
        try:
            with open(path) as f:
                entries.extend(json.load(f))
        except Exception:
            pass
    return jsonify({"ok": True, "count": len(entries), "entries": entries})


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
    freq_hz     = int(d.get("freq_hz", 14074000))
    mode        = d.get("mode", "USB").upper()
    device      = d.get("device", None)
    # "nRSP-ST" is the friendly RigPi label. SDRConnect headless identifies
    # this receiver by its actual valid_devices name.
#    if device == "nRSP-ST":
#        device = "SDRPlay RSP-ST"
    sample_rate = int(d.get("sample_rate", 2_000_000))
    try:
        if owrx_client.connected:
            owrx_client.disconnect()
        if owrx_client.connected:
            owrx_client.disconnect()
        sdrconn_client.connect(engine, device_name=device, freq_hz=freq_hz, mode=mode, sample_rate=sample_rate)
        engine.device_driver = "fake"   # pause local SDR while nRSP-ST active
        logger.info("[SDRCONN] /api/sdrconn/connect freq=%d mode=%s sr=%d", freq_hz, mode, sample_rate)
        # Persist settings
        user = request.args.get("user", "admin").strip().lower()
        us = get_user_state(user)
        us["sdrconn_freq"] = freq_hz
        us["sdrconn_mode"] = mode
        us["sdrconn_sample_rate"] = sample_rate
        if device: us["sdrconn_device"] = device
        save_user_settings(user, us)
        return jsonify({"ok": True, "freq_hz": freq_hz, "mode": mode})
    except Exception as e:
        logger.error("[SDRCONN] connect error: %s", e)
        return jsonify({"ok": False, "error": str(e)}), 500

@app.route("/api/sdrconn/disconnect", methods=["POST"])
def api_sdrconn_disconnect():
    sdrconn_client.disconnect()
    sdrconn_client._stop_headless()
    if hasattr(engine, '_pre_sdrconn_driver'):
        engine.device_driver = engine._pre_sdrconn_driver
    with engine.lock:
        engine.sample_rate = float(SAMPLE_RATE)
    return jsonify({"ok": True})


@app.route("/api/sdrconn/set_rate", methods=["POST"])
def api_sdrconn_set_rate():
    data = request.get_json(force=True) or {}
    rate = int(data.get("sample_rate", 2_000_000))

    if not sdrconn_client.connected:
        return jsonify({
            "ok": False,
            "error": "not connected",
        })

    sdrconn_client._rate_lock = True
    sdrconn_client.set_sample_rate(rate)
    sdrconn_client._pending_sample_rate = rate

    with engine.lock:
        engine.sample_rate = float(rate)
        engine.span = float(rate)

    user = request.args.get(
        "user",
        data.get("user", "admin"),
    ).strip().lower()

    us = get_user_state(user)
    us["sdrconn_sample_rate"] = int(rate)
    us["sample_rate"] = float(rate)
    us["span"] = float(rate)

    save_user_settings(user, us)

    engine.supported_sample_rates = [
        200_000,
        500_000,
        1_000_000,
        2_000_000,
        4_000_000,
        6_000_000,
        8_000_000,
        10_000_000,
    ]

    def _unlock():
        time.sleep(5.0)
        sdrconn_client._rate_lock = False
        sdrconn_client._pending_sample_rate = None

    threading.Thread(
        target=_unlock,
        daemon=True,
    ).start()

    logger.info(
        "[SDRCONN] set_rate sample rate and span -> %d",
        rate,
    )

    return jsonify({
        "ok": True,
        "sample_rate": rate,
        "span": rate,
    })

@app.route("/api/sdrconn/lna", methods=["POST"])
def api_sdrconn_lna():
    d = request.get_json(force=True) or {}
    lna = int(d.get("lna", 0))
    sdrconn_client.set_lna(lna)
    user = request.args.get("user", "admin").strip().lower()
    us = get_user_state(user)
    us["sdrconn_lna"] = lna
    save_user_settings(user, us)
    return jsonify({"ok": True, "lna": lna})

@app.route("/api/sdrconn/antenna", methods=["POST"])
def api_sdrconn_antenna():
    d = request.get_json(force=True) or {}
    antenna = d.get("antenna", "Antenna A")
    sdrconn_client.set_antenna(antenna)
    # Persist to user settings
    user = request.args.get("user", "admin").strip().lower()
    us = get_user_state(user)
    us["sdrconn_antenna"] = antenna
    save_user_settings(user, us)
    return jsonify({"ok": True, "antenna": antenna})

@app.route("/api/sdrconn/tune", methods=["POST"])
def api_sdrconn_tune():
    d = request.get_json(force=True) or {}
    freq_hz = int(d.get("freq_hz", 14074000))
    mode    = d.get("mode", None)
    sdrconn_client.tune(freq_hz, mode)
    return jsonify({"ok": True, "freq_hz": freq_hz})


# Cache device list at startup
_sdrconn_devices = []

def _probe_sdrconn_devices():
    """Probe SDRConnect headless for available devices — retry until found."""
    import asyncio as _aio, json as _json, time as _time
    global _sdrconn_devices
    async def _probe():
        try:
            import websockets as _ws
            async with _ws.connect("ws://127.0.0.1:5454/", open_timeout=5) as ws:
                await ws.send(_json.dumps({
                    "event_type": "get_property",
                    "property": "valid_devices",
                    "value": ""
                }))
                for _ in range(15):
                    msg = await _aio.wait_for(ws.recv(), timeout=2)
                    d = _json.loads(msg)
                    if d.get("property") == "valid_devices":
                        devs = [v.strip() for v in d.get("value","").split(",") if v.strip()]
                        return devs
        except Exception as e:
            logger.warning("[SDRCONN] devices probe failed: %s", e)
        return []
    # Retry up to 10 times with 3s delay
    for attempt in range(10):
        _time.sleep(3)
        try:
            loop = _aio.new_event_loop()
            devs = loop.run_until_complete(_probe())
            loop.close()
            if devs:
                _sdrconn_devices = devs
                logger.info("[SDRCONN] cached devices (attempt %d): %s", attempt+1, devs)
                return
        except Exception as e:
            logger.warning("[SDRCONN] probe error attempt %d: %s", attempt+1, e)
    logger.warning("[SDRCONN] gave up probing devices after 10 attempts")

@app.route("/api/sdrconn/devices")
def api_sdrconn_devices():
    """Probe or return cached SDRConnect device list."""
    import asyncio as _aio, json as _json
    global _sdrconn_devices
    # Return cache if already populated
    if _sdrconn_devices:
        return jsonify({"ok": True, "devices": _sdrconn_devices})
    # Skip the blocking websocket probe entirely on instances that aren't
    # using SDRConnect/SDRplay — it can take up to ~10s (websocket connect +
    # retry loop) and there's nothing to find on an AirSpy/RTL-SDR instance.
#    if DEVICE_DRIVER not in ("sdrconnect", "sdrplay", ""):
#        return jsonify({"ok": True, "devices": []})
    # Otherwise probe now
    async def _probe():
        try:
            import websockets as _ws
            async with _ws.connect("ws://127.0.0.1:5454/", open_timeout=5) as ws:
                await ws.send(_json.dumps({
                    "event_type": "get_property",
                    "property": "valid_devices",
                    "value": ""
                }))
                for _ in range(15):
                    msg = await _aio.wait_for(ws.recv(), timeout=2)
                    d = _json.loads(msg)
                    if d.get("property") == "valid_devices":
                        return [v.strip() for v in d.get("value","").split(",") if v.strip()]
        except Exception as e:
            logger.warning("[SDRCONN] devices probe failed: %s", e)
        return []
    try:
        loop = _aio.new_event_loop()
        devs = loop.run_until_complete(_probe())
        loop.close()
        if devs:
            _sdrconn_devices = devs
            logger.info("[SDRCONN] devices: %s", devs)
        return jsonify({
            "ok": True,
            "devices": devs,
        })
    except Exception as e:
        return jsonify({"ok": False, "devices": [], "error": str(e)})


@app.route("/api/sdrconn/status")
def api_sdrconn_status():
    return jsonify({
        "connected":      sdrconn_client.connected,
        "freq_hz":        sdrconn_client._freq_hz,
        "mode":           sdrconn_client._mode,
        "device":         sdrconn_client._device,
        "sample_rate":    sdrconn_client._sample_rate,
        "antennas":       getattr(sdrconn_client, "_antennas", []),
        "active_antenna": getattr(sdrconn_client, "_active_antenna", ""),
        "lna":            getattr(sdrconn_client, "_lna", 0),
        "lna_min":        getattr(sdrconn_client, "_lna_min", 0),
        "lna_max":        getattr(sdrconn_client, "_lna_max", 19),
        "supported_sample_rates": [200000, 500000, 1000000, 2000000, 4000000, 6000000, 8000000, 10000000],
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
    # Audio must follow every requested VFO frequency, including slice_only
    # tunes that deliberately leave the RX888 spectrum center unchanged.
    if engine.device_driver == "rx888":
        with engine.lock:
            engine.tuned_freq_hz = float(freq_hz)
    tune_user     = data.get("user", "admin").strip().lower()
    tune_callsign = data.get("callsign", "ADMIN").strip().upper()
    radio_id = user_radio_map.get(tune_user, int(data.get("radio", 1)))
    logger.info("[TUNE] user=%s radio=%d freq=%d", tune_user, radio_id, freq_hz)
    global active_radio
    active_radio = radio_id
    slice_only = bool(data.get("slice_only", False))
    # Update per-user center freq and persist (not for slice_only)
    if not slice_only:
        get_user_state(tune_user)["center_freq"] = freq_hz
        save_user_settings(tune_user, get_user_state(tune_user))
    _m = _get_ft8_mgr()
    if _m is not None:
        try:
            _m.on_freq_change(freq_hz)
        except Exception as _fe:
            logger.warning("[FT8] on_freq_change: %s", _fe)
    if slice_only:
        # Within hardware BW — display only, no SDR hardware retune
        # But still send to rig control so radio follows
        get_rigsync(radio_id).request_tune(freq_hz, tune_user, data.get("mode"), data.get("bw"))
        # Notify other users
        notify = {"callsign": tune_callsign, "freq_hz": freq_hz, "in_band": True}
        for ws_id, ws_u in list(active_ws_users.items()):
            if ws_id in tune_notify and ws_u != tune_user:
                tune_notify[ws_id] = notify
        return jsonify({"ok": True, "freq": freq_hz, "hw_retuned": False})
    else:
        if sdrconn_client.connected:
            sdrconn_client.tune(
                freq_hz,
                engine.mode,
            )

            with engine.lock:
                engine.tuned_freq_hz = float(freq_hz)
                engine.zoom_center_hz = float(freq_hz)

        elif owrx_client.connected:
            owrx_client.set_freq(freq_hz)
            engine.center_freq = float(freq_hz)

        elif not kiwi_mgr.connected:
            engine.tune(freq_hz)
        if kiwi_mgr.connected:
            _mode_map = {'usb':'usb','lsb':'lsb','am':'am','cw':'cw','cwu':'cw','cwl':'cw',
                         'nfm':'nfm','wfm':'am','pktusb':'usb','pktlsb':'lsb','dsb':'am'}
            _kiwi_mode = _mode_map.get(engine.mode.lower(), 'usb')
            _bw_hz = int(getattr(engine, 'bw', 2700))
            if _kiwi_mode in ('usb', 'cw'):
                _lc, _hc = 300, min(_bw_hz, 3800)
            elif _kiwi_mode == 'lsb':
                _lc, _hc = -min(_bw_hz, 3800), -300
            elif _kiwi_mode == 'am':
                _lc, _hc = -min(_bw_hz, 6000), min(_bw_hz, 6000)
            else:
                _lc, _hc = -min(_bw_hz, 4000), min(_bw_hz, 4000)
            kiwi_mgr.tune(freq_hz / 1000.0, _kiwi_mode, lc=_lc, hc=_hc)
    if not slice_only:
        get_rigsync(radio_id).request_tune(freq_hz, tune_user, data.get("mode"), data.get("bw"))
    # Broadcast notify to ALL connected WebSocket clients on this instance
    # Check if tune is out of current hardware band
    hw_center = engine.center_freq if hasattr(engine, 'center_freq') else freq_hz
    sample_rate = engine.sample_rate if hasattr(engine, 'sample_rate') else 912000
    in_band = abs(freq_hz - hw_center) <= (sample_rate / 2)
    notify = {"callsign": tune_callsign, "freq_hz": freq_hz, "in_band": in_band}
    logger.info("[TUNE] broadcasting notify to %d ws clients: %s in_band=%s", len(active_ws_users), list(active_ws_users.values()), in_band)
    for ws_id, ws_u in list(active_ws_users.items()):
        if ws_id in tune_notify:
            tune_notify[ws_id] = notify
            logger.info("[TUNE] set notify for ws_id=%d user=%s", ws_id, ws_u)
    return jsonify({"ok": True, "freq": freq_hz, "hw_retuned": True})

_last_restart_request = 0.0

@app.route("/api/restart_mine", methods=["POST"])
def api_restart_mine():
    """Self-service restart of THIS instance only, for any logged-in user.
    Distinct from /api/admin/restart (which is admin-only and restarts every
    instance). A user on port 8003 can only ever restart unit sdr_web@8003 —
    scope is automatic via SERVER_PORT, never a parameter from the client.
    Requires a passwordless sudoers rule scoped to exactly this command:
        pi ALL=(ALL) NOPASSWD: /usr/bin/systemctl restart sdr_web@<PORT>
    """
    data = request.get_json(force=True) or {}
    user = data.get("user", request.args.get("user", "")).strip().lower()
    if not user:
        return jsonify({"ok": False, "error": "user required"}), 400

    unit = f"sdr_web@{SERVER_PORT}"

    global _last_restart_request
    now = time.time()
    if now - _last_restart_request < 30:
        return jsonify({"ok": False, "error": "Restart already in progress, please wait"}), 429
    _last_restart_request = now

    logger.warning("[RESTART] requested by user=%s for unit=%s", user, unit)

    def _do_restart():
        import subprocess
        # Give the HTTP response a moment to actually leave the process
        # before systemd kills it.
        time.sleep(0.15)
        try:
            subprocess.run(
                ["sudo", "-n", "systemctl", "restart", unit],
                timeout=10,
                check=False,
            )
        except Exception as e:
            logger.error("[RESTART] restart command failed: %s", e)

    threading.Thread(target=_do_restart, daemon=True).start()
    return jsonify({"ok": True, "message": f"Restarting {unit}..."})


@app.route("/api/control", methods=["POST"])
def api_control():
    data = request.get_json(force=True) or {}
    user = data.get("user", request.args.get("user", "admin")).strip().lower()
    us = get_user_state(user)
    if "squelch" in data: us["squelch"] = int(float(data["squelch"]))
    if "device_args" in data: us["device_args"] = str(data["device_args"]).strip()
    if "mode" in data: us["mode"] = str(data["mode"]).strip().upper()
    if "bw" in data: us["bw"] = int(float(data["bw"]))
    if "smoothing" in data: us["smoothing"] = float(data["smoothing"])
    if "span" in data:
        requested_span = float(data["span"])

        if sdrconn_client.connected:
            supported_rates = [
                200_000,
                500_000,
                1_000_000,
                2_000_000,
                4_000_000,
                6_000_000,
                8_000_000,
                10_000_000,
            ]

            # Select the smallest hardware rate that can contain the requested span.
            target_rate = next(
                (
                    rate
                    for rate in supported_rates
                    if rate >= requested_span
                ),
                supported_rates[-1],
            )

            current_rate = int(
                getattr(
                    sdrconn_client,
                    "_sample_rate",
                    2_000_000,
                )
            )

            if target_rate != current_rate:
                sdrconn_client._rate_lock = True
                sdrconn_client.set_sample_rate(target_rate)
                sdrconn_client._pending_sample_rate = int(target_rate)

                with engine.lock:
                    engine.sample_rate = float(target_rate)

                def _unlock_sdrconn_rate():
                    time.sleep(5.0)
                    sdrconn_client._rate_lock = False
                    sdrconn_client._pending_sample_rate = None

                threading.Thread(
                    target=_unlock_sdrconn_rate,
                    daemon=True,
                ).start()

                logger.info(
                    "[SDRCONN AUTOZOOM] span %.0f -> sample rate %.0f",
                    requested_span,
                    target_rate,
                )

            # Preserve the requested visual span, bounded by the selected rate.
            us["span"] = max(
                50_000.0,
                min(float(target_rate), requested_span),
            )

            with engine.lock:
                engine.span = float(us["span"])

        else:
            us["span"] = requested_span
    if "floor" in data: us["floor"] = float(data["floor"])
    if "wf_floor" in data: us["wf_floor"] = float(data["wf_floor"])
    # Also update engine for shared state
    if "mode" in data:
        engine.mode = us["mode"]

        if sdrconn_client.connected:
            sdrconn_client.set_mode(engine.mode)
            us["sdrconn_mode"] = engine.mode
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
    # A mode or bandwidth click is an event: send it once to the selected rig.
    # Do not rely on periodic rig polling to carry SDR state toward the radio.
    if "mode" in data or "bw" in data:
        radio_id = user_radio_map.get(
            user,
            int(data.get("radio", 1)),
        )

        logger.info(
            "[MODE SYNC] payload=%r user=%s radio=%d mode=%s bw=%d to_rig=%r",
            data,
            user,
            radio_id,
            us.get("mode", engine.mode),
            int(us.get("bw", engine.bw)),
            data.get("to_rig"),
        )

        get_rigsync(radio_id).request_mode(
            us.get("mode", engine.mode),
            int(us.get("bw", engine.bw)),
            user,
        )
    # Update KiwiSDR passband if connected
    if kiwi_mgr.connected and ("bw" in data or "mode" in data):
        _mode_map = {'usb':'usb','lsb':'lsb','am':'am','cw':'cw','cwu':'cw','cwl':'cw',
                     'nfm':'nfm','wfm':'am','pktusb':'usb','pktlsb':'lsb','dsb':'am'}
        _km = _mode_map.get(engine.mode.lower(), 'usb')
        _bw_hz = int(engine.bw)
        if _km in ('usb', 'cw'):
            _lc, _hc = 300, min(_bw_hz, 3800)
        elif _km == 'lsb':
            _lc, _hc = -min(_bw_hz, 3800), -300
        elif _km == 'am':
            _lc, _hc = -min(_bw_hz, 6000), min(_bw_hz, 6000)
        else:
            _lc, _hc = -min(_bw_hz, 4000), min(_bw_hz, 4000)
        try:
            _snd = getattr(kiwi_mgr, '_snd_client', None)
            if _snd:
                _snd.set_mod(_km, _lc, _hc, engine.center_freq / 1000.0)
        except Exception as _e:
            logger.debug("[KIWI] passband update failed: %s", _e)
    if kiwi_mgr.connected and ("bw" in data or "mode" in data):
        _mm = {'usb':'usb','lsb':'lsb','am':'am','cw':'cw','cwu':'cw','cwl':'cw',
               'nfm':'nfm','wfm':'am','pktusb':'usb','pktlsb':'lsb','dsb':'am'}
        _km = _mm.get(engine.mode.lower(), 'usb')
        _bw = int(engine.bw)
        if _km in ('usb','cw'):   _lc3,_hc3 = 300,min(_bw,3800)
        elif _km == 'lsb':        _lc3,_hc3 = -min(_bw,3800),-300
        elif _km == 'am':         _lc3,_hc3 = -min(_bw,6000),min(_bw,6000)
        else:                     _lc3,_hc3 = -min(_bw,4000),min(_bw,4000)
        try:
            _snd = getattr(kiwi_mgr, '_snd_client', None)
            if _snd: _snd.set_mod(_km, _lc3, _hc3, engine.center_freq / 1000.0)
        except Exception as _e:
            logger.debug("[KIWI] passband update failed: %s", _e)
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
        requested_gain = float(data["hw_gain"])
        us["hw_gain"] = requested_gain

    def _set_gain():
        try:
            if sdrconn_client.connected:
                # SDRconnect uses an indexed LNA state rather than dB.
                lna = int(round(requested_gain))
                sdrconn_client.set_lna(lna)

                logger.info(
                    "[HW GAIN] SDRconnect hw_gain=%s -> lna_state=%d",
                    data["hw_gain"],
                    lna,
                )

            elif engine.device_driver == "rx888":
                source = getattr(
                    engine,
                    "rx888_source",
                    None,
                )

                if source is None:
                    raise RuntimeError(
                        "RX888 native source unavailable"
                    )

                actual_gain = source.set_gain(
                    requested_gain
                )

                with engine.lock:
                    engine.hw_gain = float(actual_gain)
                    engine.hw_agc = False

                logger.info(
                    "[CONTROL] RX888 gain set to %.1f",
                    engine.hw_gain,
                )

            elif engine.sdr:
                engine.sdr.setGainMode(
                    SOAPY_SDR_RX,
                    0,
                    False,
                )
                engine.sdr.setGain(
                    SOAPY_SDR_RX,
                    0,
                    requested_gain,
                )

                with engine.lock:
                    engine.hw_gain = requested_gain
                    engine.hw_agc = False

                logger.info(
                    "[CONTROL] Soapy gain set to %.1f",
                    requested_gain,
                )

        except Exception as e:
            logger.warning(
                "[CONTROL] gain failed: %s",
                e,
            )

    threading.Thread(
        target=_set_gain,
        daemon=True,
    ).start()
    state = engine.get_state()
    _kiwi_keys = ("mode","bw","floor","wf_floor","smoothing","memories","band_memories","band_slot")
    _all_keys = _kiwi_keys + ("span",)
    state.update({k: us[k] for k in (_kiwi_keys if kiwi_mgr.connected else _all_keys)})
    return jsonify({"ok": True, "state": state})

INSTANCES_FILE = "/home/pi/sdr_web/config/instances.json"
INSTANCES_LOCK = INSTANCES_FILE + ".lock"
_active_callsign = ""


def _read_instances_unlocked():
    """Read the registry, treating empty or damaged content as an empty list."""
    try:
        with open(INSTANCES_FILE, "r") as f:
            content = f.read().strip()

        if not content:
            return []

        data = json.loads(content)
        return data if isinstance(data, list) else []

    except (FileNotFoundError, json.JSONDecodeError, OSError, TypeError):
        return []


def _write_instances_unlocked(instances):
    """Atomically replace the registry file."""
    tmp = f"{INSTANCES_FILE}.{os.getpid()}.tmp"

    with open(tmp, "w") as f:
        json.dump(instances, f, indent=2)
        f.write("\n")
        f.flush()
        os.fsync(f.fileno())

    os.replace(tmp, INSTANCES_FILE)


def _register_instance(username="", callsign=""):
    global _active_callsign

    if callsign:
        _active_callsign = callsign

    try:
        os.makedirs(os.path.dirname(INSTANCES_FILE), exist_ok=True)

        with open(INSTANCES_LOCK, "a+") as lock:
            fcntl.flock(lock.fileno(), fcntl.LOCK_EX)

            instances = _read_instances_unlocked()
            now = time.time()

            # Remove entries that have not checked in recently.
            instances = [
                inst for inst in instances
                if inst.get("port") == SERVER_PORT
                or now - float(inst.get("last_seen", 0)) <= 120
            ]

            found = False
            for inst in instances:
                if inst.get("port") == SERVER_PORT:
                    inst.update({
                        "label": f"SDR @ {SERVER_PORT}",
                        "device": engine.device_driver,
                        "device_serial": getattr(engine, "device_serial", ""),
                        "active": True,
                        "last_seen": now,
                        "in_use_by": _active_callsign,
                    })
                    found = True
                    break

            if not found:
                instances.append({
                    "port": SERVER_PORT,
                    "label": f"SDR @ {SERVER_PORT}",
                    "device": engine.device_driver,
                    "device_serial": getattr(engine, "device_serial", ""),
                    "active": True,
                    "last_seen": now,
                    "in_use_by": _active_callsign,
                })

            _write_instances_unlocked(instances)

    except Exception as e:
        logger.warning("[INST] register error: %s", e)


def _get_all_instances():
    try:
        os.makedirs(os.path.dirname(INSTANCES_FILE), exist_ok=True)

        with open(INSTANCES_LOCK, "a+") as lock:
            fcntl.flock(lock.fileno(), fcntl.LOCK_SH)
            return _read_instances_unlocked()

    except Exception as e:
        logger.warning("[INST] registry read error: %s", e)
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
    user = data.get("user", "admin").strip().lower()
    logger.info("[DEVICE] switch to driver=%s serial=%s user=%s", driver, serial, user)
    if not driver:
        return jsonify({"error": "missing driver"}), 400
    # Check if another instance actually has this device open right now
    if driver != "fake":
        for inst in _get_all_instances():
            if inst.get("port") == SERVER_PORT:
                continue
            age = time.time() - inst.get("last_seen", 0)
            if age > 120:
                continue  # stale
            if inst.get("device","") in ("", "fake"):
                continue  # that instance is on fake, no conflict
            if inst.get("device") != driver:
                continue  # different device type
            # Query the other instance's live state to confirm device is actually open
            try:
                import urllib.request as _ur

                url = f"http://localhost:{inst['port']}/api/state?user=admin&radio=1"

                _resp = _ur.urlopen(url, timeout=1)
                _live = json.loads(_resp.read())

                live_driver = _live.get("device_driver", "fake")
                live_serial = _live.get("device_serial", "")
                live_open = bool(_live.get("device_open", False))

            except Exception:
                live_driver = inst.get("device", "fake")
                live_serial = inst.get("device_serial", "")
                live_open = live_driver not in ("", "fake")
            if live_driver == "fake":
                logger.info("[DEVICE] conflict check: port %s has driver=%s (live=fake, skip)", inst.get("port"), driver)
                continue  # other instance not actually using device
            # Count physical units
            try:
                all_devs = SoapySDR.Device.enumerate()
                unit_count = sum(1 for d in all_devs
                if dict(d).get("driver","").lower() == driver
                                 and not dict(d).get("remote",""))
            except Exception:
                unit_count = 1
            other_serial = inst.get("device_serial","")
            logger.info("[DEVICE] conflict check: port %s live=%s serial=%s unit_count=%d", inst.get("port"), live_driver, other_serial, unit_count)
            if serial == other_serial or unit_count <= 1 or not serial or not other_serial:
                return jsonify({"error": f"Device in use by port {inst['port']}",
                                "in_use_by_port": inst["port"]}), 409
    # Save device selection to user settings
    us = get_user_state(user)
    us["device_driver"] = driver
    us["device_serial"] = serial
    save_user_settings(user, us)
    # Update instance config so device persists across restart
    try:
        with open(_cfg_path) as _f:
            _cfg = json.load(_f)
        _cfg["device_driver"] = driver
        _cfg["device_serial"] = serial
        with open(_cfg_path, "w") as _f:
            json.dump(_cfg, _f, indent=2)
        logger.info("[DEVICE] updated instance config: driver=%s serial=%s", driver, serial)
    except Exception as _ce:
        logger.warning("[DEVICE] could not update instance config: %s", _ce)

    # A full process restart after a device switch is more reliable than the
    # in-process SoapySDR close/reopen below — it guarantees clean engine
    # state and avoids the class of "frozen panel" issues that come from a
    # stuck reopen leaving the gevent worker in a bad state. The in-process
    # path below still runs for the *current* request/response so the user
    # sees an immediate ok, but we follow it with a restart shortly after.
    def _restart_after_switch():
        import subprocess
        time.sleep(2.5)  # let the in-process switch attempt finish/settle first
        unit = f"sdr_web@{SERVER_PORT}"
        logger.warning("[DEVICE] restarting %s after device switch to driver=%s", unit, driver)
        try:
            subprocess.run(["sudo", "-n", "systemctl", "restart", unit], timeout=10, check=False)
        except Exception as e:
            logger.error("[DEVICE] post-switch restart failed: %s", e)
    threading.Thread(target=_restart_after_switch, daemon=True).start()

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
            time.sleep(1.0)  # give stream time to stabilize
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

# ---- Audio recordings (browser-side MediaRecorder, server is just storage) ----
import re as _re_audio
RECORDINGS_DIR = os.path.join(BASE_DIR, f"recordings_{SERVER_PORT}")
os.makedirs(RECORDINGS_DIR, exist_ok=True)

def _fmt_size(n):
    for unit in ("B", "KB", "MB", "GB"):
        if n < 1024:
            return f"{n:.0f} {unit}" if unit == "B" else f"{n:.1f} {unit}"
        n /= 1024
    return f"{n:.1f} TB"

def _safe_recording_name(name):
    """Reject anything that isn't a plain filename — no path traversal, no
    directory separators, must match our own generated pattern or be a
    simple safe filename."""
    if not name or "/" in name or "\\" in name or ".." in name:
        return None
    name = os.path.basename(name)
    if not _re_audio.match(r"^[A-Za-z0-9_.\-]+$", name):
        return None
    return name

@app.route("/api/audio/recordings", methods=["GET", "POST"])
def api_audio_recordings():
    if request.method == "GET":
        try:
            files = []
            for fn in sorted(os.listdir(RECORDINGS_DIR), reverse=True):
                full = os.path.join(RECORDINGS_DIR, fn)
                if os.path.isfile(full):
                    files.append({"name": fn, "size": _fmt_size(os.path.getsize(full))})
            return jsonify({"files": files})
        except Exception as e:
            logger.warning("[AUDIO] recordings list failed: %s", e)
            return jsonify({"files": []})

    # POST — upload a new recording (multipart/form-data, field "file")
    f = request.files.get("file")
    if not f or not f.filename:
        return jsonify({"ok": False, "error": "no file"}), 400
    # Build a safe name: keep client-provided name if it's clean, else
    # fall back to a timestamped one to guarantee no collisions/traversal.
    name = _safe_recording_name(f.filename)
    if not name:
        name = "rec_" + time.strftime("%Y%m%d_%H%M%S", time.gmtime()) + ".webm"
    dest = os.path.join(RECORDINGS_DIR, name)
    # Avoid clobbering an existing file with the same name
    if os.path.exists(dest):
        base, ext = os.path.splitext(name)
        name = f"{base}_{int(time.time())}{ext}"
        dest = os.path.join(RECORDINGS_DIR, name)
    try:
        f.save(dest)
        logger.info("[AUDIO] recording saved: %s (%s)", name, _fmt_size(os.path.getsize(dest)))
        # Transcode webm -> mp3 (browser MediaRecorder can't produce mp3 directly
        # due to licensing; ffmpeg + libmp3lame handles it server-side).
        if name.lower().endswith(".webm"):
            mp3_name = name[:-len(".webm")] + ".mp3"
            mp3_dest = os.path.join(RECORDINGS_DIR, mp3_name)
            try:
                import subprocess
                result = subprocess.run(
                    ["/usr/bin/ffmpeg", "-y", "-i", dest, "-q:a", "4", mp3_dest],
                    capture_output=True, timeout=30,
                )
                if result.returncode == 0 and os.path.isfile(mp3_dest):
                    os.remove(dest)
                    name = mp3_name
                    logger.info("[AUDIO] transcoded to mp3: %s", mp3_name)
                else:
                    logger.warning("[AUDIO] ffmpeg conversion failed (rc=%s): %s",
                                    result.returncode,
                                    (result.stderr or b"").decode(errors="replace")[:300])
            except Exception as e:
                logger.warning("[AUDIO] ffmpeg conversion error: %s", e)
        return jsonify({"ok": True, "name": name})
    except Exception as e:
        logger.error("[AUDIO] recording save failed: %s", e)
        return jsonify({"ok": False, "error": str(e)}), 500

@app.route("/api/audio/recordings/<path:name>", methods=["GET", "DELETE"])
def api_audio_recording_file(name):
    safe_name = _safe_recording_name(name)
    if not safe_name:
        return jsonify({"ok": False, "error": "invalid filename"}), 400

    if request.method == "DELETE":
        full = os.path.join(RECORDINGS_DIR, safe_name)
        try:
            if os.path.isfile(full):
                os.remove(full)
                logger.info("[AUDIO] recording deleted: %s", safe_name)
            return jsonify({"ok": True})
        except Exception as e:
            logger.error("[AUDIO] recording delete failed: %s", e)
            return jsonify({"ok": False, "error": str(e)}), 500

    # GET — serve the file for playback/download
    full = os.path.join(RECORDINGS_DIR, safe_name)
    if not os.path.isfile(full):
        return jsonify({"ok": False, "error": "not found"}), 404
    return send_from_directory(RECORDINGS_DIR, safe_name, as_attachment=False)

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
                    logger.debug("[TX] queue empty, continuing...")
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
                    logger.debug("[TX] frames=%d max=%d", frame_count, abs(audio_i16).max())
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

        _track = EngineAudioTrack(engine)
        pc.addTrack(_track)

        @pc.on("connectionstatechange")
        async def on_rx_state():
            if pc.connectionState in ("failed", "closed", "disconnected"):
                _track.cleanup()
                await pc.close()
                pcs.discard(pc)

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

RX888_SPAN_STEPS = (
    50_000.0,
    100_000.0,
    250_000.0,
    500_000.0,
    1_000_000.0,
    2_000_000.0,
    4_000_000.0,
    8_000_000.0,
    16_000_000.0,
    32_000_000.0,
)


def _snap_rx888_span(value):
    requested = max(RX888_SPAN_STEPS[0], min(RX888_SPAN_STEPS[-1], float(value)))
    return min(RX888_SPAN_STEPS, key=lambda step: abs(step - requested))


@app.route("/api/span", methods=["POST"])
def api_span():
    data = request.get_json(force=True) or {}
    user = str(data.get("user", "admin")).strip().lower() or "admin"

    if "span" in data:
        requested_span = max(
            50_000.0,
            float(data["span"]),
        )

        if sdrconn_client.connected:
            supported_rates = [
                200_000,
                500_000,
                1_000_000,
                2_000_000,
                4_000_000,
                6_000_000,
                8_000_000,
                10_000_000,
            ]

            target_rate = next(
                (
                    rate
                    for rate in supported_rates
                    if rate >= requested_span
                ),
                supported_rates[-1],
            )

            current_rate = int(
                getattr(
                    sdrconn_client,
                    "_sample_rate",
                    2_000_000,
                )
            )

            if target_rate != current_rate:
                sdrconn_client._rate_lock = True
                sdrconn_client.set_sample_rate(target_rate)
                sdrconn_client._pending_sample_rate = int(target_rate)
                with engine.lock:
                    engine.sample_rate = float(target_rate)

                def _unlock_sdrconn_rate():
                    time.sleep(5.0)
                    sdrconn_client._rate_lock = False
                    sdrconn_client._pending_sample_rate = None

                threading.Thread(
                    target=_unlock_sdrconn_rate,
                    daemon=True,
                ).start()

                logger.info(
                    "[SDRCONN AUTOZOOM] span %.0f -> sample rate %.0f",
                    requested_span,
                    target_rate,
                )

            engine.span = min(
                requested_span,
                float(target_rate),
            )

        elif engine.device_driver == "rx888":
            engine.span = _snap_rx888_span(requested_span)

        else:
            device_max_span = max(
                50_000.0,
                float(engine.sample_rate),
            )
            engine.span = max(
                50_000.0,
                min(device_max_span, requested_span),
            )

        us = get_user_state(user)
        us["span"] = float(engine.span)

        if sdrconn_client.connected:
            us["sdrconn_sample_rate"] = int(
                getattr(
                    sdrconn_client,
                    "_sample_rate",
                    engine.sample_rate,
                )
            )

        save_user_settings(user, us)

    if "center" in data:
        try:
            center = float(data["center"])

            if engine.device_driver == "rx888":
                valid_center = (
                    0.0
                    <= center
                    <= float(engine.input_sample_rate) / 2.0
                )
            else:
                half_rate = float(engine.sample_rate) / 2.0
                valid_center = (
                    abs(center - float(engine.center_freq))
                    <= half_rate
                )

            if valid_center:
                engine.zoom_center_hz = center

        except (TypeError, ValueError):
            pass

    return jsonify({
        "ok": True,
        "span": float(engine.span),
        "sample_rate": (
            float(sdrconn_client._sample_rate)
            if sdrconn_client.connected
            else float(engine.sample_rate)
        ),
        "zoom_center": float(engine.zoom_center_hz),
    })

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
active_ws_users = {}  # ws_id -> username

@app.route("/api/active_users")
def api_active_users():
    # Local WebSocket users
    users = set(active_ws_users.values())
    # Also check DB for recently active users (last 5 minutes)
    try:
        conn = pymysql.connect(host="localhost", user="ham", password="7388",
                               database="station", connect_timeout=3,
                               cursorclass=pymysql.cursors.DictCursor)
        with conn.cursor() as cur:
            cur.execute("SELECT Username FROM Users WHERE Active='1' AND Access_Level < 9")
            for row in cur.fetchall():
                users.add(row['Username'].lower())
        conn.close()
    except Exception:
        pass
    return jsonify({"ok": True, "users": sorted(users)})

AUDIO_FRAME     = 2880          # 60ms @ 48kHz
AUDIO_RING_SIZE = int(4.0 * 48000)

def spectrum_ws(ws):
    global ws_id_counter
    from urllib.parse import parse_qs
    qs = parse_qs(ws.environ.get("QUERY_STRING", ""))
    callsign = qs.get("call", ["ADMIN"])[0].upper()
    ws_user = qs.get("user", ["admin"])[0].lower()
    ws_id = ws_id_counter; ws_id_counter += 1
    tune_notify[ws_id] = None
    active_ws_users[ws_id] = ws_user
    ws_audio_enabled  = False
    ws_audio_read_pos = [0]
    ws_cmd_queue      = []
    logger.info("[WS] connected: %s id=%d", callsign, ws_id)

    def _ws_reader():
        try:
            while True:
                msg = ws.receive()
                if msg is None:
                    break
                try:
                    ws_cmd_queue.append(json.loads(msg))
                except Exception:
                    pass
        except Exception:
            pass
    gevent.spawn(_ws_reader)

    try:
        while True:
            spec = engine.get_spectrum()
            full = spec["mags"]
            mags = [round(v, 1) for v in full]
            us = get_user_state(ws_user)
            ws_radio = user_radio_map.get(ws_user, 1)
            rig_freq = rigsyncs[ws_radio].last_freq if ws_radio in rigsyncs else None
            payload = {
                "hw_center":      spec["hw_center"],
                "center":         spec["center"],
                "sample_rate":    sdrconn_client._sample_rate if sdrconn_client.connected else spec["sample_rate"],
                "fft_size":       len(full),
                "mags":           mags,
                "mode":           spec["mode"],
                "bw":             spec["bw"],
                "rig_freq":       rig_freq,
                "owrx_connected": owrx_client.connected,
                "owrx_center":    float(owrx_client._center_hz) if owrx_client.connected else None,
                "owrx_span":      float(owrx_client._span_hz)   if owrx_client.connected else None,
                "zoom_active":    spec.get("zoom_active", False),
                "decimation":     spec.get("decimation", 1),
                "hz_per_bin":     spec.get("hz_per_bin"),
            }
            notify = tune_notify.get(ws_id)
            if notify:
                tune_notify[ws_id] = None
                payload.update({
                    "tune_notify": True,
                    "callsign":    notify["callsign"],
                    "freq_hz":     notify["freq_hz"],
                    "in_band":     notify.get("in_band", True),
                    "self_tune":   (callsign == notify["callsign"]),
                })
##            if ws_id == 0 and (time.time() % 5) < 0.02:
 ##           logger.info("[WS] bins=%d json=%d bytes audio=%s", len(full), len(json.dumps(payload)), ws_audio_enabled)
            ws.send(json.dumps(payload))

            # Drain command queue FIRST — so audio_on sets read_pos before first send
            while ws_cmd_queue:
                cmd = ws_cmd_queue.pop(0)
                if cmd.get("cmd") == "audio_on":
                    ws_audio_enabled = True
                    ring = _get_or_create_ring(engine)
                    ws_audio_read_pos[0] = ring['write_pos']
                    logger.info("[WS] audio enabled for %s", ws_user)
                elif cmd.get("cmd") == "audio_off":
                    ws_audio_enabled = False

            # Send audio frames if enabled
            if ws_audio_enabled:
                try:
                    ring = _get_or_create_ring(engine)
                    rp = ws_audio_read_pos[0]
                    avail = (ring['write_pos'] - rp) % AUDIO_RING_SIZE
                    sent = 0
                    while avail >= AUDIO_FRAME and sent < 4:
                        end = rp + AUDIO_FRAME
                        if end <= AUDIO_RING_SIZE:
                            audio_f32 = ring['buf'][rp:end].copy()
                        else:
                            first = AUDIO_RING_SIZE - rp
                            audio_f32 = np.concatenate([ring['buf'][rp:], ring['buf'][:end - AUDIO_RING_SIZE]])
                        rp = end % AUDIO_RING_SIZE
                        pcm = (np.clip(audio_f32, -1.0, 1.0) * 32767).astype(np.int16)
                        frame = bytes([0x01]) + pcm.tobytes()
                        ws.send(frame)
                        avail = (ring['write_pos'] - rp) % AUDIO_RING_SIZE
                        sent += 1
                    ws_audio_read_pos[0] = rp
                except Exception as _ae:
                    logger.warning("[WS] audio send error: %s", _ae)

            gevent.sleep(0.02)
    except Exception as e:
        logger.info("[WS] closed: %s", e)
    finally:
        tune_notify.pop(ws_id, None)
        active_ws_users.pop(ws_id, None)

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


# ---------------------------------------------------------------------------
# LoTW user cache
# ---------------------------------------------------------------------------
_lotw_users = {}  # call -> last_upload_date
_lotw_loaded = False

def _load_lotw_cache():
    global _lotw_users, _lotw_loaded
    path = os.path.join(BASE_DIR, "lotw_users.csv")
    if not os.path.exists(path):
        logger.warning("[LOTW] Cache file not found: %s", path)
        return
    try:
        with open(path) as f:
            for line in f:
                parts = line.strip().split(',')
                if len(parts) >= 2:
                    _lotw_users[parts[0].upper()] = parts[1]
        _lotw_loaded = True
        logger.info("[LOTW] Loaded %d users", len(_lotw_users))
    except Exception as e:
        logger.warning("[LOTW] Load failed: %s", e)

# Load at startup in background
import threading as _threading
_threading.Thread(target=_load_lotw_cache, daemon=True).start()

@app.route("/api/lotw_check")
def api_lotw_check():
    call = request.args.get("call","").strip().upper()
    if not call:
        return jsonify({"ok": False, "error": "call required"})
    date = _lotw_users.get(call)
    return jsonify({"ok": True, "call": call, "lotw": date is not None, "last_upload": date or ""})

# ---------------------------------------------------------------------------
# ELMER Workability Index
# ---------------------------------------------------------------------------
try:
    from elmer_workability import WorkabilityEngine as _WorkabilityEngine
    _workability_engine = None

    def _get_workability_engine(my_call, my_grid, beam_heading=None):
        global _workability_engine
        if _workability_engine is None or _workability_engine.my_call != my_call:
            _workability_engine = _WorkabilityEngine(my_call=my_call, my_grid=my_grid, beam_heading=beam_heading)
        return _workability_engine
    _workability_available = True
    logger.info("[WORKABILITY] Module loaded OK")
except Exception as _we:
    _workability_available = False
    logger.warning("[WORKABILITY] Module not available: %s", _we)

@app.route("/api/workability")
def api_workability():
    if not _workability_available:
        return jsonify({"ok": False, "error": "Workability module not loaded"}), 503
    target_call = request.args.get("target_call", "").strip().upper()
    if not target_call:
        return jsonify({"ok": False, "error": "target_call required"}), 400
    target_grid = request.args.get("target_grid", "").strip() or None
    band = request.args.get("band", "20m").strip()
    cluster_spots = int(request.args.get("cluster_spots", "0") or "0")
    beam_heading_str = request.args.get("beam_heading", "")
    beam_heading = float(beam_heading_str) if beam_heading_str else None
    user = request.args.get("user", "admin").strip().lower()
    my_call = "W6HN"
    my_grid = "EN90bt"
    has_rotor = False
    rotor_az = None
    try:
        conn = pymysql.connect(host="localhost", user="ham", password="7388",
                               database="station", connect_timeout=3,
                               cursorclass=pymysql.cursors.DictCursor)
        with conn.cursor() as cur:
            cur.execute("SELECT MyCall, My_Grid FROM Users WHERE Username=%s LIMIT 1", (user,))
            row = cur.fetchone()
            if row:
                if row.get("MyCall"): my_call = row["MyCall"].strip()
                if row.get("My_Grid"): my_grid = row["My_Grid"].strip()
            cur.execute("SELECT RotorAzOut, powerOut FROM RadioInterface WHERE Radio=1 LIMIT 1")
            ri = cur.fetchone()
            if ri:
                try: rotor_az = float(ri["RotorAzOut"]) if ri.get("RotorAzOut") else None
                except: pass
            cur.execute("SELECT RotorID FROM MySettings LIMIT 1")
            ms = cur.fetchone()
            if ms: has_rotor = (ms.get("RotorID", 1) != 1)
        conn.close()
    except Exception as e:
        logger.warning("[WORKABILITY] DB lookup: %s", e)
    if beam_heading is None and rotor_az is not None:
        beam_heading = rotor_az  # use rotor position even if dummy
    # QRZ lookup — always run for name/photo/LoTW, also get grid if missing
    qrz_info = {}
    if True:
        try:
            _qconn = pymysql.connect(host="localhost", user="ham", password="7388",
                                     database="station", connect_timeout=3,
                                     cursorclass=pymysql.cursors.DictCursor)
            with _qconn.cursor() as _qcur:
                _qcur.execute("SELECT qrzUser, qrzPWD, hamqthUser, hamqthPWD FROM Users WHERE Username=%s LIMIT 1", (user,))
                _qrow = _qcur.fetchone()
            _qconn.close()
            if _qrow and _qrow.get("qrzUser") and _qrow.get("qrzPWD"):
                from elmer_workability import fetch_qrz_grid as _fqrz
                _qgrid, qrz_info = _fqrz(target_call, _qrow["qrzUser"], _qrow["qrzPWD"])
                if _qgrid and not target_grid:
                    target_grid = _qgrid
                    logger.info("[WORKABILITY] QRZ grid for %s: %s", target_call, target_grid)
            # HamQTH fallback — use if QRZ failed or returned no info
            if (not qrz_info or not qrz_info.get("name")) and _qrow and _qrow.get("hamqthUser") and _qrow.get("hamqthPWD"):
                from elmer_workability import fetch_hamqth_grid as _fhqth
                _hgrid, _hinfo = _fhqth(target_call, _qrow["hamqthUser"], _qrow["hamqthPWD"])
                if _hinfo and not _hinfo.get("error"):
                    qrz_info = _hinfo
                    logger.info("[WORKABILITY] HamQTH info for %s", target_call)
                if _hgrid and not target_grid:
                    target_grid = _hgrid
                    logger.info("[WORKABILITY] HamQTH grid for %s: %s", target_call, target_grid)
        except Exception as _qe:
            logger.warning("[WORKABILITY] QRZ lookup: %s", _qe)
    # Look up flag abbreviation from country name for image fallback
    if qrz_info and qrz_info.get("country") and not qrz_info.get("image"):
        try:
            _aconn = pymysql.connect(host="localhost", user="ham", password="7388",
                                     database="station", connect_timeout=3,
                                     cursorclass=pymysql.cursors.DictCursor)
            with _aconn.cursor() as _acur:
                _acur.execute("SELECT Abbreviation FROM Prefixes WHERE Country=%s LIMIT 1",
                              (qrz_info["country"],))
                _arow = _acur.fetchone()
                if _arow and _arow.get("Abbreviation"):
                    qrz_info["abbreviation"] = _arow["Abbreviation"].strip().lower()
            _aconn.close()
        except Exception as _ae:
            logger.warning("[WORKABILITY] abbreviation lookup: %s", _ae)
    try:
        eng = _get_workability_engine(my_call, my_grid, beam_heading)
        result = eng.score(target_call=target_call, target_grid=target_grid,
                           band=band, cluster_spots=cluster_spots,
                           beam_heading=beam_heading)
        result["ok"] = True
        result["my_call"] = my_call
        result["my_grid"] = my_grid
        result["qrz"] = qrz_info or {}
        result["target_grid_used"] = target_grid or ""
        result["lotw"] = {"active": target_call in _lotw_users, "last_upload": _lotw_users.get(target_call, "")}
        result["has_rotor"] = has_rotor
        result["rotor_az"] = rotor_az
        return jsonify(result)
    except Exception as e:
        logger.error("[WORKABILITY] Error: %s", e)
        return jsonify({"ok": False, "error": str(e)}), 500

# ---------------------------------------------------------------------------
# Messaging system
# ---------------------------------------------------------------------------
import uuid as _uuid_mod
import time as _time_mod

MSG_FILE = os.path.join(os.path.dirname(__file__), "messages.json")

def _load_messages():
    try:
        with open(MSG_FILE) as f:
            return json.load(f)
    except Exception:
        return []

def _save_messages(msgs):
    with open(MSG_FILE, "w") as f:
        json.dump(msgs, f)

@app.route("/api/msg/send", methods=["POST"])
def api_msg_send():
    d = request.get_json(force=True) or {}
    user = request.args.get("user", "admin").strip().lower()
    us = get_user_state(user)
    is_admin = us.get("is_admin", False) or (user == "admin")
    from_call = (us.get("callsign") or user).upper()
    to = d.get("to", "all").strip()
    text = d.get("text", "").strip()[:500]
    msg_type = d.get("type", "message")
    if not text:
        return jsonify({"ok": False, "error": "empty"}), 400
    if to != "admin" and not is_admin:
        return jsonify({"ok": False, "error": "unauthorized"}), 403
    if not is_admin:
        msg_type = "message"
    msg = {
        "id": str(_uuid_mod.uuid4()),
        "from": from_call,
        "to": to,
        "type": msg_type,
        "text": text,
        "ts": _time_mod.time(),
        "read_by": []
    }
    msgs = _load_messages()
    msgs.append(msg)
    cutoff = _time_mod.time() - 86400
    msgs = [m for m in msgs if m.get("ts", 0) > cutoff]
    _save_messages(msgs)
    logger.info("[MSG] from=%s to=%s type=%s: %s", from_call, to, msg_type, text)
    return jsonify({"ok": True, "id": msg["id"]})

@app.route("/api/msg/poll")
def api_msg_poll():
    user = request.args.get("user", "").strip().lower()
    if not user:
        return jsonify({"messages": []})
    us = get_user_state(user)
    callsign = (us.get("callsign") or user).upper()
    is_admin = us.get("is_admin", False)
    msgs = _load_messages()
    result = []
    for m in msgs:
        to = m.get("to", "all")
        if to == "all" or to.upper() == callsign or is_admin:
            if callsign not in m.get("read_by", []):
                result.append(m)
    return jsonify({"messages": result})

@app.route("/api/msg/ack", methods=["POST"])
def api_msg_ack():
    d = request.get_json(force=True) or {}
    user = request.args.get("user", "").strip().lower()
    us = get_user_state(user)
    callsign = (us.get("callsign") or user).upper()
    msg_ids = d.get("ids", [])
    msgs = _load_messages()
    for m in msgs:
        if m["id"] in msg_ids and callsign not in m.get("read_by", []):
            m.setdefault("read_by", []).append(callsign)
    _save_messages(msgs)
    return jsonify({"ok": True})

@app.route("/api/msg/list")
def api_msg_list():
    user = request.args.get("user", "").strip().lower()
    us = get_user_state(user)
    if not us.get("is_admin", False):
        return jsonify({"ok": False, "error": "unauthorized"}), 403
    msgs = _load_messages()
    return jsonify({"ok": True, "messages": msgs[-100:]})


@app.route("/api/admin/restart", methods=["POST"])
def api_admin_restart():
    user = request.args.get("user", "").strip().lower()
    us = get_user_state(user)
    if not (us.get("is_admin", False) or user == "admin"):
        return jsonify({"ok": False, "error": "unauthorized"}), 403
    import subprocess as _sp
    results = {}
    own_port = SERVER_PORT
    other_ports = [p for p in [8001, 8002, 8003, 8004, 8005] if p != own_port]
    for port in other_ports:
        try:
            r = _sp.run(
                ["sudo", "systemctl", "restart", f"sdr_web@{port}"],
                capture_output=True, timeout=10
            )
            results[port] = "ok" if r.returncode == 0 else r.stderr.decode()[:80]
        except Exception as e:
            results[port] = str(e)[:80]
    logger.info("[ADMIN] restart requested by %s: %s", user, results)
    resp = jsonify({"ok": True, "results": results})
    # Restart own instance after sending response
    def _restart_self():
        import time as _t
        _t.sleep(1)
        _sp.Popen(["sudo", "systemctl", "restart", f"sdr_web@{own_port}"])
    threading.Thread(target=_restart_self, daemon=True).start()
    return resp



@app.route("/api/log_lookup")
def api_log_lookup():
    """Look up log history and award status for a target callsign.
    Query params:
        target_call: callsign to look up
        user: username (default: admin)
    """
    target_call = request.args.get("target_call","").strip().upper()
    if not target_call:
        return jsonify({"ok": False, "error": "target_call required"}), 400
    user = request.args.get("user", "admin").strip().lower()

    try:
        conn = pymysql.connect(host="localhost", user="ham", password="7388",
                               database="station", connect_timeout=3,
                               cursorclass=pymysql.cursors.DictCursor)
        with conn.cursor() as cur:
            # ── Previous QSOs with this call ──────────────────────────────────
            cur.execute("""
                SELECT Callsign, Band, Mode, Time_Start_Plain,
                       LoTWReceived, eQSLReceived, QSL_R, His_Country,
                       His_Continent, DXCC, CQZone
                FROM Logbook
                WHERE Callsign=%s
                ORDER BY Time_Start DESC
                LIMIT 10
            """, (target_call,))
            qsos = cur.fetchall() or []

            # ── Get DXCC entity for this call from Prefixes ───────────────────
            cur.execute("""
                SELECT Country, Continent, CQ_Zone, DXCC
                FROM Prefixes
                WHERE %s LIKE CONCAT(Prefix, '%%')
                ORDER BY LENGTH(Prefix) DESC
                LIMIT 1
            """, (target_call,))
            prefix_info = cur.fetchone() or {}

            # ── Award computations ────────────────────────────────────────────
            # DXCC — unique confirmed entities
            cur.execute("""
                SELECT DXCC, MAX(CASE WHEN LoTWReceived='Y' OR QSL_R='Y' OR eQSLReceived='Y'
                               THEN 1 ELSE 0 END) as confirmed
                FROM Logbook WHERE DXCC IS NOT NULL AND DXCC != ''
                GROUP BY DXCC
            """)
            dxcc_rows = cur.fetchall() or []
            dxcc_worked = len(dxcc_rows)
            dxcc_confirmed = sum(1 for r in dxcc_rows if r['confirmed'])

            # Check if target DXCC is already worked/confirmed
            target_dxcc = prefix_info.get('DXCC') or (qsos[0]['DXCC'] if qsos else None)
            dxcc_need = True
            dxcc_status = "needed"
            if target_dxcc:
                for r in dxcc_rows:
                    if str(r['DXCC']) == str(target_dxcc):
                        dxcc_need = False
                        dxcc_status = "confirmed" if r['confirmed'] else "worked unconfirmed"
                        break

            # WAC — 6 continents
            CONTINENTS = {'AF':'Africa','AN':'Antarctica','AS':'Asia',
                          'EU':'Europe','NA':'North America','OC':'Oceania','SA':'South America'}
            cur.execute("""
                SELECT His_Continent,
                       MAX(CASE WHEN LoTWReceived='Y' OR QSL_R='Y' OR eQSLReceived='Y'
                               THEN 1 ELSE 0 END) as confirmed
                FROM Logbook WHERE His_Continent IS NOT NULL AND His_Continent != ''
                GROUP BY His_Continent
            """)
            cont_rows = {r['His_Continent']: r['confirmed'] for r in (cur.fetchall() or [])}
            wac_worked = len([c for c in cont_rows if c in CONTINENTS])
            wac_confirmed = len([c for c in cont_rows if c in CONTINENTS and cont_rows[c]])
            target_cont = prefix_info.get('Continent') or (qsos[0]['His_Continent'] if qsos else None)
            wac_need = target_cont and (target_cont not in cont_rows)
            wac_cont_status = ""
            if target_cont:
                if target_cont not in cont_rows:
                    wac_cont_status = f"need {CONTINENTS.get(target_cont, target_cont)} for WAC"
                elif not cont_rows[target_cont]:
                    wac_cont_status = f"{CONTINENTS.get(target_cont, target_cont)} worked but unconfirmed"

            # WAZ — 40 CQ zones
            cur.execute("""
                SELECT CQZone,
                       MAX(CASE WHEN LoTWReceived='Y' OR QSL_R='Y' OR eQSLReceived='Y'
                               THEN 1 ELSE 0 END) as confirmed
                FROM Logbook WHERE CQZone IS NOT NULL AND CQZone != ''
                GROUP BY CQZone
            """)
            zone_rows = {str(r['CQZone']): r['confirmed'] for r in (cur.fetchall() or [])}
            waz_worked = len(zone_rows)
            waz_confirmed = len([z for z in zone_rows if zone_rows[z]])
            target_zone = prefix_info.get('CQ_Zone') or (qsos[0]['CQZone'] if qsos else None)
            waz_need = target_zone and (str(target_zone) not in zone_rows)

            # WAS — 50 US states
            cur.execute("""
                SELECT His_State,
                       MAX(CASE WHEN LoTWReceived='Y' OR QSL_R='Y' OR eQSLReceived='Y'
                               THEN 1 ELSE 0 END) as confirmed
                FROM Logbook WHERE His_Country LIKE '%United States%'
                AND His_State IS NOT NULL AND His_State != ''
                GROUP BY His_State
            """)
            state_rows = cur.fetchall() or []
            was_worked = len(state_rows)
            was_confirmed = sum(1 for r in state_rows if r['confirmed'])

        conn.close()

        # ── Format QSO history ────────────────────────────────────────────────
        qso_list = []
        for q in qsos:
            conf = []
            if q.get('LoTWReceived') == 'Y': conf.append('LoTW')
            if q.get('eQSLReceived') == 'Y': conf.append('eQSL')
            if q.get('QSL_R') == 'Y': conf.append('QSL')
            qso_list.append({
                'date': (q.get('Time_Start_Plain') or '')[:10],
                'band': q.get('Band',''),
                'mode': q.get('Mode',''),
                'confirmed': conf,
                'country': q.get('His_Country',''),
            })

        # ── Award needs summary ───────────────────────────────────────────────
        awards = []
        if dxcc_need:
            awards.append(f"Need {prefix_info.get('Country', target_call)} for DXCC ({dxcc_confirmed} confirmed, {dxcc_worked} worked)")
        else:
            awards.append(f"DXCC: {prefix_info.get('Country', '')} already {dxcc_status} ({dxcc_confirmed}/{dxcc_worked})")

        if wac_cont_status:
            awards.append(f"WAC: {wac_cont_status} ({wac_confirmed}/6 continents confirmed)")
        else:
            awards.append(f"WAC: {wac_confirmed}/6 continents confirmed")

        awards.append(f"WAZ: Zone {target_zone} {'needed' if waz_need else 'worked'} ({waz_confirmed}/40 zones confirmed)")
        awards.append(f"WAS: {was_confirmed}/50 states confirmed")

        return jsonify({
            "ok": True,
            "target_call": target_call,
            "qsos": qso_list,
            "awards": awards,
            "dxcc": {"worked": dxcc_worked, "confirmed": dxcc_confirmed, "need": dxcc_need, "status": dxcc_status},
            "wac": {"worked": wac_worked, "confirmed": wac_confirmed, "need": bool(wac_need)},
            "waz": {"worked": waz_worked, "confirmed": waz_confirmed, "need": bool(waz_need), "zone": target_zone},
            "was": {"worked": was_worked, "confirmed": was_confirmed},
            "country": prefix_info.get('Country',''),
            "continent": prefix_info.get('Continent',''),
            "cq_zone": prefix_info.get('CQ_Zone',''),
        })

    except Exception as e:
        logger.error("[LOG_LOOKUP] %s", e)
        return jsonify({"ok": False, "error": str(e)}), 500


@app.route("/api/signal_check")
def api_signal_check():
    """Check signal strength at a given frequency.
    Query params:
        freq_hz: center frequency to check
        bw_hz: bandwidth to check (default 3000)
        user: username
    Returns signal level, noise floor, and whether signal detected.
    """
    try:
        freq_hz = float(request.args.get("freq_hz", 0))
        bw_hz   = float(request.args.get("bw_hz", 3000))
        if not freq_hz:
            return jsonify({"ok": False, "error": "freq_hz required"}), 400

        mags = engine.latest_mags
        if mags is None or len(mags) == 0:
            return jsonify({"ok": False, "error": "No spectrum data"})

        n = len(mags)
        cf = engine.center_freq
        sr = engine.sample_rate

        # Convert freq to bin index
        hz_per_bin = sr / n
        center_bin = int((freq_hz - cf + sr/2) / sr * n)
        bw_bins = max(2, int(bw_hz / hz_per_bin))

        # Signal bins
        lo = max(0, center_bin - bw_bins//2)
        hi = min(n-1, center_bin + bw_bins//2)

        # Check in range
        lo_freq = cf - sr/2
        hi_freq = cf + sr/2
        if freq_hz < lo_freq or freq_hz > hi_freq:
            return jsonify({"ok": False, "error": f"Frequency {freq_hz/1e6:.3f}MHz out of SDR range {lo_freq/1e6:.3f}-{hi_freq/1e6:.3f}MHz"})
        if lo >= hi:
            return jsonify({"ok": False, "error": "Frequency bin range error"})

        signal_mags = mags[lo:hi]
        signal_peak = float(np.max(signal_mags))
        signal_mean = float(np.mean(signal_mags))

        # Noise floor — use 10% of bins furthest from signal
        noise_bins = int(n * 0.1)
        noise_lo = mags[:noise_bins]
        noise_hi = mags[n-noise_bins:]
        noise_floor = float(np.mean(np.concatenate([noise_lo, noise_hi])))

        snr = signal_peak - noise_floor
        detected = snr > 6.0  # 6dB above noise floor

        return jsonify({
            "ok": True,
            "freq_hz": freq_hz,
            "signal_peak_db": round(signal_peak, 1),
            "signal_mean_db": round(signal_mean, 1),
            "noise_floor_db": round(noise_floor, 1),
            "snr_db": round(snr, 1),
            "detected": detected,
            "center_freq": cf,
            "sample_rate": sr,
        })
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500


@app.route("/api/contests")
def api_contests():
    """Return upcoming contests from WA7BNM RSS feed."""
    import re, xml.etree.ElementTree as ET, urllib.request
    from datetime import datetime, timezone, timedelta

    mode = request.args.get("mode","").upper()
    days = int(request.args.get("days","7"))
    now = datetime.now(timezone.utc)

    try:
        req = urllib.request.Request(
            "https://www.contestcalendar.com/calendar.rss",
            headers={"User-Agent": "ELMER-WI/1.0"}
        )
        with urllib.request.urlopen(req, timeout=10) as r:
            xml_data = r.read()
        root = ET.fromstring(xml_data)
        items = root.findall(".//item")
        contests = []
        MONTHS = {"Jan":1,"Feb":2,"Mar":3,"Apr":4,"May":5,"Jun":6,
                  "Jul":7,"Aug":8,"Sep":9,"Oct":10,"Nov":11,"Dec":12}
        year = now.year
        for item in items:
            title = item.findtext("title") or ""
            desc  = item.findtext("description") or ""
            link  = item.findtext("link") or ""
            # Parse description like "0000Z-0500Z, May 14"
            m = re.search(r"(\d{4})Z.*?(\w{3})\s+(\d{1,2})", desc)
            if not m: continue
            hour = int(m.group(1)[:2])
            mon  = MONTHS.get(m.group(2), 0)
            day  = int(m.group(3))
            if not mon: continue
            # Handle year rollover
            ev_year = year if mon >= now.month else year + 1
            try:
                dt = datetime(ev_year, mon, day, hour, 0, tzinfo=timezone.utc)
            except:
                continue
            days_away = (dt - now).days
            if -1 <= days_away <= days:
                # Filter by mode if specified
                title_up = title.upper()
                if mode:
                    if "CW" in mode and not any(x in title_up for x in ["CW","CWT","MORSE"]): continue
                    if mode in ("FT8","FT4","RTTY","PKTUSB","PKTLSB") and not any(x in title_up for x in ["FT8","FT4","RTTY","DIGI","DIGITAL"]): pass
                contests.append({
                    "name": title,
                    "description": desc,
                    "start_dt": dt.strftime("%b %d %H%Mz"),
                    "days_away": days_away,
                    "link": link,
                })
        contests.sort(key=lambda x: x["days_away"])
        return jsonify({"ok": True, "contests": contests[:15], "count": len(contests)})
    except Exception as e:
        logger.warning("[CONTESTS] RSS failed: %s", e)
        return jsonify({"ok": False, "error": str(e)})

@app.route("/api/send_wi_report", methods=["POST"])
def api_send_wi_report():
    """Email WI report to contact. Images are already inline as base64 data:
    URIs from the client, so they survive as-is. Audio can't be embedded
    inline in email — the <audio src='/api/audio/recordings/...'> tag is a
    relative server URL the recipient's email client can never reach, so we
    strip it from the HTML and attach the actual recording file instead."""
    import subprocess
    from email.message import EmailMessage
    data = request.get_json(force=True) or {}
    to_email  = (data.get("email") or "").strip()
    call      = (data.get("call")  or "").strip()
    report    = (data.get("report") or "").strip()
    user_call = (data.get("user") or "").strip()
    recording = (data.get("recording") or "").strip()
    if not to_email or "@" not in to_email:
        return jsonify({"ok": False, "error": "No valid email address"})
    subject = f"RigPi Workability Index Report for {call}"
    cc_email = ""
    try:
        conn = pymysql.connect(host="localhost", user="ham", password="7388",
                               database="station", connect_timeout=3,
                               cursorclass=pymysql.cursors.DictCursor)
        with conn:
            with conn.cursor() as cur:
                cur.execute("SELECT My_Email FROM Users WHERE MyCall=%s OR Username=%s LIMIT 1", (user_call.upper(), user_call.lower()))
                row = cur.fetchone()
                if row and row.get("My_Email"):
                    cc_email = row["My_Email"].strip()
    except Exception:
        pass

    # Strip the <audio ...>...</audio> player — its src is a relative server
    # URL the recipient's mail client can never load. Replace with a short
    # note if we're attaching the actual file below.
    import re as _re_wi
    report_clean = _re_wi.sub(r"<audio\b[^>]*>.*?</audio>", "", report, flags=_re_wi.DOTALL | _re_wi.IGNORECASE)
    report_clean = _re_wi.sub(r"<audio\b[^>]*/?>", "", report_clean, flags=_re_wi.IGNORECASE)

    safe_rec_name = _safe_recording_name(recording) if recording else None
    rec_path = os.path.join(RECORDINGS_DIR, safe_rec_name) if safe_rec_name else None
    has_attachment = bool(rec_path and os.path.isfile(rec_path))
    if has_attachment:
        report_clean += (
            "<div style='margin-top:8px;font-size:11px;color:#888;font-family:Arial,sans-serif;'>"
            "&#x1F3A7; Audio recording attached: " + safe_rec_name + "</div>"
        )

    try:
        msg = EmailMessage()
        msg["To"] = to_email
        msg["From"] = "RigPi SDR <hlnurse@gmail.com>"
        msg["Subject"] = subject
        if cc_email:
            msg["Cc"] = cc_email
        msg.set_content("This report requires an HTML-capable email client to view.")
        msg.add_alternative(report_clean, subtype="html")

        if has_attachment:
            with open(rec_path, "rb") as fh:
                audio_bytes = fh.read()
            ext = os.path.splitext(safe_rec_name)[1].lstrip(".").lower() or "mp3"
            maintype, subtype = ("audio", ext if ext in ("mp3", "wav", "webm", "ogg") else "mpeg")
            # add_attachment on the alternative part attaches to the whole
            # message correctly via EmailMessage's MIME tree handling
            msg.add_attachment(audio_bytes, maintype=maintype, subtype=subtype,
                                filename=safe_rec_name)

        proc = subprocess.run(
            ["/usr/bin/msmtp", "-t"],
            input=msg.as_bytes(),
            capture_output=True, timeout=30,
        )
        if proc.returncode == 0:
            return jsonify({"ok": True, "attached": has_attachment})
        else:
            return jsonify({"ok": False, "error": proc.stderr.decode(errors="replace").strip()})
    except Exception as e:
        logger.error("[WI] send_wi_report failed: %s", e)
        return jsonify({"ok": False, "error": str(e)})



@app.route("/api/wi_map")
def api_wi_map():
    """Return a static map image showing path from QTH to DX station."""
    try:
        from staticmap import StaticMap, Line, CircleMarker
        import io
        dlat = float(request.args.get("dlat", 0))
        dlon = float(request.args.get("dlon", 0))
        if not dlat and not dlon:
            return jsonify({"ok": False, "error": "No coordinates"})
        # Get QTH from DB
        qlat, qlon = 40.830699, -81.895377
        try:
            conn = pymysql.connect(host="localhost", user="ham", password="7388",
                                   database="station", connect_timeout=3,
                                   cursorclass=pymysql.cursors.DictCursor)
            with conn:
                with conn.cursor() as cur:
                    cur.execute("SELECT My_Latitude, My_Longitude FROM Users WHERE MyCall='W6HN' LIMIT 1")
                    row = cur.fetchone()
                    if row:
                        qlat = float(row["My_Latitude"])
                        qlon = float(row["My_Longitude"])
        except Exception:
            pass
        m = StaticMap(600, 260, url_template="https://tile.openstreetmap.org/{z}/{x}/{y}.png")
        # Great circle path with waypoints
        import math
        def gc_points(lat1, lon1, lat2, lon2, n=50):
            """Generate n points along great circle between two lat/lon pairs."""
            lat1, lon1, lat2, lon2 = map(math.radians, [lat1, lon1, lat2, lon2])
            d = 2 * math.asin(math.sqrt(math.sin((lat2-lat1)/2)**2 +
                math.cos(lat1)*math.cos(lat2)*math.sin((lon2-lon1)/2)**2))
            pts = []
            for i in range(n+1):
                f = i / n
                A = math.sin((1-f)*d) / math.sin(d)
                B = math.sin(f*d) / math.sin(d)
                x = A*math.cos(lat1)*math.cos(lon1) + B*math.cos(lat2)*math.cos(lon2)
                y = A*math.cos(lat1)*math.sin(lon1) + B*math.cos(lat2)*math.sin(lon2)
                z = A*math.sin(lat1) + B*math.sin(lat2)
                lat = math.degrees(math.atan2(z, math.sqrt(x**2+y**2)))
                lon = math.degrees(math.atan2(y, x))
                pts.append((lon, lat))
            return pts
        waypoints = gc_points(qlat, qlon, dlat, dlon)
        m.add_line(Line(waypoints, "red", 2))
        # Add invisible markers at extremes to force bounding box to include arc
        lats = [p[1] for p in waypoints]
        lons = [p[0] for p in waypoints]
        apex_idx = lats.index(max(lats))
        nadir_idx = lats.index(min(lats))
        m.add_marker(CircleMarker(waypoints[apex_idx], "red", 1))
        m.add_marker(CircleMarker(waypoints[nadir_idx], "red", 1))
        # QTH marker (blue)
        m.add_marker(CircleMarker((qlon, qlat), "blue", 10))
        # DX marker (red)
        m.add_marker(CircleMarker((dlon, dlat), "red", 10))
        img = m.render()
        buf = io.BytesIO()
        img.save(buf, format="PNG")
        buf.seek(0)
        from flask import Response
        return Response(buf.read(), mimetype="image/png",
                       headers={"Cache-Control": "no-cache"})
    except Exception as e:
        logger.warning("[WI_MAP] failed: %s", e)
        return jsonify({"ok": False, "error": str(e)})

def _sdrconn_auto_connect():
    """Auto-connect SDRConnect WebSocket at startup to enumerate devices, then disconnect.
    Skipped entirely if this instance's configured device is not SDRConnect/SDRplay —
    no reason to spend ~14s probing SDRConnect on an AirSpy/RTL-SDR-only instance."""
    import time
    if DEVICE_DRIVER not in ("sdrconnect", "sdrplay", ""):
        logger.info("[SDRCONN] skipping startup auto-connect — instance device_driver=%s doesn't need it",
                    DEVICE_DRIVER)
        return
    time.sleep(8)  # wait for server to be fully up
    try:
        logger.info("[SDRCONN] auto-connecting at startup to enumerate devices...")
        sdrconn_client.connect(engine, device_name=None,
                               freq_hz=14074000, mode="USB", sample_rate=2_000_000)
        time.sleep(6)  # wait for device list
        sdrconn_client.disconnect()
        logger.info("[SDRCONN] auto-connect complete, disconnected")
    except Exception as e:
        logger.warning("[SDRCONN] auto-connect failed: %s", e)

threading.Thread(target=_sdrconn_auto_connect, daemon=True).start()

@app.route("/api/ft8/spots", methods=["GET"])
def api_ft8_spots():
    """Read FT8 spots from MySQL Spots table (inserted by ft8modem_manager process)."""
    try:
        import pymysql, datetime as _dt
        conn = pymysql.connect(host="localhost", user="ham", password="7388",
                               database="station", connect_timeout=3,
                               cursorclass=pymysql.cursors.DictCursor)
        with conn:
            with conn.cursor() as cur:
                cur.execute("""SELECT DX as dx_call, Spotter as de_call, Frequency as freq_hz,
                               Band as band, Mode as mode, Note as msg, Webtime as webtime,
                               Webdate as webdate, DXGrid as dxgrid
                               FROM Spots WHERE Note LIKE %s AND (Hide IS NULL OR Hide!='1')
                               AND Radio=%s
                               ORDER BY id DESC LIMIT 100""", ('%FT8%', int(_FT8_RADIO_ID)))
                rows = cur.fetchall()
        import re as _re
        FT8_DIAL = {
            '160m':1840000,'80m':3573000,'60m':5357000,'40m':7074000,
            '30m':10136000,'20m':14074000,'17m':18100000,'15m':21074000,
            '12m':24915000,'10m':28074000,'6m':50313000,'2m':144174000
        }
        spots = []
        for s in rows:
            msg = s.get('msg') or ''
            # Parse SNR from Note: "FT8 +7 dB", "FT8 -14", "FT8 SNR-19"
            snr = None
            m = _re.search(r'([+-]\d+)\s*dB', msg, _re.I)
            if m: snr = int(m.group(1))
            else:
                m = _re.search(r'SNR([+-]?\d+)', msg, _re.I)
                if m: snr = int(m.group(1))
            if snr is None:
                # "FT8 -14" or "<ES> FT8 -14" — number after FT8
                m = _re.search(r'FT8\s+([+-]?\d+)(?!\s*dB)', msg, _re.I)
                if m: snr = int(m.group(1))
            # Parse grid: prefer DXGrid column, fall back to Note
            grid = (s.get('dxgrid') or '')[:4].upper()
            if not grid:
                m = _re.search(r'([A-R]{2}\d{2}[a-x]{0,2})', msg, _re.I)
                if m: grid = m.group(1)[:4].upper()
            s['snr']  = snr  # None if not parseable — UI shows blank
            # If no grid, try prefix lookup for lat/lon then derive grid
            if not grid and s.get('dx_call'):
                try:
                    call = s['dx_call'].strip().upper().split('/')[0]  # strip /P /R /M etc
                    test = call
                    conn2 = pymysql.connect(host="localhost", user="ham", password="7388",
                                           database="station", connect_timeout=3,
                                           cursorclass=pymysql.cursors.DictCursor)
                    with conn2:
                        with conn2.cursor() as cur2:
                            for plen in range(len(call), 0, -1):
                                pfx = call[:plen]
                                cur2.execute("""SELECT pr.Latitude, pr.Longitude
                                    FROM PLink p JOIN Prefixes pr ON p.plink=pr.ID
                                    WHERE p.Prefix=%s AND pr.Latitude IS NOT NULL
                                    LIMIT 1""", (pfx,))
                                row2 = cur2.fetchone()
                                if row2 and row2.get('Latitude'):
                                    lat, lon = float(row2['Latitude']), float(row2['Longitude'])
                                    lon_adj = lon + 180
                                    lat_adj = lat + 90
                                    grid = (chr(65 + int(lon_adj/20)) +
                                            chr(65 + int(lat_adj/10)) +
                                            str(int((lon_adj % 20)/2)) +
                                            str(int(lat_adj % 10)))
                                    break
                except Exception:
                    pass
            s['grid'] = grid
            _band = (s.get('band') or '').strip().lower()
            if not _band.endswith('m'): _band += 'm'
            dial = FT8_DIAL.get(_band, 0)
            try:
                _fhz = int(float(s.get('freq_hz') or 0))
            except (TypeError, ValueError):
                _fhz = 0
            s['audio_hz'] = (_fhz - dial) if dial else None
            spots.append(s)
        running = len(spots) > 0
        return jsonify({"spots": spots, "status": {"running": running}, "total_decodes": len(spots)})
    except Exception as e:
        import traceback
        logger.warning("[FT8] api_ft8_spots DB error: %s\n%s", e, traceback.format_exc())
        return jsonify({"spots": [], "status": {"running": False}, "total_decodes": 0})

if __name__ == "__main__":
    engine = SdrEngine()
    # Probe SDRConnect devices before streaming starts
    threading.Thread(target=_probe_sdrconn_devices, daemon=True).start()
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
                    if engine.device_driver != "rx888":
                        engine.sample_rate = float(saved_sample_rate)
                        engine.span = float(saved_sample_rate)
                        logger.info(
                            "[STARTUP] restored sample_rate=%.0f",
                            engine.sample_rate,
                        )
                    else:
                        logger.info(
                            "[STARTUP] ignoring saved sample_rate for RX888 "
                            "(input=%.0f display_span=%.0f)",
                            engine.input_sample_rate,
                            engine.span,
                        )
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
    if engine.device_driver == "rx888":
        engine.real_adc_stream = True
        engine.input_sample_rate = 64_000_000.0
        engine.sample_rate = 32_000_000.0
        engine.span = 32_000_000.0
        engine.center_freq = 16_000_000.0

        logger.info(
            "[RX888] enforced startup display "
            "center=%.0f span=%.0f",
            engine.center_freq,
            engine.span,
        )
    threading.Thread(target=_heartbeat, daemon=True).start()
    get_rigsync(1)  # pre-create radio 1

    from gevent.pywsgi import WSGIServer
    from geventwebsocket.handler import WebSocketHandler

    server = WSGIServer(("0.0.0.0", SERVER_PORT), application,
                        handler_class=WebSocketHandler)
    _register_instance()  # register immediately so instances.json is current before first request
    print(f"[SERVER] :{SERVER_PORT} device={engine.device_driver}")
    server.serve_forever()