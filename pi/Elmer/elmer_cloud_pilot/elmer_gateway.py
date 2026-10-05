#!/usr/bin/env python3
"""Public, quota-controlled gateway for the hosted RigPi Elmer pilot."""

import argparse
import json
import mimetypes
import os
import re
import sqlite3
import sys
from http import cookies
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs,unquote,urlparse

from answer_knowledge import DEFAULT_MODEL,stream_openai_answer
from elmer_web import AnswerSink,event_bytes,load_api_key
from gateway_store import (
    authenticate_station,complete_request,digest,exchange_pass,initialize,
    pairing_status,reserve_anonymous,reserve_reviewer,reserve_station,
    reviewer_context,revoke_station,start_pairing,station_statistics,store_feedback,
)
from live_connectors import normalize_live_context
from question_knowledge import make_packet
from resolve_reference import parse_reference,resolve


ROOT=Path(__file__).resolve().parent
PAGE=ROOT/'web'/'gateway.html'
ACTIVATION_PAGE=ROOT/'web'/'activate.html'
LOGO=ROOT/'web'/'RigPiW.png'
COOKIE_NAME='elmer_reviewer'


def load_secret(path):
    try:
        secret=Path(path).read_bytes().strip()
    except OSError as exc:
        raise RuntimeError(f'Unable to read gateway secret: {exc}') from exc
    if len(secret)<32:
        raise RuntimeError('Gateway secret must contain at least 32 bytes.')
    return secret


def cookie_value(header,name):
    jar=cookies.SimpleCookie()
    try:
        jar.load(header or '')
    except cookies.CookieError:
        return ''
    return jar[name].value if name in jar else ''


class GatewayHandler(BaseHTTPRequestHandler):
    server_version='RigPi-Elmer-Gateway/0.21'
    sys_version=''

    def log_message(self,fmt,*args):
        sys.stderr.write('[Elmer gateway] '+fmt%args+'\n')

    def end_headers(self):
        self.send_header('X-Content-Type-Options','nosniff')
        self.send_header('Referrer-Policy','no-referrer')
        self.send_header('Permissions-Policy','camera=(), microphone=(), geolocation=()')
        self.send_header('X-Frame-Options','SAMEORIGIN')
        self.send_header('Content-Security-Policy',
            "default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'; "
            "img-src 'self' data: https://rigpi.net https://*.rigpi.net https://groups.io https://*.groups.io https://raw.githubusercontent.com https://cdn-bio.qrz.com https://cdn-xml.qrz.com https://files.qrz.com https://static.qrz.com; "
            "style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'")
        super().end_headers()

    def valid_host(self):
        host=(self.headers.get('Host') or '').split(':',1)[0].casefold()
        return host in self.server.allowed_hosts

    def send_json(self,status,payload,extra_headers=None):
        data=json.dumps(payload,ensure_ascii=False).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type','application/json; charset=utf-8')
        self.send_header('Content-Length',str(len(data)))
        self.send_header('Cache-Control','no-store')
        for key,value in extra_headers or []:
            self.send_header(key,value)
        self.end_headers()
        self.wfile.write(data)

    def send_event(self,event,payload):
        self.wfile.write(event_bytes(event,payload))
        self.wfile.flush()

    def client_identity(self):
        forwarded=self.headers.get('X-Forwarded-For','')
        address=forwarded.split(',')[-1].strip() if forwarded else self.client_address[0]
        return digest(self.server.gateway_secret,'anonymous:'+address)

    def reviewer(self):
        token=cookie_value(self.headers.get('Cookie'),COOKIE_NAME)
        context=reviewer_context(self.server.state_db,self.server.gateway_secret,token)
        return token,context

    def authorization(self,scheme):
        value=(self.headers.get('Authorization') or '').strip()
        prefix=scheme+' '
        return value[len(prefix):].strip() if value.startswith(prefix) else ''

    def station(self):
        credential=self.authorization('Bearer')
        return credential,authenticate_station(
            self.server.state_db,self.server.gateway_secret,credential) if credential else None

    def do_GET(self):
        if not self.valid_host():
            self.send_json(421,{'error':'Unexpected host name.'})
            return
        parsed=urlparse(self.path)
        if parsed.path in ('/','/activate'):
            page=PAGE if parsed.path=='/' else ACTIVATION_PAGE
            try:
                data=page.read_bytes()
            except OSError as exc:
                self.send_json(500,{'error':str(exc)})
                return
            self.send_response(200)
            self.send_header('Content-Type','text/html; charset=utf-8')
            self.send_header('Content-Length',str(len(data)))
            self.send_header('Cache-Control','no-store')
            self.end_headers()
            self.wfile.write(data)
            return
        if parsed.path=='/RigPiW.png':
            try:
                data=LOGO.read_bytes()
            except OSError as exc:
                self.send_json(500,{'error':str(exc)})
                return
            self.send_response(200)
            self.send_header('Content-Type','image/png')
            self.send_header('Content-Length',str(len(data)))
            self.send_header('Cache-Control','public, max-age=86400')
            self.end_headers()
            self.wfile.write(data)
            return
        if parsed.path.startswith('/media/official-help/'):
            relative=Path(unquote(parsed.path[len('/media/official-help/'):]))
            root=(self.server.owned_images_root/'official-help').resolve()
            try:
                target=(root/relative).resolve()
                target.relative_to(root)
                if target.suffix.lower() not in ('.png','.jpg','.jpeg','.gif','.svg','.webp'):
                    raise FileNotFoundError
                data=target.read_bytes()
            except (OSError,ValueError):
                self.send_json(404,{'error':'image not found'})
                return
            self.send_response(200)
            self.send_header('Content-Type',mimetypes.guess_type(target.name)[0] or 'application/octet-stream')
            self.send_header('Content-Length',str(len(data)))
            self.send_header('Cache-Control','public, max-age=86400')
            self.end_headers()
            self.wfile.write(data)
            return
        if parsed.path=='/health':
            self.send_json(200,{'status':'ok','service':'elmer-gateway','version':'0.21'})
            return
        if parsed.path=='/api/session':
            _token,reviewer=self.reviewer()
            if reviewer:
                self.send_json(200,{'access':'reviewer','name':reviewer['name'],
                                    'remaining':reviewer['remaining'],'expires_at':reviewer['expires_at']})
            else:
                self.send_json(200,{'access':'anonymous','remaining':self.server.anonymous_per_day})
            return
        if parsed.path.startswith('/api/v1/elmer/pairing/status/'):
            pairing_id=unquote(parsed.path.rsplit('/',1)[-1])
            poll_token=self.authorization('Pairing')
            try:
                result=pairing_status(
                    self.server.state_db,self.server.gateway_secret,pairing_id,poll_token)
            except PermissionError as exc:
                self.send_json(403,{'error':str(exc)})
                return
            self.send_json(200,result)
            return
        if parsed.path=='/api/v1/elmer/stations/status':
            _credential,station=self.station()
            if not station:
                self.send_json(401,{'error':'Station credential is invalid or revoked.'})
                return
            self.send_json(200,{'status':'connected',**station})
            return
        if parsed.path=='/api/v1/elmer/stations/statistics':
            _credential,station=self.station()
            if not station:
                self.send_json(401,{'error':'Station credential is invalid or revoked.'})
                return
            try:
                days=int(parse_qs(parsed.query).get('days',['30'])[0])
                result=station_statistics(
                    self.server.state_db,station['station_id'],days)
            except (ValueError,LookupError,sqlite3.Error) as exc:
                self.send_json(400,{'error':str(exc)})
                return
            self.send_json(200,result)
            return
        if parsed.path.startswith('/review/'):
            invitation=unquote(parsed.path[len('/review/'):]).strip('/')
            try:
                session,expires,_name=exchange_pass(
                    self.server.state_db,self.server.gateway_secret,invitation,
                    self.server.reviewer_session_hours)
            except PermissionError as exc:
                self.send_json(403,{'error':str(exc)})
                return
            max_age=max(1,int((expires.timestamp()-__import__('time').time())))
            self.send_response(303)
            self.send_header('Location','/')
            self.send_header('Cache-Control','no-store')
            self.send_header('Set-Cookie',
                f'{COOKIE_NAME}={session}; Path=/; Max-Age={max_age}; Secure; HttpOnly; SameSite=Lax')
            self.end_headers()
            return
        if parsed.path=='/api/reference':
            reference=parse_qs(parsed.query).get('ref',[''])[0]
            try:
                _kind,source_id,_thread_id,_stable_id=parse_reference(reference)
                if source_id!='official-help':
                    raise PermissionError('This source is cited for traceability but its full local text is not published by the demonstration.')
                with sqlite3.connect(f'file:{self.server.db_path}?mode=ro',uri=True) as db:
                    result=resolve(db,reference,0)
            except PermissionError as exc:
                self.send_json(403,{'error':str(exc)})
                return
            except (ValueError,LookupError,sqlite3.Error) as exc:
                self.send_json(404,{'error':str(exc)})
                return
            self.send_json(200,result)
            return
        self.send_json(404,{'error':'not found'})

    def read_json(self,maximum):
        length=int(self.headers.get('Content-Length','0'))
        if length<1 or length>maximum:
            raise ValueError('Invalid request size.')
        value=json.loads(self.rfile.read(length))
        if not isinstance(value,dict):
            raise ValueError('The request body must be a JSON object.')
        return value

    def do_POST(self):
        if not self.valid_host():
            self.send_json(421,{'error':'Unexpected host name.'})
            return
        path=urlparse(self.path).path
        if path=='/api/v1/elmer/pairing/start':
            self.handle_pairing_start()
            return
        if path=='/api/v1/elmer/stations/disconnect':
            _credential,station=self.station()
            if not station:
                self.send_json(401,{'error':'Station credential is invalid or revoked.'})
                return
            revoke_station(self.server.state_db,station['credential_id'])
            self.send_json(200,{'status':'disconnected'})
            return
        if path in ('/api/feedback','/api/v1/elmer/feedback'):
            self.handle_feedback()
            return
        if path not in ('/api/answer','/api/v1/elmer/demo/answers','/api/v1/elmer/answers'):
            self.send_json(404,{'error':'not found'})
            return
        self.handle_answer(station_route=path=='/api/v1/elmer/answers')

    def handle_pairing_start(self):
        try:
            request=self.read_json(8192)
            station_id=str(request.get('station_id','')).strip()
            station_name=str(request.get('station_name','')).strip()
            if not re.fullmatch(r'[A-Za-z0-9._:-]{8,128}',station_id):
                raise ValueError('station_id must contain 8–128 safe identifier characters.')
            if not station_name or len(station_name)>100:
                raise ValueError('station_name must contain 1–100 characters.')
            result=start_pairing(
                self.server.state_db,self.server.gateway_secret,self.client_identity(),
                station_id,station_name,self.server.pairing_per_day,
                self.server.pairing_global_per_day,self.server.pairing_minutes)
        except PermissionError as exc:
            self.send_json(429,{'error':str(exc)})
            return
        except (ValueError,json.JSONDecodeError,sqlite3.Error,RuntimeError) as exc:
            self.send_json(400,{'error':str(exc)})
            return
        result['activation_url']='https://elmer.rigpi.net/activate'
        self.send_json(201,result)

    def handle_answer(self,station_route=False):
        try:
            request=self.read_json(16384)
            question=str(request.get('question','')).strip()
            if request.get('live_context') and not station_route:
                raise ValueError('Live connector data is accepted only from a paired RigPi.')
            live_context=normalize_live_context(request.get('live_context')) if station_route else None
            if not question or len(question)>self.server.max_question_chars:
                raise ValueError(f'Please enter a question of 1–{self.server.max_question_chars} characters.')
        except (ValueError,json.JSONDecodeError) as exc:
            self.send_json(400,{'error':str(exc)})
            return

        session_token,reviewer=self.reviewer()
        try:
            if station_route:
                station_credential,station=self.station()
                if not station:
                    self.send_json(401,{'error':'Station credential is invalid or revoked.'})
                    return
                supplied_station=str(request.get('station_id','')).strip()
                if supplied_station and supplied_station!=station['station_id']:
                    self.send_json(403,{'error':'The request station_id does not match this credential.'})
                    return
                request_id,remaining,actor_hash,station=reserve_station(
                    self.server.state_db,self.server.gateway_secret,station_credential)
                access_type='station'
            elif reviewer:
                request_id,remaining,actor_hash,_name=reserve_reviewer(
                    self.server.state_db,self.server.gateway_secret,session_token)
                access_type='reviewer'
            else:
                actor_hash=self.client_identity()
                request_id,remaining=reserve_anonymous(
                    self.server.state_db,actor_hash,self.server.anonymous_per_day,
                    self.server.anonymous_global_per_day)
                access_type='anonymous'
        except PermissionError as exc:
            self.send_json(429,{'error':str(exc)})
            return

        self.send_response(200)
        self.send_header('Content-Type','text/event-stream; charset=utf-8')
        self.send_header('Cache-Control','no-cache, no-transform')
        self.send_header('Connection','close')
        self.end_headers()
        try:
            self.send_event('status',{'text':'Elmer is looking through the RigPi knowledge base…'})
            with sqlite3.connect(f'file:{self.server.db_path}?mode=ro',uri=True) as db:
                packet=make_packet(db,question,self.server.evidence_limit,None,
                                   self.server.max_per_source,self.server.excerpt_chars)
            if live_context:
                packet['live_context']=live_context
            if not packet.get('evidence') and not live_context:
                complete_request(
                    self.server.state_db,request_id,'no_evidence',
                    records_searched=self.server.searchable_records,evidence_used=0,
                    model=self.server.model)
                self.send_event('error',{'message':'Elmer could not find enough evidence to answer that question.'})
                return
            self.send_event('status',{'text':'Elmer found relevant material and is preparing an answer…'})
            _,usage,timing=stream_openai_answer(
                packet,self.server.api_key,self.server.model,self.server.reasoning,
                self.server.max_output_tokens,output=AnswerSink(self))
            complete_request(
                self.server.state_db,request_id,'complete',usage,timing,
                records_searched=self.server.searchable_records,
                evidence_used=len(packet.get('evidence',[])),model=self.server.model)
            self.send_event('done',{'request_id':request_id,'access':access_type,
                                    'remaining':remaining,'usage':usage,'timing':timing,
                                    'records_searched':self.server.searchable_records,
                                    'evidence_used':len(packet.get('evidence',[]))})
        except (RuntimeError,sqlite3.Error) as exc:
            complete_request(
                self.server.state_db,request_id,'error',error_class=type(exc).__name__,
                records_searched=self.server.searchable_records,model=self.server.model)
            try:
                self.send_event('error',{'message':'Elmer could not complete the answer. Please try again later.'})
            except (BrokenPipeError,ConnectionResetError):
                pass
        except (BrokenPipeError,ConnectionResetError):
            complete_request(self.server.state_db,request_id,'disconnected')

    def handle_feedback(self):
        try:
            request=self.read_json(65536)
            request_id=str(request.get('request_id','')).strip()
            rating=int(request.get('rating',0))
            comment=str(request.get('comment','')).strip()
            question=str(request.get('question','')).strip()
            answer=str(request.get('answer','')).strip()
            station_credential,station=self.station()
            session_token,reviewer=self.reviewer()
            if station:
                access_type='station'
                actor_hash=digest(self.server.gateway_secret,'station-id:'+station['credential_id'])
            elif reviewer:
                access_type='reviewer'
                actor_hash=digest(self.server.gateway_secret,'reviewer:'+reviewer['pass_id'])
            else:
                access_type='anonymous'
                actor_hash=self.client_identity()
            feedback_id=store_feedback(self.server.state_db,request_id,access_type,actor_hash,
                                       rating,comment,question,answer)
        except (ValueError,json.JSONDecodeError,sqlite3.Error) as exc:
            self.send_json(400,{'error':str(exc)})
            return
        self.send_json(201,{'status':'saved','id':feedback_id})


class GatewayServer(ThreadingHTTPServer):
    daemon_threads=True


def main():
    parser=argparse.ArgumentParser(description='Run the public RigPi Elmer gateway')
    parser.add_argument('--host',default='127.0.0.1')
    parser.add_argument('--port',type=int,default=8092)
    parser.add_argument('--db',default='/opt/elmer/data/knowledge.db')
    parser.add_argument('--state-db',default='/opt/elmer/state/gateway.db')
    parser.add_argument('--owned-images-root',default='/opt/elmer/data/owned-images')
    parser.add_argument('--gateway-secret',default='/etc/elmer/gateway_secret')
    parser.add_argument('--allowed-hosts',default='elmer.rigpi.net,www.elmer.rigpi.net,127.0.0.1,localhost')
    parser.add_argument('--anonymous-per-day',type=int,default=1)
    parser.add_argument('--anonymous-global-per-day',type=int,default=50)
    parser.add_argument('--reviewer-session-hours',type=int,default=24)
    parser.add_argument('--pairing-per-day',type=int,default=5)
    parser.add_argument('--pairing-global-per-day',type=int,default=200)
    parser.add_argument('--pairing-minutes',type=int,default=10)
    parser.add_argument('--max-question-chars',type=int,default=600)
    parser.add_argument('--model',default=os.environ.get('ELMER_OPENAI_MODEL',DEFAULT_MODEL))
    parser.add_argument('--reasoning',choices=['none','low','medium','high','xhigh','max'],default='low')
    parser.add_argument('--max-output-tokens',type=int,default=1600)
    parser.add_argument('--evidence-limit',type=int,default=10)
    parser.add_argument('--max-per-source',type=int,default=3)
    parser.add_argument('--excerpt-chars',type=int,default=700)
    args=parser.parse_args()
    if min(args.anonymous_per_day,args.anonymous_global_per_day,args.reviewer_session_hours,
           args.pairing_per_day,args.pairing_global_per_day,args.pairing_minutes)<1:
        parser.error('quota and session values must be positive')
    api_key=load_api_key()
    if not api_key:
        print('OpenAI API key is not configured.',file=sys.stderr)
        return 2
    try:
        gateway_secret=load_secret(args.gateway_secret)
    except RuntimeError as exc:
        print(str(exc),file=sys.stderr)
        return 2
    if not Path(args.db).is_file():
        print(f'Knowledge database not found: {args.db}',file=sys.stderr)
        return 2
    try:
        with sqlite3.connect(f'file:{args.db}?mode=ro',uri=True) as db:
            searchable_records=db.execute('SELECT COUNT(*) FROM chunks').fetchone()[0]
    except sqlite3.Error as exc:
        print(f'Unable to count searchable knowledge records: {exc}',file=sys.stderr)
        return 2
    Path(args.state_db).parent.mkdir(parents=True,exist_ok=True)
    initialize(args.state_db)
    server=GatewayServer((args.host,args.port),GatewayHandler)
    server.api_key=api_key
    server.gateway_secret=gateway_secret
    server.db_path=args.db
    server.state_db=args.state_db
    server.owned_images_root=Path(args.owned_images_root)
    server.allowed_hosts={x.strip().casefold() for x in args.allowed_hosts.split(',') if x.strip()}
    server.anonymous_per_day=args.anonymous_per_day
    server.anonymous_global_per_day=args.anonymous_global_per_day
    server.reviewer_session_hours=args.reviewer_session_hours
    server.pairing_per_day=args.pairing_per_day
    server.pairing_global_per_day=args.pairing_global_per_day
    server.pairing_minutes=args.pairing_minutes
    server.max_question_chars=args.max_question_chars
    server.model=args.model
    server.reasoning=args.reasoning
    server.max_output_tokens=args.max_output_tokens
    server.evidence_limit=args.evidence_limit
    server.max_per_source=args.max_per_source
    server.excerpt_chars=args.excerpt_chars
    server.searchable_records=searchable_records
    print(f'Elmer gateway ready at http://{args.host}:{args.port}')
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
    return 0


if __name__=='__main__':
    raise SystemExit(main())
