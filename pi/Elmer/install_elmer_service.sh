#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then echo 'Run this installer as root.' >&2; exit 1; fi
cd /home/pi/Elmer

install -d -o root -g root -m 700 /etc/elmer
if [[ -n ${OPENAI_API_KEY:-} ]]; then
  umask 077; printf '%s' "$OPENAI_API_KEY" > /etc/elmer/openai_api_key
elif [[ ! -s /etc/elmer/openai_api_key ]]; then
  read -rsp 'Paste OpenAI API key: ' elmer_api_key; echo
  [[ -n $elmer_api_key ]] || { echo 'No API key supplied.' >&2; exit 1; }
  umask 077; printf '%s' "$elmer_api_key" > /etc/elmer/openai_api_key; unset elmer_api_key
fi
chown root:root /etc/elmer/openai_api_key; chmod 600 /etc/elmer/openai_api_key

install -o root -g root -m 644 deploy/elmer.php /var/www/html/elmer.php
install -o root -g root -m 644 deploy/elmerAuth.php /var/www/html/programs/elmerAuth.php
install -o root -g root -m 644 deploy/elmer-nginx.conf /etc/nginx/snippets/elmer.conf
install -o root -g root -m 644 deploy/elmer-web.service /etc/systemd/system/elmer-web.service
python3 deploy/install_rigpi_integration.py

if ! nginx -t; then
  cp /etc/nginx/sites-enabled/myserver.pre-elmer /etc/nginx/sites-enabled/myserver
  echo 'nginx validation failed; its configuration was restored.' >&2
  exit 1
fi

systemctl daemon-reload
systemctl enable --now elmer-web.service
systemctl reload nginx
sleep 1
systemctl --no-pager --full status elmer-web.service
echo
echo 'Elmer is installed. Log into RigPi as an administrator and choose Help > Ask Elmer.'
