#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  printf 'Run this installer with sudo.\n' >&2
  exit 1
fi

release_elmer=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
release_pi=$(CDPATH= cd -- "${release_elmer}/.." && pwd)
target_elmer=/home/pi/Elmer
target_help=/var/www/html/Help/rigpi-sdr.html
python_path=/home/pi/venv/bin/python
stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_root=${target_elmer}/backups/ft4-awareness-${stamp}

for required in \
  "${release_pi}/RigPi/Help/rigpi-sdr.html" \
  "${release_elmer}/answer_knowledge.py" \
  "${release_elmer}/config/current_guidance.json" \
  "${target_elmer}/build_knowledge.py" \
  "${target_elmer}/config.json" \
  "${target_help}" \
  "${python_path}"; do
  if [[ ! -e ${required} ]]; then
    printf 'Required FT4-awareness input not found: %s\n' "$required" >&2
    exit 1
  fi
done

help_zip=$("$python_path" - "$target_elmer" "${target_elmer}/config.json" <<'PY'
import sys
from pathlib import Path

root=Path(sys.argv[1])
config_path=Path(sys.argv[2]).resolve()
sys.path.insert(0,str(root))
from build_knowledge import load_cfg,resolve,source

config=load_cfg(config_path)
item=source(config,'help_zip')
if not item or not item.get('enabled') or not item.get('path'):
    raise SystemExit('No enabled Help ZIP is configured.')
print(resolve(config_path.parent,item['path']))
PY
)
if [[ ! -f ${help_zip} ]]; then
  printf 'Configured Help ZIP not found: %s\n' "$help_zip" >&2
  exit 1
fi

PYTHONPYCACHEPREFIX=/tmp/rigpi-ft4-awareness-pycache \
  "$python_path" -m py_compile "${release_elmer}/answer_knowledge.py"
"$python_path" -m json.tool \
  "${release_elmer}/config/current_guidance.json" >/dev/null

install -d -o pi -g pi -m 0750 "$backup_root"
cp -a "$target_help" "$backup_root/rigpi-sdr.html"
for current in \
  "${target_elmer}/answer_knowledge.py" \
  "${target_elmer}/config/current_guidance.json" \
  "${target_elmer}/output/knowledge.db"; do
  if [[ -f ${current} ]]; then cp -a "$current" "$backup_root/"; fi
done
cp -a "$help_zip" "$backup_root/"

install -o root -g root -m 0644 \
  "${release_pi}/RigPi/Help/rigpi-sdr.html" "$target_help"
install -o pi -g pi -m 0644 \
  "${release_elmer}/answer_knowledge.py" \
  "${target_elmer}/answer_knowledge.py"
install -o pi -g pi -m 0644 \
  "${release_elmer}/config/current_guidance.json" \
  "${target_elmer}/config/current_guidance.json"

"$python_path" - "$help_zip" "${release_pi}/RigPi/Help/rigpi-sdr.html" <<'PY'
import os
import sys
import tempfile
import zipfile
from pathlib import Path, PurePosixPath

archive=Path(sys.argv[1])
replacement=Path(sys.argv[2]).read_bytes()
stat=archive.stat()
fd,temp_name=tempfile.mkstemp(prefix=archive.name+'.',suffix='.tmp',dir=archive.parent)
os.close(fd)
temp=Path(temp_name)
matches=0
try:
    with zipfile.ZipFile(archive,'r') as source, zipfile.ZipFile(temp,'w') as target:
        for info in source.infolist():
            data=source.read(info.filename)
            if PurePosixPath(info.filename).name.casefold()=='rigpi-sdr.html':
                data=replacement
                matches+=1
            target.writestr(info,data)
    if matches!=1:
        raise SystemExit(f'Expected one rigpi-sdr.html in Help ZIP; found {matches}.')
    os.chmod(temp,stat.st_mode)
    os.chown(temp,stat.st_uid,stat.st_gid)
    os.replace(temp,archive)
finally:
    if temp.exists(): temp.unlink()
print(f'Updated RigPi-SDR Help inside {archive}')
PY

runuser -u pi -- "$python_path" \
  "${target_elmer}/build_knowledge.py" \
  --config "${target_elmer}/config.json"

runuser -u pi -- "$python_path" - "${target_elmer}/output/knowledge.db" <<'PY'
import sqlite3
import sys

with sqlite3.connect(f'file:{sys.argv[1]}?mode=ro', uri=True) as database:
    check = database.execute('PRAGMA quick_check').fetchone()[0]
    ft4 = database.execute("""SELECT COUNT(*) FROM chunks AS c
        JOIN documents AS d ON d.stable_id=c.stable_id
        WHERE d.source_id='official-help' AND d.path='rigpi-sdr.html'
        AND c.content LIKE '%built-in receive decoding for both FT8 and FT4%'""").fetchone()[0]
if check != 'ok' or ft4 < 1:
    raise SystemExit(f'FT4 knowledge validation failed: quick_check={check}, matches={ft4}')
print('Validated official RigPi FT8/FT4 decoder evidence')
PY

if systemctl is-active --quiet elmer-web.service; then
  systemctl restart elmer-web.service
fi

printf '\nRigPi FT4 decoder awareness installed.\n'
printf 'Backup: %s\n' "$backup_root"
printf 'Upload this database to the cloud installer: %s/output/knowledge.db\n' "$target_elmer"
