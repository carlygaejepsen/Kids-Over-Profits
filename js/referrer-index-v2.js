/**
 * REFERRER DIRECTORY JS - Ultimate Data Integrity Version
 * Ensures every consultant within an agency gets a card-style view.
 * Renders LITERALLY every field available.
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

    // Load Data
    fetch('/wp-content/themes/child/api/get-referrers-only.php?t=' + Date.now())
        .then(res => res.json())
        .then(res => {
            if (res.success && res.projects) {
                allReferrers = Object.values(res.projects);
                allReferrers.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
                
                // Build Location List
                const locs = new Set();
                allReferrers.forEach(p => {
                    const str = JSON.stringify(p);
                    const states = ['AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY'];
                    states.forEach(s => { if(str.includes('"' + s + '"')) locs.add(s); });
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
            const name = (p.name || '').toLowerCase();
            const dataStr = JSON.stringify(p).toLowerCase();
            if (a && a === '#' && !/^[0-9]/.test(name)) return false;
            if (a && a !== '#' && !name.startsWith(a)) return false;
            if (q && !name.includes(q) && !dataStr.includes(q)) return false;
            if (l && !dataStr.includes('"' + l.toUpperCase() + '"')) return false;
            return true;
        });
        renderList(filtered);
    }

    /**
     * Helper to render generic data fields
     */
    function renderField(label, value) {
        const val = clean(value);
        if (!val || val === 'null' || val === 'undefined') return '';
        if (val.startsWith('http')) {
            return `<div class="data-row"><span class="data-label">${label}</span><span class="data-value"><a href="${val}" target="_blank">Website Link</a></span></div>`;
        }
        return `<div class="data-row"><span class="data-label">${label}</span><span class="data-value">${esc(val)}</span></div>`;
    }

    function renderList(list) {
        container.innerHTML = '';
        const grid = document.createElement('div');
        grid.className = 'referrer-grid';

        list.forEach(p => {
            const card = document.createElement('div');
            card.className = 'referrer-card';
            
            const pData = p.data || {};
            const agency = pData.referrerAgency || pData.referrerGroup || {};
            
            // Labels
            const isIndy = pData.isIndependentConsultant === true || pData.referrerType === 'individual';
            const label = isIndy ? 'Independent Consultant' : 'Referral Agency / Group';
            
            // Profiles Collection
            const profileMap = new Map();
            const raw = [];
            if (pData.referrerIndividual) raw.push(pData.referrerIndividual);
            if (Array.isArray(pData.referrerConsultants)) pData.referrerConsultants.forEach(c => { if(c) raw.push(c); });
            
            raw.forEach(c => {
                const name = clean([c.firstName, c.lastName].join(' ')) || clean(c.fullName) || p.name;
                const key = name.toLowerCase();
                if (!profileMap.has(key)) profileMap.set(key, { ...c, resolvedName: name });
                else {
                    const existing = profileMap.get(key);
                    for (let k in c) if (!existing[k] || (Array.isArray(existing[k]) && existing[k].length === 0)) existing[k] = c[k];
                }
            });

            const profiles = Array.from(profileMap.values());
            if (profiles.length === 0) profiles.push({ resolvedName: p.name });

            // Card Header
            let html = `
                <div class="referrer-card-header">
                    <h3 class="referrer-main-name">${esc(p.name)}</h3>
                    <span class="referrer-sub-label">${label}</span>
                </div>
                <div class="referrer-card-body">`;

            // Agency Details (If it's a group or has specific agency data)
            if (!isIndy || clean(agency.name)) {
                html += `
                    <div class="agency-info-section">
                        <div class="section-label">Organization Details</div>
                        ${renderField('Organization', agency.name || p.name)}
                        ${renderField('Address', agency.address)}
                        ${renderField('City', agency.city)}
                        ${renderField('State', agency.state)}
                        ${renderField('Website', agency.website)}
                        ${renderField('Notes', agency.notes)}
                    </div>`;
            }

            // Consultant Sub-Cards
            profiles.forEach(c => {
                const cTitle = clean(c.role || c.title || 'Consultant');
                const cLoc = clean([c.city, c.state].filter(Boolean).join(', ')) || c.location || '';
                const pastJobs = Array.isArray(c.pastTTIJobs) ? c.pastTTIJobs : [];
                const known = Array.isArray(c.knownReferrals) ? c.knownReferrals : (Array.isArray(c.facilitiesReferred) ? c.facilitiesReferred : []);
                const affiliations = Array.isArray(c.affiliations) ? c.affiliations : [];
                const districts = Array.isArray(c.schoolDistricts) ? c.schoolDistricts : [];

                html += `
                    <div class="consultant-sub-card">
                        <div class="consultant-name-header">${esc(c.resolvedName)}</div>
                        
                        ${renderField('Role', cTitle)}
                        ${renderField('Status', c.status)}
                        ${renderField('Location', cLoc)}
                        ${renderField('Phone', c.phone)}
                        ${renderField('Email', c.email)}
                        ${renderField('Website', c.website)}
                        ${renderField('Education', c.education)}
                        ${renderField('Credentials', c.credentials)}
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
                                    ${known.filter(clean).map(r => `<li class="data-list-item"><span class="item-primary">${esc(r)}</span></li>`).join('')}
                                </ul>
                            </div>` : ''}

                        ${affiliations.length && clean(affiliations[0]) ? `
                            <div class="list-section">
                                <div class="section-label">Affiliations</div>
                                <ul class="data-list">
                                    ${affiliations.filter(clean).map(a => `<li class="data-list-item"><span class="item-primary">${esc(a)}</span></li>`).join('')}
                                </ul>
                            </div>` : ''}

                        ${districts.length && clean(districts[0]) ? `
                            <div class="list-section">
                                <div class="section-label">School Districts</div>
                                <ul class="data-list">
                                    ${districts.filter(clean).map(d => `<li class="data-list-item">${esc(d)}</li>`).join('')}
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
