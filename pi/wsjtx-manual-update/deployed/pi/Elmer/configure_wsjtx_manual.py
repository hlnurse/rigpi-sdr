#!/usr/bin/env python3
"""Enable complete WSJT-X User Guide ingestion without replacing connectors."""

import argparse
import json
import shutil
from pathlib import Path


parser=argparse.ArgumentParser()
parser.add_argument('--connectors',default='config/connectors.json')
args=parser.parse_args()
path=Path(args.connectors)
backup=path.with_name(path.name+'.before-wsjtx-manual')
data=json.loads(path.read_text(encoding='utf-8'))
connectors=data.get('connectors',[])
connector=next((item for item in connectors
                if item.get('id')=='wsjtx-github'),None)
if connector is None:
    raise SystemExit('wsjtx-github connector not found')
if not backup.exists():
    shutil.copy2(path,backup)

connector.update({
    'name':'WSJT-X GitHub and User Guide',
    'type':'github_repository',
    'enabled':True,
    'authority':90,
    'category':'official-reference',
    'tags':['github','wsjt-x','digital-modes','user-guide'],
})
options=connector.setdefault('options',{})
extensions=list(options.get('source_extensions',[]))
if not extensions:
    extensions=['.c','.cc','.cpp','.h','.hpp','.f','.f90','.py','.sh','.md',
                '.rst','.txt','.html','.htm','.xml','.json','.yaml','.yml']
for extension in ('.adoc','.asciidoc'):
    if extension not in extensions:
        extensions.append(extension)
options.update({
    'owner':'WSJTX',
    'repository':'wsjtx',
    'include_source':True,
    'source_extensions':extensions,
    'manual_patterns':[
        'doc/user_guide/*.adoc',
        'doc/user_guide/*/*.adoc',
        'doc/common/*.adoc',
        'doc/common/*/*.adoc',
    ],
    'manual_authority':100,
})

manual=next((item for item in connectors if item.get('id')=='wsjtx-manual'),None)
if manual is None:
    manual={}
    connectors.append(manual)
manual.update({
    'id':'wsjtx-manual',
    'name':'Official WSJT-X 3.0.0 User Guide',
    'type':'html_manual',
    'enabled':True,
    'authority':100,
    'category':'official-reference',
    'tags':['wsjt-x','digital-modes','user-guide','official-manual'],
    'refresh':'release',
    'options':{
        'url':'https://github.com/WSJTX/wsjtx/releases/download/v3.0.0/wsjtx-main_en.html',
        'version':'3.0.0',
        'maximum_bytes':12582912,
    },
})
path.write_text(json.dumps(data,indent=2)+'\n',encoding='utf-8')
print('Enabled WSJT-X User Guide ingestion; preserved backup at',backup)
