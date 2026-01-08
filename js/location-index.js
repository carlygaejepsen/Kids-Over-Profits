
// Location Index Display
// Displays summary of facilities per State/Country

document.addEventListener('DOMContentLoaded', function() {
    const containerId = 'locations-container';
    const searchInput = document.getElementById('searchInput');
    const typeFilter = document.getElementById('typeFilter');
    const sortBy = document.getElementById('sortBy');
    const clearSearchBtn = document.getElementById('clearSearch');
    const alphabetFilter = document.getElementById('alphabet-filter');

    let allData = [];
    let filteredData = [];

    // Helper: US State List for categorization
    const US_STATES = new Set([
        'ALABAMA', 'ALASKA', 'ARIZONA', 'ARKANSAS', 'CALIFORNIA', 'COLORADO', 'CONNECTICUT',
        'DELAWARE', 'FLORIDA', 'GEORGIA', 'HAWAII', 'IDAHO', 'ILLINOIS', 'INDIANA', 'IOWA',
        'KANSAS', 'KENTUCKY', 'LOUISIANA', 'MAINE', 'MARYLAND', 'MASSACHUSETTS', 'MICHIGAN',
        'MINNESOTA', 'MISSISSIPPI', 'MISSOURI', 'MONTANA', 'NEBRASKA', 'NEVADA', 'NEW HAMPSHIRE',
        'NEW JERSEY', 'NEW MEXICO', 'NEW YORK', 'NORTH CAROLINA', 'NORTH DAKOTA', 'OHIO',
        'OKLAHOMA', 'OREGON', 'PENNSYLVANIA', 'RHODE ISLAND', 'SOUTH CAROLINA', 'SOUTH DAKOTA',
        'TENNESSEE', 'TEXAS', 'UTAH', 'VERMONT', 'VIRGINIA', 'WASHINGTON', 'WEST VIRGINIA',
        'WISCONSIN', 'WYOMING'
    ]);

    // --- Fetch Data ---
    function fetchData() {
        const config = window.locationConfig || {};
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
        
        Object.values(projects).forEach(project => {
            // Determine if this is a location project
            let isLocation = false;
            let category = 'companies';
            
            if (project.category) category = project.category;
            else if (project.data && project.data.category) category = project.data.category;
            
            if (category === 'locations') isLocation = true;
            
            // Fallback: Check if name is a known state/country
            if (!isLocation && US_STATES.has(project.name.toUpperCase())) isLocation = true;

            if (isLocation) {
                const pData = project.data || project;
                const facilities = pData.facilities || [];
                const referrers = pData.referrerConsultants || [];
                
                allData.push({
                    name: project.name,
                    type: US_STATES.has(project.name.toUpperCase()) ? 'state' : 'country',
                    facilityCount: facilities.length,
                    referrerCount: referrers.length,
                    totalCount: facilities.length + referrers.length,
                    // Store explicit lists for preview if needed
                    facilities: facilities
                });
            }
        });

        renderAlphabetFilter();
        filterAndRender();
    }

    function renderAlphabetFilter() {
        if (!alphabetFilter) return;
        const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
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
        const typeQuery = typeFilter.value;
        
        filteredData = allData.filter(item => {
            const matchesSearch = item.name.toLowerCase().includes(query);
            const matchesType = !typeQuery || item.type === typeQuery;
            const matchesAlpha = !currentAlphaFilter || item.name.toUpperCase().startsWith(currentAlphaFilter);
            
            // Only show locations with actual data? Optional.
            // const hasData = item.totalCount > 0; 
            
            return matchesSearch && matchesType && matchesAlpha;
        });

        // Sort
        const sortVal = sortBy.value;
        filteredData.sort((a, b) => {
            if (sortVal === 'name') return a.name.localeCompare(b.name);
            if (sortVal === 'count') return b.totalCount - a.totalCount;
            return 0;
        });

        renderList();
    }

    function renderList() {
        const container = document.getElementById(containerId);
        container.innerHTML = '';

        if (filteredData.length === 0) {
            container.innerHTML = '<div class="no-results">No locations found matching your criteria.</div>';
            return;
        }

        const grid = document.createElement('div');
        grid.className = 'location-grid';

        filteredData.forEach(item => {
            const card = document.createElement('div');
            card.className = `location-card type-${item.type}`;
            
            // Generate facility preview (top 3)
            let preview = '';
            if (item.facilities.length > 0) {
                const top3 = item.facilities.slice(0, 3);
                preview = '<ul class="facility-preview">';
                top3.forEach(f => {
                    const name = f.identification?.name || f.name || 'Unknown';
                    preview += `<li>${name}</li>`;
                });
                if (item.facilities.length > 3) {
                    preview += `<li class="more-count">+${item.facilities.length - 3} more</li>`;
                }
                preview += '</ul>';
            } else {
                preview = '<p class="empty-state">No facilities listed yet.</p>';
            }

            card.innerHTML = `
                <div class="card-header">
                    <h3>${item.name}</h3>
                    <span class="badge ${item.type}">${item.type === 'state' ? 'State' : 'Country'}</span>
                </div>
                <div class="card-stats">
                    <div class="stat">
                        <span class="count">${item.facilityCount}</span>
                        <span class="label">Facilities</span>
                    </div>
                    <div class="stat">
                        <span class="count">${item.referrerCount}</span>
                        <span class="label">Consultants</span>
                    </div>
                </div>
                <div class="card-body">
                    ${preview}
                </div>
                <div class="card-footer">
                    <a href="/active-programs/active-programs-${item.name.toLowerCase().replace(/\s+/g, '-')}" class="view-btn">View Full Report</a>
                </div>
            `;
            grid.appendChild(card);
        });

        container.appendChild(grid);
    }

    // --- Event Listeners ---
    searchInput.addEventListener('input', filterAndRender);
    typeFilter.addEventListener('change', filterAndRender);
    sortBy.addEventListener('change', filterAndRender);
    clearSearchBtn.addEventListener('click', () => {
        searchInput.value = '';
        typeFilter.value = '';
        currentAlphaFilter = '';
        document.querySelectorAll('.alpha-btn').forEach(btn => btn.classList.remove('active'));
        filterAndRender();
    });

    fetchData();
});

window.clearSearch = function() {
    const searchInput = document.getElementById('searchInput');
    const typeFilter = document.getElementById('typeFilter');
    if (searchInput) searchInput.value = '';
    if (typeFilter) typeFilter.value = '';
    if (searchInput) searchInput.dispatchEvent(new Event('input'));
};
