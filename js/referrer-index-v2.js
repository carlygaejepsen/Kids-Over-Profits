/**
 * REFERRER DIRECTORY JS - Final Robust Version
 * Merges all possible consultant data paths. Strictly referrers_master.
 */

document.addEventListener('DOMContentLoaded', function() {
    const containerId = 'referrers-container';
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const alphabetFilter = document.getElementById('alphabet-filter');

    let allReferrers = []; 
    let filteredReferrers = [];

    const clean = v => (typeof v === 'string' ? v.trim() : (v ? String(v) : ''));
    const esc = v => clean(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c));

    window.clearSearch = () => {
        searchInput.value = '';
        statusFilter.value = '';
        window.currentAlphaFilter = '';
        document.querySelectorAll('.alpha-btn').forEach(b => b.classList.remove('active'));
        filterAndRender();
    };

    // Load Data
    fetch('/wp-content/themes/child/api/get-master-data.php?t=' + Date.now())
        .then(res => res.json())
        .then(res => {
            if (res.success && res.projects) {
                // Filter strictly for referrers source table
                allReferrers = Object.values(res.projects).filter(p => p._sourceTable === 'referrers');
                allReferrers.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
                
                initLocations(allReferrers);
                renderAlphabet();
                filterAndRender();
            } else {
                document.getElementById(containerId).innerHTML = '<p>Error loading referrers table.</p>';
            }
        })
        .catch(err => { console.error('Fetch Error:', err); });

    function initLocations(data) {
        const locs = new Set();
        data.forEach(p => {
            const d = p.data || {};
            const items = [];
            if (d.referrerIndividual) items.push(d.referrerIndividual);
            if (Array.isArray(d.referrerConsultants)) d.referrerConsultants.forEach(i => items.push(i));
            
            items.forEach(c => {
                if (!c) return;
                const s = clean(c.state) || (c.location && c.location.includes(',') ? c.location.split(',').pop().trim() : null);
                if (s) locs.add(s.toUpperCase());
            });
        });
        statusFilter.innerHTML = '<option value="">All Locations</option>' + 
            Array.from(locs).sort().map(l => `<option value="${l}">${l}</option>`).join('');
    }

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
        const query = searchInput.value.toLowerCase();
        const loc = statusFilter.value.toLowerCase();
        const alpha = (window.currentAlphaFilter || '').toLowerCase();

        filteredReferrers = allReferrers.filter(p => {
            const name = (p.name || '').toLowerCase();
            const dataStr = JSON.stringify(p).toLowerCase();
            
            if (alpha && alpha === '#' && !/^[0-9]/.test(name)) return false;
            if (alpha && alpha !== '#' && !name.startsWith(alpha)) return false;
            if (query && !name.includes(query) && !dataStr.includes(query)) return false;
            if (loc && !dataStr.includes(loc)) return false;
            
            return true;
        });

        renderList();
    }

    function renderList() {
        const container = document.getElementById(containerId);
        container.innerHTML = '';

        if (!filteredReferrers.length) {
            container.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:50px;">No referrers found.</div>';
            return;
        }

        const grid = document.createElement('div');
        grid.className = 'referrer-grid';

        filteredReferrers.forEach(p => {
            const pData = p.data || {};
            const agency = pData.referrerAgency || pData.referrerGroup || {};
            
            // AGGREGATE ALL POSSIBLE CONSULTANT ENTRIES
            let rawList = [];
            if (pData.referrerIndividual) rawList.push(pData.referrerIndividual);
            if (Array.isArray(pData.referrerConsultants)) rawList = rawList.concat(pData.referrerConsultants);
            
            // Filter out entries that are completely empty
            rawList = rawList.filter(c => c && (c.firstName || c.lastName || c.fullName || c.role || c.email || c.phone || (c.pastTTIJobs && c.pastTTIJobs.length)));

            // Deduplicate by Name to prevent doubles (especially for individuals like Mary Jo)
            const consultants = [];
            const seenNames = new Set();
            rawList.forEach(c => {
                const cName = clean([c.firstName, c.lastName].join(' ')) || clean(c.fullName) || p.name;
                const nameKey = cName.toLowerCase();
                if (!seenNames.has(nameKey)) {
                    seenNames.add(nameKey);
                    consultants.push({ ...c, resolvedName: cName });
                }
            });

            // Absolute Fallback
            if (consultants.length === 0) consultants.push({ resolvedName: p.name, role: 'Consultant' });

            const card = document.createElement('div');
            card.className = 'referrer-card';
            
            const isAgency = !!(clean(agency.name) || clean(pData.referrerAgency?.name));

            card.innerHTML = `
                <div class="referrer-card-header">
                    <h3 class="referrer-main-name">${esc(p.name)}</h3>
                    <span class="referrer-sub-label">${isAgency ? 'Referral Agency' : 'Educational Consultant'}</span>
                </div>
                <div class="referrer-card-body">
                    ${consultants.map(c => {
                        const cTitle = clean(c.role || c.title || (isAgency ? 'Consultant' : 'Educational Consultant'));
                        const cLoc = clean([c.city, c.state].filter(Boolean).join(', ')) || c.location || agency.location || '';

                        return `
                        <div class="consultant-profile">
                            <div class="consultant-name">${esc(c.resolvedName)}</div>
                            <div class="consultant-title">${esc(cTitle)}</div>
                            
                            ${cLoc ? `<div class="referrer-detail-row"><span class="detail-icon">📍</span><span class="detail-value">${esc(cLoc)}</span></div>` : ''}
                            ${c.email ? `<div class="referrer-detail-row"><span class="detail-icon">📧</span><span class="detail-value"><a href="mailto:${c.email}">${esc(c.email)}</a></span></div>` : ''}
                            ${c.phone ? `<div class="referrer-detail-row"><span class="detail-icon">📞</span><span class="detail-value"><a href="tel:${c.phone}">${esc(c.phone)}</a></span></div>` : ''}
                            ${(c.website || agency.website) ? `<div class="referrer-detail-row"><span class="detail-icon">🔗</span><span class="detail-value"><a href="${c.website || agency.website}" target="_blank">Website</a></span></div>` : ''}

                            ${(c.pastTTIJobs && c.pastTTIJobs.length) ? `
                                <details class="referrer-expandable">
                                    <summary>View Past TTI Jobs (${c.pastTTIJobs.length})</summary>
                                    <div class="expandable-content">
                                        <ul class="data-list">
                                            ${c.pastTTIJobs.map(j => `<li class="data-list-item"><span class="job-role">${esc(j.role)}</span><span class="job-employer">at ${esc(j.employer || j.organization)}</span></li>`).join('')}
                                        </ul>
                                    </div>
                                </details>` : ''}

                            ${(c.knownReferrals && c.knownReferrals.length && clean(c.knownReferrals[0])) ? `
                                <details class="referrer-expandable">
                                    <summary>Known Facility Referrals</summary>
                                    <div class="expandable-content">
                                        <ul class="data-list">
                                            ${c.knownReferrals.filter(clean).map(r => `<li class="data-list-item">${esc(r)}</li>`).join('')}
                                        </ul>
                                    </div>
                                </details>` : ''}
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