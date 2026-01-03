/**
 * TTI Program Index - Hybrid Version
 * Styling: Matches Dec 30 version (Old CSS compatible).
 * Logic: Uses Enhanced Rendering (recursive field display) from later version.
 */

(function() { // Wrap in IIFE to prevent global namespace pollution

    // Global configuration
    const localFacilitiesConfig = window.facilitiesConfig || {};

    // --- Utility Functions ---

    const cleanText = (text) => {
        if (typeof text !== 'string') return '';
        return text.replace(/\'/g, "'" ).replace(/\"/g, '"').trim();
    };

    const escapeHtml = (unsafe) => {
        if (typeof unsafe !== 'string') return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return unsafe.replace(/[&<>"']/g, m => map[m]);
    };

    const escapeAttribute = (unsafe) => {
        if (typeof unsafe !== 'string') return '';
        return unsafe.replace(/"/g, "&quot;");
    };

    const toArray = (val) => {
        if (!val) return [];
        if (Array.isArray(val)) return val;
        return [val];
    };

    const formatFieldLabel = (key) => {
        if (!key || typeof key !== 'string') return 'Field';
        return key
            .replace(/([A-Z])/g, ' $1')
            .replace(/[._-]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .split(' ')
            .map(w => w.charAt(0).toUpperCase() + w.slice(1));
    };

    const isUrl = (str) => {
        if (typeof str !== 'string') return false;
        const s = str.trim();
        return s.startsWith('http://') || s.startsWith('https://') || s.startsWith('www.');
    };

    const isValueEmpty = (value) => {
        if (value === null || value === undefined) return true;
        if (typeof value === 'boolean' || typeof value === 'number') return false;
        if (typeof value === 'string') {
            const lower = value.trim().toLowerCase();
            const placeholders = ['none', 'no', 'n/a', 'na', 'unknown', 'null', 'undefined', '-', '--', 'not specified', 'no data'];
            return !lower || placeholders.includes(lower.replace(/[.,;:]+$/, ''));
        }
        if (Array.isArray(value)) return value.length === 0 || value.every(isValueEmpty);
        if (typeof value === 'object') return Object.keys(value).length === 0 || Object.values(value).every(isValueEmpty);
        return false;
    };

    // --- Enhanced Recursive Rendering Logic ---

    const renderValue = (value, depth = 0) => {
        if (isValueEmpty(value)) return null;

        if (typeof value === 'boolean') return value ? 'Yes' : 'No';
        
        if (typeof value === 'string') {
            if (isUrl(value)) {
                let display = value.replace(/^https?:\/\/(www\.)?/, '');
                if (display.length > 50) display = display.substring(0, 47) + '...';
                return `<a href="${escapeAttribute(value)}" target="_blank" rel="noopener">${escapeHtml(display)}</a>`;
            }
            return escapeHtml(value);
        }

        if (typeof value === 'number') return escapeHtml(String(value));

        if (Array.isArray(value)) {
            const validItems = value.filter(i => !isValueEmpty(i));
            if (validItems.length === 0) return null;

            // If simple strings/numbers, comma separate
            if (validItems.every(i => typeof i === 'string' || typeof i === 'number')) {
                if (validItems.some(i => isUrl(String(i)))) {
                    return validItems.map(i => renderValue(i)).join('<br>');
                }
                return validItems.map(i => `<span class="list-item">${escapeHtml(String(i))}</span>`).join(' ');
            }
            
            // If complex objects, stack them
            return validItems.map(i => renderValue(i, depth + 1)).join('<br>');
        }

        if (typeof value === 'object') {
            // Prevent infinite recursion or deep nesting mess
            if (depth > 3) return escapeHtml(JSON.stringify(value));

            let html = '<div class="nested-object" style="margin-left: 10px; border-left: 2px solid #eee; padding-left: 10px;">';
            let hasContent = false;
            
            Object.keys(value).forEach(key => {
                const val = renderValue(value[key], depth + 1);
                if (val) {
                    html += `<div class="field-row-nested">
                                <strong class="field-label-nested">${escapeHtml(formatFieldLabel(key))}:</strong> 
                                <span class="field-value-nested">${val}</span>
                             </div>`;
                    hasContent = true;
                }
            });
            html += '</div>';
            return hasContent ? html : null;
        }

        return escapeHtml(String(value));
    };

    const renderAllFields = (obj, excludeKeys = []) => {
        if (!obj || typeof obj !== 'object') return '';
        
        let html = '';
        Object.keys(obj).forEach(key => {
            if (excludeKeys.includes(key)) return;
            const val = renderValue(obj[key]);
            
            if (val) {
                // Using .field-row class which matches the OLD CSS
                html += `<div class="field-row">
                            <span class="field-label">${escapeHtml(formatFieldLabel(key))}</span>
                            <span class="field-value">${val}</span>
                         </div>`;
            }
        });
        return html;
    };

    // --- Main Display Function ---

    window.displayFacilities = function(facilitiesData, containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;

        if (!facilitiesData) {
            container.innerHTML = '<p>No data available.</p>';
            return;
        }

        let operatorGroups = [];
        if (facilitiesData.projects) {
            Object.values(facilitiesData.projects).forEach(p => {
                if (p.category === 'companies' || p.category === 'company') {
                    operatorGroups.push({
                        operator: p.data?.operator || {},
                        facilities: toArray(p.data?.facilities),
                        name: cleanText(p.name)
                    });
                }
            });
        } else if (Array.isArray(facilitiesData)) {
            operatorGroups = facilitiesData;
        }

        if (!operatorGroups.length) {
            container.innerHTML = '<p>No facilities found.</p>';
            return;
        }

        operatorGroups.sort((a, b) => (a.name || '').localeCompare(b.name || ''));

        let html = '<div class="facilities-database">';

        operatorGroups.forEach(group => {
            const operator = group.operator || {};
            const facilities = group.facilities || [];
            
            // Deduplicate facilities
            const seen = new Set();
            const uniqueFacilities = facilities.filter(f => {
                const name = cleanText(f.identification?.name || f.identification?.currentName);
                if (!name || seen.has(name)) return false;
                seen.add(name);
                return true;
            });

            const opName = cleanText(operator.name || operator.currentName || group.name);
            if (!opName || opName === 'Unknown Parent Company') return;

            // Operator Header Info
            const opHeader = `<span class="operator-name">${escapeHtml(opName)}</span>`;
            const opLoc = cleanText(operator.location || operator.headquarters);
            const opSub = opLoc ? `<div class="operator-location">${escapeHtml(opLoc)}</div>` : '';

            // Render ALL Operator Fields (excluding already shown ones)
            const opFields = renderAllFields(operator, ['name', 'currentName', 'location', 'headquarters', 'projects']);
            const opDetails = opFields ? `<div class="operator-details">${opFields}</div>` : '';

            html += `<details class="operator-section" data-operator="${escapeAttribute(opName)}">
                        <summary class="operator-header">
                            ${opHeader}
                            ${opSub}
                        </summary>
                        <div class="operator-content-scrollable">
                            ${opDetails}`;

            uniqueFacilities.forEach(fac => {
                const ident = fac.identification || {};
                const opPeriod = fac.operatingPeriod || {};
                
                const facName = cleanText(ident.name || ident.currentName || 'Unnamed Facility');
                const status = cleanText(opPeriod.status || 'Unknown');
                const statusClass = status.toLowerCase().replace(/[^a-z0-9]+/g, '-');
                
                // Render ALL Facility Fields (excluding header info)
                const facFields = renderAllFields(fac, ['identification', 'operatingPeriod', 'location']);
                
                // Header Info
                const loc = cleanText(fac.location);
                const years = opPeriod.startYear ? `${opPeriod.startYear}-${opPeriod.endYear||'Present'}` : '';
                
                // Structure matching OLD CSS (.facility-summary, .facility-details)
                html += `<div class="facility-card status-${statusClass}" data-facility="${escapeAttribute(facName)}" data-status="${statusClass}">
                            <div class="facility-summary">
                                <h3 class="facility-name">${escapeHtml(facName)}</h3>
                                ${loc ? `<p class="facility-location">${escapeHtml(loc)}</p>` : ''}
                                ${years ? `<p class="facility-years">${escapeHtml(years)}</p>` : ''}
                                <p class="facility-status">
                                    <span class="status-badge status-${statusClass}">${escapeHtml(status)}</span>
                                </p>
                            </div>
                            <div class="facility-details">
                                <details class="facility-expanded-info">
                                    <summary><span class="closed-text">+ Learn more</span><span class="open-text">- Collapse details</span></summary>
                                    <div class="facility-extra-content">
                                        ${facFields}
                                    </div>
                                </details>
                            </div>
                        </div>`;
            });

            html += `</div></details>`;
        });

        html += '</div>';
        container.innerHTML = html;
        window.facilitiesData = facilitiesData;
    };

    // --- Filtering Logic ---

    window.filterFacilities = function() {
        const search = document.getElementById('searchInput')?.value.toLowerCase() || '';
        const status = document.getElementById('statusFilter')?.value || '';
        
        document.querySelectorAll('.operator-section').forEach(section => {
            let visibleCount = 0;
            const opName = section.dataset.operator.toLowerCase();
            
            section.querySelectorAll('.facility-card').forEach(card => {
                const name = card.dataset.facility.toLowerCase();
                const stat = card.dataset.status;
                
                const matchesSearch = opName.includes(search) || name.includes(search);
                const matchesStatus = !status || stat === status;
                
                if (matchesSearch && matchesStatus) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });
            
            section.style.display = visibleCount > 0 ? 'block' : 'none';
        });
    };

    window.clearSearch = function() {
        if(document.getElementById('searchInput')) document.getElementById('searchInput').value = '';
        if(document.getElementById('statusFilter')) document.getElementById('statusFilter').value = '';
        window.filterFacilities();
        document.getElementById('clearSearch')?.classList.remove('visible');
    };

    window.filterByLetter = function(letter) {
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.value = letter;
            document.getElementById('clearSearch')?.classList.add('visible');
            window.filterFacilities();
        }
    };

    window.toggleAllFacilityDetails = function(shouldExpand) {
        document.querySelectorAll('.facility-expanded-info').forEach(details => {
            if (shouldExpand) {
                details.setAttribute('open', '');
            } else {
                details.removeAttribute('open');
            }
        });
    };

    window.handleSort = function() {
        const sortValue = document.getElementById('sortBy')?.value || 'name';
        const container = document.querySelector('.facilities-database');
        if (!container) return;
        
        const sections = Array.from(container.querySelectorAll('.operator-section'));

        if (sortValue === 'name') {
            sections.sort((a, b) => a.dataset.operator.localeCompare(b.dataset.operator));
        }
        
        sections.forEach(section => container.appendChild(section));
    };

    // --- Init ---

    document.addEventListener('DOMContentLoaded', () => {
        const container = document.getElementById('facilities-container');
        if (!container) return;

        // Filters
        document.getElementById('searchInput')?.addEventListener('keyup', (e) => {
            document.getElementById('clearSearch')?.classList.toggle('visible', e.target.value.length > 0);
            window.filterFacilities();
        });
        document.getElementById('statusFilter')?.addEventListener('change', window.filterFacilities);
        document.getElementById('sortBy')?.addEventListener('change', window.handleSort);

        const alphabetFilter = document.getElementById('alphabet-filter');
        if (alphabetFilter) {
            alphabetFilter.innerHTML = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('')
                .map(l => `<button onclick="filterByLetter('${l}')">${l}</button>`)
                .join('') + `<button onclick="filterByLetter('')">All</button>`;
        }
        
        // Expand/Collapse All Buttons
        document.getElementById('expandAllBtn')?.addEventListener('click', () => window.toggleAllFacilityDetails(true));
        document.getElementById('collapseAllBtn')?.addEventListener('click', () => window.toggleAllFacilityDetails(false));

        // Load Data
        const load = async () => {
            const urls = [
                localFacilitiesConfig.jsonDataUrl,
                ...(localFacilitiesConfig.jsonFileUrls || [])
            ].filter(u => u);

            for (const url of urls) {
                try {
                    const res = await fetch(url);
                    if (res.ok) {
                        window.displayFacilities(await res.json(), 'facilities-container');
                        return;
                    }
                } catch(e) {
                    console.warn('Failed to load from', url, e);
                }
            }
            container.innerHTML = '<p>Error loading data.</p>';
        };
        load();
    });

})();