#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  printf 'Run this installer as root.\n' >&2
  exit 1
fi

release_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
required=(
  elmer_gateway.py
  live_connectors.py
  gateway_store.py
  answer_knowledge.py
  question_knowledge.py
  owned_images.py
  reviewer_pass.py
  station_admin.py
  web/gateway.html
  web/activate.html
  web/RigPiW.png
  deploy/elmer-gateway.service
  deploy/apache-elmer-http.conf
  deploy/apache-elmer-https.conf
  deploy/activate_gateway_apache.sh
  deploy/deactivate_gateway_apache.sh
)
for relative_path in "${required[@]}"; do
  if [[ ! -f ${release_root}/${relative_path} ]]; then
    printf 'Missing gateway file: %s\n' "$relative_path" >&2
    exit 1
  fi
done

if [[ ! -x /opt/elmer/venv/bin/python || ! -f /opt/elmer/data/knowledge.db ]]; then
  printf 'Install and test the localhost Elmer pilot first.\n' >&2
  exit 1
fi

install -d -o elmer -g elmer -m 0750 \
  /opt/elmer/app/web \
  /opt/elmer/state \
  /opt/elmer/deploy

install -o elmer -g elmer -m 0640 \
  "${release_root}/elmer_gateway.py" \
  "${release_root}/live_connectors.py" \
  "${release_root}/gateway_store.py" \
  "${release_root}/answer_knowledge.py" \
  "${release_root}/question_knowledge.py" \
  "${release_root}/owned_images.py" \
  /opt/elmer/app/

install -o root -g elmer -m 0750 \
  "${release_root}/reviewer_pass.py" \
  "${release_root}/station_admin.py" \
  /opt/elmer/app/

install -o elmer -g elmer -m 0640 \
  "${release_root}/web/gateway.html" \
  "${release_root}/web/activate.html" \
  "${release_root}/web/RigPiW.png" \
  /opt/elmer/app/web/

install -o root -g elmer -m 0640 \
  "${release_root}/deploy/apache-elmer-http.conf" \
  "${release_root}/deploy/apache-elmer-https.conf" \
  /opt/elmer/deploy/

install -o root -g root -m 0750 \
  "${release_root}/deploy/activate_gateway_apache.sh" \
  "${release_root}/deploy/deactivate_gateway_apache.sh" \
  /opt/elmer/deploy/

if [[ ! -s /etc/elmer/gateway_secret ]]; then
  umask 0027
  /opt/elmer/venv/bin/python -c 'import secrets; print(secrets.token_urlsafe(48))' \
    > /etc/elmer/gateway_secret
fi
chown root:elmer /etc/elmer/gateway_secret
chmod 0640 /etc/elmer/gateway_secret

install -o root -g root -m 0644 \
  "${release_root}/deploy/elmer-gateway.service" \
  /etc/systemd/system/elmer-gateway.service
systemctl daemon-reload

printf '\nElmer gateway files installed.\n'
printf 'The gateway was not started, enabled, or connected to Apache.\n'
