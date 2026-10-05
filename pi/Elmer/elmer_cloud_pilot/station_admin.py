#!/usr/bin/env python3
"""Approve pairing codes and manage Elmer station credentials."""

import argparse
import sys
from pathlib import Path

from elmer_gateway import load_secret
from gateway_store import (
    approve_pairing,initialize,list_pairings,list_stations,reject_pairing,revoke_station,
)


def main():
    parser=argparse.ArgumentParser(description='Manage paired Elmer stations')
    parser.add_argument('--db',default='/opt/elmer/state/gateway.db')
    parser.add_argument('--secret',default='/etc/elmer/gateway_secret')
    sub=parser.add_subparsers(dest='command',required=True)
    sub.add_parser('pairings')
    approve=sub.add_parser('approve')
    approve.add_argument('code')
    approve.add_argument('--plan',default='pilot')
    approve.add_argument('--monthly-questions',type=int,default=200)
    reject=sub.add_parser('reject'); reject.add_argument('code')
    sub.add_parser('stations')
    revoke=sub.add_parser('revoke'); revoke.add_argument('credential_id')
    args=parser.parse_args()
    if getattr(args,'monthly_questions',1)<1:
        parser.error('monthly question allowance must be positive')
    secret=load_secret(args.secret)
    Path(args.db).parent.mkdir(parents=True,exist_ok=True)
    initialize(args.db)
    if args.command=='pairings':
        print('ID\tSTATION ID\tNAME\tEXPIRES\tSTATUS')
        for row in list_pairings(args.db):
            print(f'{row[0]}\t{row[1]}\t{row[2]}\t{row[4]}\t{row[5]}')
        return 0
    if args.command=='approve':
        try:
            result=approve_pairing(
                args.db,secret,args.code,args.plan,args.monthly_questions)
        except PermissionError as exc:
            print(str(exc),file=sys.stderr); return 1
        print('Station approved. The credential is waiting for one-time delivery to the station.')
        for key in ('station_id','station_name','credential_id','plan','monthly_limit'):
            print(f'{key}: {result[key]}')
        return 0
    if args.command=='reject':
        if not reject_pairing(args.db,secret,args.code):
            print('Pending pairing code not found.',file=sys.stderr); return 1
        print('Pairing rejected.')
        return 0
    if args.command=='stations':
        print('CREDENTIAL ID\tSTATION ID\tNAME\tPLAN\tUSED/LIMIT\tSTATUS')
        for row in list_stations(args.db):
            status='revoked' if row[5] else 'active'
            print(f'{row[0]}\t{row[1]}\t{row[2]}\t{row[3]}\t{row[8]}/{row[6]}\t{status}')
        return 0
    if not revoke_station(args.db,args.credential_id):
        print('Active station credential not found.',file=sys.stderr); return 1
    print('Station credential revoked:',args.credential_id)
    return 0


if __name__=='__main__':
    raise SystemExit(main())
