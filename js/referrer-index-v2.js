/**
 * REFERRER DIRECTORY JS - Precise Labeling Fix
 */

document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('referrers-container');
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const alphabetFilter = document.getElementById('alphabet-filter');

    if (!container) return;

    let allReferrers = []; 

    const clean = v => (typeof v === 'string' ? v.trim() : (v ? String(v) : ''));
    const esc = v => clean(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c));

    fetch('/wp-content/themes/child/api/get-referrers-only.php?t=' + Date.now())
        .then(res => res.json())
        .then(res => {
            if (res.success && res.projects) {
                allReferrers = Object.values(res.projects);
                allReferrers.sort((a, b) => (a.db_name || '').localeCompare(b.db_name || ''));
                
                const locs = new Set();
                allReferrers.forEach(p => {
                    const str = JSON.stringify(p).toUpperCase();
                    const states = ['AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY'];
                    states.forEach(s => { if(str.includes('"' + s + '"') || str.includes(', ' + s)) locs.add(s); });
                });
                statusFilter.innerHTML = '<option value="">All Locations</option>' + Array.from(locs).sort().map(l => `<option value="${l}">${l}</option>`).join('');

                renderAlphabet();
                filterAndRender();
            }
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

        const filtered = allReferrers.filter(p => {
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

    function findConsultantData(root) {
        const candidates = [];
        const stack = [root];
        let depth = 0;
        while (stack.length > 0 && depth < 5) {
            const current = stack.pop();
            if (!current || typeof current !== 'object') continue;
            if (current.referrerIndividual) candidates.push(current.referrerIndividual);
            if (Array.isArray(current.referrerConsultants)) current.referrerConsultants.forEach(c => candidates.push(c));
            if (current.data) stack.push(current.data);
            if (current.payload) stack.push(current.payload);
            depth++;
        }
        return candidates;
    }

    function findAgencyInfo(root) {
        if (root?.payload?.referrerAgency) return root.payload.referrerAgency;
        if (root?.payload?.data?.referrerAgency) return root.payload.data.referrerAgency;
        return {};
    }

    function renderField(label, value) {
        const val = clean(value);
        if (!val || val === 'null') return '';
        if (val.startsWith('http')) return `<div class="data-row"><span class="data-label">${label}</span><span class="data-value"><a href="${val}" target="_blank">Link</a></span></div>`;
        return `<div class="data-row"><span class="data-label">${label}</span><span class="data-value">${esc(val)}</span></div>`;
    }

    function renderList(list) {
        container.innerHTML = '';
        const grid = document.createElement('div');
        grid.className = 'referrer-grid';

        list.forEach(p => {
            const card = document.createElement('div');
            card.className = 'referrer-card';
            
            const rawProfiles = findConsultantData(p);
            const agency = findAgencyInfo(p);
            
            // Deduplicate
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

            // --- SMART LABEL LOGIC V2 ---
            let label = 'Referral Agency / Group';
            
            // 1. Explicit Flags
            const jsonStr = JSON.stringify(p.payload || {});
            const explicitIndy = jsonStr.includes('"isIndependentConsultant":true') || jsonStr.includes('"referrerType":"individual"');
            
            // 2. Name Matching Heuristic
            // If Agency Name === Consultant Name, it's an Individual
            const agencyName = clean(agency.name);
            const consultantName = profiles[0].resolvedName;
            const namesMatch = agencyName && consultantName && agencyName.toLowerCase() === consultantName.toLowerCase();
            const projectMatchesConsultant = p.db_name.toLowerCase() === consultantName.toLowerCase();

            if (explicitIndy || (profiles.length === 1 && (namesMatch || projectMatchesConsultant))) {
                label = 'Independent Consultant';
            }

            let html = `
                <div class="referrer-card-header">
                    <h3 class="referrer-main-name">${esc(p.db_name)}</h3>
                    <span class="referrer-sub-label">${label}</span>
                </div>
                <div class="referrer-card-body">`;

            // Only show Agency Info if it's NOT an individual, or if the agency name is DIFFERENT from the main name
            if (label !== 'Independent Consultant' && agencyName && agencyName.toLowerCase() !== p.db_name.toLowerCase()) {
                html += `
                    <div class="agency-info-section">
                        <div class="section-label">Organization Details</div>
                        ${renderField('Name', agency.name)}
                        ${renderField('Location', [agency.city, agency.state].filter(Boolean).join(', '))}
                        ${renderField('Website', agency.website)}
                    </div>`;
            }

            profiles.forEach(c => {
                const cTitle = clean(c.role || c.title || 'Educational Consultant');
                const cLoc = clean([c.city, c.state].filter(Boolean).join(', ')) || c.location || agency.location || '';
                const pastJobs = Array.isArray(c.pastTTIJobs) ? c.pastTTIJobs : [];
                const known = Array.isArray(c.knownReferrals) ? c.knownReferrals : (Array.isArray(c.facilitiesReferred) ? c.facilitiesReferred : []);
                const affs = Array.isArray(c.affiliations) ? c.affiliations : [];
                const dists = Array.isArray(c.schoolDistricts) ? c.schoolDistricts : [];

                html += `
                    <div class="consultant-sub-card">
                        <div class="consultant-name-header">${esc(c.resolvedName)}</div>
                        
                        ${renderField('Role', cTitle)}
                        ${renderField('Status', c.status)}
                        ${renderField('Location', cLoc)}
                        ${renderField('Phone', c.phone)}
                        ${renderField('Email', c.email)}
                        ${renderField('Website', c.website)}
                        ${renderField('Credentials', c.credentials)}
                        ${renderField('Education', c.education)}
                        ${renderField('Lawsuits', c.lawsuits)}

                        ${pastJobs.length ? `
                            <div class="list-section">
                                <div class="section-label">Career History</div>
                                <ul class="data-list">
                                    ${pastJobs.map(j => `<li class="data-list-item"><span class="job-role">${esc(j.role)}</span><span class="job-meta">at ${esc(j.employer || j.organization)}</span></li>`).join('')}
                                </ul>
                            </div>` : ''}

                        ${known.length && clean(known[0]) ? `
                            <div class="list-section">
                                <div class="section-label">Facility Referrals</div>
                                <ul class="data-list">
                                    ${known.filter(clean).map(r => `<li class="data-list-item"><span class="job-role">${esc(r)}</span></li>`).join('')}
                                </ul>
                            </div>` : ''}

                        ${affs.length && clean(affs[0]) ? `
                            <div class="list-section">
                                <div class="section-label">Affiliations</div>
                                <ul class="data-list">
                                    ${affs.filter(clean).map(a => `<li class="data-list-item"><span class="job-role">${esc(a)}</span></li>`).join('')}
                                </ul>
                            </div>` : ''}

                        ${dists.length && clean(dists[0]) ? `
                            <div class="list-section">
                                <div class="section-label">School Districts</div>
                                <ul class="data-list">
                                    ${dists.filter(clean).map(d => `<li class="data-list-item"><span class="job-role">${esc(d)}</span></li>`).join('')}
                                </ul>
                            </div>` : ''}

                        ${c.notes ? `<div class="list-section"><div class="section-label">Notes</div><div class="data-value">${esc(c.notes)}</div></div>` : ''}
                    </div>`;
            });

            html += `</div>`;
            card.innerHTML = html;
            grid.appendChild(card);
        });
        container.appendChild(grid);
    }

    searchInput.addEventListener('input', filterAndRender);
    statusFilter.addEventListener('change', filterAndRender);
});