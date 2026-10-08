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
        facilities: 'Facility',
        program_homes: 'Facility',
        young_adult: 'Young adult program (18+)',
        indigenous_schools: 'Indian boarding school',
        referrers: 'Referrer',
        transporters: 'Transporter',
        providers: 'Mental Health Provider',
        locations: 'Location',
        people: 'Person'
    };

    function icon(name) { return (typeof kopIcon === 'function') ? kopIcon(name) : ''; }

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
                        var meta = [aliasLabel(row), row.city, row.state, row.status].filter(Boolean).join(' · ');
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

    // "Formerly X" / "Now known as X" / "Also known as X" for a hit on one of
    // the program's other names (facility-search.php matched_name/_kind).
    function aliasLabel(row) {
        if (!row.matched_name) return '';
        return (row.matched_kind === 'past' ? 'Formerly ' : row.matched_kind === 'current' ? 'Now known as ' : 'Also known as ') + row.matched_name;
    }

    // Name cell: name, stub/designation tags, place, companies, alias hit, page link.
    function nameCellHtml(it) {
        var sub = [];
        if (it.kind === 'facility' || it.kind === 'young_adult' || it.kind === 'indigenous_school') {
            sub.push([it.place, it.status && it.status !== 'Unknown' ? it.status : '', '#' + it.id].filter(Boolean).join(' · '));
            if (it.companies && it.companies.length) sub.push('Company: ' + it.companies.join(', '));
        } else if (it.kind === 'person') {
            sub.push('Person #' + it.id + ' · named on ' + it.record_count + ' record' + (it.record_count === 1 ? '' : 's'));
            if (it.aliases && it.aliases.length) sub.push('Also written: ' + it.aliases.join(', '));
        } else {
            sub.push(it.unique_name);
        }
        return esc(it.display_name || it.unique_name) +
            (it.is_stub ? ' <span class="dm-stub">stub</span>' : '') +
            (it.designation ? ' <span class="dm-designation dm-designation-' + esc(it.home_role || it.review || 'x') + '">' + esc(it.designation) + '</span>' : '') +
            sub.map(function (s) { return '<div class="dm-uniquename">' + esc(s) + '</div>'; }).join('') +
            (it.matched_name ? '<div class="dm-muted">' + esc(aliasLabel(it)) + '</div>' : '') +
            (it.page_url ? '<div><a class="dm-page-link" href="' + esc(it.page_url) + '" target="_blank" rel="noopener">View page</a></div>' : '');
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

    // --- in-place row updates (so saving never rebuilds the table / loses place) ---
    function docCellHtml(id) { return id ? ((typeof kopIcon === 'function') ? kopIcon('folder-open') : '') + ' ' + esc(id) : '<span class="dm-muted">—</span>'; }
    function setDocCell(item) { if (item._docCell) item._docCell.innerHTML = docCellHtml(item.document_folder_id); }
    function setWikiCell(item) { if (item._wikiCell) item._wikiCell.innerHTML = wikiBadge(item.wiki_links); }
    function setFacCount(item) {
        if (item._toggle) item._toggle.textContent = (item._toggle.getAttribute('aria-expanded') === 'true' ? '▼ ' : '▶ ') + item.facility_count;
    }
    function removeRowDom(item) {
        if (item._tr && item._tr.parentNode) item._tr.parentNode.removeChild(item._tr);
        if (item._subTr && item._subTr.parentNode) item._subTr.parentNode.removeChild(item._subTr);
    }
    function countsFromLinks(links) {
        var c = { suggested: 0, confirmed: 0, total: 0 };
        (links || []).forEach(function (lk) {
            c.total++;
            if (lk.facility_link_status === 'confirmed') c.confirmed++; else c.suggested++;
        });
        return c;
    }
    // Refresh just one company row's wiki badge without touching the rest of the table.
    function refreshWikiCell(item) {
        getJson(API.manager + '?action=get_wiki_links&unique_name=' + encodeURIComponent(item.unique_name))
            .then(function (d) {
                if (d && d.success) { item.wiki_links = countsFromLinks(d.links); setWikiCell(item); }
            }).catch(function () {});
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
                : '<span class="dm-muted">' + (it.kind === 'operator' || it.kind === 'legacy' || !it.kind ? '0' : '—') + '</span>';
            tr.innerHTML =
                '<td class="dm-name">' + nameCellHtml(it) + '</td>' +
                '<td>' + badge(it.category) + '</td>' +
                '<td class="dm-mono">#' + esc(it.id) + '</td>' +
                '<td class="dm-center dm-fac-cell">' + facCell + '</td>' +
                '<td class="dm-center">' + (it.document_folder_id ? ((typeof kopIcon === 'function') ? kopIcon('folder-open') : '') + ' ' + esc(it.document_folder_id) : '<span class="dm-muted">—</span>') + '</td>' +
                '<td class="dm-center">' + wikiBadge(it.wiki_links) + '</td>';

            // Cache cell refs for in-place updates (avoids full table reloads on save).
            it._tr = tr;
            it._nameCell = tr.children[0];
            it._catCell = tr.children[1];
            it._docCell = tr.children[4];
            it._wikiCell = tr.children[5];

            var actions = el('td', 'dm-actions');
            var actionDefs = [];
            if (it.kind === 'facility') {
                // One facility record: what it is (its own program, a home of a
                // program, young adult program, Indian boarding school), plus edits.
                actionDefs.push(
                    ['Designation', 'designation'],
                    ['Rename', 'rename'],
                    ['Doc ID', 'docfolder'],
                    ['Wiki', 'wiki']
                );
            } else if (it.kind === 'young_adult' || it.kind === 'indigenous_school') {
                actionDefs.push(['Rename', 'rename'], ['Edit details', 'edit']);
            } else if (it.kind === 'person') {
                actionDefs.push(['Where named', 'person_roles'], ['Edit', 'person_edit'], ['Same person as', 'person_merge'], ['People screen', 'edit']);
            } else {
                // Auto-link only makes sense for actual programs, not location aggregates.
                if (it.category !== 'locations') actionDefs.push([icon('sparkles') + ' Auto', 'auto']);
                actionDefs.push(
                    ['Rename', 'rename'],
                    ['Doc ID', 'docfolder'],
                    ['Category', 'category'],
                    ['Reassign', 'reassign'],
                    ['Wiki', 'wiki'],
                    ['Delete', 'delete']
                );
            }
            actionDefs.forEach(function (a) {
                var label = a[0];
                var cls = 'dm-act dm-act-' + a[1].replace(/\W/g, '');
                // No-link fallback indicator: wiki entries name-match this program
                // but aren't explicitly linked — flag the Wiki button for review.
                if (a[1] === 'wiki' && it.name_match_unlinked > 0) {
                    label = 'Wiki ' + ((typeof kopIcon === 'function') ? kopIcon('alert-triangle') : '') + it.name_match_unlinked;
                    cls += ' dm-act-attention';
                }
                var btn = el('button', cls, label);
                if (a[1] === 'wiki' && it.name_match_unlinked > 0) {
                    btn.title = it.name_match_unlinked + ' wiki entr' + (it.name_match_unlinked === 1 ? 'y' : 'ies') +
                        ' name-match this program but are not linked. Open Wiki to link them.';
                }
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
            it._subTr = subTr;
            it._subTd = subTd;

            if (it.facility_count > 0) {
                var toggle = tr.querySelector('.dm-fac-toggle');
                it._toggle = toggle;
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
                // Keep the parent row's facility count badge fresh in place.
                if (d.facilities && typeof d.facilities.length === 'number') {
                    operator.facility_count = d.facilities.length;
                    setFacCount(operator);
                }
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
                        '<span class="dm-fac-item-name">' + ((typeof kopIcon === 'function') ? kopIcon('graduation-cap') : '') + ' ' + esc(f.name) +
                        (f.location ? ' <span class="dm-muted">(' + esc(f.location) + ')</span>' : '') +
                        (f.facility_id ? ' <span class="dm-id">id ' + esc(f.facility_id) + '</span>' : '') +
                        (f.document_folder_id ? ' <span class="dm-fac-doc">' + ((typeof kopIcon === 'function') ? kopIcon('folder-open') : '') + ' ' + esc(f.document_folder_id) + '</span>' : '') +
                        (f.wiki_count ? ' <span class="dm-fac-wiki">' + ((typeof kopIcon === 'function') ? kopIcon('link') : '') + ' ' + esc(f.wiki_count) + '</span>' : '') +
                        (f.designation ? ' <span class="dm-designation dm-designation-' + esc(f.home_role || 'x') + '">' + esc(f.designation) + '</span>' : '') +
                        (f.page_url ? ' <a class="dm-page-link" href="' + esc(f.page_url) + '" target="_blank" rel="noopener">View page</a>' : '') +
                        '</span>';
                    var acts = el('span', 'dm-fac-item-acts');
                    (f.facility_id ? [['designation', 'Designation', function () {
                        openDesignation(f.facility_id, f.name, function () { loadFacilitySubrows(operator, container); });
                    }]] : []).concat([
                        ['auto', ((typeof kopIcon === 'function') ? kopIcon('sparkles') + ' ' : '') + 'Auto', function () { facilityAuto(operator, f, container); }],
                        ['rename', 'Rename', function () { facilityRename(operator, f, container); }],
                        ['docfolder', 'Doc ID', function () { facilityDocFolder(operator, f, container); }],
                        ['wiki', 'Wiki', function () { facilityWiki(operator, f, container); }],
                        ['move', 'Move', function () { facilityReassign(operator, f, container); }],
                        ['delete', 'Delete', function () { facilityDelete(operator, f, container); }]
                    ]).forEach(function (a) {
                        var key = a[0], label = a[1], handler = a[2];
                        var cls = 'dm-act';
                        if (key === 'delete') cls += ' dm-act-delete';
                        if (key === 'auto') cls += ' dm-act-auto';
                        var b = el('button', cls, label);
                        b.type = 'button';
                        b.addEventListener('click', handler);
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
            if (res.data && res.data.success) { loadFacilitySubrows(operator, container); return true; }
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

    // One-click auto-link for a single facility (folder + wiki, strong matches).
    function facilityAuto(operator, f, container) {
        var body = el('div', 'dm-form');
        body.innerHTML = '<p class="dm-muted">Finding strong matches for facility <strong>' + esc(f.name) + '</strong>…</p>';
        openModal('Auto-link facility: ' + f.name, body);
        postJson(API.manager, {
            action: 'auto_apply_facility',
            operator_unique_name: operator.unique_name,
            facility_id: f.facility_id,
            facility_index: f.index
        }).then(function (res) {
            if (!res.data || !res.data.success) {
                body.innerHTML = '<p class="dm-error">' + esc((res.data && res.data.error) || 'Failed.') + '</p>';
                return;
            }
            var d = res.data;
            var html = '<p class="dm-status-ok">' + esc(d.message) + '</p><ul class="dm-auto-notes">';
            if (d.folder) html += '<li>' + ((typeof kopIcon === 'function') ? kopIcon('folder-open') : '') + ' Folder: <strong>' + esc(d.folder.name) + '</strong> #' + esc(d.folder.id) + '</li>';
            if (d.wiki) html += '<li>' + ((typeof kopIcon === 'function') ? kopIcon('link') : '') + ' Wiki: <strong>' + esc(d.wiki.program_name) + '</strong> <span class="dm-muted">(suggested — confirm under Wiki)</span></li>';
            (d.notes || []).forEach(function (n) { html += '<li class="dm-muted">' + esc(n) + '</li>'; });
            html += '</ul><div class="dm-form-actions"><button type="button" class="kop-dm-btn dm-done">Done</button></div>';
            body.innerHTML = html;
            body.querySelector('.dm-done').addEventListener('click', function () { closeModal(); loadFacilitySubrows(operator, container); });
            loadFacilitySubrows(operator, container); // refresh badges underneath
        }).catch(function () { body.innerHTML = '<p class="dm-error">Network error.</p>'; });
    }

    // Manage wiki links for one facility. Resolves the facility's own
    // facilities_master record (promoting it if needed) and opens the shared
    // wiki manager against that unique_name.
    function facilityWiki(operator, f, container) {
        var label = f.name + ' — facility';
        var onCounts = function () { loadFacilitySubrows(operator, container); };
        if (f.facility_unique_name) {
            openWikiManager(f.facility_unique_name, label, onCounts);
            return;
        }
        postJson(API.manager, {
            action: 'facility_link_target',
            operator_unique_name: operator.unique_name,
            facility_id: f.facility_id,
            facility_index: f.index
        }).then(function (res) {
            if (res.data && res.data.success) {
                f.facility_unique_name = res.data.facility_unique_name;
                openWikiManager(res.data.facility_unique_name, label, onCounts);
                loadFacilitySubrows(operator, container); // refresh badges/targets
            } else {
                alert((res.data && res.data.error) || 'Could not prepare this facility for wiki linking.');
            }
        }).catch(function () { alert('Network error.'); });
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
            '<button type="button" class="kop-dm-btn dm-doc-browse">' + ((typeof kopIcon === 'function') ? kopIcon('folder') : '') + ' Browse folders…</button></div>' +
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
                    setTimeout(function () { closeModal(); loadFacilitySubrows(operator, container); }, 600);
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
                    setTimeout(function () { closeModal(); loadFacilitySubrows(operator, container); }, 800);
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
        if (kind === 'designation') {
            return openDesignation(item.id, item.display_name || item.unique_name, function (res) {
                if (res && res.moved) { removeRowDom(item); state.total = Math.max(0, state.total - 1); renderPagination(); }
                else refreshRow(item);
            });
        }
        if (kind === 'edit') { window.open(item.admin_url, '_blank', 'noopener'); return; }
        if (kind === 'person_roles' || kind === 'person_edit' || kind === 'person_merge') return openPerson(item, kind);
        if (kind === 'rename' && item.kind && item.kind !== 'operator' && item.kind !== 'legacy') return actionRenameRecord(item);
        if (kind === 'docfolder' && item.kind === 'facility') return actionDocFolder(item, true);
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
                if (d.folder) html += '<li>' + ((typeof kopIcon === 'function') ? kopIcon('folder-open') : '') + ' Folder: <strong>' + esc(d.folder.name) + '</strong> #' + esc(d.folder.id) + '</li>';
                if (d.wiki) html += '<li>' + ((typeof kopIcon === 'function') ? kopIcon('link') : '') + ' Wiki: <strong>' + esc(d.wiki.program_name) + '</strong> <span class="dm-muted">(suggested — confirm under Wiki)</span></li>';
                (d.notes || []).forEach(function (n) { html += '<li class="dm-muted">' + esc(n) + '</li>'; });
                html += '</ul><div class="dm-form-actions">' +
                    '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-fix-doc">Edit folder</button>' +
                    '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-fix-wiki">Edit wiki</button>' +
                    '<button type="button" class="kop-dm-btn dm-done">Done</button></div>';
                body.innerHTML = html;
                body.querySelector('.dm-fix-doc').addEventListener('click', function () { actionDocFolder(item); });
                body.querySelector('.dm-fix-wiki').addEventListener('click', function () { actionWiki(item); });
                body.querySelector('.dm-done').addEventListener('click', closeModal);
                // In-place badge updates only — never rebuild the table.
                if (d.folder) setDocCell(item);
                if (d.wiki) refreshWikiCell(item);
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
                        // Update this row in place; keep everything else as-is.
                        item.unique_name = newName;
                        if (item._nameCell) item._nameCell.innerHTML = nameCellHtml(item);
                        setTimeout(closeModal, 800);
                    } else {
                        setStatus(esc((res.data && (res.data.error || res.data.message)) || 'Rename failed.'), 'error');
                    }
                })
                .catch(function () { setStatus('Network error.', 'error'); });
        });
    }

    // asRecord: one facility record (facilities_v2 id), saved by data-manager.php
    // so a company with the same unique name is never the one changed.
    // Rename a facility record, young adult program or Indian boarding school
    // (its shown name; a facility keeps its unique name, so links stay).
    function actionRenameRecord(item) {
        var body = el('div', 'dm-form');
        body.innerHTML =
            '<label>New name</label>' +
            '<input type="text" class="dm-rename-input" value="' + esc(item.display_name || item.unique_name) + '">' +
            '<div class="dm-form-actions">' +
            '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-cancel">Cancel</button>' +
            '<button type="button" class="kop-dm-btn dm-confirm">Rename</button></div>';
        openModal('Rename: ' + (item.display_name || item.unique_name), body);
        body.querySelector('.dm-cancel').addEventListener('click', closeModal);
        body.querySelector('.dm-confirm').addEventListener('click', function () {
            var newName = body.querySelector('.dm-rename-input').value.trim();
            if (!newName || newName === item.display_name) { setStatus('Enter a different name.', 'error'); return; }
            setStatus('Renaming…');
            postJson(API.manager, { action: 'rename_record', kind: item.kind, id: item.id, new_name: newName })
                .then(function (res) {
                    if (res.data && res.data.success) {
                        setStatus(esc(res.data.message || 'Renamed.'), 'ok');
                        item.display_name = newName;
                        if (item.kind !== 'facility') item.unique_name = newName;
                        if (item._nameCell) item._nameCell.innerHTML = nameCellHtml(item);
                        setTimeout(closeModal, 800);
                    } else {
                        setStatus(esc((res.data && res.data.error) || 'Rename failed.'), 'error');
                    }
                })
                .catch(function () { setStatus('Network error.', 'error'); });
        });
    }

    // ---- one person (inc/people.php person ids) ----
    // person_roles: every record that names them, with Separate (two people,
    // one name); person_edit: name, other spellings, notes; person_merge: join
    // this id into another (one person, two names), with Undo.
    function openPerson(item, view) {
        var title = { person_roles: 'Where named: ', person_edit: 'Edit: ', person_merge: 'Same person as: ' }[view];
        var body = el('div', 'dm-form dm-person');
        body.innerHTML = '<p class="dm-muted">Loading…</p>';
        openModal(title + item.display_name, body);
        getJson(API.manager + '?action=get_person&id=' + encodeURIComponent(item.id))
            .then(function (d) {
                if (!d || !d.success) { body.innerHTML = '<p class="dm-error">' + esc((d && d.error) || 'Could not load this person.') + '</p>'; return; }
                renderPerson(body, d.person, item, view);
            })
            .catch(function () { body.innerHTML = '<p class="dm-error">Network error.</p>'; });
    }

    // The list row after a save or a separation, in place.
    function personRowUpdate(item, p) {
        item.display_name = item.unique_name = p.name;
        item.aliases = (p.aliases || '').split(/\r?\n/).map(function (a) { return a.trim(); }).filter(Boolean);
        var recs = {};
        p.roles.forEach(function (r) { recs[r.record_kind + r.record_id] = true; });
        item.record_count = Object.keys(recs).length;
        if (item._nameCell) item._nameCell.innerHTML = nameCellHtml(item);
    }

    function renderPerson(body, p, item, view) {
        var html = '';
        if (view === 'person_roles') {
            if (!p.roles.length) {
                html += '<p class="dm-muted">No record names this person now (the entry was renamed or removed).</p>';
            } else {
                html += '<table class="dm-person-roles"><thead><tr><th>Record</th><th>List</th><th>Written as</th><th>Role</th><th></th></tr></thead><tbody>' +
                    p.roles.map(function (r, i) {
                        return '<tr><td>' + (r.record_url ? '<a href="' + esc(r.record_url) + '" target="_blank" rel="noopener">' + esc(r.record_name) + '</a>' : esc(r.record_name)) +
                            ' <span class="dm-muted">' + esc(r.what) + '</span></td><td>' + esc(r.list_label) + '</td><td>' + esc(r.written_as) + '</td><td>' + esc(r.role) + '</td>' +
                            '<td>' + (r.can_separate ? '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-person-sep" data-i="' + i + '" title="This entry is someone else with the same name">Separate</button>' : '') + '</td></tr>';
                    }).join('') + '</tbody></table>' +
                    '<p class="dm-muted">Separate gives one entry its own person id, for two people who share a name.</p>';
            }
        } else if (view === 'person_edit') {
            html += '<label>Name</label><input type="text" class="dm-person-name" value="' + esc(p.name) + '">' +
                '<label>Also written as (one per line)</label><textarea class="dm-person-aliases" rows="3">' + esc(p.aliases) + '</textarea>' +
                '<label>Notes (admins only)</label><textarea class="dm-person-notes" rows="3">' + esc(p.notes) + '</textarea>' +
                '<p class="dm-muted">A changed name keeps the old one as another spelling, so the entries written that way stay with this person.</p>' +
                '<div class="dm-form-actions"><button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-cancel">Cancel</button>' +
                '<button type="button" class="kop-dm-btn dm-person-save">Save</button></div>';
        } else {
            html += '<p>If ' + esc(p.name) + ' already has another id (a nickname, a maiden name, a misspelling), join this id into it. ' +
                'Their entries move there and this id forwards to it. <a href="' + esc(p.merge_url) + '" target="_blank" rel="noopener">Merge People</a> lists likely pairs and every merge.</p>';
            if (p.similar.length) {
                html += '<div class="dm-person-similar">' + p.similar.map(function (o) {
                    return '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-person-into" data-into="#' + esc(o.id) + '">Same person as ' + esc(o.name) + ' (#' + esc(o.id) + ')</button>';
                }).join('') + '</div>';
            }
            html += '<label>Other person\'s name or id</label><input type="text" class="dm-person-intotext" placeholder="Name or #id">' +
                '<div class="dm-form-actions"><button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-cancel">Cancel</button>' +
                '<button type="button" class="kop-dm-btn dm-person-merge">Same person</button></div>';
        }
        html += '<p><a href="' + esc(p.admin_url) + '" target="_blank" rel="noopener">Open on the People screen</a></p>';
        body.innerHTML = html;
        var cancel = body.querySelector('.dm-cancel');
        if (cancel) cancel.addEventListener('click', closeModal);

        Array.prototype.forEach.call(body.querySelectorAll('.dm-person-sep'), function (b) {
            b.addEventListener('click', function () {
                var r = p.roles[+b.getAttribute('data-i')];
                if (!confirm('Give "' + r.written_as + '" on ' + r.record_name + ' its own person id? Use this when it is a different person with the same name.')) return;
                setStatus('Separating…');
                postJson(API.manager, { action: 'person_separate', id: p.id, facility_id: r.record_id, list: r.list, position: r.position }).then(function (res) {
                    if (!res.data || !res.data.success) { setStatus(esc((res.data && res.data.error) || 'Not done.'), 'error'); return; }
                    setStatus(esc(res.data.message), 'ok');
                    personRowUpdate(item, res.data.person);
                    renderPerson(body, res.data.person, item, view);
                }).catch(function () { setStatus('Network error.', 'error'); });
            });
        });

        var save = body.querySelector('.dm-person-save');
        if (save) save.addEventListener('click', function () {
            setStatus('Saving…');
            postJson(API.manager, {
                action: 'person_save', id: p.id,
                name: body.querySelector('.dm-person-name').value.trim(),
                aliases: body.querySelector('.dm-person-aliases').value,
                notes: body.querySelector('.dm-person-notes').value
            }).then(function (res) {
                if (!res.data || !res.data.success) { setStatus(esc((res.data && res.data.error) || 'Not saved.'), 'error'); return; }
                setStatus('Saved.', 'ok');
                personRowUpdate(item, res.data.person);
                setTimeout(closeModal, 800);
            }).catch(function () { setStatus('Network error.', 'error'); });
        });

        function done(message, undoLog) {
            body.innerHTML = '<p class="dm-status-ok">' + esc(message) + '</p>' +
                '<div class="dm-form-actions">' + (undoLog ? '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-person-undo">Undo</button>' : '') +
                '<button type="button" class="kop-dm-btn dm-done">Done</button></div>';
            body.querySelector('.dm-done').addEventListener('click', function () { closeModal(); load(); });
            var undo = body.querySelector('.dm-person-undo');
            if (undo) undo.addEventListener('click', function () {
                setStatus('Undoing…');
                postJson(API.manager, { action: 'person_undo_merge', log: undoLog }).then(function (u) {
                    if (!u.data || !u.data.success) { setStatus(esc((u.data && u.data.error) || 'Undo failed.'), 'error'); return; }
                    setStatus('');
                    done(u.data.message, '');
                }).catch(function () { setStatus('Network error.', 'error'); });
            });
        }

        function merge(into) {
            if (!into) { setStatus('Type the other person\'s id or name.', 'error'); return; }
            setStatus('Merging…');
            postJson(API.manager, { action: 'person_merge', id: p.id, into: into }).then(function (res) {
                if (!res.data || !res.data.success) { setStatus(esc((res.data && res.data.error) || 'Not merged.'), 'error'); return; }
                setStatus('');
                removeRowDom(item);
                state.total = Math.max(0, state.total - 1);
                renderPagination();
                done(res.data.message, res.data.undo_log);
            }).catch(function () { setStatus('Network error.', 'error'); });
        }
        Array.prototype.forEach.call(body.querySelectorAll('.dm-person-into'), function (b) {
            b.addEventListener('click', function () { merge(b.getAttribute('data-into')); });
        });
        var mergeBtn = body.querySelector('.dm-person-merge');
        if (mergeBtn) mergeBtn.addEventListener('click', function () { merge(body.querySelector('.dm-person-intotext').value.trim()); });
    }

    // Re-read one facility row's designation after a change, in place.
    function refreshRow(item) {
        getJson(API.manager + '?action=get_designation&facility_id=' + encodeURIComponent(item.id))
            .then(function (d) {
                if (!d || !d.success) return;
                item.home_role = d.role;
                item.designation = d.role === 'home' ? 'Home of ' + d.program_name
                    : d.role === 'program' ? 'Program: ' + d.homes.length + ' home' + (d.homes.length === 1 ? '' : 's') : '';
                if (item._nameCell) item._nameCell.innerHTML = nameCellHtml(item);
            }).catch(function () {});
    }

    // ---- designation of one facility record ----
    // Its own program (the default), a home of a program (Program Homes: keeps
    // its record, listed on the program's page; Undo here), a young adult
    // program (18+) or an Indian boarding school (both move it out of the
    // facility records into their own lists). onDone(result) after a change.
    function openDesignation(fid, label, onDone) {
        var body = el('div', 'dm-form dm-designation-form');
        body.innerHTML = '<p class="dm-muted">Loading…</p>';
        openModal('Designation: ' + label, body);
        getJson(API.manager + '?action=get_designation&facility_id=' + encodeURIComponent(fid))
            .then(function (d) {
                if (!d || !d.success) { body.innerHTML = '<p class="dm-error">' + esc((d && d.error) || 'Could not load this record.') + '</p>'; return; }
                renderDesignation(body, d, onDone);
            })
            .catch(function () { body.innerHTML = '<p class="dm-error">Network error.</p>'; });
    }

    function renderDesignation(body, d, onDone) {
        var now = d.role === 'home'
            ? 'A <strong>home of ' + esc(d.program_name) + '</strong> (record #' + esc(d.program_id) + '). It keeps its own record and page, and is listed on the program\'s page.'
            : d.role === 'program'
                ? 'A <strong>program</strong> with ' + d.homes.length + ' home' + (d.homes.length === 1 ? '' : 's') + ' listed under it: ' +
                    d.homes.map(function (h) { return esc(h.name); }).join(', ') + '.'
                : 'Its <strong>own program</strong> (a TTI facility record, not a home of another program).';
        var html = '<p>' + esc(d.name) + ' is now: ' + now + '</p>';
        if (d.page_url) html += '<p><a href="' + esc(d.page_url) + '" target="_blank" rel="noopener">View its page</a></p>';

        if (d.role === 'program') {
            html += '<p class="dm-muted">A program\'s homes are managed at KOP Tools &gt; Program Homes. Take its homes off there before giving this record another designation.</p>';
        } else if (d.homes_available) {
            html += '<fieldset class="dm-desig-block"><legend>Home of a program</legend>' +
                '<p class="dm-muted">For a licensed home or cottage that belongs to one program. It stays its own record (inspections, page); the program\'s page lists it with its news, lawsuits and findings.</p>' +
                '<label>Program record</label><div class="dm-desig-finder"></div>' +
                '<div class="dm-desig-chosen dm-muted">No program record picked.</div>' +
                '<label>or a new program named</label><input type="text" class="dm-desig-newname" placeholder="e.g. Newport Academy">' +
                '<div class="dm-form-actions">' +
                (d.role === 'home' ? '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-desig-nothome">Not a home: its own program</button>' : '') +
                '<button type="button" class="kop-dm-btn dm-desig-home">' + (d.role === 'home' ? 'Move to this program' : 'Make it a home of this program') + '</button></div>' +
                '</fieldset>';
        }
        if (d.role !== 'program' && (d.young_adult_available || d.schools_available)) {
            html += '<fieldset class="dm-desig-block"><legend>Not a TTI facility</legend>' +
                '<p class="dm-muted">These move the record out of the facility records into its own list: off the directory, map, search and facility pages. ' +
                'A record with a lawsuit linked (or, for young adult programs, an article) is refused until that is sorted out by hand. There is no one-click undo.</p>' +
                '<div class="dm-form-actions">' +
                (d.young_adult_available ? '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-desig-ya">Young adult program (18+)</button>' : '') +
                (d.schools_available ? '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-desig-school">Indian boarding / residential school</button>' : '') +
                '</div></fieldset>';
        }
        body.innerHTML = html;

        var picked = null;

        function send(payload, confirmText) {
            if (confirmText && !confirm(confirmText)) return;
            payload.action = 'set_designation';
            payload.facility_id = d.facility_id;
            setStatus('Saving…');
            postJson(API.manager, payload).then(function (res) {
                if (!res.data || !res.data.success) { setStatus(esc((res.data && res.data.error) || 'Not done.'), 'error'); return; }
                setStatus(esc(res.data.message), 'ok');
                if (onDone) onDone(res.data);
                if (res.data.moved) { body.innerHTML = '<p class="dm-status-ok">' + esc(res.data.message) + '</p>'; return; }
                showUndo(res.data.undo, res.data.message);
            }).catch(function () { setStatus('Network error.', 'error'); });
        }

        function showUndo(undo, message) {
            body.innerHTML = '<p class="dm-status-ok">' + esc(message) + '</p>' +
                '<div class="dm-form-actions"><button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-desig-undo">Undo</button>' +
                '<button type="button" class="kop-dm-btn dm-done">Done</button></div>';
            body.querySelector('.dm-done').addEventListener('click', closeModal);
            body.querySelector('.dm-desig-undo').addEventListener('click', function () {
                var p = { action: 'undo_home' };
                Object.keys(undo || {}).forEach(function (k) { p[k] = undo[k]; });
                setStatus('Undoing…');
                postJson(API.manager, p).then(function (res) {
                    if (!res.data || !res.data.success) { setStatus(esc((res.data && res.data.error) || 'Undo failed.'), 'error'); return; }
                    setStatus(esc(res.data.message), 'ok');
                    if (onDone) onDone(res.data);
                    body.innerHTML = '<p class="dm-status-ok">' + esc(res.data.message) + '</p>';
                }).catch(function () { setStatus('Network error.', 'error'); });
            });
        }

        var finderHost = body.querySelector('.dm-desig-finder');
        if (finderHost) {
            var chosen = body.querySelector('.dm-desig-chosen');
            finderHost.appendChild(buildFacilityFinder(function (f) {
                if (f.id === d.facility_id) { chosen.innerHTML = '<span class="dm-error">Pick the program\'s own record, not this one.</span>'; return; }
                picked = f;
                chosen.classList.remove('dm-muted');
                chosen.innerHTML = 'Program: <strong>' + esc(f.name) + '</strong> #' + esc(f.id) +
                    (f.designation ? ' <span class="dm-muted">(' + esc(f.designation) + ')</span>' : '');
            }));
            body.querySelector('.dm-desig-home').addEventListener('click', function () {
                var newName = body.querySelector('.dm-desig-newname').value.trim();
                if (!picked && !newName) { setStatus('Pick the program\'s record, or type a name for a new one.', 'error'); return; }
                send(picked ? { designation: 'home', program_id: picked.id } : { designation: 'home', program_name: newName });
            });
            var notHome = body.querySelector('.dm-desig-nothome');
            if (notHome) notHome.addEventListener('click', function () { send({ designation: 'not_home' }); });
        }
        var yaBtn = body.querySelector('.dm-desig-ya');
        if (yaBtn) yaBtn.addEventListener('click', function () {
            send({ designation: 'young_adult' }, 'Move ' + d.name + ' out of the facility records into Young Adult Programs (18+)? It stops being a facility record.');
        });
        var schoolBtn = body.querySelector('.dm-desig-school');
        if (schoolBtn) schoolBtn.addEventListener('click', function () {
            send({ designation: 'indigenous_school' }, 'Move ' + d.name + ' out of the facility records into Indian boarding schools? It stops being a facility record.');
        });
    }

    // Search facility records by name, past name or id (data-manager.php
    // find_facility = the admin facility finder). onPick(facility).
    function buildFacilityFinder(onPick) {
        var wrap = el('div', 'dm-progsearch');
        var input = el('input', 'dm-progsearch-input');
        input.type = 'search';
        input.placeholder = 'Search facility records by name…';
        var results = el('div', 'dm-progsearch-results');
        wrap.appendChild(input);
        wrap.appendChild(results);
        input.addEventListener('input', debounce(function () {
            var q = input.value.trim();
            if (q.length < 2) { results.innerHTML = ''; return; }
            results.innerHTML = '<div class="dm-muted">Searching…</div>';
            getJson(API.manager + '?action=find_facility&q=' + encodeURIComponent(q))
                .then(function (d) {
                    results.innerHTML = '';
                    if (!d || !d.success || !d.results || !d.results.length) { results.innerHTML = '<div class="dm-muted">No matches.</div>'; return; }
                    d.results.forEach(function (f) {
                        var said = f.matched ? aliasLabel({ matched_name: f.matched, matched_kind: f.matched_kind }) : '';
                        var meta = [said, [f.city, f.state || f.country].filter(Boolean).join(', '), f.status, f.designation].filter(Boolean).join(' · ');
                        var b = el('button', 'dm-progsearch-row',
                            '<strong>' + esc(f.name) + '</strong>' + (meta ? ' <span class="dm-muted">' + esc(meta) + '</span>' : '') +
                            ' <span class="dm-id">#' + esc(f.id) + '</span>');
                        b.type = 'button';
                        b.addEventListener('click', function () { results.innerHTML = ''; input.value = f.name; onPick(f); });
                        results.appendChild(b);
                    });
                })
                .catch(function () { results.innerHTML = '<div class="dm-error">Search failed.</div>'; });
        }, 250));
        return wrap;
    }

    function actionDocFolder(item, asRecord) {
        var body = el('div', 'dm-form');
        body.innerHTML =
            '<p class="dm-muted">FileBird folder for this record\'s document library. Browse to pick one, or leave blank to clear.</p>' +
            '<label>Document library folder</label>' +
            '<div class="dm-doc-row">' +
            '<input type="number" min="1" step="1" class="dm-doc-input" placeholder="folder ID" value="' + (item.document_folder_id || '') + '">' +
            '<button type="button" class="kop-dm-btn dm-doc-browse">' + ((typeof kopIcon === 'function') ? kopIcon('folder') : '') + ' Browse folders…</button>' +
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
            var folder = val === '' ? null : parseInt(val, 10);
            setStatus('Saving…');
            (asRecord
                ? postJson(API.manager, { action: 'set_record_doc_folder', kind: 'facility', id: item.id, document_folder_id: folder })
                : postJson(API.picker, { action: 'set_doc_folder', unique_name: item.unique_name, document_folder_id: folder }))
                .then(function (res) {
                    if (res.data && res.data.success) {
                        setStatus('Saved.', 'ok');
                        item.document_folder_id = val === '' ? null : parseInt(val, 10);
                        setDocCell(item); // in-place; no table reload
                        setTimeout(closeModal, 600);
                    } else {
                        setStatus(esc((res.data && res.data.error) || 'Save failed.'), 'error');
                    }
                })
                .catch(function () { setStatus('Network error.', 'error'); });
        });
    }

    function actionCategory(item) {
        var body = el('div', 'dm-form');
        var opts = ['companies', 'referrers', 'transporters', 'providers']
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
                        item.category = target;
                        if (item._catCell) item._catCell.innerHTML = badge(target);
                        setTimeout(closeModal, 700);
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
                    // Update this record's facility count in place; refresh its
                    // sub-list if it's open. Destination row updates on next expand.
                    item.facility_count = Math.max(0, (item.facility_count || 1) - 1);
                    setFacCount(item);
                    if (item._subTr && item._subTr.style.display !== 'none') loadFacilitySubrows(item, item._subTd);
                    setTimeout(closeModal, 800);
                } else {
                    setStatus(esc((res.data && res.data.error) || 'Move failed.'), 'error');
                }
            }).catch(function () { setStatus('Network error.', 'error'); });
        });
    }

    // Company Wiki action delegates to the shared manager, updating the row badge.
    function actionWiki(item) {
        openWikiManager(item.unique_name, item.display_name || item.unique_name, function (counts) {
            item.wiki_links = counts;
            setWikiCell(item);
        });
    }

    // Shared wiki-link manager. targetUnique is the facilities_master unique_name
    // to link against — a company OR an individual facility's own record.
    // onCounts(counts) is called whenever the linked set changes (for badge updates).
    function openWikiManager(targetUnique, label, onCounts) {
        var body = el('div', 'dm-form');
        body.innerHTML =
            '<p class="dm-muted">Wiki entries linked to <strong>' + esc(label) + '</strong>. Confirm a suggested link, unlink it, or repoint it.</p>' +
            '<div class="dm-wiki-list dm-muted">Loading…</div>' +
            '<hr class="dm-wiki-sep">' +
            '<label>Find a wiki entry to link here</label>' +
            '<input type="search" class="dm-wiki-search" placeholder="Search wiki entries by name…" value="' + esc(label) + '">' +
            '<div class="dm-wiki-results dm-muted"></div>';
        openModal('Wiki links: ' + label, body);

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
                var here = r.facility_unique_name === targetUnique;
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
                            facility_unique_name: targetUnique, force: true }, function () {
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
            getJson(API.manager + '?action=get_wiki_links&unique_name=' + encodeURIComponent(targetUnique))
                .then(function (d) {
                    if (!d || !d.success) { list.innerHTML = '<div class="dm-error">Failed to load.</div>'; return; }
                    if (onCounts) onCounts(countsFromLinks(d.links)); // update row badge in place
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
                    setTimeout(function () { openWikiManager(targetUnique, label, onCounts); }, 700);
                });
            }, 'Search new program…');
            bodyNode.appendChild(search);
            var back = el('button', 'kop-dm-btn kop-dm-btn-ghost', '← Back to links');
            back.type = 'button';
            back.addEventListener('click', function () { openWikiManager(targetUnique, label, onCounts); });
            bodyNode.appendChild(back);
            openModal('Repoint wiki link', bodyNode);
        }

        function wikiOp(payload, done) {
            postJson(API.linkWiki, payload).then(function (res) {
                // reload() (called via done) refreshes the modal list AND the row
                // badge through onCounts — no full table rebuild needed.
                if (res.data && res.data.success) { if (done) done(); }
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
                        removeRowDom(item); // drop just this row; keep place
                        state.total = Math.max(0, state.total - 1);
                        renderPagination();
                        setTimeout(closeModal, 800);
                    } else {
                        setStatus(esc((res.data && (res.data.error || res.data.message)) || 'Delete failed.'), 'error');
                    }
                })
                .catch(function () { setStatus('Network error.', 'error'); });
        });
    }

    // ---- initial scrape (bulk link everything) ----
    function actionScrape() {
        var body = el('div', 'dm-form');
        body.innerHTML =
            '<p class="dm-muted">This walks every program (skipping location aggregates) and, using strong name matches:</p>' +
            '<ul class="dm-auto-notes">' +
            '<li>' + ((typeof kopIcon === 'function') ? kopIcon('folder-open') : '') + ' sets a document folder where one is <strong>empty</strong> and a folder name matches,</li>' +
            '<li>' + ((typeof kopIcon === 'function') ? kopIcon('link') : '') + ' links every <strong>unlinked</strong> wiki entry whose organization matches a program (as <em>suggested</em>, for review).</li>' +
            '</ul>' +
            '<p class="dm-muted">It never overwrites an existing folder and never moves an already-linked wiki entry. Safe to re-run.</p>' +
            '<div class="dm-scrape-progress" style="display:none;"><div class="dm-scrape-bar"><div class="dm-scrape-fill"></div></div>' +
            '<div class="dm-scrape-stat dm-muted"></div></div>' +
            '<div class="dm-form-actions">' +
            '<button type="button" class="kop-dm-btn kop-dm-btn-ghost dm-cancel">Cancel</button>' +
            '<button type="button" class="kop-dm-btn dm-confirm">Run scrape</button></div>';
        openModal('Initial Scrape — link everything', body);

        var cancelBtn = body.querySelector('.dm-cancel');
        var runBtn = body.querySelector('.dm-confirm');
        var progressWrap = body.querySelector('.dm-scrape-progress');
        var fill = body.querySelector('.dm-scrape-fill');
        var stat = body.querySelector('.dm-scrape-stat');
        var cancelled = false;
        cancelBtn.addEventListener('click', function () { cancelled = true; closeModal(); });

        runBtn.addEventListener('click', function () {
            runBtn.disabled = true;
            cancelBtn.textContent = 'Stop';
            progressWrap.style.display = 'block';
            var totals = { folders: 0, wiki: 0, total: 0 };

            function step(offset) {
                if (cancelled) return;
                postJson(API.manager, { action: 'scrape', offset: offset, limit: 50 })
                    .then(function (res) {
                        if (!res.data || !res.data.success) {
                            stat.innerHTML = '<span class="dm-error">' + esc((res.data && res.data.error) || 'Scrape failed.') + '</span>';
                            runBtn.disabled = false;
                            return;
                        }
                        var d = res.data;
                        totals.folders += d.folders_set || 0;
                        totals.wiki += d.wiki_linked || 0;
                        totals.total = d.total || 0;
                        var done = Math.min(d.next_offset, d.total);
                        var pct = d.total ? Math.round(done / d.total * 100) : 100;
                        fill.style.width = pct + '%';
                        stat.textContent = done + ' / ' + d.total + ' programs · ' +
                            totals.folders + ' folders set · ' + totals.wiki + ' wiki links';
                        if (d.done || cancelled) {
                            stat.innerHTML = '<span class="dm-status-ok">✓ Done — ' + totals.folders +
                                ' folders set, ' + totals.wiki + ' wiki entries linked (suggested).</span>';
                            cancelBtn.textContent = 'Close';
                            runBtn.disabled = false;
                            runBtn.textContent = 'Re-run';
                            load(); // refresh the table to show new links/badges
                        } else {
                            step(d.next_offset);
                        }
                    })
                    .catch(function () {
                        stat.innerHTML = '<span class="dm-error">Network error — stopped. Re-run to continue (it skips already-linked items).</span>';
                        runBtn.disabled = false;
                    });
            }
            step(0);
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
                row.innerHTML = '<span class="dm-fb-name">' + ((typeof kopIcon === 'function') ? kopIcon('folder') : '') + ' ' + esc(node.name) + ' <span class="dm-id">#' + esc(node.id) + '</span></span>';

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
        if ($('dmScrape')) $('dmScrape').addEventListener('click', actionScrape);
        $('dmPrev').addEventListener('click', function () { if (state.offset > 0) { state.offset -= state.limit; load(); } });
        $('dmNext').addEventListener('click', function () { if (state.offset + state.limit < state.total) { state.offset += state.limit; load(); } });

        $('dmModal').querySelector('.kop-dm-modal-close').addEventListener('click', closeModal);
        $('dmModal').addEventListener('click', function (e) { if (e.target === this) closeModal(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

        load();
    });
})();
