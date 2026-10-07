// KOP Tools > State Lists (inc/state-lists.php): one card per facility a state's list names that our
// records do not, with Link / Create / Not a TTI facility / Later, Undo on the Done tab, and the rows
// gone from a list on "Left the list". Every action posts to admin-ajax (action=kop_state_lists) and
// redraws from the data it answers with.
(function () {
    var cfgNode = document.getElementById('kop-sl-config');
    if (!cfgNode) return;
    var C = JSON.parse(cfgNode.textContent);
    var D = C.data;
    var root = document.querySelector('.kop-sl');
    var list = root.querySelector('.kop-sl__list');
    var status = root.querySelector('.kop-sl__status');
    var stateSel = root.querySelector('.kop-sl__state');
    var search = root.querySelector('.kop-sl__search');
    var covers = root.querySelector('.kop-sl__covers');
    var tab = 'open';
    var stateWant = '';
    try { stateWant = localStorage.getItem('kopSlState') || ''; } catch (e) {}

    function icon(name) {
        if (typeof window.kopIcon !== 'function') return null;
        var span = document.createElement('span');
        span.innerHTML = window.kopIcon(name);
        return span.firstChild;
    }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }
    function link(text, url) {
        if (!url) return el('strong', '', text);
        var a = el('a', '', text);
        a.href = url; a.target = '_blank'; a.rel = 'noopener';
        return a;
    }
    function button(label, cls, iconName) {
        var b = el('button', 'kop-sl__btn ' + (cls || ''));
        b.type = 'button';
        var i = iconName ? icon(iconName) : null;
        if (i) b.appendChild(i);
        b.appendChild(document.createTextNode(label));
        return b;
    }
    function help(text) { return el('p', 'kop-sl__help', text); }
    function place(f) { return [f.city, f.state].filter(Boolean).join(', '); }

    function tabOf(it) {
        if (!it.on_list) return 'left';
        // The name is now exactly one record's (kop_sl_on_file()): link it there.
        if (it.on_file && (it.status === 'open' || it.status === 'later')) return 'onfile';
        if (it.status === 'open') return 'open';
        if (it.status === 'later') return 'later';
        return 'done';
    }
    function visible(it) {
        if (stateWant && it.list !== stateWant) return false;
        var q = search.value.trim().toLowerCase();
        if (q && (it.name + ' ' + it.place + ' ' + (it.license_id || '')).toLowerCase().indexOf(q) === -1) return false;
        return true;
    }

    function send(op, id, params, card) {
        var body = new FormData();
        body.append('action', 'kop_state_lists');
        body.append('nonce', C.nonce);
        body.append('op', op);
        if (id) body.append('id', id);
        Object.keys(params || {}).forEach(function (k) { body.append('params[' + k + ']', params[k]); });
        status.className = 'kop-sl__status';
        status.textContent = 'Saving...';
        if (card) card.classList.add('is-busy');
        return fetch(C.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                var d = json && json.data ? json.data : {};
                if (d.data) D = d.data;
                status.className = 'kop-sl__status' + (json && json.success ? '' : ' is-bad');
                status.textContent = d.message || (json && json.success ? 'Done.' : 'That did not work; reload the page.');
                draw();
            })
            .catch(function () {
                status.className = 'kop-sl__status is-bad';
                status.textContent = 'The request failed; reload the page and try again.';
                if (card) card.classList.remove('is-busy');
            });
    }

    function head(it) {
        var h = el('div', 'kop-sl__head');
        h.appendChild(el('h3', 'kop-sl__name', it.name));
        var bits = [it.place, it.type, it.capacity ? 'capacity ' + it.capacity : '', it.license_id ? 'licence ' + it.license_id : ''].filter(Boolean);
        h.appendChild(el('p', 'kop-sl__meta', bits.join(' · ')));
        if (it.address) h.appendChild(el('p', 'kop-sl__meta', it.address));
        var src = el('p', 'kop-sl__src');
        src.appendChild(document.createTextNode('From the '));
        src.appendChild(link(it.source_text, it.source_url));
        if (it.list_date) src.appendChild(document.createTextNode(', list dated ' + it.list_date));
        h.appendChild(src);
        return h;
    }

    function openCard(it, card) {
        if (it.excluded) {
            card.appendChild(el('p', 'kop-sl__note', 'This name is on our ' + it.excluded.label + ' list ("' + it.excluded.name + '"). '
                + 'Those are never TTI facility records, so no record is offered or created here: mark it as not a TTI facility.'));
        } else if (it.ambiguous) {
            card.appendChild(el('p', 'kop-sl__note', 'More than one record begins with this name, so none was picked. Link the one the list means.'));
        }
        var addName = el('input');
        addName.type = 'checkbox';
        if (it.on_file) {
            var known = el('p', 'kop-sl__note');
            known.appendChild(document.createTextNode('Already on file: the listed name is a name of '));
            known.appendChild(link(it.on_file.name, it.on_file.url));
            known.appendChild(document.createTextNode(' (' + [place(it.on_file), '#' + it.on_file.id].filter(Boolean).join(' · ') + '). Link it there.'));
            card.appendChild(known);
            if (!it.candidates.some(function (c) { return c.id === it.on_file.id; })) {
                it.candidates = [Object.assign({ why: 'it has this exact name in the same state' }, it.on_file)].concat(it.candidates);
            }
        }
        if (!it.excluded) {
            var box = el('div', 'kop-sl__box');
            box.appendChild(el('h4', 'kop-sl__h', it.candidates.length ? 'Is it one of these records?' : 'Is it a record we have under another name?'));
            it.candidates.forEach(function (c) {
                var row = el('div', 'kop-sl__cand');
                var who = el('div', 'kop-sl__cand-who');
                who.appendChild(link(c.name, c.url));
                who.appendChild(el('span', 'kop-sl__meta', [place(c), c.status, '#' + c.id].filter(Boolean).join(' · ')));
                if (c.why) who.appendChild(el('span', 'kop-sl__why', 'Suggested because ' + c.why + '.'));
                row.appendChild(who);
                var b = button('Link to this record', 'kop-sl__btn--go', 'link');
                b.addEventListener('click', function () { send('link', it.id, { facility_id: c.id, add_name: addName.checked ? 1 : 0 }, card); });
                row.appendChild(b);
                box.appendChild(row);
            });
            var other = el('div', 'kop-sl__other');
            other.appendChild(el('span', 'kop-sl__label', it.candidates.length ? 'Another record:' : 'Find the record:'));
            var fid = el('input');
            fid.type = 'number'; fid.min = '1'; fid.placeholder = 'id'; fid.className = 'kop-sl__fid';
            fid.setAttribute('data-kop-facility-finder', '1');
            fid.setAttribute('aria-label', 'Facility record id');
            other.appendChild(fid);
            var go = button('Link to the record I picked', 'kop-sl__btn--plain', 'link');
            go.addEventListener('click', function () {
                if (!fid.value) { status.className = 'kop-sl__status is-bad'; status.textContent = 'Find the record by name first.'; return; }
                send('link', it.id, { facility_id: fid.value, add_name: addName.checked ? 1 : 0 }, card);
            });
            other.appendChild(go);
            box.appendChild(other);
            if (typeof window.kopFacilityFinderAttach === 'function') window.kopFacilityFinderAttach(fid);
            var lab = el('label', 'kop-sl__check');
            lab.appendChild(addName);
            lab.appendChild(document.createTextNode(' Also add "' + it.name + '" to the record\'s other names'));
            box.appendChild(lab);
            box.appendChild(help('Linking records that the listed facility is that record; the record itself changes only if the box is ticked. '
                + 'Off by default: a record\'s other names that match a name on the network map become rename lines there.'));
            card.appendChild(box);

            var cr = el('details', 'kop-sl__box kop-sl__create');
            cr.appendChild(el('summary', 'kop-sl__h', 'Not in our records? Create one from this row'));
            var form = el('div', 'kop-sl__form');
            function field(label, name, value, type) {
                var l = el('label', 'kop-sl__field');
                l.appendChild(el('span', '', label));
                var inp;
                if (type === 'select') {
                    inp = el('select');
                    [''].concat(D.types).forEach(function (t) {
                        var o = el('option', '', t || '(type unknown)'); o.value = t; if (t === value) o.selected = true; inp.appendChild(o);
                    });
                } else {
                    inp = el('input'); inp.type = 'text'; inp.value = value || '';
                }
                inp.name = name;
                l.appendChild(inp);
                form.appendChild(l);
                return inp;
            }
            var fName = field('Name', 'name', it.suggested_name);
            var fCity = field('Town', 'city', it.city);
            var fState = field('State', 'state', it.state);
            fState.maxLength = 2; fState.className = 'kop-sl__short';
            var fType = field('Type', 'type', it.type_guess, 'select');
            cr.appendChild(form);
            var mk = button('Create record', 'kop-sl__btn--go', 'plus');
            mk.addEventListener('click', function () {
                if (!window.confirm('Create a new facility record for "' + fName.value + '"?')) return;
                send('create', it.id, { name: fName.value, city: fCity.value, state: fState.value, type: fType.value }, card);
            });
            cr.appendChild(mk);
            cr.appendChild(help('Makes a new facility record with its own page, its notes and "Materials and links" citing this list. '
                + 'If that name and place already has a record, the row is linked to it instead. Undo deletes it while nobody has edited it.'));
            card.appendChild(cr);
        }
        var acts = el('div', 'kop-sl__acts');
        var no = button('Not a TTI facility', 'kop-sl__btn--no', 'x');
        no.addEventListener('click', function () { send('not_tti', it.id, {}, card); });
        acts.appendChild(no);
        if (it.status === 'later') {
            var back = button('Back to review', 'kop-sl__btn--plain', 'refresh');
            back.addEventListener('click', function () { send('undo', it.id, {}, card); });
            acts.appendChild(back);
        } else {
            var later = button('Later', 'kop-sl__btn--plain', 'hourglass');
            later.addEventListener('click', function () { send('later', it.id, {}, card); });
            acts.appendChild(later);
        }
        card.appendChild(acts);
        card.appendChild(help('"Not a TTI facility" (an ordinary boarding school, a camp, an adult program) changes nothing on the site; the row moves to Done. '
            + (it.status === 'later' ? '"Back to review" returns it to To review.' : '"Later" parks it on the Later tab.')));
    }

    function doneCard(it, card) {
        var p = el('p', 'kop-sl__decided');
        p.appendChild(el('strong', '', (D.statuses[it.status] || it.status) + (it.facility ? ': ' : '.')));
        if (it.facility) {
            p.appendChild(document.createTextNode(' '));
            p.appendChild(link(it.facility.name, it.facility.url));
            if (place(it.facility)) p.appendChild(document.createTextNode(' (' + place(it.facility) + ')'));
        }
        if (it.added_name) p.appendChild(document.createTextNode(' The listed name was added as an other name.'));
        if (it.decided_by) p.appendChild(el('span', 'kop-sl__meta', ' ' + it.decided_by + ', ' + it.decided_at + ' UTC'));
        card.appendChild(p);
        var undo = button('Undo', 'kop-sl__btn--plain', 'refresh');
        undo.addEventListener('click', function () {
            if (it.status === 'created' && !window.confirm('Delete the record created from this row?')) return;
            send('undo', it.id, {}, card);
        });
        card.appendChild(el('div', 'kop-sl__acts')).appendChild(undo);
        card.appendChild(help(it.status === 'created'
            ? 'Undo deletes the record made here (only while nobody has edited it or linked anything to it) and puts the row back in To review.'
            : it.status === 'linked'
                ? 'Undo takes the link back' + (it.added_name ? ' and the added name off the record' : '') + '; the row returns to To review.'
                : 'Undo puts the row back in To review.'));
    }

    function leftCard(it, card) {
        var p = el('p', 'kop-sl__decided');
        p.appendChild(document.createTextNode('Not on the list dated ' + (it.left_date || 'last checked') + '. '));
        var rec = it.left_record || it.facility;
        if (rec) {
            p.appendChild(document.createTextNode('Our record: '));
            p.appendChild(link(rec.name, rec.url));
            p.appendChild(document.createTextNode('.'));
        } else {
            p.appendChild(document.createTextNode('We have no record of it.'));
        }
        card.appendChild(p);
        var acts = el('div', 'kop-sl__acts');
        var b = it.left_seen ? button('Show again', 'kop-sl__btn--plain', 'refresh') : button('Dismiss', 'kop-sl__btn--plain', 'check');
        b.addEventListener('click', function () { send(it.left_seen ? 'undo_left' : 'dismiss_left', it.id, {}, card); });
        acts.appendChild(b);
        card.appendChild(acts);
        card.appendChild(help('Leaving a list can mean a closure, a new licence or a renamed program; check before changing anything. '
            + 'This tab never changes a record. ' + (it.left_seen ? 'Dismissed: "Show again" brings it back.' : 'Dismiss only hides the notice.')));
    }

    function draw() {
        var counts = { open: 0, onfile: 0, later: 0, done: 0, left: 0 };
        var shown = [];
        D.items.forEach(function (it) {
            if (!visible(it)) return;
            var t = tabOf(it);
            if (!(t === 'left' && it.left_seen)) counts[t]++;   // dismissed notices are listed, not counted
            if (t === tab) shown.push(it);
        });
        root.querySelectorAll('.kop-sl__tab').forEach(function (b) {
            b.setAttribute('aria-selected', b.getAttribute('data-tab') === tab ? 'true' : 'false');
            b.querySelector('span').textContent = '(' + counts[b.getAttribute('data-tab')] + ')';
        });
        if (tab === 'left') shown.sort(function (a, b) { return (a.left_seen ? 1 : 0) - (b.left_seen ? 1 : 0); });
        var l = stateWant && D.lists[stateWant];
        covers.hidden = !l;
        if (l) covers.textContent = l.label + ': ' + l.covers + (l.list_date ? ' List dated ' + l.list_date + '.' : '');
        list.innerHTML = '';
        if (!shown.length) {
            list.appendChild(el('p', 'kop-sl__empty', D.items.length ? 'Nothing here.' : 'No rows yet: the list file has not been imported.'));
            return;
        }
        shown.forEach(function (it) {
            var card = el('article', 'kop-sl__card' + (tab === 'left' ? ' is-left' : '') + (it.left_seen ? ' is-seen' : ''));
            card.appendChild(head(it));
            if (tab === 'open' || tab === 'onfile' || tab === 'later') openCard(it, card);
            else if (tab === 'done') doneCard(it, card);
            else leftCard(it, card);
            list.appendChild(card);
        });
    }

    Object.keys(D.lists).sort().forEach(function (st) {
        var o = el('option', '', st + ' - ' + D.lists[st].label);
        o.value = st;
        stateSel.appendChild(o);
    });
    if (stateWant && !D.lists[stateWant]) stateWant = '';
    stateSel.value = stateWant;
    stateSel.addEventListener('change', function () {
        stateWant = stateSel.value;
        try { localStorage.setItem('kopSlState', stateWant); } catch (e) {}
        draw();
    });
    search.addEventListener('input', draw);
    root.querySelectorAll('.kop-sl__tab').forEach(function (b) {
        b.addEventListener('click', function () { tab = b.getAttribute('data-tab'); draw(); });
    });
    var imp = root.querySelector('.kop-sl__import');
    if (imp) imp.addEventListener('click', function () { send('import', 0, {}, null); });
    draw();
})();
