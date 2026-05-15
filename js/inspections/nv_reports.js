document.addEventListener('DOMContentLoaded', () => {
    const reportContainer = document.getElementById('report-container');
    if (!reportContainer) {
        console.error('ERROR: #report-container element not found on page');
        return;
    }

    const alphabetFilter  = document.getElementById('alphabet-filter');
    const searchInput     = document.getElementById('searchInput');
    const sortSelect      = document.getElementById('sortBy');
    const clearButton     = document.getElementById('clearSearch');
    const newOnlyCheckbox = document.getElementById('newReportsOnly');

    const NEW_REPORT_WINDOW_MS = 30 * 24 * 60 * 60 * 1000;

    let allFacilitiesData = {};
    let currentLetter     = null;
    let isSearching       = false;
    let scrapedTimestamp  = '';

    if (searchInput)     searchInput.addEventListener('input', filterAndSort);
    if (sortSelect)      sortSelect.addEventListener('change', filterAndSort);
    if (newOnlyCheckbox) newOnlyCheckbox.addEventListener('change', filterAndSort);

    const safeString = v => (v == null ? '' : String(v).trim());

    function isRecentReport(report) {
        if (!report) return false;
        const t = parseDate(report.report_date).getTime();
        return t > 0 && (Date.now() - t) <= NEW_REPORT_WINDOW_MS;
    }

    function reportHasDeficiencies(report) {
        const cats = report.categories || {};
        const count = parseInt(cats.deficiency_count, 10);
        return Number.isFinite(count) && count > 0;
    }

    async function initializeReport() {
        try {
            const resp = await fetch('/wp-content/themes/child/api/inspections-read.php?state=NV');
            if (!resp.ok) throw new Error(`API returned ${resp.status}`);
            const apiData = await resp.json();

            if (!apiData.facilities || apiData.facilities.length === 0) {
                reportContainer.innerHTML = '<p>No facilities found in the database for Nevada.</p>';
                return;
            }

            scrapedTimestamp = apiData.scraped_timestamp || '';
            renderLastUpdated();

            const facilitiesArray = convertApiDataToFacilities(apiData.facilities);
            allFacilitiesData     = groupFacilitiesFromArray(facilitiesArray);

            renderAlphabetFilter();
            renderFacilitiesForLetter('ALL');
        } catch (error) {
            console.error('Failed to load Nevada report data:', error);
            reportContainer.innerHTML = `<p class="error">Error loading data: ${error.message}</p>`;
        }
    }

    function convertApiDataToFacilities(apiFacilities) {
        return apiFacilities.map(facility => {
            const info = facility.facility_info || {};
            const reports = (facility.reports || []).map(report => {
                const cats = report.categories || {};
                return {
                    report_id:        report.report_id    || '',
                    report_date:      report.report_date  || '',
                    summary:          report.summary      || '',
                    raw_content:      report.raw_content  || '',
                    agency:           cats.agency         || '',
                    credential_type:  cats.credential_type || '',
                    license_number:   cats.license_number || '',
                    inspection_reason: cats.inspection_reason || '',
                    grade:            cats.grade          || '',
                    event_id:         cats.event_id       || '',
                    inspection_time:  cats.inspection_time || '',
                    doc_count:        parseInt(cats.doc_count, 10) || 0,
                    deficiency_count: parseInt(cats.deficiency_count, 10) || 0,
                    categories:       cats,
                };
            }).sort((a, b) => parseDate(b.report_date) - parseDate(a.report_date));

            return {
                name:             safeString(info.facility_name),
                program_name:     safeString(info.program_name),
                program_category: safeString(info.program_category),
                address:          safeString(info.full_address),
                phone:            safeString(info.phone),
                license_status:   safeString(info.action),
                license_exp_date: safeString(info.license_exp_date),
                reports,
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

    function groupFacilitiesFromArray(arr) {
        const grouped = arr.reduce((acc, f) => {
            const first = (f.name || '').charAt(0).toUpperCase();
            const key   = (first >= 'A' && first <= 'Z') ? first : '#';
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
        const term   = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const sortBy = sortSelect  ? sortSelect.value : '';
        if (!Object.keys(allFacilitiesData).length) return;

        if (term) {
            isSearching = true;
            if (clearButton) clearButton.style.display = 'inline-block';
            document.querySelectorAll('#alphabet-filter a').forEach(a => a.classList.remove('active'));
            const all      = [].concat(...Object.values(allFacilitiesData));
            const filtered = all.filter(f =>
                [f.name, f.program_name, f.program_category, f.address]
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
        if (sortSelect)  sortSelect.value  = '';
        filterAndSort();
    }
    window.clearSearch = clearSearch;

    function countDeficiencies(f) {
        return (f.reports || []).filter(reportHasDeficiencies).length;
    }

    function getMostRecentDate(f) {
        if (!f.reports || !f.reports.length) return new Date(0);
        const dates = f.reports.map(r => parseDate(r.report_date)).filter(d => d.getTime() > 0);
        return dates.length ? new Date(Math.max(...dates.map(d => d.getTime()))) : new Date(0);
    }

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
                const v = f.reports.filter(reportHasDeficiencies);
                return v.length ? { ...f, reports: v } : null;
            }).filter(Boolean);
        }
        return processed.sort((a, b) => {
            switch (sortBy) {
                case 'name':
                case 'violations-only':
                    return (a.name || '').localeCompare(b.name || '');
                case 'violations-desc':
                    return countDeficiencies(b) - countDeficiencies(a);
                case 'recent-inspection':
                    return getMostRecentDate(b) - getMostRecentDate(a);
                default:
                    return 0;
            }
        });
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
            const total        = (facility.reports || []).length;
            const deficiencies = countDeficiencies(facility);
            const el = document.createElement('div');
            el.className = 'facility-box';
            el.innerHTML = `
                <details>
                    <summary class="facility-header">
                        <h1>${escapeHtml(facility.name) || 'N/A'}</h1>
                        <h2>${escapeHtml(facility.program_category) || 'Nevada Residential'}</h2>
                        <p class="facility-details">
                            ${total} inspection${total === 1 ? '' : 's'}
                            ${deficiencies ? ` &middot; ${deficiencies} with deficiencies` : ''}
                            ${facility.license_status ? ` &middot; License: ${escapeHtml(facility.license_status)}` : ''}
                        </p>
                    </summary>
                    ${facility.address ? `<p class="facility-details">${escapeHtml(facility.address)}</p>` : ''}
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
            el.id        = 'last-updated';
            el.className = 'last-updated';
            const anchor = document.querySelector('.facility-report-container') || reportContainer.parentNode;
            anchor.appendChild(el);
        }
        if (!scrapedTimestamp) { el.innerHTML = ''; return; }
        const parsed     = new Date(scrapedTimestamp);
        const updateDate = isNaN(parsed.getTime())
            ? scrapedTimestamp
            : parsed.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
        el.innerHTML = `<p>Last updated: ${updateDate}</p>`;
    }

    function createReportHTML(report) {
        const hasDefc  = reportHasDeficiencies(report);
        const klass    = hasDefc ? 'inspection-box-violation' : 'inspection-box-clean';
        const dateStr  = escapeHtml(report.report_date) || 'Date unknown';
        const agencyLabel = report.agency === 'DSS-CCL' ? 'DSS Child Care Licensing' :
                            report.agency === 'HCQC'    ? 'Nevada Health Authority' :
                            escapeHtml(report.agency);
        const gradeHtml = report.grade
            ? `<strong>Grade:</strong> ${escapeHtml(report.grade)}<br>`
            : '';
        const reasonHtml = report.inspection_reason
            ? `<strong>Reason:</strong> ${escapeHtml(report.inspection_reason)}<br>`
            : '';
        const defHtml = hasDefc
            ? `<strong>Deficiencies:</strong> ${report.deficiency_count}<br>`
            : '';
        const docHtml = report.doc_count
            ? `<strong>Documents on file:</strong> ${report.doc_count}<br>`
            : '';

        const rawHtml = report.raw_content
            ? `<details class="violation-box">
                <summary class="deficiency-header">Inspection record</summary>
                <div class="deficiency-content"><pre style="white-space:pre-wrap;font-family:inherit;">${escapeHtml(report.raw_content)}</pre></div>
               </details>`
            : '';

        return `
        <details class="inspection-box ${klass}">
            <summary class="inspection-header">
                Inspection &mdash; ${dateStr}
                ${hasDefc ? ' <span class="deficiency-badge">deficiencies</span>' : ''}
            </summary>
            <div class="inspection-content">
                <div class="inspection-details-block">
                    <strong>Agency:</strong> ${agencyLabel}<br>
                    <strong>Date:</strong> ${dateStr}<br>
                    ${report.credential_type ? `<strong>License type:</strong> ${escapeHtml(report.credential_type)}<br>` : ''}
                    ${gradeHtml}
                    ${reasonHtml}
                    ${defHtml}
                    ${docHtml}
                </div>
                ${rawHtml}
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
