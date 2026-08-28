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

    var MIN_W = 320;     // min panel width (desktop, side-by-side)
    var MIN_H = 200;     // min panel height (mobile, top/bottom split)
    var panelW = 480;    // remembered desktop width
    var panelH = null;   // remembered mobile height (null = default to ~50vh)
    var els = {};        // cached DOM refs
    var currentDocs = []; // docs in the loaded folder

    // Below this viewport width we dock to the bottom (top/bottom split) instead
    // of the right (side-by-side), so the form still fits on narrow screens.
    function isDesktop() { return window.innerWidth >= 1024; }

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
            '<button type="button" class="kop-dv-list-toggle" hidden></button>' +
            '<div class="kop-dv-list"></div>' +
            '<div class="kop-dv-preview"><div class="kop-dv-empty">Browse a folder, then pick a document to view it here.</div></div>';
        document.body.appendChild(panel);
        els.panel = panel;
        els.folder = panel.querySelector('.kop-dv-folder');
        els.list = panel.querySelector('.kop-dv-list');
        els.listToggle = panel.querySelector('.kop-dv-list-toggle');
        els.preview = panel.querySelector('.kop-dv-preview');

        els.listToggle.addEventListener('click', function () { setListCollapsed(false); });

        panel.querySelector('.kop-dv-close').addEventListener('click', closePanel);
        panel.querySelector('.kop-dv-browse').addEventListener('click', browse);
        initResizer(panel.querySelector('.kop-dv-resizer'));

        // Feature-scoped bug reporting for the document viewer.
        if (window.KOPBugReporter && typeof window.KOPBugReporter.attach === 'function') {
            window.KOPBugReporter.attach(panel, 'document-viewer', 'Document Viewer');
        }
    }

    // On small screens the panel is only ~half the viewport tall, so once a
    // document is chosen the list (filter + items) collapses into a one-line
    // bar to give the preview the room; tapping the bar brings the list back.
    function setListCollapsed(collapsed, currentTitle) {
        if (!els.list || !els.listToggle) return;
        if (collapsed) {
            els.list.style.display = 'none';
            els.listToggle.hidden = false;
            els.listToggle.innerHTML =
                '<span class="kop-dv-list-toggle-name">📑 ' + esc(currentTitle || 'Documents') + '</span>' +
                '<span class="kop-dv-list-toggle-hint">' +
                (currentDocs.length ? 'choose another (' + currentDocs.length + ') ▾' : 'show list ▾') +
                '</span>';
        } else {
            els.list.style.display = '';
            els.listToggle.hidden = true;
        }
    }

    // Size and dock the panel for the current viewport, reflowing the page so
    // the form is never covered: side-by-side (reserve width) on desktop,
    // top/bottom split (reserve height) on mobile.
    function applyLayout() {
        if (!els.panel || !els.panel.classList.contains('is-open')) {
            document.body.style.paddingRight = '';
            document.body.style.paddingBottom = '';
            return;
        }
        if (isDesktop()) {
            document.body.classList.remove('kop-dv-bottom');
            // Desktop has the height for both; always show the list.
            setListCollapsed(false);
            var w = Math.max(MIN_W, Math.min(panelW, Math.round(window.innerWidth * 0.85)));
            panelW = w;
            els.panel.style.width = w + 'px';
            els.panel.style.height = '';
            document.body.style.paddingBottom = '';
            document.body.style.paddingRight = w + 'px';
        } else {
            document.body.classList.add('kop-dv-bottom');
            var maxH = Math.round(window.innerHeight * 0.85);
            var h = Math.max(MIN_H, Math.min(panelH || Math.round(window.innerHeight * 0.5), maxH));
            panelH = h;
            els.panel.style.height = h + 'px';
            els.panel.style.width = '';
            document.body.style.paddingRight = '';
            document.body.style.paddingBottom = h + 'px';
        }
    }

    function openPanel() {
        if (!els.panel.classList.contains('is-open')) {
            els.panel.classList.add('is-open');
            els.toggle.classList.add('is-hidden');
            applyLayout();
        }
    }
    function closePanel() {
        els.panel.classList.remove('is-open');
        els.toggle.classList.remove('is-hidden');
        document.body.classList.remove('kop-dv-bottom');
        document.body.style.paddingRight = '';
        document.body.style.paddingBottom = '';
    }

    function initResizer(handle) {
        if (!handle) return;
        var dragging = false;

        function move(clientX, clientY) {
            if (!dragging) return;
            if (isDesktop()) {
                panelW = window.innerWidth - clientX;
            } else {
                panelH = window.innerHeight - clientY;
            }
            applyLayout();
        }
        function endDrag() {
            if (dragging) { dragging = false; document.body.classList.remove('kop-dv-resizing'); }
        }

        handle.addEventListener('mousedown', function (e) {
            dragging = true;
            document.body.classList.add('kop-dv-resizing');
            e.preventDefault();
        });
        handle.addEventListener('touchstart', function (e) {
            dragging = true;
            document.body.classList.add('kop-dv-resizing');
            e.preventDefault();
        }, { passive: false });

        document.addEventListener('mousemove', function (e) { move(e.clientX, e.clientY); });
        document.addEventListener('touchmove', function (e) {
            if (!dragging || !e.touches.length) return;
            move(e.touches[0].clientX, e.touches[0].clientY);
            e.preventDefault();
        }, { passive: false });

        document.addEventListener('mouseup', endDrag);
        document.addEventListener('touchend', endDrag);

        // Re-dock when the viewport crosses the desktop/mobile breakpoint
        // (window resize or device rotation).
        window.addEventListener('resize', applyLayout);
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
        setListCollapsed(false);
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

        // Small screens: give the preview the space the list was using.
        if (!isDesktop()) {
            setListCollapsed(true, doc.title || '');
        }
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

    window.KOPDocViewer = {
        open: openPanel,
        close: closePanel,
        // Open the panel and load a specific FileBird folder in one step —
        // used by the wiki editor to jump straight to the linked program's
        // document library.
        openFolder: function (id, name) {
            if (!els.panel) init();
            openPanel();
            loadFolder(id, name);
        }
    };
})();
