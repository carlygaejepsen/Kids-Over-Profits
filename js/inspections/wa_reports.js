document.addEventListener('DOMContentLoaded', () => {
    // --- GET HTML ELEMENTS ---
    const reportContainer = document.getElementById('report-container');
    if (!reportContainer) {
        console.error('ERROR: #report-container element not found on page');
        return;
    }

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

    if (searchInput) searchInput.addEventListener('input', filterAndSort);
    if (sortSelect) sortSelect.addEventListener('change', filterAndSort);
    if (newOnlyCheckbox) newOnlyCheckbox.addEventListener('change', filterAndSort);

    function isRecentInspection(inspection) {
        if (!inspection) return false;
        const t = parseDate(inspection.inspection_date || inspection.report_date).getTime();
        return t > 0 && (Date.now() - t) <= NEW_REPORT_WINDOW_MS;
    }

    const safeString = value => {
        if (value === null || value === undefined) return '';
        if (typeof value === 'string') return (value.trim() !== '[object Object]') ? value.trim() : '';
        if (typeof value === 'number' || typeof value === 'boolean') return String(value);
        if (Array.isArray(value)) return value.map(v => safeString(v)).filter(Boolean).join(', ');
        if (typeof value === 'object') {
            for (const f of ['name', 'Name', 'fullName', 'value', 'text', 'title']) {
                if (value[f]) return safeString(value[f]);
            }
            return '';
        }
        const str = String(value);
        return (str !== '[object Object]') ? str : '';
    };

    async function initializeReport() {
        try {
            const apiUrl = '/wp-content/themes/child/api/inspections-read.php?state=WA';
            const resp = await fetch(apiUrl);
            if (!resp.ok) throw new Error(`API returned ${resp.status}`);
            const apiData = await resp.json();

            if (!apiData.facilities || apiData.facilities.length === 0) {
                reportContainer.innerHTML = '<p>No facilities found in the database for Washington.</p>';
                return;
            }

            scrapedTimestamp = apiData.scraped_timestamp || '';
            renderLastUpdated();

            const facilitiesArray = convertApiDataToFacilities(apiData.facilities);
            allFacilitiesData = groupFacilitiesFromArray(facilitiesArray);

            renderAlphabetFilter();
            if (Object.keys(allFacilitiesData).length) {
                renderFacilitiesForLetter('ALL');
            } else {
                reportContainer.innerHTML = '<p>No facilities found in the data.</p>';
            }
        } catch (error) {
            console.error('Failed to load Washington report data:', error);
            reportContainer.innerHTML = `<p class="error">Error loading data: ${error.message}</p>`;
        }
    }

    function convertApiDataToFacilities(apiFacilities) {
        return apiFacilities.map(facility => {
            const info = facility.facility_info || {};
            const inspections = (facility.reports || []).map(report => {
                const cats = report.categories || {};
                return {
                    inspection_number: cats.inspection_number || report.report_id || '',
                    inspection_date: cats.inspection_date || report.report_date || '',
                    inspection_type: cats.inspection_type || '',
                    inspector: cats.inspector || '',
                    service_types: cats.service_types || '',
                    report_category: cats.report_category || '',
                    report_type: cats.report_type || '',
                    pdf_url: cats.pdf_url || '',
                    deficiencies: cats.deficiencies || [],
                };
            }).sort((a, b) => parseDate(b.inspection_date) - parseDate(a.inspection_date));

            return {
                name: info.facility_name || '',
                license_number: info.program_name || '',
                address: info.full_address || '',
                phone: info.phone || '',
                administrator: info.executive_director || '',
                facility_status: info.action || '',
                license_expires_date: info.license_exp_date || '',
                inspections: inspections,
            };
        });
    }

    function parseDate(dateStr) {
        if (!dateStr) return new Date(0);
        const first = String(dateStr).split(/\s*[-–]\s*/)[0].trim();
        const parts = first.split('/');
        if (parts.length === 3) {
            let [m, d, y] = parts.map(p => parseInt(p, 10));
            if (y < 100) y += (y < 50) ? 2000 : 1900;
            return new Date(y, m - 1, d);
        }
        const parsed = new Date(first);
        return isNaN(parsed.getTime()) ? new Date(0) : parsed;
    }

    function inspectionHasViolations(inspection) {
        return Array.isArray(inspection.deficiencies) && inspection.deficiencies.length > 0;
    }

    function groupFacilitiesFromArray(facilitiesArray) {
        const grouped = facilitiesArray.reduce((acc, facility) => {
            const firstLetter = (facility.name || '').charAt(0).toUpperCase();
            const key = (firstLetter >= 'A' && firstLetter <= 'Z') ? firstLetter : '#';
            if (!acc[key]) acc[key] = [];
            acc[key].push(facility);
            return acc;
        }, {});
        for (const letter in grouped) {
            grouped[letter].sort((a, b) => (a.name || '').localeCompare(b.name || ''));
        }
        return grouped;
    }

    function renderAlphabetFilter() {
        const letters = Object.keys(allFacilitiesData).sort();
        alphabetFilter.innerHTML = [`<a href="#" data-letter="ALL">All</a>`, ...letters.map(l => `<a href="#" data-letter="${l}">${l}</a>`)].join('');
        alphabetFilter.addEventListener('click', (e) => {
            e.preventDefault();
            if (e.target.tagName === 'A') renderFacilitiesForLetter(e.target.dataset.letter);
        }, { passive: false });
    }

    function getFacilitiesForSelection(letter) {
        if (letter === 'ALL') {
            return Object.keys(allFacilitiesData).sort()
                .reduce((all, l) => all.concat(allFacilitiesData[l] || []), []);
        }
        return allFacilitiesData[letter] || [];
    }

    function renderFacilitiesForLetter(letter) {
        if (isSearching) return;
        currentLetter = letter;
        document.querySelectorAll('#alphabet-filter a').forEach(a => {
            a.classList.toggle('active', a.dataset.letter === letter);
        });
        const facilities = getFacilitiesForSelection(letter);
        renderFilteredFacilities(sortFacilities(facilities, sortSelect ? sortSelect.value : ''), letter);
    }

    function filterAndSort() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const sortBy = sortSelect ? sortSelect.value : '';
        if (!allFacilitiesData || Object.keys(allFacilitiesData).length === 0) return;

        if (searchTerm) {
            isSearching = true;
            if (clearButton) clearButton.style.display = 'inline-block';
            document.querySelectorAll('#alphabet-filter a').forEach(a => a.classList.remove('active'));

            const all = [].concat(...Object.values(allFacilitiesData));
            const filtered = all.filter(f => {
                const s = [f.name, f.license_number, f.address, f.administrator]
                    .map(v => (v || '').toLowerCase()).join(' ');
                return s.includes(searchTerm);
            });
            renderFilteredFacilities(sortFacilities(filtered, sortBy), 'Search Results');
        } else {
            isSearching = false;
            if (clearButton) clearButton.style.display = 'none';
            if (currentLetter && (currentLetter === 'ALL' || allFacilitiesData[currentLetter])) {
                renderFacilitiesForLetter(currentLetter);
            } else {
                renderFacilitiesForLetter('ALL');
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
        let processed = [...facilities];
        if (newOnlyCheckbox && newOnlyCheckbox.checked) {
            processed = processed.map(f => {
                const recent = (f.inspections || []).filter(isRecentInspection);
                return recent.length ? { ...f, inspections: recent } : null;
            }).filter(Boolean);
        }
        if (!sortBy) return processed;
        if (sortBy === 'violations-only' || sortBy === 'violations-desc') {
            processed = processed.map(f => {
                const v = f.inspections.filter(inspectionHasViolations);
                return v.length > 0 ? { ...f, inspections: v } : null;
            }).filter(Boolean);
        }
        return processed.sort((a, b) => {
            switch (sortBy) {
                case 'name':
                case 'violations-only':
                    return (a.name || '').localeCompare(b.name || '');
                case 'violations-desc':
                    return countViolations(b) - countViolations(a);
                case 'recent-inspection':
                    return getMostRecentInspectionDate(b) - getMostRecentInspectionDate(a);
                default:
                    return 0;
            }
        });
    }

    function countViolations(facility) {
        if (!facility.inspections) return 0;
        return facility.inspections.reduce((t, i) => t + (i.deficiencies ? i.deficiencies.length : 0), 0);
    }

    function getMostRecentInspectionDate(facility) {
        if (!facility.inspections || facility.inspections.length === 0) return new Date(0);
        const dates = facility.inspections
            .map(i => parseDate(i.inspection_date))
            .filter(d => d.getTime() > 0);
        return dates.length > 0 ? new Date(Math.max(...dates.map(d => d.getTime()))) : new Date(0);
    }

    function renderFilteredFacilities(facilities, context) {
        reportContainer.innerHTML = '';
        if (!facilities || facilities.length === 0) {
            const message = isSearching ? 'No facilities found matching your search.' : `No facilities found for the letter "${context}".`;
            reportContainer.innerHTML = `<p>${message}</p>`;
            return;
        }

        if (isSearching) {
            const header = document.createElement('div');
            header.style.cssText = 'margin-bottom: 20px; padding: 10px; background: #e8f4f8; border-radius: 4px; font-weight: bold;';
            header.innerHTML = `Found ${facilities.length} facilities matching your search`;
            reportContainer.appendChild(header);
        }

        facilities.forEach(facility => {
            const el = document.createElement('div');
            el.className = 'facility-box';
            el.innerHTML = `
                <details>
                    <summary class="facility-header">
                        <h1>${toTitleCase(safeString(facility.name)) || 'N/A'}</h1>
                        <h2>License Number: ${facility.license_number || 'N/A'}</h2>
                        <p class="facility-details">
                            Administrator: ${toTitleCase(safeString(facility.administrator)) || 'N/A'}<br>
                            Status: ${safeString(facility.facility_status) || 'N/A'} |
                            License Expires: ${facility.license_expires_date || 'N/A'}
                        </p>
                        <p class="facility-address">${safeString(facility.address) || ''}</p>
                    </summary>
                    <div class="inspections-container">
                        ${(facility.inspections || []).map(createInspectionHTML).join('')}
                    </div>
                </details>
            `;
            reportContainer.appendChild(el);
        });
    }

    function renderLastUpdated() {
        let el = document.getElementById('last-updated');
        if (!el) {
            el = document.createElement('div');
            el.id = 'last-updated';
            el.className = 'last-updated';
            const anchor = document.querySelector('.facility-report-container') || reportContainer.parentNode;
            anchor.appendChild(el);
        }
        if (!scrapedTimestamp) { el.innerHTML = ''; return; }
        const parsed = new Date(scrapedTimestamp);
        const updateDate = isNaN(parsed.getTime())
            ? scrapedTimestamp
            : parsed.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        el.innerHTML = `<p>Last updated: ${updateDate}</p>`;
    }

    function toTitleCase(str) {
        if (!str) return str;
        const acronyms = ['LLC', 'LLP', 'INC', 'CORP', 'CO', 'LTD', 'MD', 'RN', 'LPN', 'LCSW',
            'CEO', 'COO', 'CFO', 'HR', 'IT', 'VP', 'DOH', 'USA', 'US', 'WA', 'RTF', 'FS', 'BHA', 'III', 'II'];
        const lowercaseWords = ['a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'if', 'in',
            'of', 'on', 'or', 'the', 'to', 'up', 'via', 'with', 'from'];
        const words = str.toLowerCase().split(/(\s+|-|\/)/);
        return words.map((word, index) => {
            if (/^\s+$|^-$|^\/$/.test(word)) return word;
            if (index !== 0 && lowercaseWords.includes(word.toLowerCase())) return word.toLowerCase();
            if (acronyms.includes(word.toUpperCase())) return word.toUpperCase();
            return word.charAt(0).toUpperCase() + word.slice(1).toLowerCase();
        }).join('');
    }

    function createInspectionHTML(inspection) {
        const hasViolations = inspectionHasViolations(inspection);
        const klass = hasViolations ? 'inspection-box-violation' : 'inspection-box-clean';
        const category = (inspection.report_category || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
        const heading = inspection.inspection_type || category || 'Inspection';

        return `
        <details class="inspection-box ${klass}">
            <summary class="inspection-header">
                ${heading} - ${inspection.inspection_date || 'N/A'}
            </summary>
            <div class="inspection-content">
                <div class="inspection-details-block">
                    <strong>Inspection Date:</strong> ${inspection.inspection_date || 'N/A'}<br>
                    <strong>Inspection Type:</strong> ${inspection.inspection_type || 'N/A'}<br>
                    <strong>Report Category:</strong> ${category || 'N/A'}<br>
                    <strong>Inspection Number:</strong> ${inspection.inspection_number || 'N/A'}<br>
                    <strong>Inspector:</strong> ${inspection.inspector || 'N/A'}<br>
                    <strong>Service Types:</strong> ${inspection.service_types || 'N/A'}<br>
                    ${inspection.pdf_url ? `<strong>Source:</strong> <a href="${inspection.pdf_url}" target="_blank" rel="noopener">View full PDF</a>` : ''}
                </div>
                <h4>Deficiencies:</h4>
                ${hasViolations
                    ? inspection.deficiencies.map((def, i) => createDeficiencyHTML(def, i)).join('')
                    : '<div class="no-violations"><p><strong>No violations noted in this inspection.</strong></p></div>'}
            </div>
        </details>
        `;
    }

    function createDeficiencyHTML(deficiency, index) {
        const text = typeof deficiency === 'string'
            ? deficiency
            : (deficiency.findings || deficiency.rule || deficiency.evidence || JSON.stringify(deficiency));
        const escaped = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        return `
            <details class="violation-box">
                <summary class="deficiency-header">Deficiency ${index + 1}</summary>
                <div class="deficiency-content">
                    <p>${escaped}</p>
                </div>
            </details>
        `;
    }

    initializeReport();
});
