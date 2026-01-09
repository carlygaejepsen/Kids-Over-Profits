/**
 * REFERRER DIRECTORY JS - Absolute Data Renderer
 * Renders LITERALLY every field available in the record without icons.
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
        if (!val || val === 'null') return '';
        
        // Handle Links
        if (val.startsWith('http')) {
            return `<div class="kv-pair"><span class="kv-key">${label}</span><span class="kv-val"><a href="${val}" target="_blank">Link</a></span></div>`;
        }
        
        return `<div class="kv-pair"><span class="kv-key">${label}</span><span class="kv-val">${esc(val)}</span></div>`;
    }

    function renderList(list) {
        container.innerHTML = '';
        const grid = document.createElement('div');
        grid.className = 'referrer-grid';

        list.forEach(p => {
            const card = document.createElement('div');
            card.className = 'referrer-card';
            
            const pData = p.data || {};
            // Type Detection
            const type = pData.referrerType || (pData.isIndependentConsultant === false ? 'group' : 'individual');
            const label = type === 'group' ? 'Referral Agency / Group' : 'Independent Consultant';
            
            // Collect Profiles
            const profileMap = new Map();
            const raw = [];
            if (pData.referrerIndividual) raw.push(pData.referrerIndividual);
            if (Array.isArray(pData.referrerConsultants)) pData.referrerConsultants.forEach(c => raw.push(c));
            
            raw.forEach(c => {
                if (!c) return;
                const name = clean([c.firstName, c.lastName].join(' ')) || clean(c.fullName) || p.name;
                const key = name.toLowerCase();
                if (!profileMap.has(key)) profileMap.set(key, { ...c, resolvedName: name });
                else {
                    const existing = profileMap.get(key);
                    for (let k in c) if (!existing[k] || (Array.isArray(existing[k]) && existing[k].length === 0)) existing[k] = c[k];
                }
            });

            const profiles = profileMap.size > 0 ? Array.from(profileMap.values()) : [{ resolvedName: p.name }];

            // Header
            card.innerHTML = `
                <div class="referrer-card-header">
                    <h3 class="referrer-main-name">${esc(p.name)}</h3>
                    <span class="referrer-sub-label">${label}</span>
                </div>
                <div class="referrer-card-body">
                    ${profiles.map(c => {
                        const cTitle = clean(c.role || c.title || 'Educational Consultant');
                        const cLoc = clean([c.city, c.state].filter(Boolean).join(', ')) || c.location || pData.referrerAgency?.location || '';
                        
                        // Lists
                        const pastJobs = Array.isArray(c.pastTTIJobs) ? c.pastTTIJobs : [];
                        const known = Array.isArray(c.knownReferrals) ? c.knownReferrals : (Array.isArray(c.facilitiesReferred) ? c.facilitiesReferred : []);
                        const affs = Array.isArray(c.affiliations) ? c.affiliations : [];
                        const dists = Array.isArray(c.schoolDistricts) ? c.schoolDistricts : [];

                        return `
                        <div class="profile-section">
                            <div class="profile-header">${esc(c.resolvedName)}</div>
                            <div class="data-block">
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
                                    <details class="details-group">
                                        <summary>Career History (${pastJobs.length})</summary>
                                        <div class="details-content">
                                            <ul class="nested-list">
                                                ${pastJobs.map(j => `<li class="nested-item"><strong>${esc(j.role)}</strong> at ${esc(j.employer || j.organization)}</li>`).join('')}
                                            </ul>
                                        </div>
                                    </details>` : ''}

                                ${known.length && clean(known[0]) ? `
                                    <details class="details-group">
                                        <summary>Known Facility Referrals</summary>
                                        <div class="details-content">
                                            <ul class="nested-list">
                                                ${known.filter(clean).map(r => `<li class="nested-item">${esc(r)}</li>`).join('')}
                                            </ul>
                                        </div>
                                    </details>` : ''}

                                ${affs.length && clean(affs[0]) ? `
                                    <details class="details-group">
                                        <summary>Professional Affiliations</summary>
                                        <div class="details-content">
                                            <ul class="nested-list">
                                                ${affs.filter(clean).map(a => `<li class="nested-item">${esc(a)}</li>`).join('')}
                                            </ul>
                                        </div>
                                    </details>` : ''}

                                ${dists.length && clean(dists[0]) ? `
                                    <details class="details-group">
                                        <summary>School Districts</summary>
                                        <div class="details-content">
                                            <ul class="nested-list">
                                                ${dists.filter(clean).map(d => `<li class="nested-item">${esc(d)}</li>`).join('')}
                                            </ul>
                                        </div>
                                    </details>` : ''}

                                ${c.notes ? `<div style="margin-top:10px; font-size:0.9rem;"><strong>Notes:</strong><br>${esc(c.notes)}</div>` : ''}
                            </div>
                        </div>`;
                    }).join('')}
                </div>
            `;
            grid.appendChild(card);
        });
        container.appendChild(grid);
    }

    searchInput.addEventListener('input', filterAndRender);
    statusFilter.addEventListener('change', filterAndRender);
});