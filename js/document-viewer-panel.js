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
 * PDFs: a plain <iframe> only works where the browser has its own inline PDF
 * viewer. Mobile Chrome has none, desktop Chrome set to "Download PDFs" has
 * none, and iOS frames show page one only - there the click downloaded the
 * file or left the preview blank. Those browsers get the pages drawn by
 * pdf.js instead, with a text layer so the words can still be selected and
 * copied into the form.
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
    var currentDoc = null; // doc showing in the preview
    var renderToken = 0;   // bumped per preview; a stale pdf.js render checks it and stops
    var pdfTask = null;    // in-flight pdf.js loading task
    var pdfLoaded = null;  // { url, pdfjs, pdf } for the document on show
    var pdfWidth = 0;      // host width the pdf.js pages were drawn at
    var pdfZoom = 1;       // pdf.js page width as a multiple of the panel width

    var PDFJS_BASE = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
    var pdfjsReady = null;

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
        var toggle = el('button', 'kop-dv-toggle', 'Source Docs');
        toggle.type = 'button';
        toggle.addEventListener('click', openPanel);
        document.body.appendChild(toggle);
        els.toggle = toggle;

        // Panel.
        var panel = el('div', 'kop-dv-panel');
        panel.innerHTML =
            '<div class="kop-dv-resizer" title="Drag to resize"></div>' +
            '<div class="kop-dv-header">' +
                '<span class="kop-dv-title">Document Viewer</span>' +
                '<div class="kop-dv-headbtns">' +
                    '<button type="button" class="kop-dv-browse">Browse folder</button>' +
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

        // Preview-bar tools: zoom the drawn pages, or swap between the browser's
        // own PDF viewer and the drawn pages.
        els.preview.addEventListener('click', function (e) {
            var tool = e.target.closest ? e.target.closest('[data-dv]') : null;
            if (!tool || !currentDoc) return;
            var act = tool.getAttribute('data-dv');
            if (act === 'mode') {
                setPreferDraw(!preferDraw());
                showDoc(currentDoc);
            } else {
                pdfZoom = Math.max(0.5, Math.min(4, pdfZoom * (act === 'in' ? 1.25 : 0.8)));
                refitPdf(true);
            }
        });

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
                '<span class="kop-dv-list-toggle-name">' + esc(currentTitle || 'Documents') + '</span>' +
                '<span class="kop-dv-list-toggle-hint">' +
                (currentDocs.length ? 'choose another (' + currentDocs.length + ')' : 'show list') +
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
            if (dragging) { dragging = false; document.body.classList.remove('kop-dv-resizing'); refitPdf(); }
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
        var refitTimer;
        window.addEventListener('resize', function () {
            applyLayout();
            clearTimeout(refitTimer);
            refitTimer = setTimeout(refitPdf, 300);
        });
    }

    function browse() {
        if (!window.KOPFolderBrowser || typeof window.KOPFolderBrowser.open !== 'function') {
            els.folder.textContent = 'Folder browser failed to load.';
            return;
        }
        window.KOPFolderBrowser.open({
            foldersUrl: FOLDERS_URL,
            hideEmpty: true,   // nothing to read in an empty folder
            allowClear: false
        }).then(function (res) {
            if (!res || res.id === null) return;
            loadFolder(res.id, res.name);
        });
    }

    function loadFolder(id, name) {
        setListCollapsed(false);
        els.folder.classList.remove('dk-muted');
        els.folder.innerHTML = '<strong>' + esc(name || ('Folder #' + id)) + '</strong>';
        els.list.innerHTML = '<div class="kop-dv-loading">Loading documents…</div>';
        stopPdf();
        currentDoc = null;
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
                row.innerHTML = '<span class="kop-dv-item-icon">' + kindOf(d).toUpperCase() + '</span>' +
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

    function kindOf(doc) {
        var mime = doc.mime_type || '';
        var e = ext(doc.url || '');
        if (e === 'pdf' || mime === 'application/pdf') return 'pdf';
        if (/^image\//.test(mime) || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'].indexOf(e) !== -1) return 'img';
        if (/^video\//.test(mime) || ['mp4', 'webm', 'mov', 'm4v'].indexOf(e) !== -1) return 'vid';
        if (/^audio\//.test(mime) || ['mp3', 'wav', 'ogg', 'm4a'].indexOf(e) !== -1) return 'aud';
        return 'file';
    }

    // True only where an <iframe> really shows a whole PDF. pdfViewerEnabled is
    // false on mobile Chrome and wherever the user turned the built-in viewer
    // off; iOS reports true but frames page one only, unscrollable.
    function hasNativePdf() {
        var ios = /iPad|iPhone|iPod/.test(navigator.userAgent || '') ||
            (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        return !ios && navigator.pdfViewerEnabled !== false;
    }

    // A browser can claim a PDF viewer and still show nothing (or download the
    // file), so the choice can be flipped by hand and is remembered.
    function preferDraw() {
        try { return window.localStorage.getItem('kopDvDrawPdf') === '1'; } catch (e) { return false; }
    }
    function setPreferDraw(on) {
        try { window.localStorage.setItem('kopDvDrawPdf', on ? '1' : '0'); } catch (e) { /* private mode */ }
    }

    function loadPdfJs() {
        if (pdfjsReady) return pdfjsReady;
        pdfjsReady = new Promise(function (resolve, reject) {
            var sc = document.createElement('script');
            sc.src = PDFJS_BASE + 'pdf.min.js';
            sc.onload = function () {
                if (!window.pdfjsLib) { reject(new Error('pdf.js missing')); return; }
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_BASE + 'pdf.worker.min.js';
                resolve(window.pdfjsLib);
            };
            sc.onerror = function () { pdfjsReady = null; reject(new Error('pdf.js failed to load')); };
            document.head.appendChild(sc);
        });
        return pdfjsReady;
    }

    // Cancels whatever is drawing. keepDoc leaves the downloaded PDF loaded, so
    // a zoom or a panel resize redraws the pages without fetching it again.
    function stopPdf(keepDoc) {
        renderToken++;
        if (keepDoc) return;
        if (pdfTask) { try { pdfTask.destroy(); } catch (e) { /* already gone */ } pdfTask = null; }
        pdfLoaded = null;
    }

    // Draw a PDF as a scrolling column of pages. Pages render as they near the
    // viewport, so a 100-page scan costs no more up front than a one-pager.
    function renderPdf(doc, host, keepScroll) {
        var token = renderToken;
        var stale = function () { return token !== renderToken; };
        var loaded;

        if (pdfLoaded && pdfLoaded.url === doc.url) {
            loaded = Promise.resolve(pdfLoaded);
        } else {
            host.innerHTML = '<div class="kop-dv-loading">Loading document…</div>';
            loaded = loadPdfJs().then(function (pdfjs) {
                if (stale()) return null;
                pdfTask = pdfjs.getDocument({ url: doc.url });
                // Big scans take a while; show how far along the download is.
                pdfTask.onProgress = function (p) {
                    var note = host.querySelector('.kop-dv-loading');
                    if (stale() || !note || !p || !p.total) return;
                    note.textContent = 'Loading document… ' +
                        Math.min(100, Math.round(p.loaded / p.total * 100)) + '%';
                };
                return pdfTask.promise.then(function (pdf) {
                    if (stale()) return null;
                    pdfLoaded = { url: doc.url, pdfjs: pdfjs, pdf: pdf };
                    return pdfLoaded;
                });
            });
        }

        loaded.then(function (ctx) {
            if (!ctx || stale()) return null;
            return ctx.pdf.getPage(1).then(function (first) {
                return { pdfjs: ctx.pdfjs, pdf: ctx.pdf, first: first };
            });
        }).then(function (ctx) {
            if (!ctx || stale()) return;
            var pdfjs = ctx.pdfjs, pdf = ctx.pdf;
            pdfWidth = host.clientWidth;
            var cssW = Math.max(200, Math.round((pdfWidth - 16) * pdfZoom));
            var base = ctx.first.getViewport({ scale: 1 });
            host.innerHTML = '';

            function drawPage(holder, n) {
                pdf.getPage(n).then(function (page) {
                    if (stale()) return;
                    var vp = page.getViewport({ scale: cssW / page.getViewport({ scale: 1 }).width });
                    var ratio = Math.min(window.devicePixelRatio || 1, 2);
                    var canvas = document.createElement('canvas');
                    canvas.width = Math.floor(vp.width * ratio);
                    canvas.height = Math.floor(vp.height * ratio);
                    holder.style.height = vp.height + 'px';
                    holder.innerHTML = '';
                    holder.appendChild(canvas);
                    page.render({
                        canvasContext: canvas.getContext('2d'),
                        viewport: vp,
                        transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null
                    });
                    // Selectable text over the image (scans have none).
                    page.getTextContent().then(function (text) {
                        if (stale() || !text.items.length || !pdfjs.renderTextLayer) return;
                        var layer = el('div', 'kop-dv-textlayer');
                        layer.style.setProperty('--scale-factor', vp.scale);
                        holder.appendChild(layer);
                        pdfjs.renderTextLayer({ textContentSource: text, container: layer, viewport: vp });
                    });
                });
            }

            var seen = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
                entries.forEach(function (en) {
                    if (!en.isIntersecting) return;
                    seen.unobserve(en.target);
                    drawPage(en.target, parseInt(en.target.getAttribute('data-page'), 10));
                });
            }, { root: host, rootMargin: '800px 0px' }) : null;

            for (var n = 1; n <= pdf.numPages; n++) {
                var holder = el('div', 'kop-dv-page');
                holder.setAttribute('data-page', n);
                holder.style.width = cssW + 'px';
                holder.style.height = Math.round(cssW * base.height / base.width) + 'px';
                host.appendChild(holder);
                if (seen) seen.observe(holder); else drawPage(holder, n);
            }
            if (keepScroll) host.scrollTop = keepScroll * host.scrollHeight;
        }).catch(function () {
            if (stale()) return;
            // pdf.js blocked or the file would not parse: show the cover image
            // and a plain way to open the document.
            host.innerHTML =
                (doc.thumb_url ? '<img class="kop-dv-cover" src="' + esc(doc.thumb_url) + '" alt="">' : '') +
                '<div class="kop-dv-empty">This document could not be shown here. ' +
                '<a href="' + esc(doc.url) + '" target="_blank" rel="noopener">Open it in a new tab</a>.</div>';
        });
    }

    // Panel resized or zoom changed: pdf.js pages are drawn at a fixed width,
    // so redraw them.
    function refitPdf(force) {
        var host = els.preview && els.preview.querySelector('.kop-dv-pages');
        if (!host || !currentDoc) return;
        if (force !== true && Math.abs(host.clientWidth - pdfWidth) < 24) return;
        var at = host.scrollHeight ? host.scrollTop / host.scrollHeight : 0;
        stopPdf(true);
        renderPdf(currentDoc, host, at);
    }

    function showDoc(doc) {
        var url = doc.url || '';
        var kind = kindOf(doc);
        var drawPdf = kind === 'pdf' && (!hasNativePdf() || preferDraw());

        stopPdf();
        currentDoc = doc;

        var body;
        if (drawPdf) {
            body = '<div class="kop-dv-pages"></div>';
        } else if (kind === 'pdf') {
            body = '<iframe class="kop-dv-frame" src="' + esc(url) + '" title="' + esc(doc.title || 'PDF') + '"></iframe>';
        } else if (kind === 'img') {
            body = '<div class="kop-dv-imgwrap"><img src="' + esc(url) + '" alt="' + esc(doc.title || '') + '"></div>';
        } else if (kind === 'vid') {
            body = '<video class="kop-dv-frame" controls src="' + esc(url) + '"></video>';
        } else if (kind === 'aud') {
            body = '<audio controls src="' + esc(url) + '" style="width:100%;margin-top:12px;"></audio>';
        } else {
            body = '<div class="kop-dv-empty">No inline preview for this file type.</div>';
        }

        var tools = '';
        if (drawPdf) {
            tools += '<button type="button" class="kop-dv-tool" data-dv="out" aria-label="Zoom out">-</button>' +
                '<button type="button" class="kop-dv-tool" data-dv="in" aria-label="Zoom in">+</button>';
        }
        if (kind === 'pdf' && hasNativePdf()) {
            tools += '<button type="button" class="kop-dv-tool" data-dv="mode">' +
                (drawPdf ? 'Browser viewer' : 'Blank? Page view') + '</button>';
        }

        els.preview.innerHTML =
            '<div class="kop-dv-preview-bar">' +
                '<span class="kop-dv-preview-title">' + esc(doc.title || url) + '</span>' +
                '<span class="kop-dv-tools">' + tools + '</span>' +
                '<a class="kop-dv-open" href="' + esc(url) + '" target="_blank" rel="noopener">Open in new tab</a>' +
            '</div>' + body;

        // Small screens: give the preview the space the list was using.
        if (!isDesktop()) {
            setListCollapsed(true, doc.title || '');
        }

        // After the list collapses, so the pages are sized to the final width.
        if (drawPdf) {
            renderPdf(doc, els.preview.querySelector('.kop-dv-pages'));
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
