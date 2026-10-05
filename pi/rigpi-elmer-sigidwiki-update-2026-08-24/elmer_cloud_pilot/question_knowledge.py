#!/usr/bin/env python3
"""Turn a natural-language question into a cited Elmer evidence packet."""

import argparse
import json
import re
import sqlite3
import sys
from collections import Counter
from pathlib import Path

from owned_images import image_references


STOP_WORDS={
    'a','an','and','are','as','at','be','can','could','do','does','for','from',
    'how','i','in','is','it','me','my','of','on','or','should','the','this','to',
    'was','what','when','where','which','with','would','you','your',
}

ALIASES={
    'cat':['rigctl','rigctld','radio control'],
    'ptt':['push to talk','transmit control'],
    'wsjt-x':['wsjtx'],
    'wsjtx':['wsjt-x'],
    'fst4w':['fts4w'],
    'fts4w':['fst4w'],
    'lotw':['tqsl','trustedqsl'],
    'audio':['sound card','codec'],
    'remote':['network','lan'],
    'forwarding':['cloudflare tunnel','remote access'],
    'w/o':['without','no'],
    'macro':['macros','rigctl command'],
}

TYPE_WEIGHTS={
    'help':1.40,
    'github_file':1.15,
    'github_manual':1.55,
    'wsjtx_manual':1.75,
    'signal_wiki':1.20,
    'github_release':1.00,
    'groups_io_file':1.05,
    'groups_io_message':0.82,
    'github_issue':0.78,
    'github_issue_comment':0.70,
    'github_wiki':1.05,
}

SOURCE_LABELS={
    'official-help':'Official RigPi Help',
    'groups-messages':'RigPi Groups.io discussions',
    'groups-files':'RigPi Groups.io contributed files',
    'hamlib-github':'Hamlib GitHub',
    'rigpi-github':'RigPi GitHub',
    'wsjtx-github':'WSJT-X GitHub',
    'wsjtx-manual':'Official WSJT-X User Guide',
    'sigidwiki-amateur-radio':'SIGIDWiki Amateur Radio Signals',
}

CURRENT_GUIDANCE_PATH=Path(__file__).resolve().parent/'config'/'current_guidance.json'
HAMLIB_SUPPORT_PATH=Path(__file__).resolve().parent/'config'/'hamlib_support.json'


def hamlib_version_intent(question):
    text=question.casefold()
    return ('hamlib' in text and
            any(term in text for term in ('version','release','support','compatible','download')))


def hamlib_support_records(question):
    """Return curated first-release facts for radio models named in a question."""
    if not hamlib_version_intent(question):
        return []
    try:
        data=json.loads(HAMLIB_SUPPORT_PATH.read_text())
    except (OSError,json.JSONDecodeError):
        return []
    normalized=re.sub(r'[^a-z0-9]','',question.casefold())
    matches=[]
    for record in data.get('models',[]):
        aliases=record.get('aliases',[])
        if any(re.sub(r'[^a-z0-9]','',str(alias).casefold()) in normalized
               for alias in aliases):
            matches.append(record)
    return matches


def full_document(db,where,parameters):
    row=db.execute('''SELECT stable_id,title,path,source_id,source_type,author,
        source_url,local_ref,authority,published_at FROM documents
        WHERE '''+where+' LIMIT 1',parameters).fetchone()
    if not row:
        return None
    sid,title,path,source_id,source_type,author,source_url,local_ref,authority,published_at=row
    content=' '.join(x[0] for x in db.execute(
        'SELECT content FROM chunks WHERE stable_id=? ORDER BY rowid',(sid,)))
    return {
        'stable_id':sid,'title':title,'path':path,'content':content,
        'source_id':source_id,'source_type':source_type,'author':author or '',
        'thread_id':'','source_url':source_url or '','local_ref':local_ref or '',
        'authority':int(authority),'published_at':published_at or '',
        'score':float('inf'),
    }


def hamlib_version_evidence(db,question,source=None):
    if not hamlib_version_intent(question) or (source and source!='hamlib-github'):
        return []
    additions=[]
    for record in hamlib_support_records(question):
        model=record.get('model','radio')
        version=record.get('first_supported_version','')
        additions.append({
            'stable_id':'HAMLIB-SUPPORT-'+re.sub(r'[^A-Z0-9]','',model.upper()),
            'title':f'{model}: first Hamlib release support',
            'path':f'compatibility:{model}',
            'content':record.get('evidence',''),
            'source_id':'hamlib-github','source_type':'github_file','author':'Hamlib',
            'thread_id':'','source_url':record.get('reference',''),'local_ref':'',
            'authority':100,'published_at':record.get('introduced_at',''),
            'focus_terms':[model,version,'first supported release'],'score':float('inf'),
        })
    latest=full_document(db,
        "source_id='hamlib-github' AND source_type='github_release' "
        "ORDER BY published_at DESC",())
    if latest:
        version_match=re.fullmatch(r'release:([0-9][0-9A-Za-z.-]*)',latest['path'])
        if version_match:
            version=version_match.group(1)
            direct_url=(f'https://github.com/Hamlib/Hamlib/releases/download/{version}/'
                        f'hamlib-{version}.tar.gz')
            latest['content']+=(' Official Hamlib GNU Autotools source archive '
                                f'direct download: {direct_url}')
            latest['guidance_excerpt_chars']=1600
        additions.append(latest)
    download=full_document(db,
        "source_id='hamlib-github' AND path='wiki/Download.md'",())
    if download:
        additions.append(download)
    return additions


def apply_hamlib_version_evidence(db,question,selected,limit,source=None):
    additions=hamlib_version_evidence(db,question,source)
    if not additions:
        return selected
    keys={(item['source_id'],item['path']) for item in additions}
    remaining=[item for item in selected if (item['source_id'],item['path']) not in keys]
    return (additions+remaining)[:limit]


def matched_current_guidance(question,selected):
    try:
        data=json.loads(CURRENT_GUIDANCE_PATH.read_text())
    except (OSError,json.JSONDecodeError):
        return []
    question_text=question.casefold()
    evidence_text=' '.join([
        ' '.join((item.get('title',''),item.get('path',''),item.get('content','')))
        for item in selected
    ]).casefold()
    direct=[]
    evidence_fallback=[]
    for rule in data.get('rules',[]):
        question_terms=rule.get('question_terms',rule.get('match_terms',[]))
        evidence_terms=rule.get('evidence_terms',rule.get('match_terms',[]))
        if any(str(term).casefold() in question_text for term in question_terms):
            direct.append(rule)
        elif any(str(term).casefold() in evidence_text for term in evidence_terms):
            evidence_fallback.append(rule)
    # Explicit question intent is more precise than incidental words found in
    # a long Help topic. Use evidence matching only when no rule directly
    # matches the question.
    return direct or evidence_fallback


def guidance_document(db,rule):
    row=db.execute('''SELECT stable_id,title,path,source_id,source_type,author,
        source_url,local_ref,authority,published_at FROM documents
        WHERE source_id=? AND path=? LIMIT 1''',
        (rule.get('source_id','official-help'),rule.get('path',''))).fetchone()
    if not row:
        return None
    sid,title,path,source_id,source_type,author,source_url,local_ref,authority,published_at=row
    content=' '.join(x[0] for x in db.execute(
        'SELECT content FROM chunks WHERE stable_id=? ORDER BY rowid',(sid,)))
    return {
        'stable_id':sid,'title':title,'path':path,'content':content,
        'source_id':source_id,'source_type':source_type,'author':author or '',
        'thread_id':'','source_url':source_url or '','local_ref':local_ref or '',
        'authority':int(authority),'published_at':published_at or '',
        'focus_terms':rule.get('focus_terms',[]),
        'guidance_excerpt_chars':int(rule.get('excerpt_chars',0)),
        'score':float('inf'),
    }


def apply_current_guidance(db,question,selected,limit,source=None):
    rules=matched_current_guidance(question,selected)
    additions=[]
    existing={(item['source_id'],item['path']) for item in selected}
    for rule in rules:
        if source and rule.get('source_id','official-help')!=source:
            continue
        item=guidance_document(db,rule)
        if not item:
            continue
        key=(item['source_id'],item['path'])
        if key in existing:
            # A normal FTS hit contains only the matching chunk. Replace it
            # with the curated full-document view so focus_terms and a larger
            # excerpt can expose complete procedures or lists.
            selected=[item if (entry['source_id'],entry['path'])==key else entry
                      for entry in selected]
        else:
            additions.append(item); existing.add(key)
    if additions:
        selected=(additions+selected)[:limit]
    return selected,rules


def unique(values):
    out=[]; seen=set()
    for value in values:
        key=value.casefold()
        if key not in seen:
            seen.add(key); out.append(value)
    return out


def concepts(question):
    quoted=re.findall(r'"([^"\n]+)"',question)
    tokens=re.findall(r"[A-Za-z0-9]+(?:[-./+][A-Za-z0-9]+)*",question)
    useful=[x for x in tokens if x.casefold() not in STOP_WORDS and (len(x)>1 or x.isdigit())]
    base=unique(quoted+useful)
    expanded=list(base)
    for term in base:
        expanded.extend(ALIASES.get(term.casefold(),[]))
    return base,unique(expanded)


def anchor_concepts(base):
    """Return distinctive identifiers that should remain present in results."""
    return [term for term in base if re.search(r'[A-Za-z]',term) and re.search(r'\d',term)]


def anchor_present(anchor,searchable):
    """Accept an identifier or one of its explicit spelling aliases."""
    alternatives=[anchor,*ALIASES.get(anchor.casefold(),[])]
    return any(term.casefold() in searchable for term in alternatives)


def fts_atom(value):
    return '"'+value.replace('"','""')+'"'


def query_variants(question):
    base,expanded=concepts(question)
    variants=[]
    anchors=anchor_concepts(base)
    related=[x for x in expanded if x.casefold() not in {a.casefold() for a in anchors}]
    if anchors and related:
        anchor_query=' OR '.join(fts_atom(x) for x in anchors)
        related_query=' OR '.join(fts_atom(x) for x in related[:18])
        variants.append((f'({anchor_query}) AND ({related_query})',2.8,'anchored concepts'))
    if len(base)>=2:
        variants.append((' AND '.join(fts_atom(x) for x in base[:8]),2.1,'all primary concepts'))
    for phrase in re.findall(r'"([^"\n]+)"',question):
        variants.append((fts_atom(phrase),2.6,'explicit phrase'))
    if expanded:
        variants.append((' OR '.join(fts_atom(x) for x in expanded[:20]),1.0,'expanded concepts'))
    return base,expanded,unique_variants(variants)


def unique_variants(variants):
    out=[]; seen=set()
    for query,boost,label in variants:
        if query and query not in seen:
            seen.add(query); out.append((query,boost,label))
    return out


def excerpt(text,terms,limit=700):
    text=re.sub(r'\s+',' ',text or '').strip()
    if len(text)<=limit: return text
    positions=[]
    lowered=text.casefold()
    for term in terms:
        pos=lowered.find(term.casefold())
        if pos>=0: positions.append(pos)
    center=min(positions) if positions else 0
    start=max(0,center-limit//3)
    end=min(len(text),start+limit)
    start=max(0,end-limit)
    result=text[start:end].strip()
    return ('…' if start else '')+result+('…' if end<len(text) else '')


def retrieve(db,question,source=None,candidate_limit=600):
    base,expanded,variants=query_variants(question)
    anchors=anchor_concepts(base)
    if not variants:
        return base,expanded,[]
    candidates={}
    sql='''
      SELECT chunks.stable_id,chunks.title,chunks.path,chunks.content,
             chunks.source_id,chunks.source_type,chunks.author,chunks.thread_id,
             chunks.source_url,chunks.local_ref,documents.authority,
             documents.published_at,bm25(chunks)
      FROM chunks JOIN documents ON documents.stable_id=chunks.stable_id
      WHERE chunks MATCH ? AND (? IS NULL OR chunks.source_id=? )
      ORDER BY bm25(chunks) LIMIT ?
    '''
    for fts_query,boost,_label in variants:
        try:
            rows=db.execute(sql,(fts_query,source,source,candidate_limit)).fetchall()
        except sqlite3.OperationalError:
            continue
        for row in rows:
            sid,title,path,content,source_id,source_type,author,thread_id,source_url,local_ref,authority,published_at,rank=row
            searchable=(title+' '+path+' '+content).casefold()
            if anchors and not any(anchor_present(anchor,searchable)
                                   for anchor in anchors):
                continue
            discussion=source_type in ('groups_io_message','github_issue','github_issue_comment') and thread_id
            key=(source_id,thread_id if discussion else sid)
            relevance=max(0.000001,-float(rank))
            score=relevance*boost*(max(1,int(authority))/100.0)*TYPE_WEIGHTS.get(source_type,1.0)
            heading=(title+' '+path).casefold()
            title_hits=sum(1 for term in base if term.casefold() in heading)
            coverage=sum(1 for term in base if term.casefold() in searchable)/max(1,len(base))
            score*=1+min(.80,title_hits*.18)
            score*=.65+coverage
            old=candidates.get(key)
            if old is None or score>old['score']:
                candidates[key]={
                    'stable_id':sid,'title':title,'path':path,'content':content,
                    'source_id':source_id,'source_type':source_type,'author':author or '',
                    'thread_id':thread_id or '','source_url':source_url or '',
                    'local_ref':local_ref or '','authority':int(authority),
                    'published_at':published_at or '','score':score,
                }
    return base,expanded,sorted(candidates.values(),key=lambda x:(-x['score'],-x['authority'],x['stable_id']))


def diversify(candidates,limit,max_per_source=3):
    if limit<=0: return []
    selected=[]; counts=Counter(); remaining=list(candidates)
    while remaining and len(selected)<limit:
        eligible=[x for x in remaining if counts[x['source_id']]<max_per_source]
        if not eligible: break
        # Diversity is a modest tie-breaker, never a reason to include weak
        # evidence merely because its source has not appeared yet.
        item=max(eligible,key=lambda x:x['score']*(.88**counts[x['source_id']]))
        selected.append(item); counts[item['source_id']]+=1; remaining.remove(item)
    return selected


def make_packet(db,question,limit=10,source=None,max_per_source=3,excerpt_chars=700):
    base,expanded,candidates=retrieve(db,question,source)
    selected=diversify(candidates,limit,max_per_source)
    selected,guidance=apply_current_guidance(db,question,selected,limit,source)
    selected=apply_hamlib_version_evidence(db,question,selected,limit,source)
    evidence=[]
    for index,item in enumerate(selected,1):
        evidence.append({
            'number':index,
            'source':item['source_id'],
            'source_label':SOURCE_LABELS.get(item['source_id'],item['source_id']),
            'source_type':item['source_type'],
            'authority':item['authority'],
            'title':item['title'],
            'path':item['path'],
            'author':item['author'],
            'published_at':item['published_at'],
            'excerpt':excerpt(
                item['content'],item.get('focus_terms') or expanded,
                max(excerpt_chars,item.get('guidance_excerpt_chars',0)),
            ),
            'external_reference':item['source_url'],
            'local_reference':item['local_ref'],
            'image_references':image_references(db,item['stable_id']),
        })
    return {
        'question':question,
        'primary_concepts':base,
        'expanded_concepts':[x for x in expanded if x.casefold() not in {b.casefold() for b in base}],
        'required_anchors':anchor_concepts(base),
        'evidence_count':len(evidence),
        'current_guidance':[{
            'id':rule.get('id',''),
            'statement':rule.get('statement',''),
            'supersedes':rule.get('supersedes',[]),
        } for rule in guidance],
        'sources represented':sorted({x['source'] for x in evidence}),
        'evidence':evidence,
        'instruction':'Use only supported claims; distinguish official/project evidence from community experience and cite evidence numbers.',
    }


def print_packet(packet):
    print('ELMER EVIDENCE PACKET')
    print('Question:',packet['question'])
    print('Primary concepts:',', '.join(packet['primary_concepts']) or '(none)')
    if packet['required_anchors']:
        print('Required anchors:',', '.join(packet['required_anchors']))
    if packet['expanded_concepts']:
        print('Related concepts:',', '.join(packet['expanded_concepts']))
    for guidance in packet.get('current_guidance',[]):
        print('Current guidance:',guidance['statement'])
    print('Sources represented:',', '.join(packet['sources represented']) or '(none)')
    print()
    if not packet['evidence']:
        print('No supporting evidence found. Rephrase the question or check that the expected sources are enabled.')
        return
    for item in packet['evidence']:
        print(f"[{item['number']}] {item['title']}")
        print(f"Source: {item['source_label']} ({item['source_type']}, authority {item['authority']})")
        print('Path:',item['path'])
        if item['author']: print('Author:',item['author'])
        if item['published_at']: print('Date:',item['published_at'])
        print('Evidence:',item['excerpt'])
        if item['external_reference']: print('External:',item['external_reference'])
        if item['local_reference']: print('Local:',item['local_reference'])
        print()
    print('Answering instruction:',packet['instruction'])


def main():
    parser=argparse.ArgumentParser(description='Create a cited evidence packet for a natural-language Elmer question')
    parser.add_argument('question')
    parser.add_argument('--db',default='output/knowledge.db')
    parser.add_argument('-n',type=int,default=10,help='maximum evidence items')
    parser.add_argument('--source',help='limit retrieval to one source ID')
    parser.add_argument('--max-per-source',type=int,default=3)
    parser.add_argument('--excerpt-chars',type=int,default=700)
    parser.add_argument('--json',action='store_true')
    args=parser.parse_args()
    if args.n<0 or args.max_per_source<1 or args.excerpt_chars<100:
        parser.error('invalid evidence limit, source limit, or excerpt size')
    try:
        db=sqlite3.connect(args.db)
        packet=make_packet(db,args.question,args.n,args.source,args.max_per_source,args.excerpt_chars)
        db.close()
    except sqlite3.Error as exc:
        print(f'Unable to create evidence packet: {exc}',file=sys.stderr)
        return 1
    if args.json: print(json.dumps(packet,indent=2,ensure_ascii=False))
    else: print_packet(packet)
    return 0


if __name__=='__main__':
    raise SystemExit(main())
