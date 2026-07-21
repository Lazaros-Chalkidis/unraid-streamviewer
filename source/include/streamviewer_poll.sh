#!/bin/bash
# ============================================================================
# STREAM VIEWER
# Copyright (C) 2026 Lazaros Chalkidis
# License: GPLv3
# =========================================================================

# background daemon, polls the media servers every 60s
# the settings page starts and stops this when statistics are toggled

PIDFILE="/var/run/streamviewer_poll.pid"
SCRIPT="/usr/local/emhttp/plugins/streamviewer/include/streamviewer_cron.php"
VARINI="/var/local/emhttp/var.ini"

echo $$ > "$PIDFILE"
cd /

while true; do
    # only poll once the array is started and shares are mounted
    if grep -qs 'mdState="STARTED"' "$VARINI" 2>/dev/null && mountpoint -q /mnt/user 2>/dev/null; then
        /usr/bin/php "$SCRIPT" >/dev/null 2>&1
    fi
    sleep 60
done
