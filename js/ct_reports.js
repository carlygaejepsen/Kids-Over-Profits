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
    
    function getFacilityInfo(facility = {}) {
        return facility.facility_info || {};
    }

    function getFacilityName(facility = {}) {
        return getFacilityInfo(facility).facility_name || '';
    }

    function getFacilityAddress(facility = {}) {
        const info = getFacilityInfo(facility);
        return info.full_address || info.city_state_zip || '';
    }

    function getFacilityDirector(facility = {}) {
        return getFacilityInfo(facility).executive_director || '';
    }

    const themeData = window.ctReportsData || window.myThemeData || {};

    async function initializeReport() {
        try {
            console.log('Starting to initialize CT DCF report...');
            const urls = Array.isArray(themeData.jsonFileUrls) ? themeData.jsonFileUrls : [];
            const url = urls[0] || '/wp-content/themes/child/js/data/ct_reports.json';
            console.log('URL to fetch:', url);

            // Fetch the CT DCF data
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`Failed to fetch data: ${response.status}`);
            }
            
            const jsonData = await response.json();
            console.log('Loaded CT DCF data:', jsonData);
            
            // Extract facilities from the CT DCF data structure
            const facilities = jsonData.facilities || [];
            console.log(`Found ${facilities.length} facilities`);
            
            allFacilitiesData = groupFacilitiesFromArray(facilities);
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
            console.error('Failed to load or process CT DCF data:', error);
            reportContainer.innerHTML = `<p class="error">Error processing data. Please check the console.</p>`;
        }
    }

    // --- GROUPS THE CT DCF FACILITIES BY FIRST LETTER ---
    function groupFacilitiesFromArray(facilitiesArray) {
        const grouped = facilitiesArray.reduce((acc, facility) => {
            const name = facility.facility_info?.facility_name || '';
            const firstLetter = name.charAt(0).toUpperCase();
            const groupKey = (firstLetter >= 'A' && firstLetter <= 'Z') ? firstLetter : '#';
    
            if (!acc[groupKey]) { acc[groupKey] = []; }
            acc[groupKey].push(facility);
            return acc;
        }, {});
    
        for (const letter in grouped) {
            grouped[letter].sort((a, b) => {
                const nameA = a.facility_info?.facility_name || '';
                const nameB = b.facility_info?.facility_name || '';
                return nameA.localeCompare(nameB);
            });
        }
        return grouped;
    }

    // Helper function to check if report has compliance issues
    function reportHasViolations(report) {
        if (!report.categories) return false;
        
        const nonCompliance = report.categories.regulatory_non_compliance;
        if (!nonCompliance || !Array.isArray(nonCompliance)) return false;
        
        // Check if it's actually violations or just "none"
        return nonCompliance.some(item => 
            item && typeof item === 'object' && 
            item.type !== 'none' && 
            item.description !== 'None'
        );
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
                const info = getFacilityInfo(facility);
                const name = getFacilityName(facility).toLowerCase();
                const program = (info.program_name || '').toLowerCase();
                const director = (info.executive_director || '').toLowerCase();
                const category = (info.program_category || '').toLowerCase();
                const address = getFacilityAddress(facility).toLowerCase();

                return name.includes(searchTerm) ||
                       program.includes(searchTerm) ||
                       director.includes(searchTerm) ||
                       category.includes(searchTerm) ||
                       address.includes(searchTerm);
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
        
        // Filter reports to show only those with violations
        if (sortBy === 'violations-only' || sortBy === 'violations-desc') {
            processedFacilities = facilities.map(facility => {
                const violationReports = facility.reports?.filter(reportHasViolations) || [];
                
                // Only include facilities that have at least one report with violations
                if (violationReports.length > 0) {
                    return {
                        ...facility,
                        reports: violationReports
                    };
                }
                return null;
            }).filter(facility => facility !== null);
            
            console.log(`Filtered to ${processedFacilities.length} facilities with violation reports`);
        }
        
        return processedFacilities.sort((a, b) => {
            const nameA = getFacilityName(a);
            const nameB = getFacilityName(b);
            
            switch(sortBy) {
                case 'name':
                    return nameA.localeCompare(nameB);
                
                case 'violations-only':
                    // Just filter, keep alphabetical order
                    return nameA.localeCompare(nameB);
                
                case 'violations-desc':
                    const aViolations = countViolations(a);
                    const bViolations = countViolations(b);
                    return bViolations - aViolations;
                
                case 'recent-inspection':
                    const aDate = getMostRecentReportDate(a);
                    const bDate = getMostRecentReportDate(b);
                    return bDate - aDate; // Most recent first
                
                default:
                    return 0;
            }
        });
    }

    // Helper function to count violations
    function countViolations(facility) {
        if (!facility.reports) return 0;
        return facility.reports.reduce((total, report) => {
            const nonCompliance = report.categories?.regulatory_non_compliance || [];
            return total + nonCompliance.filter(item => item.type !== 'none').length;
        }, 0);
    }

    // Helper function to get most recent report date
    function getMostRecentReportDate(facility) {
        if (!facility.reports || facility.reports.length === 0) {
            return new Date(0);
        }
        
        const dates = facility.reports
            .map(report => new Date(report.report_date || 0))
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
            const info = getFacilityInfo(facility);
            const address = getFacilityAddress(facility);

            facilityElement.innerHTML = `
                <details>
                    <summary class="facility-header">
                        <h1>${toTitleCase(getFacilityName(facility)) || 'N/A'}</h1>
                        <h2>Program: ${info.program_name || 'N/A'}</h2>
                        <p class="facility-details">
                            Category: ${info.program_category || 'N/A'} |
                            Director: ${toTitleCase(getFacilityDirector(facility)) || 'N/A'} |
                            Capacity: ${info.bed_capacity || 'N/A'}
                        </p>
                        <p class="facility-address">
                            ${address || ''}<br>
                            ${info.phone || ''}
                        </p>
                    </summary>
                    <div class="reports-container">
                        ${(facility.reports || []).map(createReportHTML).join('')}
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
            'CEO', 'COO', 'CFO', 'HR', 'IT', 'VP', 'AVP', 'DCF'
        ];
        
        // Words that should always be lowercase (except when first word)
        const lowercaseWords = [
            'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'if', 'in', 
            'of', 'on', 'or', 'the', 'to', 'up', 'via', 'with', 'from',
            'into', 'onto', 'upon', 'over', 'under', 'above', 'below', 'is'
        ];
        
        return str.toLowerCase()
            .replace(/(^|\s|-|\/)\w/g, l => l.toUpperCase())
            .replace(/\b[a-z](\.[a-z])+\.?\b/gi, match => match.toUpperCase())
            .replace(/\b\w+\b/g, word => {
                if (acronyms.includes(word.toUpperCase())) {
                    return word.toUpperCase();
                }
                return word;
            });
    }
    
    function createReportHTML(report) {
        const hasViolations = reportHasViolations(report);
        const reportClass = hasViolations ? 'inspection-box-violation' : 'inspection-box-clean';
        
        return `
            <details class="inspection-box ${reportClass}">
                <summary class="inspection-header">
                    Report ${report.report_id} - ${report.report_date || 'N/A'}
                </summary>
                <div class="inspection-content">
                    <div class="inspection-details-block">
                        <strong>Report ID:</strong> ${report.report_id || 'N/A'}<br>
                        <strong>Report Date:</strong> ${report.report_date || 'N/A'}<br>
                        <strong>Content Length:</strong> ${report.content_length || 0} characters<br>
                        <strong>Summary:</strong> ${report.summary || 'No summary available'}
                    </div>
                    ${createReportCategoriesHTML(report)}
                </div>
            </details>
        `;
    }

    function createReportCategoriesHTML(report) {
        if (!report.categories) {
            return '<div class="no-data"><p><strong>No categorized data available.</strong></p></div>';
        }

        let html = '';
        
        // Areas/Topics covered
        if (report.categories.areas_topics_covered) {
            html += `
                <div class="category-section">
                    <h4>Areas/Topics Covered During Visit:</h4>
                    <ul>
                        ${report.categories.areas_topics_covered.map(topic => `<li>${topic}</li>`).join('')}
                    </ul>
                </div>
            `;
        }
        
        // Regulatory non-compliance
        const nonCompliance = report.categories.regulatory_non_compliance || [];
        const hasRealViolations = nonCompliance.some(item => item.type !== 'none');
        
        html += `
            <div class="category-section">
                <h4>Regulatory Non-Compliance:</h4>
                ${hasRealViolations ? 
                    nonCompliance.filter(item => item.type !== 'none').map(item => `
                        <div class="violation-item">
                            ${item.area_type ? `<strong>Area:</strong> ${item.area_type}<br>` : ''}
                            ${item.regulation ? `<strong>Regulation:</strong> ${item.regulation}<br>` : ''}
                            <strong>Description:</strong> ${item.description || 'N/A'}
                        </div>
                    `).join('') :
                    '<div class="no-violations"><p><strong>No violations noted in this report.</strong></p></div>'
                }
            </div>
        `;
        
        // Corrective actions
        if (report.categories.corrective_actions) {
            html += `
                <div class="category-section">
                    <h4>Corrective Actions:</h4>
                    <ul>
                        ${report.categories.corrective_actions.map(action => `<li>${action}</li>`).join('')}
                    </ul>
                </div>
            `;
        }
        
        // Recommendations
        if (report.categories.recommendations) {
            html += `
                <div class="category-section">
                    <h4>Recommendations:</h4>
                    <ul>
                        ${report.categories.recommendations.map(rec => `<li>${rec}</li>`).join('')}
                    </ul>
                </div>
            `;
        }
        
        // Visit details
        if (report.categories.visit_details) {
            const details = report.categories.visit_details;
            html += `
                <div class="category-section">
                    <h4>Visit Details:</h4>
                    <div class="visit-details">
                        ${details.facility_name ? `<strong>Facility:</strong> ${details.facility_name}<br>` : ''}
                        ${details.visit_date ? `<strong>Visit Date:</strong> ${details.visit_date}<br>` : ''}
                        ${details.visit_time ? `<strong>Visit Time:</strong> ${details.visit_time}<br>` : ''}
                        ${details.personnel ? `<strong>Personnel:</strong> ${details.personnel.join(', ')}<br>` : ''}
                    </div>
                </div>
            `;
        }
        
        // Incidents
        if (report.categories.incidents) {
            html += `
                <div class="category-section">
                    <h4>Incidents:</h4>
                    <ul>
                        ${report.categories.incidents.map(incident => `<li>${incident}</li>`).join('')}
                    </ul>
                </div>
            `;
        }
        
        // Full report content for unstructured reports
        if (report.categories.full_report_content) {
            const content = report.categories.full_report_content.replace(/\n/g, '<br>');
            html += `
                <div class="category-section">
                    <h4>Full Report Content:</h4>
                    <div class="full-content">${content}</div>
                </div>
            `;
        }
        
        return html;
    }
    
    initializeReport();
});