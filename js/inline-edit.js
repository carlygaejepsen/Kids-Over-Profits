/**
 * Edit in place (inc/inline-edit.php): a pencil on every element marked
 * data-kop-edit, for admins only. The pencil opens the field's dialog, built
 * from the server's description of it; Save writes it and reloads the page
 * where it was.
 *
 * Pencils live in one layer over the page (position: absolute, in document
 * coordinates) so they never change a page's own layout, and are placed again
 * when the page's size or content changes.
 */
(function () {
    'use strict';

    var C = window.KOP_INLINE_EDIT;
    if (!C || !document.body) return;

    var OFF_KEY = 'kopInlineEditOff';
    var SCROLL_KEY = 'kopInlineEditScroll';
    var on = true;
    try { on = localStorage.getItem(OFF_KEY) !== '1'; } catch (e) { /* private window */ }

    function icon(name) {
        return typeof window.kopIcon === 'function' ? window.kopIcon(name) : '';
    }

    function el(tag, attrs, children) {
        var n = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') n.textContent = attrs[k];
            else if (k === 'html') n.innerHTML = attrs[k];
            else if (k === 'class') n.className = attrs[k];
            else n.setAttribute(k, attrs[k]);
        });
        (children || []).forEach(function (c) { if (c) n.appendChild(c); });
        return n;
    }

    /* ---- Pencils --------------------------------------------------------- */

    var layer = el('div', { class: 'kop-ie-layer', 'aria-label': 'Edit buttons' });
    var box = el('div', { class: 'kop-ie-box', hidden: '' });
    layer.appendChild(box);
    document.body.appendChild(layer);
    var pens = []; // {target, button}

    /** The element's box; a display:contents wrapper has none, so its children's. */
    function rectOf(target) {
        if (getComputedStyle(target).display !== 'contents') return target.getBoundingClientRect();
        var r = null;
        Array.prototype.forEach.call(target.children, function (c) {
            var b = rectOf(c);
            if (!b.width && !b.height) return;
            r = r ? { top: Math.min(r.top, b.top), left: Math.min(r.left, b.left), right: Math.max(r.right, b.right), bottom: Math.max(r.bottom, b.bottom) }
                  : { top: b.top, left: b.left, right: b.right, bottom: b.bottom };
        });
        if (!r) return { top: 0, left: 0, right: 0, bottom: 0, width: 0, height: 0 };
        r.width = r.right - r.left;
        r.height = r.bottom - r.top;
        return r;
    }

    function scan() {
        var seen = new Set(pens.map(function (p) { return p.target; }));
        document.querySelectorAll('[data-kop-edit]').forEach(function (target) {
            if (seen.has(target) || layer.contains(target)) return;
            var label = target.getAttribute('data-kop-edit-label') || 'this';
            var b = el('button', { type: 'button', class: 'kop-ie-pen', title: 'Edit ' + label, 'aria-label': 'Edit ' + label, html: icon('pencil') });
            b.addEventListener('click', function () { openEditor(target.getAttribute('data-kop-edit')); });
            b.addEventListener('mouseenter', function () { highlight(target); });
            b.addEventListener('focus', function () { highlight(target); });
            b.addEventListener('mouseleave', function () { box.hidden = true; });
            b.addEventListener('blur', function () { box.hidden = true; });
            layer.appendChild(b);
            pens.push({ target: target, button: b });
        });
        pens = pens.filter(function (p) {
            if (document.contains(p.target)) return true;
            p.button.remove();
            return false;
        });
    }

    function highlight(target) {
        var r = rectOf(target);
        box.style.top = (r.top + window.scrollY - 3) + 'px';
        box.style.left = (r.left + window.scrollX - 3) + 'px';
        box.style.width = (r.width + 6) + 'px';
        box.style.height = (r.height + 6) + 'px';
        box.hidden = false;
    }

    function place() {
        layer.hidden = !on;
        if (!on) return;
        var taken = {};
        var maxX = document.documentElement.clientWidth - 30;
        pens.forEach(function (p) {
            var r = rectOf(p.target);
            if (!r.width && !r.height) { p.button.hidden = true; return; }
            p.button.hidden = false;
            var top = Math.round(r.top + window.scrollY);
            var left = Math.round(Math.min(Math.max(r.right + window.scrollX - 28, 2), maxX + window.scrollX));
            // Two pencils on one spot (an element and its first child) stack.
            var key = Math.round(top / 8) + ':' + Math.round(left / 8);
            while (taken[key]) { top += 28; key = Math.round(top / 8) + ':' + Math.round(left / 8); }
            taken[key] = true;
            p.button.style.top = top + 'px';
            p.button.style.left = left + 'px';
        });
    }

    var queued = false;
    function refresh() {
        if (queued) return;
        queued = true;
        requestAnimationFrame(function () { queued = false; scan(); place(); });
    }

    /* ---- Toolbar ------------------------------------------------------------ */

    var bar = el('div', { class: 'kop-ie-bar', role: 'region', 'aria-label': 'Edit this page' });
    var toggle = el('button', { type: 'button', class: 'kop-ie-bar-btn' });
    function paintToggle() {
        toggle.innerHTML = icon('pencil') + ' <span>' + (on ? 'Pencils on' : 'Pencils off') + '</span>';
        toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
    }
    toggle.addEventListener('click', function () {
        on = !on;
        try { localStorage.setItem(OFF_KEY, on ? '0' : '1'); } catch (e) { /* ignore */ }
        paintToggle();
        refresh();
    });
    paintToggle();
    bar.appendChild(toggle);
    (C.pageRefs || []).forEach(function (r) {
        var b;
        if (r.url) {
            b = el('a', { class: 'kop-ie-bar-btn', href: r.url, text: r.label });
        } else {
            b = el('button', { type: 'button', class: 'kop-ie-bar-btn', text: r.label });
            b.addEventListener('click', function () { openEditor(r.ref); });
        }
        bar.appendChild(b);
    });
    document.body.appendChild(bar);

    /* ---- Dialog -------------------------------------------------------------- */

    function api(method, body, ref) {
        var opts = { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': C.nonce } };
        var url = C.rest;
        if (method === 'GET') {
            url += (url.indexOf('?') >= 0 ? '&' : '?') + 'ref=' + encodeURIComponent(ref);
        } else {
            // A file part, not a form field: the host firewall rejects HTML in fields.
            var fd = new FormData();
            fd.append('payload', new Blob([JSON.stringify(body)], { type: 'application/json' }), 'payload.json');
            opts.body = fd;
        }
        return fetch(url, opts).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok) throw new Error(data && data.message ? data.message : 'The server said ' + res.status + '.');
                return data;
            });
        });
    }

    /** Lawsuits, legislation and news save through api/manage-submissions.php (it re-syncs facility links). */
    function saveVia(via, values) {
        return fetch(via.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_fields', type: via.type, id: via.id, fields: values })
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok || data.success === false) throw new Error(data.error || data.message || 'The server said ' + res.status + '.');
                return { message: 'Saved.' };
            });
        });
    }

    var dialog = null;

    function closeDialog() {
        if (!dialog) return;
        if (dialog.open) dialog.close();
        dialog.remove();
        dialog = null;
    }

    function openEditor(ref) {
        if (!ref) return;
        closeDialog();
        dialog = el('dialog', { class: 'kop-ie-dialog', 'aria-labelledby': 'kop-ie-title' });
        var title = el('h2', { id: 'kop-ie-title', class: 'kop-ie-title', text: 'Loading...' });
        var body = el('div', { class: 'kop-ie-body' });
        var status = el('p', { class: 'kop-ie-status', role: 'status' });
        var save = el('button', { type: 'submit', class: 'kop-ie-save', text: 'Save', disabled: '' });
        var cancel = el('button', { type: 'button', class: 'kop-ie-cancel', text: 'Cancel' });
        var form = el('form', { method: 'dialog', class: 'kop-ie-form' }, [title, body, el('div', { class: 'kop-ie-actions' }, [status, cancel, save])]);
        dialog.appendChild(form);
        document.body.appendChild(dialog);
        cancel.addEventListener('click', closeDialog);
        dialog.addEventListener('cancel', function (e) { e.preventDefault(); closeDialog(); });
        dialog.showModal();

        var spec = null;
        var getters = [];
        api('GET', null, ref).then(function (data) {
            spec = data;
            title.textContent = data.title || 'Edit';
            if (data.help) body.appendChild(el('p', { class: 'kop-ie-help', text: data.help }));
            var section = null;
            var holder = body;
            (data.fields || []).forEach(function (f) {
                if (f.section && f.section !== section) {
                    section = f.section;
                    holder = el('fieldset', { class: 'kop-ie-section' }, [el('legend', { text: section })]);
                    body.appendChild(holder);
                }
                var built = buildField(f);
                holder.appendChild(built.node);
                getters.push({ name: f.name, get: built.get });
            });
            save.removeAttribute('disabled');
            var first = body.querySelector('input, textarea, select');
            if (first) first.focus();
        }).catch(function (err) {
            title.textContent = 'Cannot edit this';
            status.textContent = err.message;
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!spec) return;
            var values = {};
            getters.forEach(function (g) { values[g.name] = g.get(); });
            save.setAttribute('disabled', '');
            status.textContent = 'Saving...';
            var done = spec.save_via ? saveVia(spec.save_via, values) : api('POST', { ref: ref, values: values });
            done.then(function (res) {
                status.textContent = (res && res.message) || 'Saved.';
                try { sessionStorage.setItem(SCROLL_KEY, String(window.scrollY)); } catch (err) { /* ignore */ }
                var here = location.href.split('#')[0];
                if (res && res.redirect && res.redirect.split('#')[0] !== here && res.redirect.split('#')[0] + '/' !== here) {
                    location.href = res.redirect;
                } else {
                    location.reload();
                }
            }).catch(function (err) {
                save.removeAttribute('disabled');
                status.textContent = 'Not saved: ' + err.message;
            });
        });
    }

    var uid = 0;

    /** One field: {node, get()}. */
    function buildField(f) {
        var id = 'kop-ie-f' + (++uid);
        var wrap = el('div', { class: 'kop-ie-field kop-ie-field--' + f.type });
        var label = el('label', { for: id, class: 'kop-ie-label', text: f.label });
        var help = f.help ? el('p', { class: 'kop-ie-help', text: f.help }) : null;
        var get;
        var v = f.value;

        function finish(input) {
            wrap.appendChild(label);
            if (help) wrap.appendChild(help);
            if (input) wrap.appendChild(input);
        }

        switch (f.type) {
            case 'textarea':
            case 'code':
            case 'lines': {
                var lines = typeof v === 'string' ? v.split('\n').length : 1;
                var ta = el('textarea', { id: id, class: 'kop-ie-input' + (f.type === 'code' ? ' kop-ie-code' : ''),
                    rows: String(f.rows || (f.type === 'lines' ? Math.max(3, lines + 1) : Math.min(14, Math.max(4, lines + 2)))) });
                if (f.type === 'code' || f.type === 'lines') ta.setAttribute('spellcheck', 'false');
                ta.value = v == null ? '' : String(v);
                if (f.type === 'lines' && !help) help = el('p', { class: 'kop-ie-help', text: 'One a line.' });
                finish(ta);
                get = function () { return ta.value; };
                break;
            }
            case 'select': {
                var sel = el('select', { id: id, class: 'kop-ie-input' });
                (f.options || []).forEach(function (o) {
                    var val = typeof o === 'object' ? o.value : o;
                    var opt = el('option', { value: val, text: typeof o === 'object' ? o.label : o });
                    if (String(val) === String(v == null ? '' : v)) opt.selected = true;
                    sel.appendChild(opt);
                });
                finish(sel);
                get = function () { return sel.value; };
                break;
            }
            case 'checks': {
                label = el('p', { class: 'kop-ie-label', text: f.label });
                var list = el('div', { class: 'kop-ie-checks' });
                var boxes = [];
                var checked = new Set((v || []).map(String));
                (f.options || []).forEach(function (o) {
                    var cb = el('input', { type: 'checkbox', value: o.value });
                    cb.checked = checked.has(String(o.value));
                    boxes.push(cb);
                    list.appendChild(el('label', { class: 'kop-ie-check' }, [cb, document.createTextNode(' ' + o.label)]));
                });
                finish(list);
                get = function () { return boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; }); };
                break;
            }
            case 'bool': {
                var one = el('input', { type: 'checkbox', id: id });
                one.checked = !!v;
                wrap.appendChild(el('label', { class: 'kop-ie-check', for: id }, [one, document.createTextNode(' ' + f.label)]));
                if (help) wrap.appendChild(help);
                get = function () { return one.checked; };
                break;
            }
            case 'items': {
                label = el('p', { class: 'kop-ie-label', text: f.label });
                var items = el('div', { class: 'kop-ie-items' });
                var addItem = function (text) {
                    var t = el('textarea', { class: 'kop-ie-input', rows: '3', 'aria-label': f.label });
                    t.value = text || '';
                    var rm = el('button', { type: 'button', class: 'kop-ie-mini', text: 'Remove' });
                    var row = el('div', { class: 'kop-ie-item' }, [t, rm]);
                    rm.addEventListener('click', function () { row.remove(); });
                    items.appendChild(row);
                    return t;
                };
                (v || []).forEach(function (t) { addItem(t); });
                var add = el('button', { type: 'button', class: 'kop-ie-mini', text: 'Add one' });
                add.addEventListener('click', function () { addItem('').focus(); });
                finish(items);
                wrap.appendChild(add);
                get = function () { return Array.prototype.map.call(items.querySelectorAll('textarea'), function (t) { return t.value; }); };
                break;
            }
            case 'rows': {
                label = el('p', { class: 'kop-ie-label', text: f.label });
                var cols = f.columns || {};
                var types = f.column_types || {};
                var keys = Object.keys(cols);
                var table = el('div', { class: 'kop-ie-rows' });
                var addRow = function (row) {
                    row = row || {};
                    var r = el('div', { class: 'kop-ie-row' });
                    r.dataset.i = row.__i == null ? '' : String(row.__i);
                    keys.forEach(function (k) {
                        var input;
                        if (types[k] === 'bool') {
                            input = el('input', { type: 'checkbox', 'data-k': k });
                            input.checked = row[k] === true || row[k] === '1' || row[k] === 'true';
                            r.appendChild(el('label', { class: 'kop-ie-check kop-ie-cell' }, [input, document.createTextNode(' ' + cols[k])]));
                            return;
                        }
                        input = types[k] === 'textarea'
                            ? el('textarea', { class: 'kop-ie-input', rows: '4', 'data-k': k, placeholder: cols[k], 'aria-label': cols[k] })
                            : el('input', { type: 'text', class: 'kop-ie-input', 'data-k': k, placeholder: cols[k], 'aria-label': cols[k] });
                        input.value = row[k] == null ? '' : String(row[k]);
                        r.appendChild(el('div', { class: 'kop-ie-cell' + (types[k] === 'textarea' ? ' kop-ie-cell--wide' : '') }, [input]));
                    });
                    var rm = el('button', { type: 'button', class: 'kop-ie-mini', text: 'Remove' });
                    rm.addEventListener('click', function () { r.remove(); });
                    r.appendChild(rm);
                    table.appendChild(r);
                    return r;
                };
                (v || []).forEach(function (row) { addRow(row); });
                var addR = el('button', { type: 'button', class: 'kop-ie-mini', text: 'Add a row' });
                addR.addEventListener('click', function () {
                    var r = addRow(null);
                    var first = r.querySelector('input, textarea');
                    if (first) first.focus();
                });
                finish(table);
                wrap.appendChild(addR);
                get = function () {
                    return Array.prototype.map.call(table.querySelectorAll('.kop-ie-row'), function (r) {
                        var out = { __i: r.dataset.i };
                        r.querySelectorAll('[data-k]').forEach(function (input) {
                            out[input.getAttribute('data-k')] = input.type === 'checkbox' ? input.checked : input.value;
                        });
                        return out;
                    });
                };
                break;
            }
            default: { // text, number, year
                var inp = el('input', { type: 'text', id: id, class: 'kop-ie-input' });
                if (f.type === 'number' || f.type === 'year') inp.setAttribute('inputmode', 'numeric');
                inp.value = v == null ? '' : String(v);
                finish(inp);
                get = function () { return inp.value; };
            }
        }
        return { node: wrap, get: get };
    }

    /* ---- Start ------------------------------------------------------------- */

    function start() {
        try {
            var y = sessionStorage.getItem(SCROLL_KEY);
            if (y !== null) {
                sessionStorage.removeItem(SCROLL_KEY);
                window.scrollTo(0, parseInt(y, 10) || 0);
            }
        } catch (e) { /* ignore */ }
        refresh();
        window.addEventListener('resize', refresh);
        window.addEventListener('load', refresh);
        document.addEventListener('click', function () { setTimeout(refresh, 50); }, true);
        if (window.ResizeObserver) new ResizeObserver(refresh).observe(document.body);
        new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                if (!layer.contains(records[i].target) && !(dialog && dialog.contains(records[i].target))) { refresh(); return; }
            }
        }).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden', 'class', 'open'] });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
