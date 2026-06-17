/**
 * FileBird Folder Browser
 * -----------------------
 * A reusable popup for picking an existing FileBird folder instead of typing
 * its ID number by hand. Used by the wiki program picker and the Data Manager.
 *
 * Usage:
 *   const result = await window.KOPFolderBrowser.open({
 *       foldersUrl,   // REST endpoint returning [{id, name, parent}, ...]
 *       currentId     // optionally highlight the currently-selected folder
 *   });
 *   // result === null              → cancelled
 *   // result === { id: null }      → "no folder" chosen (clear)
 *   // result === { id, name }      → folder picked
 *
 * Source endpoint: /wp-json/kop/v1/folders (flat list with parent ids).
 */
(function () {
    'use strict';

    var folderCache = null;          // cached flat folder list (across opens)
    var cachedFrom = null;           // url the cache was built from

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
    function debounce(fn, ms) {
        var t;
        return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); };
    }

    function loadFolders(url) {
        if (folderCache && cachedFrom === url) return Promise.resolve(folderCache);
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                folderCache = Array.isArray(data) ? data : [];
                cachedFrom = url;
                return folderCache;
            })
            .catch(function () { folderCache = []; cachedFrom = url; return folderCache; });
    }

    // Flatten the parent/child relationship into a depth-tagged list and build a
    // readable "Parent / Child" path string for each folder (used when searching).
    function buildHierarchy(folders) {
        var byId = {};
        folders.forEach(function (f) { byId[String(f.id)] = { id: f.id, name: f.name, parent: String(f.parent || 0), children: [] }; });
        var roots = [];
        Object.keys(byId).forEach(function (k) {
            var f = byId[k];
            if (f.parent === '0' || !byId[f.parent]) roots.push(f);
            else byId[f.parent].children.push(f);
        });
        var flat = [];
        function walk(nodes, depth, prefix) {
            nodes.slice().sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); })
                .forEach(function (node) {
                    var path = prefix ? prefix + ' / ' + node.name : node.name;
                    flat.push({ id: node.id, name: node.name, depth: depth, path: path });
                    if (node.children.length) walk(node.children, depth + 1, path);
                });
        }
        walk(roots, 0, '');
        return flat;
    }

    function open(opts) {
        opts = opts || {};
        var foldersUrl = opts.foldersUrl;
        var currentId = opts.currentId != null ? String(opts.currentId) : null;

        return new Promise(function (resolve) {
            var overlay = el('div', 'kop-fb-overlay');
            var dialog = el('div', 'kop-fb-dialog');
            overlay.appendChild(dialog);

            dialog.appendChild(el('div', 'kop-fb-header',
                '<h3 class="kop-fb-title">Choose a Document Folder</h3>' +
                '<button type="button" class="kop-fb-close" aria-label="Close">&times;</button>'));

            var searchInput = el('input', 'kop-fb-search');
            searchInput.type = 'search';
            searchInput.placeholder = 'Filter folders by name…';
            dialog.appendChild(searchInput);

            var list = el('div', 'kop-fb-list', '<div class="kop-fb-loading">Loading folders…</div>');
            dialog.appendChild(list);

            var footer = el('div', 'kop-fb-footer');
            var clearBtn = el('button', 'kop-fb-btn kop-fb-clear', 'Use no folder');
            clearBtn.type = 'button';
            var cancelBtn = el('button', 'kop-fb-btn kop-fb-cancel', 'Cancel');
            cancelBtn.type = 'button';
            footer.appendChild(clearBtn);
            footer.appendChild(cancelBtn);
            dialog.appendChild(footer);

            document.body.appendChild(overlay);
            searchInput.focus();

            var hierarchy = [];

            function render(filter) {
                var f = (filter || '').toLowerCase().trim();
                list.innerHTML = '';
                if (!hierarchy.length) {
                    list.innerHTML = '<div class="kop-fb-empty">No FileBird folders found. ' +
                        'Check that FileBird is installed and the folders endpoint is reachable.</div>';
                    return;
                }
                var rows = hierarchy;
                var searching = f.length > 0;
                if (searching) {
                    rows = hierarchy.filter(function (r) { return r.path.toLowerCase().indexOf(f) !== -1; });
                }
                if (!rows.length) {
                    list.innerHTML = '<div class="kop-fb-empty">No folders match “' + esc(filter) + '”.</div>';
                    return;
                }
                rows.forEach(function (r) {
                    var row = el('button', 'kop-fb-row');
                    row.type = 'button';
                    // When searching show the full path; otherwise indent by depth.
                    var label = searching
                        ? esc(r.path)
                        : '<span class="kop-fb-indent">' + '&nbsp;&nbsp;'.repeat(r.depth) + '</span>📁 ' + esc(r.name);
                    row.innerHTML = '<span class="kop-fb-name">' + label + '</span>' +
                        '<span class="kop-fb-id">#' + esc(r.id) + '</span>';
                    if (currentId !== null && String(r.id) === currentId) {
                        row.classList.add('is-current');
                    }
                    row.addEventListener('click', function () { close({ id: r.id, name: r.name }); });
                    list.appendChild(row);
                });
            }

            loadFolders(foldersUrl).then(function (folders) {
                hierarchy = buildHierarchy(folders);
                render('');
            });

            var onSearch = debounce(function () { render(searchInput.value); }, 200);
            searchInput.addEventListener('input', onSearch);

            function close(result) {
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                document.removeEventListener('keydown', onKey);
                resolve(result);
            }
            function onKey(e) { if (e.key === 'Escape') close(null); }
            document.addEventListener('keydown', onKey);

            overlay.addEventListener('click', function (e) { if (e.target === overlay) close(null); });
            dialog.querySelector('.kop-fb-close').addEventListener('click', function () { close(null); });
            cancelBtn.addEventListener('click', function () { close(null); });
            clearBtn.addEventListener('click', function () { close({ id: null, name: '' }); });
        });
    }

    window.KOPFolderBrowser = { open: open };
})();
