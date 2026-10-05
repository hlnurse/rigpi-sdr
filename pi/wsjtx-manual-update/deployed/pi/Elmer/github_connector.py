#!/usr/bin/env python3
"""Cached GitHub synchronization and normalization for Elmer."""

import fnmatch
import hashlib
import json
import os
import re
import shutil
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path


DEFAULT_EXTENSIONS={
    '.c','.cc','.cpp','.h','.hpp','.py','.pl','.pm','.sh','.md','.markdown',
    '.rst','.adoc','.asciidoc','.txt','.html','.htm','.xml','.json','.yaml',
    '.yml','.in','.am','.ac',
}
DEFAULT_NAMES={'readme','copying','license','news','authors','changelog','makefile'}


def run_git(args,cwd=None):
    result=subprocess.run(['git',*args],cwd=cwd,text=True,capture_output=True)
    if result.returncode:
        raise RuntimeError(result.stderr.strip() or result.stdout.strip() or 'git command failed')
    return result.stdout.strip()


def api_headers(options):
    headers={'Accept':'application/vnd.github+json','User-Agent':'RigPi-Elmer-Builder'}
    token=os.environ.get(options.get('token_environment_variable','GITHUB_TOKEN'),'').strip()
    if token: headers['Authorization']=f'Bearer {token}'
    return headers


def fetch_json_pages(url,headers):
    rows=[]; page=1
    while True:
        separator='&' if '?' in url else '?'
        request=urllib.request.Request(f'{url}{separator}per_page=100&page={page}',headers=headers)
        try:
            with urllib.request.urlopen(request,timeout=60) as response:
                batch=json.load(response)
        except urllib.error.HTTPError as exc:
            remaining=exc.headers.get('X-RateLimit-Remaining','')
            reset=exc.headers.get('X-RateLimit-Reset','')
            detail=f'GitHub API returned {exc.code} for {request.full_url}'
            if remaining=='0': detail+=f'; rate limit resets at Unix time {reset}'
            raise RuntimeError(detail) from exc
        if not isinstance(batch,list):
            raise RuntimeError(f'GitHub API did not return a list for {url}')
        rows.extend(batch)
        if len(batch)<100: break
        page+=1
    return rows


def fetch_json_windowed(url,headers):
    """Fetch collections beyond GitHub's 1,000-record pagination window."""
    rows=[]; seen=set(); since=''
    while True:
        window=[]
        cursor=urllib.parse.quote(since) if since else ''
        window_url=url+('&' if '?' in url else '?')+'sort=updated&direction=asc'
        if cursor: window_url+=f'&since={cursor}'
        for page in range(1,11):
            request=urllib.request.Request(f'{window_url}&per_page=100&page={page}',headers=headers)
            try:
                with urllib.request.urlopen(request,timeout=60) as response: batch=json.load(response)
            except urllib.error.HTTPError as exc:
                raise RuntimeError(f'GitHub API returned {exc.code} for {request.full_url}') from exc
            if not isinstance(batch,list): raise RuntimeError(f'GitHub API did not return a list for {url}')
            window.extend(batch)
            if len(batch)<100: break
        added=0
        for item in window:
            key=item.get('id') or item.get('node_id') or item.get('url')
            if key in seen: continue
            seen.add(key); rows.append(item); added+=1
        if len(window)<1000: break
        next_since=max((str(x.get('updated_at') or x.get('created_at') or '') for x in window),default='')
        if not next_since or next_since==since or added==0:
            raise RuntimeError(f'Unable to advance GitHub pagination window for {url}')
        since=next_since
    return rows


def sync_repository(cache_dir,connector):
    options=connector.get('options',{})
    owner=str(options.get('owner','')).strip(); repo=str(options.get('repository','')).strip()
    if not owner or not repo: raise ValueError(f"{connector.get('id')}: owner and repository are required")
    cache_dir=Path(cache_dir); cache_dir.parent.mkdir(parents=True,exist_ok=True)
    staging=cache_dir.with_name(cache_dir.name+'.staging')
    if staging.exists(): shutil.rmtree(staging)
    staging.mkdir(parents=True)
    repository=staging/'repository'
    clone_url=f'https://github.com/{owner}/{repo}.git'
    run_git(['clone','--depth','1',clone_url,str(repository)])
    commit=run_git(['rev-parse','HEAD'],repository)
    branch=run_git(['rev-parse','--abbrev-ref','HEAD'],repository)
    api_base=f'https://api.github.com/repos/{owner}/{repo}'
    headers=api_headers(options)
    releases=fetch_json_pages(api_base+'/releases',headers) if options.get('include_releases',True) else []
    issues=fetch_json_windowed(api_base+'/issues?state=all',headers) if options.get('include_issues',True) else []
    if not options.get('include_pull_requests',False): issues=[x for x in issues if 'pull_request' not in x]
    comments=fetch_json_windowed(api_base+'/issues/comments',headers) if options.get('include_issue_comments',True) else []
    (staging/'releases.json').write_text(json.dumps(releases,ensure_ascii=False),encoding='utf-8')
    (staging/'issues.json').write_text(json.dumps(issues,ensure_ascii=False),encoding='utf-8')
    (staging/'comments.json').write_text(json.dumps(comments,ensure_ascii=False),encoding='utf-8')
    wiki_synced=False
    if options.get('include_wiki',True):
        wiki_url=f'https://github.com/{owner}/{repo}.wiki.git'
        wiki=subprocess.run(['git','clone','--depth','1',wiki_url,str(staging/'wiki')],text=True,capture_output=True)
        wiki_synced=wiki.returncode==0
        if not wiki_synced and (staging/'wiki').exists(): shutil.rmtree(staging/'wiki')
    manifest={
        'connector_id':connector['id'],'owner':owner,'repository':repo,
        'default_branch':branch,'commit':commit,'synced_at':datetime.now(timezone.utc).isoformat(),
        'releases':len(releases),'issues':len(issues),'issue_comments':len(comments),
        'wiki_synced':wiki_synced,
    }
    (staging/'manifest.json').write_text(json.dumps(manifest,indent=2),encoding='utf-8')
    if cache_dir.exists(): shutil.rmtree(cache_dir)
    staging.rename(cache_dir)
    return manifest


def stable_id(prefix,value):
    digest=hashlib.sha256(value.encode('utf-8','replace')).hexdigest()[:16].upper()
    return f'{prefix}-{digest}'


def local_ref(source_id,sid):
    return 'elmer://source/{}/document/{}'.format(
        urllib.parse.quote(source_id,safe=''),urllib.parse.quote(sid,safe=''))


def file_allowed(path,options):
    rel=path.as_posix()
    excludes=options.get('exclude_patterns',['.git/*','autom4te.cache/*','build/*','.github/workflows/*'])
    if any(fnmatch.fnmatch(rel,p) for p in excludes): return False
    extensions={str(x).lower() for x in options.get('source_extensions',DEFAULT_EXTENSIONS)}
    name=path.name.lower()
    return path.suffix.lower() in extensions or name in DEFAULT_NAMES or any(name.startswith(x+'.') for x in DEFAULT_NAMES)


def read_text(path,max_bytes):
    if path.stat().st_size>max_bytes: return None
    data=path.read_bytes()
    if b'\x00' in data: return None
    return data.decode('utf-8',errors='replace')


def load_cached_repository(cache_dir,connector):
    cache_dir=Path(cache_dir); manifest_path=cache_dir/'manifest.json'
    if not manifest_path.exists():
        raise FileNotFoundError(f"GitHub cache missing for {connector['id']}; run sync_github.py first")
    manifest=json.loads(manifest_path.read_text(encoding='utf-8'))
    options=connector.get('options',{}); owner=manifest['owner']; repo=manifest['repository']; commit=manifest['commit']
    source_id=connector['id']; authority=int(connector.get('authority',80)); documents=[]
    max_bytes=int(options.get('maximum_file_bytes',524288))
    root=cache_dir/'repository'
    if options.get('include_source',True):
        for path in sorted(root.rglob('*')):
            if not path.is_file(): continue
            rel=path.relative_to(root)
            if not file_allowed(rel,options): continue
            text=read_text(path,max_bytes)
            if text is None or not text.strip(): continue
            rel_text=rel.as_posix()
            manual_patterns=options.get('manual_patterns',[])
            is_manual=any(fnmatch.fnmatch(rel_text,pattern)
                          for pattern in manual_patterns)
            source_type='github_manual' if is_manual else 'github_file'
            document_authority=(int(options.get('manual_authority',authority))
                                if is_manual else authority)
            sid=stable_id(source_id.upper()+'-FILE',rel_text)
            url=f"https://github.com/{owner}/{repo}/blob/{commit}/{urllib.parse.quote(rel.as_posix(),safe='/')}"
            title=(f'WSJT-X User Guide: {rel.stem.replace("_"," ")}'
                   if is_manual else f'{repo} source: {rel_text}')
            documents.append({'stable_id':sid,'title':title,'path':rel_text,'source_type':source_type,'authority':document_authority,'words':len(re.findall(r"\b[\w'-]+\b",text)),'author':'WSJT Development Group' if is_manual else '','published_at':manifest.get('synced_at','') if is_manual else '','thread_id':'','source_url':url,'local_ref':local_ref(source_id,sid),'body':text})
    if options.get('include_wiki',True) and (cache_dir/'wiki').exists():
        for path in sorted((cache_dir/'wiki').rglob('*.md')):
            rel=path.relative_to(cache_dir/'wiki'); text=read_text(path,max_bytes)
            if not text: continue
            sid=stable_id(source_id.upper()+'-WIKI',rel.as_posix())
            page=rel.with_suffix('').as_posix().replace(' ','-')
            url=f'https://github.com/{owner}/{repo}/wiki/{urllib.parse.quote(page,safe="/")}'
            documents.append({'stable_id':sid,'title':f'{repo} wiki: {rel.stem}','path':'wiki/'+rel.as_posix(),'source_type':'github_wiki','authority':authority,'words':len(text.split()),'author':'','published_at':'','thread_id':'','source_url':url,'local_ref':local_ref(source_id,sid),'body':text})
    for release in json.loads((cache_dir/'releases.json').read_text(encoding='utf-8')):
        body='\n'.join(x for x in [release.get('name') or release.get('tag_name',''),release.get('body','')] if x)
        sid=f"{source_id.upper()}-RELEASE-{release.get('id')}"
        documents.append({'stable_id':sid,'title':release.get('name') or f"Release {release.get('tag_name','')}",'path':f"release:{release.get('tag_name','')}",'source_type':'github_release','authority':authority,'words':len(body.split()),'author':(release.get('author') or {}).get('login',''),'published_at':release.get('published_at',''),'thread_id':'','source_url':release.get('html_url',''),'local_ref':local_ref(source_id,sid),'body':body})
    issues=json.loads((cache_dir/'issues.json').read_text(encoding='utf-8'))
    issue_titles={int(x.get('number',0)):x.get('title','') for x in issues}
    for issue in issues:
        number=int(issue.get('number',0)); labels=', '.join(x.get('name','') for x in issue.get('labels',[]))
        body=f"Issue #{number}: {issue.get('title','')}\nState: {issue.get('state','')}\nLabels: {labels}\n\n{issue.get('body') or ''}"
        sid=f'{source_id.upper()}-ISSUE-{number}'
        documents.append({'stable_id':sid,'title':f"Issue #{number}: {issue.get('title','')}",'path':f'issue:{number}','source_type':'github_issue','authority':authority,'words':len(body.split()),'author':(issue.get('user') or {}).get('login',''),'published_at':issue.get('created_at',''),'thread_id':f'{source_id}-issue-{number}','source_url':issue.get('html_url',''),'local_ref':local_ref(source_id,sid),'body':body})
    for comment in json.loads((cache_dir/'comments.json').read_text(encoding='utf-8')):
        match=re.search(r'/issues/(\d+)$',comment.get('issue_url','')); number=int(match.group(1)) if match else 0
        if number not in issue_titles: continue
        body=comment.get('body') or ''; sid=f"{source_id.upper()}-COMMENT-{comment.get('id')}"
        documents.append({'stable_id':sid,'title':f"Issue #{number} comment: {issue_titles[number]}",'path':f'issue:{number}#issuecomment-{comment.get("id")}','source_type':'github_issue_comment','authority':authority,'words':len(body.split()),'author':(comment.get('user') or {}).get('login',''),'published_at':comment.get('created_at',''),'thread_id':f'{source_id}-issue-{number}','source_url':comment.get('html_url',''),'local_ref':local_ref(source_id,sid),'body':body})
    counts={}
    for d in documents: counts[d['source_type']]=counts.get(d['source_type'],0)+1
    return {'source_id':source_id,'name':connector.get('name',source_id),'source_type':'github_repository','authority':authority,'path':str(cache_dir),'manifest':manifest,'documents':documents,'counts':counts}
