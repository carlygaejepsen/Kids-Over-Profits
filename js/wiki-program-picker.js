/**
 * Wiki Program Picker
 * --------------------
 * A self-contained browser popup that ties a wiki entry to a program index
 * entry (facilities_master) by its unique_name (the program's unique ID).
 *
 * It can:
 *   - search existing programs (api/facility-search.php),
 *   - create a new program stub when none matches (api/facility-picker.php),
 *   - capture the FileBird document-library folder ID number for the program.
 *
 * Usage:
 *   const result = await window.KOPProgramPicker.open({
 *       searchUrl, pickerApi,          // endpoints (fall back to theme defaults)
 *       name, organization, cityState, // prefill for search + stub creation
 *       programType, yearsActive,
 *       current: { uniqueName, id, documentFolderId } // already-linked program
 *   });
 *   // result === null  → user cancelled
 *   // result === { uniqueName, id, documentFolderId }
 */
(function () {
    'use strict';

    var THEME_API = '/wp-content/themes/child/api/';

    function el(tag, className, html) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (html != null) node.innerHTML = html;
        return node;
    }

    function escapeHtml(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function debounce(fn, ms) {
        var t;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, ms);
        };
    }

    function open(opts) {
        opts = opts || {};
        var searchUrl = opts.searchUrl || (THEME_API + 'facility-search.php');
        var pickerApi = opts.pickerApi || (THEME_API + 'facility-picker.php');
        var foldersUrl = opts.foldersUrl || '/wp-json/kop/v1/folders';
        var current = opts.current || {};

        return new Promise(function (resolve) {
            // ---- selection state ----
            var selected = {
                uniqueName: current.uniqueName || null,
                id: current.id || null,
                documentFolderId: current.documentFolderId || null
            };

            // ---- overlay + dialog ----
            var overlay = el('div', 'kop-pp-overlay');
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');

            var dialog = el('div', 'kop-pp-dialog');
            overlay.appendChild(dialog);

            dialog.appendChild(el('div', 'kop-pp-header',
                '<h3 class="kop-pp-title">Link to a Program Index Entry</h3>' +
                '<button type="button" class="kop-pp-close" aria-label="Close">&times;</button>'));

            var intro = el('p', 'kop-pp-intro',
                'Every wiki entry must point to a program in the index so it has a matching unique ID. ' +
                'Search for the program below, or create a new one if it does not exist yet.');
            dialog.appendChild(intro);

            // Search field
            var searchWrap = el('div', 'kop-pp-search');
            var searchInput = el('input', 'kop-pp-search-input');
            searchInput.type = 'search';
            searchInput.placeholder = 'Search programs by name…';
            searchInput.value = opts.name || '';
            searchWrap.appendChild(searchInput);
            dialog.appendChild(searchWrap);

            // Results
            var results = el('div', 'kop-pp-results');
            dialog.appendChild(results);

            // "No match → create" area
            var createToggle = el('button', 'kop-pp-create-toggle',
                '➕ Not in the list? Create a new program entry');
            createToggle.type = 'button';
            dialog.appendChild(createToggle);

            var createPanel = el('div', 'kop-pp-create-panel');
            createPanel.style.display = 'none';
            createPanel.innerHTML =
                '<div class="kop-pp-field"><label>Program name *</label>' +
                '<input type="text" class="kop-pp-c-name" value="' + escapeHtml(opts.name || '') + '"></div>' +
                '<div class="kop-pp-field"><label>Organization / operator</label>' +
                '<input type="text" class="kop-pp-c-org" value="' + escapeHtml(opts.organization || '') + '"></div>' +
                '<div class="kop-pp-field"><label>Location (City, ST)</label>' +
                '<input type="text" class="kop-pp-c-loc" value="' + escapeHtml(opts.cityState || '') + '"></div>' +
                '<button type="button" class="kop-pp-create-btn">Create program &amp; select</button>' +
                '<div class="kop-pp-create-status"></div>';
            dialog.appendChild(createPanel);

            // Document library folder id
            var docWrap = el('div', 'kop-pp-doc');
            docWrap.innerHTML =
                '<label class="kop-pp-doc-label">📂 Document library folder ' +
                '<span class="kop-pp-doc-hint">(FileBird folder — optional)</span></label>' +
                '<div class="kop-pp-doc-row">' +
                '<input type="number" min="1" step="1" class="kop-pp-doc-input" ' +
                'value="' + (selected.documentFolderId ? escapeHtml(selected.documentFolderId) : '') + '" ' +
                'placeholder="folder ID">' +
                '<button type="button" class="kop-pp-doc-browse">📁 Browse folders…</button>' +
                '</div>' +
                '<div class="kop-pp-doc-chosen"></div>';
            dialog.appendChild(docWrap);
            var docInput = docWrap.querySelector('.kop-pp-doc-input');
            var docChosen = docWrap.querySelector('.kop-pp-doc-chosen');

            docWrap.querySelector('.kop-pp-doc-browse').addEventListener('click', function () {
                if (!window.KOPFolderBrowser || typeof window.KOPFolderBrowser.open !== 'function') {
                    docChosen.innerHTML = '<span class="kop-pp-error">Folder browser failed to load.</span>';
                    return;
                }
                window.KOPFolderBrowser.open({ foldersUrl: foldersUrl, currentId: docInput.value })
                    .then(function (res) {
                        if (!res) return; // cancelled
                        if (res.id === null) {
                            docInput.value = '';
                            docChosen.textContent = '';
                        } else {
                            docInput.value = res.id;
                            docChosen.innerHTML = 'Selected folder: <strong>' + escapeHtml(res.name) + '</strong> #' + escapeHtml(res.id);
                        }
                    })
                    .catch(function (err) {
                        docChosen.innerHTML = '<span class="kop-pp-error">Folder browser error: '
                            + escapeHtml(err && err.message ? err.message : 'unknown error') + '</span>';
                    });
            });

            // Footer
            var footer = el('div', 'kop-pp-footer');
            var selectedLabel = el('div', 'kop-pp-selected', renderSelectedLabel());
            var actions = el('div', 'kop-pp-actions');
            var cancelBtn = el('button', 'kop-pp-btn kop-pp-cancel', 'Cancel');
            cancelBtn.type = 'button';
            var confirmBtn = el('button', 'kop-pp-btn kop-pp-confirm', 'Use this program');
            confirmBtn.type = 'button';
            confirmBtn.disabled = !selected.uniqueName;
            actions.appendChild(cancelBtn);
            actions.appendChild(confirmBtn);
            footer.appendChild(selectedLabel);
            footer.appendChild(actions);
            dialog.appendChild(footer);

            // Feature-scoped bug reporting for the picker itself.
            if (window.KOPBugReporter && typeof window.KOPBugReporter.attach === 'function') {
                window.KOPBugReporter.attach(dialog, 'wiki-editor/program-picker', 'Program Picker');
            }

            document.body.appendChild(overlay);
            searchInput.focus();

            function renderSelectedLabel() {
                if (!selected.uniqueName) {
                    return '<span class="kop-pp-none">No program selected</span>';
                }
                return 'Selected: <strong>' + escapeHtml(selected.uniqueName) + '</strong>' +
                    (selected.id ? ' <span class="kop-pp-id">#' + escapeHtml(selected.id) + '</span>' : '');
            }

            function refreshSelectedUi() {
                selectedLabel.innerHTML = renderSelectedLabel();
                confirmBtn.disabled = !selected.uniqueName;
                // Highlight the active row
                Array.prototype.forEach.call(results.querySelectorAll('.kop-pp-result'), function (row) {
                    row.classList.toggle('is-active', row.dataset.uniqueName === selected.uniqueName);
                });
            }

            function selectProgram(uniqueName, id) {
                selected.uniqueName = uniqueName;
                selected.id = id != null ? id : null;
                refreshSelectedUi();
            }

            // ---- search ----
            function renderResults(rows) {
                results.innerHTML = '';
                if (!rows || !rows.length) {
                    results.appendChild(el('div', 'kop-pp-empty',
                        'No matching programs. Create a new one below.'));
                    return;
                }
                rows.forEach(function (row) {
                    var meta = [];
                    if (row.city) meta.push(escapeHtml(row.city));
                    if (row.state) meta.push(escapeHtml(row.state));
                    if (row.status) meta.push(escapeHtml(row.status));
                    var rowEl = el('button', 'kop-pp-result',
                        '<span class="kop-pp-result-name">' + escapeHtml(row.unique_name) + '</span>' +
                        (meta.length ? '<span class="kop-pp-result-meta">' + meta.join(' · ') + '</span>' : '') +
                        '<span class="kop-pp-result-id">#' + escapeHtml(row.id) + '</span>');
                    rowEl.type = 'button';
                    rowEl.dataset.uniqueName = row.unique_name;
                    rowEl.addEventListener('click', function () {
                        selectProgram(row.unique_name, row.id);
                    });
                    results.appendChild(rowEl);
                });
                refreshSelectedUi();
            }

            var runSearch = debounce(function () {
                var q = searchInput.value.trim();
                if (q.length < 2) {
                    results.innerHTML = '';
                    results.appendChild(el('div', 'kop-pp-empty', 'Type at least 2 characters to search.'));
                    return;
                }
                results.innerHTML = '';
                results.appendChild(el('div', 'kop-pp-loading', 'Searching…'));
                fetch(searchUrl + '?q=' + encodeURIComponent(q) + '&limit=25')
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            renderResults(data.data || []);
                        } else {
                            results.innerHTML = '';
                            results.appendChild(el('div', 'kop-pp-error', 'Search failed. Try again.'));
                        }
                    })
                    .catch(function () {
                        results.innerHTML = '';
                        results.appendChild(el('div', 'kop-pp-error', 'Network error during search.'));
                    });
            }, 250);

            searchInput.addEventListener('input', runSearch);
            if (searchInput.value.trim().length >= 2) runSearch();

            // ---- create stub ----
            createToggle.addEventListener('click', function () {
                var showing = createPanel.style.display !== 'none';
                createPanel.style.display = showing ? 'none' : 'block';
                createToggle.classList.toggle('is-open', !showing);
            });

            // Show the server's "these already exist" candidates and let the
            // user pick one instead — or, when allowed, insist on creating.
            function renderDuplicateWarning(statusEl, data, retryCreate) {
                statusEl.innerHTML = '';

                var warn = el('div', 'kop-pp-dup-warning');
                warn.appendChild(el('p', 'kop-pp-dup-title',
                    '⚠️ ' + escapeHtml(data.error || 'Similar programs already exist.') +
                    ' Please check this list before creating a new entry:'));

                var list = el('div', 'kop-pp-dup-list');
                (data.duplicates || []).forEach(function (dup) {
                    var meta = [];
                    if (dup.location) meta.push(escapeHtml(dup.location));
                    // The match may be on a former or alternate name rather
                    // than the current index name — say so.
                    var viaAltName = dup.matched_name && dup.matched_name !== dup.unique_name;
                    if (viaAltName) {
                        meta.push((dup.exact ? 'exactly matches' : 'similar to') +
                            ' its past/other name “' + escapeHtml(dup.matched_name) + '”');
                    } else if (dup.exact) {
                        meta.push('exact name match');
                    } else if (dup.similarity) {
                        meta.push(escapeHtml(dup.similarity) + '% similar');
                    }
                    var row = el('button', 'kop-pp-result kop-pp-dup-result',
                        '<span class="kop-pp-result-name">' + escapeHtml(dup.unique_name) + '</span>' +
                        (meta.length ? '<span class="kop-pp-result-meta">' + meta.join(' · ') + '</span>' : '') +
                        '<span class="kop-pp-result-id">#' + escapeHtml(dup.id) + '</span>');
                    row.type = 'button';
                    row.dataset.uniqueName = dup.unique_name;
                    row.addEventListener('click', function () {
                        selectProgram(dup.unique_name, dup.id);
                        statusEl.innerHTML = '<span class="kop-pp-ok">Selected the existing entry “' +
                            escapeHtml(dup.unique_name) + '”.</span>';
                        createPanel.style.display = 'none';
                        createToggle.classList.remove('is-open');
                    });
                    list.appendChild(row);
                });
                warn.appendChild(list);

                if (data.canOverride) {
                    var anyway = el('button', 'kop-pp-dup-anyway',
                        'None of these match — create a new entry anyway');
                    anyway.type = 'button';
                    anyway.addEventListener('click', function () { retryCreate(true); });
                    warn.appendChild(anyway);
                } else {
                    warn.appendChild(el('p', 'kop-pp-dup-note',
                        'This name exactly matches an existing program (possibly under a past or ' +
                        'alternate name), so a duplicate can’t be created. If it really is a ' +
                        'different program, select the closest entry and mention it in your ' +
                        'submission notes.'));
                }

                statusEl.appendChild(warn);
            }

            function submitCreate(createAnyway) {
                var nameEl = createPanel.querySelector('.kop-pp-c-name');
                var statusEl = createPanel.querySelector('.kop-pp-create-status');
                var name = nameEl.value.trim();
                if (!name) {
                    statusEl.innerHTML = '<span class="kop-pp-error">Program name is required.</span>';
                    return;
                }
                var btn = createPanel.querySelector('.kop-pp-create-btn');
                btn.disabled = true;
                statusEl.innerHTML = '<span class="kop-pp-loading">' +
                    (createAnyway ? 'Creating anyway…' : 'Checking for existing entries…') + '</span>';

                var folderVal = parseInt(docInput.value, 10);
                fetch(pickerApi, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'create_stub',
                        name: name,
                        organization: createPanel.querySelector('.kop-pp-c-org').value.trim(),
                        city_state: createPanel.querySelector('.kop-pp-c-loc').value.trim(),
                        document_folder_id: folderVal > 0 ? folderVal : null,
                        create_anyway: !!createAnyway
                    })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        btn.disabled = false;
                        if (data && data.success) {
                            statusEl.innerHTML = '<span class="kop-pp-ok">Created “' +
                                escapeHtml(data.unique_name) + '”.</span>';
                            if (data.document_folder_id) {
                                selected.documentFolderId = data.document_folder_id;
                            }
                            selectProgram(data.unique_name, data.id);
                            createPanel.style.display = 'none';
                            createToggle.classList.remove('is-open');
                        } else if (data && data.code === 'possible_duplicate') {
                            renderDuplicateWarning(statusEl, data, submitCreate);
                        } else {
                            statusEl.innerHTML = '<span class="kop-pp-error">' +
                                escapeHtml((data && data.error) || 'Could not create program.') + '</span>';
                        }
                    })
                    .catch(function () {
                        btn.disabled = false;
                        statusEl.innerHTML = '<span class="kop-pp-error">Network error creating program.</span>';
                    });
            }

            createPanel.querySelector('.kop-pp-create-btn').addEventListener('click', function () {
                submitCreate(false);
            });

            // ---- close / confirm ----
            function close(result) {
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                document.removeEventListener('keydown', onKey);
                resolve(result);
            }
            function onKey(e) {
                if (e.key === 'Escape') close(null);
            }
            document.addEventListener('keydown', onKey);

            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) close(null);
            });
            dialog.querySelector('.kop-pp-close').addEventListener('click', function () { close(null); });
            cancelBtn.addEventListener('click', function () { close(null); });

            confirmBtn.addEventListener('click', function () {
                if (!selected.uniqueName) return;
                var folderVal = parseInt(docInput.value, 10);
                selected.documentFolderId = folderVal > 0 ? folderVal : null;
                close({
                    uniqueName: selected.uniqueName,
                    id: selected.id,
                    documentFolderId: selected.documentFolderId
                });
            });
        });
    }

    window.KOPProgramPicker = { open: open };
})();
