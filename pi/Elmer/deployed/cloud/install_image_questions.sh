#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  echo "Run this installer as root." >&2
  exit 1
fi

package_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)
cloud_source="${package_root}/deployed/cloud/app"
page_source="${package_root}/deployed/pi/Elmer/elmer_cloud_pilot/web/gateway.html"
app_dir=/opt/elmer/app
backup_dir="/opt/elmer/backups/image-questions-$(date -u +%Y%m%dT%H%M%SZ)"

test -x /opt/elmer/venv/bin/python
test -f "${cloud_source}/answer_knowledge.py"
test -f "${cloud_source}/elmer_gateway.py"
test -f "${page_source}"

/opt/elmer/venv/bin/python -m py_compile \
  "${cloud_source}/answer_knowledge.py" \
  "${cloud_source}/elmer_gateway.py"

install -d -o elmer -g elmer -m 0750 "${backup_dir}" "${app_dir}/web"
for path in answer_knowledge.py elmer_gateway.py web/gateway.html; do
  if [[ -f "${app_dir}/${path}" ]]; then
    install -D -o elmer -g elmer -m 0640 \
      "${app_dir}/${path}" "${backup_dir}/${path}"
  fi
done

install -o elmer -g elmer -m 0640 \
  "${cloud_source}/answer_knowledge.py" \
  "${cloud_source}/elmer_gateway.py" \
  "${app_dir}/"
install -o elmer -g elmer -m 0640 "${page_source}" "${app_dir}/web/gateway.html"

systemctl restart elmer-gateway.service
for attempt in {1..30}; do
  if curl -fsS --max-time 5 http://127.0.0.1:8092/health >/dev/null; then
    echo "Hosted Ask Elmer image questions installed."
    exit 0
  fi
  sleep 1
done

echo "Elmer gateway did not become healthy after the update." >&2
systemctl --no-pager --full status elmer-gateway.service >&2 || true
exit 1
