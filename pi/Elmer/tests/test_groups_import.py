#!/usr/bin/env python3
import json, mailbox, sqlite3, subprocess, tempfile
from email.message import EmailMessage
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as td:
    td=Path(td)
    (td/'sources').mkdir(); (td/'output').mkdir()
    (td/'sources'/'Help.zip').write_bytes((ROOT/'sources'/'Help.sample.zip').read_bytes())
    (td/'sources'/'messages.mbox').write_bytes((ROOT/'sources'/'messages.sample.mbox').read_bytes())
    box=mailbox.mbox(td/'sources'/'messages.mbox')
    multipart=EmailMessage()
    multipart['From']='Tester <tester@example.com>'
    multipart['Date']='Wed, 30 Jul 2026 12:00:00 +0000'
    multipart['Subject']='Multipart alternative test'
    multipart['Message-ID']='<multipart-test@example.com>'
    multipart['X-Groupsio-MsgNum']='4242'
    multipart.set_content('This multipart unique phrase must appear exactly once in the indexed body.')
    multipart.add_alternative('<p>This multipart unique phrase must appear exactly once in the indexed body.</p>',subtype='html')
    box.add(multipart); box.flush(); box.close()
    cfg={
      'project':{'product_name':'Test','help_name':'Helper','builder_name':'Test Builder','report_title':'Test QA','version':'0.18'},
      'messages':{'report_closing':'','console_closing':'','build_complete':''},
      'paths':{'output_directory':'output','database_name':'knowledge.db'},
      'sources':[
        {'id':'help','type':'help_zip','path':'sources/Help.zip','enabled':True,'authority':100},
        {'id':'forum','type':'groups_io_mbox','path':'sources/messages.mbox','enabled':True,'authority':60,'group':'RigPi','base_url':'https://groups.io/g/RigPi','options':{'strip_quoted_replies':True,'strip_signatures':True,'strip_groups_footer':True,'deduplicate_messages':True,'minimum_message_words':5}}
      ],
      'help':{'home_page':'RigPi.html','minimum_page_words':10},
      'link_check':{'ignored_patterns':['https://<*'],'review_patterns':[]},
      'analysis':{'legacy_terms':[]},
      'report':{'generate_html':True,'generate_json':True,'generate_sqlite':True,'generate_build_report':True}
    }
    (td/'config.json').write_text(json.dumps(cfg))
    subprocess.run(['python3',str(ROOT/'build_knowledge.py'),'--config',str(td/'config.json')],check=True,capture_output=True,text=True)
    report=json.loads((td/'output'/'documentation-analysis.json').read_text())
    assert report['groups_io'][0]['summary']['messages']==4
    assert report['groups_io'][0]['summary']['threads']==3
    db=sqlite3.connect(td/'output'/'knowledge.db')
    assert db.execute('select count(*) from messages').fetchone()[0]==4
    assert db.execute('select count(*) from threads').fetchone()[0]==3
    assert db.execute("select count(*) from chunks where chunks match 'RX888'").fetchone()[0]>=1
    body=db.execute("select body from messages where stable_id like '%MSG-0002'").fetchone()[0]
    assert 'Older quoted text' not in body
    multipart_body=db.execute("select body from messages where subject='Multipart alternative test'").fetchone()[0]
    assert multipart_body.count('multipart unique phrase')==1
    msgnum,source_url,local_ref=db.execute("select groupsio_msgnum,source_url,local_ref from messages where subject='Multipart alternative test'").fetchone()
    assert msgnum=='4242'
    assert source_url=='https://groups.io/g/RigPi/message/4242'
    assert local_ref.startswith('elmer://source/forum/thread/') and '/message/' in local_ref
    no_number=db.execute("select source_url,local_ref from messages where subject!='Multipart alternative test' limit 1").fetchone()
    assert no_number[0]=='' and no_number[1].startswith('elmer://')
print('Groups.io importer test passed')
