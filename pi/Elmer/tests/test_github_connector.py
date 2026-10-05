#!/usr/bin/env python3
import json
import sqlite3
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

with tempfile.TemporaryDirectory() as td:
    td=Path(td); (td/'sources').mkdir(); (td/'output').mkdir()
    (td/'sources'/'Help.zip').write_bytes((ROOT/'sources'/'Help.sample.zip').read_bytes())
    cache=td/'cache'/'connectors'/'hamlib-github'; repo=cache/'repository'/'rigs'; repo.mkdir(parents=True)
    (repo/'example.c').write_text('int rigctld_example(void) { return 42; }\n')
    (repo/'binary.bin').write_bytes(b'\x00not knowledge')
    (cache/'manifest.json').write_text(json.dumps({'connector_id':'hamlib-github','owner':'Hamlib','repository':'Hamlib','default_branch':'master','commit':'abc123def456','synced_at':'2026-08-13T00:00:00Z','releases':1,'issues':1,'issue_comments':1,'wiki_synced':False}))
    (cache/'releases.json').write_text(json.dumps([{'id':7,'name':'Hamlib Test Release','tag_name':'9.9','body':'Release mentions rigctld.','html_url':'https://github.com/Hamlib/Hamlib/releases/tag/9.9','published_at':'2026-01-01','author':{'login':'N0NB'}}]))
    (cache/'issues.json').write_text(json.dumps([{'number':12,'title':'Rig model test','body':'Issue body for IC-7300.','state':'open','labels':[{'name':'rig'}],'html_url':'https://github.com/Hamlib/Hamlib/issues/12','created_at':'2026-01-02','user':{'login':'tester'}}]))
    (cache/'comments.json').write_text(json.dumps([{'id':99,'issue_url':'https://api.github.com/repos/Hamlib/Hamlib/issues/12','body':'Comment confirms the backend behavior.','html_url':'https://github.com/Hamlib/Hamlib/issues/12#issuecomment-99','created_at':'2026-01-03','user':{'login':'maintainer'}}]))
    cfg={
      'project':{'product_name':'Test','help_name':'Elmer','builder_name':'Test Builder'},
      'messages':{'report_closing':'','console_closing':'','build_complete':''},
      'paths':{'output_directory':'output','database_name':'knowledge.db','connector_cache_directory':'cache/connectors'},
      'connectors':[
        {'id':'help','type':'help_zip','path':'sources/Help.zip','enabled':True,'authority':100},
        {'id':'hamlib-github','name':'Hamlib GitHub','type':'github_repository','enabled':True,'authority':90,'options':{'owner':'Hamlib','repository':'Hamlib','include_source':True,'include_wiki':True}}
      ],
      'help':{'home_page':'RigPi.html','minimum_page_words':10},
      'analysis':{'legacy_terms':[]},'link_check':{'ignored_patterns':[],'review_patterns':[]},
      'report':{'generate_html':False,'generate_json':True,'generate_sqlite':True,'generate_build_report':True},
    }
    config=td/'config.json'; config.write_text(json.dumps(cfg))
    result=subprocess.run([sys.executable,str(ROOT/'build_knowledge.py'),'--config',str(config)],check=True,capture_output=True,text=True)
    assert 'GitHub Hamlib GitHub: 4 documents at abc123def456' in result.stdout
    db=sqlite3.connect(td/'output'/'knowledge.db')
    counts=dict(db.execute("SELECT source_type,COUNT(*) FROM documents WHERE source_id='hamlib-github' GROUP BY source_type"))
    assert counts=={'github_file':1,'github_issue':1,'github_issue_comment':1,'github_release':1}
    url,local=db.execute("SELECT source_url,local_ref FROM documents WHERE source_type='github_file'").fetchone()
    assert '/blob/abc123def456/rigs/example.c' in url
    assert local.startswith('elmer://source/hamlib-github/document/')
    assert db.execute('SELECT COUNT(*) FROM chunks WHERE chunks MATCH ?',('"IC-7300"',)).fetchone()[0]==1
    db.close()
    search=subprocess.run([sys.executable,str(ROOT/'search_knowledge.py'),'"IC-7300"','--db',str(td/'output'/'knowledge.db')],check=True,capture_output=True,text=True)
    assert '[Hamlib GitHub]' in search.stdout and 'External: https://github.com/Hamlib/Hamlib/issues/12' in search.stdout

print('GitHub connector test passed')
