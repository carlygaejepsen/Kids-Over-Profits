/**
 * Virginia licensing reports (/va-reports/) -- adapter for report-page.js.
 *
 * The scraper combines VDSS children's residential facility inspections with
 * DBHDS youth residential service inspections and investigations. Each row is
 * one state licence or service licence; the report source and type stay
 * visible because the two agencies publish different records.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Virginia reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    function list(value) {
        return Array.isArray(value) ? value : [];
    }

    function count(value) {
        var parsed = parseInt(value, 10);
        return isNaN(parsed) ? 0 : parsed;
    }

    function parseDate(value) {
        var match = safeString(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (match) return new Date(+match[1], +match[2] - 1, +match[3]);
        var date = new Date(safeString(value));
        return isNaN(date.getTime()) ? new Date(0) : date;
    }

    function source(report) {
        return report.source === 'dbhds' ? 'DBHDS' : 'VDSS';
    }

    function kind(report) {
        return report.kind === 'investigation' ? 'Investigation' : 'Inspection';
    }

    function findingCount(report) {
        return report.source === 'dbhds'
            ? count(report.citation_count)
            : count(report.violation_count);
    }

    function isFlagged(report) {
        return findingCount(report) > 0;
    }

    function convertApiDataToFacilities(apiFacilities) {
        return list(apiFacilities).map(function (facility) {
            var info = facility.facility_info || {};
            var reports = list(facility.reports).map(function (report) {
                var categories = report.categories || {};
                return {
                    report_id: safeString(report.report_id),
                    row_id: report.row_id || null,
                    report_date: safeString(report.report_date),
                    report_url: safeString(report.report_url),
                    raw_content: safeString(report.raw_content),
                    has_text: report.has_text !== undefined ? !!report.has_text : !!report.raw_content,
                    summary: safeString(report.summary),
                    source: categories.source === 'dbhds' ? 'dbhds' : 'vdss',
                    kind: categories.kind === 'investigation' ? 'investigation' : 'inspection',
                    complaint_related: !!categories.complaint_related,
                    inspection_type: safeString(categories.inspection_type),
                    purpose: safeString(categories.purpose),
                    violation_count: count(categories.violation_count),
                    listed_with_violations: !!categories.listed_with_violations,
                    violations: list(categories.violations),
                    citation_count: count(categories.citation_count),
                    citations: list(categories.citations),
                    detail: categories.detail || null,
                    has_cap: !!categories.has_cap,
                    archive_names: list(categories.archive_names).concat(
                        categories.archive_name ? [categories.archive_name] : []
                    ).filter(function (name, index, all) {
                        return all.indexOf(name) === index;
                    }),
                    locations: list(categories.locations),
                    provider: safeString(categories.provider),
                    received: safeString(categories.received),
                    closed: safeString(categories.closed)
                };
            }).sort(function (a, b) {
                return parseDate(b.report_date) - parseDate(a.report_date);
            });

            return {
                name: safeString(info.facility_name),
                number: safeString(info.program_name),
                category: safeString(info.program_category),
                address: safeString(info.full_address),
                phone: safeString(info.phone),
                capacity: safeString(info.bed_capacity),
                contact: safeString(info.executive_director),
                status: safeString(info.action),
                reports: reports
            };
        });
    }

    function violationFinding(violation) {
        var evidence = [];
        if (violation.description) evidence.push(violation.description);
        if (violation.findings) evidence.push('Findings: ' + violation.findings);
        if (violation.plan) evidence.push('Plan of correction: ' + violation.plan);
        return ui.finding({
            title: safeString(violation.standard) || 'Violation',
            citation: safeString(violation.standard),
            evidence: evidence,
            tone: 'flagged'
        });
    }

    function citationFinding(citation, detail) {
        var evidence = [];
        var description = safeString(detail && detail.noncompliance) || safeString(citation.noncompliance);
        if (description) evidence.push(description);
        if (detail && detail.provider_action) evidence.push('Provider action: ' + detail.provider_action);
        if (detail && detail.licensing_response) evidence.push('Office of Licensing response: ' + detail.licensing_response);
        if (citation.response_status) evidence.push('Response status: ' + citation.response_status);
        if (citation.response_date) evidence.push('Response date: ' + citation.response_date);
        if (citation.planned_date) evidence.push('Planned completion: ' + citation.planned_date);
        return ui.finding({
            title: safeString(citation.standard) || 'Citation',
            citation: safeString(detail && detail.standard_text) || safeString(citation.standard),
            chips: citation.comp ? [{ text: safeString(citation.comp), tone: 'flagged' }] : [],
            evidence: evidence,
            tone: 'flagged'
        });
    }

    function reportBody(report) {
        var html = '';
        if (report.source === 'vdss') {
            html += ui.note('VDSS does not publish plans of correction on its inspection pages.');
            if (report.complaint_related) {
                html += ui.note('The state identifies this inspection as complaint-related.');
            }
            if (report.violations.length) {
                html += ui.heading('Violations (' + report.violations.length + ')');
                html += report.violations.map(function (violation) {
                    return violationFinding(violation);
                }).join('');
            } else if (report.listed_with_violations) {
                html += ui.note('The state lists violations for this inspection, but none could be read from the published page.');
            } else {
                html += ui.note('The state lists no violations for this inspection.');
            }
        } else {
            html += ui.note('DBHDS publishes only finalized plans of correction; earlier reports, including records before late 2021, are not available here.');
            if (report.locations.length) {
                html += ui.paragraphs(['Locations: ' + report.locations.join('; ')]);
            }
            if (report.citations.length) {
                html += ui.heading('Citations (' + report.citations.length + ')');
                var details = report.detail ? list(report.detail.citations) : [];
                html += report.citations.map(function (citation, index) {
                    return citationFinding(citation, details[index]);
                }).join('');
            } else if (report.has_cap) {
                html += ui.note('A corrective action plan was published, but no citation could be read from it.');
            } else {
                html += ui.note('No finalized corrective action plan was published for this report.');
            }
        }
        if (report.raw_content) {
            html += ui.section('Full report text', ui.docText(report.raw_content));
        }
        return html;
    }

    function reportBadges(report, ctx) {
        var badges = [{ text: source(report), tone: 'neutral' }];
        if (isFlagged(report)) {
            badges.push({
                text: ctx.plural(findingCount(report), report.source === 'dbhds' ? 'citation' : 'violation'),
                tone: 'flagged'
            });
        } else if (report.source === 'vdss' && report.listed_with_violations) {
            badges.push({ text: 'Violations listed; none read', tone: 'neutral' });
        } else if (report.source === 'dbhds' && report.has_cap) {
            badges.push({ text: 'Plan published; no citation read', tone: 'neutral' });
        } else if (report.source === 'dbhds') {
            badges.push({ text: 'No finalized plan published', tone: 'neutral' });
        } else {
            badges.push({ text: 'No violations listed', tone: 'clean' });
        }
        if (report.complaint_related) badges.push({ text: 'Complaint-related', tone: 'flagged' });
        return badges;
    }

    page.mount({
        state: 'Virginia',
        archiveState: 'VA',
        emptyMessage: 'No facilities found in the database for Virginia.',

        filters: [{
            id: 'source',
            label: 'Agency:',
            options: [
                { value: 'ALL', label: 'All agencies' },
                { value: 'vdss', label: 'VDSS' },
                { value: 'dbhds', label: 'DBHDS' }
            ],
            test: function (facility, value) {
                return facility.reports.some(function (report) { return report.source === value; });
            },
            testReport: function (report, value) { return report.source === value; }
        }, {
            id: 'service',
            label: 'Service type:',
            options: [
                { value: 'ALL', label: 'All service types' },
                { value: 'Psychiatric residential treatment facility (DBHDS)', label: 'Psychiatric residential treatment' },
                { value: 'Therapeutic group home (DBHDS)', label: 'Therapeutic group home' },
                { value: 'Residential crisis stabilization (DBHDS)', label: 'Residential crisis stabilization' },
                { value: 'Substance use residential, ASAM 3.5 (DBHDS)', label: 'Substance use residential (ASAM 3.5)' },
                { value: 'Substance use residential, ASAM 3.1 (DBHDS)', label: 'Substance use residential (ASAM 3.1)' },
                { value: 'Substance use inpatient, ASAM 3.7 (DBHDS)', label: 'Substance use inpatient (ASAM 3.7)' },
                { value: 'Inpatient psychiatric service (DBHDS)', label: 'Inpatient psychiatric service' }
            ],
            test: function (facility, value) { return facility.category === value; }
        }, {
            id: 'kind',
            label: 'Record type:',
            options: [
                { value: 'ALL', label: 'All records' },
                { value: 'inspection', label: 'Inspections' },
                { value: 'investigation', label: 'Investigations' }
            ],
            test: function (facility, value) {
                return facility.reports.some(function (report) { return report.kind === value; });
            },
            testReport: function (report, value) { return report.kind === value; }
        }],

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=VA&lite=1')
                .then(function (response) {
                    if (!response.ok) throw new Error('API returned ' + response.status);
                    return response.json();
                })
                .then(function (apiData) {
                    return {
                        facilities: convertApiDataToFacilities(apiData.facilities),
                        scrapedTimestamp: apiData.scraped_timestamp || ''
                    };
                });
        },

        facilityName: function (facility) { return facility.name; },

        searchText: function (facility) {
            var parts = [facility.name, facility.number, facility.category, facility.address, facility.status];
            facility.reports.forEach(function (report) {
                parts.push(report.source, report.kind, report.provider, report.purpose, report.inspection_type);
                parts = parts.concat(report.locations);
            });
            return parts.map(function (value) { return safeString(value).toLowerCase(); }).join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        countFlagged: function (facility) {
            return facility.reports.reduce(function (sum, report) {
                return sum + findingCount(report);
            }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var vdss = reports.filter(function (report) { return report.source === 'vdss'; }).length;
            var dbhds = reports.length - vdss;
            var flagged = reports.filter(isFlagged).length;
            var latest = reports.reduce(function (max, report) {
                return Math.max(max, parseDate(report.report_date).getTime());
            }, 0);
            var stats = [{ text: ctx.plural(reports.length, 'report'), tone: 'neutral' }];
            if (vdss) stats.push({ text: ctx.plural(vdss, 'VDSS inspection'), tone: 'neutral' });
            if (dbhds) stats.push({ text: ctx.plural(dbhds, 'DBHDS record'), tone: 'neutral' });
            if (flagged) stats.push({ text: ctx.plural(flagged, 'report') + ' with findings', tone: 'flagged' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });
            return {
                meta: [
                    facility.category,
                    facility.number ? 'Licence ' + facility.number : '',
                    facility.capacity ? 'Licensed capacity ' + facility.capacity : '',
                    facility.phone,
                    facility.contact
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var facts = [source(report) + ' ' + kind(report).toLowerCase()];
            var timestamp = parseDate(report.report_date).getTime();
            if (report.inspection_type) facts.push(report.inspection_type);
            if (report.purpose) facts.push(report.purpose);
            if (report.provider) facts.push('Provider: ' + report.provider);
            if (report.received) facts.push('Received ' + report.received);
            if (report.closed) facts.push('Closed ' + report.closed);
            var links = report.archive_names.map(function (name) {
                return ctx.archiveLink(name, 'Archived report');
            }).filter(Boolean);
            return {
                date: timestamp ? ctx.formatDate(timestamp) : 'Date unknown',
                type: source(report) + ' ' + kind(report).toLowerCase(),
                tone: isFlagged(report) ? 'flagged'
                    : report.source === 'vdss' && !report.listed_with_violations ? 'clean' : 'neutral',
                badges: reportBadges(report, ctx),
                facts: facts,
                link: report.report_url ? { href: report.report_url, text: 'State report' } : null,
                links: links,
                preview: report.summary || (report.raw_content ? report.raw_content.split(/\r?\n/)[0] : ''),
                body: function () {
                    return page.withText(report, 'VA', function () { return reportBody(report); });
                }
            };
        }
    });
}());
