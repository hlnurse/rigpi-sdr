#!/usr/bin/env python3
"""End-to-end regression for complete WSJT-X User Guide retrieval."""

import json
import sqlite3
import subprocess
import sys
import tempfile
from pathlib import Path


ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
from question_knowledge import make_packet


with tempfile.TemporaryDirectory() as temporary:
    folder=Path(temporary)
    cache=folder/'cache/connectors/wsjtx-github'
    guide=cache/'repository/doc/user_guide/en'
    source=cache/'repository/lib'
    guide.mkdir(parents=True)
    source.mkdir(parents=True)
    (guide/'modes.adoc').write_text('''
[[FST4W]]
=== FST4W
FST4W is used for WSPR-like propagation probing. The available T/R sequence
lengths are 120, 300, 900, and 1800 seconds. FST4W-120 transmits for about
109 seconds. FT8 uses synchronized 15-second T/R periods.
''')
    (source/'incidental.f90').write_text(
        'frequency_example = 14095000 ! unrelated implementation constant\n')
    (cache/'manifest.json').write_text(json.dumps({
        'connector_id':'wsjtx-github','owner':'WSJTX','repository':'wsjtx',
        'default_branch':'master','commit':'abc123','synced_at':'2026-08-24T00:00:00Z',
        'releases':0,'issues':0,'issue_comments':0,'wiki_synced':False,
    }))
    for filename in ('releases.json','issues.json','comments.json'):
        (cache/filename).write_text('[]')
    html_cache=folder/'cache/connectors/wsjtx-manual'
    html_cache.mkdir(parents=True)
    (html_cache/'manual.html').write_text('''
<html><body><h1 id="fst4w">FST4W</h1>
<p>FST4W offers synchronized T/R sequence lengths of 120, 300, 900, and
1800 seconds. FST4W-120 has an actual transmit duration of about 109.3
seconds. FT8 instead uses synchronized 15-second periods.</p>
</body></html>
''')
    (html_cache/'manifest.json').write_text(json.dumps({
        'connector_id':'wsjtx-manual','version':'3.0.0',
        'url':'https://example.test/wsjtx-main_en.html',
        'retrieved_at':'2026-08-24T00:00:00Z','sha256':'test','sections':1,
    }))
    config={
        'project':{'product_name':'Test','help_name':'Elmer','builder_name':'Test'},
        'paths':{'output_directory':'output','database_name':'knowledge.db',
                 'connector_cache_directory':'cache/connectors'},
        'connectors':[
            {
                'id':'wsjtx-manual','name':'Official WSJT-X 3.0.0 User Guide',
                'type':'html_manual','enabled':True,'authority':100,
                'options':{'url':'https://example.test/wsjtx-main_en.html',
                           'version':'3.0.0'},
            },
            {
                'id':'wsjtx-github','name':'WSJT-X GitHub and User Guide',
                'type':'github_repository','enabled':True,'authority':90,
                'options':{
                    'owner':'WSJTX','repository':'wsjtx','include_source':True,
                    'include_wiki':False,'source_extensions':['.adoc','.f90'],
                    'manual_patterns':['doc/user_guide/*/*.adoc'],
                    'manual_authority':100,
                },
            },
        ],
        'report':{'generate_html':False,'generate_json':False,
                  'generate_sqlite':True,'generate_build_report':False},
        'analysis':{'legacy_terms':[]},
    }
    config_path=folder/'config.json'
    config_path.write_text(json.dumps(config))
    subprocess.run(
        [sys.executable,str(ROOT/'build_knowledge.py'),'--config',str(config_path)],
        check=True,capture_output=True,text=True,
    )
    db=sqlite3.connect(folder/'output/knowledge.db')
    manual=db.execute('''SELECT source_type,authority,source_url FROM documents
        WHERE path='doc/user_guide/en/modes.adoc' ''').fetchone()
    assert manual[0]=='github_manual',manual
    assert manual[1]==100,manual
    assert '/blob/abc123/doc/user_guide/en/modes.adoc' in manual[2],manual
    packet=make_packet(
        db,'fts4w shows near 14.095 in WSJTX; is its timing the same as FT8?',
        limit=4,max_per_source=4,
    )
    assert packet['evidence'],packet
    assert packet['evidence'][0]['source_type']=='wsjtx_manual',packet['evidence']
    assert '120, 300, 900, and 1800 seconds' in packet['evidence'][0]['excerpt']
    short_packet=make_packet(db,'what is fts4w?',limit=4,max_per_source=4)
    assert short_packet['evidence'],short_packet
    assert short_packet['evidence'][0]['source_type']=='wsjtx_manual',short_packet['evidence']
    assert 'FST4W' in short_packet['evidence'][0]['excerpt']
    db.close()

with tempfile.TemporaryDirectory() as temporary:
    folder=Path(temporary)
    connectors=folder/'connectors.json'
    connectors.write_text(json.dumps({'connectors':[
        {'id':'official-help','type':'help_zip','enabled':True},
        {'id':'wsjtx-github','type':'github_repository','enabled':False,
         'options':{'source_extensions':['.c','.md']}},
    ]}))
    subprocess.run(
        [sys.executable,str(ROOT/'configure_wsjtx_manual.py'),
         '--connectors',str(connectors)],
        check=True,capture_output=True,text=True,
    )
    configured=json.loads(connectors.read_text())['connectors']
    assert configured[0]=={'id':'official-help','type':'help_zip','enabled':True}
    wsjtx=configured[1]
    assert wsjtx['enabled'] is True
    assert '.adoc' in wsjtx['options']['source_extensions']
    assert wsjtx['options']['manual_authority']==100
    assert wsjtx['options']['include_wiki'] is False
    manual_connector=next(item for item in configured
                          if item['id']=='wsjtx-manual')
    assert manual_connector['type']=='html_manual'
    assert manual_connector['options']['version']=='3.0.0'
    assert (folder/'connectors.json.before-wsjtx-manual').exists()

print('WSJT-X User Guide ingestion regression test passed')
