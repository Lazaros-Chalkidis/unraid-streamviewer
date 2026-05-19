/* ═══════════════════════════════════════════════════════════════════════════
   Stream Viewer  —  streamviewer-widget.js
   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3

   Thin bootstrap that wires the dashboard widget tile to the shared core.
   Reads the per-tile config from window.streamviewerConfig (set inline by
   StreamViewer.page) and spins up an SVCore instance scoped to the widget's
   container. Also re-exposes the legacy window.StreamViewer / streamviewerInit
   helpers so any external caller keeps working unchanged.
   ═══════════════════════════════════════════════════════════════════════════ */
/* global $ */

(function () {
'use strict';

// Guard against the widget bootstrap running more than once per page (the
// dashboard can re-include this script when tiles are toggled).
if (window.__svWidgetBootstrapped) return;
window.__svWidgetBootstrapped = true;

function boot() {
    // Wait for core + jQuery to be present before instantiating
    if (!window.SVCore || typeof window.SVCore.create !== 'function' || typeof $ === 'undefined') {
        setTimeout(boot, 60);
        return;
    }

    var instance = window.SVCore.create({
        config:              window.streamviewerConfig || {},
        containerSelector:   '.sv-widget-wrap',
        fallbackContainerId: 'db-streamviewer',
    });

    // Legacy public API (callers may reinit/refresh/getSessions from outside)
    window.StreamViewer = {
        reinit: function (newConfig) {
            if (newConfig) window.streamviewerConfig = newConfig;
            instance.reinit(newConfig);
        },
        refresh:     function () { instance.refresh(); },
        getSessions: function () { return instance.getSessions(); },
    };
    window.streamviewerInit = window.StreamViewer.reinit;

    instance.start();
}

boot();

})();
