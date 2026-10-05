#!/usr/bin/env python3
import json
import sys
import tempfile
import threading
import urllib.request
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))

from elmer_web import ElmerHandler,ElmerServer


received=[]


class CloudHandler(BaseHTTPRequestHandler):
    def log_message(self,*_args): pass

    def do_POST(self):
        assert self.headers['Authorization']=='Bearer elp_'+'x'*64
        body=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
        received.append((self.path,body))
        if self.path.endswith('/plans'):
            data=json.dumps({
                'language_code':'sv','language_name':'Swedish','response_locale':'sv-SE',
                'english_search_query':'Test question',
                'callbook':{'enabled':False,'call':'','provider':'auto','include':[]},
                'fcc_search':{'enabled':False,'call':'','postal_code':'','center':'user','radius_miles':-1,'limit':25},
            }).encode()
            content_type='application/json; charset=utf-8'
        elif self.path.endswith('/answers'):
            data=(
                'event: delta\ndata: {"text":"Cloud answer"}\n\n'
                'event: done\ndata: {"request_id":"request-123","usage":{"total_tokens":12},'
                '"timing":{"first_text_seconds":0.1,"total_seconds":0.2},'
                '"records_searched":25000,"evidence_used":8}\n\n').encode()
            content_type='text/event-stream; charset=utf-8'
        else:
            data=b'{"status":"saved","id":"feedback-123"}'
            content_type='application/json; charset=utf-8'
        self.send_response(200)
        self.send_header('Content-Type',content_type)
        self.send_header('Content-Length',str(len(data)))
        self.end_headers()
        self.wfile.write(data)


with tempfile.TemporaryDirectory() as td:
    cloud=ThreadingHTTPServer(('127.0.0.1',0),CloudHandler)
    cloud_thread=threading.Thread(target=cloud.serve_forever,daemon=True); cloud_thread.start()
    local=ElmerServer(('127.0.0.1',0),ElmerHandler)
    local.mode='cloud'; local.model='test'; local.cloud_url=f'http://127.0.0.1:{cloud.server_port}/'
    local.station_credential='elp_'+'x'*64
    local.db_path=str(Path(td)/'unused.db'); local.feedback_db_path=str(Path(td)/'feedback.db')
    local_thread=threading.Thread(target=local.serve_forever,daemon=True); local_thread.start()
    base=f'http://127.0.0.1:{local.server_port}'
    try:
        plan_request=urllib.request.Request(
            base+'/api/plan',data='{"question":"Testfråga"}'.encode(),
            headers={'Content-Type':'application/json'},method='POST')
        with urllib.request.urlopen(plan_request) as response:
            plan=json.load(response)
        assert plan['response_locale']=='sv-SE'
        answer=urllib.request.Request(
            base+'/api/answer',data=b'{"question":"Test question"}',
            headers={'Content-Type':'application/json'},method='POST')
        with urllib.request.urlopen(answer) as response:
            stream=response.read().decode()
        assert 'Cloud answer' in stream and 'request-123' in stream
        assert '"records_searched":25000' in stream and '"evidence_used":8' in stream
        feedback=urllib.request.Request(
            base+'/api/feedback',
            data=json.dumps({'request_id':'request-123','username':'joe','rating':9,
                             'comment':'Good','question':'Test question','answer':'Cloud answer'}).encode(),
            headers={'Content-Type':'application/json'},method='POST')
        with urllib.request.urlopen(feedback) as response:
            assert json.load(response)['status']=='saved'
        assert received[0]==('/api/v1/elmer/plans',{'question':'Testfråga'})
        assert received[1]==('/api/v1/elmer/answers',{'question':'Test question'})
        assert received[2][0]=='/api/v1/elmer/feedback'
        assert 'username' not in received[2][1]

        live_answer=urllib.request.Request(
            base+'/api/answer',data=json.dumps({
                'question':'What is SM5VFE’s name?',
                'live_context':{'callbook':{
                    'call':'SM5VFE','name':'Joe Example','provider':'QRZ XML',
                    'retrieved_at':'2026-08-15T12:00:00Z',
                    'reference':'https://www.qrz.com/db/SM5VFE',
                }},
            }).encode(),headers={'Content-Type':'application/json'},method='POST')
        with urllib.request.urlopen(live_answer) as response:
            response.read()
        assert received[3][1]['live_context']['callbook']['name']=='Joe Example'

        fcc_answer=urllib.request.Request(
            base+'/api/answer',data=json.dumps({
                'question':'List hams in 44691',
                'live_context':{'fcc_search':{
                    'provider':'RigPi onboard FCC database',
                    'retrieved_at':'2026-08-15T17:00:00Z',
                    'postal_code_filter':'44691','center':'ZIP 44691 centroid',
                    'radius_applied':True,
                    'distance_basis':'FCC mailing ZIP-code centroids; distances are approximate, not street-level',
                    'total_matches':1,'returned':1,'results':[
                        {'call':'W6HN','name':'Howard Nurse','city':'Wooster','state':'OH',
                         'postal_code':'44691','distance_miles':0},
                    ],
                }},
            }).encode(),headers={'Content-Type':'application/json'},method='POST')
        with urllib.request.urlopen(fcc_answer) as response:
            response.read()
        assert received[4][1]['live_context']['fcc_search']['results'][0]['call']=='W6HN'
    finally:
        local.shutdown(); cloud.shutdown()
        local.server_close(); cloud.server_close()
        local_thread.join(timeout=3); cloud_thread.join(timeout=3)

print('Elmer paired-cloud relay tests passed')
