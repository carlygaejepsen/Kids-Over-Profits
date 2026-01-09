/**
 * REFERRER DIRECTORY JS - Clean & Specific
 * Pulls ONLY from referrers_master. Fresh custom styling.
 */

document.addEventListener('DOMContentLoaded', function() {
    const containerId = 'referrers-container';
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const alphabetFilter = document.getElementById('alphabet-filter');

    let allReferrers = []; 
    let filteredReferrers = [];

    const clean = v => (typeof v === 'string' ? v.trim() : (v ? String(v) : ''));
    const escape = v => clean(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c));

    // Clear search
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
                // STRICT FILTER: Only entries from referrers_master
                allReferrers = Object.values(res.projects).filter(p => p._sourceTable === 'referrers');
                
                initLocations(allReferrers);
                renderAlphabet();
                filterAndRender();
            } else {
                document.getElementById(containerId).innerHTML = '<p>Error loading referrers table.</p>';
            }
        })
        .catch(err => { console.error(err); });

    function initLocations(data) {
        const locs = new Set();
        data.forEach(p => {
            const d = p.data || {};
            const cons = d.referrerConsultants || (d.referrerIndividual ? [d.referrerIndividual] : []);
            cons.forEach(c => {
                const s = c?.state || (c?.location?.includes(',') ? c.location.split(',').pop().trim() : null);
                if (s) locs.add(s.toUpperCase());
            });
        });
        statusFilter.innerHTML = '<option value="">All Locations</option>' + 
            Array.from(locs).sort().map(l => `<option value="${l}">${l}</option>`).join('');
    }

    function renderAlphabet() {
        const alpha = '#ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
        alphabetFilter.innerHTML = alpha.map(c => `<button class="alpha-btn" onclick="window.filterByChar('${c}')">${c}</button>`).join('') + 
            `<button class="alpha-btn" onclick="window.filterByChar('')">All</button>`;
        
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
            
            // Extract consultants from the record
            let consultants = pData.referrerConsultants || [];
            if (!consultants.length && pData.referrerIndividual) consultants = [pData.referrerIndividual];
            if (!consultants.length) consultants = [{ fullName: p.name }]; // Fallback to record name

            const card = document.createElement('div');
            card.className = 'referrer-card';
            
            card.innerHTML = `
                <div class="referrer-card-header">
                    <h3 class="referrer-main-name">${escape(p.name)}</h3>
                    <span class="referrer-sub-label">${agency.name ? 'Referral Agency' : 'Educational Consultant'}</span>
                </div>
                <div class="referrer-card-body">
                    ${consultants.map(c => {
                        if (!c) return '';
                        // THE FIX: If name fields are empty, use the record's primary name
                        const cName = clean(c.firstName + ' ' + c.lastName) || clean(c.fullName) || p.name;
                        const cTitle = clean(c.role || c.title || 'Educational Consultant');
                        const cLoc = clean(c.city && c.state ? `${c.city}, ${c.state}` : (c.location || agency.location || ''));

                        return `
                        <div class="consultant-profile">
                            <div class="consultant-name">${escape(cName)}</div>
                            <div class="consultant-title">${escape(cTitle)}</div>
                            
                            ${cLoc ? `<div class="referrer-detail-row"><span class="detail-icon">📍</span><span class="detail-value">${escape(cLoc)}</span></div>` : ''}
                            ${c.email ? `<div class="referrer-detail-row"><span class="detail-icon">📧</span><span class="detail-value"><a href="mailto:${c.email}">${escape(c.email)}</a></span></div>` : ''}
                            ${c.phone ? `<div class="referrer-detail-row"><span class="detail-icon">📞</span><span class="detail-value"><a href="tel:${c.phone}">${escape(c.phone)}</a></span></div>` : ''}
                            ${(c.website || agency.website) ? `<div class="referrer-detail-row"><span class="detail-icon">🔗</span><span class="detail-value"><a href="${c.website || agency.website}" target="_blank">Website</a></span></div>` : ''}

                            ${(c.pastTTIJobs && c.pastTTIJobs.length) ? `
                                <details class="referrer-expandable">
                                    <summary>View Past TTI Jobs (${c.pastTTIJobs.length})</summary>
                                    <div class="expandable-content">
                                        <ul class="data-list">
                                            ${c.pastTTIJobs.map(j => `<li class="data-list-item"><span class="job-role">${escape(j.role)}</span><span class="job-employer">at ${escape(j.employer || j.organization)}</span></li>`).join('')}
                                        </ul>
                                    </div>
                                </details>` : ''}

                            ${(c.knownReferrals && c.knownReferrals.length && c.knownReferrals[0]) ? `
                                <details class="referrer-expandable">
                                    <summary>Known Facility Referrals</summary>
                                    <div class="expandable-content">
                                        <ul class="data-list">
                                            ${c.knownReferrals.map(r => r ? `<li class="data-list-item">${escape(r)}</li>` : '').join('')}
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

    // Event listeners
    searchInput.addEventListener('input', filterAndRender);
    statusFilter.addEventListener('change', filterAndRender);
});