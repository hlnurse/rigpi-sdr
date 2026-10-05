#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  printf 'Run this installer as root.\n' >&2
  exit 1
fi
if [[ $# -ne 1 ]]; then
  printf 'Usage: %s KNOWLEDGE_DB\n' "$0" >&2
  exit 1
fi

release_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
source_db=$1
python_path=/opt/elmer/venv/bin/python
for required in \
  "$source_db" \
  "${release_root}/answer_knowledge.py" \
  "${release_root}/config/current_guidance.json" \
  "$python_path"; do
  if [[ ! -e ${required} ]]; then
    printf 'Required cloud FT4-awareness input not found: %s\n' "$required" >&2
    exit 1
  fi
done

"$python_path" - "$source_db" <<'PY'
import sqlite3
import sys

with sqlite3.connect(f'file:{sys.argv[1]}?mode=ro', uri=True) as database:
    check = database.execute('PRAGMA quick_check').fetchone()[0]
    ft4 = database.execute("""SELECT COUNT(*) FROM chunks AS c
        JOIN documents AS d ON d.stable_id=c.stable_id
        WHERE d.source_id='official-help' AND d.path='rigpi-sdr.html'
        AND c.content LIKE '%built-in receive decoding for both FT8 and FT4%'""").fetchone()[0]
if check != 'ok' or ft4 < 1:
    raise SystemExit(f'Invalid FT4 update database: quick_check={check}, matches={ft4}')
print('Validated cloud FT8/FT4 decoder evidence')
PY
PYTHONPYCACHEPREFIX=/tmp/elmer-ft4-awareness-pycache \
  "$python_path" -m py_compile "${release_root}/answer_knowledge.py"
"$python_path" -m json.tool \
  "${release_root}/config/current_guidance.json" >/dev/null

stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_root=/opt/elmer/backups/ft4-awareness-${stamp}
install -d -o root -g elmer -m 0750 "$backup_root"
for current in \
  /opt/elmer/app/answer_knowledge.py \
  /opt/elmer/app/config/current_guidance.json \
  /opt/elmer/data/knowledge.db; do
  if [[ -f ${current} ]]; then cp -a "$current" "$backup_root/"; fi
done

install -d -o elmer -g elmer -m 0750 /opt/elmer/app/config
install -o elmer -g elmer -m 0640 \
  "${release_root}/answer_knowledge.py" \
  /opt/elmer/app/answer_knowledge.py
install -o elmer -g elmer -m 0640 \
  "${release_root}/config/current_guidance.json" \
  /opt/elmer/app/config/current_guidance.json
staging_db=/opt/elmer/data/.knowledge.db.ft4-awareness-${stamp}
install -o elmer -g elmer -m 0640 "$source_db" "$staging_db"
mv "$staging_db" /opt/elmer/data/knowledge.db

cloud_active=false
gateway_active=false
if systemctl is-active --quiet elmer-cloud.service; then cloud_active=true; fi
if systemctl is-active --quiet elmer-gateway.service; then gateway_active=true; fi
if [[ ${cloud_active} == true ]]; then systemctl restart elmer-cloud.service; fi
if [[ ${gateway_active} == true ]]; then systemctl restart elmer-gateway.service; fi
if [[ ${cloud_active} == true ]]; then
  curl -fsS --max-time 10 http://127.0.0.1:8091/health >/dev/null
fi
if [[ ${gateway_active} == true ]]; then
  curl -fsS --max-time 10 http://127.0.0.1:8092/health >/dev/null
fi

printf '\nRigPi FT4 decoder awareness is active on the cloud host.\n'
printf 'Backup: %s\n' "$backup_root"
