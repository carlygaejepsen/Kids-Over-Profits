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
 * takes the text out of the note and into a new entry.
 *
 * Nothing is public by default: publish stays false until the "OK to publish"
 * box is ticked, and only then does /facility/<slug>/ show the entry
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

    /** Where a moved note came from: the open submission, or an admin edit. */
    function currentSource() {
        const session = window.KOP_SubmissionEditor && window.KOP_SubmissionEditor.session;
        if (session && session.active && session.submission) {
            const created = String(session.submission.created_at || '').slice(0, 10);
            return { source: `Submission #${session.id}`, date: created || today() };
        }
        return { source: 'Added by an admin', date: today() };
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

        // Anything already moved (or copied) is not offered again.
        const moved = new Set(testimonyList(facility).map(entry => String(entry.text || '').trim()));
        return items.filter(item => !moved.has(item.text));
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

    function moveToTestimony(item) {
        const facility = currentFacility();
        if (!facility) return;
        const { source, date } = currentSource();
        testimonyList(facility).push({
            id: newId(),
            text: item.text,
            source,
            date,
            movedFrom: item.label,
            publish: false
        });
        item.remove();
        changed();
        // Re-render the whole form so the note disappears where it was.
        if (typeof window.updateAllUI === 'function') window.updateAllUI();
        else render();
        if (typeof window.showUploadStatus === 'function') {
            window.showUploadStatus('Moved to Survivor Testimony. It stays private until "OK to publish" is ticked.', 'success');
        }
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
        entries.forEach((entry, index) => list.append(renderEntry(facility, entry, index)));

        const notes = collectMovableNotes(facility);
        movable.append(el('h3', { style: 'font-size: 16px; margin: 16px 0 8px;' }, `Notes that could be testimony (${notes.length})`));
        if (!notes.length) {
            movable.append(el('p', { style: 'margin: 0; font-style: italic;' }, 'No notes on this facility.'));
            return;
        }
        notes.forEach(item => {
            const rowEl = el('div', { style: 'display: flex; gap: 10px; align-items: flex-start; padding: 8px 0; border-top: 1px solid var(--kop-border-primary, #B6E3D4);' });
            const body = el('div', { style: 'flex: 1;' });
            body.append(el('div', { style: 'font-size: 12px; font-weight: 600;' }, item.label));
            const textEl = el('div', { style: 'font-size: 14px;', title: item.text }, preview(item.text));
            body.append(textEl);
            const move = el('button', { type: 'button', className: 'btn', style: 'background: var(--kop-teal-ink, #24757F); color: var(--kop-white, #fff); border: none; border-radius: 4px; padding: 6px 12px; cursor: pointer; white-space: nowrap;' }, 'Move to testimony');
            move.addEventListener('click', () => moveToTestimony(item));
            rowEl.append(body, move);
            movable.append(rowEl);
        });
    }

    function addBlankEntry() {
        const facility = currentFacility();
        if (!facility) return;
        const { source, date } = currentSource();
        testimonyList(facility).push({ id: newId(), text: '', source, date, movedFrom: '', publish: false });
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

    window.KOP_Testimony = { render, collectMovableNotes, moveToTestimony, init };

    if (!document.getElementById(SECTION_ID)) return;
    if (window.formReady) init();
    else document.addEventListener('formReady', init, { once: true });
})();
