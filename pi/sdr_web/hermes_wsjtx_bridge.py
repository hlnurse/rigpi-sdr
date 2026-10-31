"""Local WSJT-X loopback audio and CAT PTT bridge for one Hermes instance."""
import os
import queue
import subprocess
import threading
import time
import numpy as np
from scipy.signal import lfilter

class DigitalIQ:
    """Streaming analytic audio; preserve FT8 amplitude/phase across blocks."""
    def __init__(self, amplitude=0.2, maximum_amplitude=0.5):
        n = np.arange(1023) - 511
        h = np.zeros(1023, dtype=np.float64)
        odd = (n % 2) != 0
        h[odd] = 2.0 / (np.pi * n[odd])
        self.h = h * np.hamming(1023)
        self.delay = np.zeros(511)
        self.zi = np.zeros(1022)
        self.amplitude = max(0.01, min(max(0.01, min(0.95, float(maximum_amplitude))), float(amplitude)))

    def convert(self, samples):
        x = np.clip(np.nan_to_num(np.asarray(samples, dtype=np.float64).reshape(-1)), -1, 1)
        delayed = np.concatenate((self.delay, x))
        real = delayed[:x.size]; self.delay = delayed[x.size:]
        imag, self.zi = lfilter(self.h, [1.0], x, zi=self.zi)
        # HL2 wire-IQ convention: conjugate analytic audio for USB.
        return ((real - 1j * imag) * self.amplitude).astype(np.complex64)

class WsjtxBridge:
    def __init__(self, env, cat=None, clock=time.monotonic):
        self.env = env; self.clock = clock
        self.cat = cat or env['RigCtl']('127.0.0.1', int(env['RIGCTL_PORT']), timeout=0.3)
        self.lock = threading.RLock(); self.rx = queue.Queue(maxsize=10)
        self.ptt = False; self.control_at = 0.0; self.frequency = 0; self.mode = ''
        self.seen_release = False; self.blocked = False; self.owned = False
        self.last_audio = 0.0; self.capture_running = False; self.rx_running = False
        self.frames = 0; self.rx_samples = 0; self.rx_drops = 0
        self.rx_silence_blocks = 0; self.rx_samples_received = 0
        self.error = ''; self.audio_error = ''; self.iq = DigitalIQ()
        self.running = True
        self.audio_selection_checked = 0.0; self.audio_selected_port = 0

    def permitted(self):
        e = self.env; eng = e['engine']
        return bool(e['_inst_cfg'].get('hermes_ft8_enabled', False) and e['HERMES_TX_ENABLED']
                    and eng.device_driver == 'hermeslite' and eng.mode in ('USB','PKTUSB','USB-D')
                    and self.mode in ('USB','PKTUSB','USB-D')
                    and any(low <= self.frequency <= high for low, high in e['_HACKRF_TX_RANGES_HZ'][:11]))

    def stop_owned(self, reason='', block=False):
        if self.owned:
            self.env['hermes_tx_controller'].stop()
        self.owned = False
        if block: self.blocked = True
        if reason: self.error = reason

    def update_control(self, ptt, frequency, mode):
        with self.lock:
            self.ptt = bool(ptt); self.frequency = int(frequency); self.mode = str(mode).upper()
            self.control_at = self.clock()
            if not self.ptt:
                self.stop_owned(); self.seen_release = True; self.blocked = False; self.error = ''
            elif not self.permitted():
                self.stop_owned('WSJT-X TX requires enabled Hermes USB/data mode in an amateur band.', True)
            elif self.owned:
                native = self.env['hermes_tx_controller'].status()
                if not native.get('active'):
                    self.stop_owned('Hermes TX stopped. Release CAT PTT before transmitting again.', True)
                elif self.clock() - self.last_audio > 0.75:
                    self.stop_owned('WSJT-X transmit audio was lost.', True)

    def process_audio(self, samples):
        audio = np.asarray(samples, dtype=np.float32).reshape(-1) / 32768.0
        if not audio.size: return
        peak = float(np.max(np.abs(audio)))
        with self.lock:
            now = self.clock()
            if not self.ptt or not self.seen_release or self.blocked or not self.permitted(): return
            if now - self.control_at > 0.5:
                self.stop_owned('WSJT-X CAT heartbeat was lost.', True); return
            controller = self.env['hermes_tx_controller']
            if not self.owned:
                if peak < 0.0001: return
                if controller.status().get('active') or controller.starting: return
                drive = int(self.env['_inst_cfg'].get('hermes_ft8_drive', 32))
                limit = float(self.env['_inst_cfg'].get('hermes_ft8_maximum_seconds', 30))
                self.iq = DigitalIQ(self.env['_inst_cfg'].get('hermes_ft8_amplitude', self.env['HERMES_TX_AMPLITUDE']), maximum_amplitude=0.95)
                try:
                    controller.start(self.frequency, 'PKTUSB', limit, drive=drive)
                except Exception as error:
                    self.error = str(error); self.blocked = True; return
                self.owned = True; self.frames = 0; self.error = ''
            elif not controller.status().get('active'):
                self.stop_owned('Hermes TX stopped. Release CAT PTT before transmitting again.', True); return
            iq = self.iq.convert(audio)
            controller._source().submit_tx_iq(iq)
            with controller.lock: controller.frames += 1
            self.frames += 1
            if peak >= 0.0001: self.last_audio = now
            self.env['_tx_vu'] = min(1.0, peak); self.env['_tx_vu_updated_at'] = time.monotonic()

    def selected_for_audio(self):
        now = self.clock()
        if now - self.audio_selection_checked >= 0.5:
            self.audio_selection_checked = now
            try:
                with open("/run/user/1000/rigpi-digital-audio-port", encoding="ascii") as selection:
                    self.audio_selected_port = int(selection.read().strip())
            except (OSError, ValueError):
                self.audio_selected_port = 0
        return self.audio_selected_port == int(self.env["_inst_cfg"].get("port") or 0)

    def receive_audio(self, audio):
        if not self.selected_for_audio():
            return
        self.rx_samples_received += len(audio)
        block = np.clip(np.asarray(audio, dtype=np.float32).reshape(-1) * 0.10 * 32767, -32768, 32767).astype('<i2').tobytes()
        try: self.rx.put_nowait(block)
        except queue.Full:
            try: self.rx.get_nowait()
            except queue.Empty: pass
            self.rx_drops += 1
            try: self.rx.put_nowait(block)
            except queue.Full: pass

    def control_worker(self):
        while self.running:
            try:
                value = self.cat._cmd(b't'+bytes([10]))
                if value not in ('0','1','2','3'): raise RuntimeError('Invalid CAT PTT response')
                frequency = self.cat.get_freq(); mode, _ = self.cat.get_mode_bw()
                if not frequency or not mode: raise RuntimeError('CAT frequency/mode unavailable')
                self.update_control(value != '0', frequency, mode)
            except Exception as error:
                with self.lock:
                    self.control_at = 0.0; self.seen_release = False
                    self.stop_owned(str(error), True)
            time.sleep(0.1)

    def capture_worker(self):
        while self.running:
            p = None
            try:
                p = subprocess.Popen(['arecord','-q','-D','plughw:Loopback,0,7','-t','raw','-f','S16_LE','-r','48000','-c','1','--buffer-time=100000','--period-time=20000'],stdout=subprocess.PIPE,stderr=subprocess.DEVNULL)
                self.capture_running = True
                while self.running:
                    raw = p.stdout.read(1920)
                    if len(raw) != 1920: raise RuntimeError('Hermes FT8 audio capture stopped')
                    self.audio_error = ''
                    self.process_audio(np.frombuffer(raw,dtype='<i2'))
            except Exception as error:
                self.audio_error = str(error)
                with self.lock: self.stop_owned(self.audio_error, True)
            finally:
                self.capture_running = False
                if p is not None:
                    p.terminate()
                    try: p.wait(timeout=1)
                    except subprocess.TimeoutExpired: p.kill();p.wait()
            time.sleep(1)

    def receive_worker(self):
        while self.running:
            p = None
            try:
                p = subprocess.Popen(['pacat','--playback','--raw','--device=hermes_ft8_rx','--format=s16le','--rate=48000','--channels=1','--latency-msec=250','--volume=65536'],env={**os.environ,"XDG_RUNTIME_DIR":"/run/user/1000","PULSE_SERVER":"unix:/run/user/1000/pulse/native"},stdin=subprocess.PIPE,stderr=subprocess.DEVNULL)
                self.rx_running = True
                while self.running:
                    # Wait through producer jitter; a short queue wait must not
                    # manufacture samples and shift all following FT8 symbols.
                    try: block = self.rx.get(timeout=0.25)
                    except queue.Empty:
                        block = bytes(1920)
                        self.rx_silence_blocks += 1
                    if self.owned: block = bytes(len(block))
                    p.stdin.write(block);p.stdin.flush();self.rx_samples += len(block)//2
            except Exception as error: self.audio_error = str(error)
            finally:
                self.rx_running = False
                if p is not None:
                    p.terminate()
                    try: p.wait(timeout=1)
                    except subprocess.TimeoutExpired: p.kill();p.wait()
            time.sleep(1)

    def status(self):
        with self.lock:
            return {'enabled': True, 'cat_port': int(self.env['RIGCTL_PORT']),
                    'cat_connected': self.control_at > 0 and self.clock()-self.control_at < 0.5,
                    'cat_ptt': self.ptt, 'active': self.owned, 'eligible': self.permitted(), 'blocked': self.blocked,
                    'capture_running': self.capture_running, 'receive_running': self.rx_running,
                    'drive': int(self.env['_inst_cfg'].get('hermes_ft8_drive',32)),
                    'iq_amplitude': max(0.01, min(0.95, float(self.env['_inst_cfg'].get('hermes_ft8_amplitude',self.env['HERMES_TX_AMPLITUDE'])))),
                    'frames': self.frames,'rx_samples': self.rx_samples,'rx_drops': self.rx_drops,
                    'rx_silence_blocks': self.rx_silence_blocks, 'rx_samples_received': self.rx_samples_received, 'rx_queue_blocks': self.rx.qsize(),
                    'error': self.error or self.audio_error, 'audio_device': 'hermes_ft8'}

    def start(self):
        for name, target in [('cat',self.control_worker),('capture',self.capture_worker),('receive',self.receive_worker)]:
            threading.Thread(target=target,name='hermes-ft8-'+name,daemon=True).start()

def install(env):
    env['_hermes_ft8_status'] = lambda: {'enabled': False}
    bridge = WsjtxBridge(env)
    env['_hermes_ft8_bridge'] = bridge
    env['_hermes_ft8_rx_sink'] = bridge.receive_audio
    env['_hermes_ft8_status'] = bridge.status
    bridge.start()
