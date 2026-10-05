#!/usr/bin/env python3
import http.cookiejar
import json
import re
import sqlite3
import sys
import tempfile
import threading
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))

import elmer_gateway
from elmer_gateway import GatewayHandler,GatewayServer
from gateway_store import approve_pairing,initialize


def knowledge_db(path):
    with sqlite3.connect(path) as db:
        db.executescript('''
          CREATE TABLE documents(
            stable_id TEXT PRIMARY KEY,title TEXT,path TEXT,source_id TEXT,
            source_type TEXT,authority INTEGER,word_count INTEGER,author TEXT,
            published_at TEXT,source_url TEXT,local_ref TEXT
          );
          CREATE VIRTUAL TABLE chunks USING fts5(
            stable_id UNINDEXED,title,path,content,source_id UNINDEXED,
            source_type UNINDEXED,author UNINDEXED,thread_id UNINDEXED,
            source_url UNINDEXED,local_ref UNINDEXED
          );
        ''')
        row=('HELP-TEST','IC-7300 troubleshooting','troubleshooting.html','official-help',
             'help',100,20,'','','','elmer://source/official-help/document/HELP-TEST')
        db.execute('INSERT INTO documents VALUES(?,?,?,?,?,?,?,?,?,?,?)',row)
        db.execute('INSERT INTO chunks VALUES(?,?,?,?,?,?,?,?,?,?)',(
            row[0],row[1],row[2],'The IC-7300 uses Hamlib model 3073 for diagnostic testing.',
            row[3],row[4],'','','',row[10]))


def fake_answer(packet,api_key,model,reasoning,max_output_tokens,output):
    text='## Test answer\n\nUse **Hamlib model 3073**. [1]'
    output.write(text)
    return text,{'input_tokens':20,'output_tokens':10,'total_tokens':30},{'first_text_seconds':0.1,'total_seconds':0.2}


with tempfile.TemporaryDirectory() as td:
    folder=Path(td)
    db_path=folder/'knowledge.db'; state=folder/'gateway.db'
    knowledge_db(db_path); initialize(state)
    server=GatewayServer(('127.0.0.1',0),GatewayHandler)
    server.api_key='test-key'; server.gateway_secret=b'x'*48
    server.db_path=str(db_path); server.state_db=str(state)
    server.owned_images_root=folder/'owned-images'
    (server.owned_images_root/'official-help').mkdir(parents=True)
    (server.owned_images_root/'official-help'/'sample.png').write_bytes(
        (ROOT/'web'/'RigPiW.png').read_bytes())
    server.allowed_hosts={'127.0.0.1'}
    server.anonymous_per_day=1; server.anonymous_global_per_day=10
    server.reviewer_session_hours=24; server.max_question_chars=600
    server.pairing_per_day=5; server.pairing_global_per_day=20; server.pairing_minutes=10
    server.model='test-model'; server.reasoning='low'; server.max_output_tokens=100
    server.evidence_limit=3; server.max_per_source=3; server.excerpt_chars=300
    server.searchable_records=1
    original=elmer_gateway.stream_openai_answer
    elmer_gateway.stream_openai_answer=fake_answer
    thread=threading.Thread(target=server.serve_forever,daemon=True); thread.start()
    base=f'http://127.0.0.1:{server.server_port}'
    try:
        with urllib.request.urlopen(base+'/health') as response:
            health=json.load(response)
            assert health['status']=='ok'
            assert response.headers['X-Content-Type-Options']=='nosniff'
        with urllib.request.urlopen(base+'/RigPiW.png') as response:
            assert response.headers['Content-Type']=='image/png'
            assert response.read(8)==b'\x89PNG\r\n\x1a\n'
        with urllib.request.urlopen(base+'/media/official-help/sample.png') as response:
            assert response.headers['Content-Type']=='image/png'
            assert response.read(8)==b'\x89PNG\r\n\x1a\n'
        try:
            urllib.request.urlopen(base+'/media/official-help/../../gateway_store.py')
            raise AssertionError('owned-image path traversal was accepted')
        except urllib.error.HTTPError as exc:
            assert exc.code in (404,421)
        body=json.dumps({'question':'What model does the IC-7300 use?'}).encode()
        request=urllib.request.Request(base+'/api/answer',data=body,
            headers={'Content-Type':'application/json'},method='POST')
        with urllib.request.urlopen(request) as response:
            stream=response.read().decode()
        assert 'event: delta' in stream and 'Hamlib model 3073' in stream and 'event: done' in stream
        request_id=re.search(r'"request_id":\s*"([^"]+)"',stream).group(1)

        blocked_live=urllib.request.Request(base+'/api/answer',data=json.dumps({
            'question':'Who is SM5VFE?',
            'live_context':{'callbook':{'call':'SM5VFE','name':'Joe Example'}},
        }).encode(),headers={'Content-Type':'application/json'},method='POST')
        try:
            urllib.request.urlopen(blocked_live)
            raise AssertionError('anonymous live connector data was accepted')
        except urllib.error.HTTPError as exc:
            assert exc.code==400

        feedback=json.dumps({'request_id':request_id,'rating':9,'comment':'Clear',
                             'question':'What model does the IC-7300 use?',
                             'answer':'Use Hamlib model 3073.'}).encode()
        feedback_request=urllib.request.Request(base+'/api/feedback',data=feedback,
            headers={'Content-Type':'application/json'},method='POST')
        with urllib.request.urlopen(feedback_request) as response:
            assert response.status==201

        try:
            urllib.request.urlopen(request)
            raise AssertionError('second anonymous question was accepted')
        except urllib.error.HTTPError as exc:
            assert exc.code==429

        pairing_body=json.dumps({
            'station_id':'station-http-1234','station_name':'HTTP Test RigPi'}).encode()
        pairing_request=urllib.request.Request(
            base+'/api/v1/elmer/pairing/start',data=pairing_body,
            headers={'Content-Type':'application/json'},method='POST')
        with urllib.request.urlopen(pairing_request) as response:
            pairing=json.load(response)
            assert response.status==201 and len(pairing['code'])==6

        status_request=urllib.request.Request(
            base+'/api/v1/elmer/pairing/status/'+pairing['pairing_id'],
            headers={'Authorization':'Pairing '+pairing['poll_token']})
        with urllib.request.urlopen(status_request) as response:
            assert json.load(response)['status']=='pending'
        approve_pairing(state,server.gateway_secret,pairing['code'],'pilot',2)
        with urllib.request.urlopen(status_request) as response:
            paired=json.load(response)
            assert paired['status']=='paired' and paired['credential'].startswith('elp_')
        credential=paired['credential']
        with urllib.request.urlopen(status_request) as response:
            assert json.load(response)['credential']==credential

        station_headers={'Authorization':'Bearer '+credential}
        station_status=urllib.request.Request(
            base+'/api/v1/elmer/stations/status',headers=station_headers)
        with urllib.request.urlopen(station_status) as response:
            connected=json.load(response)
            assert connected['status']=='connected' and connected['remaining']==2
        with urllib.request.urlopen(status_request) as response:
            assert 'credential' not in json.load(response)

        station_body=json.dumps({
            'question':'What model does the IC-7300 use?',
            'station_id':'station-http-1234'}).encode()
        station_headers.update({'Content-Type':'application/json'})
        station_answer=urllib.request.Request(
            base+'/api/v1/elmer/answers',data=station_body,
            headers=station_headers,method='POST')
        with urllib.request.urlopen(station_answer) as response:
            station_stream=response.read().decode()
        assert '"access": "station"' in station_stream and '"remaining": 1' in station_stream
        assert '"records_searched": 1' in station_stream and '"evidence_used": 1' in station_stream
        station_request_id=re.search(r'"request_id":\s*"([^"]+)"',station_stream).group(1)

        live_body=json.dumps({
            'question':'What is SM5VFE’s name?',
            'live_context':{'callbook':{
                'call':'SM5VFE','name':'Joe Example','city':'Uppsala','country':'Sweden',
                'provider':'QRZ XML','retrieved_at':'2026-08-15T12:00:00Z',
                'reference':'https://www.qrz.com/db/SM5VFE',
            }},
        }).encode()
        live_answer=urllib.request.Request(
            base+'/api/v1/elmer/answers',data=live_body,
            headers=station_headers,method='POST')
        with urllib.request.urlopen(live_answer) as response:
            live_stream=response.read().decode()
        assert 'event: done' in live_stream and '"remaining": 0' in live_stream

        station_statistics=urllib.request.Request(
            base+'/api/v1/elmer/stations/statistics?days=30',headers=station_headers)
        with urllib.request.urlopen(station_statistics) as response:
            statistics=json.load(response)
            assert statistics['station']['station_id']=='station-http-1234'
            assert statistics['questions']==2 and statistics['tokens']['total']==60
            assert 'What model' not in json.dumps(statistics)

        station_feedback=json.dumps({
            'request_id':station_request_id,'rating':10,'comment':'Station feedback',
            'question':'What model does the IC-7300 use?',
            'answer':'Use Hamlib model 3073.'}).encode()
        feedback_headers={'Content-Type':'application/json','Authorization':'Bearer '+credential}
        feedback_request=urllib.request.Request(
            base+'/api/v1/elmer/feedback',data=station_feedback,
            headers=feedback_headers,method='POST')
        with urllib.request.urlopen(feedback_request) as response:
            assert response.status==201

        disconnect=urllib.request.Request(
            base+'/api/v1/elmer/stations/disconnect',data=b'{}',
            headers=feedback_headers,method='POST')
        with urllib.request.urlopen(disconnect) as response:
            assert json.load(response)['status']=='disconnected'
        try:
            urllib.request.urlopen(station_status)
            raise AssertionError('revoked station credential was accepted')
        except urllib.error.HTTPError as exc:
            assert exc.code==401

        ref=urllib.parse.quote('elmer://source/official-help/document/HELP-TEST',safe='')
        with urllib.request.urlopen(base+'/api/reference?ref='+ref) as response:
            assert json.load(response)['document']['title']=='IC-7300 troubleshooting'
        blocked=urllib.parse.quote(
            'elmer://source/groups-messages/thread/T/message/M',safe='')
        try:
            urllib.request.urlopen(base+'/api/reference?ref='+blocked)
            raise AssertionError('restricted community text was exposed')
        except urllib.error.HTTPError as exc:
            assert exc.code==403
    finally:
        server.shutdown(); server.server_close(); thread.join(timeout=3)
        elmer_gateway.stream_openai_answer=original

print('Elmer gateway HTTP tests passed')
