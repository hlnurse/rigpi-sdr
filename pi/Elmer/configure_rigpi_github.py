#!/usr/bin/env python3
"""Enable the RigPi GitHub connector without changing other connectors."""
import json
import shutil
from pathlib import Path

path=Path('config/connectors.json')
backup=path.with_name(path.name+'.before-rigpi-github')
data=json.loads(path.read_text(encoding='utf-8'))
connector=next((x for x in data.get('connectors',[]) if x.get('id')=='rigpi-github'),None)
if not connector: raise SystemExit('rigpi-github connector not found')
if not backup.exists(): shutil.copy2(path,backup)
connector.update({'name':'RigPi GitHub','type':'github_repository','enabled':True,'authority':95,'category':'development','tags':['github','rigpi','issues','releases'],'refresh':'manual'})
connector['options']={
    **connector.get('options',{}),
    'owner':'hlnurse','repository':'rigpi','include_source':True,
    'include_readme':True,'include_releases':True,'include_issues':True,
    'include_issue_comments':True,'include_wiki':True,'include_pull_requests':False,
    'maximum_file_bytes':524288,
    'source_extensions':['.c','.cc','.cpp','.h','.hpp','.py','.php','.js','.css','.sh','.md','.rst','.txt','.html','.htm','.xml','.json','.yaml','.yml','.ini','.conf','.service','.sql','.twig'],
    'exclude_patterns':['.git/*','autom4te.cache/*','build/*','.github/workflows/*','html/Help/*','html/phpmyadmin/*','html/awe/*','html/Bootstrap/*','html/flags/*','html/Images/*','html/programs/vendor/*','html/programs/GPIO/vendor/*','html/includes/*.json','html/js/*.min.js','html/js/FileSaver.js','html/js/PerfectWidgets.js','html/js/bootstrap-slider.js','html/js/jquery-*.js','html/js/leaflet.js','html/js/modernizr.js','html/js/moment.js','html/js/mscorlib.js','html/js/summernote-case-converter.js','html/scheduler/ical.js','html/scheduler/moment*.js','html/classes/MysqliDb.php','html/includes/ChromePhp.php'],
    'token_environment_variable':'GITHUB_TOKEN',
}
path.write_text(json.dumps(data,indent=2)+'\n',encoding='utf-8')
print('Enabled rigpi-github; preserved backup at',backup)
