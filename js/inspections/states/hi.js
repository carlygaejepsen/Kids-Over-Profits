/**
 * Hawaii special treatment facility and therapeutic living program
 * inspections (/hi-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=HI (hi_scraper.py in the
 * Tools repo): the Department of Health, Office of Health Care Assurance,
 * State Licensing Section, which posts one statement of deficiencies and plan
 * of correction per licensing inspection (2023 on) on its inspection reports
 * page. Special treatment facility (STF) and therapeutic living program (TLP)
 * licences cover adult and youth programs alike; the scraper posts only the
 * youth programs on its allowlist (hi_scope.json). Each report is one
 * statement, in categories.kind:
 *
 *   deficiencies     The inspection cited at least one rule of Hawaii
 *                    Administrative Rules chapter 11-98. Per deficiency
 *                    (categories.deficiencies[]): the rule cited, the
 *                    inspector's findings, the facility's account of how it
 *                    corrected it (PART 1), its plan to keep it from happening
 *                    again (PART 2) and the completion dates it gave. Most of
 *                    these statements are scans of the signed form, read by
 *                    software (categories.ocr); a plan written by hand is not
 *                    transcribed and says so.
 *   no_deficiencies  The statement's table reads "NO DEFICIENCIES".
 *   unread           A statement whose table the scraper could not read; the
 *                    state's document is the record. Neutral, never clean.
 *
 * Nothing else is posted: the scraper leaves out any document that is not a
 * statement of deficiencies, and holds back any whose text carries a date of
 * birth, a named young person or a record number.
 *
 * What counts as a violation: a statement with deficiencies. Every cited rule
 * is a finding of non-compliance, whatever the plan of correction says
 * afterwards, so the statement is flagged. The state marks inspections as
 * annual or initial; it does not say which (if any) followed a complaint, so
 * none is labelled as one.
 *
 * The page loads in full (a few dozen reports). The archived copy on Drive
 * (archiveState 'HI') is linked beside the state's document.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Hawaii reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var STATEMENT_NOTE = 'The rules, findings and plans above are read from the state’s form; the state’s document is the record. '
        + 'The correction and the future plan are the facility’s own answers.';
    var OCR_NOTE = 'This statement is a scan. Its text was read by software and may contain reading errors.';
    var HANDWRITTEN_NOTE = 'Part of the facility’s plan is handwritten and was not transcribed; open the document to read it.';
    var NONE_NOTE = 'The inspection cited no deficiencies.';
    var UNREAD_NOTE = 'The deficiencies in this statement could not be read automatically. Open the document to read it.';
    var HANDWRITTEN = /^\(handwritten;/;

    // "2024-03-19" -> local Date; anything else through Date; '' -> epoch 0.
    function parseDate(value) {
        var m = safeString(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
        var d = new Date(safeString(value));
        return isNaN(d.getTime()) ? new Date(0) : d;
    }

    function formatIso(ctx, value) {
        var ms = parseDate(value).getTime();
        return ms > 0 ? ctx.formatDate(ms) : '';
    }

    function clip(text, max) {
        text = safeString(text).replace(/\s+/g, ' ');
        return text.length > max ? text.slice(0, max - 1).replace(/\s+\S*$/, '') + '…' : text;
    }

    function convertApiDataToFacilities(apiFacilities) {
        return (apiFacilities || []).map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var deficiencies = Array.isArray(cats.deficiencies) ? cats.deficiencies : [];
                var kind = cats.kind === 'deficiencies' || cats.kind === 'no_deficiencies' ? cats.kind : 'unread';
                return {
                    report_id:      safeString(report.report_id),
                    report_date:    safeString(report.report_date),
                    report_url:     safeString(report.report_url),
                    summary:        safeString(report.summary),
                    kind:           kind,
                    inspectionType: safeString(cats.inspection_type),
                    archiveName:    safeString(cats.archive_name),
                    ocr:            !!cats.ocr,
                    deficiencies:   deficiencies,
                    count:          parseInt(cats.deficiency_count, 10) || deficiencies.length,
                    handwritten:    deficiencies.some(function (d) {
                        return HANDWRITTEN.test(safeString(d.correction)) || HANDWRITTEN.test(safeString(d.future_plan));
                    })
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            return {
                name:     safeString(info.facility_name),
                licence:  /^\d+-(STF|TLP)$/.test(safeString(info.program_name)) ? safeString(info.program_name) : '',
                category: safeString(info.program_category),
                address:  safeString(info.full_address),
                phone:    safeString(info.phone),
                expires:  safeString(info.license_exp_date),
                status:   safeString(info.action),
                reports:  reports
            };
        });
    }

    function isFlagged(report) {
        return report.kind === 'deficiencies';
    }

    function reportTone(report) {
        if (report.kind === 'deficiencies') return 'flagged';
        return report.kind === 'no_deficiencies' ? 'clean' : 'neutral';
    }

    function planParagraphs(text) {
        return safeString(text).split('\n').filter(function (line) { return line.trim(); });
    }

    function deficiencyFinding(rule, ctx) {
        var chips = [];
        var dates = (Array.isArray(rule.completion_dates) ? rule.completion_dates : [])
            .map(function (d) { return formatIso(ctx, d); })
            .filter(Boolean);
        if (dates.length) chips.push({ text: 'Completion ' + dates.join(', '), tone: 'neutral' });
        var more = [];
        if (rule.only_future_plan) {
            more.push({ title: 'Correcting the deficiency', paragraphs: ['The state asked only for a future plan: correcting it after the fact was not practical.'] });
        } else if (rule.correction) {
            more.push({ title: 'How the facility says it corrected it', paragraphs: planParagraphs(rule.correction) });
        }
        if (rule.future_plan) {
            more.push({ title: 'The facility’s plan to keep it from happening again', paragraphs: planParagraphs(rule.future_plan) });
        }
        var title = safeString(rule.heading).replace(/[\s.;:,_-]+$/, '') || 'Rule cited';
        return ui.finding({
            title: title,
            citation: safeString(rule.rule),
            chips: chips,
            evidence: planParagraphs(rule.finding),
            requirement: rule.rule_text ? [safeString(rule.rule_text).replace(/\s*\n\s*/g, ' ')] : [],
            more: more,
            tone: 'flagged'
        });
    }

    function reportBody(report, ctx) {
        var html = '';
        if (report.kind === 'deficiencies') {
            html += ui.heading(ctx.plural(report.deficiencies.length, 'deficiency', 'deficiencies') + ' cited');
            html += report.deficiencies.map(function (rule) { return deficiencyFinding(rule, ctx); }).join('');
            html += ui.note(STATEMENT_NOTE);
            if (report.handwritten) html += ui.note(HANDWRITTEN_NOTE);
            if (report.ocr) html += ui.note(OCR_NOTE);
        } else if (report.kind === 'no_deficiencies') {
            html += ui.note(NONE_NOTE);
        } else {
            html += ui.note(UNREAD_NOTE);
        }
        return html;
    }

    function reportBadges(report, ctx) {
        if (report.kind === 'no_deficiencies') return [{ text: 'No deficiencies', tone: 'clean' }];
        if (report.kind === 'unread') return [{ text: 'Not read', tone: 'neutral' }];
        var badges = [{ text: ctx.plural(report.count, 'deficiency', 'deficiencies'), tone: 'flagged' }];
        if (report.ocr) badges.push({ text: 'Scanned', tone: 'neutral' });
        return badges;
    }

    function reportPreview(report) {
        if (report.kind === 'no_deficiencies') return 'No deficiencies cited';
        if (report.kind === 'unread') return 'Open the document to read it';
        var first = report.deficiencies.filter(function (d) { return d.finding; })[0];
        return first ? clip(first.finding, 220) : '';
    }

    function kindMatches(report, value) {
        if (value === 'deficiencies') return report.kind === 'deficiencies';
        if (value === 'none') return report.kind === 'no_deficiencies';
        return true;
    }

    page.mount({
        state: 'Hawaii',
        archiveState: 'HI',
        emptyMessage: 'No facilities found in the database for Hawaii.',

        filters: [{
            id: 'kind',
            label: 'Statements:',
            options: [
                { value: 'ALL', label: 'All statements' },
                { value: 'deficiencies', label: 'With deficiencies' },
                { value: 'none', label: 'No deficiencies' }
            ],
            test: function (f, value) { return f.reports.some(function (r) { return kindMatches(r, value); }); },
            testReport: function (r, value) { return kindMatches(r, value); }
        }],

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=HI')
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
            return [f.name, f.licence, f.category, f.address]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        countFlagged: function (f) {
            return f.reports.reduce(function (sum, r) { return sum + (isFlagged(r) ? r.count : 0); }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var statements = reports.filter(isFlagged);
            var clean = reports.filter(function (r) { return r.kind === 'no_deficiencies'; }).length;
            var deficiencies = statements.reduce(function (sum, r) { return sum + r.count; }, 0);
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'inspection'), tone: 'neutral' }];
            if (deficiencies) stats.push({ text: ctx.plural(deficiencies, 'deficiency', 'deficiencies'), tone: 'flagged' });
            if (clean) stats.push({ text: ctx.plural(clean, 'inspection') + ' with no deficiencies', tone: 'clean' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            var expires = formatIso(ctx, facility.expires);
            var meta = [
                facility.category,
                facility.licence ? 'License ' + facility.licence : '',
                expires ? 'License expires ' + expires : '',
                facility.phone,
                facility.status
            ];
            return { meta: meta, address: facility.address, stats: stats };
        },

        report: function (report, ctx) {
            var type = (report.inspectionType ? report.inspectionType + ' inspection' : 'Inspection');
            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || 'Date unknown',
                type: type,
                tone: reportTone(report),
                badges: reportBadges(report, ctx),
                facts: [],
                link: { href: report.report_url, text: 'State document' },
                links: [ctx.archiveLink(report.archiveName)],
                preview: reportPreview(report),
                // Built up front, not on open: severe-flags.js finds a severe
                // finding by the text on the page.
                body: reportBody(report, ctx)
            };
        }
    });
}());
