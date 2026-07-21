/* ============================================================================
   STREAM VIEWER
   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3
   ========================================================================= */

// navbar button click handler, opens the stats page
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
        if(!navItem){ setTimeout(setup, 500); return; }  // nav item not in the dom yet, retry shortly

        navItem.style.display = 'none';

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
                p.setAttribute('d','M7 4v16l13 -8z');
                p.setAttribute('fill','currentColor');
                svg.appendChild(p);
                img.parentNode.replaceChild(svg, img);

                var iconColor = getComputedStyle(link).color || '#ccc';  // tint the play glyph to match the navbar text colour
                svg.setAttribute('stroke', iconColor);
            }

            link.style.position = 'relative';
            badge = document.createElement('span');
            badge.style.position = 'absolute';
            badge.style.top = '0px';
            badge.style.right = '0.5rem';
            badge.style.background = 'transparent';
            badge.style.color = '#486dba';
            badge.style.fontSize = '10px';
            badge.style.display = 'flex';
            badge.style.lineHeight = '1';
            badge.style.pointerEvents = 'none';
            link.appendChild(badge);
        }

        poll();
        setInterval(poll, 10000);  // refresh the stream count every 10s
    }

    function poll(){
        var x = new XMLHttpRequest();
        x.open('GET', '/plugins/streamviewer/include/streamviewer_header.php?t=' + Date.now());
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

    setTimeout(setup, 800);
})();
