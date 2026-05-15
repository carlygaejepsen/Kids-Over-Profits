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

    // Load Data
    fetch('/wp-content/themes/child/api/get-transporters-only.php?t=' + Date.now())
        .then(res => res.json())
        .then(res => {
            if (res.success && res.projects) {
                allTransporters = Object.values(res.projects);
                allTransporters.sort((a, b) => (a.db_name || '').localeCompare(b.db_name || ''));

                const locs = new Set();
                allTransporters.forEach(p => {
                    const str = JSON.stringify(p).toUpperCase();
                    const states = ['AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY'];
                    states.forEach(s => { if(str.includes('"' + s + '"') || str.includes(', ' + s)) locs.add(s); });
                });
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
        const l = (statusFilter.value || '').toLowerCase();
        const a = (window.currentAlphaFilter || '').toLowerCase();

        const filtered = allTransporters.filter(p => {
            const name = (p.db_name || '').toLowerCase();
            const dataStr = JSON.stringify(p).toLowerCase();
            if (a && a === '#' && !/^[0-9]/.test(name)) return false;
            if (a && a !== '#' && !name.startsWith(a)) return false;
            if (q && !name.includes(q) && !dataStr.includes(q)) return false;
            if (l && !dataStr.includes(l)) return false;
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
        if (val.startsWith('http')) return `<div class="data-row"><span class="data-label">${label}</span><span class="data-value"><a href="${val}" target="_blank" rel="noopener">Link</a></span></div>`;
        return `<div class="data-row"><span class="data-label">${label}</span><span class="data-value">${esc(val)}</span></div>`;
    }

    function renderArrayList(label, arr) {
        if (!Array.isArray(arr) || !arr.length || !clean(arr[0])) return '';
        return `
            <div class="list-section">
                <div class="section-label">${esc(label)}</div>
                <ul class="data-list">
                    ${arr.filter(clean).map(a => `<li class="data-list-item"><span class="job-role">${esc(a)}</span></li>`).join('')}
                </ul>
            </div>`;
    }

    function renderList(list) {
        container.innerHTML = '';
        const grid = document.createElement('div');
        grid.className = 'transporter-grid';

        list.forEach(p => {
            const card = document.createElement('details');
            card.className = 'transporter-card';

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

            if (label !== 'Independent Transporter' && companyName && companyName.toLowerCase() !== p.db_name.toLowerCase()) {
                html += `
                    <div class="company-info-section">
                        <div class="section-label">Company Details</div>
                        ${renderField('Name', company.name)}
                        ${renderField('Location', [company.city, company.state, company.country].filter(Boolean).join(', '))}
                        ${renderField('Founded', company.founded)}
                        ${renderField('Status', company.status)}
                        ${renderField('Bonded', company.bonded)}
                        ${renderField('Insured', company.insured)}
                        ${renderField('BBB Rating', company.bbbRating)}
                        ${renderField('Website', company.website)}
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
                        ${renderArrayList('Source URLs', company.sourceUrls)}
                        ${company.pricingNotes ? `<div class="list-section"><div class="section-label">Pricing Notes</div><div class="data-value">${esc(company.pricingNotes)}</div></div>` : ''}
                        ${company.notes ? `<div class="list-section"><div class="section-label">Notes</div><div class="data-value">${esc(company.notes)}</div></div>` : ''}
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

                        ${c.notes ? `<div class="list-section"><div class="section-label">Notes</div><div class="data-value">${esc(c.notes)}</div></div>` : ''}
                    </div>`;
            });

            html += `</div>`;
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
