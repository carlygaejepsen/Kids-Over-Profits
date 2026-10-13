/**
 * Document libraries on facility and company pages ([filebird_folder],
 * inc/features.php): when the tiles hold two or more document types
 * (data-doc-types, inc/doc-types.php), a row of buttons above the library
 * shows only one type at a time, opening the subfolders that hold it, and
 * links to every document of that type across programs (/documents/<type>/).
 * Labels come from KOP_DOC_TYPES (wp_localize_script).
 */
(function () {
    'use strict';

    var TYPES = (window.KOP_DOC_TYPES && window.KOP_DOC_TYPES.types) || {};

    function el(tag, attrs, kids) {
        var n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') n.textContent = attrs[k];
            else n.setAttribute(k, attrs[k]);
        });
        (kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
        return n;
    }

    function setup(lib) {
        if (lib.querySelector('.kop-dtf')) return;
        var tiles = Array.prototype.slice.call(lib.querySelectorAll('li.doc-item'));
        var counts = {};
        tiles.forEach(function (li) {
            (li.getAttribute('data-doc-types') || '').split(' ').filter(Boolean).forEach(function (t) {
                if (TYPES[t]) counts[t] = (counts[t] || 0) + 1;
            });
        });
        // Types in the order the site lists them.
        var present = Object.keys(TYPES).filter(function (t) { return counts[t]; });
        if (present.length < 2) return;

        var bar = el('div', { class: 'kop-dtf', role: 'group', 'aria-label': 'Show one kind of document' });
        var more = el('p', { class: 'kop-dtf__more' });
        more.hidden = true;
        var buttons = [];
        var details = Array.prototype.slice.call(lib.querySelectorAll('details'));
        var wasOpen = details.map(function (d) { return d.open; });

        function pick(type, btn) {
            buttons.forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
            tiles.forEach(function (li) {
                var has = (' ' + (li.getAttribute('data-doc-types') || '') + ' ').indexOf(' ' + type + ' ') !== -1;
                li.hidden = !!type && !has;
            });
            details.forEach(function (d, i) {
                if (!type) { d.hidden = false; d.open = wasOpen[i]; return; }
                var any = !!d.querySelector('li.doc-item:not([hidden])');
                d.hidden = !any;
                d.open = any;
            });
            more.textContent = '';
            more.hidden = !type;
            if (type && TYPES[type].url) {
                more.appendChild(el('a', { href: TYPES[type].url, text: 'Compare with ' + TYPES[type].plural.toLowerCase() + ' from other programs' }));
            }
        }

        var all = el('button', { type: 'button', class: 'kop-dtf__btn', 'aria-pressed': 'true' }, ['All (' + tiles.length + ')']);
        all.addEventListener('click', function () { pick('', all); });
        buttons.push(all);
        bar.appendChild(all);
        present.forEach(function (t) {
            var b = el('button', { type: 'button', class: 'kop-dtf__btn', 'aria-pressed': 'false' }, [TYPES[t].plural + ' (' + counts[t] + ')']);
            b.addEventListener('click', function () { pick(t, b); });
            buttons.push(b);
            bar.appendChild(b);
        });

        var title = lib.querySelector('.doc-folder-title-single');
        var at = title ? title.nextSibling : lib.firstChild;
        lib.insertBefore(bar, at);
        lib.insertBefore(more, bar.nextSibling);
    }

    function init() {
        document.querySelectorAll('.kop-document-folder').forEach(setup);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
