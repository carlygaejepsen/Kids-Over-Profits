// Referrer Database Display
// Styled to match TTI Program Index (Groups by Agency)

document.addEventListener('DOMContentLoaded', function() {
    const containerId = 'referrers-container';
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter'); // Location filter
    const sortBy = document.getElementById('sortBy');
    const clearSearchBtn = document.getElementById('clearSearch');
    const alphabetFilter = document.getElementById('alphabet-filter');

    let allGroups = []; // Array of { agencyName, consultants: [] }
    let filteredGroups = [];

    // --- Helper Functions ---
    const cleanText = value => {
        if (typeof value === 'string') return value.trim();
        if (typeof value === 'number') return String(value);
        return '';
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
                    document.getElementById(containerId).innerHTML = '<p>Error loading data.</p>';
                }
            })
            .catch(err => {
                console.error('Fetch error:', err);
                document.getElementById(containerId).innerHTML = '<p>Error loading data.</p>';
            });
    }

    function processData(projects) {
        const agencyMap = new Map();

        Object.values(projects).forEach(project => {
            let category = project.category || (project.data && project.data.category) || 'companies';
            
            if (category === 'referrers') {
                const pData = project.data || {};
                
                // Robustly find referrer data: check root first, then data object
                const agency = project.referrerAgency || pData.referrerAgency || {};
                let consultants = project.referrerConsultants || pData.referrerConsultants || [];
                
                // Fallback to referrerIndividual if array is empty
                if (!consultants.length) {
                    const ind = project.referrerIndividual || pData.referrerIndividual;
                    if (ind) consultants = [ind];
                }
                
                // Check if independent
                const isIndependent = project.isIndependentConsultant === true || 
                                      pData.isIndependentConsultant === true || 
                                      agency.isIndependent === true ||
                                      project.referrerType === 'individual' ||
                                      pData.referrerType === 'individual' ||
                                      (consultants.length === 1 && !agency.name);

                // Determine agency name
                let agencyName;
                if (isIndependent) {
                    agencyName = 'Independent Consultants';
                } else {
                    // Use project name as fallback for agency name
                    agencyName = cleanText(agency.name || project.name || 'Unknown Agency');
                }
                
                if (!agencyMap.has(agencyName)) {
                    agencyMap.set(agencyName, {
                        name: agencyName,
                        location: agency.location || agency.state || '', // Agency location
                        consultants: []
                    });
                }

                const group = agencyMap.get(agencyName);

                // If no individual consultants, list the agency itself as a "consultant" card
                if (consultants.length === 0) {
                    group.consultants.push({
                        name: agencyName,
                        type: 'Agency',
                        location: agency.location || agency.state || '',
                        website: agency.website || '',
                        email: agency.email || '',
                        phone: agency.phone || '',
                        notes: 'Agency listing'
                    });
                } else {
                    consultants.forEach(c => {
                        let cName;
                        if (isIndependent) {
                            // For an individual, just make the name match the project name
                            cName = project.name;
                        } else {
                            cName = (c.firstName || '') + ' ' + (c.lastName || '');
                            if (!cName.trim()) cName = c.fullName || '';
                            if (!cName.trim() && consultants.length === 1) cName = project.name;
                        }
                        
                        group.consultants.push({
                            name: (cName || 'Unknown Consultant').trim(),
                            title: c.title || 'Consultant',
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

        allGroups = Array.from(agencyMap.values());
        
        // Sort agencies A-Z
        allGroups.sort((a, b) => a.name.localeCompare(b.name));

        // Populate Location Filter (based on Consultants)
        const locations = new Set();
        allGroups.forEach(g => {
            g.consultants.forEach(c => {
                if (c.location) {
                    const parts = c.location.split(',');
                    const state = parts[parts.length-1].trim();
                    if(state) locations.add(state);
                }
            });
        });
        
        const sortedLocs = Array.from(locations).sort();
        statusFilter.innerHTML = '<option value="">All Locations</option>';
        sortedLocs.forEach(loc => {
            const option = document.createElement('option');
            option.value = loc;
            option.textContent = loc;
            statusFilter.appendChild(option);
        });

        renderAlphabetFilter();
        filterAndRender();
    }

    function renderAlphabetFilter() {
        if (!alphabetFilter) return;
        const alphabet = '#ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
        let html = '';
        alphabet.forEach(char => {
            html += `<button class="alpha-btn" onclick="filterByChar('${char}')">${char}</button>`;
        });
        html += `<button class="alpha-btn" onclick="filterByChar('')">All</button>`;
        alphabetFilter.innerHTML = html;
        
        window.filterByChar = (char) => {
            document.querySelectorAll('.alpha-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            currentAlphaFilter = char;
            filterAndRender();
        };
    }

    let currentAlphaFilter = '';

    function filterAndRender() {
        const query = searchInput.value.toLowerCase();
        const locationQuery = statusFilter.value.toLowerCase();

        // Filter Groups
        filteredGroups = allGroups.map(group => {
            // Check if Agency matches
            const agencyMatch = group.name.toLowerCase().includes(query);
            
            // Filter consultants within the group
            const matchingConsultants = group.consultants.filter(c => {
                const nameMatch = c.name.toLowerCase().includes(query);
                const locMatch = !locationQuery || (c.location && c.location.toLowerCase().includes(locationQuery));
                return (agencyMatch || nameMatch) && locMatch;
            });

            if (matchingConsultants.length > 0) {
                // Return a new group object with only matching consultants
                return { ...group, consultants: matchingConsultants };
            }
            return null;
        }).filter(g => g !== null);

        // Apply Alphabet Filter (on Agency Name)
        if (currentAlphaFilter) {
            filteredGroups = filteredGroups.filter(g => {
                if (currentAlphaFilter === '#') return /^[0-9]/.test(g.name);
                return g.name.toUpperCase().startsWith(currentAlphaFilter);
            });
        }

        renderList();
    }

    function renderList() {
        const container = document.getElementById(containerId);
        container.innerHTML = '';

        if (filteredGroups.length === 0) {
            container.innerHTML = '<div class="no-results" style="grid-column: 1/-1; text-align: center; padding: 20px;">No agencies or consultants found.</div>';
            return;
        }

        // Use the same grid class as TTI index
        const grid = document.createElement('div');
        grid.className = 'facilities-database'; 

        filteredGroups.forEach(group => {
            const details = document.createElement('details');
            details.className = 'operator-section'; // Reuse TTI class for styling

            // -- Agency Header (Summary) --
            let headerHtml = `
                <summary class="operator-header">
                    <span class="operator-name" title="${group.name}">${group.name}</span>
                    <span class="operator-location">${group.location || group.consultants.length + ' Consultants'}</span>
                </summary>
            `;

            // -- Agency Content (Consultant Cards) --
            let contentHtml = `<div class="operator-content-scrollable">`;
            
            group.consultants.forEach(c => {
                contentHtml += `
                    <div class="facility-card status-open"> <!-- Reuse facility-card -->
                        <div class="facility-name">${c.name}</div>
                        <div class="facility-header-subtext">${c.title}</div>
                        
                        <div class="facility-summary">
                            ${c.location ? `<p class="facility-location">📍 ${c.location}</p>` : ''}
                        </div>

                        <div class="facility-extra-content">
                            ${c.phone ? `<div class="field-row"><span class="field-label">Phone</span><span class="field-value"><a href="tel:${c.phone}">${c.phone}</a></span></div>` : ''}
                            ${c.email ? `<div class="field-row"><span class="field-label">Email</span><span class="field-value"><a href="mailto:${c.email}">${c.email}</a></span></div>` : ''}
                            ${c.website ? `<div class="field-row full-width"><span class="field-label">Web</span><span class="field-value"><a href="${c.website}" target="_blank">${c.website.replace(/^https?:\/\//,'')}</a></span></div>` : ''}
                        </div>
                        
                        ${c.notes ? `<div class="facility-expanded-info"><summary>Notes</summary><p style="padding:10px; font-size:0.9em;">${c.notes}</p></div>` : ''}
                    </div>
                `;
            });
            contentHtml += `</div>`;

            details.innerHTML = headerHtml + contentHtml;
            grid.appendChild(details);
        });

        container.appendChild(grid);
    }

    // Events
    searchInput.addEventListener('input', filterAndRender);
    statusFilter.addEventListener('change', filterAndRender);
    sortBy.addEventListener('change', () => {
        // Re-sort logic if needed
        const sort = sortBy.value;
        if (sort === 'name') {
            allGroups.sort((a, b) => a.name.localeCompare(b.name));
        }
        filterAndRender();
    });
    clearSearchBtn.addEventListener('click', window.clearSearch);

    fetchData();
});