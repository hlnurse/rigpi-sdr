#!/usr/bin/env python3
"""Small streaming web interface for asking Elmer questions."""

from shortwave_plan import supplement_shortwave_plan
import argparse
import json
import os
import sqlite3
import sys
from datetime import datetime,timezone
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from pathlib import Path
from urllib.error import HTTPError,URLError
from urllib.parse import parse_qs,urljoin,urlparse
from urllib.request import Request,urlopen

from answer_knowledge import DEFAULT_MODEL,normalize_user_image,stream_openai_answer
from live_connectors import normalize_live_context
from question_knowledge import make_packet
from resolve_reference import resolve


ROOT=Path(__file__).resolve().parent
PAGE=ROOT/'web'/'index.html'


def event_bytes(event,payload):
    return f'event: {event}\ndata: {json.dumps(payload,ensure_ascii=False)}\n\n'.encode('utf-8')


def load_api_key(environ=os.environ):
    key=environ.get('OPENAI_API_KEY','').strip()
    if key:
        return key
    credentials=environ.get('CREDENTIALS_DIRECTORY','').strip()
    if credentials:
        try:
            return (Path(credentials)/'openai_api_key').read_text().strip()
        except OSError:
            pass
    return ''


def load_station_credential(path):
    try:
        credential=Path(path).read_text(encoding='utf-8').strip()
    except OSError:
        return ''
    return credential if credential.startswith('elp_') and len(credential)>=40 else ''


def cloud_request(url,credential,payload,timeout=210,accept='text/event-stream, application/json'):
    request=Request(
        url,data=json.dumps(payload).encode('utf-8'),method='POST',headers={
            'Accept':accept,
            'Authorization':'Bearer '+credential,
            'Content-Type':'application/json',
            'User-Agent':'RigPi-Elmer/0.22',
        })
    try:
        return urlopen(request,timeout=timeout)
    except HTTPError as exc:
        try:
            message=json.load(exc).get('error')
        except (ValueError,AttributeError):
            message=None
        raise RuntimeError(message or f'Elmer cloud returned HTTP {exc.code}.') from exc
    except (URLError,OSError) as exc:
        raise RuntimeError(f'Unable to reach the Elmer cloud service: {exc}') from exc


def cloud_get(url,credential,timeout=30):
    request=Request(url,method='GET',headers={
        'Accept':'application/json','Authorization':'Bearer '+credential,
        'User-Agent':'RigPi-Elmer/0.21',
    })
    try:
        return urlopen(request,timeout=timeout)
    except HTTPError as exc:
        try:
            message=json.load(exc).get('error')
        except (ValueError,AttributeError):
            message=None
        raise RuntimeError(message or f'Elmer cloud returned HTTP {exc.code}.') from exc
    except (URLError,OSError) as exc:
        raise RuntimeError(f'Unable to reach the Elmer cloud service: {exc}') from exc


def initialize_feedback_db(path):
    path=Path(path)
    path.parent.mkdir(parents=True,exist_ok=True)
    with sqlite3.connect(path) as db:
        db.execute('''CREATE TABLE IF NOT EXISTS feedback(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            username TEXT NOT NULL,
            rating INTEGER NOT NULL CHECK(rating BETWEEN 1 AND 10),
            comment TEXT NOT NULL,
            question TEXT NOT NULL,
            answer TEXT NOT NULL,
            model TEXT NOT NULL
        )''')


def store_feedback(path,username,rating,comment,question,answer,model):
    if not 1<=rating<=10:
        raise ValueError('Rating must be from 1 through 10.')
    if len(username)>100 or len(comment)>2000 or len(question)>1000 or len(answer)>50000:
        raise ValueError('Feedback is too long.')
    with sqlite3.connect(path,timeout=10) as db:
        cursor=db.execute('''INSERT INTO feedback
            (created_at,username,rating,comment,question,answer,model)
            VALUES(?,?,?,?,?,?,?)''',(
                datetime.now(timezone.utc).isoformat(),username,rating,comment,
                question,answer,model,
            ))
        return cursor.lastrowid


class AnswerSink:
    def __init__(self,handler):
        self.handler=handler

    def write(self,text):
        self.handler.send_event('delta',{'text':text})

    def flush(self):
        try:
            self.handler.wfile.flush()
        except (BrokenPipeError,ConnectionResetError):
            pass


class ElmerHandler(BaseHTTPRequestHandler):
    server_version='RigPi-Elmer/0.22'

    def log_message(self,fmt,*args):
        sys.stderr.write('[Elmer web] '+fmt%args+'\n')

    def send_json(self,status,payload):
        data=json.dumps(payload,ensure_ascii=False).encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type','application/json; charset=utf-8')
        self.send_header('Content-Length',str(len(data)))
        self.send_header('Cache-Control','no-store')
        self.end_headers()
        self.wfile.write(data)

    def send_audio(self,status,data):
        self.send_response(status)
        self.send_header('Content-Type','audio/wav')
        self.send_header('Content-Length',str(len(data)))
        self.send_header('Cache-Control','no-store')
        self.end_headers()
        self.wfile.write(data)

    def send_event(self,event,payload):
        self.wfile.write(event_bytes(event,payload))
        self.wfile.flush()

    def do_GET(self):
        parsed=urlparse(self.path)
        if parsed.path=='/api/station/statistics':
            if self.server.mode!='cloud':
                self.send_json(409,{'error':'Station statistics require a paired cloud connection.'})
                return
            days=parse_qs(parsed.query).get('days',['30'])[0]
            try:
                days=max(1,min(366,int(days)))
                upstream=cloud_get(
                    urljoin(self.server.cloud_url,f'api/v1/elmer/stations/statistics?days={days}'),
                    self.server.station_credential)
                with upstream:
                    result=json.load(upstream)
            except (ValueError,RuntimeError,json.JSONDecodeError) as exc:
                self.send_json(502,{'error':str(exc)})
                return
            self.send_json(200,result)
            return
        if parsed.path=='/':
            try:
                data=PAGE.read_bytes()
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
        if parsed.path=='/health':
            self.send_json(200,{'status':'ok','mode':self.server.mode,'model':self.server.model})
            return
        if parsed.path=='/api/reference':
            reference=parse_qs(parsed.query).get('ref',[''])[0]
            try:
                with sqlite3.connect(self.server.db_path) as db:
                    result=resolve(db,reference,1)
            except (ValueError,LookupError,sqlite3.Error) as exc:
                self.send_json(404,{'error':str(exc)})
                return
            self.send_json(200,result)
            return
        self.send_json(404,{'error':'not found'})

    def do_POST(self):
        path=urlparse(self.path).path
        if path=='/api/speech':
            if self.server.mode!='cloud':
                self.send_json(409,{'error':'Generated speech requires a paired Elmer cloud connection.'})
                return
            try:
                length=int(self.headers.get('Content-Length','0'))
                if length<1 or length>4096:
                    raise ValueError('invalid request size')
                request=json.loads(self.rfile.read(length))
                if not isinstance(request,dict):
                    raise ValueError('The request body must be a JSON object.')
                upstream=cloud_request(
                    urljoin(self.server.cloud_url,'api/v1/elmer/speech'),
                    self.server.station_credential,request,60,
                    'audio/pcm' if request.get('stream') is True else 'audio/wav')
                if request.get('stream') is True:
                    self.send_response(200)
                    self.send_header('Content-Type','audio/pcm; rate=24000; channels=1')
                    self.send_header('Cache-Control','no-store, no-transform')
                    self.send_header('X-Accel-Buffering','no')
                    self.send_header('Connection','close')
                    self.end_headers()
                    with upstream:
                        while True:
                            chunk=upstream.read(4096)
                            if not chunk:
                                break
                            self.wfile.write(chunk)
                            self.wfile.flush()
                    self.close_connection=True
                    return
                with upstream:
                    audio=upstream.read(2500001)
                if not audio or len(audio)>2500000:
                    raise RuntimeError('Elmer cloud returned invalid speech audio.')
            except (BrokenPipeError,ConnectionResetError):
                return
            except (ValueError,json.JSONDecodeError,RuntimeError) as exc:
                self.send_json(400,{'error':str(exc)})
                return
            self.send_audio(200,audio)
            return
        if path=='/api/transcription':
            if self.server.mode!='cloud':
                self.send_json(409,{'error':'Voice transcription requires a paired Elmer cloud connection.'})
                return
            try:
                length=int(self.headers.get('Content-Length','0'))
                if length<1 or length>2097152:
                    raise ValueError('invalid request size')
                request=json.loads(self.rfile.read(length))
                if not isinstance(request,dict):
                    raise ValueError('The request body must be a JSON object.')
                upstream=cloud_request(
                    urljoin(self.server.cloud_url,'api/v1/elmer/transcriptions'),
                    self.server.station_credential,request,60)
                with upstream:
                    response=json.load(upstream)
            except (ValueError,json.JSONDecodeError,RuntimeError) as exc:
                self.send_json(400,{'error':str(exc)})
                return
            self.send_json(200,response)
            return
        if path=='/api/text-translation':
            if self.server.mode!='cloud':
                self.send_json(409,{'error':'QSO translation requires a paired Elmer cloud connection.'})
                return
            try:
                length=int(self.headers.get('Content-Length','0'))
                if length<1 or length>8192:
                    raise ValueError('invalid request size')
                request=json.loads(self.rfile.read(length))
                if not isinstance(request,dict):
                    raise ValueError('The request body must be a JSON object.')
                upstream=cloud_request(
                    urljoin(self.server.cloud_url,'api/v1/elmer/text-translations'),
                    self.server.station_credential,request,60)
                with upstream:
                    response=json.load(upstream)
            except (ValueError,json.JSONDecodeError,RuntimeError) as exc:
                self.send_json(400,{'error':str(exc)})
                return
            self.send_json(200,response)
            return
        if path=='/api/translation':
            if self.server.mode!='cloud':
                self.send_json(409,{'error':'Live captions require a paired Elmer cloud connection.'})
                return
            try:
                length=int(self.headers.get('Content-Length','0'))
                if length<1 or length>1572864:
                    raise ValueError('invalid request size')
                request=json.loads(self.rfile.read(length))
                if not isinstance(request,dict):
                    raise ValueError('The request body must be a JSON object.')
                upstream=cloud_request(
                    urljoin(self.server.cloud_url,'api/v1/elmer/translations'),
                    self.server.station_credential,request,60)
                with upstream:
                    response=json.load(upstream)
            except (ValueError,json.JSONDecodeError,RuntimeError) as exc:
                self.send_json(400,{'error':str(exc)})
                return
            self.send_json(200,response)
            return
        if path=='/api/feedback':
            try:
                length=int(self.headers.get('Content-Length','0'))
                if length<1 or length>65536:
                    raise ValueError('invalid request size')
                request=json.loads(self.rfile.read(length))
                rating=int(request.get('rating',0))
                username=str(request.get('username','')).strip()
                comment=str(request.get('comment','')).strip()
                question=str(request.get('question','')).strip()
                answer=str(request.get('answer','')).strip()
                if not question or not answer:
                    raise ValueError('Question and answer are required.')
                if self.server.mode=='cloud':
                    request_id=str(request.get('request_id','')).strip()
                    if not request_id:
                        raise ValueError('The answer identifier is missing. Please ask Elmer again before rating it.')
                    upstream=cloud_request(
                        urljoin(self.server.cloud_url,'api/v1/elmer/feedback'),
                        self.server.station_credential,{
                            'request_id':request_id,'rating':rating,'comment':comment,
                            'question':question,'answer':answer,
                        },30)
                    with upstream:
                        response=json.load(upstream)
                    self.send_json(201,response)
                    return
                feedback_id=store_feedback(
                    self.server.feedback_db_path,username,rating,comment,
                    question,answer,self.server.model,
                )
            except (ValueError,json.JSONDecodeError,sqlite3.Error,RuntimeError) as exc:
                self.send_json(400,{'error':str(exc)})
                return
            self.send_json(201,{'status':'saved','id':feedback_id})
            return
        if path=='/api/plan':
            if self.server.mode!='cloud':
                self.send_json(404,{'error':'not found'})
                return
            try:
                length=int(self.headers.get('Content-Length','0'))
                if length<1 or length>8192: raise ValueError('invalid request size')
                request=json.loads(self.rfile.read(length))
                question=str(request.get('question','')).strip()
                if not question or len(question)>600: raise ValueError('Please enter a question of 1–600 characters.')
                upstream=cloud_request(urljoin(self.server.cloud_url,'api/v1/elmer/plans'),
                                       self.server.station_credential,{'question':question},60)
                with upstream: response=json.load(upstream)
            except (ValueError,json.JSONDecodeError,RuntimeError) as exc:
                self.send_json(400,{'error':str(exc)})
                return
            response=supplement_shortwave_plan(response,question)
            self.send_json(200,response)
            return
        if path!='/api/answer':
            self.send_json(404,{'error':'not found'})
            return
        try:
            length=int(self.headers.get('Content-Length','0'))
            if length<1 or length>6*1024*1024:
                raise ValueError('invalid request size')
            request=json.loads(self.rfile.read(length))
            question=str(request.get('question','')).strip()
            user_image=normalize_user_image(request.get('image'))
            live_context=normalize_live_context(request.get('live_context'))
            query_plan=request.get('query_plan') if isinstance(request.get('query_plan'),dict) else None
            maximum=600 if self.server.mode=='cloud' else 1000
            if not question or len(question)>maximum:
                raise ValueError(f'Please enter a question of 1–{maximum} characters.')
        except (ValueError,json.JSONDecodeError) as exc:
            self.send_json(400,{'error':str(exc)})
            return

        if self.server.mode=='cloud':
            try:
                cloud_payload={'question':question}
                if live_context:
                    cloud_payload['live_context']=live_context
                if query_plan:
                    cloud_payload['query_plan']=query_plan
                if user_image:
                    cloud_payload['image']={
                        'media_type':user_image['media_type'],
                        'data_base64':user_image['data_base64'],
                    }
                upstream=cloud_request(
                    urljoin(self.server.cloud_url,'api/v1/elmer/answers'),
                    self.server.station_credential,cloud_payload)
            except RuntimeError as exc:
                self.send_json(502,{'error':str(exc)})
                return
            self.send_response(200)
            self.send_header('Content-Type','text/event-stream; charset=utf-8')
            self.send_header('Cache-Control','no-cache, no-transform')
            self.send_header('Connection','close')
            self.end_headers()
            try:
                with upstream:
                    while True:
                        chunk=upstream.read(4096)
                        if not chunk:
                            break
                        self.wfile.write(chunk)
                        self.wfile.flush()
            except (BrokenPipeError,ConnectionResetError):
                pass
            return

        self.send_response(200)
        self.send_header('Content-Type','text/event-stream; charset=utf-8')
        self.send_header('Cache-Control','no-cache, no-transform')
        self.send_header('Connection','close')
        self.end_headers()
        try:
            self.send_event('status',{'text':'Elmer is looking through the RigPi knowledge base…'})
            with sqlite3.connect(self.server.db_path) as db:
                packet=make_packet(db,question,self.server.evidence_limit,None,
                                   self.server.max_per_source,self.server.excerpt_chars)
            if live_context:
                packet['live_context']=live_context
            if not packet.get('evidence') and not live_context and not user_image:
                self.send_event('error',{'message':'Elmer could not find enough evidence to answer that question.'})
                return
            self.send_event('status',{'text':'Elmer found relevant material and is preparing an answer…'})
            _,usage,timing=stream_openai_answer(
                packet,self.server.api_key,self.server.model,self.server.reasoning,
                self.server.max_output_tokens,output=AnswerSink(self),user_image=user_image,
            )
            self.send_event('done',{
                'usage':usage,'timing':timing,
                'records_searched':self.server.searchable_records,
                'evidence_used':len(packet.get('evidence',[])),
            })
        except (RuntimeError,sqlite3.Error) as exc:
            try:
                self.send_event('error',{'message':str(exc)})
            except (BrokenPipeError,ConnectionResetError):
                pass
        except (BrokenPipeError,ConnectionResetError):
            pass


class ElmerServer(ThreadingHTTPServer):
    daemon_threads=True


def main():
    parser=argparse.ArgumentParser(description='Run the RigPi Elmer question-and-answer web interface')
    parser.add_argument('--host',default='0.0.0.0')
    parser.add_argument('--port',type=int,default=8090)
    parser.add_argument('--db',default='output/knowledge.db')
    parser.add_argument('--feedback-db',default='output/feedback.db')
    parser.add_argument('--mode',choices=['auto','cloud','local'],default='auto')
    parser.add_argument('--cloud-url',default='https://elmer.rigpi.net/')
    parser.add_argument('--station-credential',default='/etc/elmer/station_credential')
    parser.add_argument('--model',default=os.environ.get('ELMER_OPENAI_MODEL',DEFAULT_MODEL))
    parser.add_argument('--reasoning',choices=['none','low','medium','high','xhigh','max'],default='low')
    parser.add_argument('--max-output-tokens',type=int,default=2200)
    parser.add_argument('-n','--evidence-limit',type=int,default=10)
    parser.add_argument('--max-per-source',type=int,default=3)
    parser.add_argument('--excerpt-chars',type=int,default=700)
    args=parser.parse_args()
    station_credential=load_station_credential(args.station_credential)
    mode=('cloud' if station_credential else 'local') if args.mode=='auto' else args.mode
    if mode=='cloud' and not station_credential:
        print(f'Elmer station credential is missing or invalid: {args.station_credential}',file=sys.stderr)
        return 2
    parsed_cloud=urlparse(args.cloud_url)
    if mode=='cloud' and (parsed_cloud.scheme!='https' or not parsed_cloud.netloc):
        print('The Elmer cloud URL must be a valid HTTPS URL.',file=sys.stderr)
        return 2
    api_key=load_api_key() if mode=='local' else ''
    if mode=='local' and not api_key:
        print('OpenAI API key is not set in OPENAI_API_KEY or the system credential.',file=sys.stderr)
        return 2
    db_path=Path(args.db)
    if not db_path.is_file():
        print(f'Knowledge database not found: {db_path}',file=sys.stderr)
        return 2
    try:
        with sqlite3.connect(f'file:{db_path}?mode=ro',uri=True) as db:
            searchable_records=db.execute('SELECT COUNT(*) FROM chunks').fetchone()[0]
    except sqlite3.Error as exc:
        print(f'Unable to count searchable knowledge records: {exc}',file=sys.stderr)
        return 2
    initialize_feedback_db(args.feedback_db)
    server=ElmerServer((args.host,args.port),ElmerHandler)
    server.api_key=api_key
    server.mode=mode
    server.cloud_url=args.cloud_url.rstrip('/')+'/'
    server.station_credential=station_credential
    server.db_path=str(db_path)
    server.feedback_db_path=str(args.feedback_db)
    server.model=args.model
    server.reasoning=args.reasoning
    server.max_output_tokens=args.max_output_tokens
    server.evidence_limit=args.evidence_limit
    server.max_per_source=args.max_per_source
    server.excerpt_chars=args.excerpt_chars
    server.searchable_records=searchable_records
    print(f'Elmer is ready at http://{args.host}:{args.port} in {mode} mode')
    print('Press Ctrl-C to stop.')
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print('\nStopping Elmer.')
    finally:
        server.server_close()
    return 0


if __name__=='__main__':
    raise SystemExit(main())
