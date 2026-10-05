#!/usr/bin/env python3
"""Synchronize and normalize a public MediaWiki category for Elmer."""

import hashlib
import json
import re
import urllib.parse
import urllib.request
from datetime import datetime,timezone
from pathlib import Path


def _request(api,parameters):
    url=api+'?'+urllib.parse.urlencode(parameters)
    request=urllib.request.Request(url,headers={
        'User-Agent':'RigPi-Elmer-Builder/1.0 (read-only knowledge sync)',
        'Accept':'application/json',
    })
    with urllib.request.urlopen(request,timeout=60) as response:
        return json.load(response)


def sync_mediawiki_category(cache_dir,connector):
    options=connector.get('options',{})
    api=str(options.get('api_url','')).strip()
    category=str(options.get('category','')).strip()
    if not api or not category:
        raise ValueError(f"{connector.get('id')}: api_url and category are required")
    title=category if category.startswith('Category:') else 'Category:'+category
    members=[]; continuation=''
    while True:
        parameters={'action':'query','list':'categorymembers','cmtitle':title,
                    'cmnamespace':'0','cmlimit':'500','format':'json'}
        if continuation: parameters['cmcontinue']=continuation
        result=_request(api,parameters)
        members.extend(result.get('query',{}).get('categorymembers',[]))
        continuation=result.get('query-continue',{}).get(
            'categorymembers',{}).get('cmcontinue','')
        if not continuation: break
    maximum=int(options.get('maximum_pages',500))
    members=members[:maximum]; pages=[]
    for offset in range(0,len(members),25):
        titles='|'.join(item['title'] for item in members[offset:offset+25])
        result=_request(api,{
            'action':'query','prop':'revisions|info','rvprop':'ids|timestamp|content',
            'inprop':'url','redirects':'1','titles':titles,'format':'json',
        })
        for page in result.get('query',{}).get('pages',{}).values():
            revisions=page.get('revisions') or []
            if page.get('missing') is not None or not revisions: continue
            revision=revisions[0]
            pages.append({
                'pageid':page.get('pageid'),'title':page.get('title',''),
                'fullurl':page.get('fullurl',''),'revid':revision.get('revid'),
                'timestamp':revision.get('timestamp',''),
                'content':revision.get('*',''),
            })
    cache_dir=Path(cache_dir); cache_dir.mkdir(parents=True,exist_ok=True)
    (cache_dir/'pages.json').write_text(
        json.dumps(pages,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    manifest={'connector_id':connector['id'],'api_url':api,'category':title,
              'synced_at':datetime.now(timezone.utc).isoformat(),'pages':len(pages)}
    (cache_dir/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
    return manifest


def _plain_wikitext(text):
    text=re.sub(r'<ref\b[^>]*>.*?</ref>|<ref\b[^>]*/>', ' ',text,flags=re.I|re.S)
    text=re.sub(r'\[\[(?:File|Image):[^\]]+\]\]',' ',text,flags=re.I)
    text=re.sub(r'\[\[[^\]|]+\|([^\]]+)\]\]',r'\1',text)
    text=re.sub(r'\[\[([^\]]+)\]\]',r'\1',text)
    text=re.sub(r'\[(?:https?://\S+)\s+([^\]]+)\]',r'\1',text)
    text=re.sub(r'https?://\S+',' ',text)
    text=re.sub(r"'{2,}",'',text)
    text=re.sub(r'<[^>]+>',' ',text)
    text=text.replace('{{',' ').replace('}}',' ').replace('{|',' ').replace('|}',' ')
    text=re.sub(r'(?m)^\s*[|!+-]+\s*','',text)
    return re.sub(r'\s+',' ',text).strip()


def load_cached_mediawiki_category(cache_dir,connector):
    cache_dir=Path(cache_dir)
    manifest_path=cache_dir/'manifest.json'; pages_path=cache_dir/'pages.json'
    if not manifest_path.exists() or not pages_path.exists():
        raise FileNotFoundError(
            f"MediaWiki cache missing for {connector['id']}; run sync_mediawiki.py first")
    manifest=json.loads(manifest_path.read_text())
    pages=json.loads(pages_path.read_text())
    source_id=connector['id']; authority=int(connector.get('authority',72)); documents=[]
    for page in pages:
        body=_plain_wikitext(page.get('content',''))
        if len(body.split())<8: continue
        revision=str(page.get('revid',''))
        stable_id=(source_id.upper()+'-'+hashlib.sha256(
            str(page.get('pageid',page.get('title',''))).encode()).hexdigest()[:16].upper())
        base=page.get('fullurl','')
        reference=(base+('&' if '?' in base else '?')+'oldid='+revision
                   if base and revision else base)
        documents.append({
            'stable_id':stable_id,'title':'SIGIDWiki: '+page.get('title',''),
            'path':'wiki:'+page.get('title',''),'source_type':'signal_wiki',
            'authority':authority,'words':len(body.split()),
            'author':'SIGIDWiki contributors','published_at':page.get('timestamp',''),
            'thread_id':'','source_url':reference,
            'local_ref':'elmer://source/{}/document/{}'.format(
                urllib.parse.quote(source_id,safe=''),urllib.parse.quote(stable_id,safe='')),
            'body':body,
        })
    return {'source_id':source_id,'name':connector.get('name',source_id),
            'source_type':'mediawiki_category','authority':authority,
            'path':str(cache_dir),'manifest':manifest,'documents':documents,
            'counts':{'signal_wiki':len(documents)}}

