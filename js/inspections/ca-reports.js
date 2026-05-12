// California Reports JavaScript - Last deployed: 2025-01-15
document.addEventListener('DOMContentLoaded', () => {
    console.log('=== CA REPORTS DEBUG START ===');
    console.log('Script loaded successfully');
    const themeData = window.caReportsData || window.myThemeData;
    console.log('typeof themeData:', typeof themeData);
    console.log('CA theme data:', themeData);

    /**
     * Safely extracts a displayable string from any value
     * Handles objects, arrays, strings, numbers - prevents "[object Object]" display
     * @param {*} value - The value to convert to a safe string
     * @returns {string} - A clean string or empty string
     */
    const safeString = value => {
        if (value === null || value === undefined) return '';

        // Already a string
        if (typeof value === 'string') {
            const trimmed = value.trim();
            return (trimmed && trimmed !== '[object Object]') ? trimmed : '';
        }

        // Numbers and booleans - convert directly
        if (typeof value === 'number' || typeof value === 'boolean') {
            return String(value);
        }

        // Arrays - recursively join string values
        if (Array.isArray(value)) {
            const stringValues = value.map(v => safeString(v)).filter(Boolean);
            return stringValues.join(', ');
        }

        // Objects - try to extract a meaningful value
        if (typeof value === 'object') {
            // Try common field names in order of preference
            const possibleFields = ['name', 'Name', 'fullName', 'value', 'text', 'title',
                                    'url', 'link', 'label', 'display', 'content'];

            for (const field of possibleFields) {
                if (value[field] && typeof value[field] === 'string' && value[field].trim()) {
                    return value[field].trim();
                }
            }

            // Try firstName + lastName combo
            if (value.firstName || value.lastName) {
                const name = [value.firstName, value.lastName].filter(Boolean).join(' ').trim();
                if (name) return name;
            }

            // Don't output objects as strings
            return '';
        }

        // Fallback - try String() but check result
        const str = String(value);
        return (str && str !== '[object Object]') ? str : '';
    };

    const normalizeWhitespace = value => safeString(value).replace(/\s+/g, ' ').trim();

    function isStreetAddressLike(value) {
        const name = normalizeWhitespace(value);
        if (!name) return false;

        // Treat only house-number style patterns as addresses.
        // This preserves legitimate facility names like "2 Loving Hearts" or "2nd Chance".
        if (/^\d{1,6}\s+[a-z0-9.'-]+(?:\s+[a-z0-9.'-]+){0,4}\s+(street|st|avenue|ave|boulevard|blvd|road|rd|drive|dr|lane|ln|court|ct|circle|cir|way|place|pl|highway|hwy|trail|terrace|ter)\b/i.test(name)) {
            return true;
        }

        if (/^(north|south|east|west|ne|nw|se|sw)\s+\d{1,6}\s+[a-z0-9.'-]+(?:\s+[a-z0-9.'-]+){0,3}\s+(street|st|avenue|ave|boulevard|blvd|road|rd|drive|dr|lane|ln|court|ct|circle|cir|way|place|pl|highway|hwy|trail|terrace|ter)\b/i.test(name)) {
            return true;
        }

        return false;
    }

    function stripTrailingLocationSuffix(value) {
        const name = normalizeWhitespace(value);
        if (!name) return '';

        const delimitedLocationMatch = name.match(
            /^(.*?\b[A-Za-z][A-Za-z0-9'&.,/-]*(?:\s+[A-Za-z0-9][A-Za-z0-9'&.,/-]*){1,8})\s*[,/-]\s*([A-Za-z0-9.'-]+(?:\s+[A-Za-z0-9.'-]+){0,4}\s+(street|st|avenue|ave|boulevard|blvd|road|rd|drive|dr|lane|ln|court|ct|circle|cir|way|place|pl|highway|hwy|trail|terrace|ter))$/i
        );
        if (delimitedLocationMatch && delimitedLocationMatch[1]) {
            return normalizeWhitespace(delimitedLocationMatch[1]);
        }

        const ordinalStreetMatch = name.match(
            /^(.*?\b[A-Za-z][A-Za-z0-9'&./-]*(?:\s+[A-Za-z][A-Za-z0-9'&./-]*){1,8})\s+\d+(?:st|nd|rd|th)\s+(street|st|avenue|ave|boulevard|blvd|road|rd|drive|dr|lane|ln|court|ct|circle|cir|way|place|pl|highway|hwy|trail|terrace|ter)$/i
        );
        if (ordinalStreetMatch && ordinalStreetMatch[1]) {
            return normalizeWhitespace(ordinalStreetMatch[1]);
        }

        return name;
    }

    function cleanFacilityNameCandidate(value) {
        let name = normalizeWhitespace(value);
        if (!name) return '';

        name = name
            .replace(/\s+Facility Number:\s*\d+.*$/i, '')
            .replace(/\s+Administrator:\s*.+$/i, '')
            .replace(/\s+Licensed Capacity:\s*.+$/i, '')
            .replace(/\s{2,}/g, ' ')
            .trim();

        name = stripTrailingLocationSuffix(name);
        if (!name) return '';

        // Guard against long narrative chunks accidentally treated as facility names.
        if (name.length > 120) return '';
        if (/\b(report date|facility evaluation report|complaint investigation report|deficienc|plan of correction)\b/i.test(name)) {
            return '';
        }

        // Reject only actual street-address patterns, not names that happen to begin with digits.
        if (isStreetAddressLike(name)) return '';

        return name;
    }

    function parseUsDateToIso(dateStr) {
        const source = normalizeWhitespace(dateStr);
        if (!source) return '';

        const first = source.split(/\s*[-–]\s*/)[0].trim();
        const parts = first.split('/');
        if (parts.length === 3) {
            let [m, d, y] = parts.map(p => parseInt(p, 10));
            if (Number.isNaN(m) || Number.isNaN(d) || Number.isNaN(y)) return '';
            if (y < 100) y += (y < 50) ? 2000 : 1900;
            const mm = String(m).padStart(2, '0');
            const dd = String(d).padStart(2, '0');
            return `${y}-${mm}-${dd}`;
        }

        const t = new Date(first);
        if (Number.isNaN(t.getTime())) return '';
        return t.toISOString().slice(0, 10);
    }

    function normalizeOneReport(report) {
        if (!report || typeof report !== 'object') return null;

        // Legacy flat CA JSON already has facility_number and visit/report fields.
        if (report.facility_number) {
            return report;
        }

        // New API schema: flatten reports[*] with categories and facility-level info.
        const categories = (report.categories && typeof report.categories === 'object') ? report.categories : {};
        const rawDate = safeString(report.report_date || categories.report_date || categories.visit_date);
        const fallbackVisit = parseUsDateToIso(rawDate);

        return {
            facility_number: safeString(report.facility_number || report.program_name || ''),
            facility_name: safeString(report.facility_name || report.program_name || ''),
            administrator: safeString(report.executive_director || categories.administrator || ''),
            facility_type_name: safeString(report.program_category || categories.facility_type_name || ''),
            capacity: safeString(report.bed_capacity || categories.capacity || ''),
            census: safeString(categories.census || ''),
            report_type: safeString(categories.report_type || ''),
            form_number: safeString(categories.form_number || ''),
            report_date: safeString(rawDate),
            visit_date: safeString(categories.visit_date || fallbackVisit),
            date_signed: safeString(categories.date_signed || ''),
            met_with: safeString(categories.met_with || ''),
            complaint_status: safeString(categories.complaint_status || ''),
            narrative: safeString(categories.narrative || report.raw_content || ''),
            investigation_findings: safeString(categories.investigation_findings || ''),
            deficiencies: Array.isArray(categories.deficiencies) ? categories.deficiencies : [],
            allegations: Array.isArray(categories.allegations) ? categories.allegations : [],
            source_url: safeString(categories.source_url || ''),
            report_id: safeString(report.report_id || ''),
            report_index: safeString(categories.report_index || ''),
        };
    }

    function normalizeRawReports(rawPayload) {
        const normalized = [];

        (rawPayload || []).forEach(item => {
            if (!item || typeof item !== 'object') return;

            // New API wrapper shape: { facilities: [...] }
            if (Array.isArray(item.facilities)) {
                item.facilities.forEach(facility => {
                    const facilityInfo = (facility && facility.facility_info) || {};
                    const reports = (facility && Array.isArray(facility.reports)) ? facility.reports : [];
                    reports.forEach(rep => {
                        const flattened = normalizeOneReport({
                            ...rep,
                            facility_number: rep && rep.facility_number ? rep.facility_number : facilityInfo.program_name,
                            program_name: facilityInfo.program_name,
                            facility_name: facilityInfo.facility_name,
                            executive_director: facilityInfo.executive_director,
                            program_category: facilityInfo.program_category,
                            bed_capacity: facilityInfo.bed_capacity,
                        });
                        if (flattened) normalized.push(flattened);
                    });
                });
                return;
            }

            // Direct array of facilities shape: [{facility_info, reports:[]}, ...]
            if (item.facility_info && Array.isArray(item.reports)) {
                const facilityInfo = item.facility_info || {};
                item.reports.forEach(rep => {
                    const flattened = normalizeOneReport({
                        ...rep,
                        facility_number: rep && rep.facility_number ? rep.facility_number : facilityInfo.program_name,
                        program_name: facilityInfo.program_name,
                        facility_name: facilityInfo.facility_name,
                        executive_director: facilityInfo.executive_director,
                        program_category: facilityInfo.program_category,
                        bed_capacity: facilityInfo.bed_capacity,
                    });
                    if (flattened) normalized.push(flattened);
                });
                return;
            }

            const flattened = normalizeOneReport(item);
            if (flattened) normalized.push(flattened);
        });

        // Deduplicate at report level to prevent repeated cards when multiple sources overlap.
        const seen = new Set();
        return normalized.filter(report => {
            const key = [
                safeString(report.facility_number),
                safeString(report.report_id),
                safeString(report.report_date),
                safeString(report.visit_date),
                safeString(report.report_type),
                safeString(report.narrative).slice(0, 120),
            ].join('|');

            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
    }

    function pickMostUsefulCandidate(candidates) {
        const scored = new Map();

        candidates.forEach(candidate => {
            const cleaned = cleanFacilityNameCandidate(candidate);
            if (!cleaned) return;

            const key = cleaned.toLowerCase();
            if (!scored.has(key)) {
                scored.set(key, { name: cleaned, count: 0 });
            }

            const entry = scored.get(key);
            entry.count += 1;

            // Keep the most descriptive variant when the normalized key matches.
            if (cleaned.length > entry.name.length) {
                entry.name = cleaned;
            }
        });

        return Array.from(scored.values())
            .sort((a, b) => (b.count - a.count) || (b.name.length - a.name.length))
            .map(entry => entry.name)[0] || '';
    }

    function inferFacilityNameFromText(text) {
        const source = normalizeWhitespace(text);
        if (!source) return '';

        const patterns = [
            /\b([A-Z][A-Za-z0-9&'.,#()\/\- ]{4,120}?)\s+Short Term Residential Therapeutic Program\s*\(STRTP\)(?:\b|[.])/i,
            /\b([A-Z][A-Za-z0-9&'.,#()\/\- ]{4,120}?)\s+Enhanced Behavioral Support Home\s*\(EBSH\)(?:\b|[.])/i,
            /\b([A-Z][A-Za-z0-9&'.,#()\/\- ]{4,120}?)\s+Community Crisis Home\s*\(CCH\)(?:\b|[.])/i,
            /\bheld at the\s+([A-Z][A-Za-z0-9&'.,#()\/\- ]{4,120}?(?:Program|Center|Home|House|Ranch|School))(?:\b|[.])/i,
            // Broad last-resort: only match when facility-type word is the FINAL word of the name.
            /(?:^|[.!?]\s+|\bthe\s+)([A-Z][A-Za-z0-9&'.,()\/\- ]{4,100}?\s+(?:Group Home|Crisis Home|Group|House|Ranch|Center|Services|School|Foundation|Academy|Program))\s*(?:[,.]|$)/,
        ];

        for (const pattern of patterns) {
            const match = source.match(pattern);
            if (!match || !match[1]) continue;

            const candidate = cleanFacilityNameCandidate(match[1]);
            if (!candidate) continue;

            const lower = candidate.toLowerCase();
            if (lower === 'the facility' || lower === 'above facility' || lower === 'the above facility') {
                continue;
            }
            if (/\b(?:licensing program|lpa\b|lpas\b|analyst\b|manager\b|this meeting was held|process for|above listed facility)\b/i.test(candidate)) {
                continue;
            }

            return candidate;
        }

        return '';
    }

    function pickLatestNonEmpty(rows, field) {
        const sorted = [...rows].sort((a, b) => reportDateMs(b.visit_date || b.report_date) - reportDateMs(a.visit_date || a.report_date));
        for (const row of sorted) {
            const value = normalizeWhitespace(row && row[field]);
            if (value) return value;
        }
        return '';
    }

    function resolveFacilityName(rows, facilityNumber) {
        const explicit = pickMostUsefulCandidate(rows.map(row => row && row.facility_name));
        if (explicit) return explicit;

        const inferred = pickMostUsefulCandidate(rows.flatMap(row => ([
            inferFacilityNameFromText(row && row.narrative),
            inferFacilityNameFromText(row && row.investigation_findings),
        ])));
        if (inferred) return inferred;

        return `Facility #${facilityNumber}`;
    }
    
    // --- GET HTML ELEMENTS ---
    const reportContainer = document.getElementById('report-container');
    const alphabetFilter = document.getElementById('alphabet-filter');
    let allFacilitiesData = {};
    const searchInput = document.getElementById('searchInput');
    const sortSelect = document.getElementById('sortBy');
    let currentLetter = null;
    let isSearching = false;
    const clearButton = document.getElementById('clearSearch');
    const newOnlyCheckbox = document.getElementById('newReportsOnly');
    let scrapedTimestamp = '';

    const NEW_REPORT_WINDOW_MS = 30 * 24 * 60 * 60 * 1000;

        if (searchInput) {
        searchInput.addEventListener('input', filterAndSort);
    }
    if (sortSelect) {
        sortSelect.addEventListener('change', filterAndSort);
    }
    if (newOnlyCheckbox) {
        newOnlyCheckbox.addEventListener('change', filterAndSort);
    }

    function reportDateMs(dateStr) {
        if (!dateStr) return 0;
        const first = String(dateStr).split(/\s*[-–]\s*/)[0].trim();
        const parts = first.split('/');
        if (parts.length === 3) {
            let [m, d, y] = parts.map(p => parseInt(p, 10));
            if (y < 100) y += (y < 50) ? 2000 : 1900;
            const t = new Date(y, m - 1, d).getTime();
            return isNaN(t) ? 0 : t;
        }
        const t = new Date(first).getTime();
        return isNaN(t) ? 0 : t;
    }

    function isRecentReport(item) {
        if (!item) return false;
        const t = reportDateMs(item.inspection_date || item.report_date);
        return t > 0 && (Date.now() - t) <= NEW_REPORT_WINDOW_MS;
    }
    
    async function initializeReport() {
        try {
            console.log('Starting to initialize report...');

            // Check if configuration data exists
            if (!themeData) {
                console.error('ERROR: caReportsData is undefined');
                reportContainer.innerHTML = '<p class="error">Configuration error: caReportsData not loaded. Check WordPress functions.php</p>';
                return;
            }

            // It now gets the ARRAY of URLs from the configuration object
            const urls = Array.isArray(themeData.jsonFileUrls) ? themeData.jsonFileUrls : [];
            console.log('URLs to fetch:', urls);
            console.log('Number of URLs:', urls ? urls.length : 0);

            if (!urls || urls.length === 0) {
                console.error('ERROR: No URLs found in caReportsData.jsonFileUrls');
                reportContainer.innerHTML = '<p>No data files found to load. Check that JSON files exist in /js/data/ directory.</p>';
                return;
            }

            // Fetch all 21 files concurrently for better performance
            const fetchPromises = urls.map(url => fetch(url));
            const responses = await Promise.all(fetchPromises);
            const lastModifiedDates = responses
                .map(r => r.headers.get('Last-Modified'))
                .filter(Boolean)
                .map(s => new Date(s))
                .filter(d => !isNaN(d.getTime()));
            if (lastModifiedDates.length > 0) {
                scrapedTimestamp = new Date(Math.max(...lastModifiedDates.map(d => d.getTime()))).toISOString();
                renderLastUpdated();
            }
            const jsonPromises = responses.map(response => response.ok ? response.json() : Promise.resolve([]));
            const allJsonData = await Promise.all(jsonPromises);

            // Normalize mixed payloads (legacy flat rows and API facility/reports rows).
            const rawReports = normalizeRawReports(allJsonData.flat());
            console.log(`Loaded ${rawReports.length} raw reports`);
            
            // The rest of the logic remains the same
            const aggregatedFacilities = aggregateReportsIntoFacilities(rawReports);
            console.log(`Aggregated into ${aggregatedFacilities.length} facilities`);

            const mergedFacilities = mergeFacilitiesByName(aggregatedFacilities);
            console.log(`After name-merge: ${mergedFacilities.length} facilities`);

            allFacilitiesData = groupFacilitiesFromArray(mergedFacilities);
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
        const groupedReports = reports.reduce((acc, report) => {
            const num = safeString(report && report.facility_number);
            if (!num) return acc;

            if (!acc[num]) {
                acc[num] = [];
            }

            acc[num].push(report);
            return acc;
        }, {});

        return Object.entries(groupedReports).map(([num, facilityReports]) => {
            const dedupedFacilityReports = [];
            const reportKeys = new Set();
            facilityReports.forEach(report => {
                const reportKey = [
                    safeString(report.report_id),
                    safeString(report.report_date),
                    safeString(report.visit_date),
                    safeString(report.report_type),
                    safeString(report.narrative).slice(0, 120),
                ].join('|');
                if (reportKeys.has(reportKey)) return;
                reportKeys.add(reportKey);
                dedupedFacilityReports.push(report);
            });

            const inspections = [...dedupedFacilityReports].sort((a, b) => reportDateMs(b.visit_date || b.report_date) - reportDateMs(a.visit_date || a.report_date));
            const facilityType = pickMostUsefulCandidate(facilityReports.map(report => report && report.facility_type_name))
                || pickLatestNonEmpty(facilityReports, 'facility_type_name');
            const officer = pickLatestNonEmpty(facilityReports, 'administrator');
            const capacity = pickLatestNonEmpty(facilityReports, 'capacity');

            return {
                number: num,
                name: resolveFacilityName(facilityReports, num),
                facility_type: facilityType,
                officer,
                capacity,
                inspections,
            };
        });
    }

    // --- MERGE FACILITIES WITH THE SAME RESOLVED NAME ---
    // A single operator may hold multiple license numbers (e.g. different buildings)
    // that all share the same programme name. Collapse them into one card so the
    // same facility doesn't appear as several separate entries.
    function isAddressLikeName(name) {
        return isStreetAddressLike(name);
    }

    function mergeFacilitiesByName(facilitiesArray) {
        const nameMap = new Map(); // normalizedName -> merged facility object

        facilitiesArray.forEach(facility => {
            const name = (facility.name || '').trim();
            const isFallback = /^Facility\s*#/i.test(name);
            // Don't merge on address-like names, very short names, or fallbacks — keep those separate.
            const isMergeable = !isFallback && !isAddressLikeName(name) && name.length >= 8;
            const key = isMergeable
                ? name.toLowerCase()
                : `__unique__${facility.number}`;

            if (!nameMap.has(key)) {
                // First occurrence: seed with a copy so we can mutate it safely.
                nameMap.set(key, {
                    ...facility,
                    // Keep all facility numbers for reference; start as array.
                    numbers: [facility.number],
                    inspections: [...(facility.inspections || [])],
                });
            } else {
                const existing = nameMap.get(key);
                if (!existing.numbers.includes(facility.number)) {
                    existing.numbers.push(facility.number);
                }
                // Merge inspections, tagging each with its originating number.
                (facility.inspections || []).forEach(insp => {
                    existing.inspections.push(insp);
                });
                // Prefer whichever record has the richer metadata.
                if (!existing.facility_type && facility.facility_type) existing.facility_type = facility.facility_type;
                if (!existing.officer && facility.officer) existing.officer = facility.officer;
                if (!existing.capacity && facility.capacity) existing.capacity = facility.capacity;
            }
        });

        return Array.from(nameMap.values()).map(facility => {
            // Sort merged inspections newest-first and dedupe.
            const seen = new Set();
            const deduped = facility.inspections
                .sort((a, b) => reportDateMs(b.visit_date || b.report_date) - reportDateMs(a.visit_date || a.report_date))
                .filter(insp => {
                    const k = [
                        safeString(insp.report_id),
                        safeString(insp.report_date),
                        safeString(insp.visit_date),
                        safeString(insp.report_type),
                        safeString(insp.narrative).slice(0, 120),
                    ].join('|');
                    if (seen.has(k)) return false;
                    seen.add(k);
                    return true;
                });

            // Expose merged numbers; keep .number as primary for links.
            return { ...facility, inspections: deduped };
        });
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
        }, { passive: false });
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
                const name = safeString(facility.name).toLowerCase();
                const facilityType = safeString(facility.facility_type).toLowerCase();
                const officer = safeString(facility.officer).toLowerCase();
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
        let processedFacilities = [...facilities];

        if (newOnlyCheckbox && newOnlyCheckbox.checked) {
            processedFacilities = processedFacilities.map(facility => {
                const recent = (facility.inspections || []).filter(isRecentReport);
                return recent.length ? { ...facility, inspections: recent } : null;
            }).filter(facility => facility !== null);
        }

        if (!sortBy) return processedFacilities;

        console.log(`Sorting ${processedFacilities.length} facilities by: ${sortBy}`);

        // Filter inspections to show only those with violations
        if (sortBy === 'violations-only' || sortBy === 'violations-desc') {
            processedFacilities = processedFacilities.map(facility => {
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
                        <h1>${toTitleCase(safeString(facility.name)) || 'N/A'}</h1>
                        <h2>Facility Number: ${facility.number || 'N/A'}</h2>
                        <p class="facility-details">Type: ${safeString(facility.facility_type) || 'N/A'} | Administrator: ${toTitleCase(safeString(facility.officer)) || 'N/A'} | Licensed Capacity: ${facility.capacity || 'N/A'}</p>
                    </summary>
                    <div class="inspections-container">
                        ${(facility.inspections || []).map(createInspectionHTML).join('')}
                    </div>
                </details>
            `;
            reportContainer.appendChild(facilityElement);
        });
    }

    function renderLastUpdated() {
        const el = document.getElementById('last-updated');
        if (!el) return;
        if (!scrapedTimestamp) { el.innerHTML = ''; return; }
        const parsed = new Date(scrapedTimestamp);
        const updateDate = isNaN(parsed.getTime())
            ? scrapedTimestamp
            : parsed.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        el.innerHTML = `<p>Last updated: ${updateDate}</p>`;
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
                ${safeString(inspection.report_type) || 'Report'} - ${safeString(inspection.visit_date) || 'N/A'}
            </summary>
            <div class="inspection-content">
                <div class="inspection-details-block">
                    <strong>Report Type:</strong> ${safeString(inspection.report_type) || 'N/A'}<br>
                    <strong>Visit Date:</strong> ${safeString(inspection.visit_date) || 'N/A'}<br>
                    <strong>Report Date:</strong> ${safeString(inspection.report_date) || 'N/A'}<br>
                    <strong>Form Number:</strong> ${safeString(inspection.form_number) || 'N/A'}<br>
                    <strong>Census:</strong> ${safeString(inspection.census) || 'N/A'}<br>
                    <strong>Complaint Status:</strong> ${safeString(inspection.complaint_status) || 'N/A'}<br>
                    <strong>Met With:</strong> ${safeString(inspection.met_with) || 'N/A'}<br>
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
        const section = safeString(deficiency.section_cited || '').replace(/\n/g, '<br>');
        const description = safeString(deficiency.description || '').replace(/\n/g, '<br>');
        const poc = safeString(deficiency.plan_of_correction || '').replace(/\n/g, '<br>');
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
