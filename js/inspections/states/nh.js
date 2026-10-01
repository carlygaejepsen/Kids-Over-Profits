/**
 * New Hampshire licensing visits (/nh-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=NH: the NH DHHS Child Care
 * Licensing Unit's search of licensed residential child care programs
 * (nh_scraper.py in the Tools repo). Each program's page on the state site
 * lists every licensing visit with the rules reviewed; for each rule not met
 * it gives the licensing coordinator's observations and the program's
 * corrective action plan. The scraper posts one report per visit, with
 * everything the page needs in categories:
 *   visit_type      "Licensed Complaint Visit", "Renewal Visit", "Compliance
 *                   Visit", "Monitoring Visit", "Quality Assurance Visit",
 *                   "Revision Visit", "New Visit"
 *   is_complaint    true for both complaint types (they are the majority)
 *   announced       "Announced" or "Unannounced"
 *   compliance      { met, reviewed }: the state's "level of compliance"
 *   licensor, cap_accepted_date, cap_due_date, issue_date, license_number
 *   items           [{ rule, rule_text, domain, result, high_risk,
 *                      observations, directed_cap, corrective_action_plan,
 *                      completion_date }]: the rules not met, and only those
 *   item_count, resolved_count, rules_reviewed
 *   documents       [{ name, kind }]: the state's generated "statement of
 *                   findings" PDFs, archived on this site (see archiveState)
 * raw_content repeats the items as text for site search and the severe-finding
 * scan, and is small, so the list loads in full (no ?lite=1).
 *
 * What counts as a violation: a visit with at least one rule not met. That is
 * the state's own count: its level of compliance (for example 97 / 98) treats
 * both "Non-Compliant" and "Founded, Problem Resolved" as not met. A founded
 * rule that the program had already put right when the licensing coordinator
 * arrived has no corrective action plan; it is still a rule the program broke,
 * so it counts, and carries a "problem resolved" tag. A visit with every rule
 * met is clean; a visit with no rules reviewed (a license revision) is neutral.
 * The state's list does not say which complaints were substantiated, only which
 * rules were found not met, so complaint visits are shown apart (the filter) but
 * not tagged as substantiated.
 *
 * The state shows only the previous three years of visits, and a program that
 * loses its license disappears with its history. The scraper keeps a copy of
 * every page it reads, so visits that have aged out of the state's list stay here.
 *
 * Statements of findings are archived through ReportStore (Drive folder
 * nh_pdfs, copied to uploads/inspection-reports/nh/ by
 * api/sync-inspection-archive.php), hence archiveState 'NH'.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('New Hampshire reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var WINDOW_NOTE = 'New Hampshire shows only the previous three years of licensing visits on its site, and a program that loses its license disappears with its history. Visits that have aged out of the state’s list stay here.';
    var ITEMS_NOTE = 'Only the rules the licensing coordinator found not met are listed; every other rule reviewed in the visit was met.';
    var BLANK_NOTE = 'The state’s page gives no observations for this rule.';

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

    function lines(text) {
        return safeString(text).split(/\n+/).map(safeString).filter(Boolean);
    }

    function convertApiDataToFacilities(apiFacilities) {
        return (apiFacilities || []).map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var items = (Array.isArray(cats.items) ? cats.items : []).map(function (item) {
                    return {
                        rule:           safeString(item.rule),
                        ruleText:       safeString(item.rule_text),
                        domain:         safeString(item.domain),
                        result:         safeString(item.result),
                        highRisk:       !!item.high_risk,
                        observations:   safeString(item.observations),
                        directedCap:    safeString(item.directed_cap),
                        plan:           safeString(item.corrective_action_plan),
                        completionDate: safeString(item.completion_date)
                    };
                });
                var compliance = cats.compliance || {};
                return {
                    report_id:    safeString(report.report_id),
                    report_date:  safeString(report.report_date),
                    report_url:   safeString(report.report_url),
                    visitType:    safeString(cats.visit_type),
                    complaint:    !!cats.is_complaint,
                    announced:    safeString(cats.announced),
                    met:          parseInt(compliance.met, 10) || 0,
                    reviewed:     parseInt(cats.rules_reviewed, 10) || parseInt(compliance.reviewed, 10) || 0,
                    licensor:     safeString(cats.licensor),
                    capAccepted:  safeString(cats.cap_accepted_date),
                    capDue:       safeString(cats.cap_due_date),
                    licenseNo:    safeString(cats.license_number),
                    items:        items,
                    documents:    (Array.isArray(cats.documents) ? cats.documents : []).map(function (d) {
                        return { name: safeString(d.name), kind: safeString(d.kind) };
                    }).filter(function (d) { return d.name; })
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            var licenseNo = '';
            reports.some(function (r) { licenseNo = r.licenseNo; return !!licenseNo; });
            return {
                name:     safeString(info.facility_name),
                account:  safeString(info.program_name),
                licenseNo: licenseNo,
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
        return report.items.length > 0;
    }

    function reportTone(report) {
        if (isFlagged(report)) return 'flagged';
        return report.reviewed ? 'clean' : 'neutral';
    }

    // The three groups of the visit-type filter.
    function visitGroup(report) {
        if (report.complaint) return 'complaint';
        if (/^(renewal|new)\b/i.test(report.visitType)) return 'renewal';
        return 'other';
    }

    // "Licensed Complaint Visit" -> "Complaint visit".
    function typeLabel(report) {
        var label = report.visitType.replace(/^Licen[sc]ed\s+/i, '') || 'Licensing visit';
        if (!/\bvisit\b/i.test(label)) label += ' visit';
        return label.charAt(0).toUpperCase() + label.slice(1).toLowerCase();
    }

    function isResolved(item) {
        return item.result && !/^non-?compliant$/i.test(item.result);
    }

    function itemFinding(item) {
        var chips = [];
        if (isResolved(item)) chips.push({ text: 'Founded, problem resolved', tone: 'flagged' });
        if (item.highRisk) chips.push({ text: 'Higher risk to children’s health and safety', tone: 'repeat' });
        var evidence = [];
        if (item.observations) {
            evidence.push('Observations:');
            lines(item.observations).forEach(function (l) { evidence.push(l); });
        } else {
            evidence.push(BLANK_NOTE);
        }
        if (item.plan) {
            evidence.push('Corrective action plan:');
            lines(item.plan).forEach(function (l) { evidence.push(l); });
        } else if (isResolved(item)) {
            evidence.push('No corrective action plan was asked for: the problem was already resolved.');
        } else {
            evidence.push('No corrective action plan is on the state’s page yet.');
        }
        if (item.directedCap) {
            evidence.push('Directed corrective action:');
            lines(item.directedCap).forEach(function (l) { evidence.push(l); });
        }
        return ui.finding({
            title: item.domain || 'Rule not met',
            citation: item.rule,
            chips: chips,
            evidence: evidence,
            requirement: item.ruleText ? [item.ruleText] : [],
            tone: 'flagged'
        });
    }

    function reportBody(report, ctx) {
        var html = '';
        if (report.items.length) {
            html += ui.heading(report.items.length + ' of ' + ctx.plural(report.reviewed, 'rule') + ' not met');
            html += report.items.map(itemFinding).join('');
            html += ui.note(ITEMS_NOTE);
        } else if (report.reviewed) {
            html += ui.note('All ' + ctx.plural(report.reviewed, 'rule') + ' reviewed were met.');
        } else {
            html += ui.note('The state lists no rules reviewed for this visit.');
        }
        html += ui.note(WINDOW_NOTE);
        return html;
    }

    function reportBadges(report, ctx) {
        if (report.items.length) {
            var badges = [{ text: report.items.length + ' of ' + ctx.plural(report.reviewed, 'rule') + ' not met', tone: 'flagged' }];
            var resolved = report.items.filter(isResolved).length;
            if (resolved) badges.push({ text: resolved + ' founded, problem resolved', tone: 'neutral' });
            return badges;
        }
        if (report.reviewed) return [{ text: 'All ' + ctx.plural(report.reviewed, 'rule') + ' met', tone: 'clean' }];
        return [{ text: 'No rules reviewed', tone: 'neutral' }];
    }

    function documentLinks(report, ctx) {
        var links = [];
        report.documents.forEach(function (doc) {
            var text = doc.kind === 'scanned_statement' ? 'Statement of findings (scanned copy)' : 'Statement of findings';
            var link = ctx.archiveLink(doc.name, text);
            if (link) links.push(link);
        });
        return links;
    }

    page.mount({
        state: 'New Hampshire',
        emptyMessage: 'No facilities found in the database for New Hampshire.',
        archiveState: 'NH',

        filters: [{
            id: 'visit',
            label: 'Visit type:',
            options: [
                { value: 'ALL', label: 'All visits' },
                { value: 'complaint', label: 'Complaint visits' },
                { value: 'renewal', label: 'Renewal and new visits' },
                { value: 'other', label: 'Compliance, monitoring and other visits' }
            ],
            test: function (f, value) {
                return f.reports.some(function (r) { return visitGroup(r) === value; });
            },
            testReport: function (r, value) { return visitGroup(r) === value; }
        }],

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=NH')
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
            return [f.name, f.account, f.licenseNo, f.category, f.address]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        countFlagged: function (f) {
            return f.reports.reduce(function (sum, r) { return sum + r.items.length; }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var complaints = reports.filter(function (r) { return r.complaint; }).length;
            var items = reports.reduce(function (sum, r) { return sum + r.items.length; }, 0);
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [];
            if (reports.length) stats.push({ text: ctx.plural(reports.length, 'visit'), tone: 'neutral' });
            if (complaints) stats.push({ text: ctx.plural(complaints, 'complaint visit'), tone: 'flagged' });
            if (items) stats.push({ text: ctx.plural(items, 'rule') + ' not met', tone: 'flagged' });
            else if (reports.length) stats.push({ text: 'No rules found not met', tone: 'clean' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [
                    facility.category || 'Residential child care program',
                    facility.licenseNo ? 'License ' + facility.licenseNo : '',
                    facility.capacity ? 'Capacity ' + facility.capacity : '',
                    /^No longer listed/.test(facility.status) ? facility.status : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var facts = [];
            if (report.announced) facts.push(report.announced + ' visit');
            if (report.licensor) facts.push('Licensing coordinator: ' + report.licensor);
            if (report.capDue) facts.push('Corrective action plan due ' + dateText(ctx, report.capDue));
            if (report.capAccepted) facts.push('Corrective action accepted ' + dateText(ctx, report.capAccepted));
            var first = '';
            report.items.some(function (i) { first = i.observations; return !!first; });
            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || 'Date unknown',
                type: typeLabel(report),
                tone: reportTone(report),
                badges: reportBadges(report, ctx),
                facts: facts,
                link: { href: report.report_url, text: 'State licensing page' },
                links: documentLinks(report, ctx),
                preview: first ? first.split('\n')[0] : '',
                // Built up front, not on open: the bodies are small, and
                // severe-flags.js finds a severe finding by the text on the page.
                body: reportBody(report, ctx)
            };
        }
    });
}());
