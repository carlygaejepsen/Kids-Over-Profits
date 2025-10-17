document.addEventListener('DOMContentLoaded', () => {
    // --- GET HTML ELEMENTS ---
    const reportContainer = document.getElementById('report-container');
    const alphabetFilter = document.getElementById('alphabet-filter');
    let allFacilitiesData = {};
    const searchInput = document.getElementById('searchInput');
    const sortSelect = document.getElementById('sortBy');
    let currentLetter = null;
    let isSearching = false;
    const clearButton = document.getElementById('clearSearch');
    let facilitiesArray = [];

    // Check if elements exist
    if (!reportContainer) {
        console.error('report-container element not found!');
        return;
    }

    if (searchInput) {
        searchInput.addEventListener('input', filterAndSort);
    }
    if (sortSelect) {
        sortSelect.addEventListener('change', filterAndSort);
    }

    const themeData = window.txReportsData || window.myThemeData || {};

    async function initializeReport() {
        try {
            console.log('Starting to initialize Texas report...');

            // Check for container before proceeding
            if (!reportContainer) {
                console.error('Report container not found');
                return;
            }

            // Get URLs from WordPress theme data
            const urls = Array.isArray(themeData.jsonFileUrls) ? themeData.jsonFileUrls : [];
            console.log('URLs to fetch:', urls);

            if (!urls || urls.length === 0) {
                reportContainer.innerHTML = '<p>No data files found to load.</p>';
                return;
            }

            // Find the Texas reports file
            const reportsUrl = urls.find(url => url.includes('tx_reports.json'));
            
            if (!reportsUrl) {
                reportContainer.innerHTML = '<p>Texas reports data file not found.</p>';
                return;
            }

            console.log('Loading reports from:', reportsUrl);
            
            // Load reports data
            const reportsResponse = await fetch(reportsUrl);
            if (!reportsResponse.ok) {
                throw new Error('Failed to load reports: ' + reportsResponse.status);
            }
            const reportsData = await reportsResponse.json();
            console.log('Loaded reports data:', reportsData);

            // Convert Texas data structure to facility array
            facilitiesArray = convertTexasDataToFacilities(reportsData);
            console.log('Converted to', facilitiesArray.length, 'facilities');

            if (facilitiesArray.length === 0) {
                reportContainer.innerHTML = '<p>No facilities found in the data.</p>';
                return;
            }

            // Group facilities by first letter
            allFacilitiesData = groupFacilitiesByLetter(facilitiesArray);
            console.log('Grouped facilities by letter:', Object.keys(allFacilitiesData));

            // Render alphabet filter and show first letter
            renderAlphabetFilter();
            const firstLetter = Object.keys(allFacilitiesData).sort()[0];
            if (firstLetter) {
                renderFacilitiesForLetter(firstLetter);
            }

        } catch (error) {
            console.error('Failed to load or process report data:', error);
            if (reportContainer) {
                reportContainer.innerHTML = '<p class="error">Error loading data: ' + error.message + '</p>';
            }
        }
    }

    function convertTexasDataToFacilities(reportsData) {
        const facilities = [];
        
        // Handle different possible data structures
        let dataToProcess = reportsData;
        
        // If the data has a "facilities" or similar wrapper, unwrap it
        if (reportsData.facilities) {
            dataToProcess = reportsData.facilities;
        } else if (reportsData.data) {
            dataToProcess = reportsData.data;
        } else if (reportsData.response) {
            dataToProcess = reportsData.response;
        }
        
        // If it's an array, process each facility
        if (Array.isArray(dataToProcess)) {
            dataToProcess.forEach(facility => {
                // Construct full address from components
                const addressParts = [];
                if (facility['Address']) addressParts.push(facility['Address']);
                if (facility['City']) addressParts.push(facility['City']);
                if (facility['State']) addressParts.push(facility['State']);
                if (facility['Zip']) addressParts.push(facility['Zip']);
                const fullAddress = addressParts.length > 0 ? addressParts.join(', ') : 'Address not available';

                const facilityObj = {
                    providerNum: facility['Operation #'] || facility.operation_num || facility.id || 'Unknown',
                    facility_name: facility['Operation/Caregiver Name'] || facility.facility_name || facility.name || `Operation #${facility['Operation #'] || 'Unknown'}`,
                    facility_type: facility['Type'] || facility.facility_type || facility.type || 'Unknown',
                    facility_address: fullAddress,
                    city: facility['City'] || facility.city || '',
                    county: facility['County'] || facility.county || facility.countyName || '',
                    capacity: facility['Capacity'] || facility.capacity || null,
                    ages_served: facility['Ages Served'] || facility.ages_served || facility.agesServed || '',
                    gender_served: facility['Genders '] || facility['Genders'] || facility.gender_served || facility.genderServed || '', // Note the space in 'Genders '
                    phone: facility['Phone'] || facility.phone || '',
                    status: facility['Status'] || facility.status || '',
                    issue_date: facility['Issue Date'] || facility.issue_date || '',
                    deficiencies: facility['Deficiencies'] || facility.deficiencies || 0,
                    citations: facility.citations || facility.violations || [],
                    citation_count: (facility.citations || facility.violations || []).length
                };
                facilities.push(facilityObj);
            });
        } 
        // If it's an object with provider numbers as keys (original expected format)
        else if (typeof dataToProcess === 'object') {
            for (const [key, value] of Object.entries(dataToProcess)) {
                // Skip if this looks like a metadata key
                if (key === 'metadata' || key === 'info' || key === 'summary') {
                    continue;
                }
                
                const facility = {
                    providerNum: key,
                    facility_name: `Provider #${key}`,
                    facility_type: 'Unknown',
                    facility_address: 'Address not available',
                    city: '',
                    county: '',
                    capacity: null,
                    ages_served: '',
                    gender_served: '',
                    citations: Array.isArray(value) ? value : [],
                    citation_count: Array.isArray(value) ? value.length : 0
                };
                
                facilities.push(facility);
            }
        }
        
        return facilities;
    }

    function groupFacilitiesByLetter(facilities) {
        const grouped = {};
        
        facilities.forEach(facility => {
            const name = facility.facility_name || '';
            const firstLetter = name.charAt(0).toUpperCase();
            const groupKey = (firstLetter >= 'A' && firstLetter <= 'Z') ? firstLetter : '#';
            
            if (!grouped[groupKey]) {
                grouped[groupKey] = [];
            }
            grouped[groupKey].push(facility);
        });
        
        // Sort facilities within each letter group
        for (const letter in grouped) {
            grouped[letter].sort((a, b) => {
                const nameA = a.facility_name || '';
                const nameB = b.facility_name || '';
                return nameA.localeCompare(nameB);
            });
        }
        
        return grouped;
    }

    function renderAlphabetFilter() {
        if (!alphabetFilter) return;
        
        const letters = Object.keys(allFacilitiesData).sort();
        alphabetFilter.innerHTML = letters.map(letter => 
            `<a href="#" data-letter="${letter}">${letter}</a>`
        ).join('');
        
        alphabetFilter.addEventListener('click', (e) => {
            e.preventDefault();
            if (e.target.tagName === 'A') {
                const letter = e.target.dataset.letter;
                renderFacilitiesForLetter(letter);
            }
        });
    }

    function renderFacilitiesForLetter(letter) {
        if (isSearching) return;
        
        currentLetter = letter;
        
        // Update active state
        document.querySelectorAll('#alphabet-filter a').forEach(a => {
            a.classList.toggle('active', a.dataset.letter === letter);
        });
        
        const facilities = allFacilitiesData[letter] || [];
        const sortBy = sortSelect ? sortSelect.value : '';
        const sortedFacilities = sortFacilities(facilities, sortBy);
        renderFilteredFacilities(sortedFacilities, letter);
    }

    function filterAndSort() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const sortBy = sortSelect ? sortSelect.value : '';
        
        if (searchTerm) {
            isSearching = true;
            if (clearButton) clearButton.style.display = 'inline-block';
            
            // Remove active state from alphabet
            document.querySelectorAll('#alphabet-filter a').forEach(a => {
                a.classList.remove('active');
            });
            
            // Search all facilities
            const filteredFacilities = facilitiesArray.filter(facility => {
                const name = (facility.facility_name || '').toLowerCase();
                const type = (facility.facility_type || '').toLowerCase();
                const num = (facility.providerNum || '').toString();
                const address = (facility.facility_address || '').toLowerCase();
                
                return name.includes(searchTerm) || 
                       type.includes(searchTerm) || 
                       num.includes(searchTerm) ||
                       address.includes(searchTerm);
            });
            
            const sortedFacilities = sortFacilities(filteredFacilities, sortBy);
            renderFilteredFacilities(sortedFacilities, 'Search Results');
            
        } else {
            isSearching = false;
            if (clearButton) clearButton.style.display = 'none';
            
            // Return to letter view
            if (currentLetter && allFacilitiesData[currentLetter]) {
                renderFacilitiesForLetter(currentLetter);
            } else {
                const firstLetter = Object.keys(allFacilitiesData).sort()[0];
                if (firstLetter) {
                    renderFacilitiesForLetter(firstLetter);
                }
            }
        }
    }

    function clearSearch() {
        if (searchInput) searchInput.value = '';
        if (sortSelect) sortSelect.value = '';
        filterAndSort();
    }
    
    window.clearSearch = clearSearch;

    function sortFacilities(facilities, sortBy) {
        if (!sortBy) return facilities;
        
        let processedFacilities = [...facilities];
        
        if (sortBy === 'violations-only') {
            processedFacilities = facilities.filter(facility => 
                facility.citations && facility.citations.length > 0
            );
        }
        
        return processedFacilities.sort((a, b) => {
            switch(sortBy) {
                case 'violations-only':
                    return (a.facility_name || '').localeCompare(b.facility_name || '');
                
                case 'violations-desc':
                    return (b.citation_count || 0) - (a.citation_count || 0);
                
                case 'recent-inspection':
                    const aDate = getMostRecentCitationDate(a);
                    const bDate = getMostRecentCitationDate(b);
                    return bDate - aDate;
                
                default:
                    return (a.facility_name || '').localeCompare(b.facility_name || '');
            }
        });
    }

    function getMostRecentCitationDate(facility) {
        if (!facility.citations || facility.citations.length === 0) {
            return new Date(0);
        }
        
        const dates = facility.citations
            .map(citation => new Date(citation['Citation Date'] || citation.date || 0))
            .filter(date => !isNaN(date.getTime()));
        
        return dates.length > 0 ? Math.max(...dates) : new Date(0);
    }

    function toTitleCase(str) {
        if (!str) return str;
        
        const acronyms = [
            'LLC', 'LLP', 'CORP', 'CO', 'LTD', 'MD', 'RN', 'LPN', 'LCSW', 'LPA', 'LPCC', 'LISW', 'MSW', 'BSW', 'DBA', 
            'RD', 'CNA', 'CMA', 'EMT', 'LVN', 'DON', 'CEO', 'COO', 'CFO', 'HR', 'IT', 'VP', 'AVP', 'FDA', 'CDC', 
            'CMS', 'HHS', 'DOH', 'OSHA', 'JCAHO', 'USA', 'US', 'UT', 'ID', 'SSN', 'CCL', 'PRTF', 'DBA', 'QPRTF', 'RMBHS', 
            'ABA', 'RTC', 'STRTP', 'RT', 'DT', 'OT', 'SD'
        ];
        const lowercaseWords = [
            'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'if', 'in', 'of', 'on', 'or', 'the', 'to', 'up', 'via', 
            'with', 'from', 'into', 'onto', 'upon', 'over', 'under', 'above', 'below', 'is'
        ];
        const specialNames = {
            'mcdonald': 'McDonald', 'mcdonalds': "McDonald's", 'mcdowell': 'McDowell', 'mccarthy': 'McCarthy', 
            'obrien': "O'Brien", 'oconnor': "O'Connor", 'osullivan': "O'Sullivan", 'mcalister': "McAlister", 
            'mckays': "McKay's", 'mckinley': 'McKinley', "mckay's": "McKay's", 'mckee': 'McKee', 'redcliff': 'RedCliff'
        };

        return str.split(' ').map((word, index) => {
            const lowerWord = word.toLowerCase();
            const cleanWord = word.replace(/[^\w']/g, '').toLowerCase();
            
            // Check for special names first
            if (specialNames[cleanWord]) {
                return word.replace(new RegExp(cleanWord, 'i'), specialNames[cleanWord]);
            }
            
            // Check for acronyms
            if (acronyms.includes(word.toUpperCase())) {
                return word.toUpperCase();
            }
            
            // Keep lowercase words lowercase unless they're the first word
            if (index > 0 && lowercaseWords.includes(lowerWord)) {
                return lowerWord;
            }
            
            // Default title case
            return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
        }).join(' ');
    }

    function renderFilteredFacilities(facilities, context) {
        if (!reportContainer) return;
        
        reportContainer.innerHTML = '';
        reportContainer.className = 'facility-report-container';
        
        if (!facilities || facilities.length === 0) {
            const message = isSearching ? 
                'No facilities found matching your search.' : 
                `No facilities found for the letter "${context}".`;
            reportContainer.innerHTML = `<p>${message}</p>`;
            return;
        }

        if (isSearching) {
            const resultsHeader = document.createElement('div');
            resultsHeader.style.cssText = 'margin-bottom: 20px; padding: 10px; background: #e8f4f8; border-radius: 4px; font-weight: bold;';
            resultsHeader.innerHTML = `Found ${facilities.length} facilities matching your search`;
            reportContainer.appendChild(resultsHeader);
        }

        facilities.forEach(facility => {
            const facilityElement = document.createElement('div');
            facilityElement.className = 'facility-box';
            
            // Build extra details
            let extraDetails = [];
            if (facility.facility_type) extraDetails.push(`Type: ${facility.facility_type}`);
            if (facility.capacity) extraDetails.push(`Capacity: ${facility.capacity}`);
            if (facility.ages_served) extraDetails.push(`Ages: ${facility.ages_served}`);
            if (facility.gender_served) extraDetails.push(`Gender: ${facility.gender_served}`);
            
            facilityElement.innerHTML = `
                <details>
                    <summary class="facility-header">
                        <h1>${facility.facility_name || 'N/A'}</h1>
                        <h2>Facility Number: ${facility.providerNum}</h2>
                        <p class="facility-details">${extraDetails.join(' | ')}</p>
                        <p class="facility-details">Address: ${toTitleCase(facility.facility_address) || 'N/A'}</p>
                    </summary>
                    <div class="inspections-container">
                        ${facility.citations && facility.citations.length > 0 ? 
                            facility.citations.map(createCitationHTML).join('') : 
                            '<div class="no-violations"><p><strong>No violations noted for this facility.</strong></p></div>'}
                    </div>
                </details>
            `;
            
            reportContainer.appendChild(facilityElement);
        });
    }

    function createCitationHTML(citation) {
        const riskLevel = citation['Standard Risk Level'] || 'Unknown';
        const riskClass = getRiskLevelClass(riskLevel);
        const corrected = citation['Corrected at Inspection'] === 'Yes';
        
        // Use inspection-box-violation for violations, inspection-box-clean for others
        const hasViolation = citation['Deficiency Narrative'] && citation['Deficiency Narrative'].trim();
        const boxClass = hasViolation ? 'inspection-box-violation' : 'inspection-box-clean';
        
        return `
            <details class="inspection-box ${boxClass}">
                <summary class="inspection-header">
                    ${citation['Citation Date'] || 'N/A'} - ${citation['Standard Number / Description'] || 'Citation'}
                </summary>
                <div class="inspection-content">
                    <div class="inspection-details-block">
                        <strong>Citation Date:</strong> ${citation['Citation Date'] || 'N/A'}<br>
                        <strong>Standard Number / Description:</strong> ${citation['Standard Number / Description'] || 'N/A'}<br>
                        <strong>Category:</strong> ${citation['Category'] || 'N/A'}<br>
                        <strong>Sections Violated:</strong> ${citation['Sections Violated'] || 'N/A'}<br>
                        <strong>Standard Risk Level:</strong> ${riskLevel}<br>
                        <strong>Corrected at Inspection:</strong> ${citation['Corrected at Inspection'] || 'N/A'}<br>
                        ${citation['Date Correction Evaluated'] ? `<strong>Date Correction Evaluated:</strong> ${citation['Date Correction Evaluated']}<br>` : ''}
                    </div>
                    
                    ${citation['Deficiency Narrative'] ? `
                        <div class="narrative-section">
                            <h4>Deficiency Narrative:</h4>
                            <p>${citation['Deficiency Narrative']}</p>
                        </div>
                    ` : ''}
                    
                    ${citation['Correction Narrative'] ? `
                        <div class="narrative-section">
                            <h4>Correction Narrative:</h4>
                            <p>${citation['Correction Narrative']}</p>
                        </div>
                    ` : ''}
                    
                    ${!hasViolation ? `
                        <div class="no-violations">
                            <p><strong>No violations noted in this inspection.</strong></p>
                        </div>
                    ` : ''}
                </div>
            </details>
        `;
    }

    function getRiskLevelClass(riskLevel) {
        const level = riskLevel.toLowerCase();
        if (level.includes('high')) return 'risk-high';
        if (level.includes('medium high')) return 'risk-medium-high';
        if (level.includes('medium')) return 'risk-medium';
        if (level.includes('low')) return 'risk-low';
        return 'risk-unknown';
    }

    // Initialize the report
    initializeReport();
});