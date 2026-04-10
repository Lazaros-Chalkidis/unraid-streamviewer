// StreamViewer header indicator
// Copyright (C) 2026 Lazaros Chalkidis - License: GPLv3
// Click handler (called by Unraid button onclick)
function StreamViewerButton(){
    location.href = '/Tools/StreamViewerTool';
}

(function(){
    "use strict";
    var navItem = null;
    var badge = null;
    var lastCount = 0;

    function setup(){
        navItem = document.querySelector('.nav-item.StreamViewerButton');
        if(!navItem){ setTimeout(setup, 500); return; }

        // Hide initially
        navItem.style.display = 'none';

        // Replace <img> with inline SVG so currentColor works with all themes
        var link = navItem.querySelector('a');
        if(link){
            var img = link.querySelector('img, b.system, i.system, b.fa');
            if(img){
                var svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
                svg.setAttribute('width','16');
                svg.setAttribute('height','16');
                svg.setAttribute('viewBox','0 0 24 24');
                svg.setAttribute('fill','none');
                svg.setAttribute('stroke','currentColor');
                svg.setAttribute('stroke-width','2');
                svg.setAttribute('stroke-linecap','round');
                svg.setAttribute('stroke-linejoin','round');
                svg.setAttribute('class','system');
                var p = document.createElementNS('http://www.w3.org/2000/svg','path');
                p.setAttribute('d','M2 16.1A5 5 0 0 1 5.9 20M2 12.05A9 9 0 0 1 9.95 20M2 8V6a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-6');
                svg.appendChild(p);
                var ln = document.createElementNS('http://www.w3.org/2000/svg','line');
                ln.setAttribute('x1','2'); ln.setAttribute('y1','20');
                ln.setAttribute('x2','2.01'); ln.setAttribute('y2','20');
                svg.appendChild(ln);
                img.parentNode.replaceChild(svg, img);

                // Match icon color to theme
                var iconColor = getComputedStyle(link).color || '#ccc';
                svg.setAttribute('stroke', iconColor);
            }

            link.style.position = 'relative';
            badge = document.createElement('span');
            badge.style.position = 'absolute';
            badge.style.top = '-1px';
            badge.style.right = '-4px';
            badge.style.background = '#7C4DFF';
            badge.style.color = '#fff';
            badge.style.fontSize = '8px';
            badge.style.fontWeight = '600';
            badge.style.minWidth = '12px';
            badge.style.height = '12px';
            badge.style.borderRadius = '6px';
            badge.style.display = 'flex';
            badge.style.alignItems = 'center';
            badge.style.justifyContent = 'center';
            badge.style.padding = '0 3px';
            badge.style.lineHeight = '1';
            badge.style.pointerEvents = 'none';
            link.appendChild(badge);
        }

        // Start polling
        poll();
        setInterval(poll, 30000);
    }

    function poll(){
        var x = new XMLHttpRequest();
        x.open('GET', '/plugins/streamviewer/streamviewer_header.php?t=' + Date.now());
        x.timeout = 5000;
        x.onload = function(){
            try {
                var c = (JSON.parse(x.responseText).count) || 0;
                lastCount = c;
                if(c > 0){
                    navItem.style.display = '';
                    if(badge) badge.textContent = c;
                } else {
                    navItem.style.display = 'none';
                }
            } catch(e){
                navItem.style.display = 'none';
            }
        };
        x.onerror = x.ontimeout = function(){};
        x.send();
    }

    setTimeout(setup, 2000);
})();
