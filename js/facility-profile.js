/* Facility profile: collapsible sections and the video carousel.
   Without this script every section is open and every video is a plain link. */
(function () {
    'use strict';

    var OPEN_BY_DEFAULT = ['memorials', 'violations', 'lawsuits', 'incidents', 'testimony', 'videos', 'news', 'network', 'documents'];

    function sectionOf(el) {
        while (el && el !== document.body) {
            if (el.classList && el.classList.contains('kop-fp-collapsible')) return el;
            el = el.parentNode;
        }
        return null;
    }

    function setOpen(section, open) {
        var btn = section.querySelector('.kop-fp-toggle');
        var body = section.querySelector('.kop-fp-sec-body');
        if (!btn || !body) return;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        body.hidden = !open;
        section.classList.toggle('is-collapsed', !open);
    }

    function openForHash() {
        if (!location.hash) return;
        var target = null;
        try { target = document.getElementById(decodeURIComponent(location.hash.slice(1))); } catch (e) { return; }
        var section = sectionOf(target);
        if (section) {
            setOpen(section, true);
            target.scrollIntoView();
        }
    }

    function initSections() {
        var root = document.querySelector('.kop-fp-generated-body');
        if (!root) return;
        var sections = root.querySelectorAll(':scope > .kop-fp-section');
        var made = [];
        Array.prototype.forEach.call(sections, function (section) {
            var h2 = section.querySelector(':scope > h2');
            if (!h2) return;
            var body = document.createElement('div');
            body.className = 'kop-fp-sec-body';
            body.id = (section.id || 'kop-sec-' + made.length) + '-body';
            var node = h2.nextSibling;
            while (node) {
                var next = node.nextSibling;
                body.appendChild(node);
                node = next;
            }
            section.appendChild(body);

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'kop-fp-toggle';
            btn.setAttribute('aria-controls', body.id);
            while (h2.firstChild) btn.appendChild(h2.firstChild);
            var chev = document.createElement('span');
            chev.className = 'kop-fp-chev';
            chev.setAttribute('aria-hidden', 'true');
            btn.appendChild(chev);
            h2.appendChild(btn);

            section.classList.add('kop-fp-collapsible');
            btn.addEventListener('click', function () {
                setOpen(section, btn.getAttribute('aria-expanded') !== 'true');
            });
            setOpen(section, OPEN_BY_DEFAULT.indexOf(section.id) !== -1);
            made.push(section);
        });
        if (made.length < 3) return;

        var bar = document.createElement('div');
        bar.className = 'kop-fp-expandbar';
        ['Expand all', 'Collapse all'].forEach(function (label, i) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'kop-fp-expand';
            b.textContent = label;
            b.addEventListener('click', function () {
                made.forEach(function (s) { setOpen(s, i === 0); });
            });
            bar.appendChild(b);
        });
        var nav = root.querySelector(':scope > .kop-fp-jump');
        if (nav) nav.parentNode.insertBefore(bar, nav.nextSibling);
        else root.insertBefore(bar, root.firstChild);

        window.addEventListener('hashchange', openForHash);
        openForHash();
    }

    function initCarousel(root) {
        var viewport = root.querySelector('.kop-vc-viewport');
        var slides = root.querySelectorAll('.kop-vc-slide');
        var now = root.querySelector('[data-kop-vc-now]');
        if (!viewport || !slides.length) return;

        function current() {
            var w = viewport.clientWidth || 1;
            return Math.max(0, Math.min(slides.length - 1, Math.round(viewport.scrollLeft / w)));
        }
        function go(i) {
            i = Math.max(0, Math.min(slides.length - 1, i));
            viewport.scrollTo({ left: i * viewport.clientWidth, behavior: 'smooth' });
        }
        Array.prototype.forEach.call(root.querySelectorAll('.kop-vc-btn'), function (b) {
            b.addEventListener('click', function () { go(current() + parseInt(b.getAttribute('data-dir'), 10)); });
        });
        viewport.addEventListener('scroll', function () {
            if (now) now.textContent = String(current() + 1);
        }, { passive: true });
        viewport.addEventListener('keydown', function (e) {
            if (e.target !== viewport) return;
            if (e.key === 'ArrowRight') { e.preventDefault(); go(current() + 1); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); go(current() - 1); }
        });

        // The player loads on click, so the page carries no YouTube script or cookies until someone presses play.
        Array.prototype.forEach.call(root.querySelectorAll('.kop-vc-play'), function (btn) {
            btn.addEventListener('click', function () {
                var frame = btn.parentNode;
                var id = frame.getAttribute('data-id');
                var src = frame.getAttribute('data-provider') === 'vimeo'
                    ? 'https://player.vimeo.com/video/' + encodeURIComponent(id) + '?autoplay=1&dnt=1'
                    : 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(id) + '?autoplay=1&rel=0';
                var iframe = document.createElement('iframe');
                iframe.src = src;
                iframe.title = frame.getAttribute('data-title') || 'Video';
                iframe.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
                iframe.allowFullscreen = true;
                iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
                frame.replaceChild(iframe, btn);
                iframe.focus();
            });
        });
    }

    function start() {
        initSections();
        Array.prototype.forEach.call(document.querySelectorAll('[data-kop-video-carousel]'), initCarousel);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
