/**
 * South Dakota youth care provider documents (/sd-reports/) -- adapter for
 * report-page.js.
 *
 * Data comes from api/inspections-read.php?state=SD (sd_scraper.py in the
 * Tools repo): the documents the Department of Social Services, Office of
 * Licensing and Accreditation, posts on each provider's page of its public
 * portal (olapublic.sd.gov). Providers in scope: Residential Treatment,
 * Intensive Residential Treatment, Group Care, Shelter Care and Independent
 * Living. Program certificates are left out. The portal posts no complaint or
 * investigation documents, so this page has none.
 *
 * One document is one report, of three kinds (categories.kind):
 *   licensing_study         The renewal study: a checklist of rule sections,
 *                           each item answered Yes / No / N/A (2025 form) or
 *                           ticked under YES or NO (2024 form), with the
 *                           reviewer's comments and a licensing recommendation.
 *   corrective_action_plan  A corrective action plan (licensing findings) or a
 *                           compliance plan (fire and health findings): per
 *                           item the rule, the finding and the correction.
 *   inspection              The Department of Public Safety's fire, health and
 *                           food service inspection form. The 2024 forms are
 *                           scans filled in by hand; their answers are not read.
 *
 * What counts as a violation:
 *   - a licensing study with at least one rule section the scraper lists as
 *     not met (categories.not_met): an item answered No, an item whose answer
 *     column says "See comments" (2024 form), or a section whose reviewer
 *     comment refers to a corrective action plan or compliance plan;
 *   - every corrective action plan and compliance plan: the state writes one
 *     only for a finding of noncompliance;
 *   - an inspection form with at least one item answered No.
 * A scanned inspection whose answers were not read is neutral, never clean.
 *
 * The box "All applicable requirements of this inspection have been met"
 * reads No on every typed form posted, also on forms where every item is Yes,
 * so it is never counted; a note in the report says so.
 *
 * The list loads without document text (?lite=1); a report's text is fetched
 * when it is opened.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('South Dakota reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var KIND_LABELS = {
        licensing_study: 'Licensing study',
        corrective_action_plan: 'Corrective action plan',
        inspection: 'Fire, health and safety inspection'
    };

    var PROVIDER_TYPES = [
        { value: 'residential treatment', label: 'Residential treatment' },
        { value: 'intensive residential treatment', label: 'Intensive residential treatment' },
        { value: 'group care', label: 'Group care' },
        { value: 'shelter care', label: 'Shelter care' },
        { value: 'independent living', label: 'Independent living' }
    ];

    var ALL_MET_NOTE = 'The form’s box “All applicable requirements of this inspection have been met” '
        + 'reads No on every typed inspection form posted, including forms where every item is answered Yes. '
        + 'This page counts the items answered No instead.';
    var SCAN_NOTE = 'This inspection form was filled in by hand and scanned. Its answers were not read by machine; '
        + 'open the document to read them. It is listed here as neither a finding of violations nor a finding that there were none.';
    var STUDY_NOTE = 'A licensing study is the state’s review of a provider for license renewal. '
        + 'A section is listed here when an item in it is answered No or “See comments”, '
        + 'or when the reviewer’s comment refers to a corrective action plan or compliance plan.';
    var PLAN_NOTE = 'The state writes a corrective action plan or compliance plan for a finding of noncompliance. '
        + 'The provider fills in the correction.';

    // "2025-06-12" -> local Date; anything else through Date; '' -> epoch 0.
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

    function list(value) {
        return Array.isArray(value) ? value : [];
    }

    function shorten(text, max) {
        var s = safeString(text);
        if (s.length <= max) return s;
        return s.slice(0, max).replace(/\s+\S*$/, '') + '…';
    }

    // The forms run words together where the PDF sets them tight.
    function tidy(text) {
        return safeString(text)
            .replace(/extinguishersare\b/g, 'extinguishers are')
            .replace(/contaminationand\b/g, 'contamination and')
            .replace(/Sprinklerhas\b/g, 'Sprinkler has');
    }

    // "Fire and Life Safety: Fire & Life Safety" -> "Fire and Life Safety".
    function sectionLabel(section) {
        var parts = safeString(section).split(/:\s+/);
        var norm = function (s) { return safeString(s).toLowerCase().replace(/&/g, 'and').replace(/[^a-z]+/g, ' ').trim(); };
        if (parts.length === 2 && norm(parts[0]) === norm(parts[1])) return parts[0];
        return safeString(section);
    }

    // Trailing rule numbers ("... repair? 67:42:11:39") become the citation.
    var TRAILING_RULES = /((?:\s*,?\s*\d{2}:\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?)+)\s*$/;
    function splitRules(text) {
        var s = tidy(text);
        var m = s.match(TRAILING_RULES);
        if (!m) return { text: s, rules: '' };
        return { text: s.slice(0, m.index).trim(), rules: m[1].replace(/^\s*,?\s*/, '').replace(/\s*,\s*/g, ', ').trim() };
    }

    function convertApiDataToFacilities(apiFacilities) {
        return (apiFacilities || []).map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var kind = KIND_LABELS[cats.kind] ? cats.kind : 'other';
                var out = {
                    report_id:    safeString(report.report_id),
                    report_date:  safeString(report.report_date),
                    report_url:   safeString(report.report_url),
                    raw_content:  String(report.raw_content || ''),
                    has_text:     report.has_text !== undefined ? !!report.has_text : !!report.raw_content,
                    row_id:       report.row_id || null,
                    kind:         kind,
                    title:        safeString(cats.title),
                    doc_type:     safeString(cats.doc_type),
                    archive_name: safeString(cats.archive_name),
                    named_program: safeString(cats.named_program)
                };
                if (kind === 'licensing_study') {
                    out.form = safeString(cats.form);
                    out.program_type = safeString(cats.program_type);
                    out.site_visit_date = safeString(cats.site_visit_date);
                    out.section_count = parseInt(cats.section_count, 10) || 0;
                    out.not_met = list(cats.not_met);
                    out.recommendation = tidy(cats.recommendation);
                } else if (kind === 'corrective_action_plan') {
                    out.plan_type = cats.plan_type === 'compliance' ? 'compliance' : 'corrective_action';
                    out.status = safeString(cats.status);
                    out.date_issued = safeString(cats.date_issued);
                    out.completion_date = safeString(cats.completion_date);
                    out.items = list(cats.items);
                } else if (kind === 'inspection') {
                    out.scanned = !!cats.scanned;
                    out.result = safeString(cats.result);
                    out.all_met = safeString(cats.all_met);
                    out.inspection_date = safeString(cats.inspection_date);
                    // Only a form whose answers were read carries failed items.
                    var read = out.result === 'failed_items' || out.result === 'all_met';
                    out.unread = !read;
                    out.failed = read ? list(cats.failed) : [];
                    out.comments = read ? list(cats.comments).filter(function (c) {
                        return safeString(c.text) && !/^Page \d+ PDF Created On\b/i.test(safeString(c.text));
                    }) : [];
                }
                return out;
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            return {
                name:     safeString(info.facility_name),
                category: safeString(info.program_category),
                address:  safeString(info.full_address),
                phone:    safeString(info.phone),
                capacity: safeString(info.bed_capacity),
                status:   safeString(info.action),
                reports:  reports
            };
        });
    }

    function isFlagged(report) {
        if (report.kind === 'licensing_study') return report.not_met.length > 0;
        if (report.kind === 'corrective_action_plan') return true;
        if (report.kind === 'inspection') return report.failed.length > 0;
        return false;
    }

    function reportTone(report) {
        if (isFlagged(report)) return 'flagged';
        if (report.kind === 'licensing_study') return 'clean';
        if (report.kind === 'inspection' && !report.unread) return 'clean';
        return 'neutral';
    }

    function typeLabel(report) {
        if (report.kind === 'corrective_action_plan') {
            return report.plan_type === 'compliance' ? 'Compliance plan' : 'Corrective action plan';
        }
        return KIND_LABELS[report.kind] || report.doc_type || 'Document';
    }

    // "10. Treatment" -> "Treatment (section 10)"
    function studySectionTitle(section) {
        var m = safeString(section).match(/^(\d{1,2})\.\s*(.*)$/);
        return m ? (m[2] || 'Section') + ' (section ' + m[1] + ')' : safeString(section);
    }

    // The citation chip does not wrap: a long list of rules goes in the text.
    var CITATION_MAX = 32;
    function citationOf(rules) {
        var r = safeString(rules).replace(/[,;\s]+$/, '');
        return r.length <= CITATION_MAX ? r : '';
    }
    function rulesLine(rules) {
        var r = safeString(rules).replace(/[,;\s]+$/, '');
        return r.length > CITATION_MAX ? ['Rules: ' + r] : [];
    }

    function studyBlock(entry) {
        var evidence = rulesLine(entry.rule).concat(list(entry.items).map(function (item) {
            var answer = item.answer === 'See comments' ? 'answered “See comments”' : 'answered No';
            var under = safeString(item.under);
            return tidy((under ? under + ': ' : '') + safeString(item.text)) + ' (' + answer + ')';
        }));
        if (entry.comment) {
            evidence.push('Reviewer’s comment:');
            evidence.push(tidy(entry.comment));
        }
        var chips = [];
        if (entry.basis === 'comment') chips.push({ text: 'Listed from the reviewer’s comment', tone: 'neutral' });
        return ui.finding({
            title: studySectionTitle(entry.section),
            citation: citationOf(entry.rule),
            chips: chips,
            evidence: evidence,
            tone: 'flagged'
        });
    }

    function lines(text) {
        return tidy(text).split('\n');
    }

    // "67:42:07:24. Use of seclusion and restraint ... documentation. A facility ..."
    function planItemTitle(item) {
        var m = tidy(item.rule_text).match(/^\d{2}:\d{2}:\d{2}:\d{2}(?:\.\d+)?[,.]*\s*([^.]{3,140})\./);
        if (m) return m[1].trim();
        return shorten(tidy(item.finding), 140) || 'Finding of noncompliance';
    }

    function planBlock(item) {
        var evidence = rulesLine(item.rule);
        var title = planItemTitle(item);
        // A finding short enough to be the title is not repeated below it.
        if (item.finding && title !== tidy(item.finding)) evidence = evidence.concat(['Finding:'], lines(item.finding));
        var more = [];
        if (item.action_needed) more.push({ title: 'Correction the state required', paragraphs: lines(item.action_needed) });
        if (item.corrective_action) more.push({ title: 'The provider’s correction', paragraphs: lines(item.corrective_action) });
        if (item.evidence) more.push({ title: 'Supporting evidence', paragraphs: lines(item.evidence) });
        if (item.maintained) more.push({ title: 'How the correction is maintained', paragraphs: lines(item.maintained) });
        return ui.finding({
            title: title,
            citation: citationOf(item.rule),
            evidence: evidence,
            requirement: item.rule_text ? lines(item.rule_text) : [],
            more: more,
            tone: 'flagged'
        });
    }

    function inspectionBlock(item) {
        var split = splitRules(item.text);
        // The question is the title; the form's note after it ("(Exit signs
        // must be ...)") goes under "What the rule requires".
        var cut = split.text.indexOf('? ');
        var question = cut > 0 ? split.text.slice(0, cut + 1) : split.text;
        var rest = cut > 0 ? split.text.slice(cut + 2).trim() : '';
        return ui.finding({
            title: shorten(question, 200) || 'Item not met',
            requirement: rest ? [rest] : [],
            citation: citationOf(split.rules),
            evidence: rulesLine(split.rules),
            chips: [{ text: sectionLabel(item.section), tone: 'neutral' }, { text: 'Answered No', tone: 'flagged' }],
            tone: 'flagged'
        });
    }

    function studyBody(report, ctx) {
        var html = '';
        if (report.not_met.length) {
            html += ui.heading(ctx.plural(report.not_met.length, 'section') + ' not met');
            html += report.not_met.map(studyBlock).join('');
        } else {
            html += ui.note('No section of this study is marked not met'
                + (report.section_count ? ' (' + ctx.plural(report.section_count, 'section') + ' read).' : '.'));
        }
        if (report.recommendation) html += ui.heading('Recommendation') + ui.paragraphs([report.recommendation]);
        html += ui.note(STUDY_NOTE);
        return html;
    }

    function planBody(report, ctx) {
        var html = '';
        if (report.items.length) {
            html += ui.heading(ctx.plural(report.items.length, 'finding') + ' of noncompliance');
            html += report.items.map(planBlock).join('');
        } else {
            html += ui.note('The findings could not be read from this plan. Open the document.');
        }
        html += ui.note(PLAN_NOTE);
        return html;
    }

    function inspectionBody(report, ctx) {
        if (report.unread) return ui.note(SCAN_NOTE);
        var html = '';
        if (report.failed.length) {
            html += ui.heading(ctx.plural(report.failed.length, 'item') + ' answered No');
            html += report.failed.map(inspectionBlock).join('');
        } else {
            html += ui.note('No item on this form is answered No.');
        }
        if (report.comments.length) {
            html += ui.heading('Inspector’s comments');
            html += ui.paragraphs(report.comments.map(function (c) {
                var section = sectionLabel(c.section);
                return (section ? section + ': ' : '') + tidy(c.text);
            }));
        }
        if (report.all_met === 'No') html += ui.note(ALL_MET_NOTE);
        return html;
    }

    function reportBody(report, ctx) {
        var html = '';
        if (report.kind === 'licensing_study') html = studyBody(report, ctx);
        else if (report.kind === 'corrective_action_plan') html = planBody(report, ctx);
        else if (report.kind === 'inspection') html = inspectionBody(report, ctx);
        if (report.named_program) {
            html += ui.note('The form names another program, ' + report.named_program
                + ', although the state posted it on this provider’s page.');
        }
        var scan = report.kind === 'inspection' && report.scanned;
        html += ui.section(scan ? 'Text read from the scan (may contain recognition errors)' : 'Full document text',
            ui.docText(report.raw_content));
        return html;
    }

    function reportBadges(report, ctx) {
        if (report.kind === 'licensing_study') {
            return report.not_met.length
                ? [{ text: ctx.plural(report.not_met.length, 'section') + ' not met', tone: 'flagged' }]
                : [{ text: 'No section marked not met', tone: 'clean' }];
        }
        if (report.kind === 'corrective_action_plan') {
            var badges = [{
                text: report.items.length ? ctx.plural(report.items.length, 'finding') : 'Finding of noncompliance',
                tone: 'flagged'
            }];
            if (report.status) badges.push({ text: 'Status: ' + report.status, tone: 'neutral' });
            return badges;
        }
        if (report.kind === 'inspection') {
            if (report.unread) return [{ text: report.scanned ? 'Scanned form, answers not read' : 'Answers not read', tone: 'neutral' }];
            if (report.failed.length) return [{ text: ctx.plural(report.failed.length, 'item') + ' answered No', tone: 'flagged' }];
            return [{ text: 'No item answered No', tone: 'clean' }];
        }
        return [];
    }

    function reportPreview(report) {
        if (report.kind === 'licensing_study') {
            return report.not_met.map(function (e) { return safeString(e.section).replace(/^\d{1,2}\.\s*/, ''); })
                .filter(Boolean).join('; ');
        }
        if (report.kind === 'corrective_action_plan') {
            var first = report.items[0];
            return first ? shorten(tidy(first.finding) || planItemTitle(first), 160) : '';
        }
        if (report.kind === 'inspection') {
            return shorten(report.failed.map(function (i) { return splitRules(i.text).text.replace(/\?.*$/, ''); }).join('; '), 160);
        }
        return '';
    }

    function reportFacts(report, ctx) {
        var facts = [];
        if (report.kind === 'licensing_study') {
            if (report.site_visit_date) facts.push('Site visit ' + formatIso(ctx, report.site_visit_date));
            if (report.program_type) facts.push('Study for ' + report.program_type.toLowerCase());
        } else if (report.kind === 'corrective_action_plan') {
            if (report.date_issued) facts.push('Issued ' + formatIso(ctx, report.date_issued));
            if (report.completion_date) facts.push('Completed ' + formatIso(ctx, report.completion_date));
        } else if (report.kind === 'inspection') {
            if (report.inspection_date) facts.push('Inspected ' + formatIso(ctx, report.inspection_date));
        }
        if (report.title) facts.push('State title: ' + report.title);
        return facts;
    }

    page.mount({
        state: 'South Dakota',
        archiveState: 'SD',
        emptyMessage: 'No facilities found in the database for South Dakota.',

        filters: [{
            id: 'kind',
            label: 'Document type:',
            options: [
                { value: 'ALL', label: 'All documents' },
                { value: 'licensing_study', label: 'Licensing studies' },
                { value: 'corrective_action_plan', label: 'Corrective action and compliance plans' },
                { value: 'inspection', label: 'Fire, health and safety inspections' }
            ],
            test: function (f, value) {
                return f.reports.some(function (r) { return r.kind === value; });
            },
            testReport: function (r, value) { return r.kind === value; }
        }, {
            id: 'provider',
            label: 'Provider type:',
            options: [{ value: 'ALL', label: 'All provider types' }].concat(PROVIDER_TYPES),
            test: function (f, value) { return f.category.toLowerCase() === value; }
        }],

        load: function () {
            // lite: no document text in the list; withText() fetches a
            // report's text when it opens.
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=SD&lite=1')
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
            return [f.name, f.category, f.address, f.phone]
                .map(function (v) { return safeString(v).toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var count = function (test) { return reports.filter(test).length; };
            var studies = count(function (r) { return r.kind === 'licensing_study'; });
            var studiesNotMet = count(function (r) { return r.kind === 'licensing_study' && isFlagged(r); });
            var plans = count(function (r) { return r.kind === 'corrective_action_plan'; });
            var inspections = count(function (r) { return r.kind === 'inspection'; });
            var inspectionsNo = count(function (r) { return r.kind === 'inspection' && isFlagged(r); });
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [];
            if (studies) {
                stats.push({ text: ctx.plural(studies, 'licensing study', 'licensing studies'), tone: 'neutral' });
                if (studiesNotMet) stats.push({ text: studiesNotMet + ' with a section not met', tone: 'flagged' });
            }
            if (plans) stats.push({ text: ctx.plural(plans, 'correction plan'), tone: 'flagged' });
            if (inspections) {
                stats.push({ text: ctx.plural(inspections, 'inspection'), tone: 'neutral' });
                if (inspectionsNo) stats.push({ text: inspectionsNo + ' with items answered No', tone: 'flagged' });
            }
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [
                    facility.category,
                    facility.capacity ? 'Licensed capacity ' + facility.capacity : '',
                    facility.phone,
                    facility.status && facility.status !== 'Operational' ? facility.status : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            return {
                date: formatIso(ctx, report.report_date) || 'Date unknown',
                type: typeLabel(report),
                tone: reportTone(report),
                badges: reportBadges(report, ctx),
                facts: reportFacts(report, ctx),
                link: { href: report.report_url, text: 'State document' },
                links: [ctx.archiveLink(report.archive_name, 'Archived copy')],
                preview: reportPreview(report),
                body: function () { return page.withText(report, 'SD', function () { return reportBody(report, ctx); }); }
            };
        }
    });
}());
