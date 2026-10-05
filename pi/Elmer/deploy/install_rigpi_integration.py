#!/usr/bin/env python3
"""Idempotently add Elmer's nginx include and admin Help-menu link."""
import shutil
from pathlib import Path

NGINX=Path('/etc/nginx/sites-enabled/myserver')
HEADERS=[Path('/var/www/html/includes/header.php'),Path('/var/www/html/includes/header_cal.php')]
NGINX_INCLUDE='    include /etc/nginx/snippets/elmer.conf;'
MENU_MARKER='Ask Elmer'
MENU_LINE="\t\t<?php if ((int)$level === 1) { echo '<li><a class=\"dropdown-item\" href=\"/elmer.php\">Ask Elmer</a></li>'; } ?>"

def backup(path):
    saved=path.with_name(path.name+'.pre-elmer')
    if not saved.exists():
        shutil.copy2(path,saved)

def patch_nginx(path):
    text=path.read_text()
    if NGINX_INCLUDE in text:
        return False
    marker='    index index.php index.html;'
    if marker not in text:
        raise RuntimeError(f'Unable to locate nginx index line in {path}')
    backup(path)
    path.write_text(text.replace(marker,marker+'\n\n'+NGINX_INCLUDE,1))
    return True

def patch_header(path,required=True):
    text=path.read_text()
    if MENU_MARKER in text:
        return False
    lines=text.splitlines()
    for index,line in enumerate(lines):
        if 'href="/help.php"' in line and 'RigPi Help' in line:
            backup(path)
            lines.insert(index+1,MENU_LINE)
            path.write_text('\n'.join(lines)+'\n')
            return True
    if required:
        raise RuntimeError(f'Unable to locate Help menu in {path}')
    print(f'Skipped optional menu without the standard Help link: {path}')
    return False

def main():
    changed=[]
    if patch_nginx(NGINX): changed.append(str(NGINX))
    for index,path in enumerate(HEADERS):
        if path.exists() and patch_header(path,required=index==0): changed.append(str(path))
    print('Updated: '+(', '.join(changed) if changed else 'already installed'))

if __name__=='__main__':
    main()
