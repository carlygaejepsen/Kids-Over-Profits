/**
 * Connecticut reports (/ct-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=CT (static ct_reports.json
 * as a fallback): Department of Children and Families licensing documents for
 * child-caring facilities, group homes and residential treatment centers.
 * Two kinds:
 *   - Field Visit Reporting Forms (DCF-3034), the quarterly visits. The form
 *     has fixed headings: areas / topics covered, corrective actions from the
 *     previous visit, and "Areas of regulatory non-compliance identified
 *     during this visit". That last section decides the outcome.
 *   - Letters: relicensing inspection results and license issuance letters.
 *
 * Outcome, read from the document's own non-compliance section, never from
 * the scraper's regulatory_non_compliance array (it marks "Not at the time of
 * this quarterly visit." as an issue, and the old viewer flagged on it):
 *   - section cites a regulation ("Section 17a-145-73 Sleeping
 *     accommodations") or describes a problem -> flagged,
 *   - section says none / not applicable / nothing identified, or lists
 *     nothing -> clean,
 *   - a letter listing "the areas of regulatory non-compliance" or areas that
 *     "shall be addressed" -> flagged; a letter stating the program "is in
 *     compliance" -> clean,
 *   - anything else (a form missing the section, a fragment too short to
 *     read, a license letter with no finding) -> neutral with the text.
 * Every form prints "Please submit a plan of correction to address the above
 * referenced areas of non-compliance ..." whether or not anything was found,
 * so that sentence is removed before reading the section.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Connecticut reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;
    var themeData = window.ctReportsData || {};

    var IS_FORM = /Field\s+Visit\s+Reporting\s+Form|DCF-3034|TIME\s+OF\s+VISIT|List\s+of\s+Areas\s*\/\s*Topics/i;

    // The form heading. In-text references ("See Areas of Regulatory
    // Non-compliance below") also match the bare phrase, so prefer the last
    // "... identified during" heading, then the last one at a line start.
    var NC_HEAD = /areas?\s+of\s+regulatory\s+non[-\s]?compliance\s+identified[^:\n]*:?/gi;
    var NC_HEAD_LINE = /(?:^|\n)[ \t\u2022?-]*areas?\s+of\s+regulatory\s+non[-\s]?compliance[^:\n]*:?/gi;
    var TOPICS_HEAD = /(?:list\s+of\s+)?areas\s*\/\s*topics[^:\n]*:?/i;
    var CORRECTIVE_HEAD = /(?:corrective\s+actions?|corrections)\s+implemented\s+as\s+a\s+result\s+of\s+(?:the\s+)?previous\s+visit\s*:?/i;
    // Where a section stops: another form heading, the boilerplate request,
    // the consultant's signature line (name, then a date), or the footer.
    var SECTION_END = /\n[ \t]*(?:corrective\s+actions?\s+implemented|corrections\s+implemented|recommendations?\s*:|list\s+of\s+areas|areas?\s+of\s+regulatory\s+non|a\s+copy\s+of\s+this\s+summary|regulatory\s+consultant\b|cc:)|\n[ \t]*[A-Z][A-Za-z.'-]+(?:[ \t]+[A-Z][A-Za-z.,'-]+){0,4}[ \t]*(?:\t|[ ]{2,})[ \t_]*(?:date:?)?[ \t_]*\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}/i;
    var PLAN_REQUEST = /please\s+submit\s+a[^.]*?(?:plan|\(rcp\)|\(sdp\))[^.]*?within\s+\d+\s+days[^.]*\.(?:\s*the\s+[^.]*?must\s+be\s+submitted[^.]*\.)?/gi;
    var NO_PLAN = /-?\s*\bno\s+(?:sdp|rcp|(?:licensing\s+)?regulat\w*\s+compliance\s+plan|service\s+development\s+plan|plan\s+of\s+correction)(?:\s+(?:is\s+)?required)?(?:\s+following\s+this\s+licensing\s+visit)?(?:\s+from\s+(?:the\s+)?(?:last|previous)\s+visit)?\s*[.:-]?/gi;
    var SAYS_NONE = /^[o?\u2022\uf0b7\-\s]*(?:n\/?a|none|not\s+applicable|not\s+at\s+the\s+time|nothing\s+(?:was\s+)?(?:identified|noted|observed)|no\s+(?:regulatory\s+)?(?:deficienc\w+|non-?compliance|areas?|concerns?|issues?|violations?|citations?)|there\s+were\s+no\s+(?:regulatory\s+)?(?:citations?|deficienc\w+|violations?))\b/i;
    var CITES = /\bsec(?:tion|\.)\s+\d+[a-z]?\s*-\s*\d+|\b17[a-z]?\s*-\s*\d+(?:\s*-\s*\d+)?\b|\b\d{2}[a-z]-\d+|(?:^|\s)\d{2,3}\.?\s+[A-Z][a-z]+(?:\s+[a-z]+){0,4}[.,:]/;
    // A citation that opens an item: at the start, a line start, or after a sentence.
    var CITE_START = /(^|\n|[.;]\s+)[ \t\u2022?o-]*((?:section|sec\.)\s*)?(\d{2}[a-z]?\s*-\s*\d+(?:\s*-\s*\d+)?)\b[ \t.:-]*/gi;
    var SIGNATURE_ONLY = /^(?:[A-Z][A-Za-z.'-]*[\s,]*){1,4}(?:LCSW|MSW|LMSW)?[\s,]*(?:[Dd]ate:?)?[\s_]*(?:\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}|[A-Z][a-z]+\s+\d{1,2},?\s+\d{4})?[\s_]*$/;

    // Forms missing the non-compliance heading still close with this line.
    var NO_PLAN_REQUIRED = /\bno\s+(?:licensing\s+)?regulat\w*\s+compliance\s+plan\s+is\s+required\b/i;

    var LETTER_NONE = /\bno\s+areas?\s+of\s+(?:regulatory\s+)?non-?compliance\s+were\s+(?:identified|found|noted)/i;
    var LETTER_CITES = /\bin\s+compliance\s+with\s+all\s+applicable\s+regulatory\s+provisions\s+except\b|\bplease\s+review\s+(?:the\s+)?(?:areas?|sections?)\s+(?:of\s+(?:regulatory\s+)?non-?compliance\s+)?identified|\b(?:below\s+are|listed\s+below\s+are|the\s+following\s+are)\b[^.]*\bareas?\s+of\s+(?:regulatory\s+)?non-?compliance|\bfollowing\s+areas?\s+(?:shall|must|should)\s+be\s+addressed|\bareas?\s+of\s+(?:regulatory\s+)?non-?compliance\s+(?:which|that)\s+were\s+identified/i;
    var LETTER_COMPLIANT = /\b(?:determined|found)\s+(?:that\s+)?(?:your\s+)?(?:agency'?s?\s+)?(?:program|facility|agency)?\s*(?:is|was|to\s+be)\s+in\s+(?:full\s+|substantial\s+)?compliance\b|\ball\s+areas\s+of\s+the\s+program\s+(?:are|were)\s+in\s+compliance\b|\bdemonstrated\s+continued\s+compliance\b/i;
    // Follow-up paperwork, not inspection results.
    var PLAN_DOCUMENT = /^\s*DCF\s+Corrective\s+Action\s+Plan\b/i;
    var PLAN_ACCEPTED = /\b(?:accepts?|accepted|approved?)\s+the\s+(?:regulatory\s+compliance|service\s+development|corrective\s+action)?\s*plan\b|\bwe\s+(?:have\s+)?received\s+your\s+agency'?s\s+(?:regulatory\s+compliance|service\s+development|corrective\s+action)\s+plan\b/i;

    // "12/11/2025" -> Date.
    function parseDate(dateStr) {
        var parts = safeString(dateStr).split(/[\/-]/);
        if (parts.length === 3) {
            var y = parseInt(parts[2], 10);
            if (y < 100) y += (y < 50) ? 2000 : 1900;
            return new Date(y, parseInt(parts[0], 10) - 1, parseInt(parts[1], 10));
        }
        return new Date(0);
    }

    function lastMatch(re, text) {
        re.lastIndex = 0;
        var m, last = null;
        while ((m = re.exec(text))) last = m;
        return last;
    }

    /** Text after a heading match up to the next heading or signature. */
    function sectionAfter(text, match) {
        if (!match) return null;
        var rest = text.slice(match.index + match[0].length);
        var end = rest.search(SECTION_END);
        return end === -1 ? rest : rest.slice(0, end);
    }

    /** Form lines as paragraphs: bullets and underscores dropped, blanks skipped. */
    function lines(text) {
        return safeString(text).split('\n').map(function (line) {
            return line.replace(/^[\s\u2022\uf0b7?o\-]+(?=\S)/, '').replace(/_{3,}/g, ' ').replace(/\s+/g, ' ').trim();
        }).filter(function (line) { return line && !/^[?\u2022.\-]+$/.test(line); });
    }

    function flat(text) {
        return safeString(text).replace(/[\u2022\uf0b7\t]/g, ' ').replace(/_{3,}/g, ' ').replace(/\s+/g, ' ').trim();
    }

    /** Split cited non-compliance into items: { citation, title, paragraphs }. */
    function citationItems(text) {
        var items = [];
        var intro = '';
        var starts = [];
        var m;
        CITE_START.lastIndex = 0;
        while ((m = CITE_START.exec(text))) {
            starts.push({ at: m.index + m[1].length, bodyAt: m.index + m[0].length, citation: m[3].replace(/\s+/g, '') });
            if (m[0].length === 0) CITE_START.lastIndex++;
        }
        if (!starts.length) return { intro: text, items: [] };
        intro = text.slice(0, starts[0].at);
        starts.forEach(function (s, i) {
            var body = text.slice(s.bodyAt, i + 1 < starts.length ? starts[i + 1].at : text.length);
            var flatBody = flat(body);
            // "Sleeping accommodations. The bedroom window ..." -> title + text.
            var t = flatBody.match(/^([A-Z][^.:]{2,80}?)[.:]\s+(.*)$/);
            items.push({
                citation: s.citation,
                title: t ? t[1] : '',
                paragraphs: t ? lines(body.slice(body.indexOf(t[1]) + t[1].length).replace(/^[\s.:]+/, '')) : lines(body)
            });
        });
        return { intro: intro, items: items };
    }

    function readForm(text) {
        var ncMatch = lastMatch(NC_HEAD, text) || lastMatch(NC_HEAD_LINE, text);
        var ncRaw = sectionAfter(text, ncMatch);
        var nc = ncRaw === null ? null : ncRaw.replace(PLAN_REQUEST, ' ').replace(NO_PLAN, ' ');
        var ncFlat = nc === null ? null : flat(nc).replace(/^[\s.:;-]+|[\s.:;-]+$/g, '');

        var outcome, kind;
        if (ncFlat === null && NO_PLAN_REQUIRED.test(text)) { outcome = 'clean'; kind = 'no-plan'; }
        else if (ncFlat === null) { outcome = 'neutral'; kind = 'no-section'; }
        else if (!ncFlat || SIGNATURE_ONLY.test(ncFlat)) { outcome = 'clean'; kind = 'none-listed'; }
        else if (SAYS_NONE.test(ncFlat) && !CITES.test(ncFlat)) { outcome = 'clean'; kind = 'none'; }
        else if (CITES.test(ncFlat)) { outcome = 'flagged'; kind = 'cited'; }
        else if (ncFlat.split(/\s+/).length < 6) { outcome = 'neutral'; kind = 'unclear'; }
        else { outcome = 'flagged'; kind = 'described'; }

        var split = kind === 'cited' ? citationItems(nc) : { intro: '', items: [] };
        var topicsMatch = text.match(TOPICS_HEAD);
        var correctiveMatch = text.match(CORRECTIVE_HEAD);

        return {
            is_form: true,
            outcome: outcome,
            kind: kind,
            // First line only: the consultant's signature can follow on the same line.
            none_text: kind === 'none' ? (lines(nc)[0] || ncFlat).replace(/\s+[A-Z][a-z]+(?:\s+[A-Z]\.?)?\s+[A-Z][a-z]+,?(?:\s+LC?SW|\s+LMSW)?\s+(?:[Dd]ate:?\s*)?[\w ,\/-]*\d{2,4}\s*$/, '') : '',
            // "No Regulatory Compliance Plan required." is the whole section on some forms.
            plan_line: kind === 'none-listed' && ncRaw ? (safeString(ncRaw.match(NO_PLAN) && ncRaw.match(NO_PLAN)[0]).replace(/^-\s*/, '')) : '',
            nc_intro: lines(split.intro),
            nc_items: split.items,
            nc_text: kind === 'described' || kind === 'unclear' ? lines(nc) : [],
            topics: topicsMatch ? lines(sectionAfter(text, topicsMatch)) : [],
            corrective: correctiveMatch ? lines(sectionAfter(text, correctiveMatch)) : []
        };
    }

    function readLetter(text) {
        var kind = /re-?licens/i.test(text) ? 'relicensing' : (/\blicen[sc]e\b/i.test(text) ? 'license' : 'letter');
        if (PLAN_DOCUMENT.test(text)) return { is_form: false, outcome: 'neutral', kind: 'plan' };
        // Result letters often carry the later plan acceptance appended, so the
        // result wins; "plan accepted" labels only letters with no result.
        if (LETTER_NONE.test(text)) return { is_form: false, outcome: 'clean', kind: kind };
        if (LETTER_CITES.test(text)) return { is_form: false, outcome: 'flagged', kind: kind };
        if (LETTER_COMPLIANT.test(text)) return { is_form: false, outcome: 'clean', kind: kind };
        if (PLAN_ACCEPTED.test(text)) return { is_form: false, outcome: 'neutral', kind: 'plan-accepted' };
        return { is_form: false, outcome: 'neutral', kind: kind };
    }

    function readReport(report) {
        // Word bullets arrive as control or private-use characters (U+001A, U+F0B7).
        var text = safeString(report.raw_content).replace(/\r/g, '').replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f\uf000-\uf8ff]/g, ' ');
        var cats = report.categories || {};
        var visit = cats.visit_details && typeof cats.visit_details === 'object' ? cats.visit_details : {};
        var base = IS_FORM.test(text) ? readForm(text) : readLetter(text);
        base.id = safeString(report.report_id);
        base.report_date = safeString(report.report_date);
        base.text = text;
        base.visit_time = safeString(visit.visit_time);
        base.personnel = Array.isArray(visit.personnel) ? visit.personnel.map(safeString).filter(Boolean) : [];
        return base;
    }

    function cleanAddress(address) {
        return safeString(address).replace(/\s*-?\s*Phone:.*$/i, '').trim();
    }

    function convert(apiFacilities) {
        return apiFacilities.map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(readReport).sort(function (a, b) {
                return parseDate(b.report_date) - parseDate(a.report_date);
            });
            return {
                name: safeString(info.facility_name),
                program: safeString(info.program_name),
                category: safeString(info.program_category),
                address: cleanAddress(info.full_address),
                director: safeString(info.executive_director),
                capacity: safeString(info.bed_capacity),
                license_expires: safeString(info.license_exp_date),
                reports: reports
            };
        });
    }

    function fetchJson(url) {
        return fetch(url).then(function (resp) {
            if (!resp.ok) throw new Error('API returned ' + resp.status);
            return resp.json();
        });
    }

    function isFlagged(report) {
        return report.outcome === 'flagged';
    }

    function typeLabel(report) {
        if (report.is_form) return 'Field visit';
        if (report.kind === 'plan') return 'Corrective action plan';
        if (report.kind === 'plan-accepted') return 'Compliance plan accepted';
        if (report.kind === 'relicensing') return 'Relicensing inspection letter';
        if (report.kind === 'license') return 'Licensing letter';
        return 'Letter';
    }

    page.mount({
        state: 'Connecticut',
        emptyMessage: 'No facilities found for Connecticut.',

        load: function () {
            return fetchJson('/wp-content/themes/child/api/inspections-read.php?state=CT')
                .then(function (apiData) {
                    if (!apiData.facilities || !apiData.facilities.length) throw new Error('no facilities');
                    return apiData;
                })
                .catch(function (error) {
                    // The theme localizes a shape-compatible static dataset.
                    var urls = Array.isArray(themeData.jsonFileUrls) ? themeData.jsonFileUrls : [];
                    if (!urls.length) throw error;
                    return fetchJson(urls[0]);
                })
                .then(function (apiData) {
                    return {
                        facilities: convert(apiData.facilities || []),
                        scrapedTimestamp: apiData.scraped_timestamp || ''
                    };
                });
        },

        facilityName: function (f) { return f.name; },

        searchText: function (f) {
            return [f.name, f.program, f.director, f.address].join(' ').toLowerCase();
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var flagged = reports.filter(isFlagged).length;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'report'), tone: 'neutral' }];
            if (flagged) stats.push({ text: ctx.plural(flagged, 'report') + ' with non-compliance', tone: 'flagged' });
            else if (reports.some(function (r) { return r.outcome === 'clean'; })) stats.push({ text: 'No non-compliance found', tone: 'clean' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [
                    facility.category || 'Connecticut licensed facility',
                    facility.capacity ? 'Capacity ' + facility.capacity : '',
                    facility.director ? 'Director ' + facility.director : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var badges = [];
            var count = report.nc_items ? report.nc_items.length : 0;
            if (report.outcome === 'flagged') {
                badges.push({ text: count ? ctx.plural(count, 'citation') : 'Non-compliance found', tone: 'flagged' });
            } else if (report.outcome === 'clean') {
                badges.push({ text: 'No non-compliance', tone: 'clean' });
            }

            var preview = '';
            if (count) {
                preview = report.nc_items.map(function (item) { return item.title || ('Section ' + item.citation); })
                    .filter(function (t, i, all) { return all.indexOf(t) === i; }).join('; ');
            }

            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || report.report_date,
                type: typeLabel(report),
                tone: report.outcome,
                badges: badges,
                facts: [
                    report.visit_time ? 'Visit ' + report.visit_time : '',
                    report.personnel.length ? 'Met with ' + report.personnel.join(', ') : ''
                ],
                link: null,
                preview: preview,
                body: function () {
                    if (!report.is_form) {
                        var note = report.outcome === 'neutral'
                            ? ui.note('This letter does not state an inspection result in a form we can read reliably; the full text is below.')
                            : '';
                        return note + ui.docText(report.text);
                    }

                    var html = '';
                    if (report.kind === 'cited') {
                        html += ui.heading('Areas of regulatory non-compliance');
                        html += ui.paragraphs(report.nc_intro);
                        html += report.nc_items.map(function (item) {
                            return ui.finding({
                                title: item.title || 'Regulation cited',
                                citation: 'Sec. ' + item.citation,
                                evidence: item.paragraphs
                            });
                        }).join('');
                    } else if (report.kind === 'described') {
                        html += ui.heading('Areas of regulatory non-compliance');
                        html += ui.paragraphs(report.nc_text);
                    } else if (report.kind === 'none') {
                        html += ui.paragraphs(['Areas of regulatory non-compliance: ' + report.none_text], 'kop-rp-purpose');
                    } else if (report.kind === 'no-plan') {
                        html += ui.paragraphs(['No Regulation Compliance Plan is required following this visit.'], 'kop-rp-purpose');
                    } else if (report.kind === 'none-listed') {
                        html += report.plan_line
                            ? ui.paragraphs(['Areas of regulatory non-compliance: ' + report.plan_line], 'kop-rp-purpose')
                            : ui.note('The form lists no areas of regulatory non-compliance for this visit.');
                    } else if (report.kind === 'unclear') {
                        html += ui.note('The non-compliance section of this form is incomplete: "' + report.nc_text.join(' ') + '". The full text is below.');
                    } else {
                        html += ui.note('This form has no non-compliance section we could find. The full text is below.');
                    }

                    html += ui.section('Topics covered during the visit', ui.paragraphs(report.topics), { open: report.outcome !== 'flagged' });
                    html += ui.section('Corrective actions from the previous visit', ui.paragraphs(report.corrective));
                    html += ui.section('Full form text', ui.docText(report.text), { open: report.kind === 'no-section' || report.kind === 'unclear' });
                    return html;
                }
            };
        }
    });
}());
