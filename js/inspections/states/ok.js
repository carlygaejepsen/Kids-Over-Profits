/**
 * Oklahoma monitoring reports (/ok-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=OK: OKDHS Child Care Services
 * monitoring of licensed residential programs and shelters (ok_scraper.py in
 * the Tools repo). Each program's page on the state site lists every
 * monitoring visit and every substantiated complaint; the scraper posts one
 * report per visit and one per complaint, with the items in categories:
 *   kind        'visit' or 'complaint'
 *   visit_type  Full, Partial or Attempted (visits)
 *   purpose     Periodic, Permit, Application, Follow Up, Complaint, Other
 *   items       [{ requirement, description, observed, plan,
 *                  correction_date, nrs, finding }]
 * For a complaint, observed is the allegation and finding is "Substantiated"
 * or "Determined During Course of Investigation" (a further non-compliance
 * found while investigating). There is no document text beyond that, so the
 * list loads in full (no ?lite=1).
 *
 * What counts as a violation: a visit with at least one non-compliance, or any
 * complaint (the state publishes only substantiated ones). An item the state
 * marks NRS ("Numerous, Repeated and/or Serious") is shown as a repeat. A visit
 * with nothing found is clean; an attempted visit did not take place and is
 * neutral (the state records nothing from it).
 *
 * The state shows only the last 36 months, and refers anything that rises to
 * abuse or neglect to Child Welfare Services without publishing it here, so an
 * empty complaint list does not mean there were no complaints. Visits that have
 * aged out of the state's window stay on this site.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Oklahoma reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var WINDOW_NOTE = 'Oklahoma shows only the last 36 months of monitoring on its site; older visits stay here.';
    var COMPLAINT_NOTE = 'Oklahoma publishes only substantiated complaints. Complaints that rise to the level of abuse or neglect go to Child Welfare Services and are not published, so a program with no complaints listed may still have had them.';

    // "2024-03-19" -> local Date; anything else through Date.
    function parseDate(value) {
        var m = safeString(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
        var d = new Date(safeString(value));
        return isNaN(d.getTime()) ? new Date(0) : d;
    }

    function convertApiDataToFacilities(apiFacilities) {
        return (apiFacilities || []).map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var items = (Array.isArray(cats.items) ? cats.items : []).map(function (item) {
                    return {
                        requirement:     safeString(item.requirement),
                        description:     safeString(item.description),
                        observed:        safeString(item.observed),
                        plan:            safeString(item.plan),
                        correction_date: safeString(item.correction_date),
                        nrs:             !!item.nrs,
                        finding:         safeString(item.finding)
                    };
                });
                return {
                    report_id:   safeString(report.report_id),
                    report_date: safeString(report.report_date),
                    report_url:  safeString(report.report_url),
                    kind:        cats.kind === 'complaint' ? 'complaint' : 'visit',
                    visit_type:  safeString(cats.visit_type),
                    purpose:     safeString(cats.purpose),
                    items:       items,
                    nrs:         items.filter(function (i) { return i.nrs; }).length
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            return {
                name:     safeString(info.facility_name),
                caseNo:   safeString(info.program_name),
                category: safeString(info.program_category),
                address:  safeString(info.full_address),
                phone:    safeString(info.phone),
                director: safeString(info.executive_director),
                capacity: safeString(info.bed_capacity),
                status:   safeString(info.action),
                reports:  reports
            };
        });
    }

    function isAttempted(report) {
        return report.kind === 'visit' && /^attempted$/i.test(report.visit_type);
    }

    function isFlagged(report) {
        return report.kind === 'complaint' || report.items.length > 0;
    }

    function reportTone(report) {
        if (report.nrs) return 'repeat';
        if (isFlagged(report)) return 'flagged';
        return isAttempted(report) ? 'neutral' : 'clean';
    }

    function typeLabel(report) {
        if (report.kind === 'complaint') return 'Substantiated complaint';
        return (report.visit_type || 'Monitoring') + ' visit';
    }

    // "Residential: Residential Treatment" -> "Residential Treatment"; the
    // program's own kind for the filter and the summary line.
    function subtype(category) {
        var c = safeString(category);
        var m = c.match(/^[^:]+:\s*(.+)$/);
        return m ? m[1].trim() : c;
    }

    function categoryLabel(category) {
        var c = safeString(category);
        if (/^Shelter/i.test(c)) return 'Shelter';
        var kind = subtype(c);
        if (!kind || /^Residential$/i.test(kind)) return 'Residential program';
        if (/^Residential/i.test(kind)) return kind.charAt(0) + kind.slice(1).toLowerCase() + ' program';
        return kind.charAt(0) + kind.slice(1).toLowerCase() + ' residential program';
    }

    function formatIso(ctx, value) {
        var ms = parseDate(value).getTime();
        return ms > 0 ? ctx.formatDate(ms) : safeString(value);
    }

    // "References.  The program obtains three references ..." -> "References"
    // as the title, the whole text as what the rule requires.
    function itemTitle(item) {
        var text = item.description;
        if (!text) return item.requirement || 'Requirement';
        var first = text.match(/^(.{3,80}?)\.(?:\s|$)/);
        if (first && first[1].length < text.length - 1) return first[1];
        if (text.length <= 90) return text.replace(/[.;:,]\s*$/, '');
        var cut = text.slice(0, 80);
        return cut.slice(0, cut.lastIndexOf(' ')).replace(/[\s.;:,]+$/, '') + '\u2026';
    }

    function sameText(a, b) {
        var norm = function (s) { return safeString(s).replace(/[\s.;:,]+$/, '').toLowerCase(); };
        return norm(a) === norm(b);
    }

    function itemFinding(report, item, ctx) {
        var chips = [];
        if (item.nrs) chips.push({ text: 'Numerous, repeated or serious', tone: 'repeat' });
        if (report.kind === 'complaint' && item.finding) {
            chips.push({
                text: /course of investigation/i.test(item.finding) ? 'Found during the investigation' : item.finding,
                tone: 'flagged'
            });
        }
        var title = itemTitle(item);
        title = title.charAt(0).toUpperCase() + title.slice(1);
        var plan = [];
        if (item.plan) plan.push(item.plan);
        if (item.correction_date) plan.push('Correction date: ' + formatIso(ctx, item.correction_date));
        return ui.finding({
            title: title,
            citation: item.requirement,
            chips: chips,
            evidence: [item.observed],
            requirement: sameText(title, item.description) ? [] : [item.description],
            more: plan.length ? [{ title: 'Plan to correct', paragraphs: plan }] : [],
            tone: item.nrs ? 'repeat' : 'flagged'
        });
    }

    function reportBody(report, ctx) {
        var html = '';
        if (report.kind === 'complaint') {
            html += ui.heading(ctx.plural(report.items.length, 'requirement') + ' found not met');
            html += report.items.map(function (item) { return itemFinding(report, item, ctx); }).join('');
            html += ui.note(COMPLAINT_NOTE);
            return html;
        }
        if (isAttempted(report)) {
            html += ui.note('The state lists this as an attempted visit and records nothing from it.');
        } else if (!report.items.length) {
            html += ui.note('No non-compliances observed during this visit.');
        } else {
            html += ui.heading(ctx.plural(report.items.length, 'non-compliance') + ' observed');
            html += report.items.map(function (item) { return itemFinding(report, item, ctx); }).join('');
        }
        html += ui.note(WINDOW_NOTE);
        return html;
    }

    function reportBadges(report, ctx) {
        if (report.kind === 'complaint') {
            var extra = report.items.filter(function (i) { return /course of investigation/i.test(i.finding); }).length;
            var badges = [{ text: ctx.plural(report.items.length, 'requirement') + ' not met', tone: 'flagged' }];
            if (extra) badges.push({ text: extra + ' found during the investigation', tone: 'flagged' });
            return badges;
        }
        if (isAttempted(report)) return [{ text: 'Not carried out', tone: 'neutral' }];
        if (!report.items.length) return [{ text: 'No non-compliances observed', tone: 'clean' }];
        var list = [{ text: ctx.plural(report.items.length, 'non-compliance'), tone: 'flagged' }];
        if (report.nrs) list.push({ text: report.nrs + ' numerous, repeated or serious', tone: 'repeat' });
        return list;
    }

    page.mount({
        state: 'Oklahoma',
        emptyMessage: 'No facilities found in the database for Oklahoma.',

        filters: [{
            id: 'kind',
            label: 'Report type:',
            options: [
                { value: 'ALL', label: 'Visits and complaints' },
                { value: 'visit', label: 'Monitoring visits' },
                { value: 'complaint', label: 'Substantiated complaints' }
            ],
            test: function (f, value) {
                return f.reports.some(function (r) { return r.kind === value; });
            },
            testReport: function (r, value) { return r.kind === value; }
        }, {
            id: 'subtype',
            label: 'Program type:',
            options: [
                { value: 'ALL', label: 'All program types' },
                { value: 'residential', label: 'Residential' },
                { value: 'treatment', label: 'Residential treatment' },
                { value: 'family', label: 'Family style' },
                { value: 'shelter', label: 'Shelters' }
            ],
            // Regimented residential and secure care exist as subtypes but no
            // program held either on 2026-09-30; they fall under Residential.
            test: function (f, value) { return programKind(f.category) === value; }
        }],

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=OK')
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
            return [f.name, f.caseNo, f.category, f.address, f.director]
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
            var visits = reports.filter(function (r) { return r.kind === 'visit'; });
            var complaints = reports.length - visits.length;
            var items = visits.reduce(function (sum, r) { return sum + r.items.length; }, 0);
            var nrs = visits.reduce(function (sum, r) { return sum + r.nrs; }, 0);
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [];
            if (visits.length) stats.push({ text: ctx.plural(visits.length, 'visit'), tone: 'neutral' });
            if (items) stats.push({ text: ctx.plural(items, 'non-compliance'), tone: 'flagged' });
            else if (visits.length) stats.push({ text: 'No non-compliances observed', tone: 'clean' });
            if (nrs) stats.push({ text: nrs + ' numerous, repeated or serious', tone: 'repeat' });
            if (complaints) stats.push({ text: ctx.plural(complaints, 'substantiated complaint'), tone: 'flagged' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [
                    categoryLabel(facility.category),
                    facility.caseNo ? 'Case ' + facility.caseNo : '',
                    facility.capacity ? 'Capacity ' + facility.capacity : '',
                    facility.director ? 'Director: ' + facility.director : '',
                    /^No longer listed/.test(facility.status) ? facility.status : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var facts = [];
            if (report.kind === 'visit' && report.purpose) facts.push('Purpose: ' + report.purpose);
            if (report.kind === 'complaint') facts.push('Complaint received ' + formatIso(ctx, report.report_date));
            var first = null;
            report.items.some(function (i) { first = i.observed ? i : null; return !!first; });
            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || 'Date unknown',
                type: typeLabel(report),
                tone: reportTone(report),
                badges: reportBadges(report, ctx),
                facts: facts,
                link: { href: report.report_url, text: 'State monitoring page' },
                preview: first ? first.observed : '',
                // Built up front, not on open: the bodies are small, and
                // severe-flags.js finds a severe finding by the text on the page.
                body: reportBody(report, ctx)
            };
        }
    });

    function programKind(category) {
        var c = safeString(category);
        if (/^Shelter/i.test(c)) return 'shelter';
        var kind = subtype(c).toLowerCase();
        if (/treatment/.test(kind)) return 'treatment';
        if (/family/.test(kind)) return 'family';
        return 'residential';
    }
}());
