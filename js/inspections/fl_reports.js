document.addEventListener('DOMContentLoaded', () => {
    const reportContainer = document.getElementById('report-container');
    if (!reportContainer) {
        console.error('ERROR: #report-container element not found on page');
        return;
    }

    const alphabetFilter = document.getElementById('alphabet-filter');
    const searchInput    = document.getElementById('searchInput');
    const sortSelect     = document.getElementById('sortBy');
    const clearButton    = document.getElementById('clearSearch');
    const newOnlyCheckbox = document.getElementById('newReportsOnly');

    const NEW_REPORT_WINDOW_MS = 30 * 24 * 60 * 60 * 1000;

    let allFacilitiesData = {};
    let currentLetter     = null;
    let isSearching       = false;
    let scrapedTimestamp  = '';
    let sourceFilter      = 'ALL';  // 'ALL' | 'DJJ' | 'AHCA'

    if (searchInput) searchInput.addEventListener('input', filterAndSort);
    if (sortSelect)  sortSelect.addEventListener('change', filterAndSort);
    if (newOnlyCheckbox) newOnlyCheckbox.addEventListener('change', filterAndSort);

    const safeString = v => (v == null ? '' : String(v).trim());

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

    function isRecentReport(report) {
        if (!report) return false;
        const t = parseDate(report.report_date).getTime();
        return t > 0 && (Date.now() - t) <= NEW_REPORT_WINDOW_MS;
    }

    function detectSource(programName, categoriesSource) {
        if (categoriesSource === 'AHCA' || categoriesSource === 'DJJ') return categoriesSource;
        const pn = safeString(programName);
        if (pn.startsWith('AHCA-')) return 'AHCA';
        if (pn.startsWith('DJJ-')) return 'DJJ';
        return 'Unknown';
    }

    async function initializeReport() {
        try {
            const resp = await fetch('/wp-content/themes/child/api/inspections-read.php?state=FL');
            if (!resp.ok) throw new Error(`API returned ${resp.status}`);
            const apiData = await resp.json();

            if (!apiData.facilities || apiData.facilities.length === 0) {
                reportContainer.innerHTML = '<p>No facilities found in the database for Florida.</p>';
                return;
            }

            scrapedTimestamp = apiData.scraped_timestamp || '';
            renderLastUpdated();
            renderSourceFilter();

            const facilitiesArray = convertApiDataToFacilities(apiData.facilities);
            allFacilitiesData     = groupFacilitiesFromArray(facilitiesArray);

            renderAlphabetFilter();
            renderFacilitiesForLetter('ALL');
        } catch (error) {
            console.error('Failed to load Florida report data:', error);
            reportContainer.innerHTML = `<p class="error">Error loading data: ${error.message}</p>`;
        }
    }

    function convertApiDataToFacilities(apiFacilities) {
        return apiFacilities.map(facility => {
            const info = facility.facility_info || {};
            const programName = safeString(info.program_name);
            const firstCats = (facility.reports && facility.reports[0] && facility.reports[0].categories) || {};
            const source = detectSource(programName, firstCats.source);

            const reports = (facility.reports || []).map(r => convertReport(r, source))
                .sort((a, b) => parseDate(b.report_date) - parseDate(a.report_date));

            return {
                name:             safeString(info.facility_name),
                program_name:     programName,
                program_category: safeString(info.program_category),
                address:          safeString(info.full_address),
                phone:            safeString(info.phone),
                bed_capacity:     safeString(info.bed_capacity),
                executive_director: safeString(info.executive_director),
                action:           safeString(info.action),
                source,
                reports,
            };
        });
    }

    function convertReport(report, facilitySource) {
        const cats = report.categories || {};
        const source = cats.source || facilitySource;
        const base = {
            report_id:   safeString(report.report_id),
            report_date: safeString(report.report_date),
            summary:     safeString(report.summary),
            raw_content: safeString(report.raw_content),
            source,
        };

        if (source === 'AHCA') {
            const deficiencies = Array.isArray(cats.deficiencies) ? cats.deficiencies : [];
            const parsedCount  = parseInt(cats.deficiency_count, 10);
            return Object.assign(base, {
                kind:             'ahca',
                inspection_type:  safeString(cats.report_type),
                track_id:         safeString(cats.track_id),
                survey_date:      safeString(cats.survey_date || report.report_date),
                deficiencies,
                deficiency_count: Number.isFinite(parsedCount) && parsedCount >= 0 ? parsedCount : deficiencies.length,
            });
        }

        // DJJ (default)
        return Object.assign(base, {
            kind:           'djj',
            report_type:    safeString(cats.report_type),
            fiscal_year:    safeString(cats.fiscal_year),
            status:         safeString(cats.status),
            pdf_url:        safeString(cats.pdf_url),
            file_name:      safeString(cats.file_name),
            cycle:          safeString(cats.cycle),
            service_type:   safeString(cats.service_type),
            unmatched:      !!cats.unmatched,
        });
    }

    function reportHasFindings(r) {
        if (r.kind === 'ahca') return (r.deficiency_count || 0) > 0;
        // DJJ PDF parsing doesn't extract structured findings yet — mark
        // PREA Interim and re-review reports as needing attention as a
        // reasonable proxy until structured extraction lands.
        return /(?:re-?review|interim)/i.test(r.status || '') || /findings?\s*:?\s*[1-9]/i.test(r.raw_content || '');
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

    function renderSourceFilter() {
        const controls = document.querySelector('.controls');
        if (!controls || document.getElementById('fl-source-filter')) return;
        const wrap = document.createElement('span');
        wrap.id = 'fl-source-filter';
        wrap.style.cssText = 'display:inline-flex;gap:0.4em;align-items:center;margin-left:0.6em;';
        wrap.innerHTML = `
            <label style="font-weight:600;font-size:0.9em;">Agency:</label>
            <select id="flSourceSelect">
                <option value="ALL">All Florida agencies</option>
                <option value="DJJ">DJJ (residential commitment, QI / PREA / SPEP)</option>
                <option value="AHCA">AHCA (RTC / Therapeutic Group Homes)</option>
            </select>
        `;
        controls.appendChild(wrap);
        const sel = wrap.querySelector('#flSourceSelect');
        sel.addEventListener('change', () => {
            sourceFilter = sel.value;
            if (isSearching) {
                filterAndSort();
            } else {
                renderFacilitiesForLetter(currentLetter || 'ALL');
            }
        });
    }

    function applySourceFilter(facilities) {
        if (sourceFilter === 'ALL') return facilities;
        return facilities.filter(f => f.source === sourceFilter);
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
        const all = letter === 'ALL'
            ? Object.keys(allFacilitiesData).sort()
                .reduce((acc, l) => acc.concat(allFacilitiesData[l] || []), [])
            : (allFacilitiesData[letter] || []);
        return applySourceFilter(all);
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
            const all = applySourceFilter([].concat(...Object.values(allFacilitiesData)));
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

    function countFindings(f) {
        return (f.reports || []).filter(reportHasFindings).length;
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
                return recent.length ? Object.assign({}, f, { reports: recent }) : null;
            }).filter(Boolean);
        }
        if (!sortBy) return processed;
        if (sortBy === 'violations-only' || sortBy === 'violations-desc') {
            processed = processed.map(f => {
                const v = (f.reports || []).filter(reportHasFindings);
                return v.length ? Object.assign({}, f, { reports: v }) : null;
            }).filter(Boolean);
        }
        return processed.sort((a, b) => {
            switch (sortBy) {
                case 'name':
                case 'violations-only':
                    return (a.name || '').localeCompare(b.name || '');
                case 'violations-desc':
                    return countFindings(b) - countFindings(a);
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
                : sourceFilter !== 'ALL'
                    ? `No ${sourceFilter} facilities found for "${context}".`
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
            const total    = (facility.reports || []).length;
            const findings = countFindings(facility);
            const sourceBadge = facility.source && facility.source !== 'Unknown'
                ? `<span class="fl-source-badge fl-source-${facility.source.toLowerCase()}" title="${sourceBadgeTitle(facility.source)}">${escapeHtml(facility.source)}</span>`
                : '';
            const addressLine = facility.address
                ? `<p class="facility-details">${escapeHtml(facility.address)}</p>`
                : '';
            const metaParts = [];
            if (facility.phone) metaParts.push(`Phone: ${escapeHtml(facility.phone)}`);
            if (facility.bed_capacity) metaParts.push(`Beds: ${escapeHtml(facility.bed_capacity)}`);
            if (facility.executive_director) metaParts.push(`Director: ${escapeHtml(facility.executive_director)}`);
            const metaLine = metaParts.length
                ? `<p class="facility-details">${metaParts.join(' &middot; ')}</p>`
                : '';

            const el = document.createElement('div');
            el.className = 'facility-box';
            el.innerHTML = `
                <details>
                    <summary class="facility-header">
                        <h1>${escapeHtml(facility.name) || 'N/A'} ${sourceBadge}</h1>
                        <h2>${escapeHtml(facility.program_category) || 'Florida'}</h2>
                        <p class="facility-details">
                            ${total} report${total === 1 ? '' : 's'}
                            ${findings ? ` &middot; ${findings} with findings` : ''}
                            ${facility.action && facility.action !== 'Active' ? ` &middot; ${escapeHtml(facility.action)}` : ''}
                        </p>
                    </summary>
                    ${addressLine}
                    ${metaLine}
                    <div class="inspections-container">
                        ${(facility.reports || []).map(createReportHTML).join('')}
                    </div>
                </details>
            `;
            reportContainer.appendChild(el);
        });
    }

    function sourceBadgeTitle(source) {
        if (source === 'DJJ') return 'Florida Department of Juvenile Justice';
        if (source === 'AHCA') return 'Florida Agency for Health Care Administration';
        return '';
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
        return report.kind === 'ahca' ? createAhcaReportHTML(report) : createDjjReportHTML(report);
    }

    function createDjjReportHTML(report) {
        const hasFindings = reportHasFindings(report);
        const klass       = hasFindings ? 'inspection-box-violation' : 'inspection-box-clean';
        const label       = report.report_type || 'Report';
        const subParts    = [];
        if (report.fiscal_year) subParts.push(report.fiscal_year);
        if (report.status) subParts.push(report.status);
        if (report.cycle) subParts.push(report.cycle);
        const subLabel = subParts.length ? ` (${subParts.join(' · ')})` : '';
        const heading  = `${escapeHtml(label)}${escapeHtml(subLabel)}`;

        const pdfLink = report.pdf_url
            ? `<a href="${escapeAttr(report.pdf_url)}" target="_blank" rel="noopener">View PDF</a>`
            : '';

        const detailRows = [];
        detailRows.push(`<strong>Source:</strong> Florida DJJ — ${escapeHtml(report.report_type || 'Report')}`);
        if (report.fiscal_year) detailRows.push(`<strong>Fiscal Year:</strong> ${escapeHtml(report.fiscal_year)}`);
        if (report.status) detailRows.push(`<strong>Status:</strong> ${escapeHtml(report.status)}`);
        if (report.service_type) detailRows.push(`<strong>Service Type:</strong> ${escapeHtml(report.service_type)}`);
        if (report.cycle) detailRows.push(`<strong>Audit Cycle:</strong> ${escapeHtml(report.cycle)}`);
        if (report.file_name) detailRows.push(`<strong>File:</strong> ${escapeHtml(report.file_name)}`);
        if (pdfLink) detailRows.push(`<strong>PDF:</strong> ${pdfLink}`);

        const rawHtml = report.raw_content
            ? `<details class="violation-box">
                <summary class="deficiency-header">Full report text</summary>
                <div class="deficiency-content"><pre style="white-space:pre-wrap;font-family:inherit;">${escapeHtml(report.raw_content)}</pre></div>
               </details>`
            : '';

        const unmatchedNote = report.unmatched
            ? `<p class="facility-details" style="color:#8c1f2e;font-style:italic;">This report's program name didn't match a current DJJ residential facility — likely a closed or historical program.</p>`
            : '';

        return `
        <details class="inspection-box ${klass}">
            <summary class="inspection-header">${heading}</summary>
            <div class="inspection-content">
                ${unmatchedNote}
                <div class="inspection-details-block">${detailRows.join('<br>')}</div>
                ${rawHtml}
            </div>
        </details>
        `;
    }

    function createAhcaReportHTML(report) {
        const hasFindings = reportHasFindings(report);
        const klass       = hasFindings ? 'inspection-box-violation' : 'inspection-box-clean';
        const inspectionType = report.inspection_type || 'Inspection';
        const date    = report.survey_date || report.report_date || 'N/A';
        const heading = `${escapeHtml(inspectionType)} — ${escapeHtml(date)}`;

        const detailRows = [];
        detailRows.push(`<strong>Source:</strong> Florida AHCA — ${escapeHtml(inspectionType)}`);
        if (report.survey_date) detailRows.push(`<strong>Survey Date:</strong> ${escapeHtml(report.survey_date)}`);
        if (report.track_id) detailRows.push(`<strong>Track ID:</strong> ${escapeHtml(report.track_id)}`);
        detailRows.push(
            `<strong>Deficiencies:</strong> ${report.deficiency_count} ` +
            (report.deficiency_count === 1 ? 'finding' : 'findings')
        );

        const deficienciesHtml = report.deficiencies && report.deficiencies.length
            ? `<details class="violation-box" open>
                <summary class="deficiency-header">${report.deficiency_count} deficienc${report.deficiency_count === 1 ? 'y' : 'ies'}</summary>
                <div class="deficiency-content">
                    <table class="fl-ahca-deficiencies" style="width:100%;border-collapse:collapse;font-size:0.95em;">
                        <thead>
                            <tr style="background:#f0f3fa;">
                                <th style="text-align:left;padding:6px;border-bottom:1px solid #ddd;">Code</th>
                                <th style="text-align:left;padding:6px;border-bottom:1px solid #ddd;">Requirement</th>
                                <th style="text-align:left;padding:6px;border-bottom:1px solid #ddd;">Corrected</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${report.deficiencies.map(d => `
                                <tr>
                                    <td style="padding:6px;border-bottom:1px solid #eee;vertical-align:top;white-space:nowrap;"><strong>${escapeHtml(d.deficiency || '')}</strong></td>
                                    <td style="padding:6px;border-bottom:1px solid #eee;vertical-align:top;">${escapeHtml(d.requirement_description || '')}</td>
                                    <td style="padding:6px;border-bottom:1px solid #eee;vertical-align:top;white-space:nowrap;">${escapeHtml(d.correction_date || '')}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
               </details>`
            : '';

        return `
        <details class="inspection-box ${klass}">
            <summary class="inspection-header">${heading}</summary>
            <div class="inspection-content">
                <div class="inspection-details-block">${detailRows.join('<br>')}</div>
                ${deficienciesHtml}
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
