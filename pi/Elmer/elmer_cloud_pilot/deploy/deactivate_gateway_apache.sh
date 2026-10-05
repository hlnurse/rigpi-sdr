#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  printf 'Run this rollback script as root.\n' >&2
  exit 1
fi

http_include=/etc/apache2/conf.d/userdata/std/2_4/rigpi/elmer.rigpi.net/elmer-gateway.conf
https_include=/etc/apache2/conf.d/userdata/ssl/2_4/rigpi/elmer.rigpi.net/elmer-gateway.conf

rm -f "$http_include" "$https_include"
/usr/local/cpanel/scripts/rebuildhttpdconf
apachectl -t
/usr/local/cpanel/scripts/restartsrv_httpd --graceful
printf 'The public Elmer gateway route has been removed.\n'
