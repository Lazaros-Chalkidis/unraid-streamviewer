/* ═══════════════════════════════════════════════════════════════════════════
   Stream Viewer -- Statistics Tool Page
   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3
   /plugins/streamviewer/js/streamviewer-tool.js

   Drives the statistics / history tool page.
   Depends on: Chart.js (loaded via CDN in the .page file), jQuery (Unraid)
   ═══════════════════════════════════════════════════════════════════════════ */
/* global $, Chart */

(function () {
'use strict';

// Guard against double-init
if (window.__svtLoaded) return;
window.__svtLoaded = true;

// ══════════════════════════════════════════════════════════════════════════════
// 1. STATE
// ══════════════════════════════════════════════════════════════════════════════

var _cfg        = window.svToolConfig || {};
var _token      = _cfg.svToken || '';
var _light      = _cfg.lightTheme || false;
var _period     = '30d';
var _histPage   = 1;
var _chart      = null;  // Chart.js instance
var _loading    = false;

// Theme-dependent chart colors
var _chartColors = _light ? {
    tooltipBg:    '#ffffff',
    tooltipTitle: '#333333',
    tooltipBody:  '#555555',
    tooltipBorder:'#ddd',
    tickColor:    '#888',
    gridColor:    'rgba(0,0,0,.08)',
    legendColor:  '#666',
} : {
    tooltipBg:    '#1a1a1a',
    tooltipTitle: '#e0e0e0',
    tooltipBody:  '#ccc',
    tooltipBorder:'#3a3a3a',
    tickColor:    '#666',
    gridColor:    'rgba(255,255,255,.06)',
    legendColor:  '#999',
};

// API base
var API = '/plugins/streamviewer/streamviewer_api.php';

// ══════════════════════════════════════════════════════════════════════════════
// 2. DOM REFS
// ══════════════════════════════════════════════════════════════════════════════

var DOM = {
    period:       function() { return document.getElementById('svt-period'); },
    subtitle:     function() { return document.getElementById('svt-subtitle'); },
    // Cards
    totalPlays:   function() { return document.getElementById('svt-total-plays'); },
    hours:        function() { return document.getElementById('svt-hours'); },
    users:        function() { return document.getElementById('svt-users'); },
    peak:         function() { return document.getElementById('svt-peak'); },
    // Play types
    ptDpFill:     function() { return document.getElementById('svt-pt-dp-fill'); },
    ptDsFill:     function() { return document.getElementById('svt-pt-ds-fill'); },
    ptTcFill:     function() { return document.getElementById('svt-pt-tc-fill'); },
    ptDpPct:      function() { return document.getElementById('svt-pt-dp-pct'); },
    ptDsPct:      function() { return document.getElementById('svt-pt-ds-pct'); },
    ptTcPct:      function() { return document.getElementById('svt-pt-tc-pct'); },
    ptRmFill:     function() { return document.getElementById('svt-pt-rm-fill'); },
    ptRmPct:      function() { return document.getElementById('svt-pt-rm-pct'); },
    // Chart
    chartCanvas:  function() { return document.getElementById('svt-daily-chart'); },
    chartPeriod:  function() { return document.getElementById('svt-chart-period'); },
    // Leaderboards
    topMedia:     function() { return document.getElementById('svt-top-media'); },
    topUsers:     function() { return document.getElementById('svt-top-users'); },
    // History
    histBody:     function() { return document.getElementById('svt-history-body'); },
    pagination:   function() { return document.getElementById('svt-pagination'); },
    filterServer: function() { return document.getElementById('svt-filter-server'); },
    filterPlay:   function() { return document.getElementById('svt-filter-play'); },
    page:         function() { return document.querySelector('.svt-page'); },
};


// ══════════════════════════════════════════════════════════════════════════════
// 3. FETCH HELPERS
// ══════════════════════════════════════════════════════════════════════════════

function apiUrl(action, extra) {
    var url = API + '?action=' + encodeURIComponent(action)
            + '&_svt=' + encodeURIComponent(_token)
            + '&period=' + encodeURIComponent(_period);
    if (extra) url += '&' + extra;
    return url;
}

function fetchJson(action, extra) {
    return $.ajax({
        url: apiUrl(action, extra),
        dataType: 'json',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
}


// ══════════════════════════════════════════════════════════════════════════════
// 4. RENDER -- Summary Cards + Play Types
// ══════════════════════════════════════════════════════════════════════════════

function loadStats() {
    return fetchJson('get_stats').then(function(data) {
        var el;

        el = DOM.totalPlays();
        if (el) el.textContent = formatNumber(data.total_plays || 0);

        el = DOM.hours();
        if (el) el.innerHTML = formatNumber(data.hours_watched || 0) + '<span class="svt-unit">h</span>';

        el = DOM.users();
        if (el) el.textContent = formatNumber(data.unique_users || 0);

        el = DOM.peak();
        if (el) el.textContent = formatNumber(data.peak_concurrent || 0);

        // Play type ratio (3 types sum to 100%)
        var pt = data.play_types || {};
        var dp = pt.direct_play   || 0;
        var ds = pt.direct_stream || 0;
        var tc = pt.transcode     || 0;
        var total = dp + ds + tc || 1;

        setBar(DOM.ptDpFill(), DOM.ptDpPct(), dp, total);
        setBar(DOM.ptDsFill(), DOM.ptDsPct(), ds, total);
        setBar(DOM.ptTcFill(), DOM.ptTcPct(), tc, total);

        // Remote (separate, from IP analysis)
        var rmPct = data.remote_pct || 0;
        var rmFill = DOM.ptRmFill();
        var rmPctEl = DOM.ptRmPct();
        if (rmFill) rmFill.style.width = rmPct + '%';
        if (rmPctEl) rmPctEl.textContent = rmPct + '%';

        // Subtitle
        var sub = DOM.subtitle();
        if (sub) {
            var periodLabel = { '7d': 'Last 7 days', '30d': 'Last 30 days', '90d': 'Last 90 days' };
            sub.textContent = (periodLabel[_period] || 'Last 30 days');
        }
    });
}

function setBar(fillEl, pctEl, value, total) {
    var pct = Math.round((value / total) * 100);
    if (fillEl) fillEl.style.width = pct + '%';
    if (pctEl)  pctEl.textContent  = pct + '%';
}


// ══════════════════════════════════════════════════════════════════════════════
// 5. RENDER -- Daily Chart (Chart.js stacked bar)
// ══════════════════════════════════════════════════════════════════════════════

function loadDailyChart() {
    return fetchJson('get_daily_chart').then(function(data) {
        var daily = data.daily || [];
        var labels = [];
        var plex = [], jf = [], emby = [];

        for (var i = 0; i < daily.length; i++) {
            var d = daily[i];
            // Format date label: M/D
            var parts = (d.date || '').split('-');
            labels.push(parts.length === 3 ? parseInt(parts[1],10) + '/' + parseInt(parts[2],10) : d.date);
            plex.push(d.plex || 0);
            jf.push(d.jellyfin || 0);
            emby.push(d.emby || 0);
        }

        var canvas = DOM.chartCanvas();
        if (!canvas) return;

        if (_chart) {
            _chart.destroy();
            _chart = null;
        }

        var periodLabel = { '7d': 'last 7 days', '30d': 'last 30 days', '90d': 'last 90 days' };
        var cp = DOM.chartPeriod();
        if (cp) cp.textContent = periodLabel[_period] || 'last 30 days';

        var ctx = canvas.getContext('2d');

        _chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Plex',
                        data: plex,
                        backgroundColor: '#e5a00d',
                        borderRadius: 2,
                        maxBarThickness: 20,
                    },
                    {
                        label: 'Jellyfin',
                        data: jf,
                        backgroundColor: '#00a4dc',
                        borderRadius: 2,
                        maxBarThickness: 20,
                    },
                    {
                        label: 'Emby',
                        data: emby,
                        backgroundColor: '#52b54b',
                        borderRadius: 2,
                        maxBarThickness: 20,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end',
                        labels: {
                            color: _chartColors.legendColor,
                            boxWidth: 12,
                            boxHeight: 12,
                            padding: 12,
                            font: { size: 12 },
                        },
                    },
                    tooltip: {
                        backgroundColor: _chartColors.tooltipBg,
                        titleColor: _chartColors.tooltipTitle,
                        bodyColor: _chartColors.tooltipBody,
                        borderColor: _chartColors.tooltipBorder,
                        borderWidth: 1,
                        cornerRadius: 6,
                        padding: 10,
                        callbacks: {
                            footer: function(items) {
                                var sum = 0;
                                for (var j = 0; j < items.length; j++) sum += items[j].parsed.y;
                                return 'Total: ' + sum;
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        stacked: true,
                        ticks: { color: _chartColors.tickColor, font: { size: 11 }, maxRotation: 0 },
                        grid:  { display: false },
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: { color: _chartColors.tickColor, font: { size: 11 }, precision: 0 },
                        grid:  { color: _chartColors.gridColor },
                    },
                },
            },
        });
    });
}


// ══════════════════════════════════════════════════════════════════════════════
// 6. RENDER -- Leaderboards
// ══════════════════════════════════════════════════════════════════════════════

// Color palette for user avatars
var AVATAR_COLORS = ['#e5a00d','#00a4dc','#52b54b','#e91e63','#9c27b0','#ff5722','#00bcd4','#8bc34a'];

var MEDIA_COLORS = {
    'episode': '#2980b9',
    'movie':   '#c0392b',
    'track':   '#8e44ad',
};
var MEDIA_COLOR_DEFAULT = '#3a3a3a';

var SERVER_COLORS = {
    'plex':     '#e5a00d',
    'jellyfin': '#00a4dc',
    'emby':     '#52b54b',
};

function loadTopMedia() {
    return fetchJson('get_top_media', 'limit=5').then(function(data) {
        var el = DOM.topMedia();
        if (!el) return;

        var items = data.media || [];
        if (!items.length) { el.innerHTML = '<div class="svt-list__empty">No data yet</div>'; return; }

        var html = '';
        for (var i = 0; i < items.length; i++) {
            var m = items[i];
            var icon = mediaTypeIcon(m.media_type);
            var bgColor = MEDIA_COLORS[m.media_type] || MEDIA_COLOR_DEFAULT;
            html += '<div class="svt-list-item">'
                  + '<div class="svt-media-icon" style="background:' + bgColor + ';color:#fff;"><i class="fa ' + icon + '" aria-hidden="true"></i></div>'
                  + '<div class="svt-list-item__info">'
                  +   '<div class="svt-list-item__name" title="' + esc(m.title) + '">' + esc(m.title) + '</div>'
                  +   '<div class="svt-list-item__meta">' + m.plays + ' plays &middot; ' + m.users + ' users</div>'
                  + '</div>'
                  + '<div class="svt-list-item__hours">' + m.hours + 'h</div>'
                  + '</div>';
        }
        el.innerHTML = html;
    });
}

function loadTopUsers() {
    return fetchJson('get_top_users', 'limit=5').then(function(data) {
        var el = DOM.topUsers();
        if (!el) return;

        var items = data.users || [];
        if (!items.length) { el.innerHTML = '<div class="svt-list__empty">No data yet</div>'; return; }

        var html = '';
        for (var i = 0; i < items.length; i++) {
            var u = items[i];
            var color = AVATAR_COLORS[i % AVATAR_COLORS.length];
            var initials = userInitials(u.user);

            // Build colored server list
            var serverParts = (u.servers || '').split(',');
            var serverHtml = [];
            for (var j = 0; j < serverParts.length; j++) {
                var s = serverParts[j].trim();
                var sColor = SERVER_COLORS[s] || '#999';
                var sName = s.charAt(0).toUpperCase() + s.slice(1);
                serverHtml.push('<span style="color:' + sColor + ';">' + esc(sName) + '</span>');
            }

            html += '<div class="svt-list-item">'
                  + '<div class="svt-avatar" style="background:' + color + ';">' + esc(initials) + '</div>'
                  + '<div class="svt-list-item__info">'
                  +   '<div class="svt-list-item__name" title="' + esc(u.user) + '">' + esc(u.user) + '</div>'
                  +   '<div class="svt-list-item__meta">' + u.plays + ' plays &middot; ' + serverHtml.join(' + ') + '</div>'
                  + '</div>'
                  + '<div class="svt-list-item__hours">' + u.hours + 'h</div>'
                  + '</div>';
        }
        el.innerHTML = html;
    });
}

function mediaTypeIcon(type) {
    switch (type) {
        case 'episode': return 'fa-tv';
        case 'movie':   return 'fa-film';
        case 'track':   return 'fa-music';
        case 'album':   return 'fa-music';
        default:        return 'fa-play';
    }
}

function userInitials(name) {
    if (!name) return '?';
    var parts = name.trim().split(/\s+/);
    if (parts.length >= 2) return parts[0].charAt(0) + parts[parts.length-1].charAt(0);
    return name.substring(0, 2);
}


// ══════════════════════════════════════════════════════════════════════════════
// 7. RENDER -- History Table
// ══════════════════════════════════════════════════════════════════════════════

function loadHistory() {
    var filterServer = (DOM.filterServer() || {}).value || '';
    var filterPlay   = (DOM.filterPlay()   || {}).value || '';
    var extra = 'page=1&per_page=10';
    if (filterServer) extra += '&server_type=' + encodeURIComponent(filterServer);
    if (filterPlay)   extra += '&play_type='   + encodeURIComponent(filterPlay);

    return fetchJson('get_history', extra).then(function(data) {
        var body = DOM.histBody();
        if (!body) return;

        var rows = data.rows || [];
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="7" class="svt-table__empty">No history yet</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var titleIcon = mediaTypeIcon(r.media_type);
            var ipHtml = '';
            if (r.ip_address) {
                var locTag = r.is_local
                    ? '<span class="svt-ip-tag svt-ip-tag--local">L</span>'
                    : '<span class="svt-ip-tag svt-ip-tag--remote">R</span>';
                ipHtml = esc(r.ip_address) + ' ' + locTag;
            }
            html += '<tr>'
                  + '<td>' + formatDate(r.ended_at) + '</td>'
                  + '<td>' + esc(r.user) + '</td>'
                  + '<td><i class="fa ' + titleIcon + ' svt-title-icon" aria-hidden="true"></i> ' + esc(r.title) + '</td>'
                  + '<td>' + serverBadge(r.server_type) + '</td>'
                  + '<td>' + playBadge(r.play_type) + '</td>'
                  + '<td style="font-size:.85rem;color:var(--svt-text-dim);">' + ipHtml + '</td>'
                  + '<td>' + esc(r.duration) + '</td>'
                  + '</tr>';
        }
        body.innerHTML = html;
    });
}

function serverBadge(type) {
    var cls = 'svt-badge svt-badge--' + esc(type);
    return '<span class="' + cls + '">' + esc(type) + '</span>';
}

function playBadge(type) {
    var map = {
        'direct_play':   { cls: 'dp', label: 'DIRECT PLAY' },
        'direct_stream': { cls: 'ds', label: 'DIRECT STREAM' },
        'transcode':     { cls: 'tc', label: 'TRANSCODE' },
    };
    var info = map[type] || { cls: 'dp', label: type };
    return '<span class="svt-badge svt-badge--' + info.cls + '">' + info.label + '</span>';
}



// ══════════════════════════════════════════════════════════════════════════════
// 8. UTILITY HELPERS
// ══════════════════════════════════════════════════════════════════════════════

function formatNumber(n) {
    if (typeof n !== 'number') n = parseInt(n, 10) || 0;
    return n.toLocaleString();
}

function formatDate(ts) {
    if (!ts) return '\u2014';
    var d = new Date(ts * 1000);
    var month = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var h = d.getHours();
    var m = ('0' + d.getMinutes()).slice(-2);
    return month[d.getMonth()] + ' ' + d.getDate() + ', '
         + ('0' + h).slice(-2) + ':' + m;
}

var _escDiv = null;
function esc(s) {
    if (!s) return '';
    if (!_escDiv) _escDiv = document.createElement('div');
    _escDiv.textContent = s;
    return _escDiv.innerHTML;
}

// Auto-size select to fit selected option text
var _sizeSpan = null;
function autoSizeSelect(sel) {
    if (!sel) return;
    if (!_sizeSpan) {
        _sizeSpan = document.createElement('span');
        _sizeSpan.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap;';
        document.body.appendChild(_sizeSpan);
    }
    _sizeSpan.style.font = window.getComputedStyle(sel).font;
    _sizeSpan.textContent = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '';
    sel.style.width = (_sizeSpan.offsetWidth + 28) + 'px'; // text + padding-left + arrow + padding-right
}
function autoSizeAllSelects() {
    var sels = document.querySelectorAll('.svt-select--sm, .svt-select');
    for (var i = 0; i < sels.length; i++) {
        autoSizeSelect(sels[i]);
        sels[i].removeEventListener('change', autoSizeOnChange);
        sels[i].addEventListener('change', autoSizeOnChange);
    }
}
function autoSizeOnChange() { autoSizeSelect(this); }


// ══════════════════════════════════════════════════════════════════════════════
// 9. LOAD ALL
// ══════════════════════════════════════════════════════════════════════════════

function loadAll() {
    if (_loading) return $.Deferred().resolve();
    _loading = true;
    var pg = DOM.page();
    if (pg) pg.classList.add('svt-loading');

    return $.when(
        loadStats(),
        loadDailyChart(),
        loadTopMedia(),
        loadTopUsers(),
        loadHistory()
    ).always(function() {
        _loading = false;
        if (pg) pg.classList.remove('svt-loading');
    });
}


// ══════════════════════════════════════════════════════════════════════════════
// 10. TAB SWITCHING
// ══════════════════════════════════════════════════════════════════════════════

var TAB_MAP = {
    svtTabDashboard:  'svtPanelDashboard',
    svtTabLibraries:  'svtPanelLibraries',
    svtTabUsers:      'svtPanelUsers',
    svtTabHistory:    'svtPanelHistory',
    svtTabGraphs:     'svtPanelGraphs',
    svtTabAlerts:     'svtPanelAlerts',
};
var _activeTab = 'svtTabDashboard';
var _libLoaded = false;
var _usersLoaded = false;
var _usersPeriod = '30d';
var _histTabLoaded = false;
var _histTabPage = 1;
var _histTabPeriod = '30d';
var _graphsLoaded = false;
var _graphsPeriod = '30d';
var _graphCharts = {};
var _alertsLoaded = false;
var _alertsPeriod = '30d';

function switchTab(tabId) {
    if (_activeTab === tabId) return;
    _activeTab = tabId;

    // Toggle tab active class
    var tabs = document.querySelectorAll('.svt-tab');
    for (var i = 0; i < tabs.length; i++) {
        tabs[i].classList.toggle('active', tabs[i].id === tabId);
    }

    // Toggle panels
    var panelIds = Object.keys(TAB_MAP);
    for (var j = 0; j < panelIds.length; j++) {
        var panel = document.getElementById(TAB_MAP[panelIds[j]]);
        if (panel) panel.style.display = (panelIds[j] === tabId) ? 'block' : 'none';
    }

    // Lazy load Libraries on first switch
    if (tabId === 'svtTabLibraries' && !_libLoaded) {
        _libLoaded = true;
        loadLibraries();
        loadRecentlyAdded();
    }

    // Lazy load Users on first switch
    if (tabId === 'svtTabUsers' && !_usersLoaded) {
        _usersLoaded = true;
        loadUserStats();
    }

    // Lazy load History on first switch
    if (tabId === 'svtTabHistory' && !_histTabLoaded) {
        _histTabLoaded = true;
        loadHistoryTab();
    }

    // Lazy load Graphs on first switch
    if (tabId === 'svtTabGraphs' && !_graphsLoaded) {
        _graphsLoaded = true;
        loadGraphs();
    }

    // Lazy load Alerts on first switch
    if (tabId === 'svtTabAlerts' && !_alertsLoaded) {
        _alertsLoaded = true;
        loadAlerts();
    }

    // Re-measure selects now visible
    setTimeout(autoSizeAllSelects, 100);
}


// ══════════════════════════════════════════════════════════════════════════════
// 11a. LIBRARIES TAB
// ══════════════════════════════════════════════════════════════════════════════

var LIB_TYPE_COLORS = {
    'movie':  '#c0392b',
    'show':   '#2980b9',
    'music':  '#8e44ad',
    'photo':  '#555',
};
var LIB_TYPE_ICONS = {
    'movie':  'fa-film',
    'show':   'fa-tv',
    'music':  'fa-music',
    'photo':  'fa-camera',
};
var SERVER_DOT_COLORS = {
    'plex':     '#e5a00d',
    'jellyfin': '#00a4dc',
    'emby':     '#52b54b',
};

function loadLibraries() {
    return fetchJson('get_libraries').then(function(data) {
        // Summary cards
        var elTotal   = document.getElementById('svt-lib-total');
        var elCount   = document.getElementById('svt-lib-count');
        var elServers = document.getElementById('svt-lib-servers');
        if (elTotal)   elTotal.textContent   = formatNumber(data.total_items || 0);
        if (elCount)   elCount.textContent   = formatNumber(data.total_libs || 0);
        if (elServers) elServers.textContent = formatNumber(data.server_count || 0);

        // Server sections
        var container = document.getElementById('svt-lib-servers-container');
        if (!container) return;

        var servers = data.servers || [];
        if (!servers.length) {
            container.innerHTML = '<div class="svt-disabled"><p>No servers configured</p></div>';
            return;
        }

        var html = '';
        for (var i = 0; i < servers.length; i++) {
            var s = servers[i];
            var dotColor = SERVER_DOT_COLORS[s.type] || '#999';
            var isOnline = s.synced_at > 0;
            var statusCls = isOnline ? 'svt-lib-server-hd__status--online' : 'svt-lib-server-hd__status--offline';
            var statusText = isOnline ? 'ONLINE' : 'OFFLINE';
            var offlineCls = isOnline ? '' : ' svt-lib-server--offline';
            var syncAgo = isOnline ? timeAgo(s.synced_at) : 'Never';
            var serverTint = 'rgba(0,164,220,.04)';

            var libOpen = _cfg.libSectionsOpen || false;

            html += '<div class="svt-lib-server' + offlineCls + '">';
            html += '<div class="svt-lib-server-hd svt-lib-toggle" data-collapsed="' + (libOpen ? 'false' : 'true') + '">';
            html += '<div class="svt-lib-server-hd__dot" style="background:' + dotColor + ';"></div>';
            html += '<span class="svt-lib-server-hd__name">' + esc(s.name) + '</span>';
            html += '<span class="svt-lib-server-hd__status ' + statusCls + '">' + statusText + '</span>';
            html += '<span class="svt-lib-server-hd__sync">Last synced: ' + esc(syncAgo) + '</span>';
            html += '<i class="fa ' + (libOpen ? 'fa-chevron-up' : 'fa-chevron-down') + ' svt-lib-toggle__arrow" aria-hidden="true"></i>';
            html += '</div>';

            var libs = s.libraries || [];
            html += '<div class="svt-lib-server__body"' + (libOpen ? '' : ' style="display:none;"') + '>';
            if (libs.length) {
                html += '<div class="svt-lib-grid">';
                for (var j = 0; j < libs.length; j++) {
                    var lib = libs[j];
                    var bgColor = LIB_TYPE_COLORS[lib.type] || '#555';
                    var icon    = LIB_TYPE_ICONS[lib.type]  || 'fa-folder';
                    var total   = lib.total_items || 0;
                    var watched = lib.watched || 0;
                    var pct     = total > 0 ? Math.round((watched / total) * 100) : 0;
                    var typeLabel = { movie: 'movies', show: 'shows', music: 'albums', photo: 'photos' }[lib.type] || 'items';

                    html += '<div class="svt-lib-card" style="' + (serverTint ? 'background:' + serverTint + ';' : '') + '">';
                    html += '<div class="svt-lib-card__hd">';
                    html += '<div class="svt-lib-card__icon" style="background:' + bgColor + ';"><i class="fa ' + icon + '" aria-hidden="true"></i></div>';
                    var episodeSuffix = (lib.episode_count > 0) ? ' / ' + formatNumber(lib.episode_count) + ' episodes' : '';
                    html += '<span class="svt-lib-card__name">' + esc(lib.name) + ' <span class="svt-lib-card__num">' + formatNumber(total) + ' ' + typeLabel + episodeSuffix + '</span></span>';
                    html += '</div>';
                    html += '<div class="svt-lib-card__bar"><div class="svt-lib-card__bar-fill" style="width:' + pct + '%;"></div></div>';
                    html += '<div class="svt-lib-card__watched">' + pct + '% watched (' + formatNumber(watched) + ')</div>';
                    html += '</div>';
                }
                html += '</div>';
            } else {
                html += '<div style="color:var(--svt-text-dim);font-size:.9rem;padding:.5rem 0;">No libraries found</div>';
            }
            html += '</div>'; // close svt-lib-server__body

            html += '</div>';
        }
        container.innerHTML = html;
    });
}

function loadRecentlyAdded() {
    return fetchJson('get_recently_added', 'limit=10').then(function(data) {
        var body = document.getElementById('svt-lib-recent-body');
        if (!body) return;

        var items = data.items || [];
        if (!items.length) {
            body.innerHTML = '<tr><td colspan="6" class="svt-table__empty">No recently added items</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < items.length; i++) {
            var r = items[i];
            var typeIcon = mediaTypeIcon(r.media_type);
            var typeLbl = r.type_label || '-';
            var typeColor = LIB_TYPE_COLORS[r.media_type] || LIB_TYPE_COLORS['movie'] || '#555';
            var statusCls = r.watched ? 'svt-lib-status--watched' : 'svt-lib-status--unwatched';
            var statusTxt = r.watched ? 'Watched' : 'Unwatched';

            html += '<tr>';
            html += '<td>' + formatDate(r.added_at) + '</td>';
            html += '<td><i class="fa ' + typeIcon + ' svt-title-icon" aria-hidden="true"></i> ' + esc(r.title) + '</td>';
            html += '<td><span style="color:' + typeColor + ';font-size:.85rem;">' + esc(typeLbl) + '</span></td>';
            html += '<td>' + serverBadge(r.server_type) + '</td>';
            html += '<td>' + esc(r.library_name) + '</td>';
            html += '<td><span class="svt-lib-status ' + statusCls + '">' + statusTxt + '</span></td>';
            html += '</tr>';
        }
        body.innerHTML = html;
    });
}

function timeAgo(ts) {
    if (!ts) return 'Never';
    var diff = Math.floor(Date.now() / 1000) - ts;
    if (diff < 60)   return diff + ' sec ago';
    if (diff < 3600)  return Math.floor(diff / 60) + ' min ago';
    if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
    return Math.floor(diff / 86400) + ' days ago';
}


// ══════════════════════════════════════════════════════════════════════════════
// 11b. USERS TAB
// ══════════════════════════════════════════════════════════════════════════════

var USER_COLORS = ['#e5a00d','#00a4dc','#52b54b','#e91e63','#9c27b0','#ff5722','#00bcd4','#8bc34a'];

function loadUserStats() {
    var url = API + '?action=get_user_stats'
            + '&_svt=' + encodeURIComponent(_token)
            + '&period=' + encodeURIComponent(_usersPeriod);

    return $.ajax({ url: url, dataType: 'json', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(data) {
        // Summary cards
        var el;
        el = document.getElementById('svt-u-total');
        if (el) el.textContent = formatNumber(data.total_users || 0);
        el = document.getElementById('svt-u-most-active');
        if (el) el.textContent = data.most_active || '\u2014';
        el = document.getElementById('svt-u-avg-plays');
        if (el) el.textContent = formatNumber(data.avg_plays || 0);
        el = document.getElementById('svt-u-avg-hours');
        if (el) el.innerHTML = formatNumber(data.avg_hours || 0) + '<span class="svt-unit">h</span>';

        // User cards
        var container = document.getElementById('svt-users-container');
        if (!container) return;

        var users = data.users || [];
        if (!users.length) {
            container.innerHTML = '<div class="svt-table__empty">No user data yet</div>';
            return;
        }

        var html = '';
        for (var i = 0; i < users.length; i++) {
            var u = users[i];
            var avatarColor = USER_COLORS[i % USER_COLORS.length];
            var initials = userInitials(u.user);

            // Server badges
            var serverParts = (u.servers || '').split(',');
            var serverBadges = '';
            for (var j = 0; j < serverParts.length; j++) {
                var s = serverParts[j].trim();
                if (s) serverBadges += serverBadge(s) + ' ';
            }

            // Location badge
            var locBadge = '';
            if (u.last_ip) {
                locBadge = u.is_local
                    ? '<span class="svt-user-badge-local">Local</span>'
                    : '<span class="svt-user-badge-remote">Remote</span>';
            }

            // Active badge
            var activeBadge = u.is_active
                ? '<span class="svt-user-badge-active">Active now</span>'
                : '';

            // Play type bar segments
            var pt = u.play_types || {};
            var dp = pt.direct_play || 0;
            var ds = pt.direct_stream || 0;
            var tc = pt.transcode || 0;
            var ptTotal = dp + ds + tc || 1;
            var dpPct = Math.round((dp / ptTotal) * 100);
            var dsPct = Math.round((ds / ptTotal) * 100);
            var tcPct = 100 - dpPct - dsPct;

            var ptParts = [];
            if (dpPct > 0) ptParts.push(dpPct + '% DP');
            if (dsPct > 0) ptParts.push(dsPct + '% DS');
            if (tcPct > 0) ptParts.push(tcPct + '% TC');
            var ptSummary = ptParts.join(' / ');

            var media = u.media || {};

            html += '<div class="svt-user-card">';

            // Header
            html += '<div class="svt-user-hd">';
            html += '<div class="svt-user-avatar" style="background:' + avatarColor + ';">' + esc(initials) + '</div>';
            html += '<div class="svt-user-hd__info">';
            html += '<div style="display:flex;align-items:center;gap:6px;">';
            html += '<span class="svt-user-hd__name">' + esc(u.user) + '</span>';
            html += activeBadge;
            html += '</div>';
            html += '<div class="svt-user-hd__meta">Last seen: ' + formatDate(u.last_seen);
            if (u.last_ip) html += ' &middot; IP: ' + esc(u.last_ip);
            html += '</div>';
            html += '</div>';
            html += '<div class="svt-user-hd__badges">' + serverBadges + locBadge + '</div>';
            html += '</div>';

            // Stats grid
            var tint = 'background:' + avatarColor.replace('rgb(', 'rgba(').replace(')', ',.06)') + ';';
            if (avatarColor.charAt(0) === '#') {
                var r = parseInt(avatarColor.slice(1,3),16), g = parseInt(avatarColor.slice(3,5),16), b = parseInt(avatarColor.slice(5,7),16);
                tint = 'background:rgba(' + r + ',' + g + ',' + b + ',.06);';
            }
            html += '<div class="svt-user-stats">';
            html += '<div class="svt-user-stat" style="' + tint + '"><div class="svt-user-stat__val">' + formatNumber(u.plays) + '</div><div class="svt-user-stat__label">Plays</div></div>';
            html += '<div class="svt-user-stat" style="' + tint + '"><div class="svt-user-stat__val">' + formatNumber(u.hours) + '<span class="svt-unit">h</span></div><div class="svt-user-stat__label">Watch time</div></div>';
            html += '<div class="svt-user-stat" style="' + tint + '"><div class="svt-user-stat__val" style="color:#c0392b;">' + formatNumber(media.movie || 0) + '</div><div class="svt-user-stat__label">Movies</div></div>';
            html += '<div class="svt-user-stat" style="' + tint + '"><div class="svt-user-stat__val" style="color:#2980b9;">' + formatNumber(media.episode || 0) + '</div><div class="svt-user-stat__label">Episodes</div></div>';
            html += '<div class="svt-user-stat" style="' + tint + '"><div class="svt-user-stat__val svt-user-stat__val--device">' + esc(u.last_device || '\u2014') + '</div><div class="svt-user-stat__label">Device</div></div>';
            html += '</div>';

            // Play type bar
            html += '<div class="svt-user-pt">';
            html += '<span class="svt-user-pt__label">Play types:</span>';
            html += '<div class="svt-user-pt__bar">';
            if (dpPct > 0) html += '<div class="svt-user-pt__seg--dp" style="width:' + dpPct + '%;"></div>';
            if (dsPct > 0) html += '<div class="svt-user-pt__seg--ds" style="width:' + dsPct + '%;"></div>';
            if (tcPct > 0) html += '<div class="svt-user-pt__seg--tc" style="width:' + tcPct + '%;"></div>';
            html += '</div>';
            html += '<span class="svt-user-pt__summary">' + ptSummary + '</span>';
            html += '</div>';

            html += '</div>';
        }
        container.innerHTML = html;
    });
}


// ══════════════════════════════════════════════════════════════════════════════
// 11c. HISTORY TAB
// ══════════════════════════════════════════════════════════════════════════════

function histGetFilters() {
    return {
        period:      ((document.getElementById('svt-hist-period') || {}).value || '30d'),
        server_type: ((document.getElementById('svt-hist-server') || {}).value || ''),
        user:        ((document.getElementById('svt-hist-user')   || {}).value || ''),
        play_type:   ((document.getElementById('svt-hist-play')   || {}).value || ''),
        media_type:  ((document.getElementById('svt-hist-media')  || {}).value || ''),
        search:      ((document.getElementById('svt-hist-search') || {}).value || ''),
    };
}

function loadHistoryTab() {
    var f = histGetFilters();
    var extra = 'page=' + _histTabPage + '&per_page=10'
              + '&period=' + encodeURIComponent(f.period);
    if (f.server_type) extra += '&server_type=' + encodeURIComponent(f.server_type);
    if (f.user)        extra += '&user='        + encodeURIComponent(f.user);
    if (f.play_type)   extra += '&play_type='   + encodeURIComponent(f.play_type);
    if (f.media_type)  extra += '&media_type='  + encodeURIComponent(f.media_type);
    if (f.search)      extra += '&search='      + encodeURIComponent(f.search);

    return fetchJson('get_history', extra).then(function(data) {
        // Summary cards
        var el;
        el = document.getElementById('svt-h-total');
        if (el) el.textContent = formatNumber(data.total || 0);
        el = document.getElementById('svt-h-time');
        if (el) el.innerHTML = formatNumber(Math.round((data.total_sec || 0) / 3600 * 10) / 10) + '<span class="svt-unit">h</span>';
        el = document.getElementById('svt-h-avg');
        if (el) {
            var avg = data.avg_sec || 0;
            el.innerHTML = avg >= 3600
                ? Math.floor(avg / 3600) + '<span class="svt-unit">h</span> ' + Math.round((avg % 3600) / 60) + '<span class="svt-unit">m</span>'
                : Math.round(avg / 60) + '<span class="svt-unit">m</span>';
        }
        el = document.getElementById('svt-h-remote');
        if (el) {
            var rp = data.remote_pct || 0;
            el.textContent = rp + '%';
            el.style.color = rp > 50 ? '#c62828' : '';
        }

        // Populate user filter dropdown (once)
        var userSel = document.getElementById('svt-hist-user');
        if (userSel && data.user_list && userSel.options.length <= 1) {
            for (var u = 0; u < data.user_list.length; u++) {
                var opt = document.createElement('option');
                opt.value = data.user_list[u];
                opt.textContent = data.user_list[u];
                userSel.appendChild(opt);
            }
        }

        // Table rows
        var body = document.getElementById('svt-hist-body');
        if (!body) return;

        var rows = data.rows || [];
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="7" class="svt-table__empty">No history found</td></tr>';
            renderHistFooter(0, 0, 0, 0);
            return;
        }

        var html = '';
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var avatarColor = USER_COLORS[hashStr(r.user) % USER_COLORS.length];
            var initials = userInitials(r.user);
            var titleIcon = mediaTypeIcon(r.media_type);
            var ipHtml = '';
            if (r.ip_address) {
                var locTag = r.is_local
                    ? '<span class="svt-ip-tag svt-ip-tag--local">L</span>'
                    : '<span class="svt-ip-tag svt-ip-tag--remote">R</span>';
                ipHtml = esc(r.ip_address) + ' ' + locTag;
            }

            html += '<tr>';
            html += '<td>' + formatDate(r.ended_at) + '</td>';
            html += '<td><div class="svt-hist-user">';
            html += '<div class="svt-hist-user__avatar" style="background:' + avatarColor + ';">' + esc(initials) + '</div>';
            html += '<span>' + esc(r.user) + '</span></div></td>';
            html += '<td><i class="fa ' + titleIcon + ' svt-title-icon" aria-hidden="true"></i> ' + esc(r.title) + '</td>';
            html += '<td>' + serverBadge(r.server_type) + '</td>';
            html += '<td>' + playBadge(r.play_type) + '</td>';
            html += '<td style="font-size:.85rem;color:var(--svt-text-dim);">' + ipHtml + '</td>';
            html += '<td style="font-weight:500;">' + esc(r.duration) + '</td>';
            html += '</tr>';
        }
        body.innerHTML = html;

        renderHistFooter(data.page || 1, data.pages || 1, data.total || 0, data.per_page || 10);
    });
}

function renderHistFooter(page, pages, total, perPage) {
    var el = document.getElementById('svt-hist-footer');
    if (!el) return;

    if (total === 0) { el.innerHTML = ''; return; }

    var start = ((page - 1) * perPage) + 1;
    var end   = Math.min(page * perPage, total);

    var html = '<span class="svt-hist-footer__info">Showing ' + start + '-' + end + ' of ' + total + ' entries</span>';
    html += '<div class="svt-hist-footer__pages">';

    // Prev
    html += '<button class="svt-page-btn" data-histpage="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>&laquo;</button>';

    var s = Math.max(1, page - 3);
    var e = Math.min(pages, s + 6);
    if (e - s < 6) s = Math.max(1, e - 6);
    for (var p = s; p <= e; p++) {
        html += '<button class="svt-page-btn' + (p === page ? ' svt-page-btn--active' : '') + '" data-histpage="' + p + '">' + p + '</button>';
    }

    // Next
    html += '<button class="svt-page-btn" data-histpage="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>&raquo;</button>';
    html += '</div>';

    el.innerHTML = html;
}

function histExportCsv() {
    var f = histGetFilters();
    var extra = 'per_page=100&period=' + encodeURIComponent(f.period);
    if (f.server_type) extra += '&server_type=' + encodeURIComponent(f.server_type);
    if (f.user)        extra += '&user='        + encodeURIComponent(f.user);
    if (f.play_type)   extra += '&play_type='   + encodeURIComponent(f.play_type);
    if (f.media_type)  extra += '&media_type='  + encodeURIComponent(f.media_type);
    if (f.search)      extra += '&search='      + encodeURIComponent(f.search);

    // Fetch all pages
    var allRows = [];
    var page = 1;

    function fetchPage() {
        return fetchJson('get_history', extra + '&page=' + page).then(function(data) {
            var rows = data.rows || [];
            allRows = allRows.concat(rows);
            if (page < (data.pages || 1) && page < 10) {
                page++;
                return fetchPage();
            }
        });
    }

    fetchPage().then(function() {
        if (!allRows.length) return;

        var bom = '\uFEFF';
        var csv = bom + 'Date,User,Title,Media Type,Server,Play Type,IP,Local/Remote,Duration\n';
        for (var i = 0; i < allRows.length; i++) {
            var r = allRows[i];
            csv += '"' + formatDate(r.ended_at).replace(/"/g, '""') + '",'
                 + '"' + (r.user || '').replace(/"/g, '""') + '",'
                 + '"' + (r.title || '').replace(/"/g, '""') + '",'
                 + '"' + (r.media_type || '').replace(/"/g, '""') + '",'
                 + '"' + (r.server_type || '').replace(/"/g, '""') + '",'
                 + '"' + (r.play_type || '').replace(/"/g, '""') + '",'
                 + '"' + (r.ip_address || '').replace(/"/g, '""') + '",'
                 + '"' + (r.is_local ? 'Local' : 'Remote') + '",'
                 + '"' + (r.duration || '').replace(/"/g, '""') + '"\n';
        }

        var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'streamviewer-history.csv';
        a.click();
        URL.revokeObjectURL(url);
    });
}

// Simple string hash for consistent avatar colors per username
function hashStr(s) {
    var h = 0;
    for (var i = 0; i < (s || '').length; i++) {
        h = ((h << 5) - h) + s.charCodeAt(i);
        h |= 0;
    }
    return Math.abs(h);
}


// ══════════════════════════════════════════════════════════════════════════════
// 11d. GRAPHS TAB
// ══════════════════════════════════════════════════════════════════════════════

function grTick() { return _light ? '#888' : '#666'; }
function grGrid() { return _light ? 'rgba(0,0,0,.08)' : 'rgba(255,255,255,.06)'; }
function grTooltipBg() { return _light ? '#fff' : '#1a1a1a'; }
function grTooltipText() { return _light ? '#333' : '#e0e0e0'; }
function grTooltipBorder() { return _light ? '#ddd' : '#3a3a3a'; }

function grMakeChart(id, cfg) {
    if (_graphCharts[id]) _graphCharts[id].destroy();
    var canvas = document.getElementById(id);
    if (!canvas) return null;
    _graphCharts[id] = new Chart(canvas.getContext('2d'), cfg);
    return _graphCharts[id];
}

function grBaseTooltip() {
    return { backgroundColor: grTooltipBg(), titleColor: grTooltipText(), bodyColor: grTooltipText(), borderColor: grTooltipBorder(), borderWidth: 1, cornerRadius: 6, padding: 8 };
}

function grFormatDate(d) {
    var p = (d || '').split('-');
    return p.length === 3 ? parseInt(p[1],10) + '/' + parseInt(p[2],10) : d;
}

function grBuildLegend(el, items) {
    if (!el) return;
    var html = '';
    for (var i = 0; i < items.length; i++) {
        html += '<span><i style="background:' + items[i].color + ';"></i>' + items[i].label + '</span>';
    }
    el.innerHTML = html;
}

function loadGraphs() {
    var url = API + '?action=get_graph_data'
            + '&_svt=' + encodeURIComponent(_token)
            + '&period=' + encodeURIComponent(_graphsPeriod);

    return $.ajax({ url: url, dataType: 'json', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function(data) {

        // 1. Watch time per day (line)
        var wt = data.watch_time_daily || [];
        var wtLabels = [], wtPlex = [], wtJf = [], wtEmby = [];
        for (var i = 0; i < wt.length; i++) {
            wtLabels.push(grFormatDate(wt[i].date));
            wtPlex.push(wt[i].plex || 0);
            wtJf.push(wt[i].jellyfin || 0);
            wtEmby.push(wt[i].emby || 0);
        }
        grBuildLegend(document.getElementById('svt-gr-legend-wt'), [
            { color: '#e5a00d', label: 'Plex' },
            { color: '#00a4dc', label: 'Jellyfin' },
            { color: '#52b54b', label: 'Emby' },
        ]);
        grMakeChart('svt-gr-watchtime', {
            type: 'line',
            data: { labels: wtLabels, datasets: [
                { label: 'Plex', data: wtPlex, borderColor: '#e5a00d', backgroundColor: 'rgba(229,160,13,.1)', fill: true, tension: 0.3, borderWidth: 2, pointRadius: 2 },
                { label: 'Jellyfin', data: wtJf, borderColor: '#00a4dc', backgroundColor: 'rgba(0,164,220,.08)', fill: true, tension: 0.3, borderWidth: 2, pointRadius: 2 },
                { label: 'Emby', data: wtEmby, borderColor: '#52b54b', backgroundColor: 'rgba(82,181,75,.08)', fill: true, tension: 0.3, borderWidth: 2, pointRadius: 2 },
            ]},
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: grBaseTooltip() },
                scales: { x: { ticks: { color: grTick(), font: { size: 15 } }, grid: { display: false } },
                          y: { ticks: { color: grTick(), font: { size: 15 }, callback: function(v) { return v + 'h'; } }, grid: { color: grGrid() }, beginAtZero: true } },
                interaction: { mode: 'index', intersect: false } }
        });

        // 2. Peak hours (bar)
        var ph = data.peak_hours || [];
        var phLabels = [];
        for (var h = 0; h < 24; h++) phLabels.push(h.toString());
        grMakeChart('svt-gr-peak', {
            type: 'bar',
            data: { labels: phLabels, datasets: [{ data: ph, backgroundColor: 'rgba(229,160,13,.7)', borderRadius: 2, maxBarThickness: 20 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: $.extend(grBaseTooltip(), { callbacks: { title: function(it) { return it[0].label + ':00'; }, label: function(it) { return it.parsed.y + ' plays'; } } }) },
                scales: { x: { ticks: { color: grTick(), font: { size: 14 } }, grid: { display: false } },
                          y: { ticks: { color: grTick(), font: { size: 15 }, precision: 0 }, grid: { color: grGrid() }, beginAtZero: true } } }
        });

        // 3. Play type donut
        var pd = data.play_type_dist || {};
        var pdTotal = (pd.direct_play || 0) + (pd.direct_stream || 0) + (pd.transcode || 0) || 1;
        grBuildLegend(document.getElementById('svt-gr-legend-pt'), [
            { color: '#4caf50', label: 'Direct play ' + Math.round((pd.direct_play||0)/pdTotal*100) + '%' },
            { color: '#2196f3', label: 'Direct stream ' + Math.round((pd.direct_stream||0)/pdTotal*100) + '%' },
            { color: '#ff9800', label: 'Transcode ' + Math.round((pd.transcode||0)/pdTotal*100) + '%' },
        ]);
        grMakeChart('svt-gr-playtype', {
            type: 'doughnut',
            data: { labels: ['Direct play','Direct stream','Transcode'], datasets: [{ data: [pd.direct_play||0, pd.direct_stream||0, pd.transcode||0], backgroundColor: ['#4caf50','#2196f3','#ff9800'], borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: grBaseTooltip() }, cutout: '60%' }
        });

        // 4. Media type donut
        var md = data.media_type_dist || {};
        var mdTotal = (md.movie||0) + (md.episode||0) + (md.track||0) || 1;
        grBuildLegend(document.getElementById('svt-gr-legend-mt'), [
            { color: '#c0392b', label: 'Movies ' + Math.round((md.movie||0)/mdTotal*100) + '%' },
            { color: '#2980b9', label: 'Episodes ' + Math.round((md.episode||0)/mdTotal*100) + '%' },
            { color: '#8e44ad', label: 'Music ' + Math.round((md.track||0)/mdTotal*100) + '%' },
        ]);
        grMakeChart('svt-gr-mediatype', {
            type: 'doughnut',
            data: { labels: ['Movies','Episodes','Music'], datasets: [{ data: [md.movie||0, md.episode||0, md.track||0], backgroundColor: ['#c0392b','#2980b9','#8e44ad'], borderWidth: 0 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: grBaseTooltip() }, cutout: '60%' }
        });

        // 5. User activity (horizontal bar)
        var ua = data.user_activity || [];
        var uaLabels = [], uaData = [], uaColors = [];
        for (var u = 0; u < ua.length; u++) {
            uaLabels.push(ua[u].user);
            uaData.push(ua[u].hours);
            uaColors.push(USER_COLORS[u % USER_COLORS.length]);
        }
        var uaWrap = document.getElementById('svt-gr-users');
        if (uaWrap) uaWrap = uaWrap.parentNode;
        if (uaWrap) uaWrap.style.height = Math.max(154, ua.length * 42 + 40) + 'px';
        grMakeChart('svt-gr-users', {
            type: 'bar',
            data: { labels: uaLabels, datasets: [{ data: uaData, backgroundColor: uaColors, borderRadius: 4, maxBarThickness: 20 }] },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: $.extend(grBaseTooltip(), { callbacks: { label: function(it) { return it.parsed.x + 'h'; } } }) },
                scales: { x: { ticks: { color: grTick(), font: { size: 15 }, callback: function(v) { return v + 'h'; } }, grid: { color: grGrid() }, beginAtZero: true },
                          y: { ticks: { color: grTick(), font: { size: 16 } }, grid: { display: false } } } }
        });

        // 6. Local vs remote (stacked bar)
        var lr = data.local_remote_daily || [];
        var lrLabels = [], lrLocal = [], lrRemote = [];
        var lrTotalL = 0, lrTotalR = 0;
        for (var d = 0; d < lr.length; d++) {
            lrLabels.push(grFormatDate(lr[d].date));
            lrLocal.push(lr[d].local || 0);
            lrRemote.push(lr[d].remote || 0);
            lrTotalL += (lr[d].local || 0);
            lrTotalR += (lr[d].remote || 0);
        }
        var lrSum = lrTotalL + lrTotalR || 1;
        grBuildLegend(document.getElementById('svt-gr-legend-lr'), [
            { color: '#4caf50', label: 'Local ' + Math.round(lrTotalL/lrSum*100) + '%' },
            { color: '#c62828', label: 'Remote ' + Math.round(lrTotalR/lrSum*100) + '%' },
        ]);
        grMakeChart('svt-gr-localremote', {
            type: 'bar',
            data: { labels: lrLabels, datasets: [
                { label: 'Local', data: lrLocal, backgroundColor: '#4caf50', borderRadius: 2, maxBarThickness: 20 },
                { label: 'Remote', data: lrRemote, backgroundColor: '#c62828', borderRadius: 2, maxBarThickness: 20 },
            ]},
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: grBaseTooltip() },
                scales: { x: { stacked: true, ticks: { color: grTick(), font: { size: 11 }, maxRotation: 0 }, grid: { display: false } },
                          y: { stacked: true, ticks: { color: grTick(), font: { size: 15 }, precision: 0 }, grid: { color: grGrid() }, beginAtZero: true } },
                interaction: { mode: 'index', intersect: false } }
        });

        // 7. Bandwidth (line)
        var bw = data.bandwidth_daily || [];
        var bwLabels = [], bwData = [];
        for (var b = 0; b < bw.length; b++) {
            bwLabels.push(grFormatDate(bw[b].date));
            bwData.push(bw[b].avg_mbps || 0);
        }
        grMakeChart('svt-gr-bandwidth', {
            type: 'line',
            data: { labels: bwLabels, datasets: [{ data: bwData, borderColor: '#2196f3', backgroundColor: 'rgba(33,150,243,.12)', fill: true, tension: 0.4, borderWidth: 2, pointRadius: 2 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: $.extend(grBaseTooltip(), { callbacks: { label: function(it) { return it.parsed.y + ' Mbps'; } } }) },
                scales: { x: { ticks: { color: grTick(), font: { size: 15 } }, grid: { display: false } },
                          y: { ticks: { color: grTick(), font: { size: 15 }, callback: function(v) { return v + ' Mbps'; } }, grid: { color: grGrid() }, beginAtZero: true } } }
        });

        // 8. Plays by day of week (bar)
        var dow = data.plays_by_dow || [];
        var dowLabels = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        var dowColors = [];
        var dowMax = Math.max.apply(null, dow.length ? dow : [0]);
        for (var di = 0; di < 7; di++) {
            dowColors.push((dow[di] || 0) === dowMax && dowMax > 0 ? '#e5a00d' : 'rgba(229,160,13,.5)');
        }
        grMakeChart('svt-gr-dow', {
            type: 'bar',
            data: { labels: dowLabels, datasets: [{ data: dow, backgroundColor: dowColors, borderRadius: 2, maxBarThickness: 20 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: $.extend(grBaseTooltip(), { callbacks: { label: function(it) { return it.parsed.y + ' plays'; } } }) },
                scales: { x: { ticks: { color: grTick(), font: { size: 14 } }, grid: { display: false } },
                          y: { ticks: { color: grTick(), font: { size: 14 }, precision: 0 }, grid: { color: grGrid() }, beginAtZero: true } } }
        });

        // 9. Monthly plays (bar) -- always show 12 months
        var mp = data.plays_per_month || [];
        var monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var mpLookup = {};
        for (var mi = 0; mi < mp.length; mi++) {
            mpLookup[mp[mi].month || ''] = mp[mi];
        }
        var mpLabels = [], mpPlays = [], mpHours = [];
        var now = new Date();
        for (var m = 0; m < 12; m++) {
            var key = now.getFullYear() + '-' + (m + 1 < 10 ? '0' : '') + (m + 1);
            mpLabels.push(monthNames[m]);
            var entry = mpLookup[key];
            mpPlays.push(entry ? (entry.plays || 0) : 0);
            mpHours.push(entry ? (entry.hours || 0) : 0);
        }
        grMakeChart('svt-gr-monthly', {
            type: 'bar',
            data: { labels: mpLabels, datasets: [{ data: mpPlays, backgroundColor: 'rgba(0,164,220,.7)', borderRadius: 2, maxBarThickness: 20 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: $.extend(grBaseTooltip(), { callbacks: { label: function(it) { var h = mpHours[it.dataIndex] || 0; return it.parsed.y + ' plays (' + h + 'h)'; } } }) },
                scales: { x: { ticks: { color: grTick(), font: { size: 13 } }, grid: { display: false } },
                          y: { ticks: { color: grTick(), font: { size: 14 }, precision: 0 }, grid: { color: grGrid() }, beginAtZero: true } } }
        });

        // 10. Top devices (horizontal bar)
        var td = data.top_devices || [];
        var tdLabels = [], tdData = [], tdColors = [];
        for (var ti = 0; ti < td.length; ti++) {
            tdLabels.push(td[ti].device);
            tdData.push(td[ti].plays);
            tdColors.push(AVATAR_COLORS[ti % AVATAR_COLORS.length]);
        }
        var tdWrap = document.getElementById('svt-gr-devices');
        if (tdWrap) tdWrap = tdWrap.parentNode;
        if (tdWrap) tdWrap.style.height = Math.max(154, td.length * 42 + 40) + 'px';
        grMakeChart('svt-gr-devices', {
            type: 'bar',
            data: { labels: tdLabels, datasets: [{ data: tdData, backgroundColor: tdColors, borderRadius: 4, maxBarThickness: 20 }] },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: $.extend(grBaseTooltip(), { callbacks: { label: function(it) { var h = td[it.dataIndex] ? td[it.dataIndex].hours : 0; return it.parsed.x + ' plays (' + h + 'h)'; } } }) },
                scales: { x: { ticks: { color: grTick(), font: { size: 14 }, precision: 0 }, grid: { color: grGrid() }, beginAtZero: true },
                          y: { ticks: { color: grTick(), font: { size: 13 } }, grid: { display: false } } } }
        });

        // 11. Library activity (horizontal bar)
        var la = data.library_activity || [];
        var laLabels = [], laData = [], laColors = [];
        for (var li = 0; li < la.length; li++) {
            laLabels.push(la[li].label);
            laData.push(la[li].plays);
            laColors.push(AVATAR_COLORS[li % AVATAR_COLORS.length]);
        }
        var laWrap = document.getElementById('svt-gr-libraries');
        if (laWrap) laWrap = laWrap.parentNode;
        if (laWrap) laWrap.style.height = Math.max(154, la.length * 42 + 40) + 'px';
        grMakeChart('svt-gr-libraries', {
            type: 'bar',
            data: { labels: laLabels, datasets: [{ data: laData, backgroundColor: laColors, borderRadius: 4, maxBarThickness: 20 }] },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: $.extend(grBaseTooltip(), { callbacks: { label: function(it) { var h = la[it.dataIndex] ? la[it.dataIndex].hours : 0; return it.parsed.x + ' plays (' + h + 'h)'; } } }) },
                scales: { x: { ticks: { color: grTick(), font: { size: 14 }, precision: 0 }, grid: { color: grGrid() }, beginAtZero: true },
                          y: { ticks: { color: grTick(), font: { size: 13 } }, grid: { display: false } } } }
        });

        // 12. Concurrent streams per day (line)
        var cs = data.concurrent_daily || [];
        var csLabels = [], csData = [];
        for (var ci = 0; ci < cs.length; ci++) {
            csLabels.push(grFormatDate(cs[ci].date));
            csData.push(cs[ci].peak || 0);
        }
        grMakeChart('svt-gr-concurrent', {
            type: 'line',
            data: { labels: csLabels, datasets: [{ data: csData, borderColor: '#e91e63', backgroundColor: 'rgba(233,30,99,.1)', fill: true, tension: 0.3, borderWidth: 2, pointRadius: 2, stepped: 'middle' }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: $.extend(grBaseTooltip(), { callbacks: { label: function(it) { return it.parsed.y + ' concurrent'; } } }) },
                scales: { x: { ticks: { color: grTick(), font: { size: 15 } }, grid: { display: false } },
                          y: { ticks: { color: grTick(), font: { size: 15 }, precision: 0, stepSize: 1 }, grid: { color: grGrid() }, beginAtZero: true } } }
        });

        // 13. Source resolution donut
        var RES_COLORS = ['#e5a00d','#2196f3','#4caf50','#e91e63','#9c27b0','#ff5722','#00bcd4','#8bc34a'];
        var sr = data.source_res_dist || [];
        var srLabels = [], srData = [], srColors = [];
        var srTotal = 0;
        for (var si = 0; si < sr.length; si++) srTotal += sr[si].count;
        srTotal = srTotal || 1;
        var srLegendItems = [];
        for (var si = 0; si < sr.length; si++) {
            var lbl = sr[si].label === '4k' ? '4K' : sr[si].label + 'p';
            srLabels.push(lbl);
            srData.push(sr[si].count);
            srColors.push(RES_COLORS[si % RES_COLORS.length]);
            srLegendItems.push({ color: RES_COLORS[si % RES_COLORS.length], label: lbl + ' ' + Math.round(sr[si].count / srTotal * 100) + '%' });
        }
        grBuildLegend(document.getElementById('svt-gr-legend-srcres'), srLegendItems);
        if (sr.length > 0) {
            grMakeChart('svt-gr-srcres', {
                type: 'doughnut',
                data: { labels: srLabels, datasets: [{ data: srData, backgroundColor: srColors, borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: grBaseTooltip() }, cutout: '60%' }
            });
        }

        // 14. Stream resolution donut
        var stm = data.stream_res_dist || [];
        var stmLabels = [], stmData = [], stmColors = [];
        var stmTotal = 0;
        for (var sti = 0; sti < stm.length; sti++) stmTotal += stm[sti].count;
        stmTotal = stmTotal || 1;
        var stmLegendItems = [];
        for (var sti = 0; sti < stm.length; sti++) {
            var slbl = stm[sti].label === '4k' ? '4K' : stm[sti].label + 'p';
            stmLabels.push(slbl);
            stmData.push(stm[sti].count);
            stmColors.push(RES_COLORS[sti % RES_COLORS.length]);
            stmLegendItems.push({ color: RES_COLORS[sti % RES_COLORS.length], label: slbl + ' ' + Math.round(stm[sti].count / stmTotal * 100) + '%' });
        }
        grBuildLegend(document.getElementById('svt-gr-legend-stmres'), stmLegendItems);
        if (stm.length > 0) {
            grMakeChart('svt-gr-stmres', {
                type: 'doughnut',
                data: { labels: stmLabels, datasets: [{ data: stmData, backgroundColor: stmColors, borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: grBaseTooltip() }, cutout: '60%' }
            });
        }

        // 15. Watch completion histogram
        var cb = data.completion_buckets || [0,0,0,0];
        var cbLabels = ['0-25%', '25-50%', '50-75%', '75-100%'];
        var cbColors = ['#c62828', '#ff9800', '#2196f3', '#4caf50'];
        grMakeChart('svt-gr-completion', {
            type: 'bar',
            data: { labels: cbLabels, datasets: [{ data: cb, backgroundColor: cbColors, borderRadius: 2, maxBarThickness: 20 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: $.extend(grBaseTooltip(), { callbacks: { label: function(it) { return it.parsed.y + ' plays'; } } }) },
                scales: { x: { ticks: { color: grTick(), font: { size: 14 } }, grid: { display: false } },
                          y: { ticks: { color: grTick(), font: { size: 14 }, precision: 0 }, grid: { color: grGrid() }, beginAtZero: true } } }
        });

        // 16. Plays by country (horizontal bar)
        var pc = data.plays_by_country || [];
        var pcLabels = [], pcData = [], pcColors = [];
        for (var pi = 0; pi < pc.length; pi++) {
            pcLabels.push(pc[pi].country);
            pcData.push(pc[pi].plays);
            pcColors.push(AVATAR_COLORS[pi % AVATAR_COLORS.length]);
        }
        var pcWrap = document.getElementById('svt-gr-country');
        if (pcWrap) pcWrap = pcWrap.parentNode;
        if (pcWrap) pcWrap.style.height = Math.max(154, pc.length * 42 + 40) + 'px';
        if (pc.length > 0) {
            grMakeChart('svt-gr-country', {
                type: 'bar',
                data: { labels: pcLabels, datasets: [{ data: pcData, backgroundColor: pcColors, borderRadius: 4, maxBarThickness: 20 }] },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: $.extend(grBaseTooltip(), { callbacks: { label: function(it) { return it.parsed.x + ' plays'; } } }) },
                    scales: { x: { ticks: { color: grTick(), font: { size: 14 }, precision: 0 }, grid: { color: grGrid() }, beginAtZero: true },
                              y: { ticks: { color: grTick(), font: { size: 13 } }, grid: { display: false } } } }
            });
        }

        // 17. Top locations (horizontal bar)
        var tl = data.top_locations || [];
        var tlLabels = [], tlData = [], tlColors = [];
        for (var tli = 0; tli < tl.length; tli++) {
            tlLabels.push(tl[tli].city + ', ' + tl[tli].country_code);
            tlData.push(tl[tli].plays);
            tlColors.push(AVATAR_COLORS[tli % AVATAR_COLORS.length]);
        }
        var tlWrap = document.getElementById('svt-gr-locations');
        if (tlWrap) tlWrap = tlWrap.parentNode;
        if (tlWrap) tlWrap.style.height = Math.max(154, tl.length * 42 + 40) + 'px';
        if (tl.length > 0) {
            grMakeChart('svt-gr-locations', {
                type: 'bar',
                data: { labels: tlLabels, datasets: [{ data: tlData, backgroundColor: tlColors, borderRadius: 4, maxBarThickness: 20 }] },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: $.extend(grBaseTooltip(), { callbacks: { label: function(it) { return it.parsed.x + ' plays'; } } }) },
                    scales: { x: { ticks: { color: grTick(), font: { size: 14 }, precision: 0 }, grid: { color: grGrid() }, beginAtZero: true },
                              y: { ticks: { color: grTick(), font: { size: 13 } }, grid: { display: false } } } }
            });
        }
    });
}


// ══════════════════════════════════════════════════════════════════════════════
// 11e. ALERTS TAB
// ══════════════════════════════════════════════════════════════════════════════

function loadAlerts() {
    var contentEl = document.getElementById('svt-alerts-content');
    if (contentEl) contentEl.innerHTML = '<div class="svt-table__empty">Loading...</div>';

    return $.ajax({
        url: API + '?action=get_alerts&period=' + encodeURIComponent(_alertsPeriod)
             + '&_svt=' + encodeURIComponent(_token),
        dataType: 'json',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).done(function(d) {
        renderAlerts(d);
    }).fail(function() {
        if (contentEl) contentEl.innerHTML = '<div class="svt-table__empty">Failed to load alerts</div>';
    });
}

function renderAlerts(d) {
    // Summary cards
    var totalEl = document.getElementById('svt-al-total');
    var bufEl   = document.getElementById('svt-al-buffering');
    var inacEl  = document.getElementById('svt-al-inactive');
    var tcEl    = document.getElementById('svt-al-transcode');

    if (totalEl) totalEl.textContent = d.alert_count || '0';
    if (bufEl)   bufEl.textContent   = (d.buffering || []).length;
    if (inacEl)  inacEl.textContent  = (d.inactive || []).length;
    if (tcEl) {
        tcEl.textContent = (d.transcode ? d.transcode.tc_pct : 0) + '%';
        var tcSev = d.transcode ? d.transcode.severity : 'ok';
        tcEl.style.color = tcSev === 'critical' ? '#e74c3c' : tcSev === 'warning' ? '#f39c12' : '#2ecc71';
    }

    // Build sections
    var html = '';

    // 1. Buffering warnings
    html += renderAlertSection(
        'buffering',
        'fa-exclamation-circle',
        'Buffering warnings',
        'Titles with multiple short sessions (under 2 min) may indicate playback issues',
        d.buffering || [],
        function(items) {
            if (!items.length) return '<div class="svt-alert-none"><i class="fa fa-check-circle"></i>No buffering issues detected</div>';
            var h = '';
            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                var sev = it.cnt >= 5 ? 'critical' : 'warning';
                h += '<div class="svt-alert-item">';
                h += '<div class="svt-alert-item__icon svt-alert-item__icon--' + sev + '"><i class="fa fa-refresh"></i></div>';
                h += '<div class="svt-alert-item__text"><strong>' + esc(it.title) + '</strong> restarted ' + it.cnt + ' times by ' + esc(it.user) + '</div>';
                h += '<div class="svt-alert-item__meta">avg ' + formatDuration(it.avg_dur) + ' / ' + serverBadge(it.server_type) + '</div>';
                h += '</div>';
            }
            return h;
        }
    );

    // 2. Inactive users
    html += renderAlertSection(
        'inactive',
        'fa-user-times',
        'Inactive users',
        'Users with no streaming activity in the selected period',
        d.inactive || [],
        function(items) {
            if (!items.length) return '<div class="svt-alert-none"><i class="fa fa-check-circle"></i>All users are active</div>';
            var h = '';
            for (var i = 0; i < items.length; i++) {
                var it = items[i];
                var lastDate = new Date(it.last_seen * 1000);
                var daysAgo = Math.floor((Date.now() - lastDate.getTime()) / 86400000);
                var sev = daysAgo > 60 ? 'critical' : 'warning';
                h += '<div class="svt-alert-item">';
                h += '<div class="svt-alert-item__icon svt-alert-item__icon--' + sev + '"><i class="fa fa-user"></i></div>';
                h += '<div class="svt-alert-item__text"><strong>' + esc(it.user) + '</strong> has ' + it.total_plays + ' total plays</div>';
                h += '<div class="svt-alert-item__meta">last seen ' + daysAgo + 'd ago</div>';
                h += '</div>';
            }
            return h;
        }
    );

    // 3. Transcode ratio
    var tc = d.transcode || { tc_pct: 0, severity: 'ok', total: 0, tc_count: 0, users: [] };
    html += renderAlertSection(
        'transcode',
        'fa-microchip',
        'Transcode ratio',
        tc.severity === 'critical' ? 'High transcode ratio may indicate need for hardware upgrade'
            : tc.severity === 'warning' ? 'Moderate transcode ratio, monitor for changes'
            : 'Transcode ratio is healthy',
        [tc],
        function() {
            var h = '';
            // Gauge bar
            h += '<div class="svt-tc-gauge">';
            h += '<div class="svt-tc-gauge__bar"><div class="svt-tc-gauge__fill svt-tc-gauge__fill--' + tc.severity + '" style="width:' + tc.tc_pct + '%;"></div></div>';
            h += '<div class="svt-tc-gauge__pct svt-tc-gauge__pct--' + tc.severity + '">' + tc.tc_pct + '%</div>';
            h += '</div>';
            h += '<div class="svt-alert-item" style="border:0;">';
            h += '<div class="svt-alert-item__text">' + tc.tc_count + ' of ' + tc.total + ' streams used transcoding</div>';
            h += '</div>';

            // Top transcode users
            if (tc.users && tc.users.length) {
                h += '<div style="margin-top:.75rem;font-size:1.1rem;font-weight:600;color:var(--svt-text-mid);">Top transcode users</div>';
                for (var i = 0; i < tc.users.length; i++) {
                    var u = tc.users[i];
                    var uSev = (u.tc_pct || 0) >= 70 ? 'critical' : (u.tc_pct || 0) >= 40 ? 'warning' : 'ok';
                    h += '<div class="svt-alert-item">';
                    h += '<div class="svt-alert-item__icon svt-alert-item__icon--' + uSev + '"><i class="fa fa-user"></i></div>';
                    h += '<div class="svt-alert-item__text"><strong>' + esc(u.user) + '</strong> ' + u.tc_plays + ' transcodes</div>';
                    h += '<div class="svt-alert-item__meta">' + (u.tc_pct || 0) + '% of their plays</div>';
                    h += '</div>';
                }
            }
            return h;
        }
    );

    var contentEl = document.getElementById('svt-alerts-content');
    if (contentEl) contentEl.innerHTML = html;

    // Collapsible sections
    var headers = document.querySelectorAll('#svt-alerts-content .svt-alert-section__hd');
    for (var i = 0; i < headers.length; i++) {
        headers[i].addEventListener('click', function() {
            var body = this.nextElementSibling;
            var arrow = this.querySelector('.svt-alert-section__chevron');
            if (!body) return;
            var hidden = !body.classList.contains('svt-alert-section__body--hidden');
            body.classList.toggle('svt-alert-section__body--hidden');
            if (arrow) arrow.className = 'fa ' + (hidden ? 'fa-chevron-down' : 'fa-chevron-up') + ' svt-alert-section__chevron';
        });
    }
}

function renderAlertSection(id, icon, title, subtitle, items, renderFn) {
    var count = items.length;
    var sev = 'ok';
    if (id === 'transcode') {
        sev = (items[0] && items[0].severity) || 'ok';
        count = items[0] ? items[0].tc_pct + '%' : '0%';
    } else if (count > 0) {
        sev = 'warning';
        if (count >= 5) sev = 'critical';
    }
    if (id !== 'transcode' && count === 0) sev = 'ok';

    var h = '<div class="svt-alert-section">';
    h += '<div class="svt-alert-section__hd">';
    h += '<div class="svt-alert-section__icon svt-alert-section__icon--' + sev + '"><i class="fa ' + icon + '"></i></div>';
    h += '<div class="svt-alert-section__title">' + title + '</div>';
    h += '<span class="svt-alert-section__count svt-alert-section__count--' + sev + '">' + count + '</span>';
    h += '<i class="fa fa-chevron-up svt-alert-section__chevron"></i>';
    h += '</div>';
    h += '<div class="svt-alert-section__body">';
    if (subtitle) h += '<div style="font-size:1rem;color:var(--svt-text-dim);margin-bottom:.75rem;">' + subtitle + '</div>';
    h += renderFn(items);
    h += '</div></div>';
    return h;
}

function formatDuration(sec) {
    sec = Math.round(sec || 0);
    if (sec < 60) return sec + 's';
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
}


// ══════════════════════════════════════════════════════════════════════════════
// 11f. BACKGROUND SESSION RECORDING
// ══════════════════════════════════════════════════════════════════════════════

// The widget dashboard records sessions to SQLite on every poll cycle via
// get_sessions -> recordSessions(). When the user is on the Tool page instead,
// that polling stops and no data is recorded. This background poller calls
// get_sessions silently so recording continues regardless of which page is open.

var _bgRecordTimer = null;
var _bgRecordIntervalMs = 15000; // 15 seconds

function startBackgroundRecording() {
    if (_bgRecordTimer) return;
    bgRecordPoll(); // first call immediately
    _bgRecordTimer = setInterval(bgRecordPoll, _bgRecordIntervalMs);
}

function bgRecordPoll() {
    $.ajax({
        url: API + '?action=get_sessions&_svt=' + encodeURIComponent(_token),
        dataType: 'json',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        timeout: 10000
    });
    // Fire-and-forget: we don't need the response, the server-side
    // recordSessions() handles everything. Errors are silently ignored.
}


// ══════════════════════════════════════════════════════════════════════════════
// 12. EVENT BINDINGS
// ══════════════════════════════════════════════════════════════════════════════

function init() {
    if (!_cfg.statsEnabled) return;

    // Tab switching
    var tabIds = Object.keys(TAB_MAP);
    for (var t = 0; t < tabIds.length; t++) {
        (function(tid) {
            var tabEl = document.getElementById(tid);
            if (tabEl) {
                tabEl.addEventListener('click', function() { switchTab(tid); });
            }
        })(tabIds[t]);
    }

    // Period selector
    var periodEl = DOM.period();
    if (periodEl) {
        periodEl.addEventListener('change', function() {
            _period   = this.value;
            _histPage = 1;
            loadAll();
        });
    }

    // Refresh button (hourglass animation like widget)
    var refreshBtn = document.getElementById('svt-refresh-btn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var icon = refreshBtn.querySelector('.fa');
            if (icon) icon.className = 'fa fa-fw fa-hourglass-half control';
            _histPage = 1;
            loadAll().always(function() {
                if (icon) icon.className = 'fa fa-fw fa-refresh control';
            });
        });
    }

    // Library sync button
    var libSyncBtn = document.getElementById('svt-lib-sync-btn');
    if (libSyncBtn) {
        libSyncBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var icon = libSyncBtn.querySelector('.fa');
            if (icon) icon.className = 'fa fa-fw fa-hourglass-half control';
            fetchJson('sync_libraries').always(function() {
                if (icon) icon.className = 'fa fa-fw fa-refresh control';
                loadLibraries();
                loadRecentlyAdded();
            });
        });
    }

    // Library collapsible toggle (event delegation, attached once)
    var libContainer = document.getElementById('svt-lib-servers-container');
    if (libContainer) {
        libContainer.addEventListener('click', function(e) {
            var hd = e.target.closest('.svt-lib-toggle');
            if (!hd) return;
            var body = hd.nextElementSibling;
            if (!body) return;
            var collapsed = hd.getAttribute('data-collapsed') === 'true';
            hd.setAttribute('data-collapsed', collapsed ? 'false' : 'true');
            body.style.display = collapsed ? '' : 'none';
            var arrow = hd.querySelector('.svt-lib-toggle__arrow');
            if (arrow) arrow.className = 'fa ' + (collapsed ? 'fa-chevron-up' : 'fa-chevron-down') + ' svt-lib-toggle__arrow';
        });
    }

    // Users tab period selector
    var usersPeriodEl = document.getElementById('svt-users-period');
    if (usersPeriodEl) {
        usersPeriodEl.addEventListener('change', function() {
            _usersPeriod = this.value;
            loadUserStats();
        });
    }

    // Users tab refresh button
    var usersRefreshBtn = document.getElementById('svt-users-refresh-btn');
    if (usersRefreshBtn) {
        usersRefreshBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var icon = usersRefreshBtn.querySelector('.fa');
            if (icon) icon.className = 'fa fa-fw fa-hourglass-half control';
            loadUserStats().always(function() {
                if (icon) icon.className = 'fa fa-fw fa-refresh control';
            });
        });
    }

    // ── History TAB events ──
    var histFilterIds = ['svt-hist-period', 'svt-hist-server', 'svt-hist-user', 'svt-hist-play', 'svt-hist-media'];
    for (var hf = 0; hf < histFilterIds.length; hf++) {
        (function(id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('change', function() { _histTabPage = 1; loadHistoryTab(); });
        })(histFilterIds[hf]);
    }

    // History search with debounce
    var histSearchEl = document.getElementById('svt-hist-search');
    var _histSearchTimer = null;
    if (histSearchEl) {
        histSearchEl.addEventListener('input', function() {
            clearTimeout(_histSearchTimer);
            _histSearchTimer = setTimeout(function() { _histTabPage = 1; loadHistoryTab(); }, 400);
        });
    }

    // History export
    var histExportBtn = document.getElementById('svt-hist-export');
    if (histExportBtn) histExportBtn.addEventListener('click', histExportCsv);

    // History pagination (delegated)
    var histFooter = document.getElementById('svt-hist-footer');
    if (histFooter) {
        histFooter.addEventListener('click', function(e) {
            var btn = e.target.closest('[data-histpage]');
            if (!btn || btn.disabled) return;
            var p = parseInt(btn.getAttribute('data-histpage'), 10);
            if (p && p > 0) { _histTabPage = p; loadHistoryTab(); }
        });
    }

    // History refresh
    var histRefreshBtn = document.getElementById('svt-hist-refresh-btn');
    if (histRefreshBtn) {
        histRefreshBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var icon = histRefreshBtn.querySelector('.fa');
            if (icon) icon.className = 'fa fa-fw fa-hourglass-half control';
            _histTabPage = 1;
            loadHistoryTab().always(function() {
                if (icon) icon.className = 'fa fa-fw fa-refresh control';
            });
        });
    }

    // ── Graphs TAB events ──
    var graphPeriodEl = document.getElementById('svt-graph-period');
    if (graphPeriodEl) {
        graphPeriodEl.addEventListener('change', function() {
            _graphsPeriod = this.value;
            loadGraphs();
        });
    }
    var graphRefreshBtn = document.getElementById('svt-graph-refresh-btn');
    if (graphRefreshBtn) {
        graphRefreshBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var icon = graphRefreshBtn.querySelector('.fa');
            if (icon) icon.className = 'fa fa-fw fa-hourglass-half control';
            loadGraphs().always(function() {
                if (icon) icon.className = 'fa fa-fw fa-refresh control';
            });
        });
    }

    // ── Dashboard history filters (existing) ──
    // History filters
    var fServer = DOM.filterServer();
    var fPlay   = DOM.filterPlay();
    if (fServer) fServer.addEventListener('change', function() { loadHistory(); });
    if (fPlay)   fPlay.addEventListener('change',   function() { loadHistory(); });

    // ── Alerts TAB events ──
    var alertsPeriodEl = document.getElementById('svt-alerts-period');
    if (alertsPeriodEl) {
        alertsPeriodEl.addEventListener('change', function() {
            _alertsPeriod = this.value;
            loadAlerts();
        });
    }
    var alertsRefreshBtn = document.getElementById('svt-alerts-refresh-btn');
    if (alertsRefreshBtn) {
        alertsRefreshBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var icon = alertsRefreshBtn.querySelector('.fa');
            if (icon) icon.className = 'fa fa-fw fa-hourglass-half control';
            loadAlerts().always(function() {
                if (icon) icon.className = 'fa fa-fw fa-refresh control';
            });
        });
    }

    // Initial load (Dashboard)
    loadAll();

    // Start background session recording so stats are captured
    // even when the user is on the Tool page instead of the widget
    startBackgroundRecording();

    // Auto-size all selects to fit content
    setTimeout(autoSizeAllSelects, 100);
}


// Bootstrap
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

})();
