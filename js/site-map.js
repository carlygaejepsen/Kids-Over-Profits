/**
 * Site map (/site-map/, templates/site-map.php): the "Find a page" filter and
 * the A to Z jumps.
 *
 * Every link's words are in its <li data-sm> (folded by kop_site_map_fold() in
 * inc/site-map.php: lower case, no accents; fold() here must match). Typing
 * keeps the entries that hold every word typed, with the sections and the
 * articles above them for context and everything under them, opens the
 * collapsed A to Z groups that have a match, and hides the rest. Enter submits
 * the form to the site search (search.php) for anything not in a page title.
 */
(function () {
    'use strict';

    var form = document.querySelector('[data-kop-sm-filter]');
    if (!form) return;
    var input = form.querySelector('input[type="search"]');
    var status = form.querySelector('.kop-sm-filter__status');
    var empty = document.querySelector('[data-kop-sm-empty]');
    var root = form.closest('.kop-site-map') || document;
    var items = Array.prototype.slice.call(root.querySelectorAll('li[data-sm]'));
    var groups = Array.prototype.slice.call(root.querySelectorAll('[data-kop-sm-group]'));
    var sections = Array.prototype.slice.call(root.querySelectorAll('[data-kop-sm-section]'));
    var extras = Array.prototype.slice.call(root.querySelectorAll('.kop-sm-letters, .kop-sm-contents'));
    var wasOpen = groups.map(function (g) { return g.open; });
    var timer = null;
    var filtering = false;

    function fold(text) {
        var s = String(text || '');
        if (s.normalize) s = s.normalize('NFD').replace(/[̀-ͯ]/g, '');
        return s.toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function matches(words, text) {
        for (var i = 0; i < words.length; i++) {
            if (text.indexOf(words[i]) === -1) return false;
        }
        return true;
    }

    function parentItem(li) {
        var p = li.parentElement;
        while (p && p !== root) {
            if (p.tagName === 'LI' && p.hasAttribute('data-sm')) return p;
            p = p.parentElement;
        }
        return null;
    }

    function reset() {
        items.forEach(function (li) { li.hidden = false; });
        groups.forEach(function (g, i) { g.hidden = false; g.open = wasOpen[i]; });
        sections.forEach(function (s) { s.hidden = false; });
        extras.forEach(function (e) { e.hidden = false; });
        if (empty) empty.hidden = true;
        if (status) status.textContent = '';
        filtering = false;
    }

    function apply() {
        var q = fold(input.value);
        if (q.length < 2) {
            if (filtering) reset();
            return;
        }
        if (!filtering) {
            wasOpen = groups.map(function (g) { return g.open; });
            filtering = true;
        }
        var words = q.split(' ');
        var show = new Set();
        var hits = 0;

        // A category whose own name matches keeps everything in it.
        var groupHit = new Set();
        groups.forEach(function (g) {
            if (g.hasAttribute('data-sm') && matches(words, g.getAttribute('data-sm'))) groupHit.add(g);
        });

        items.forEach(function (li) {
            var hit = matches(words, li.getAttribute('data-sm'));
            if (!hit) {
                var g = li.closest('[data-kop-sm-group]');
                hit = !!(g && groupHit.has(g));
            }
            if (!hit) return;
            hits++;
            show.add(li);
            // Everything under it, and the entries above it.
            Array.prototype.forEach.call(li.querySelectorAll('li[data-sm]'), function (c) { show.add(c); });
            var up = parentItem(li);
            while (up) { show.add(up); up = parentItem(up); }
        });

        items.forEach(function (li) { li.hidden = !show.has(li); });
        groups.forEach(function (g) {
            var any = groupHit.has(g) || !!g.querySelector('li[data-sm]:not([hidden])');
            g.hidden = !any;
            g.open = any;
        });
        sections.forEach(function (s) {
            s.hidden = !s.querySelector('li[data-sm]:not([hidden])');
        });
        extras.forEach(function (e) { e.hidden = true; });

        if (empty) empty.hidden = hits > 0;
        if (status) {
            status.textContent = hits
                ? hits + (hits === 1 ? ' page matches.' : ' pages match.') + ' Press Enter to search inside records and documents too.'
                : 'No page title matches. Press Enter to search inside records and documents.';
        }
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(apply, 120);
    });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && input.value !== '') {
            input.value = '';
            reset();
        }
    });
    form.addEventListener('submit', function (e) {
        if (fold(input.value) === '') {
            e.preventDefault();
            input.focus();
        }
    });

    // A to Z jumps and #letter addresses open the group they point at.
    function openTarget(hash) {
        if (!hash || hash.length < 2) return;
        var target = document.getElementById(decodeURIComponent(hash.slice(1)));
        if (target && target.tagName === 'DETAILS') target.open = true;
    }
    root.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a[href^="#"]') : null;
        if (a) openTarget(a.getAttribute('href'));
    });
    window.addEventListener('hashchange', function () { openTarget(window.location.hash); });
    openTarget(window.location.hash);
    if (window.location.hash) {
        var t = document.getElementById(decodeURIComponent(window.location.hash.slice(1)));
        if (t && t.scrollIntoView) t.scrollIntoView();
    }
})();
