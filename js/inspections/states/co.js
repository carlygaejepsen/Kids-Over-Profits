/**
 * Colorado health facility inspections (/co-reports/) -- adapter for
 * report-page.js.
 *
 * Data comes from api/inspections-read.php?state=CO (co_scraper.py in the
 * Tools repo): the Colorado Department of Public Health and Environment
 * (CDPHE) "Find and compare facilities" dashboard, which shows every
 * inspection of a health-licensed facility in the last three years with each
 * citation's text and the facility's plan of correction. The scraper reads it
 * slowly through the dashboard itself and posts only the facilities on its
 * youth allowlist (co_scope.json): psychiatric residential treatment
 * facilities and psychiatric hospitals with units for children or
 * adolescents. Most Colorado youth programs hold a child-welfare licence
 * instead, which publishes nothing.
 *
 * One report = one inspection (survey). categories.citations[] holds each
 * citation: the state's code and title, scope and severity (federal surveys
 * only), the surveyor's text and the facility's plan of correction when the
 * dashboard has one. Code "0000" and "9999" are the inspection's opening and
 * closing comments, kept in categories.comments, never counted as citations.
 *
 * What counts as a violation: an inspection with at least one citation. A
 * citation is the state's (or, for federal surveys, CMS's) finding that a
 * rule was not met. An inspection with only comments is "no citations".
 *
 * Psychiatric hospitals also run adult units, and the dashboard does not say
 * which unit a citation concerns; the citation text usually does, so the
 * text is shown whole and the page says so.
 *
 * The page loads in full (a few hundred reports at most).
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Colorado reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var HOSPITAL_NOTE = 'This is a psychiatric hospital that may also treat adults. The state does not say which unit a citation '
        + 'concerns; the surveyor’s text usually does.';
    var SOURCE_NOTE = 'Citation text and plans of correction are copied from the health department’s dashboard. '
        + 'The plan of correction is the facility’s own answer.';
    var NONE_NOTE = 'The inspection cited no rules.';
    var COMMENTS_TITLE = 'Surveyor’s opening and closing comments';

    // "2024-03-19" -> local Date; anything else through Date; '' -> epoch 0.
    function parseDate(value) {
        var m = safeString(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
        var d = new Date(safeString(value));
        return isNaN(d.getTime()) ? new Date(0) : d;
    }

    function clip(text, max) {
        text = safeString(text).replace(/\s+/g, ' ');
        return text.length > max ? text.slice(0, max - 1).replace(/\s+\S*$/, '') + '…' : text;
    }

    function paragraphs(text) {
        return safeString(text).split(/\n+/).map(function (line) { return line.trim(); }).filter(Boolean);
    }

    function isHospital(category) {
        return /hospital/i.test(safeString(category));
    }

    function convertApiDataToFacilities(apiFacilities) {
        return (apiFacilities || []).map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var citations = (Array.isArray(cats.citations) ? cats.citations : []).filter(function (c) {
                    return c && safeString(c.code) && !/^(0000|9999)$/.test(safeString(c.code));
                });
                return {
                    report_id:      safeString(report.report_id),
                    report_date:    safeString(report.report_date),
                    report_url:     safeString(report.report_url),
                    summary:        safeString(report.summary),
                    inspectionType: safeString(cats.inspection_type),
                    isComplaint:    !!cats.is_complaint,
                    comments:       safeString(cats.comments),
                    citations:      citations,
                    count:          citations.length
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            return {
                name:     safeString(info.facility_name),
                stateId:  safeString(info.program_name).replace(/^CO-/, ''),
                category: safeString(info.program_category),
                address:  safeString(info.full_address),
                phone:    safeString(info.phone),
                status:   safeString(info.action),
                reports:  reports
            };
        });
    }

    function isFlagged(report) {
        return report.count > 0;
    }

    function citationFinding(citation) {
        var chips = [];
        var ss = safeString(citation.scope_severity);
        if (ss && ss !== 'N/A') chips.push({ text: 'Scope and severity ' + ss, tone: 'neutral' });
        var more = [];
        if (safeString(citation.plan)) {
            more.push({ title: 'The facility’s plan of correction', paragraphs: paragraphs(citation.plan) });
        }
        return ui.finding({
            title: safeString(citation.title) || 'Rule cited',
            citation: 'Code ' + safeString(citation.code),
            chips: chips,
            evidence: paragraphs(citation.text),
            requirement: [],
            more: more,
            tone: 'flagged'
        });
    }

    function reportBody(report, facility, ctx) {
        var html = '';
        if (report.count) {
            html += ui.heading(ctx.plural(report.count, 'citation') + ' cited');
            html += report.citations.map(citationFinding).join('');
        } else {
            html += ui.note(NONE_NOTE);
        }
        if (report.comments) {
            html += ui.section(COMMENTS_TITLE, ui.paragraphs(paragraphs(report.comments)), { open: !report.count });
        }
        if (facility && isHospital(facility.category)) html += ui.note(HOSPITAL_NOTE);
        html += ui.note(SOURCE_NOTE);
        return html;
    }

    function reportPreview(report) {
        if (!report.count) return report.comments ? clip(report.comments, 220) : 'No citations';
        var first = report.citations.filter(function (c) { return safeString(c.text); })[0];
        return first ? clip(first.text, 220) : clip(report.summary, 220);
    }

    function kindMatches(report, value) {
        if (value === 'cited') return report.count > 0;
        if (value === 'none') return report.count === 0;
        if (value === 'complaint') return report.isComplaint;
        return true;
    }

    // report() gets only the report; keep a back-reference for the hospital note.
    var owner = new WeakMap();

    page.mount({
        state: 'Colorado',
        emptyMessage: 'No facilities found in the database for Colorado.',

        filters: [{
            id: 'kind',
            label: 'Inspections:',
            options: [
                { value: 'ALL', label: 'All inspections' },
                { value: 'cited', label: 'With citations' },
                { value: 'none', label: 'No citations' },
                { value: 'complaint', label: 'Complaint inspections' }
            ],
            test: function (f, value) { return f.reports.some(function (r) { return kindMatches(r, value); }); },
            testReport: function (r, value) { return kindMatches(r, value); }
        }],

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=CO')
                .then(function (resp) {
                    if (!resp.ok) throw new Error('API returned ' + resp.status);
                    return resp.json();
                })
                .then(function (apiData) {
                    var facilities = convertApiDataToFacilities(apiData.facilities);
                    facilities.forEach(function (f) {
                        f.reports.forEach(function (r) { owner.set(r, f); });
                    });
                    return { facilities: facilities, scrapedTimestamp: apiData.scraped_timestamp || '' };
                });
        },

        facilityName: function (f) { return f.name || ''; },

        searchText: function (f) {
            return [f.name, f.stateId, f.category, f.address]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        countFlagged: function (f) {
            return f.reports.reduce(function (sum, r) { return sum + r.count; }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var citations = reports.reduce(function (sum, r) { return sum + r.count; }, 0);
            var clean = reports.filter(function (r) { return !r.count; }).length;
            var complaints = reports.filter(function (r) { return r.isComplaint; }).length;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'inspection'), tone: 'neutral' }];
            if (citations) stats.push({ text: ctx.plural(citations, 'citation'), tone: 'flagged' });
            if (clean) stats.push({ text: ctx.plural(clean, 'inspection') + ' with no citations', tone: 'clean' });
            if (complaints) stats.push({ text: ctx.plural(complaints, 'complaint inspection'), tone: 'neutral' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            var meta = [
                facility.category,
                facility.stateId ? 'State facility ID ' + facility.stateId : '',
                facility.phone,
                facility.status
            ];
            return { meta: meta, address: facility.address, stats: stats };
        },

        report: function (report, ctx) {
            var facility = owner.get(report);
            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || 'Date unknown',
                type: report.inspectionType || 'Inspection',
                tone: report.count ? 'flagged' : 'clean',
                badges: report.count
                    ? [{ text: ctx.plural(report.count, 'citation'), tone: 'flagged' }]
                    : [{ text: 'No citations', tone: 'clean' }],
                facts: [report.report_id ? 'Inspection ID ' + report.report_id : ''].filter(Boolean),
                link: { href: report.report_url, text: 'Health department dashboard' },
                links: [],
                preview: reportPreview(report),
                // Built up front, not on open: severe-flags.js finds a severe
                // finding by the text on the page.
                body: reportBody(report, facility, ctx)
            };
        }
    });
}());
