/* ═══════════════════════════════════════════════════════════════════════════
   Stream Viewer  —  streamviewer-live.js
   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3

   Bootstrap for the Statistics page Live tab.

   Differs from streamviewer-widget.js in two ways:
     1. It does NOT auto-start. Polling begins only when the user actually
        opens the Live tab (streamviewer-tool.js calls window.SVLive.start())
        and stops when they navigate away (window.SVLive.stop()). This keeps
        the page from polling servers in the background while the user is
        browsing other stats tabs.
     2. It reads its config from window.svLiveConfig (built from the LIVE_*
        settings in StreamViewerSettings) instead of window.streamviewerConfig.

   Exposed as window.SVLive = { start, stop, refresh, isStarted }.
   ═══════════════════════════════════════════════════════════════════════════ */
/* global $ */

(function () {
'use strict';

if (window.__svLiveBootstrapped) return;
window.__svLiveBootstrapped = true;

var _instance = null;
var _started  = false;

function ensureInstance() {
    if (_instance) return _instance;
    if (!window.SVCore || typeof window.SVCore.create !== 'function') return null;
    _instance = window.SVCore.create({
        config:              window.svLiveConfig || {},
        containerSelector:   '.sv-widget-wrap.sv-large-view',
        fallbackContainerId: 'svtPanelLive',
    });
    return _instance;
}

function whenReady(cb) {
    // Wait for SVCore + jQuery before invoking cb
    if (window.SVCore && typeof window.SVCore.create === 'function' && typeof $ !== 'undefined') {
        cb();
    } else {
        setTimeout(function () { whenReady(cb); }, 60);
    }
}

window.SVLive = {
    start: function () {
        whenReady(function () {
            var inst = ensureInstance();
            if (!inst || _started) return;
            _started = true;
            inst.start();
        });
    },
    stop: function () {
        if (!_started || !_instance) return;
        _started = false;
        _instance.stop();
    },
    refresh: function () {
        if (_instance) _instance.refresh();
    },
    isStarted: function () { return _started; },
};

})();
