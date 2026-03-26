
// Location Index Display
// Styled to match TTI Program Index (Grouped by State/Country)

(function() {

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

    // --- Helper Functions (Copied from tti-program-index.js) ---

    const toArray = value => Array.isArray(value) ? value : [];

    const cleanText = value => {
        if (typeof value === 'string') return value.trim();
        if (typeof value === 'number') return String(value);
        return '';
    };

    const isValueEmpty = (value) => {
        if (value === null || value === undefined) return true;
        if (typeof value === 'boolean') return !value; 
        if (typeof value === 'string') {
            const lower = value.trim().toLowerCase();
            const placeholders = ['none', 'no', 'n/a', 'na', 'n.a.', 'n.a', 'unknown', 'null', 'undefined', 'false', 'empty', '-', '--', '—', '–', 'tbd', 'tba', '[]', '{}', 'not specified', 'not available', 'not applicable', 'no data', 'no info', 'no information', 'pending', 'none reported', 'not reported', 'no report', 'nil', 'unspecified'];
            if (placeholders.includes(lower)) return true;
            const stripped = lower.replace(/[.,;:\-–—]+$/, '');
            if (placeholders.includes(stripped)) return true;
            return false;
        }
        if (typeof value === 'number') return false; 
        if (Array.isArray(value)) return value.length === 0 || value.every(item => isValueEmpty(item));
        if (typeof value === 'object') {
            const keys = Object.keys(value);
            if (keys.length === 0) return true;
            return keys.every(k => isValueEmpty(value[k]));
        }
        return true;
    };

    const htmlEscapeMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    const escapeHtml = value => {
        const text = cleanText(value);
        return text ? text.replace(/[&<>"']/g, char => htmlEscapeMap[char] || char) : '';
    };
    const escapeAttribute = value => escapeHtml(value);
    const textCollator = new Intl.Collator(undefined, {
        numeric: true,
        sensitivity: 'base'
    });
    const normalizeTextKey = value => cleanText(value).replace(/\s+/g, ' ').trim().toLocaleLowerCase();
    const compareDisplayText = (a, b) => {
        const textA = cleanText(a);
        const textB = cleanText(b);

        if (!textA && !textB) return 0;
        if (!textA) return 1;
        if (!textB) return -1;

        return textCollator.compare(textA, textB);
    };
    const collectUniqueTexts = (...values) => {
        const seen = new Set();
        const items = [];

        values.forEach(value => {
            const candidates = Array.isArray(value) ? value : [value];
            candidates.forEach(candidate => {
                const text = cleanText(candidate);
                if (!text || isValueEmpty(text)) return;

                const key = normalizeTextKey(text);
                if (!key || seen.has(key)) return;

                seen.add(key);
                items.push(text);
            });
        });

        return items;
    };

    const joinList = values => toArray(values).filter(item => !isValueEmpty(item)).map(item => cleanText(item)).filter(item => item);

    const formatFieldLabel = (key) => {
        if (!key || typeof key !== 'string') return 'Field';
        let cleanKey = key.replace(/^(operator|facility|identification|facilityDetails|operatingPeriod|staff|accreditations)\./i, '').replace(/\./g, ' ');
        return cleanKey.replace(/([A-Z])/g, ' $1').replace(/has\s*/gi, '').replace(/\s+/g, ' ').trim().split(' ').map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase()).join(' ') || 'Field';
    };

    const getNotesForKey = (fieldNotes, key) => {
        if (!fieldNotes || typeof fieldNotes !== 'object' || !key) return [];
        const notes = fieldNotes[key];
        if (!notes) return [];
        const collected = [];
        const addNote = (text) => { if (text && text.trim()) collected.push(text.trim()); };
        if (Array.isArray(notes)) { notes.forEach(note => { if (typeof note === 'string') addNote(note); else if (note && typeof note === 'object' && note.text) addNote(note.text); }); } 
        else if (typeof notes === 'string') addNote(notes); 
        else if (notes && typeof notes === 'object' && notes.text) addNote(notes.text);
        return collected;
    };

    const getValueFromKeys = (obj, keys) => {
        if (!obj || typeof obj !== 'object') return null;
        for (const key of keys) {
            if (key.includes('.')) {
                const parts = key.split('.');
                let val = obj;
                for (const part of parts) { val = val && val[part]; if (val === undefined || val === null) break; }
                if (!isValueEmpty(val)) return typeof val === 'string' ? cleanText(val) : val;
            } else {
                if (!isValueEmpty(obj[key])) return typeof obj[key] === 'string' ? cleanText(obj[key]) : obj[key];
            }
        }
        return null;
    };

    const getFacilityDisplayName = facility => getValueFromKeys(facility, [
        'identification.name', 'identification.currentName',
        'name', 'programName', 'facilityName', 'title'
    ]) || '';

    const sortFacilitiesAlphabetically = facilities => toArray(facilities)
        .slice()
        .sort((a, b) => compareDisplayText(getFacilityDisplayName(a), getFacilityDisplayName(b)));

    const getMergedLocation = (obj) => {
        if (!obj || typeof obj !== 'object') return null;
        const loc = getValueFromKeys(obj, ['location', 'address', 'cityState', 'city_state', 'fullAddress', 'full_address', 'hq_location', 'headquarters']);
        if (loc) return loc;
        const city = getValueFromKeys(obj, ['city', 'locationCity', 'location_city', 'headquartersCity', 'hq_city']);
        const state = getValueFromKeys(obj, ['state', 'locationState', 'location_state', 'headquartersState', 'hq_state', 'province']);
        if (city && state) return `${city}, ${state}`;
        return city || state || null;
    };

    const renderInlineFieldNotes = (key, fieldNotes, usedKeys) => {
        let notes = getNotesForKey(fieldNotes, key);
        let matchedKey = key;
        if (!notes.length && key.includes('.')) {
            const leafKey = key.split('.').pop();
            notes = getNotesForKey(fieldNotes, leafKey);
            if (notes.length) matchedKey = leafKey;
        }
        if (!notes.length) return '';
        if (usedKeys && matchedKey) usedKeys.add(matchedKey);
        const noteText = notes.map(n => escapeHtml(n)).join('; ');
        return `<div class="field-note-inline"><span class="field-note-label">Note:</span> ${noteText}</div>`;
    };

    const renderRemainingFieldNotes = (fieldNotes, usedKeys) => {
        if (!fieldNotes || typeof fieldNotes !== 'object') return '';
        const items = [];
        Object.keys(fieldNotes).forEach(key => {
            if (usedKeys && usedKeys.has(key)) return;
            const notes = getNotesForKey(fieldNotes, key);
            if (!notes.length) return;
            const label = formatFieldLabel(key);
            notes.forEach(text => { items.push({ label, text }); });
        });
        if (!items.length) return '';
        const list = items.map(({ label, text }) => `<li><strong>${escapeHtml(label)}:</strong> ${escapeHtml(text)}</li>`).join('');
        return `<div class="facility-field-notes"><p><strong>Additional Field Notes</strong></p><ul>${list}</ul></div>`;
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
                
                if (facilities.length > 0) {
                    allLocations.push({
                        name: project.name,
                        type: US_STATES.has(project.name.toUpperCase()) ? 'state' : 'country',
                        facilities: sortFacilitiesAlphabetically(facilities)
                    });
                }
            }
        });

        allLocations.sort((a, b) => compareDisplayText(a.name, b.name));
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
                return loc;
            }
            return null;
        }).filter(l => l !== null);

        const sortVal = sortBy.value;
        filteredLocations.sort((a, b) => {
            if (sortVal === 'name') return compareDisplayText(a.name, b.name);
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
            details.className = 'operator-section'; 

            // -- Location Header --
            let headerHtml = `
                <summary class="operator-header">
                    <span class="operator-name" title="${loc.name}">${loc.name}</span>
                    <span class="operator-location">${loc.facilities.length} Facilities</span>
                </summary>
            `;

            // -- Location Content (Facilities) --
            let contentHtml = `<div class="operator-content-scrollable">`;
            
            loc.facilities.forEach(facility => {
                // Full Facility Card Logic adapted from TTI index
                const identification = facility && facility.identification ? facility.identification : {};
                const facilityDetails = facility && facility.facilityDetails ? facility.facilityDetails : {};
                const operatingPeriod = facility && facility.operatingPeriod ? facility.operatingPeriod : {};
                const fieldNotes = (facility && facility.fieldNotes) || {};
                const usedFieldNoteKeys = new Set();

                const statusLabelRaw = cleanText(operatingPeriod.status) || 'Unknown';
                const statusClass = statusLabelRaw.toLowerCase().replace(/[^a-z0-9]+/g, '-');
                const statusLabel = escapeHtml(statusLabelRaw);

                const facilityHeaderRaw = getFacilityDisplayName(facility);
                const facilityHeader = escapeHtml(facilityHeaderRaw || 'Unnamed Facility');
                const facilityDatasetName = escapeAttribute(facilityHeaderRaw || 'Unnamed Facility');

                const facilityLocation = getMergedLocation(facility) ? escapeHtml(getMergedLocation(facility)) : '';

                let yearRange = '';
                let facYears = getValueFromKeys(facility, ['yearsOfOperation', 'yearsActive', 'years_active']);
                if (facYears && typeof facYears === 'string') {
                    yearRange = escapeHtml(facYears);
                } else {
                    const startYear = getValueFromKeys(operatingPeriod, ['startYear']) || getValueFromKeys(facility, ['founded', 'yearFounded']);
                    if (startYear) {
                        const endYear = getValueFromKeys(operatingPeriod, ['endYear']) || 'Present';
                        yearRange = escapeHtml(`${startYear}-${endYear}`);
                    }
                }

                // Subtext for former names, aliases, and current operator
                const formerNames = collectUniqueTexts(
                    getValueFromKeys(facility, ['identification.pastNames', 'pastNames', 'identification.formerNames', 'formerNames'])
                );
                const formerNameKeys = new Set(formerNames.map(normalizeTextKey));
                const otherNames = collectUniqueTexts(
                    getValueFromKeys(facility, ['identification.otherNames', 'otherNames'])
                ).filter(name => !formerNameKeys.has(normalizeTextKey(name)));
                const currentOp = getValueFromKeys(facility, ['identification.currentOperator', 'currentOperator', 'sourceOperator.name']);
                
                let subtextParts = [];
                if (formerNames.length > 0) subtextParts.push(`Formerly: ${escapeHtml(formerNames.join(', '))}`);
                if (otherNames.length > 0) subtextParts.push(`Also known as: ${escapeHtml(otherNames.join(', '))}`);
                if (currentOp && !isValueEmpty(currentOp)) {
                    subtextParts.push(`Operated by: ${escapeHtml(currentOp)}`);
                }
                let otherNamesHtml = subtextParts.length > 0 ? `<div class="facility-header-subtext">${subtextParts.join('<br>')}</div>` : '';

                // Build other facility data
                let otherFacilityData = '';
                const renderItemFac = (item) => {
                    if (isValueEmpty(item)) return '';
                    if (typeof item === 'string') return escapeHtml(item);
                    if (typeof item === 'object' && !Array.isArray(item)) {
                        if (item.url) return `<a href="${escapeAttribute(item.url)}" target="_blank">${escapeHtml(item.name || item.text || item.url)}</a>`;
                        const parts = [];
                        Object.entries(item).forEach(([k, v]) => { 
                            if (!isValueEmpty(v) && (k === 'name' || k === 'value' || (k !== 'role' || v.trim()))) parts.push(escapeHtml(v)); 
                        });
                        return parts.join(' - ');
                    }
                    return escapeHtml(String(item));
                };

                const renderAllObjectFieldsFac = (obj, prefix = '', depth = 0) => {
                    if (!obj || typeof obj !== 'object' || depth > 3) return [];
                    const fields = [];
                    const skipFullKeys = ['resources', 'fieldNotes'];
                    Object.keys(obj).forEach(key => {
                        const fullKey = prefix ? `${prefix}.${key}` : key;
                        const value = obj[key];
                        if (skipFullKeys.includes(fullKey)) return;
                        if (isValueEmpty(value)) return;
                        if (Array.isArray(value)) {
                            if (value.length > 0 && !value.every(isValueEmpty)) fields.push({ key: fullKey, label: formatFieldLabel(fullKey), value: value, isList: true });
                        } else if (typeof value === 'object') fields.push(...renderAllObjectFieldsFac(value, fullKey, depth + 1));
                        else fields.push({ key: fullKey, label: formatFieldLabel(fullKey), value: cleanText(value) });
                    });
                    return fields;
                };

                const facilityFields = renderAllObjectFieldsFac(facility);
                facilityFields.forEach(field => {
                    if (isValueEmpty(field.value)) return;
                    let renderedValue = '';
                    if (field.isList) {
                        const items = Array.isArray(field.value) ? field.value : [field.value];
                        renderedValue = items.map(item => renderItemFac(item)).filter(Boolean).join(', ');
                    } else {
                        renderedValue = escapeHtml(field.value);
                    }
                    if (!renderedValue) return;
                    otherFacilityData += `<div class="field-row"><span class="field-label">${escapeHtml(field.label)}</span><span class="field-value">${renderedValue}</span></div>`;
                    otherFacilityData += renderInlineFieldNotes(field.key, fieldNotes, usedFieldNoteKeys);
                });

                contentHtml += `
                    <div class="facility-card status-${statusClass}" data-facility="${facilityDatasetName}" data-status="${statusClass}">
                        <div class="facility-summary">
                            <h3 class="facility-name">${facilityHeader}</h3>
                            ${otherNamesHtml}
                            ${facilityLocation ? `<p class="facility-location">${facilityLocation}</p>` : ''}
                            ${yearRange ? `<p class="facility-years">${yearRange}</p>` : ''}
                            <p class="facility-status">
                                <span class="status-badge status-${statusClass}">${statusLabel}</span>
                            </p>
                        </div>
                        <div class="facility-details">
                            <details class="facility-expanded-info">
                                <summary><span class="closed-text">+ Learn more</span><span class="open-text">- Collapse details</span></summary>
                                <div class="facility-extra-content">
                                    ${otherFacilityData}
                                    ${renderRemainingFieldNotes(fieldNotes, usedFieldNoteKeys)}
                                </div>
                            </details>
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

})();
