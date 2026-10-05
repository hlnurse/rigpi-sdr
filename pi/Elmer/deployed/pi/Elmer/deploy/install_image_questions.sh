#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  echo "Run this installer as root." >&2
  exit 1
fi

package_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../../.." && pwd)
source_dir="${package_root}/deployed/pi/Elmer"
backup_dir="/home/pi/Elmer/backups/image-questions-$(date -u +%Y%m%dT%H%M%SZ)"

test -f "${source_dir}/answer_knowledge.py"
test -f "${source_dir}/elmer_web.py"
test -f "${package_root}/deployed/pi/installed/elmer.php"

/usr/bin/python3 -m py_compile \
  "${source_dir}/answer_knowledge.py" \
  "${source_dir}/elmer_web.py"
php -l "${package_root}/deployed/pi/installed/elmer.php"

install -d -o pi -g pi -m 0750 "${backup_dir}"
for path in /home/pi/Elmer/answer_knowledge.py /home/pi/Elmer/elmer_web.py /var/www/html/elmer.php; do
  if [[ -f "${path}" ]]; then
    install -o pi -g pi -m 0640 "${path}" "${backup_dir}/$(basename "${path}")"
  fi
done

install -o pi -g pi -m 0644 \
  "${source_dir}/answer_knowledge.py" \
  "${source_dir}/elmer_web.py" \
  /home/pi/Elmer/
install -o root -g root -m 0644 \
  "${package_root}/deployed/pi/installed/elmer.php" \
  /var/www/html/elmer.php

systemctl restart elmer-web.service
for attempt in {1..30}; do
  if curl -fsS --max-time 5 http://127.0.0.1:8090/health >/dev/null; then
    echo "RigPi Ask Elmer image questions installed."
    exit 0
  fi
  sleep 1
done

echo "Elmer station relay did not become healthy after the update." >&2
systemctl --no-pager --full status elmer-web.service >&2 || true
exit 1
