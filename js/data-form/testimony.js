/**
 * Survivor Testimony section of the admin data form.
 *
 * Each facility (or provider site) carries facility.survivorTestimony, a list
 * of { id, text, source, date, movedFrom, publish }. The section lists the
 * entries for editing and, below them, every note on the facility that could
 * be testimony (general notes, field notes, operating period and resources
 * notes, the parent company notes, the custom treatment / philosophy /
 * incident entries, a provider's other TTI practices and referral notes, and
 * an open submission's reason), each with a "Move to testimony" button that
 * takes the text out of the note and into testimony. A note that repeats
 * text already in testimony gets "Remove copy" instead, which only deletes it.
 *
 * One survivor's account is one entry: a moved note is added as a paragraph
 * of the entry picked in "Moved notes go into" (by default the open
 * submission's entry, or the only entry), "Move all" moves every note at
 * once, and "Combine into one account" joins existing entries. Text from a
 * submission is sourced "Submitted by a survivor (submission #N)"; older
 * "Submission #N" entries read and save that way.
 *
 * Every account on the site has already been published elsewhere, so an
 * entry starts with "OK to publish" ticked and /facility/<slug>/ shows it;
 * unticking the box hides it
 * (inc/facility-pages.php). Stored by kop_facility_testimony_list() in
 * inc/facility-store.php (v2TestimonyList() in data-normalizer.js).
 */
(function() {
    if (window.KOP_Testimony) return;

    const SECTION_ID = 'survivor-testimony-section';

    // Custom list entries that often hold a survivor's own account.
    const MOVABLE_LISTS = [
        { path: ['treatmentTypes', 'custom'], label: 'Custom treatment type' },
        { path: ['philosophy', 'custom'], label: 'Custom philosophy' },
        { path: ['criticalIncidents', 'custom'], label: 'Custom incident' },
        { path: ['providerDetails', 'otherTtiPractices'], label: 'Other TTI practice' },
        { path: ['operatingPeriod', 'notes'], label: 'Operating period note' },
        { path: ['resources', 'notes'], label: 'Resources note' }
    ];

    // Single free-text boxes on the facility that can hold an account.
    const MOVABLE_FIELDS = [
        { path: ['providerDetails', 'referralNotes'], label: 'Referral notes' }
    ];

    function currentFacility() {
        const facilities = window.formData && Array.isArray(window.formData.facilities) ? window.formData.facilities : [];
        return facilities[window.currentFacilityIndex || 0] || null;
    }

    function testimonyList(facility) {
        if (!Array.isArray(facility.survivorTestimony)) facility.survivorTestimony = [];
        return facility.survivorTestimony;
    }

    function newId() {
        return `testimony_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
    }

    function today() {
        return new Date().toISOString().slice(0, 10);
    }

    const SURVIVOR_SOURCE = 'Submitted by a survivor';

    /**
     * "Submission #50" (the old label) reads as "Submitted by a survivor
     * (submission #50)". A survivor's account is theirs even when an admin
     * moved some of it, so "Added by an admin" drops out beside a survivor.
     */
    function normalizeSource(source) {
        const parts = [];
        String(source || '').split(/;\s*/).forEach(part => {
            const text = part.trim();
            if (!text) return;
            const match = text.match(/^Submission #(\d+)$/i);
            const label = match ? `${SURVIVOR_SOURCE} (submission #${match[1]})` : text;
            if (!parts.includes(label)) parts.push(label);
        });
        const survivor = parts.some(p => p.indexOf(SURVIVOR_SOURCE) === 0);
        return parts.filter(p => !(survivor && /^Added by an admin$/i.test(p))).join('; ');
    }

    /** A label ("Medication"), not an account: kept out of "Move all". */
    function isLabel(text) {
        return String(text || '').trim().split(/\s+/).length < 4;
    }

    /** Where a moved note came from: the open submission, or an admin edit. */
    function currentSource() {
        const session = window.KOP_SubmissionEditor && window.KOP_SubmissionEditor.session;
        if (session && session.active && session.submission) {
            const created = String(session.submission.created_at || '').slice(0, 10);
            return { source: `${SURVIVOR_SOURCE} (submission #${session.id})`, date: created || today(), fromSubmission: true };
        }
        return { source: 'Added by an admin', date: today(), fromSubmission: false };
    }

    // Which entry "Move to testimony" adds to: an entry id, 'new', or null for
    // the default (the open submission's own entry, else the only entry).
    let moveTarget = null;

    function targetEntry(facility) {
        const entries = testimonyList(facility);
        if (moveTarget === 'new') return null;
        if (moveTarget) {
            const chosen = entries.find(entry => entry.id === moveTarget);
            if (chosen) return chosen;
        }
        const current = currentSource();
        if (current.fromSubmission) {
            return entries.find(entry => normalizeSource(entry.source) === current.source) || null;
        }
        return entries.length === 1 ? entries[0] : null;
    }

    /** One account's paragraphs, as stored: blank lines between them. */
    function paragraphs(text) {
        return String(text || '').split(/\n\s*\n/).map(p => p.trim()).filter(Boolean);
    }

    function changed() {
        if (typeof window.updateJSON === 'function') window.updateJSON();
        if (typeof window.autoSave === 'function') window.autoSave();
    }

    function getPath(obj, path) {
        return path.reduce((value, key) => (value && typeof value === 'object' ? value[key] : undefined), obj);
    }

    function preview(text) {
        const flat = String(text).replace(/\s+/g, ' ').trim();
        return flat.length > 220 ? flat.slice(0, 217) + '...' : flat;
    }

    // ------------------------------------------------------------------
    // Notes that can be moved
    // ------------------------------------------------------------------

    /**
     * Every note-like text on the facility, each with a remove() that takes
     * it out of the facility (and out of the notes module's cache).
     */
    function collectMovableNotes(facility) {
        const items = [];

        if (typeof facility.notes === 'string' && facility.notes.trim()) {
            items.push({
                label: 'General notes',
                text: facility.notes.trim(),
                remove: () => { facility.notes = ''; }
            });
        } else if (Array.isArray(facility.notes)) {
            facility.notes.forEach((note, index) => {
                const text = typeof note === 'string' ? note : (note && note.text) || '';
                if (!String(text).trim()) return;
                items.push({
                    label: 'General notes',
                    text: String(text).trim(),
                    remove: () => { facility.notes.splice(facility.notes.indexOf(note) >= 0 ? facility.notes.indexOf(note) : index, 1); }
                });
            });
        }

        const fieldNotes = facility.fieldNotes && typeof facility.fieldNotes === 'object' ? facility.fieldNotes : {};
        Object.keys(fieldNotes).forEach(key => {
            const notes = fieldNotes[key];
            const list = Array.isArray(notes) ? notes : (typeof notes === 'string' && notes.trim() ? [notes] : []);
            list.forEach(note => {
                const text = typeof note === 'string' ? note : (note && note.text) || '';
                if (!String(text).trim()) return;
                const stamp = note && note.timestamp ? String(note.timestamp).slice(0, 10) : '';
                items.push({
                    label: stamp ? `Field note, ${stamp}` : 'Field note',
                    text: String(text).trim(),
                    remove: () => removeFieldNote(facility, key, note)
                });
            });
        });

        MOVABLE_LISTS.forEach(({ path, label }) => {
            const values = getPath(facility, path);
            if (!Array.isArray(values)) return;
            values.forEach(value => {
                if (typeof value !== 'string' || !value.trim()) return;
                items.push({
                    label,
                    text: value.trim(),
                    remove: () => {
                        const at = values.indexOf(value);
                        if (at >= 0) values.splice(at, 1);
                    }
                });
            });
        });

        MOVABLE_FIELDS.forEach(({ path, label }) => {
            const parent = getPath(facility, path.slice(0, -1));
            const key = path[path.length - 1];
            const value = parent && typeof parent === 'object' ? parent[key] : undefined;
            if (typeof value !== 'string' || !value.trim()) return;
            items.push({ label, text: value.trim(), remove: () => { parent[key] = ''; } });
        });

        // The project-level parent company notes box.
        const operator = window.formData && window.formData.operator;
        if (operator && typeof operator.notes === 'string' && operator.notes.trim()) {
            items.push({
                label: 'Parent company notes',
                text: operator.notes.trim(),
                remove: () => { operator.notes = ''; }
            });
        }

        // The submitter's reason on an open submission is not part of the
        // record, so moving it copies the text and leaves the reason alone.
        const session = window.KOP_SubmissionEditor && window.KOP_SubmissionEditor.session;
        const reason = session && session.active && session.submission ? String(session.submission.reason || '').trim() : '';
        if (reason) {
            items.push({ label: "Submitter's reason", text: reason, remove: () => {} });
        }

        // A note whose text is already a testimony entry is a leftover copy:
        // submissions often hold the same account twice (a field note and a
        // custom list entry, or a note under a field id from the submitter's
        // own form). Hiding it left it in the record for good, so it is
        // offered as a copy to remove instead. The submitter's reason is not
        // part of the record, so once copied it is simply not offered again.
        const moved = textsInTestimony(facility);
        return items.filter(item => {
            if (!moved.has(sameText(item.text))) return true;
            if (item.label === "Submitter's reason") return false;
            item.duplicate = true;
            return true;
        });
    }

    /** Text compared for "already in testimony": whitespace runs collapsed. */
    function sameText(text) {
        return String(text || '').replace(/\s+/g, ' ').trim();
    }

    /** Every entry, and every paragraph of a combined entry, as sameText(). */
    function textsInTestimony(facility) {
        const texts = new Set();
        testimonyList(facility).forEach(entry => {
            texts.add(sameText(entry.text));
            paragraphs(entry.text).forEach(p => texts.add(sameText(p)));
        });
        return texts;
    }

    /** Distinct labels, in order, from "A, B" strings. */
    function mergeLabels(values) {
        const out = [];
        values.forEach(value => String(value || '').split(/,\s*/).forEach(label => {
            const clean = label.trim();
            if (clean && !out.includes(clean)) out.push(clean);
        }));
        return out.join(', ');
    }

    function removeFieldNote(facility, key, note) {
        const drop = (store) => {
            if (!store || typeof store !== 'object') return;
            const value = store[key];
            if (Array.isArray(value)) {
                const at = value.indexOf(note);
                const byId = note && note.id ? value.findIndex(n => n && n.id === note.id) : -1;
                const index = at >= 0 ? at : byId;
                if (index >= 0) value.splice(index, 1);
                if (value.length === 0) delete store[key];
            } else if (typeof value === 'string') {
                delete store[key];
            }
        };
        drop(facility.fieldNotes);
        // notes.js keeps its own per-facility copy and writes it back on save.
        const cache = window.NotesModule && typeof window.NotesModule.getCurrentFacilityNotes === 'function'
            ? window.NotesModule.getCurrentFacilityNotes()
            : null;
        if (cache && cache !== facility.fieldNotes) drop(cache);
    }

    /**
     * Take one note into testimony: added as a paragraph of the target entry
     * (one survivor's account stays one entry), or as a new entry. A copy of
     * text already in testimony is only removed. Returns 'removed', 'added'
     * (to an existing entry) or 'new'.
     */
    function moveOne(facility, item) {
        let result = 'removed';
        if (!textsInTestimony(facility).has(sameText(item.text))) {
            const target = targetEntry(facility);
            if (target) {
                target.text = paragraphs(target.text).concat(item.text.trim()).join('\n\n');
                target.movedFrom = mergeLabels([target.movedFrom, item.label]);
                target.source = normalizeSource(target.source);
                result = 'added';
            } else {
                const { source, date } = currentSource();
                testimonyList(facility).push({
                    id: newId(),
                    text: item.text,
                    source,
                    date,
                    movedFrom: item.label,
                    publish: true
                });
                result = 'new';
            }
        }
        item.remove();
        return result;
    }

    function refresh(message) {
        changed();
        // Re-render the whole form so a moved note disappears where it was.
        if (typeof window.updateAllUI === 'function') window.updateAllUI();
        else render();
        if (message && typeof window.showUploadStatus === 'function') window.showUploadStatus(message, 'success');
    }

    function moveToTestimony(item) {
        const facility = currentFacility();
        if (!facility) return;
        const target = targetEntry(facility);
        const result = moveOne(facility, item);
        refresh(result === 'removed'
            ? 'Removed the copy. The text is still in Survivor Testimony.'
            : result === 'added'
                ? `Added to testimony ${testimonyList(facility).indexOf(target) + 1}.${target.publish === true ? ' That account is published, so this shows on the public page too.' : ''}`
                : 'Moved to Survivor Testimony. It is published; untick "OK to publish" to hide it.');
    }

    /** Every note at once, into the chosen entry (copies are removed). */
    function moveAll() {
        const facility = currentFacility();
        if (!facility) return;
        // Copies are always cleared; one- to three-word labels ("Medication")
        // stay for a one-by-one decision.
        const notes = collectMovableNotes(facility).filter(item => item.duplicate || !isLabel(item.text));
        if (!notes.length) return;
        const target = targetEntry(facility);
        const where = target ? `testimony ${testimonyList(facility).indexOf(target) + 1}` : 'one new testimony entry';
        if (!confirm(`Move all ${notes.length} notes into ${where}? They leave the fields they are in now.`)) return;
        // Into one account: the first move creates the entry when none was chosen.
        const before = moveTarget;
        notes.forEach(item => {
            moveOne(facility, item);
            if (!targetEntry(facility)) {
                const entries = testimonyList(facility);
                if (entries.length) moveTarget = entries[entries.length - 1].id;
            }
        });
        moveTarget = before;
        refresh(`Moved ${notes.length} notes into ${where}.`);
    }

    /**
     * One survivor's account in one entry: every entry joined, in order, as
     * paragraphs. Published only if every entry was.
     */
    function combineAll(facility) {
        const entries = testimonyList(facility);
        if (entries.length < 2) return null;
        const source = normalizeSource(entries.map(entry => entry.source).join('; '));
        const dates = entries.map(entry => String(entry.date || '').trim()).filter(Boolean).sort();
        const merged = {
            id: entries[0].id,
            text: entries.reduce((all, entry) => all.concat(paragraphs(entry.text)), []).join('\n\n'),
            source,
            date: dates[0] || '',
            movedFrom: mergeLabels(entries.map(entry => entry.movedFrom)),
            publish: entries.every(entry => entry.publish === true)
        };
        entries.splice(0, entries.length, merged);
        moveTarget = null;
        return merged;
    }

    function combineClicked() {
        const facility = currentFacility();
        if (!facility) return;
        const count = testimonyList(facility).length;
        if (count < 2) return;
        if (!confirm(`Combine all ${count} testimony entries into one account? Their text is kept, in order, as paragraphs.`)) return;
        const merged = combineAll(facility);
        refresh(`Combined ${count} entries into one account.${merged.publish ? '' : ' One of them was hidden, so the account stays hidden until "OK to publish" is ticked.'}`);
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    function el(tag, attrs = {}, text) {
        const node = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => {
            if (k === 'style') node.style.cssText = v;
            else if (k === 'className') node.className = v;
            else node.setAttribute(k, v);
        });
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function renderEntry(facility, entry, index) {
        const card = el('div', { className: 'kop-testimony-entry', style: 'background: var(--kop-white, #fff); border: 1px solid var(--kop-border-secondary, #AEE0ED); border-left: 4px solid var(--kop-teal, #33A7B5); border-radius: 6px; padding: 12px; margin-bottom: 12px;' });
        const idBase = `testimony-${index}`;

        const textLabel = el('label', { for: `${idBase}-text`, style: 'display: block; font-weight: 600; margin-bottom: 4px;' }, `Testimony ${index + 1}`);
        const text = el('textarea', { id: `${idBase}-text`, rows: '5', style: 'width: 100%; box-sizing: border-box;' });
        text.value = entry.text || '';
        text.addEventListener('input', () => { entry.text = text.value; changed(); });

        const row = el('div', { style: 'display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px; align-items: flex-end;' });

        const sourceWrap = el('div', { style: 'flex: 1 1 200px;' });
        sourceWrap.append(el('label', { for: `${idBase}-source`, style: 'display: block; font-size: 13px;' }, 'Source'));
        const sourceInput = el('input', { id: `${idBase}-source`, type: 'text', style: 'width: 100%; box-sizing: border-box;' });
        // Old entries say "Submission #50"; they read, and save, as submitted by a survivor.
        entry.source = normalizeSource(entry.source);
        sourceInput.value = entry.source || '';
        sourceInput.addEventListener('input', () => { entry.source = sourceInput.value; changed(); });
        sourceWrap.append(sourceInput);

        const dateWrap = el('div', { style: 'flex: 0 1 160px;' });
        dateWrap.append(el('label', { for: `${idBase}-date`, style: 'display: block; font-size: 13px;' }, 'Date'));
        const dateInput = el('input', { id: `${idBase}-date`, type: 'text', placeholder: 'YYYY-MM-DD', style: 'width: 100%; box-sizing: border-box;' });
        dateInput.value = entry.date || '';
        dateInput.addEventListener('input', () => { entry.date = dateInput.value; changed(); });
        dateWrap.append(dateInput);

        const publishWrap = el('label', { style: 'display: flex; gap: 6px; align-items: center; font-weight: 600;' });
        const publish = el('input', { type: 'checkbox', id: `${idBase}-publish` });
        publish.checked = entry.publish === true;
        publish.addEventListener('change', () => { entry.publish = publish.checked; changed(); });
        publishWrap.append(publish, document.createTextNode('OK to publish'));

        const remove = el('button', { type: 'button', className: 'btn', style: 'background: var(--kop-coral-pink-ink, #D9020F); color: var(--kop-white, #fff); border: none; border-radius: 4px; padding: 6px 12px; cursor: pointer;' }, 'Remove');
        remove.addEventListener('click', () => {
            if (!confirm('Remove this testimony? This does not put it back in the notes.')) return;
            testimonyList(facility).splice(testimonyList(facility).indexOf(entry), 1);
            changed();
            render();
        });

        row.append(sourceWrap, dateWrap, publishWrap, remove);
        card.append(textLabel, text, row);
        if (entry.movedFrom) {
            card.append(el('div', { style: 'font-size: 12px; margin-top: 6px; color: var(--kop-text-secondary, #000080);' }, `Moved from: ${entry.movedFrom}`));
        }
        return card;
    }

    function render() {
        const list = document.getElementById('survivor-testimony-list');
        const movable = document.getElementById('survivor-testimony-movable');
        if (!list || !movable) return;
        list.innerHTML = '';
        movable.innerHTML = '';

        const facility = currentFacility();
        if (!facility) {
            list.append(el('p', { style: 'margin: 0;' }, 'Load or add a facility first.'));
            return;
        }

        const entries = testimonyList(facility);
        if (!entries.length) {
            list.append(el('p', { style: 'margin: 0 0 12px; font-style: italic;' }, 'No survivor testimony on this facility yet.'));
        }
        if (entries.length > 1) {
            const combineRow = el('div', { style: 'display: flex; gap: 10px; align-items: center; flex-wrap: wrap; background: var(--kop-sand, #F2EEDF); color: var(--kop-midnight, #000435); border-radius: 6px; padding: 10px 12px; margin-bottom: 12px;' });
            combineRow.append(el('span', { style: 'flex: 1 1 260px; font-size: 14px;' },
                `${entries.length} entries. If they are one survivor's account, combine them into one.`));
            const combine = el('button', { type: 'button', className: 'btn', style: 'background: var(--kop-navy, #000080); color: var(--kop-white, #fff); border: none; border-radius: 4px; padding: 6px 12px; cursor: pointer; white-space: nowrap;' },
                'Combine into one account');
            combine.addEventListener('click', combineClicked);
            combineRow.append(combine);
            list.append(combineRow);
        }
        entries.forEach((entry, index) => list.append(renderEntry(facility, entry, index)));

        const notes = collectMovableNotes(facility);
        movable.append(el('h3', { style: 'font-size: 16px; margin: 16px 0 8px;' }, `Notes that could be testimony (${notes.length})`));
        if (!notes.length) {
            movable.append(el('p', { style: 'margin: 0; font-style: italic;' }, 'No notes on this facility.'));
            return;
        }

        // Where moved notes go, and moving them all at once.
        const controls = el('div', { style: 'display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 8px;' });
        const pickLabel = el('label', { for: 'survivor-testimony-target', style: 'font-size: 14px; font-weight: 600;' }, 'Moved notes go into');
        const pick = el('select', { id: 'survivor-testimony-target', style: 'background: var(--kop-white, #fff); color: var(--kop-midnight, #000435); border: 1px solid var(--kop-border-secondary, #AEE0ED); border-radius: 4px; padding: 4px 8px;' });
        const current = targetEntry(facility);
        entries.forEach((entry, index) => {
            const option = el('option', { value: entry.id }, `Testimony ${index + 1}${entry.source ? ` (${normalizeSource(entry.source)})` : ''}`);
            if (current && current.id === entry.id) option.selected = true;
            pick.append(option);
        });
        const fresh = el('option', { value: 'new' }, 'A new testimony entry');
        if (!current) fresh.selected = true;
        pick.append(fresh);
        pick.addEventListener('change', () => { moveTarget = pick.value; });
        controls.append(pickLabel, pick);
        const bulk = notes.filter(item => item.duplicate || !isLabel(item.text)).length;
        if (bulk > 1) {
            const all = el('button', { type: 'button', className: 'btn', style: 'background: var(--kop-teal-ink, #24757F); color: var(--kop-white, #fff); border: none; border-radius: 4px; padding: 6px 12px; cursor: pointer; white-space: nowrap;' },
                `Move all ${bulk}`);
            all.addEventListener('click', moveAll);
            controls.append(all);
            if (bulk < notes.length) {
                controls.append(el('span', { style: 'font-size: 13px; color: var(--kop-text-muted, #4A5568);' },
                    `${notes.length - bulk} short label${notes.length - bulk === 1 ? '' : 's'} left for you to move one by one`));
            }
        }
        movable.append(controls);
        notes.forEach(item => {
            const rowEl = el('div', { style: 'display: flex; gap: 10px; align-items: flex-start; padding: 8px 0; border-top: 1px solid var(--kop-border-primary, #B6E3D4);' });
            const body = el('div', { style: 'flex: 1;' });
            body.append(el('div', { style: 'font-size: 12px; font-weight: 600;' },
                item.duplicate ? `${item.label} (copy of text already in testimony)` : item.label));
            const textEl = el('div', { style: 'font-size: 14px;', title: item.text }, preview(item.text));
            body.append(textEl);
            const move = el('button', { type: 'button', className: 'btn', style: 'background: var(--kop-teal-ink, #24757F); color: var(--kop-white, #fff); border: none; border-radius: 4px; padding: 6px 12px; cursor: pointer; white-space: nowrap;' },
                item.duplicate ? 'Remove copy' : 'Move to testimony');
            move.addEventListener('click', () => moveToTestimony(item));
            rowEl.append(body, move);
            movable.append(rowEl);
        });
    }

    function addBlankEntry() {
        const facility = currentFacility();
        if (!facility) return;
        const { source, date } = currentSource();
        testimonyList(facility).push({ id: newId(), text: '', source, date, movedFrom: '', publish: true });
        render();
        const last = document.getElementById(`testimony-${testimonyList(facility).length - 1}-text`);
        if (last) last.focus();
    }

    function init() {
        const addBtn = document.getElementById('add-survivor-testimony-btn');
        if (addBtn && !addBtn.dataset.listenerAttached) {
            addBtn.addEventListener('click', addBlankEntry);
            addBtn.dataset.listenerAttached = 'true';
        }
        // Re-render whenever the form re-renders: updateAllUI on project loads,
        // loadFacilityData when moving between facilities.
        ['updateAllUI', 'loadFacilityData'].forEach(name => {
            const original = window[name];
            if (typeof original !== 'function' || original.__kopTestimony) return;
            const wrapped = function() {
                const result = original.apply(this, arguments);
                try { render(); } catch (e) { console.error('Survivor testimony render failed:', e); }
                return result;
            };
            wrapped.__kopTestimony = true;
            window[name] = wrapped;
        });
        render();
    }

    window.KOP_Testimony = { render, collectMovableNotes, moveToTestimony, moveOne, moveAll, combineAll, normalizeSource, init };

    if (!document.getElementById(SECTION_ID)) return;
    if (window.formReady) init();
    else document.addEventListener('formReady', init, { once: true });
})();
