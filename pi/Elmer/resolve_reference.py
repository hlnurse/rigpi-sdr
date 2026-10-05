#!/usr/bin/env python3
"""Resolve stable elmer:// knowledge references against knowledge.db."""

import argparse
import json
import sqlite3
import sys
import urllib.parse


def parse_reference(value):
    parsed=urllib.parse.urlparse(value)
    parts=[urllib.parse.unquote(x) for x in parsed.path.split('/') if x]
    if parsed.scheme!='elmer' or parsed.netloc!='source':
        raise ValueError('expected an elmer://source reference')
    if len(parts)==3 and parts[1]=='document' and all(parts):
        return 'document',parts[0],'',parts[2]
    if len(parts)!=5:
        raise ValueError('expected a document or thread/message reference')
    source_id,thread_label,thread_id,message_label,stable_id=parts
    if thread_label!='thread' or message_label!='message' or not all((source_id,thread_id,stable_id)):
        raise ValueError('expected a document or thread/message reference')
    return 'message',source_id,thread_id,stable_id


def resolve(db,reference,context):
    kind,source_id,thread_id,stable_id=parse_reference(reference)
    if kind=='document':
        row=db.execute('''SELECT stable_id,title,path,source_id,source_type,authority,
            word_count,author,published_at,source_url,local_ref FROM documents
            WHERE source_id=? AND stable_id=?''',(source_id,stable_id)).fetchone()
        if not row: raise LookupError(f'document not found: {stable_id}')
        body=''.join(x[0] for x in db.execute('SELECT content FROM chunks WHERE stable_id=? ORDER BY rowid',(stable_id,)))
        return {'reference':reference,'kind':'document','source_id':source_id,'document':{
            'stable_id':row[0],'title':row[1],'path':row[2],'source_type':row[4],
            'authority':row[5],'word_count':row[6],'author':row[7],
            'published_at':row[8],'source_url':row[9],'local_ref':row[10],'body':body}}
    thread=db.execute(
        'SELECT subject,message_count,first_date,last_date,authors_json FROM threads WHERE source_id=? AND thread_id=?',
        (source_id,thread_id)).fetchone()
    rows=db.execute('''
        SELECT stable_id,subject,author,published_at,body,source_url,local_ref
        FROM messages WHERE source_id=? AND thread_id=? ORDER BY stable_id
    ''',(source_id,thread_id)).fetchall()
    selected=next((i for i,row in enumerate(rows) if row[0]==stable_id),None)
    if selected is None:
        raise LookupError(f'message not found: {stable_id}')
    start=max(0,selected-context); end=min(len(rows),selected+context+1)
    messages=[]
    for i,row in enumerate(rows[start:end],start):
        messages.append({
            'stable_id':row[0], 'subject':row[1], 'author':row[2],
            'published_at':row[3], 'body':row[4], 'source_url':row[5],
            'local_ref':row[6], 'selected':i==selected,
        })
    thread_data={
        'thread_id':thread_id,
        'subject':thread[0] if thread else messages[0]['subject'],
        'message_count':thread[1] if thread else len(rows),
        'first_date':thread[2] if thread else '',
        'last_date':thread[3] if thread else '',
        'authors':json.loads(thread[4]) if thread and thread[4] else [],
    }
    return {
        'reference':reference, 'kind':'message', 'source_id':source_id,
        'thread':thread_data, 'selected_stable_id':stable_id,
        'context_before':selected-start, 'context_after':end-selected-1,
        'messages':messages,
    }


def preview(text,limit=600):
    text='\n'.join(line.rstrip() for line in (text or '').strip().splitlines())
    return text if len(text)<=limit else text[:limit].rstrip()+'…'


def print_result(result,full_context=False):
    if result.get('kind')=='document':
        d=result['document']
        print(f"Document: {d['title']}")
        print(f"Source: {result['source_id']}  Type: {d['source_type']}  Authority: {d['authority']}")
        print(f"Path: {d['path']}")
        if d['author']: print(f"Author: {d['author']}")
        if d['published_at']: print(f"Date: {d['published_at']}")
        if d['source_url']: print(f"External: {d['source_url']}")
        print(f"Local: {d['local_ref']}\n")
        print(d['body'].rstrip())
        print()
        return
    thread=result['thread']
    print(f"Thread: {thread['subject']}")
    print(f"Source: {result['source_id']}  Messages: {thread['message_count']}")
    if thread['first_date'] or thread['last_date']:
        print(f"Dates: {thread['first_date']} — {thread['last_date']}")
    print()
    for message in result['messages']:
        marker='SELECTED' if message['selected'] else 'CONTEXT'
        print(f"--- {marker}: {message['stable_id']} ---")
        print(f"{message['subject']}")
        print(f"Author: {message['author'] or '(unknown)'}")
        print(f"Date: {message['published_at'] or '(unknown)'}")
        if message['source_url']:
            print(f"External: {message['source_url']}")
        print(f"Local: {message['local_ref']}")
        print()
        body=message['body'] if message['selected'] or full_context else preview(message['body'])
        print(body.rstrip())
        print()


def main():
    parser=argparse.ArgumentParser(description='Resolve an Elmer local knowledge reference')
    parser.add_argument('reference')
    parser.add_argument('--db',default='output/knowledge.db')
    parser.add_argument('--context',type=int,default=2,help='neighboring messages on each side')
    parser.add_argument('--full-context',action='store_true',help='show complete bodies for neighboring messages')
    parser.add_argument('--json',action='store_true',help='emit structured JSON')
    args=parser.parse_args()
    if args.context<0:
        parser.error('--context must be zero or greater')
    try:
        db=sqlite3.connect(args.db)
        result=resolve(db,args.reference,args.context)
        db.close()
    except (ValueError,LookupError,sqlite3.Error) as exc:
        print(f'Unable to resolve reference: {exc}',file=sys.stderr)
        return 1
    if args.json:
        print(json.dumps(result,indent=2,ensure_ascii=False))
    else:
        print_result(result,args.full_context)
    return 0


if __name__=='__main__':
    raise SystemExit(main())
