#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  printf 'Run this installer as root.\n' >&2
  exit 1
fi

release_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
backend=${release_root}/elmer_web.py
live_connector=${release_root}/live_connectors.py
patcher=${release_root}/deploy/patch_elmer_php_cloud.py
callbook_endpoint=${release_root}/deploy/ElmerCallbook.php
fcc_endpoint=${release_root}/deploy/ElmerFCC.php
fcc_geocoder=${release_root}/deploy/ElmerFCCGeocode.php
rig_control_endpoint=${release_root}/deploy/ElmerRigControl.php
rig_action_registry=${release_root}/deploy/ElmerRigActions.php
logo=${release_root}/web/RigPiW.png
voice_css=${release_root}/web/elmer_voice.css
voice_js=${release_root}/web/elmer_voice.js
stats_page=${release_root}/deploy/elmer_stats.php
live_backend=/home/pi/Elmer/elmer_web.py
live_page=/var/www/html/elmer.php

for required in "${backend}" "${live_connector}" "${patcher}" "${callbook_endpoint}" "${fcc_endpoint}" "${fcc_geocoder}" "${rig_control_endpoint}" "${rig_action_registry}" "${logo}" "${voice_css}" "${voice_js}" "${stats_page}" "${live_backend}" "${live_page}" /etc/elmer/station_credential; do
  if [[ ! -s ${required} ]]; then
    printf 'Required file is missing or empty: %s\n' "$required" >&2
    exit 1
  fi
done

if ! systemctl cat elmer-web >/dev/null 2>&1; then
  printf 'The elmer-web service is not installed.\n' >&2
  exit 1
fi

if [[ ! -e ${live_backend}.pre-cloud-pairing ]]; then
  cp -a "${live_backend}" "${live_backend}.pre-cloud-pairing"
fi

install -o pi -g pi -m 0755 "${backend}" "${live_backend}"
install -o pi -g pi -m 0644 "${live_connector}" /home/pi/Elmer/live_connectors.py
install -d -o root -g root -m 0755 /var/www/html/images
install -d -o root -g root -m 0755 /var/www/html/css /var/www/html/js
install -o root -g root -m 0644 "${logo}" /var/www/html/images/AskElmerRigPiW.png
install -o root -g root -m 0644 "${voice_css}" /var/www/html/css/elmer_voice.css
install -o root -g root -m 0644 "${voice_js}" /var/www/html/js/elmer_voice.js
install -o root -g root -m 0644 "${stats_page}" /var/www/html/elmer-stats.php
install -o root -g root -m 0644 "${callbook_endpoint}" /var/www/html/programs/ElmerCallbook.php
install -o root -g root -m 0644 "${fcc_endpoint}" /var/www/html/programs/ElmerFCC.php
install -o root -g root -m 0644 "${fcc_geocoder}" /var/www/html/programs/ElmerFCCGeocode.php
install -o root -g root -m 0644 "${rig_control_endpoint}" /var/www/html/programs/ElmerRigControl.php
install -o root -g root -m 0644 "${rig_action_registry}" /var/www/html/programs/ElmerRigActions.php
/usr/bin/python3 "${patcher}" --target "${live_page}"

PYTHONPYCACHEPREFIX=/tmp/elmer-cloud-pycache /usr/bin/python3 -m py_compile \
  "${live_backend}" /home/pi/Elmer/live_connectors.py
php -l "${live_page}"
php -l /var/www/html/elmer-stats.php
php -l /var/www/html/programs/ElmerCallbook.php
php -l /var/www/html/programs/ElmerFCC.php
php -l /var/www/html/programs/ElmerFCCGeocode.php
php -l /var/www/html/programs/ElmerRigControl.php
php -l /var/www/html/programs/ElmerRigActions.php
systemctl restart elmer-web

for attempt in 1 2 3 4 5; do
  if curl -fsS --max-time 5 http://127.0.0.1:8090/health; then
    printf '\nAsk Elmer is now using the paired cloud service.\n'
    exit 0
  fi
  sleep 1
done

printf 'The updated service did not pass its health check. Use the rollback script.\n' >&2
exit 1
