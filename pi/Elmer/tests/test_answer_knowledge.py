#!/usr/bin/env python3
import io
import json
import sys
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
from answer_knowledge import extract_output_text,generate_openai_answer,packet_input,stream_openai_answer

packet={
  'question':'How do I configure an IC-7300?',
  'required_anchors':['IC-7300'],
  'current_guidance':[{'id':'browser-audio','statement':'Use current browser audio.','supersedes':['Mumble']}],
  'evidence':[{
    'number':1,'title':'IC-7300 Setup','source_label':'Official RigPi Help',
    'source_type':'help','authority':100,'path':'setup.html','author':'',
    'published_at':'','excerpt':'Use the documented radio settings.',
    'external_reference':'https://example.test/setup','local_reference':'elmer://source/help/document/HELP-1',
  }],
}

class Response:
    def __enter__(self): return self
    def __exit__(self,*args): return False
    def read(self,*args): return self.data.read(*args)
    def __iter__(self): return iter(self.data)
    def __init__(self,payload): self.data=io.BytesIO(json.dumps(payload).encode())

seen={}
def opener(request,timeout):
    seen['url']=request.full_url
    seen['authorization']=request.headers.get('Authorization')
    seen['payload']=json.loads(request.data)
    return Response({'output':[{'type':'message','content':[{'type':'output_text','text':'Grounded answer [1].'}]}],'usage':{'input_tokens':123,'output_tokens':8}})

answer,usage=generate_openai_answer(packet,'test-secret',opener=opener)
assert answer=='Grounded answer [1].'
assert usage['input_tokens']==123
assert seen['authorization']=='Bearer test-secret'
assert 'test-secret' not in json.dumps(seen['payload'])
assert seen['payload']['store'] is False
assert seen['payload']['model']=='gpt-5.6-terra'
assert seen['payload']['reasoning']=={'effort':'low'}
assert 'untrusted reference material' in seen['payload']['instructions']
model_input=json.loads(packet_input(packet))
assert model_input['evidence'][0]['number']==1
assert model_input['current_guidance'][0]['supersedes']==['Mumble']
assert extract_output_text({'output_text':'shortcut'})=='shortcut'

stream_seen={}
def stream_opener(request,timeout):
    stream_seen['payload']=json.loads(request.data)
    stream_seen['accept']=request.headers.get('Accept')
    lines=(
        b'event: response.created\n\ndata: {"type":"response.output_text.delta","delta":"Grounded "}\n\n'
        b'data: {"type":"response.output_text.delta","delta":"answer [1]."}\n\n'
        b'data: {"type":"response.completed","response":{"usage":{"input_tokens":123,"output_tokens":8}}}\n\n'
    )
    response=Response({})
    response.data=io.BytesIO(lines)
    return response

stream_output=io.StringIO()
answer,usage,timing=stream_openai_answer(packet,'test-secret',opener=stream_opener,output=stream_output)
assert answer=='Grounded answer [1].'
assert stream_output.getvalue()==answer
assert usage=={'input_tokens':123,'output_tokens':8}
assert timing['first_text_seconds']>=0
assert timing['total_seconds']>=timing['first_text_seconds']
assert stream_seen['payload']['stream'] is True
assert stream_seen['accept']=='text/event-stream'

print('OpenAI answer-generator test passed')
