#!/bin/bash
# StreamViewer Poll Daemon
# Runs in background, polls media servers every 60 seconds.
# Started/stopped by the settings page when statistics are toggled.

PIDFILE="/var/run/streamviewer_poll.pid"
SCRIPT="/usr/local/emhttp/plugins/streamviewer/streamviewer_cron.php"

echo $$ > "$PIDFILE"

while true; do
    /usr/bin/php "$SCRIPT" >/dev/null 2>&1
    sleep 60
done
