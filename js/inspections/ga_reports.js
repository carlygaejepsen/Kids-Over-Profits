/**
 * Georgia inspection reports viewer (/ga-reports).
 *
 * Data comes from api/inspections-read.php?state=GA (DB-backed; rows written
 * by ga_scraper.py). Each report's categories carry survey_type ("Incident",
 * "Re-Licensure", "Follow-up/Revisit", ...), survey_status, survey start/exit
 * dates, under_appeal, and sod_url — a link to Georgia DHS's Statement of
 * Deficiencies page for the survey. raw_content is usually just the report
 * viewer's UI boilerplate (the PDF text extraction failed), so it is only
 * rendered when it looks like real narrative.
 */
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

    // GA has no parsed deficiency counts — Incident surveys are the ones worth
    // flagging, so they drive the "violations" filters and the red styling.
    function reportIsIncident(report) {
        return /incident|complaint/i.test(safeString(report.survey_type));
    }

    async function initializeReport() {
        try {
            const resp = await fetch('/wp-content/themes/child/api/inspections-read.php?state=GA');
            if (!resp.ok) throw new Error(`API returned ${resp.status}`);
            const apiData = await resp.json();

            if (!apiData.facilities || apiData.facilities.length === 0) {
                reportContainer.innerHTML = '<p>No facilities found in the database for Georgia.</p>';
                return;
            }

            scrapedTimestamp = apiData.scraped_timestamp || '';
            renderLastUpdated();

            const facilitiesArray = convertApiDataToFacilities(apiData.facilities);
            allFacilitiesData     = groupFacilitiesFromArray(facilitiesArray);

            renderAlphabetFilter();
            renderFacilitiesForLetter('ALL');
        } catch (error) {
            console.error('Failed to load Georgia report data:', error);
            reportContainer.innerHTML = `<p class="error">Error loading data: ${error.message}</p>`;
        }
    }

    // raw_content is real narrative only when it's substantial and not the
    // SOD viewer's export-widget chrome.
    function usableRawContent(report) {
        const raw = safeString(report.raw_content);
        if (raw.length < 400) return '';
        if (/Export to the selected format|Generating report/i.test(raw)) return '';
        return raw;
    }

    function convertApiDataToFacilities(apiFacilities) {
        return apiFacilities.map(facility => {
            const info = facility.facility_info || {};
            const reports = (facility.reports || []).map(report => {
                const cats = report.categories || {};
                return {
                    report_id:       report.report_id || '',
                    report_date:     report.report_date || '',
                    summary:         report.summary || '',
                    raw_content:     report.raw_content || '',
                    survey_type:     cats.survey_type || '',
                    survey_status:   cats.survey_status || '',
                    survey_start:    cats.survey_start_date || '',
                    survey_exit:     cats.survey_exit_date || '',
                    under_appeal:    cats.under_appeal || '',
                    sod_url:         cats.sod_url || report.report_url || '',
                    event_id:        cats.event_id || '',
                };
            }).sort((a, b) => parseDate(b.report_date) - parseDate(a.report_date));

            return {
                name:             safeString(info.facility_name),
                program_name:     safeString(info.program_name),   // GA FACID
                program_category: safeString(info.program_category),
                address:          safeString(info.full_address),
                phone:            safeString(info.phone),
                license_status:   safeString(info.action),
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

    function countIncidents(f) {
        return (f.reports || []).filter(reportIsIncident).length;
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
                const v = f.reports.filter(reportIsIncident);
                return v.length ? { ...f, reports: v } : null;
            }).filter(Boolean);
        }
        return processed.sort((a, b) => {
            switch (sortBy) {
                case 'name':
                case 'violations-only':
                    return (a.name || '').localeCompare(b.name || '');
                case 'violations-desc':
                    return countIncidents(b) - countIncidents(a);
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
            const total     = (facility.reports || []).length;
            const incidents = countIncidents(facility);
            const el = document.createElement('div');
            el.className = 'facility-box';
            el.innerHTML = `
                <details>
                    <summary class="facility-header">
                        <h1>${escapeHtml(facility.name) || 'N/A'}</h1>
                        <h2>${escapeHtml(facility.program_category) || 'Georgia Residential'}</h2>
                        <p class="facility-details">
                            ${total} survey${total === 1 ? '' : 's'}
                            ${incidents ? ` &middot; ${incidents} incident${incidents === 1 ? '' : 's'}` : ''}
                            ${facility.program_name ? ` &middot; FACID: ${escapeHtml(facility.program_name)}` : ''}
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
        const isIncident = reportIsIncident(report);
        const klass      = isIncident ? 'inspection-box-violation' : 'inspection-box-clean';
        const dateStr    = escapeHtml(report.report_date) || 'Date unknown';
        const typeStr    = escapeHtml(report.survey_type) || 'Survey';

        const visitHtml = (report.survey_start && report.survey_start !== report.survey_exit)
            ? `<strong>Survey started:</strong> ${escapeHtml(report.survey_start)}<br>`
            : '';
        const appealHtml = (report.under_appeal && !/^(n|no|false|0)$/i.test(report.under_appeal))
            ? `<strong>Under appeal:</strong> ${escapeHtml(report.under_appeal)}<br>`
            : '';
        const sodHtml = report.sod_url
            ? `<strong>Official report:</strong> <a href="${escapeAttr(report.sod_url)}" target="_blank" rel="noopener">Statement of Deficiencies (GA DHS)</a><br>`
            : '';

        const raw = usableRawContent(report);
        const rawHtml = raw
            ? `<details class="violation-box">
                <summary class="deficiency-header">Survey record</summary>
                <div class="deficiency-content"><pre style="white-space:pre-wrap;font-family:inherit;">${escapeHtml(raw)}</pre></div>
               </details>`
            : '';

        return `
        <details class="inspection-box ${klass}">
            <summary class="inspection-header">
                ${typeStr} &mdash; ${dateStr}
                ${isIncident ? ' <span class="deficiency-badge">incident</span>' : ''}
            </summary>
            <div class="inspection-content">
                <div class="inspection-details-block">
                    <strong>Type:</strong> ${typeStr}<br>
                    <strong>Date:</strong> ${dateStr}<br>
                    ${report.survey_status ? `<strong>Status:</strong> ${escapeHtml(report.survey_status)}<br>` : ''}
                    ${visitHtml}
                    ${appealHtml}
                    ${report.event_id ? `<strong>Survey ID:</strong> ${escapeHtml(report.event_id)}<br>` : ''}
                    ${sodHtml}
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
