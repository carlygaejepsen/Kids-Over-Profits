/**
 * REFERRER DIRECTORY JS - Fresh Modern Renderer
 * No recycled TTI components. Optimized for Referrer/Consultant data.
 */

document.addEventListener('DOMContentLoaded', function() {
    const containerId = 'referrers-container';
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const alphabetFilter = document.getElementById('alphabet-filter');

    let allEntries = []; 
    let filteredEntries = [];

    const cleanText = v => (typeof v === 'string' ? v.trim() : (v ? String(v) : ''));
    const escapeHtml = v => cleanText(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c));

    // Global clear search function
    window.clearSearch = () => {
        searchInput.value = '';
        statusFilter.value = '';
        window.currentAlphaFilter = '';
        document.querySelectorAll('.alpha-btn').forEach(b => b.classList.remove('active'));
        filterAndRender();
    };

    function fetchData() {
        const baseUrl = '/wp-content/themes/child/api/get-master-data.php';
        fetch(baseUrl + '?t=' + Date.now())
            .then(res => res.json())
            .then(data => {
                if (data.success && data.projects) {
                    processData(data.projects);
                } else {
                    document.getElementById(containerId).innerHTML = '<p class="error">Error loading data.</p>';
                }
            })
            .catch(err => {
                console.error('Fetch error:', err);
                document.getElementById(containerId).innerHTML = '<p class="error">Connection error.</p>';
            });
    }

    function processData(projects) {
        // Filter specifically for referrers source table
        allEntries = Object.values(projects).filter(p => p._sourceTable === 'referrers');

        // Build location list for filter
        const locs = new Set();
        allEntries.forEach(p => {
            const data = p.data || {};
            const consultants = data.referrerConsultants || (data.referrerIndividual ? [data.referrerIndividual] : []);
            consultants.forEach(c => {
                if (!c) return;
                const state = c.state || (c.location && c.location.includes(',') ? c.location.split(',').pop().trim() : null);
                if (state) locs.add(state.toUpperCase());
            });
        });
        
        statusFilter.innerHTML = '<option value="">All Locations</option>' + 
            Array.from(locs).sort().map(l => `<option value="${l}">${l}</option>`).join('');

        renderAlphabetFilter();
        filterAndRender();
    }

    function renderAlphabetFilter() {
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
        const locQuery = statusFilter.value.toLowerCase();
        const alpha = window.currentAlphaFilter || '';

        filteredEntries = allEntries.filter(p => {
            const name = (p.name || '').toLowerCase();
            // Alphabet check
            if (alpha && alpha !== '#' && !name.startsWith(alpha.toLowerCase())) return false;
            if (alpha === '#' && !/^[0-9]/.test(name)) return false;
            
            // Search text check (check name and full record string)
            const matchesQuery = name.includes(query) || JSON.stringify(p).toLowerCase().includes(query);
            
            // Location check
            const matchesLoc = !locQuery || JSON.stringify(p).toLowerCase().includes(locQuery);
            
            return matchesQuery && matchesLoc;
        });

        renderList();
    }

    function renderList() {
        const container = document.getElementById(containerId);
        container.innerHTML = '';

        if (!filteredEntries.length) {
            container.innerHTML = '<div style="text-align:center; padding: 40px; grid-column: 1/-1;">No consultants found matching your criteria.</div>';
            return;
        }

        const grid = document.createElement('div');
        grid.className = 'referrer-grid';

        filteredEntries.forEach(p => {
            const pData = p.data || {};
            const agency = pData.referrerAgency || {};
            
            // Identify consultants
            let consultants = pData.referrerConsultants || [];
            if (!consultants.length && pData.referrerIndividual) consultants = [pData.referrerIndividual];
            if (!consultants.length) consultants = [{ firstName: '', lastName: '', fullName: p.name }];

            const card = document.createElement('div');
            card.className = 'referrer-card';
            
            // Card Header
            let headerHtml = `
                <div class="referrer-card-header">
                    <h3 class="referrer-main-name">${escapeHtml(p.name)}</h3>
                    <span class="referrer-sub-label">${agency.name ? 'Agency' : 'Independent Consultant'}</span>
                </div>
            `;

            // Card Body
            let bodyHtml = `<div class="referrer-card-body">`;
            
            consultants.forEach(c => {
                if (!c) return;
                const cName = cleanText(c.firstName + ' ' + c.lastName) || cleanText(c.fullName) || p.name;
                const cTitle = cleanText(c.role || c.title || (agency.name ? 'Consultant' : 'Educational Consultant'));
                const cLoc = cleanText(c.city && c.state ? `${c.city}, ${c.state}` : (c.location || agency.location || ''));

                bodyHtml += `
                    <div class="consultant-profile">
                        <div class="consultant-name">${escapeHtml(cName)}</div>
                        <div class="consultant-title">${escapeHtml(cTitle)}</div>
                        
                        ${cLoc ? `
                            <div class="referrer-detail-row">
                                <span class="detail-icon">📍</span>
                                <span class="detail-value">${escapeHtml(cLoc)}</span>
                            </div>` : ''}

                        ${c.email ? `
                            <div class="referrer-detail-row">
                                <span class="detail-icon">📧</span>
                                <span class="detail-value"><a href="mailto:${c.email}">${escapeHtml(c.email)}</a></span>
                            </div>` : ''}

                        ${c.phone ? `
                            <div class="referrer-detail-row">
                                <span class="detail-icon">📞</span>
                                <span class="detail-value"><a href="tel:${c.phone}">${escapeHtml(c.phone}</a></span>
                            </div>` : ''}

                        ${c.website || agency.website ? `
                            <div class="referrer-detail-row">
                                <span class="detail-icon">🔗</span>
                                <span class="detail-value"><a href="${c.website || agency.website}" target="_blank">Website</a></span>
                            </div>` : ''}

                        <!-- Past Jobs Expandable -->
                        ${(c.pastTTIJobs && c.pastTTIJobs.length) ? `
                            <details class="referrer-expandable">
                                <summary>View Past TTI Jobs (${c.pastTTIJobs.length})</summary>
                                <div class="expandable-content">
                                    <ul class="data-list">
                                        ${c.pastTTIJobs.map(j => `
                                            <li class="data-list-item">
                                                <span class="job-role">${escapeHtml(j.role)}</span>
                                                <span class="job-employer">at ${escapeHtml(j.employer || j.organization)}</span>
                                            </li>
                                        `).join('')}
                                    </ul>
                                </div>
                            </details>` : ''}

                        <!-- Known Referrals Expandable -->
                        ${(c.knownReferrals && c.knownReferrals.length && c.knownReferrals[0]) ? `
                            <details class="referrer-expandable">
                                <summary>Known Facility Referrals</summary>
                                <div class="expandable-content">
                                    <ul class="data-list">
                                        ${c.knownReferrals.map(r => r ? `<li class="data-list-item">${escapeHtml(r)}</li>` : '').join('')}
                                    </ul>
                                </div>
                            </details>` : ''}
                            
                        ${c.notes ? `<div style="margin-top:10px; font-size:0.85em; color:#666;"><strong>Notes:</strong> ${escapeHtml(c.notes)}</div>` : ''}
                    </div>
                `;
            });

            bodyHtml += `</div>`;
            card.innerHTML = headerHtml + bodyHtml;
            grid.appendChild(card);
        });

        container.appendChild(grid);
    }

    fetchData();
});