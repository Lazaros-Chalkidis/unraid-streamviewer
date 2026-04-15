#!/bin/bash
# StreamViewer Poll Daemon
# Copyright (C) 2026 Lazaros Chalkidis - License: GPLv3
# Runs in background, polls media servers every 60 seconds.
# Started/stopped by the settings page when statistics are toggled.

PIDFILE="/var/run/streamviewer_poll.pid"
SCRIPT="/usr/local/emhttp/plugins/streamviewer/include/streamviewer_cron.php"
VARINI="/var/local/emhttp/var.ini"

echo $$ > "$PIDFILE"
cd /

while true; do
    # Wait for array AND user shares to be fully mounted before polling
    if grep -qs 'mdState="STARTED"' "$VARINI" 2>/dev/null && mountpoint -q /mnt/user 2>/dev/null; then
        /usr/bin/php "$SCRIPT" >/dev/null 2>&1
    fi
    sleep 60
done
