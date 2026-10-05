#!/usr/bin/env python3
"""Create, list, and revoke hosted Elmer reviewer passes."""

import argparse
import sys
from pathlib import Path

from elmer_gateway import load_secret
from gateway_store import create_pass,initialize,list_passes,revoke_pass


def main():
    parser=argparse.ArgumentParser(description='Manage Elmer reviewer passes')
    parser.add_argument('--db',default='/opt/elmer/state/gateway.db')
    parser.add_argument('--secret',default='/etc/elmer/gateway_secret')
    sub=parser.add_subparsers(dest='command',required=True)
    create=sub.add_parser('create')
    create.add_argument('--name',required=True)
    create.add_argument('--days',type=int,default=14)
    create.add_argument('--questions',type=int,default=20)
    create.add_argument('--base-url',default='https://elmer.rigpi.net')
    sub.add_parser('list')
    revoke=sub.add_parser('revoke')
    revoke.add_argument('pass_id')
    args=parser.parse_args()
    if args.command=='create' and (args.days<1 or args.questions<1 or len(args.name)>120):
        parser.error('name must be at most 120 characters; days and questions must be positive')
    secret=load_secret(args.secret)
    Path(args.db).parent.mkdir(parents=True,exist_ok=True)
    initialize(args.db)
    if args.command=='create':
        pass_id,token,expires=create_pass(args.db,secret,args.name,args.days,args.questions)
        print('Reviewer pass created.')
        print('ID:',pass_id)
        print('Name:',args.name)
        print('Expires:',expires.isoformat())
        print('Questions:',args.questions)
        print('Invitation URL:',args.base_url.rstrip('/')+'/review/'+token)
        print('The invitation token is shown only now; share it privately.')
        return 0
    if args.command=='list':
        print('ID\tNAME\tUSED/LIMIT\tEXPIRES\tSTATUS')
        for row in list_passes(args.db):
            pass_id,name,_created,expires,used,limit,revoked=row
            print(f'{pass_id}\t{name}\t{used}/{limit}\t{expires}\t{"revoked" if revoked else "active"}')
        return 0
    if not revoke_pass(args.db,args.pass_id):
        print('Active reviewer pass not found.',file=sys.stderr)
        return 1
    print('Reviewer pass revoked:',args.pass_id)
    return 0


if __name__=='__main__':
    raise SystemExit(main())
