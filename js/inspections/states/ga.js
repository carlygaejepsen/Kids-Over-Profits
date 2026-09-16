/**
 * Georgia inspection reports (/ga-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=GA (DB-backed; rows written
 * by ga_scraper.py). Each report's categories carry survey_type ("Incident",
 * "Re-Licensure", "Follow-up/Revisit", ...), survey_status, survey start/exit
 * dates, under_appeal, and sod_url -- a link to Georgia DHS's Statement of
 * Deficiencies page for the survey. raw_content is usually just the report
 * viewer's UI boilerplate (the PDF text extraction failed), so it is only
 * rendered when it looks like real narrative.
 *
 * Replaces js/inspections/ga_reports.js; everything shared across states
 * (filters, search, sort, list shell) now lives in report-page.js.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Georgia reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var escapeHtml = page.escapeHtml;

    // "3/14/24" or "03/14/2024 - 03/15/2024" -> the first date.
    function parseDate(dateStr) {
        if (!dateStr) return new Date(0);
        var first = String(dateStr).split(/\s*[-–]\s*/)[0].trim();
        var slashParts = first.split('/');
        if (slashParts.length === 3) {
            var m = parseInt(slashParts[0], 10);
            var d = parseInt(slashParts[1], 10);
            var y = parseInt(slashParts[2], 10);
            if (y < 100) y += (y < 50) ? 2000 : 1900;
            return new Date(y, m - 1, d);
        }
        var parsed = new Date(first);
        return isNaN(parsed.getTime()) ? new Date(0) : parsed;
    }

    // GA has no parsed deficiency counts -- Incident and complaint surveys are
    // the ones worth flagging, so they drive the violation sorts and styling.
    function reportIsIncident(report) {
        return /incident|complaint/i.test(safeString(report.survey_type));
    }

    // raw_content is real narrative only when it is substantial and not the
    // SOD viewer's export-widget chrome.
    function usableRawContent(report) {
        var raw = safeString(report.raw_content);
        if (raw.length < 400) return '';
        if (/Export to the selected format|Generating report/i.test(raw)) return '';
        return raw;
    }

    function convertApiDataToFacilities(apiFacilities) {
        return apiFacilities.map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                return {
                    report_id:     report.report_id || '',
                    report_date:   report.report_date || '',
                    summary:       report.summary || '',
                    raw_content:   report.raw_content || '',
                    survey_type:   cats.survey_type || '',
                    survey_status: cats.survey_status || '',
                    survey_start:  cats.survey_start_date || '',
                    survey_exit:   cats.survey_exit_date || '',
                    under_appeal:  cats.under_appeal || '',
                    sod_url:       cats.sod_url || report.report_url || '',
                    event_id:      cats.event_id || ''
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            return {
                name:             safeString(info.facility_name),
                program_name:     safeString(info.program_name),   // GA FACID
                program_category: safeString(info.program_category),
                address:          safeString(info.full_address),
                phone:            safeString(info.phone),
                license_status:   safeString(info.action),
                reports:          reports
            };
        });
    }

    page.mount({
        state: 'Georgia',
        emptyMessage: 'No facilities found in the database for Georgia.',

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=GA')
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
            return [f.name, f.program_name, f.program_category, f.address]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: reportIsIncident,

        renderSummary: function (facility, ctx) {
            var total = ctx.reports(facility).length;
            var incidents = ctx.countFlagged(facility);
            return '<h1>' + (escapeHtml(facility.name) || 'N/A') + '</h1>'
                + '<h2>' + (escapeHtml(facility.program_category) || 'Georgia Residential') + '</h2>'
                + '<p class="facility-details">'
                + total + ' survey' + (total === 1 ? '' : 's')
                + (incidents ? ' &middot; ' + incidents + ' incident' + (incidents === 1 ? '' : 's') : '')
                + (facility.program_name ? ' &middot; FACID: ' + escapeHtml(facility.program_name) : '')
                + '</p>';
        },

        renderBody: function (facility) {
            return facility.address ? '<p class="facility-details">' + escapeHtml(facility.address) + '</p>' : '';
        },

        renderReport: function (report) {
            var isIncident = reportIsIncident(report);
            var klass = isIncident ? 'inspection-box-violation' : 'inspection-box-clean';
            var dateStr = escapeHtml(report.report_date) || 'Date unknown';
            var typeStr = escapeHtml(report.survey_type) || 'Survey';

            var visitHtml = (report.survey_start && report.survey_start !== report.survey_exit)
                ? '<strong>Survey started:</strong> ' + escapeHtml(report.survey_start) + '<br>'
                : '';
            var appealHtml = (report.under_appeal && !/^(n|no|false|0)$/i.test(report.under_appeal))
                ? '<strong>Under appeal:</strong> ' + escapeHtml(report.under_appeal) + '<br>'
                : '';
            var sodHtml = report.sod_url
                ? '<strong>Official report:</strong> <a href="' + escapeHtml(report.sod_url) + '" target="_blank" rel="noopener">Statement of Deficiencies (GA DHS)</a><br>'
                : '';

            var raw = usableRawContent(report);
            var rawHtml = raw
                ? '<details class="violation-box">'
                    + '<summary class="deficiency-header">Survey record</summary>'
                    + '<div class="deficiency-content"><pre style="white-space:pre-wrap;font-family:inherit;">' + escapeHtml(raw) + '</pre></div>'
                    + '</details>'
                : '';

            return '<details class="inspection-box ' + klass + '">'
                + '<summary class="inspection-header">'
                + typeStr + ' &mdash; ' + dateStr
                + (isIncident ? ' <span class="deficiency-badge">incident</span>' : '')
                + '</summary>'
                + '<div class="inspection-content">'
                + '<div class="inspection-details-block">'
                + '<strong>Type:</strong> ' + typeStr + '<br>'
                + '<strong>Date:</strong> ' + dateStr + '<br>'
                + (report.survey_status ? '<strong>Status:</strong> ' + escapeHtml(report.survey_status) + '<br>' : '')
                + visitHtml
                + appealHtml
                + (report.event_id ? '<strong>Survey ID:</strong> ' + escapeHtml(report.event_id) + '<br>' : '')
                + sodHtml
                + '</div>'
                + rawHtml
                + '</div>'
                + '</details>';
        }
    });
}());
