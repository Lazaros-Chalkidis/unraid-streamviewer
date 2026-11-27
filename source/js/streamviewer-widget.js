/* ============================================================================
   STREAM VIEWER
   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3
   ========================================================================= */

(function () {
'use strict';

if (window.__svWidgetBootstrapped) return;
window.__svWidgetBootstrapped = true;

function boot() {

    if (!window.SVCore || typeof window.SVCore.create !== 'function' || typeof $ === 'undefined') {
        setTimeout(boot, 60);
        return;
    }

    var instance = window.SVCore.create({
        config:              window.streamviewerConfig || {},
        containerSelector:   '.sv-widget-wrap',
        fallbackContainerId: 'db-streamviewer',
    });

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
