(function () {
    'use strict';

    function addSourcePreview(link) {
        if (link.hasAttribute('data-kop-citation-preview')) return;

        var href = link.getAttribute('href') || '';
        if (!href || href.charAt(0) !== '#') return;

        var target;
        try {
            target = document.getElementById(decodeURIComponent(href.slice(1)));
        } catch (error) {
            return;
        }
        if (!target || !(target.matches('.kop-article-sources__item') || target.matches('li[id^="src-"]'))) return;

        var preview = (target.textContent || '').replace(/\s+/g, ' ').trim();
        if (!preview) return;
        if (preview.length > 500) preview = preview.slice(0, 497).trimEnd() + '...';

        link.setAttribute('data-kop-citation-preview', preview);
    }

    function initialize() {
        document.querySelectorAll('a[href^="#"]').forEach(addSourcePreview);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, { once: true });
    } else {
        initialize();
    }
})();
