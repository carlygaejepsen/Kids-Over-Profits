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

    function getFacilityName(facility = {}) {
        return facility.facility_name || facility.name || '';
    }

    function getFacilityAddress(facility = {}) {
        return facility.facility_address || facility.address || '';
    }

    function deriveFromChecklists(facility = {}, key) {
        if (!Array.isArray(facility.inspections)) {
            return null;
        }

        for (const inspection of facility.inspections) {
            if (!inspection || !Array.isArray(inspection.checklists)) {
                continue;
            }

            const match = inspection.checklists.find(item => item && item[key] !== undefined && item[key] !== null);

            if (match) {
                return match[key];
            }
        }

        return null;
    }

    function getFacilityCapacity(facility = {}) {
        if (facility.capacity !== undefined && facility.capacity !== null) {
            return facility.capacity;
        }

        return deriveFromChecklists(facility, 'capacity');
    }

    function getFacilityCensus(facility = {}) {
        if (facility.most_recent_census !== undefined && facility.most_recent_census !== null) {
            return facility.most_recent_census;
        }

        return deriveFromChecklists(facility, 'census');
    }

    function normaliseInspections(rawInspections = []) {
        return rawInspections.map(inspection => {
            if (!inspection) {
                return {};
            }

            const findingsArray = Array.isArray(inspection.inspection_findings)
                ? inspection.inspection_findings
                : Array.isArray(inspection.findings)
                    ? inspection.findings.map(finding => ({
                        rule_number: finding?.rule_number || finding?.ruleNumber || '',
                        description: finding?.description || finding?.rule_description || '',
                        text: finding?.text || finding?.finding_text || ''
                    }))
                    : [];

            const checklistUrls = Array.isArray(inspection.checklist_urls) && inspection.checklist_urls.length > 0
                ? inspection.checklist_urls
                : Array.isArray(inspection.checklists)
                    ? inspection.checklists
                        .map(item => item?.pdf_file)
                        .filter(Boolean)
                        .map(file => {
                            const fileName = file.split('/').pop();
                            return fileName ? `https://kidsoverprofits.org/wp-content/uploads/utah-checklists/${fileName}` : null;
                        })
                        .filter(Boolean)
                    : [];

            return {
                inspection_date: inspection.inspection_date || inspection.date || '',
                inspection_type: inspection.inspection_type || inspection.inspection_types || 'Inspection',
                inspection_findings: findingsArray,
                checklist_urls: checklistUrls,
                checklists: inspection.checklists || [],
            };
        });
    }

    async function initializeReport() {
        try {
            console.log('Starting to initialize Utah report...');

            // NOTE: This part assumes myThemeData.jsonFileUrls is correctly defined elsewhere
            const urls = (window.myThemeData && Array.isArray(window.myThemeData.jsonFileUrls)) ? window.myThemeData.jsonFileUrls : [];
            console.log('URLs to fetch:', urls);

            if (!urls || urls.length === 0) {
                reportContainer.innerHTML = '<p>No data files found to load.</p>';
                return;
            }

            const jsonUrl = urls.find(url => url.includes('ut_reports')) || urls[0];
            console.log('Using URL:', jsonUrl);

            const response = await fetch(jsonUrl);

            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }

            const facilitiesArray = await response.json();
            console.log('Loaded ' + facilitiesArray.length + ' facilities');

            const normalisedFacilities = facilitiesArray.map(facility => ({
                ...facility,
                facility_name: getFacilityName(facility),
                facility_address: getFacilityAddress(facility),
                capacity: getFacilityCapacity(facility),
                most_recent_census: getFacilityCensus(facility),
                inspections: normaliseInspections(facility.inspections),
            }));

            allFacilitiesData = groupFacilitiesFromArray(normalisedFacilities);
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
            reportContainer.innerHTML = '<p class="error">Error loading data: ' + error.message + '</p>';
        }
    }
    
    // Helper function to check if inspection has violations/findings
    function inspectionHasViolations(inspection) {
        if (!inspection) {
            return false;
        }

        if (Array.isArray(inspection.inspection_findings)) {
            return inspection.inspection_findings.length > 0;
        }

        if (Array.isArray(inspection.findings)) {
            return inspection.findings.length > 0;
        }

        return false;
    }

    // --- GROUPS THE FACILITIES BY FIRST LETTER ---
    function groupFacilitiesFromArray(facilitiesArray) {
        const grouped = facilitiesArray.reduce((acc, facility) => {
            const name = getFacilityName(facility);
            const firstLetter = name.charAt(0).toUpperCase();
            const groupKey = (firstLetter >= 'A' && firstLetter <= 'Z') ? firstLetter : '#';

            if (!acc[groupKey]) { acc[groupKey] = []; }
            acc[groupKey].push(facility);
            return acc;
        }, {});

        for (const letter in grouped) {
            grouped[letter].sort((a, b) => getFacilityName(a).localeCompare(getFacilityName(b)));
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
                const name = getFacilityName(facility).toLowerCase();
                const address = getFacilityAddress(facility).toLowerCase();

                return name.includes(searchTerm) || address.includes(searchTerm);
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
                    return { ...facility, inspections: violationInspections };
                }
                return null;
            }).filter(facility => facility !== null);
        }
        
        return processedFacilities.sort((a, b) => {
            switch(sortBy) {
                case 'name':
                    return getFacilityName(a).localeCompare(getFacilityName(b));

                case 'violations-only':
                    return getFacilityName(a).localeCompare(getFacilityName(b));
                
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
            const findings = Array.isArray(inspection.inspection_findings)
                ? inspection.inspection_findings
                : Array.isArray(inspection.findings)
                    ? inspection.findings
                    : [];

            return total + findings.length;
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

            const capacityValue = getFacilityCapacity(facility);
            const censusValue = getFacilityCensus(facility);
            const capacityHTML = capacityValue !== null && capacityValue !== undefined ? `<li><strong>Capacity:</strong> ${capacityValue}</li>` : '';
            const censusHTML = censusValue !== null && censusValue !== undefined ? `<li><strong>Most Recent Census:</strong> ${censusValue}</li>` : '';
            const pageLinkHTML = facility.facility_page_link ? `<li><strong>Facility Page:</strong> <a href="${facility.facility_page_link}" target="_blank" rel="noopener noreferrer">View Official Page</a></li>` : '';

            facilityElement.innerHTML = `
                <details>
                    <summary class="facility-header">
                        <h1>${toTitleCase(getFacilityName(facility)) || 'N/A'}</h1>
                        <p class="facility-details">Address: ${getFacilityAddress(facility) || 'N/A'}</p>
                    <div class="facility-extra-details">
                        <ul>
                            ${capacityHTML}
                            ${censusHTML}
                            ${pageLinkHTML}
                        </ul>
                    </div>
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
        
        return str.toLowerCase()
            .replace(/(^|\s|\(|\[|\{)\w/g, l => l.toUpperCase())
            .replace(/\b[a-z](\.[a-z])+\.?\b/gi, match => match.toUpperCase())
            .replace(/\b\w+\b/g, word => {
                const lowerWord = word.toLowerCase();
                if (specialNames[lowerWord]) { return specialNames[lowerWord]; }
                if (acronyms.includes(word.toUpperCase())) { return word.toUpperCase(); }
                return word;
            });
    }
    
    function createInspectionHTML(inspection) {
        const hasViolations = inspectionHasViolations(inspection);
        const inspectionClass = hasViolations ? 'inspection-box-violation' : 'inspection-box-clean';
        
        // ADDED: Generate HTML for checklist URLs
        const checklistHTML = (inspection.checklist_urls && inspection.checklist_urls.length > 0) ?
            `<h4>Checklists:</h4>
             <ul>
                ${inspection.checklist_urls.map(url => `<li><a href="${url}" target="_blank" rel="noopener noreferrer">View Checklist</a></li>`).join('')}
             </ul>` : '';

        const findings = Array.isArray(inspection.inspection_findings)
            ? inspection.inspection_findings
            : Array.isArray(inspection.findings)
                ? inspection.findings
                : [];

        return `
        <details class="inspection-box ${inspectionClass}">
            <summary class="inspection-header">
                ${inspection.inspection_type || 'Inspection'} - ${inspection.inspection_date || 'N/A'}
            </summary>
            <div class="inspection-content">
                <div class="inspection-details-block">
                    <strong>Inspection Date:</strong> ${inspection.inspection_date || 'N/A'}<br>
                    <strong>Inspection Type:</strong> ${inspection.inspection_type || 'N/A'}
                </div>
                <h4>Findings:</h4>
                ${hasViolations ? findings.map((finding, index) => createFindingHTML(finding, index)).join('') || '<div class="no-violations"><p><strong>Violations found but no finding details available.</strong></p></div>' : '<div class="no-violations"><p><strong>No violations noted in this inspection.</strong></p></div>'}

                ${checklistHTML}
            </div>
        </details>
        `;
    }

    function createFindingHTML(finding, index) {
        if (!finding) {
            return '';
        }

        const ruleNumber = finding.rule_number || finding.ruleNumber || 'N/A';
        const ruleDescriptionRaw = finding.description || finding.rule_description || '';
        const findingTextRaw = finding.text || finding.finding_text || '';
        const ruleDescription = ruleDescriptionRaw.replace(/\n/g, '<br>');
        const findingText = findingTextRaw.replace(/\n/g, '<br>');
        
        return `
            <details class="violation-box">
                <summary class="deficiency-header">Finding ${index + 1} - Rule ${ruleNumber}</summary>
                <div class="deficiency-content">
                    <strong>Rule Number:</strong><p>${ruleNumber}</p>
                    <strong>Rule Description:</strong><p>${ruleDescription || 'N/A'}</p>
                    <strong>Finding:</strong><p>${findingText || 'N/A'}</p>
                </div>
            </details>
        `;
    }
    
    initializeReport();
});