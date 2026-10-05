#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  printf 'Run this rollback as root.\n' >&2
  exit 1
fi

backend=/home/pi/Elmer/elmer_web.py
page=/var/www/html/elmer.php
for backup in "${backend}.pre-cloud-pairing" "${page}.pre-cloud-pairing"; do
  if [[ ! -f ${backup} ]]; then
    printf 'Rollback file not found: %s\n' "$backup" >&2
    exit 1
  fi
done

cp -a "${backend}.pre-cloud-pairing" "${backend}"
cp -a "${page}.pre-cloud-pairing" "${page}"
systemctl restart elmer-web
printf 'Ask Elmer was restored to its pre-cloud-pairing version.\n'
