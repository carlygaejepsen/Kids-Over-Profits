/**
 * Idaho children's residential licensing surveys (/id-reports/) -- adapter for
 * report-page.js.
 *
 * Data comes from api/inspections-read.php?state=ID (id_scraper.py in the
 * Tools repo): the Department of Health and Welfare's Children's Residential
 * Licensing documents, one folder per licensed children's residential care
 * facility and outdoor program in the department's public document
 * repository. Each licensing survey leaves one document, in categories.kind:
 *
 *   deficiencies     "Approved POC": the statement of deficiencies with the
 *                    facility's accepted plan of correction. Per deficiency:
 *                    the rule cited, the surveyor's finding, the facility's
 *                    answers to the state's four questions, the date it is
 *                    to be corrected, and whether the surveyor calls it a
 *                    repeat. The scraper reads the state's four-column table
 *                    (categories.deficiencies[]); raw_content repeats it as
 *                    text for site search and the severe-finding scan.
 *   no_deficiencies  "No Deficiencies Letter": the license was renewed and
 *                    the survey found nothing. The letter's text is the body.
 *
 * Nothing else is posted: the scraper holds back any document that is neither
 * of these (and any whose text carries a date of birth, a named child or a
 * record number), so every row here is a licensing document.
 *
 * What counts as a violation: a statement of deficiencies. The state only
 * writes one when the survey cited at least one rule, and a cited rule is a
 * finding of non-compliance whatever the plan of correction says afterwards,
 * so every statement is flagged; the rows say how many rules were cited and
 * how many the surveyor marks as a repeat of an earlier citation (tone
 * "repeat"). A no-deficiency letter is clean. Statements that carry "N/A" as
 * the license granted are surveys outside the yearly renewal; they cite rules
 * all the same and are flagged. The state does not mark which surveys follow
 * a complaint, so none is labelled as one.
 *
 * Two things the state's folders do not say, which this page does not guess:
 *   - A folder whose provider is missing from the state's current provider
 *     list (closed, no longer licensed, or renamed; the documents do not say
 *     which) carries categories.provider.listed = false and an action that
 *     begins "Not on the state's current provider list".
 *   - Folders whose documents carry the same license number (a renamed or
 *     taken-over program keeps it) name each other in
 *     categories.same_license_as. They stay separate: a name keeps its own
 *     years.
 *
 * The state keeps a facility's documents only while its folder exists, so the
 * archived copy on this site (archiveState 'ID') is the reader's link as much
 * as the state's. The page loads in full (about 2 MB of text, no lite list).
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Idaho reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var STATEMENT_NOTE = 'The rules, findings and plans of correction above are read from the state’s table; '
        + 'the state’s document is the record. The plan of correction is the facility’s own answer, '
        + 'accepted by the department.';
    var LETTER_NOTE = 'The department sends this letter when a survey cites no deficiencies and the license is renewed.';
    var LOOSE_DATE_NOTE = 'This statement gives its survey date loosely (“{dates}”); the date shown is the date in the document’s name.';
    var SMALL_WORDS = /^(and|of|the|for|in|at|by|to|on|or|with|from)$/;

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

    function isIso(value) {
        return /^\d{4}-\d{2}-\d{2}$/.test(safeString(value));
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
                return {
                    report_id:     safeString(report.report_id),
                    report_date:   safeString(report.report_date),
                    report_url:    safeString(report.report_url),
                    summary:       safeString(report.summary),
                    raw_content:   safeString(report.raw_content),
                    kind:          cats.kind === 'deficiencies' ? 'deficiencies' : 'no_deficiencies',
                    surveyDates:   safeString(cats.survey_dates),
                    license:       safeString(cats.license),
                    licenseGranted: safeString(cats.license_granted),
                    licenseEnd:    safeString(cats.license_end),
                    region:        safeString(cats.region),
                    risk:          safeString(cats.risk_assessment),
                    planSubmitted: safeString(cats.plan_submitted),
                    dateSource:    safeString(cats.date_source),
                    documentName:  safeString(cats.document_name),
                    archiveName:   safeString(cats.archive_name),
                    formerName:    safeString(cats.former_name),
                    deficiencies:  deficiencies,
                    count:         parseInt(cats.deficiency_count, 10) || deficiencies.length,
                    repeatCount:   parseInt(cats.repeat_count, 10) || 0,
                    provider:      cats.provider || null,
                    sameLicense:   Array.isArray(cats.same_license_as) ? cats.same_license_as : []
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            // The scraper sends the provider list's details and the shared
            // license note on a facility's newest report only.
            var withProvider = reports.filter(function (r) { return r.provider; })[0];
            var withShared = reports.filter(function (r) { return r.sameLicense.length; })[0];
            var licensed = reports.filter(function (r) { return r.license; })[0];
            return {
                name:       safeString(info.facility_name),
                category:   safeString(info.program_category),
                address:    safeString(info.full_address),
                phone:      safeString(info.phone),
                capacity:   safeString(info.bed_capacity),
                status:     safeString(info.action),
                license:    licensed ? licensed.license : '',
                formerName: (reports.filter(function (r) { return r.formerName; })[0] || {}).formerName || '',
                ages:       withProvider && withProvider.provider ? safeString(withProvider.provider.ages) : '',
                unlisted:   !!(withProvider && withProvider.provider && withProvider.provider.listed === false),
                sameLicense: withShared ? withShared.sameLicense : [],
                reports:    reports
            };
        });
    }

    function isFlagged(report) {
        return report.kind === 'deficiencies';
    }

    function reportTone(report) {
        if (!isFlagged(report)) return 'clean';
        return report.repeatCount ? 'repeat' : 'flagged';
    }

    // "SERVICE PLANS. 02. Updated Service Plan. ..." -> "Service plans".
    function ruleTitle(rule) {
        var m = safeString(rule.rule_text).match(/^([A-Z][A-Z0-9 ,;'&\/()-]{2,}?)\s*\./);
        if (!m) return rule.rule ? 'Rule ' + safeString(rule.rule) : 'Rule cited';
        var words = m[1].toLowerCase().split(/\s+/);
        return words.map(function (w, i) {
            return (i === 0 || !SMALL_WORDS.test(w)) ? w.charAt(0).toUpperCase() + w.slice(1) : w;
        }).join(' ');
    }

    function correctionChip(rule, ctx) {
        var value = safeString(rule.date_to_correct);
        if (!value) return null;
        if (isIso(value)) return { text: 'Correct by ' + formatIso(ctx, value), tone: 'neutral' };
        return value.length <= 60 ? { text: 'Correct by: ' + value, tone: 'neutral' } : null;
    }

    function deficiencyFinding(rule, ctx) {
        var chips = [];
        if (rule.repeat) chips.push({ text: 'Repeat deficiency', tone: 'repeat' });
        var when = correctionChip(rule, ctx);
        if (when) chips.push(when);
        var more = [];
        if (rule.plan) more.push({ title: 'Facility’s plan of correction', paragraphs: safeString(rule.plan).split('\n') });
        return ui.finding({
            title: ruleTitle(rule),
            citation: safeString(rule.rule),
            chips: chips,
            evidence: safeString(rule.finding).split('\n'),
            requirement: rule.rule_text ? [safeString(rule.rule_text)] : [],
            more: more,
            tone: rule.repeat ? 'repeat' : 'flagged'
        });
    }

    function reportBody(report, ctx) {
        var html = '';
        if (report.kind === 'deficiencies') {
            html += ui.heading(ctx.plural(report.deficiencies.length, 'deficiency', 'deficiencies') + ' cited');
            html += report.deficiencies.map(function (rule) { return deficiencyFinding(rule, ctx); }).join('');
            html += ui.note(STATEMENT_NOTE);
            if (report.dateSource === 'document_name') {
                html += ui.note(LOOSE_DATE_NOTE.replace('{dates}', report.surveyDates || 'no date'));
            }
        } else {
            html += ui.note(LETTER_NOTE);
            html += ui.section('Text of the letter', ui.docText(report.raw_content));
        }
        return html;
    }

    // "4/10/2024 to 4/10/2024" -> "4/10/2024".
    function surveyWhen(text) {
        var parts = safeString(text).split(/\s+to\s+/);
        return parts.length === 2 && parts[0] === parts[1] ? parts[0] : safeString(text);
    }

    function reportBadges(report, ctx) {
        if (report.kind === 'no_deficiencies') return [{ text: 'No deficiencies', tone: 'clean' }];
        var badges = [{ text: ctx.plural(report.count, 'deficiency', 'deficiencies'), tone: 'flagged' }];
        if (report.repeatCount) {
            badges.push({ text: report.repeatCount === 1 ? 'Repeat deficiency' : report.repeatCount + ' repeat deficiencies', tone: 'repeat' });
        }
        return badges;
    }

    function reportFacts(report, ctx) {
        var facts = [];
        if (report.kind === 'deficiencies') {
            if (report.surveyDates && report.dateSource !== 'document_name') facts.push('Survey ' + surveyWhen(report.surveyDates));
            if (report.license) facts.push('License ' + report.license);
            if (report.licenseGranted) facts.push('License granted: ' + report.licenseGranted);
            if (report.region) facts.push('Region ' + report.region);
            if (report.risk) facts.push('Risk assessment: ' + report.risk);
            if (isIso(report.planSubmitted)) facts.push('Plan submitted ' + formatIso(ctx, report.planSubmitted));
        } else if (isIso(report.licenseEnd)) {
            facts.push('License valid until ' + formatIso(ctx, report.licenseEnd));
        }
        return facts;
    }

    function reportPreview(report) {
        if (report.kind === 'no_deficiencies') return 'License renewed; no deficiencies noted';
        var first = report.deficiencies.filter(function (d) { return d.finding; })[0];
        return first ? clip(first.finding, 220) : '';
    }

    page.mount({
        state: 'Idaho',
        archiveState: 'ID',
        emptyMessage: 'No facilities found in the database for Idaho.',

        filters: [{
            id: 'kind',
            label: 'Documents:',
            options: [
                { value: 'ALL', label: 'All documents' },
                { value: 'deficiencies', label: 'Statements of deficiencies' },
                { value: 'repeat', label: 'With a repeat deficiency' },
                { value: 'none', label: 'No-deficiency letters' }
            ],
            test: function (f, value) { return f.reports.some(function (r) { return kindMatches(r, value); }); },
            testReport: function (r, value) { return kindMatches(r, value); }
        }, {
            id: 'program',
            label: 'Program type:',
            options: [
                { value: 'ALL', label: 'All program types' },
                { value: 'residential', label: 'Residential care facilities' },
                { value: 'outdoor', label: 'Outdoor programs' }
            ],
            test: function (f, value) {
                var outdoor = /outdoor|wilderness/i.test(f.category);
                return value === 'outdoor' ? outdoor : !outdoor;
            }
        }, {
            id: 'listed',
            label: 'State provider list:',
            options: [
                { value: 'ALL', label: 'All providers' },
                { value: 'listed', label: 'On the state’s current list' },
                { value: 'unlisted', label: 'Not on the current list' }
            ],
            test: function (f, value) { return value === 'unlisted' ? f.unlisted : !f.unlisted; }
        }],

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=ID')
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
            return [f.name, f.formerName, f.license, f.category, f.address]
                .concat(f.sameLicense.map(function (s) { return s.name; }))
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
            var letters = reports.length - statements.length;
            var deficiencies = statements.reduce(function (sum, r) { return sum + r.count; }, 0);
            var repeats = statements.reduce(function (sum, r) { return sum + r.repeatCount; }, 0);
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'survey'), tone: 'neutral' }];
            if (deficiencies) stats.push({ text: ctx.plural(deficiencies, 'deficiency', 'deficiencies'), tone: 'flagged' });
            if (repeats) stats.push({ text: ctx.plural(repeats, 'repeat deficiency', 'repeat deficiencies'), tone: 'repeat' });
            if (letters) stats.push({ text: ctx.plural(letters, 'survey') + ' with no deficiencies', tone: 'clean' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            var meta = [
                facility.category,
                facility.license ? 'License ' + facility.license : '',
                facility.formerName ? 'Formerly ' + facility.formerName : '',
                facility.capacity ? 'Capacity ' + facility.capacity : '',
                facility.ages ? 'Ages ' + facility.ages : '',
                facility.status
            ];
            // A renamed or taken-over program keeps its license number: say
            // so, with the years of the other name's surveys, and nothing more.
            facility.sameLicense.forEach(function (other) {
                var first = safeString(other.first).slice(0, 4);
                var last = safeString(other.last).slice(0, 4);
                meta.push('Same license number as ' + other.name + ' (surveys ' + (first === last ? first : first + '–' + last) + ')');
            });
            return { meta: meta, address: facility.address, stats: stats };
        },

        report: function (report, ctx) {
            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || 'Date unknown',
                type: report.kind === 'deficiencies' ? 'Statement of deficiencies' : 'No-deficiency letter',
                tone: reportTone(report),
                badges: reportBadges(report, ctx),
                facts: reportFacts(report, ctx),
                link: { href: report.report_url, text: 'State document' },
                links: [ctx.archiveLink(report.archiveName)],
                preview: reportPreview(report),
                // Built up front, not on open: severe-flags.js finds a severe
                // finding by the text on the page.
                body: reportBody(report, ctx)
            };
        }
    });

    function kindMatches(report, value) {
        if (value === 'deficiencies') return report.kind === 'deficiencies';
        if (value === 'repeat') return report.repeatCount > 0;
        if (value === 'none') return report.kind === 'no_deficiencies';
        return true;
    }
}());
