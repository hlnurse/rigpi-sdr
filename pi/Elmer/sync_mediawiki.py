#!/usr/bin/env python3
import argparse,json
from pathlib import Path
from build_knowledge import load_cfg,resolve
from mediawiki_connector import sync_mediawiki_category

p=argparse.ArgumentParser(); p.add_argument('--config',default='config.json')
p.add_argument('--connector',required=True); a=p.parse_args()
config_path=Path(a.config).resolve(); config=load_cfg(config_path)
connector=next((x for x in config['connectors'] if x.get('id')==a.connector),None)
if not connector or connector.get('type')!='mediawiki_category':
    raise SystemExit(f'MediaWiki connector not found: {a.connector}')
cache=resolve(config_path.parent,config['paths'].get('connector_cache_directory','cache/connectors'))
print(json.dumps(sync_mediawiki_category(cache/a.connector,connector),indent=2))

