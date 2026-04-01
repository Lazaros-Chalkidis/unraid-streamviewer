/* ═══════════════════════════════════════════════════════════════════════════
   Stream Viewer  —  streamviewer.js
   Drives the dashboard widget.

   Config is injected via window.streamviewerConfig.

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
var _pollTimer    = null;
var _inFlight     = false;
var _backoffUntil = 0;        // timestamp ms — skip fetch until this time
var _errorCount   = 0;        // consecutive fetch errors (for self-healing backoff)
var _initialized  = false;    // true after first successful fetch
var _reloadScheduled = false; // true when 403 auto-reload is pending
var _lastActiveStreams = 0;   // track active streams for docker stats mini-poll
var _dockerPollTimer  = null; // independent docker stats polling timer


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
    manualBtn:  function() { return document.getElementById('sv-manual-refresh');    },
    dockerStats:function() { return document.getElementById('sv-docker-stats');      },
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
    return h + ':' + pad2(m) + ':' + pad2(sec);
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
        case 'series':  return '<svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M21 3H3c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h5v2h8v-2h5c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 14H3V5h18v12z"/></svg>';
        case 'audio':
        case 'track':   return '<svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>';
        case 'photo':   return '<svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M21 3H3C2 3 1 4 1 5v14c0 1 1 2 2 2h18c1 0 2-1 2-2V5c0-1-1-2-2-2zM5 17l3.5-4.5 2.5 3 3.5-4.5L19 17H5z"/></svg>';
        default:        return '<svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M4 2v20h16V2H4zm2 2h3v3H6V4zm0 5h3v3H6V9zm0 5h3v3H6v-3zm5-10h7v7h-7V4zm0 9h7v7h-7v-7zM6 19v-1h3v1H6z"/></svg>';
    }
}

var BADGE_THEMES = {
    'default': { bg:'#1a1a1a',                    c:'rgba(255,255,255,.85)', br:'1px solid rgba(255,255,255,.15)' },
    'blue':    { bg:'rgba(21,101,192,.18)',         c:'#1976d2',              br:'1px solid rgba(21,101,192,.40)' },
    'lime':    { bg:'rgba(175,180,43,.15)',         c:'#c0ca33',              br:'1px solid rgba(175,180,43,.35)' },
    'green':   { bg:'rgba(46,204,113,.12)',         c:'#2ecc71',              br:'1px solid rgba(46,204,113,.30)' },
    'purple':  { bg:'#1a1a2d',                     c:'#9b8ce8',              br:'1px solid #2a2644' },
    'unraid':  { bg:'rgba(244,125,66,.15)',         c:'#f47d42',              br:'1px solid rgba(244,125,66,.35)' },
    'red':     { bg:'rgba(231,76,60,.15)',          c:'#e74c3c',              br:'1px solid rgba(231,76,60,.35)' },
    'cyan':    { bg:'rgba(0,188,212,.12)',          c:'#00bcd4',              br:'1px solid rgba(0,188,212,.30)' },
    'pink':    { bg:'rgba(233,30,99,.15)',          c:'#e91e63',              br:'1px solid rgba(233,30,99,.35)' },
    'gold':    { bg:'rgba(255,235,59,.10)',         c:'#fdd835',              br:'1px solid rgba(255,235,59,.25)' },
    'teal':    { bg:'rgba(0,150,136,.15)',          c:'#26a69a',              br:'1px solid rgba(0,150,136,.35)' },
    'mono':    { bg:'rgba(255,255,255,.06)',        c:'rgba(255,255,255,.45)',br:'1px solid rgba(255,255,255,.12)' },
};

function fmtBitrate(kbps) {
    if (!kbps || kbps <= 0) return '';
    return kbps >= 1000 ? (kbps / 1000).toFixed(1) + ' Mbps' : kbps + ' kbps';
}


// ══════════════════════════════════════════════════════════════════════════════
// 3b. DOMINANT COLOR EXTRACTION (canvas-based, for synopsis tinting)
// ══════════════════════════════════════════════════════════════════════════════

var _colorCache = {};
var _colorCanvas = null;

function getDominantColor(img) {
    if (!_colorCanvas) {
        _colorCanvas = document.createElement('canvas');
        _colorCanvas.width = 16;
        _colorCanvas.height = 16;
    }
    var ctx = _colorCanvas.getContext('2d');
    ctx.drawImage(img, 0, 0, 16, 16);
    var data;
    try { data = ctx.getImageData(0, 0, 16, 16).data; }
    catch(e) { return null; }

    var rSum = 0, gSum = 0, bSum = 0, count = 0;
    for (var i = 0; i < data.length; i += 4) {
        var r = data[i], g = data[i+1], b = data[i+2];
        var brightness = r * 0.299 + g * 0.587 + b * 0.114;
        if (brightness < 20 || brightness > 235) continue;
        rSum += r; gSum += g; bSum += b; count++;
    }
    if (count === 0) return null;
    return [Math.round(rSum/count), Math.round(gSum/count), Math.round(bSum/count)];
}

function applyDominantColors(container) {
    if (!container) return;
    container.querySelectorAll('.sv-stream__thumb-img').forEach(function(img) {
        var row3bd = img.closest('.sv-stream__row3-bd');
        if (!row3bd) return;

        var apply = function() {
            var src = img.src || '';
            var rgb = _colorCache[src];
            if (!rgb) {
                rgb = getDominantColor(img);
                if (rgb) _colorCache[src] = rgb;
            }
            if (!rgb) return;
            var desc = row3bd.querySelector('.sv-stream__desc');
            var thumbCol = row3bd.querySelector('.sv-stream__thumb-col');
            if (desc) {
                desc.style.background = 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ',.12)';
                desc.style.borderRightColor = 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ',.20)';
            }
            if (thumbCol) {
                thumbCol.style.borderRightColor = 'rgba(' + rgb[0] + ',' + rgb[1] + ',' + rgb[2] + ',.20)';
            }
        };

        if (img.complete && img.naturalWidth > 0) { apply(); }
        else { img.addEventListener('load', apply, { once: true }); }
    });
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

    // Row 1 left: media icon + state icon (play/pause) + kill button + title
    var stateIcon = isPaused
        ? '<i class="fa fa-pause sv-stream__state-icon sv-stream__state-icon--paused" title="Paused" aria-hidden="true"></i>'
        : '<i class="fa fa-play sv-stream__state-icon sv-stream__state-icon--playing" title="Playing" aria-hidden="true"></i>';

    var killHtml = '';
    if (cfg.allowKill) {
        killHtml = '<button class="sv-kill-btn"'
            + ' data-session-id="'       + esc(s.session_id         || '') + '"'
            + ' data-session-key="'      + esc(s.session_key        || '') + '"'
            + ' data-server-name="'      + esc(s.server_name        || '') + '"'
            + ' data-plex-session-uuid="'+ esc(s.plex_session_uuid  || '') + '"'
            + ' title="Stop stream">'
            + '<i class="fa fa-stop" aria-hidden="true"></i>'
            + '</button>';
    }

    var titleHtml = '<div class="sv-stream__main">'
        + '<span class="sv-stream__type-icon">' + mediaIcon(s.media_type) + '</span>'
        + stateIcon
        + killHtml
        + '<span class="sv-stream__title" title="' + esc(s.title) + '">' + esc(s.title) + '</span>'
        + '</div>';

    // Badges row -- LEFT: quality + live bitrate  |  RIGHT: server type + name + transcode
    var bt = BADGE_THEMES[cfg.badgeTheme] || BADGE_THEMES['default'];
    var serverBadge = '<span class="sv-stream__server-badge" title="' + esc(s.server_name) + '"'
        + ' style="background:' + bt.bg + ';color:' + bt.c + ';border:' + bt.br + ';">'
        + esc(s.server_name || s.server_type) + '</span>';

    var transHtml = '';
    if (cfg.showTranscode) {
        var tLabel = isPaused ? 'Paused' : ptm.label;
        var tMod   = isPaused ? 'paused' : ptm.badge;
        var speedSuffix = '';
        if (!isPaused && ptm.bar === 'trans' && s.server_type === 'plex') {
            var speedVal = (s.transcode_speed > 0) ? s.transcode_speed.toFixed(1) + 'x' : '';
            speedSuffix = '<span class="sv-stream__transcode-speed">' + esc(speedVal) + '</span>';
        }
        transHtml = '<span class="sv-stream__transcode sv-stream__transcode--' + esc(tMod) + '">'
            + esc(tLabel) + speedSuffix + '</span>';
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
        + '<div class="sv-stream__badges-right">' + serverTypeBadge + serverBadge + transHtml + '</div>'
        + '</div>';

    // Row 3: collapsible thumbnail preview
    var thumbSrc = s.thumb_url
        ? '/plugins/streamviewer/streamviewer_api.php?action=get_thumb&_svt='
          + encodeURIComponent(_cfg.svToken || '')
          + '&u=' + encodeURIComponent(s.thumb_url)
        : '';
    var thumbHtml = thumbSrc
        ? '<img class="sv-stream__thumb-img" src="' + thumbSrc + '" alt="Cover" loading="lazy">'
        : '<div class="sv-stream__thumb-placeholder">' + mediaIcon(s.media_type) + '</div>';
    var descHtml = s.summary
        ? '<p class="sv-stream__desc">' + esc(s.summary) + '</p>'
        : '';
    var thumbColHtml = '<div class="sv-stream__thumb-col">' + thumbHtml + '</div>';
    var badgesHtml = '';
    if (cfg.showSummary) {
        var sumCls = cfg.summaryOpen ? 'sv-stream__row3' : 'sv-stream__row3 sv-stream__row3--collapsed';
        var sumArrow = '&#9660;';
        badgesHtml = '<div class="' + sumCls + '">'
            + '<div class="sv-stream__row3-hd"><span style="font-size:.7em;opacity:.55;">Synopsis</span> <span class="sv-stream__row3-arrow">' + sumArrow + '</span></div>'
            + '<div class="sv-stream__row3-bd">' + thumbColHtml + descHtml + '</div>'
            + '</div>';
    }

    // Row 2: user · device · IP  +  progress
    var userHtml = '<span class="sv-stream__user">' + esc(s.user || 'Unknown') + '</span>';

    var deviceHtml = '';
    if (cfg.showDevice && s.device) {
        var devLabel = (s.client && s.client !== s.device) ? s.client + ' · ' + s.device : s.device;
        deviceHtml = '<span class="sv-stream__device-wrap">'
            + '<span class="sv-stream__sep">·</span>'
            + '<span class="sv-stream__device" title="' + esc(devLabel) + '">' + esc(s.device) + '</span>'
            + '</span>';
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

    // Row 4: technical details (codecs)
    var detailsHtml = '';
    if (cfg.showDetails) {
        var tags = [];

        if (s.video_codec) {
            var vcLabel = s.video_codec.toUpperCase();
            if (s.transcode_video_codec && s.transcode_video_codec.toUpperCase() !== vcLabel) {
                vcLabel += ' → ' + s.transcode_video_codec.toUpperCase();
            }
            tags.push('<span class="sv-dtag">' + esc(vcLabel) + '</span>');
        }
        if (s.bit_depth > 0)  tags.push('<span class="sv-dtag">' + esc(s.bit_depth + '-bit') + '</span>');
        if (s.video_range && s.video_range !== 'SDR') {
            tags.push('<span class="sv-dtag sv-dtag--accent">' + esc(s.video_range) + '</span>');
        } else if (s.video_range === 'SDR') {
            tags.push('<span class="sv-dtag">' + esc(s.video_range) + '</span>');
        }
        if (s.audio_codec) {
            var audioLabel = s.audio_codec.toUpperCase();
            if (s.audio_channels > 0) {
                var ch = s.audio_channels;
                audioLabel += ' ' + (ch === 2 ? '2.0' : ch === 6 ? '5.1' : ch === 8 ? '7.1' : String(ch) + 'ch');
            }
            tags.push('<span class="sv-dtag">' + esc(audioLabel) + '</span>');
        }
        if (s.audio_spatial)    tags.push('<span class="sv-dtag sv-dtag--accent">' + esc(s.audio_spatial) + '</span>');
        if (s.container)        tags.push('<span class="sv-dtag">' + esc(s.container.toUpperCase()) + '</span>');
        if (s.subtitle_language) {
            var subLabel = s.subtitle_language;
            if (s.subtitle_codec) subLabel += ' (' + s.subtitle_codec.toUpperCase() + ')';
            tags.push('<span class="sv-dtag sv-dtag--sub">Sub: ' + esc(subLabel) + '</span>');
        }
        if (s.hw_accel && s.hw_accel !== 'none') {
            tags.push('<span class="sv-dtag sv-dtag--hw">HW: ' + esc(s.hw_accel.toUpperCase()) + '</span>');
        } else if (s.play_type === 'transcode') {
            tags.push('<span class="sv-dtag sv-dtag--hw">HW: SW</span>');
        }
        if (s.transcode_reasons) tags.push('<span class="sv-dtag sv-dtag--reason">' + esc(s.transcode_reasons) + '</span>');
        if (s.transcode_buffer_pct > 0) tags.push('<span class="sv-dtag">Buffer: ' + esc(s.transcode_buffer_pct.toFixed(0)) + '%</span>');

        if (tags.length > 0) {
            detailsHtml = '<div class="sv-stream__details">'
                + '<div class="sv-stream__details-bd">' + tags.join('') + '</div>'
                + '</div>';
        }
    }

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
        + barHtml + badgesRowHtml + titleHtml + subHtml + detailsHtml + badgesHtml
        + '</div>';
}

function buildSkeletons() {
    return '<div class="sv-loading">'
        + '<div class="sv-loading__bars">'
        + '<div class="sv-loading__bar"></div>'
        + '<div class="sv-loading__bar"></div>'
        + '<div class="sv-loading__bar"></div>'
        + '<div class="sv-loading__bar"></div>'
        + '<div class="sv-loading__bar"></div>'
        + '</div>'
        + '<span class="sv-loading__text">Fetching streams...</span>'
        + '</div>';
}


// ══════════════════════════════════════════════════════════════════════════════
// 6. RENDER — streams list
// ══════════════════════════════════════════════════════════════════════════════

// Patch a single existing stream row with updated dynamic data (no DOM replacement).
// Returns true if a full rebuild is needed (e.g. title changed = different media).
function patchRow(el, s) {
    // Full rebuild if title or quality changed (different media)
    var titleEl = el.querySelector('.sv-stream__title');
    if (titleEl && titleEl.title !== (s.title || '')) return true;
    if (el.dataset.quality   !== (s.quality   || '')) return true;
    if (el.dataset.bandwidth  !== (s.bandwidth_kbps > 0 ? '1' : '0')) return true;

    // play_type or state changed: rebuild but preserve details open state
    var needRebuild = false;
    if (el.dataset.state    !== (s.state     || '')) needRebuild = true;
    if (el.dataset.playType !== (s.play_type || '')) needRebuild = true;
    if (needRebuild) {
        return true;
    }

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

    // Transcode badge label + modifier class (preserve nested speed span)
    var trans = el.querySelector('.sv-stream__transcode');
    if (trans) {
        var tLabel = isPaused ? 'Paused' : ptm.label;
        var tMod   = isPaused ? 'paused' : ptm.badge;
        var wantTransClass = 'sv-stream__transcode sv-stream__transcode--' + tMod;
        if (trans.className !== wantTransClass) trans.className = wantTransClass;
        // Update only the text node, not the speed span
        var textNode = trans.firstChild;
        if (textNode && textNode.nodeType === 3) {
            if (textNode.textContent !== tLabel) textNode.textContent = tLabel;
        }
    }

    // State icon (play/pause) in title row
    var mainDiv   = el.querySelector('.sv-stream__main');
    var stateIcon = el.querySelector('.sv-stream__main .sv-stream__state-icon');
    if (mainDiv) {
        var hasPause = stateIcon && stateIcon.classList.contains('fa-pause');
        var hasPlay  = stateIcon && stateIcon.classList.contains('fa-play');
        var titleSpan = mainDiv.querySelector('.sv-stream__title');
        if (isPaused && !hasPause) {
            if (stateIcon) stateIcon.parentNode.removeChild(stateIcon);
            var pi = document.createElement('i');
            pi.className = 'fa fa-pause sv-stream__state-icon sv-stream__state-icon--paused';
            pi.title = 'Paused';
            pi.setAttribute('aria-hidden', 'true');
            if (titleSpan) mainDiv.insertBefore(pi, titleSpan);
        } else if (!isPaused && !hasPlay) {
            if (stateIcon) stateIcon.parentNode.removeChild(stateIcon);
            var pl = document.createElement('i');
            pl.className = 'fa fa-play sv-stream__state-icon sv-stream__state-icon--playing';
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

    // Live transcode speed update (inside transcode badge) — keep last known value when API returns 0
    var speedEl = el.querySelector('.sv-stream__transcode .sv-stream__transcode-speed');
    if (speedEl && s.transcode_speed > 0) {
        var wantSpeed = s.transcode_speed.toFixed(1) + 'x';
        if (speedEl.textContent !== wantSpeed) speedEl.textContent = wantSpeed;
    }

    // Live transcode buffer update (inside details)
    var bufferTags = el.querySelectorAll('.sv-dtag');
    bufferTags.forEach(function(tag) {
        if (tag.textContent.indexOf('Buffer:') === 0 && s.transcode_buffer_pct > 0) {
            var wantBuf = 'Buffer: ' + s.transcode_buffer_pct.toFixed(0) + '%';
            if (tag.textContent !== wantBuf) tag.textContent = wantBuf;
        }
    });

    return false; // no rebuild needed
}

function renderStreams(sessions) {
    var container  = DOM.container();
    var emptyState = DOM.emptyState();
    if (!container) return;

    var visible = sortSessions(sessions);

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
    applyDominantColors(container);
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
    var btn             = e.currentTarget;
    var row             = btn.closest('.sv-stream');
    var sessionId       = btn.dataset.sessionId       || '';
    var sessionKey      = btn.dataset.sessionKey      || '';
    var serverName      = btn.dataset.serverName      || '';
    var plexSessionUuid = btn.dataset.plexSessionUuid || '';
    var titleEl         = row && row.querySelector('.sv-stream__title');
    var title           = titleEl ? titleEl.textContent : (sessionId || 'this stream');

    if (!confirm('Stop stream: "' + title + '"?\n\nThis will immediately disconnect the user.')) return;

    var serverIndex = resolveServerIndex(serverName);
    if (!serverIndex) {
        alert('Could not identify the server for this stream.\nTry saving Settings and refreshing.');
        return;
    }

    doKillSession(btn, row, serverIndex, sessionId, sessionKey, plexSessionUuid);
}

function doKillSession(btn, row, serverIndex, sessionId, sessionKey, plexSessionUuid) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin" aria-hidden="true"></i>';

    $.ajax({
        url:      '/plugins/streamviewer/streamviewer_api.php?_svt=' + encodeURIComponent(_cfg.svToken || ''),
        method:   'POST',
        timeout:  15000,
        headers:  { 'X-Requested-With': 'XMLHttpRequest' },
        data: {
            action:            'kill_session',
            server_index:      serverIndex,
            session_id:        sessionId,
            session_key:       sessionKey,
            plex_session_uuid: plexSessionUuid || '',
            reason:            'Stream terminated by Unraid administrator',
            _svt:              _cfg.svToken || '',
        },
        dataType: 'json',
        success: function(data) {
            if (data && data.ok) {
                // Fix Αιτία Δ: αμέσως αφαίρεσε από _sessions ώστε το
                // renderStreams() να μην ξαναβάλει τη σκοτωμένη ταινία
                // πριν φτάσει το επόμενο server fetch.
                var killId = sessionId || sessionKey;
                if (killId) {
                    _sessions = _sessions.filter(function(s) {
                        return (s.session_id || s.session_key) !== killId;
                    });
                }
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
                // Fix Αιτία ΣΤ: 3500ms δίνει στο Plex/Jellyfin χρόνο να κλείσει
                // τη session πριν κάνουμε re-fetch. Επίσης reset polling ώστε
                // το interval να μετράει από το φρέσκο fetch.
                stopPolling();
                setTimeout(function() {
                    fetchSessions(function() { startPolling(); });
                }, 3500);
            } else {
                btn.disabled = false;
                btn.innerHTML = 'STOP';
                alert('Failed to stop stream: ' + ((data && data.error) ? data.error : 'Unknown error'));
            }
        },
        error: function(xhr) {
            btn.disabled = false;
            btn.innerHTML = 'STOP';
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

var _lastDockerHtml = '';  // persist across Unraid tile re-renders

function updateDockerStats(stats, activeStreams) {
    if (!_cfg.showDocker || activeStreams === 0 || !Array.isArray(stats) || stats.length === 0) {
        _lastDockerHtml = '';
        var el = DOM.dockerStats();
        if (el) el.innerHTML = '';
        return;
    }

    var totalCpu = 0;
    var totalMem = 0;
    for (var i = 0; i < stats.length; i++) {
        totalCpu += (stats[i].cpu_pct || 0);
        totalMem += (stats[i].mem_used || 0);
    }

    var memStr;
    if (totalMem >= 1073741824) {
        memStr = (totalMem / 1073741824).toFixed(1) + ' GB';
    } else {
        memStr = Math.round(totalMem / 1048576) + ' MB';
    }

    var cpuColor = totalCpu >= 70 ? '#e74c3c' : totalCpu >= 50 ? '#f39c12' : '';
    var cpuFillColor = cpuColor || '#a3a3a3';
    var cpuValStyle = cpuColor ? ' style="color:' + cpuColor + ';"' : '';
    var cpuPct = Math.min(100, totalCpu);

    var memTotalBytes = 0;
    for (var j = 0; j < stats.length; j++) {
        memTotalBytes += (stats[j].mem_limit || 0);
    }
    var memPct = (memTotalBytes > 0) ? Math.min(100, (totalMem / memTotalBytes) * 100) : 30;

    _lastDockerHtml = '<span class="sv-docker-stat">'
        + '<span class="sv-docker-stat__label">CPU</span>'
        + '<span class="sv-docker-stat__bar"><span class="sv-docker-stat__fill sv-docker-stat__fill--cpu" style="width:' + cpuPct.toFixed(1) + '%;background:' + cpuFillColor + ';"></span></span>'
        + '<span class="sv-docker-stat__val"' + cpuValStyle + '>' + totalCpu.toFixed(1) + '%</span>'
        + '</span>'
        + '<span class="sv-docker-stat">'
        + '<span class="sv-docker-stat__label">RAM</span>'
        + '<span class="sv-docker-stat__bar"><span class="sv-docker-stat__fill sv-docker-stat__fill--ram" style="width:' + memPct.toFixed(1) + '%;"></span></span>'
        + '<span class="sv-docker-stat__val">' + memStr + '</span>'
        + '</span>';

    applyDockerHtml();
}

function applyDockerHtml() {
    var el = DOM.dockerStats();
    if (el && _lastDockerHtml) el.innerHTML = _lastDockerHtml;
}

// Unraid dashboard re-renders the tile every few seconds, wiping our innerHTML.
// This interval re-applies cached docker stats HTML if the element was emptied.
setInterval(function() {
    if (_lastDockerHtml) {
        var el = DOM.dockerStats();
        if (el && el.innerHTML === '') applyDockerHtml();
    }
}, 2000);

// Independent docker stats polling (every 5s, lightweight endpoint)
var _dockerFetching = false;

function fetchDockerStats() {
    if (!_cfg.showDocker || _lastActiveStreams === 0 || _dockerFetching) return;
    _dockerFetching = true;
    $.ajax({
        url:      '/plugins/streamviewer/streamviewer_api.php',
        method:   'GET',
        timeout:  12000,
        headers:  { 'X-Requested-With': 'XMLHttpRequest' },
        data: {
            action: 'get_docker_stats',
            _svt:   _cfg.svToken || '',
        },
        dataType: 'json',
        success: function(data) {
            updateDockerStats(data.docker_stats || [], _lastActiveStreams);
        },
        error: function() {},
        complete: function() { _dockerFetching = false; }
    });
}

function startDockerPoll() {
    if (_dockerPollTimer) return;
    fetchDockerStats();
    _dockerPollTimer = setInterval(fetchDockerStats, 5000);
}

function stopDockerPoll() {
    if (_dockerPollTimer) { clearInterval(_dockerPollTimer); _dockerPollTimer = null; }
}


// ══════════════════════════════════════════════════════════════════════════════
// 9. API FETCH
// ══════════════════════════════════════════════════════════════════════════════

function fetchSessions(onDone) {
    if (Date.now() < _backoffUntil) {
        // Fix Αιτία Α: ανανέωσε το timestamp ακόμα και σε backoff
        // ώστε ο χρήστης να ξέρει ότι το polling τρέχει (σε αναμονή).
        var ts = DOM.timestamp();
        if (ts) {
            var remaining = Math.ceil((_backoffUntil - Date.now()) / 1000);
            ts.textContent = fmtNow() + ' (retry in ' + remaining + 's)';
        }
        if (typeof onDone === 'function') onDone('backoff', null);
        return;
    }
    if (_inFlight) return;
    _inFlight    = true;
    _lastFetchAt = Date.now();
    // Fix Αιτία Β: safety timeout > AJAX timeout (38s) για να αποφύγουμε
    // πρόωρη απελευθέρωση του _inFlight lock και overlapping requests.
    var _inFlightTimeout = setTimeout(function() { _inFlight = false; }, 40000);

    $.ajax({
        url:      '/plugins/streamviewer/streamviewer_api.php',
        method:   'GET',
        timeout:  38000,  // worst case: 5 servers × 7s timeout + margin
        headers:  { 'X-Requested-With': 'XMLHttpRequest' },
        data: {
            action:  'get_sessions',
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
            _lastActiveStreams = data.total_sessions || 0;
            updateTimestamp();
            flashPulse();
            updateErrorIndicator(_serverStats);
            updateDockerStats(data.docker_stats || [], data.total_sessions || 0);
            if (_lastActiveStreams > 0) startDockerPoll(); else stopDockerPoll();

            _initialized = true;
            if (typeof onDone === 'function') onDone(null, data);
        },

        error: function(xhr, status) {
            var msg = (xhr.responseJSON && xhr.responseJSON.error)
                ? xhr.responseJSON.error : (status || 'Request failed');

            // Token expired/invalid: page reload is the only way to get a fresh nonce
            if (xhr.status === 403 && !_reloadScheduled) {
                _reloadScheduled = true;
                setTimeout(function() { location.reload(); }, 2000);
                return;
            }

            _errorCount = (_errorCount || 0) + 1;
            // Backoff: 15s -> 45s, then reset after 4 failures (self-healing)
            if (_errorCount >= 4) {
                _errorCount   = 0;
                _backoffUntil = Date.now() + 15000;
            } else {
                _backoffUntil = Date.now() + (_errorCount === 1 ? 15000 : 45000);
            }
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
    // visibilitychange: tab hidden → stop, tab visible → resume
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopPolling();
            stopDockerPoll();
        } else {
            _backoffUntil = 0;
            _inFlight     = false;
            fetchSessions();
            startPolling();
            if (_lastActiveStreams > 0) startDockerPoll();
        }
    });

    // Fix Αιτία Γ: browsers throttle setInterval σε background/low-power mode
    // ακόμα κι όταν το tab δεν είναι hidden. Ο window focus event δεν υπόκειται
    // στο ίδιο throttling — τον χρησιμοποιούμε ως safety net.
    window.addEventListener('focus', function() {
        if (document.hidden) return;
        var staleMs = (_cfg.refreshInterval || 30000) * 2;
        if (_lastFetchAt > 0 && (Date.now() - _lastFetchAt) > staleMs) {
            _backoffUntil = 0;
            _inFlight     = false;
            fetchSessions(function() { startPolling(); });
        }
    });
}


// ══════════════════════════════════════════════════════════════════════════════
// 11. REFRESH BUTTONS
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
// 12. "NO SERVERS" STATE
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
// 13. CONFIG RESOLUTION
// ══════════════════════════════════════════════════════════════════════════════

function resolveConfig() {
    var raw = window.streamviewerConfig || {};

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
        showDetails:         raw.showDetails  !== false,
        showSummary:         raw.showSummary  !== false,
        summaryOpen:         raw.summaryOpen  === true,
        allowKill:           raw.allowKill    === true,
        showDocker:          raw.showDocker   !== false,
        servers:             Array.isArray(raw.servers) ? raw.servers : [],
        noServersConfigured: raw.noServersConfigured === true,
        isResponsive:        raw.isResponsive !== false,
        badgeTheme:          String(raw.badgeTheme || 'default'),
    };
}


// ══════════════════════════════════════════════════════════════════════════════
// 14. THEME DETECTION (light/dark)
// ══════════════════════════════════════════════════════════════════════════════

function detectTheme() {
    var bg = getComputedStyle(document.body).backgroundColor || '';
    var m = bg.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
    if (!m) return;
    var luminance = ((+m[1]) * 299 + (+m[2]) * 587 + (+m[3]) * 114) / 1000;
    var isLight = luminance > 128;
    var wrap = document.querySelector('.sv-widget-wrap') || document.getElementById('db-streamviewer');
    if (wrap) wrap.classList.toggle('sv-light', isLight);
}


// ══════════════════════════════════════════════════════════════════════════════
// 15. INIT
// ══════════════════════════════════════════════════════════════════════════════

function init() {
    detectTheme();
    _cfg = resolveConfig();

    if (_cfg.noServersConfigured) {
        renderNoServers();
        return;
    }

    wireRefreshBtn(DOM.manualBtn());
    initVisibilityHandling();

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
