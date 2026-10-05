#!/usr/bin/env python3
import io
import json
import sys
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
from answer_knowledge_local import generate_local_answer

packet={'question':'How?','required_anchors':[],'evidence':[{
  'number':1,'title':'Help','source_label':'Official RigPi Help','source_type':'help',
  'authority':100,'path':'help.html','author':'','published_at':'','excerpt':'Use this setting.',
  'external_reference':'','local_reference':'elmer://source/official-help/document/HELP-1'}]}

class Response:
    def __init__(self,payload): self.data=io.BytesIO(json.dumps(payload).encode())
    def __enter__(self): return self
    def __exit__(self,*args): return False
    def read(self,*args): return self.data.read(*args)
    def __iter__(self): return iter(self.data)

seen={}
def opener(request,timeout):
    seen['payload']=json.loads(request.data)
    return Response({'message':{'role':'assistant','content':'Local answer [1].'},'prompt_eval_count':100,'eval_count':20,'prompt_eval_duration':2_000_000_000,'eval_duration':4_000_000_000,'total_duration':7_000_000_000})

answer,usage=generate_local_answer(packet,opener=opener)
assert answer=='Local answer [1].'
assert seen['payload']['model']=='qwen3:8b-q4_K_M'
assert seen['payload']['think'] is False and seen['payload']['stream'] is False
assert seen['payload']['options']['num_ctx']==8192
assert seen['payload']['options']['temperature']==0.1
assert seen['payload']['messages'][0]['role']=='system'
assert usage['total_tokens']==120 and usage['tokens_per_second']==5.0

print('Local Ollama answer-generator test passed')
