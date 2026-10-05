#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
  echo "Run this installer as root." >&2
  exit 1
fi

release_root=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
relay_source=${release_root}/elmer_web.py
patcher_source=${release_root}/elmer_cloud_pilot/deploy/patch_elmer_php_cloud.py
relay_live=/home/pi/Elmer/elmer_web.py
patcher_live=/home/pi/Elmer/elmer_cloud_pilot/deploy/patch_elmer_php_cloud.py
page=/var/www/html/elmer.php

test -s "$relay_source" || { echo "The paired RigPi relay update is missing." >&2; exit 1; }
test -s "$patcher_source" || { echo "The Ask Elmer page update is missing." >&2; exit 1; }
test -s "$page" || { echo "The Ask Elmer page is not installed." >&2; exit 1; }

python3 -m py_compile "$relay_source" "$patcher_source"
install -d -o root -g root -m 0755 /home/pi/Elmer/backups/multilingual-qso
if [[ -e "$relay_live" && ! -e /home/pi/Elmer/backups/multilingual-qso/elmer_web.py.previous ]]; then
  install -o root -g root -m 0644 "$relay_live" \
    /home/pi/Elmer/backups/multilingual-qso/elmer_web.py.previous
fi
if [[ -e "$page" && ! -e /home/pi/Elmer/backups/multilingual-qso/elmer.php.previous ]]; then
  install -o root -g root -m 0644 "$page" \
    /home/pi/Elmer/backups/multilingual-qso/elmer.php.previous
fi

install -o pi -g pi -m 0755 "$relay_source" "$relay_live"
install -o pi -g pi -m 0755 "$patcher_source" "$patcher_live"
python3 "$patcher_live" --target "$page"
php -l "$page"

systemctl restart elmer-web.service
for _attempt in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
  if curl -fsS --max-time 5 http://127.0.0.1:8090/health >/dev/null; then
    echo "Elmer multilingual QSO Ideas installed on RigPi."
    exit 0
  fi
  sleep 1
done

systemctl --no-pager --full status elmer-web.service
echo "Elmer web service did not pass its health check." >&2
exit 1
