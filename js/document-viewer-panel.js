/**
 * Document Viewer Panel
 * ---------------------
 * A docked, resizable side panel that shows FileBird documents next to the
 * form so editors can read a source document and fill in fields at the same
 * time (easy copy/paste). Used on the data editor and wiki editor pages.
 *
 * Flow: toggle open → Browse folders (reuses KOPFolderBrowser) → pick a folder
 * → lists every document in it → click a doc → preview (PDF/image/video/audio
 * inline, else a download link). The page content reflows left so both stay
 * visible.
 *
 * Config via window.kopDocViewer = { foldersUrl, restBase }.
 *   foldersUrl – /wp-json/kop/v1/folders
 *   restBase   – /wp-json/kop/v1/   (folder-content is restBase + 'folder-content')
 */
(function () {
    'use strict';

    var cfg = window.kopDocViewer || {};
    var FOLDERS_URL = cfg.foldersUrl || '/wp-json/kop/v1/folders';
    var REST_BASE = cfg.restBase || '/wp-json/kop/v1/';

    var MIN_W = 320;
    var els = {};        // cached DOM refs
    var currentDocs = []; // docs in the loaded folder

    function el(tag, cls, html) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (html != null) n.innerHTML = html;
        return n;
    }
    function esc(t) {
        return String(t == null ? '' : t)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function ext(url) {
        if (!url) return '';
        var clean = url.split('#')[0].split('?')[0];
        var parts = clean.split('.');
        return parts.length < 2 ? '' : parts.pop().toLowerCase();
    }

    function build() {
        // Floating toggle button.
        var toggle = el('button', 'kop-dv-toggle', '📄 Source Docs');
        toggle.type = 'button';
        toggle.addEventListener('click', openPanel);
        document.body.appendChild(toggle);
        els.toggle = toggle;

        // Panel.
        var panel = el('div', 'kop-dv-panel');
        panel.innerHTML =
            '<div class="kop-dv-resizer" title="Drag to resize"></div>' +
            '<div class="kop-dv-header">' +
                '<span class="kop-dv-title">📄 Document Viewer</span>' +
                '<div class="kop-dv-headbtns">' +
                    '<button type="button" class="kop-dv-browse">📁 Browse folder</button>' +
                    '<button type="button" class="kop-dv-close" aria-label="Close">&times;</button>' +
                '</div>' +
            '</div>' +
            '<div class="kop-dv-folder dk-muted"></div>' +
            '<div class="kop-dv-list"></div>' +
            '<div class="kop-dv-preview"><div class="kop-dv-empty">Browse a folder, then pick a document to view it here.</div></div>';
        document.body.appendChild(panel);
        els.panel = panel;
        els.folder = panel.querySelector('.kop-dv-folder');
        els.list = panel.querySelector('.kop-dv-list');
        els.preview = panel.querySelector('.kop-dv-preview');

        panel.querySelector('.kop-dv-close').addEventListener('click', closePanel);
        panel.querySelector('.kop-dv-browse').addEventListener('click', browse);
        initResizer(panel.querySelector('.kop-dv-resizer'));
    }

    function setWidth(px) {
        px = Math.max(MIN_W, Math.min(px, Math.round(window.innerWidth * 0.85)));
        els.panel.style.width = px + 'px';
        // Reflow page content on wide screens; overlay on narrow ones.
        if (window.innerWidth >= 1024) {
            document.body.style.paddingRight = px + 'px';
        } else {
            document.body.style.paddingRight = '';
        }
    }

    function openPanel() {
        if (!els.panel.classList.contains('is-open')) {
            els.panel.classList.add('is-open');
            els.toggle.classList.add('is-hidden');
            var w = parseInt(els.panel.style.width, 10) || 480;
            setWidth(w);
        }
    }
    function closePanel() {
        els.panel.classList.remove('is-open');
        els.toggle.classList.remove('is-hidden');
        document.body.style.paddingRight = '';
    }

    function initResizer(handle) {
        if (!handle) return;
        var dragging = false;
        handle.addEventListener('mousedown', function (e) {
            dragging = true;
            document.body.classList.add('kop-dv-resizing');
            e.preventDefault();
        });
        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            setWidth(window.innerWidth - e.clientX);
        });
        document.addEventListener('mouseup', function () {
            if (dragging) { dragging = false; document.body.classList.remove('kop-dv-resizing'); }
        });
    }

    function browse() {
        if (!window.KOPFolderBrowser || typeof window.KOPFolderBrowser.open !== 'function') {
            els.folder.textContent = 'Folder browser failed to load.';
            return;
        }
        window.KOPFolderBrowser.open({ foldersUrl: FOLDERS_URL }).then(function (res) {
            if (!res || res.id === null) return;
            loadFolder(res.id, res.name);
        });
    }

    function loadFolder(id, name) {
        els.folder.classList.remove('dk-muted');
        els.folder.innerHTML = '📁 <strong>' + esc(name || ('Folder #' + id)) + '</strong>';
        els.list.innerHTML = '<div class="kop-dv-loading">Loading documents…</div>';
        els.preview.innerHTML = '<div class="kop-dv-empty">Pick a document from the list above.</div>';

        fetch(REST_BASE + 'folder-content?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                // Endpoint returns a flat array; tolerate {files:[...]} too.
                var docs = Array.isArray(data) ? data : (data && Array.isArray(data.files) ? data.files : []);
                currentDocs = docs;
                renderList(docs);
            })
            .catch(function () { els.list.innerHTML = '<div class="kop-dv-error">Failed to load documents.</div>'; });
    }

    function renderList(docs) {
        els.list.innerHTML = '';
        if (!docs.length) {
            els.list.innerHTML = '<div class="kop-dv-empty">No documents in this folder.</div>';
            return;
        }
        var filter = el('input', 'kop-dv-filter');
        filter.type = 'search';
        filter.placeholder = 'Filter ' + docs.length + ' document' + (docs.length === 1 ? '' : 's') + '…';
        els.list.appendChild(filter);

        var items = el('div', 'kop-dv-items');
        els.list.appendChild(items);

        function paint(rows) {
            items.innerHTML = '';
            rows.forEach(function (d) {
                var row = el('button', 'kop-dv-item');
                row.type = 'button';
                var icon = /^image\//.test(d.mime_type || '') ? '🖼️'
                    : (ext(d.url) === 'pdf' || d.mime_type === 'application/pdf') ? '📕' : '📄';
                row.innerHTML = '<span class="kop-dv-item-icon">' + icon + '</span>' +
                    '<span class="kop-dv-item-name">' + esc(d.title || d.url) + '</span>';
                row.addEventListener('click', function () {
                    Array.prototype.forEach.call(items.children, function (c) { c.classList.remove('is-active'); });
                    row.classList.add('is-active');
                    showDoc(d);
                });
                items.appendChild(row);
            });
        }
        paint(docs);

        filter.addEventListener('input', function () {
            var q = filter.value.toLowerCase().trim();
            paint(!q ? docs : docs.filter(function (d) {
                return String(d.title || '').toLowerCase().indexOf(q) !== -1;
            }));
        });
    }

    function showDoc(doc) {
        var url = doc.url || '';
        var mime = doc.mime_type || '';
        var e = ext(url);
        var isPdf = e === 'pdf' || mime === 'application/pdf';
        var isImg = /^image\//.test(mime) || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'].indexOf(e) !== -1;
        var isVid = /^video\//.test(mime) || ['mp4', 'webm', 'mov', 'm4v'].indexOf(e) !== -1;
        var isAud = /^audio\//.test(mime) || ['mp3', 'wav', 'ogg', 'm4a'].indexOf(e) !== -1;

        var body;
        if (isPdf) {
            body = '<iframe class="kop-dv-frame" src="' + esc(url) + '" title="' + esc(doc.title || 'PDF') + '" loading="lazy"></iframe>';
        } else if (isImg) {
            body = '<div class="kop-dv-imgwrap"><img src="' + esc(url) + '" alt="' + esc(doc.title || '') + '"></div>';
        } else if (isVid) {
            body = '<video class="kop-dv-frame" controls src="' + esc(url) + '"></video>';
        } else if (isAud) {
            body = '<audio controls src="' + esc(url) + '" style="width:100%;margin-top:12px;"></audio>';
        } else {
            body = '<div class="kop-dv-empty">No inline preview for this file type.</div>';
        }

        els.preview.innerHTML =
            '<div class="kop-dv-preview-bar">' +
                '<span class="kop-dv-preview-title">' + esc(doc.title || url) + '</span>' +
                '<a class="kop-dv-open" href="' + esc(url) + '" target="_blank" rel="noopener">Open ↗</a>' +
            '</div>' + body;
    }

    function init() {
        if (document.querySelector('.kop-dv-panel')) return; // already mounted
        build();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.KOPDocViewer = { open: openPanel, close: closePanel };
})();
