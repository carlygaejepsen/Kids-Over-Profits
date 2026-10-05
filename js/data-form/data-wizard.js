/**
 * Guided start for the public data form (/tti-data-submission/).
 *
 * The full form has every field for every kind of record, which is too much for
 * someone who wants to add one fact. This puts three steps in front of it:
 *
 *   1. Find the record (search over the projects the form already loaded), or
 *      add a new facility, parent company, provider, transporter or referrer.
 *   2. Pick what to add or fix (staff, dates, incidents, ...).
 *   3. Edit: the form shows only the sections for those topics, plus Submit.
 *
 * It drives the existing form through its own functions (KOP_Project.loadProject,
 * navigateToFacility, updateAllUI), so saving still goes through
 * submitSuggestion() -> api/save-suggestion.php unchanged. "Show every field"
 * drops back to the full form. Body classes: kop-wiz-pick (steps 1-2),
 * kop-wiz-focus (step 3), neither (full form). ?find=<name> starts a search,
 * ?full=1 opens the full form.
 */
(function () {
    'use strict';

    const root = document.getElementById('kop-wizard');
    if (!root) return;

    const TYPES = {
        facility: {
            label: 'Facility or program',
            hint: 'A wilderness program, boarding school, residential treatment center, group home or camp.',
            category: 'locations'
        },
        company: {
            label: 'Parent company',
            hint: 'A company, church or organization that owns or runs programs.',
            category: 'companies'
        },
        provider: {
            label: 'Mental health provider',
            hint: 'A psychiatric ward, day program, school or therapist outside the industry that uses its methods or sends kids to programs.',
            category: 'providers'
        },
        transporter: {
            label: 'Transporter',
            hint: 'A youth transport company or escort who takes kids to programs.',
            category: 'transporters'
        },
        referrer: {
            label: 'Referrer',
            hint: 'An educational consultant, placement service or school district that sends kids to programs.',
            category: 'referrers'
        }
    };
    const TYPE_ORDER = ['facility', 'company', 'provider', 'transporter', 'referrer'];

    // Topics: what the person wants to add, and the form sections that hold it.
    // `extra` are non-section blocks shown with the topic.
    const TOPICS = {
        facility: [
            { key: 'names', label: 'Name, past names and website', sections: ['identification-section'] },
            { key: 'place', label: 'Where it is', sections: ['location-section'] },
            { key: 'dates', label: 'When it opened, closed or changed hands', sections: ['operations-section'] },
            { key: 'owner', label: 'Who owns or runs it', sections: ['operator-section'], extra: ['#private-ownership-toggle-section'] },
            { key: 'staff', label: 'Staff', sections: ['staff-section'] },
            { key: 'incidents', label: 'Deaths, abuse, injuries and other incidents', sections: ['incidents-section'] },
            { key: 'sources', label: 'News, documents, lawsuits and records', sections: ['resources-section'] },
            { key: 'methods', label: 'What it does: treatment, practices, beliefs', sections: ['treatment-section', 'common-tti-practices-section', 'philosophy-section', 'target-profile-section'] },
            { key: 'details', label: 'Ages, gender and size', sections: ['facility-section'] },
            { key: 'licenses', label: 'Licenses, accreditations and memberships', sections: ['accreditations-section'] },
            { key: 'notes', label: 'Something else', sections: ['notes-section'] }
        ],
        company: [
            { key: 'company', label: 'Company names, headquarters, leaders and investors', sections: ['operator-section'] },
            { key: 'programs', label: 'Programs it runs', sections: ['identification-section', 'location-section', 'operations-section'], extra: ['.facility-loader-panel'] },
            { key: 'notes', label: 'Something else', sections: ['notes-section'] }
        ],
        provider: [
            { key: 'names', label: 'Name, past names and website', sections: ['identification-section'] },
            { key: 'care', label: 'Type of care and ties to the industry', sections: ['provider-section'] },
            { key: 'place', label: 'Where it is', sections: ['location-section'] },
            { key: 'owner', label: 'Who owns or runs it', sections: ['operator-section'], extra: ['#private-ownership-toggle-section'] },
            { key: 'dates', label: 'When it opened or closed', sections: ['operations-section'] },
            { key: 'staff', label: 'Staff', sections: ['staff-section'] },
            { key: 'incidents', label: 'Deaths, abuse, injuries and other incidents', sections: ['incidents-section'] },
            { key: 'sources', label: 'News, documents, lawsuits and records', sections: ['resources-section'] },
            { key: 'notes', label: 'Something else', sections: ['notes-section'] }
        ],
        transporter: [
            { key: 'company', label: 'The company: name, place, website', sections: ['transporter-company-section'] },
            { key: 'people', label: 'People who work for it', sections: ['transporter-individuals-section'] }
        ],
        referrer: [
            { key: 'agency', label: 'The business or agency: name, place, website', sections: ['referrer-agency-section'], extra: ['#referrer-agency-toggle-section'] },
            { key: 'people', label: 'Consultants and the programs they send kids to', sections: ['referrer-consultants-section'] }
        ]
    };
    // Shown with every topic set: the submit block of each view.
    const ALWAYS_ON = ['submission-section', 'referrer-submission-section', 'transporter-submission-section'];
    // What a new record opens with, already ticked.
    const NEW_DEFAULT_TOPICS = {
        facility: ['names', 'place'],
        company: ['company'],
        provider: ['names', 'care', 'place'],
        transporter: ['company', 'people'],
        referrer: ['agency', 'people']
    };

    const state = {
        step: 'find',        // find | new | topics | edit | full
        query: '',
        target: null,        // search entry, or { isNew: true, type, name, place, isPerson }
        topics: [],
        index: null,
        ready: false
    };

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    function esc(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function icon(name) {
        return typeof window.kopIcon === 'function' ? window.kopIcon(name) : '';
    }

    function fold(text) {
        return String(text || '')
            .normalize('NFKD').replace(/\p{M}/gu, '')
            .toLowerCase().replace(/&/g, ' and ').replace(/[^a-z0-9]+/g, ' ').trim();
    }

    function titleCase(text) {
        return String(text || '').replace(/\b([a-z])/g, (m) => m.toUpperCase());
    }

    function nameList(value) {
        if (!value) return [];
        const list = Array.isArray(value) ? value : [value];
        return list.map((item) => {
            if (typeof item === 'string') return item.trim();
            if (item && typeof item === 'object') return String(item.name || item.value || '').trim();
            return '';
        }).filter(Boolean);
    }

    function personName(person) {
        if (!person || typeof person !== 'object') return '';
        const full = String(person.fullName || '').trim();
        if (full) return full;
        return [person.firstName, person.lastName].map((p) => String(p || '').trim()).filter(Boolean).join(' ');
    }

    function facilityPlace(facility) {
        const details = facility.locationDetails || {};
        const city = String(details.city || '').trim();
        const st = String(details.state || details.country || '').trim();
        if (city || st) return [city, st].filter(Boolean).join(', ');
        return String(facility.location || '').trim();
    }

    function setBodyMode(mode) {
        document.body.classList.toggle('kop-wiz-pick', mode === 'pick');
        document.body.classList.toggle('kop-wiz-focus', mode === 'focus');
    }

    function project() {
        return window.KOP_Project || {};
    }

    // ------------------------------------------------------------------
    // Search index, built from window.projects once the form has loaded
    // ------------------------------------------------------------------

    function buildIndex() {
        const projects = window.projects || {};
        const facilities = new Map();
        const entries = [];

        const addFacility = (facility, projectKey, category, operatorName) => {
            const ident = facility.identification || {};
            const name = String(ident.name || ident.currentName || '').trim();
            if (!name) return;
            const id = facility.facility_id || facility.facilityId || '';
            const place = facilityPlace(facility);
            const key = id ? `id:${id}` : `n:${fold(name)}|${fold(place)}`;
            const type = category === 'providers' ? 'provider' : 'facility';
            let entry = facilities.get(key);
            if (!entry) {
                const aliases = [ident.currentName].concat(nameList(ident.otherNames), nameList(ident.pastNames))
                    .map((a) => String(a || '').trim())
                    .filter((a) => a && fold(a) !== fold(name));
                entry = { type, name, aliases, place, operator: '', facilityId: id, homes: [] };
                facilities.set(key, entry);
                entries.push(entry);
            }
            if (operatorName && !entry.operator) entry.operator = operatorName;
            entry.homes.push({ project: projectKey, category });
        };

        Object.keys(projects).forEach((key) => {
            const p = projects[key] || {};
            const data = p.data || {};
            const category = p.category || 'companies';
            const list = Array.isArray(data.facilities) ? data.facilities : [];

            if (category === 'referrers' || category === 'transporters') {
                const isRef = category === 'referrers';
                const org = isRef ? (data.referrerAgency || {}) : (data.transporterCompany || {});
                const people = (isRef ? data.referrerConsultants : data.transporters) || [];
                const peopleNames = people.map(personName).filter(Boolean);
                const name = String(org.name || '').trim() || peopleNames[0] || key;
                entries.push({
                    type: isRef ? 'referrer' : 'transporter',
                    name,
                    aliases: [key].concat(nameList(org.otherNames), peopleNames).filter((a) => fold(a) !== fold(name)),
                    place: [org.city, org.state].map((v) => String(v || '').trim()).filter(Boolean).join(', '),
                    people: peopleNames,
                    homes: [{ project: key, category }]
                });
                return;
            }

            if (category === 'companies') {
                const op = data.operator || {};
                const opName = String(op.name || op.currentName || '').trim();
                const named = list.filter((f) => String(f?.identification?.name || '').trim());
                // Skip shells with nothing in them (published wiki entries load
                // as empty company projects).
                if (opName || named.length) {
                    entries.push({
                        type: 'company',
                        name: opName || key,
                        aliases: [key, op.currentName].concat(nameList(op.otherNames), nameList(op.pastNames))
                            .map((a) => String(a || '').trim())
                            .filter((a) => a && fold(a) !== fold(opName || key)),
                        place: String(op.headquarters || op.location || '').trim(),
                        count: named.length,
                        homes: [{ project: key, category }]
                    });
                }
                list.forEach((f) => f && addFacility(f, key, category, opName || key));
                return;
            }

            list.forEach((f) => f && addFacility(f, key, category, ''));
        });

        state.index = entries;
    }

    function scoreEntry(entry, q, tokens) {
        const names = [entry.name].concat(entry.aliases || []);
        let best = 0;
        let via = '';
        names.forEach((raw, i) => {
            const n = fold(raw);
            if (!n) return;
            let s = 0;
            if (n === q) s = 100;
            else if (n.startsWith(q)) s = 80;
            else if (n.includes(` ${q}`) || n.includes(q)) s = 60;
            else if (tokens.every((t) => n.includes(t))) s = 40;
            if (s && i > 0) s -= 15; // a past name or alias ranks under the current name
            if (s > best) {
                best = s;
                via = i > 0 ? raw : '';
            }
        });
        if (!best && entry.place && tokens.length > 1) {
            // "sunrise utah": the name holds some words, the place the rest.
            const hay = `${fold(entry.name)} ${fold(entry.place)}`;
            if (tokens.every((t) => hay.includes(t))) best = 30;
        }
        return { score: best, via };
    }

    function search(query) {
        const q = fold(query);
        if (q.length < 2 || !state.index) return [];
        const tokens = q.split(' ').filter(Boolean);
        const hits = [];
        state.index.forEach((entry) => {
            const { score, via } = scoreEntry(entry, q, tokens);
            if (score) hits.push({ entry, score, via });
        });
        hits.sort((a, b) => b.score - a.score || a.entry.name.localeCompare(b.entry.name));
        return hits.slice(0, 15);
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    function stepLabel(n) {
        return `<p class="kop-wiz-kicker">Step ${n} of 3</p>`;
    }

    function render() {
        if (state.step === 'full') return renderFull();
        if (state.step === 'edit') return renderEditBar();
        setBodyMode('pick');
        if (state.step === 'new') return renderNew();
        if (state.step === 'topics') return renderTopics();
        return renderFind();
    }

    function renderFind() {
        const newButtons = TYPE_ORDER.map((type) => `
            <button type="button" class="kop-wiz-type" data-new="${type}">
                <strong>${esc(TYPES[type].label)}</strong>
                <span>${esc(TYPES[type].hint)}</span>
            </button>`).join('');

        root.innerHTML = `
            <section class="kop-wiz-card" aria-labelledby="kop-wiz-h">
                ${stepLabel(1)}
                <h2 id="kop-wiz-h">What do you want to tell us about?</h2>
                <p class="kop-wiz-lead">Search for the program, company, provider, transporter or consultant. Programs change names often, so an old name works too.</p>
                <div class="kop-wiz-search">
                    <label for="kop-wiz-q">Name</label>
                    <input type="search" id="kop-wiz-q" data-growing-text-field="true" autocomplete="off" placeholder="e.g. Turn-About Ranch" value="${esc(state.query)}">
                </div>
                <div id="kop-wiz-results" class="kop-wiz-results" aria-live="polite"></div>
                <div class="kop-wiz-new">
                    <h3>Not listed? Add it as a new</h3>
                    <div class="kop-wiz-types">${newButtons}</div>
                </div>
                <p class="kop-wiz-foot">
                    You do not need to know everything about it. One fact and where you learned it is a real help.
                    <button type="button" class="kop-wiz-link" data-act="full">Show the full form instead</button>
                </p>
            </section>`;

        const input = root.querySelector('#kop-wiz-q');
        let timer = null;
        input.addEventListener('input', () => {
            state.query = input.value;
            clearTimeout(timer);
            timer = setTimeout(renderResults, 150);
        });
        renderResults();
        bindCommon();
    }

    function renderResults() {
        const box = root.querySelector('#kop-wiz-results');
        if (!box) return;
        const q = state.query.trim();
        if (!state.ready) {
            box.innerHTML = q ? '<p class="kop-wiz-muted">Loading the records. Results will show in a moment.</p>' : '';
            return;
        }
        if (fold(q).length < 2) {
            box.innerHTML = '';
            return;
        }
        const hits = search(q);
        if (!hits.length) {
            box.innerHTML = `<p class="kop-wiz-muted">Nothing found for "${esc(q)}". Try another spelling or an old name, or add it as new below.</p>`;
            return;
        }
        box.innerHTML = `<ul class="kop-wiz-hits">${hits.map((hit, i) => {
            const e = hit.entry;
            const bits = [];
            if (hit.via) bits.push(`Also known as ${esc(hit.via)}`);
            if (e.place) bits.push(esc(e.place));
            if (e.operator && fold(e.operator) !== fold(e.name)) bits.push(`Run by ${esc(e.operator)}`);
            if (e.type === 'company' && e.count) bits.push(`${e.count} program${e.count === 1 ? '' : 's'}`);
            return `<li><button type="button" class="kop-wiz-hit" data-hit="${i}">
                <span class="kop-wiz-badge kop-wiz-badge-${e.type}">${esc(TYPES[e.type].label)}</span>
                <strong>${esc(e.name)}</strong>
                ${bits.length ? `<span class="kop-wiz-meta">${bits.join(' &middot; ')}</span>` : ''}
            </button></li>`;
        }).join('')}</ul>`;
        box.querySelectorAll('[data-hit]').forEach((btn) => {
            btn.addEventListener('click', () => {
                state.target = hits[Number(btn.dataset.hit)].entry;
                state.topics = [];
                state.step = 'topics';
                render();
                focusHeading();
            });
        });
    }

    function renderNew() {
        const t = state.target;
        const type = TYPES[t.type];
        const needsPlace = t.type === 'facility' || t.type === 'provider';
        const canBePerson = t.type === 'transporter' || t.type === 'referrer';
        const places = (window.US_STATE_NAMES || []).concat(window.COUNTRY_NAMES || [])
            .map((p) => `<option value="${esc(titleCase(p))}"></option>`).join('');
        const personWord = t.type === 'referrer' ? 'one consultant working alone' : 'one person working alone';

        root.innerHTML = `
            <section class="kop-wiz-card" aria-labelledby="kop-wiz-h">
                ${stepLabel(1)}
                <h2 id="kop-wiz-h">Add a new ${esc(type.label.toLowerCase())}</h2>
                <p class="kop-wiz-lead">${esc(type.hint)}</p>
                <form class="kop-wiz-form" novalidate>
                    ${canBePerson ? `
                    <fieldset class="kop-wiz-choice">
                        <legend>Is it a business or a person?</legend>
                        <label><input type="radio" name="kop-wiz-kind" value="org" ${t.isPerson ? '' : 'checked'}> A business or agency</label>
                        <label><input type="radio" name="kop-wiz-kind" value="person" ${t.isPerson ? 'checked' : ''}> ${esc(personWord)}</label>
                    </fieldset>` : ''}
                    <div class="kop-wiz-field">
                        <label for="kop-wiz-name">Name</label>
                        <input type="text" id="kop-wiz-name" data-growing-text-field="true" required value="${esc(t.name || state.query)}">
                    </div>
                    ${needsPlace ? `
                    <div class="kop-wiz-field">
                        <label for="kop-wiz-place">State or country <span class="kop-wiz-muted">(if you know it)</span></label>
                        <input type="text" id="kop-wiz-place" data-growing-text-field="true" list="kop-wiz-places" autocomplete="off" value="${esc(t.place || '')}">
                        <datalist id="kop-wiz-places">${places}</datalist>
                    </div>` : ''}
                    <p class="kop-wiz-error" role="alert" hidden>Please type a name.</p>
                    <div class="kop-wiz-actions">
                        <button type="submit" class="kop-wiz-btn kop-wiz-primary">Continue</button>
                        <button type="button" class="kop-wiz-btn" data-act="back">Back to search</button>
                    </div>
                </form>
            </section>`;

        const form = root.querySelector('form');
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const name = root.querySelector('#kop-wiz-name').value.trim();
            if (!name) {
                form.querySelector('.kop-wiz-error').hidden = false;
                root.querySelector('#kop-wiz-name').focus();
                return;
            }
            t.name = name;
            t.place = needsPlace ? root.querySelector('#kop-wiz-place').value.trim() : '';
            const kind = form.querySelector('input[name="kop-wiz-kind"]:checked');
            t.isPerson = Boolean(kind && kind.value === 'person');
            state.topics = NEW_DEFAULT_TOPICS[t.type].slice();
            state.step = 'topics';
            render();
            focusHeading();
        });
        bindCommon();
        root.querySelector('#kop-wiz-name').focus();
    }

    function targetSummary() {
        const t = state.target;
        const type = TYPES[t.type];
        return `<span class="kop-wiz-badge kop-wiz-badge-${t.type}">${esc(t.isNew ? `New ${type.label.toLowerCase()}` : type.label)}</span>
            <strong>${esc(t.name)}</strong>${t.place ? `<span class="kop-wiz-muted">${esc(t.place)}</span>` : ''}`;
    }

    function renderTopics() {
        const t = state.target;
        const topics = TOPICS[t.type];
        const boxes = topics.map((topic) => `
            <label class="kop-wiz-topic">
                <input type="checkbox" value="${topic.key}" ${state.topics.includes(topic.key) ? 'checked' : ''}>
                <span>${esc(topic.label)}</span>
            </label>`).join('');

        root.innerHTML = `
            <section class="kop-wiz-card" aria-labelledby="kop-wiz-h">
                ${stepLabel(2)}
                <div class="kop-wiz-target">${targetSummary()}
                    <button type="button" class="kop-wiz-link" data-act="back">Change</button></div>
                <h2 id="kop-wiz-h">What do you want to ${t.isNew ? 'add' : 'add or fix'}?</h2>
                <p class="kop-wiz-lead">Pick one or more. You will only see the fields for what you pick. Nobody is expected to fill in every field: leave blank whatever you do not know.</p>
                <div class="kop-wiz-topics">${boxes}</div>
                <p class="kop-wiz-error" role="alert" hidden>Pick at least one, or choose "Show every field".</p>
                <div class="kop-wiz-actions">
                    <button type="button" class="kop-wiz-btn kop-wiz-primary" data-act="go">Continue</button>
                    <button type="button" class="kop-wiz-btn" data-act="go-all">Show every field</button>
                </div>
            </section>`;

        root.querySelectorAll('.kop-wiz-topic input').forEach((box) => {
            box.addEventListener('change', () => {
                state.topics = Array.from(root.querySelectorAll('.kop-wiz-topic input:checked')).map((b) => b.value);
                root.querySelector('.kop-wiz-error').hidden = true;
            });
        });
        root.querySelector('[data-act="go"]').addEventListener('click', () => {
            if (!state.topics.length) {
                root.querySelector('.kop-wiz-error').hidden = false;
                return;
            }
            openEditor();
        });
        root.querySelector('[data-act="go-all"]').addEventListener('click', () => {
            state.topics = TOPICS[t.type].map((topic) => topic.key);
            openEditor();
        });
        bindCommon();
    }

    function renderEditBar() {
        setBodyMode('focus');
        const t = state.target;
        const all = TOPICS[t.type];
        const chosen = all.filter((topic) => state.topics.includes(topic.key));
        const others = all.filter((topic) => !state.topics.includes(topic.key));
        const submitWord = 'Submit for review';

        root.innerHTML = `
            <section class="kop-wiz-card kop-wiz-bar" aria-labelledby="kop-wiz-h">
                ${stepLabel(3)}
                <div class="kop-wiz-target">${targetSummary()}</div>
                <h2 id="kop-wiz-h">Fill in what you know</h2>
                <p class="kop-wiz-lead">Showing: ${chosen.map((topic) => esc(topic.label)).join('; ')}.
                    Leave blank anything you are not sure of. When you are done, press <strong>${submitWord}</strong> at the bottom
                    and say where you learned it (a link, a document, or your own time there). A volunteer checks every suggestion before it goes on the site.</p>
                ${others.length ? `
                <details class="kop-wiz-more">
                    <summary>Add something else too</summary>
                    <div class="kop-wiz-chips">${others.map((topic) => `<button type="button" class="kop-wiz-chip" data-add-topic="${topic.key}">${icon('plus')}${esc(topic.label)}</button>`).join('')}</div>
                </details>` : ''}
                <div class="kop-wiz-actions">
                    ${t.type === 'company' && state.topics.includes('programs') ? '<button type="button" class="kop-wiz-btn" data-act="add-program">Add a program this company runs</button>' : ''}
                    <button type="button" class="kop-wiz-btn" data-act="restart">Start over</button>
                    <button type="button" class="kop-wiz-link" data-act="full">Show the full form</button>
                </div>
                <div class="kop-wiz-confirm" hidden>
                    <p>Start over? Anything you typed here and have not submitted will be cleared.</p>
                    <button type="button" class="kop-wiz-btn kop-wiz-primary" data-act="restart-yes">Yes, start over</button>
                    <button type="button" class="kop-wiz-btn" data-act="restart-no">Keep editing</button>
                </div>
            </section>`;

        root.querySelectorAll('[data-add-topic]').forEach((btn) => {
            btn.addEventListener('click', () => {
                state.topics.push(btn.dataset.addTopic);
                applyFocus();
                renderEditBar();
                const topic = all.find((tp) => tp.key === btn.dataset.addTopic);
                const first = topic && document.getElementById(topic.sections[0]);
                if (first) first.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });
        const addProgram = root.querySelector('[data-act="add-program"]');
        if (addProgram) {
            addProgram.addEventListener('click', () => {
                if (typeof window.addFacility === 'function') window.addFacility();
            });
        }
        const confirmBox = root.querySelector('.kop-wiz-confirm');
        root.querySelector('[data-act="restart"]').addEventListener('click', () => {
            confirmBox.hidden = false;
            confirmBox.querySelector('[data-act="restart-yes"]').focus();
        });
        root.querySelector('[data-act="restart-no"]').addEventListener('click', () => { confirmBox.hidden = true; });
        root.querySelector('[data-act="restart-yes"]').addEventListener('click', restart);
        bindCommon();
    }

    function renderFull() {
        setBodyMode('');
        clearFocus();
        root.innerHTML = `
            <div class="kop-wiz-slim">
                <span>You are using the full form.</span>
                <button type="button" class="kop-wiz-btn" data-act="guided">Use the guided form instead</button>
            </div>`;
        root.querySelector('[data-act="guided"]').addEventListener('click', restart);
    }

    function bindCommon() {
        root.querySelectorAll('[data-new]').forEach((btn) => {
            btn.addEventListener('click', () => {
                state.target = { isNew: true, type: btn.dataset.new, name: '', place: '', isPerson: false };
                state.step = 'new';
                render();
            });
        });
        root.querySelectorAll('[data-act="back"]').forEach((btn) => {
            btn.addEventListener('click', () => {
                state.step = 'find';
                render();
                const input = root.querySelector('#kop-wiz-q');
                if (input) input.focus();
            });
        });
        root.querySelectorAll('[data-act="full"]').forEach((btn) => {
            btn.addEventListener('click', () => {
                state.step = 'full';
                render();
            });
        });
    }

    function focusHeading() {
        const h = root.querySelector('#kop-wiz-h');
        if (h) {
            h.setAttribute('tabindex', '-1');
            h.focus({ preventScroll: true });
        }
        root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function restart() {
        state.step = 'find';
        state.target = null;
        state.topics = [];
        clearFocus();
        // A blank project, so nothing half-typed rides along with the next record.
        if (project().createNewProjectData) {
            window.formData = project().createNewProjectData();
            window.currentProjectName = '';
            window.currentFacilityIndex = 0;
            if (typeof window.updateAllUI === 'function') window.updateAllUI();
        }
        render();
        focusHeading();
    }

    // ------------------------------------------------------------------
    // Driving the form
    // ------------------------------------------------------------------

    function sectionIdsFor(topics) {
        const t = state.target;
        const ids = new Set(ALWAYS_ON);
        const extras = new Set();
        TOPICS[t.type].forEach((topic) => {
            if (!topics.includes(topic.key)) return;
            topic.sections.forEach((id) => ids.add(id));
            (topic.extra || []).forEach((sel) => extras.add(sel));
        });
        return { ids, extras };
    }

    function clearFocus() {
        document.querySelectorAll('.kop-wiz-on').forEach((el) => el.classList.remove('kop-wiz-on'));
    }

    function expand(section) {
        if (!section || section.classList.contains('expanded')) return;
        section.classList.add('expanded');
        const content = section.querySelector('.section-content');
        if (content) content.style.display = 'block';
        const toggle = section.querySelector('.section-toggle');
        if (toggle) toggle.setAttribute('aria-expanded', 'true');
    }

    // Open the sub-sections too: a picked topic should show its fields, not a
    // row of closed headings (same state ui-events.js sets on a click).
    function expandSubSections(section) {
        if (!section) return;
        section.querySelectorAll('.sub-section').forEach((sub) => {
            sub.classList.add('expanded');
            const content = sub.querySelector('.sub-section-content');
            if (content) content.style.display = 'block';
            const toggle = sub.querySelector('.sub-section-toggle');
            if (toggle) toggle.setAttribute('aria-expanded', 'true');
        });
    }

    function applyFocus() {
        clearFocus();
        const { ids, extras } = sectionIdsFor(state.topics);
        ids.forEach((id) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.classList.add('kop-wiz-on');
            if (!ALWAYS_ON.includes(id)) {
                // The early return in expand() skips sections that open expanded.
                expand(el);
                expandSubSections(el);
            }
        });
        extras.forEach((sel) => document.querySelectorAll(sel).forEach((el) => el.classList.add('kop-wiz-on')));
    }

    function newTemplate() {
        const make = project().createNewProjectData;
        return make ? make() : { facilities: [{ identification: {}, locationDetails: {} }] };
    }

    function placeFields(facility, place) {
        if (!place) return;
        const lower = place.toLowerCase();
        facility.locationDetails = facility.locationDetails || {};
        if ((window.COUNTRY_NAMES || []).includes(lower) && lower !== 'united states') {
            facility.locationDetails.country = titleCase(lower);
            facility.isInternational = true;
        } else {
            facility.locationDetails.state = (window.US_STATE_NAMES || []).includes(lower) ? titleCase(lower) : place;
        }
        facility.location = place;
    }

    function locationProjectKey(place) {
        const lower = String(place || '').trim().toLowerCase();
        if (!lower) return '';
        const known = (window.US_STATE_NAMES || []).includes(lower) || (window.COUNTRY_NAMES || []).includes(lower);
        return known ? lower.toUpperCase() : '';
    }

    function refreshView(category) {
        if (typeof window.applyViewLayout === 'function') window.applyViewLayout(category);
        if (typeof window.updatePrivateOwnershipSliderAppearance === 'function') window.updatePrivateOwnershipSliderAppearance();
    }

    async function loadNew(t) {
        const load = project().loadProject;
        const data = newTemplate();

        if (t.type === 'facility') {
            const facility = newTemplate().facilities[0];
            facility.identification.name = t.name;
            facility.isPrivatelyOwned = true;
            placeFields(facility, t.place);
            const locKey = locationProjectKey(t.place);
            if (locKey) {
                // Into the state's project: its submit sends only the facilities
                // that changed, here the new one.
                if (window.projects && window.projects[locKey]) await load(locKey);
                else await load(locKey, { data: {}, category: 'locations' });
                window.formData.facilities.push(facility);
                window.currentFacilityIndex = window.formData.facilities.length - 1;
                if (typeof window.updateAllUI === 'function') window.updateAllUI();
                return 'locations';
            }
            data.facilities = [facility];
            await load(t.name, { data, category: 'companies' });
            return 'companies';
        }

        if (t.type === 'company') {
            data.operator.name = t.name;
            await load(t.name, { data, category: 'companies' });
            return 'companies';
        }

        if (t.type === 'provider') {
            const facility = data.facilities[0];
            facility.identification.name = t.name;
            facility.isPrivatelyOwned = true;
            placeFields(facility, t.place);
            data.category = 'providers';
            await load(t.name, { data, category: 'providers' });
            return 'providers';
        }

        const isRef = t.type === 'referrer';
        const [first, ...rest] = t.name.split(/\s+/);
        if (isRef) {
            data.isIndependentConsultant = t.isPerson;
            data.referrerType = t.isPerson ? 'individual' : 'group';
            if (t.isPerson) Object.assign(data.referrerConsultants[0], { firstName: first, lastName: rest.join(' '), fullName: t.name });
            else data.referrerAgency.name = t.name;
        } else {
            data.isIndependentTransporter = t.isPerson;
            data.transporterType = t.isPerson ? 'individual' : 'company';
            if (t.isPerson) Object.assign(data.transporters[0], { firstName: first, lastName: rest.join(' '), fullName: t.name });
            else data.transporterCompany.name = t.name;
        }
        await load(t.name, { data, category: isRef ? 'referrers' : 'transporters' });
        return isRef ? 'referrers' : 'transporters';
    }

    async function loadExisting(entry) {
        const load = project().loadProject;
        // A facility sits in its state's project and its company's; the state's
        // submits only what changed, so prefer it.
        const homes = entry.homes.slice().sort((a, b) => (a.category === 'locations' ? -1 : 0) - (b.category === 'locations' ? -1 : 0));
        const home = homes[0];
        await load(home.project);

        if (entry.type === 'facility' || entry.type === 'provider') {
            const list = window.formData?.facilities || [];
            let index = entry.facilityId
                ? list.findIndex((f) => String(f?.facility_id || f?.facilityId || '') === String(entry.facilityId))
                : -1;
            if (index < 0) index = list.findIndex((f) => fold(f?.identification?.name) === fold(entry.name));
            if (index >= 0) {
                window.currentFacilityIndex = index;
                if (typeof window.updateAllUI === 'function') window.updateAllUI();
            }
        }
        return home.category;
    }

    async function openEditor() {
        const t = state.target;
        if (!project().loadProject) {
            state.step = 'full';
            render();
            return;
        }
        root.innerHTML = '<section class="kop-wiz-card"><p class="kop-wiz-muted">Opening the form...</p></section>';
        try {
            const category = t.isNew ? await loadNew(t) : await loadExisting(t);
            refreshView(category);
        } catch (error) {
            console.error('[data wizard] could not open the record', error);
            root.innerHTML = `<section class="kop-wiz-card"><p class="kop-wiz-error" role="alert">That record could not be opened. Try again, or use the full form.</p>
                <div class="kop-wiz-actions"><button type="button" class="kop-wiz-btn" data-act="back">Back to search</button>
                <button type="button" class="kop-wiz-link" data-act="full">Show the full form</button></div></section>`;
            bindCommon();
            return;
        }
        state.step = 'edit';
        applyFocus();
        render();
        focusHeading();
    }

    // ------------------------------------------------------------------
    // Start
    // ------------------------------------------------------------------

    function onReady() {
        if (state.ready) return;
        state.ready = true;
        try {
            buildIndex();
        } catch (error) {
            console.error('[data wizard] search index failed', error);
            state.index = [];
        }
        if (state.step === 'find') renderResults();
    }

    const params = new URLSearchParams(window.location.search);
    if (params.get('full') === '1') {
        state.step = 'full';
    } else if (params.get('find')) {
        state.query = params.get('find');
    } else if (TYPES[params.get('add')]) {
        state.target = { isNew: true, type: params.get('add'), name: '', place: '', isPerson: false };
        state.step = 'new';
    }

    // ui-events.js swaps text inputs for growing textareas as they are added;
    // the wizard's inputs carry data-growing-text-field="true" to stay inputs.

    // For tests and the console: what the wizard holds and its search.
    window.KOP_DataWizard = { state, search };

    render();
    if (window.formReady) onReady();
    else document.addEventListener('formReady', onReady, { once: true });

})();
