#!/usr/bin/env python3
import argparse,json,shutil
from pathlib import Path

p=argparse.ArgumentParser(); p.add_argument('--connectors',default='config/connectors.json'); a=p.parse_args()
path=Path(a.connectors); data=json.loads(path.read_text()); connectors=data.get('connectors',[])
backup=path.with_name(path.name+'.before-sigidwiki')
if not backup.exists(): shutil.copy2(path,backup)
item=next((x for x in connectors if x.get('id')=='sigidwiki-amateur-radio'),None)
if item is None: item={}; connectors.append(item)
item.update({'id':'sigidwiki-amateur-radio','name':'SIGIDWiki Amateur Radio Signals',
 'type':'mediawiki_category','enabled':True,'authority':72,
 'category':'community-reference','tags':['signals','frequencies','digital-modes','community'],
 'refresh':'weekly','options':{'api_url':'https://www.sigidwiki.com/api.php',
 'category':'Amateur Radio','maximum_pages':500,
 'terms_url':'https://www.sigidwiki.com/wiki/Signal_Identification_Wiki:General_disclaimer'}})
path.write_text(json.dumps(data,indent=2)+'\n'); print('Enabled SIGIDWiki connector; backup:',backup)
