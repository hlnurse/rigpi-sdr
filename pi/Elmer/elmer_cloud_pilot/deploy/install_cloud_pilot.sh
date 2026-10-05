#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  printf 'Run this installer as root.\n' >&2
  exit 1
fi

release_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
database_path=${1:-${release_root}/output/knowledge.db}

if ! getent passwd elmer >/dev/null; then
  printf 'The elmer service account does not exist.\n' >&2
  exit 1
fi

if [[ ! -x /opt/elmer/venv/bin/python ]]; then
  printf 'The Elmer Python environment is missing.\n' >&2
  exit 1
fi

required=(
  answer_knowledge.py
  live_connectors.py
  question_knowledge.py
  resolve_reference.py
  elmer_web.py
  config/current_guidance.json
  web/index.html
  deploy/elmer-cloud.service
)

for relative_path in "${required[@]}"; do
  if [[ ! -f ${release_root}/${relative_path} ]]; then
    printf 'Missing release file: %s\n' "$relative_path" >&2
    exit 1
  fi
done

if [[ ! -f ${database_path} ]]; then
  printf 'Knowledge database not found: %s\n' "$database_path" >&2
  printf 'Pass the complete Pi knowledge.db as the first argument.\n' >&2
  exit 1
fi

/opt/elmer/venv/bin/python - "$database_path" <<'PY'
import sqlite3
import sys

path=sys.argv[1]
minimums={
    'official-help':90,
    'groups-messages':11000,
    'groups-files':45,
    'hamlib-github':6000,
    'rigpi-github':400,
    'wsjtx-github':1500,
}
try:
    with sqlite3.connect(f'file:{path}?mode=ro',uri=True) as db:
        check=db.execute('PRAGMA quick_check').fetchone()[0]
        counts=dict(db.execute(
            'SELECT source_id,COUNT(*) FROM documents GROUP BY source_id'
        ))
except sqlite3.Error as exc:
    raise SystemExit(f'Unable to validate knowledge database: {exc}')
if check!='ok':
    raise SystemExit(f'Knowledge database failed quick_check: {check}')
short={key:(counts.get(key,0),minimum) for key,minimum in minimums.items()
       if counts.get(key,0)<minimum}
if short:
    details=', '.join(f'{key}={actual} (need {minimum})'
                     for key,(actual,minimum) in sorted(short.items()))
    raise SystemExit(f'Knowledge database is incomplete: {details}')
print('Knowledge database validation passed:')
for key in sorted(minimums):
    print(f'  {key}: {counts[key]} documents')
PY

install -d -o elmer -g elmer -m 0750 \
  /opt/elmer/app/config \
  /opt/elmer/app/web \
  /opt/elmer/data \
  /opt/elmer/state

install -o elmer -g elmer -m 0640 \
  "${release_root}/answer_knowledge.py" \
  "${release_root}/live_connectors.py" \
  "${release_root}/question_knowledge.py" \
  "${release_root}/resolve_reference.py" \
  "${release_root}/elmer_web.py" \
  /opt/elmer/app/

install -o elmer -g elmer -m 0640 \
  "${release_root}/config/current_guidance.json" \
  /opt/elmer/app/config/current_guidance.json

install -o elmer -g elmer -m 0640 \
  "${release_root}/web/index.html" \
  /opt/elmer/app/web/index.html

install -o root -g elmer -m 0640 \
  "${database_path}" \
  /opt/elmer/data/knowledge.db

install -d -o root -g elmer -m 0750 /etc/elmer
install -o root -g root -m 0644 \
  "${release_root}/deploy/elmer-cloud.service" \
  /etc/systemd/system/elmer-cloud.service

systemctl daemon-reload

printf '\nElmer cloud pilot files installed.\n'
printf 'The service was not started or enabled.\n'
printf 'Create /etc/elmer/openai_api_key, then start the service explicitly.\n'
