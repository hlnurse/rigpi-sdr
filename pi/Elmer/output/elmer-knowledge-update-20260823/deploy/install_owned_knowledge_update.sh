#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  printf 'Run this installer as root.\n' >&2
  exit 1
fi
if [[ $# -ne 2 ]]; then
  printf 'Usage: %s KNOWLEDGE_DB OWNED_IMAGES_DIRECTORY\n' "$0" >&2
  exit 1
fi

release_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
source_db=$1
source_images=$2
validator=${release_root}/validate_owned_images.py
for required in "${source_db}" "${source_images}/manifest.json" "${validator}"; do
  if [[ ! -e ${required} ]]; then
    printf 'Required update input not found: %s\n' "$required" >&2
    exit 1
  fi
done
if [[ ! -x /opt/elmer/venv/bin/python ]]; then
  printf 'Elmer Python environment is not installed.\n' >&2
  exit 1
fi

/opt/elmer/venv/bin/python "${validator}" --db "${source_db}" --images "${source_images}"

stamp=$(date -u +%Y%m%dT%H%M%SZ)
staging_db=/opt/elmer/data/.knowledge.db.${stamp}
staging_images=/opt/elmer/data/.owned-images.${stamp}
install -o elmer -g elmer -m 0640 "${source_db}" "${staging_db}"
install -d -o elmer -g elmer -m 0750 "${staging_images}"
cp -a "${source_images}/." "${staging_images}/"
chown -R elmer:elmer "${staging_images}"
find "${staging_images}" -type d -exec chmod 0750 {} +
find "${staging_images}" -type f -exec chmod 0640 {} +
/opt/elmer/venv/bin/python "${validator}" --db "${staging_db}" --images "${staging_images}"

if [[ -e /opt/elmer/data/owned-images ]]; then
  mv /opt/elmer/data/owned-images "/opt/elmer/data/owned-images.backup-${stamp}"
fi
mv "${staging_images}" /opt/elmer/data/owned-images
if [[ -e /opt/elmer/data/knowledge.db ]]; then
  mv /opt/elmer/data/knowledge.db "/opt/elmer/data/knowledge.db.backup-${stamp}"
fi
mv "${staging_db}" /opt/elmer/data/knowledge.db
systemctl restart elmer-cloud elmer-gateway
curl -fsS --max-time 10 http://127.0.0.1:8092/health
printf '\nOwned Help images and matching knowledge database installed.\n'
