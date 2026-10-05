#!/usr/bin/env python3
"""Synchronize one configured HTML manual into Elmer's local cache."""

import argparse
import json
from pathlib import Path

from build_knowledge import load_cfg,resolve
from html_manual_connector import sync_manual


parser=argparse.ArgumentParser()
parser.add_argument('--config',default='config.json')
parser.add_argument('--connector',required=True)
args=parser.parse_args()
config_path=Path(args.config).resolve()
config=load_cfg(config_path)
connector=next((item for item in config['connectors']
                if item.get('id')==args.connector),None)
if connector is None:
    raise SystemExit(f'Connector not found: {args.connector}')
if connector.get('type')!='html_manual':
    raise SystemExit(f'Not an HTML manual connector: {args.connector}')
cache_root=resolve(
    config_path.parent,
    config['paths'].get('connector_cache_directory','cache/connectors'),
)
print(json.dumps(sync_manual(cache_root/args.connector,connector),indent=2))
