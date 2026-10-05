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

install -o root -g root -m 0644 \
  "${release_pi}/RigPi/Help/rigpi-sdr.html" "$target_help"
install -o pi -g pi -m 0644 \
  "${release_elmer}/answer_knowledge.py" \
  "${target_elmer}/answer_knowledge.py"
install -o pi -g pi -m 0644 \
  "${release_elmer}/config/current_guidance.json" \
  "${target_elmer}/config/current_guidance.json"

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
