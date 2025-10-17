// Facilities Database Display - Drop-in JavaScript
// Usage: Include this file and call displayFacilities(jsonData, containerId)

function displayFacilities(facilitiesData, containerId) {
    const container = document.getElementById(containerId);
    if (!container) {
        console.warn('Facilities display skipped - container not found:', containerId);
        return;
    }
    
    const toArray = value => Array.isArray(value) ? value : [];
    const cleanText = value => {
        if (typeof value === 'string') {
            return value.trim();
        }

        if (typeof value === 'number') {
            return String(value);
        }

        return '';
    };
    const joinList = values => toArray(values).map(item => cleanText(item)).filter(item => item);

    let html = '<div class="facilities-database">';

    // Don't inject controls - use the existing ones from the template

    // Convert the new JSON structure to work with existing code
    let operatorGroups = [];
    if (facilitiesData && facilitiesData.projects) {
        // Handle new JSON structure
        Object.values(facilitiesData.projects).forEach(project => {
            if (!project || !project.data) {
                return;
            }

            const operator = project.data.operator || {};
            const facilities = toArray(project.data.facilities);

            operatorGroups.push({
                operator,
                facilities
            });
        });
    } else if (Array.isArray(facilitiesData)) {
        // Handle old structure (fallback)
        operatorGroups = facilitiesData;
    } else if (facilitiesData && typeof facilitiesData === 'object') {
        operatorGroups = Object.values(facilitiesData);
    }

    if (!Array.isArray(operatorGroups) || operatorGroups.length === 0) {
        container.innerHTML = '<p>No facility operators found in the provided data.</p>';
        return;
    }

    // Sort operators alphabetically by name (falling back to empty string)
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

        const operatorName = cleanText(operator.name) || cleanText(operator.currentName) || cleanText(operatorGroup && operatorGroup.name) || 'Unknown Operator';

        // Sort facilities alphabetically by name
        facilities.sort((a, b) => {
            const facilityA = a && a.identification ? a.identification : {};
            const facilityB = b && b.identification ? b.identification : {};
            const nameA = facilityA.name || facilityA.currentName || '';
            const nameB = facilityB.name || facilityB.currentName || '';
            return nameA.localeCompare(nameB);
        });

        // Build operator header with Name - add class for long names or long words
        const hasLongWord = operatorName.split(' ').some(word => word.length > 14);
        const isTooLong = operatorName.length > 25;
        const operatorNameClass = (hasLongWord || isTooLong) ? 'operator-name operator-name-long' : 'operator-name';
        let operatorHeader = `<span class="${operatorNameClass}">${operatorName}</span>`;
        // Build location and years - each on separate lines
        let locationYearsLine = '';
        const locationLines = [];

        const operatorLocation = cleanText(operator.location);
        if (operatorLocation) {
            locationLines.push(`<div>${operatorLocation}</div>`);
        }
        const operatorHQ = cleanText(operator.headquarters);
        if (operatorHQ) {
            locationLines.push(`<div>${operatorHQ}</div>`);
        }
        const operatorOperatingPeriod = cleanText(operator.operatingPeriod);
        if (operatorOperatingPeriod) {
            locationLines.push(`<div>${operatorOperatingPeriod}</div>`);
        } else if (cleanText(operator.founded)) {
            const endYear = operator.status === 'Defunct' ? 'Defunct' : 'Present';
            locationLines.push(`<div>${cleanText(operator.founded)}-${endYear}</div>`);
        }
        
        if (locationLines.length > 0) {
            locationYearsLine = `<span class="operator-location">${locationLines.join('')}</span>`;
        }
        
        // Build other operator data
        let otherOperatorData = '';
        const parentCompanies = joinList(operator.parentCompanies);
        const keyStaff = operator.keyStaff || {};
        const founders = joinList(keyStaff.founders);
        const websites = joinList(operator.websites);
        const ceoName = cleanText(keyStaff.ceo);

        const operatorFields = [
            { key: 'status', label: 'Status', value: cleanText(operator.status) },
            { key: 'founded', label: 'Founded', value: cleanText(operator.founded) },
            { key: 'parentCompanies', label: 'Parent Companies', value: parentCompanies.length > 0 ? parentCompanies.join(', ') : null },
            { key: 'keyStaff.founders', label: 'Founders', value: founders.length > 0 ? founders.join(', ') : null },
            { key: 'keyStaff.ceo', label: 'CEO', value: ceoName || null },
            { key: 'websites', label: '', value: websites.length > 0 ? `<a href='${websites[0]}' target='_blank'>Archived website</a>` : null }
        ];
        
        operatorFields.forEach(field => {
            if (field.value && field.value.toString().trim()) {
                if (field.label) {
                    otherOperatorData += '<p><strong>' + field.label + ':</strong> ' + field.value + '</p>';
                } else {
                    otherOperatorData += '<p>' + field.value + '</p>';
                }
            }
        });
        
        // Always create the div, even if empty
        otherOperatorData = `<div class="operator-details">${otherOperatorData}</div>`;
        
        html += '<details class="operator-section" data-operator="' + operatorName + '">' +
                '<summary class="operator-header">' +
                    operatorHeader +
                    locationYearsLine +
                '</summary>' +
                '<div class="operator-content-scrollable">' +
                    otherOperatorData;

        facilities.forEach(facility => {
            const identification = facility && facility.identification ? facility.identification : {};
            const facilityDetails = facility && facility.facilityDetails ? facility.facilityDetails : {};
            const ageRange = facilityDetails && facilityDetails.ageRange ? facilityDetails.ageRange : {};
            const staff = facility && facility.staff ? facility.staff : {};
            const accreditations = facility && facility.accreditations ? facility.accreditations : {};
            const operatingPeriod = facility && facility.operatingPeriod ? facility.operatingPeriod : {};
            const memberships = joinList(facility && facility.memberships);
            const licensing = joinList(facility && facility.licensing);
            const profileLinks = joinList(facility && facility.profileLinks);

            const statusLabelRaw = cleanText(operatingPeriod.status) || 'Unknown';
            const statusClass = statusLabelRaw.toLowerCase().replace(/[^a-z0-9]+/g, '-');

            // Build facility header with Name
            let facilityHeader = cleanText(identification.name) || cleanText(identification.currentName) || 'Unnamed Facility';

            // Build location, years, and status - each on its own line
            let facilityLocationYears = '';

            const facilityLocation = cleanText(facility && facility.location);
            if (facilityLocation) {
                facilityLocationYears += `<p>${facilityLocation}</p>`;
            }
            const facilityAddress = cleanText(facility && facility.address);
            if (facilityAddress) {
                facilityLocationYears += `<p>${facilityAddress}</p>`;
            }

            // Years of operation
            if (operatingPeriod.startYear) {
                const endYear = operatingPeriod.endYear ? operatingPeriod.endYear : 'Present';
                const yearRange = operatingPeriod.startYear + '-' + endYear;
                facilityLocationYears += `<p>${yearRange}</p>`;
            }

            // Add status on its own line
            if (operatingPeriod.status) {
                facilityLocationYears += `<p><span class="status-badge status-${statusClass}">${statusLabelRaw}</span></p>`;
            }

            // Build other facility data
            let otherFacilityData = '';
            const facilityFields = [
                { key: 'facilityDetails.type', label: 'Type', value: cleanText(facilityDetails.type) },
                { key: 'facilityDetails.capacity', label: 'Capacity', value: cleanText(facilityDetails.capacity) },
                { key: 'facilityDetails.ageRange', label: 'Age Range', value: (ageRange.min || ageRange.max) ? `${ageRange.min || '?'}-${ageRange.max || '?'}` : null },
                { key: 'facilityDetails.gender', label: 'Gender', value: cleanText(facilityDetails.gender) },
                { key: 'identification.pastNames', label: 'Past Names', value: joinList(identification.pastNames).join(', ') || null },
                { key: 'staff.administrator', label: 'Administrator', value: joinList(staff.administrator).join(', ') || null },
                { key: 'accreditations.current', label: 'Current Accreditations', value: joinList(accreditations.current).join(', ') || null },
                { key: 'accreditations.past', label: 'Past Accreditations', value: joinList(accreditations.past).join(', ') || null },
                { key: 'memberships', label: 'Memberships', value: memberships.length > 0 ? memberships.join(', ') : null },
                { key: 'licensing', label: 'Licensing', value: licensing.length > 0 ? licensing.join(', ') : null },
                { key: 'profileLinks', label: '', value: profileLinks.length > 0 ? `<a href='${profileLinks[0]}' target='_blank'>Archived website</a>` : null }
            ];

            facilityFields.forEach(field => {
                if (field.value && field.value.toString().trim()) {
                    if (field.label) {
                        otherFacilityData += '<p><strong>' + field.label + ':</strong> ' + field.value + '</p>';
                    } else {
                        otherFacilityData += '<p>' + field.value + '</p>';
                    }
                }
            });

            // Build resources available section
            let resourcesAvailable = '';
            if (facility.resources) {
                const resources = [];
                const resourceMap = {
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
                
                Object.keys(resourceMap).forEach(key => {
                    if (facility.resources[key] === true) {
                        resources.push(resourceMap[key]);
                    }
                });
                
                if (facility.resources.customResources && facility.resources.customResources.length > 0) {
                    resources.push(...facility.resources.customResources);
                }
                
                if (resources.length > 0) {
                    resourcesAvailable = '<p><strong>Resources Available:</strong> ' + resources.join(', ') + '</p>';
                }
            }
            
            const facilityDatasetName = cleanText(identification.name) || cleanText(identification.currentName) || facilityHeader;

            html += '<div class="facility-card status-' + statusClass + '" data-facility="' + facilityDatasetName + '" data-status="' + statusClass + '">' +
                    '<h3 class="facility-name">' + facilityHeader + '</h3>' +
                    '<div class="facility-details">' +
                        facilityLocationYears +
                        '<details class="facility-expanded-info">' +
                            '<summary><span class="closed-text">+ Learn more</span><span class="open-text">- Collapse details</span></summary>' +
                            '<div class="facility-extra-content">' +
                                otherFacilityData +
                                resourcesAvailable +
                            '</div>' +
                        '</details>' +
                    '</div>' +
                '</div>';
        });
        
        html += '</div>' +
            '</details>';
    });
    
    html += '</div>';
    
    container.innerHTML = html;
    
    // Store data globally for filtering
    window.facilitiesData = facilitiesData;
}


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
        
        // Hide operator section if no facilities match
        section.style.display = visibleFacilities > 0 ? 'block' : 'none';
        
        // Update facility count
        const countSpan = section.querySelector('.facility-count');
        if (countSpan) {
            countSpan.textContent = `(${visibleFacilities} facilities)`;
        }
    });
}


// Functions for your template features
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
        if (letter === '') {
            searchInput.value = '';
        } else {
            searchInput.value = letter;
        }
        filterFacilities();
    }
}

function setupEventListeners() {
    // Setup search input
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
    
    // Setup status filter
    const statusFilter = document.getElementById('statusFilter');
    if (statusFilter) {
        statusFilter.addEventListener('change', filterFacilities);
    }
    
    // Setup sort dropdown
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
    
    switch(sortValue) {
        case 'name':
            operatorSections.sort((a, b) => a.dataset.operator.localeCompare(b.dataset.operator));
            break;
        case 'violations-only':
            // Filter to show only facilities with violations (you'll need to add violation data to your JSON)
            operatorSections.forEach(section => {
                const facilities = section.querySelectorAll('.facility-card');
                let hasViolations = false;
                facilities.forEach(facility => {
                    // Check if facility has violations in the resources or add violation indicator
                    const violationText = facility.textContent.toLowerCase();
                    if (violationText.includes('violation') || violationText.includes('violations')) {
                        hasViolations = true;
                    }
                });
                section.style.display = hasViolations ? 'block' : 'none';
            });
            return;
        case 'violations-desc':
            // Sort by most violations (you'll need violation count in your data)
            break;
        case 'recent-inspection':
            // Sort by recent inspection (you'll need inspection dates in your data)
            break;
        default:
            // Default A-Z sort
            operatorSections.sort((a, b) => a.dataset.operator.localeCompare(b.dataset.operator));
    }
    
    // Re-append sorted sections
    operatorSections.forEach(section => container.appendChild(section));
}

function toggleAllFacilityDetails(button) {
    const operatorSection = button.closest('.operator-section');
    const facilityDetails = operatorSection.querySelectorAll('.facility-expanded-info');
    const isExpanding = button.textContent.includes('Expand');
    
    facilityDetails.forEach(detail => {
        detail.open = isExpanding;
    });
    
    button.textContent = isExpanding ? 'Collapse All Facility Details' : 'Expand All Facility Details';
}

// Add this to the end of your facilities-display.js file
document.addEventListener('DOMContentLoaded', function() {
    console.log('Facilities script loaded');

    const facilitiesContainer = document.getElementById('facilities-container');
    if (!facilitiesContainer) {
        console.info('Facilities script: no facilities-container element present, skipping data fetch.');
        return;
    }

    // Setup your template features when the container exists
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
                displayFacilities(data, 'facilities-container');
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
