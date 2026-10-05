#!/usr/bin/env python3
"""Generate a cited Elmer answer using a local Ollama model."""

import argparse
import json
import os
import sqlite3
import sys
import urllib.error
import urllib.request

from answer_knowledge import INSTRUCTIONS,packet_input
from question_knowledge import make_packet


DEFAULT_URL='http://127.0.0.1:11434/api/chat'
DEFAULT_MODEL='qwen3:8b-q4_K_M'


def local_usage(result):
    prompt_tokens=int(result.get('prompt_eval_count') or 0)
    output_tokens=int(result.get('eval_count') or 0)
    prompt_seconds=float(result.get('prompt_eval_duration') or 0)/1_000_000_000
    output_seconds=float(result.get('eval_duration') or 0)/1_000_000_000
    total_seconds=float(result.get('total_duration') or 0)/1_000_000_000
    return {
        'prompt_tokens':prompt_tokens,
        'output_tokens':output_tokens,
        'total_tokens':prompt_tokens+output_tokens,
        'prompt_seconds':round(prompt_seconds,3),
        'generation_seconds':round(output_seconds,3),
        'total_seconds':round(total_seconds,3),
        'tokens_per_second':round(output_tokens/output_seconds,2) if output_seconds else 0,
    }


def generate_local_answer(packet,model=DEFAULT_MODEL,url=DEFAULT_URL,num_ctx=8192,num_predict=2200,opener=urllib.request.urlopen):
    payload={
        'model':model,
        'messages':[
            {'role':'system','content':INSTRUCTIONS},
            {'role':'user','content':packet_input(packet)},
        ],
        'stream':False,
        'think':False,
        'keep_alive':'10m',
        'options':{
            'num_ctx':num_ctx,
            'num_predict':num_predict,
            'temperature':0.1,
            'seed':42,
        },
    }
    request=urllib.request.Request(
        url,data=json.dumps(payload,ensure_ascii=False).encode('utf-8'),
        headers={'Content-Type':'application/json','User-Agent':'RigPi-Elmer'},method='POST')
    try:
        with opener(request,timeout=1800) as response: result=json.load(response)
    except urllib.error.HTTPError as exc:
        detail=exc.read().decode('utf-8',errors='replace')
        raise RuntimeError(f'Ollama returned HTTP {exc.code}: {detail}') from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f'Unable to reach Ollama at {url}: {exc.reason}') from exc
    message=result.get('message') or {}
    text=str(message.get('content') or '').strip()
    if not text: raise RuntimeError('Ollama returned no answer text')
    return text,local_usage(result)


def main():
    parser=argparse.ArgumentParser(description='Answer a RigPi question from cited Elmer evidence using local Ollama')
    parser.add_argument('question')
    parser.add_argument('--db',default='output/knowledge.db')
    parser.add_argument('-n',type=int,default=10,help='maximum evidence items')
    parser.add_argument('--max-per-source',type=int,default=3)
    parser.add_argument('--excerpt-chars',type=int,default=700)
    parser.add_argument('--model',default=os.environ.get('ELMER_LOCAL_MODEL',DEFAULT_MODEL))
    parser.add_argument('--url',default=os.environ.get('ELMER_OLLAMA_URL',DEFAULT_URL))
    parser.add_argument('--num-ctx',type=int,default=8192)
    parser.add_argument('--max-output-tokens',type=int,default=2200)
    parser.add_argument('--show-usage',action='store_true')
    args=parser.parse_args()
    if args.n<1 or args.max_per_source<1 or args.excerpt_chars<100 or args.num_ctx<4096 or args.max_output_tokens<100:
        parser.error('invalid evidence, context, or output limit')
    try:
        db=sqlite3.connect(args.db)
        packet=make_packet(db,args.question,args.n,None,args.max_per_source,args.excerpt_chars)
        db.close()
    except sqlite3.Error as exc:
        print(f'Unable to retrieve Elmer evidence: {exc}',file=sys.stderr); return 1
    if not packet['evidence']:
        print('Elmer could not find enough evidence to answer that question.',file=sys.stderr); return 2
    try:
        answer,usage=generate_local_answer(packet,args.model,args.url,args.num_ctx,args.max_output_tokens)
    except RuntimeError as exc:
        print(f'Unable to generate local answer: {exc}',file=sys.stderr); return 1
    print(answer)
    if args.show_usage: print('\nLocal usage: '+json.dumps(usage,sort_keys=True),file=sys.stderr)
    return 0


if __name__=='__main__':
    raise SystemExit(main())
