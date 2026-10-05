#!/usr/bin/env python3
"""Cache and section an official versioned HTML manual for Elmer."""

import hashlib
import json
import re
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from html.parser import HTMLParser
from pathlib import Path


class ManualParser(HTMLParser):
    SKIP={'script','style','noscript','template','svg'}
    HEADINGS={'h1','h2','h3','h4'}

    def __init__(self):
        super().__init__()
        self.skip_depth=0
        self.in_heading=False
        self.heading=[]
        self.title=''
        self.anchor=''
        self.body=[]
        self.sections=[]

    def _flush(self):
        text=re.sub(r'\n{3,}','\n\n',''.join(self.body)).strip()
        title=' '.join(''.join(self.heading).split()) or self.title
        if title and text and len(text.split())>=8:
            self.sections.append({'title':title,'anchor':self.anchor,'body':text})
        self.body=[]

    def handle_starttag(self,tag,attrs):
        tag=tag.lower()
        if tag in self.SKIP:
            self.skip_depth+=1
            return
        if self.skip_depth:
            return
        attributes=dict(attrs)
        if tag in self.HEADINGS:
            self._flush()
            self.in_heading=True
            self.heading=[]
            self.anchor=str(attributes.get('id','')).strip()
        elif tag in ('p','div','section','tr','ul','ol'):
            self.body.append('\n')
        elif tag=='li':
            self.body.append('\n- ')
        elif tag in ('td','th'):
            self.body.append(' | ')
        elif tag=='br':
            self.body.append('\n')

    def handle_endtag(self,tag):
        tag=tag.lower()
        if tag in self.SKIP:
            self.skip_depth=max(0,self.skip_depth-1)
            return
        if self.skip_depth:
            return
        if tag in self.HEADINGS:
            self.in_heading=False
            self.title=' '.join(''.join(self.heading).split())
        elif tag in ('p','div','section','tr','li'):
            self.body.append('\n')

    def handle_data(self,data):
        if self.skip_depth:
            return
        text=' '.join(data.split())
        if not text:
            return
        if self.in_heading:
            self.heading.append((' ' if self.heading else '')+text)
        else:
            if self.body and not self.body[-1].endswith((' ','\n','| ')):
                self.body.append(' ')
            self.body.append(text)

    def close(self):
        super().close()
        self._flush()


def _stable_id(source_id,value):
    digest=hashlib.sha256(value.encode('utf-8','replace')).hexdigest()[:16].upper()
    return f'{source_id.upper()}-{digest}'


def _local_ref(source_id,stable_id):
    return 'elmer://source/{}/document/{}'.format(
        urllib.parse.quote(source_id,safe=''),
        urllib.parse.quote(stable_id,safe=''),
    )


def sync_manual(cache_dir,connector):
    options=connector.get('options',{})
    url=str(options.get('url','')).strip()
    version=str(options.get('version','')).strip()
    if not url or not version:
        raise ValueError(f"{connector.get('id')}: url and version are required")
    request=urllib.request.Request(url,headers={
        'User-Agent':'RigPi-Elmer-Builder',
        'Accept':'text/html,application/xhtml+xml',
    })
    maximum=int(options.get('maximum_bytes',12*1024*1024))
    with urllib.request.urlopen(request,timeout=90) as response:
        data=response.read(maximum+1)
    if len(data)>maximum:
        raise RuntimeError(f'{connector.get("id")}: manual exceeds maximum_bytes')
    text=data.decode('utf-8',errors='replace')
    parser=ManualParser(); parser.feed(text); parser.close()
    if len(parser.sections)<10:
        raise RuntimeError(
            f'{connector.get("id")}: expected a complete manual, found only '
            f'{len(parser.sections)} sections'
        )
    cache_dir=Path(cache_dir); cache_dir.mkdir(parents=True,exist_ok=True)
    (cache_dir/'manual.html').write_text(text,encoding='utf-8')
    manifest={
        'connector_id':connector['id'],'url':url,'version':version,
        'retrieved_at':datetime.now(timezone.utc).isoformat(),
        'sha256':hashlib.sha256(data).hexdigest(),'sections':len(parser.sections),
    }
    (cache_dir/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n',encoding='utf-8')
    return manifest


def load_cached_manual(cache_dir,connector):
    cache_dir=Path(cache_dir)
    manifest_path=cache_dir/'manifest.json'
    html_path=cache_dir/'manual.html'
    if not manifest_path.exists() or not html_path.exists():
        raise FileNotFoundError(
            f"HTML manual cache missing for {connector['id']}; run sync_manual.py first"
        )
    manifest=json.loads(manifest_path.read_text(encoding='utf-8'))
    parser=ManualParser(); parser.feed(html_path.read_text(encoding='utf-8')); parser.close()
    source_id=connector['id']; authority=int(connector.get('authority',100))
    version=str(manifest.get('version','')).strip()
    base_url=str(manifest.get('url','')).strip()
    documents=[]; seen={}
    for index,section in enumerate(parser.sections,1):
        anchor=section['anchor'] or f'section-{index}'
        occurrence=seen.get(anchor,0)+1; seen[anchor]=occurrence
        identity=anchor if occurrence==1 else f'{anchor}-{occurrence}'
        stable_id=_stable_id(source_id,version+':'+identity)
        source_url=base_url+('#'+urllib.parse.quote(anchor,safe='_-')
                             if section['anchor'] else '')
        documents.append({
            'stable_id':stable_id,
            'title':f"WSJT-X {version} User Guide: {section['title']}",
            'path':f'manual:{version}#{identity}',
            'source_type':'wsjtx_manual','authority':authority,
            'words':len(re.findall(r"\b[\w'-]+\b",section['body'])),
            'author':'WSJT Development Group',
            'published_at':manifest.get('retrieved_at',''),'thread_id':'',
            'source_url':source_url,
            'local_ref':_local_ref(source_id,stable_id),'body':section['body'],
        })
    return {
        'source_id':source_id,'name':connector.get('name',source_id),
        'source_type':'html_manual','authority':authority,'path':str(cache_dir),
        'manifest':manifest,'documents':documents,
        'counts':{'wsjtx_manual':len(documents)},
    }
