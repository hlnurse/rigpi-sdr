#!/bin/bash
# ELMER SDR Watchdog — restarts sdr_web@8001 if snapshot endpoint fails

SNAP="http://localhost:8001/api/fft/snapshot"
LOG="/home/pi/sdr_web/watchdog.log"

result=$(curl -s --max-time 5 "$SNAP")

if echo "$result" | python3 -c "import sys,json; json.load(sys.stdin); sys.exit(0)" 2>/dev/null; then
    # OK — silent
    exit 0
else
    echo "$(date): snapshot failed, restarting sdr_web@8001" >> "$LOG"
    # Kill any rogue sdr_web_server process
    pkill -f "sdr_web_server.py" 2>/dev/null
    sleep 2
    systemctl restart sdr_web@8001
    echo "$(date): restart complete" >> "$LOG"
fi
