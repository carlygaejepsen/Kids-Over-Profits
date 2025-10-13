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
    
    if (searchInput) {
        searchInput.addEventListener('input', filterAndSort);
    }
    if (sortSelect) {
        sortSelect.addEventListener('change', filterAndSort);
    }
    
    async function initializeReport() {
        try {
            console.log('Starting to initialize Arizona report...');
            const urls = myThemeData.jsonFileUrls;
            console.log('URLs to fetch:', urls);

            if (!urls || urls.length === 0) {
                reportContainer.innerHTML = '<p>No data files found to load.</p>';
                return;
            }

            // Fetch all JSON files concurrently
            const fetchPromises = urls.map(url => fetch(url));
            const responses = await Promise.all(fetchPromises);
            const jsonPromises = responses.map(response => response.ok ? response.json() : Promise.resolve(null));
            const allJsonData = await Promise.all(jsonPromises);

            // Filter out failed requests and combine data
            const rawReports = allJsonData.filter(data => data !== null);
            console.log(`Loaded ${rawReports.length} inspection reports`);
            
            // Group reports by facility
            const aggregatedFacilities = aggregateReportsIntoFacilities(rawReports);
            allFacilitiesData = groupFacilitiesFromArray(aggregatedFacilities);
            console.log('Processed facilities data:', allFacilitiesData);
            console.log('Available letters:', Object.keys(allFacilitiesData));
            
            renderAlphabetFilter();
            const firstLetter = Object.keys(allFacilitiesData).sort()[0];
            if (firstLetter) {
                renderFacilitiesForLetter(firstLetter);
            } else {
                reportContainer.innerHTML = '<p>No facilities found in the data.</p>';
            }
        } catch (error) {
            console.error('Failed to load or process report data:', error);
            reportContainer.innerHTML = `<p class="error">Error loading data: ${error.message}</p>`;
        }
    }

    // Helper function to check if inspection has violations
    function inspectionHasViolations(inspection) {
        // Check if deficiencies exist and contain actual violation content
        if (!inspection.deficiencies || !Array.isArray(inspection.deficiencies) || inspection.deficiencies.length === 0) {
            return false;
        }
        
        // Check if any deficiency has actual violation content
        for (let deficiency of inspection.deficiencies) {
            if (deficiency && typeof deficiency === 'object') {
                if (deficiency.rule || deficiency.evidence || deficiency.findings) {
                    return true;
                }
            }
        }
        
        return false;
    }

    // --- AGGREGATES RAW REPORTS INTO FACILITY GROUPS ---
    function aggregateReportsIntoFacilities(reports) {
        const facilities = reports.reduce((acc, report) => {
            const licenseNum = report.license_number;
            if (!licenseNum) { return acc; }

            if (!acc[licenseNum]) {
                acc[licenseNum] = {
                    license_number: licenseNum,
                    name: report.legal_name || `License #${licenseNum}`,
                    facility_status: report.facility_status,
                    license_status: report.license_status,
                    address: report.address,
                    phone: report.phone,
                    max_capacity: report.max_licensed_capacity,
                    license_effective_date: report.license_effective_date,
                    license_expires_date: report.license_expires_date,
                    chief_officer: report.chief_administrative_officer,
                    owner_licensee: report.owner_licensee,
                    inspections: []
                };
            }
            
            // Add this inspection to the facility
            acc[licenseNum].inspections.push({
                inspection_number: report.inspection_number,
                inspection_date: report.inspection_date,
                inspection_type: report.inspection_type,
                certificate_number: report.certificate_number,
                deficiencies: report.deficiencies || []
            });
            
            return acc;
        }, {});
        
        // Sort inspections within each facility by date (most recent first)
        Object.values(facilities).forEach(facility => {
            facility.inspections.sort((a, b) => {
                const dateA = new Date(a.inspection_date || 0);
                const dateB = new Date(b.inspection_date || 0);
                return dateB - dateA; // Most recent first
            });
        });
        
        return Object.values(facilities);
    }

    // --- GROUPS THE FINALIZED FACILITIES BY FIRST LETTER ---
    function groupFacilitiesFromArray(facilitiesArray) {
        const grouped = facilitiesArray.reduce((acc, facility) => {
            const name = facility.name || '';
            const firstLetter = name.charAt(0).toUpperCase();
            const groupKey = (firstLetter >= 'A' && firstLetter <= 'Z') ? firstLetter : '#';

            if (!acc[groupKey]) { acc[groupKey] = []; }
            acc[groupKey].push(facility);
            return acc;
        }, {});

        for (const letter in grouped) {
            grouped[letter].sort((a, b) => (a.name || '').localeCompare(b.name || ''));
        }
        return grouped;
    }

    // --- RENDER FUNCTIONS ---
    function renderAlphabetFilter() {
        const letters = Object.keys(allFacilitiesData).sort();
        alphabetFilter.innerHTML = letters.map(letter => `<a href="#" data-letter="${letter}">${letter}</a>`).join('');
        alphabetFilter.addEventListener('click', (e) => {
            e.preventDefault();
            if (e.target.tagName === 'A') {
                const letter = e.target.dataset.letter;
                renderFacilitiesForLetter(letter);
            }
        });
    }

    function renderFacilitiesForLetter(letter) {
        if (isSearching) {
            return; // Don't change letter selection while searching
        }
        
        currentLetter = letter;
        document.querySelectorAll('#alphabet-filter a').forEach(a => {
            a.classList.toggle('active', a.dataset.letter === letter);
        });
        
        const facilities = allFacilitiesData[letter];
        const sortBy = sortSelect ? sortSelect.value : '';
        const sortedFacilities = sortFacilities(facilities || [], sortBy);
        renderFilteredFacilities(sortedFacilities, letter);
    }

    function filterAndSort() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const sortBy = sortSelect ? sortSelect.value : '';
        
        if (!allFacilitiesData || Object.keys(allFacilitiesData).length === 0) {
            return;
        }
        
        if (searchTerm) {
            isSearching = true;
            if (clearButton) clearButton.style.display = 'inline-block';
            
            document.querySelectorAll('#alphabet-filter a').forEach(a => {
                a.classList.remove('active');
            });
            
            let allFacilities = [];
            Object.values(allFacilitiesData).forEach(letterGroup => {
                allFacilities = allFacilities.concat(letterGroup);
            });
            
            const filteredFacilities = allFacilities.filter(facility => {
                const name = (facility.name || '').toLowerCase();
                const licenseNumber = (facility.license_number || '').toLowerCase();
                const address = (facility.address || '').toLowerCase();
                const owner = (facility.owner_licensee || '').toLowerCase();
                
                return name.includes(searchTerm) || 
                       licenseNumber.includes(searchTerm) || 
                       address.includes(searchTerm) ||
                       owner.includes(searchTerm);
            });
            
            const sortedFacilities = sortFacilities(filteredFacilities, sortBy);
            renderFilteredFacilities(sortedFacilities, 'Search Results');
            
        } else {
            isSearching = false;
            if (clearButton) clearButton.style.display = 'none';
            
            if (currentLetter && allFacilitiesData[currentLetter]) {
                document.querySelectorAll('#alphabet-filter a').forEach(a => {
                    a.classList.toggle('active', a.dataset.letter === currentLetter);
                });
                
                const facilities = allFacilitiesData[currentLetter];
                const sortedFacilities = sortFacilities(facilities, sortBy);
                renderFilteredFacilities(sortedFacilities, currentLetter);
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
        
        if (sortBy === 'violations-only' || sortBy === 'violations-desc') {
            processedFacilities = facilities.map(facility => {
                const violationInspections = facility.inspections.filter(inspectionHasViolations);
                
                if (violationInspections.length > 0) {
                    return {
                        ...facility,
                        inspections: violationInspections
                    };
                }
                return null;
            }).filter(facility => facility !== null);
        }
        
        return processedFacilities.sort((a, b) => {
            switch(sortBy) {
                case 'name':
                    return (a.name || '').localeCompare(b.name || '');
                
                case 'violations-only':
                    return (a.name || '').localeCompare(b.name || '');
                
                case 'violations-desc':
                    const aViolations = countViolations(a);
                    const bViolations = countViolations(b);
                    return bViolations - aViolations;
                
                case 'recent-inspection':
                    const aDate = getMostRecentInspectionDate(a);
                    const bDate = getMostRecentInspectionDate(b);
                    return bDate - aDate;
                
                default:
                    return 0;
            }
        });
    }

    function countViolations(facility) {
        if (!facility.inspections) return 0;
        return facility.inspections.reduce((total, inspection) => {
            return total + (inspection.deficiencies ? inspection.deficiencies.length : 0);
        }, 0);
    }

    function getMostRecentInspectionDate(facility) {
        if (!facility.inspections || facility.inspections.length === 0) {
            return new Date(0);
        }
        
        const dates = facility.inspections
            .map(inspection => new Date(inspection.inspection_date || 0))
            .filter(date => !isNaN(date.getTime()));
        
        return dates.length > 0 ? Math.max(...dates) : new Date(0);
    }

    function renderFilteredFacilities(facilities, context) {
        reportContainer.innerHTML = '';
        
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
            facilityElement.innerHTML = `
                <details>
                    <summary class="facility-header">
                        <h1>${toTitleCase(facility.name) || 'N/A'}</h1>
                        <h2>License Number: ${facility.license_number || 'N/A'}</h2>
                        <p class="facility-details">
                            Status: ${facility.facility_status || 'N/A'} | 
                            License Status: ${facility.license_status || 'N/A'} | 
                            Max Capacity: ${facility.max_capacity || 'N/A'}<br>
                            Address: ${facility.address || 'N/A'}<br>
                            Administrator: ${toTitleCase(facility.chief_officer) || 'N/A'} | 
                            Owner/Licensee: ${toTitleCase(facility.owner_licensee) || 'N/A'}
                        </p>
                    </summary>
                    <div class="inspections-container">
                        ${(facility.inspections || []).map(createInspectionHTML).join('')}
                    </div>
                </details>
            `;
            reportContainer.appendChild(facilityElement);
        });
    }

    function toTitleCase(str) {
        if (!str) return str;
        
        const acronyms = [
            'LLC', 'LLP', 'CORP', 'CO', 'LTD', 
        'MD', 'RN', 'LPN', 'LCSW', 'LPA', 'LPCC', 'LISW', 'MSW', 'BSW', 
        'RD', 'CNA', 'CMA', 'EMT', 'LVN', 'DON', 
        'CEO', 'COO', 'CFO', 'HR', 'IT', 'VP', 'AVP',
        'FDA', 'CDC', 'CMS', 'HHS', 'DOH', 'OSHA', 'JCAHO',
        'USA', 'US', 'ID', 'SSN', 'THP', 'FC', 'STRTP', 'ARS', 'TRSCF', 'OC', 'TLC', '3R', 'BCFS', 'SFH', 'THPP', 'III', 'II', 'AZ', 'PRTF', 'QPRTF', 'RTC', 'LWB'
        ];
        
   // Words that should always be lowercase (except when first word)
    const lowercaseWords = [
        'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'if', 'in', 
        'of', 'on', 'or', 'the', 'to', 'up', 'via', 'with', 'from',
        'into', 'onto', 'upon', 'over', 'under', 'above', 'below', 'is'
    ];
    
    // Special name patterns that need custom capitalization
    const specialNames = {
        'mcdonald': 'McDonald',
        'mcdonalds': "McDonald's",
        'mcdowell': 'McDowell',
        'mccarthy': 'McCarthy',
        'obrien': "O'Brien",
        'oconnor': "O'Connor",
        'osullivan': "O'Sullivan",
        'mcalister': "McAlister",
        'mckays': "McKay's",
        'mckinley': 'McKinley',
        'emoticare': 'EmotiCare',
    };
    
    const words = str.toLowerCase().split(/(\s+|-|\/)/);
    
    return words.map((word, index) => {
        // Skip whitespace, hyphens, slashes
        if (/^\s+$|^-$|^\/$/.test(word)) {
            return word;
        }
        
        // First word is always capitalized
        if (index === 0) {
            return capitalizeWord(word);
        }
        
        // Check if word should be lowercase
        if (lowercaseWords.includes(word.toLowerCase())) {
            return word.toLowerCase();
        }
        
        return capitalizeWord(word);
    }).join('');
    
    function capitalizeWord(word) {
        const lowerWord = word.toLowerCase();
        
        // Handle special names
        if (specialNames[lowerWord]) {
            return specialNames[lowerWord];
        }
        
        // Handle acronyms
        if (acronyms.includes(word.toUpperCase())) {
            return word.toUpperCase();
        }
        
        // Handle dotted acronyms
        if (/^[a-z](\.[a-z])+\.?$/i.test(word)) {
            return word.toUpperCase();
        }
        
        // Regular title case
        return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
    }
}
    
    function createInspectionHTML(inspection) {
        const hasViolations = inspectionHasViolations(inspection);
        const inspectionClass = hasViolations ? 'inspection-box-violation' : 'inspection-box-clean';
        
        return `
        <details class="inspection-box ${inspectionClass}">
            <summary class="inspection-header">
                ${inspection.inspection_type || 'Inspection'} - ${inspection.inspection_date || 'N/A'}
            </summary>
            <div class="inspection-content">
                <div class="inspection-details-block">
                    <strong>Inspection Date:</strong> ${inspection.inspection_date || 'N/A'}<br>
                    <strong>Inspection Type:</strong> ${inspection.inspection_type || 'N/A'}<br>
                    <strong>Inspection Number:</strong> ${inspection.inspection_number || 'N/A'}<br>
                    <strong>Certificate Number:</strong> ${inspection.certificate_number || 'N/A'}
                </div>
                <h4>Deficiencies:</h4>
                ${hasViolations ? inspection.deficiencies?.map((def, index) => createDeficiencyHTML(def, index)).join('') || '<div class="no-violations"><p><strong>Violations found but no deficiency details available.</strong></p></div>' : '<div class="no-violations"><p><strong>No violations noted in this inspection.</strong></p></div>'}
            </div>
        </details>
        `;
    }

    function createDeficiencyHTML(deficiency, index) {
        const rule = (deficiency.rule || '').replace(/\n/g, '<br>');
        const evidence = (deficiency.evidence || '').replace(/\n/g, '<br>');
        const findings = (deficiency.findings || '').replace(/\n/g, '<br>');
        
        return `
            <details class="violation-box">
                <summary class="deficiency-header">Deficiency ${index + 1}</summary>
                <div class="deficiency-content">
                    <strong>Rule:</strong><p>${rule || 'N/A'}</p>
                    <strong>Evidence:</strong><p>${evidence || 'N/A'}</p>
                    <strong>Findings:</strong><p>${findings || 'N/A'}</p>
                </div>
            </details>
        `;
    }
    
    initializeReport();
});