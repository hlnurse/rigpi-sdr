#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  printf 'Run this installer with sudo.\n' >&2
  exit 1
fi

release_root=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
target_root=/home/pi/sdr_web
stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_root=${target_root}/backups/ft8-panel-layering-${stamp}

if [[ ! -s ${release_root}/index.html || ! -s ${target_root}/index.html ]]; then
  printf 'Required RigPi-SDR file is missing: index.html\n' >&2
  exit 1
fi

install -d -o pi -g pi -m 0750 "$backup_root"
install -o pi -g pi -m 0640 "${target_root}/index.html" "$backup_root/"
install -o pi -g pi -m 0644 "${release_root}/index.html" "$target_root/"

active_units=()
for port in 8001 8002 8003 8004 8005; do
  unit=sdr_web@${port}.service
  if systemctl is-active --quiet "$unit"; then active_units+=("$unit"); fi
done
for unit in "${active_units[@]}"; do systemctl restart "$unit"; done
for unit in "${active_units[@]}"; do
  port=${unit#sdr_web@}; port=${port%.service}
  for attempt in 1 2 3 4 5; do
    if curl -fsS --max-time 5 \
      "http://127.0.0.1:${port}/api/state?user=admin&radio=1" >/dev/null; then
      break
    fi
    if [[ ${attempt} -eq 5 ]]; then
      printf 'RigPi-SDR instance %s did not pass its health check.\n' "$port" >&2
      exit 1
    fi
    sleep 1
  done
done

printf '\nFT8 panel layering fix installed.\n'
printf 'Backup: %s\n' "$backup_root"
printf 'Restarted: %s\n' "${active_units[*]:-(none)}"
