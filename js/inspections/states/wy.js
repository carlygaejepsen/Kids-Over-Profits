/**
 * Wyoming youth residential provider findings (/wy-reports/) -- adapter for
 * report-page.js.
 *
 * Data comes from api/inspections-read.php?state=WY (wy_scraper.py in the Tools
 * repo), from two agencies, told apart by categories.source:
 *
 *   DFS  The Department of Family Services page of notices of non-compliance
 *        and facility visits for the certified residential treatment centers,
 *        group homes, crisis centers, juvenile detention center and BOCES
 *        residential schools. Every document is a scan.
 *          kind 'notice'  Notice of Non-Compliance (form SCL-305), typed. The
 *                         outcome of an allegation investigation: the allegation,
 *                         the finding ("Evidence supports findings of
 *                         non-compliance") and the rules violated, read from the
 *                         scan by OCR into categories (allegation, finding,
 *                         non_compliance, rules[{ chapter, section, title }]).
 *          kind 'visit'   Facility Visit (form SCL-300), HANDWRITTEN. Never
 *                         transcribed: the scraper posts the date, the state's
 *                         label and the link, and no text.
 *          kind 'other'   Anything else the page links (corrective action plan
 *                         responses, recertifications), with the state's label.
 *   WDH  The Department of Health's federal CMS-2567 surveys of psychiatric
 *        residential treatment facilities (kind 'survey'): tags with the
 *        regulation, the surveyor's findings and the provider's plan of
 *        correction, read by column. A scanned form whose columns could not be
 *        told apart carries its plan as one block (plan_text) instead of per tag.
 *
 * What counts as a violation:
 *   - a notice whose finding says the evidence supports non-compliance (a notice
 *     whose evidence did not support the allegation is shown as clean, not
 *     hidden: the allegation is public record);
 *   - a health survey that cites at least one deficiency tag.
 * Visits are NEVER flagged and never shown as clean: the form is handwritten, so
 * this page cannot tell whether a visit found violations. They are neutral
 * documents; open the form to read it. A notice or survey whose scan could not
 * be read is neutral for the same reason.
 *
 * The state removes a provider's documents when the provider leaves its list,
 * so the archived copy on this site (archiveState 'WY') is the reader's link as
 * much as the state's. The page loads in full (the text is a few hundred KB).
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Wyoming reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var VISIT_NOTE = 'Facility visits are recorded on a handwritten form that cannot be read by machine. '
        + 'This page shows the date and the document only; open the form to read it. '
        + 'A visit listed here is not a finding of violations, and not a finding that there were none.';
    var NOTICE_NOTE = 'A notice of non-compliance is the state’s answer to an allegation it investigated. '
        + 'The text below was read from a scan and may contain recognition errors; the document is the record.';
    var SURVEY_NOTE = 'Federal survey of a psychiatric residential treatment facility (form CMS-2567), with the provider’s plan of correction.';

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

    function convertApiDataToFacilities(apiFacilities) {
        return (apiFacilities || []).map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var kind = { notice: 1, visit: 1, survey: 1 }[cats.kind] ? cats.kind : 'other';
                return {
                    report_id:       safeString(report.report_id),
                    report_date:     safeString(report.report_date),
                    report_url:      safeString(report.report_url),
                    summary:         safeString(report.summary),
                    raw_content:     safeString(report.raw_content),
                    kind:            kind,
                    source:          safeString(cats.source),
                    label:           safeString(cats.label),
                    archive_name:    safeString(cats.archive_name),
                    // notices
                    allegation:      safeString(cats.allegation),
                    allegation_date: safeString(cats.allegation_date),
                    received_date:   safeString(cats.received_date),
                    finding:         safeString(cats.finding),
                    nonCompliance:   !!cats.non_compliance,
                    rules:           Array.isArray(cats.rules) ? cats.rules : [],
                    // health surveys
                    surveyType:      safeString(cats.survey_type),
                    isRevisit:       !!cats.is_revisit,
                    outcome:         safeString(cats.outcome),
                    ocr:             !!cats.ocr,
                    ocr:             !!cats.ocr,
                    initialComments: safeString(cats.initial_comments),
                    intakes:         Array.isArray(cats.complaint_intakes) ? cats.complaint_intakes : [],
                    planText:        safeString(cats.plan_text),
                    tags:            Array.isArray(cats.tags) ? cats.tags : []
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            return {
                name:     safeString(info.facility_name),
                program:  safeString(info.program_name),
                category: safeString(info.program_category),
                address:  safeString(info.full_address),
                phone:    safeString(info.phone),
                status:   safeString(info.action),
                reports:  reports
            };
        });
    }

    function isFlagged(report) {
        if (report.kind === 'notice') return report.nonCompliance;
        if (report.kind === 'survey') return report.tags.length > 0 || report.outcome === 'cited';
        return false;
    }

    // A notice whose scan gave no finding, or a survey whose scan gave neither
    // tags nor a statement: not clean, just unread.
    function isUnread(report) {
        if (report.kind === 'notice') return !report.finding;
        if (report.kind === 'survey') return report.outcome === 'unread';
        return false;
    }

    function reportTone(report) {
        if (isFlagged(report)) return 'flagged';
        if (report.kind === 'notice' && !isUnread(report)) return 'clean';
        if (report.kind === 'survey' && report.outcome === 'clean') return 'clean';
        return 'neutral';
    }

    function typeLabel(report) {
        if (report.kind === 'notice') return 'Notice of non-compliance';
        if (report.kind === 'visit') return 'Facility visit';
        if (report.kind === 'survey') return (report.surveyType || 'Survey') + (report.isRevisit ? ' (revisit)' : '');
        return report.label || 'Document';
    }

    // The state's category wording for the filter.
    function providerKind(category) {
        var c = safeString(category).toLowerCase();
        if (/psychiatric/.test(c)) return 'prtf';
        if (/boces/.test(c)) return 'boces';
        if (/detention/.test(c)) return 'detention';
        if (/crisis/.test(c)) return 'crisis';
        if (/group home/.test(c)) return 'group';
        if (/residential treatment/.test(c)) return 'rtc';
        return 'other';
    }

    function ruleTitle(rule) {
        var text = safeString(rule.title);
        return text || ('Section ' + safeString(rule.section));
    }

    function ruleCitation(rule) {
        var parts = [];
        if (rule.chapter) parts.push('Chapter\u00A0' + safeString(rule.chapter));
        if (rule.section) parts.push('Section\u00A0' + safeString(rule.section));
        return parts.join(', ');
    }

    function noticeBody(report, ctx) {
        var html = '';
        if (report.allegation) html += ui.heading('The allegation') + ui.paragraphs([report.allegation]);
        if (report.finding) {
            html += ui.heading('The finding') + ui.paragraphs([report.finding]);
        } else {
            html += ui.note('The finding could not be read from the scan. Open the document.');
        }
        if (report.rules.length) {
            html += ui.heading(ctx.plural(report.rules.length, 'rule') + ' cited');
            html += report.rules.map(function (rule) {
                return ui.finding({
                    title: ruleTitle(rule),
                    citation: ruleCitation(rule),
                    tone: report.nonCompliance ? 'flagged' : 'neutral'
                });
            }).join('');
        }
        html += ui.note(NOTICE_NOTE);
        if (report.raw_content) html += ui.section('Text of the notice (read from the scan)', ui.docText(report.raw_content));
        return html;
    }

    function surveyBody(report, ctx) {
        var html = '';
        if (report.initialComments) html += ui.section('Initial comments', ui.paragraphs(report.initialComments.split('\n')), { open: true });
        if (report.tags.length) {
            html += ui.heading(ctx.plural(report.tags.length, 'deficiency', 'deficiencies') + ' cited');
            html += report.tags.map(function (tag) {
                var more = [];
                if (tag.plan) more.push({ title: 'Provider’s plan of correction', paragraphs: safeString(tag.plan).split('\n') });
                var chips = [];
                if (tag.completion) chips.push({ text: 'Correction date ' + formatIso(ctx, tag.completion), tone: 'neutral' });
                return ui.finding({
                    title: safeString(tag.title) || ('Tag ' + safeString(tag.tag)),
                    citation: safeString(tag.tag) + (tag.cfr ? ' · ' + safeString(tag.cfr) : ''),
                    chips: chips,
                    evidence: safeString(tag.evidence).split('\n'),
                    requirement: tag.regulation ? [tag.regulation] : [],
                    more: more,
                    tone: 'flagged'
                });
            }).join('');
        } else if (report.outcome === 'clean') {
            html += ui.note('The survey cited no deficiencies.');
        } else {
            html += ui.note('The tags could not be read from this scan. Open the document, or read the text below.');
        }
        if (report.planText) html += ui.section('Provider’s plan of correction', ui.paragraphs(report.planText.split('\n')));
        html += ui.note(SURVEY_NOTE + (report.ocr ? ' This one is a scan; the text was read by machine and may contain recognition errors.' : ''));
        if (!report.tags.length && report.raw_content) html += ui.section('Text of the survey (read from the scan)', ui.docText(report.raw_content));
        return html;
    }

    function reportBody(report, ctx) {
        if (report.kind === 'notice') return noticeBody(report, ctx);
        if (report.kind === 'survey') return surveyBody(report, ctx);
        if (report.kind === 'visit') return ui.note(VISIT_NOTE);
        var html = ui.note('The state lists this document with the title “' + (report.label || 'no title') + '”.');
        if (report.raw_content) html += ui.section('Text of the document (read from the scan)', ui.docText(report.raw_content));
        return html;
    }

    function reportBadges(report, ctx) {
        if (report.kind === 'notice') {
            if (report.nonCompliance) return [{ text: 'Evidence supports non-compliance', tone: 'flagged' }];
            if (isUnread(report)) return [{ text: 'Finding not read from the scan', tone: 'neutral' }];
            return [{ text: 'Evidence did not support non-compliance', tone: 'clean' }];
        }
        if (report.kind === 'visit') return [{ text: 'Handwritten form, not transcribed', tone: 'neutral' }];
        if (report.kind === 'survey') {
            if (report.tags.length) return [{ text: ctx.plural(report.tags.length, 'deficiency', 'deficiencies') + ' cited', tone: 'flagged' }];
            if (report.outcome === 'cited') return [{ text: 'Deficiencies cited', tone: 'flagged' }];
            if (report.outcome === 'clean') return [{ text: 'No deficiencies cited', tone: 'clean' }];
            return [{ text: 'Findings not read from the scan', tone: 'neutral' }];
        }
        return [];
    }

    function reportPreview(report) {
        if (report.kind === 'notice') return report.allegation;
        if (report.kind === 'survey') {
            if (report.tags.length) return report.tags.map(function (t) { return t.title; }).filter(Boolean).slice(0, 3).join('; ');
            return '';
        }
        if (report.kind === 'visit') return 'Handwritten form; open the document to read it';
        return report.label;
    }

    function reportFacts(report, ctx) {
        var facts = [];
        if (report.kind === 'notice') {
            if (report.received_date) facts.push('Allegation received ' + formatIso(ctx, report.received_date));
            if (report.allegation_date) facts.push('Date of allegation ' + formatIso(ctx, report.allegation_date));
            if (report.label) facts.push('State label: ' + report.label);
        } else if (report.kind === 'survey') {
            if (report.intakes.length) facts.push('Complaint intake ' + report.intakes.join(', '));
        } else if (report.label) {
            facts.push('State label: ' + report.label);
        }
        return facts;
    }

    page.mount({
        state: 'Wyoming',
        archiveState: 'WY',
        emptyMessage: 'No facilities found in the database for Wyoming.',

        filters: [{
            id: 'kind',
            label: 'Report type:',
            options: [
                { value: 'ALL', label: 'All documents' },
                { value: 'notice', label: 'Notices of non-compliance' },
                { value: 'visit', label: 'Facility visits (handwritten)' },
                { value: 'survey', label: 'Health department surveys' },
                { value: 'other', label: 'Other documents' }
            ],
            test: function (f, value) {
                return f.reports.some(function (r) { return r.kind === value; });
            },
            testReport: function (r, value) { return r.kind === value; }
        }, {
            id: 'provider',
            label: 'Provider type:',
            options: [
                { value: 'ALL', label: 'All provider types' },
                { value: 'rtc', label: 'Residential treatment centers' },
                { value: 'prtf', label: 'Psychiatric residential treatment' },
                { value: 'group', label: 'Group homes' },
                { value: 'crisis', label: 'Crisis centers' },
                { value: 'detention', label: 'Juvenile detention' },
                { value: 'boces', label: 'BOCES residential schools' }
            ],
            test: function (f, value) { return providerKind(f.category) === value; }
        }],

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=WY')
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
            return [f.name, f.program, f.category, f.address]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var count = function (kind) { return reports.filter(function (r) { return r.kind === kind; }).length; };
            var notices = count('notice');
            var supported = reports.filter(function (r) { return r.kind === 'notice' && r.nonCompliance; }).length;
            var surveys = count('survey');
            var cited = reports.filter(function (r) { return r.kind === 'survey' && isFlagged(r); }).length;
            var visits = count('visit');
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [];
            if (notices) {
                stats.push({ text: ctx.plural(notices, 'notice') + ' of non-compliance', tone: 'neutral' });
                if (supported) stats.push({ text: supported + ' with evidence supporting non-compliance', tone: 'flagged' });
            }
            if (surveys) {
                stats.push({ text: ctx.plural(surveys, 'health survey'), tone: 'neutral' });
                if (cited) stats.push({ text: ctx.plural(cited, 'survey') + ' citing deficiencies', tone: 'flagged' });
            }
            if (visits) stats.push({ text: ctx.plural(visits, 'facility visit') + ' (handwritten)', tone: 'neutral' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [
                    safeString(facility.category),
                    facility.phone,
                    // The state's own note ("Currently not accepting placements...",
                    // "No longer on the state's list"), not the default line.
                    /^Certified provider/.test(facility.status) ? '' : facility.status
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || 'Date not readable from the state’s label',
                type: typeLabel(report),
                tone: reportTone(report),
                badges: reportBadges(report, ctx),
                facts: reportFacts(report, ctx),
                link: {
                    href: report.report_url,
                    text: report.source === 'WDH' ? 'State survey (Department of Health)' : 'State document (Google Drive)'
                },
                links: [ctx.archiveLink(report.archive_name, 'Archived copy')],
                preview: reportPreview(report),
                // Built up front, not on open: severe-flags.js finds a severe
                // finding by the text on the page.
                body: reportBody(report, ctx)
            };
        }
    });
}());
