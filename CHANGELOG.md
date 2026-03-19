
## Version 2026.03.19a

### Bug Fixes
- Plex: Auto rediscover no longer switches from local to remote URL (or vice versa) after server restart
- Plex: Auto rediscover sort order fixed, was preferring remote over local connections

### New Features
- Credits modal: Widget and Settings adaptive to Dynamix color theme: Black - Light - Gray - Azure
- Smart rediscover: Retries 3 times before triggering URL rediscovery
- Smart rediscover: Respects URL type — local stays local, remote stays remote
- Jellyfin/Emby: Local container IP auto-discovery via Docker Engine API (no shell exec)

---

## Version 2026.03.18

### Bug Fixes
- Emby: Add Server button now works was silently failing due to undefined variable
- Emby: Kill Session now works fixed Content Type mismatch and added fallback endpoint
- Jellyfin/Emby: Test Connection now validates the API key, previously only checked server reachability
- Jellyfin/Emby: Play type (Direct Play / Direct Stream / Transcode) now detects correctly was stuck on Direct Stream due to wrong API field name
- Transcode speed: No longer disappears between polls, keeps last known value until updated
- Transcode speed: Badge no longer jumps in width, fixed width speed display
- Transcode speed: Live updates now work, was frozen after first render
- Details panel: No longer collapses when play type changes mid stream

### New Features
- Technical Details: New collapsible row per stream showing video codec, audio codec + channels, audio spatial format (Dolby Atmos), container, subtitles, HW acceleration type, transcode reasons, and transcode buffer % (where available)
- Transcode speed now visible in widget inside the TRANSCODE badge (Plex only — Jellyfin/Emby APIs don't provide this)
- Show technical details toggle in Settings → Dashboard Widget → Display

### Cleanup
- Removed all orphan Tool page code (JS, CSS) — feature was never implemented
- Renumbered JS sections (1–15) and CSS sections (1–14)

---

## Version 2026.03.17

### Bug Fixes
- Jellyfin: Fixed "Please select a server type" error when adding or testing a Jellyfin server
- Jellyfin: Quality (e.g. 1080p) now displays correctly in the widget was showing only bitrate
- Emby: Test Connection no longer freezes on "Testing…" missing result handler added
- Emby: 401 error now shows "Invalid API key" instead of incorrectly saying "Invalid Jellyfin API key"
- Plex: Quality badge now shows "1080p" instead of "1080" for consistency across all server types
- Test Connection: Re-clicking the button now visually flashes the result so the user sees it responded
- Test Connection: Now works for unsaved servers, previously required the server to be saved first
- Reset button: Now works on all tabs clears form fields on Plex/Jellyfin/Emby, resets defaults on Dashboard Widget
- Reset button: No longer hidden on server tabs shows "Clear Form" on Plex/Jellyfin/Emby

### Security
- TLS: Added configurable "Verify SSL certificates" toggle (Settings → Dashboard Widget → Connection)
- TLS: Connections to plex.tv (OAuth, discovery) always verify SSL regardless of toggle
- Thumb Proxy: Tightened URL validation — now matches host+port instead of prefix string
- Thumb Proxy: Disabled redirect following to prevent open-redirect SSRF
- Thumb Proxy: Added 5 MB response size cap

### Performance
- API: Server sessions are now fetched in parallel via curl_multi instead of sequentially
- API: Plugin config file is now read once per request instead of up to 5 times
- Cache: Micro cache window increased from 500 ms to 2000 ms to reduce redundant fetches

### Cleanup
- Removed server type filter tabs from widget (server type already shown per stream in badges)
- Removed all orphan code related to filter tabs (PHP, JS, CSS)
- Renumbered all section headers in JS and CSS after tab removal

### UX
- Network hint: Connection errors now show a helpful message about enabling "Host access to custom networks" for ipvlan/macvlan Docker setups

---

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