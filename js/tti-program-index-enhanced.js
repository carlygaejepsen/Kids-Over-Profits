// Enhanced Facilities Database Display - Shows ALL Available Fields
// This version dynamically renders all fields from the JSON data

function displayFacilitiesEnhanced(facilitiesData, containerId) {
    const container = document.getElementById(containerId);
    if (!container) {
        console.warn('Facilities display skipped - container not found:', containerId);
        return;
    }

    const toArray = value => Array.isArray(value) ? value : [];
    const cleanText = value => {
        if (typeof value === 'string') return value.trim();
        if (typeof value === 'number') return String(value);
        return '';
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

    const formatFieldLabel = (key) => {
        if (!key || typeof key !== 'string') return 'Field';
        return key
            .replace(/([A-Z])/g, ' $1')
            .replace(/[._-]/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .split(' ')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ');
    };

    const isUrl = (str) => {
        if (typeof str !== 'string') return false;
        return str.startsWith('http://') || str.startsWith('https://') || str.startsWith('www.');
    };

    const renderValue = (value, depth = 0) => {
        if (value === null || value === undefined) {
            return '<em style="color: #999;">Not specified</em>';
        }

        if (typeof value === 'boolean') {
            return value ? 'Yes' : 'No';
        }

        if (typeof value === 'string') {
            if (isUrl(value)) {
                return `<a href="${escapeAttribute(value)}" target="_blank" rel="noopener">${escapeHtml(value)}</a>`;
            }
            return escapeHtml(value);
        }

        if (typeof value === 'number') {
            return escapeHtml(String(value));
        }

        if (Array.isArray(value)) {
            if (value.length === 0) return '<em style="color: #999;">None</em>';

            // Check if array contains objects
            if (value.some(item => typeof item === 'object' && item !== null)) {
                return value.map(item => renderValue(item, depth + 1)).join('<br>');
            }

            // Simple array of primitives
            return value.map(item => {
                if (isUrl(item)) {
                    return `<a href="${escapeAttribute(item)}" target="_blank" rel="noopener">${escapeHtml(item)}</a>`;
                }
                return escapeHtml(String(item));
            }).join(', ');
        }

        if (typeof value === 'object') {
            // Render nested object
            if (depth > 2) return '<em>[Complex Object]</em>'; // Prevent too deep nesting

            let html = '<div style="margin-left: 20px; border-left: 2px solid #ddd; padding-left: 10px;">';
            Object.keys(value).forEach(key => {
                if (value[key] !== null && value[key] !== undefined && value[key] !== '') {
                    html += `<p style="margin: 5px 0;"><strong>${escapeHtml(formatFieldLabel(key))}:</strong> ${renderValue(value[key], depth + 1)}</p>`;
                }
            });
            html += '</div>';
            return html;
        }

        return escapeHtml(String(value));
    };

    const renderAllFields = (obj, excludeKeys = []) => {
        if (!obj || typeof obj !== 'object') return '';

        let html = '';
        Object.keys(obj).forEach(key => {
            if (excludeKeys.includes(key)) return;

            const value = obj[key];

            // Skip empty values
            if (value === null || value === undefined || value === '') return;
            if (Array.isArray(value) && value.length === 0) return;
            if (typeof value === 'object' && Object.keys(value).length === 0) return;

            html += `<p><strong>${escapeHtml(formatFieldLabel(key))}:</strong> ${renderValue(value)}</p>`;
        });

        return html;
    };

    const normalizeProjectCategory = project => {
        if (!project || typeof project !== 'object') return 'companies';

        let rawCategory = '';
        if (typeof project.category === 'string' && project.category) {
            rawCategory = project.category;
        } else if (project.data && typeof project.data === 'object' && typeof project.data.category === 'string' && project.data.category) {
            rawCategory = project.data.category;
        }

        return rawCategory ? rawCategory.toLowerCase() : 'companies';
    };

    const isOperatorCategory = project => {
        const category = normalizeProjectCategory(project);
        return category === 'operators' || category === 'operator' || category === 'companies' || category === 'company';
    };

    let html = '<div class="facilities-database">';

    // Convert the JSON structure to operator groups
    let operatorGroups = [];
    if (facilitiesData && facilitiesData.projects) {
        const operatorProjects = Object.values(facilitiesData.projects).filter(isOperatorCategory);

        operatorProjects.forEach(project => {
            if (!project || !project.data) return;

            const operator = project.data.operator || {};
            const facilities = toArray(project.data.facilities);

            operatorGroups.push({
                operator,
                facilities,
                name: cleanText(project.name),
                rawData: project  // Keep raw data for complete display
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

    // Sort operators alphabetically
    operatorGroups.sort((a, b) => {
        const operatorA = a && a.operator ? a.operator : {};
        const operatorB = b && b.operator ? b.operator : {};
        const nameA = cleanText(operatorA.name) || cleanText(operatorA.currentName) || cleanText(a && a.name) || '';
        const nameB = cleanText(operatorB.name) || cleanText(operatorB.currentName) || cleanText(b && b.name) || '';
        return nameA.localeCompare(nameB);
    });

    // Generate operator sections
    operatorGroups.forEach(operatorGroup => {
        const operator = operatorGroup && operatorGroup.operator ? operatorGroup.operator : {};
        const facilities = toArray(operatorGroup && operatorGroup.facilities).slice();

        // Get operator name
        const hasPrivateOwner = facilities.some(f => f && f.isPrivatelyOwned === true);
        const actualOperatorName = cleanText(operator.name) || cleanText(operator.currentName);

        let operatorName;
        if (hasPrivateOwner && !actualOperatorName) {
            operatorName = 'Privately Owned';
        } else if (actualOperatorName) {
            operatorName = actualOperatorName;
        } else {
            operatorName = cleanText(operatorGroup && operatorGroup.name) || 'Unknown Parent Company';
        }

        // Skip unknown operators
        if (operatorName === 'Unknown Parent Company') return;

        // Sort facilities alphabetically
        facilities.sort((a, b) => {
            const facilityA = a && a.identification ? a.identification : {};
            const facilityB = b && b.identification ? b.identification : {};
            const nameA = facilityA.name || facilityA.currentName || '';
            const nameB = facilityB.name || facilityB.currentName || '';
            return nameA.localeCompare(nameB);
        });

        // Build operator header
        const hasLongWord = operatorName.split(' ').some(word => word.length > 14);
        const isTooLong = operatorName.length > 25;
        const operatorNameClass = (hasLongWord || isTooLong) ? 'operator-name operator-name-long' : 'operator-name';

        html += `<details class="operator-section" data-operator="${escapeAttribute(operatorName)}">
                <summary class="operator-header">
                    <span class="${operatorNameClass}">${escapeHtml(operatorName)}</span>
                </summary>
                <div class="operator-content-scrollable">
                    <div class="operator-details">
                        <h4 style="color: #33A7B5; margin-top: 0;">Parent Company Details</h4>
                        ${renderAllFields(operator)}
                    </div>`;

        // Display all facilities
        facilities.forEach(facility => {
            const identification = facility && facility.identification ? facility.identification : {};
            const operatingPeriod = facility && facility.operatingPeriod ? facility.operatingPeriod : {};

            const statusLabelRaw = cleanText(operatingPeriod.status) || 'Unknown';
            const statusClass = statusLabelRaw.toLowerCase().replace(/[^a-z0-9]+/g, '-');
            const statusLabel = escapeHtml(statusLabelRaw);

            const facilityHeaderRaw = cleanText(identification.name) || cleanText(identification.currentName);
            const facilityHeader = escapeHtml(facilityHeaderRaw || 'Unnamed Facility');

            const facilityLocation = escapeHtml(cleanText(facility && facility.location));
            const yearRange = operatingPeriod.startYear
                ? escapeHtml(`${operatingPeriod.startYear}-${operatingPeriod.endYear || 'Present'}`)
                : '';

            html += `<div class="facility-card status-${statusClass}" data-facility="${escapeAttribute(facilityHeaderRaw)}" data-status="${statusClass}">
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
                            <summary><span class="closed-text">+ Show all fields</span><span class="open-text">- Hide fields</span></summary>
                            <div class="facility-extra-content">
                                ${renderAllFields(facility)}
                            </div>
                        </details>
                    </div>
                </div>`;
        });

        html += '</div></details>';
    });

    html += '</div>';
    container.innerHTML = html;

    // Store data globally for filtering
    window.facilitiesData = facilitiesData;
}

// Keep all the filter and search functions from the original file
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
            const facilityText = card.textContent.toLowerCase();

            const matchesSearch = operatorName.includes(searchTerm) ||
                                 facilityName.includes(searchTerm) ||
                                 facilityText.includes(searchTerm);
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
    if (clearButton) clearButton.style.display = 'none';
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
        searchInput.value = letter === '' ? '' : letter;
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
                clearBtn.style.display = this.value ? 'inline-block' : 'none';
            }
            filterFacilities();
        });
    }

    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.addEventListener('change', filterFacilities);
    }

    const sortBy = document.getElementById('sortBy');
    if (sortBy) {
        sortBy.addEventListener('change', handleSort);
    }
}

function handleSort() {
    const sortDropdown = document.getElementById('sortBy');
    const sortValue = sortDropdown ? sortDropdown.value : 'name';
    const operatorSections = Array.from(document.querySelectorAll('.operator-section'));
    const container = document.querySelector('.facilities-database');

    if (!container) return;

    operatorSections.sort((a, b) => a.dataset.operator.localeCompare(b.dataset.operator));
    operatorSections.forEach(section => container.appendChild(section));
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    console.log('Enhanced facilities script loaded');

    const facilitiesContainer = document.getElementById('facilities-container');
    if (!facilitiesContainer) {
        console.info('Facilities script: no facilities-container element present, skipping data fetch.');
        return;
    }

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
        console.error('Facilities script: no dataset URLs are configured.');
        facilitiesContainer.innerHTML = '<p>Error loading facilities data: no dataset URL is configured.</p>';
        return;
    }

    const decodeResponseAsJson = async (response) => {
        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch (parseError) {
            throw new Error('Invalid JSON (' + parseError.message + ')');
        }
    };

    (async () => {
        const failureSummaries = [];

        for (const candidateUrl of datasetCandidates) {
            try {
                console.log('Facilities script: attempting to load data from', candidateUrl);
                const response = await fetch(candidateUrl, { credentials: 'same-origin' });

                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ' ' + response.statusText);
                }

                const data = await decodeResponseAsJson(response);
                console.log('Facilities script: data loaded successfully from', candidateUrl);

                // Use the enhanced display function
                displayFacilitiesEnhanced(data, 'facilities-container');

                setupEventListeners();
                return;
            } catch (candidateError) {
                console.warn('Facilities script: failed to load dataset from', candidateUrl, candidateError);
                failureSummaries.push(candidateUrl + ' → ' + candidateError.message);
            }
        }

        const summaryMessage = failureSummaries.length
            ? 'Tried ' + failureSummaries.length + ' URL(s): ' + failureSummaries.join('; ')
            : 'No dataset URLs were available.';

        console.error('Facilities script: unable to load facilities data. ' + summaryMessage);
        facilitiesContainer.innerHTML = '<p>Error loading facilities data. ' + summaryMessage + '</p>';
    })();
});
