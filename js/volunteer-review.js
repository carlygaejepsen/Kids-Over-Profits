/**
 * /volunteer-review/ (templates/volunteer-review.php, inc/review-volunteers.php).
 *
 * A tab per queue open to volunteers and "Your recommendations". Each card
 * shows the item (title, link, facts, what approving or rejecting would do)
 * and three choices with a note: Recommend approve, Recommend reject, Not
 * sure. Saving files a recommendation; an admin makes the decision in the
 * review inbox. A recommendation can be taken back until the admin acts.
 *
 * Every request is a POST to kop/v1/volunteer/* with the X-KOP-Vol header
 * the page was given (window.kopVolunteer.page).
 */
(function () {
    'use strict';

    var cfg = window.kopVolunteer || {};
    var app = document.getElementById('kop-vol-app');
    if (!cfg.rest || !app) return;

    var state = { queues: [], queue: null, offset: 0, mine: false };

    function api(path, body) {
        var headers = { 'Content-Type': 'application/json' };
        if (cfg.page) headers['X-KOP-Vol'] = cfg.page;
        if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
        return fetch(cfg.rest + path, { method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify(body || {}) })
            .then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (data) {
                    if (!res.ok) throw new Error(data && (data.error || data.message) || ('Something went wrong (' + res.status + ').'));
                    return data;
                });
            });
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) return;
            if (k === 'text') node.textContent = v;
            else if (k === 'class') node.className = v;
            else if (k.slice(0, 2) === 'on') node.addEventListener(k.slice(2), v);
            else node.setAttribute(k, v === true ? '' : v);
        });
        (children || []).forEach(function (c) {
            if (c === null || c === undefined || c === '') return;
            node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        });
        return node;
    }

    function safe(url) {
        return /^https?:\/\//i.test(String(url || '')) ? url : null;
    }

    function host(url) {
        try { return new URL(url).hostname.replace(/^www\./, ''); } catch (e) { return 'the source'; }
    }

    function link(url, text) {
        return el('a', { href: url, target: '_blank', rel: 'noopener noreferrer', text: text });
    }

    function fmtDate(iso) {
        // A bare date is the day itself, not midnight UTC (which reads as the day before in the US).
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || ''));
        var d = m ? new Date(+m[1], m[2] - 1, +m[3]) : new Date(iso);
        return isNaN(d) ? String(iso || '').slice(0, 10) : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    /* ---- Tabs ----------------------------------------------------------------- */

    var tabs = el('nav', { class: 'kop-vol-tabs', 'aria-label': 'What to review' });
    var body = el('div', { class: 'kop-vol-body', 'aria-live': 'polite' });

    function renderTabs() {
        tabs.innerHTML = '';
        state.queues.forEach(function (q) {
            tabs.appendChild(el('button', {
                type: 'button', class: 'kop-vol-tab' + (!state.mine && state.queue === q.key ? ' is-active' : ''),
                'aria-pressed': !state.mine && state.queue === q.key ? 'true' : 'false',
                onclick: function () { openQueue(q.key); }
            }, [q.label, q.count ? el('span', { class: 'kop-vol-count', text: String(q.count) }) : null]));
        });
        tabs.appendChild(el('button', {
            type: 'button', class: 'kop-vol-tab kop-vol-tab-mine' + (state.mine ? ' is-active' : ''), 'aria-pressed': state.mine ? 'true' : 'false',
            onclick: openMine
        }, ['Your recommendations']));
    }

    function remember() {
        try {
            var u = new URL(window.location.href);
            u.searchParams.set('q', state.mine ? '_mine' : state.queue);
            window.history.replaceState(null, '', u.toString());
        } catch (e) { /* the tab just is not remembered */ }
    }

    /* ---- A queue ---------------------------------------------------------------- */

    var list;
    var more;
    var status;

    function openQueue(key) {
        state.queue = key;
        state.mine = false;
        state.offset = 0;
        renderTabs();
        remember();
        var q = state.queues.filter(function (x) { return x.key === key; })[0] || {};
        body.innerHTML = '';
        var head = el('section', { class: 'kop-vol-panel kop-vol-guide' }, [
            el('h2', { text: q.label }),
            q.guidance ? el('p', { text: q.guidance }) : null
        ]);
        status = el('p', { class: 'kop-vol-status', role: 'status', text: 'Loading…' });
        head.appendChild(status);
        list = el('div', { class: 'kop-vol-list' });
        more = el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain', text: 'Show more', hidden: true, onclick: loadMore });
        body.appendChild(head);
        body.appendChild(list);
        body.appendChild(el('div', { class: 'kop-vol-more' }, [more]));
        loadMore();
    }

    function loadMore() {
        more.hidden = true;
        var key = state.queue;
        api('items', { source: key, offset: state.offset, limit: 10 }).then(function (data) {
            if (key !== state.queue || state.mine) return;
            (data.items || []).forEach(function (it) { list.appendChild(card(it)); });
            state.offset = data.next_offset;
            var shown = list.querySelectorAll('.kop-vol-card').length;
            status.textContent = shown ? '' : 'Nothing waiting for you here right now. Thank you! Try another tab.';
            status.hidden = !!shown;
            more.hidden = !(data.next_offset >= 0 && (data.items || []).length);
        }).catch(function (e) {
            status.hidden = false;
            status.textContent = 'Could not load: ' + e.message;
        });
    }

    function card(it) {
        var node = el('article', { class: 'kop-vol-card kop-vol-panel' });
        fillCard(node, it);
        return node;
    }

    function fillCard(node, it) {
        node.innerHTML = '';
        node.appendChild(el('h3', { class: 'kop-vol-title', text: it.title || '(no title)' }));
        var meta = [it.subtitle, it.created ? 'Added ' + fmtDate(it.created) : ''].filter(Boolean).join(' · ');
        if (meta) node.appendChild(el('p', { class: 'kop-vol-meta', text: meta }));

        var links = el('ul', { class: 'kop-vol-links' });
        if (safe(it.url)) links.appendChild(el('li', null, [link(it.url, 'Open it on ' + host(it.url))]));
        if (it.facility && it.facility.name) {
            links.appendChild(el('li', null, [safe(it.facility.url) ? link(it.facility.url, 'Our page for ' + it.facility.name) : 'Program: ' + it.facility.name]));
        }
        (it.links || []).forEach(function (l) { if (safe(l.url)) links.appendChild(el('li', null, [link(l.url, l.label || host(l.url))])); });
        if (links.childNodes.length) node.appendChild(links);

        if (it.text) node.appendChild(el('p', { class: 'kop-vol-text', text: it.text }));
        if (it.facts && it.facts.length) {
            var dl = el('dl', { class: 'kop-vol-facts' });
            it.facts.forEach(function (f) {
                dl.appendChild(el('dt', { text: f.label }));
                dl.appendChild(el('dd', null, [safe(f.url) ? link(f.url, f.value) : f.value]));
            });
            node.appendChild(dl);
        }
        if (it.compare && it.compare.rows && it.compare.rows.length) {
            var table = el('table', { class: 'kop-vol-compare' });
            table.appendChild(el('thead', null, [el('tr', null, [el('th', { text: '' })].concat((it.compare.heads || []).map(function (h) { return el('th', { scope: 'col', text: h }); })))]));
            var tb = el('tbody');
            it.compare.rows.forEach(function (r) {
                tb.appendChild(el('tr', null, [el('th', { scope: 'row', text: r.label })].concat((r.values || []).map(function (v) { return el('td', { text: v }); }))));
            });
            table.appendChild(tb);
            node.appendChild(el('div', { class: 'kop-vol-compare-wrap' }, [table]));
        }

        if ((it.approve && it.approve.length) || (it.reject && it.reject.length)) {
            var what = el('div', { class: 'kop-vol-what' }, [el('p', { class: 'kop-vol-what-head', text: 'What the admin would do' })]);
            if (it.approve && it.approve.length) what.appendChild(el('p', null, [el('strong', { text: 'Approve: ' }), it.approve.join(' Or: ')]));
            if (it.reject && it.reject.length) what.appendChild(el('p', null, [el('strong', { text: 'Reject: ' }), it.reject.join(' Or: ')]));
            node.appendChild(what);
        }

        var id = 'kop-vol-note-' + Math.random().toString(36).slice(2);
        var note = el('textarea', { id: id, rows: '2', maxlength: '1000', placeholder: 'Why? (optional for approve or reject, needed for not sure)' });
        var msg = el('p', { class: 'kop-vol-msg', role: 'status', hidden: true });
        var buttons = [
            ['approve', 'Recommend approve', 'kop-vol-btn kop-vol-btn-approve'],
            ['reject', 'Recommend reject', 'kop-vol-btn kop-vol-btn-reject'],
            ['unsure', 'Not sure', 'kop-vol-btn kop-vol-btn-plain']
        ].map(function (b) {
            return el('button', { type: 'button', class: b[2], text: b[1], disabled: cfg.preview ? true : null, onclick: function () { send(b[0], this); } });
        });
        function send(verdict, btn) {
            buttons.forEach(function (b) { b.disabled = true; });
            msg.hidden = true;
            api('recommend', { source: it.source, key: it.key, verdict: verdict, note: note.value }).then(function (res) {
                done(node, it, verdict, note.value, res.message);
            }).catch(function (e) {
                buttons.forEach(function (b) { b.disabled = false; });
                msg.hidden = false;
                msg.className = 'kop-vol-msg is-error';
                msg.textContent = e.message;
                btn.focus();
            });
        }
        node.appendChild(el('div', { class: 'kop-vol-decide' }, [
            el('label', { for: id, text: 'Your note' }), note,
            el('div', { class: 'kop-vol-buttons' }, buttons), msg
        ]));
    }

    var SAID = { approve: 'approve', reject: 'reject', unsure: 'not sure' };

    function done(node, it, verdict, note, message) {
        node.innerHTML = '';
        node.classList.add('is-done');
        node.appendChild(el('p', { class: 'kop-vol-done' }, [
            el('strong', { text: it.title || '(no title)' }), ': you said ', el('em', { text: SAID[verdict] }), note ? ' (' + note + ')' : '', '. ',
            message || ''
        ]));
        node.appendChild(el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain', text: 'Take it back', onclick: function () {
            this.disabled = true;
            api('recommend', { source: it.source, key: it.key, withdraw: 1 }).then(function () {
                node.classList.remove('is-done');
                fillCard(node, it);
            }).catch(function (e) { node.appendChild(el('p', { class: 'kop-vol-msg is-error', text: e.message })); });
        } }));
    }

    /* ---- Your recommendations ---------------------------------------------------- */

    var OUTCOME = { '': 'Waiting for an admin', approve: 'An admin approved it', reject: 'An admin rejected it', gone: 'Handled elsewhere', dismissed: 'Closed by an admin' };

    function openMine() {
        state.mine = true;
        renderTabs();
        remember();
        body.innerHTML = '';
        var panel = el('section', { class: 'kop-vol-panel' }, [
            el('h2', { text: 'Your recommendations' }),
            el('p', { text: 'Everything you recommended and what the admin decided. You can take back a recommendation until an admin acts on it.' })
        ]);
        var st = el('p', { class: 'kop-vol-status', role: 'status', text: 'Loading…' });
        panel.appendChild(st);
        body.appendChild(panel);
        api('mine').then(function (data) {
            var rows = data.rows || [];
            st.textContent = rows.length ? '' : (cfg.preview ? 'Admins have no recommendations of their own.' : 'None yet.');
            st.hidden = !!rows.length;
            if (!rows.length) return;
            var ul = el('ul', { class: 'kop-vol-mine' });
            rows.forEach(function (r) {
                var same = (r.outcome === 'approve' || r.outcome === 'reject') && r.verdict !== 'unsure'
                    ? (r.outcome === r.verdict ? ' (same as you)' : ' (differently from you)') : '';
                var li = el('li', null, [
                    el('strong', { text: r.title || r.key }), el('span', { class: 'kop-vol-meta', text: ' ' + r.source_label + ', ' + fmtDate(r.at) }), el('br'),
                    'You said ', el('em', { text: String(r.verdict_label).toLowerCase() }), r.note ? ' (' + r.note + ')' : '', '. ',
                    el('span', { class: 'kop-vol-outcome', text: (OUTCOME[r.outcome] || r.outcome) + same + '.' })
                ]);
                if (r.outcome === '') {
                    li.appendChild(document.createTextNode(' '));
                    li.appendChild(el('button', { type: 'button', class: 'kop-vol-btn kop-vol-btn-plain kop-vol-btn-small', text: 'Take it back', onclick: function () {
                        var b = this;
                        b.disabled = true;
                        api('recommend', { source: r.source, key: r.key, withdraw: 1 }).then(function () {
                            li.remove();
                        }).catch(function (e) { b.disabled = false; li.appendChild(el('span', { class: 'kop-vol-msg is-error', text: ' ' + e.message })); });
                    } }));
                }
                ul.appendChild(li);
            });
            panel.appendChild(ul);
        }).catch(function (e) { st.textContent = 'Could not load: ' + e.message; });
    }

    /* ---- Start -------------------------------------------------------------------- */

    api('queues').then(function (data) {
        state.queues = data.queues || [];
        app.innerHTML = '';
        if (!state.queues.length) {
            app.appendChild(el('section', { class: 'kop-vol-panel' }, [el('p', { text: 'There is nothing to review right now. Thank you for checking!' })]));
            return;
        }
        app.appendChild(el('section', { class: 'kop-vol-panel kop-vol-tabs-panel' }, [tabs]));
        app.appendChild(body);
        var wanted = null;
        try { wanted = new URL(window.location.href).searchParams.get('q'); } catch (e) { wanted = null; }
        if (wanted === '_mine') openMine();
        else if (state.queues.some(function (q) { return q.key === wanted; })) openQueue(wanted);
        else openQueue((state.queues.filter(function (q) { return q.count; })[0] || state.queues[0]).key);
    }).catch(function (e) {
        app.innerHTML = '';
        app.appendChild(el('section', { class: 'kop-vol-panel' }, [el('p', { text: e.message })]));
    });
})();
