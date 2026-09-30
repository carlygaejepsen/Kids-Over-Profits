/**
 * Pennsylvania inspection summaries (/pa-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=PA: the Department of Human
 * Services "Licensing Inspection Summary - Public" for every licensed children's
 * residential unit (residential services, transitional living, secure
 * detention, secure care, outdoor and mobile programs; pa_scraper.py in the
 * Tools repo). One facility row per licensed unit: the state inspects and
 * cites each cottage or building on its own, and a legal entity can hold
 * dozens (George Junior Republic, KidsPeace), so the unit keeps the entity's
 * name in front ("The Summit School: 1") and the search finds every unit of
 * an entity.
 *
 * Each document is one of these, in categories.kind:
 *   citation   citations found; per citation the regulation, what it
 *              requires, the description of the violation and, once
 *              submitted, the provider's plan of correction.
 *   followup   the same summary once the plan of correction is accepted and
 *              verified on site. The state usually replaces the citation
 *              summary with this version, so for most inspections it is the
 *              only record of the citations.
 *   sanction   a letter taking action on the licence (provisional licence,
 *              revocation, refusal to renew), usually with the summaries.
 *   clean      an inspection with no citations.
 *   licence    a licence or certificate issued, no inspection findings.
 *   waiver     a waiver of a regulation granted.
 * Documents before mid-2019 are scans the scraper read with OCR; their text
 * has reading errors and, for about 2009-2012, only the regulation numbers
 * are certain (the state's table is read column by column).
 *
 * What counts as a violation (categories.counts_as_violation, set by the
 * scraper): a citation document, a licence action, and a follow-up whose
 * inspection has no citation document on the state's list. A follow-up of an
 * inspection whose citation document is listed is shown, not counted, so
 * the same citations are not counted twice.
 *
 * The state blanks names and most dates inside the letters and narratives
 * ("inspections on  of the above facility"); the gaps are the state's.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Pennsylvania reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var KIND_LABELS = {
        citation: 'Citations',
        followup: 'Plan of correction verified',
        sanction: 'Licence action',
        clean: 'No citations',
        licence: 'Licence issued',
        waiver: 'Waiver granted',
        other: 'Document'
    };

    var REDACTION_NOTE = 'The state blanks names and most dates in these summaries before publishing them; '
        + 'gaps such as \u201con\u00a0 of the above facility\u201d are in the original.';
    var OCR_NOTE = 'This is a scanned document. Its text was read by OCR and has reading errors; '
        + 'the state\u2019s PDF is the authority.';
    var TABLE_NOTE = 'In scanned summaries from before 2013 the state set findings in a table that OCR reads '
        + 'column by column, so only the regulation numbers are certain. The full text is below.';

    // "2024-03-19" -> local Date; anything else through Date.
    function parseDate(value) {
        var m = safeString(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
        var d = new Date(safeString(value));
        return isNaN(d.getTime()) ? new Date(0) : d;
    }

    function dateText(ctx, value) {
        return value ? ctx.formatDate(parseDate(value).getTime()) : '';
    }

    function convertApiDataToFacilities(apiFacilities) {
        return (apiFacilities || []).map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var citations = Array.isArray(cats.citations) ? cats.citations : [];
                return {
                    report_id:     safeString(report.report_id),
                    report_date:   safeString(report.report_date),
                    report_url:    safeString(report.report_url),
                    raw_content:   String(report.raw_content || ''),
                    has_text:      report.has_text !== undefined ? !!report.has_text : !!report.raw_content,
                    row_id:        report.row_id || null,
                    kind:          KIND_LABELS[cats.kind] ? cats.kind : 'other',
                    form:          safeString(cats.form),
                    ocr:           !!cats.ocr,
                    counted:       !!cats.counts_as_violation,
                    followupOf:    safeString(cats.followup_of),
                    citations:     citations,
                    citationCount: parseInt(cats.citation_count, 10) || citations.length,
                    repeatCount:   parseInt(cats.repeat_count, 10) || 0,
                    inspectionType: safeString(cats.inspection_type),
                    inspectionStart: safeString(cats.inspection_start),
                    inspectionEnd: safeString(cats.inspection_end),
                    notice:        safeString(cats.notice),
                    narrative:     safeString(cats.narrative),
                    fileName:      safeString(cats.file_name),
                    fileDate:      safeString(cats.file_date),
                    dateCorrected: !!cats.date_corrected,
                    legalEntity:   safeString(cats.legal_entity),
                    region:        safeString(cats.region),
                    county:        safeString(cats.county)
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            var first = reports[0] || {};
            return {
                name:        safeString(info.facility_name),
                license:     safeString(info.program_name),
                serviceType: safeString(info.program_category),
                address:     safeString(info.full_address),
                phone:       safeString(info.phone),
                capacity:    safeString(info.bed_capacity),
                status:      safeString(info.action),
                expires:     safeString(info.license_exp_date),
                legalEntity: first.legalEntity || '',
                region:      first.region || '',
                county:      first.county || '',
                reports:     reports
            };
        });
    }

    function isFlagged(report) {
        return report.counted;
    }

    function citationWord(ctx, n) {
        return ctx.plural(n, 'citation');
    }

    function reportBadges(report, ctx) {
        var badges = [];
        var n = report.citationCount;
        if (report.kind === 'sanction') {
            badges.push({ text: 'Licence action', tone: 'flagged' });
            if (n) badges.push({ text: citationWord(ctx, n), tone: 'flagged' });
        } else if (report.kind === 'citation') {
            badges.push({ text: n ? citationWord(ctx, n) : 'Citations', tone: 'flagged' });
        } else if (report.kind === 'followup') {
            if (report.counted) badges.push({ text: citationWord(ctx, n), tone: 'flagged' });
            badges.push({ text: 'Plan of correction verified', tone: 'neutral' });
        } else if (report.kind === 'clean') {
            badges.push({ text: 'No citations', tone: 'clean' });
        } else if (report.kind === 'licence') {
            badges.push({ text: 'Licence issued', tone: 'neutral' });
        } else if (report.kind === 'waiver') {
            badges.push({ text: 'Waiver granted', tone: 'neutral' });
        }
        if (report.repeatCount) {
            badges.push({ text: ctx.plural(report.repeatCount, 'repeat violation'), tone: 'repeat' });
        }
        return badges;
    }

    function planTitle(c, ctx) {
        var bits = ['Plan of correction'];
        if (c.plan_status) bits.push('(' + safeString(c.plan_status).toLowerCase() + ')');
        return bits.join(' ');
    }

    function citationFinding(c, ctx, report) {
        var chips = [];
        if (c.repeat) chips.push({ text: 'Repeat violation', tone: 'repeat' });
        if (c.verification_status) {
            chips.push({ text: 'Correction ' + safeString(c.verification_status).toLowerCase(), tone: 'neutral' });
        } else if (c.plan_status) {
            chips.push({ text: 'Plan ' + safeString(c.plan_status).toLowerCase(), tone: 'neutral' });
        }
        var plan = [];
        if (c.plan) plan.push(safeString(c.plan));
        if (c.completion_date) plan.push('Completion date: ' + dateText(ctx, c.completion_date));
        var verification = [];
        if (c.verification) verification.push(safeString(c.verification));
        if (c.verification_date) verification.push('Verified: ' + dateText(ctx, c.verification_date));
        return ui.finding({
            title: safeString(c.title) || (c.regulation ? 'Regulation ' + safeString(c.regulation) : 'Citation'),
            citation: c.regulation ? '55 Pa. Code \u00a7 ' + safeString(c.regulation) : '',
            chips: chips,
            evidence: c.violation ? [safeString(c.violation)] : [],
            requirement: c.regulation_text ? [safeString(c.regulation_text)] : [],
            more: [
                plan.length ? { title: planTitle(c, ctx), paragraphs: plan } : null,
                verification.length ? { title: 'On-site verification', paragraphs: verification } : null
            ],
            tone: report.counted ? (c.repeat ? 'repeat' : 'flagged') : 'neutral'
        });
    }

    function reportBody(report, ctx) {
        var html = '';
        if (report.narrative) html += ui.section('Inspection narrative', ui.paragraphs([report.narrative]), { open: true });
        if (report.citations.length) {
            html += ui.heading(citationWord(ctx, report.citations.length));
            html += report.citations.map(function (c) { return citationFinding(c, ctx, report); }).join('');
            if (report.form === 'table') html += ui.note(TABLE_NOTE);
        } else if (report.kind === 'citation' || report.kind === 'sanction') {
            html += ui.note('The citations in this document could not be read into a list; they are in the full text below.');
        }
        if (report.kind === 'followup' && !report.counted && report.followupOf) {
            html += ui.note('These citations are counted once, on the citation summary of the inspection of '
                + dateText(ctx, report.followupOf) + '.');
        }
        if (report.dateCorrected) {
            html += ui.note('The state filed this document under ' + dateText(ctx, report.fileDate)
                + '; the date shown is the one in the document.');
        }
        if (report.ocr) html += ui.note(OCR_NOTE);
        html += ui.note(REDACTION_NOTE);
        html += ui.section('Full document text', ui.docText(report.raw_content));
        return html;
    }

    // The row's type: the inspection ("Renewal inspection", "Complaint
    // inspection") when the summary names it; the badges say what the
    // document found.
    function typeLabel(report) {
        if (report.inspectionType && report.kind !== 'licence' && report.kind !== 'waiver') {
            return report.inspectionType + ' inspection';
        }
        return KIND_LABELS[report.kind];
    }

    function reportFacts(report, ctx) {
        var facts = [];
        var start = dateText(ctx, report.inspectionStart);
        var end = dateText(ctx, report.inspectionEnd);
        // OCR now and then misreads a date; an end before the start is dropped.
        var ordered = parseDate(report.inspectionEnd) > parseDate(report.inspectionStart);
        if (start && end && ordered) facts.push('Inspected ' + start + ' to ' + end);
        else if (start) facts.push('Inspected ' + start);
        if (report.notice) facts.push(report.notice);
        if (report.ocr) facts.push('Scanned document');
        return facts;
    }

    function preview(report) {
        var first = report.citations.filter(function (c) { return c.violation; })[0];
        var text = first ? safeString(first.violation) : '';
        return text.length > 220 ? text.slice(0, 217).replace(/\s+\S*$/, '') + '\u2026' : text;
    }

    var SERVICE_OPTIONS = [
        { value: 'ALL', label: 'All service types' },
        { value: 'residential services', label: 'Residential services' },
        { value: 'transitional living', label: 'Transitional living' },
        { value: 'secure detention', label: 'Secure detention' },
        { value: 'secure care', label: 'Secure care' },
        { value: 'outdoor program', label: 'Outdoor programs' },
        { value: 'mobile program', label: 'Mobile programs' }
    ];

    page.mount({
        state: 'Pennsylvania',
        archiveState: 'PA',
        emptyMessage: 'No facilities found in the database for Pennsylvania.',

        filters: [{
            id: 'service',
            label: 'Service type:',
            options: SERVICE_OPTIONS,
            test: function (f, value) { return f.serviceType.toLowerCase() === value; }
        }, {
            id: 'region',
            label: 'Region:',
            options: [
                { value: 'ALL', label: 'All regions' },
                { value: 'western', label: 'Western' },
                { value: 'central', label: 'Central' },
                { value: 'northeast', label: 'Northeast' },
                { value: 'southeast', label: 'Southeast' }
            ],
            test: function (f, value) { return f.region.toLowerCase() === value; }
        }, {
            id: 'kind',
            label: 'Documents:',
            options: [
                { value: 'ALL', label: 'All documents' },
                { value: 'cited', label: 'Citations and licence actions' },
                { value: 'sanction', label: 'Licence actions only' },
                { value: 'clean', label: 'Inspections with no citations' }
            ],
            test: function (f, value) { return f.reports.some(function (r) { return kindMatches(r, value); }); },
            testReport: function (r, value) { return kindMatches(r, value); }
        }],

        load: function () {
            // lite: no document text in the list; withText() fetches a
            // report's text when it is opened.
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=PA&lite=1')
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
            return [f.name, f.license, f.serviceType, f.address, f.legalEntity, f.county, f.region]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        countFlagged: function (f) {
            return f.reports.reduce(function (sum, r) {
                return sum + (r.counted ? Math.max(1, r.citationCount) : 0);
            }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var citations = reports.reduce(function (sum, r) { return sum + (r.counted ? r.citationCount : 0); }, 0);
            var inspections = reports.filter(function (r) { return r.kind !== 'licence' && r.kind !== 'other'; }).length;
            var actions = reports.filter(function (r) { return r.kind === 'sanction'; }).length;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'document'), tone: 'neutral' }];
            if (citations) stats.push({ text: citationWord(ctx, citations), tone: 'flagged' });
            else if (inspections) stats.push({ text: 'No citations', tone: 'clean' });
            if (actions) stats.push({ text: ctx.plural(actions, 'licence action'), tone: 'flagged' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            var entityShown = facility.legalEntity
                && facility.name.toLowerCase().indexOf(facility.legalEntity.toLowerCase().replace(/[\s,]+(inc|llc|corp)\.?$/i, '')) !== 0;
            return {
                meta: [
                    facility.serviceType,
                    facility.license ? 'Licence ' + facility.license : '',
                    entityShown ? 'Legal entity: ' + facility.legalEntity : '',
                    facility.capacity ? 'Capacity ' + facility.capacity : '',
                    facility.status && !/^licensed$/i.test(facility.status) ? facility.status : '',
                    facility.county ? facility.county + ' County' : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var badges = reportBadges(report, ctx);
            var tone = isFlagged(report) ? 'flagged' : (report.kind === 'clean' ? 'clean' : 'neutral');
            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || 'Date unknown',
                type: typeLabel(report),
                tone: tone,
                badges: badges,
                facts: reportFacts(report, ctx),
                link: { href: report.report_url, text: 'Report (state)' },
                links: [ctx.archiveLink(report.fileName)],
                preview: preview(report),
                body: function () { return page.withText(report, 'PA', function () { return reportBody(report, ctx); }); }
            };
        }
    });

    function kindMatches(r, value) {
        if (value === 'cited') return r.counted;
        if (value === 'sanction') return r.kind === 'sanction';
        if (value === 'clean') return r.kind === 'clean';
        return true;
    }
}());
