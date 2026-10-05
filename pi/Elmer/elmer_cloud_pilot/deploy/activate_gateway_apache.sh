#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  printf 'Run this activation script as root.\n' >&2
  exit 1
fi

release_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
http_dir=/etc/apache2/conf.d/userdata/std/2_4/rigpi/elmer.rigpi.net
https_dir=/etc/apache2/conf.d/userdata/ssl/2_4/rigpi/elmer.rigpi.net
http_include=${http_dir}/elmer-gateway.conf
https_include=${https_dir}/elmer-gateway.conf

if [[ -e ${http_include} || -e ${https_include} ]]; then
  printf 'An Elmer gateway Apache include already exists; no changes made.\n' >&2
  exit 1
fi

curl -fsS --max-time 10 -H 'Host: localhost' \
  http://127.0.0.1:8092/health >/dev/null

install -d -o root -g root -m 0755 "$http_dir" "$https_dir"
install -o root -g root -m 0644 \
  "${release_root}/deploy/apache-elmer-http.conf" "$http_include"
install -o root -g root -m 0644 \
  "${release_root}/deploy/apache-elmer-https.conf" "$https_include"

rollback() {
  rm -f "$http_include" "$https_include"
  /usr/local/cpanel/scripts/rebuildhttpdconf >/dev/null 2>&1 || true
  /usr/local/cpanel/scripts/restartsrv_httpd --graceful >/dev/null 2>&1 || true
}
trap rollback ERR

/usr/local/cpanel/scripts/rebuildhttpdconf
apachectl -t
/usr/local/cpanel/scripts/restartsrv_httpd --graceful
trap - ERR

printf 'Apache now routes https://elmer.rigpi.net/ to the Elmer gateway.\n'
