// Facilities Database Display - Drop-in JavaScript
// Usage: Include this file and call displayFacilities(jsonData, containerId)

(function() { // Wrap in IIFE to prevent global namespace pollution

    const CONFIG = {
        emptyPlaceholders: [
            'none', 'no', 'n/a', 'na', 'n.a.', 'n.a', 
            'unknown', 'null', 'undefined', 'false', 'empty', 
            '-', '--', '—', '–', 'tbd', 'tba', '[]', '{}', 
            'not specified', 'not available', 'not applicable',
            'no data', 'no info', 'no information', 'nil'
        ]
    };

    // --- Helper Functions ---

    const cleanText = (text) => {
        if (typeof text !== 'string') return '';
        // Remove single and double quotes wrapping the string if present, but keep apostrophes
        // This is a simple trim for now.
        return text.trim();
    };

    const escapeHtml = (unsafe) => {
        if (typeof unsafe !== 'string') return '';
        return unsafe
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    };

    const escapeAttribute = (unsafe) => {
        if (typeof unsafe !== 'string') return '';
        return unsafe.replace(/"/g, "&quot;");
    };

    const toArray = (val) => {
        if (val === null || val === undefined) return [];
        if (Array.isArray(val)) return val;
        return [val];
    };

    // Strict check for empty values
    const isValueEmpty = (value) => {
        if (value === null || value === undefined) return true;
        if (typeof value === 'boolean') return !value; // Hide false, show true
        if (typeof value === 'number') return false;   // Numbers (even 0) are valid
        
        if (typeof value === 'string') {
            const lower = value.trim().toLowerCase();
            if (!lower) return true;
            
            // Check against placeholder list
            // We check for exact matches or matches with trailing punctuation stripped
            const stripped = lower.replace(/[.,;:\-–—!]+$/, '');
            
            if (CONFIG.emptyPlaceholders.includes(stripped) || CONFIG.emptyPlaceholders.includes(lower)) {
                return true;
            }
            return false;
        }
        
        if (Array.isArray(value)) {
            // Array is empty if it has no items or all items are empty
            return value.length === 0 || value.every(item => isValueEmpty(item));
        }
        
        if (typeof value === 'object') {
            // Object is empty if it has no keys or all values are empty
            const keys = Object.keys(value);
            if (keys.length === 0) return true;
            return keys.every(k => isValueEmpty(value[k]));
        }
        
        return true;
    };

    const formatLabel = (key) => {
        if (!key) return '';
        // Extract the last part of a dot-notation key (e.g., 'facilityDetails.ageRange' -> 'ageRange')
        const label = key.split('.').pop();
        // Insert space before capital letters and capitalize first letter
        return label
            .replace(/([A-Z])/g, ' $1')
            .replace(/^./, str => str.toUpperCase())
            .trim();
    };

    // --- Rendering Functions ---

    const renderResources = (resources) => {
        if (!resources || typeof resources !== 'object') return '';
        
        const list = [];
        const map = {
            'hasNews': 'News', 
            'hasPressReleases': 'Press Releases', 
            'hasInspections': 'Inspections',
            'hasStateReports': 'State Reports', 
            'hasRegulatoryFilings': 'Regulatory Filings',
            'hasLawsuits': 'Lawsuits', 
            'hasSettlements': 'Settlements',
            'hasViolations': 'Violations', 
            'hasResearch': 'Research', 
            'hasFinancial': 'Financial', 
            'hasNATSAP': 'NATSAP Profile',
            'hasWebsite': 'Website Screenshots', 
            'hasOther': 'Other'
        };
        
        Object.keys(map).forEach(k => { 
            if (resources[k] === true) list.push(map[k]); 
        });
        
        if (Array.isArray(resources.customResources)) {
            resources.customResources.forEach(res => {
                if (!isValueEmpty(res)) list.push(cleanText(res));
            });
        }

        if (list.length === 0) return '';
        
        const itemsHtml = list.map(r => `<span class="list-item">${escapeHtml(r)}</span>`).join(' ');
        return `
            <div class="field-row full-width-grid">
                <span class="field-label">Resources Available</span>
                <span class="field-value">${itemsHtml}</span>
            </div>
        `;
    };

    // Recursively render object fields
    const renderFields = (obj, skipKeys = [], prefix = '', depth = 0) => {
        if (!obj || typeof obj !== 'object' || depth > 3) return '';
        
        let html = '';
        
        Object.keys(obj).forEach(key => {
            const fullKey = prefix ? `${prefix}.${key}` : key;
            const value = obj[key];

            // Skip strict blocks
            if (skipKeys.includes(key) || skipKeys.includes(fullKey)) return;
            
            // Skip empty values
            if (isValueEmpty(value)) return;

            // Handle nested objects (recurse)
            if (typeof value === 'object' && !Array.isArray(value)) {
                html += renderFields(value, skipKeys, fullKey, depth + 1);
                return;
            }

            // Render value
            let renderedValue = '';
            let isFullWidth = false;

            if (Array.isArray(value)) {
                // Filter array for empty items
                const validItems = value.filter(i => !isValueEmpty(i));
                if (validItems.length === 0) return;

                isFullWidth = true; // Lists usually take full width

                // Check if it's a list of URLs
                const isUrlList = validItems.some(i => typeof i === 'string' && (i.startsWith('http://') || i.startsWith('https://')));

                if (isUrlList) {
                    renderedValue = validItems.map(url => {
                        const safeUrl = escapeAttribute(url);
                        // Shorten URL for display
                        let display = url.replace(/^https?:\/\/(www\.)?/, '');
                        if (display.length > 40) display = display.substring(0, 37) + '...';
                        return `<a href="${safeUrl}" target="_blank" rel="noopener">${escapeHtml(display)}</a>`;
                    }).join('<br>');
                } else {
                    // Regular list (strings or objects)
                    renderedValue = validItems.map(i => {
                        if (typeof i === 'object') {
                            // Render object values joined by dash
                            return Object.values(i)
                                .filter(v => !isValueEmpty(v))
                                .map(escapeHtml)
                                .join(' - ');
                        }
                        return `<span class="list-item">${escapeHtml(String(i))}</span>`;
                    }).join(' ');
                }

            } else if (typeof value === 'string' && (value.startsWith('http://') || value.startsWith('https://'))) {
                // Single URL
                const safeUrl = escapeAttribute(value);
                renderedValue = `<a href="${safeUrl}" target="_blank" rel="noopener">${escapeHtml(value)}</a>`;
                isFullWidth = true;
            } else {
                // Simple string/number
                renderedValue = escapeHtml(String(value));
                if (renderedValue.length > 60) isFullWidth = true;
            }

            // Only append if we actually generated content
            if (renderedValue) {
                const rowClass = isFullWidth ? 'field-row full-width-grid' : 'field-row';
                html += `
                    <div class="${rowClass}">
                        <span class="field-label">${escapeHtml(formatLabel(fullKey))}</span>
                        <span class="field-value">${renderedValue}</span>
                    </div>
                `;
            }
        });

        return html;
    };

    // --- Main Display Function ---

    window.displayFacilities = function(facilitiesData, containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;

        if (!facilitiesData) {
            container.innerHTML = '<p>No data available to display.</p>';
            return;
        }

        let operatorGroups = [];

        // Parse Data Structure (Supports multiple formats)
        if (facilitiesData.projects) {
            // Object of projects format
            const cats = ['companies', 'company', 'operators', 'operator'];
            Object.values(facilitiesData.projects).forEach(proj => {
                if (!proj) return;
                const cat = (proj.category || (proj.data && proj.data.category) || '').toLowerCase();
                
                if (cats.includes(cat)) {
                    operatorGroups.push({
                        name: cleanText(proj.name),
                        operator: proj.data?.operator || {},
                        facilities: toArray(proj.data?.facilities)
                    });
                }
            });
        } else if (Array.isArray(facilitiesData)) {
            // Array format
            operatorGroups = facilitiesData;
        }

        if (operatorGroups.length === 0) {
            container.innerHTML = '<p>No facility operators found.</p>';
            return;
        }

        // Sort Operators A-Z
        operatorGroups.sort((a, b) => {
            const nameA = (a.name || a.operator?.name || '').toLowerCase();
            const nameB = (b.name || b.operator?.name || '').toLowerCase();
            return nameA.localeCompare(nameB);
        });

        let html = '<div class="facilities-database">';

        operatorGroups.forEach(group => {
            const operator = group.operator || {};
            let rawFacilities = toArray(group.facilities);

            // Deduplicate facilities by name
            const seen = new Set();
            const facilities = rawFacilities.filter(f => {
                const name = f?.identification?.name || f?.identification?.currentName;
                if (!name || seen.has(name)) return false;
                seen.add(name);
                return true;
            });

            // Determine Operator Name
            const hasPrivateOwner = facilities.some(f => f.isPrivatelyOwned === true);
            let operatorName = cleanText(operator.name || operator.currentName);
            
            if (!operatorName) {
                if (hasPrivateOwner) operatorName = 'Privately Owned';
                else operatorName = cleanText(group.name);
            }

            // Skip invalid or placeholder operators
            if (!operatorName || 
                operatorName === 'Unknown Parent Company' || 
                isValueEmpty(operatorName)) {
                return;
            }

            // Build Operator Header Info
            const locationParts = [];
            if (!isValueEmpty(operator.location)) locationParts.push(escapeHtml(operator.location));
            if (!isValueEmpty(operator.headquarters)) locationParts.push(escapeHtml(operator.headquarters));
            
            let years = '';
            if (!isValueEmpty(operator.operatingPeriod)) {
                years = escapeHtml(operator.operatingPeriod);
            } else if (!isValueEmpty(operator.founded)) {
                const end = (!operator.status || operator.status === 'Active') ? 'Present' : 'Defunct';
                years = `${escapeHtml(operator.founded)}-${end}`;
            }
            if (years) locationParts.push(years);

            const locationHtml = locationParts.length > 0 
                ? `<div class="operator-location">${locationParts.join(' • ')}</div>` 
                : '';

            // Render Operator Details
            const opFields = renderFields(operator, [
                'name', 'currentName', 'location', 'headquarters', 'status', 'operatingPeriod', 'founded',
                'identification', 'facilities'
            ]);
            
            const opDetailsHtml = opFields ? `<div class="operator-details">${opFields}</div>` : '';

            // Build HTML for this Operator Group
            html += `
                <details class="operator-section" data-operator="${escapeAttribute(operatorName)}">
                    <summary class="operator-header">
                        <span class="operator-name">${escapeHtml(operatorName)}</span>
                        ${locationHtml}
                    </summary>
                    <div class="operator-content-scrollable">
                        ${opDetailsHtml}
            `;

            // Render Facilities Cards
            facilities.sort((a, b) => {
                const na = (a.identification?.name || '').toLowerCase();
                const nb = (b.identification?.name || '').toLowerCase();
                return na.localeCompare(nb);
            });

            facilities.forEach(fac => {
                const ident = fac.identification || {};
                const period = fac.operatingPeriod || {};
                
                const facName = cleanText(ident.name || ident.currentName || 'Unnamed Facility');
                const status = cleanText(period.status) || 'Unknown';
                const statusClass = status.toLowerCase().replace(/[^a-z0-9]/g, '-');
                
                // Facility Subtitle (Location • Years)
                const facLoc = cleanText(fac.location);
                let facYears = '';
                if (!isValueEmpty(period.startYear)) {
                    facYears = `${period.startYear}-${period.endYear || 'Present'}`;
                }
                
                const subParts = [];
                if (facLoc) subParts.push(facLoc);
                if (facYears) subParts.push(facYears);
                const subtitle = subParts.join(' • ');

                // Render Facility Body
                const facFields = renderFields(fac, [
                    'identification.name', 'identification.currentName', 
                    'location', 'operatingPeriod', 'resources', 'fieldNotes',
                    'isPrivatelyOwned'
                ]);
                
                const resourcesHtml = renderResources(fac.resources);

                html += `
                    <div class="facility-card status-${statusClass}" data-facility="${escapeAttribute(facName)}" data-status="${statusClass}">
                        <div class="facility-summary">
                            <h3 class="facility-name">${escapeHtml(facName)}</h3>
                            ${subtitle ? `<p class="facility-location">${escapeHtml(subtitle)}</p>` : ''}
                            <p class="facility-status">
                                <span class="status-badge status-${statusClass}">${escapeHtml(status)}</span>
                            </p>
                        </div>
                        <div class="facility-details">
                            <details class="facility-expanded-info">
                                <summary><span class="closed-text">+ Learn more</span><span class="open-text">- Collapse details</span></summary>
                                <div class="facility-extra-content">
                                    ${facFields}
                                    ${resourcesHtml}
                                </div>
                            </details>
                        </div>
                    </div>
                `;
            });

            html += `
                    </div>
                </details>
            `;
        });

        html += '</div>';
        container.innerHTML = html;
        
        // Expose data for filtering
        window.facilitiesData = facilitiesData;
    };

    // --- Filter & Search Logic ---

    window.filterFacilities = function() {
        const searchInput = document.getElementById('searchInput');
        const term = searchInput ? searchInput.value.toLowerCase() : '';
        const statusSelect = document.getElementById('statusFilter');
        const statusVal = statusSelect ? statusSelect.value : '';

        document.querySelectorAll('.operator-section').forEach(section => {
            const opName = section.dataset.operator.toLowerCase();
            let hasVisibleCards = false;

            section.querySelectorAll('.facility-card').forEach(card => {
                const facName = card.dataset.facility.toLowerCase();
                const facStatus = card.dataset.status; // status-open, status-closed

                const matchesTerm = opName.includes(term) || facName.includes(term);
                // Status filter check: if 'open', match 'status-open'. 
                // Note: The select values are likely 'open', 'closed'. The dataset is 'status-open'.
                const matchesStatus = !statusVal || facStatus.includes(statusVal);

                if (matchesTerm && matchesStatus) {
                    card.style.display = 'block';
                    hasVisibleCards = true;
                } else {
                    card.style.display = 'none';
                }
            });

            section.style.display = hasVisibleCards ? 'block' : 'none';
        });
    };

    window.clearSearch = function() {
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.value = '';
            document.getElementById('clearSearch').classList.remove('visible');
        }
        const statusFilter = document.getElementById('statusFilter');
        if (statusFilter) statusFilter.value = '';
        
        window.filterFacilities();
    };

    window.filterByLetter = function(letter) {
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.value = letter;
            // Show clear button since we have a value
            document.getElementById('clearSearch').classList.add('visible');
            window.filterFacilities();
        }
    };

    // --- Initialization ---

    document.addEventListener('DOMContentLoaded', function() {
        const container = document.getElementById('facilities-container');
        if (!container) return;

        // Setup UI Events
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const btn = document.getElementById('clearSearch');
                if (btn) btn.classList.toggle('visible', this.value.length > 0);
                window.filterFacilities();
            });
        }

        const statusFilter = document.getElementById('statusFilter');
        if (statusFilter) {
            statusFilter.addEventListener('change', window.filterFacilities);
        }

        const alphabetFilter = document.getElementById('alphabet-filter');
        if (alphabetFilter) {
            alphabetFilter.innerHTML = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('')
                .map(l => `<button onclick="filterByLetter('${l}')">${l}</button>`)
                .join('') + `<button onclick="filterByLetter('')">All</button>`;
        }

        // Fetch Data
        const facilitiesConfig = window.facilitiesConfig || {};
        // Combine all potential URLs
        const urls = [
            facilitiesConfig.jsonDataUrl, 
            ...(facilitiesConfig.jsonFileUrls || [])
        ].filter(u => u);

        if (urls.length === 0) {
            container.innerHTML = '<p>Error: No data source configured.</p>';
            return;
        }

        // Attempt fetch
        (async () => {
            for (const url of urls) {
                try {
                    const resp = await fetch(url);
                    if (resp.ok) {
                        const data = await resp.json();
                        window.displayFacilities(data, 'facilities-container');
                        return; // Success
                    }
                } catch (e) {
                    console.error('Failed to load:', url, e);
                }
            }
            container.innerHTML = '<p>Error loading facilities data. Please try again later.</p>';
        })();
    });

})();
