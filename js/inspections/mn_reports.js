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

    let allFacilitiesData = {};
    let currentLetter = null;
    let isSearching = false;
    let scrapedTimestamp = '';

    const VIOLATION_DOC_TYPES = new Set(['Notice of Incident', 'Compliance Report', 'CFS Report']);

    if (searchInput) searchInput.addEventListener('input', filterAndSort);
    if (sortSelect) sortSelect.addEventListener('change', filterAndSort);

    const safeString = v => (v == null ? '' : String(v).trim());

    async function initializeReport() {
        try {
            const apiUrl = '/wp-content/themes/child/api/inspections-read.php?state=MN';
            const resp = await fetch(apiUrl);
            if (!resp.ok) throw new Error(`API returned ${resp.status}`);
            const apiData = await resp.json();

            if (!apiData.facilities || apiData.facilities.length === 0) {
                reportContainer.innerHTML = '<p>No facilities found in the database for Minnesota.</p>';
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
        const urls = (window.mnReportsData && window.mnReportsData.jsonFileUrls) || [];
        if (!urls.length) {
            reportContainer.innerHTML = '<p>No Minnesota inspection data available yet.</p>';
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

        reportContainer.innerHTML = '<p>No Minnesota inspection data could be loaded.</p>';
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
        if (!sortBy) return facilities;
        let processed = [...facilities];
        if (sortBy === 'violations-only' || sortBy === 'violations-desc') {
            processed = facilities.map(f => {
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
                        <h2>${escapeHtml(facility.program_category) || 'Residential'}</h2>
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
        // Reclassify doc type from the actual document text (scraper's type is sometimes wrong)
        const refinedType = classifyDocument(report.raw_content, report.doc_type);
        const reportForDisplay = { ...report, doc_type: refinedType };

        const empty   = isEmptyDocument(report.raw_content);
        const isViol  = reportIsViolation(reportForDisplay);
        const klass   = isViol ? 'inspection-box-violation' : 'inspection-box-clean';
        const dateStr = escapeHtml(report.report_date) || 'Date unknown';
        const typeStr = escapeHtml(refinedType);
        const typeSlug = refinedType.toLowerCase().replace(/\s+/g, '-');

        const tagsHtml = (report.tags || []).length
            ? `<div class="mn-tags">${report.tags.map(t => `<span class="mn-tag">${escapeHtml(t)}</span>`).join('')}</div>`
            : '';

        const docLink = (report.doc_page_url || report.report_url)
            ? `<a href="${escapeAttr(report.doc_page_url || report.report_url)}" target="_blank" rel="noopener">View source document ↗</a>`
            : '';

        // If scrape only captured the footer, show a clear note instead of noise
        if (empty) {
            return `
            <details class="inspection-box inspection-box-clean mn-empty">
                <summary class="inspection-header">
                    <span class="doc-type-label" data-doc-type="${typeSlug}">${typeStr}</span>
                    <span class="doc-date">${dateStr}</span>
                </summary>
                <div class="inspection-content">
                    <p class="mn-empty-note">Document content could not be retrieved.</p>
                    ${docLink ? `<div class="doc-source-link">${docLink}</div>` : ''}
                </div>
            </details>`;
        }

        const parsed     = parseDocumentFields(report.raw_content, refinedType);
        const parsedHtml = renderParsedFields(parsed, refinedType);

        const excerptHtml = !parsedHtml && report.raw_content
            ? `<p class="doc-excerpt">${highlightCitations(escapeHtml(extractExcerpt(report.raw_content)))}</p>`
            : '';

        const fullTextHtml = report.raw_content
            ? `<details class="violation-box">
                <summary class="deficiency-header">Full document text</summary>
                <div class="deficiency-content doc-body">${renderDocText(report.raw_content)}</div>
               </details>`
            : '';

        return `
        <details class="inspection-box ${klass}">
            <summary class="inspection-header">
                <span class="doc-type-label" data-doc-type="${typeSlug}">${typeStr}</span>
                <span class="doc-date">${dateStr}</span>
                ${tagsHtml}
            </summary>
            <div class="inspection-content">
                ${excerptHtml}
                ${parsedHtml}
                ${fullTextHtml}
                ${docLink ? `<div class="doc-source-link">${docLink}</div>` : ''}
            </div>
        </details>`;
    }

    function highlightCitations(escapedText) {
        if (!escapedText) return '';
        const patterns = [
            // Minnesota Rules citations
            /(Minnesota\s+Rules?,?\s+(?:parts?|chapter)\s+[\d.]+(?:\s*(?:through|to)\s+[\d.]+)?(?:,?\s*subparts?\s+[\d]+(?:\s*(?:through|to|,)\s*[\d]+)?)?(?:,?\s*items?\s+[A-Z0-9]+)?)/gi,
            // Minnesota Statutes citations
            /(Minnesota\s+Statutes?,?\s+sections?\s+[\d.]+[A-Z]?(?:\.\d+)*(?:,\s*subdivisions?\s+\d+(?:\(\w+\))?)?(?:,\s*paragraphs?\s+\([a-z]\))?(?:,\s*clauses?\s+\(\d+\))?)/gi,
        ];
        let html = escapedText;
        patterns.forEach(p => {
            html = html.replace(p, '<span class="mn-citation">$1</span>');
        });
        return html;
    }

    function classifyDocument(text, fallbackType) {
        // Classify using clear markers in the actual document body
        const head = (text || '').slice(0, 600);
        if (/MALTREATMENT INVESTIGATION MEMORANDUM/i.test(head))    return 'Maltreatment Investigation';
        if (/ORDER TO PAY A FINE/i.test(head))                      return 'Fine Order';
        if (/ORDER OF CONDITIONAL LICENSE/i.test(head))             return 'Conditional License Order';
        if (/Summary of Settlement Agreement/i.test(head))          return 'Settlement Agreement';
        if (/LICENSE REVOCATION|REVOCATION OF LICENSE/i.test(head)) return 'License Revocation';
        if (/CORRECTION ORDER/i.test(head))                         return 'Correction Order';
        if (/NOTICE OF .*VIOLATION/i.test(head))                    return 'Notice of Violation';
        return fallbackType || 'Document';
    }

    function isEmptyDocument(text) {
        if (!text) return true;
        const stripped = text.replace(/\s+/g, ' ').trim();
        if (stripped.length < 300) return true;
        // Detect pages that only returned the DHS footer
        return /^PO Box 64242.*Veteran Friendly Employer/i.test(stripped);
    }

    function parseDocumentFields(rawContent, docType) {
        if (!rawContent) return {};
        const t = rawContent;
        const fields = {};

        const grab = (pattern) => {
            const m = t.match(pattern);
            return m ? m[1].trim() : '';
        };

        fields.license_number = grab(/License Number[:\s]+(\d{6,})/i);
        fields.report_number  = grab(/Report Number[:\s]+(\d{7,})/i);
        fields.incident_date  = grab(/Date of Incident[s()]*[:\s]+(.+?)(?=\s+Nature of|\s+Summary of|\s+Suspected)/i);

        // Maltreatment-specific fields
        if (/Maltreatment|Investigation/i.test(docType)) {
            fields.date_issued           = grab(/Date Issued[:\s]+([\w ]+\d{4})/i);
            fields.date_reissued         = grab(/Date Reissued[:\s]+([\w ]+\d{4})/i);
            fields.disposition           = grab(/Disposition[:\s]+(.+?)(?=\s+(?:License Number|Investigator|Program Type))/i);
            fields.investigators         = grab(/Investigator[s(]*[):\s]+(.+?)(?=\s+(?:Minnesota Department|Suspected|Saint Paul|651-))/i);
            fields.maltreatment_reported = grab(/Suspected Maltreatment Reported[:\s]+(.+?)(?=\s+(?:Date of Incident|Nature of|Summary of))/i);
            fields.action_facility       = grab(/Action Taken by Facility[:\s]+(.+?)(?=\s+(?:Action Taken by Dep|Certification|Pursuant))/i);
            fields.action_dhs            = grab(/Action Taken by Department[^:]*[:\s]+(.+?)(?=\s+(?:Certification|Pursuant|PO Box))/i);
        }

        // Fine order / Conditional license / Settlement fields
        if (/Fine Order|Settlement|Conditional/i.test(docType)) {
            fields.fine_amount = grab(/(?:fine in the amount of|amount of the fine is|fine of)\s*\$?([\d,]+(?:\.\d+)?)/i);
            fields.license_action = grab(/(?:Licensing Action|License Action|Date of Licensing Action)[:\s]+(.+?)(?=\s+(?:License Holder|Program Type|Effective|$))/i);
        }

        // Extract all violation blocks (numbered OR section-headed) via Rule Violated markers
        const violations = extractViolations(t);
        if (violations.length) fields.violations = violations;

        // Remove empty fields
        Object.keys(fields).forEach(k => {
            if (!fields[k] || (Array.isArray(fields[k]) && !fields[k].length)) delete fields[k];
        });

        return fields;
    }

    function extractViolations(text) {
        // Find every violation trio (Violation: … Rule Violated: … Corrective Action Required: …)
        // Works for "1. Violation:", "Resident File Violation:", "Violation:", etc.
        const violations = [];
        const pattern = /Violation:\s+([\s\S]+?)Rule Violated:\s*([\s\S]+?)Corrective Action Required:\s*([\s\S]+?)(?=\bViolation:|Written Response|YOUR RIGHT|Legal authority|$)/g;
        let m, num = 1;
        while ((m = pattern.exec(text)) !== null) {
            violations.push({
                number:     num++,
                violation:  m[1].trim(),
                rule:       m[2].trim(),
                correction: m[3].trim(),
            });
        }
        return violations;
    }

    function renderParsedFields(fields, docType) {
        if (!fields || !Object.keys(fields).length) return '';
        const parts = [];

        const row = (label, value, highlightCite = false) => {
            const v = highlightCite ? highlightCitations(escapeHtml(value)) : escapeHtml(value);
            return `<div class="mn-field"><span class="mn-label">${label}:</span> ${v}</div>`;
        };

        if (fields.disposition)           parts.push(row('Disposition',         fields.disposition));
        if (fields.investigators)         parts.push(row('Investigator(s)',     fields.investigators));
        if (fields.incident_date)         parts.push(row('Date of Incident',    fields.incident_date));
        if (fields.maltreatment_reported) parts.push(row('Reported',            fields.maltreatment_reported, true));
        if (fields.fine_amount)           parts.push(row('Fine Amount',         '$' + fields.fine_amount));
        if (fields.license_action)        parts.push(row('Licensing Action',    fields.license_action));
        if (fields.action_facility)       parts.push(row('Action by Facility',  fields.action_facility, true));
        if (fields.action_dhs)            parts.push(row('Action by DHS',       fields.action_dhs, true));
        if (fields.report_number)         parts.push(row('Report #',            fields.report_number));

        if (fields.violations && fields.violations.length) {
            const violHtml = fields.violations.map(v => `
                <div class="mn-violation">
                    <div class="mn-violation-text"><strong>Violation ${v.number}:</strong> ${highlightCitations(escapeHtml(v.violation))}</div>
                    ${v.rule ? `<div class="mn-violation-rule"><em>Rule violated:</em> ${highlightCitations(escapeHtml(v.rule))}</div>` : ''}
                    ${v.correction ? `<div class="mn-violation-correction"><em>Required correction:</em> ${highlightCitations(escapeHtml(v.correction))}</div>` : ''}
                </div>`).join('');
            parts.push(`<div class="mn-violations-list"><strong>Licensing Violations (${fields.violations.length}):</strong>${violHtml}</div>`);
        }

        return parts.length ? `<div class="mn-parsed-fields">${parts.join('')}</div>` : '';
    }

    function extractExcerpt(text) {
        if (!text) return '';
        // Skip the letter header — find "Dear X:" and use the body after it
        const dearMatch = text.match(/Dear\s+\w[^:\n]{0,60}:/i);
        const body = dearMatch ? text.slice(dearMatch.index + dearMatch[0].length).trim() : text;
        // Take first 350 chars, cut at last sentence boundary
        const excerpt = body.slice(0, 350).replace(/\n/g, ' ');
        const lastDot = excerpt.lastIndexOf('.');
        return lastDot > 80 ? excerpt.slice(0, lastDot + 1) : excerpt;
    }

    function renderDocText(text) {
        if (!text) return '';
        const paras = text.split(/\n\n+/).map(p => p.replace(/\n/g, ' ').trim()).filter(Boolean);
        const wrap = (p) => `<p>${highlightCitations(escapeHtml(p))}</p>`;
        if (paras.length <= 1) return wrap(text);
        return paras.map(wrap).join('\n');
    }

    function escapeHtml(s) {
        return safeString(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function escapeAttr(s) { return escapeHtml(s); }

    initializeReport();
});
