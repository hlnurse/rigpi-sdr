#!/usr/bin/env python3
"""Export and index approved images owned with Official RigPi Help."""

import fnmatch
import hashlib
import json
import mimetypes
import re
import shutil
import urllib.parse
from pathlib import Path


IMAGE_EXTENSIONS={'.png','.jpg','.jpeg','.gif','.svg','.webp'}
DEFAULT_EXCLUDES=(
    'hm_webhelp_*','hm_topicheader_*','cicon_*','favicon*','blank.gif','spacer.gif',
)


def safe_relative_image(root,page_path,src):
    parsed=urllib.parse.urlparse(str(src or ''))
    if parsed.scheme or parsed.netloc or str(src).startswith('//'):
        return None
    clean=urllib.parse.unquote(parsed.path).replace('\\','/').lstrip('/')
    if not clean:
        return None
    target=(root/Path(page_path).parent/clean).resolve()
    try:
        relative=target.relative_to(root.resolve())
    except ValueError:
        return None
    if not target.is_file() or target.suffix.lower() not in IMAGE_EXTENSIONS:
        return None
    return relative


def useful_label(detail,relative):
    value=' '.join(str(detail.get(key,'')).strip() for key in ('alt','title')).strip()
    if value:
        return re.sub(r'\s+',' ',value)[:300]
    return re.sub(r'[-_]+',' ',relative.stem).strip().title()[:300]


def export_help_images(root,report,output_root,source_cfg):
    root=Path(root)
    output_root=Path(output_root)
    source_id=str(source_cfg.get('id','official-help'))
    options=source_cfg.get('options',{}) if isinstance(source_cfg.get('options',{}),dict) else {}
    base_url=str(options.get(
        'image_base_url',f'https://elmer.rigpi.net/media/{source_id}/')).rstrip('/')+'/'
    excludes=tuple(str(x).casefold() for x in options.get(
        'image_exclude_patterns',DEFAULT_EXCLUDES))
    source_output=output_root/source_id
    if source_output.exists():
        shutil.rmtree(source_output)
    source_output.mkdir(parents=True,exist_ok=True)
    images={}; associations=[]
    for page in report.get('pages',[]):
        approved=[]
        for position,detail in enumerate(page.pop('image_details',[]),1):
            relative=safe_relative_image(root,page.get('file',''),detail.get('src',''))
            if relative is None or any(fnmatch.fnmatch(relative.name.casefold(),pattern) for pattern in excludes):
                continue
            path_text=relative.as_posix()
            content=(root/relative).read_bytes()
            path_digest=hashlib.sha256(f'{source_id}:{path_text}'.encode()).hexdigest()
            image_id='IMAGE-'+path_digest[:20].upper()
            label=useful_label(detail,relative)
            image_url=base_url+urllib.parse.quote(path_text,safe='/')
            record={
                'image_id':image_id,'source_id':source_id,'image_path':path_text,
                'image_url':image_url,'alt_text':label,
                'media_type':mimetypes.guess_type(relative.name)[0] or 'application/octet-stream',
                'sha256':hashlib.sha256(content).hexdigest(),'byte_size':len(content),
                'license':'RigPi-owned Help asset','approved':1,
            }
            images.setdefault(image_id,record)
            association={
                'image_id':image_id,'image_path':path_text,'image_url':image_url,
                'alt_text':label,'caption':label,'position':position,
            }
            approved.append(association)
            associations.append({'page_path':page.get('file',''),**association})
            destination=source_output/relative
            destination.parent.mkdir(parents=True,exist_ok=True)
            if not destination.exists():
                shutil.copy2(root/relative,destination)
        page['owned_images']=approved
    manifest={
        'version':1,'source_id':source_id,'license':'RigPi-owned Help assets',
        'images':sorted(images.values(),key=lambda x:x['image_path']),
        'associations':associations,
    }
    output_root.mkdir(parents=True,exist_ok=True)
    (output_root/'manifest.json').write_text(
        json.dumps(manifest,indent=2,ensure_ascii=False),encoding='utf-8')
    report.setdefault('summary',{})['approved_images']=len(images)
    report['owned_images_manifest']=manifest
    return manifest


def create_image_schema(db):
    db.executescript('''
      CREATE TABLE owned_images(
        image_id TEXT PRIMARY KEY,source_id TEXT NOT NULL,image_path TEXT NOT NULL,
        image_url TEXT NOT NULL,alt_text TEXT NOT NULL,media_type TEXT NOT NULL,
        sha256 TEXT NOT NULL,byte_size INTEGER NOT NULL,license TEXT NOT NULL,
        approved INTEGER NOT NULL DEFAULT 1
      );
      CREATE TABLE document_images(
        stable_id TEXT NOT NULL,image_id TEXT NOT NULL,position INTEGER NOT NULL,
        caption TEXT NOT NULL,PRIMARY KEY(stable_id,image_id),
        FOREIGN KEY(image_id) REFERENCES owned_images(image_id)
      );
      CREATE INDEX document_images_stable_id ON document_images(stable_id);
    ''')


def store_page_images(db,page,stable_id,image_records):
    for association in page.get('owned_images',[]):
        image_id=association['image_id']
        record=image_records.get(image_id)
        if record is None:
            continue
        db.execute('''INSERT OR IGNORE INTO owned_images
            (image_id,source_id,image_path,image_url,alt_text,media_type,sha256,
             byte_size,license,approved) VALUES(?,?,?,?,?,?,?,?,?,?)''',(
            image_id,record.get('source_id','official-help'),record['image_path'],
            record['image_url'],record.get('alt_text',''),record.get('media_type',''),
            record.get('sha256',''),int(record.get('byte_size',0)),
            record.get('license','RigPi-owned Help asset'),int(record.get('approved',1))))
        db.execute('''INSERT OR IGNORE INTO document_images
            (stable_id,image_id,position,caption) VALUES(?,?,?,?)''',(
            stable_id,image_id,int(association.get('position',0)),association.get('caption','')))


def image_references(db,stable_id,limit=4):
    try:
        rows=db.execute('''SELECT i.image_url,di.caption,i.alt_text,i.image_path
            FROM document_images di JOIN owned_images i ON i.image_id=di.image_id
            WHERE di.stable_id=? AND i.approved=1
            ORDER BY di.position,i.image_path LIMIT ?''',(stable_id,limit)).fetchall()
    except Exception:
        return []
    return [
        {'url':row[0],'caption':row[1] or row[2],'alt':row[2],'path':row[3]}
        for row in rows
    ]
