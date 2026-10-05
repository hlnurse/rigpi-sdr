#!/usr/bin/env python3
import json,sqlite3,subprocess,sys,tempfile
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]; sys.path.insert(0,str(ROOT))
from question_knowledge import make_packet

with tempfile.TemporaryDirectory() as temporary:
    folder=Path(temporary); cache=folder/'cache/connectors/sigidwiki-amateur-radio'
    cache.mkdir(parents=True)
    pages=[
      {'pageid':1,'title':'FST4','fullurl':'https://www.sigidwiki.com/wiki/FST4',
       'revid':10,'timestamp':'2025-01-01T00:00:00Z','content':
       "'''FST4''' is a weak-signal QSO mode. == Frequencies == * 137 kHz * 474 kHz"},
      {'pageid':2,'title':'FST4W','fullurl':'https://www.sigidwiki.com/wiki/FST4W',
       'revid':11,'timestamp':'2025-01-02T00:00:00Z','content':
       "'''FST4W''' is a WSPR-style beacon mode. == Frequencies == * 136 kHz * 474.2 kHz * 1.8366 MHz * 14.0971 MHz"},
    ]
    (cache/'pages.json').write_text(json.dumps(pages))
    (cache/'manifest.json').write_text(json.dumps({'connector_id':'sigidwiki-amateur-radio','pages':2}))
    config={'project':{'product_name':'Test','help_name':'Elmer','builder_name':'Test'},
      'paths':{'output_directory':'output','database_name':'knowledge.db','connector_cache_directory':'cache/connectors'},
      'connectors':[{'id':'sigidwiki-amateur-radio','name':'SIGIDWiki Amateur Radio Signals',
        'type':'mediawiki_category','enabled':True,'authority':72,'options':{}}],
      'report':{'generate_html':False,'generate_json':False,'generate_sqlite':True,'generate_build_report':False},
      'analysis':{'legacy_terms':[]}}
    config_path=folder/'config.json'; config_path.write_text(json.dumps(config))
    subprocess.run([sys.executable,str(ROOT/'build_knowledge.py'),'--config',str(config_path)],check=True,capture_output=True,text=True)
    db=sqlite3.connect(folder/'output/knowledge.db')
    packet=make_packet(db,'what freqs to use for fst4?',limit=3,max_per_source=3)
    assert packet['evidence'] and packet['evidence'][0]['source_type']=='signal_wiki',packet
    assert '137 kHz' in packet['evidence'][0]['excerpt'] and '474 kHz' in packet['evidence'][0]['excerpt']
    packet=make_packet(db,'what frequencies for fts4w?',limit=3,max_per_source=3)
    assert packet['evidence'] and '14.0971 MHz' in packet['evidence'][0]['excerpt'],packet
    assert 'oldid=11' in packet['evidence'][0]['external_reference']
    db.close()
print('SIGIDWiki ingestion regression test passed')
