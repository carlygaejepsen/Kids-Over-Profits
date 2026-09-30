/**
 * Michigan licensing reports (/mi-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=MI: MDHHS Division of Child
 * Welfare Licensing documents for child caring institutions, court operated
 * facilities and therapeutic group homes (mi_scraper.py in the Tools repo).
 * Four kinds of report, in categories.doc_type:
 *   special_investigation  a complaint investigation: per allegation the rule,
 *                          the allegation, the interviews, an analysis and a
 *                          conclusion ("Violation Established", "Violation Not
 *                          Established", "Repeat Violation Established ...").
 *   renewal, interim,      inspections; the cover letter says whether a
 *   original               corrective action plan is required and the report
 *                          lists the rules cited, or says the agency was found
 *                          in compliance.
 * The scraper reads all of that from the PDF into categories, so the list
 * loads without the text (?lite=1) and the text is fetched when a report opens.
 *
 * What counts as a violation: a special investigation with at least one
 * allegation whose conclusion establishes a violation, or an inspection that
 * requires a corrective action plan or cites a rule. A special investigation
 * that established nothing is shown as clean, not hidden: the allegation and
 * the interviews are public record.
 *
 * The state has no direct link to a document (it serves the PDF only through
 * its search page's script), so the link to the state is the facility's page
 * on its licensing search, and the archived copy on this site is the report.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Michigan reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var TYPE_LABELS = {
        special_investigation: 'Special investigation',
        renewal: 'Renewal inspection',
        interim: 'Interim inspection',
        original: 'Original licensing study',
        other: 'Licensing report'
    };

    // "2024-03-19" -> local Date; anything else through Date.
    function parseDate(value) {
        var m = safeString(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
        var d = new Date(safeString(value));
        return isNaN(d.getTime()) ? new Date(0) : d;
    }

    function established(conclusion) {
        var c = safeString(conclusion).toLowerCase();
        return c.indexOf('established') !== -1 && c.indexOf('not established') === -1;
    }

    function isRepeat(conclusion) {
        return established(conclusion) && /repeat/i.test(conclusion);
    }

    function convertApiDataToFacilities(apiFacilities) {
        return (apiFacilities || []).map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var allegations = Array.isArray(cats.allegations) ? cats.allegations : [];
                return {
                    report_id:     safeString(report.report_id),
                    report_date:   safeString(report.report_date),
                    report_url:    safeString(report.report_url),
                    raw_content:   String(report.raw_content || ''),
                    has_text:      report.has_text !== undefined ? !!report.has_text : !!report.raw_content,
                    row_id:        report.row_id || null,
                    doc_type:      TYPE_LABELS[cats.doc_type] ? cats.doc_type : 'other',
                    title:         safeString(cats.title),
                    si_number:     safeString(cats.si_number),
                    intake_date:   safeString(cats.intake_date),
                    inspection_date: safeString(cats.inspection_date),
                    licensee:      safeString(cats.licensee),
                    capacity:      safeString(cats.capacity),
                    cap_required:  !!cats.cap_required,
                    outcome:       safeString(cats.outcome),
                    allegations:   allegations,
                    established:   parseInt(cats.violations_established, 10) || 0,
                    repeat:        allegations.some(function (a) { return isRepeat(a.conclusion); }),
                    cited_rules:   Array.isArray(cats.cited_rules) ? cats.cited_rules : [],
                    violations:    Array.isArray(cats.violations) ? cats.violations : [],
                    recommendation: safeString(cats.recommendation),
                    archive_name:  safeString(cats.archive_name)
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            var licensee = '';
            reports.some(function (r) { licensee = r.licensee; return !!licensee; });

            return {
                name:     safeString(info.facility_name),
                license:  safeString(info.program_name),
                category: safeString(info.program_category),
                address:  safeString(info.full_address),
                phone:    safeString(info.phone),
                director: safeString(info.executive_director),
                capacity: safeString(info.bed_capacity),
                status:   safeString(info.action),
                licensee: licensee,
                reports:  reports
            };
        });
    }

    function isInspection(report) {
        return report.doc_type !== 'special_investigation';
    }

    function isFlagged(report) {
        if (report.established > 0) return true;
        return isInspection(report) && (report.cap_required || report.cited_rules.length > 0);
    }

    // "Child Caring Institution: Private" -> "Private child caring institution"
    function categoryLabel(category) {
        var m = safeString(category).match(/^Child Caring Institution:\s*(.+)$/i);
        if (!m) return safeString(category);
        var kind = m[1].trim();
        if (/^Therapeutic Group Home$/i.test(kind)) return 'Therapeutic group home';
        if (/^MDHHS$/i.test(kind)) return 'State-run (MDHHS) child caring institution';
        if (/^Government Non-MDHHS$/i.test(kind)) return 'Government child caring institution';
        return kind + ' child caring institution';
    }

    function reportBadges(report, ctx) {
        var badges = [];
        if (report.doc_type === 'special_investigation') {
            if (report.established) {
                badges.push({ text: ctx.plural(report.established, 'violation') + ' established', tone: 'flagged' });
                if (report.repeat) badges.push({ text: 'Repeat violation', tone: 'repeat' });
            } else if (report.allegations.length) {
                badges.push({ text: 'No violations established', tone: 'clean' });
            }
            return badges;
        }
        if (report.cited_rules.length) {
            badges.push({ text: ctx.plural(report.cited_rules.length, 'rule') + ' cited', tone: 'flagged' });
        }
        if (report.cap_required) badges.push({ text: 'Corrective action plan required', tone: 'flagged' });
        if (!badges.length) badges.push({ text: 'In compliance', tone: 'clean' });
        return badges;
    }

    function allegationFinding(a) {
        var done = established(a.conclusion);
        // "Repeat Violation Established SIR 2024SIC0001561 dated 8/14/2024, ..."
        var head = safeString(a.conclusion).match(/^(Repeat\s+)?Violation\s+(?:Not\s+)?Established/i);
        var chip = head ? head[0] : safeString(a.conclusion);
        var more = [];
        if (a.conclusion_note) more.push({ title: 'Earlier reports cited', paragraphs: [a.conclusion_note] });
        return ui.finding({
            title: safeString(a.rule_title) || safeString(a.rule) || 'Allegation',
            citation: a.rule_title ? safeString(a.rule) : '',
            chips: [{ text: chip || 'No conclusion given', tone: done ? (isRepeat(a.conclusion) ? 'repeat' : 'flagged') : 'clean' }],
            evidence: [safeString(a.allegation)],
            more: more,
            tone: done ? 'flagged' : 'clean'
        });
    }

    function reportBody(report, ctx) {
        var html = '';
        if (report.outcome) html += ui.paragraphs([report.outcome]);
        if (report.allegations.length) {
            html += ui.heading(ctx.plural(report.allegations.length, 'allegation'));
            html += report.allegations.map(allegationFinding).join('');
            html += ui.note('The interviews and the investigator’s analysis of each allegation are in the full text below.');
        } else if (report.doc_type === 'special_investigation') {
            html += ui.note('The allegations in this report could not be read into a list; they are in the full text below.');
        }
        if (isInspection(report) && report.cited_rules.length) {
            var titles = {};
            report.violations.forEach(function (v) { titles[v.rule] = safeString(v.title); });
            html += ui.heading(ctx.plural(report.cited_rules.length, 'rule') + ' cited');
            html += report.cited_rules.map(function (rule) {
                return ui.finding({ title: titles[rule] || 'Rule ' + rule, citation: 'R ' + rule, tone: 'flagged' });
            }).join('');
            html += ui.note('What the inspector found for each rule is in the full text below.');
        }
        if (report.recommendation) html += ui.section('Recommendation', ui.paragraphs([report.recommendation]));
        html += ui.section('Full report text', ui.docText(report.raw_content));
        return html;
    }

    page.mount({
        state: 'Michigan',
        archiveState: 'MI',
        emptyMessage: 'No facilities found in the database for Michigan.',

        filters: [{
            id: 'type',
            label: 'Report type:',
            options: [
                { value: 'ALL', label: 'All report types' },
                { value: 'special_investigation', label: 'Special investigations' },
                { value: 'inspection', label: 'Inspections and licensing studies' }
            ],
            test: function (f, value) {
                return f.reports.some(function (r) { return reportType(r) === value; });
            },
            testReport: function (r, value) { return reportType(r) === value; }
        }, {
            id: 'agency',
            label: 'Facility type:',
            options: [
                { value: 'ALL', label: 'All facility types' },
                { value: 'private', label: 'Private institutions' },
                { value: 'court', label: 'Court operated facilities' },
                { value: 'government', label: 'State and government institutions' },
                { value: 'group', label: 'Therapeutic group homes' }
            ],
            test: function (f, value) { return agencyKind(f.category) === value; }
        }],

        load: function () {
            // lite: no document text in the list; withText() fetches a
            // report's text when it is opened.
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=MI&lite=1')
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
            return [f.name, f.license, f.category, f.address, f.licensee, f.director]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        countFlagged: function (f) {
            return f.reports.reduce(function (sum, r) {
                return sum + (r.established || (isInspection(r) && isFlagged(r) ? Math.max(1, r.cited_rules.length) : 0));
            }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var investigations = reports.filter(function (r) { return r.doc_type === 'special_investigation'; });
            var established = investigations.reduce(function (sum, r) { return sum + r.established; }, 0);
            var caps = reports.filter(function (r) { return isInspection(r) && r.cap_required; }).length;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'report'), tone: 'neutral' }];
            if (investigations.length) stats.push({ text: ctx.plural(investigations.length, 'investigation'), tone: 'neutral' });
            if (established) stats.push({ text: ctx.plural(established, 'violation') + ' established', tone: 'flagged' });
            else if (investigations.length) stats.push({ text: 'No violations established', tone: 'clean' });
            if (caps) stats.push({ text: ctx.plural(caps, 'corrective action plan') + ' required', tone: 'flagged' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [
                    categoryLabel(facility.category),
                    facility.license ? 'License ' + facility.license : '',
                    facility.licensee && facility.licensee !== facility.name ? 'Licensee: ' + facility.licensee : '',
                    facility.capacity ? 'Capacity ' + facility.capacity : '',
                    /^No longer listed/.test(facility.status) ? facility.status : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var badges = reportBadges(report, ctx);
            var tone = isFlagged(report) ? 'flagged' : (badges.length && badges[0].tone === 'clean' ? 'clean' : 'neutral');
            var facts = [];
            if (report.si_number) facts.push('SI #' + report.si_number);
            if (report.intake_date) facts.push('Complaint received ' + ctx.formatDate(parseDate(report.intake_date).getTime()));
            if (report.inspection_date && isInspection(report)) {
                facts.push('Inspected ' + ctx.formatDate(parseDate(report.inspection_date).getTime()));
            }
            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || 'Date unknown',
                type: TYPE_LABELS[report.doc_type],
                tone: tone,
                badges: badges,
                facts: facts,
                link: { href: report.report_url, text: 'State licensing page' },
                links: [ctx.archiveLink(report.archive_name, 'Report (archived copy)')],
                preview: report.outcome,
                body: function () { return page.withText(report, 'MI', function () { return reportBody(report, ctx); }); }
            };
        }
    });

    function reportType(r) {
        return r.doc_type === 'special_investigation' ? 'special_investigation' : 'inspection';
    }

    function agencyKind(category) {
        var c = safeString(category);
        if (/^Court Operated/i.test(c)) return 'court';
        if (/Therapeutic Group Home/i.test(c)) return 'group';
        if (/Private/i.test(c)) return 'private';
        return 'government';
    }
}());
