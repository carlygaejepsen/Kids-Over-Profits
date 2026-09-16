/**
 * Georgia inspection reports (/ga-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=GA (DB-backed; rows written
 * by ga_scraper.py). Each report's categories carry survey_type ("Incident",
 * "Re-Licensure", "Follow-up/Revisit", ...), survey start/exit dates, the
 * event id and sod_url, the report's page on Georgia DHS's site.
 *
 * raw_content is the text of Georgia DHS's Statement of Deficiencies PDF. It
 * is parsed into opening comments, cited violations and closing comments
 * (parseStatement below) instead of being dumped as raw PDF text.
 *
 * What counts as a violation: a report is flagged when its statement cites at
 * least one rule. The old viewer flagged by survey type ("incident" or
 * "complaint"), which was wrong for 377 of 772 readable reports on the
 * 2026-09-01 data: 103 incident surveys cited nothing, and 274 routine surveys
 * cited violations but showed as clean.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Georgia reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    // "3/14/24" or "03/14/2024 - 03/15/2024" -> the first date.
    function parseDate(dateStr) {
        if (!dateStr) return new Date(0);
        var first = String(dateStr).split(/\s*[-–]\s*/)[0].trim();
        var slashParts = first.split('/');
        if (slashParts.length === 3) {
            var m = parseInt(slashParts[0], 10);
            var d = parseInt(slashParts[1], 10);
            var y = parseInt(slashParts[2], 10);
            if (y < 100) y += (y < 50) ? 2000 : 1900;
            return new Date(y, m - 1, d);
        }
        var parsed = new Date(first);
        return isNaN(parsed.getTime()) ? new Date(0) : parsed;
    }

    function parseStatement(rawText) {
        // Page chrome, plus a "Completed Date : ____" that wrapped onto its own
        // line when a tag line sat at the bottom of a PDF page.
        var CHROME = /^(TAG|NUMBER|SUMMARY OF STATEMENT OF DEFICIENCIES PLAN OF CORRECTION|\d{1,2}\/\d{1,2}\/\d{4} \d{1,2}:\d{2}:\d{2} [AP]M \d+|Completed Date\s*:\s*_*)$/;
        // Completed Date is optional for the same page-break reason; it is blank
        // on every live report, so nothing is read from it.
        var TAG_LINE = /^(\d{4})\s+Severity\s*:\s*(\S+)\s+Survey Type\(s\)\s*:/;
        var NOT_MET = 'This Requirement is not met as evidenced by:';
        var CITATION = /\b\d{3}-\d-\d+-\.\d+(?:\([0-9A-Za-z]+\)|\d+\.|\.)*/;
        // A sub-paragraph reference printed after the citation: "((12)(c)", "(VI-XIII)".
        var SUB_REF = /^\(?((?:\([0-9A-Za-z -]{1,8}\))+)\s*/;
        var MINOR = /^(and|of|or|the|for|in|to|a|an|on|with|by|at|from|&)$/i;

        var lines = String(rawText || '').replace(/\r/g, '').split('\n')
            .map(function (l) { return l.trim(); })
            .filter(function (l) { return l && !CHROME.test(l); });

        var blocks = [];
        var current = null;
        lines.forEach(function (line) {
            var m = line.match(TAG_LINE);
            if (m) {
                current = { tag: m[1], severity: m[2], lines: [] };
                blocks.push(current);
            } else if (current) {
                current.lines.push(line);
            }
            // Lines before the first tag are the header block; dropped on purpose.
        });

        // Rejoin PDF hard wraps into paragraphs. A paragraph ends on a short line
        // closing with terminal punctuation; list items and the fixed report
        // phrases always start their own paragraph.
        function reflow(blockLines) {
            var paras = [];
            var buf = '';
            var startsOwn = /^(\(?\d{1,2}[.)]|\(?[a-z][.)]|\([ivx]+\)|[ivx]+\.)\s/;
            function flush() { if (buf) { paras.push(buf); buf = ''; } }
            blockLines.forEach(function (line) {
                if (line === NOT_MET || /^Findings include:?$/i.test(line) || startsOwn.test(line)) flush();
                if (!buf) {
                    buf = line;
                } else if (/-$/.test(buf) && /^[.\d(]/.test(line)) {
                    buf += line;                       // "290-2-5-" + ".09(2)(b)"
                } else {
                    buf += ' ' + line;
                }
                if (line === NOT_MET || /^Findings include:?$/i.test(line)
                    // ")" is not an ending: "...capacity of nine (9)" continues.
                    || (line.length < 50 && /[.:;"”]$/.test(line))) {
                    flush();
                }
            });
            flush();
            return paras;
        }

        function isTitleSentence(sentence) {
            var words = sentence.split(/\s+/).filter(Boolean);
            // Rule text always carries lowercase words ("shall"), so a generous cap
            // is safe; the longest real heading is 9 words.
            if (!words.length || words.length > 12) return false;
            return words.every(function (w, i) {
                if (i > 0 && MINOR.test(w)) return true;
                return /^[A-Z][A-Za-z'\/-]*,?$/.test(w);   // "Checks," in a title list
            });
        }

        // "Referral and Admission. 290-2-5-.09(2)(b) Prior to admission..." or
        // "290-2-7-.10(8) Medical and Dental Care. Persons administering..."
        function splitHeading(text) {
            var citation = '';
            var before = '';
            var rest = text;
            var m = text.slice(0, 220).match(CITATION);
            if (m) {
                citation = m[0].replace(/\.$/, '');
                before = text.slice(0, m.index).trim();
                rest = text.slice(m.index + m[0].length).trim();
            }

            var sub = rest.match(SUB_REF);
            if (sub && citation) {
                citation += sub[1];
                rest = rest.slice(sub[0].length);
            }

            var titles = [];
            if (before) {
                titles = before.split(/\.\s*/).map(function (s) { return s.trim(); }).filter(Boolean);
            }
            // Title sentences may also follow the citation; take them while they
            // stay short and title-cased ("Foster Care Services."). Never across a
            // paragraph break.
            var guard = 0;
            while (guard++ < 4) {
                var sm = rest.match(/^([^.\n]{1,90})\.\s*/);
                if (!sm || !isTitleSentence(sm[1].trim())) break;
                titles.push(sm[1].trim());
                rest = rest.slice(sm[0].length);
            }
            var seen = {};
            titles = titles.filter(function (t, i) {
                var k = t.toLowerCase();
                if (seen[k]) return false;
                seen[k] = true;
                // "Minimum Requirements" then "Minimum Requirements for Prospective
                // Foster Families": keep only the fuller heading.
                var next = titles[i + 1];
                return !(next && next.toLowerCase().indexOf(k) === 0 && next.length > t.length);
            });
            return { title: titles.join(': '), citation: citation, rest: rest.trim() };
        }

        var result = { opening: [], closing: [], deficiencies: [] };

        blocks.forEach(function (block) {
            var paras = reflow(block.lines);
            if (block.tag === '0000' || block.tag === '9999') {
                var target = block.tag === '0000' ? result.opening : result.closing;
                paras.forEach(function (p, i) {
                    // Some PDFs print the label twice, wrapped: "Closing Comments. Closing Comments."
                    var text = i === 0 ? p.replace(/^(?:(?:Opening|Closing) Comments\.?\s*)+/i, '') : p;
                    if (text) target.push(text);
                });
                return;
            }

            var splitAt = paras.indexOf(NOT_MET);
            var requirementParas = splitAt === -1 ? paras : paras.slice(0, splitAt);
            var evidenceParas = splitAt === -1 ? [] : paras.slice(splitAt + 1);

            var heading = splitHeading(requirementParas.join('\n'));
            var requirement = heading.rest ? heading.rest.split('\n').map(function (s) { return s.trim(); }).filter(Boolean) : [];
            var evidenceText = evidenceParas.join(' ');

            result.deficiencies.push({
                tag: block.tag,
                severity: block.severity,
                title: heading.title,
                citation: heading.citation,
                requirement: requirement,
                evidence: evidenceParas,
                repeat: /previously cited/i.test(evidenceText)
            });
        });

        return result;
    }

    // The report viewer sometimes returns its own export-widget chrome instead
    // of the statement text; those reports have no readable content.
    function statementText(report) {
        var raw = safeString(report.raw_content);
        if (raw.length < 400) return '';
        if (/Export to the selected format|Generating report/i.test(raw)) return '';
        return raw;
    }

    function convertApiDataToFacilities(apiFacilities) {
        return apiFacilities.map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var text = statementText(report);
                var parsed = text ? parseStatement(text) : null;
                return {
                    report_date:  report.report_date || '',
                    survey_type:  cats.survey_type || '',
                    survey_start: cats.survey_start_date || '',
                    survey_exit:  cats.survey_exit_date || '',
                    official_url: cats.sod_url || report.report_url || '',
                    event_id:     cats.event_id || '',
                    has_text:     !!parsed,
                    opening:      parsed ? parsed.opening : [],
                    closing:      parsed ? parsed.closing : [],
                    deficiencies: parsed ? parsed.deficiencies : []
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            return {
                name:             safeString(info.facility_name),
                program_name:     safeString(info.program_name),   // GA FACID
                program_category: safeString(info.program_category),
                address:          safeString(info.full_address),
                reports:          reports
            };
        });
    }

    function hasDeficiencies(report) {
        return report.deficiencies.length > 0;
    }

    function hasRepeat(report) {
        return report.deficiencies.some(function (d) { return d.repeat; });
    }

    // Survey dates as a range when the visit spanned more than one day.
    function surveyWindow(report) {
        var start = parseDate(report.survey_start).getTime();
        var exit = parseDate(report.survey_exit).getTime();
        if (start > 0 && exit > 0 && start !== exit) {
            return 'Surveyed ' + page.formatDate(start) + ' to ' + page.formatDate(exit);
        }
        return '';
    }

    page.mount({
        state: 'Georgia',
        emptyMessage: 'No facilities found in the database for Georgia.',

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=GA')
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
            return [f.name, f.program_name, f.program_category, f.address]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: hasDeficiencies,

        // "Most Violations First" ranks by cited rules, not by survey count.
        countFlagged: function (f) {
            return f.reports.reduce(function (sum, r) { return sum + r.deficiencies.length; }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var cited = ctx.countFlagged(facility);
            var readable = reports.filter(function (r) { return r.has_text; }).length;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'report'), tone: 'neutral' }];
            if (cited) {
                stats.push({ text: ctx.plural(cited, 'violation') + ' cited', tone: 'flagged' });
                if (reports.some(hasRepeat)) stats.push({ text: 'Repeat violations', tone: 'repeat' });
            } else if (readable) {
                stats.push({ text: 'No violations cited', tone: 'clean' });
            }
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [
                    facility.program_category || 'Georgia residential care',
                    facility.program_name ? 'Facility\u00a0ID\u00a0' + facility.program_name : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var count = report.deficiencies.length;
            var tone = !report.has_text ? 'neutral' : (count ? 'flagged' : 'clean');

            var badges = [];
            if (!report.has_text) {
                badges.push({ text: 'See official report', tone: 'neutral' });
            } else if (count) {
                badges.push({ text: ctx.plural(count, 'violation'), tone: 'flagged' });
                if (hasRepeat(report)) badges.push({ text: 'Repeat', tone: 'repeat' });
            } else {
                badges.push({ text: 'No violations', tone: 'clean' });
            }

            var body = '';
            if (!report.has_text) {
                body = ui.note('The text of this report is not available here. The official report on the Georgia DHS site has the details.');
            } else {
                body += ui.paragraphs(report.opening, 'kop-rp-purpose');
                if (count) {
                    body += ui.heading(ctx.plural(count, 'violation') + ' cited');
                    body += report.deficiencies.map(function (d) {
                        var chips = [];
                        if (d.severity && d.severity !== '0') chips.push({ text: 'Severity ' + d.severity, tone: 'neutral' });
                        if (d.repeat) chips.push({ text: 'Repeat violation', tone: 'repeat' });
                        return ui.finding({
                            title: d.title || ('Rule ' + d.tag),
                            citation: d.citation,
                            chips: chips,
                            evidence: d.evidence,
                            requirement: d.requirement,
                            tone: d.repeat ? 'repeat' : 'flagged'
                        });
                    }).join('');
                    body += ui.section('Closing comments', ui.paragraphs(report.closing));
                } else {
                    // With nothing cited, the closing comments are the substance
                    // of the report (outcome, license terms, capacity).
                    body += ui.paragraphs(report.closing);
                }
            }

            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || report.report_date,
                type: report.survey_type || 'Survey',
                tone: tone,
                badges: badges,
                facts: [
                    surveyWindow(report),
                    report.event_id ? 'Survey ID ' + report.event_id : ''
                ],
                link: { href: report.official_url, text: 'Official report' },
                body: body
            };
        }
    });

    // Exposed for offline tests of the statement parser.
    window.KOP.gaReports = { parseStatement: parseStatement };
}());
