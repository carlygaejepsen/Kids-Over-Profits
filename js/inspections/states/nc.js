/**
 * North Carolina inspection reports (/nc-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=NC: NC DHSR Mental Health
 * Licensure and Certification Section documents, mostly Statements of
 * Deficiencies with some Plans of Correction. categories carry inspection_type
 * ("MHLCS Annual and Complaint"), document_type, pages, pdf_url and fid.
 *
 * The text is OCR of a two-column CMS-2567-style form, so narrative paragraphs
 * interleave and are not rebuilt; the full text stays available collapsed.
 * What survives OCR reliably, and is read here:
 *   - "This Rule is not met as evidenced by:" once per citation (the count),
 *   - the survey result sentence ("No deficiencies were cited."),
 *   - the opening narrative ("An annual and complaint survey was completed on
 *     5/15/18. The complaint was unsubstantiated."),
 *   - deficiency tags with their rule headings ("V 118 27G .0209 (C)
 *     Medication Requirements"), best effort.
 *
 * What counts as a violation: a statement that cites a rule. The old viewer
 * flagged any document containing "deficien" and similar words, which marked
 * 4,780 of 4,834 documents, including the ones stating no deficiencies were
 * cited. It also read a doc_type field NC does not have, so every document was
 * labelled "Document".
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('North Carolina reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var NOT_MET = /(?:Rule|Requirement|Standard|Statute)\s+(?:is|are|was)\s+not\s+met\s+as\s+evidenced\s+by/gi;
    var SAID_NONE = /\bno\s+deficienc(?:y|ies)\s+(?:was|were)\s+(?:cited|found|identified)/i;
    var SAID_CITED = /\bdeficienc(?:y|ies)\s+(?:was|were)\s+cited/i;
    var ATTEMPTED = /survey\s+was\s+attempted/i;
    // Allows an intake reference between subject and verb:
    // "The complaint (intake #NC00176347) was substantiated."
    var COMPLAINT_OUTCOME = /complaints?\s+(?:\([^)]{0,80}\)\s*)?(?:was|were|is|are)\s+(un)?substantiated|allegations?\s+(?:\([^)]{0,80}\)\s*)?(?:was|were)\s+(un)?substantiated|(un)?substantiated\s+complaint/gi;
    var OPENING = /\b(?:An?|The)\s+(?:[a-z,\s-]{0,60})?survey\s+was\s+(?:completed|attempted|conducted)[\s\S]{0,900}?(?:deficienc(?:y|ies)\s+(?:was|were)\s+(?:cited|found|identified)\.|(?=\n\s*\n\s*This facility is licensed))/i;
    // Form chrome that means the OCR interleaved a column into the opening.
    var FORM_CHROME = /PREFIX|\(X\d\)|STATEMENT OF DEFICIENCIES|Division of Health Service|PROVIDER'S PLAN/i;

    // "4/30/2025", "10/02/2019" -> Date.
    function parseDate(dateStr) {
        var m = String(dateStr || '').trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})/);
        if (!m) return new Date(0);
        var y = parseInt(m[3], 10);
        if (y < 100) y += (y < 50) ? 2000 : 1900;
        return new Date(y, parseInt(m[1], 10) - 1, parseInt(m[2], 10));
    }

    /**
     * Deficiency tags and their rule headings, best effort. The count of
     * citations comes from NOT_MET, not from this list.
     */
    function citedRules(text) {
        var TAG_LINE = /^\s*[Vv]{1,2}\s?(\d{3})\)?!?\s*\|?\s*(.{0,120})$/gm;
        var CITE = /^((?:\d{2}[A-Z]\s?\.\s?\d{4}(?:\s?\([A-Za-z0-9-]{1,6}\))*)|(?:G\.\s?S\.\s?§?\s?[0-9A-Z]+-[0-9A-Z.]+(?:\s?\([A-Za-z0-9]{1,4}\))*))\s*/;
        var clean = function (line) {
            return String(line || '')
                .replace(/^[\]\}\|\)\(\[\{!'"‘’.\s]+/, '')      // column border misread by OCR
                .replace(/\s+[Vv]{1,2}\s?\d{0,3}\)?\s*$/, '')          // trailing repeat of the tag
                .replace(/\s+/g, ' ')
                .trim();
        };
        var byTag = {};
        var order = [];
        var m;
        while ((m = TAG_LINE.exec(text))) {
            var code = m[1];
            var heading = clean(m[2]);
            if (!heading) {
                // Heading on the following line.
                var after = text.slice(m.index + m[0].length).replace(/^\s*\n/, '');
                heading = clean(after.split('\n')[0]);
                if (!CITE.test(heading)) heading = '';
            }
            if (!byTag[code]) { byTag[code] = []; order.push(code); }
            byTag[code].push(heading);
        }

        var rules = [];
        order.forEach(function (code) {
            if (code === '000') return;
            var best = '';
            byTag[code].forEach(function (h) {
                if (!h || /continued from page|initial comments/i.test(h)) return;
                if (!best || (CITE.test(h) && !CITE.test(best))) best = h;
            });
            if (!best) return;
            var citation = '';
            var title = best;
            var cm = best.match(CITE);
            if (cm) {
                citation = cm[1].replace(/\s+/g, ' ').replace(/(\S)\(/g, '$1 (').trim();
                title = best.slice(cm[0].length);
            }
            title = title.replace(/[,;:|&]+$/, '').replace(/\s+[A-Za-z]$/, '').trim();
            if (!title && !citation) return;
            rules.push({ tag: 'V ' + code, citation: citation, title: title });
        });
        return rules;
    }

    function openingNarrative(text) {
        var m = String(text || '').match(OPENING);
        if (!m) return '';
        var sentence = m[0].replace(/\s*\n\s*/g, ' ').replace(/\s+/g, ' ').trim();
        if (FORM_CHROME.test(sentence) || sentence.length > 700) return '';
        return sentence;
    }

    function complaintOutcome(text) {
        var found = { substantiated: false, unsubstantiated: false };
        String(text || '').replace(COMPLAINT_OUTCOME, function (match, a, b, c) {
            if (a || b || c) found.unsubstantiated = true;
            else found.substantiated = true;
            return match;
        });
        return found;
    }

    function readStatement(text) {
        var citations = (String(text).match(NOT_MET) || []).length;
        var saysNone = SAID_NONE.test(text);
        if (!citations && !saysNone && SAID_CITED.test(text)) citations = 1;   // "A deficiency was cited."
        return {
            citations: citations,
            clean: !citations && saysNone,
            attempted: !citations && ATTEMPTED.test(text),
            complaint: complaintOutcome(text),
            opening: openingNarrative(text)
        };
    }

    // "MHLCS Annual, Complaint, and Follow-up" -> "Annual, complaint and follow-up survey"
    function surveyLabel(inspectionType) {
        var parts = safeString(inspectionType).replace(/^MHLCS\s*/i, '')
            .split(/\s*(?:,|\band\b)\s*/i)
            .map(function (p) { return p.trim().toLowerCase(); })
            .filter(Boolean);
        if (!parts.length) return 'Survey';
        var joined = parts.length === 1 ? parts[0]
            : parts.slice(0, -1).join(', ') + ' and ' + parts[parts.length - 1];
        return joined.charAt(0).toUpperCase() + joined.slice(1) + ' survey';
    }

    function isStatement(documentType) {
        return /defic|defen|statement/i.test(documentType);
    }

    function convertApiDataToFacilities(apiFacilities) {
        return (apiFacilities || []).map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var text = String(report.raw_content || '');
                var statement = isStatement(cats.document_type || report.summary);
                var read = statement ? readStatement(text) : { citations: 0, clean: false, attempted: false, complaint: {}, opening: '' };
                return {
                    report_date:   cats.inspection_date || report.report_date || '',
                    survey:        surveyLabel(cats.inspection_type),
                    is_statement:  statement,
                    pages:         parseInt(cats.pages, 10) || 0,
                    pdf_url:       cats.pdf_url || report.report_url || '',
                    raw_content:   text,
                    citations:     read.citations,
                    clean:         read.clean,
                    attempted:     read.attempted,
                    complaint:     read.complaint,
                    opening:       read.opening
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            return {
                name:             safeString(info.facility_name),
                license:          safeString(info.program_name),
                category:         safeString(info.program_category),
                address:          safeString(info.full_address),
                director:         safeString(info.executive_director),
                capacity:         safeString(info.bed_capacity),
                status:           safeString(info.action),
                reports:          reports
            };
        });
    }

    function hasCitations(report) {
        return report.citations > 0;
    }

    // "RESIDENTL" and similar truncated database codes read poorly.
    var CATEGORY_LABELS = { RESIDENTL: 'Residential', DAY: 'Day treatment' };

    page.mount({
        state: 'North Carolina',
        emptyMessage: 'No facilities found in the database for North Carolina.',

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=NC')
                .then(function (resp) {
                    if (!resp.ok) throw new Error('API returned ' + resp.status);
                    return resp.json();
                })
                .then(function (apiData) {
                    return {
                        facilities: convertApiDataToFacilities(apiData.facilities),
                        scrapedTimestamp: apiData.scraped_timestamp || ''
                    };
                });
        },

        facilityName: function (f) { return f.name || ''; },

        searchText: function (f) {
            return [f.name, f.license, f.category, f.address, f.director]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: hasCitations,

        countFlagged: function (f) {
            return f.reports.reduce(function (sum, r) { return sum + r.citations; }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var statements = reports.filter(function (r) { return r.is_statement; });
            var cited = ctx.countFlagged(facility);
            var substantiated = reports.filter(function (r) { return r.complaint.substantiated; }).length;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'report'), tone: 'neutral' }];
            if (cited) stats.push({ text: ctx.plural(cited, 'violation') + ' cited', tone: 'flagged' });
            else if (statements.some(function (r) { return r.clean; })) stats.push({ text: 'No violations cited', tone: 'clean' });
            if (substantiated) stats.push({ text: ctx.plural(substantiated, 'substantiated complaint'), tone: 'flagged' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [
                    CATEGORY_LABELS[facility.category] || facility.category,
                    facility.license ? 'License\u00a0' + facility.license : '',
                    facility.capacity ? 'Capacity ' + facility.capacity : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var badges = [];
            var tone = 'neutral';
            if (!report.is_statement) {
                badges.push({ text: 'Facility response', tone: 'neutral' });
            } else if (report.citations) {
                tone = 'flagged';
                badges.push({ text: ctx.plural(report.citations, 'violation'), tone: 'flagged' });
            } else if (report.clean) {
                tone = 'clean';
                badges.push({ text: 'No violations', tone: 'clean' });
            } else if (report.attempted) {
                badges.push({ text: 'Survey not completed', tone: 'neutral' });
            }
            if (report.complaint.substantiated) badges.push({ text: 'Complaint substantiated', tone: 'flagged' });
            else if (report.complaint.unsubstantiated) badges.push({ text: 'Complaint unsubstantiated', tone: 'neutral' });

            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || 'Date unknown',
                type: report.is_statement ? report.survey : 'Plan of correction · ' + report.survey.toLowerCase(),
                tone: tone,
                badges: badges,
                facts: [report.pages ? ctx.plural(report.pages, 'page') : ''],
                link: { href: report.pdf_url, text: 'Official report' },
                preview: report.opening,
                body: function () {
                    var html = '';
                    if (report.opening) html += ui.paragraphs([report.opening]);
                    if (report.is_statement && report.citations) {
                        var rules = citedRules(report.raw_content);
                        html += ui.heading(ctx.plural(report.citations, 'violation') + ' cited');
                        if (rules.length) {
                            html += rules.map(function (r) {
                                return ui.finding({
                                    title: r.title || r.citation || 'Cited rule',
                                    citation: r.title ? r.citation : '',
                                    chips: [{ text: r.tag, tone: 'neutral' }],
                                    tone: 'flagged'
                                });
                            }).join('');
                        }
                        html += ui.note('This report is a scan, so the inspector’s findings are in the full text below and in the official report.');
                    } else if (!report.opening && report.is_statement && !report.clean) {
                        html += ui.note('The result of this survey could not be read from the scan. The official report has the details.');
                    }
                    html += ui.section('Full report text', ui.docText(report.raw_content));
                    return html;
                }
            };
        }
    });
}());
