#!/usr/bin/env python3
"""Persistent quotas, reviewer passes, request metadata, and feedback for Elmer."""

import hashlib
import hmac
import json
import secrets
import sqlite3
import uuid
from datetime import datetime,timezone,timedelta


def utc_now():
    return datetime.now(timezone.utc)


def iso(value):
    return value.astimezone(timezone.utc).isoformat()


def digest(secret,value):
    return hmac.new(secret,value.encode('utf-8'),hashlib.sha256).hexdigest()


def initialize(path):
    with sqlite3.connect(path) as db:
        db.executescript('''
          PRAGMA journal_mode=WAL;
          PRAGMA foreign_keys=ON;
          CREATE TABLE IF NOT EXISTS anonymous_usage(
            day TEXT NOT NULL,
            identity_hash TEXT NOT NULL,
            request_count INTEGER NOT NULL,
            PRIMARY KEY(day,identity_hash)
          );
          CREATE TABLE IF NOT EXISTS anonymous_global_usage(
            day TEXT PRIMARY KEY,
            request_count INTEGER NOT NULL
          );
          CREATE TABLE IF NOT EXISTS reviewer_passes(
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            question_limit INTEGER NOT NULL,
            question_count INTEGER NOT NULL DEFAULT 0,
            revoked_at TEXT NOT NULL DEFAULT ''
          );
          CREATE TABLE IF NOT EXISTS reviewer_sessions(
            session_hash TEXT PRIMARY KEY,
            pass_id TEXT NOT NULL REFERENCES reviewer_passes(id),
            created_at TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL
          );
          CREATE TABLE IF NOT EXISTS requests(
            id TEXT PRIMARY KEY,
            created_at TEXT NOT NULL,
            access_type TEXT NOT NULL,
            actor_hash TEXT NOT NULL,
            status TEXT NOT NULL,
            input_tokens INTEGER,
            output_tokens INTEGER,
            total_tokens INTEGER,
            first_text_seconds REAL,
            total_seconds REAL,
            error_class TEXT NOT NULL DEFAULT ''
          );
          CREATE TABLE IF NOT EXISTS feedback(
            id TEXT PRIMARY KEY,
            created_at TEXT NOT NULL,
            request_id TEXT NOT NULL,
            access_type TEXT NOT NULL,
            actor_hash TEXT NOT NULL,
            rating INTEGER NOT NULL CHECK(rating BETWEEN 1 AND 10),
            comment TEXT NOT NULL,
            question TEXT NOT NULL,
            answer TEXT NOT NULL
          );
          CREATE UNIQUE INDEX IF NOT EXISTS feedback_one_per_request
            ON feedback(request_id);
          CREATE TABLE IF NOT EXISTS pairing_usage(
            day TEXT NOT NULL,
            actor_hash TEXT NOT NULL,
            request_count INTEGER NOT NULL,
            PRIMARY KEY(day,actor_hash)
          );
          CREATE TABLE IF NOT EXISTS pairing_global_usage(
            day TEXT PRIMARY KEY,
            request_count INTEGER NOT NULL
          );
          CREATE TABLE IF NOT EXISTS pairing_requests(
            id TEXT PRIMARY KEY,
            code_hash TEXT NOT NULL UNIQUE,
            poll_hash TEXT NOT NULL,
            station_id TEXT NOT NULL,
            station_name TEXT NOT NULL,
            actor_hash TEXT NOT NULL,
            created_at TEXT NOT NULL,
            expires_at TEXT NOT NULL,
            status TEXT NOT NULL,
            approved_at TEXT NOT NULL DEFAULT '',
            claimed_at TEXT NOT NULL DEFAULT '',
            credential_plaintext TEXT NOT NULL DEFAULT '',
            credential_id TEXT NOT NULL DEFAULT ''
          );
          CREATE TABLE IF NOT EXISTS station_credentials(
            id TEXT PRIMARY KEY,
            station_id TEXT NOT NULL,
            station_name TEXT NOT NULL,
            credential_hash TEXT NOT NULL UNIQUE,
            plan TEXT NOT NULL,
            created_at TEXT NOT NULL,
            revoked_at TEXT NOT NULL DEFAULT '',
            monthly_limit INTEGER NOT NULL,
            usage_month TEXT NOT NULL,
            question_count INTEGER NOT NULL DEFAULT 0
          );
          CREATE TABLE IF NOT EXISTS admin_audit(
            id TEXT PRIMARY KEY,
            created_at TEXT NOT NULL,
            administrator TEXT NOT NULL,
            action TEXT NOT NULL,
            target_type TEXT NOT NULL,
            target_id TEXT NOT NULL,
            detail TEXT NOT NULL
          );
        ''')
        columns={row[1] for row in db.execute('PRAGMA table_info(requests)')}
        if 'subject_id' not in columns:
            db.execute("ALTER TABLE requests ADD COLUMN subject_id TEXT NOT NULL DEFAULT ''")
        if 'records_searched' not in columns:
            db.execute('ALTER TABLE requests ADD COLUMN records_searched INTEGER')
        if 'evidence_used' not in columns:
            db.execute('ALTER TABLE requests ADD COLUMN evidence_used INTEGER')
        if 'model' not in columns:
            db.execute("ALTER TABLE requests ADD COLUMN model TEXT NOT NULL DEFAULT ''")


def _request(db,access_type,actor_hash,subject_id=''):
    request_id=str(uuid.uuid4())
    db.execute('''INSERT INTO requests(id,created_at,access_type,actor_hash,status,subject_id)
                  VALUES(?,?,?,?,?,?)''',
               (request_id,iso(utc_now()),access_type,actor_hash,'reserved',subject_id))
    return request_id


def reserve_anonymous(path,actor_hash,per_day,global_per_day):
    day=utc_now().date().isoformat()
    with sqlite3.connect(path,timeout=15) as db:
        db.execute('BEGIN IMMEDIATE')
        personal=db.execute(
            'SELECT request_count FROM anonymous_usage WHERE day=? AND identity_hash=?',
            (day,actor_hash)).fetchone()
        global_row=db.execute(
            'SELECT request_count FROM anonymous_global_usage WHERE day=?',(day,)).fetchone()
        personal_count=personal[0] if personal else 0
        global_count=global_row[0] if global_row else 0
        if personal_count>=per_day:
            raise PermissionError('Today’s complimentary live question has already been used from this connection.')
        if global_count>=global_per_day:
            raise PermissionError('Today’s public Elmer demonstration allowance has been reached. Please try again tomorrow.')
        db.execute('''INSERT INTO anonymous_usage(day,identity_hash,request_count)
                      VALUES(?,?,1)
                      ON CONFLICT(day,identity_hash) DO UPDATE SET request_count=request_count+1''',
                   (day,actor_hash))
        db.execute('''INSERT INTO anonymous_global_usage(day,request_count) VALUES(?,1)
                      ON CONFLICT(day) DO UPDATE SET request_count=request_count+1''',(day,))
        request_id=_request(db,'anonymous',actor_hash)
        return request_id,per_day-personal_count-1


def create_pass(path,secret,name,days,question_limit):
    token=secrets.token_urlsafe(32)
    pass_id=str(uuid.uuid4())
    now=utc_now()
    with sqlite3.connect(path) as db:
        db.execute('''INSERT INTO reviewer_passes
            (id,name,token_hash,created_at,expires_at,question_limit,question_count)
            VALUES(?,?,?,?,?,?,0)''',
            (pass_id,name,digest(secret,token),iso(now),iso(now+timedelta(days=days)),question_limit))
    return pass_id,token,now+timedelta(days=days)


def exchange_pass(path,secret,token,session_hours):
    token_hash=digest(secret,token)
    now=utc_now()
    session_token=secrets.token_urlsafe(32)
    with sqlite3.connect(path,timeout=15) as db:
        db.execute('BEGIN IMMEDIATE')
        row=db.execute('''SELECT id,name,expires_at,question_limit,question_count,revoked_at
                          FROM reviewer_passes WHERE token_hash=?''',(token_hash,)).fetchone()
        if not row or row[5] or datetime.fromisoformat(row[2])<=now or row[4]>=row[3]:
            raise PermissionError('This reviewer invitation is invalid, expired, revoked, or fully used.')
        expires=min(datetime.fromisoformat(row[2]),now+timedelta(hours=session_hours))
        db.execute('''INSERT INTO reviewer_sessions
            (session_hash,pass_id,created_at,expires_at,last_seen_at) VALUES(?,?,?,?,?)''',
            (digest(secret,session_token),row[0],iso(now),iso(expires),iso(now)))
    return session_token,expires,row[1]


def reviewer_context(path,secret,session_token):
    if not session_token:
        return None
    now=utc_now()
    with sqlite3.connect(path) as db:
        row=db.execute('''SELECT p.id,p.name,p.expires_at,p.question_limit,p.question_count,
                                 p.revoked_at,s.expires_at
                          FROM reviewer_sessions s JOIN reviewer_passes p ON p.id=s.pass_id
                          WHERE s.session_hash=?''',(digest(secret,session_token),)).fetchone()
    if not row or row[5] or datetime.fromisoformat(row[2])<=now or datetime.fromisoformat(row[6])<=now:
        return None
    return {'pass_id':row[0],'name':row[1],'remaining':max(0,row[3]-row[4]),
            'expires_at':row[2]}


def reserve_reviewer(path,secret,session_token):
    session_hash=digest(secret,session_token)
    now=utc_now()
    with sqlite3.connect(path,timeout=15) as db:
        db.execute('BEGIN IMMEDIATE')
        row=db.execute('''SELECT p.id,p.name,p.expires_at,p.question_limit,p.question_count,
                                 p.revoked_at,s.expires_at
                          FROM reviewer_sessions s JOIN reviewer_passes p ON p.id=s.pass_id
                          WHERE s.session_hash=?''',(session_hash,)).fetchone()
        if not row or row[5] or datetime.fromisoformat(row[2])<=now or datetime.fromisoformat(row[6])<=now:
            raise PermissionError('The reviewer session has expired or been revoked.')
        if row[4]>=row[3]:
            raise PermissionError('This reviewer pass has used all of its questions.')
        db.execute('UPDATE reviewer_passes SET question_count=question_count+1 WHERE id=?',(row[0],))
        db.execute('UPDATE reviewer_sessions SET last_seen_at=? WHERE session_hash=?',(iso(now),session_hash))
        actor_hash=digest(secret,'reviewer:'+row[0])
        request_id=_request(db,'reviewer',actor_hash)
        return request_id,row[3]-row[4]-1,actor_hash,row[1]


def complete_request(path,request_id,status,usage=None,timing=None,error_class='',
                     records_searched=None,evidence_used=None,model=''):
    usage=usage or {}; timing=timing or {}
    with sqlite3.connect(path) as db:
        db.execute('''UPDATE requests SET status=?,input_tokens=?,output_tokens=?,total_tokens=?,
            first_text_seconds=?,total_seconds=?,error_class=?,records_searched=?,
            evidence_used=?,model=? WHERE id=?''',(
            status,usage.get('input_tokens'),usage.get('output_tokens'),usage.get('total_tokens'),
            timing.get('first_text_seconds'),timing.get('total_seconds'),error_class,
            records_searched,evidence_used,model,request_id))


def store_feedback(path,request_id,access_type,actor_hash,rating,comment,question,answer):
    if not 1<=rating<=10:
        raise ValueError('Rating must be from 1 through 10.')
    if len(comment)>2000 or len(question)>1000 or len(answer)>50000:
        raise ValueError('Feedback is too long.')
    feedback_id=str(uuid.uuid4())
    with sqlite3.connect(path) as db:
        request_row=db.execute(
            'SELECT access_type,actor_hash,status FROM requests WHERE id=?',(request_id,)).fetchone()
        if (not request_row or request_row[0]!=access_type or
                not hmac.compare_digest(request_row[1],actor_hash) or
                request_row[2]!='complete'):
            raise ValueError('Unknown or unauthorized answer request.')
        db.execute('''INSERT INTO feedback
            (id,created_at,request_id,access_type,actor_hash,rating,comment,question,answer)
            VALUES(?,?,?,?,?,?,?,?,?)''',(
            feedback_id,iso(utc_now()),request_id,access_type,actor_hash,
            rating,comment,question,answer))
    return feedback_id


def list_passes(path):
    with sqlite3.connect(path) as db:
        return db.execute('''SELECT id,name,created_at,expires_at,question_count,question_limit,revoked_at
                             FROM reviewer_passes ORDER BY created_at DESC''').fetchall()


def revoke_pass(path,pass_id):
    with sqlite3.connect(path) as db:
        cursor=db.execute('UPDATE reviewer_passes SET revoked_at=? WHERE id=? AND revoked_at=""',
                          (iso(utc_now()),pass_id))
        return cursor.rowcount


PAIRING_ALPHABET='ABCDEFGHJKLMNPQRSTUVWXYZ23456789'
PAIRING_CLAIM_MINUTES=10


def start_pairing(path,secret,actor_hash,station_id,station_name,per_day=5,global_per_day=200,minutes=10):
    now=utc_now(); day=now.date().isoformat()
    poll_token=secrets.token_urlsafe(32)
    pairing_id=str(uuid.uuid4())
    with sqlite3.connect(path,timeout=15) as db:
        db.execute('BEGIN IMMEDIATE')
        personal=db.execute('SELECT request_count FROM pairing_usage WHERE day=? AND actor_hash=?',
                            (day,actor_hash)).fetchone()
        global_row=db.execute('SELECT request_count FROM pairing_global_usage WHERE day=?',(day,)).fetchone()
        if personal and personal[0]>=per_day:
            raise PermissionError('Too many pairing requests from this connection today.')
        if global_row and global_row[0]>=global_per_day:
            raise PermissionError('The pairing service is temporarily at capacity.')
        for _attempt in range(20):
            code=''.join(secrets.choice(PAIRING_ALPHABET) for _ in range(6))
            code_hash=digest(secret,'pair-code:'+code)
            if not db.execute('SELECT 1 FROM pairing_requests WHERE code_hash=?',(code_hash,)).fetchone():
                break
        else:
            raise RuntimeError('Unable to allocate a pairing code.')
        db.execute('''INSERT INTO pairing_requests
            (id,code_hash,poll_hash,station_id,station_name,actor_hash,created_at,expires_at,status)
            VALUES(?,?,?,?,?,?,?,?,?)''',(
                pairing_id,code_hash,digest(secret,'pair-poll:'+poll_token),station_id,
                station_name,actor_hash,iso(now),iso(now+timedelta(minutes=minutes)),'pending'))
        db.execute('''INSERT INTO pairing_usage(day,actor_hash,request_count) VALUES(?,?,1)
                      ON CONFLICT(day,actor_hash) DO UPDATE SET request_count=request_count+1''',
                   (day,actor_hash))
        db.execute('''INSERT INTO pairing_global_usage(day,request_count) VALUES(?,1)
                      ON CONFLICT(day) DO UPDATE SET request_count=request_count+1''',(day,))
    return {'pairing_id':pairing_id,'poll_token':poll_token,'code':code,
            'expires_at':iso(now+timedelta(minutes=minutes))}


def pairing_status(path,secret,pairing_id,poll_token):
    now=utc_now()
    with sqlite3.connect(path,timeout=15) as db:
        db.execute('BEGIN IMMEDIATE')
        row=db.execute('''SELECT station_id,station_name,expires_at,status,credential_plaintext,
                                 credential_id,poll_hash
                          FROM pairing_requests WHERE id=?''',(pairing_id,)).fetchone()
        if not row or not hmac.compare_digest(row[6],digest(secret,'pair-poll:'+poll_token)):
            raise PermissionError('Unknown pairing request.')
        status=row[3]
        if status in ('pending','approved') and datetime.fromisoformat(row[2])<=now:
            status='expired'
            db.execute("UPDATE pairing_requests SET status=?,credential_plaintext='' WHERE id=?",
                       (status,pairing_id))
            if row[5]:
                db.execute("UPDATE station_credentials SET revoked_at=? WHERE id=? AND revoked_at=''",
                           (iso(now),row[5]))
        result={'status':status,'station_id':row[0],'station_name':row[1],'expires_at':row[2]}
        if status=='approved' and row[4]:
            result.update({'status':'paired','credential':row[4],'credential_id':row[5]})
        elif status=='claimed':
            result['status']='paired'
        return result


def approve_pairing(path,secret,code,plan='pilot',monthly_limit=200):
    now=utc_now(); code_hash=digest(secret,'pair-code:'+code.strip().upper())
    credential='elp_'+secrets.token_urlsafe(48)
    credential_id=str(uuid.uuid4())
    with sqlite3.connect(path,timeout=15) as db:
        db.execute('BEGIN IMMEDIATE')
        row=db.execute('''SELECT id,station_id,station_name,expires_at,status
                          FROM pairing_requests WHERE code_hash=?''',(code_hash,)).fetchone()
        if not row or row[4]!='pending' or datetime.fromisoformat(row[3])<=now:
            raise PermissionError('Pairing code is invalid, expired, or already used.')
        db.execute('UPDATE station_credentials SET revoked_at=? WHERE station_id=? AND revoked_at=""',
                   (iso(now),row[1]))
        db.execute('''INSERT INTO station_credentials
            (id,station_id,station_name,credential_hash,plan,created_at,monthly_limit,usage_month,question_count)
            VALUES(?,?,?,?,?,?,?,?,0)''',(
                credential_id,row[1],row[2],digest(secret,'station:'+credential),plan,
                iso(now),monthly_limit,now.strftime('%Y-%m')))
        db.execute('''UPDATE pairing_requests SET status='approved',approved_at=?,expires_at=?,
                     credential_plaintext=?,credential_id=? WHERE id=?''',
                   (iso(now),iso(now+timedelta(minutes=PAIRING_CLAIM_MINUTES)),
                    credential,credential_id,row[0]))
    return {'pairing_id':row[0],'station_id':row[1],'station_name':row[2],
            'credential_id':credential_id,'plan':plan,'monthly_limit':monthly_limit}


def reject_pairing(path,secret,code):
    code_hash=digest(secret,'pair-code:'+code.strip().upper())
    with sqlite3.connect(path) as db:
        cursor=db.execute("UPDATE pairing_requests SET status='rejected' WHERE code_hash=? AND status='pending'",
                          (code_hash,))
        return cursor.rowcount


def list_pairings(path,limit=50):
    with sqlite3.connect(path) as db:
        return db.execute('''SELECT id,station_id,station_name,created_at,expires_at,status
                             FROM pairing_requests ORDER BY created_at DESC LIMIT ?''',(limit,)).fetchall()


def authenticate_station(path,secret,credential):
    if not credential.startswith('elp_') or len(credential)<40:
        return None
    with sqlite3.connect(path,timeout=15) as db:
        db.execute('BEGIN IMMEDIATE')
        row=db.execute('''SELECT id,station_id,station_name,plan,monthly_limit,usage_month,
                                 question_count,created_at
                          FROM station_credentials
                          WHERE credential_hash=? AND revoked_at=""''',
                       (digest(secret,'station:'+credential),)).fetchone()
        if row:
            db.execute('''UPDATE pairing_requests SET status='claimed',claimed_at=?,credential_plaintext=''
                          WHERE credential_id=? AND status='approved' ''',(iso(utc_now()),row[0]))
    if not row:
        return None
    month=utc_now().strftime('%Y-%m')
    used=row[6] if row[5]==month else 0
    return {'credential_id':row[0],'station_id':row[1],'station_name':row[2],
            'plan':row[3],'monthly_limit':row[4],'used':used,
            'remaining':max(0,row[4]-used),'created_at':row[7]}


def reserve_station(path,secret,credential):
    credential_hash=digest(secret,'station:'+credential)
    now=utc_now(); month=now.strftime('%Y-%m')
    with sqlite3.connect(path,timeout=15) as db:
        db.execute('BEGIN IMMEDIATE')
        row=db.execute('''SELECT id,station_id,station_name,plan,monthly_limit,usage_month,question_count
                          FROM station_credentials WHERE credential_hash=? AND revoked_at=""''',
                       (credential_hash,)).fetchone()
        if not row:
            raise PermissionError('Station credential is invalid or revoked.')
        used=row[6] if row[5]==month else 0
        if used>=row[4]:
            raise PermissionError('This station has reached its monthly Elmer allowance.')
        if row[5]!=month:
            db.execute('UPDATE station_credentials SET usage_month=?,question_count=0 WHERE id=?',
                       (month,row[0]))
        db.execute('UPDATE station_credentials SET question_count=question_count+1 WHERE id=?',(row[0],))
        actor_hash=digest(secret,'station-id:'+row[0])
        request_id=_request(db,'station',actor_hash,row[0])
        return request_id,row[4]-used-1,actor_hash,{
            'credential_id':row[0],'station_id':row[1],'station_name':row[2],
            'plan':row[3],'monthly_limit':row[4]}


def list_stations(path):
    with sqlite3.connect(path) as db:
        return db.execute('''SELECT id,station_id,station_name,plan,created_at,revoked_at,
                                    monthly_limit,usage_month,question_count
                             FROM station_credentials ORDER BY created_at DESC''').fetchall()


def revoke_station(path,credential_id):
    with sqlite3.connect(path) as db:
        cursor=db.execute('''UPDATE station_credentials SET revoked_at=?
                             WHERE id=? AND revoked_at=""''',(iso(utc_now()),credential_id))
        return cursor.rowcount


def _percentile(values,fraction):
    values=sorted(value for value in values if value is not None)
    if not values:
        return None
    return values[min(len(values)-1,max(0,int((len(values)-1)*fraction)))]


def station_statistics(path,station_id,days=30):
    """Return content-free operational statistics for one physical RigPi."""
    if not station_id or not 1<=days<=366:
        raise ValueError('Invalid station statistics request.')
    now=utc_now(); month=now.strftime('%Y-%m')
    since=iso(now-timedelta(days=days))
    with sqlite3.connect(path) as db:
        db.row_factory=sqlite3.Row
        credential=db.execute('''SELECT id,station_name,plan,created_at,revoked_at,
                                        monthly_limit,usage_month,question_count
            FROM station_credentials WHERE station_id=?
            ORDER BY (revoked_at='') DESC,created_at DESC LIMIT 1''',(station_id,)).fetchone()
        if not credential:
            raise LookupError('Station was not found.')
        rows=db.execute('''SELECT r.created_at,r.status,r.input_tokens,r.output_tokens,
                                  r.total_tokens,r.first_text_seconds,r.total_seconds,
                                  r.records_searched,r.evidence_used,r.model
            FROM requests r JOIN station_credentials c ON c.id=r.subject_id
            WHERE c.station_id=? AND r.access_type='station' AND r.created_at>=?
            ORDER BY r.created_at''',(station_id,since)).fetchall()
        month_rows=[row for row in rows if row['created_at'].startswith(month)]
        feedback=db.execute('''SELECT COUNT(*) AS count,AVG(f.rating) AS average
            FROM feedback f JOIN requests r ON r.id=f.request_id
            JOIN station_credentials c ON c.id=r.subject_id
            WHERE c.station_id=? AND f.created_at>=?''',(station_id,since)).fetchone()
        daily=db.execute('''SELECT substr(r.created_at,1,10) AS day,COUNT(*) AS questions,
                                   COALESCE(SUM(r.total_tokens),0) AS tokens
            FROM requests r JOIN station_credentials c ON c.id=r.subject_id
            WHERE c.station_id=? AND r.access_type='station' AND r.created_at>=?
            GROUP BY substr(r.created_at,1,10) ORDER BY day''',(station_id,since)).fetchall()
    used=(credential['question_count'] if credential['usage_month']==month else
          sum(1 for row in month_rows))
    limit=credential['monthly_limit']
    statuses={}
    for row in rows:
        statuses[row['status']]=statuses.get(row['status'],0)+1
    first=[row['first_text_seconds'] for row in rows]
    total=[row['total_seconds'] for row in rows]
    return {
        'station':{
            'station_id':station_id,'station_name':credential['station_name'],
            'plan':credential['plan'],'connected':not bool(credential['revoked_at']),
            'created_at':credential['created_at'],'monthly_limit':limit,
            'used':used,'remaining':max(0,limit-used),'usage_month':month,
        },
        'period_days':days,'questions':len(rows),'statuses':statuses,
        'tokens':{
            'input':sum(row['input_tokens'] or 0 for row in rows),
            'output':sum(row['output_tokens'] or 0 for row in rows),
            'total':sum(row['total_tokens'] or 0 for row in rows),
        },
        'timing':{
            'average_first_text_seconds':round(sum(x for x in first if x is not None)/max(1,sum(x is not None for x in first)),2) if any(x is not None for x in first) else None,
            'p95_first_text_seconds':round(_percentile(first,.95),2) if _percentile(first,.95) is not None else None,
            'average_total_seconds':round(sum(x for x in total if x is not None)/max(1,sum(x is not None for x in total)),2) if any(x is not None for x in total) else None,
            'p95_total_seconds':round(_percentile(total,.95),2) if _percentile(total,.95) is not None else None,
        },
        'feedback':{
            'count':feedback['count'],'average_rating':round(feedback['average'],2) if feedback['average'] is not None else None,
        },
        'daily':[dict(row) for row in daily],
        'models':sorted({row['model'] for row in rows if row['model']}),
    }


def master_overview(path,days=30):
    if not 1<=days<=366:
        raise ValueError('Invalid reporting period.')
    since=iso(utc_now()-timedelta(days=days))
    with sqlite3.connect(path) as db:
        db.row_factory=sqlite3.Row
        totals=db.execute('''SELECT COUNT(*) AS questions,
            COALESCE(SUM(input_tokens),0) AS input_tokens,
            COALESCE(SUM(output_tokens),0) AS output_tokens,
            COALESCE(SUM(total_tokens),0) AS total_tokens,
            AVG(first_text_seconds) AS average_first,AVG(total_seconds) AS average_total
            FROM requests WHERE created_at>=?''',(since,)).fetchone()
        by_access=db.execute('''SELECT access_type,COUNT(*) AS questions,
            COALESCE(SUM(total_tokens),0) AS tokens FROM requests
            WHERE created_at>=? GROUP BY access_type ORDER BY access_type''',(since,)).fetchall()
        by_status=db.execute('''SELECT status,COUNT(*) AS count FROM requests
            WHERE created_at>=? GROUP BY status ORDER BY status''',(since,)).fetchall()
        station_ids=[row[0] for row in db.execute(
            'SELECT DISTINCT station_id FROM station_credentials ORDER BY station_id')]
        pending=db.execute("SELECT COUNT(*) FROM pairing_requests WHERE status='pending'").fetchone()[0]
        feedback=db.execute('SELECT COUNT(*),AVG(rating) FROM feedback WHERE created_at>=?',
                            (since,)).fetchone()
    return {
        'period_days':days,
        'totals':{
            'questions':totals['questions'],'input_tokens':totals['input_tokens'],
            'output_tokens':totals['output_tokens'],'total_tokens':totals['total_tokens'],
            'average_first_text_seconds':round(totals['average_first'],2) if totals['average_first'] is not None else None,
            'average_total_seconds':round(totals['average_total'],2) if totals['average_total'] is not None else None,
        },
        'by_access':[dict(row) for row in by_access],
        'by_status':[dict(row) for row in by_status],
        'pending_pairings':pending,
        'feedback':{'count':feedback[0],'average_rating':round(feedback[1],2) if feedback[1] is not None else None},
        'stations':[station_statistics(path,station_id,days)['station'] for station_id in station_ids],
    }


def feedback_rows(path,limit=100):
    if not 1<=limit<=500:
        raise ValueError('Invalid feedback limit.')
    with sqlite3.connect(path) as db:
        db.row_factory=sqlite3.Row
        rows=db.execute('''SELECT f.id,f.created_at,f.rating,f.comment,f.question,f.answer,
            f.access_type,COALESCE(c.station_id,'') AS station_id,
            COALESCE(c.station_name,'') AS station_name
            FROM feedback f JOIN requests r ON r.id=f.request_id
            LEFT JOIN station_credentials c ON c.id=r.subject_id
            ORDER BY f.created_at DESC LIMIT ?''',(limit,)).fetchall()
    return [dict(row) for row in rows]


def update_station_limit(path,credential_id,monthly_limit):
    if not 1<=monthly_limit<=100000:
        raise ValueError('Monthly allowance must be between 1 and 100000.')
    with sqlite3.connect(path) as db:
        cursor=db.execute("UPDATE station_credentials SET monthly_limit=? WHERE id=? AND revoked_at=''",
                          (monthly_limit,credential_id))
        return cursor.rowcount


def audit_action(path,administrator,action,target_type,target_id,detail=None):
    entry_id=str(uuid.uuid4())
    with sqlite3.connect(path) as db:
        db.execute('''INSERT INTO admin_audit
            (id,created_at,administrator,action,target_type,target_id,detail)
            VALUES(?,?,?,?,?,?,?)''',(
            entry_id,iso(utc_now()),administrator,action,target_type,target_id,
            json.dumps(detail or {},ensure_ascii=False,sort_keys=True)))
    return entry_id


def audit_rows(path,limit=200):
    if not 1<=limit<=1000:
        raise ValueError('Invalid audit limit.')
    with sqlite3.connect(path) as db:
        db.row_factory=sqlite3.Row
        rows=db.execute('''SELECT id,created_at,administrator,action,target_type,target_id,detail
            FROM admin_audit ORDER BY created_at DESC LIMIT ?''',(limit,)).fetchall()
    result=[]
    for row in rows:
        item=dict(row)
        try:
            item['detail']=json.loads(item['detail'])
        except ValueError:
            pass
        result.append(item)
    return result
