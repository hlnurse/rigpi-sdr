#!/usr/bin/env python3
import json
import tempfile
import zipfile
import sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT))
from build_knowledge import import_groups_files

with tempfile.TemporaryDirectory() as td:
    root=Path(td)/'RigPi-2'/'Files'
    (root/'FAQs').mkdir(parents=True)
    text=root/'FAQs'/'Radio.txt'
    text.write_text('RigPi radio connection troubleshooting steps and settings.',encoding='utf-8')
    Path(str(text)+'.json').write_text(json.dumps({'id':42,'object':'file','name':'Radio troubleshooting','desc':'Useful notes','updated':'2026-01-02T03:04:05Z','path':'RigPi/Files/FAQs/Radio.txt'}),encoding='utf-8')
    (root/'Copy.txt').write_bytes(text.read_bytes())
    docx=root/'Setup.docx'
    with zipfile.ZipFile(docx,'w') as archive:
        archive.writestr('word/document.xml','<w:document><w:body><w:p><w:r><w:t>WSJT-X UDP setup guide</w:t></w:r></w:p></w:body></w:document>')
    (root/'Video.mp4').write_bytes(b'not indexed')
    (root/'FAQs.json').write_text(json.dumps({'id':1,'object':'folder','name':'FAQs'}),encoding='utf-8')
    cfg={'id':'groups-files','name':'Files','authority':70,'options':{'base_url':'https://groups.io/g/RigPi','minimum_document_words':3}}
    report=import_groups_files(Path(td)/'RigPi-2',cfg)
    assert report['summary']['documents']==2,report['summary']
    assert report['summary']['duplicates']==1,report['summary']
    assert report['summary']['unsupported']==1,report['summary']
    first=next(x for x in report['documents'] if x['stable_id']=='GROUPS-FILES-FILE-42')
    assert first['title']=='Radio troubleshooting'
    assert first['source_url'].endswith('/files/FAQs/Radio.txt')
    assert first['local_ref'].startswith('elmer://source/groups-files/document/')

print('Groups.io files importer test passed')
