#!/usr/bin/env python3
import argparse
import json
from pathlib import Path
from build_knowledge import load_cfg,resolve
from github_connector import sync_repository

p=argparse.ArgumentParser(description='Synchronize an Elmer GitHub connector into its local cache')
p.add_argument('--config',default='config.json'); p.add_argument('--connector',required=True)
a=p.parse_args(); cfgpath=Path(a.config).resolve(); cfg=load_cfg(cfgpath)
connector=next((x for x in cfg['connectors'] if x.get('id')==a.connector),None)
if not connector: raise SystemExit(f'Connector not found: {a.connector}')
if connector.get('type')!='github_repository': raise SystemExit(f'Not a GitHub connector: {a.connector}')
cache_root=resolve(cfgpath.parent,cfg['paths'].get('connector_cache_directory','cache/connectors'))
manifest=sync_repository(cache_root/a.connector,connector)
print(json.dumps(manifest,indent=2))
