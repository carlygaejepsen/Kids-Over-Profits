/**
 * TRANSPORTER DIRECTORY JS - Collapsible Tiles (TTI Style)
 */

document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('transporters-container');
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const alphabetFilter = document.getElementById('alphabet-filter');

    if (!container) return;

    let allTransporters = [];

    const clean = v => (typeof v === 'string' ? v.trim() : (v ? String(v) : ''));
    const esc = v => clean(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c));

    const STATE_CODES = ['AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY'];
    const STATE_NAMES = {'ALABAMA':'AL','ALASKA':'AK','ARIZONA':'AZ','ARKANSAS':'AR','CALIFORNIA':'CA','COLORADO':'CO','CONNECTICUT':'CT','DELAWARE':'DE','FLORIDA':'FL','GEORGIA':'GA','HAWAII':'HI','IDAHO':'ID','ILLINOIS':'IL','INDIANA':'IN','IOWA':'IA','KANSAS':'KS','KENTUCKY':'KY','LOUISIANA':'LA','MAINE':'ME','MARYLAND':'MD','MASSACHUSETTS':'MA','MICHIGAN':'MI','MINNESOTA':'MN','MISSISSIPPI':'MS','MISSOURI':'MO','MONTANA':'MT','NEBRASKA':'NE','NEVADA':'NV','NEW HAMPSHIRE':'NH','NEW JERSEY':'NJ','NEW MEXICO':'NM','NEW YORK':'NY','NORTH CAROLINA':'NC','NORTH DAKOTA':'ND','OHIO':'OH','OKLAHOMA':'OK','OREGON':'OR','PENNSYLVANIA':'PA','RHODE ISLAND':'RI','SOUTH CAROLINA':'SC','SOUTH DAKOTA':'SD','TENNESSEE':'TN','TEXAS':'TX','UTAH':'UT','VERMONT':'VT','VIRGINIA':'VA','WASHINGTON':'WA','WEST VIRGINIA':'WV','WISCONSIN':'WI','WYOMING':'WY'};

    // Collect the record's actual state values from `state` fields instead of
    // substring-scanning its JSON — that matched "IN" inside "consulting",
    // "ME" inside names, etc., making the filter meaningless for many states.
    function recordStates(p) {
        const found = new Set();
        const addState = raw => {
            const s = clean(raw).toUpperCase().replace(/\./g, '').trim();
            if (!s) return;
            if (STATE_CODES.indexOf(s) !== -1) { found.add(s); return; }
            if (STATE_NAMES[s]) found.add(STATE_NAMES[s]);
        };
        const stack = [p];
        let guard = 0;
        while (stack.length && guard < 500) {
            const cur = stack.pop();
            guard++;
            if (!cur || typeof cur !== 'object') continue;
            if (typeof cur.state === 'string') addState(cur.state);
            if (typeof cur.locationState === 'string') addState(cur.locationState);
            Object.values(cur).forEach(v => { if (v && typeof v === 'object') stack.push(v); });
        }
        return found;
    }

    // Load Data
    fetch('/wp-content/themes/child/api/get-transporters-only.php?t=' + Date.now())
        .then(res => res.json())
        .then(res => {
            if (res.success && res.projects) {
                allTransporters = Object.values(res.projects);
                allTransporters.sort((a, b) => (a.db_name || '').localeCompare(b.db_name || ''));

                const locs = new Set();
                allTransporters.forEach(p => { recordStates(p).forEach(s => locs.add(s)); });
                statusFilter.innerHTML = '<option value="">All Locations</option>' + Array.from(locs).sort().map(l => `<option value="${l}">${l}</option>`).join('');

                renderAlphabet();
                filterAndRender();
            } else {
                container.innerHTML = '<div style="text-align:center;padding:50px;color:#666;">No transporter records yet.</div>';
            }
        })
        .catch(err => {
            container.innerHTML = '<div style="text-align:center;padding:50px;color:#a33;">Could not load transporter directory.</div>';
            console.error('Transporter directory load error:', err);
        });

    function renderAlphabet() {
        const alpha = '#ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
        alphabetFilter.innerHTML = alpha.map(c => `<button type="button" class="alpha-btn" onclick="window.filterByChar('${c}')">${c}</button>`).join('') +
            `<button type="button" class="alpha-btn" onclick="window.filterByChar('')">All</button>`;
        window.filterByChar = (c) => {
            document.querySelectorAll('.alpha-btn').forEach(b => b.classList.toggle('active', b.innerText === (c||'All')));
            window.currentAlphaFilter = c;
            filterAndRender();
        };
    }

    function filterAndRender() {
        const q = (searchInput.value || '').toLowerCase();
        const l = (statusFilter.value || '').toUpperCase();
        const a = (window.currentAlphaFilter || '').toLowerCase();

        const filtered = allTransporters.filter(p => {
            const name = (p.db_name || '').toLowerCase();
            const dataStr = JSON.stringify(p).toLowerCase();
            if (a && a === '#' && !/^[0-9]/.test(name)) return false;
            if (a && a !== '#' && !name.startsWith(a)) return false;
            if (q && !name.includes(q) && !dataStr.includes(q)) return false;
            if (l && !recordStates(p).has(l)) return false;
            return true;
        });
        renderList(filtered);
    }

    function findTransporterData(root) {
        const candidates = [];
        const stack = [root];
        let depth = 0;
        while (stack.length > 0 && depth < 5) {
            const current = stack.pop();
            if (!current || typeof current !== 'object') continue;
            if (current.transporterIndividual) candidates.push(current.transporterIndividual);
            if (Array.isArray(current.transporters)) current.transporters.forEach(c => candidates.push(c));
            if (current.data) stack.push(current.data);
            if (current.payload) stack.push(current.payload);
            depth++;
        }
        return candidates;
    }

    function findCompanyInfo(root) {
        if (root?.payload?.transporterCompany) return root.payload.transporterCompany;
        if (root?.payload?.data?.transporterCompany) return root.payload.data.transporterCompany;
        if (root?.payload?.transporterAgency) return root.payload.transporterAgency;
        if (root?.payload?.data?.transporterAgency) return root.payload.data.transporterAgency;
        return {};
    }

    function renderField(label, value) {
        const val = clean(value);
        if (!val || val === 'null') return '';
        if (val.startsWith('http')) return `<div class="data-row"><span class="data-label">${label}</span><span class="data-value"><a href="${esc(val)}" target="_blank" rel="noopener">Link</a></span></div>`;
        return `<div class="data-row"><span class="data-label">${label}</span><span class="data-value">${esc(val)}</span></div>`;
    }

    // One display string per array entry — handles plain strings and the
    // {role, name} / {url, displayText} object shapes the form saves.
    function itemText(item) {
        if (item == null) return '';
        if (typeof item === 'object' && !Array.isArray(item)) {
            const name = clean(item.name || item.text || item.value || item.employer || item.organization || item.displayText || item.url);
            const role = clean(item.role || item.title);
            return role && name ? `${name} — ${role}` : (name || role);
        }
        return clean(item);
    }

    function renderArrayList(label, arr) {
        const items = (Array.isArray(arr) ? arr : (arr ? [arr] : [])).map(itemText).filter(Boolean);
        if (!items.length) return '';
        return `
            <div class="list-section">
                <div class="section-label">${esc(label)}</div>
                <ul class="data-list">
                    ${items.map(a => `<li class="data-list-item"><span class="job-role">${esc(a)}</span></li>`).join('')}
                </ul>
            </div>`;
    }

    function renderLinkList(label, arr) {
        const items = (Array.isArray(arr) ? arr : (arr ? [arr] : [])).map(itemText).filter(Boolean);
        if (!items.length) return '';
        const lis = items.map(u => {
            if (/^https?:\/\//i.test(u)) {
                let d = u.replace(/^https?:\/\/(www\.)?/i, '');
                if (d.length > 50) d = d.slice(0, 47) + '…';
                return `<li class="data-list-item"><a href="${esc(u)}" target="_blank" rel="noopener">${esc(d)}</a></li>`;
            }
            return `<li class="data-list-item"><span class="job-role">${esc(u)}</span></li>`;
        }).join('');
        return `<div class="list-section"><div class="section-label">${esc(label)}</div><ul class="data-list">${lis}</ul></div>`;
    }

    // Notes may be a plain string, an array of strings, or [{text}] objects.
    function renderNotes(label, notes) {
        const items = (Array.isArray(notes) ? notes : (notes ? [notes] : []))
            .map(n => (typeof n === 'object' && n) ? clean(n.text || n.value) : clean(n))
            .filter(Boolean);
        if (!items.length) return '';
        return `<div class="list-section"><div class="section-label">${esc(label)}</div>${items.map(t => `<div class="data-value">${esc(t)}</div>`).join('')}</div>`;
    }

    // Per-field research notes: {key: "text"} / {key: ["text"]} / {key: [{text}]}.
    // Auto-generated "field-<timestamp>" keys carry no readable label, so those
    // notes render bare.
    function renderFieldNotes(fieldNotes) {
        if (!fieldNotes || typeof fieldNotes !== 'object' || Array.isArray(fieldNotes)) return '';
        const rows = [];
        Object.keys(fieldNotes).forEach(key => {
            const raw = fieldNotes[key];
            const texts = (Array.isArray(raw) ? raw : [raw])
                .map(n => (typeof n === 'object' && n) ? clean(n.text) : clean(n))
                .filter(Boolean);
            if (!texts.length) return;
            const leaf = String(key).split('.').pop();
            const label = /^field-\d/.test(leaf) ? '' : leaf
                .replace(/^has(?=[A-Z0-9])/, '')
                .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
                .trim().replace(/^./, c => c.toUpperCase());
            texts.forEach(t => rows.push(`<li class="data-list-item"><span class="job-role">${label ? esc(label) + ': ' : ''}${esc(t)}</span></li>`));
        });
        if (!rows.length) return '';
        return `<div class="list-section"><div class="section-label">Research Notes</div><ul class="data-list">${rows.join('')}</ul></div>`;
    }

    // True when the record holds anything renderable besides its name.
    function hasMeaningfulData(obj, skipKeys) {
        if (!obj || typeof obj !== 'object') return false;
        return Object.keys(obj).some(k => {
            if (skipKeys.indexOf(k) !== -1) return false;
            const v = obj[k];
            if (Array.isArray(v)) return v.some(x => itemText(x));
            if (v && typeof v === 'object') return hasMeaningfulData(v, []);
            const t = clean(v);
            return t !== '' && t !== 'null';
        });
    }

    function renderList(list) {
        container.innerHTML = '';
        const grid = document.createElement('div');
        grid.className = 'transporter-grid';

        list.forEach(p => {
            const card = document.createElement('details');
            card.className = 'transporter-card';
            card.setAttribute('data-kop-bug-feature', 'transporter-index/card');
            card.setAttribute('data-kop-bug-label', 'Transporter: ' + (p.db_name || ''));

            const rawProfiles = findTransporterData(p);
            const company = findCompanyInfo(p);

            const profileMap = new Map();
            rawProfiles.forEach(c => {
                if (!c) return;
                const name = clean([c.firstName, c.lastName].join(' ')) || clean(c.fullName) || p.db_name;
                const key = name.toLowerCase();
                if (!profileMap.has(key)) profileMap.set(key, { ...c, resolvedName: name });
                else {
                    const existing = profileMap.get(key);
                    for (let k in c) if (!existing[k] || (Array.isArray(existing[k]) && existing[k].length === 0)) existing[k] = c[k];
                }
            });
            const profiles = profileMap.size > 0 ? Array.from(profileMap.values()) : [{ resolvedName: p.db_name }];

            let label = 'Transport Company';
            const jsonStr = JSON.stringify(p.payload || {});
            const explicitIndy = jsonStr.includes('"isIndependentTransporter":true') || jsonStr.includes('"transporterType":"individual"');
            const companyName = clean(company.name);
            const transporterName = profiles[0].resolvedName;

            if (explicitIndy || (profiles.length === 1 && (companyName === transporterName || p.db_name.toLowerCase() === transporterName.toLowerCase()))) {
                label = 'Independent Transporter';
            }

            let html = `
                <summary class="transporter-card-summary">
                    <h3 class="transporter-main-name">${esc(p.db_name)}</h3>
                    <span class="transporter-sub-label">${label}</span>
                </summary>

                <div class="transporter-card-body">`;

            // Render company details whenever the record holds any — previously
            // this whole section was skipped for independent transporters and for
            // companies whose name matches the card title, hiding restraint
            // practices, licensing, lawsuits, etc. saved on the company record.
            const companyHasData = hasMeaningfulData(company, ['name']);
            if (companyHasData || (companyName && companyName.toLowerCase() !== p.db_name.toLowerCase())) {
                const showName = companyName && companyName.toLowerCase() !== p.db_name.toLowerCase();
                html += `
                    <div class="company-info-section">
                        <div class="section-label">Company Details</div>
                        ${showName ? renderField('Name', company.name) : ''}
                        ${renderField('Location', [company.city, company.state, company.country].filter(Boolean).join(', '))}
                        ${renderField('Founded', company.founded)}
                        ${renderField('Status', company.status)}
                        ${renderField('Bonded', company.bonded)}
                        ${renderField('Insured', company.insured)}
                        ${renderField('BBB Rating', company.bbbRating)}
                        ${renderField('Website', company.website)}
                        ${renderArrayList('Other Names', company.otherNames)}
                        ${renderArrayList('Parent Companies', company.parentCompanies)}
                        ${renderLinkList('Websites', company.websites)}
                        ${renderLinkList('Social Media', company.socialMedia)}
                        ${renderArrayList('Service Areas', company.serviceAreas)}
                        ${renderArrayList('Vehicle Types', company.vehicleTypes)}
                        ${renderArrayList('Pickup Methods', company.pickupMethods)}
                        ${renderArrayList('Restraint Practices', company.restraintPractices)}
                        ${renderArrayList('Licensing', company.licensing)}
                        ${renderArrayList('Affiliations', company.affiliations)}
                        ${renderArrayList('Key Personnel', company.keyPersonnel)}
                        ${renderArrayList('Known Facilities Transported To', company.knownFacilities)}
                        ${renderArrayList('Known Referrers', company.knownReferrers)}
                        ${renderArrayList('Lawsuits', company.lawsuits)}
                        ${renderLinkList('Source URLs', company.sourceUrls)}
                        ${renderNotes('Pricing Notes', company.pricingNotes)}
                        ${renderNotes('Notes', company.notes)}
                        ${renderFieldNotes(company.fieldNotes)}
                    </div>`;
            }

            profiles.forEach(c => {
                if (!c.firstName && !c.lastName && !c.fullName && !c.role) return;
                const cTitle = clean(c.role || c.title || 'Transporter');
                const cLoc = clean([c.city, c.state].filter(Boolean).join(', '));
                const pastJobs = Array.isArray(c.pastTTIJobs) ? c.pastTTIJobs : [];
                const affs = Array.isArray(c.affiliations) ? c.affiliations : [];
                const affCos = Array.isArray(c.affiliatedCompanies) ? c.affiliatedCompanies : [];

                html += `
                    <div class="transporter-sub-card">
                        <div class="transporter-name-header">${esc(c.resolvedName)}</div>

                        ${renderField('Role', cTitle)}
                        ${renderField('Status', c.status)}
                        ${renderField('Location', cLoc)}
                        ${renderField('Phone', c.phone)}
                        ${renderField('Email', c.email)}
                        ${renderField('Website', c.website)}
                        ${renderField('Credentials', c.credentials)}
                        ${renderField('Lawsuits', c.lawsuits)}

                        ${pastJobs.length ? `
                            <div class="list-section">
                                <div class="section-label">Career History</div>
                                <ul class="data-list">
                                    ${pastJobs.map(j => {
                                        if (typeof j === 'string') return `<li class="data-list-item"><span class="job-role">${esc(j)}</span></li>`;
                                        return `<li class="data-list-item"><span class="job-role">${esc(j.role)}</span><span class="job-meta">at ${esc(j.employer || j.organization)}</span></li>`;
                                    }).join('')}
                                </ul>
                            </div>` : ''}

                        ${renderArrayList('Affiliated Transport Companies', affCos)}
                        ${renderArrayList('Affiliations', affs)}
                        ${renderLinkList('Websites', c.websites)}

                        ${renderNotes('Notes', c.notes)}
                        ${renderFieldNotes(c.fieldNotes)}
                    </div>`;
            });

            html += `
                <div class="kop-submit-info-row">
                    <button type="button" class="kop-submit-info-btn" data-kop-submit-type="transporter" data-kop-submit-name="${esc(p.db_name)}">✏️ Submit info about this transporter</button>
                </div>
            </div>`;
            card.innerHTML = html;
            grid.appendChild(card);
        });

        if (!list.length) {
            container.innerHTML = '<div style="text-align:center;padding:50px;color:#666;">No transporter records match your filters.</div>';
            return;
        }

        container.appendChild(grid);
    }

    window.clearSearch = () => {
        searchInput.value = '';
        statusFilter.value = '';
        window.currentAlphaFilter = '';
        document.querySelectorAll('.alpha-btn').forEach(b => b.classList.toggle('active', b.innerText === 'All'));
        filterAndRender();
    };

    searchInput.addEventListener('input', filterAndRender);
    statusFilter.addEventListener('change', filterAndRender);
});
