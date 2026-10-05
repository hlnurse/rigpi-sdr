#!/usr/bin/env python3
from __future__ import annotations
import ctypes, time, subprocess
from pathlib import Path
import numpy as np

class RX888AudioProcessor:

    MODES={"USB":0,"PKTUSB":0,"LSB":1,"PKTLSB":1,"CW":0,"CWR":5,"AM":3}
    def __init__(self,input_rate=64_000_000.0,library_path=None):
        path=Path(library_path or Path(__file__).with_name("librx888_audio_dsp.so"))
        source=Path(__file__).with_name("rx888_audio_dsp.c")
        if (not path.exists()) or (source.exists() and source.stat().st_mtime > path.stat().st_mtime):
            subprocess.run(["cc","-O3","-ffast-math","-fPIC","-shared",str(source),"-o",str(path),"-lm"], check=True)
        self.lib=ctypes.CDLL(str(path))
        self.lib.rx888_audio_create.argtypes=[ctypes.c_double]; self.lib.rx888_audio_create.restype=ctypes.c_void_p
        self.lib.rx888_audio_destroy.argtypes=[ctypes.c_void_p]
        self.lib.rx888_audio_reset.argtypes=[ctypes.c_void_p]
        self.lib.rx888_audio_process.argtypes=[ctypes.c_void_p,ctypes.POINTER(ctypes.c_float),ctypes.c_int,ctypes.c_double,ctypes.c_int,ctypes.POINTER(ctypes.c_float),ctypes.c_int]
        self.lib.rx888_audio_process.restype=ctypes.c_int
        self.state=self.lib.rx888_audio_create(float(input_rate)); self.process_ms=0.0
        if not self.state: raise RuntimeError("RX888 audio DSP allocation failed")
    def close(self):
        if self.state: self.lib.rx888_audio_destroy(self.state); self.state=None
    def reset(self): self.lib.rx888_audio_reset(self.state)
    def process(self,samples,center_hz,mode="USB"):
        a = np.asarray(samples)

        src=np.ascontiguousarray(samples,dtype=np.float32)
        src -= np.mean(src, dtype=np.float64)
        cap=max(2048,int(len(src)*48000/64_000_000)+64); out=np.empty(cap,dtype=np.float32)
        t=time.perf_counter(); n=self.lib.rx888_audio_process(self.state,src.ctypes.data_as(ctypes.POINTER(ctypes.c_float)),len(src),float(center_hz),self.MODES.get(str(mode).upper(),0),out.ctypes.data_as(ctypes.POINTER(ctypes.c_float)),cap); self.process_ms=(time.perf_counter()-t)*1000
        return out[:n].copy()