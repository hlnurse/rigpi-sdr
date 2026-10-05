#!/usr/bin/env python3
import argparse,re,sqlite3

p=argparse.ArgumentParser()
p.add_argument('query')
p.add_argument('--db',default='output/knowledge.db')
p.add_argument('-n',type=int,default=10,help='maximum number of unique pages or discussion threads')
p.add_argument('--source',help='limit results to one source ID, such as rigpi-github')
a=p.parse_args()

def prepare_query(value):
 # Preserve explicit FTS phrases/operators, but quote common hyphenated model
 # names so queries such as IC-7300 do not parse as column expressions.
 if '"' in value: return value
 return ' '.join(f'"{token}"' if '-' in token and re.fullmatch(r'[\w.+/-]+',token) else token for token in value.split())

db=sqlite3.connect(a.db)
rows=db.execute("""
 SELECT chunks.stable_id,chunks.title,chunks.path,snippet(chunks,-1,'[',']',' ... ',18),
        bm25(chunks)*(documents.authority/100.0),chunks.source_id,chunks.source_type,
        chunks.author,chunks.thread_id,chunks.source_url,chunks.local_ref
 FROM chunks JOIN documents ON documents.stable_id=chunks.stable_id
 WHERE chunks MATCH ? AND (? IS NULL OR chunks.source_id=?)
 ORDER BY bm25(chunks)*(documents.authority/100.0),chunks.stable_id
""",(prepare_query(a.query),a.source,a.source))
seen=set()
limit=max(0,a.n)
if limit==0:
 db.close()
 raise SystemExit(0)
for sid,title,path,snip,_rank,source_id,source_type,_author,thread_id,source_url,local_ref in rows:
 is_group=source_type=='groups_io_message' and bool(thread_id)
 is_github_discussion=source_type in ('github_issue','github_issue_comment') and bool(thread_id)
 key=(source_id,thread_id if is_group or is_github_discussion else sid)
 if key in seen: continue
 seen.add(key)
 if is_group:
  thread_title=path.removeprefix('thread:')
  refs=[]
  if source_url: refs.append(f"External: {source_url}")
  if local_ref: refs.append(f"Local: {local_ref}")
  reference=('\n'+'\n'.join(refs)) if refs else ''
  print(f"{sid}  {thread_title}\n[Groups.io] {title}\n{snip}{reference}\n")
 else:
  source_label='Official Help' if source_type=='help' else 'Hamlib GitHub' if source_id=='hamlib-github' else 'RigPi GitHub' if source_id=='rigpi-github' else source_id
  refs=[]
  if source_url: refs.append(f"External: {source_url}")
  if local_ref: refs.append(f"Local: {local_ref}")
  reference=('\n'+'\n'.join(refs)) if refs else ''
  print(f"{sid}  {title}\n[{source_label}] {path}\n{snip}{reference}\n")
 if len(seen)>=limit: break
db.close()
