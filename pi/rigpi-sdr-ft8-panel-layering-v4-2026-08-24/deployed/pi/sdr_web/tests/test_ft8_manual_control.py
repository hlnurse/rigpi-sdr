#!/usr/bin/env python3
"""Regression checks for arbitrary-dial FT8/FT4 decoder control."""

import ast
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
server_path=ROOT/'sdr_web_server_minimal.py'
server_text=server_path.read_text()
tree=ast.parse(server_text)
wanted={'_ft8_band_for_frequency','_notify_ft8_frequency'}
functions=[node for node in tree.body if isinstance(node,ast.FunctionDef) and node.name in wanted]
assert {node.name for node in functions}==wanted

class Logger:
    def info(self,*_args): pass
    def warning(self,*_args): pass

class Manager:
    def __init__(self):
        self.is_running=False; self.started=[]; self.stopped=0
        self.status={'running':False,'mode':None,'band':None,'dial_hz':None}
    def start(self,mode,band,dial):
        self.is_running=True; self.started.append((mode,band,dial))
        self.status={'running':True,'mode':mode,'band':band,'dial_hz':dial}
    def stop(self): self.is_running=False; self.stopped+=1
    def on_freq_change(self,_freq): pass
    def get_ft8_mode(self,_freq): return None

manager=Manager()
namespace={'logger':Logger(),'_ft8_mgr':manager,'_get_ft8_mgr':lambda:manager,
           '_ft8_selection':'ft8','_ft8_current_hz':14_074_000}
exec(compile(ast.Module(body=functions,type_ignores=[]),str(server_path),'exec'),namespace)
result=namespace['_notify_ft8_frequency'](14_095_000,'test')
assert result==('FT8','20m',14_095_000),result
assert manager.started[-1]==('FT8','20m',14_095_000),manager.started
namespace['_ft8_selection']='off'; namespace['_notify_ft8_frequency'](14_095_000,'test')
assert manager.stopped==1

assert '14.071e6:"20m"' in server_text
assert "request.headers.get('Origin'" in server_text
assert "request.headers.get('X-Requested-With') != 'RigPi-SDR'" in server_text
html=(ROOT/'index.html').read_text()
for value in ('auto','ft8','ft4','off'):
    assert f'<option value="{value}">' in html
assert 'X-Requested-With":"RigPi-SDR' in html
assert 'Number(s.dial_hz) || ft8DialHz(s.band)' in html
print('FT8 manual-control regression test passed')
