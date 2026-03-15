# Changelog

## Version 2026.03.15

### Bug Fixes

- Kill Session: Stopping a stream on Plex now correctly disconnects the user in all cases
- Kill Session: Stopped stream no longer reappears on the dashboard after being terminated
- Kill Session: Added a short delay after stopping a stream before refreshing, so the server has time to fully close the session
- Auto Refresh: The "Last Refresh" timer no longer freezes when the server is temporarily unreachable — it now shows how many seconds until the next retry
- Auto Refresh: Fixed rare issue where the widget could send two overlapping refresh requests at the same time
- Auto Refresh: Widget now recovers automatically if the browser has slowed down background timers
- Credits popup: OK button is now correctly centered

---

## Version 2026.03.14

### First release

- Dashboard Widget: Monitor active streams in real-time from the Unraid dashboard
- Multi-Server Support: Monitor up to 10 Plex, Jellyfin, and Emby servers simultaneously
- Stream Details: User, device, IP address, playback progress, quality and codec info per stream
- Transcode Monitoring: Visual indicators for Direct Play, Direct Stream, and Transcode sessions
- Kill Session: Terminate active streams directly from the UI (configurable)
- Plex OAuth: Secure Plex server setup via OAuth — no password ever stored
- Auto-Rediscover: Automatically recovers Plex server URLs after IP changes
- Server Filter: Filter streams by server type (Plex / Jellyfin / Emby)
- Auto Refresh: Configurable polling interval for live updates
- Mobile Responsive: Works on all screen sizes
- Performance Friendly: Micro-cache, backoff on errors, lightweight polling