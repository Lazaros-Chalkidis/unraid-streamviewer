/* ============================================================================
   STREAM VIEWER
   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3
   ========================================================================= */

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
