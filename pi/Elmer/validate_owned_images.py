#!/usr/bin/env python3
"""Validate an Elmer knowledge database and its approved owned-image files."""

import argparse
import hashlib
import json
import sqlite3
from pathlib import Path


def validate(db_path,images_root):
    images_root=Path(images_root).resolve()
    manifest=json.loads((images_root/'manifest.json').read_text(encoding='utf-8'))
    if manifest.get('version')!=1 or manifest.get('source_id')!='official-help':
        raise ValueError('Unsupported owned-image manifest.')
    manifest_rows={}
    for item in manifest.get('images',[]):
        relative=Path(str(item.get('image_path','')))
        target=(images_root/'official-help'/relative).resolve()
        target.relative_to(images_root/'official-help')
        content=target.read_bytes()
        if hashlib.sha256(content).hexdigest()!=item.get('sha256'):
            raise ValueError(f'Image checksum mismatch: {relative}')
        if len(content)!=int(item.get('byte_size',-1)):
            raise ValueError(f'Image size mismatch: {relative}')
        manifest_rows[item['image_id']]=item
    with sqlite3.connect(f'file:{Path(db_path).resolve()}?mode=ro',uri=True) as db:
        if db.execute('PRAGMA integrity_check').fetchone()[0]!='ok':
            raise ValueError('Knowledge database integrity check failed.')
        rows=db.execute('''SELECT image_id,image_path,image_url,sha256,byte_size
                           FROM owned_images WHERE approved=1''').fetchall()
        associations=db.execute('SELECT COUNT(*) FROM document_images').fetchone()[0]
    if len(rows)!=len(manifest_rows):
        raise ValueError('Database and manifest image counts do not match.')
    for image_id,path,url,digest,size in rows:
        item=manifest_rows.get(image_id)
        if not item or (path,url,digest,int(size))!=(
                item['image_path'],item['image_url'],item['sha256'],int(item['byte_size'])):
            raise ValueError(f'Database image metadata mismatch: {image_id}')
    if associations<len(rows):
        raise ValueError('Owned images are missing document associations.')
    return len(rows),associations


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--db',required=True)
    parser.add_argument('--images',required=True)
    args=parser.parse_args()
    images,associations=validate(args.db,args.images)
    print(f'Owned-image validation passed: {images} images, {associations} document links')


if __name__=='__main__':
    main()
