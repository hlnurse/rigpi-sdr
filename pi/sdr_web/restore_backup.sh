
Copy

#!/bin/bash
# restore_backup.sh
# Restores a factory reset backup zip
# Usage: sudo /home/pi/sdr_web/restore_backup.sh /home/pi/factory_reset_backup_YYYYMMDD_HHMMSS.zip

set -e
LOG="/var/log/rigpi-factory-reset.log"
ZIP="$1"

if [ -z "$ZIP" ] || [ ! -f "$ZIP" ]; then
	echo "ERROR: Backup file not found: $ZIP"
	exit 1
fi

echo "=== RigPi Restore $(date) from $ZIP ===" | tee -a "$LOG"

# Extract to temp dir
TMPDIR=$(mktemp -d)
unzip -q "$ZIP" -d "$TMPDIR"

# Find the backup dir inside zip
BACKUP_DIR=$(find "$TMPDIR" -maxdepth 1 -type d -name "factory_reset_backup_*" | head -1)
if [ -z "$BACKUP_DIR" ]; then
	echo "ERROR: Could not find backup directory in zip"
	rm -rf "$TMPDIR"
	exit 1
fi

echo "[R1] Restoring SDR files" | tee -a "$LOG"
if [ -d "$BACKUP_DIR/sdr_web" ]; then
	cp -rp "$BACKUP_DIR/sdr_web/." /home/pi/sdr_web/ 2>/dev/null || true
fi

echo "[R2] Restoring web files" | tee -a "$LOG"
if [ -d "$BACKUP_DIR/www" ]; then
	cp -rp "$BACKUP_DIR/www/." /var/www/html/ 2>/dev/null || true
fi

echo "[R3] Restoring database" | tee -a "$LOG"
if [ -f "$BACKUP_DIR/station_backup.sql" ]; then
	mysql -e "DROP DATABASE IF EXISTS station; CREATE DATABASE station;"
	mysql station < "$BACKUP_DIR/station_backup.sql"
fi

echo "[R4] Fixing ownership" | tee -a "$LOG"
chown -R pi:pi /home/pi/sdr_web 2>/dev/null || true
find /var/www/html -maxdepth 1 -not -path /var/www/html/phpmyadmin \
	-not -path /var/www/html/vendor -exec chown pi:pi {} \; 2>/dev/null || true
chown -R pi:pi /var/www/html/classes /var/www/html/programs \
	/var/www/html/includes /var/www/html/my 2>/dev/null || true

rm -rf "$TMPDIR"
echo "=== Restore Complete $(date) ===" | tee -a "$LOG"
echo "DONE"