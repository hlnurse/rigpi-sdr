#!/usr/bin/env python3
"""Enable Groups.io contributed files without changing other connectors."""
import json
import shutil
from pathlib import Path

path=Path('config/connectors.json')
backup=path.with_name(path.name+'.before-groups-files')
data=json.loads(path.read_text(encoding='utf-8'))
connectors=data.setdefault('connectors',[])
connector=next((x for x in connectors if x.get('id')=='groups-files'),None)
if connector is None:
    connector={}
    connectors.append(connector)
if not backup.exists(): shutil.copy2(path,backup)
connector.update({
    'id':'groups-files','name':'Groups.io Contributed Files',
    'type':'groups_io_files_export','path':'sources/RigPi-2','enabled':True,
    'authority':70,'category':'community','tags':['community','files'],
    'refresh':'manual',
})
connector['options']={
    **connector.get('options',{}),
    'base_url':'https://groups.io/g/RigPi',
    'extensions':['.pdf','.docx','.rtf','.txt','.dat','.h','.md'],
    'minimum_document_words':3,
}
path.write_text(json.dumps(data,indent=2)+'\n',encoding='utf-8')
print('Enabled groups-files; preserved all other connectors')
print('Backup:',backup)
