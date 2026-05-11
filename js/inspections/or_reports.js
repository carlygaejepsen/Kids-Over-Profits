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
    const OREGON_INLINE_STOP_LABELS = [
        'Date of site visit',
        'Date of Unannounced',
        'Executive Director',
        'Program Director',
        'Board Chairperson',
        'Licensing Coordinator',
        'Other Regulatory or Accrediting Agencies',
        'Purpose',
        'Program Compliance',
        'Program Description',
        'Program type and services',
        'Capacity and age-range',
        'Capacity and Age Range',
        'Funding sources',
        'Contracts and sources for referrals',
        'Average length of stay',
        'Average daily population served',
        'Number of children served annually',
        'Use of seclusion or restraint',
        'Interviews, Observations',
        'Program Strengths',
        'Program Challenges',
        'Changes that have occurred in the last 2 years',
        'Changes that have occurred in the last two years',
        'Lawsuits',
        'Grievances and complaints filed in the last two years',
        'Corrective Actions and Timeframes',
        'Recommendations',
        'Exceptions',
        'Changes in License',
        'Summary of Review',
    ];

    let allFacilitiesData = {};
    let currentLetter     = null;
    let isSearching       = false;
    let scrapedTimestamp  = '';

    if (searchInput) searchInput.addEventListener('input', filterAndSort);
    if (sortSelect)  sortSelect.addEventListener('change', filterAndSort);
    if (newOnlyCheckbox) newOnlyCheckbox.addEventListener('change', filterAndSort);

    function isRecentReport(report) {
        if (!report) return false;
        const t = parseDate(report.report_date).getTime();
        return t > 0 && (Date.now() - t) <= NEW_REPORT_WINDOW_MS;
    }

    const safeString = v => (v == null ? '' : String(v).trim());
    const escapeRegex = value => safeString(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const oregonInlineStopRe = new RegExp(
        `\\s+(?=${OREGON_INLINE_STOP_LABELS.map(escapeRegex).join('|')})\\s*:`,
        'i'
    );

    function normalizeOregonWhitespace(value) {
        return safeString(value)
            .replace(/\r/g, '\n')
            .replace(/\u00a0/g, ' ')
            .replace(/[ \t]+/g, ' ')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    function stripTrailingOregonLabel(value) {
        const normalized = normalizeOregonWhitespace(value);
        if (!normalized) return '';

        const match = normalized.match(oregonInlineStopRe);
        const trimmed = (match ? normalized.slice(0, match.index) : normalized)
            .replace(/\s+[:,-]\s*$/, '')
            .trim();

        return trimmed;
    }

    function cleanOregonInlineField(value) {
        return stripTrailingOregonLabel(value).replace(/\s+/g, ' ').trim();
    }

    function cleanOregonNarrative(value) {
        return stripTrailingOregonLabel(value)
            .replace(/[ \t]+\n/g, '\n')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    function formatMultilineHtml(value) {
        return escapeHtml(cleanOregonNarrative(value)).replace(/\n/g, '<br>');
    }

    function extractOregonDisplayDate(value) {
        const cleaned = cleanOregonInlineField(value);
        if (!cleaned) return '';

        const match = cleaned.match(
            /\b(?:\d{1,2}\/\d{1,2}\/\d{2,4}|January \d{1,2}, \d{4}|February \d{1,2}, \d{4}|March \d{1,2}, \d{4}|April \d{1,2}, \d{4}|May \d{1,2}, \d{4}|June \d{1,2}, \d{4}|July \d{1,2}, \d{4}|August \d{1,2}, \d{4}|September \d{1,2}, \d{4}|October \d{1,2}, \d{4}|November \d{1,2}, \d{4}|December \d{1,2}, \d{4})\b/
        );

        return match ? match[0] : cleaned;
    }

    function normalizeFindings(findings) {
        if (!Array.isArray(findings)) return [];

        return findings.map(finding => ({
            rule: cleanOregonInlineField(finding && finding.rule),
            excerpt: cleanOregonNarrative(finding && finding.excerpt),
        })).filter(finding => finding.rule || finding.excerpt);
    }

    function getFindingCount(report) {
        if (!report) return 0;
        if (Number.isFinite(report.finding_count) && report.finding_count > 0) {
            return report.finding_count;
        }
        return Array.isArray(report.findings) ? report.findings.length : 0;
    }

    async function initializeReport() {
        try {
            const resp = await fetch('/wp-content/themes/child/api/inspections-read.php?state=OR');
            if (!resp.ok) throw new Error(`API returned ${resp.status}`);
            const apiData = await resp.json();

            if (!apiData.facilities || apiData.facilities.length === 0) {
                reportContainer.innerHTML = '<p>No facilities found in the database for Oregon.</p>';
                return;
            }

            scrapedTimestamp = apiData.scraped_timestamp || '';
            renderLastUpdated();

            const facilitiesArray = convertApiDataToFacilities(apiData.facilities);
            allFacilitiesData     = groupFacilitiesFromArray(facilitiesArray);

            renderAlphabetFilter();
            renderFacilitiesForLetter('ALL');
        } catch (error) {
            console.error('Failed to load Oregon report data:', error);
            reportContainer.innerHTML = `<p class="error">Error loading data: ${error.message}</p>`;
        }
    }

    function convertApiDataToFacilities(apiFacilities) {
        const raw = apiFacilities.map(facility => {
            const info = facility.facility_info || {};
            const reports = (facility.reports || []).map(report => {
                const cats = report.categories || {};
                const findings = normalizeFindings(cats.findings);
                const parsedFindingCount = parseInt(cats.finding_count, 10);
                return {
                    report_id:          report.report_id    || '',
                    report_date:        report.report_date  || '',
                    summary:            report.summary      || '',
                    raw_content:        report.raw_content  || '',
                    content_length:     report.content_length || 0,
                    report_type:        cleanOregonInlineField(cats.report_type || cats.report_title || ''),
                    view_code:          cleanOregonInlineField(cats.view_code || ''),
                    pdf_url:            safeString(cats.pdf_url || ''),
                    source_page:        safeString(cats.source_page || ''),
                    visit_date:         extractOregonDisplayDate(cats.visit_date || report.report_date || ''),
                    licensee:           cleanOregonInlineField(cats.licensee || ''),
                    program_compliance: cleanOregonNarrative(cats.program_compliance || ''),
                    corrective_actions: cleanOregonNarrative(cats.corrective_actions || ''),
                    findings,
                    finding_count:      Number.isFinite(parsedFindingCount) && parsedFindingCount >= 0 ? parsedFindingCount : findings.length,
                    program_description:         cleanOregonNarrative(cats.program_description || ''),
                    program_services:            cleanOregonNarrative(cats.program_services || ''),
                    capacity_age_range:          cleanOregonInlineField(cats.capacity_age_range || ''),
                    average_length_of_stay:      cleanOregonInlineField(cats.average_length_of_stay || ''),
                    average_daily_population:    cleanOregonInlineField(cats.average_daily_population_served || ''),
                    number_children_annually:    cleanOregonInlineField(cats.number_of_children_served_annually || ''),
                    use_of_seclusion_or_restraint: cleanOregonNarrative(cats.use_of_seclusion_or_restraint || ''),
                    interviews_observations:     cleanOregonNarrative(cats.interviews_observations || ''),
                    interview_summary:           cleanOregonNarrative(cats.interview_summary || ''),
                    observations:                cleanOregonNarrative(cats.observations || ''),
                    program_strengths:           cleanOregonNarrative(cats.program_strengths || ''),
                    program_challenges:          cleanOregonNarrative(cats.program_challenges || ''),
                    lawsuits:                    cleanOregonNarrative(cats.lawsuits || ''),
                    grievances_and_complaints:   cleanOregonNarrative(cats.grievances_and_complaints || ''),
                    recommendations:             cleanOregonNarrative(cats.recommendations || ''),
                    exceptions:                  cleanOregonNarrative(cats.exceptions || ''),
                    changes_in_license:          cleanOregonNarrative(cats.changes_in_license || ''),
                    previous_findings:           cleanOregonNarrative(cats.previous_findings || ''),
                    new_findings:                cleanOregonNarrative(cats.new_findings || ''),
                };
            }).sort((a, b) => parseDate(b.report_date) - parseDate(a.report_date));

            return {
                name:             cleanOregonInlineField(info.facility_name || info.agency_name || ''),
                program_name:     cleanOregonInlineField(info.program_name || ''),
                program_category: cleanOregonInlineField(info.program_category || ''),
                agency_name:      cleanOregonInlineField(info.agency_name || ''),
                address:          cleanOregonNarrative(info.full_address || ''),
                website:          safeString(info.website || ''),
                reports:          reports,
            };
        });

        // Merge facilities whose names differ only in punctuation/separators
        // e.g. "Adapt Deer Creek" and "Adapt - Deer Creek"
        const normName = name => {
            if (!name) return '';
            return name.toLowerCase()
                .replace(/\s*[-–—]\s*/g, ' ')
                .replace(/\s*&\s*/g, ' and ')
                .replace(/[^\w\s]/g, '')
                .replace(/\s+/g, ' ')
                .trim();
        };

        const mergeMap = new Map();
        for (const f of raw) {
            const key = normName(f.name);
            if (!mergeMap.has(key)) {
                mergeMap.set(key, { ...f, reports: [...f.reports] });
            } else {
                const existing = mergeMap.get(key);
                const seenIds = new Set(existing.reports.map(r => r.report_id));
                for (const r of f.reports) {
                    if (!seenIds.has(r.report_id)) {
                        existing.reports.push(r);
                        seenIds.add(r.report_id);
                    }
                }
                existing.reports.sort((a, b) => parseDate(b.report_date) - parseDate(a.report_date));
            }
        }

        return Array.from(mergeMap.values());
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

    function reportHasFindings(r) {
        return getFindingCount(r) > 0 ||
               (r.corrective_actions && r.corrective_actions.toLowerCase() !== 'none');
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
                [f.name, f.program_name, f.program_category, f.agency_name]
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
                return recent.length ? { ...f, reports: recent } : null;
            }).filter(Boolean);
        }
        if (!sortBy) return processed;
        if (sortBy === 'violations-only' || sortBy === 'violations-desc') {
            processed = processed.map(f => {
                const v = f.reports.filter(reportHasFindings);
                return v.length ? { ...f, reports: v } : null;
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
            const websiteLink = facility.website
                ? `<a href="${escapeAttr(facility.website)}" target="_blank" rel="noopener">${escapeHtml(facility.website)}</a>`
                : '';
            const agencyNote = facility.agency_name && facility.agency_name !== facility.name
                ? `<p class="facility-details">Agency: ${escapeHtml(facility.agency_name)}${websiteLink ? ' &middot; ' + websiteLink : ''}</p>`
                : (websiteLink ? `<p class="facility-details">${websiteLink}</p>` : '');

            const el = document.createElement('div');
            el.className = 'facility-box';
            el.innerHTML = `
                <details>
                    <summary class="facility-header">
                        <h1>${escapeHtml(facility.name) || 'N/A'}</h1>
                        <h2>${escapeHtml(facility.program_category) || 'Oregon Residential'}</h2>
                        <p class="facility-details">
                            ${total} report${total === 1 ? '' : 's'}
                            ${findings ? ` &middot; ${findings} with findings` : ''}
                        </p>
                    </summary>
                    ${agencyNote}
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
        const hasFindings = reportHasFindings(report);
        const klass       = hasFindings ? 'inspection-box-violation' : 'inspection-box-clean';
        const label       = cleanOregonInlineField(report.report_type) || 'Report';
        const date        = extractOregonDisplayDate(report.visit_date || report.report_date || '') || 'N/A';
        const licensee    = cleanOregonInlineField(report.licensee);
        const findingCount = getFindingCount(report);
        const heading     = `${escapeHtml(label)} — ${escapeHtml(date)}`;

        const pdfLink = report.pdf_url
            ? `<a href="${escapeAttr(report.pdf_url)}" target="_blank" rel="noopener">View PDF</a>`
            : '';

        // Helper: render a labelled narrative paragraph only when non-empty.
        const narrativeRow = (rowLabel, value) => {
            const cleaned = cleanOregonNarrative(value);
            return cleaned
                ? `<div class="narrative-section"><h4>${escapeHtml(rowLabel)}</h4><p>${formatMultilineHtml(cleaned)}</p></div>`
                : '';
        };

        // Program overview fields — shown when present.
        const programInfoRows = [
            report.program_description   && narrativeRow('Program Description', report.program_description),
            report.program_services      && narrativeRow('Program Type & Services', report.program_services),
            report.capacity_age_range    && narrativeRow('Capacity & Age Range', report.capacity_age_range),
            report.average_length_of_stay && narrativeRow('Average Length of Stay', report.average_length_of_stay),
            report.average_daily_population && narrativeRow('Average Daily Population', report.average_daily_population),
            report.number_children_annually && narrativeRow('Children Served Annually', report.number_children_annually),
            report.use_of_seclusion_or_restraint && narrativeRow('Use of Seclusion or Restraint', report.use_of_seclusion_or_restraint),
        ].filter(Boolean).join('');

        const programInfoHtml = programInfoRows
            ? `<details class="violation-box">
                <summary class="deficiency-header">Program Information</summary>
                <div class="deficiency-content">${programInfoRows}</div>
               </details>`
            : '';

        // Observation / interview fields.
        const observationRows = [
            report.interviews_observations && narrativeRow('Interviews & Observations', report.interviews_observations),
            report.interview_summary       && narrativeRow('Interview Summary', report.interview_summary),
            report.observations            && narrativeRow('Observations', report.observations),
            report.program_strengths       && narrativeRow('Program Strengths', report.program_strengths),
            report.program_challenges      && narrativeRow('Program Challenges', report.program_challenges),
        ].filter(Boolean).join('');

        const observationsHtml = observationRows
            ? `<details class="violation-box">
                <summary class="deficiency-header">Observations & Interviews</summary>
                <div class="deficiency-content">${observationRows}</div>
               </details>`
            : '';

        // Legal / complaint fields.
        const legalRows = [
            report.lawsuits                && narrativeRow('Lawsuits', report.lawsuits),
            report.grievances_and_complaints && narrativeRow('Grievances & Complaints', report.grievances_and_complaints),
        ].filter(Boolean).join('');

        const legalHtml = legalRows
            ? `<details class="violation-box">
                <summary class="deficiency-header">Lawsuits & Grievances</summary>
                <div class="deficiency-content">${legalRows}</div>
               </details>`
            : '';

        const reviewRows = [
            report.previous_findings && narrativeRow('Previous Findings', report.previous_findings),
            report.new_findings && narrativeRow('New Findings', report.new_findings),
            report.recommendations && narrativeRow('Recommendations', report.recommendations),
            report.exceptions && narrativeRow('Exceptions', report.exceptions),
            report.changes_in_license && narrativeRow('Changes in License', report.changes_in_license),
        ].filter(Boolean).join('');

        const reviewHtml = reviewRows
            ? `<details class="violation-box">
                <summary class="deficiency-header">Review Notes</summary>
                <div class="deficiency-content">${reviewRows}</div>
               </details>`
            : '';

        const findingsHtml = report.findings && report.findings.length
            ? `<details class="violation-box">
                <summary class="deficiency-header">${findingCount} compliance finding${findingCount === 1 ? '' : 's'}</summary>
                <div class="deficiency-content">
                    ${report.findings.map(f => `
                        <div style="margin-bottom:10px">
                            <strong>${escapeHtml(f.rule)}</strong>
                            <p style="margin:4px 0 0;white-space:pre-wrap">${formatMultilineHtml(f.excerpt)}</p>
                        </div>
                    `).join('')}
                </div>
               </details>`
            : '';

        const correctiveHtml = report.corrective_actions && report.corrective_actions.toLowerCase() !== 'none'
            ? narrativeRow('Corrective Actions', report.corrective_actions)
            : '';

        const complianceHtml = report.program_compliance
            ? narrativeRow('Program Compliance', report.program_compliance)
            : '';

        const rawHtml = report.raw_content
            ? `<details class="violation-box">
                <summary class="deficiency-header">Full report text</summary>
                <div class="deficiency-content"><pre style="white-space:pre-wrap;font-family:inherit;">${escapeHtml(report.raw_content)}</pre></div>
               </details>`
            : '';

        return `
        <details class="inspection-box ${klass}">
            <summary class="inspection-header">${heading}</summary>
            <div class="inspection-content">
                <div class="inspection-details-block">
                    <strong>Type:</strong> ${escapeHtml(label)}<br>
                    <strong>Visit Date:</strong> ${escapeHtml(date)}<br>
                    ${licensee ? `<strong>Licensee:</strong> ${escapeHtml(licensee)}<br>` : ''}
                    ${pdfLink ? `<strong>Source:</strong> ${pdfLink}` : ''}
                </div>
                ${complianceHtml}
                ${programInfoHtml}
                ${observationsHtml}
                ${legalHtml}
                ${reviewHtml}
                ${correctiveHtml}
                ${findingsHtml}
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
