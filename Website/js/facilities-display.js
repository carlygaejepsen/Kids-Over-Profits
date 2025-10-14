// Facilities Database Display - Drop-in JavaScript
// Usage: Include this file and call displayFacilities(jsonData, containerId)

function displayFacilities(facilitiesData, containerId) {
    const container = document.getElementById(containerId);
    if (!container) {
        console.error('Container not found:', containerId);
        return;
    }
    
    let html = '<div class="facilities-database">';
    
    // Don't inject controls - use the existing ones from the template
    
        // Convert the new JSON structure to work with existing code
        let operatorGroups = [];
        if (facilitiesData.projects) {
            // Handle new JSON structure
            Object.values(facilitiesData.projects).forEach(project => {
                operatorGroups.push({
                    operator: project.data.operator,
                    facilities: project.data.facilities
                });
            });
        } else {
            // Handle old structure (fallback)
            operatorGroups = facilitiesData;
        }
        
        // Sort operators alphabetically by name
        operatorGroups.sort((a, b) => a.operator.name.localeCompare(b.operator.name));
        
        // Generate operator sections
        operatorGroups.forEach(operatorGroup => {
            const operator = operatorGroup.operator;
            const facilities = operatorGroup.facilities;
    

        // Sort facilities alphabetically by name
        facilities.sort((a, b) => a.identification.name.localeCompare(b.identification.name));
        
        // Build operator header with Name - add class for long names or long words
        const hasLongWord = operator.name.split(' ').some(word => word.length > 14);
        const isTooLong = operator.name.length > 25;
        const operatorNameClass = (hasLongWord || isTooLong) ? 'operator-name operator-name-long' : 'operator-name';
        let operatorHeader = `<span class="${operatorNameClass}">${operator.name}</span>`;       
        // Build location and years - each on separate lines
        let locationYearsLine = '';
        const locationLines = [];
        
        if (operator.location && operator.location.trim()) {
            locationLines.push(`<div>${operator.location}</div>`);
        }
        if (operator.headquarters && operator.headquarters.trim()) {
            locationLines.push(`<div>${operator.headquarters}</div>`);
        }
        if (operator.operatingPeriod && operator.operatingPeriod.trim()) {
            locationLines.push(`<div>${operator.operatingPeriod}</div>`);
        } else if (operator.founded && operator.founded.trim()) {
            const endYear = operator.status === 'Defunct' ? 'Defunct' : 'Present';
            locationLines.push(`<div>${operator.founded}-${endYear}</div>`);
        }
        
        if (locationLines.length > 0) {
            locationYearsLine = `<span class="operator-location">${locationLines.join('')}</span>`;
        }
        
        // Build other operator data
        let otherOperatorData = '';
        const operatorFields = [
            { key: 'status', label: 'Status', value: operator.status },
            { key: 'founded', label: 'Founded', value: operator.founded },
            { key: 'parentCompanies', label: 'Parent Companies', value: operator.parentCompanies && operator.parentCompanies.length > 0 ? operator.parentCompanies.join(', ') : null },
            { key: 'keyStaff.founders', label: 'Founders', value: operator.keyStaff && operator.keyStaff.founders && operator.keyStaff.founders.length > 0 ? operator.keyStaff.founders.join(', ') : null },
            { key: 'keyStaff.ceo', label: 'CEO', value: operator.keyStaff && operator.keyStaff.ceo && operator.keyStaff.ceo.trim() ? operator.keyStaff.ceo : null },
            { key: 'websites', label: '', value: operator.websites && operator.websites.length > 0 ? `<a href='${operator.websites[0]}' target='_blank'>Archived website</a>` : null }
        ];
        
        operatorFields.forEach(field => {
            if (field.value && field.value.toString().trim()) {
                otherOperatorData += '<p><strong>' + field.label + ':</strong> ' + field.value + '</p>';
            }
        });
        
        // Always create the div, even if empty
        otherOperatorData = `<div class="operator-details">${otherOperatorData}</div>`;
        
        html += '<details class="operator-section" data-operator="' + operator.name + '">' +
                '<summary class="operator-header">' +
                    operatorHeader +
                    locationYearsLine +
                '</summary>' +
                '<div class="operator-content-scrollable">' +
                    otherOperatorData;
        
        facilities.forEach(facility => {
            const statusClass = facility.operatingPeriod.status ? facility.operatingPeriod.status.toLowerCase() : 'unknown';
            
            // Build facility header with Name
            let facilityHeader = facility.identification.name;
            
            // Build location, years, and status - each on its own line
            let facilityLocationYears = '';
            
            if (facility.location && facility.location.trim()) {
                facilityLocationYears += `<p>${facility.location}</p>`;
            }
            if (facility.address && facility.address.trim()) {
                facilityLocationYears += `<p>${facility.address}</p>`;
            }
            
            // Years of operation
            if (facility.operatingPeriod.startYear) {
                const endYear = facility.operatingPeriod.endYear ? facility.operatingPeriod.endYear : 'Present';
                const yearRange = facility.operatingPeriod.startYear + '-' + endYear;
                facilityLocationYears += `<p>${yearRange}</p>`;
            }
            
            // Add status on its own line
            if (facility.operatingPeriod.status) {
                facilityLocationYears += `<p><span class="status-badge status-${statusClass}">${facility.operatingPeriod.status}</span></p>`;
            }
            
            // Build other facility data
            let otherFacilityData = '';
            const facilityFields = [
                { key: 'facilityDetails.type', label: 'Type', value: facility.facilityDetails.type },
                { key: 'facilityDetails.capacity', label: 'Capacity', value: facility.facilityDetails.capacity },
                { key: 'facilityDetails.ageRange', label: 'Age Range', value: facility.facilityDetails.ageRange && (facility.facilityDetails.ageRange.min || facility.facilityDetails.ageRange.max) ? `${facility.facilityDetails.ageRange.min || '?'}-${facility.facilityDetails.ageRange.max || '?'}` : null },
                { key: 'facilityDetails.gender', label: 'Gender', value: facility.facilityDetails.gender },
                { key: 'identification.pastNames', label: 'Past Names', value: facility.identification.pastNames && facility.identification.pastNames.length > 0 ? facility.identification.pastNames.join(', ') : null },
                { key: 'staff.administrator', label: 'Administrator', value: facility.staff.administrator && facility.staff.administrator.length > 0 ? facility.staff.administrator.join(', ') : null },
                { key: 'accreditations.current', label: 'Current Accreditations', value: facility.accreditations.current && facility.accreditations.current.length > 0 ? facility.accreditations.current.join(', ') : null },
                { key: 'accreditations.past', label: 'Past Accreditations', value: facility.accreditations.past && facility.accreditations.past.length > 0 ? facility.accreditations.past.join(', ') : null },
                { key: 'memberships', label: 'Memberships', value: facility.memberships && facility.memberships.length > 0 ? facility.memberships.filter(m => m && m.trim()).join(', ') : null },
                { key: 'licensing', label: 'Licensing', value: facility.licensing && facility.licensing.length > 0 ? facility.licensing.filter(l => l && l.trim()).join(', ') : null },
                { key: 'profileLinks', label: '', value: facility.profileLinks && facility.profileLinks.length > 0 ? `<a href='${facility.profileLinks[0]}' target='_blank'>Archived website</a>` : null }
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
            
            html += '<div class="facility-card status-' + statusClass + '" data-facility="' + facility.identification.name + '" data-status="' + statusClass + '">' +
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
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const statusFilter = document.getElementById('statusFilter').value;
    
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
    document.getElementById('searchInput').value = '';
    document.getElementById('statusFilter').value = '';
    document.getElementById('sortBy').value = '';
    document.getElementById('clearSearch').style.display = 'none';
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
    if (letter === '') {
        searchInput.value = '';
    } else {
        searchInput.value = letter;
    }
    filterFacilities();
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
    const sortValue = document.getElementById('sortBy').value;
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
    
    // Setup your template features
    setupAlphabetFilter();
    setupEventListeners();
    
    // Direct path to your JSON file
    const jsonPath = '/wp-content/themes/child/js/data/facility-projects-export-2025-10-02.json';
    
    console.log('Loading data from:', jsonPath);
    
    fetch(jsonPath)
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to load JSON: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('Data loaded successfully:', data);
            displayFacilities(data, 'facilities-container');
        })
        .catch(error => {
            console.error('Error loading facilities data:', error);
            const container = document.getElementById('facilities-container');
            if (container) {
                container.innerHTML = '<p>Error loading facilities data: ' + error.message + '</p>';
            }
        });
});