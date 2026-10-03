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

    var state = { sources: [], byKey: {}, tags: [], source: null, view: '', search: '', origin: '', offset: 0, limit: 25, total: 0 };

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

    /** Options for a "Came from" select: Everywhere, then each origin with its count. */
    function fillOrigins(select, origins, keep) {
        select.innerHTML = '';
        select.appendChild(el('option', { value: '', text: 'Everywhere' }));
        var found = false;
        origins.forEach(function (o) {
            var opt = el('option', { value: o.key, text: o.label + ' (' + o.count + ')' });
            if (o.key === keep) { opt.selected = true; found = true; }
            select.appendChild(opt);
        });
        if (keep && !found) {
            // Still offer the current choice when nothing of it is left in this view.
            var opt = el('option', { value: keep, text: keep + ' (0)' });
            opt.selected = true;
            select.appendChild(opt);
        }
        select.disabled = origins.length === 0 && !keep;
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
        state.origin = '';
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

    var listEl, pagerEl, statusEl, bulkEl;

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
        var origin = null;
        if (s.has_origins) {
            origin = el('select', { class: 'rinbox-origin', 'aria-label': 'Came from' });
            fillOrigins(origin, [], state.origin);
            origin.addEventListener('change', function () { state.origin = origin.value; state.offset = 0; loadItems(); });
            api('origins', null, { source: state.source, view: state.view }).then(function (data) {
                fillOrigins(origin, data.origins || [], state.origin);
            }).catch(function () { /* no filter */ });
        }
        var search = el('input', { type: 'search', class: 'rinbox-search', placeholder: 'Search this queue…', value: state.search, 'aria-label': 'Search this queue' });
        var timer = null;
        search.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { state.search = search.value.trim(); state.offset = 0; loadItems(); }, 350);
        });
        var tools = null;
        if (s.tools && s.tools.length) {
            tools = el('div', { class: 'rinbox-tools' });
            s.tools.forEach(function (t) {
                tools.appendChild(paramButton(t, 'rinbox-tool-', function (params, btn) {
                    btn.disabled = true;
                    statusEl.textContent = 'Working…';
                    api('tool', { source: state.source, tool: t.id, params: params }).then(function (res) {
                        btn.disabled = false;
                        loadItems(res.message);
                        refreshCounts();
                    }).catch(function (e) {
                        btn.disabled = false;
                        statusEl.textContent = e.message;
                    });
                }));
            });
        }
        statusEl = el('p', { class: 'rinbox-status', role: 'status' });
        bulkEl = el('div', { class: 'rinbox-bulk' });
        listEl = el('div', { class: 'rinbox-list' });
        pagerEl = el('div', { class: 'rinbox-pager' });
        panel.appendChild(head);
        panel.appendChild(el('div', { class: 'rinbox-toolbar' }, [views, el('span', { class: 'rinbox-filters' }, [
            origin ? el('label', { class: 'rinbox-quick-field' }, ['Came from ', origin]) : null, search
        ])]));
        if (tools) {
            panel.appendChild(tools);
            if (typeof window.kopFacilityFinderAttach === 'function') {
                tools.querySelectorAll('input[data-kop-facility-finder]').forEach(window.kopFacilityFinderAttach);
            }
        }
        panel.appendChild(statusEl);
        panel.appendChild(bulkEl);
        panel.appendChild(listEl);
        panel.appendChild(pagerEl);
    }

    function loadItems(note) {
        var src = state.source;
        statusEl.textContent = 'Loading…';
        bulkEl.innerHTML = '';
        listEl.innerHTML = '';
        pagerEl.innerHTML = '';
        api('items', null, { source: src, view: state.view, search: state.search, origin: state.origin, offset: state.offset, limit: state.limit }).then(function (data) {
            if (state.source !== src) return;
            state.total = data.total || 0;
            var items = data.items || [];
            statusEl.textContent = (note ? note + ' ' : '') + (state.total ? (state.total + (state.total === 1 ? ' item' : ' items')) : 'Nothing here.');
            items.forEach(function (it) { listEl.appendChild(card(it)); });
            renderBulk();
            renderPager();
        }).catch(function (e) {
            statusEl.textContent = 'Could not load this queue: ' + e.message;
        });
    }

    /** "Select all" and the buttons every selected card has (those that need no answer, or have one filled in). */
    function renderBulk() {
        bulkEl.innerHTML = '';
        var cards = listEl.querySelectorAll('.rinbox-card');
        if (cards.length < 2) return;
        var all = el('input', { type: 'checkbox', 'aria-label': 'Select every item on this page' });
        var count = el('span', { class: 'rinbox-bulk-count', text: '0 selected' });
        var buttons = el('span', { class: 'rinbox-bulk-actions' });
        all.addEventListener('change', function () {
            listEl.querySelectorAll('.rinbox-select').forEach(function (c) { if (!c.disabled) c.checked = all.checked; });
            update();
        });
        listEl.onchange = function (e) { if (e.target.classList.contains('rinbox-select')) update(); };
        function selected() {
            return Array.prototype.filter.call(listEl.querySelectorAll('.rinbox-card'), function (c) {
                var box = c.querySelector('.rinbox-select');
                return box && box.checked && c.kopItem;
            });
        }
        function update() {
            var sel = selected();
            count.textContent = sel.length + ' selected';
            buttons.innerHTML = '';
            if (!sel.length) return;
            (sel[0].kopItem.actions || []).forEach(function (a) {
                var ready = function (c) {
                    var mine = (c.kopItem.actions || []).filter(function (b) { return b.id === a.id; })[0];
                    return mine && (mine.params || []).every(function (p) { return p.value !== '' && p.value !== null && p.value !== undefined && p.value !== 0; });
                };
                if (!sel.every(ready)) return;
                buttons.appendChild(el('button', {
                    type: 'button', class: 'rinbox-btn rinbox-btn-' + (a.style || 'neutral'), text: a.label + ' (' + sel.length + ')',
                    onclick: function () { runBulk(sel, a); }
                }));
            });
        }
        bulkEl.appendChild(el('label', { class: 'rinbox-quick-field' }, [all, ' Select all']));
        bulkEl.appendChild(count);
        bulkEl.appendChild(buttons);
    }

    function runBulk(cards, action) {
        if (action.confirm && !window.confirm(action.confirm + ' (' + cards.length + ' items)')) return;
        var s = state.byKey[state.source], src = state.source;
        var done = 0, failed = 0;
        statusEl.textContent = 'Working on ' + cards.length + '…';
        var chain = Promise.resolve();
        cards.forEach(function (node) {
            chain = chain.then(function () {
                var item = node.kopItem;
                var mine = (item.actions || []).filter(function (b) { return b.id === action.id; })[0] || action;
                var params = {};
                (mine.params || []).forEach(function (p) { params[p.name] = p.value; });
                return api('act', { source: src, key: item.key, action: action.id, params: params }).then(function (res) {
                    done++;
                    if (res.item) fillCard(node, res.item, s, src, res.message);
                    else { node.classList.add('rinbox-gone'); node.innerHTML = ''; node.appendChild(el('p', { class: 'rinbox-message', text: res.message || 'Done.' })); }
                }).catch(function (e) {
                    failed++;
                    fillCard(node, item, s, src, e.message, true);
                });
            });
        });
        chain.then(function () {
            statusEl.textContent = action.label + ': ' + done + ' done' + (failed ? ', ' + failed + ' could not be (their cards say why)' : '') + '.';
            renderBulk();
            refreshCounts();
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
        } else if (type === 'checkbox') {
            input = el('input', { id: id, type: 'checkbox' });
            input.checked = !!f.value && f.value !== '0';
        } else if (type === 'facilities') {
            // Several records: chips, and the facility finder adds one per pick.
            input = facilitiesInput(id, f.value);
        } else {
            input = el('input', { id: id, type: type === 'number' ? 'number' : 'text' });
            input.value = f.value === null || f.value === undefined ? '' : String(f.value);
        }
        if (f.readonly) input.disabled = true;
        input.dataset.field = f.name;
        input.dataset.kind = type;
        return input;
    }

    function facilitiesInput(id, value) {
        var box = el('span', { id: id, class: 'rinbox-facilities' });
        var chips = el('span', { class: 'rinbox-facility-chips' });
        var finder = el('input', { type: 'hidden', 'data-kop-facility-finder': 'multi' });
        var picked = [];
        (Array.isArray(value) ? value : (value ? [value] : [])).forEach(function (v) {
            picked.push(typeof v === 'object' ? { id: String(v.id), name: v.name || ('#' + v.id) } : { id: String(v), name: '#' + v });
        });
        function draw() {
            box.dataset.ids = JSON.stringify(picked.map(function (p) { return p.id; }));
            chips.innerHTML = '';
            picked.forEach(function (p) {
                chips.appendChild(el('span', { class: 'rinbox-tag' }, [p.name, el('button', {
                    type: 'button', class: 'rinbox-tag-x', 'aria-label': 'Remove ' + p.name, text: '\u00d7',
                    onclick: function () { picked = picked.filter(function (x) { return x.id !== p.id; }); draw(); }
                })]));
            });
        }
        box.addEventListener('kop-facility-picked', function (e) {
            var f = e.detail || {};
            if (f.id && !picked.some(function (x) { return x.id === String(f.id); })) picked.push({ id: String(f.id), name: f.name || ('#' + f.id) });
            draw();
        });
        box.appendChild(chips);
        box.appendChild(finder);
        draw();
        return box;
    }

    function readInput(input) {
        var kind = input.dataset.kind;
        if (kind === 'checkbox') return input.checked ? '1' : '';
        if (kind === 'facilities') { try { return JSON.parse(input.dataset.ids || '[]'); } catch (e) { return []; } }
        if (kind === 'list') return input.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean);
        if (kind === 'facility' || kind === 'number') return input.value.trim() === '' ? '' : input.value.trim();
        return input.value;
    }

    var cardSeq = 0;

    /** A button that may first ask for a few values (a tool, or an action with params). go(params, button) runs it. */
    function paramButton(def, prefix, go) {
        var wrap = el('span', { class: 'rinbox-action-group' });
        var inputs = (def.params || []).map(function (p) {
            var input = fieldInput(p, prefix + def.id + '-');
            wrap.appendChild(el('label', { class: 'rinbox-param' }, [p.label + ' ', input]));
            return input;
        });
        var btn = el('button', {
            type: 'button', class: 'rinbox-btn rinbox-btn-' + (def.style || 'neutral'), text: def.label, title: def.help || null,
            onclick: function () {
                if (def.confirm && !window.confirm(def.confirm)) return;
                var params = {};
                inputs.forEach(function (input) { params[input.dataset.field] = readInput(input); });
                go(params, btn);
            }
        });
        wrap.appendChild(btn);
        return wrap;
    }

    /**
     * "Move to…": a destination with params (a facility website or resource
     * needs the facility; a resource also its kind) opens a small form first.
     * go(params) sends the move.
     */
    function moveControl(item, prefix, go) {
        var wrap = el('span', { class: 'rinbox-move' });
        var mv = el('select', { 'aria-label': 'Move to another queue' }, [el('option', { value: '', text: 'Move to…' })]);
        (item.moves || []).forEach(function (m) { mv.appendChild(el('option', { value: m.id, text: m.label })); });
        var form = el('span', { class: 'rinbox-move-form', hidden: true });
        wrap.appendChild(mv);
        wrap.appendChild(form);
        mv.addEventListener('change', function () {
            form.innerHTML = '';
            form.hidden = true;
            var m = (item.moves || []).filter(function (x) { return x.id === mv.value; })[0];
            if (!m) return;
            if (!m.params || !m.params.length) { go({ to: m.id }); return; }
            var inputs = m.params.map(function (p) {
                var input = fieldInput(p, prefix + 'mv-' + m.id + '-');
                form.appendChild(el('label', { class: 'rinbox-param' }, [p.label + ' ', input]));
                return input;
            });
            form.appendChild(el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-neutral', text: m.label,
                onclick: function () {
                    var params = { to: m.id };
                    inputs.forEach(function (input) { params[input.dataset.field] = readInput(input); });
                    go(params);
                }
            }));
            form.hidden = false;
            if (typeof window.kopFacilityFinderAttach === 'function') {
                form.querySelectorAll('input[data-kop-facility-finder]').forEach(window.kopFacilityFinderAttach);
            }
        });
        return wrap;
    }

    function card(item) {
        var src = state.source;
        var s = state.byKey[src];
        var node = el('article', { class: 'rinbox-card', 'data-key': item.key });
        fillCard(node, item, s, src);
        return node;
    }

    function fillCard(node, item, s, src, message, isError) {
        node.innerHTML = '';
        node.kopItem = item;
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

        var pick = (item.actions || []).length
            ? el('input', { type: 'checkbox', class: 'rinbox-select', 'aria-label': 'Select ' + (item.title || 'this item') })
            : null;
        node.appendChild(el('header', { class: 'rinbox-card-head' }, [pick ? el('span', { class: 'rinbox-title-row' }, [pick, title]) : title, meta]));
        if (links.childNodes.length) node.appendChild(links);
        if (item.text) node.appendChild(el('p', { class: 'rinbox-text', text: item.text }));
        if (item.details && item.details.length) {
            var dl = el('dl', { class: 'rinbox-details' });
            item.details.forEach(function (d) {
                dl.appendChild(el('dt', { text: d.label }));
                var dd = el('dd');
                if (d.url && safeHref(d.url)) dd.appendChild(el('a', { href: d.url, target: '_blank', rel: 'noopener', text: d.value || linkLabel(d.url) }));
                else dd.textContent = Array.isArray(d.value) ? d.value.join(', ') : String(d.value === null || d.value === undefined ? '' : d.value);
                dl.appendChild(dd);
            });
            node.appendChild(dl);
        }
        if (item.compare && item.compare.rows && item.compare.rows.length) {
            var table = el('table', { class: 'rinbox-compare' });
            var headRow = el('tr', null, [el('th', { scope: 'col', text: '' })]);
            (item.compare.heads || []).forEach(function (h) { headRow.appendChild(el('th', { scope: 'col', text: h })); });
            table.appendChild(el('thead', null, [headRow]));
            var body = el('tbody');
            item.compare.rows.forEach(function (r) {
                var tr = el('tr', { class: r.differs ? 'rinbox-differs' : null }, [el('th', { scope: 'row', text: r.label })]);
                (r.values || []).forEach(function (v) { tr.appendChild(el('td', { text: Array.isArray(v) ? v.join(', ') : String(v === null || v === undefined ? '' : v) })); });
                body.appendChild(tr);
            });
            table.appendChild(body);
            node.appendChild(el('div', { class: 'rinbox-compare-wrap' }, [table]));
        }
        if (item.preview && safeHref(item.preview.url)) {
            var frameBox = el('div', { class: 'rinbox-preview', hidden: true });
            var label = item.preview.label || 'Show pages';
            node.appendChild(el('button', {
                type: 'button', class: 'rinbox-btn rinbox-btn-undo rinbox-preview-toggle', text: label, 'aria-expanded': 'false',
                onclick: function (e) {
                    if (!frameBox.firstChild) frameBox.appendChild(el('iframe', { src: item.preview.url, title: label, loading: 'lazy' }));
                    frameBox.hidden = !frameBox.hidden;
                    e.currentTarget.setAttribute('aria-expanded', frameBox.hidden ? 'false' : 'true');
                    e.currentTarget.textContent = frameBox.hidden ? label : 'Hide';
                }
            }));
            node.appendChild(frameBox);
        }

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
            quick.appendChild(moveControl(item, prefix, function (params) {
                run(node, item, s, src, 'act', { action: 'move', params: params });
            }));
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
    var statusInput = document.getElementById('statusFilter');
    var originSelect = document.getElementById('originFilter');
    var originFor = '';

    /** Fill "Came from" for the page's own type and status, keeping the choice. */
    function refreshOwnOrigins(force) {
        if (!originSelect || !typeInput) return;
        var type = typeInput.value, status = statusInput ? statusInput.value : '';
        var s = state.byKey[type];
        var wanted = type + '|' + status;
        if (!force && wanted === originFor) return;
        originFor = wanted;
        if (!s || !s.has_origins) { fillOrigins(originSelect, [], ''); return; }
        api('origins', null, { source: type, status: status }).then(function (data) {
            fillOrigins(originSelect, data.origins || [], originSelect.value);
        }).catch(function () { /* the filter stays as it was */ });
    }
    if (originSelect) {
        originSelect.addEventListener('change', function () {
            var refresh = document.getElementById('refreshBtn');
            if (refresh) refresh.click();
        });
    }
    // A new type starts from "Everywhere" (capture: before the page's own tab handler loads the list).
    if (typeTabs) {
        typeTabs.addEventListener('click', function (e) {
            if (e.target.closest('[data-type]') && originSelect) { originSelect.value = ''; originFor = ''; }
        }, true);
    }

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
            refreshOwnOrigins(true);
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
            row.appendChild(moveControl(item, 'rinbox-n' + item.key + '-', function (params) {
                go('act', { action: 'move', params: params });
            }));
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
        refreshOwnOrigins(false);
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
