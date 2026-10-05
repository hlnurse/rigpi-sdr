#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  printf 'Run this installer with sudo.\n' >&2
  exit 1
fi

release_root=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
target_root=/home/pi/sdr_web
python_path=/home/pi/venv/bin/python
stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_root=${target_root}/backups/ft8-manual-control-${stamp}

for name in sdr_web_server_minimal.py index.html; do
  if [[ ! -s ${release_root}/${name} || ! -s ${target_root}/${name} ]]; then
    printf 'Required RigPi-SDR file is missing: %s\n' "$name" >&2
    exit 1
  fi
done
if [[ ! -x ${python_path} ]]; then
  printf 'RigPi Python environment is missing: %s\n' "$python_path" >&2
  exit 1
fi
PYTHONPYCACHEPREFIX=/tmp/rigpi-ft8-control-pycache \
  "$python_path" -m py_compile "${release_root}/sdr_web_server_minimal.py"

active_units=()
for port in 8001 8002 8003 8004 8005; do
  unit=sdr_web@${port}.service
  if systemctl is-active --quiet "$unit"; then active_units+=("$unit"); fi
done

install -d -o pi -g pi -m 0750 "$backup_root"
install -o pi -g pi -m 0640 "${target_root}/sdr_web_server_minimal.py" "$backup_root/"
install -o pi -g pi -m 0640 "${target_root}/index.html" "$backup_root/"
install -o pi -g pi -m 0644 "${release_root}/sdr_web_server_minimal.py" "$target_root/"
install -o pi -g pi -m 0644 "${release_root}/index.html" "$target_root/"

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

printf '\nFT8/FT4 manual decoder control installed.\n'
printf 'Backup: %s\n' "$backup_root"
printf 'Restarted: %s\n' "${active_units[*]:-(none)}"
