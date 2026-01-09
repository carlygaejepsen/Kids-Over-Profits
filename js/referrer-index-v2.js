// Referrer Database Display - Simple Table-to-Tile Renderer
document.addEventListener('DOMContentLoaded', function() {
    const containerId = 'referrers-container';
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const alphabetFilter = document.getElementById('alphabet-filter');

    let allEntries = []; 
    let filteredEntries = [];

    const cleanText = v => (typeof v === 'string' ? v.trim() : (v ? String(v) : ''));
    const escapeHtml = v => cleanText(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c));

    function fetchData() {
        const baseUrl = '/wp-content/themes/child/api/get-master-data.php';
        fetch(baseUrl + '?t=' + Date.now())
            .then(res => res.json())
            .then(data => {
                if (data.success && data.projects) {
                    processData(data.projects);
                } else {
                    document.getElementById(containerId).innerHTML = '<p>Error loading data.</p>';
                }
            })
            .catch(err => {
                console.error('Fetch error:', err);
                document.getElementById(containerId).innerHTML = '<p>Connection error.</p>';
            });
    }

    function processData(projects) {
        allEntries = Object.values(projects).filter(p => p._sourceTable === 'referrers');

        // Populate Location Filter
        const locs = new Set();
        allEntries.forEach(p => {
            const data = p.data || {};
            const consultants = data.referrerConsultants || (data.referrerIndividual ? [data.referrerIndividual] : []);
            consultants.forEach(c => {
                if (c && c.state) locs.add(c.state.trim());
                else if (c && c.location && c.location.includes(',')) locs.add(c.location.split(',').pop().trim());
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
            if (alpha && alpha !== '#' && !name.startsWith(alpha.toLowerCase())) return false;
            if (alpha === '#' && !/^[0-9]/.test(name)) return false;
            
            const matchesQuery = name.includes(query);
            const matchesLoc = !locQuery || JSON.stringify(p).toLowerCase().includes(locQuery);
            
            return matchesQuery && matchesLoc;
        });

        renderList();
    }

    function renderList() {
        const container = document.getElementById(containerId);
        container.innerHTML = '';

        if (!filteredEntries.length) {
            container.innerHTML = '<div class="no-results">No referrers found.</div>';
            return;
        }

        const grid = document.createElement('div');
        grid.className = 'facilities-database';

        filteredEntries.forEach(p => {
            const pData = p.data || {};
            // Gather all consultants listed in this record
            let consultants = pData.referrerConsultants || [];
            if (!consultants.length && pData.referrerIndividual) consultants = [pData.referrerIndividual];
            if (!consultants.length) consultants = [{ fullName: p.name }]; // Final fallback

            const details = document.createElement('details');
            details.className = 'operator-section';
            
            // Tile Header
            details.innerHTML = `
                <summary class="operator-header">
                    <span class="operator-name">${escapeHtml(p.name)}</span>
                    <span class="operator-location">${consultants.length} Listing(s)</span>
                </summary>
                <div class="operator-content-scrollable">
                    ${consultants.map(c => {
                        const cName = cleanText(c.firstName + ' ' + c.lastName) || cleanText(c.fullName) || escapeHtml(p.name);
                        return `
                        <div class="facility-card status-open">
                            <div class="facility-name">${escapeHtml(cName)}</div>
                            <div class="facility-header-subtext">${escapeHtml(c.role || c.title || 'Consultant')}</div>
                            <div class="facility-summary">
                                ${c.location ? `<p>📍 ${escapeHtml(c.location)}</p>` : ''}
                                ${c.city || c.state ? `<p>📍 ${escapeHtml(cleanText(c.city + ', ' + c.state))}</p>` : ''}
                            </div>
                            <div class="facility-extra-content">
                                ${c.phone ? `<div><strong>Phone:</strong> ${escapeHtml(c.phone)}</div>` : ''}
                                ${c.email ? `<div><strong>Email:</strong> ${escapeHtml(c.email)}</div>` : ''}
                                ${c.website ? `<div class="full-width"><strong>Web:</strong> <a href="${c.website}" target="_blank">${c.website}</a></div>` : ''}
                            </div>
                            ${(c.pastTTIJobs && c.pastTTIJobs.length) ? `
                                <details class="facility-expanded-info">
                                    <summary>+ Past TTI Jobs</summary>
                                    <div class="facility-extra-content">
                                        ${c.pastTTIJobs.map(j => `<div class="full-width">• ${escapeHtml(j.role)} at ${escapeHtml(j.employer || j.organization)}</div>`).join('')}
                                    </div>
                                </details>
                            ` : ''}
                        </div>`;
                    }).join('')}
                </div>
            `;
            grid.appendChild(details);
        });
        container.appendChild(grid);
    }

    fetchData();
});