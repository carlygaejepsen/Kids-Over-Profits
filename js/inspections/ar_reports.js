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

    let allFacilitiesData = {};
    let currentLetter = null;
    let isSearching = false;
    let scrapedTimestamp = '';

    const VIOLATION_DOC_TYPES = new Set(['Notice of Incident', 'Compliance Report', 'CFS Report']);
    const NEW_REPORT_WINDOW_MS = 30 * 24 * 60 * 60 * 1000;

    if (searchInput) searchInput.addEventListener('input', filterAndSort);
    if (sortSelect) sortSelect.addEventListener('change', filterAndSort);
    if (newOnlyCheckbox) newOnlyCheckbox.addEventListener('change', filterAndSort);

    function isRecentReport(report) {
        if (!report) return false;
        const t = parseDate(report.report_date).getTime();
        return t > 0 && (Date.now() - t) <= NEW_REPORT_WINDOW_MS;
    }

    const safeString = v => (v == null ? '' : String(v).trim());

    async function initializeReport() {
        try {
            const apiUrl = '/wp-content/themes/child/api/inspections-read.php?state=AR';
            const resp = await fetch(apiUrl);
            if (!resp.ok) throw new Error(`API returned ${resp.status}`);
            const apiData = await resp.json();

            if (!apiData.facilities || apiData.facilities.length === 0) {
                reportContainer.innerHTML = '<p>No facilities found in the database for Arkansas.</p>';
                return;
            }

            scrapedTimestamp = apiData.scraped_timestamp || '';
            renderLastUpdated();

            const facilitiesArray = convertApiDataToFacilities(apiData.facilities);
            allFacilitiesData = groupFacilitiesFromArray(facilitiesArray);

            renderAlphabetFilter();
            renderFacilitiesForLetter('ALL');
        } catch (error) {
            console.error('Failed to load Arkansas report data:', error);
            reportContainer.innerHTML = `<p class="error">Error loading data: ${error.message}</p>`;
        }
    }

    function convertApiDataToFacilities(apiFacilities) {
        return apiFacilities.map(facility => {
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
                    doc_type: cats.doc_type || 'Document',
                    tags: Array.isArray(cats.tags) ? cats.tags : [],
                    pdf_url: cats.pdf_url || '',
                    doc_page_url: cats.doc_page_url || '',
                };
            }).sort((a, b) => parseDate(b.report_date) - parseDate(a.report_date));

            return {
                name: info.facility_name || '',
                program_name: info.program_name || '',
                program_category: info.program_category || '',
                address: info.full_address || '',
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

    function reportIsViolation(r) {
        return VIOLATION_DOC_TYPES.has(r.doc_type) ||
               (r.tags || []).some(t => /citation|maltreatment|assault|abuse|sexual|violence|neglect|complaint/i.test(t));
    }

    function groupFacilitiesFromArray(arr) {
        const grouped = arr.reduce((acc, f) => {
            const first = (f.name || '').charAt(0).toUpperCase();
            const key = (first >= 'A' && first <= 'Z') ? first : '#';
            (acc[key] = acc[key] || []).push(f);
            return acc;
        }, {});
        for (const k in grouped) grouped[k].sort((a, b) => (a.name || '').localeCompare(b.name || ''));
        return grouped;
    }

    function renderAlphabetFilter() {
        const letters = Object.keys(allFacilitiesData).sort();
        alphabetFilter.innerHTML = [
            `<a href="#" data-letter="ALL">All</a>`,
            ...letters.map(l => `<a href="#" data-letter="${l}">${l}</a>`),
        ].join('');
        alphabetFilter.addEventListener('click', (e) => {
            e.preventDefault();
            if (e.target.tagName === 'A') renderFacilitiesForLetter(e.target.dataset.letter);
        });
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
        const term = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const sortBy = sortSelect ? sortSelect.value : '';
        if (!Object.keys(allFacilitiesData).length) return;

        if (term) {
            isSearching = true;
            if (clearButton) clearButton.style.display = 'inline-block';
            document.querySelectorAll('#alphabet-filter a').forEach(a => a.classList.remove('active'));
            const all = [].concat(...Object.values(allFacilitiesData));
            const filtered = all.filter(f =>
                [f.name, f.program_name, f.program_category]
                    .map(v => (v || '').toLowerCase()).join(' ').includes(term)
            );
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
        if (!sortBy) return processed;
        if (sortBy === 'violations-only' || sortBy === 'violations-desc') {
            processed = processed.map(f => {
                const v = f.reports.filter(reportIsViolation);
                return v.length ? { ...f, reports: v } : null;
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

    function countViolations(f) {
        return (f.reports || []).filter(reportIsViolation).length;
    }

    function getMostRecentDate(f) {
        if (!f.reports || !f.reports.length) return new Date(0);
        const dates = f.reports.map(r => parseDate(r.report_date)).filter(d => d.getTime() > 0);
        return dates.length ? new Date(Math.max(...dates.map(d => d.getTime()))) : new Date(0);
    }

    function renderFilteredFacilities(facilities, context) {
        reportContainer.innerHTML = '';
        if (!facilities || !facilities.length) {
            const msg = isSearching
                ? 'No facilities found matching your search.'
                : `No facilities found for "${context}".`;
            reportContainer.innerHTML = `<p>${msg}</p>`;
            return;
        }

        if (isSearching) {
            const header = document.createElement('div');
            header.style.cssText = 'margin-bottom:20px;padding:10px;background:#e8f4f8;border-radius:4px;font-weight:bold;';
            header.innerHTML = `Found ${facilities.length} facilities matching your search`;
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
                        <h2>${escapeHtml(facility.program_category) || 'PRTF'}</h2>
                        <p class="facility-details">
                            ${total} document${total === 1 ? '' : 's'}
                            ${violations ? ` &middot; ${violations} flagged` : ''}
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

    function createReportHTML(report) {
        const isViol = reportIsViolation(report);
        const klass = isViol ? 'inspection-box-violation' : 'inspection-box-clean';
        const heading = `${escapeHtml(report.doc_type)} - ${escapeHtml(report.report_date) || 'N/A'}`;
        const tagsHtml = (report.tags || []).length
            ? `<div class="ar-tags">${report.tags.map(t => `<span class="ar-tag">${escapeHtml(t)}</span>`).join('')}</div>`
            : '';
        const pdfLink = report.pdf_url
            ? `<a href="${escapeAttr(report.pdf_url)}" target="_blank" rel="noopener">View PDF</a>`
            : '';
        const docLink = report.doc_page_url
            ? `<a href="${escapeAttr(report.doc_page_url)}" target="_blank" rel="noopener">DRA page</a>`
            : '';
        const links = [pdfLink, docLink].filter(Boolean).join(' &middot; ');

        return `
        <details class="inspection-box ${klass}">
            <summary class="inspection-header">${heading}</summary>
            <div class="inspection-content">
                <div class="inspection-details-block">
                    <strong>Type:</strong> ${escapeHtml(report.doc_type)}<br>
                    <strong>Date:</strong> ${escapeHtml(report.report_date) || 'N/A'}<br>
                    ${tagsHtml ? `<strong>Tags:</strong> ${tagsHtml}` : ''}
                    ${links ? `<strong>Source:</strong> ${links}` : ''}
                </div>
                ${report.summary ? `<div class="narrative-section"><h4>Summary</h4><p>${escapeHtml(report.summary)}</p></div>` : ''}
                ${report.raw_content ? `<details class="violation-box"><summary class="deficiency-header">Full document text</summary><div class="deficiency-content"><pre style="white-space:pre-wrap;font-family:inherit;">${escapeHtml(report.raw_content)}</pre></div></details>` : ''}
            </div>
        </details>
        `;
    }

    function escapeHtml(s) {
        return safeString(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function escapeAttr(s) { return escapeHtml(s); }

    initializeReport();
});
