// Location Index Display
// Styled to match TTI Program Index (Grouped by State/Country)

document.addEventListener('DOMContentLoaded', function() {
    const containerId = 'locations-container';
    const searchInput = document.getElementById('searchInput');
    const typeFilter = document.getElementById('typeFilter');
    const sortBy = document.getElementById('sortBy');
    const clearSearchBtn = document.getElementById('clearSearch');
    const alphabetFilter = document.getElementById('alphabet-filter');

    let allLocations = [];
    let filteredLocations = [];

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
        const config = window.locationConfig || {};
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
        allLocations = [];
        
        Object.values(projects).forEach(project => {
            let isLocation = false;
            let category = project.category || (project.data && project.data.category) || 'companies';
            
            if (category === 'locations') isLocation = true;
            if (!isLocation && US_STATES.has(project.name.toUpperCase())) isLocation = true;

            if (isLocation) {
                const pData = project.data || project;
                const facilities = pData.facilities || [];
                
                // Only showing facilities for now (referrers could be added if desired)
                if (facilities.length > 0) {
                    allLocations.push({
                        name: project.name,
                        type: US_STATES.has(project.name.toUpperCase()) ? 'state' : 'country',
                        facilities: facilities
                    });
                }
            }
        });

        // Sort A-Z
        allLocations.sort((a, b) => a.name.localeCompare(b.name));

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
        
        filteredLocations = allLocations.map(loc => {
            const matchesName = loc.name.toLowerCase().includes(query);
            const matchesType = !typeQuery || loc.type === typeQuery;
            const matchesAlpha = !currentAlphaFilter || loc.name.toUpperCase().startsWith(currentAlphaFilter);
            
            if (matchesName && matchesType && matchesAlpha) {
                // Return original location object (includes all facilities)
                // Could filter facilities inside if we wanted deep search
                return loc;
            }
            return null;
        }).filter(l => l !== null);

        // Sort
        const sortVal = sortBy.value;
        filteredLocations.sort((a, b) => {
            if (sortVal === 'name') return a.name.localeCompare(b.name);
            if (sortVal === 'count') return b.facilities.length - a.facilities.length;
            return 0;
        });

        renderList();
    }

    function renderList() {
        const container = document.getElementById(containerId);
        container.innerHTML = '';

        if (filteredLocations.length === 0) {
            container.innerHTML = '<div class="no-results" style="grid-column: 1/-1; text-align: center; padding: 20px;">No locations found.</div>';
            return;
        }

        const grid = document.createElement('div');
        grid.className = 'facilities-database';

        filteredLocations.forEach(loc => {
            const details = document.createElement('details');
            details.className = 'operator-section'; // Reuse TTI styling

            // -- Location Header --
            let headerHtml = `
                <summary class="operator-header">
                    <span class="operator-name" title="${loc.name}">${loc.name}</span>
                    <span class="operator-location">${loc.facilities.length} Facilities</span>
                </summary>
            `;

            // -- Location Content (Facilities) --
            let contentHtml = `<div class="operator-content-scrollable">`;
            
            loc.facilities.forEach(f => {
                const name = f.identification?.name || f.name || 'Unknown';
                const type = f.facilityDetails?.type || f.type || '';
                const years = f.operatingPeriod?.text || f.years_active || '';
                const location = f.location || f.city || '';
                const operator = f.identification?.currentOperator || f.operator || '';

                contentHtml += `
                    <div class="facility-card status-open">
                        <div class="facility-name">${name}</div>
                        
                        <div class="facility-summary">
                            ${type ? `<p class="facility-type" style="font-style:italic;">${type}</p>` : ''}
                            ${location ? `<p class="facility-location">📍 ${location}</p>` : ''}
                            ${years ? `<p class="facility-years">📅 ${years}</p>` : ''}
                        </div>

                        <div class="facility-extra-content">
                            ${operator ? `<div class="field-row full-width"><span class="field-label">Operator</span><span class="field-value">${operator}</span></div>` : ''}
                        </div>
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
    typeFilter.addEventListener('change', filterAndRender);
    sortBy.addEventListener('change', filterAndRender);
    clearSearchBtn.addEventListener('click', window.clearSearch);

    fetchData();
});

window.clearSearch = function() {
    const searchInput = document.getElementById('searchInput');
    const typeFilter = document.getElementById('typeFilter');
    if (searchInput) searchInput.value = '';
    if (typeFilter) typeFilter.value = '';
    if (searchInput) searchInput.dispatchEvent(new Event('input'));
};