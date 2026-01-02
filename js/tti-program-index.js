// Facilities Database Display - Drop-in JavaScript
// Usage: Include this file and call displayFacilities(jsonData, containerId)

function displayFacilities(facilitiesData, containerId) {
    const container = document.getElementById(containerId);
    if (!container) {
        console.warn('Facilities display skipped - container not found:', containerId);
        return;
    }
    
    // --- Helper Functions ---

    const toArray = value => Array.isArray(value) ? value : [];
    
    const cleanText = value => {
        if (typeof value === 'string') return value.trim();
        if (typeof value === 'number') return String(value);
        return '';
    };

    // Robust check for empty/placeholder values
    const isValueEmpty = (value) => {
        if (value === null || value === undefined) return true;
        
        if (typeof value === 'boolean') return !value; // Hide false values
        
        if (typeof value === 'string') {
            const lower = value.trim().toLowerCase();
            if (!lower) return true;
            
            const placeholders = [
                'none', 'no', 'n/a', 'na', 'n.a.', 'n.a',
                'unknown', 'null', 'undefined', 'false', 'empty',
                '-', '--', '—', '–', 'tbd', 'tba', '[]', '{}',
                'not specified', 'not available', 'not applicable',
                'no data', 'no info', 'no information', 'nil'
            ];
            
            // Check for exact match or stripped punctuation
            const stripped = lower.replace(/[.,;:\-–—!]+$/, '');
            if (placeholders.includes(stripped) || placeholders.includes(lower)) return true;

            return false;
        }
        
        if (typeof value === 'number') return false; // Numbers are valid (even 0)

        if (Array.isArray(value)) {
            return value.length === 0 || value.every(item => isValueEmpty(item));
        }
        
        if (typeof value === 'object') {
            const keys = Object.keys(value);
            if (keys.length === 0) return true;
            return keys.every(k => isValueEmpty(value[k]));
        }
        
        return true;
    };

    const htmlEscapeMap = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    };
    
    const escapeHtml = value => {
        const text = cleanText(value);
        return text ? text.replace(/[&<>"']/g, char => htmlEscapeMap[char] || char) : '';
    };
    
    const escapeAttribute = value => escapeHtml(value);
    
    const joinList = values => toArray(values)
        .filter(item => !isValueEmpty(item))
        .map(item => cleanText(item))
        .filter(item => item);

    const formatFieldLabel = (key) => {
        if (!key || typeof key !== 'string') return 'Field';
        
        // Strip common redundant prefixes
        let cleanKey = key
            .replace(/^(operator|facility|identification|facilityDetails|operatingPeriod|staff|accreditations)\./i, '')
            .replace(/\./g, ' ');
            
        return cleanKey
            .replace(/([A-Z])/g, ' $1')
            .replace(/has\s*/gi, '')
            .replace(/\s+/g, ' ')
            .trim()
            .split(' ')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
            .join(' ') || 'Field';
    };

    const getNotesForKey = (fieldNotes, key) => {
        if (!fieldNotes || typeof fieldNotes !== 'object' || !key) return [];
        const notes = fieldNotes[key];
        if (!notes) return [];
        const collected = [];
        const addNote = (text) => {
            if (text && text.trim()) collected.push(text.trim());
        };
        if (Array.isArray(notes)) {
            notes.forEach(note => {
                if (typeof note === 'string') {
                    addNote(note);
                } else if (note && typeof note === 'object' && note.text) {
                    addNote(note.text);
                }
            });
        } else if (typeof notes === 'string') {
            addNote(notes);
        } else if (notes && typeof notes === 'object' && notes.text) {
            addNote(notes.text);
        }
        return collected;
    };

    const renderInlineFieldNotes = (key, fieldNotes, usedKeys) => {
        const notes = getNotesForKey(fieldNotes, key);
        if (!notes.length) return '';
        if (usedKeys && key) usedKeys.add(key);
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
            notes.forEach(text => {
                items.push({ label, text });
            });
        });
        if (!items.length) return '';
        const list = items.map(({ label, text }) =>
            `<li><strong>${escapeHtml(label)}:</strong> ${escapeHtml(text)}</li>`
        ).join('');
        return `
            <div class="facility-field-notes">
                <p><strong>Additional Field Notes</strong></p>
                <ul>${list}</ul>
            </div>
        `;
    };

    const normalizeProjectCategory = project => {
        if (!project || typeof project !== 'object') {
            return 'companies';
        }
        let rawCategory = '';
        if (typeof project.category === 'string' && project.category) {
            rawCategory = project.category;
        } else if (project.data && typeof project.data === 'object' && typeof project.data.category === 'string' && project.data.category) {
            rawCategory = project.data.category;
        }
        if (!rawCategory) return 'companies';
        return rawCategory.toLowerCase();
    };

    const isOperatorCategory = project => {
        const category = normalizeProjectCategory(project);
        return category === 'operators' || category === 'operator' || category === 'companies' || category === 'company';
    };

    // --- Main Rendering Logic ---

    let html = '<div class="facilities-database">';

    // Convert the new JSON structure to work with existing code
    let operatorGroups = [];
    if (facilitiesData && facilitiesData.projects) {
        const operatorProjects = Object.values(facilitiesData.projects).filter(isOperatorCategory);
        operatorProjects.forEach(project => {
            if (!project || !project.data) return;
            operatorGroups.push({
                operator: project.data.operator || {},
                facilities: toArray(project.data.facilities),
                name: cleanText(project.name)
            });
        });
    } else if (Array.isArray(facilitiesData)) {
        operatorGroups = facilitiesData;
    } else if (facilitiesData && typeof facilitiesData === 'object') {
        operatorGroups = Object.values(facilitiesData);
    }

    if (!Array.isArray(operatorGroups) || operatorGroups.length === 0) {
        container.innerHTML = '<p>No facility operators found in the provided data.</p>';
        return;
    }

    operatorGroups.sort((a, b) => {
        const nameA = cleanText(a?.operator?.name || a?.operator?.currentName || a?.name || '');
        const nameB = cleanText(b?.operator?.name || b?.operator?.currentName || b?.name || '');
        return nameA.localeCompare(nameB);
    });

    operatorGroups.forEach(operatorGroup => {
        const operator = operatorGroup?.operator || {};
        const rawFacilities = toArray(operatorGroup?.facilities).slice();

        // Deduplicate facilities
        const seenFacilityNames = new Set();
        const facilities = rawFacilities.filter(f => {
            if (!f) return false;
            const id = f.identification || {};
            const name = cleanText(id.name) || cleanText(id.currentName) || '';
            if (!name || seenFacilityNames.has(name)) return false;
            seenFacilityNames.add(name);
            return true;
        });

        const hasPrivateOwner = facilities.some(f => f?.isPrivatelyOwned === true);
        const actualOperatorName = cleanText(operator.name) || cleanText(operator.currentName);
        
        let operatorName;
        if (hasPrivateOwner && !actualOperatorName) {
            operatorName = 'Privately Owned';
        } else if (actualOperatorName) {
            operatorName = actualOperatorName;
        } else {
            operatorName = cleanText(operatorGroup.name) || 'Unknown Parent Company';
        }

        if (operatorName === 'Unknown Parent Company' || isValueEmpty(operatorName)) return;

        facilities.sort((a, b) => {
            const nameA = a?.identification?.name || a?.identification?.currentName || '';
            const nameB = b?.identification?.name || b?.identification?.currentName || '';
            return nameA.localeCompare(nameB);
        });

        const hasLongWord = operatorName.split(' ').some(word => word.length > 14);
        const isTooLong = operatorName.length > 25;
        const operatorNameClass = (hasLongWord || isTooLong) ? 'operator-name operator-name-long' : 'operator-name';
        const operatorHeaderName = escapeHtml(operatorName);
        let operatorHeader = `<span class="${operatorNameClass}">${operatorHeaderName}</span>`;

        let locationYearsLine = '';
        const locationLines = [];

        const operatorLocation = cleanText(operator.location);
        if (!isValueEmpty(operatorLocation)) locationLines.push(`<div>${escapeHtml(operatorLocation)}</div>`);
        
        const operatorHQ = cleanText(operator.headquarters);
        if (!isValueEmpty(operatorHQ)) locationLines.push(`<div>${escapeHtml(operatorHQ)}</div>`);
        
        const operatorOperatingPeriod = cleanText(operator.operatingPeriod);
        if (!isValueEmpty(operatorOperatingPeriod)) {
            locationLines.push(`<div>${escapeHtml(operatorOperatingPeriod)}</div>`);
        } else if (!isValueEmpty(operator.founded)) {
            const endYear = isValueEmpty(operator.status) || operator.status === 'Defunct' ? 'Defunct' : 'Present';
            locationLines.push(`<div>${escapeHtml(`${cleanText(operator.founded)}-${endYear}`)}</div>`);
        }

        if (locationLines.length > 0) {
            locationYearsLine = `<span class="operator-location">${locationLines.join('')}</span>`;
        }

        let otherOperatorData = '';

        const renderItem = (item) => {
            if (isValueEmpty(item)) return '';
            if (typeof item === 'boolean') return item ? 'Yes' : '';
            if (typeof item === 'string') return escapeHtml(item);
            if (typeof item === 'number') return escapeHtml(String(item));
            if (typeof item === 'object') {
                const parts = [];
                Object.entries(item).forEach(([k, v]) => {
                    if (!isValueEmpty(v)) {
                        if (k === 'name' || k === 'value' || k === 'text') {
                            parts.push(escapeHtml(v));
                        } else if (k !== 'role' || v.trim()) {
                            parts.push(escapeHtml(v));
                        }
                    }
                });
                return parts.join(' - ');
            }
            return escapeHtml(String(item));
        };

        const renderAllObjectFields = (obj, prefix = '', depth = 0) => {
            if (!obj || typeof obj !== 'object' || depth > 3) return [];
            const fields = [];

            // Skip keys shown in header
            const skipFullKeys = [
                'name', 'location', 'headquarters', 'status', 'operatingPeriod', 'founded',
                'identification.name', 'operatingPeriod.startYear', 'operatingPeriod.endYear',
                'resources', 'fieldNotes'
            ];

            Object.keys(obj).forEach(key => {
                const fullKey = prefix ? `${prefix}.${key}` : key;
                const value = obj[key];

                if (skipFullKeys.includes(key) || skipFullKeys.includes(fullKey)) return;
                if (isValueEmpty(value)) return;

                if (Array.isArray(value)) {
                    const label = formatFieldLabel(fullKey);
                    fields.push({ key: fullKey, label, value: value, isList: true });
                } else if (typeof value === 'object') {
                    fields.push(...renderAllObjectFields(value, fullKey, depth + 1));
                } else if (typeof value === 'boolean') {
                    if (value) {
                         const label = formatFieldLabel(fullKey);
                         fields.push({ key: fullKey, label, value: 'Yes' });
                    }
                } else {
                    const label = formatFieldLabel(fullKey);
                    fields.push({ key: fullKey, label, value: cleanText(value) });
                }
            });
            return fields;
        };

        const operatorFields = renderAllObjectFields(operator);

        operatorFields.forEach(field => {
            if (isValueEmpty(field.value)) return;

            let renderedValue = '';
            let isFullWidth = false;

            if (field.isList) {
                const items = Array.isArray(field.value) ? field.value : [field.value];
                const validItems = items.filter(i => !isValueEmpty(i));
                if (!validItems.length) return;
                
                isFullWidth = true; // Lists get full width

                const isUrlList = validItems.some(item => typeof item === 'string' && item.startsWith('http'));

                if (isUrlList) {
                    renderedValue = validItems.map(url => {
                        const safeUrl = escapeAttribute(url);
                        let displayUrl = url.replace(/^https?:\/\/(www\.)?/, '').substring(0, 47) + (url.length > 50 ? '...' : '');
                        return safeUrl ? `<a href="${safeUrl}" target="_blank" rel="noopener">${escapeHtml(displayUrl)}</a>` : '';
                    }).join('<br>');
                } else {
                    renderedValue = validItems.map(item => renderItem(item)).filter(i => i.trim() !== '').join(', ');
                }
            } else {
                renderedValue = escapeHtml(field.value);
            }

            if (!renderedValue || isValueEmpty(renderedValue)) return;

            const rowClass = isFullWidth ? 'field-row full-width-grid' : 'field-row';
            otherOperatorData += `<div class="${rowClass}"><span class="field-label">${escapeHtml(field.label)}</span><span class="field-value">${renderedValue}</span></div>`;
        });

        const operatorDetailsDiv = otherOperatorData ? `<div class="operator-details">${otherOperatorData}</div>` : '';

        html += `<details class="operator-section" data-operator="${escapeAttribute(operatorName)}">
                <summary class="operator-header">
                    ${operatorHeader}
                    ${locationYearsLine}
                </summary>
                <div class="operator-content-scrollable">
                    ${operatorDetailsDiv}`;

        facilities.forEach(facility => {
            const identification = facility?.identification || {};
            const operatingPeriod = facility?.operatingPeriod || {};
            const fieldNotes = facility?.fieldNotes || {};
            const usedFieldNoteKeys = new Set();

            const statusLabelRaw = cleanText(operatingPeriod.status) || 'Unknown';
            const statusClass = statusLabelRaw.toLowerCase().replace(/[^a-z0-9]+/g, '-');
            const statusLabel = escapeHtml(statusLabelRaw);

            const facilityHeaderRaw = cleanText(identification.name) || cleanText(identification.currentName);
            const facilityHeader = escapeHtml(facilityHeaderRaw || 'Unnamed Facility');

            const facilityLocationRaw = cleanText(facility?.location);
            const facilityLocation = isValueEmpty(facilityLocationRaw) ? '' : escapeHtml(facilityLocationRaw);
            const yearRangeRaw = !isValueEmpty(operatingPeriod.startYear) ? `${operatingPeriod.startYear}-${operatingPeriod.endYear || 'Present'}` : null;
            const yearRange = isValueEmpty(yearRangeRaw) ? '' : escapeHtml(yearRangeRaw);

            let otherFacilityData = '';
            const facilityFields = renderAllObjectFields(facility);

            facilityFields.forEach(field => {
                if (isValueEmpty(field.value)) return;

                let renderedValue = '';
                let isFullWidth = false;

                if (field.isList) {
                    const items = Array.isArray(field.value) ? field.value : [field.value];
                    const validItems = items.filter(i => !isValueEmpty(i));
                    if (!validItems.length) return;
                    
                    isFullWidth = true;

                    const isUrlList = validItems.some(item => typeof item === 'string' && item.startsWith('http'));

                    if (isUrlList) {
                        renderedValue = validItems.map(url => {
                            const safeUrl = escapeAttribute(url);
                            let displayUrl = url.replace(/^https?:\/\/(www\.)?/, '').substring(0, 47) + (url.length > 50 ? '...' : '');
                            return safeUrl ? `<a href="${safeUrl}" target="_blank" rel="noopener">${escapeHtml(displayUrl)}</a>` : '';
                        }).join('<br>');
                    } else {
                        renderedValue = validItems.map(item => renderItem(item)).filter(i => i.trim() !== '').join(', ');
                        if (typeof validItems[0] === 'object') {
                             renderedValue = validItems.map(item => `<div class="list-item">${renderItem(item)}</div>`).join('');
                         }
                    }
                } else {
                    renderedValue = escapeHtml(field.value);
                    if (renderedValue.length > 50) isFullWidth = true; // Long text gets full width
                }

                if (!renderedValue || isValueEmpty(renderedValue)) return;

                const rowClass = isFullWidth ? 'field-row full-width-grid' : 'field-row';
                
                if (field.label) {
                    otherFacilityData += `<div class="${rowClass}"><span class="field-label">${escapeHtml(field.label)}</span><span class="field-value">${renderedValue}</span></div>`;
                    otherFacilityData += renderInlineFieldNotes(field.key, fieldNotes, usedFieldNoteKeys);
                } else {
                    otherFacilityData += `<div class="${rowClass}"><span class="field-value">${renderedValue}</span></div>`;
                }
            });

            // Resources
            let resourcesAvailable = '';
            if (facility.resources) {
                const resources = [];
                const resourceMap = {
                    'hasNews': 'News', 'hasPressReleases': 'Press Releases', 'hasInspections': 'Inspections',
                    'hasStateReports': 'State Reports', 'hasRegulatoryFilings': 'Regulatory Filings',
                    'hasLawsuits': 'Lawsuits', 'hasSettlements': 'Settlements', 'hasViolations': 'Violations',
                    'hasResearch': 'Research', 'hasFinancial': 'Financial', 'hasNATSAP': 'NATSAP Profile',
                    'hasWebsite': 'Website Screenshots', 'hasOther': 'Other'
                };
                Object.keys(resourceMap).forEach(key => {
                    if (facility.resources[key] === true) resources.push(resourceMap[key]);
                });
                if (facility.resources.customResources) {
                    resources.push(...facility.resources.customResources.map(i => cleanText(i)).filter(i => !isValueEmpty(i)));
                }
                if (resources.length > 0) {
                    resourcesAvailable = `<div class="field-row full-width-grid"><span class="field-label">Resources Available</span><span class="field-value">${escapeHtml(resources.join(', '))}</span></div>`;
                }
            }

            const facilityDatasetName = escapeAttribute(facilityDatasetNameRaw);

            html += `<div class="facility-card status-${statusClass}" data-facility="${facilityDatasetName}" data-status="${statusClass}">
                    <div class="facility-summary">
                        <h3 class="facility-name">${facilityHeader}</h3>
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
                                ${resourcesAvailable}
                                ${renderRemainingFieldNotes(fieldNotes, usedFieldNoteKeys)}
                            </div>
                        </details>
                    </div>
                </div>`;
        });
        
        html += '</div></details>';
    });
    
    html += '</div>';
    
    container.innerHTML = html;
    window.facilitiesData = facilitiesData;
}

// --- Filter & Sort Logic ---
function filterFacilities() {
    const searchInput = document.getElementById('searchInput');
    const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
    const statusFilterSelect = document.getElementById('statusFilter');
    const statusFilter = statusFilterSelect ? statusFilterSelect.value : '';

    const operatorSections = document.querySelectorAll('.operator-section');
    
    operatorSections.forEach(section => {
        const operatorName = section.dataset.operator.toLowerCase();
        const facilityCards = section.querySelectorAll('.facility-card');
        let visibleFacilities = 0;
        
        facilityCards.forEach(card => {
            const facilityName = card.dataset.facility.toLowerCase();
            const facilityStatus = card.dataset.status;
            const matchesSearch = operatorName.includes(searchTerm) || facilityName.includes(searchTerm);
            const matchesStatus = !statusFilter || facilityStatus === statusFilter;
            
            if (matchesSearch && matchesStatus) {
                card.style.display = 'block';
                visibleFacilities++;
            } else {
                card.style.display = 'none';
            }
        });
        
        section.style.display = visibleFacilities > 0 ? 'block' : 'none';
    });
}

function clearSearch() {
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const sortBy = document.getElementById('sortBy');
    const clearButton = document.getElementById('clearSearch');

    if (searchInput) searchInput.value = '';
    if (statusFilter) statusFilter.value = '';
    if (sortBy) sortBy.value = '';
    if (clearButton) clearButton.classList.remove('visible');
    filterFacilities();
}
window.clearSearch = clearSearch;

function setupAlphabetFilter() {
    const alphabetFilter = document.getElementById('alphabet-filter');
    if (!alphabetFilter) return;
    const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
    alphabetFilter.innerHTML = letters.map(letter => 
        `<button onclick="filterByLetter('${letter}')">${letter}</button>`
    ).join('') + '<button onclick="filterByLetter(\'\')">All</button>';
}

function filterByLetter(letter) {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.value = letter;
        filterFacilities();
    }
}
window.filterByLetter = filterByLetter;

function setupEventListeners() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const clearBtn = document.getElementById('clearSearch');
            if (clearBtn) {
                clearBtn.classList.toggle('visible', this.value.length > 0);
            }
            filterFacilities();
        });
    }
    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) statusFilter.addEventListener('change', filterFacilities);
    
    const sortBy = document.getElementById('sortBy');
    if (sortBy) sortBy.addEventListener('change', handleSort);
}

function handleSort() {
    const sortDropdown = document.getElementById('sortBy');
    const sortValue = sortDropdown ? sortDropdown.value : 'name';
    const operatorSections = Array.from(document.querySelectorAll('.operator-section'));
    const container = document.querySelector('.facilities-database');

    if (!container) return;
    
    if (sortValue === 'name') {
        operatorSections.sort((a, b) => a.dataset.operator.localeCompare(b.dataset.operator));
    } else if (sortValue === 'violations-only') {
         // basic filtering placeholder
    }
    operatorSections.forEach(section => container.appendChild(section));
}

document.addEventListener('DOMContentLoaded', function() {
    const facilitiesContainer = document.getElementById('facilities-container');
    if (!facilitiesContainer) return;

    setupAlphabetFilter();
    setupEventListeners();

    const facilitiesConfig = window.facilitiesConfig || {};
    const configUrls = Array.isArray(facilitiesConfig.jsonFileUrls) ? facilitiesConfig.jsonFileUrls : [];
    const defaultDatasetPath = '/wp-content/themes/child/js/data/facilities_master.json';
    const datasetCandidates = Array.from(new Set([
        facilitiesConfig.jsonDataUrl,
        ...configUrls,
        defaultDatasetPath
    ].filter(url => typeof url === 'string' && url.trim().length > 0)));

    if (!datasetCandidates.length) {
        facilitiesContainer.innerHTML = '<p>Error loading facilities data: no dataset URL is configured.</p>';
        return;
    }

    (async () => {
        const failureSummaries = [];
        for (const candidateUrl of datasetCandidates) {
            try {
                const response = await fetch(candidateUrl, { credentials: 'same-origin' });
                if (!response.ok) throw new Error('HTTP ' + response.status);
                const data = await response.json();
                displayFacilities(data, 'facilities-container');
                setupEventListeners();
                return;
            } catch (error) {
                failureSummaries.push(error.message);
            }
        }
        facilitiesContainer.innerHTML = '<p>Error loading facilities data.</p>';
    })();
});
