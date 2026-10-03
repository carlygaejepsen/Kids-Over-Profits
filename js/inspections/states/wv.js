/**
 * West Virginia survey reports (/wv-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=WV: surveys by the Office of
 * Health Facility Licensure and Certification (OHFLAC) of psychiatric
 * residential treatment facilities and of the behavioral health licences held
 * by youth providers (wv_scraper.py in the Tools repo; the facilities are an
 * allowlist, wv_scope.json there). Each report is one statement of
 * deficiencies, on the state's form or the federal CMS-2567: the surveyors'
 * opening comments, then one "tag" per rule not met, with the finding and the
 * provider's plan of correction.
 *
 * OHFLAC licenses only the health side of a provider. The group home licence
 * itself is held by the Bureau for Social Services, which publishes no
 * reports, so a group home shows here only through its operator's behavioral
 * health licence, often as one agency-wide record.
 *
 * The scraper reads the tags from the PDF into categories (short form in
 * categories.tags; the full regulation, finding and plan in categories.detail),
 * so the list loads without the text (?lite=1) and one report's text and
 * detail are fetched when it opens.
 *
 * What counts as a violation: a survey with at least one deficiency tag other
 * than the opening comments (categories.tag_count > 0). A revisit that only
 * lists earlier tags as corrected is not one, and a survey whose comments say
 * no deficiencies were cited is shown as clean. Complaint surveys are marked
 * and can be listed on their own; a complaint survey with no tag found
 * nothing against the provider.
 *
 * The state's report link is generated per request, so the link to the state
 * is the facility's page on its lookup and the archived copy on this site is
 * the report.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('West Virginia reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var LICENCE_NOTE = 'This is a survey of the provider’s health licence by the Office of Health Facility '
        + 'Licensure and Certification. A group home’s own licence is held by the Bureau for Social Services, '
        + 'which publishes no reports.';

    // "2024-03-19" -> local Date; anything else through Date.
    function parseDate(value) {
        var m = safeString(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
        var d = new Date(safeString(value));
        return isNaN(d.getTime()) ? new Date(0) : d;
    }

    function lines(value) {
        return safeString(value).split(/\n+/).map(safeString).filter(Boolean);
    }

    function facilityKind(category) {
        var c = safeString(category).toLowerCase();
        if (c.indexOf('psychiatric') !== -1) return 'prtf';
        if (c.indexOf('community') !== -1) return 'community';
        return 'residential';
    }

    var KIND_LABELS = {
        prtf: 'Psychiatric residential treatment facility',
        residential: 'Youth provider (behavioral health licence)',
        community: 'Youth community services agency (behavioral health licence)'
    };

    function isClosed(status) {
        return /^closed/i.test(safeString(status));
    }

    // "Closed - Owner" -> "Closed by the owner"
    function statusLabel(status) {
        var s = safeString(status);
        if (/^closed\s*-\s*owner/i.test(s)) return 'Closed by the owner';
        if (/^closed\s*-\s*state/i.test(s)) return 'Closed by the state';
        if (/^closed\s*-\s*moved/i.test(s)) return 'Closed (moved)';
        if (/^closed/i.test(s)) return 'Closed';
        if (/^pending/i.test(s)) return 'Licence pending';
        return s;
    }

    function convertApiDataToFacilities(apiFacilities) {
        return (apiFacilities || []).map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var tags = Array.isArray(cats.tags) ? cats.tags : [];
                return {
                    report_id:    safeString(report.report_id),
                    report_date:  safeString(report.report_date),
                    report_url:   safeString(report.report_url),
                    raw_content:  String(report.raw_content || ''),
                    has_text:     report.has_text !== undefined ? !!report.has_text : !!report.raw_content,
                    row_id:       report.row_id || null,
                    survey_type:  safeString(cats.survey_type) || 'Survey',
                    form:         safeString(cats.form),
                    is_complaint: !!cats.is_complaint,
                    event_id:     safeString(cats.event_id),
                    legal_name:   safeString(cats.legal_name),
                    tags:         tags,
                    tag_count:    parseInt(cats.tag_count, 10) || 0,
                    corrected:    parseInt(cats.corrected_count, 10) || 0,
                    outcome:      safeString(cats.outcome),
                    comments:     safeString(cats.comments),
                    archive_name: safeString(cats.archive_name),
                    detail:       cats.detail || null
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            var legal = '';
            reports.some(function (r) { legal = r.legal_name; return !!legal; });

            return {
                name:     safeString(info.facility_name),
                id:       safeString(info.program_name),
                category: safeString(info.program_category),
                kind:     facilityKind(info.program_category),
                address:  safeString(info.full_address),
                phone:    safeString(info.phone),
                capacity: safeString(info.bed_capacity),
                status:   safeString(info.action),
                legal:    legal,
                reports:  reports
            };
        });
    }

    function isFlagged(report) {
        return report.tag_count > 0;
    }

    function reportBadges(report, ctx) {
        var badges = [];
        if (report.is_complaint) badges.push({ text: 'Complaint survey', tone: 'neutral' });
        if (report.tag_count) {
            badges.push({ text: ctx.plural(report.tag_count, 'deficiency', 'deficiencies') + ' cited', tone: 'flagged' });
        } else if (report.corrected) {
            badges.push({ text: 'Earlier deficiencies corrected', tone: 'clean' });
        } else {
            badges.push({ text: 'No deficiencies cited', tone: 'clean' });
        }
        return badges;
    }

    // A tag with its detail (same order) laid over the list's short form.
    function fullTags(report) {
        var more = (report.detail && Array.isArray(report.detail.tags)) ? report.detail.tags : [];
        return report.tags.map(function (t, i) {
            var full = more[i] || {};
            var regulation = lines(full.regulation);
            var title = safeString(t.regulation);
            if (regulation.length && regulation[0] === title) regulation = regulation.slice(1);
            return {
                tag: safeString(t.tag),
                title: title,
                scope: safeString(t.scope),
                corrected: !!t.corrected,
                completion_date: safeString(t.completion_date),
                regulation: regulation,
                finding: lines(full.finding || t.finding),
                plan: lines(full.plan)
            };
        });
    }

    function tagFinding(t) {
        var chips = [];
        if (t.corrected) chips.push({ text: 'Shown as corrected', tone: 'clean' });
        if (t.scope) chips.push({ text: t.scope, tone: 'neutral' });
        var planTitle = 'Provider’s plan of correction'
            + (t.completion_date ? ' (completion date ' + t.completion_date + ')' : '');
        return ui.finding({
            title: t.title || 'Tag ' + t.tag,
            citation: t.tag,
            chips: chips,
            evidence: t.finding,
            requirement: t.regulation,
            more: [t.plan.length ? { title: planTitle, paragraphs: t.plan } : null],
            tone: t.corrected ? 'clean' : 'flagged'
        });
    }

    function reportBody(report, ctx, facility) {
        var html = '';
        var comments = report.detail ? safeString(report.detail.comments) : report.comments;
        if (comments) html += ui.section('Surveyors’ opening comments', ui.paragraphs(lines(comments)), { open: true });
        var tags = fullTags(report);
        var cited = tags.filter(function (t) { return !t.corrected; });
        var corrected = tags.filter(function (t) { return t.corrected; });
        if (cited.length) {
            html += ui.heading(ctx.plural(cited.length, 'deficiency', 'deficiencies') + ' cited');
            html += cited.map(tagFinding).join('');
        }
        if (corrected.length) {
            html += ui.heading(ctx.plural(corrected.length, 'earlier deficiency', 'earlier deficiencies') + ' shown as corrected');
            html += corrected.map(tagFinding).join('');
        }
        if (!tags.length && !comments) {
            html += ui.note('This form lists no deficiencies.');
        }
        if (facility && facility.kind !== 'prtf') html += ui.note(LICENCE_NOTE);
        html += ui.section('Full report text', ui.docText(report.raw_content));
        return html;
    }

    function preview(report) {
        var first = report.tags.filter(function (t) { return !t.corrected && t.finding; })[0];
        var text = first ? safeString(first.finding) : report.comments;
        text = text.replace(/\s+/g, ' ');
        return text.length > 220 ? text.slice(0, 217).replace(/\s+\S*$/, '') + '…' : text;
    }

    function surveyGroup(report) {
        return report.is_complaint ? 'complaint' : 'routine';
    }

    // report() gets no facility, so each report remembers its own.
    function link(facilities) {
        facilities.forEach(function (f) {
            f.reports.forEach(function (r) { r.facility = f; });
        });
        return facilities;
    }

    page.mount({
        state: 'West Virginia',
        archiveState: 'WV',
        emptyMessage: 'No facilities found in the database for West Virginia.',

        filters: [{
            id: 'survey',
            label: 'Survey type:',
            options: [
                { value: 'ALL', label: 'All surveys' },
                { value: 'complaint', label: 'Complaint surveys' },
                { value: 'routine', label: 'Licensure and certification surveys' }
            ],
            test: function (f, value) {
                return f.reports.some(function (r) { return surveyGroup(r) === value; });
            },
            testReport: function (r, value) { return surveyGroup(r) === value; }
        }, {
            id: 'kind',
            label: 'Facility type:',
            options: [
                { value: 'ALL', label: 'All facility types' },
                { value: 'prtf', label: 'Psychiatric residential treatment facilities' },
                { value: 'residential', label: 'Youth providers' },
                { value: 'community', label: 'Community services agencies' }
            ],
            test: function (f, value) { return f.kind === value; }
        }, {
            id: 'status',
            label: 'Status:',
            options: [
                { value: 'ALL', label: 'Open and closed' },
                { value: 'open', label: 'Open' },
                { value: 'closed', label: 'Closed' }
            ],
            test: function (f, value) { return (isClosed(f.status) ? 'closed' : 'open') === value; }
        }],

        load: function () {
            // lite: no document text in the list; withText() fetches a
            // report's text and detail when it is opened.
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=WV&lite=1')
                .then(function (resp) {
                    if (!resp.ok) throw new Error('API returned ' + resp.status);
                    return resp.json();
                })
                .then(function (apiData) {
                    return {
                        facilities: link(convertApiDataToFacilities(apiData.facilities)),
                        scrapedTimestamp: apiData.scraped_timestamp || ''
                    };
                });
        },

        facilityName: function (f) { return f.name || ''; },

        searchText: function (f) {
            return [f.name, f.legal, f.id, f.category, f.address]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        countFlagged: function (f) {
            return f.reports.reduce(function (sum, r) { return sum + r.tag_count; }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var complaints = reports.filter(function (r) { return r.is_complaint; }).length;
            var cited = reports.filter(isFlagged).length;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'survey'), tone: 'neutral' }];
            if (complaints) stats.push({ text: ctx.plural(complaints, 'complaint survey'), tone: 'neutral' });
            if (cited) stats.push({ text: ctx.plural(cited, 'survey') + ' with deficiencies', tone: 'flagged' });
            else stats.push({ text: 'No deficiencies cited', tone: 'clean' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            var sameName = facility.legal.toLowerCase().replace(/[^a-z0-9]/g, '')
                .indexOf(facility.name.toLowerCase().replace(/[^a-z0-9]/g, '')) === 0
                && facility.legal.length - facility.name.length < 25;

            return {
                meta: [
                    KIND_LABELS[facility.kind],
                    statusLabel(facility.status),
                    facility.legal && !sameName ? 'Licence holder: ' + page.displayName(facility.legal) : '',
                    facility.capacity ? 'Licensed beds: ' + facility.capacity : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var facts = [];
            if (report.form === 'Federal') facts.push('Federal form (CMS-2567)');
            else if (report.form) facts.push('State form');
            if (report.event_id) facts.push('Event ' + report.event_id);
            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || 'Date unknown',
                type: report.survey_type,
                tone: isFlagged(report) ? 'flagged' : 'clean',
                badges: reportBadges(report, ctx),
                facts: facts,
                link: { href: report.report_url, text: 'State facility page' },
                links: [ctx.archiveLink(report.archive_name, 'Report (archived copy)')],
                preview: preview(report),
                body: function () {
                    return page.withText(report, 'WV', function () { return reportBody(report, ctx, report.facility); });
                }
            };
        }
    });
}());
