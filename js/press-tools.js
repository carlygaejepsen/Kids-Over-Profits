/**
 * Cite and print on /facility/ and /operator/ pages (inc/press-tools.php).
 * Builds the "Cite this page" box above the page footer from KOP_PRESS_TOOLS,
 * with the reader's own access date, and a "printed from" line that only
 * paper shows. The citation wording must match kop_press_citation().
 */
(function () {
    'use strict';

    // name, work ("Kids Over Profits facility database"), updated, url, accessed;
    // scripts/test-press-tools.php checks it against kop_press_citation().
    function citationText(name, work, updated, url, accessed) {
        var text = 'Kids Over Profits. "' + String(name).trim().replace(/\.+$/, '') + '." ' + work;
        if (updated && String(updated).trim()) text += ', last updated ' + String(updated).trim();
        text += '. ' + String(url).trim() + '.';
        if (accessed && String(accessed).trim()) text += ' Accessed ' + String(accessed).trim() + '.';
        return text;
    }
    window.kopPressCitation = citationText;

    var cfg = window.KOP_PRESS_TOOLS;
    if (!cfg || !cfg.name) return;

    function longDate(d) {
        try {
            return d.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
        } catch (e) {
            return d.toDateString();
        }
    }

    function pageUrl() {
        var link = document.querySelector('link[rel="canonical"]');
        var url = link && link.href ? link.href : window.location.href;
        return url.split('#')[0].split('?')[0];
    }

    function citation(accessed) {
        return citationText(cfg.name, cfg.work, cfg.updated, pageUrl(), accessed);
    }

    function el(tag, attrs, text) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
        if (text) node.textContent = text;
        return node;
    }

    function copy(text, done) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { fallback(text, done); });
        } else {
            fallback(text, done);
        }
    }

    function fallback(text, done) {
        var area = el('textarea', { readonly: '', 'aria-hidden': 'true', style: 'position:fixed;top:-1000px;opacity:0' });
        area.value = text;
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); done(); } catch (e) { /* the text stays selectable in the box */ }
        document.body.removeChild(area);
    }

    function build() {
        var article = document.querySelector('article.kop-facility-profile');
        if (!article || article.querySelector('.kop-press')) return;
        var footer = article.querySelector('.kop-fp-footer');
        var text = citation(longDate(new Date()));

        var box = el('section', { 'class': 'kop-press', 'aria-labelledby': 'kop-press-h' });
        box.appendChild(el('h2', { id: 'kop-press-h', 'class': 'kop-press-h' }, 'Cite this page'));
        // The address is part of the citation: js/url-labels.js must not
        // turn it into words.
        var cite = el('p', { 'class': 'kop-press-cite', 'data-kop-keep-url': '' }, text);
        box.appendChild(cite);

        var actions = el('p', { 'class': 'kop-press-actions' });
        var copyBtn = el('button', { type: 'button', 'class': 'kop-press-btn' }, 'Copy citation');
        var status = el('span', { 'class': 'kop-press-status', role: 'status' });
        copyBtn.addEventListener('click', function () {
            copy(text, function () {
                status.textContent = 'Copied.';
                setTimeout(function () { status.textContent = ''; }, 2500);
            });
        });
        var printBtn = el('button', { type: 'button', 'class': 'kop-press-btn' }, 'Print or save as PDF');
        printBtn.addEventListener('click', function () { window.print(); });
        actions.appendChild(copyBtn);
        actions.appendChild(printBtn);
        actions.appendChild(status);
        box.appendChild(actions);

        var note = el('p', { 'class': 'kop-press-note' });
        note.appendChild(document.createTextNode('The facts on this page are free to quote and reuse with credit to Kids Over Profits ('));
        note.appendChild(el('a', { href: cfg.licenseUrl, rel: 'license' }, cfg.license));
        note.appendChild(document.createTextNode(').'));
        if (cfg.openDataUrl) {
            note.appendChild(document.createTextNode(' Every record can be downloaded from '));
            note.appendChild(el('a', { href: cfg.openDataUrl }, 'Open data'));
            note.appendChild(document.createTextNode('.'));
        }
        if (cfg.deskUrl) {
            note.appendChild(document.createTextNode(' Reporters: '));
            note.appendChild(el('a', { href: cfg.deskUrl }, 'the press desk'));
            note.appendChild(document.createTextNode(' has our sources and how to reach us.'));
        }
        box.appendChild(note);

        // The printed copy says where and when it came from, at the top.
        var stamp = el('p', { 'class': 'kop-press-stamp', 'data-kop-keep-url': '' }, 'Printed from Kids Over Profits on ' + longDate(new Date()) + ': ' + pageUrl());
        article.insertBefore(stamp, article.firstChild);

        if (footer) {
            article.insertBefore(box, footer);
        } else {
            article.appendChild(box);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', build);
    } else {
        build();
    }
})();
