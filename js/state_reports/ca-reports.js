// California Reports JavaScript - Last deployed: 2025-01-15
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
            console.log('Starting to initialize report...');
            // It now gets the ARRAY of URLs from the myThemeData object
            const urls = myThemeData.jsonFileUrls;
            console.log('URLs to fetch:', urls);

            if (!urls || urls.length === 0) {
                reportContainer.innerHTML = '<p>No data files found to load.</p>';
                return;
            }

            // Fetch all 21 files concurrently for better performance
            const fetchPromises = urls.map(url => fetch(url));
            const responses = await Promise.all(fetchPromises);
            const jsonPromises = responses.map(response => response.ok ? response.json() : Promise.resolve([]));
            const allJsonData = await Promise.all(jsonPromises);

            // Combine the data from all files into one single list
            const rawReports = allJsonData.flat();
            console.log(`Loaded ${rawReports.length} raw reports`);
            
            // The rest of the logic remains the same
            const aggregatedFacilities = aggregateReportsIntoFacilities(rawReports);
            console.log(`Aggregated into ${aggregatedFacilities.length} facilities`);
            
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
            reportContainer.innerHTML = `<p class="error">Error processing data. Please check the console.</p>`;
        }
    }

    // --- AGGREGATES RAW REPORTS INTO FACILITY GROUPS ---
    function aggregateReportsIntoFacilities(reports) {
        const facilities = reports.reduce((acc, report) => {
            const num = report.facility_number;
            if (!num) { return acc; }
    
            if (!acc[num]) {
                acc[num] = {
                    number: num,
                    name: report.facility_name || `Facility #${num}`,
                    facility_type: report.facility_type_name,
                    officer: report.administrator,
                    capacity: report.capacity,
                    inspections: []
                };
            }
            acc[num].inspections.push(report);
            return acc;
        }, {});
        
        // Sort inspections within each facility by date (most recent first)
        Object.values(facilities).forEach(facility => {
            facility.inspections.sort((a, b) => {
                const dateA = new Date(a.visit_date || 0);
                const dateB = new Date(b.visit_date || 0);
                return dateB - dateA; // Most recent first
            });
        });
        
        return Object.values(facilities);
    }
   
    // Helper function to check if inspection has violations (same logic as orange/white boxes)
    function inspectionHasViolations(inspection) {
        // If no deficiencies array, no violations
        if (!inspection.deficiencies || !Array.isArray(inspection.deficiencies) || inspection.deficiencies.length === 0) {
            return false;
        }
        
        // Check if any deficiency has actual violation content
        for (let deficiency of inspection.deficiencies) {
            // If it has section_cited, description, or plan_of_correction, it's a real violation
            if (deficiency && typeof deficiency === 'object') {
                if (deficiency.section_cited || deficiency.description || deficiency.plan_of_correction) {
                    return true;
                }
            }
        }
        
        // If we get here, no real violations found
        return false;
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
        // Apply current sorting/filtering if any is selected
        const sortBy = sortSelect ? sortSelect.value : '';
        const sortedFacilities = sortFacilities(facilities || [], sortBy);
        renderFilteredFacilities(sortedFacilities, letter);
    }

    function filterAndSort() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const sortBy = sortSelect ? sortSelect.value : '';
        
        console.log('filterAndSort called:', { searchTerm, sortBy, isSearching });
        
        // Safety check
        if (!allFacilitiesData || Object.keys(allFacilitiesData).length === 0) {
            console.log('No facilities data available yet');
            return;
        }
        
        if (searchTerm) {
            // Search mode: search across ALL facilities
            isSearching = true;
            if (clearButton) clearButton.style.display = 'inline-block';
            
            // Remove active state from alphabet filter during search
            document.querySelectorAll('#alphabet-filter a').forEach(a => {
                a.classList.remove('active');
            });
            
            // Get all facilities from all letters
            let allFacilities = [];
            Object.values(allFacilitiesData).forEach(letterGroup => {
                allFacilities = allFacilities.concat(letterGroup);
            });
            
            console.log(`Searching ${allFacilities.length} facilities for: "${searchTerm}"`);
            
            // Filter across all facilities
            const filteredFacilities = allFacilities.filter(facility => {
                const name = (facility.name || '').toLowerCase();
                const facilityType = (facility.facility_type || '').toLowerCase();
                const officer = (facility.officer || '').toLowerCase();
                const facilityNumber = (facility.number || '').toString().toLowerCase();
                
                return name.includes(searchTerm) || 
                       facilityType.includes(searchTerm) || 
                       officer.includes(searchTerm) ||
                       facilityNumber.includes(searchTerm);
            });
            
            console.log(`Found ${filteredFacilities.length} matching facilities`);
            
            // Sort the filtered results
            const sortedFacilities = sortFacilities(filteredFacilities, sortBy);
            renderFilteredFacilities(sortedFacilities, 'Search Results');
            
        } else {
            // No search: return to letter-based view
            isSearching = false;
            if (clearButton) clearButton.style.display = 'none';
            
            if (currentLetter && allFacilitiesData[currentLetter]) {
                // Restore the current letter selection
                document.querySelectorAll('#alphabet-filter a').forEach(a => {
                    a.classList.toggle('active', a.dataset.letter === currentLetter);
                });
                
                const facilities = allFacilitiesData[currentLetter];
                const sortedFacilities = sortFacilities(facilities, sortBy);
                renderFilteredFacilities(sortedFacilities, currentLetter);
            } else {
                // No letter selected, show first letter
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
    
    // Make clearSearch available globally
    window.clearSearch = clearSearch;

    function sortFacilities(facilities, sortBy) {
        if (!sortBy) return facilities;
        
        console.log(`Sorting ${facilities.length} facilities by: ${sortBy}`);
        
        let processedFacilities = [...facilities];
        
        // Filter inspections to show only those with violations
        if (sortBy === 'violations-only' || sortBy === 'violations-desc') {
            processedFacilities = facilities.map(facility => {
                // Use the same violation detection logic as the orange/white boxes
                const violationInspections = facility.inspections.filter(inspectionHasViolations);
                
                // Only include facilities that have at least one inspection with violations
                if (violationInspections.length > 0) {
                    return {
                        ...facility,
                        inspections: violationInspections
                    };
                }
                return null;
            }).filter(facility => facility !== null);
            
            console.log(`Filtered to ${processedFacilities.length} facilities with violation inspections`);
        }
        
        return processedFacilities.sort((a, b) => {
            switch(sortBy) {
                case 'name':
                    return (a.name || '').localeCompare(b.name || '');
                
                case 'violations-only':
                    // Just filter, keep alphabetical order
                    return (a.name || '').localeCompare(b.name || '');
                
                case 'violations-desc':
                    const aViolations = countViolations(a);
                    const bViolations = countViolations(b);
                    return bViolations - aViolations;
                
                case 'recent-inspection':
                    const aDate = getMostRecentInspectionDate(a);
                    const bDate = getMostRecentInspectionDate(b);
                    return bDate - aDate; // Most recent first
                
                default:
                    return 0;
            }
        });
    }

    // Helper function to count violations
    function countViolations(facility) {
        if (!facility.inspections) return 0;
        return facility.inspections.reduce((total, inspection) => {
            return total + (inspection.deficiencies ? inspection.deficiencies.length : 0);
        }, 0);
    }
    

    // Helper function to get most recent inspection date (same as before)
    function getMostRecentInspectionDate(facility) {
        if (!facility.inspections || facility.inspections.length === 0) {
            return new Date(0);
        }
        
        const dates = facility.inspections
            .map(inspection => new Date(inspection.visit_date || 0))
            .filter(date => !isNaN(date.getTime()));
        
        return dates.length > 0 ? Math.max(...dates) : new Date(0);
    }

    // Function to render filtered facilities
    function renderFilteredFacilities(facilities, context) {
        reportContainer.innerHTML = '';
        
        if (!facilities || facilities.length === 0) {
            const message = isSearching ? 
                'No facilities found matching your search.' : 
                `No facilities found for the letter "${context}".`;
            reportContainer.innerHTML = `<p>${message}</p>`;
            return;
        }

        // Add a results header when searching
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
                        <h2>Facility Number: ${facility.number || 'N/A'}</h2>
                        <p class="facility-details">Type: ${facility.facility_type || 'N/A'} | Administrator: ${toTitleCase(facility.officer) || 'N/A'} | Licensed Capacity: ${facility.capacity || 'N/A'}</p>
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
    
    // Common acronyms/abbreviations to keep all caps
    const acronyms = [
        'LLC', 'LLP', 'INC', 'CORP', 'CO', 'LTD', 
        'MD', 'RN', 'LPN', 'LCSW', 'LPA', 'LPCC', 'LISW', 'MSW', 'BSW', 
        'RD', 'CNA', 'CMA', 'EMT', 'LVN', 'DON', 
        'CEO', 'COO', 'CFO', 'HR', 'IT', 'VP', 'AVP',
        'FDA', 'CDC', 'CMS', 'HHS', 'DOH', 'OSHA', 'JCAHO',
        'USA', 'US', 'ID', 'SSN', 'THP', 'FC', 'STRTP', 'ARS', 'TRSCF', 'OC', 'TLC', '3R', 'BCFS', 'SFH', 'THPP', 'III', 'II'
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
        "mckay's": "McKay's",
        'mckee': 'McKee',
    };
    
    return str.toLowerCase()
        // Capitalize first letter of string and letters after spaces or hyphens (but not after apostrophes)
        .replace(/(^|\s|-|\/)\w/g, l => l.toUpperCase())
        // Handle dotted acronyms like B.W.I.T. - keep them all caps
        .replace(/\b[a-z](\.[a-z])+\.?\b/gi, match => match.toUpperCase())
        // Handle special names
        .replace(/\b\w+\b/g, word => {
            const lowerWord = word.toLowerCase();
            if (specialNames[lowerWord]) {
                return specialNames[lowerWord];
            }
            if (acronyms.includes(word.toUpperCase())) {
                return word.toUpperCase();
            }
            return word;
        });
}
    
    function createInspectionHTML(inspection) {
    // Use the same violation detection logic as filtering - just check if deficiencies field exists
    const hasViolations = inspectionHasViolations(inspection);
    
    const inspectionClass = hasViolations ? 'inspection-box-violation' : 'inspection-box-clean';
    const narrative = (inspection.narrative || '').replace(/\n/g, '<br>');
    const findings = (inspection.investigation_findings || '').replace(/\n/g, '<br>');
    
    // Create facility detail link using facility number
    const facilityNumber = inspection.facility_number || '';
    const facilityDetailLink = facilityNumber ? 
        `<a href="https://www.ccld.dss.ca.gov/carefacilitysearch/FacDetail/${facilityNumber}" target="_blank" rel="noopener noreferrer">View Facility Details</a>` : 
        'N/A';
            
        return `
        <details class="inspection-box ${inspectionClass}">
            <summary class="inspection-header">
                ${inspection.report_type || 'Report'} - ${inspection.visit_date || 'N/A'}
            </summary>
            <div class="inspection-content">
                <div class="inspection-details-block">
                    <strong>Report Type:</strong> ${inspection.report_type || 'N/A'}<br>
                    <strong>Visit Date:</strong> ${inspection.visit_date || 'N/A'}<br>
                    <strong>Report Date:</strong> ${inspection.report_date || 'N/A'}<br>
                    <strong>Form Number:</strong> ${inspection.form_number || 'N/A'}<br>
                    <strong>Census:</strong> ${inspection.census || 'N/A'}<br>
                    <strong>Complaint Status:</strong> ${inspection.complaint_status || 'N/A'}<br>
                    <strong>Met With:</strong> ${inspection.met_with || 'N/A'}<br>
                    <strong>Source:</strong> ${facilityDetailLink}
                </div>
                ${narrative ? `<div class="narrative-section"><h4>Narrative:</h4><p>${narrative}</p></div>` : ''}
                ${findings ? `<div class="findings-section"><h4>Investigation Findings:</h4><p>${findings}</p></div>` : ''}
                <h4>Deficiencies:</h4>
                ${hasViolations ? inspection.deficiencies?.map((def, index) => createDeficiencyHTML(def, index)).join('') || '<div class="no-violations"><p><strong>Violations found but no deficiency details available.</strong></p></div>' : '<div class="no-violations"><p><strong>No violations noted in this inspection.</strong></p></div>'}
            </div>
        </details>
    `;
}

    function createDeficiencyHTML(deficiency, index) {
        const section = (deficiency.section_cited || '').replace(/\n/g, '<br>');
        const description = (deficiency.description || '').replace(/\n/g, '<br>');
        const poc = (deficiency.plan_of_correction || '').replace(/\n/g, '<br>');
        return `
            <details class="violation-box">
                <summary class="deficiency-header">Violation ${index + 1}</summary>
                <div class="deficiency-content">
                    <strong>Regulation Cited:</strong><p>${section || 'N/A'}</p>
                    <strong>Description:</strong><p>${description || 'N/A'}</p>
                    <strong>Plan of Correction:</strong><p>${poc || 'N/A'}</p>
                </div>
            </details>
        `;
    }
    
    initializeReport();
});