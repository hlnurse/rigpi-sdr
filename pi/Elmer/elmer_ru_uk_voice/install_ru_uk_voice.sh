#!/usr/bin/env bash
set -euo pipefail

ELMER_ROOT=/home/pi/Elmer
PACKAGE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

install -o pi -g pi -m 0644 \
  "$PACKAGE_ROOT/elmer_cloud_pilot/web/elmer_voice.js" \
  "$ELMER_ROOT/elmer_cloud_pilot/web/elmer_voice.js"
install -o pi -g pi -m 0644 \
  "$PACKAGE_ROOT/elmer_cloud_pilot/web/elmer_control.js" \
  "$ELMER_ROOT/elmer_cloud_pilot/web/elmer_control.js"
install -o pi -g pi -m 0755 \
  "$PACKAGE_ROOT/elmer_cloud_pilot/deploy/patch_elmer_php_cloud.py" \
  "$ELMER_ROOT/elmer_cloud_pilot/deploy/patch_elmer_php_cloud.py"
install -o pi -g pi -m 0644 \
  "$PACKAGE_ROOT/elmer_cloud_pilot/deploy/elmer_control_panel.php" \
  "$ELMER_ROOT/elmer_cloud_pilot/deploy/elmer_control_panel.php"
install -o pi -g pi -m 0755 \
  "$PACKAGE_ROOT/elmer_cloud_pilot/tests/test_live_connectors.py" \
  "$ELMER_ROOT/elmer_cloud_pilot/tests/test_live_connectors.py"
install -o pi -g pi -m 0755 \
  "$PACKAGE_ROOT/elmer_cloud_pilot/tests/test_ru_uk_voice.py" \
  "$ELMER_ROOT/elmer_cloud_pilot/tests/test_ru_uk_voice.py"

install -o root -g root -m 0644 \
  "$PACKAGE_ROOT/elmer_cloud_pilot/web/elmer_voice.js" \
  /var/www/html/js/elmer_voice.js
install -o root -g root -m 0644 \
  "$PACKAGE_ROOT/elmer_cloud_pilot/web/elmer_control.js" \
  /var/www/html/js/elmer_control.js
install -o root -g root -m 0644 \
  "$PACKAGE_ROOT/elmer_cloud_pilot/deploy/elmer_control_panel.php" \
  /var/www/html/includes/elmer_control_panel.php

python3 "$ELMER_ROOT/elmer_cloud_pilot/deploy/patch_elmer_php_cloud.py" \
  --target /var/www/html/elmer.php

php -l /var/www/html/elmer.php
php -l /var/www/html/includes/elmer_control_panel.php
python3 "$ELMER_ROOT/elmer_cloud_pilot/tests/test_ru_uk_voice.py"

echo 'Russian and Ukrainian voice support installed.'
