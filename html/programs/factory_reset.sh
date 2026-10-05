#!/bin/bash
# factory_reset.sh
# RigPi Factory Reset — admin only
# Run as: sudo /var/www/html/programs/factory_reset.sh
# Detaches remote services, creates a temporary recovery backup, then cleans
# files, logs, credentials, and the station database.

set -e
umask 077

LOG="/var/log/rigpi-factory-reset.log"
BACKUP_DIR="/home/pi/factory_reset_backup_$(date +%Y%m%d_%H%M%S)"
SDR_DIR="/home/pi/sdr_web"
WEB_DIR="/var/www/html"
DB="station"
KEEP_RESET_BACKUP="${RIGPI_FACTORY_RESET_KEEP_BACKUP:-0}"

clear_directory() {
	local directory="$1"
	if [ -d "$directory" ]; then
		find "$directory" -mindepth 1 -delete 2>/dev/null || true
	fi
}

remove_release_backup_files() {
	local directory="$1"
	if [ -d "$directory" ]; then
		find "$directory" -xdev -type f \( \
			-name "*.bak*" -o \
			-name "*.backup-*" -o \
			-name "*.before-*" -o \
			-name "*.pre-*" -o \
			-name "*.previous" -o \
			-name "*.save_*" \
		\) -delete 2>/dev/null || true
	fi
}

echo "=== RigPi Factory Reset $(date) ===" | tee -a "$LOG"

# ── 0. Detach station from persistent remote services ──────────────────────────
# Factory reset must not leave a public tunnel, station identity, API key, or
# VPN private key behind. The software remains installed for later onboarding.
echo "[0] Detaching persistent remote services" | tee -a "$LOG"

systemctl disable --now cloudflared.service >>"$LOG" 2>&1 || true
if command -v cloudflared >/dev/null 2>&1; then
	cloudflared service uninstall >>"$LOG" 2>&1 || true
fi
rm -f /etc/systemd/system/cloudflared.service \
	/etc/systemd/system/multi-user.target.wants/cloudflared.service \
	/etc/default/cloudflared /etc/sysconfig/cloudflared \
	/etc/rigpi/cloudflare-token 2>/dev/null || true
clear_directory /etc/cloudflared
clear_directory /usr/local/etc/cloudflared
clear_directory /root/.cloudflared
clear_directory /home/pi/.cloudflared

# Remove the station owner's DuckDNS token and Let's Encrypt certificate.
# The DuckDNS hostname remains in the owner's online DuckDNS account.
if [ -x /usr/local/sbin/rigpi-duckdns-https ]; then
	/usr/local/sbin/rigpi-duckdns-https remove >>"$LOG" 2>&1 || true
fi
systemctl disable --now rigpi-duckdns-update.timer >>"$LOG" 2>&1 || true
sed -i '\|include /etc/nginx/snippets/rigpi-https.conf;|d' \
	/etc/nginx/sites-enabled/myserver 2>/dev/null || true
rm -f /etc/letsencrypt/renewal/rigpi-duckdns.conf 2>/dev/null || true
rm -rf /etc/letsencrypt/live/rigpi-duckdns \
	/etc/letsencrypt/archive/rigpi-duckdns 2>/dev/null || true
clear_directory /etc/rigpi/https
/usr/sbin/nginx -t >>"$LOG" 2>&1 && systemctl reload nginx >>"$LOG" 2>&1 || true

systemctl disable --now elmer-web.service >>"$LOG" 2>&1 || true
clear_directory /etc/elmer

# Revoke any administrator-enabled temporary SSH support key. Preserve the
# owner's unrelated authorized keys.
if [ -x /usr/local/sbin/rigpi-support-access ]; then
	/usr/local/sbin/rigpi-support-access revoke >>"$LOG" 2>&1 || true
fi

systemctl disable --now 'wg-quick@*.service' >>"$LOG" 2>&1 || true
clear_directory /etc/wireguard

# Detach ZeroTier without uninstalling it. Preserve only the package-supplied
# planet file and command symlinks; identities, tokens, peers, and joined
# networks belong to the previous owner.
systemctl disable --now zerotier-one.service >>"$LOG" 2>&1 || true
if [ -d /var/lib/zerotier-one ]; then
	find /var/lib/zerotier-one -mindepth 1 \
		! -path /var/lib/zerotier-one/planet \
		! -path /var/lib/zerotier-one/zerotier-one \
		! -path /var/lib/zerotier-one/zerotier-cli \
		! -path /var/lib/zerotier-one/zerotier-idtool -delete 2>/dev/null || true
fi

systemctl daemon-reload >>"$LOG" 2>&1 || true
echo "[0] Local Cloudflare, DuckDNS HTTPS, Elmer, WireGuard, and ZeroTier identities removed" | tee -a "$LOG"
echo "[0] NOTE: The cloud service must also revoke this station's server-side credentials." | tee -a "$LOG"

# ── 1. Create backup ──────────────────────────────────────────────────────────
# Clean up old backups — keep only the 2 most recent
echo "[1] Cleaning old backups" | tee -a "$LOG"
ls -t /home/pi/factory_reset_backup_*.zip 2>/dev/null | tail -n +3 | xargs rm -f 2>/dev/null || true
echo "[1] Creating backup at $BACKUP_DIR" | tee -a "$LOG"
mkdir -p "$BACKUP_DIR/sdr_web" "$BACKUP_DIR/www"

# SDR: backup running files and config
cp -p "$SDR_DIR/index.html" "$SDR_DIR/sdr_web_server_minimal.py" \
	"$SDR_DIR/provision_sdr_user.sh" "$SDR_DIR/watchdog.sh" \
	"$SDR_DIR/elmer_workability.py" \
	"$BACKUP_DIR/sdr_web/" 2>/dev/null || true
cp -rp "$SDR_DIR/config" "$SDR_DIR/settings" "$BACKUP_DIR/sdr_web/" 2>/dev/null || true

# Web: backup only files modified since factory install
find "$WEB_DIR" -maxdepth 1 -newer "$WEB_DIR/LICENSE" -type f \
	-exec cp -p {} "$BACKUP_DIR/www/" \; 2>/dev/null || true
cp -rp "$WEB_DIR/classes" "$WEB_DIR/includes" "$WEB_DIR/my" "$BACKUP_DIR/www/" 2>/dev/null || true
mkdir -p "$BACKUP_DIR/www/programs"
find "$WEB_DIR/programs" -maxdepth 1 -name "*.php" -exec cp -p {} "$BACKUP_DIR/www/programs/" \; 2>/dev/null || true

# Backup DB
mysqldump "$DB" > "$BACKUP_DIR/station_backup.sql" 2>/dev/null || true
echo "[1] Zipping backup" | tee -a "$LOG"
cd /home/pi && zip -qr "${BACKUP_DIR}.zip" "$(basename $BACKUP_DIR)" 2>/dev/null && rm -rf "$BACKUP_DIR"
chown pi:pi "${BACKUP_DIR}.zip" 2>/dev/null || true
chmod 600 "${BACKUP_DIR}.zip" 2>/dev/null || true
echo "[1] Backup complete: ${BACKUP_DIR}.zip" | tee -a "$LOG"

# ── 1b. Remove development and installer backups ─────────────────────────────
# These are working backups made while preparing and installing RigPi releases,
# not user exports. Limit cleanup to RigPi-owned application trees and explicitly
# named RigPi installer-backup locations.
echo "[1b] Removing release-development backups" | tee -a "$LOG"
remove_release_backup_files "$WEB_DIR"
remove_release_backup_files "$SDR_DIR"
remove_release_backup_files /home/pi/Elmer
remove_release_backup_files /opt/elmer/app
remove_release_backup_files /opt/elmer/data

clear_directory "$SDR_DIR/backups"
rmdir "$SDR_DIR/backups" 2>/dev/null || true
clear_directory /home/pi/Elmer/backups
rmdir /home/pi/Elmer/backups 2>/dev/null || true
clear_directory /opt/elmer/backups
rmdir /opt/elmer/backups 2>/dev/null || true
clear_directory /var/backups/rigpi-elmer-service-settings
rmdir /var/backups/rigpi-elmer-service-settings 2>/dev/null || true
find /var/backups -mindepth 1 -maxdepth 1 -type d -name "Help-*" \
	-exec rm -rf -- {} + 2>/dev/null || true

# RX-888 installation backups live beside their system targets rather than in
# an application backup directory, so remove only those exact backup families.
find /usr/local/sbin -maxdepth 1 -type f \
	-name "rigpi-rx888-firmware.backup-*" -delete 2>/dev/null || true
find /etc/systemd/system -maxdepth 1 -type f \
	-name "rigpi-rx888-firmware.service.backup-*" -delete 2>/dev/null || true
find /etc/udev/rules.d -maxdepth 1 -type f \
	-name "99-rigpi-rx888-autoload.rules.backup-*" -delete 2>/dev/null || true
find /etc/systemd/system/sdr_web@.service.d -maxdepth 1 -type f \
	-name "20-rx888-firmware.conf.backup-*" -delete 2>/dev/null || true
echo "[1b] Release-development backups removed" | tee -a "$LOG"

# ── 2. Clean up SDR directory ─────────────────────────────────────────────────
echo "[2] Cleaning SDR directory" | tee -a "$LOG"

find "$SDR_DIR" -maxdepth 1 -name "*copy*" -type f -delete 2>/dev/null || true
find "$SDR_DIR" -maxdepth 1 -name "*.bak*" -type f -delete 2>/dev/null || true
find "$SDR_DIR" -maxdepth 1 -name "*.bak" -type f -delete 2>/dev/null || true

for f in "0" "index1.html" "index.diff" "index.hhhh" "index.html.bak" "index.html.bak2" \
		 "indexmmmmmmm.html" "indexOLD.html" "indexQ.html" "indexR.html" "indexS.html" \
		 "indexT.html" "indexXXX.html" "index2 copy 2.html" "index2 copy.html" \
		 "index-audio-vu-scope-24.html" "index_bak_good.html" \
		 "sdr_web_server_minimal_32.py" "sdr_web_server_minimal_bak_good.py" \
		 "sdr_web_server_minimal.py.bak" "sdr_web_server_minimal.py.good" \
		 "sdr_web_server_minimal.py.test" "sdr_web_server_minimal copy.py" \
		 "sdr_web_server.py" "sdr_web_serverOLD.py" "sdr_web_serverXXXXX.py" \
		 "sdr_web_server-audio-vu-scope-21.py" \
		 "webrtc_audio_tone.py" "webrtc_audio_tone.py.WORKING" \
		 "webrtc_audio_wfm_hfplus copy.py" "webrtc_audio_wfm_hfplus copy 2.py" \
		 "webrtc_audio_wfm_hfplus copy 3.py" "webrtc_audio_wfm_hfplus.py" \
		 "webrtc_audio_wfm_hfplus.py.WORKING" \
		 "owrx_sniff.py" "patch_audio.py" "rigpi-provision-sudoers" \
		 "sdr_web.log" "index.diff" "server.diff" "x.py" \
		 "untitled.py" "untitled 2.py" "world_mapxxx.jpg" \
		 "sdr_web_server_minimal copy 28.py"; do
	rm -f "$SDR_DIR/$f" 2>/dev/null || true
done

find "$SDR_DIR" -maxdepth 1 -name "sdr_web_server_minimal copy*.py" -delete 2>/dev/null || true
find "$SDR_DIR" -maxdepth 1 -name "sdr_web_server copy*.py" -delete 2>/dev/null || true
find "$SDR_DIR" -maxdepth 1 -name "index copy*.html" -delete 2>/dev/null || true
find "$SDR_DIR" -maxdepth 1 -name "index2 copy*.html" -delete 2>/dev/null || true

echo "[2] SDR directory cleaned" | tee -a "$LOG"

# ── 2b. Remove large junk files ──────────────────────────────────────────────
echo "[2b] Removing large junk files" | tee -a "$LOG"
rm -f "$WEB_DIR/programs/ws.pcap" 2>/dev/null || true
echo "[2b] Done" | tee -a "$LOG"

# ── 3. Clean up /var/www/html ────────────────────────────────────────────────
echo "[3] Cleaning web directory" | tee -a "$LOG"

find "$WEB_DIR" -maxdepth 1 -name "*copy*" -type f -delete 2>/dev/null || true
find "$WEB_DIR" -maxdepth 1 -name "*.bak" -type f -delete 2>/dev/null || true
find "$WEB_DIR" -maxdepth 1 -name "*.bak2" -type f -delete 2>/dev/null || true

for f in "untitled.php" "untitled 2.php" "test copy.html"; do
	rm -f "$WEB_DIR/$f" 2>/dev/null || true
done

echo "[3] Web directory cleaned" | tee -a "$LOG"

# ── 3b. Clean system logs ───────────────────────────────────────────────────
echo "[3b] Cleaning system logs" | tee -a "$LOG"
find /var/log -name "*.gz" -delete 2>/dev/null || true
find /var/log -name "*.1" -delete 2>/dev/null || true
find /var/log -name "*.2" -delete 2>/dev/null || true
echo "[3b] System logs cleaned" | tee -a "$LOG"
sudo truncate -s 0 /var/log/nginx/access.log 2>/dev/null || true
sudo truncate -s 0 /var/log/nginx/error.log 2>/dev/null || true
sudo truncate -s 0 /var/log/rigpi-access.log 2>/dev/null || true
echo "[3b] Nginx logs cleared" | tee -a "$LOG"
sudo truncate -s 0 /var/log/cloud-init.log 2>/dev/null || true
sudo truncate -s 0 /var/log/cloud-init-output.log 2>/dev/null || true
sudo truncate -s 0 /var/log/msmtp.log 2>/dev/null || true
echo "[3b] Additional logs cleared" | tee -a "$LOG"
sudo rm -rf /root/Downloads/* 2>/dev/null || true
sudo rm -rf /root/Desktop/* 2>/dev/null || true
sudo rm -f /root/.bash_history 2>/dev/null || true
echo "[3b] Root home cleaned" | tee -a "$LOG"
sudo rm -f /root/sdrplay_license.txt 2>/dev/null || true
sudo rm -f /root/.bash_history 2>/dev/null || true
sudo rm -f /home/pi/.bash_history 2>/dev/null || true
history -c 2>/dev/null || true
echo "[3b] Shell history cleared" | tee -a "$LOG"
# Clear SDR recordings
find /home/pi/sdr_web -name "recordings_800*" -type d | while read dir; do
	rm -f "$dir"/* 2>/dev/null || true
done
echo "[3b] SDR recordings cleared" | tee -a "$LOG"
sudo chown -R www-data:www-data /var/www/html/ 2>/dev/null || true
sudo find /var/www/html -type f -name "*.php" -exec chmod 644 {} \;
sudo find /var/www/html -type d -exec chmod 755 {} \;
echo "[3b] Web ownership reset" | tee -a "$LOG"
sudo truncate -s 0 /var/log/wtmp 2>/dev/null || true
sudo truncate -s 0 /var/log/btmp 2>/dev/null || true
sudo truncate -s 0 /var/log/lastlog 2>/dev/null || true
echo "[3b] Login history cleared" | tee -a "$LOG"

# Browser password stores, cookies, history, sessions, site storage, and
# caches cannot be cleaned reliably while the desktop browser is running.
# Closing only pi's browser processes leaves this server-side reset running.
pkill -TERM -u pi chromium 2>/dev/null || true
pkill -TERM -u pi firefox 2>/dev/null || true
pkill -TERM -u pi firefox-esr 2>/dev/null || true
sleep 2
for browser_directory in \
	/home/pi/.config/chromium /home/pi/.cache/chromium \
	/home/pi/.mozilla/firefox /home/pi/.cache/mozilla \
	/root/.config/chromium /root/.cache/chromium \
	/root/.mozilla/firefox /root/.cache/mozilla; do
	clear_directory "$browser_directory"
done
echo "[3b] Chromium and Firefox profiles cleared" | tee -a "$LOG"
sudo truncate -s 0 /var/log/alternatives.log 2>/dev/null || true
sudo truncate -s 0 /var/log/dpkg.log 2>/dev/null || true
sudo truncate -s 0 /var/log/fontconfig.log 2>/dev/null || true
sudo truncate -s 0 /var/log/php8.4-fpm.log 2>/dev/null || true
sudo truncate -s 0 /var/log/rigpi-provision.log 2>/dev/null || true
sudo truncate -s 0 /var/log/rigpi-radio.log 2>/dev/null || true
sudo truncate -s 0 /var/log/rigpi-rotor.log 2>/dev/null || true
sudo truncate -s 0 /var/log/rigpi-factory-reset.log 2>/dev/null || true
sudo truncate -s 0 /var/log/wtmp.db 2>/dev/null || true
sudo truncate -s 0 /var/log/boot.log 2>/dev/null || true
echo "[3b] Additional logs cleared" | tee -a "$LOG"

# ── 3c. Clear WSJTX and FLDigi user data ─────────────────────────────────────
echo "[3c] Clearing WSJTX/FLDigi data" | tee -a "$LOG"
rm -rf /home/pi/.local/share/WSJT-X/log/* 2>/dev/null || true
rm -rf /home/pi/.local/share/WSJT-X/save/* 2>/dev/null || true
rm -rf /home/pi/.fldigi/logs/* 2>/dev/null || true
rm -rf /home/pi/.fldigi/temp/* 2>/dev/null || true
echo "[3c] WSJTX/FLDigi data cleared" | tee -a "$LOG"

# ── 4. Reset logs ─────────────────────────────────────────────────────────────
echo "[4] Resetting logs" | tee -a "$LOG"
> /var/log/rigpi-provision.log      2>/dev/null || true
> /var/log/rigpi-access.log         2>/dev/null || true
> /var/log/rigpi-factory-reset.log  2>/dev/null || true
> "$WEB_DIR/my/account_requests.log" 2>/dev/null || true
> "$SDR_DIR/watchdog.log"           2>/dev/null || true
> "$SDR_DIR/sdr_web.log"            2>/dev/null || true
echo "[4] Logs cleared" | tee -a "$LOG"

# ── 4b. Clear Downloads ──────────────────────────────────────────────────────
echo "[4b] Clearing Downloads" | tee -a "$LOG"
find /home/pi/Downloads -mindepth 1 -delete 2>/dev/null || true
echo "[4b] Downloads cleared" | tee -a "$LOG"

# ── 5. Reset /tmp ─────────────────────────────────────────────────────────────
echo "[5] Clearing RigPi temporary files" | tee -a "$LOG"
find /tmp -mindepth 1 -maxdepth 1 \
	\( -name 'rigpi-*' -o -name 'RigPi_*' -o -name 'elmer-*' -o -name 'Elmer_*' \) \
	-delete 2>/dev/null || true
echo "[5] RigPi temporary files cleared" | tee -a "$LOG"

# ── 6. Reset DB from factory SQL ──────────────────────────────────────────────
echo "[6] Resetting database using factory defaults" | tee -a "$LOG"
FACTORY_SQL="$WEB_DIR/Databases/station.sql"
ELMER_FREQUENCY_SQL="$WEB_DIR/Databases/elmer_frequency_intelligence.sql"
if [ -f "$FACTORY_SQL" ]; then
	mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB;"
	mysql "$DB" < "$FACTORY_SQL"
	if [ -f "$ELMER_FREQUENCY_SQL" ]; then
		mysql "$DB" < "$ELMER_FREQUENCY_SQL"
	else
		echo "[6] WARNING: Elmer frequency SQL not found at $ELMER_FREQUENCY_SQL" | tee -a "$LOG"
	fi
	echo "[6] Database reset complete" | tee -a "$LOG"
else
	echo "[6] WARNING: Factory SQL not found at $FACTORY_SQL" | tee -a "$LOG"
fi

# ── 6b. Reset user passwords ──────────────────────────────────────────────────
echo "[6b] Resetting user passwords" | tee -a "$LOG"
mysql station -e "UPDATE Users SET Password='' WHERE Username='admin';"
mysql station -e "UPDATE Users SET Password='4180b5120ca2e09eaa3bd2ebf4b53667' WHERE Username='guest1';"
mysql station -e "UPDATE Users SET Password='82273dfbbc9cc64149d6e6d52d3104fa' WHERE Username='guest2';"
mysql station -e "UPDATE Users SET Password='88cf91a1aef212f3c2cd12406983427d' WHERE Username='guest3';"
echo "[6b] Passwords reset" | tee -a "$LOG"

# ── 6b2. Reset SDRHost to dynamic hostname ───────────────────────────────────
echo "[6b2] Resetting SDRHost fields" | tee -a "$LOG"
RIGPI_HOSTNAME=$(hostname -s)
mysql station -e "UPDATE Users SET SDRHost=CONCAT('http://${RIGPI_HOSTNAME}.local/sdr', uID), SDRHostRemote='' WHERE uID >= 1;"
echo "[6b2] SDRHost reset complete" | tee -a "$LOG"

# ── 6c. Clear SDR save/bak files ─────────────────────────────────────────────
echo "[6c] Clearing SDR save/bak files" | tee -a "$LOG"
find "$SDR_DIR" -maxdepth 1 -name "*.save_*" -type f -delete 2>/dev/null || true
find "$SDR_DIR" -maxdepth 1 -name "*.bak_*" -type f -delete 2>/dev/null || true
find "$SDR_DIR" -maxdepth 1 -name "patch_*.py" -type f -delete 2>/dev/null || true
find "$SDR_DIR" -maxdepth 1 -name "elmer_workability.py.save_*" -type f -delete 2>/dev/null || true
rm -f "$SDR_DIR/messages.json" 2>/dev/null || true
echo "[6c] SDR save/bak files cleared" | tee -a "$LOG"

# ── 6d. Restore WireGuard Keyer settings ─────────────────────────────────────
echo "[6d] Restoring WireGuard CW keyer settings" | tee -a "$LOG"
mysql station -e "UPDATE Keyer SET WKRemoteIP='10.200.200.2', WKRemotePort='30040', WKFunction='2' WHERE Radio=1;"
echo "[6d] WireGuard keyer settings restored" | tee -a "$LOG"

# ── 7. Clear SDR user settings ────────────────────────────────────────────────
echo "[7] Clearing SDR user settings" | tee -a "$LOG"
SETTINGS_DIR="$SDR_DIR/settings"
if [ -d "$SDR_DIR/config/users" ]; then
		find "$SDR_DIR/config/users" -name "*.json" -delete 2>/dev/null || true
fi
if [ -d "$SETTINGS_DIR" ]; then
	find "$SETTINGS_DIR" -name "*.json" -not -name "default*" -delete 2>/dev/null || true
fi
echo "[7] SDR user settings cleared" | tee -a "$LOG"

# ── 8. Fix ownership ─────────────────────────────────────────────────────────
echo "[8] Fixing ownership" | tee -a "$LOG"
find "$WEB_DIR" -maxdepth 1 -not -path "$WEB_DIR/phpmyadmin" -not -path "$WEB_DIR/vendor" \
	-exec chown www-data:www-data {} \; 2>/dev/null || true
chown -R www-data:www-data "$WEB_DIR/classes" "$WEB_DIR/programs" "$WEB_DIR/includes" \
	"$WEB_DIR/my" 2>/dev/null || true
# This script is invoked through a narrowly scoped sudo rule and must never be
# writable by the web-server account.
chown root:root "$WEB_DIR/programs/factory_reset.sh" 2>/dev/null || true
chmod 0755 "$WEB_DIR/programs/factory_reset.sh" 2>/dev/null || true
chown -R pi:pi "$SDR_DIR" 2>/dev/null || true
chmod +x "$SDR_DIR/provision_sdr_user.sh" 2>/dev/null || true
chmod +x "$WEB_DIR/SpotsDo.php" 2>/dev/null || true
# Elmer's cached repeater and shortwave files live below a private application
# directory.  Permit the web worker to traverse that one known path without
# granting directory listing or read access to Elmer configuration and secrets.
if command -v setfacl >/dev/null 2>&1 && [ -d /home/pi/Elmer/cache ]; then
	setfacl -m u:www-data:--x /home/pi/Elmer 2>/dev/null || true
fi
echo "[8] Ownership fixed" | tee -a "$LOG"

# ── 9. Restore sudoers for www-data ──────────────────────────────────────────
echo "[9] Restoring sudoers" | tee -a "$LOG"
echo "www-data ALL=(ALL) NOPASSWD: /sbin/reboot" > /etc/sudoers.d/www-data-reboot
echo "www-data ALL=(ALL) NOPASSWD: /sbin/halt" >> /etc/sudoers.d/www-data-reboot
chmod 440 /etc/sudoers.d/www-data-reboot
echo "[9] Sudoers restored" | tee -a "$LOG"



# ── 10. Fix instance config rigports ─────────────────────────────────────────
echo "[10] Fixing instance config rigports" | tee -a "$LOG"
python3 - << 'PYEOF'
import json
for port, rigport in [(8001,4532),(8002,4534),(8003,4536),(8004,4538)]:
	fname = f'/home/pi/sdr_web/config/instance_{port}.json'
	try:
		with open(fname) as f: cfg = json.load(f)
		cfg['rigport'] = rigport
		with open(fname,'w') as f: json.dump(cfg, f, indent=2)
		print(f"instance_{port}.json rigport={rigport}")
	except: pass
PYEOF
echo "[10] Instance configs fixed" | tee -a "$LOG"

# ── 11. Fix pcmanfm permissions ───────────────────────────────────────────────
echo "[11] Fixing pcmanfm permissions" | tee -a "$LOG"
find /home/pi/.config/pcmanfm -name "desktop-items*.conf" -exec chmod 666 {} \; 2>/dev/null || true
echo "[11] pcmanfm permissions fixed" | tee -a "$LOG"

# ── 12. SDRConnect removed — licensing compliance ────────────────────────────
echo "[12] SDRConnect removed (licensing)" | tee -a "$LOG"

# ── 13. Remove legacy persistent rigctld-dummy services ──────────────────────
# These per-instance dummy rigctlds were started at boot and had NO instance
# tag, so RigPi's reaper (which matches "-C instance=1234N") could never clean
# them up, and they squatted on the ports h.php needs. RigPi now spawns dummy
# rigctlds on demand, correctly tagged, on port 4530+2*radio, reaped on owner
# relaunch. So these standing services must be torn down, not started.
echo "[13] Removing legacy rigctld-dummy services" | tee -a "$LOG"
for i in 1 2 3 4 5 6 7 8; do
	systemctl disable --now rigctld-dummy${i} 2>/dev/null || true
	rm -f /etc/systemd/system/rigctld-dummy${i}.service 2>/dev/null || true
done
systemctl daemon-reload 2>/dev/null || true
# Kill any stray rigctld left running from a previous image
pkill -f "rigctld -m" 2>/dev/null || true
echo "[13] Legacy rigctld-dummy services removed" | tee -a "$LOG"

# ── 14. SDRConnect removed — licensing compliance ────────────────────────────
echo "[14] SDRConnect removed (licensing)" | tee -a "$LOG"

# ── 15. Security verification and recovery-backup disposition ──
echo "[15] Verifying remote services and credentials are detached" | tee -a "$LOG"
RESET_SECURITY_FAILURE=0

if systemctl is-active --quiet cloudflared.service; then
	echo "[15] ERROR: cloudflared is still active" | tee -a "$LOG"
	RESET_SECURITY_FAILURE=1
fi
if systemctl is-enabled --quiet cloudflared.service 2>/dev/null; then
	echo "[15] ERROR: cloudflared is still enabled" | tee -a "$LOG"
	RESET_SECURITY_FAILURE=1
fi
if systemctl is-active --quiet rigpi-duckdns-update.timer || \
	systemctl is-enabled --quiet rigpi-duckdns-update.timer 2>/dev/null; then
	echo "[15] ERROR: DuckDNS address updates are still active or enabled" | tee -a "$LOG"
	RESET_SECURITY_FAILURE=1
fi
if grep -Fq 'include /etc/nginx/snippets/rigpi-https.conf;' \
	/etc/nginx/sites-enabled/myserver 2>/dev/null; then
	echo "[15] ERROR: native HTTPS remains enabled in nginx" | tee -a "$LOG"
	RESET_SECURITY_FAILURE=1
fi
if [ -e /etc/letsencrypt/renewal/rigpi-duckdns.conf ] || \
	[ -e /etc/letsencrypt/live/rigpi-duckdns ] || \
	[ -e /etc/letsencrypt/archive/rigpi-duckdns ]; then
	echo "[15] ERROR: the RigPi DuckDNS certificate remains" | tee -a "$LOG"
	RESET_SECURITY_FAILURE=1
fi
if systemctl is-active --quiet elmer-web.service; then
	echo "[15] ERROR: elmer-web is still active" | tee -a "$LOG"
	RESET_SECURITY_FAILURE=1
fi
if systemctl is-enabled --quiet elmer-web.service 2>/dev/null; then
	echo "[15] ERROR: elmer-web is still enabled" | tee -a "$LOG"
	RESET_SECURITY_FAILURE=1
fi
if systemctl list-units --type=service --state=active --no-legend \
	'wg-quick@*.service' 2>/dev/null | grep -q .; then
	echo "[15] ERROR: a WireGuard service is still active" | tee -a "$LOG"
	RESET_SECURITY_FAILURE=1
fi
if systemctl is-active --quiet zerotier-one.service || \
	systemctl is-enabled --quiet zerotier-one.service 2>/dev/null; then
	echo "[15] ERROR: ZeroTier is still active or enabled" | tee -a "$LOG"
	RESET_SECURITY_FAILURE=1
fi
if [ -e /etc/systemd/system/cloudflared.service ] || \
	[ -e /etc/systemd/system/multi-user.target.wants/cloudflared.service ]; then
	echo "[15] ERROR: a cloudflared system service remains" | tee -a "$LOG"
	RESET_SECURITY_FAILURE=1
fi
for credential_directory in /etc/cloudflared /usr/local/etc/cloudflared \
	/root/.cloudflared /home/pi/.cloudflared /etc/rigpi/https /etc/elmer /etc/wireguard; do
	if [ -d "$credential_directory" ] && \
		find "$credential_directory" -mindepth 1 -print -quit 2>/dev/null | grep -q .; then
		echo "[15] ERROR: credentials remain under $credential_directory" | tee -a "$LOG"
		RESET_SECURITY_FAILURE=1
	fi
done
if [ -d /var/lib/zerotier-one ] && find /var/lib/zerotier-one -mindepth 1 \
	\( -name 'identity.*' -o -name 'authtoken.secret' -o -name 'metricstoken.secret' \
	-o -path '*/networks.d/*' -o -path '*/controller.d/*' \) \
	-print -quit 2>/dev/null | grep -q .; then
	echo "[15] ERROR: ZeroTier identity or network membership remains" | tee -a "$LOG"
	RESET_SECURITY_FAILURE=1
fi

if mysql -N -B station -e \
	"SELECT COUNT(*) FROM Users WHERE COALESCE(SDRHostRemote, '') <> '';" 2>/dev/null | grep -qv '^0$'; then
	echo "[15] ERROR: a saved remote SDR address remains" | tee -a "$LOG"
	RESET_SECURITY_FAILURE=1
fi

if [ "$RESET_SECURITY_FAILURE" -ne 0 ]; then
	echo "Factory reset stopped: remote-access credentials were not fully removed." | tee -a "$LOG"
	echo "Recovery backup retained at ${BACKUP_DIR}.zip" | tee -a "$LOG"
	exit 1
fi

if [ "$KEEP_RESET_BACKUP" = "1" ]; then
	echo "[15] WARNING: private recovery backup retained at ${BACKUP_DIR}.zip" | tee -a "$LOG"
else
	rm -f "${BACKUP_DIR}.zip"
	echo "[15] Temporary recovery backup removed from the reset image" | tee -a "$LOG"
fi

# Give every browser a fresh welcome preference after a successful reset.
install -d -m 0755 /var/lib/rigpi || exit 1
WELCOME_GENERATION_TMP=$(mktemp /var/lib/rigpi/.welcome-generation.XXXXXX) || exit 1
cat /proc/sys/kernel/random/uuid > "$WELCOME_GENERATION_TMP" || exit 1
chmod 0644 "$WELCOME_GENERATION_TMP" || exit 1
mv -f "$WELCOME_GENERATION_TMP" /var/lib/rigpi/welcome-generation || exit 1

echo "=== Factory Reset Complete $(date) ===" | tee -a "$LOG"
echo "DONE"
