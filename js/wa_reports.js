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
    
    const themeData = window.waReportsData || window.myThemeData || {};

    async function initializeReport() {
        try {
            console.log('Starting to initialize Washington reports...');
            const urls = Array.isArray(themeData.jsonFileUrls) ? themeData.jsonFileUrls : [];
            console.log('URLs to fetch:', urls);

            if (!urls || urls.length === 0) {
                reportContainer.innerHTML = '<p>No data files found to load.</p>';
                return;
            }

            // Find the Washington reports file
            const waUrl = urls.find(url => url.includes('wa_reports') || url.includes('combined_reports.json'));
            if (!waUrl) {
                reportContainer.innerHTML = '<p>Washington reports data file not found.</p>';
                return;
            }

            console.log('Loading Washington reports from:', waUrl);
            const response = await fetch(waUrl);
            if (!response.ok) {
                throw new Error(`Failed to fetch data: ${response.status}`);
            }
            
            const data = await response.json();
            console.log('Raw data loaded:', data);
            
            // Handle the structure from your Python parser
            let rawReports = [];
            if (data.reports && Array.isArray(data.reports)) {
                rawReports = data.reports;
            } else if (Array.isArray(data)) {
                rawReports = data;
            } else {
                throw new Error('Unexpected data format');
            }
            
            console.log(`Loaded ${rawReports.length} raw inspection reports`);
            
            // Transform Washington reports into agency-grouped structure
            const aggregatedFacilities = aggregateReportsIntoAgencies(rawReports);
            allFacilitiesData = groupFacilitiesFromArray(aggregatedFacilities);
            console.log('Processed facilities data:', allFacilitiesData);
            console.log('Available letters:', Object.keys(allFacilitiesData));
            
            renderAlphabetFilter();
            const firstLetter = Object.keys(allFacilitiesData).sort()[0];
            if (firstLetter) {
                renderFacilitiesForLetter(firstLetter);
            } else {
                reportContainer.innerHTML = '<p>No agencies found in the data.</p>';
            }
        } catch (error) {
            console.error('Failed to load or process report data:', error);
            reportContainer.innerHTML = `<p class="error">Error loading data: ${error.message}</p>`;
        }
    }

    // Helper function to check if inspection report has violations
    function reportHasViolations(report) {
        return report.deficiencies && Array.isArray(report.deficiencies) && report.deficiencies.length > 0;
    }

    // --- AGGREGATES RAW WASHINGTON REPORTS INTO AGENCY GROUPS ---
    function aggregateReportsIntoAgencies(reports) {
        const agencies = reports.reduce((acc, report) => {
            // Use facility name as the identifier, fallback to license number or filename
            const agencyName = report.facility_name || 
                             report.license_number || 
                             report.source_file?.replace('.txt', '') || 
                             'Unknown Agency';
            
            if (!acc[agencyName]) {
                acc[agencyName] = {
                    name: agencyName,
                    agency_address: report.facility_address || '',
                    license_number: report.license_number || '',
                    administrator: report.administrator || '',
                    inspections: []
                };
            }
            
            // Transform the report into an inspection format
            const inspection = {
                inspection_type: report.inspection_type || 'Inspection',
                inspection_date: report.inspection_date || 'N/A',
                inspection_number: report.inspection_number || 'N/A',
                inspector: report.inspector || 'N/A',
                rtf_service_types: report.service_types || '',
                source_file: report.source_file || '',
                deficiencies: report.deficiencies || []
            };
            
            acc[agencyName].inspections.push(inspection);
            return acc;
        }, {});
        
        // Sort inspections within each agency by date (most recent first)
        Object.values(agencies).forEach(agency => {
            agency.inspections.sort((a, b) => {
                const dateA = parseDate(a.inspection_date);
                const dateB = parseDate(b.inspection_date);
                return dateB - dateA; // Most recent first
            });
        });
        
        return Object.values(agencies);
    }

    // Helper function to parse MM/DD/YY or MM/DD/YYYY dates
    function parseDate(dateStr) {
        if (!dateStr || dateStr === 'N/A') return new Date(0);
        
        const parts = dateStr.split('/');
        if (parts.length !== 3) return new Date(0);
        
        let [month, day, year] = parts.map(p => parseInt(p, 10));
        
        // Handle 2-digit years (assume 20xx if < 50, 19xx if >= 50)
        if (year < 100) {
            year += (year < 50) ? 2000 : 1900;
        }
        
        return new Date(year, month - 1, day);
    }

    // --- GROUPS THE FINALIZED AGENCIES BY FIRST LETTER ---
    function groupFacilitiesFromArray(agenciesArray) {
        const grouped = agenciesArray.reduce((acc, agency) => {
            const name = agency.name || '';
            const firstLetter = name.charAt(0).toUpperCase();
            const groupKey = (firstLetter >= 'A' && firstLetter <= 'Z') ? firstLetter : '#';

            if (!acc[groupKey]) { acc[groupKey] = []; }
            acc[groupKey].push(agency);
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
        if (isSearching) return;
        
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
            
            const filteredFacilities = allFacilities.filter(agency => {
                const name = (agency.name || '').toLowerCase();
                const licenseNumber = (agency.license_number || '').toLowerCase();
                const address = (agency.agency_address || '').toLowerCase();
                const administrator = (agency.administrator || '').toLowerCase();
                
                return name.includes(searchTerm) || 
                       licenseNumber.includes(searchTerm) || 
                       address.includes(searchTerm) ||
                       administrator.includes(searchTerm);
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
            processedFacilities = facilities.map(agency => {
                const violationInspections = agency.inspections.filter(reportHasViolations);
                
                if (violationInspections.length > 0) {
                    return {
                        ...agency,
                        inspections: violationInspections
                    };
                }
                return null;
            }).filter(agency => agency !== null);
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

    function countViolations(agency) {
        if (!agency.inspections) return 0;
        return agency.inspections.reduce((total, inspection) => {
            return total + (inspection.deficiencies ? inspection.deficiencies.length : 0);
        }, 0);
    }

    function getMostRecentInspectionDate(agency) {
        if (!agency.inspections || agency.inspections.length === 0) {
            return new Date(0);
        }
        
        const dates = agency.inspections
            .map(inspection => parseDate(inspection.inspection_date))
            .filter(date => date.getTime() > 0);
        
        return dates.length > 0 ? new Date(Math.max(...dates.map(d => d.getTime()))) : new Date(0);
    }

    function renderFilteredFacilities(facilities, context) {
        reportContainer.innerHTML = '';
        
        if (!facilities || facilities.length === 0) {
            const message = isSearching ? 
                'No agencies found matching your search.' : 
                `No agencies found for the letter "${context}".`;
            reportContainer.innerHTML = `<p>${message}</p>`;
            return;
        }

        if (isSearching) {
            const resultsHeader = document.createElement('div');
            resultsHeader.style.cssText = 'margin-bottom: 20px; padding: 10px; background: #e8f4f8; border-radius: 4px; font-weight: bold;';
            resultsHeader.innerHTML = `Found ${facilities.length} agencies matching your search`;
            reportContainer.appendChild(resultsHeader);
        }

        facilities.forEach(agency => {
            const facilityElement = document.createElement('div');
            facilityElement.className = 'facility-box';
            facilityElement.innerHTML = `
                <details>
                    <summary class="facility-header">
                        <h1>${toTitleCase(agency.name) || 'N/A'}</h1>
                        <h2>License Number: ${agency.license_number || 'N/A'}</h2>
                        <p class="facility-details">
                            Administrator: ${toTitleCase(agency.administrator) || 'N/A'}
                        </p>
                        <p class="facility-address">
                            ${agency.agency_address || ''}
                        </p>
                    </summary>
                    <div class="inspections-container">
                        ${(agency.inspections || []).map(createInspectionHTML).join('')}
                    </div>
                </details>
            `;
            reportContainer.appendChild(facilityElement);
        });
    }

    function toTitleCase(str) {
        if (!str) return str;
        
        const acronyms = [
            'LLC', 'LLP', 'INC', 'CORP', 'CO', 'LTD', 
            'MD', 'RN', 'LPN', 'LCSW', 'LPA', 'LPCC', 'LISW', 'MSW', 'BSW', 
            'RD', 'CNA', 'CMA', 'EMT', 'LVN', 'DON', 
            'CEO', 'COO', 'CFO', 'HR', 'IT', 'VP', 'AVP',
            'FDA', 'CDC', 'CMS', 'HHS', 'DOH', 'OSHA', 'JCAHO',
            'USA', 'US', 'WA', 'ID', 'SSN', 'RTF', 'FS', 'III', 'II'
        ];
        
        const lowercaseWords = [
            'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'if', 'in', 
            'of', 'on', 'or', 'the', 'to', 'up', 'via', 'with', 'from',
            'into', 'onto', 'upon', 'over', 'under', 'above', 'below', 'is'
        ];
        
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
        };
        
        const words = str.toLowerCase().split(/(\s+|-|\/)/);
        
        return words.map((word, index) => {
            if (/^\s+$|^-$|^\/$/.test(word)) {
                return word;
            }
            
            if (index === 0) {
                return capitalizeWord(word);
            }
            
            if (lowercaseWords.includes(word.toLowerCase())) {
                return word.toLowerCase();
            }
            
            return capitalizeWord(word);
        }).join('');
        
        function capitalizeWord(word) {
            const lowerWord = word.toLowerCase();
            
            if (specialNames[lowerWord]) {
                return specialNames[lowerWord];
            }
            
            if (acronyms.includes(word.toUpperCase())) {
                return word.toUpperCase();
            }
            
            if (/^[a-z](\.[a-z])+\.?$/i.test(word)) {
                return word.toUpperCase();
            }
            
            return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
        }
    }
    
    function createInspectionHTML(inspection) {
        const hasViolations = reportHasViolations(inspection);
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
                    <strong>Inspector:</strong> ${inspection.inspector || 'N/A'}<br>
                    <strong>RTF Service Types:</strong> ${inspection.rtf_service_types || 'N/A'}<br>
                    <strong>Source:</strong> Washington State Department of Health
                </div>
                <h4>Deficiencies:</h4>
                ${hasViolations ? inspection.deficiencies?.map((def, index) => createDeficiencyHTML(def, index)).join('') || '<div class="no-violations"><p><strong>Violations found but no deficiency details available.</strong></p></div>' : '<div class="no-violations"><p><strong>No violations noted in this inspection.</strong></p></div>'}
            </div>
        </details>
        `;
    }

    function createDeficiencyHTML(deficiency, index) {
        const code = deficiency.deficiency_code || 'N/A';
        const title = deficiency.title || 'N/A';
        const wac = deficiency.wac_reference || 'N/A';
        const findings = deficiency.findings || 'N/A';
        const planOfCorrection = deficiency.plan_of_correction || 'N/A';
        
        return `
            <details class="violation-box">
                <summary class="deficiency-header">Deficiency ${index + 1} - Code ${code}</summary>
                <div class="deficiency-content">
                    <strong>Deficiency Code:</strong><p>${code}</p>
                    <strong>Title:</strong><p>${title}</p>
                    <strong>WAC Reference:</strong><p>${wac}</p>
                    <strong>Findings:</strong><p>${findings}</p>
                    ${planOfCorrection !== 'N/A' ? `<strong>Plan of Correction:</strong><p>${planOfCorrection}</p>` : ''}
                </div>
            </details>
        `;
    }
    
    initializeReport();
});