#!/bin/bash
# ============================================================================
# STREAM VIEWER
# Copyright (C) 2026 Lazaros Chalkidis
# License: GPLv3
# =========================================================================

PIDFILE="/var/run/streamviewer_poll.pid"
SCRIPT="/usr/local/emhttp/plugins/streamviewer/include/streamviewer_cron.php"
VARINI="/var/local/emhttp/var.ini"

echo $$ > "$PIDFILE"
cd /

while true; do
    if grep -qs 'mdState="STARTED"' "$VARINI" 2>/dev/null; then
        /usr/bin/php "$SCRIPT" >/dev/null 2>&1
    fi
    sleep 60
done
