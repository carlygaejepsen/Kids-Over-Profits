/**
 * Arkansas reports (/ar-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=AR. These are not state
 * inspection reports: they are documents published by Disability Rights
 * Arkansas about each facility -- notices of serious incident, police reports,
 * licensing compliance reports, complaint and recertification surveys. Every
 * document carries a one-sentence summary written by DRA and topic tags
 * ("Self Harm", "Peer Violence", "Citation"), which are the most readable
 * thing to show; the extracted text is kept in a collapsed section.
 *
 * What counts as a violation: a document tagged "Citation" -- the state cited
 * the facility or found a complaint valid. The old viewer flagged every
 * incident notice and compliance report plus any maltreatment-type tag, which
 * marked 2,115 of 2,517 documents (including 163 whose own summary says no
 * deficiencies were cited). Incident topics are still shown as tags; they are
 * reports of what happened, not findings against the facility.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Arkansas reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    // Police report text is OCR of a checkbox form ("[_]@) Drugs"): unreadable,
    // so those link to the scanned document instead of showing text.
    var SCAN_ONLY_TYPES = { 'Police Report': true };

    // "8/19/2025", "08/19/2025", "8/19/25" -> Date.
    function parseDate(dateStr) {
        if (!dateStr) return new Date(0);
        var first = String(dateStr).split(/\s*[-–]\s*/)[0].trim();
        var parts = first.split('/');
        if (parts.length === 3) {
            var m = parseInt(parts[0], 10);
            var d = parseInt(parts[1], 10);
            var y = parseInt(parts[2], 10);
            if (y < 100) y += (y < 50) ? 2000 : 1900;
            return new Date(y, m - 1, d);
        }
        var parsed = new Date(first);
        return isNaN(parsed.getTime()) ? new Date(0) : parsed;
    }

    function convertApiDataToFacilities(apiFacilities) {
        return apiFacilities.map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var tags = (Array.isArray(cats.tags) ? cats.tags : []).map(safeString).filter(Boolean);
                return {
                    report_date:  report.report_date || '',
                    doc_type:     safeString(cats.doc_type) || 'Document',
                    summary:      safeString(report.summary),
                    raw_content:  report.raw_content || '',
                    tags:         tags,
                    cited:        tags.indexOf('Citation') !== -1,
                    pdf_url:      cats.pdf_url || report.report_url || '',
                    doc_page_url: cats.doc_page_url || ''
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            return {
                name:             safeString(info.facility_name),
                program_name:     safeString(info.program_name),
                program_category: safeString(info.program_category),
                address:          safeString(info.full_address),
                reports:          reports
            };
        });
    }

    function isCited(report) {
        return report.cited;
    }

    page.mount({
        state: 'Arkansas',
        emptyMessage: 'No facilities found in the database for Arkansas.',

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=AR')
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
            return [f.name, f.program_name, f.program_category]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isCited,

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var cited = reports.filter(isCited).length;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'document'), tone: 'neutral' }];
            if (cited) stats.push({ text: ctx.plural(cited, 'citation'), tone: 'flagged' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [facility.program_category || 'Arkansas residential treatment'],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var badges = report.cited ? [{ text: 'Citation', tone: 'flagged' }] : [];
            // "Oversight Agency" is on 2,074 of 2,517 documents, so it says nothing.
            var topics = report.tags
                .filter(function (t) { return t !== 'Citation' && t !== 'Oversight Agency' && t !== report.doc_type; })
                .map(function (t) { return { text: t, tone: 'neutral' }; });

            var body = '';
            body += report.summary
                ? ui.paragraphs([report.summary], 'kop-rp-summary')
                : ui.note('No summary was published for this document.');
            body += ui.chipRow(topics);
            if (!SCAN_ONLY_TYPES[report.doc_type]) {
                body += ui.section('Document text', ui.docText(report.raw_content));
            } else if (report.pdf_url) {
                body += ui.note('This is a scanned police form; open the document to read it.');
            }

            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || report.report_date,
                type: report.doc_type,
                tone: report.cited ? 'flagged' : 'neutral',
                badges: badges,
                facts: ['Published by Disability Rights Arkansas'],
                link: { href: report.pdf_url, text: 'View document' },
                links: [{ href: report.doc_page_url, text: 'DRA page' }],
                preview: report.summary,
                body: body
            };
        }
    });
}());
