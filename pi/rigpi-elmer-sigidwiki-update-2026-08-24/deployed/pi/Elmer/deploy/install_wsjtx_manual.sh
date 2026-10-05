#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  printf 'Run this installer with sudo.\n' >&2
  exit 1
fi

release_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
target_root=${ELMER_ROOT:-/home/pi/Elmer}
python_path=${ELMER_PYTHON:-/home/pi/venv/bin/python}
stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_root=${target_root}/backups/wsjtx-manual-${stamp}

required=(
  build_knowledge.py
  github_connector.py
  html_manual_connector.py
  sync_github.py
  sync_manual.py
  configure_wsjtx_manual.py
  mediawiki_connector.py
  sync_mediawiki.py
  configure_sigidwiki.py
  question_knowledge.py
  answer_knowledge.py
)

for name in "${required[@]}"; do
  if [[ ! -f ${release_root}/${name} ]]; then
    printf 'Release file is missing: %s\n' "$name" >&2
    exit 1
  fi
done
if [[ ! -x ${python_path} ]]; then
  printf 'Elmer Python environment is missing: %s\n' "$python_path" >&2
  exit 1
fi
if [[ ! -f ${target_root}/config.json || ! -f ${target_root}/config/connectors.json ]]; then
  printf 'Elmer configuration was not found under %s.\n' "$target_root" >&2
  exit 1
fi

install -d -o pi -g pi -m 0750 "$backup_root"
for name in "${required[@]}"; do
  if [[ -f ${target_root}/${name} ]]; then
    cp -a "${target_root}/${name}" "$backup_root/"
  fi
done
cp -a "${target_root}/config/connectors.json" "$backup_root/connectors.json"
if [[ -f ${target_root}/output/knowledge.db ]]; then
  cp -a "${target_root}/output/knowledge.db" "$backup_root/knowledge.db"
fi

for name in "${required[@]}"; do
  install -o pi -g pi -m 0644 "${release_root}/${name}" "$target_root/$name"
done

# Older root-run builds can leave these caches unreadable to the normal Pi
# builder account. Limit the ownership repair to this update's cache paths.
for cache_path in \
  "$target_root/cache/connectors/wsjtx-github" \
  "$target_root/cache/connectors/wsjtx-github.staging" \
  "$target_root/cache/connectors/wsjtx-manual"; do
  if [[ -e ${cache_path} ]]; then
    chown -R pi:pi "$cache_path"
  fi
done

runuser -u pi -- "$python_path" \
  "$target_root/configure_wsjtx_manual.py" \
  --connectors "$target_root/config/connectors.json"
runuser -u pi -- "$python_path" \
  "$target_root/configure_sigidwiki.py" \
  --connectors "$target_root/config/connectors.json"
runuser -u pi -- "$python_path" \
  "$target_root/sync_github.py" \
  --config "$target_root/config.json" --connector wsjtx-github
runuser -u pi -- "$python_path" \
  "$target_root/sync_manual.py" \
  --config "$target_root/config.json" --connector wsjtx-manual
runuser -u pi -- "$python_path" \
  "$target_root/sync_mediawiki.py" \
  --config "$target_root/config.json" --connector sigidwiki-amateur-radio
runuser -u pi -- "$python_path" \
  "$target_root/build_knowledge.py" --config "$target_root/config.json"

runuser -u pi -- "$python_path" - "$target_root/output/knowledge.db" <<'PY'
import sqlite3
import sys

with sqlite3.connect(f'file:{sys.argv[1]}?mode=ro',uri=True) as database:
    check=database.execute('PRAGMA quick_check').fetchone()[0]
    manual=database.execute(
        "SELECT COUNT(*) FROM documents WHERE source_id='wsjtx-manual' "
        "AND source_type='wsjtx_manual'"
    ).fetchone()[0]
    sources=database.execute(
        "SELECT COUNT(*) FROM documents WHERE source_id='wsjtx-github' "
        "AND source_type='github_manual'"
    ).fetchone()[0]
    signals=database.execute(
        "SELECT COUNT(*) FROM documents WHERE source_id='sigidwiki-amateur-radio' "
        "AND source_type='signal_wiki'"
    ).fetchone()[0]
if check!='ok' or manual<40 or sources<40 or signals<50:
    raise SystemExit(
        f'Validation failed: quick_check={check}, HTML sections={manual}, '
        f'AsciiDoc sections={sources}, signal pages={signals}'
    )
print(f'Validated WSJT-X manual: {manual} HTML and {sources} AsciiDoc sections')
print(f'Validated SIGIDWiki: {signals} amateur-radio signal pages')
PY

if systemctl is-active --quiet elmer-web.service; then
  systemctl restart elmer-web.service
fi

printf '\nWSJT-X User Guide ingestion is installed.\n'
printf 'Backup: %s\n' "$backup_root"
printf 'Knowledge database: %s/output/knowledge.db\n' "$target_root"
