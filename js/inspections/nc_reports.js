document.addEventListener('DOMContentLoaded', () => {
    const reportContainer = document.getElementById('report-container');
    if (!reportContainer) {
        console.error('ERROR: #report-container element not found on page');
        return;
    }

    const alphabetFilter = document.getElementById('alphabet-filter');
    const searchInput = document.getElementById('searchInput');
    const sortSelect = document.getElementById('sortBy');
    const clearButton = document.getElementById('clearSearch');
    const newOnlyCheckbox = document.getElementById('newReportsOnly');

    const NEW_REPORT_WINDOW_MS = 30 * 24 * 60 * 60 * 1000;
    const stateName = 'North Carolina';
    const stateCode = 'NC';

    let allFacilitiesData = {};
    let currentLetter = null;
    let isSearching = false;
    let scrapedTimestamp = '';

    if (searchInput) searchInput.addEventListener('input', filterAndSort);
    if (sortSelect) sortSelect.addEventListener('change', filterAndSort);
    if (newOnlyCheckbox) newOnlyCheckbox.addEventListener('change', filterAndSort);

    async function initializeReport() {
        try {
            const apiUrl = `/wp-content/themes/child/api/inspections-read.php?state=${stateCode}`;
            const resp = await fetch(apiUrl);
            if (!resp.ok) throw new Error(`API returned ${resp.status}`);
            const apiData = await resp.json();

            if (!apiData.facilities || apiData.facilities.length === 0) {
                reportContainer.innerHTML = `<p>No facilities found in the database for ${stateName}.</p>`;
                return;
            }

            scrapedTimestamp = apiData.scraped_timestamp || '';
            renderLastUpdated();

            const facilitiesArray = convertApiDataToFacilities(apiData.facilities);
            allFacilitiesData = groupFacilitiesFromArray(facilitiesArray);

            renderAlphabetFilter();
            renderFacilitiesForLetter('ALL');
        } catch (error) {
            console.warn('API unavailable, trying fallback JSON:', error.message);
            await loadFromFallback();
        }
    }

    async function loadFromFallback() {
        const urls = (window.ncReportsData && window.ncReportsData.jsonFileUrls) || [];
        if (!urls.length) {
            reportContainer.innerHTML = `<p>No ${stateName} inspection data available yet.</p>`;
            return;
        }

        for (const url of urls) {
            try {
                const resp = await fetch(url);
                if (!resp.ok) continue;
                const data = await resp.json();
                if (!data.facilities || !data.facilities.length) continue;

                scrapedTimestamp = data.scraped_timestamp || '';
                renderLastUpdated();

                const facilitiesArray = convertApiDataToFacilities(data.facilities);
                allFacilitiesData = groupFacilitiesFromArray(facilitiesArray);

                renderAlphabetFilter();
                renderFacilitiesForLetter('ALL');
                return;
            } catch (e) {
                console.warn('Failed to load fallback:', url, e.message);
            }
        }

        reportContainer.innerHTML = `<p>No ${stateName} inspection data could be loaded.</p>`;
    }

    function convertApiDataToFacilities(apiFacilities) {
        return (apiFacilities || []).map(facility => {
            const info = facility.facility_info || {};
            const reports = (facility.reports || []).map(report => {
                const cats = report.categories || {};
                return {
                    report_id: report.report_id || '',
                    report_date: report.report_date || '',
                    report_url: report.report_url || cats.doc_page_url || '',
                    summary: report.summary || '',
                    raw_content: report.raw_content || '',
                    content_length: report.content_length || 0,
                    doc_type: cats.doc_type || report.doc_type || 'Document',
                    tags: Array.isArray(cats.tags) ? cats.tags : [],
                    pdf_url: cats.pdf_url || '',
                    doc_page_url: cats.doc_page_url || '',
                    facility_name: info.facility_name || '',
                };
            }).sort((a, b) => parseDate(b.report_date) - parseDate(a.report_date));

            return {
                name: info.facility_name || '',
                program_name: info.program_name || '',
                program_category: info.program_category || '',
                address: info.full_address || info.city_state_zip || '',
                city: info.city || '',
                reports: reports,
            };
        });
    }

    function parseDate(dateStr) {
        if (!dateStr) return new Date(0);
        const first = String(dateStr).split(/\s*[-–]\s*/)[0].trim();
        const slashParts = first.split('/');
        if (slashParts.length === 3) {
            let [m, d, y] = slashParts.map(p => parseInt(p, 10));
            if (y < 100) y += (y < 50) ? 2000 : 1900;
            return new Date(y, m - 1, d);
        }
        const parsed = new Date(first);
        return isNaN(parsed.getTime()) ? new Date(0) : parsed;
    }

    function reportHasViolations(report) {
        const docType = safeString(report.doc_type);
        const content = safeString(report.raw_content);
        const tags = Array.isArray(report.tags) ? report.tags.join(' ') : '';
        return /violation|deficien|non[-\s]?compliance|citation|disciplinary|corrective order|maltreatment|revocation|fine order/i.test(docType + ' ' + content + ' ' + tags);
    }

    function isRecentReport(report) {
        const t = parseDate(report && report.report_date).getTime();
        return t > 0 && (Date.now() - t) <= NEW_REPORT_WINDOW_MS;
    }

    function groupFacilitiesFromArray(facilitiesArray) {
        const grouped = facilitiesArray.reduce((acc, facility) => {
            const first = (facility.name || '').charAt(0).toUpperCase();
            const key = (first >= 'A' && first <= 'Z') ? first : '#';
            if (!acc[key]) acc[key] = [];
            acc[key].push(facility);
            return acc;
        }, {});

        Object.keys(grouped).forEach(letter => {
            grouped[letter].sort((a, b) => (a.name || '').localeCompare(b.name || ''));
        });
        return grouped;
    }

    function renderAlphabetFilter() {
        if (!alphabetFilter) return;
        const letters = Object.keys(allFacilitiesData).sort();
        alphabetFilter.innerHTML = [
            '<a href="#" data-letter="ALL">All</a>',
            ...letters.map(letter => `<a href="#" data-letter="${letter}">${letter}</a>`),
        ].join('');

        alphabetFilter.addEventListener('click', (e) => {
            e.preventDefault();
            if (e.target.tagName === 'A') {
                renderFacilitiesForLetter(e.target.dataset.letter);
            }
        }, { passive: false });
    }

    function getFacilitiesForSelection(letter) {
        if (letter === 'ALL') {
            return Object.keys(allFacilitiesData).sort().reduce((all, key) => all.concat(allFacilitiesData[key] || []), []);
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
        const term = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const sortBy = sortSelect ? sortSelect.value : '';

        if (!Object.keys(allFacilitiesData).length) return;

        if (term) {
            isSearching = true;
            if (clearButton) clearButton.style.display = 'inline-block';
            document.querySelectorAll('#alphabet-filter a').forEach(a => a.classList.remove('active'));

            const all = [].concat(...Object.values(allFacilitiesData));
            const filtered = all.filter(f => {
                const haystack = [
                    f.name,
                    f.program_name,
                    f.program_category,
                    f.address,
                    ...(f.reports || []).map(r => [r.doc_type, r.summary, r.raw_content].join(' ')),
                ].map(v => (v || '').toLowerCase()).join(' ');
                return haystack.includes(term);
            });

            renderFilteredFacilities(sortFacilities(filtered, sortBy), 'Search Results');
        } else {
            isSearching = false;
            if (clearButton) clearButton.style.display = 'none';
            renderFacilitiesForLetter(currentLetter || 'ALL');
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
                const recent = (f.reports || []).filter(isRecentReport);
                return recent.length ? { ...f, reports: recent } : null;
            }).filter(Boolean);
        }
        if (sortBy === 'violations-only' || sortBy === 'violations-desc') {
            processed = processed.map(f => {
                const filteredReports = (f.reports || []).filter(reportHasViolations);
                return filteredReports.length ? { ...f, reports: filteredReports } : null;
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
                    return getMostRecentDate(b) - getMostRecentDate(a);
                default:
                    return 0;
            }
        });
    }

    function countViolations(facility) {
        return (facility.reports || []).filter(reportHasViolations).length;
    }

    function getMostRecentDate(facility) {
        if (!facility.reports || !facility.reports.length) return new Date(0);
        const dates = facility.reports.map(r => parseDate(r.report_date)).filter(d => d.getTime() > 0);
        return dates.length ? new Date(Math.max(...dates.map(d => d.getTime()))) : new Date(0);
    }

    function renderFilteredFacilities(facilities, context) {
        reportContainer.innerHTML = '';
        if (!facilities || !facilities.length) {
            reportContainer.innerHTML = `<p>No facilities found${context ? ` for "${escapeHtml(context)}"` : ''}.</p>`;
            return;
        }

        if (isSearching) {
            const header = document.createElement('div');
            header.style.cssText = 'margin-bottom:20px;padding:10px;background:#e8f4f8;border-radius:4px;font-weight:bold;';
            header.textContent = `Found ${facilities.length} facilities matching your search`;
            reportContainer.appendChild(header);
        }

        facilities.forEach(facility => {
            const total = (facility.reports || []).length;
            const violations = countViolations(facility);
            const el = document.createElement('div');
            el.className = 'facility-box';
            el.innerHTML = `
                <details>
                    <summary class="facility-header">
                        <h1>${escapeHtml(facility.name) || 'N/A'}</h1>
                        <h2>${escapeHtml(facility.program_category) || 'Residential'}</h2>
                        <p class="facility-details">
                            ${total} document${total === 1 ? '' : 's'}${violations ? ` &middot; ${violations} flagged` : ''}
                        </p>
                    </summary>
                    <div class="inspections-container">
                        ${(facility.reports || []).map(createReportHTML).join('')}
                    </div>
                </details>
            `;
            reportContainer.appendChild(el);
        });
    }

    function createReportHTML(report) {
        const dateStr = escapeHtml(report.report_date) || 'Date unknown';
        const typeStr = escapeHtml(report.doc_type || 'Document');
        const summaryHtml = report.summary ? `<p class="doc-summary">${escapeHtml(report.summary)}</p>` : '';
        const excerpt = report.raw_content ? `<details class="doc-text"><summary>Full document text</summary><div>${escapeHtml(report.raw_content).replace(/\n/g, '<br>')}</div></details>` : '';
        const sourceUrl = report.doc_page_url || report.report_url || report.pdf_url || '';
        const sourceHtml = sourceUrl ? `<div class="doc-source-link"><a href="${escapeAttr(sourceUrl)}" target="_blank" rel="noopener">View source document</a></div>` : '';
        const tagsHtml = (report.tags || []).length ? `<div class="doc-tags">${report.tags.map(tag => `<span class="doc-tag">${escapeHtml(tag)}</span>`).join('')}</div>` : '';

        return `
            <details class="inspection-box ${reportHasViolations(report) ? 'inspection-box-violation' : 'inspection-box-clean'}">
                <summary class="inspection-header">
                    <span class="doc-type-label">${typeStr}</span>
                    <span class="doc-date">${dateStr}</span>
                    ${tagsHtml}
                </summary>
                <div class="inspection-content">
                    ${summaryHtml}
                    ${excerpt}
                    ${sourceHtml}
                </div>
            </details>
        `;
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

        if (!scrapedTimestamp) {
            el.innerHTML = '';
            return;
        }

        const parsed = new Date(scrapedTimestamp);
        const updateDate = isNaN(parsed.getTime())
            ? scrapedTimestamp
            : parsed.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        el.innerHTML = `<p>Last updated: ${updateDate}</p>`;
    }

    function safeString(value) {
        if (value === null || value === undefined) return '';
        if (typeof value === 'string') return value.trim();
        if (typeof value === 'number' || typeof value === 'boolean') return String(value);
        if (Array.isArray(value)) return value.map(safeString).filter(Boolean).join(', ');
        return String(value).trim();
    }

    function escapeHtml(text) {
        return safeString(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeAttr(text) {
        return escapeHtml(text).replace(/`/g, '&#096;');
    }

    initializeReport();
});
