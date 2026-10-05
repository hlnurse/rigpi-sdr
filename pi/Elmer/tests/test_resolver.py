#!/usr/bin/env python3
import json
import sqlite3
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
REF='elmer://source/groups-messages/thread/THREAD-1/message/THREAD-1-MSG-0002'

with tempfile.TemporaryDirectory() as td:
    db_path=Path(td)/'knowledge.db'
    db=sqlite3.connect(db_path)
    db.execute('CREATE TABLE threads(thread_id TEXT PRIMARY KEY,source_id TEXT,subject TEXT,message_count INTEGER,first_date TEXT,last_date TEXT,authors_json TEXT)')
    db.execute('CREATE TABLE messages(stable_id TEXT PRIMARY KEY,thread_id TEXT,source_id TEXT,message_id TEXT,groupsio_msgnum TEXT,subject TEXT,author TEXT,published_at TEXT,word_count INTEGER,body TEXT,source_url TEXT,local_ref TEXT)')
    db.execute('CREATE TABLE documents(id INTEGER PRIMARY KEY,stable_id TEXT UNIQUE,title TEXT,path TEXT,source_id TEXT,source_type TEXT,authority INTEGER,word_count INTEGER,quality_score INTEGER,author TEXT,published_at TEXT,thread_id TEXT,source_url TEXT,local_ref TEXT)')
    db.execute('CREATE VIRTUAL TABLE chunks USING fts5(stable_id,title,path,content,source_id,source_type,author,thread_id,source_url UNINDEXED,local_ref UNINDEXED)')
    db.execute('INSERT INTO threads VALUES(?,?,?,?,?,?,?)',('THREAD-1','groups-messages','Resolver discussion',4,'2026-01-01','2026-01-04',json.dumps(['Alice','Bob'])))
    for i in range(1,5):
        sid=f'THREAD-1-MSG-{i:04d}'
        local=f'elmer://source/groups-messages/thread/THREAD-1/message/{sid}'
        external='https://groups.io/g/RigPi/message/42' if i==2 else ''
        db.execute('INSERT INTO messages VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',(sid,'THREAD-1','groups-messages',f'<{i}>','42' if i==2 else '',f'Message {i}','Alice' if i%2 else 'Bob',f'2026-01-0{i}',5,f'Complete body for message {i}.',external,local))
    db.commit(); db.close()

    command=[sys.executable,str(ROOT/'resolve_reference.py'),REF,'--db',str(db_path),'--context','1']
    plain=subprocess.run(command,check=True,capture_output=True,text=True)
    assert 'Thread: Resolver discussion' in plain.stdout
    assert plain.stdout.count('--- CONTEXT:')==2
    assert plain.stdout.count('--- SELECTED:')==1
    assert 'Complete body for message 2.' in plain.stdout
    assert 'https://groups.io/g/RigPi/message/42' in plain.stdout
    assert 'message 4' not in plain.stdout

    structured=subprocess.run(command+['--json'],check=True,capture_output=True,text=True)
    data=json.loads(structured.stdout)
    assert data['selected_stable_id']=='THREAD-1-MSG-0002'
    assert len(data['messages'])==3
    assert sum(x['selected'] for x in data['messages'])==1

    invalid=subprocess.run([sys.executable,str(ROOT/'resolve_reference.py'),'not-a-reference','--db',str(db_path)],capture_output=True,text=True)
    assert invalid.returncode==1 and 'Unable to resolve reference' in invalid.stderr

    db=sqlite3.connect(db_path)
    docref='elmer://source/hamlib-github/document/HAMLIB-ISSUE-12'
    db.execute('INSERT INTO documents(stable_id,title,path,source_id,source_type,authority,word_count,quality_score,author,published_at,thread_id,source_url,local_ref) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',('HAMLIB-ISSUE-12','Issue #12','issue:12','hamlib-github','github_issue',90,7,None,'tester','2026-01-01','','https://github.com/Hamlib/Hamlib/issues/12',docref))
    db.execute('INSERT INTO chunks VALUES(?,?,?,?,?,?,?,?,?,?)',('HAMLIB-ISSUE-12','Issue #12','issue:12','Complete GitHub issue body.','hamlib-github','github_issue','tester','','https://github.com/Hamlib/Hamlib/issues/12',docref))
    db.commit(); db.close()
    generic=subprocess.run([sys.executable,str(ROOT/'resolve_reference.py'),docref,'--db',str(db_path)],check=True,capture_output=True,text=True)
    assert 'Document: Issue #12' in generic.stdout and 'Complete GitHub issue body.' in generic.stdout
    assert 'External: https://github.com/Hamlib/Hamlib/issues/12' in generic.stdout

print('Reference resolver test passed')
