/**
 * Washington reports (/wa-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=WA: Washington Department of
 * Health documents for behavioral health agency (BHA.FS.*) and residential
 * treatment facility (RTF.FS.*) licenses. categories.report_category is one of
 *   state_investigation  complaint investigation letters / statements
 *   state_inspection     inspection reports with a deficiency table
 *   enforcement          Notices of Intent and Summary Action Orders
 * categories.deficiencies is empty on every record, so outcomes are read from
 * the document text:
 *   - "No current deficiencies were identified" -> clean,
 *   - "deficiencies were identified" / a Statement of Deficiencies, or
 *     deficiency-table rows ("0695 Personnel-Agency record requirements" with
 *     a WAC reference) -> flagged,
 *   - enforcement actions -> flagged,
 *   - anything else -> neutral, pointing to the official document.
 * The old viewer read the empty deficiencies array and showed "No violations
 * noted" on all 88 records, including the enforcement actions.
 *
 * Misattached documents: some case numbers carry another case's PDF and text
 * (twelve Pearl Youth Residence cases from 2021-2024 all hold case
 * 2023-11257's document). A record whose own case number is not in the text,
 * while another record with the same document is, shows only its case number.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Washington reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var MONTHS = { january: 0, february: 1, march: 2, april: 3, may: 4, june: 5, july: 6,
                   august: 7, september: 8, october: 9, november: 10, december: 11 };

    var SAYS_NONE = /\bno\s+(?:current\s+)?deficienc(?:y|ies)\s+(?:was|were)\s+(?:identified|found|cited|noted)/i;
    var SAYS_SOME = /\bdeficienc(?:y|ies)\s+(?:was|were)\s+(?:identified|found|cited|noted)|statement of deficienc/i;
    var OUTCOME_SENTENCE = /[^.\n]*(?:no\s+(?:current\s+)?deficienc(?:y|ies)\s+(?:was|were)|deficienc(?:y|ies)\s+(?:was|were))\s+(?:identified|found|cited|noted)[^.]*\./i;

    // "September 25,2020", "January 29, 2025", "01/29/25" -> Date.
    function parseDate(dateStr) {
        var text = safeString(dateStr);
        var m = text.match(/^([A-Za-z]+)\s+(\d{1,2}),\s*(\d{4})/);
        if (m && MONTHS[m[1].toLowerCase()] !== undefined) {
            return new Date(parseInt(m[3], 10), MONTHS[m[1].toLowerCase()], parseInt(m[2], 10));
        }
        m = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/);
        if (m) {
            var y = parseInt(m[3], 10);
            if (y < 100) y += (y < 50) ? 2000 : 1900;
            return new Date(y, parseInt(m[1], 10) - 1, parseInt(m[2], 10));
        }
        return new Date(0);
    }

    /** Deficiency-table rows in an inspection report, with their WAC rule. */
    function deficiencyRows(text) {
        var lines = String(text || '').split('\n');
        var rows = [];
        var seen = {};
        lines.forEach(function (line, i) {
            var m = line.match(/^(\d{4})\s+([A-Z][A-Za-z ,\/&()-]{3,60}?)(?:\s{2,}|\s+Based on|\s+(?:Staff|Record|Observation|Interview)|$)/);
            if (!m || seen[m[1]]) return;
            var nearby = lines.slice(i, i + 3).join(' ');
            var wac = nearby.match(/WAC\s+\d{3}-\d{3}-\d{4}(?:\([0-9a-z]+\))*/);
            if (!wac) return;
            seen[m[1]] = true;
            rows.push({ number: m[1], title: m[2].trim(), citation: wac[0] });
        });
        return rows;
    }

    function outcomeSentence(text) {
        var m = String(text || '').replace(/\s*\n\s*/g, ' ').match(OUTCOME_SENTENCE);
        return m ? m[0].replace(/\s+/g, ' ').trim() : '';
    }

    function readRecord(report) {
        var cats = report.categories || {};
        var text = String(report.raw_content || '');
        var category = safeString(cats.report_category);
        var caseNo = safeString(cats.inspection_number).replace(/\s+(Notice of Intent|Summary Action Order)$/i, '');
        var action = (safeString(cats.inspection_number).match(/(Notice of Intent|Summary Action Order)$/i) || [''])[0];
        // Statement of Deficiency Reports from investigations use the same table.
        var rows = category === 'enforcement' ? [] : deficiencyRows(text);

        var outcome = 'neutral';
        if (category === 'enforcement') outcome = 'flagged';
        else if (SAYS_NONE.test(text)) outcome = 'clean';
        else if (rows.length || SAYS_SOME.test(text)) outcome = 'flagged';

        return {
            category:     category,
            case_no:      caseNo,
            action:       action,
            report_date:  report.report_date || cats.inspection_date || '',
            inspection_type: safeString(cats.inspection_type),
            onsite_dates: (text.match(/Date\(s\) of Investigation:\s*([0-9\/ ,&-]+)/) || ['', ''])[1].trim(),
            pdf_url:      safeString(cats.pdf_url),
            raw_content:  text,
            rows:         rows,
            outcome:      outcome,
            outcome_text: category === 'enforcement' ? '' : outcomeSentence(text),
            misattached:  false
        };
    }

    /**
     * Within one facility, records sharing a document: the one whose case
     * number appears in the text owns it; the rest are misattached.
     */
    function markMisattached(reports) {
        var groups = {};
        reports.forEach(function (r) {
            if (!r.pdf_url || !r.raw_content) return;
            var key = r.pdf_url + '|' + r.raw_content.length;
            (groups[key] = groups[key] || []).push(r);
        });
        Object.keys(groups).forEach(function (key) {
            var group = groups[key];
            if (group.length < 2) return;
            var owners = group.filter(function (r) { return r.case_no && r.raw_content.indexOf(r.case_no) !== -1; });
            if (!owners.length) return;
            group.forEach(function (r) {
                if (owners.indexOf(r) !== -1) return;
                r.misattached = true;
                r.outcome = 'neutral';
                r.outcome_text = '';
                r.rows = [];
                r.report_date = '';
                r.pdf_url = '';
                r.raw_content = '';
                r.owner_case = owners[0].case_no;
            });
        });
        return reports;
    }

    function caseYear(caseNo) {
        var m = String(caseNo || '').match(/^[A-Z]?(20\d\d)-/);
        return m ? m[1] : '';
    }

    function convertApiDataToFacilities(apiFacilities) {
        return apiFacilities.map(function (facility) {
            var info = facility.facility_info || {};
            var reports = markMisattached((facility.reports || []).map(readRecord))
                .sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });
            return {
                name:          safeString(info.facility_name),
                license:       safeString(info.program_name),
                address:       safeString(info.full_address),
                administrator: safeString(info.executive_director),
                status:        safeString(info.action),
                reports:       reports
            };
        });
    }

    function isFlagged(report) {
        return report.outcome === 'flagged';
    }

    function licenseKind(license) {
        if (/^RTF\./.test(license)) return 'Residential treatment facility';
        if (/^BHA\./.test(license)) return 'Behavioral health agency';
        return '';
    }

    function recordType(report) {
        if (report.category === 'enforcement') return (report.action || 'Enforcement action');
        if (report.category === 'state_inspection') {
            var kind = report.inspection_type.replace(/^ONGOING\s*-\s*/i, '').toLowerCase();
            return kind ? kind.charAt(0).toUpperCase() + kind.slice(1) + ' inspection' : 'Inspection';
        }
        return 'Complaint investigation';
    }

    page.mount({
        state: 'Washington',
        emptyMessage: 'No facilities found in the database for Washington.',

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=WA')
                .then(function (resp) {
                    if (!resp.ok) throw new Error('API returned ' + resp.status);
                    return resp.json();
                })
                .then(function (apiData) {
                    return {
                        facilities: convertApiDataToFacilities(apiData.facilities || []),
                        scrapedTimestamp: apiData.scraped_timestamp || ''
                    };
                });
        },

        facilityName: function (f) { return f.name || ''; },

        searchText: function (f) {
            return [f.name, f.license, f.address, f.administrator]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var flagged = reports.filter(isFlagged).length;
            var enforcement = reports.filter(function (r) { return r.category === 'enforcement'; }).length;
            var clean = reports.filter(function (r) { return r.outcome === 'clean'; }).length;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'record'), tone: 'neutral' }];
            if (enforcement) stats.push({ text: ctx.plural(enforcement, 'enforcement action'), tone: 'flagged' });
            if (flagged - enforcement > 0) stats.push({ text: ctx.plural(flagged - enforcement, 'report') + ' with deficiencies', tone: 'flagged' });
            if (!flagged && clean) stats.push({ text: 'No deficiencies found', tone: 'clean' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [
                    licenseKind(facility.license),
                    facility.license ? 'License ' + facility.license : '',
                    facility.administrator ? 'Administrator ' + facility.administrator : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var badges = [];
            if (report.misattached) {
                badges.push({ text: 'No document on file', tone: 'neutral' });
            } else if (report.category === 'enforcement') {
                badges.push({ text: 'Enforcement action', tone: 'flagged' });
            } else if (report.outcome === 'flagged') {
                badges.push({ text: report.rows.length ? ctx.plural(report.rows.length, 'deficiency', 'deficiencies') : 'Deficiencies found', tone: 'flagged' });
            } else if (report.outcome === 'clean') {
                badges.push({ text: 'No deficiencies', tone: 'clean' });
            }

            var time = parseDate(report.report_date).getTime();
            var year = caseYear(report.case_no);
            return {
                date: ctx.formatDate(time) || (year ? year : 'Date not listed'),
                type: recordType(report),
                tone: report.misattached ? 'neutral' : report.outcome,
                badges: badges,
                facts: [
                    report.case_no ? 'Case ' + report.case_no : '',
                    report.onsite_dates ? 'Investigated ' + report.onsite_dates : ''
                ],
                link: { href: report.pdf_url, text: 'Official document' },
                preview: report.outcome_text,
                body: function () {
                    if (report.misattached) {
                        return ui.note('The Department of Health document for this case is not in our records. The file attached to it belonged to case '
                            + report.owner_case + ', so it is shown under that case instead.');
                    }
                    var html = '';
                    if (report.outcome_text) html += ui.paragraphs([report.outcome_text]);
                    if (report.category === 'enforcement') {
                        html += ui.note('An enforcement action is a formal step by the Department of Health against the facility’s license. The document has the findings and terms.');
                    }
                    if (report.rows.length) {
                        html += ui.heading(ctx.plural(report.rows.length, 'deficiency', 'deficiencies') + ' cited');
                        html += report.rows.map(function (row) {
                            return ui.finding({
                                title: row.title,
                                citation: row.citation,
                                chips: [{ text: 'No. ' + row.number, tone: 'neutral' }],
                                tone: 'flagged'
                            });
                        }).join('');
                    }
                    if (!report.outcome_text && !report.rows.length && report.category !== 'enforcement') {
                        // Flagged without a parsed table or outcome sentence: a Statement of
                        // Deficiency Report, or a letter accepting the facility's plan of
                        // correction for the deficiencies in one.
                        html += report.outcome === 'flagged'
                            ? ui.note('The Department of Health found deficiencies in this case. They are listed in the full text below and in the official document.')
                            : ui.note('The result could not be read from this document. The official document has the details.');
                    }
                    html += ui.section('Full document text', ui.docText(report.raw_content));
                    return html;
                }
            };
        }
    });
}());
