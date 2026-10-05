#!/usr/bin/env python3
import sqlite3
import sys
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
from question_knowledge import make_packet

with tempfile.TemporaryDirectory() as td:
    db=sqlite3.connect(Path(td)/'knowledge.db')
    db.executescript('''
      CREATE TABLE documents(id INTEGER PRIMARY KEY,stable_id TEXT UNIQUE,title TEXT,path TEXT,source_id TEXT,source_type TEXT,authority INTEGER,word_count INTEGER,quality_score INTEGER,author TEXT,published_at TEXT,thread_id TEXT,source_url TEXT,local_ref TEXT);
      CREATE VIRTUAL TABLE chunks USING fts5(stable_id,title,path,content,source_id,source_type,author,thread_id,source_url UNINDEXED,local_ref UNINDEXED);
    ''')
    docs=[
      ('HELP-1','IC-7300 setup','radios/ic7300.html','official-help','help',100,'','https://help/ic7300','elmer://source/official-help/document/HELP-1','Configure IC-7300 CAT control and USB audio codec for remote operation.'),
      ('FILE-1','IC-7300 field notes','ICOM/notes.pdf','groups-files','groups_io_file',70,'','https://groups/files/notes','elmer://source/groups-files/document/FILE-1','Community notes for IC-7300 remote audio and CAT settings.'),
      ('MSG-1','Re: IC-7300','thread:IC-7300','groups-messages','groups_io_message',60,'THREAD-1','','elmer://source/groups-messages/thread/THREAD-1/message/MSG-1','A user reports their IC-7300 remote audio settings worked.'),
      ('HAM-1','icom backend','rigs/icom/icom.c','hamlib-github','github_file',90,'','https://github/icom','elmer://source/hamlib-github/document/HAM-1','Hamlib IC-7300 CAT radio control backend details.'),
      ('WSJTX-1','UDP server','Network/MessageClient.cpp','wsjtx-github','github_file',85,'','https://github/wsjtx','elmer://source/wsjtx-github/document/WSJTX-1','Remote audio CAT control network details without a radio model.'),
      ('HELP-CURRENT','RigPi Introduction','introduction.html','official-help','help',100,'','https://help/introduction','elmer://source/official-help/document/HELP-CURRENT','Current RigPi provides full duplex audio streaming directly to the browser, replacing Mumble in earlier versions.'),
      ('HELP-CLOUDFLARE','Other Programs > Cloudflare','cloudflare.html','official-help','help',100,'','https://help/cloudflare','elmer://source/official-help/document/HELP-CLOUDFLARE','Cloudflare Tunnel provides remote access without opening ports. Step-by-Step Setup: install cloudflared, authenticate, create and configure the tunnel, add DNS, and enable its system service.'),
    ]
    for sid,title,path,source,typ,authority,thread,url,local,body in docs:
        db.execute('INSERT INTO documents(stable_id,title,path,source_id,source_type,authority,word_count,quality_score,author,published_at,thread_id,source_url,local_ref) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',(sid,title,path,source,typ,authority,len(body.split()),None,'','',thread,url,local))
        db.execute('INSERT INTO chunks VALUES(?,?,?,?,?,?,?,?,?,?)',(sid,title,path,body,source,typ,'',thread,url,local))
    packet=make_packet(db,'How do I configure an IC-7300 for remote audio and CAT control?',limit=4,max_per_source=2)
    assert packet['evidence_count']==4,packet
    assert packet['evidence'][0]['source']=='official-help',packet['evidence']
    assert packet['evidence'][0]['path']=='introduction.html',packet['evidence']
    assert packet['current_guidance'][0]['id']=='browser-audio-replaces-mumble',packet
    assert len({x['source'] for x in packet['evidence']})>=3,packet['evidence']
    assert packet['required_anchors']==['IC-7300'],packet['required_anchors']
    assert all(x['source']!='wsjtx-github' for x in packet['evidence']),packet['evidence']
    assert 'rigctld' in packet['expanded_concepts'],packet['expanded_concepts']
    assert all(x['local_reference'] for x in packet['evidence'])
    remote=make_packet(db,'How do I use RigPi remote access w/o port forwarding?',limit=5,max_per_source=2)
    cloudflare=next(x for x in remote['evidence'] if x['path']=='cloudflare.html')
    assert cloudflare,remote
    assert any(x['id']=='cloudflare-tunnel-no-port-forwarding' for x in remote['current_guidance']),remote
    assert 'Step-by-Step Setup' in cloudflare['excerpt'],cloudflare
    empty=make_packet(db,'xyzzynotfound',limit=3)
    assert empty['evidence_count']==0
    db.close()

print('Question evidence-packet test passed')
