/**
 * The review inbox on the Submissions Review page (inc/review-inbox.php).
 *
 * Adds a tab for every other queue (closure reports, Drive Docs, Fornits,
 * Woodbury, merges, ...) under the page's own five, and draws one kind of
 * card for all of them: change the category and tags right on the card,
 * "Edit details" for the rest of the fields with "Fill empty fields with AI",
 * "Move to" another queue where the source allows it, and the queue's own
 * buttons. After a button the card shows what happened and, where the queue
 * has one, its Undo.
 *
 * window.kopReviewInbox.api(path, body) is used by js/admin-submissions.js
 * for the same tags / AI / move controls on its own five types.
 */
(function () {
    'use strict';

    var cfg = window.kopReviewInbox || {};
    if (!cfg.rest) return;

    var page = document.querySelector('.admin-submissions-page');
    if (!page) return;

    var state = { sources: [], byKey: {}, tags: [], source: null, view: '', search: '', offset: 0, limit: 25, total: 0 };

    function api(path, body, query) {
        var url = cfg.rest + path;
        if (query) {
            var parts = [];
            Object.keys(query).forEach(function (k) {
                if (query[k] !== undefined && query[k] !== null && query[k] !== '') parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(query[k]));
            });
            if (parts.length) url += (url.indexOf('?') === -1 ? '?' : '&') + parts.join('&');
        }
        var opts = { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } };
        if (body) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        return fetch(url, opts).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok) throw new Error(data && (data.error || data.message) || ('Request failed (' + res.status + ')'));
                return data;
            });
        });
    }
    cfg.api = api;

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                var v = attrs[k];
                if (v === null || v === undefined || v === false) return;
                if (k === 'text') node.textContent = v;
                else if (k === 'class') node.className = v;
                else if (k.indexOf('on') === 0 && typeof v === 'function') node.addEventListener(k.slice(2), v);
                else node.setAttribute(k, v === true ? '' : v);
            });
        }
        (children || []).forEach(function (c) {
            if (c === null || c === undefined || c === false) return;
            node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return node;
    }

    function linkLabel(url) {
        return typeof window.kopUrlLabel === 'function' ? window.kopUrlLabel(url) : url;
    }

    function safeHref(url) {
        return /^https?:\/\//i.test(String(url || '')) ? url : null;
    }

    /* ---- Tabs -------------------------------------------------------------- */

    var tabsRow = el('div', { class: 'rinbox-tabs', role: 'tablist', 'aria-label': 'Other review queues' });
    var panel = el('section', { class: 'rinbox-panel', hidden: true, 'aria-live': 'polite' });
    var controls = page.querySelector('.admin-controls');
    var typeTabs = page.querySelector('.type-tabs');
    if (typeTabs && typeTabs.parentNode) typeTabs.parentNode.insertBefore(tabsRow, typeTabs.nextSibling);
    var listContainer = page.querySelector('.submissions-list-container');
    if (listContainer) listContainer.parentNode.insertBefore(panel, listContainer);

    function renderTabs() {
        tabsRow.innerHTML = '';
        var groups = {};
        var order = [];
        state.sources.forEach(function (s) {
            if (s.native) return;
            if (!groups[s.group]) { groups[s.group] = []; order.push(s.group); }
            groups[s.group].push(s);
        });
        order.forEach(function (g) {
            var row = el('div', { class: 'rinbox-group' }, [el('span', { class: 'rinbox-group-label', text: g })]);
            groups[g].forEach(function (s) {
                var count = s.count ? String(s.count) : '';
                var btn = el('button', {
                    type: 'button', role: 'tab', class: 'submission-tab rinbox-tab' + (state.source === s.key ? ' is-active' : ''),
                    'aria-selected': state.source === s.key ? 'true' : 'false', 'data-source': s.key,
                    onclick: function () { openSource(s.key); }
                }, [s.label + ' ', el('span', { class: 'tab-count' + (s.count ? ' has-items' : ''), text: count })]);
                row.appendChild(btn);
            });
            tabsRow.appendChild(row);
        });
        // The page's own five tabs show their counts in data-count-for; a
        // native source's count is already there.
    }

    function loadSources(fresh) {
        return api('sources', null, fresh ? { fresh: 1 } : null).then(function (data) {
            state.sources = data.sources || [];
            state.tags = data.tags || [];
            state.byKey = {};
            state.sources.forEach(function (s) { state.byKey[s.key] = s; });
            renderTabs();
        }).catch(function (e) {
            tabsRow.textContent = 'Other queues could not be loaded: ' + e.message;
        });
    }

    function leaveInbox() {
        state.source = null;
        page.classList.remove('rinbox-active');
        panel.hidden = true;
        renderTabs();
    }

    // The page's own type tabs bring its own list back.
    if (typeTabs) {
        typeTabs.addEventListener('click', function (e) {
            if (e.target.closest('[data-type]')) leaveInbox();
        });
    }

    function setUrl() {
        try {
            var u = new URL(window.location.href);
            if (state.source) u.searchParams.set('type', state.source); else u.searchParams.delete('type');
            window.history.replaceState(null, '', u.toString());
        } catch (e) { /* old browser: the tab just is not remembered */ }
    }

    function openSource(key) {
        var s = state.byKey[key];
        if (!s || s.native) return;
        state.source = key;
        state.view = Object.keys(s.views)[0];
        state.search = '';
        state.offset = 0;
        page.classList.add('rinbox-active');
        page.querySelectorAll('.type-tabs .submission-tab').forEach(function (t) {
            t.classList.remove('is-active');
            t.setAttribute('aria-selected', 'false');
        });
        panel.hidden = false;
        renderTabs();
        setUrl();
        renderPanel();
        loadItems();
    }

    /* ---- Panel ------------------------------------------------------------- */

    var listEl, pagerEl, statusEl;

    function renderPanel() {
        var s = state.byKey[state.source];
        panel.innerHTML = '';
        var head = el('div', { class: 'rinbox-head' }, [
            el('h2', { text: s.label }),
            s.help ? el('p', { class: 'rinbox-help', text: s.help }) : null,
            s.tool_url ? el('a', { class: 'rinbox-tool-link', href: s.tool_url, text: 'Open the full ' + s.label + ' screen' }) : null
        ]);
        var views = el('div', { class: 'submission-tabs rinbox-views', role: 'tablist' });
        Object.keys(s.views).forEach(function (v) {
            views.appendChild(el('button', {
                type: 'button', role: 'tab', class: 'submission-tab' + (state.view === v ? ' is-active' : ''),
                'aria-selected': state.view === v ? 'true' : 'false',
                text: s.views[v],
                onclick: function () { state.view = v; state.offset = 0; renderPanel(); loadItems(); }
            }));
        });
        var search = el('input', { type: 'search', class: 'rinbox-search', placeholder: 'Search this queue…', value: state.search, 'aria-label': 'Search this queue' });
        var timer = null;
        search.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { state.search = search.value.trim(); state.offset = 0; loadItems(); }, 350);
        });
        statusEl = el('p', { class: 'rinbox-status' });
        listEl = el('div', { class: 'rinbox-list' });
        pagerEl = el('div', { class: 'rinbox-pager' });
        panel.appendChild(head);
        panel.appendChild(el('div', { class: 'rinbox-toolbar' }, [views, search]));
        panel.appendChild(statusEl);
        panel.appendChild(listEl);
        panel.appendChild(pagerEl);
    }

    function loadItems() {
        var src = state.source;
        statusEl.textContent = 'Loading…';
        listEl.innerHTML = '';
        pagerEl.innerHTML = '';
        api('items', null, { source: src, view: state.view, search: state.search, offset: state.offset, limit: state.limit }).then(function (data) {
            if (state.source !== src) return;
            state.total = data.total || 0;
            var items = data.items || [];
            statusEl.textContent = state.total ? (state.total + (state.total === 1 ? ' item' : ' items')) : 'Nothing here.';
            items.forEach(function (it) { listEl.appendChild(card(it)); });
            renderPager();
        }).catch(function (e) {
            statusEl.textContent = 'Could not load this queue: ' + e.message;
        });
    }

    function renderPager() {
        pagerEl.innerHTML = '';
        if (state.total <= state.limit) return;
        var from = state.offset + 1, to = Math.min(state.total, state.offset + state.limit);
        pagerEl.appendChild(el('button', {
            type: 'button', class: 'btn-secondary', text: 'Previous', disabled: state.offset === 0,
            onclick: function () { state.offset = Math.max(0, state.offset - state.limit); loadItems(); }
        }));
        pagerEl.appendChild(el('span', { text: ' ' + from + '–' + to + ' of ' + state.total + ' ' }));
        pagerEl.appendChild(el('button', {
            type: 'button', class: 'btn-secondary', text: 'Next', disabled: to >= state.total,
            onclick: function () { state.offset += state.limit; loadItems(); }
        }));
    }

    /* ---- Cards ------------------------------------------------------------- */

    function fieldInput(f, idPrefix) {
        var id = idPrefix + f.name;
        var input;
        var type = f.type || 'text';
        if (type === 'textarea') {
            input = el('textarea', { id: id, rows: 4 });
            input.value = f.value || '';
        } else if (type === 'list') {
            input = el('textarea', { id: id, rows: 3, placeholder: 'One per line' });
            input.value = Array.isArray(f.value) ? f.value.join('\n') : (f.value || '');
        } else if (type === 'select') {
            input = el('select', { id: id });
            var opts = f.options || {};
            if (!Object.prototype.hasOwnProperty.call(opts, '')) input.appendChild(el('option', { value: '', text: '—' }));
            Object.keys(opts).forEach(function (k) {
                var o = el('option', { value: k, text: opts[k] });
                if (String(f.value) === k) o.selected = true;
                input.appendChild(o);
            });
        } else if (type === 'facility') {
            input = el('input', { id: id, type: 'number', min: '1', placeholder: 'id', 'data-kop-facility-finder': '1' });
            input.value = f.value ? String(f.value) : '';
        } else {
            input = el('input', { id: id, type: type === 'number' ? 'number' : 'text' });
            input.value = f.value === null || f.value === undefined ? '' : String(f.value);
        }
        if (f.readonly) input.disabled = true;
        input.dataset.field = f.name;
        input.dataset.kind = type;
        return input;
    }

    function readInput(input) {
        var kind = input.dataset.kind;
        if (kind === 'list') return input.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean);
        if (kind === 'facility' || kind === 'number') return input.value.trim() === '' ? '' : input.value.trim();
        return input.value;
    }

    var cardSeq = 0;

    function card(item) {
        var src = state.source;
        var s = state.byKey[src];
        var node = el('article', { class: 'rinbox-card', 'data-key': item.key });
        fillCard(node, item, s, src);
        return node;
    }

    function fillCard(node, item, s, src, message, isError) {
        node.innerHTML = '';
        var prefix = 'rinbox-' + (++cardSeq) + '-';
        var category = (item.fields || []).filter(function (f) { return f.category; })[0];

        var title = el('h3', { class: 'rinbox-title', text: item.title || '(no name)' });
        var meta = el('div', { class: 'rinbox-meta' }, [
            item.status_label ? el('span', { class: 'status-badge rinbox-status-' + String(item.status || '').replace(/[^a-z_-]/gi, ''), text: item.status_label }) : null,
            item.subtitle ? el('span', { text: item.subtitle }) : null,
            item.created ? el('span', { class: 'rinbox-date', text: String(item.created).slice(0, 10) }) : null
        ]);
        var links = el('div', { class: 'rinbox-links' });
        if (item.facility) {
            links.appendChild(item.facility.url
                ? el('a', { href: item.facility.url, target: '_blank', rel: 'noopener', text: item.facility.name })
                : el('span', { text: item.facility.name }));
        }
        if (safeHref(item.url)) links.appendChild(el('a', { href: item.url, target: '_blank', rel: 'noopener', title: item.url, text: linkLabel(item.url) }));
        (item.links || []).forEach(function (l) {
            if (safeHref(l.url)) links.appendChild(el('a', { href: l.url, target: '_blank', rel: 'noopener', text: l.label }));
        });

        node.appendChild(el('header', { class: 'rinbox-card-head' }, [title, meta]));
        if (links.childNodes.length) node.appendChild(links);
        if (item.text) node.appendChild(el('p', { class: 'rinbox-text', text: item.text }));

        // Quick edits: category and tags save as soon as they change.
        var quick = el('div', { class: 'rinbox-quick' });
        if (category && s.can_save) {
            var catInput = fieldInput(category, prefix + 'cat-');
            catInput.addEventListener('change', function () {
                var fields = {};
                fields[category.name] = catInput.value;
                run(node, item, s, src, 'save', { fields: fields });
            });
            quick.appendChild(el('label', { class: 'rinbox-quick-field' }, [category.label + ' ', catInput]));
        }
        quick.appendChild(tagEditor(item, src, node));
        if (item.moves && item.moves.length) {
            var mv = el('select', { 'aria-label': 'Move to another queue' }, [el('option', { value: '', text: 'Move to…' })]);
            item.moves.forEach(function (m) { mv.appendChild(el('option', { value: m.id, text: m.label })); });
            mv.addEventListener('change', function () {
                if (!mv.value) return;
                run(node, item, s, src, 'act', { action: 'move', params: { to: mv.value } });
            });
            quick.appendChild(el('label', { class: 'rinbox-quick-field' }, [mv]));
        }
        node.appendChild(quick);

        // Details editor.
        var editable = (item.fields || []).length && s.can_save;
        var editor = null;
        if (editable) {
            editor = el('form', { class: 'rinbox-editor', hidden: true });
            var grid = el('div', { class: 'rinbox-editor-grid' });
            item.fields.forEach(function (f) {
                var input = fieldInput(f, prefix);
                var wide = f.type === 'textarea' || f.type === 'list';
                grid.appendChild(el('div', { class: 'rinbox-field' + (wide ? ' rinbox-field-wide' : '') }, [
                    el('label', { for: prefix + f.name, text: f.label }), input
                ]));
            });
            editor.appendChild(grid);
            var saveBtn = el('button', { type: 'submit', class: 'btn-save-edits', text: 'Save' });
            var aiBtn = s.can_ai ? el('button', {
                type: 'button', class: 'btn-secondary rinbox-ai', text: 'Fill empty fields with AI',
                title: 'Reads this item and the page it links to, and fills only the fields that are empty',
                onclick: function () { run(node, item, s, src, 'ai', {}, aiBtn); }
            }) : null;
            editor.appendChild(el('div', { class: 'rinbox-editor-actions' }, [saveBtn, aiBtn]));
            editor.addEventListener('submit', function (e) {
                e.preventDefault();
                var fields = {};
                editor.querySelectorAll('[data-field]').forEach(function (input) {
                    if (!input.disabled) fields[input.dataset.field] = readInput(input);
                });
                run(node, item, s, src, 'save', { fields: fields }, saveBtn);
            });
            node.appendChild(editor);
            if (typeof window.kopFacilityFinderAttach === 'function') {
                editor.querySelectorAll('input[data-kop-facility-finder]').forEach(window.kopFacilityFinderAttach);
            }
        }

        // The queue's own buttons.
        var bar = el('div', { class: 'rinbox-actions' });
        if (editor) {
            bar.appendChild(el('button', {
                type: 'button', class: 'btn-secondary rinbox-edit-toggle', text: 'Edit details', 'aria-expanded': 'false',
                onclick: function (e) {
                    editor.hidden = !editor.hidden;
                    e.currentTarget.setAttribute('aria-expanded', editor.hidden ? 'false' : 'true');
                    e.currentTarget.textContent = editor.hidden ? 'Edit details' : 'Close editor';
                }
            }));
        }
        (item.actions || []).forEach(function (a) {
            var paramInputs = (a.params || []).map(function (p) { return fieldInput(p, prefix + 'p-' + a.id + '-'); });
            var btn = el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-' + (a.style || 'neutral'), text: a.label,
                onclick: function () {
                    if (a.confirm && !window.confirm(a.confirm)) return;
                    var params = {};
                    paramInputs.forEach(function (input) { params[input.dataset.field] = readInput(input); });
                    run(node, item, s, src, 'act', { action: a.id, params: params }, btn);
                }
            });
            if (paramInputs.length) {
                var group = el('span', { class: 'rinbox-action-group' });
                (a.params || []).forEach(function (p, i) {
                    group.appendChild(el('label', { class: 'rinbox-param' }, [p.label + ' ', paramInputs[i]]));
                });
                group.appendChild(btn);
                bar.appendChild(group);
            } else {
                bar.appendChild(btn);
            }
        });
        node.appendChild(bar);
        if (typeof window.kopFacilityFinderAttach === 'function') {
            bar.querySelectorAll('input[data-kop-facility-finder]').forEach(window.kopFacilityFinderAttach);
        }

        var msg = el('p', { class: 'rinbox-message' + (isError ? ' is-error' : ''), role: 'status', text: message || '' });
        if (!message) msg.hidden = true;
        node.appendChild(msg);
    }

    function run(node, item, s, src, kind, body, btn) {
        var buttons = node.querySelectorAll('button, select, input, textarea');
        buttons.forEach(function (b) { b.disabled = true; });
        if (btn) btn.classList.add('is-busy');
        var payload = Object.assign({ source: src, key: item.key }, body);
        var busy = node.querySelector('.rinbox-message');
        if (busy) { busy.hidden = false; busy.className = 'rinbox-message'; busy.textContent = kind === 'ai' ? 'Reading the item and its link…' : 'Working…'; }
        return api(kind, payload).then(function (res) {
            var next = res.item || null;
            if (next) {
                fillCard(node, next, s, src, res.message);
            } else {
                node.classList.add('rinbox-gone');
                node.innerHTML = '';
                node.appendChild(el('p', { class: 'rinbox-message', text: res.message || 'Done.' }));
            }
            refreshCounts();
        }).catch(function (e) {
            fillCard(node, item, s, src, e.message, true);
        });
    }

    function tagEditor(item, src, node) {
        var tags = (item.tags || []).slice();
        var wrap = el('div', { class: 'rinbox-tags' });
        var listId = 'rinbox-tag-list';
        if (!document.getElementById(listId)) {
            var dl = el('datalist', { id: listId });
            state.tags.forEach(function (t) { dl.appendChild(el('option', { value: t })); });
            document.body.appendChild(dl);
        }
        function hasTag(t) {
            return tags.some(function (x) { return x.toLowerCase() === t.toLowerCase(); });
        }
        function save() {
            api('tags', { source: src, key: item.key, tags: tags }).then(function (res) {
                tags = res.tags || tags;
                item.tags = tags;
                res.tags.forEach(function (t) { if (state.tags.indexOf(t) === -1) state.tags.push(t); });
                draw();
            }).catch(function (e) {
                var m = node.querySelector('.rinbox-message');
                if (m) { m.hidden = false; m.className = 'rinbox-message is-error'; m.textContent = 'Tags not saved: ' + e.message; }
            });
        }
        var input = el('input', { type: 'text', class: 'rinbox-tag-input', list: listId, placeholder: 'Add tag', 'aria-label': 'Add a tag' });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                var t = input.value.trim();
                if (t && !hasTag(t)) { tags.push(t); save(); }
                input.value = '';
            } else if (e.key === 'Backspace' && input.value === '' && tags.length) {
                tags.pop();
                save();
            }
        });
        input.addEventListener('change', function () {
            var t = input.value.trim();
            if (t && state.tags.indexOf(t) !== -1 && !hasTag(t)) { tags.push(t); input.value = ''; save(); }
        });
        function draw() {
            wrap.innerHTML = '';
            wrap.appendChild(el('span', { class: 'rinbox-tags-label', text: 'Tags' }));
            tags.forEach(function (t) {
                wrap.appendChild(el('span', { class: 'rinbox-tag' }, [t, el('button', {
                    type: 'button', class: 'rinbox-tag-x', 'aria-label': 'Remove tag ' + t, text: '×',
                    onclick: function () { tags = tags.filter(function (x) { return x !== t; }); save(); }
                })]));
            });
            wrap.appendChild(input);
        }
        draw();
        return wrap;
    }

    var countTimer = null;
    function refreshCounts() {
        clearTimeout(countTimer);
        countTimer = setTimeout(function () {
            api('sources', null, { fresh: 1 }).then(function (data) {
                (data.sources || []).forEach(function (s) {
                    if (state.byKey[s.key]) state.byKey[s.key].count = s.count;
                });
                renderTabs();
            }).catch(function () { /* counts stay as they were */ });
        }, 800);
    }

    /* ---- The page's own cards (news, data, wiki, lawsuits, legislation) ---- */

    var typeInput = document.getElementById('typeFilter');
    var ownList = document.getElementById('submissionsList');

    function nativeQuickRow(card, item, src, message, isError) {
        var old = card.querySelector(':scope > .rinbox-native');
        var row = el('div', { class: 'rinbox-native' });
        var s = state.byKey[src] || { can_save: false, can_ai: false };
        var titleField = (item.fields || [])[0];
        var category = (item.fields || []).filter(function (f) { return f.category; })[0];
        var heading = card.querySelector('.submission-header h3');

        function after(res) {
            var next = res.item || item;
            if (heading && next.title && titleField) {
                // Keep the duplicate badge and other markup after the title text.
                if (heading.firstChild && heading.firstChild.nodeType === 3) heading.firstChild.nodeValue = next.title;
                else heading.insertBefore(document.createTextNode(next.title), heading.firstChild);
            }
            nativeQuickRow(card, next, src, res.message);
            // An open detail panel shows the old values: open it again.
            var view = card.querySelector('.btn-view');
            if (view && /hide/i.test(view.textContent)) { view.click(); setTimeout(function () { view.click(); }, 50); }
            refreshCounts();
        }
        function go(kind, body, btn) {
            row.querySelectorAll('button, select, input').forEach(function (b) { b.disabled = true; });
            if (btn) btn.classList.add('is-busy');
            msg.hidden = false;
            msg.className = 'rinbox-message';
            msg.textContent = kind === 'ai' ? 'Reading the article and filling empty fields…' : 'Working…';
            api(kind, Object.assign({ source: src, key: item.key }, body)).then(after).catch(function (e) {
                nativeQuickRow(card, item, src, e.message, true);
            });
        }

        if (titleField && s.can_save) {
            var nameInput = el('input', { type: 'text', class: 'rinbox-native-name', 'aria-label': titleField.label, value: titleField.value || '' });
            var nameForm = el('form', { class: 'rinbox-native-rename', hidden: true }, [
                nameInput, el('button', { type: 'submit', class: 'rinbox-btn rinbox-btn-neutral', text: 'Save name' })
            ]);
            nameForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var fields = {};
                fields[titleField.name] = nameInput.value;
                go('save', { fields: fields });
            });
            row.appendChild(el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-undo', text: 'Rename',
                onclick: function () { nameForm.hidden = !nameForm.hidden; if (!nameForm.hidden) nameInput.focus(); }
            }));
            row.appendChild(nameForm);
        }
        if (category && s.can_save) {
            var cat = fieldInput(category, 'rinbox-n' + item.key + '-');
            cat.addEventListener('change', function () {
                var fields = {};
                fields[category.name] = cat.value;
                go('save', { fields: fields });
            });
            row.appendChild(el('label', { class: 'rinbox-quick-field' }, [category.label + ' ', cat]));
        }
        row.appendChild(tagEditor(item, src, row));
        if (item.moves && item.moves.length) {
            var mv = el('select', { 'aria-label': 'Move to another queue' }, [el('option', { value: '', text: 'Move to…' })]);
            item.moves.forEach(function (m) { mv.appendChild(el('option', { value: m.id, text: m.label })); });
            mv.addEventListener('change', function () {
                if (mv.value) go('act', { action: 'move', params: { to: mv.value } });
            });
            row.appendChild(mv);
        }
        (item.actions || []).forEach(function (a) {
            row.appendChild(el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-' + (a.style || 'neutral'), text: a.label,
                onclick: function (e) { go('act', { action: a.id, params: {} }, e.currentTarget); }
            }));
        });
        if (s.can_ai) {
            row.appendChild(el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-neutral rinbox-ai', text: 'Fill empty fields with AI',
                title: 'Reads the item and the page it links to, and fills only the fields that are empty',
                onclick: function (e) { go('ai', {}, e.currentTarget); }
            }));
        }
        var msg = el('p', { class: 'rinbox-message' + (isError ? ' is-error' : ''), role: 'status', text: message || '' });
        if (!message) msg.hidden = true;
        row.appendChild(msg);

        var footer = card.querySelector(':scope > .submission-footer');
        if (old) old.replaceWith(row);
        else if (footer) card.insertBefore(row, footer);
        else card.appendChild(row);
    }

    var decorateTimer = null;
    function decorateNative() {
        if (!ownList || !typeInput) return;
        var src = typeInput.value;
        if (!state.byKey[src] || !state.byKey[src].native) return;
        var cards = Array.prototype.filter.call(ownList.querySelectorAll('.submission-card[data-id]'), function (c) {
            return c.dataset.rinbox !== src + ':' + c.dataset.id;
        });
        if (!cards.length) return;
        cards.forEach(function (c) { c.dataset.rinbox = src + ':' + c.dataset.id; });
        var keys = cards.map(function (c) { return c.dataset.id; });
        api('items', null, { source: src, keys: keys.join(',') }).then(function (data) {
            var byKey = {};
            (data.items || []).forEach(function (it) { byKey[it.key] = it; });
            cards.forEach(function (c) {
                if (byKey[c.dataset.id] && c.isConnected) nativeQuickRow(c, byKey[c.dataset.id], src);
            });
        }).catch(function () {
            cards.forEach(function (c) { delete c.dataset.rinbox; });
        });
    }
    if (ownList && window.MutationObserver) {
        new MutationObserver(function () {
            clearTimeout(decorateTimer);
            decorateTimer = setTimeout(decorateNative, 120);
        }).observe(ownList, { childList: true });
    }

    /* ---- Start ------------------------------------------------------------- */

    loadSources().then(function () {
        decorateNative();
        var wanted = null;
        try { wanted = new URL(window.location.href).searchParams.get('type'); } catch (e) { wanted = null; }
        if (wanted && state.byKey[wanted] && !state.byKey[wanted].native) openSource(wanted);
    });
})();
