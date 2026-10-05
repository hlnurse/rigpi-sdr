#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  printf 'Run this updater as root.\n' >&2
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
  "$release_root/question_knowledge.py" \
  "$release_root/answer_knowledge.py" \
  "$python_path"; do
  if [[ ! -e ${required} ]]; then
    printf 'Required update input not found: %s\n' "$required" >&2
    exit 1
  fi
done

"$python_path" - "$source_db" <<'PY'
import sqlite3
import sys

with sqlite3.connect(f'file:{sys.argv[1]}?mode=ro',uri=True) as database:
    check=database.execute('PRAGMA quick_check').fetchone()[0]
    html=database.execute(
        "SELECT COUNT(*) FROM documents WHERE source_id='wsjtx-manual' "
        "AND source_type='wsjtx_manual'"
    ).fetchone()[0]
    adoc=database.execute(
        "SELECT COUNT(*) FROM documents WHERE source_id='wsjtx-github' "
        "AND source_type='github_manual'"
    ).fetchone()[0]
if check!='ok' or html<40 or adoc<40:
    raise SystemExit(
        f'Invalid update database: quick_check={check}, HTML sections={html}, '
        f'AsciiDoc sections={adoc}'
    )
print(f'Validated update database: {html} HTML and {adoc} AsciiDoc sections')
PY

PYTHONPYCACHEPREFIX=/tmp/elmer-wsjtx-pycache "$python_path" -m py_compile \
  "$release_root/question_knowledge.py" \
  "$release_root/answer_knowledge.py"

stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_root=/opt/elmer/backups/wsjtx-manual-${stamp}
install -d -o root -g elmer -m 0750 "$backup_root"
for current in \
  /opt/elmer/app/question_knowledge.py \
  /opt/elmer/app/answer_knowledge.py \
  /opt/elmer/data/knowledge.db; do
  if [[ -f ${current} ]]; then
    cp -a "$current" "$backup_root/"
  fi
done

staging_db=/opt/elmer/data/.knowledge.db.wsjtx-${stamp}
install -o elmer -g elmer -m 0640 "$source_db" "$staging_db"
install -o elmer -g elmer -m 0640 \
  "$release_root/question_knowledge.py" \
  "$release_root/answer_knowledge.py" \
  /opt/elmer/app/
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

printf '\nWSJT-X User Guide knowledge is active on the cloud host.\n'
printf 'Backup: %s\n' "$backup_root"

