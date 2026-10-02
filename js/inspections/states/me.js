/**
 * Maine behavioral health licensing surveys (/me-reports/) -- adapter for
 * report-page.js.
 *
 * Data comes from api/inspections-read.php?state=ME (me_scraper.py in the Tools
 * repo): the surveys the Division of Licensing and Certification (DHHS) shows
 * for the behavioral health organization licences of youth providers in the
 * state's licence lookup. The licence belongs to an organization, not a site,
 * so a record here is an operator (name = the organization, program_name = its
 * licence number) and a survey names a site only inside its document
 * (categories.site). Which operators are listed is an allowlist the owner
 * settled (me_scope.json in the Tools repo). Within a listed operator every
 * survey is posted, including those of its adult and outpatient programs.
 *
 * Each report is one inspection row: date, type (desk review, full agency
 * survey, survey waived) and the state's outcome ("No deficiencies" or
 * "Accepted plan of correction"). Since late 2024 the state also publishes the
 * documents: the statement of deficiencies (complaint surveys included, named
 * in the document and marked categories.is_complaint), the no-deficiency
 * statement and the plan of correction. The scraper reads the statement's
 * table into categories.deficiencies (rule, finding, plan, completion date),
 * so the list loads without the text (?lite=1) and nothing here needs it.
 * Older surveys are their date, type and outcome only.
 *
 * What counts as a violation: an outcome of "Accepted plan of correction"
 * (the state accepts a plan only for a survey that cited rules), or a parsed
 * deficiency. A cited rule is a finding of non-compliance whatever the plan
 * says afterwards. "No deficiencies" is clean, including a complaint survey
 * that found nothing. A survey the state waived is neutral: nobody inspected.
 * Where the outcome says rules were cited but the statement is not online (all
 * before late 2024, some since), the row says so rather than guessing which.
 *
 * Adult programs: where a document is plainly about an adult program
 * (categories.adult_program, set by the scraper only when the text names
 * adults and never children or youth) the report is hidden until "Include
 * adult programs" is chosen. It is never dropped. A survey with no document
 * cannot be told apart and is shown.
 *
 * The state's licence lookup has no link to a licence or a document (its
 * addresses expire), so the official link is the lookup itself and the
 * archived copies on this site (archiveState 'ME') are the documents.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Maine reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var LICENCE_NOTE = 'A survey of the operator’s whole licence; documents are online from late 2024.';
    var NO_DOCUMENT_NOTE = 'The state shows this survey’s date, type and outcome, but no document for it.';
    var NOT_READ_NOTE = 'The statement of deficiencies is not available online for this survey, so the rules cited are not shown. '
        + 'The outcome means the survey cited at least one rule and the state accepted the organization’s plan of correction.';
    var PLAN_ONLY_NOTE = 'Only the plan of correction is online for this survey; the statement it answers is not.';
    var ADULT_NOTE = 'This survey appears to be about an adult program. It is hidden unless adult programs are included.';
    var SOURCE_NOTE = 'The rules, findings and plans above are read from the state’s documents; the documents themselves are the record.';

    var KEEP_UPPER = /^(QI|CQI|MH|CPR|ACT|HIPAA|PNMI|SFMO|NFPA|MHRT|CMR|DHHS)$/;

    // "Include adult programs" state. The shared page treats the first option of
    // a filter as "no filter", so hiding adult reports by default is done here:
    // a facility's reports are a getter that follows this mode, which a capture
    // listener on the filter's select keeps current.
    var adultMode = 'hide';

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (target && target.id === 'kop-rp-filter-adult') adultMode = target.value === 'ALL' ? 'hide' : target.value;
    }, true);

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

    function sentence(value) {
        var text = safeString(value).toLowerCase();
        return text.charAt(0).toUpperCase() + text.slice(1);
    }

    // "SECTION 9. CLIENT SERVICE PLAN" -> { number: 'Section 9', title: 'Client service plan' }.
    function sectionParts(section) {
        var m = safeString(section).match(/^SECTION\s+(\d+[A-Z]?)\s*\.?\s*(.*)$/i);
        if (!m) return { number: '', title: safeString(section) };
        var words = m[2].toLowerCase().split(/\s+/).filter(Boolean).map(function (w, i) {
            if (KEEP_UPPER.test(w.toUpperCase())) return w.toUpperCase();
            return i === 0 ? w.charAt(0).toUpperCase() + w.slice(1) : w;
        });
        return { number: 'Section ' + m[1], title: words.join(' ') };
    }

    function isWaived(report) {
        return /waived/i.test(report.type);
    }

    function isFlagged(report) {
        return report.outcome === 'ACCEPTED PLAN OF CORRECTION' || report.count > 0;
    }

    function reportTone(report) {
        if (isFlagged(report)) return 'flagged';
        if (isWaived(report)) return 'neutral';
        return report.outcome === 'NO DEFICIENCIES' ? 'clean' : 'neutral';
    }

    function typeLabel(report) {
        if (report.isComplaint) return 'Complaint survey';
        return sentence(report.type) || 'Survey';
    }

    function convertApiDataToFacilities(apiFacilities) {
        return (apiFacilities || []).map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var deficiencies = Array.isArray(cats.deficiencies) ? cats.deficiencies : [];
                return {
                    report_id:   safeString(report.report_id),
                    report_date: safeString(report.report_date),
                    report_url:  safeString(report.report_url),
                    summary:     safeString(report.summary),
                    type:        safeString(cats.inspection_type),
                    outcome:     safeString(cats.outcome).toUpperCase(),
                    isComplaint: !!cats.is_complaint,
                    surveyKind:  safeString(cats.survey_kind),
                    numbers:     Array.isArray(cats.survey_numbers) ? cats.survey_numbers : [],
                    site:        safeString(cats.site),
                    adult:       !!cats.adult_program,
                    documents:   Array.isArray(cats.documents) ? cats.documents : [],
                    held:        parseInt(cats.documents_held_back, 10) || 0,
                    deficiencies: deficiencies,
                    count:       parseInt(cats.deficiency_count, 10) || deficiencies.length,
                    services:    Array.isArray(cats.services) ? cats.services : []
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            var withServices = reports.filter(function (r) { return r.services.length; })[0];
            var seen = {};
            var services = [];
            ((withServices && withServices.services) || []).forEach(function (s) {
                var name = safeString(s.service);
                if (name && !seen[name]) { seen[name] = true; services.push(name); }
            });

            var out = {
                name:     safeString(info.facility_name),
                category: safeString(info.program_category),
                licence:  safeString(info.program_name),
                address:  safeString(info.full_address),
                phone:    safeString(info.phone),
                status:   safeString(info.action),
                expires:  safeString(info.license_exp_date),
                services: services,
                all:      reports
            };
            // What the page lists: adult-program reports only when asked for.
            Object.defineProperty(out, 'reports', {
                enumerable: true,
                configurable: true,
                get: function () {
                    return adultMode === 'hide' ? reports.filter(function (r) { return !r.adult; }) : reports;
                }
            });
            return out;
        });
    }

    function deficiencyFinding(rule, ctx) {
        var parts = sectionParts(rule.section);
        var chips = [];
        var completion = safeString(rule.completion_date);
        if (completion && completion.length <= 40) chips.push({ text: 'Complete by ' + completion, tone: 'neutral' });
        var more = [];
        if (rule.plan) more.push({ title: 'Organization’s plan of correction', paragraphs: safeString(rule.plan).split('\n') });
        return ui.finding({
            title: parts.title || 'Rule cited',
            citation: parts.number,
            chips: chips,
            evidence: safeString(rule.finding).split('\n'),
            requirement: rule.rule_text ? [safeString(rule.rule_text)] : [],
            more: more,
            tone: 'flagged'
        });
    }

    function reportBody(report, ctx) {
        var html = '';
        if (report.deficiencies.length) {
            html += ui.heading(ctx.plural(report.deficiencies.length, 'deficiency', 'deficiencies') + ' cited');
            html += report.deficiencies.map(function (rule) { return deficiencyFinding(rule, ctx); }).join('');
            html += ui.note(SOURCE_NOTE);
        } else if (!report.documents.length) {
            html += ui.note(report.outcome === 'ACCEPTED PLAN OF CORRECTION' ? NOT_READ_NOTE : NO_DOCUMENT_NOTE);
        } else if (report.outcome === 'ACCEPTED PLAN OF CORRECTION') {
            var onlyPlans = report.documents.every(function (d) { return /plan of correction/i.test(d.kind); });
            html += ui.note(onlyPlans ? PLAN_ONLY_NOTE : NOT_READ_NOTE);
        } else if (report.outcome === 'NO DEFICIENCIES') {
            html += ui.note('The state’s statement for this survey says the organization is in substantial compliance with the licensing rule.');
        }
        if (report.held) {
            html += ui.note(report.held === 1
                ? 'One more document for this survey is not shown here; see the state’s lookup.'
                : report.held + ' more documents for this survey are not shown here; see the state’s lookup.');
        }
        if (report.adult) html += ui.note(ADULT_NOTE);
        return html;
    }

    function reportBadges(report, ctx) {
        var badges = [];
        if (report.deficiencies.length) {
            badges.push({ text: ctx.plural(report.count, 'deficiency', 'deficiencies'), tone: 'flagged' });
        } else if (report.outcome === 'ACCEPTED PLAN OF CORRECTION') {
            badges.push({ text: 'Deficiencies cited', tone: 'flagged' });
        } else if (isWaived(report)) {
            badges.push({ text: 'Survey waived', tone: 'neutral' });
        } else if (report.outcome === 'NO DEFICIENCIES') {
            badges.push({ text: 'No deficiencies', tone: 'clean' });
        }
        if (report.adult) badges.push({ text: 'Adult program', tone: 'neutral' });
        return badges;
    }

    function reportFacts(report) {
        var facts = [];
        if (report.surveyKind && !report.isComplaint) facts.push(report.surveyKind);
        if (report.numbers.length) facts.push('Survey ' + report.numbers.join(', '));
        if (report.site) facts.push('Site: ' + report.site);
        if (report.outcome) facts.push('Outcome: ' + sentence(report.outcome));
        return facts;
    }

    function reportPreview(report) {
        var first = report.deficiencies.filter(function (d) { return d.finding; })[0];
        return first ? clip(first.finding, 220) : report.summary;
    }

    function documentLinks(report, ctx) {
        return report.documents.map(function (d) {
            var label = /plan of correction/i.test(d.kind) ? 'Plan of correction'
                : /no deficienc/i.test(d.kind) ? 'No-deficiency statement' : 'Statement of deficiencies';
            var when = formatIso(ctx, d.date);
            return ctx.archiveLink(d.archive_name, label + ' (archived copy)' + (when ? ', ' + when : ''));
        });
    }

    page.mount({
        state: 'Maine',
        archiveState: 'ME',
        flaggedFilter: 'reports',
        emptyMessage: 'No facilities found in the database for Maine.',
        violationsNote: 'Maine’s state source shows an outcome for every survey; surveys that cited rules are listed here.',

        filters: [{
            id: 'outcome',
            label: 'Outcome:',
            options: [
                { value: 'ALL', label: 'All surveys' },
                { value: 'cited', label: 'Rules cited (plan of correction accepted)' },
                { value: 'clean', label: 'No deficiencies' }
            ],
            test: function (f, value) { return f.reports.some(function (r) { return outcomeMatches(r, value); }); },
            testReport: function (r, value) { return outcomeMatches(r, value); }
        }, {
            id: 'kind',
            label: 'Survey type:',
            options: [
                { value: 'ALL', label: 'All survey types' },
                { value: 'complaint', label: 'Complaint surveys' },
                { value: 'routine', label: 'Not complaint surveys' }
            ],
            test: function (f, value) { return f.reports.some(function (r) { return kindMatches(r, value); }); },
            testReport: function (r, value) { return kindMatches(r, value); }
        }, {
            id: 'adult',
            label: 'Adult programs:',
            options: [
                { value: 'ALL', label: 'Hidden (default)' },
                { value: 'include', label: 'Include adult programs' },
                { value: 'only', label: 'Adult programs only' }
            ],
            test: function (f, value) { return value === 'include' || f.all.some(function (r) { return r.adult; }); },
            testReport: function (r, value) { return value === 'include' || r.adult; }
        }, {
            id: 'licence',
            label: 'Licence:',
            options: [
                { value: 'ALL', label: 'All licences' },
                { value: 'mental', label: 'Mental health' },
                { value: 'substance', label: 'Substance use' }
            ],
            test: function (f, value) { return /substance/i.test(f.category) === (value === 'substance'); }
        }],

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=ME&lite=1')
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
            return f.reports.reduce(function (sum, r) { return sum + (isFlagged(r) ? Math.max(r.count, 1) : 0); }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var cited = reports.filter(isFlagged);
            var complaints = reports.filter(function (r) { return r.isComplaint; });
            var hidden = adultMode === 'hide' ? facility.all.length - reports.length : 0;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'survey'), tone: 'neutral' }];
            if (cited.length) stats.push({ text: ctx.plural(cited.length, 'survey') + ' with rules cited', tone: 'flagged' });
            if (complaints.length) stats.push({ text: ctx.plural(complaints.length, 'complaint survey'), tone: 'neutral' });
            if (hidden) stats.push({ text: ctx.plural(hidden, 'adult-program survey') + ' hidden', tone: 'neutral' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            var meta = [
                facility.category,
                facility.licence ? 'Licence ' + facility.licence : '',
                facility.status,
                isoDate(facility.expires) ? 'Licence expires ' + ctx.formatDate(parseDate(facility.expires).getTime()) : '',
                facility.services.length ? 'Licensed services: ' + facility.services.join(', ') : '',
                LICENCE_NOTE
            ];
            return { meta: meta, address: facility.address, stats: stats };
        },

        report: function (report, ctx) {
            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || 'Date unknown',
                type: typeLabel(report),
                tone: reportTone(report),
                badges: reportBadges(report, ctx),
                facts: reportFacts(report),
                link: { href: report.report_url, text: 'State licence lookup' },
                links: documentLinks(report, ctx),
                preview: reportPreview(report),
                // Built up front, not on open: severe-flags.js finds a severe
                // finding by the text on the page.
                body: reportBody(report, ctx)
            };
        }
    });

    function isoDate(value) {
        return /^\d{4}-\d{2}-\d{2}$/.test(safeString(value));
    }

    function outcomeMatches(report, value) {
        if (value === 'cited') return isFlagged(report);
        if (value === 'clean') return !isFlagged(report);
        return true;
    }

    function kindMatches(report, value) {
        if (value === 'complaint') return report.isComplaint;
        if (value === 'routine') return !report.isComplaint;
        return true;
    }
}());
