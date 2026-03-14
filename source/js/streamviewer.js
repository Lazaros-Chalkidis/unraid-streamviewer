/* ═══════════════════════════════════════════════════════════════════════════
   Stream Viewer  —  streamviewer.js
   Drives the dashboard widget and Tool page.

   Config is injected via window.streamviewerConfig (widget/dashboard)
   or window.streamviewerToolConfig (tool page).

   Depends on: jQuery (already present in Unraid webgui)
   ═══════════════════════════════════════════════════════════════════════════ */
/* global $ */

(function () {
'use strict';

// ─── Guard against double-init ────────────────────────────────────────────────
if (window.__svLoaded) return;
window.__svLoaded = true;

// ══════════════════════════════════════════════════════════════════════════════
// 1. MODULE STATE
// ══════════════════════════════════════════════════════════════════════════════

var _cfg          = {};       // resolved, sanitised config
var _sessions     = [];       // last-fetched sessions (all servers combined)
var _serverStats  = [];       // last-fetched per-server status objects
var _activeFilter = 'all';    // 'all' | 'plex' | 'jellyfin' | 'emby'
var _context      = 'dash';   // 'dash' (widget) | 'tool'
var _pollTimer    = null;
var _inFlight     = false;
var _backoffUntil = 0;        // timestamp ms — skip fetch until this time
var _errorCount   = 0;        // consecutive fetch errors (for self-healing backoff)
var _initialized  = false;    // true after first successful fetch


// ══════════════════════════════════════════════════════════════════════════════
// 2. DOM HELPERS  (lazy — always look up fresh so re-mounts work)
// ══════════════════════════════════════════════════════════════════════════════

var DOM = {
    container:  function() { return document.getElementById('sv-streams-container'); },
    emptyState: function() { return document.getElementById('sv-empty-state');       },
    badgeCount: function() { return document.getElementById('sv-badge-count');       },
    badgeDot:   function() { return document.getElementById('sv-badge-dot');         },
    timestamp:  function() { return document.getElementById('sv-timestamp');         },
    pulse:      function() { return document.getElementById('sv-pulse');             },
    errBadge:   function() { return document.getElementById('sv-err-badge');         },
    errCount:   function() { return document.getElementById('sv-err-count');         },
    tabrow:     function() { return document.getElementById('sv-tabrow');            },
    manualBtn:  function() { return document.getElementById('sv-manual-refresh');    },
    toolRefBtn: function() { return document.getElementById('sv-tool-refresh');      },
};


// ══════════════════════════════════════════════════════════════════════════════
// 3. FORMATTERS
// ══════════════════════════════════════════════════════════════════════════════

function pad2(n) { return n < 10 ? '0' + n : String(n); }

function fmtMs(ms) {
    if (!ms || ms <= 0) return '–';
    var s   = Math.floor(ms / 1000);
    var h   = Math.floor(s / 3600);
    var m   = Math.floor((s % 3600) / 60);
    var sec = s % 60;
    return h > 0 ? (h + ':' + pad2(m) + ':' + pad2(sec)) : (m + ':' + pad2(sec));
}

function fmtNow() {
    var d = new Date();
    return pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
}

function esc(s) {
    if (s == null) return '';
    return String(s)
        .replace(/&/g,  '&amp;')
        .replace(/</g,  '&lt;')
        .replace(/>/g,  '&gt;')
        .replace(/"/g,  '&quot;')
        .replace(/'/g,  '&#39;');
}

function playTypeMeta(pt) {
    switch ((pt || '').toLowerCase()) {
        case 'direct_play':   return { bar:'play',   badge:'play',   label:'Direct Play'   };
        case 'direct_stream': return { bar:'stream', badge:'stream', label:'Direct Stream' };
        case 'transcode':     return { bar:'trans',  badge:'trans',  label:'Transcode'     };
        default:              return { bar:'play',   badge:'play',   label:'Direct Play'   };
    }
}

function mediaIcon(t) {
    switch ((t || '').toLowerCase()) {
        case 'episode':
        case 'series':  return 'fa-television';
        case 'audio':
        case 'track':   return 'fa-music';
        case 'photo':   return 'fa-picture-o';
        default:        return 'fa-film';
    }
}

function fmtBitrate(kbps) {
    if (!kbps || kbps <= 0) return '';
    return kbps >= 1000 ? (kbps / 1000).toFixed(1) + ' Mbps' : kbps + ' kbps';
}


// ══════════════════════════════════════════════════════════════════════════════
// 4. SESSION SORTING
// ══════════════════════════════════════════════════════════════════════════════

var SORT_PT = { transcode: 0, direct_stream: 1, direct_play: 2 };

function sortSessions(sessions) {
    return sessions.slice().sort(function(a, b) {
        var pa = SORT_PT[(a.play_type || '').toLowerCase()];
        var pb = SORT_PT[(b.play_type || '').toLowerCase()];
        if (pa == null) pa = 3;
        if (pb == null) pb = 3;
        if (pa !== pb) return pa - pb;
        return (a.user || '').localeCompare(b.user || '');
    });
}


// ══════════════════════════════════════════════════════════════════════════════
// 5. RENDER — single stream row
// ══════════════════════════════════════════════════════════════════════════════

function renderRow(s) {
    var cfg      = _cfg;
    var ptm      = playTypeMeta(s.play_type);
    var isPaused = (s.state || '').toLowerCase() === 'paused';
    var barMod   = isPaused ? 'paused' : ptm.bar;

    // Row classes
    var rowClass = 'sv-stream';
    if (isPaused)           rowClass += ' sv-stream--paused';
    else if (ptm.bar === 'trans') rowClass += ' sv-stream--transcode';

    // Left indicator bar
    var barHtml = '<div class="sv-stream__bar sv-stream__bar--' + esc(barMod) + '" aria-hidden="true"></div>';

    // Row 1 left: media icon + state icon (play/pause) + title
    var stateIcon = isPaused
        ? '<i class="fa fa-pause sv-stream__type-icon" title="Paused" aria-hidden="true"></i>'
        : '<i class="fa fa-play sv-stream__type-icon" title="Playing" aria-hidden="true"></i>';
    var titleHtml = '<div class="sv-stream__main">'
        + '<i class="fa ' + mediaIcon(s.media_type) + ' sv-stream__type-icon" aria-hidden="true"></i>'
        + stateIcon
        + '<span class="sv-stream__title" title="' + esc(s.title) + '">' + esc(s.title) + '</span>'
        + '</div>';

    // Badges row — LEFT: quality + live bitrate  |  RIGHT: server type + name + transcode + stop
    var serverBadge = '<span class="sv-stream__server-badge sv-stream__server-badge--'
        + esc(s.server_type) + '" title="' + esc(s.server_name) + '">'
        + esc(s.server_name || s.server_type) + '</span>';

    var transHtml = '';
    if (cfg.showTranscode) {
        var tLabel = isPaused ? 'Paused' : ptm.label;
        var tMod   = isPaused ? 'paused' : ptm.badge;
        transHtml = '<span class="sv-stream__transcode sv-stream__transcode--' + esc(tMod) + '">'
            + esc(tLabel) + '</span>';
    }

    var transSpeedHtml = '';
    if (_context === 'tool' && !isPaused && ptm.bar === 'trans' && s.transcode_speed > 0) {
        transSpeedHtml = '<span class="sv-stream__transcode-speed" title="Transcode speed">'
            + esc(s.transcode_speed.toFixed(1)) + 'x</span>';
    }

    var killHtml = '';
    if (cfg.allowKill) {
        killHtml = '<button class="sv-kill-btn"'
            + ' data-session-id="'  + esc(s.session_id  || '') + '"'
            + ' data-session-key="' + esc(s.session_key || '') + '"'
            + ' data-server-name="' + esc(s.server_name || '') + '"'
            + ' title="Stop this stream">'
            + '<i class="fa fa-stop" aria-hidden="true"></i> Stop'
            + '</button>';
    }

    // Left group: quality + live bitrate
    var badgesLeftHtml = '';
    if (cfg.showQuality && s.quality) {
        badgesLeftHtml += '<span class="sv-stream__quality">' + esc(s.quality) + '</span>';
    }
    if (cfg.showQuality && s.bandwidth_kbps > 0) {
        badgesLeftHtml += '<span class="sv-stream__bitrate">' + esc(fmtBitrate(s.bandwidth_kbps)) + '</span>';
    }

    var serverTypeBadge = '<span class="sv-stream__server-type sv-stream__server-type--'
        + esc(s.server_type) + '">' + esc((s.server_type || '').toUpperCase()) + '</span>';

    var badgesRowHtml = '<div class="sv-stream__badges-row">'
        + '<div class="sv-stream__badges-left">' + badgesLeftHtml + '</div>'
        + '<div class="sv-stream__badges-right">' + serverTypeBadge + serverBadge + transHtml + transSpeedHtml + killHtml + '</div>'
        + '</div>';

    // Row 3: collapsible thumbnail preview
    var thumbSrc = s.thumb_url
        ? '/plugins/streamviewer/streamviewer_api.php?action=get_thumb&_svt='
          + encodeURIComponent(_cfg.svToken || '')
          + '&u=' + encodeURIComponent(s.thumb_url)
        : '';
    var thumbHtml = thumbSrc
        ? '<img class="sv-stream__thumb-img" src="' + thumbSrc + '" alt="Cover" loading="lazy">'
        : '<div class="sv-stream__thumb-placeholder"><i class="fa ' + mediaIcon(s.media_type) + '"></i></div>';
    var descHtml = s.summary
        ? '<p class="sv-stream__desc">' + esc(s.summary) + '</p>'
        : '';
    var thumbColHtml = '<div class="sv-stream__thumb-col">' + thumbHtml + '</div>';
    var badgesHtml = '<div class="sv-stream__row3 sv-stream__row3--collapsed">'
        + '<div class="sv-stream__row3-hd"><span class="sv-stream__row3-arrow">&#9650;</span></div>'
        + '<div class="sv-stream__row3-bd">' + thumbColHtml + descHtml + '</div>'
        + '</div>';

    // Row 2: user · device · IP  +  progress
    var userHtml = '<span class="sv-stream__user">' + esc(s.user || 'Unknown') + '</span>';

    var deviceHtml = '';
    if (cfg.showDevice && s.device) {
        var devLabel = (s.client && s.client !== s.device) ? s.client + ' · ' + s.device : s.device;
        deviceHtml = '<span class="sv-stream__sep">·</span>'
            + '<span class="sv-stream__device" title="' + esc(devLabel) + '">' + esc(s.device) + '</span>';
    }

    var ipHtml = '';
    if (cfg.showIp && s.ip_address) {
        ipHtml = '<span class="sv-stream__sep">·</span>'
            + '<span class="sv-stream__ip">' + esc(s.ip_address) + '</span>';
    }



    var progressHtml = '';
    if (cfg.showProgress && s.duration_ms > 0) {
        var pct = Math.min(100, Math.max(0, s.progress_pct || 0));
        progressHtml = '<div class="sv-stream__progress-wrap">'
            + '<div class="sv-stream__progress" title="' + pct.toFixed(1) + '%">'
            +   '<div class="sv-stream__progress-bar" style="width:' + pct.toFixed(2) + '%"></div>'
            + '</div>'
            + '<span class="sv-stream__time">'
            + esc(fmtMs(s.progress_ms)) + ' / ' + esc(fmtMs(s.duration_ms))
            + '</span>'
            + '</div>';
    }

    var subHtml = '<div class="sv-stream__sub">'
        + '<div class="sv-stream__info">' + userHtml + deviceHtml + ipHtml + '</div>'
        + progressHtml
        + '</div>';

    return '<div class="' + rowClass + '"'
        + ' role="listitem"'
        + ' data-server-type="' + esc(s.server_type)      + '"'
        + ' data-server-name="' + esc(s.server_name)      + '"'
        + ' data-session-id="'  + esc(s.session_id  || '') + '"'
        + ' data-state="'       + esc(s.state       || '') + '"'
        + ' data-quality="'     + esc(s.quality     || '') + '"'
        + ' data-play-type="'   + esc(s.play_type   || '') + '"'
        + ' data-bandwidth="'   + (s.bandwidth_kbps > 0 ? '1' : '0') + '"'
        + '>'
        + barHtml + badgesRowHtml + titleHtml + subHtml + badgesHtml
        + '</div>';
}

function buildSkeletons() {
    return '<div class="sv-skeleton"></div><div class="sv-skeleton"></div><div class="sv-skeleton"></div>';
}


// ══════════════════════════════════════════════════════════════════════════════
// 6. RENDER — streams list
// ══════════════════════════════════════════════════════════════════════════════

// Patch a single existing stream row with updated dynamic data (no DOM replacement).
// Returns true if a full rebuild is needed (e.g. title changed = different media).
function patchRow(el, s) {
    // Full rebuild if title, state, quality or play_type changed
    var titleEl = el.querySelector('.sv-stream__title');
    if (titleEl && titleEl.title !== (s.title || '')) return true;
    if (el.dataset.state     !== (s.state     || '')) return true;
    if (el.dataset.quality   !== (s.quality   || '')) return true;
    if (el.dataset.playType  !== (s.play_type || '')) return true;
    if (el.dataset.bandwidth  !== (s.bandwidth_kbps > 0 ? '1' : '0')) return true;
    var isPaused = (s.state || '').toLowerCase() === 'paused';
    var ptm      = playTypeMeta(s.play_type);
    var barMod   = isPaused ? 'paused' : ptm.bar;

    // Row classes
    var wantClass = 'sv-stream';
    if (isPaused)              wantClass += ' sv-stream--paused';
    else if (ptm.bar === 'trans') wantClass += ' sv-stream--transcode';
    if (el.className !== wantClass) el.className = wantClass;

    // Left indicator bar
    var bar = el.querySelector('.sv-stream__bar');
    if (bar) {
        var wantBarClass = 'sv-stream__bar sv-stream__bar--' + barMod;
        if (bar.className !== wantBarClass) bar.className = wantBarClass;
    }

    // Progress bar width + time
    var progBar = el.querySelector('.sv-stream__progress-bar');
    if (progBar) {
        var pct = Math.min(100, Math.max(0, s.progress_pct || 0));
        var wantW = pct.toFixed(2) + '%';
        if (progBar.style.width !== wantW) progBar.style.width = wantW;
    }
    var timeEl = el.querySelector('.sv-stream__time');
    if (timeEl) {
        var wantTime = fmtMs(s.progress_ms) + ' / ' + fmtMs(s.duration_ms);
        if (timeEl.textContent !== wantTime) timeEl.textContent = wantTime;
    }

    // Transcode badge label + modifier class
    var trans = el.querySelector('.sv-stream__transcode');
    if (trans) {
        var tLabel = isPaused ? 'Paused' : ptm.label;
        var tMod   = isPaused ? 'paused' : ptm.badge;
        var wantTransClass = 'sv-stream__transcode sv-stream__transcode--' + tMod;
        if (trans.className !== wantTransClass) trans.className = wantTransClass;
        if (trans.textContent !== tLabel) trans.textContent = tLabel;
    }

    // State icon (play/pause) in title row
    var mainDiv   = el.querySelector('.sv-stream__main');
    var stateIcon = el.querySelector('.sv-stream__main .fa-pause, .sv-stream__main .fa-play');
    if (mainDiv) {
        var hasPause = stateIcon && stateIcon.classList.contains('fa-pause');
        var hasPlay  = stateIcon && stateIcon.classList.contains('fa-play');
        var titleSpan = mainDiv.querySelector('.sv-stream__title');
        if (isPaused && !hasPause) {
            if (stateIcon) stateIcon.parentNode.removeChild(stateIcon);
            var pi = document.createElement('i');
            pi.className = 'fa fa-pause sv-stream__type-icon';
            pi.title = 'Paused';
            pi.setAttribute('aria-hidden', 'true');
            if (titleSpan) mainDiv.insertBefore(pi, titleSpan);
        } else if (!isPaused && !hasPlay) {
            if (stateIcon) stateIcon.parentNode.removeChild(stateIcon);
            var pl = document.createElement('i');
            pl.className = 'fa fa-play sv-stream__type-icon';
            pl.title = 'Playing';
            pl.setAttribute('aria-hidden', 'true');
            if (titleSpan) mainDiv.insertBefore(pl, titleSpan);
        }
    }

    // IP address update (changes when user switches network)
    var ipEl = el.querySelector('.sv-stream__ip');
    if (ipEl && s.ip_address !== undefined) {
        var wantIp = s.ip_address || '';
        if (ipEl.textContent !== wantIp) ipEl.textContent = wantIp;
    }

    // Live bitrate update (left badges group)
    var bitrateEl = el.querySelector('.sv-stream__badges-left .sv-stream__bitrate');
    if (bitrateEl) {
        var wantBitrate = (s.bandwidth_kbps > 0) ? fmtBitrate(s.bandwidth_kbps) : '';
        if (bitrateEl.textContent !== wantBitrate) bitrateEl.textContent = wantBitrate;
    }

    return false; // no rebuild needed
}

function renderStreams(sessions) {
    var container  = DOM.container();
    var emptyState = DOM.emptyState();
    if (!container) return;

    var visible = _activeFilter === 'all'
        ? sessions
        : sessions.filter(function(s) { return s.server_type === _activeFilter; });

    visible = sortSessions(visible);

    var maxS = _cfg.maxStreams || 0;
    if (maxS > 0 && visible.length > maxS) visible = visible.slice(0, maxS);

    if (visible.length === 0) {
        container.innerHTML = '';
        if (emptyState) emptyState.style.display = '';
        return;
    }

    if (emptyState) emptyState.style.display = 'none';

    // Build a map of existing rows by session_id
    var existingMap = {};
    container.querySelectorAll('.sv-stream[data-session-id]').forEach(function(el) {
        existingMap[el.dataset.sessionId] = el;
    });

    // Preserve open thumbnail panels
    var openSessions = {};
    container.querySelectorAll('.sv-stream__row3:not(.sv-stream__row3--collapsed)').forEach(function(r) {
        var row = r.closest('.sv-stream');
        if (row && row.dataset.sessionId) openSessions[row.dataset.sessionId] = true;
    });

    var newIds = {};
    var fragment = document.createDocumentFragment();
    var needRebindKill = false;
    var needRebindRow3 = false;

    visible.forEach(function(s) {
        var sid = s.session_id || '';
        newIds[sid] = true;

        if (existingMap[sid] && !patchRow(existingMap[sid], s)) {
            // Row exists and media is same — reuse patched row
            fragment.appendChild(existingMap[sid]);
        } else {
            // New session — create fresh row
            var tmp = document.createElement('div');
            tmp.innerHTML = renderRow(s);
            var newEl = tmp.firstElementChild;
            if (newEl) {
                fragment.appendChild(newEl);
                needRebindKill = true;
                needRebindRow3 = true;
            }
        }
    });

    // Swap container contents in one operation (single reflow)
    container.innerHTML = '';
    container.appendChild(fragment);

    // Restore open thumbnail panels for rows that survived
    Object.keys(openSessions).forEach(function(sid) {
        var row = container.querySelector('.sv-stream[data-session-id="' + sid + '"]');
        if (row) {
            var r3 = row.querySelector('.sv-stream__row3');
            if (r3) r3.classList.remove('sv-stream__row3--collapsed');
        }
    });

    if (needRebindKill) bindKillButtons(container);
    bindRow3Toggles(container);
}


// ══════════════════════════════════════════════════════════════════════════════
// 7. KILL SESSION
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Resolve a server_index (1-based config slot) from a server display name.
 * PHP injects _cfg.servers = [{index, type, name}, ...] from the cfg file.
 */
function resolveServerIndex(serverName) {
    var servers = _cfg.servers || [];
    for (var i = 0; i < servers.length; i++) {
        if (servers[i].name === serverName) return servers[i].index;
    }
    return null;
}

function bindKillButtons(container) {
    if (!_cfg.allowKill) return;
    container.querySelectorAll('.sv-kill-btn').forEach(function(btn) {
        btn.addEventListener('click', onKillClick);
    });
}

function onKillClick(e) {
    var btn        = e.currentTarget;
    var row        = btn.closest('.sv-stream');
    var sessionId  = btn.dataset.sessionId  || '';
    var sessionKey = btn.dataset.sessionKey || '';
    var serverName = btn.dataset.serverName || '';
    var titleEl    = row && row.querySelector('.sv-stream__title');
    var title      = titleEl ? titleEl.textContent : (sessionId || 'this stream');

    if (!confirm('Stop stream: "' + title + '"?\n\nThis will immediately disconnect the user.')) return;

    var serverIndex = resolveServerIndex(serverName);
    if (!serverIndex) {
        alert('Could not identify the server for this stream.\nTry saving Settings and refreshing.');
        return;
    }

    doKillSession(btn, row, serverIndex, sessionId, sessionKey);
}

function doKillSession(btn, row, serverIndex, sessionId, sessionKey) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin" aria-hidden="true"></i>';

    $.ajax({
        url:      '/plugins/streamviewer/streamviewer_api.php?_svt=' + encodeURIComponent(_cfg.svToken || ''),
        method:   'POST',
        timeout:  15000,
        headers:  { 'X-Requested-With': 'XMLHttpRequest' },
        data: {
            action:       'kill_session',
            server_index: serverIndex,
            session_id:   sessionId,
            session_key:  sessionKey,
            reason:       'Stream terminated by Unraid administrator',
            _svt:         _cfg.svToken || '',
        },
        dataType: 'json',
        success: function(data) {
            if (data && data.ok) {
                if (row) {
                    row.style.transition = 'opacity .35s ease, transform .35s ease';
                    row.style.opacity    = '0';
                    row.style.transform  = 'translateX(-8px)';
                    setTimeout(function() {
                        if (row.parentNode) row.parentNode.removeChild(row);
                        var remaining = (DOM.container() || {}).querySelectorAll
                            ? DOM.container().querySelectorAll('.sv-stream').length : 0;
                        updateBadge(remaining);
                    }, 380);
                }
                setTimeout(fetchSessions, 2000);
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-stop" aria-hidden="true"></i> Stop';
                alert('Failed to stop stream: ' + ((data && data.error) ? data.error : 'Unknown error'));
            }
        },
        error: function(xhr) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-stop" aria-hidden="true"></i> Stop';
            var msg = (xhr.responseJSON && xhr.responseJSON.error)
                ? xhr.responseJSON.error : (xhr.statusText || 'Request failed');
            alert('Error stopping stream: ' + msg);
        },
    });
}


// ══════════════════════════════════════════════════════════════════════════════
// 8. STATUS UPDATES
// ══════════════════════════════════════════════════════════════════════════════

function updateBadge(count) {
    var bc  = DOM.badgeCount();
    var dot = DOM.badgeDot();
    if (bc)  bc.textContent = String(count);
    if (dot) dot.className  = 'sv-badge-dot ' + (count > 0 ? 'sv-badge-dot--active' : 'sv-badge-dot--idle');
}

function updateTimestamp() {
    var el = DOM.timestamp();
    if (el) el.textContent = fmtNow();
}

function flashPulse() {
    var el = DOM.pulse();
    if (!el) return;
    el.classList.remove('sv-pulse--active');
    void el.offsetWidth;
    el.classList.add('sv-pulse--active');
}

function updateErrorIndicator(stats) {
    var errCount    = 0;
    var totalCount  = (stats || []).length;
    for (var i = 0; i < totalCount; i++) {
        if ((stats[i].status || '') !== 'online') errCount++;
    }
    var eb = DOM.errBadge();
    var ec = DOM.errCount();
    if (eb) eb.style.display = errCount > 0 ? '' : 'none';
    if (ec) ec.textContent   = String(errCount);
    // Update dot: all servers down → red, otherwise restore based on session count
    var dot = DOM.badgeDot();
    if (dot) {
        if (errCount > 0 && errCount === totalCount) {
            dot.className = 'sv-badge-dot sv-badge-dot--error';
        } else if (errCount === 0) {
            // Restore to active/idle based on current session count
            var count = parseInt((DOM.badgeCount() || {}).textContent || '0', 10);
            dot.className = 'sv-badge-dot ' + (count > 0 ? 'sv-badge-dot--active' : 'sv-badge-dot--idle');
        }
        // partial errors: keep current dot state
    }
}


// ══════════════════════════════════════════════════════════════════════════════
// 9. API FETCH
// ══════════════════════════════════════════════════════════════════════════════

function fetchSessions(onDone) {
    if (Date.now() < _backoffUntil) {
        if (typeof onDone === 'function') onDone('backoff', null);
        return;
    }
    if (_inFlight) return;
    _inFlight    = true;
    _lastFetchAt = Date.now();
    // Safety: release _inFlight lock after 20s max (prevents permanent freeze)
    var _inFlightTimeout = setTimeout(function() { _inFlight = false; }, 20000);

    $.ajax({
        url:      '/plugins/streamviewer/streamviewer_api.php',
        method:   'GET',
        timeout:  38000,  // worst case: 5 servers × 7s timeout + margin
        headers:  { 'X-Requested-With': 'XMLHttpRequest' },
        data: {
            action:  'get_sessions',
            context: _context,
            _svt:    _cfg.svToken || '',
        },
        dataType: 'json',

        success: function(data) {
            _backoffUntil = 0;
            _errorCount   = 0;
            _lastFetchAt  = Date.now();
            _sessions    = Array.isArray(data.sessions) ? data.sessions : [];
            _serverStats = Array.isArray(data.servers)  ? data.servers  : [];

            renderStreams(_sessions);
            updateBadge(data.total_sessions || 0);
            updateTimestamp();
            flashPulse();
            updateErrorIndicator(_serverStats);

            _initialized = true;
            if (typeof onDone === 'function') onDone(null, data);
        },

        error: function(xhr, status) {
            var msg = (xhr.responseJSON && xhr.responseJSON.error)
                ? xhr.responseJSON.error : (status || 'Request failed');
            _errorCount = (_errorCount || 0) + 1;
            // Backoff: 15s → 45s, then reset after 4 failures (self-healing)
            if (_errorCount >= 4) {
                _errorCount   = 0;
                _backoffUntil = Date.now() + 15000; // reset to short backoff
            } else {
                _backoffUntil = Date.now() + (_errorCount === 1 ? 15000 : 45000);
            }
            // Show error badge only after 3+ consecutive errors (avoids flashing on transient failures)
            if (_errorCount >= 3) {
                updateErrorIndicator([{ status: 'error' }]);
            }
            updateTimestamp();
            if (typeof onDone === 'function') onDone(msg, null);
        },

        complete: function() { clearTimeout(_inFlightTimeout); _inFlight = false; },
    });
}


// ══════════════════════════════════════════════════════════════════════════════
// 10. POLLING
// ══════════════════════════════════════════════════════════════════════════════

var _lastFetchAt   = 0;      // timestamp of last successful fetch attempt
var _watchdogTimer = null;   // detects stalled polling and recovers

function startPolling() {
    stopPolling();
    if (!_cfg.refreshEnabled || !(_cfg.refreshInterval > 0)) return;
    _pollTimer = setInterval(fetchSessions, _cfg.refreshInterval);
    startWatchdog();
}

function stopPolling() {
    if (_pollTimer)    { clearInterval(_pollTimer);    _pollTimer    = null; }
    if (_watchdogTimer){ clearInterval(_watchdogTimer); _watchdogTimer = null; }
}

// Watchdog: if no fetch has been attempted in 3× the interval, something is stuck → recover
function startWatchdog() {
    if (_watchdogTimer) clearInterval(_watchdogTimer);
    _watchdogTimer = setInterval(function() {
        if (!_cfg.refreshEnabled) return;
        var staleMs = (_cfg.refreshInterval || 30000) * 3;
        if (_lastFetchAt > 0 && (Date.now() - _lastFetchAt) > staleMs) {
            // Polling has stalled — full recovery
            _backoffUntil = 0;
            _errorCount   = 0;
            _inFlight     = false;
            fetchSessions();
        }
    }, 15000);
}

function initVisibilityHandling() {
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopPolling();
        } else {
            _backoffUntil = 0;
            _inFlight     = false;
            fetchSessions();
            startPolling();
        }
    });
}


// ══════════════════════════════════════════════════════════════════════════════
// 11. SERVER-TYPE FILTER TABS
// ══════════════════════════════════════════════════════════════════════════════

function initTabs() {
    var tabrow = DOM.tabrow();
    if (!tabrow) return;

    tabrow.addEventListener('click', function(e) {
        var btn = e.target.closest('.sv-servertab');
        if (btn) setFilter(btn.dataset.filter || 'all');
    });

    tabrow.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var btn = e.target.closest('.sv-servertab');
        if (btn) { e.preventDefault(); setFilter(btn.dataset.filter || 'all'); }
    });
}

function setFilter(filter) {
    _activeFilter = filter;
    var tabrow = DOM.tabrow();
    if (tabrow) {
        tabrow.querySelectorAll('.sv-servertab').forEach(function(tab) {
            tab.classList.toggle('sv-servertab--active', tab.dataset.filter === filter);
        });
    }
    renderStreams(_sessions);
}


// ══════════════════════════════════════════════════════════════════════════════
// 12. REFRESH BUTTONS
// ══════════════════════════════════════════════════════════════════════════════

function wireRefreshBtn(el) {
    if (!el) return;
    el.addEventListener('click', function(e) {
        e.preventDefault();
        var icon = el.querySelector('.fa');
        if (icon) icon.className = 'fa fa-fw fa-hourglass-half control';
        _backoffUntil = 0;
        _inFlight     = false;
        _errorCount   = 0;
        fetchSessions(function() {
            if (icon) icon.className = 'fa fa-fw fa-refresh control';
        });
        startPolling();
    });
}

// ── Row 3 thumbnail toggle ─────────────────────────────────────────────────
function bindRow3Toggles(container) {
    // Use a single delegated listener on the container to avoid
    // duplicate listeners accumulating on every refresh cycle
    if (container._row3DelegateAttached) return;
    container._row3DelegateAttached = true;

    container.addEventListener('click', function(e) {
        var hd = e.target.closest('.sv-stream__row3-hd');
        if (!hd) return;
        var row3 = hd.closest('.sv-stream__row3');
        if (row3) row3.classList.toggle('sv-stream__row3--collapsed');
    });
}

// ══════════════════════════════════════════════════════════════════════════════
// 13. "NO SERVERS" STATE
// ══════════════════════════════════════════════════════════════════════════════

function renderNoServers() {
    var container = DOM.container();
    if (!container) return;
    container.innerHTML = ''
        + '<div class="sv-no-servers-state">'
        + '<i class="fa fa-server sv-no-servers-icon" aria-hidden="true"></i>'
        + '<span class="sv-no-servers-title">No media servers configured</span>'
        + '<a class="sv-no-servers-btn" href="/Settings/StreamViewerSettings">'
        + '<i class="fa fa-cog"></i>&nbsp;Open Settings'
        + '</a>'
        + '</div>';
    var emptyState = DOM.emptyState();
    if (emptyState) emptyState.style.display = 'none';
}


// ══════════════════════════════════════════════════════════════════════════════
// 14. CONFIG RESOLUTION
// ══════════════════════════════════════════════════════════════════════════════

function resolveConfig() {
    var raw = window.streamviewerConfig || window.streamviewerToolConfig || {};

    // refreshInterval comes from PHP already in ms (seconds * 1000)
    var ri = parseInt(raw.refreshInterval, 10) || 30000;
    ri = Math.max(5000, Math.min(ri, 600000));

    return {
        svToken:             String(raw.svToken || ''),
        refreshEnabled:      raw.refreshEnabled !== false,
        refreshInterval:     ri,
        maxStreams:          Math.max(0, parseInt(raw.maxStreams, 10) || 0),
        showDevice:          raw.showDevice   !== false,
        showIp:              raw.showIp       !== false,
        showProgress:        raw.showProgress !== false,
        showQuality:         raw.showQuality  !== false,
        showTranscode:       raw.showTranscode !== false,
        allowKill:           raw.allowKill    === true,
        servers:             Array.isArray(raw.servers)            ? raw.servers            : [],
        serverTypesPresent:  Array.isArray(raw.serverTypesPresent) ? raw.serverTypesPresent : [],
        noServersConfigured: raw.noServersConfigured === true,
        isResponsive:        raw.isResponsive !== false,
        context:             raw.context === 'tool' ? 'tool' : 'dash',
    };
}


// ══════════════════════════════════════════════════════════════════════════════
// 15. INIT
// ══════════════════════════════════════════════════════════════════════════════

function init() {
    _cfg     = resolveConfig();
    _context = _cfg.context;

    if (_cfg.noServersConfigured) {
        renderNoServers();
        return;
    }

    initTabs();
    wireRefreshBtn(DOM.manualBtn());
    wireRefreshBtn(DOM.toolRefBtn());
    initVisibilityHandling();

    // Apply tool-page CSS class
    if (_context === 'tool') {
        var c = DOM.container();
        if (c) {
            var b = c.closest('.sv-body');
            if (b) b.classList.add('sv-tool-page');
        }
    }

    // Loading skeleton
    var container  = DOM.container();
    var emptyState = DOM.emptyState();
    if (container)  container.innerHTML      = buildSkeletons();
    if (emptyState) emptyState.style.display = 'none';

    fetchSessions(function() { startPolling(); });
}

function boot() {
    if (typeof $ === 'undefined') { setTimeout(boot, 60); return; }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}

boot();


// ══════════════════════════════════════════════════════════════════════════════
// 16. PUBLIC API
// ══════════════════════════════════════════════════════════════════════════════

window.StreamViewer = {
    reinit: function(newConfig) {
        stopPolling();
        _initialized = false;
        _sessions    = [];
        _serverStats = [];
        _backoffUntil = 0;
        if (newConfig) window.streamviewerConfig = newConfig;
        init();
    },
    refresh: function() { _backoffUntil = 0; fetchSessions(); },
    getSessions: function() { return _sessions.slice(); },
};

window.streamviewerInit = window.StreamViewer.reinit;

})();
