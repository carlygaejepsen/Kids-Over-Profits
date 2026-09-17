/**
 * Arizona reports (/az-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=AZ: Arizona Department of
 * Health Services statements of deficiencies for behavioral health
 * residential facilities (AZ Care Check). Each inspection carries an opening
 * sentence from the Department and a structured deficiency list
 * {rule, evidence, findings}: the rule text, the Department's summary of the
 * failure, and the numbered findings behind it.
 *
 * Outcome:
 *   - deficiencies listed -> flagged (all 364 lists have content),
 *   - no list, but the Department's opening sentence says deficiencies were
 *     found -> flagged "Deficiencies found" (a handful of recent reports the
 *     scraper did not capture the table for),
 *   - opening sentence says no deficiencies were found or cited -> clean,
 *   - anything else (off-site document reviews, accreditation accepted in
 *     lieu of an inspection, settlement notices) -> neutral, showing the
 *     Department's own sentence.
 * The old viewer flagged only by the deficiency list and showed "No
 * violations noted" for off-site reviews that were never inspections.
 *
 * Text arrives with RTF hex escapes (\'a7 for the section sign); they are
 * decoded for display.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Arizona reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var SAYS_NONE = /\bno\s+deficienc(?:y|ies)\s+(?:was|were)\s+(?:found|cited)/i;
    // Checked after SAYS_NONE, so "no deficiencies were cited" never reaches it.
    var SAYS_SOME = /\bdeficien\w*\s+(?:was|were)\s+(?:found|cited)/i;

    /** "\'a7" -> "\u00a7"; the scraper keeps RTF hex escapes from the source. */
    function clean(value) {
        return safeString(value).replace(/\\'([0-9a-f]{2})/gi, function (m, hex) {
            return String.fromCharCode(parseInt(hex, 16));
        });
    }

    // "8/28/2024", "6/15/2023 - 6/22/2023" -> first date.
    function parseDate(dateStr) {
        var first = safeString(dateStr).split(/\s*[-\u2013]\s*/)[0];
        var parts = first.split('/');
        if (parts.length === 3) {
            var y = parseInt(parts[2], 10);
            if (y < 100) y += (y < 50) ? 2000 : 1900;
            return new Date(y, parseInt(parts[0], 10) - 1, parseInt(parts[1], 10));
        }
        return new Date(0);
    }

    /** "the administrator failed to ensure X." -> "Failed to ensure X" (short). */
    function findingTitle(evidence) {
        var text = evidence.replace(/^Based on [^,]*(?:,\s*and [^,]*)?,\s*/i, '');
        var m = text.match(/\b(failed to|did not)\s+(.+)/i);
        if (!m) return '';
        var title = (m[1].charAt(0).toUpperCase() + m[1].slice(1).toLowerCase()) + ' ' + m[2];
        title = title.split(/\.\s|\.$/)[0];
        // Cut before the scope clause ("for one of nine personnel members").
        title = title.replace(/,?\s+(?:for|in)\s+(?:one|two|three|four|five|six|seven|eight|nine|ten|\d+)\s+of\s+.*$/i, '');
        // Long ones break at the first clause boundary past the verb phrase.
        if (title.length > 110) {
            var cut = title.slice(50).search(/\s(?:as required|in accordance with|according to|to include|that include[ds]?|which include[ds]?|including|before|within|when|per)\s/);
            if (cut !== -1) title = title.slice(0, 50 + cut);
        }
        title = title.replace(/[\s,;:]+$/, '');
        if (title.length > 140) {
            title = title.slice(0, 140).replace(/\s+\S*$/, '') + '\u2026';
        }
        return title;
    }

    /** Rule text, with its A.A.C. citation split off when it leads the text. */
    function splitRule(rule) {
        var m = rule.match(/^((?:R\d+-\d+-\d+(?:\.[A-Z0-9]+)*\.?)|(?:A\.R\.S\.\s*\u00a7+\s*[\d-]+(?:\.\d+)?(?:\([A-Z0-9]+\))*))\s*(.*)$/);
        return m ? { citation: m[1].replace(/\.$/, ''), text: m[2] } : { citation: '', text: rule };
    }

    /** Numbered findings ("1. A review of ... 2. Interview with ...") as paragraphs. */
    function findingParagraphs(findings) {
        return findings.split(/\s+(?=\d{1,2}\.\s+[A-Z\[])/).map(safeString).filter(Boolean);
    }

    function readReport(report) {
        var cats = report.categories || {};
        var opening = clean(report.raw_content).replace(/\s*\n\s*/g, '\n').trim();
        var deficiencies = (Array.isArray(cats.deficiencies) ? cats.deficiencies : [])
            .filter(function (d) { return d && typeof d === 'object' && (d.rule || d.evidence || d.findings); })
            .map(function (d) {
                var rule = splitRule(clean(d.rule));
                var evidence = clean(d.evidence);
                return {
                    title: findingTitle(evidence),
                    citation: rule.citation,
                    requirement: rule.text,
                    evidence: evidence,
                    findings: findingParagraphs(clean(d.findings))
                };
            });

        var outcome = 'neutral';
        if (deficiencies.length) outcome = 'flagged';
        else if (SAYS_NONE.test(opening)) outcome = 'clean';
        else if (SAYS_SOME.test(opening)) outcome = 'flagged';

        var types = safeString(cats.inspection_type).split(';').map(safeString).filter(Boolean);
        return {
            number: safeString(cats.inspection_number || report.report_id),
            date_text: safeString(cats.inspection_date || report.report_date),
            types: types,
            opening: opening,
            deficiencies: deficiencies,
            outcome: outcome
        };
    }

    function convertApiDataToFacilities(apiFacilities) {
        return apiFacilities.map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(readReport).sort(function (a, b) {
                return parseDate(b.date_text) - parseDate(a.date_text);
            });
            return {
                name: safeString(info.facility_name),
                license: safeString(info.program_name),
                category: safeString(info.program_category),
                status: safeString(info.action),
                address: safeString(info.full_address),
                capacity: safeString(info.bed_capacity),
                administrator: safeString(info.executive_director),
                reports: reports
            };
        });
    }

    function isFlagged(report) {
        return report.outcome === 'flagged';
    }

    function firstSentence(text) {
        var line = safeString(text).split('\n')[0];
        var m = line.match(/^.*?[.:](?=\s|$)/);
        return (m ? m[0] : line).replace(/\s*:$/, '.');
    }

    page.mount({
        state: 'Arizona',
        emptyMessage: 'No facilities found in the database for Arizona.',

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=AZ')
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

        facilityName: function (f) { return f.name; },

        searchText: function (f) {
            return [f.name, f.license, f.administrator, f.address].join(' ').toLowerCase();
        },

        reportTime: function (report) { return parseDate(report.date_text).getTime(); },
        isFlagged: isFlagged,

        countFlagged: function (facility) {
            return facility.reports.reduce(function (sum, r) {
                return sum + (isFlagged(r) ? Math.max(1, r.deficiencies.length) : 0);
            }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var cited = reports.reduce(function (sum, r) { return sum + r.deficiencies.length; }, 0);
            var flagged = reports.filter(isFlagged).length;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.date_text).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'inspection'), tone: 'neutral' }];
            if (cited) stats.push({ text: ctx.plural(cited, 'deficiency', 'deficiencies') + ' cited', tone: 'flagged' });
            else if (flagged) stats.push({ text: 'Deficiencies found', tone: 'flagged' });
            else if (reports.some(function (r) { return r.outcome === 'clean'; })) stats.push({ text: 'No deficiencies cited', tone: 'clean' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [
                    facility.category || 'Arizona behavioral health facility',
                    facility.license ? 'License\u00a0' + facility.license : '',
                    facility.capacity ? 'Capacity ' + facility.capacity : '',
                    facility.status === 'Closed' ? 'Closed' : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var count = report.deficiencies.length;
            var badges = [];
            if (count) badges.push({ text: ctx.plural(count, 'deficiency', 'deficiencies'), tone: 'flagged' });
            else if (report.outcome === 'flagged') badges.push({ text: 'Deficiencies found', tone: 'flagged' });
            else if (report.outcome === 'clean') badges.push({ text: 'No deficiencies', tone: 'clean' });

            var time = parseDate(report.date_text).getTime();
            var range = /\s[-\u2013]\s/.test(report.date_text) ? report.date_text : '';

            return {
                date: ctx.formatDate(time) || report.date_text,
                type: report.types.join(' and ') || 'Inspection',
                tone: report.outcome,
                badges: badges,
                facts: [
                    report.number ? 'Inspection ' + report.number : '',
                    range ? 'On site ' + range : ''
                ],
                link: null,
                preview: count ? '' : firstSentence(report.opening),
                body: function () {
                    var html = ui.paragraphs(report.opening.split('\n'), 'kop-rp-purpose');
                    if (!report.opening) {
                        html += ui.note('The Department published no summary for this inspection.');
                    }
                    if (count) {
                        html += ui.heading(ctx.plural(count, 'deficiency', 'deficiencies') + ' cited');
                        html += report.deficiencies.map(function (d) {
                            // 68 deficiencies carry only the rule text; show it openly.
                            if (!d.evidence && !d.findings.length) {
                                return ui.finding({ title: 'Cited rule', citation: d.citation, evidence: [d.requirement] });
                            }
                            return ui.finding({
                                title: d.title || 'Cited rule',
                                citation: d.citation,
                                evidence: [d.evidence].concat(d.findings),
                                requirement: [d.requirement]
                            });
                        }).join('');
                    } else if (report.outcome === 'flagged') {
                        html += ui.note('The Department found deficiencies in this inspection, but the list of them was not captured. AZ Care Check has the full statement of deficiencies.');
                    }
                    return html;
                }
            };
        }
    });
}());
