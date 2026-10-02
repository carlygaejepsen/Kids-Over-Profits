/**
 * Ohio compliance reports (/oh-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=OH: the Department of
 * Children and Youth's compliance review reports for agencies that run group
 * homes, children's residential centres and the other certified residential
 * facilities (oh_scraper.py in the Tools repo).
 *
 * A report belongs to the AGENCY, not to one of its facilities, so each row on
 * this page is an agency. Its facilities travel in every report
 * (categories.facilities) and are part of the search text, so a search for a
 * group home's own name finds the agency that runs it. An agency-level review
 * also covers the agency's foster care and adoption work when it does any, so
 * findings from the foster care tools sit beside the residential ones; the
 * scraper marks a finding residential when its review tool is a residential
 * one (Child in Residential, On-Site Residential) or every rule it cites is in
 * chapter 5180:2-9, the residential facility rules.
 *
 * One report is one review number (AR-00001359): the state's main PDF and its
 * "Additional Findings" PDF read together. Three review types, as the state
 * names them: Full, Focused and Other. Nothing in an "Other" review says what
 * prompted it, so it is never called a complaint here.
 *
 * What counts as a violation: a report with at least one finding of
 * noncompliance, which is a review question answered "N" for at least one
 * record. That covers the state's "CAP Needed" and "CAP Not Needed" summaries
 * (both are findings of noncompliance; the first requires a corrective action
 * plan) and the "N" rows of an Additional Findings file. Technical assistance
 * items are advice, not findings: they are listed in the report and never
 * flag it. A main file with only a heading and no Additional Findings file is
 * shown as "No findings published", not as clean.
 *
 * The list loads without document text (?lite=1); the text and the technical
 * assistance items (categories.detail) are fetched when a report opens.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Ohio reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var REVIEW_TYPES = { Full: 'Full review', Focused: 'Focused review', Other: 'Other review' };
    var FACILITIES_IN_SUMMARY = 4;

    // "2025-09-24" -> local Date; anything else through Date.
    function parseDate(value) {
        var m = safeString(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
        var d = new Date(safeString(value));
        return isNaN(d.getTime()) ? new Date(0) : d;
    }

    function list(value) {
        return Array.isArray(value) ? value : [];
    }

    function count(value) {
        return parseInt(value, 10) || 0;
    }

    function convertApiDataToFacilities(apiFacilities) {
        return (apiFacilities || []).map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var findings = list(cats.findings);
                return {
                    report_id:      safeString(report.report_id),
                    report_date:    safeString(report.report_date),
                    report_url:     safeString(report.report_url),
                    raw_content:    String(report.raw_content || ''),
                    has_text:       report.has_text !== undefined ? !!report.has_text : !!report.raw_content,
                    row_id:         report.row_id || null,
                    detail:         cats.detail || null,
                    review_type:    REVIEW_TYPES[cats.review_type] ? cats.review_type : '',
                    review_number:  safeString(cats.review_number) || safeString(report.report_id),
                    universe_period: safeString(cats.universe_period),
                    specialist:     safeString(cats.specialist),
                    date_basis:     safeString(cats.date_basis),
                    findings:       findings,
                    finding_count:  findings.length || count(cats.finding_count),
                    cap_count:      count(cats.cap_finding_count),
                    residential_count: count(cats.residential_finding_count),
                    assistance_count: count(cats.assistance_count),
                    tools:          list(cats.tools),
                    has_additional: !!cats.has_additional,
                    stub:           !!cats.stub,
                    facilities:     list(cats.facilities),
                    archive_names:  list(cats.archive_names),
                    additional_url: safeString(cats.additional_url)
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            // Every report carries the agency's facilities as they were listed
            // when it was scraped; the newest report's list is the current one.
            var facilities = [];
            reports.some(function (r) { facilities = r.facilities; return facilities.length > 0; });

            return {
                name:       safeString(info.facility_name),
                number:     safeString(info.program_name),
                category:   safeString(info.program_category),
                address:    safeString(info.full_address),
                status:     safeString(info.action),
                facilities: facilities,
                reports:    reports
            };
        });
    }

    function isFlagged(report) {
        return report.finding_count > 0;
    }

    // "Group Home" -> "group home"; the state's type names are title case.
    function typeLabel(type) {
        return safeString(type).toLowerCase();
    }

    function facilityLine(f) {
        var bits = [safeString(f.type), safeString(f.address) || (f.county ? safeString(f.county) + ' County' : '')]
            .filter(Boolean).join(', ');
        return safeString(f.name) + (bits ? ' (' + bits + ')' : '') + (f.suspended ? ' -- active suspension' : '');
    }

    // "Runs 3 group homes and 1 child residential center"
    function facilityCounts(facilities) {
        var counts = {};
        var order = [];
        facilities.forEach(function (f) {
            var type = typeLabel(f.type) || 'residential facility';
            if (!counts[type]) { counts[type] = 0; order.push(type); }
            counts[type] += 1;
        });
        return order.map(function (type) {
            var n = counts[type];
            var many = /y$/.test(type) ? type.replace(/y$/, 'ies') : (/s$/.test(type) ? type + 'es' : type + 's');
            return n + ' ' + (n === 1 ? type : many);
        }).join(', ');
    }

    function facilityNames(facilities) {
        var names = facilities.map(function (f) { return safeString(f.name); }).filter(Boolean);
        var shown = names.slice(0, FACILITIES_IN_SUMMARY).join('; ');
        if (names.length > FACILITIES_IN_SUMMARY) shown += '; and ' + (names.length - FACILITIES_IN_SUMMARY) + ' more';
        return shown;
    }

    // "32. If due during ..." -> "If due during ..."
    function questionText(question) {
        return safeString(question).replace(/^\d{1,3}\s*\.?\s*(?=[A-Z])/, '');
    }

    // Additional findings carry the whole rule text, sometimes the same sentence
    // for each variant of the rule. The row shows the start; the full text is in
    // the report text below.
    function shortTitle(question) {
        var text = questionText(question);
        if (text.length <= 240) return text;
        return text.slice(0, 240).replace(/\s+\S*$/, '') + '\u2026';
    }

    function commentLines(comments) {
        return list(comments).map(function (c) {
            var reason = safeString(c.reason);
            var text = safeString(c.comment);
            var head = 'Record ' + safeString(c.record) + (reason && reason !== 'Other' ? ' (' + reason + ')' : '');
            return text ? head + ': ' + text : (reason ? head : '');
        }).filter(Boolean);
    }

    function findingBlock(f) {
        var chips = [];
        if (f.tool) chips.push({ text: safeString(f.tool), tone: 'neutral' });
        var n = count(f.n_count);
        var reviewed = count(f.reviewed);
        if (n) {
            chips.push({
                text: reviewed ? n + ' of ' + reviewed + ' not in compliance' : n + ' not in compliance',
                tone: 'flagged'
            });
        }
        if (f.cap_needed === true) chips.push({ text: 'Corrective action plan needed', tone: 'flagged' });
        else if (f.cap_needed === false) chips.push({ text: 'No plan required', tone: 'neutral' });
        if (f.source === 'additional') chips.push({ text: 'Additional finding', tone: 'neutral' });
        return ui.finding({
            title: shortTitle(f.question) || 'Finding of noncompliance',
            citation: safeString(f.rule),
            chips: chips,
            evidence: commentLines(f.comments),
            tone: 'flagged'
        });
    }

    function assistanceBlock(a) {
        var chips = [];
        if (a.tool) chips.push({ text: safeString(a.tool), tone: 'neutral' });
        if (count(a.count)) chips.push({ text: count(a.count) + (count(a.count) === 1 ? ' record' : ' records'), tone: 'neutral' });
        return ui.finding({
            title: shortTitle(a.question) || 'Technical assistance',
            citation: safeString(a.rule),
            chips: chips,
            evidence: commentLines(a.comments),
            tone: 'neutral'
        });
    }

    function reportBody(report, ctx) {
        var html = '';
        var detail = report.detail || {};
        var assistance = list(detail.technical_assistance);

        if (report.tools.length) {
            html += ui.paragraphs(['Records reviewed: ' + report.tools.map(function (t) {
                var n = count(t.records);
                return safeString(t.tool) + (n ? ' (' + n + ')' : '');
            }).join(', ') + '.']);
        }

        if (report.findings.length) {
            html += ui.heading(ctx.plural(report.findings.length, 'finding') + ' of noncompliance');
            html += report.findings.map(findingBlock).join('');
            if (report.findings.some(function (f) { return f.source === 'additional'; })) {
                html += ui.note('The state gives no per-record comments for additional findings.');
            }
        } else if (report.stub) {
            html += ui.note('The file the state published for this review holds only the agency name and the period reviewed. No findings were published with it.');
        } else {
            html += ui.note('This review lists no findings of noncompliance.');
        }

        if (assistance.length) {
            html += ui.section(
                'Technical assistance (' + assistance.length + ')',
                ui.note('Advice the licensing specialist gave the agency. The state requires no corrective action plan for these items.')
                    + assistance.map(assistanceBlock).join('')
            );
        }

        if (report.review_type === 'Other') {
            html += ui.note('The state does not say what prompts an "Other" review.');
        }
        html += ui.note('This review covers the whole agency. Where the agency also certifies foster or adoptive homes, findings from those tools appear here beside the residential ones, and the report does not say which facility a record came from.');

        if (report.facilities.length) {
            html += ui.section(
                'Facilities this agency runs (' + report.facilities.length + ')',
                ui.paragraphs(report.facilities.map(facilityLine))
            );
        }
        html += ui.section('Full report text', ui.docText(report.raw_content));
        return html;
    }

    function reportBadges(report, ctx) {
        var badges = [];
        if (report.finding_count) {
            badges.push({ text: ctx.plural(report.finding_count, 'finding'), tone: 'flagged' });
            if (report.residential_count) {
                badges.push({ text: report.residential_count + ' residential', tone: 'flagged' });
            }
            if (report.cap_count) badges.push({ text: 'Corrective action plan needed', tone: 'flagged' });
        } else if (report.stub) {
            badges.push({ text: 'No findings published', tone: 'neutral' });
        } else {
            badges.push({ text: 'No findings of noncompliance', tone: 'clean' });
        }
        if (report.assistance_count) {
            badges.push({ text: ctx.plural(report.assistance_count, 'technical assistance item'), tone: 'neutral' });
        }
        return badges;
    }

    var FACILITY_TYPES = [
        'Group Home',
        'Child Residential Center',
        'Child Wellness Campus',
        'Scholars Residential Center',
        'Residential Parenting Facility',
        'Crisis Care Facility',
        'Therapeutic Wilderness Camp',
        'Residential Infant Care Center'
    ];

    // The state's spelling varies ("Children's Residential Center", "Centre"),
    // and some types carry a second name ("Residential Parenting Facility - Group
    // Home", "Private, Non-profit, Therapeutic Wilderness Camp"), so a filter value
    // matches any type name that contains it.
    function typeKey(type) {
        return safeString(type).toLowerCase().replace(/children'?s/g, 'child').replace(/centre/g, 'center')
            .replace(/[^a-z]+/g, ' ').replace(/s\b/g, '').trim();
    }

    page.mount({
        state: 'Ohio',
        archiveState: 'OH',
        emptyMessage: 'No agencies found in the database for Ohio.',

        filters: [{
            id: 'review',
            label: 'Review type:',
            options: [
                { value: 'ALL', label: 'All review types' },
                { value: 'Full', label: 'Full reviews' },
                { value: 'Focused', label: 'Focused reviews' },
                { value: 'Other', label: 'Other reviews' }
            ],
            test: function (f, value) {
                return f.reports.some(function (r) { return r.review_type === value; });
            },
            testReport: function (r, value) { return r.review_type === value; }
        }, {
            id: 'facility-type',
            label: 'Facility type:',
            options: [{ value: 'ALL', label: 'All facility types' }].concat(FACILITY_TYPES.map(function (type) {
                return { value: typeKey(type), label: type };
            })),
            test: function (f, value) {
                return f.facilities.some(function (x) { return typeKey(x.type).indexOf(value) !== -1; });
            }
        }],

        load: function () {
            // lite: no document text in the list; withText() fetches a
            // report's text and its technical assistance items when it opens.
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=OH&lite=1')
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

        // The agency and every facility it runs: a group home's own name,
        // street or county finds its agency.
        searchText: function (f) {
            var parts = [f.name, f.number, f.address];
            f.facilities.forEach(function (x) { parts.push(x.name, x.type, x.address, x.county); });
            return parts.map(function (v) { return safeString(v).toLowerCase(); }).join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        countFlagged: function (f) {
            return f.reports.reduce(function (sum, r) { return sum + r.finding_count; }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var findings = reports.reduce(function (sum, r) { return sum + r.finding_count; }, 0);
            var residential = reports.reduce(function (sum, r) { return sum + r.residential_count; }, 0);
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);
            var published = reports.some(function (r) { return !r.stub; });

            var stats = [{ text: ctx.plural(reports.length, 'review'), tone: 'neutral' }];
            if (findings) {
                stats.push({ text: ctx.plural(findings, 'finding') + ' of noncompliance', tone: 'flagged' });
                if (residential) stats.push({ text: residential + ' residential', tone: 'flagged' });
            } else if (published) {
                stats.push({ text: 'No findings of noncompliance', tone: 'clean' });
            }
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            var runs = facility.facilities.length ? 'Runs ' + facilityCounts(facility.facilities) : '';
            return {
                meta: [
                    'Agency ' + facility.number,
                    runs,
                    facility.facilities.length ? 'Facilities: ' + facilityNames(facility.facilities) : '',
                    facility.status && facility.status !== 'Certified' ? facility.status : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var facts = ['Review ' + report.review_number];
            if (report.universe_period) facts.push('Period reviewed: ' + report.universe_period);
            if (report.specialist) facts.push('Licensing specialist: ' + report.specialist);
            if (report.date_basis === 'universe_end') facts.push('Dated by the end of the period reviewed');

            var links = [];
            if (report.additional_url) links.push({ href: report.additional_url, text: 'Additional findings (state copy)' });
            report.archive_names.forEach(function (name) {
                links.push(ctx.archiveLink(name, /-additional\.pdf$/i.test(name)
                    ? 'Additional findings (archived copy)' : 'Report (archived copy)'));
            });

            var first = report.findings[0];
            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || 'Date unknown',
                type: REVIEW_TYPES[report.review_type] || 'Compliance review',
                tone: isFlagged(report) ? 'flagged' : (report.stub ? 'neutral' : 'clean'),
                badges: reportBadges(report, ctx),
                facts: facts,
                link: { href: report.report_url, text: 'Report (state copy)' },
                links: links,
                preview: first ? shortTitle(first.question) : '',
                body: function () { return page.withText(report, 'OH', function () { return reportBody(report, ctx); }); }
            };
        }
    });
}());
