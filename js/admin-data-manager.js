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
        search: cfg.facilitySearchApi || '/wp-content/themes/child/api/facility-search.php'
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
            tr.innerHTML =
                '<td class="dm-name">' + esc(it.display_name || it.unique_name) +
                    (it.is_stub ? ' <span class="dm-stub">stub</span>' : '') +
                    '<div class="dm-uniquename">' + esc(it.unique_name) + '</div></td>' +
                '<td>' + badge(it.category) + '</td>' +
                '<td class="dm-mono">#' + esc(it.id) + '</td>' +
                '<td class="dm-center">' + esc(it.facility_count) + '</td>' +
                '<td class="dm-center">' + (it.document_folder_id ? '📂 ' + esc(it.document_folder_id) : '<span class="dm-muted">—</span>') + '</td>' +
                '<td class="dm-center">' + wikiBadge(it.wiki_links) + '</td>';

            var actions = el('td', 'dm-actions');
            [
                ['Rename', 'rename'],
                ['Doc ID', 'docfolder'],
                ['Category', 'category'],
                ['Facility', 'reassign'],
                ['Wiki', 'wiki'],
                ['Delete', 'delete']
            ].forEach(function (a) {
                var btn = el('button', 'dm-act dm-act-' + a[1], a[0]);
                btn.type = 'button';
                btn.addEventListener('click', function () { handleAction(a[1], it); });
                actions.appendChild(btn);
            });
            tr.appendChild(actions);
            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        wrap.innerHTML = '';
        wrap.appendChild(table);
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
        if (kind === 'rename') return actionRename(item);
        if (kind === 'docfolder') return actionDocFolder(item);
        if (kind === 'category') return actionCategory(item);
        if (kind === 'reassign') return actionReassign(item);
        if (kind === 'wiki') return actionWiki(item);
        if (kind === 'delete') return actionDelete(item);
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
            '<p class="dm-muted">FileBird folder ID number for this record\'s document library. Leave blank to clear.</p>' +
            '<label>Document library folder ID</label>' +
            '<input type="number" min="1" step="1" class="dm-doc-input" value="' + (item.document_folder_id || '') + '">' +
            '<div class="dm-form-actions">' +
            '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-cancel">Cancel</button>' +
            '<button type="button" class="kop-dm-btn dm-confirm">Save</button></div>';
        openModal('Document folder: ' + item.unique_name, body);
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
            '<p class="dm-muted">Wiki entries linked to this program. Confirm a suggested link, unlink it, or repoint it to a different program.</p>' +
            '<div class="dm-wiki-list dm-muted">Loading…</div>';
        openModal('Wiki links: ' + item.unique_name, body);

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

    // ---- wire up ----
    document.addEventListener('DOMContentLoaded', function () {
        if (!$('dmTableWrap')) return;

        var search = $('dmSearch');
        var onSearch = debounce(function () { state.q = search.value.trim(); state.offset = 0; load(); }, 300);
        search.addEventListener('input', onSearch);

        $('dmCategory').addEventListener('change', function () { state.category = this.value; state.offset = 0; load(); });
        $('dmRefresh').addEventListener('click', load);
        $('dmPrev').addEventListener('click', function () { if (state.offset > 0) { state.offset -= state.limit; load(); } });
        $('dmNext').addEventListener('click', function () { if (state.offset + state.limit < state.total) { state.offset += state.limit; load(); } });

        $('dmModal').querySelector('.kop-dm-modal-close').addEventListener('click', closeModal);
        $('dmModal').addEventListener('click', function (e) { if (e.target === this) closeModal(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

        load();
    });
})();
