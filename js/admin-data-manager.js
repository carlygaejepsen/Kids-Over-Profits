/**
 * Admin Data Manager
 * ------------------
 * Drives the "Data Manager" admin page: a cross-table listing of every master
 * record with per-row actions. Reuses existing endpoints where they exist:
 *
 *   data-manager.php    – list, get_facilities, get_wiki_links,
 *                         move_category, reassign_facility
 *   save-master.php     – rename, delete (cascades references)
 *   facility-picker.php – set_doc_folder
 *   link-wiki-facility.php – confirm, unlink, link (repoint)
 *   facility-search.php – destination typeahead
 */
(function () {
    'use strict';

    var cfg = window.dmConfig || {};
    var API = {
        manager: cfg.dataManagerApi || '/wp-content/themes/child/api/data-manager.php',
        saveMaster: cfg.saveMasterApi || '/wp-content/themes/child/api/save-master.php',
        picker: cfg.facilityPickerApi || '/wp-content/themes/child/api/facility-picker.php',
        linkWiki: cfg.linkWikiApi || '/wp-content/themes/child/api/link-wiki-facility.php',
        search: cfg.facilitySearchApi || '/wp-content/themes/child/api/facility-search.php',
        folders: cfg.foldersUrl || '/wp-json/kop/v1/folders',
        foldersAdmin: cfg.foldersAdminApi || '/wp-content/themes/child/api/filebird-folders.php'
    };

    var CATEGORY_LABELS = {
        companies: 'Company',
        referrers: 'Referrer',
        transporters: 'Transporter',
        locations: 'Location'
    };

    var state = { q: '', category: '', limit: 50, offset: 0, total: 0, items: [] };

    // ---- small helpers ----
    function $(id) { return document.getElementById(id); }
    function esc(t) {
        return String(t == null ? '' : t)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function el(tag, cls, html) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (html != null) n.innerHTML = html;
        return n;
    }
    function debounce(fn, ms) {
        var t;
        return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); };
    }
    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, data: j }; }); });
    }
    function getJson(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    // ---- modal ----
    function openModal(title, bodyNode) {
        var modal = $('dmModal');
        $('dmModalTitle').textContent = title;
        var body = $('dmModalBody');
        body.innerHTML = '';
        body.appendChild(bodyNode);
        $('dmModalStatus').innerHTML = '';
        modal.style.display = 'flex';
        modal.setAttribute('aria-hidden', 'false');
    }
    function closeModal() {
        var modal = $('dmModal');
        modal.style.display = 'none';
        modal.setAttribute('aria-hidden', 'true');
    }
    function setStatus(html, kind) {
        $('dmModalStatus').innerHTML = '<span class="dm-status-' + (kind || 'info') + '">' + html + '</span>';
    }

    // ---- destination typeahead (shared by reassign + wiki repoint) ----
    // onPick(uniqueName, id). Returns the wrapper element.
    function buildProgramSearch(onPick, placeholder) {
        var wrap = el('div', 'dm-progsearch');
        var input = el('input', 'dm-progsearch-input');
        input.type = 'search';
        input.placeholder = placeholder || 'Search destination program…';
        var results = el('div', 'dm-progsearch-results');
        wrap.appendChild(input);
        wrap.appendChild(results);

        var run = debounce(function () {
            var q = input.value.trim();
            if (q.length < 2) { results.innerHTML = ''; return; }
            results.innerHTML = '<div class="dm-muted">Searching…</div>';
            getJson(API.search + '?q=' + encodeURIComponent(q) + '&limit=20')
                .then(function (d) {
                    results.innerHTML = '';
                    if (!d || !d.success || !d.data || !d.data.length) {
                        results.innerHTML = '<div class="dm-muted">No matches.</div>';
                        return;
                    }
                    d.data.forEach(function (row) {
                        var meta = [row.city, row.state, row.status].filter(Boolean).join(' · ');
                        var b = el('button', 'dm-progsearch-row',
                            '<strong>' + esc(row.unique_name) + '</strong>' +
                            (meta ? ' <span class="dm-muted">' + esc(meta) + '</span>' : '') +
                            ' <span class="dm-id">#' + esc(row.id) + '</span>');
                        b.type = 'button';
                        b.addEventListener('click', function () { onPick(row.unique_name, row.id, b); });
                        results.appendChild(b);
                    });
                })
                .catch(function () { results.innerHTML = '<div class="dm-error">Search failed.</div>'; });
        }, 250);
        input.addEventListener('input', run);
        return wrap;
    }

    // ---- table render ----
    function badge(category) {
        return '<span class="dm-cat dm-cat-' + esc(category) + '">' +
            esc(CATEGORY_LABELS[category] || category) + '</span>';
    }
    function wikiBadge(w) {
        if (!w || !w.total) return '<span class="dm-muted">—</span>';
        var parts = [];
        if (w.confirmed) parts.push('<span class="dm-wiki-confirmed">' + w.confirmed + ' ✓</span>');
        if (w.suggested) parts.push('<span class="dm-wiki-suggested">' + w.suggested + ' ?</span>');
        return parts.join(' ') || ('<span>' + w.total + '</span>');
    }

    function renderTable() {
        var wrap = $('dmTableWrap');
        if (!state.items.length) {
            wrap.innerHTML = '<div class="kop-dm-empty">No records match.</div>';
            return;
        }
        var table = el('table', 'kop-dm-table');
        table.innerHTML =
            '<thead><tr>' +
            '<th>Name</th><th>Category</th><th>ID #</th><th>Facilities</th>' +
            '<th>Doc Folder</th><th>Wiki Links</th><th>Actions</th>' +
            '</tr></thead>';
        var tbody = el('tbody');

        state.items.forEach(function (it) {
            var tr = el('tr');
            var facCell = it.facility_count > 0
                ? '<button type="button" class="dm-fac-toggle" aria-expanded="false">▶ ' + esc(it.facility_count) + '</button>'
                : '<span class="dm-muted">0</span>';
            tr.innerHTML =
                '<td class="dm-name">' + esc(it.display_name || it.unique_name) +
                    (it.is_stub ? ' <span class="dm-stub">stub</span>' : '') +
                    '<div class="dm-uniquename">' + esc(it.unique_name) + '</div></td>' +
                '<td>' + badge(it.category) + '</td>' +
                '<td class="dm-mono">#' + esc(it.id) + '</td>' +
                '<td class="dm-center dm-fac-cell">' + facCell + '</td>' +
                '<td class="dm-center">' + (it.document_folder_id ? '📂 ' + esc(it.document_folder_id) : '<span class="dm-muted">—</span>') + '</td>' +
                '<td class="dm-center">' + wikiBadge(it.wiki_links) + '</td>';

            var actions = el('td', 'dm-actions');
            var actionDefs = [];
            // Auto-link only makes sense for actual programs, not location aggregates.
            if (it.category !== 'locations') actionDefs.push(['✨ Auto', 'auto']);
            actionDefs.push(
                ['Rename', 'rename'],
                ['Doc ID', 'docfolder'],
                ['Category', 'category'],
                ['Reassign', 'reassign'],
                ['Wiki', 'wiki'],
                ['Delete', 'delete']
            );
            actionDefs.forEach(function (a) {
                var btn = el('button', 'dm-act dm-act-' + a[1].replace(/\W/g, ''), a[0]);
                btn.type = 'button';
                btn.addEventListener('click', function () { handleAction(a[1], it); });
                actions.appendChild(btn);
            });
            tr.appendChild(actions);
            tbody.appendChild(tr);

            // Expandable sub-row holding this record's nested facilities.
            var subTr = el('tr', 'dm-fac-subrow');
            subTr.style.display = 'none';
            var subTd = el('td', 'dm-fac-subcell');
            subTd.colSpan = 7;
            subTr.appendChild(subTd);
            tbody.appendChild(subTr);

            if (it.facility_count > 0) {
                var toggle = tr.querySelector('.dm-fac-toggle');
                toggle.addEventListener('click', function () {
                    var open = subTr.style.display !== 'none';
                    if (open) {
                        subTr.style.display = 'none';
                        toggle.textContent = '▶ ' + it.facility_count;
                        toggle.setAttribute('aria-expanded', 'false');
                    } else {
                        subTr.style.display = '';
                        toggle.textContent = '▼ ' + it.facility_count;
                        toggle.setAttribute('aria-expanded', 'true');
                        loadFacilitySubrows(it, subTd);
                    }
                });
            }
        });

        table.appendChild(tbody);
        wrap.innerHTML = '';
        wrap.appendChild(table);
    }

    // ---- facility-level (nested) management ----
    function loadFacilitySubrows(operator, container) {
        container.innerHTML = '<div class="dm-muted dm-fac-loading">Loading facilities…</div>';
        getJson(API.manager + '?action=get_facilities&unique_name=' + encodeURIComponent(operator.unique_name))
            .then(function (d) {
                if (!d || !d.success) { container.innerHTML = '<div class="dm-error">Failed to load facilities.</div>'; return; }
                if (!d.facilities || !d.facilities.length) {
                    container.innerHTML = '<div class="dm-muted">No facilities in this record.</div>';
                    return;
                }
                container.innerHTML = '';
                var head = el('div', 'dm-fac-subhead', 'Facilities in <strong>' + esc(operator.unique_name) + '</strong>');
                container.appendChild(head);
                d.facilities.forEach(function (f) {
                    var row = el('div', 'dm-fac-item');
                    row.innerHTML =
                        '<span class="dm-fac-item-name">🏫 ' + esc(f.name) +
                        (f.location ? ' <span class="dm-muted">(' + esc(f.location) + ')</span>' : '') +
                        (f.facility_id ? ' <span class="dm-id">id ' + esc(f.facility_id) + '</span>' : '') +
                        (f.document_folder_id ? ' <span class="dm-fac-doc">📂 ' + esc(f.document_folder_id) + '</span>' : '') +
                        '</span>';
                    var acts = el('span', 'dm-fac-item-acts');
                    [
                        ['Rename', function () { facilityRename(operator, f, container); }],
                        ['Doc ID', function () { facilityDocFolder(operator, f, container); }],
                        ['Move', function () { facilityReassign(operator, f, container); }],
                        ['Delete', function () { facilityDelete(operator, f, container); }]
                    ].forEach(function (a) {
                        var b = el('button', 'dm-act' + (a[0] === 'Delete' ? ' dm-act-delete' : ''), a[0]);
                        b.type = 'button';
                        b.addEventListener('click', a[1]);
                        acts.appendChild(b);
                    });
                    row.appendChild(acts);
                    container.appendChild(row);
                });
            })
            .catch(function () { container.innerHTML = '<div class="dm-error">Network error.</div>'; });
    }

    // Common payload bits to address one facility within its operator.
    function facilityRef(operator, f) {
        return { operator_unique_name: operator.unique_name, facility_id: f.facility_id, facility_index: f.index };
    }
    function facilityPost(payload, operator, container) {
        return postJson(API.manager, payload).then(function (res) {
            if (res.data && res.data.success) { loadFacilitySubrows(operator, container); load(); return true; }
            alert((res.data && res.data.error) || 'Action failed.');
            return false;
        }).catch(function () { alert('Network error.'); return false; });
    }

    function facilityRename(operator, f, container) {
        var name = prompt('Rename facility:', f.name);
        if (name && name.trim() && name.trim() !== f.name) {
            var p = facilityRef(operator, f); p.action = 'rename_facility'; p.new_name = name.trim();
            facilityPost(p, operator, container);
        }
    }

    function facilityDelete(operator, f, container) {
        if (confirm('Remove facility “' + f.name + '” from ' + operator.unique_name + '? This cannot be undone.')) {
            var p = facilityRef(operator, f); p.action = 'delete_facility';
            facilityPost(p, operator, container);
        }
    }

    function facilityDocFolder(operator, f, container) {
        var body = el('div', 'dm-form');
        body.innerHTML =
            '<p class="dm-muted">Document library folder for facility “' + esc(f.name) + '”. Browse to pick one, or clear.</p>' +
            '<div class="dm-doc-row">' +
            '<input type="number" min="1" step="1" class="dm-doc-input" placeholder="folder ID" value="' + (f.document_folder_id || '') + '">' +
            '<button type="button" class="kop-dm-btn dm-doc-browse">📁 Browse folders…</button></div>' +
            '<div class="dm-doc-chosen dm-muted"></div>' +
            '<div class="dm-form-actions">' +
            '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-cancel">Cancel</button>' +
            '<button type="button" class="kop-dm-btn dm-confirm">Save</button></div>';
        openModal('Facility document folder', body);
        var docInput = body.querySelector('.dm-doc-input');
        var docChosen = body.querySelector('.dm-doc-chosen');
        body.querySelector('.dm-doc-browse').addEventListener('click', function () {
            if (!window.KOPFolderBrowser) { docChosen.innerHTML = '<span class="dm-error">Folder browser failed to load.</span>'; return; }
            window.KOPFolderBrowser.open({ foldersUrl: API.folders, currentId: docInput.value }).then(function (res) {
                if (!res) return;
                if (res.id === null) { docInput.value = ''; docChosen.textContent = ''; }
                else { docInput.value = res.id; docChosen.classList.remove('dm-muted'); docChosen.innerHTML = 'Selected: <strong>' + esc(res.name) + '</strong> #' + esc(res.id); }
            });
        });
        body.querySelector('.dm-cancel').addEventListener('click', closeModal);
        body.querySelector('.dm-confirm').addEventListener('click', function () {
            var val = docInput.value.trim();
            var p = facilityRef(operator, f);
            p.action = 'set_facility_doc_folder';
            p.document_folder_id = val === '' ? null : parseInt(val, 10);
            setStatus('Saving…');
            postJson(API.manager, p).then(function (res) {
                if (res.data && res.data.success) {
                    setStatus('Saved.', 'ok');
                    setTimeout(function () { closeModal(); loadFacilitySubrows(operator, container); load(); }, 600);
                } else { setStatus(esc((res.data && res.data.error) || 'Save failed.'), 'error'); }
            }).catch(function () { setStatus('Network error.', 'error'); });
        });
    }

    function facilityReassign(operator, f, container) {
        var body = el('div', 'dm-form');
        body.innerHTML =
            '<p class="dm-muted">Move facility “' + esc(f.name) + '” into a different program. Its ID stays the same.</p>' +
            '<div class="dm-dest"></div><div class="dm-dest-chosen dm-muted">No destination selected.</div>' +
            '<div class="dm-form-actions">' +
            '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-cancel">Cancel</button>' +
            '<button type="button" class="kop-dm-btn dm-confirm" disabled>Move facility</button></div>';
        openModal('Move facility from: ' + operator.unique_name, body);
        var chosenEl = body.querySelector('.dm-dest-chosen');
        var confirmBtn = body.querySelector('.dm-confirm');
        var dest = null;
        var search = buildProgramSearch(function (uniqueName, id) {
            if (uniqueName === operator.unique_name) { chosenEl.innerHTML = '<span class="dm-error">Cannot move to the same record.</span>'; return; }
            dest = uniqueName;
            chosenEl.classList.remove('dm-muted');
            chosenEl.innerHTML = 'Destination: <strong>' + esc(uniqueName) + '</strong> #' + esc(id);
            confirmBtn.disabled = false;
        }, 'Search destination program…');
        body.querySelector('.dm-dest').appendChild(search);
        body.querySelector('.dm-cancel').addEventListener('click', closeModal);
        confirmBtn.addEventListener('click', function () {
            setStatus('Moving…');
            postJson(API.manager, {
                action: 'reassign_facility',
                from_unique_name: operator.unique_name,
                to_unique_name: dest,
                facility_id: f.facility_id,
                facility_index: f.index
            }).then(function (res) {
                if (res.data && res.data.success) {
                    setStatus(esc(res.data.message || 'Moved.'), 'ok');
                    setTimeout(function () { closeModal(); loadFacilitySubrows(operator, container); load(); }, 800);
                } else { setStatus(esc((res.data && res.data.error) || 'Move failed.'), 'error'); }
            }).catch(function () { setStatus('Network error.', 'error'); });
        });
    }

    function renderPagination() {
        var from = state.total ? state.offset + 1 : 0;
        var to = Math.min(state.offset + state.limit, state.total);
        $('dmPageInfo').textContent = from + '–' + to + ' of ' + state.total;
        $('dmCount').textContent = state.total + ' record' + (state.total === 1 ? '' : 's');
        $('dmPrev').disabled = state.offset <= 0;
        $('dmNext').disabled = state.offset + state.limit >= state.total;
    }

    // ---- load ----
    function load() {
        $('dmTableWrap').innerHTML = '<div class="kop-dm-loading">Loading records…</div>';
        var url = API.manager + '?action=list' +
            '&q=' + encodeURIComponent(state.q) +
            '&category=' + encodeURIComponent(state.category) +
            '&limit=' + state.limit + '&offset=' + state.offset;
        getJson(url)
            .then(function (d) {
                if (!d || !d.success) {
                    $('dmTableWrap').innerHTML = '<div class="dm-error">' + esc((d && d.error) || 'Failed to load.') + '</div>';
                    return;
                }
                state.items = d.data || [];
                state.total = d.total || 0;
                renderTable();
                renderPagination();
            })
            .catch(function () {
                $('dmTableWrap').innerHTML = '<div class="dm-error">Network error loading records.</div>';
            });
    }

    // ---- actions ----
    function handleAction(kind, item) {
        if (kind === 'auto') return actionAuto(item);
        if (kind === 'rename') return actionRename(item);
        if (kind === 'docfolder') return actionDocFolder(item);
        if (kind === 'category') return actionCategory(item);
        if (kind === 'reassign') return actionReassign(item);
        if (kind === 'wiki') return actionWiki(item);
        if (kind === 'delete') return actionDelete(item);
    }

    // One-click: auto-assign the best strong FileBird folder + wiki link, then
    // show what happened so you can correct anything wrong. Never overwrites
    // existing values; wiki links are applied as 'suggested' for confirmation.
    function actionAuto(item) {
        var body = el('div', 'dm-form');
        body.innerHTML = '<p class="dm-muted">Finding strong matches for <strong>' + esc(item.unique_name) + '</strong>…</p>';
        openModal('Auto-link: ' + item.unique_name, body);
        postJson(API.manager, { action: 'auto_apply', unique_name: item.unique_name })
            .then(function (res) {
                if (!res.data || !res.data.success) {
                    body.innerHTML = '<p class="dm-error">' + esc((res.data && res.data.error) || 'Failed.') + '</p>';
                    return;
                }
                var d = res.data;
                if (d.folder) item.document_folder_id = d.folder.id; // keep in-memory item fresh
                var html = '<p class="dm-status-ok">' + esc(d.message) + '</p><ul class="dm-auto-notes">';
                if (d.folder) html += '<li>📂 Folder: <strong>' + esc(d.folder.name) + '</strong> #' + esc(d.folder.id) + '</li>';
                if (d.wiki) html += '<li>🔗 Wiki: <strong>' + esc(d.wiki.program_name) + '</strong> <span class="dm-muted">(suggested — confirm under Wiki)</span></li>';
                (d.notes || []).forEach(function (n) { html += '<li class="dm-muted">' + esc(n) + '</li>'; });
                html += '</ul><div class="dm-form-actions">' +
                    '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-fix-doc">Edit folder</button>' +
                    '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-fix-wiki">Edit wiki</button>' +
                    '<button type="button" class="kop-dm-btn dm-done">Done</button></div>';
                body.innerHTML = html;
                body.querySelector('.dm-fix-doc').addEventListener('click', function () { actionDocFolder(item); });
                body.querySelector('.dm-fix-wiki').addEventListener('click', function () { actionWiki(item); });
                body.querySelector('.dm-done').addEventListener('click', function () { closeModal(); load(); });
                load(); // refresh the table badges underneath
            })
            .catch(function () { body.innerHTML = '<p class="dm-error">Network error.</p>'; });
    }

    function actionRename(item) {
        var body = el('div', 'dm-form');
        body.innerHTML =
            '<p class="dm-muted">Renaming changes the record\'s unique ID. References in location aggregates are updated automatically.</p>' +
            '<label>New name / ID</label>' +
            '<input type="text" class="dm-rename-input" value="' + esc(item.unique_name) + '">' +
            '<div class="dm-form-actions">' +
            '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-cancel">Cancel</button>' +
            '<button type="button" class="kop-dm-btn dm-confirm">Rename</button></div>';
        openModal('Rename: ' + item.unique_name, body);
        body.querySelector('.dm-cancel').addEventListener('click', closeModal);
        body.querySelector('.dm-confirm').addEventListener('click', function () {
            var newName = body.querySelector('.dm-rename-input').value.trim();
            if (!newName || newName === item.unique_name) { setStatus('Enter a different name.', 'error'); return; }
            setStatus('Renaming…');
            postJson(API.manager, { action: 'rename', unique_name: item.unique_name, new_unique_name: newName })
                .then(function (res) {
                    if (res.data && res.data.success) {
                        setStatus(esc(res.data.message || 'Renamed.'), 'ok');
                        setTimeout(function () { closeModal(); load(); }, 900);
                    } else {
                        setStatus(esc((res.data && (res.data.error || res.data.message)) || 'Rename failed.'), 'error');
                    }
                })
                .catch(function () { setStatus('Network error.', 'error'); });
        });
    }

    function actionDocFolder(item) {
        var body = el('div', 'dm-form');
        body.innerHTML =
            '<p class="dm-muted">FileBird folder for this record\'s document library. Browse to pick one, or leave blank to clear.</p>' +
            '<label>Document library folder</label>' +
            '<div class="dm-doc-row">' +
            '<input type="number" min="1" step="1" class="dm-doc-input" placeholder="folder ID" value="' + (item.document_folder_id || '') + '">' +
            '<button type="button" class="kop-dm-btn dm-doc-browse">📁 Browse folders…</button>' +
            '</div>' +
            '<div class="dm-doc-chosen dm-muted"></div>' +
            '<div class="dm-form-actions">' +
            '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-cancel">Cancel</button>' +
            '<button type="button" class="kop-dm-btn dm-confirm">Save</button></div>';
        openModal('Document folder: ' + item.unique_name, body);
        var docInput = body.querySelector('.dm-doc-input');
        var docChosen = body.querySelector('.dm-doc-chosen');
        body.querySelector('.dm-doc-browse').addEventListener('click', function () {
            if (!window.KOPFolderBrowser || typeof window.KOPFolderBrowser.open !== 'function') {
                docChosen.innerHTML = '<span class="dm-error">Folder browser failed to load.</span>';
                return;
            }
            window.KOPFolderBrowser.open({ foldersUrl: API.folders, currentId: docInput.value })
                .then(function (res) {
                    if (!res) return;
                    if (res.id === null) { docInput.value = ''; docChosen.textContent = ''; }
                    else {
                        docInput.value = res.id;
                        docChosen.classList.remove('dm-muted');
                        docChosen.innerHTML = 'Selected: <strong>' + esc(res.name) + '</strong> #' + esc(res.id);
                    }
                });
        });
        body.querySelector('.dm-cancel').addEventListener('click', closeModal);
        body.querySelector('.dm-confirm').addEventListener('click', function () {
            var val = body.querySelector('.dm-doc-input').value.trim();
            setStatus('Saving…');
            postJson(API.picker, { action: 'set_doc_folder', unique_name: item.unique_name, document_folder_id: val === '' ? null : parseInt(val, 10) })
                .then(function (res) {
                    if (res.data && res.data.success) {
                        setStatus('Saved.', 'ok');
                        setTimeout(function () { closeModal(); load(); }, 600);
                    } else {
                        setStatus(esc((res.data && res.data.error) || 'Save failed.'), 'error');
                    }
                })
                .catch(function () { setStatus('Network error.', 'error'); });
        });
    }

    function actionCategory(item) {
        var body = el('div', 'dm-form');
        var opts = ['companies', 'referrers', 'transporters']
            .map(function (c) {
                return '<option value="' + c + '"' + (c === item.category ? ' selected' : '') + '>' +
                    esc(CATEGORY_LABELS[c]) + '</option>';
            }).join('');
        body.innerHTML =
            '<p class="dm-muted">Move this record to a different category. (Locations are auto-generated and cannot be a target.)</p>' +
            '<label>New category</label><select class="dm-cat-select">' + opts + '</select>' +
            '<div class="dm-form-actions">' +
            '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-cancel">Cancel</button>' +
            '<button type="button" class="kop-dm-btn dm-confirm">Move</button></div>';
        openModal('Move category: ' + item.unique_name, body);
        body.querySelector('.dm-cancel').addEventListener('click', closeModal);
        body.querySelector('.dm-confirm').addEventListener('click', function () {
            var target = body.querySelector('.dm-cat-select').value;
            if (target === item.category) { setStatus('Already in that category.', 'error'); return; }
            setStatus('Moving…');
            postJson(API.manager, { action: 'move_category', unique_name: item.unique_name, target_category: target })
                .then(function (res) {
                    if (res.data && res.data.success) {
                        setStatus(esc(res.data.message || 'Moved.'), 'ok');
                        setTimeout(function () { closeModal(); load(); }, 700);
                    } else {
                        setStatus(esc((res.data && res.data.error) || 'Move failed.'), 'error');
                    }
                })
                .catch(function () { setStatus('Network error.', 'error'); });
        });
    }

    function actionReassign(item) {
        var body = el('div', 'dm-form');
        body.innerHTML =
            '<p class="dm-muted">Move one of this record\'s facilities into a different program. The facility ID stays the same.</p>' +
            '<label>Facility to move</label><div class="dm-fac-list dm-muted">Loading facilities…</div>' +
            '<label>Destination program</label><div class="dm-dest"></div>' +
            '<div class="dm-dest-chosen dm-muted">No destination selected.</div>' +
            '<div class="dm-form-actions">' +
            '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-cancel">Cancel</button>' +
            '<button type="button" class="kop-dm-btn dm-confirm" disabled>Move facility</button></div>';
        openModal('Reassign facility from: ' + item.unique_name, body);

        var chosen = { facilityIndex: null, dest: null };
        var confirmBtn = body.querySelector('.dm-confirm');
        var chosenEl = body.querySelector('.dm-dest-chosen');

        function refresh() {
            confirmBtn.disabled = !(chosen.facilityIndex !== null && chosen.dest);
        }

        // facilities
        getJson(API.manager + '?action=get_facilities&unique_name=' + encodeURIComponent(item.unique_name))
            .then(function (d) {
                var list = body.querySelector('.dm-fac-list');
                if (!d || !d.success || !d.facilities || !d.facilities.length) {
                    list.innerHTML = '<div class="dm-muted">This record has no nested facilities.</div>';
                    return;
                }
                list.classList.remove('dm-muted');
                list.innerHTML = '';
                d.facilities.forEach(function (f) {
                    var lbl = el('label', 'dm-fac-row',
                        '<input type="radio" name="dmFac" value="' + f.index + '"> ' +
                        '<span>' + esc(f.name) + (f.location ? ' <span class="dm-muted">(' + esc(f.location) + ')</span>' : '') +
                        (f.facility_id ? ' <span class="dm-id">id ' + esc(f.facility_id) + '</span>' : '') + '</span>');
                    lbl.querySelector('input').addEventListener('change', function () {
                        chosen.facilityIndex = parseInt(this.value, 10); refresh();
                    });
                    list.appendChild(lbl);
                });
            });

        // destination search
        var search = buildProgramSearch(function (uniqueName, id) {
            if (uniqueName === item.unique_name) { chosenEl.innerHTML = '<span class="dm-error">Cannot move to the same record.</span>'; return; }
            chosen.dest = uniqueName;
            chosenEl.classList.remove('dm-muted');
            chosenEl.innerHTML = 'Destination: <strong>' + esc(uniqueName) + '</strong> #' + esc(id);
            refresh();
        }, 'Search destination program…');
        body.querySelector('.dm-dest').appendChild(search);

        body.querySelector('.dm-cancel').addEventListener('click', closeModal);
        confirmBtn.addEventListener('click', function () {
            setStatus('Moving facility…');
            postJson(API.manager, {
                action: 'reassign_facility',
                from_unique_name: item.unique_name,
                to_unique_name: chosen.dest,
                facility_index: chosen.facilityIndex
            }).then(function (res) {
                if (res.data && res.data.success) {
                    setStatus(esc(res.data.message || 'Moved.'), 'ok');
                    setTimeout(function () { closeModal(); load(); }, 800);
                } else {
                    setStatus(esc((res.data && res.data.error) || 'Move failed.'), 'error');
                }
            }).catch(function () { setStatus('Network error.', 'error'); });
        });
    }

    function actionWiki(item) {
        var body = el('div', 'dm-form');
        body.innerHTML =
            '<p class="dm-muted">Wiki entries linked to this program. Confirm a suggested link, unlink it, or repoint it.</p>' +
            '<div class="dm-wiki-list dm-muted">Loading…</div>' +
            '<hr class="dm-wiki-sep">' +
            '<label>Find a wiki entry to link to this program</label>' +
            '<input type="search" class="dm-wiki-search" placeholder="Search wiki entries by name…" value="' + esc(item.display_name || item.unique_name) + '">' +
            '<div class="dm-wiki-results dm-muted"></div>';
        openModal('Wiki links: ' + item.unique_name, body);

        // --- search & link unlinked wiki entries ---
        var searchInput = body.querySelector('.dm-wiki-search');
        var resultsEl = body.querySelector('.dm-wiki-results');

        function renderWikiSearch(rows) {
            resultsEl.innerHTML = '';
            if (!rows || !rows.length) {
                resultsEl.classList.add('dm-muted');
                resultsEl.innerHTML = 'No matching wiki entries.';
                return;
            }
            resultsEl.classList.remove('dm-muted');
            rows.forEach(function (r) {
                var row = el('div', 'dm-wiki-row');
                var here = r.facility_unique_name === item.unique_name;
                var linkedElsewhere = r.facility_unique_name && !here;
                var statusHtml = here
                    ? '<span class="dm-wiki-confirmed">already linked here</span>'
                    : (linkedElsewhere
                        ? '<span class="dm-wiki-suggested">linked to ' + esc(r.facility_unique_name) + '</span>'
                        : '<span class="dm-muted">unlinked</span>');
                row.innerHTML =
                    '<div class="dm-wiki-meta"><strong>' + esc(r.program_name || ('#' + r.id)) + '</strong>' +
                    (r.city_state ? ' <span class="dm-muted">(' + esc(r.city_state) + ')</span>' : '') +
                    ' <span class="dm-muted">[' + esc(r.type) + ' #' + esc(r.id) + ']</span> ' + statusHtml + '</div>';
                if (!here) {
                    var acts = el('div', 'dm-wiki-acts');
                    var linkB = el('button', 'dm-act', linkedElsewhere ? 'Relink here' : 'Link');
                    linkB.type = 'button';
                    linkB.addEventListener('click', function () {
                        wikiOp({ action: 'link', type: r.type, wiki_id: parseInt(r.id, 10),
                            facility_unique_name: item.unique_name, force: true }, function () {
                            reload(); runWikiSearch();
                        });
                    });
                    acts.appendChild(linkB);
                    row.appendChild(acts);
                }
                resultsEl.appendChild(row);
            });
        }

        function runWikiSearch() {
            var q = searchInput.value.trim();
            if (q.length < 2) { resultsEl.classList.add('dm-muted'); resultsEl.innerHTML = 'Type at least 2 characters.'; return; }
            resultsEl.classList.add('dm-muted');
            resultsEl.innerHTML = 'Searching…';
            getJson(API.manager + '?action=search_wiki&q=' + encodeURIComponent(q))
                .then(function (d) {
                    if (!d || !d.success) { resultsEl.innerHTML = '<span class="dm-error">Search failed.</span>'; return; }
                    renderWikiSearch(d.results || []);
                })
                .catch(function () { resultsEl.innerHTML = '<span class="dm-error">Network error.</span>'; });
        }

        searchInput.addEventListener('input', debounce(runWikiSearch, 300));
        runWikiSearch();

        function reload() {
            var list = body.querySelector('.dm-wiki-list');
            list.classList.add('dm-muted');
            list.innerHTML = 'Loading…';
            getJson(API.manager + '?action=get_wiki_links&unique_name=' + encodeURIComponent(item.unique_name))
                .then(function (d) {
                    if (!d || !d.success) { list.innerHTML = '<div class="dm-error">Failed to load.</div>'; return; }
                    if (!d.links || !d.links.length) {
                        list.innerHTML = '<div class="dm-muted">No wiki entries are linked to this program.</div>';
                        return;
                    }
                    list.classList.remove('dm-muted');
                    list.innerHTML = '';
                    d.links.forEach(function (lk) {
                        var row = el('div', 'dm-wiki-row');
                        var statusCls = lk.facility_link_status === 'confirmed' ? 'dm-wiki-confirmed' : 'dm-wiki-suggested';
                        row.innerHTML =
                            '<div class="dm-wiki-meta"><strong>' + esc(lk.program_name || ('#' + lk.id)) + '</strong>' +
                            ' <span class="dm-muted">(' + esc(lk.type) + ' #' + esc(lk.id) + ')</span> ' +
                            '<span class="' + statusCls + '">' + esc(lk.facility_link_status || 'suggested') + '</span></div>';
                        var acts = el('div', 'dm-wiki-acts');

                        if (lk.facility_link_status !== 'confirmed') {
                            var confirmB = el('button', 'dm-act', 'Confirm');
                            confirmB.type = 'button';
                            confirmB.addEventListener('click', function () {
                                wikiOp({ action: 'confirm', type: lk.type, wiki_id: parseInt(lk.id, 10) }, reload);
                            });
                            acts.appendChild(confirmB);
                        }
                        var unlinkB = el('button', 'dm-act dm-act-delete', 'Unlink');
                        unlinkB.type = 'button';
                        unlinkB.addEventListener('click', function () {
                            wikiOp({ action: 'unlink', type: lk.type, wiki_id: parseInt(lk.id, 10) }, reload);
                        });
                        acts.appendChild(unlinkB);

                        var repointB = el('button', 'dm-act', 'Repoint');
                        repointB.type = 'button';
                        repointB.addEventListener('click', function () { openRepoint(lk); });
                        acts.appendChild(repointB);

                        row.appendChild(acts);
                        var repointHost = el('div', 'dm-repoint-host');
                        row.appendChild(repointHost);
                        repointB._host = repointHost;
                        list.appendChild(row);
                    });
                });
        }

        function openRepoint(lk) {
            var bodyNode = el('div', 'dm-form');
            bodyNode.innerHTML = '<p class="dm-muted">Repoint wiki entry “' + esc(lk.program_name || ('#' + lk.id)) +
                '” to a different program.</p><div class="dm-dest"></div>';
            var search = buildProgramSearch(function (uniqueName) {
                setStatus('Repointing…');
                wikiOp({ action: 'link', type: lk.type, wiki_id: parseInt(lk.id, 10), facility_unique_name: uniqueName, force: true }, function () {
                    setStatus('Repointed to ' + esc(uniqueName) + '.', 'ok');
                    setTimeout(function () { actionWiki(item); }, 700);
                });
            }, 'Search new program…');
            bodyNode.appendChild(search);
            var back = el('button', 'kop-dm-btn kop-dm-btn-ghost', '← Back to links');
            back.type = 'button';
            back.addEventListener('click', function () { actionWiki(item); });
            bodyNode.appendChild(back);
            openModal('Repoint wiki link', bodyNode);
        }

        function wikiOp(payload, done) {
            postJson(API.linkWiki, payload).then(function (res) {
                if (res.data && res.data.success) { if (done) done(); load(); }
                else { setStatus(esc((res.data && res.data.error) || 'Action failed.'), 'error'); }
            }).catch(function () { setStatus('Network error.', 'error'); });
        }

        reload();
    }

    function actionDelete(item) {
        var body = el('div', 'dm-form');
        body.innerHTML =
            '<p class="dm-error-text"><strong>Delete “' + esc(item.unique_name) + '”?</strong></p>' +
            '<p class="dm-muted">This removes the record and purges it from location aggregates. This cannot be undone. ' +
            'Type the name to confirm.</p>' +
            '<input type="text" class="dm-del-input" placeholder="' + esc(item.unique_name) + '">' +
            '<div class="dm-form-actions">' +
            '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-cancel">Cancel</button>' +
            '<button type="button" class="kop-dm-btn kop-dm-btn-danger dm-confirm" disabled>Delete</button></div>';
        openModal('Delete record', body);
        var input = body.querySelector('.dm-del-input');
        var confirmBtn = body.querySelector('.dm-confirm');
        input.addEventListener('input', function () { confirmBtn.disabled = input.value.trim() !== item.unique_name; });
        body.querySelector('.dm-cancel').addEventListener('click', closeModal);
        confirmBtn.addEventListener('click', function () {
            setStatus('Deleting…');
            postJson(API.manager, { action: 'delete', unique_name: item.unique_name })
                .then(function (res) {
                    if (res.data && res.data.success) {
                        setStatus(esc(res.data.message || 'Deleted.'), 'ok');
                        setTimeout(function () { closeModal(); load(); }, 900);
                    } else {
                        setStatus(esc((res.data && (res.data.error || res.data.message)) || 'Delete failed.'), 'error');
                    }
                })
                .catch(function () { setStatus('Network error.', 'error'); });
        });
    }

    // ---- FileBird folder manager ----
    function fbBuildTree(folders) {
        var byId = {};
        folders.forEach(function (f) { byId[String(f.id)] = { id: f.id, name: f.name, parent: String(f.parent || 0), children: [] }; });
        var roots = [];
        Object.keys(byId).forEach(function (k) {
            var f = byId[k];
            if (f.parent === '0' || !byId[f.parent]) roots.push(f);
            else byId[f.parent].children.push(f);
        });
        function sortRec(nodes) {
            nodes.sort(function (a, b) { return String(a.name).localeCompare(String(b.name)); });
            nodes.forEach(function (n) { sortRec(n.children); });
        }
        sortRec(roots);
        return roots;
    }

    // Flat list of {id, name, depth} for the "move to" parent dropdown.
    function fbFlatten(roots) {
        var out = [];
        (function walk(nodes, depth) {
            nodes.forEach(function (n) { out.push({ id: n.id, name: n.name, depth: depth }); walk(n.children, depth + 1); });
        })(roots, 0);
        return out;
    }

    function openFolderManager() {
        var body = el('div', 'dm-form');
        body.innerHTML =
            '<p class="dm-muted">Create, rename, move, or delete FileBird folders. Deleting a folder moves its ' +
            'subfolders and files up to its parent (files stay in the Media Library).</p>' +
            '<div class="dm-fb-new">' +
            '<input type="text" class="dm-fb-new-name" placeholder="New top-level folder name">' +
            '<button type="button" class="kop-dm-btn dm-fb-new-btn">+ Create</button>' +
            '</div>' +
            '<div class="dm-fb-tree dm-muted">Loading folders…</div>';
        openModal('Manage FileBird Folders', body);

        var treeEl = body.querySelector('.dm-fb-tree');
        var flatFolders = [];

        function reload() {
            treeEl.classList.add('dm-muted');
            treeEl.innerHTML = 'Loading folders…';
            getJson(API.foldersAdmin + '?action=list')
                .then(function (d) {
                    if (!d || !d.success) { treeEl.innerHTML = '<div class="dm-error">' + esc((d && d.error) || 'Failed to load.') + '</div>'; return; }
                    var roots = fbBuildTree(d.folders || []);
                    flatFolders = fbFlatten(roots);
                    treeEl.classList.remove('dm-muted');
                    treeEl.innerHTML = '';
                    if (!roots.length) { treeEl.innerHTML = '<div class="dm-muted">No folders yet. Create one above.</div>'; return; }
                    renderNodes(roots, treeEl, 0);
                })
                .catch(function () { treeEl.innerHTML = '<div class="dm-error">Network error.</div>'; });
        }

        function fbOp(payload, okMsg) {
            setStatus(okMsg || 'Working…');
            postJson(API.foldersAdmin, payload).then(function (res) {
                if (res.data && res.data.success) { setStatus('Done.', 'ok'); reload(); }
                else { setStatus(esc((res.data && res.data.error) || 'Action failed.'), 'error'); }
            }).catch(function () { setStatus('Network error.', 'error'); });
        }

        function renderNodes(nodes, container, depth) {
            nodes.forEach(function (node) {
                var row = el('div', 'dm-fb-row');
                row.style.paddingLeft = (depth * 18) + 'px';
                row.innerHTML = '<span class="dm-fb-name">📁 ' + esc(node.name) + ' <span class="dm-id">#' + esc(node.id) + '</span></span>';

                var acts = el('span', 'dm-fb-acts');

                var addB = el('button', 'dm-act', '+ Sub');
                addB.type = 'button';
                addB.addEventListener('click', function () {
                    var name = prompt('New subfolder name under “' + node.name + '”:');
                    if (name && name.trim()) fbOp({ action: 'create', name: name.trim(), parent: node.id }, 'Creating…');
                });
                acts.appendChild(addB);

                var renB = el('button', 'dm-act', 'Rename');
                renB.type = 'button';
                renB.addEventListener('click', function () {
                    var name = prompt('Rename folder:', node.name);
                    if (name && name.trim() && name.trim() !== node.name) fbOp({ action: 'rename', id: node.id, name: name.trim() }, 'Renaming…');
                });
                acts.appendChild(renB);

                var moveB = el('button', 'dm-act', 'Move');
                moveB.type = 'button';
                moveB.addEventListener('click', function () { toggleMove(node, row); });
                acts.appendChild(moveB);

                var delB = el('button', 'dm-act dm-act-delete', 'Delete');
                delB.type = 'button';
                delB.addEventListener('click', function () {
                    if (confirm('Delete “' + node.name + '”? Its subfolders and files move up to the parent.')) {
                        fbOp({ action: 'delete', id: node.id }, 'Deleting…');
                    }
                });
                acts.appendChild(delB);

                row.appendChild(acts);
                container.appendChild(row);

                if (node.children.length) renderNodes(node.children, container, depth + 1);
            });
        }

        function toggleMove(node, row) {
            var existing = row.nextSibling && row.nextSibling.classList && row.nextSibling.classList.contains('dm-fb-move')
                ? row.nextSibling : null;
            if (existing) { existing.parentNode.removeChild(existing); return; }
            var mv = el('div', 'dm-fb-move');
            var opts = '<option value="0">— Top level —</option>' + flatFolders
                .filter(function (f) { return f.id !== node.id; })
                .map(function (f) { return '<option value="' + f.id + '">' + '— '.repeat(f.depth) + esc(f.name) + '</option>'; })
                .join('');
            mv.innerHTML = '<label>Move “' + esc(node.name) + '” into:</label><select class="dm-fb-move-sel">' + opts + '</select>' +
                '<button type="button" class="kop-dm-btn dm-fb-move-go">Move</button>';
            mv.querySelector('.dm-fb-move-go').addEventListener('click', function () {
                var parent = parseInt(mv.querySelector('.dm-fb-move-sel').value, 10) || 0;
                fbOp({ action: 'move', id: node.id, parent: parent }, 'Moving…');
            });
            row.parentNode.insertBefore(mv, row.nextSibling);
        }

        body.querySelector('.dm-fb-new-btn').addEventListener('click', function () {
            var input = body.querySelector('.dm-fb-new-name');
            var name = input.value.trim();
            if (!name) { setStatus('Enter a folder name.', 'error'); return; }
            input.value = '';
            fbOp({ action: 'create', name: name, parent: 0 }, 'Creating…');
        });

        reload();
    }

    // ---- wire up ----
    document.addEventListener('DOMContentLoaded', function () {
        if (!$('dmTableWrap')) return;

        var search = $('dmSearch');
        var onSearch = debounce(function () { state.q = search.value.trim(); state.offset = 0; load(); }, 300);
        search.addEventListener('input', onSearch);

        $('dmCategory').addEventListener('change', function () { state.category = this.value; state.offset = 0; load(); });
        $('dmRefresh').addEventListener('click', load);
        if ($('dmManageFolders')) $('dmManageFolders').addEventListener('click', openFolderManager);
        $('dmPrev').addEventListener('click', function () { if (state.offset > 0) { state.offset -= state.limit; load(); } });
        $('dmNext').addEventListener('click', function () { if (state.offset + state.limit < state.total) { state.offset += state.limit; load(); } });

        $('dmModal').querySelector('.kop-dm-modal-close').addEventListener('click', closeModal);
        $('dmModal').addEventListener('click', function (e) { if (e.target === this) closeModal(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

        load();
    });
})();
