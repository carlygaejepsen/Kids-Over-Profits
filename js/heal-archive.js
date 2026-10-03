/* heal-online.org is gone: send any link to it to the Wayback Machine copy.
   Server-rendered pages are already rewritten (inc/heal-archive.php); this
   covers links a script adds later. */
(function () {
    'use strict';
    var RE = /^(?:https?:)?\/\/(?:www\.)?heal-online\.org(?:[\/:?#]|$)/i;

    function fix(a) {
        var href = a.getAttribute('href') || '';
        if (!RE.test(href)) return;
        if (href.indexOf('//') === 0) href = 'http:' + href;
        a.setAttribute('href', 'https://web.archive.org/web/' + href);
    }

    function scan(root) {
        var links = (root.querySelectorAll ? root.querySelectorAll('a[href*="heal-online.org"]') : []);
        for (var i = 0; i < links.length; i++) fix(links[i]);
        if (root.tagName === 'A') fix(root);
    }

    function start() {
        scan(document);
        if (!window.MutationObserver) return;
        new MutationObserver(function (records) {
            records.forEach(function (r) {
                if (r.type === 'attributes') { fix(r.target); return; }
                for (var i = 0; i < r.addedNodes.length; i++) {
                    if (r.addedNodes[i].nodeType === 1) scan(r.addedNodes[i]);
                }
            });
        }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['href'] });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
