
// Referrer Database Display
// Based on tti-program-index.js

document.addEventListener('DOMContentLoaded', function() {
    const containerId = 'referrers-container';
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter'); // Actually location filter
    const sortBy = document.getElementById('sortBy');
    const clearSearchBtn = document.getElementById('clearSearch');
    const alphabetFilter = document.getElementById('alphabet-filter');

    let allData = [];
    let filteredData = [];

    // --- Helper Functions ---
    const cleanText = value => {
        if (typeof value === 'string') return value.trim();
        if (typeof value === 'number') return String(value);
        return '';
    };

    const isValueEmpty = (value) => {
        if (value === null || value === undefined) return true;
        if (typeof value === 'string') {
            const lower = value.trim().toLowerCase();
            const placeholders = ['none', 'no', 'n/a', 'na', 'unknown', 'null', '-', '--', 'tbd', ''];
            return placeholders.includes(lower);
        }
        if (Array.isArray(value)) return value.length === 0;
        return false;
    };

    // --- Fetch Data ---
    function fetchData() {
        const config = window.referrerConfig || {};
        const url = config.jsonFileUrls ? config.jsonFileUrls[0] : '/api/get-master-data.php';

        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.projects) {
                    processData(data.projects);
                } else {
                    console.error('Invalid data format', data);
                    document.getElementById(containerId).innerHTML = '<p>Error loading data.</p>';
                }
            })
            .catch(err => {
                console.error('Fetch error:', err);
                document.getElementById(containerId).innerHTML = '<p>Error loading data.</p>';
            });
    }

    function processData(projects) {
        allData = [];
        
        // Filter for 'referrers' category
        Object.values(projects).forEach(project => {
            let category = 'companies';
            if (project.category) category = project.category;
            else if (project.data && project.data.category) category = project.data.category;
            
            if (category === 'referrers') {
                const pData = project.data || project;
                
                // Agency Info
                const agency = pData.referrerAgency || {};
                const agencyName = agency.name || project.name || 'Unknown Agency';
                
                // Consultants
                const consultants = pData.referrerConsultants || [];
                
                // If no consultants listed, create a placeholder one for the agency itself
                if (consultants.length === 0) {
                    allData.push({
                        name: agencyName,
                        agencyName: agencyName,
                        type: 'Agency',
                        location: agency.location || agency.state || '',
                        website: agency.website || '',
                        email: agency.email || '',
                        phone: agency.phone || '',
                        notes: 'No individual consultants listed.'
                    });
                } else {
                    consultants.forEach(c => {
                        const name = (c.firstName || '') + ' ' + (c.lastName || '');
                        allData.push({
                            name: name.trim() || 'Unknown Consultant',
                            agencyName: agencyName,
                            type: 'Consultant',
                            location: c.location || c.state || agency.location || '',
                            website: c.website || agency.website || '',
                            email: c.email || agency.email || '',
                            phone: c.phone || agency.phone || '',
                            notes: c.notes || ''
                        });
                    });
                }
            }
        });

        // Populate Location Filter
        const locations = new Set();
        allData.forEach(item => {
            if (item.location) locations.add(item.location);
        });
        
        // Simple state extraction for filter
        const states = new Set();
        locations.forEach(loc => {
            // Extract state abbreviation or name if possible, else use full string
            // This is a simple heuristic
            const parts = loc.split(',');
            if (parts.length > 1) states.add(parts[parts.length-1].trim());
            else states.add(loc);
        });

        const sortedStates = Array.from(states).sort();
        statusFilter.innerHTML = '<option value="">All Locations</option>';
        sortedStates.forEach(state => {
            if(!state) return;
            const option = document.createElement('option');
            option.value = state;
            option.textContent = state;
            statusFilter.appendChild(option);
        });

        // Populate Alphabet Filter
        renderAlphabetFilter();

        // Initial Render
        filterAndRender();
    }

    function renderAlphabetFilter() {
        if (!alphabetFilter) return;
        const alphabet = '#ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
        let html = '';
        
        // Active letter tracking could go here
        
        alphabet.forEach(char => {
            html += `<button class="alpha-btn" onclick="filterByChar('${char}')">${char}</button>`;
        });
        html += `<button class="alpha-btn" onclick="filterByChar('')">All</button>`;
        alphabetFilter.innerHTML = html;
        
        // Expose filter function globally so inline onclick works
        window.filterByChar = (char) => {
            // Update UI state
            document.querySelectorAll('.alpha-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            // Perform filter
            if (char === '' || char === 'All') {
                currentAlphaFilter = '';
            } else {
                currentAlphaFilter = char;
            }
            filterAndRender();
        };
    }

    let currentAlphaFilter = '';

    function filterAndRender() {
        const query = searchInput.value.toLowerCase();
        const locationQuery = statusFilter.value.toLowerCase();
        
        filteredData = allData.filter(item => {
            const matchesSearch = item.name.toLowerCase().includes(query) || 
                                  item.agencyName.toLowerCase().includes(query);
            
            const matchesLocation = !locationQuery || (item.location && item.location.toLowerCase().includes(locationQuery));
            
            let matchesAlpha = true;
            if (currentAlphaFilter) {
                if (currentAlphaFilter === '#') {
                    matchesAlpha = /^[0-9]/.test(item.name); // or agencyName
                } else {
                    // Check either consultant name or agency name
                    matchesAlpha = item.name.toUpperCase().startsWith(currentAlphaFilter) || 
                                   item.agencyName.toUpperCase().startsWith(currentAlphaFilter);
                }
            }

            return matchesSearch && matchesLocation && matchesAlpha;
        });

        // Sort
        const sortVal = sortBy.value;
        filteredData.sort((a, b) => {
            if (sortVal === 'name') {
                // Sort by last name if consultant? For now, simple name sort
                return a.name.localeCompare(b.name);
            }
            return 0;
        });

        renderList();
    }

    function renderList() {
        const container = document.getElementById(containerId);
        container.innerHTML = '';

        if (filteredData.length === 0) {
            container.innerHTML = '<div class="no-results">No consultants found matching your criteria.</div>';
            return;
        }

        const grid = document.createElement('div');
        grid.className = 'facilities-grid'; // Reuse CSS class for grid layout

        filteredData.forEach(item => {
            const card = document.createElement('div');
            card.className = 'facility-card referrer-card';
            
            let html = `
                <div class="card-header">
                    <h3>${item.name}</h3>
                    <div class="agency-name">${item.agencyName !== item.name ? item.agencyName : ''}</div>
                </div>
                <div class="card-body">
            `;

            if (item.location) {
                html += `<p class="location-row"><span class="icon">📍</span> ${item.location}</p>`;
            }
            if (item.phone) {
                html += `<p class="contact-row"><span class="icon">📞</span> <a href="tel:${item.phone}">${item.phone}</a></p>`;
            }
            if (item.email) {
                html += `<p class="contact-row"><span class="icon">✉️</span> <a href="mailto:${item.email}">${item.email}</a></p>`;
            }
            if (item.website) {
                let displayUrl = item.website.replace(/^https?:\/\//, '').replace(/\/$/, '');
                html += `<p class="website-row"><span class="icon">🌐</span> <a href="${item.website}" target="_blank">${displayUrl}</a></p>`;
            }
            
            // Add notes if present (truncated)
            if (item.notes && item.notes.length > 0) {
                 html += `<div class="card-notes text-sm text-gray-500 mt-2">${item.notes}</div>`;
            }

            html += `</div>`; // End card-body
            card.innerHTML = html;
            grid.appendChild(card);
        });

        container.appendChild(grid);
    }

    // --- Event Listeners ---
    searchInput.addEventListener('input', filterAndRender);
    statusFilter.addEventListener('change', filterAndRender);
    sortBy.addEventListener('change', filterAndRender);
    clearSearchBtn.addEventListener('click', () => {
        searchInput.value = '';
        statusFilter.value = '';
        currentAlphaFilter = '';
        document.querySelectorAll('.alpha-btn').forEach(btn => btn.classList.remove('active'));
        filterAndRender();
    });

    // Initialize
    fetchData();
});

// Global clear function for the button
window.clearSearch = function() {
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    if (searchInput) searchInput.value = '';
    if (statusFilter) statusFilter.value = '';
    // Trigger event manually to update
    if (searchInput) searchInput.dispatchEvent(new Event('input'));
};
