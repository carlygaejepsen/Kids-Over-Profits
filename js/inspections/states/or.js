/**
 * Oregon site visit reports (/or-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=OR: Oregon Department of
 * Human Services Children's Care Licensing site visit reports (unannounced
 * visits, license renewals, six-month reviews). The scraper has already split
 * each report into labelled fields -- program description, interviews,
 * strengths and challenges, lawsuits, grievances, restraint use,
 * recommendations, new findings -- plus a findings[] checklist.
 *
 * What counts as flagged ("Corrections required"):
 *   - Current form: the report's own New Findings section lists problems.
 *     When it says there were none ("No new findings were observed"), the
 *     report is clean.
 *   - Older form (no New Findings section): a checklist item uses corrective
 *     wording ("did not", "The program shall ensure", "must be cleaned").
 * No violation count is shown: the older checklist can include follow-ups on
 * earlier visits, and OCR dropped which Yes/No box was ticked. The old viewer
 * flagged finding_count > 0, which is the whole checklist (370 of 409).
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Oregon reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    // Field labels that leak into the end of a scraped value
    // ("ADAPT - Deer Creek Board Chairperson: Kelsey Wood").
    var STOP_LABELS = [
        'Date of site visit', 'Date of Unannounced', 'Executive Director', 'Program Director',
        'Board Chairperson', 'Licensing Coordinator', 'Other Regulatory or Accrediting Agencies',
        'Purpose', 'Program Compliance', 'Program Description', 'Program type and services',
        'Capacity and age-range', 'Capacity and Age Range', 'Funding sources',
        'Contracts and sources for referrals', 'Average length of stay',
        'Average daily population served', 'Number of children served annually',
        'Use of seclusion or restraint', 'Interviews, Observations', 'Program Strengths',
        'Program Challenges', 'Changes that have occurred in the last 2 years',
        'Changes that have occurred in the last two years', 'Lawsuits',
        'Grievances and complaints filed in the last two years',
        'Corrective Actions and Timeframes', 'Recommendations', 'Exceptions',
        'Changes in License', 'Summary of Review'
    ];
    var STOP_RE = new RegExp('\\s+(?:' + STOP_LABELS.map(function (l) {
        return l.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }).join('|') + ')\\s*:', 'i');

    var ISSUE = /\b(?:program|agency|facility|staff)\s+(?:shall|will|must|needs? to)\s+(?:ensure|complete|update|provide|develop|maintain|post|obtain|document|review|train|implement|correct|repair|replace|revise|create|submit)|\bmust be\b|\bneeding\b|\bdid not\b|\bfailed to\b|\bwas not\b|\bwere not\b|\bnot (?:completed|posted|documented|current|signed|available|present|in place)\b|\bmissing\b|\bout of compliance\b|\bexpired\b|\bcorrective action\b/i;
    var NO_FINDINGS = /^(?:none|n\/?a|na)\b|^no\s+(?:new\s+)?findings|program had no findings|there were no (?:new )?findings/i;
    // Values that mean "nothing to report" for narrative fields.
    var EMPTY_VALUE = /^(?:none|n\/?a|na|no)\b[\s.!]*(?:\d+)?$|^none (?:reported|identified|disclosed)[^.]*\.?$|^none, according to the program\.?$/i;

    var VISIT_TYPES = {
        'unannounced': 'Unannounced site visit',
        'license renewals': 'License renewal visit',
        '6-month': 'Six-month review',
        'initial license': 'Initial license visit'
    };

    function normalizeWhitespace(value) {
        return safeString(value)
            .replace(/\r/g, '\n')
            .replace(/\u00a0/g, ' ')
            .replace(/[ \t]+/g, ' ')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    function stripTrailingLabel(value) {
        var normalized = normalizeWhitespace(value);
        if (!normalized) return '';
        var match = normalized.match(STOP_RE);
        return (match ? normalized.slice(0, match.index) : normalized).replace(/\s+[:,-]\s*$/, '').trim();
    }

    function inline(value) {
        return stripTrailingLabel(value).replace(/\s+/g, ' ').trim();
    }

    // Narrative text as prose: page footers dropped ("Form revised 3/12/2025
    // Page 3 of 32"), hard wraps joined, blank lines kept as paragraph breaks.
    function prose(value) {
        var text = stripTrailingLabel(value)
            .replace(/Form revised [0-9\/]+\s+Page \d+ of \d+/gi, '')
            .replace(/P a g e \d+ \| \d+/g, '')
            .trim();
        if (!text || EMPTY_VALUE.test(text)) return [];
        return text.split(/\n\s*\n/)
            .map(function (p) { return p.replace(/\s*\n\s*/g, ' ').replace(/\s+/g, ' ').trim(); })
            .filter(Boolean);
    }

    function parseDate(dateStr) {
        var m = String(dateStr || '').trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})/);
        if (!m) return new Date(0);
        var y = parseInt(m[3], 10);
        if (y < 100) y += (y < 50) ? 2000 : 1900;
        return new Date(y, parseInt(m[1], 10) - 1, parseInt(m[2], 10));
    }

    // "N/A No new findings were found during site visit." -> without the "N/A".
    function cleanPreview(text) {
        var flat = String(text || '').replace(/\s+/g, ' ').trim()
            .replace(/^(?:n\/?a|none|na)\b[\s.:-]*/i, '');
        return flat.length > 3 ? flat : 'No new findings.';
    }

    // The first sentence carrying corrective wording, for the row preview.
    function issueSentence(text) {
        var flat = String(text || '').replace(/\s+/g, ' ');
        // Split after sentence punctuation without a lookbehind, which Safari
        // before 16.4 cannot parse (the whole file would fail to load).
        var sentences = flat.replace(/([.!?])\s+(?=[A-Z"])/g, '$1\n').split('\n');
        for (var i = 0; i < sentences.length; i++) {
            if (ISSUE.test(sentences[i]) && sentences[i].length > 25) return sentences[i].trim().slice(0, 300);
        }
        return '';
    }

    function readReport(report) {
        var cats = report.categories || {};
        var newFindings = normalizeWhitespace(cats.new_findings);
        var checklist = (Array.isArray(cats.findings) ? cats.findings : []).map(function (f) {
            return { rule: inline(f && f.rule), excerpt: normalizeWhitespace(f && f.excerpt) };
        }).filter(function (f) { return f.rule || f.excerpt; });

        var status = 'neutral';
        var issues = [];
        if (newFindings) {
            var flatNew = newFindings.replace(/\s+/g, ' ');
            if (NO_FINDINGS.test(flatNew)) status = 'clean';
            else if (ISSUE.test(flatNew) || /\b4[01]\d-\d{3}-\d{4}/.test(flatNew)) status = 'flagged';
        } else {
            issues = checklist.filter(function (f) { return ISSUE.test(f.excerpt); });
            if (issues.length) status = 'flagged';
        }

        var typeKey = inline(cats.report_type).toLowerCase();
        return {
            report_id:      report.report_id || '',
            report_date:    report.report_date || '',
            visit_date:     inline(cats.visit_date),
            visit_type:     VISIT_TYPES[typeKey] || inline(cats.report_type) || 'Site visit',
            pdf_url:        safeString(cats.pdf_url),
            coordinator:    inline(cats.licensing_coordinator),
            status:         status,
            new_findings:   newFindings,
            issues:         issues,
            preview:        status === 'flagged'
                                ? issueSentence(newFindings || issues.map(function (f) { return f.excerpt; }).join(' '))
                                : (status === 'clean' ? cleanPreview(newFindings) : ''),
            fields: {
                compliance:      prose(cats.program_compliance),
                recommendations: prose(cats.recommendations),
                interviews:      prose(cats.interviews_observations).concat(prose(cats.interview_summary)),
                observations:    prose(cats.observations),
                strengths:       prose(cats.program_strengths),
                challenges:      prose(cats.program_challenges),
                lawsuits:        prose(cats.lawsuits),
                grievances:      prose(cats.grievances_and_complaints),
                restraint:       prose(cats.use_of_seclusion_or_restraint),
                description:     prose(cats.program_description),
                services:        prose(cats.program_services),
                capacity:        prose(cats.capacity_age_range),
                stay:            prose(cats.average_length_of_stay),
                population:      prose(cats.average_daily_population_served),
                annual:          prose(cats.number_of_children_served_annually),
                exceptions:      prose(cats.exceptions),
                license_changes: prose(cats.changes_in_license),
                previous:        normalizeWhitespace(cats.previous_findings)
            },
            raw_content:    report.raw_content || ''
        };
    }

    // "Adapt Deer Creek" and "Adapt - Deer Creek" are one facility.
    function mergeKey(name) {
        return String(name || '').toLowerCase()
            .replace(/\s*[-–—]\s*/g, ' ')
            .replace(/\s*&\s*/g, ' and ')
            .replace(/[^\w\s]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function convertApiDataToFacilities(apiFacilities) {
        var merged = {};
        var order = [];
        apiFacilities.forEach(function (facility) {
            var info = facility.facility_info || {};
            var name = inline(info.facility_name || info.agency_name);
            var key = mergeKey(name);
            if (!merged[key]) {
                merged[key] = {
                    name:     name,
                    category: inline(info.program_category),
                    address:  normalizeWhitespace(info.full_address).replace(/\s*\n\s*/g, ', '),
                    reports:  [],
                    seen:     {}
                };
                order.push(key);
            }
            var target = merged[key];
            (facility.reports || []).forEach(function (report) {
                var id = report.report_id || '';
                if (id && target.seen[id]) return;
                if (id) target.seen[id] = true;
                target.reports.push(readReport(report));
            });
        });
        return order.map(function (key) {
            var f = merged[key];
            delete f.seen;
            f.reports.sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });
            return f;
        });
    }

    function isFlagged(report) {
        return report.status === 'flagged';
    }

    function section(title, paragraphs, opts) {
        return paragraphs && paragraphs.length ? ui.section(title, ui.paragraphs(paragraphs), opts) : '';
    }

    function labelled(pairs) {
        var rows = pairs.filter(function (p) { return p[1] && p[1].length; })
            .map(function (p) { return p[0] + ': ' + p[1].join(' '); });
        return rows.length ? ui.paragraphs(rows) : '';
    }

    page.mount({
        state: 'Oregon',
        emptyMessage: 'No facilities found in the database for Oregon.',

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=OR')
                .then(function (resp) {
                    if (!resp.ok) throw new Error('API returned ' + resp.status);
                    return resp.json();
                })
                .then(function (apiData) {
                    return {
                        facilities: convertApiDataToFacilities(apiData.facilities || []),
                        scrapedTimestamp: apiData.scraped_timestamp || ''
                    };
                });
        },

        facilityName: function (f) { return f.name || ''; },

        searchText: function (f) {
            return [f.name, f.category, f.address]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var flagged = reports.filter(isFlagged).length;
            var clean = reports.filter(function (r) { return r.status === 'clean'; }).length;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'site visit'), tone: 'neutral' }];
            if (flagged) stats.push({ text: ctx.plural(flagged, 'visit') + ' requiring corrections', tone: 'flagged' });
            else if (clean) stats.push({ text: 'No findings', tone: 'clean' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [facility.category],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var badges = [];
            if (report.status === 'flagged') badges.push({ text: 'Corrections required', tone: 'flagged' });
            else if (report.status === 'clean') badges.push({ text: 'No new findings', tone: 'clean' });

            var f = report.fields;
            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || report.visit_date || 'Date unknown',
                type: report.visit_type,
                tone: report.status,
                badges: badges,
                facts: [report.coordinator ? 'Licensing coordinator ' + report.coordinator : ''],
                link: { href: report.pdf_url, text: 'Official report' },
                preview: report.preview,
                body: function () {
                    var html = '';
                    if (report.status === 'flagged') {
                        html += ui.heading('Findings');
                        if (report.new_findings) {
                            // Findings tables interleave columns in the scan, so keep line breaks.
                            html += ui.docText(report.new_findings);
                        } else {
                            html += report.issues.map(function (item) {
                                return ui.finding({
                                    title: item.rule ? 'OAR ' + item.rule : 'Finding',
                                    evidence: [issueSentence(item.excerpt) || item.excerpt.replace(/\s+/g, ' ').slice(0, 400)],
                                    tone: 'flagged'
                                });
                            }).join('');
                        }
                    } else if (report.status === 'clean') {
                        html += ui.paragraphs([cleanPreview(report.new_findings)]);
                    } else {
                        html += ui.note('This report does not state its findings in a form we can read. The official report has the details.');
                    }
                    html += section('Recommendations', f.recommendations, { open: report.status !== 'clean' });
                    html += section('Interviews with youth and staff', f.interviews.concat(f.observations));
                    html += section('Program strengths and challenges', f.strengths.map(function (s) { return 'Strengths: ' + s; })
                        .concat(f.challenges.map(function (s) { return 'Challenges: ' + s; })));
                    // Shown as the program's own answer. It is free text ("Buckman: None
                    // Cordero: None", "No lawsuits for Day Treatment. A former employee
                    // ... sued"), so no lawsuit badge or count is derived from it.
                    html += section('Lawsuits and grievances (as reported by the program)', f.lawsuits.map(function (s) { return 'Lawsuits: ' + s; })
                        .concat(f.grievances.map(function (s) { return 'Grievances: ' + s; })));
                    html += section('Use of seclusion or restraint', f.restraint);
                    var info = labelled([
                        ['Program', f.description], ['Services', f.services], ['Capacity and ages', f.capacity],
                        ['Average length of stay', f.stay], ['Average daily population', f.population],
                        ['Children served each year', f.annual], ['Exceptions', f.exceptions], ['License changes', f.license_changes]
                    ]);
                    if (info) html += ui.section('About the program', info);
                    if (f.previous) html += ui.section('Follow-up on previous findings', ui.docText(f.previous));
                    html += ui.section('Full report text', ui.docText(report.raw_content));
                    return html;
                }
            };
        }
    });
}());
