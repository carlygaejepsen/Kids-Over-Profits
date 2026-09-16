/**
 * Minnesota licensing actions (/mn-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=MN: Minnesota DHS Licensing
 * documents -- correction orders, maltreatment investigation memoranda, fine
 * orders, conditional licenses, suspensions, revocations and settlement
 * agreements. The document type is read from the heading in the text, since
 * the scraper's doc_type is sometimes generic ("Order", "Document").
 *
 * What counts as flagged:
 *   - correction orders, notices of violation, fines, conditional licenses,
 *     suspensions, revocations and settlements (adverse licensing actions),
 *   - maltreatment investigations whose disposition is "Maltreatment
 *     determined" or "Substantiated". "Inconclusive" and "not determined" are
 *     shown as such and not flagged.
 * Documents whose text is only the DHS footer or a "Verifying your browser"
 * bot-check page were not retrieved; they say so and link to DHS.
 *
 * The maltreatment field patterns come from the previous viewer
 * (mn_reports.js); violation parsing is rewritten, see extractViolations.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Minnesota reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var MONTHS = { january: 0, february: 1, march: 2, april: 3, may: 4, june: 5, july: 6,
                   august: 7, september: 8, october: 9, november: 10, december: 11 };

    var ADVERSE_TYPES = {
        'Correction Order': true, 'Notice of Violation': true, 'Fine Order': true,
        'Conditional License Order': true, 'License Revocation': true,
        'Temporary Immediate Suspension': true, 'Settlement Agreement': true
    };

    // "April 12, 2024", "4/12/2024" -> Date.
    function parseDate(dateStr) {
        var text = safeString(dateStr);
        var m = text.match(/^([A-Za-z]+)\s+(\d{1,2}),\s*(\d{4})/);
        if (m && MONTHS[m[1].toLowerCase()] !== undefined) {
            return new Date(parseInt(m[3], 10), MONTHS[m[1].toLowerCase()], parseInt(m[2], 10));
        }
        m = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})/);
        if (m) {
            var y = parseInt(m[3], 10);
            if (y < 100) y += (y < 50) ? 2000 : 1900;
            return new Date(y, parseInt(m[1], 10) - 1, parseInt(m[2], 10));
        }
        return new Date(0);
    }

    function flat(text) {
        return String(text || '').replace(/\s+/g, ' ').trim();
    }

    function classifyDocument(text, fallbackType) {
        var head = String(text || '').slice(0, 600);
        if (/MALTREATMENT INVESTIGATION MEMORANDUM/i.test(head)) return 'Maltreatment Investigation';
        if (/ORDER TO PAY A FINE/i.test(head)) return 'Fine Order';
        if (/TEMPORARY IMMEDIATE SUSPENSION/i.test(head)) return 'Temporary Immediate Suspension';
        if (/ORDER (?:OF|EXTENDING A CURRENT) CONDITIONAL LICENSE/i.test(head)) return 'Conditional License Order';
        if (/Summary of Settlement Agreement/i.test(head)) return 'Settlement Agreement';
        if (/LICENSE REVOCATION|REVOCATION OF LICENSE|ORDER OF LICENSE REVO/i.test(head)) return 'License Revocation';
        if (/CORRECTION ORDER/i.test(head)) return 'Correction Order';
        if (/NOTICE OF .*VIOLATION/i.test(head)) return 'Notice of Violation';
        if (/maltreatment/i.test(fallbackType)) return 'Maltreatment Investigation';
        return fallbackType || 'Document';
    }

    function notRetrieved(text) {
        var stripped = flat(text);
        if (stripped.length < 300) return true;
        return /^PO Box 64242.*Veteran Friendly Employer/i.test(stripped) || /Verifying your browser/i.test(stripped.slice(0, 200));
    }

    // Field patterns run on whitespace-collapsed text: the values wrap across
    // lines, and "." does not match a newline.
    function grab(text, pattern) {
        var m = flat(text).match(pattern);
        return m ? flat(m[1]) : '';
    }

    /**
     * Cited violations in a correction order or licensing order.
     * Each starts at "Violation:" (or "2. Violation:", not "Repeat Violation:")
     * and may carry the rule ("Rule Violated:", "Statute Violated:", "Citation:"), the
     * required correction ("Corrective Action Required:" / "Ordered:") and a
     * repeat note ("Repeat Violation:"). Splitting on each violation first keeps
     * one block from swallowing the next when the rule label differs, which the
     * previous viewer's single pattern did (483 violations found; 877 are there).
     */
    function extractViolations(text) {
        var body = flat(text);
        var end = body.search(/Written Response|YOUR RIGHT|Legal authority|Request for Reconsideration/i);
        if (end > 0) body = body.slice(0, end);

        var starts = [];
        var re = /(?:\b\d{1,2}\.\s*)?\bViolation:\s/g;
        var m;
        while ((m = re.exec(body))) {
            if (/Repeat\s*$/i.test(body.slice(Math.max(0, m.index - 7), m.index))) continue;
            starts.push(m.index);
        }

        var LABELS = /(?:(?:Rules?|Statutes?) Violated|Citation|Statutes?|Corrective Action (?:Required|Ordered)|Repeat Violation):/i;
        var out = [];
        starts.forEach(function (start, i) {
            var chunk = body.slice(start, i + 1 < starts.length ? starts[i + 1] : body.length)
                .replace(/^(?:\d{1,2}\.\s*)?Violation:\s*/, '')
                .replace(/\s+[A-Z][A-Z ,&\/-]{3,}\s*$/, '')          // next section's heading ("RESIDENT FILES")
                .trim();
            var ruleM = chunk.match(/(?:(?:Rules?|Statutes?) Violated|Citation|Statutes?):\s*([\s\S]+?)(?=Corrective Action (?:Required|Ordered):|Repeat Violation:|$)/i);
            var corrM = chunk.match(/Corrective Action (?:Required|Ordered):\s*([\s\S]+?)(?=Repeat Violation:|$)/i);
            var repeatM = chunk.match(/Repeat Violation:\s*([\s\S]+)$/i);
            var cut = chunk.search(LABELS);
            var violation = (cut >= 0 ? chunk.slice(0, cut) : chunk).trim();
            if (!violation || (!ruleM && !corrM)) return;
            out.push({
                violation: violation,
                rule: ruleM ? ruleM[1].trim() : '',
                correction: corrM ? corrM[1].trim() : '',
                repeat: repeatM ? repeatM[1].trim() : ''
            });
        });
        return out;
    }

    function readDocument(report) {
        var cats = report.categories || {};
        var text = String(report.raw_content || '');
        var missing = notRetrieved(text);
        var type = missing ? 'Document' : classifyDocument(text, safeString(cats.doc_type));
        var doc = {
            report_date: safeString(report.report_date),
            type:        type,
            missing:     missing,
            url:         safeString(cats.doc_page_url || report.report_url),
            raw_content: missing ? '' : text,
            violations:  missing ? [] : extractViolations(text),
            report_number: grab(text, /Report Number[:\s]+(\d{7,})/i),
            incident_date: grab(text, /Date of Incident[s()]*[:\s]+(.+?)(?=\s+Nature of|\s+Summary of|\s+Suspected)/i),
            disposition:   '',
            reported:      '',
            action_facility: '',
            action_dhs:    '',
            fine_amount:   ''
        };
        if (type === 'Maltreatment Investigation') {
            doc.disposition = grab(text, /Disposition[:\s]+(.+?)(?=\s+(?:License Number|Investigator|Program Type))/i);
            doc.reported = grab(text, /Suspected Maltreatment Reported[:\s]+(.+?)(?=\s+(?:Date of Incident|Nature of|Summary of))/i);
            doc.action_facility = grab(text, /Action Taken by Facility[:\s]+(.+?)(?=\s+(?:Action Taken by Dep|Certification|Pursuant))/i);
            doc.action_dhs = grab(text, /Action Taken by Department[^:]*[:\s]+(.+?)(?=\s+(?:Certification|Pursuant|PO Box))/i);
        }
        if (/Fine|Settlement|Conditional/.test(type)) {
            doc.fine_amount = grab(text, /(?:fine in the amount of|amount of the fine is|fine of)\s*\$?([\d,]+(?:\.\d+)?)/i);
        }

        // One disposition can cover several allegations ("Allegation One: Maltreatment
        // not determined. Allegation Two: Maltreatment determined ..."); any
        // determined or substantiated allegation counts.
        var positive = doc.disposition.replace(/maltreatment\s+not\s+determined/gi, '');
        var determined = /maltreatment\s+determined|substantiated/i.test(positive);
        doc.outcome = missing ? 'neutral'
            : ADVERSE_TYPES[type] ? 'flagged'
            : type === 'Maltreatment Investigation' ? (determined ? 'flagged' : 'neutral')
            : 'neutral';
        doc.determined = determined;
        return doc;
    }

    function convertApiDataToFacilities(apiFacilities) {
        return apiFacilities.map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(readDocument)
                .sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });
            return {
                name:     safeString(info.facility_name),
                license:  safeString(info.program_name),
                category: safeString(info.program_category),
                address:  safeString(info.full_address),
                reports:  reports
            };
        });
    }

    function isFlagged(report) {
        return report.outcome === 'flagged';
    }

    function preview(doc) {
        if (doc.missing) return '';
        if (doc.disposition) return 'Disposition: ' + doc.disposition;
        if (doc.violations.length) return doc.violations[0].violation.slice(0, 260);
        if (doc.fine_amount) return 'Fine of $' + doc.fine_amount + '.';
        return '';
    }

    page.mount({
        state: 'Minnesota',
        emptyMessage: 'No facilities found in the database for Minnesota.',

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=MN')
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
            return [f.name, f.license, f.category, f.address]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        countFlagged: function (f) {
            return f.reports.reduce(function (sum, r) {
                return sum + (isFlagged(r) ? Math.max(1, r.violations.length) : 0);
            }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var actions = reports.filter(function (r) { return ADVERSE_TYPES[r.type]; }).length;
            var maltreatment = reports.filter(function (r) { return r.determined; }).length;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'document'), tone: 'neutral' }];
            if (actions) stats.push({ text: ctx.plural(actions, 'licensing action'), tone: 'flagged' });
            if (maltreatment) stats.push({ text: 'Maltreatment determined ' + (maltreatment === 1 ? 'once' : maltreatment + ' times'), tone: 'flagged' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [facility.category, facility.license ? 'License ' + facility.license : ''],
                address: facility.address,
                stats: stats
            };
        },

        report: function (doc, ctx) {
            var badges = [];
            if (doc.missing) {
                badges.push({ text: 'Not retrieved', tone: 'neutral' });
            } else if (doc.type === 'Maltreatment Investigation') {
                if (doc.determined) badges.push({ text: 'Maltreatment determined', tone: 'flagged' });
                else if (/inconclusive/i.test(doc.disposition)) badges.push({ text: 'Inconclusive', tone: 'neutral' });
                else if (/not\s+determined/i.test(doc.disposition)) badges.push({ text: 'Not determined', tone: 'neutral' });
            } else if (doc.violations.length) {
                badges.push({ text: ctx.plural(doc.violations.length, 'violation'), tone: 'flagged' });
                if (doc.violations.some(function (v) { return v.repeat; })) badges.push({ text: 'Repeat', tone: 'repeat' });
            } else if (ADVERSE_TYPES[doc.type]) {
                badges.push({ text: 'Licensing action', tone: 'flagged' });
            }
            if (doc.fine_amount) badges.push({ text: '$' + doc.fine_amount + ' fine', tone: 'flagged' });

            var time = parseDate(doc.report_date).getTime();
            return {
                date: ctx.formatDate(time) || 'Date not listed',
                type: doc.type,
                tone: doc.outcome,
                badges: badges,
                facts: [doc.report_number ? 'Report #' + doc.report_number : ''],
                link: { href: doc.url, text: 'Official document' },
                preview: preview(doc),
                body: function () {
                    if (doc.missing) {
                        return ui.note('The text of this document could not be retrieved from Minnesota DHS. Open the official document to read it.');
                    }
                    var html = '';
                    if (doc.type === 'Maltreatment Investigation') {
                        html += ui.paragraphs([
                            doc.disposition ? 'Disposition: ' + doc.disposition : '',
                            doc.reported ? 'Reported: ' + doc.reported : '',
                            doc.incident_date ? 'Date of incident: ' + doc.incident_date : '',
                            doc.action_facility ? 'Action taken by the facility: ' + doc.action_facility : '',
                            doc.action_dhs ? 'Action taken by DHS: ' + doc.action_dhs : ''
                        ]);
                    }
                    if (doc.violations.length) {
                        html += ui.heading(ctx.plural(doc.violations.length, 'violation') + ' cited');
                        html += doc.violations.map(function (v, i) {
                            return ui.finding({
                                title: 'Violation ' + (i + 1),
                                citation: v.rule.length <= 60 ? v.rule : '',
                                chips: v.repeat ? [{ text: 'Repeat violation', tone: 'repeat' }] : [],
                                evidence: [v.violation],
                                more: [
                                    v.rule.length > 60 ? { title: 'Rule violated', paragraphs: [v.rule] } : null,
                                    v.correction ? { title: 'Required correction', paragraphs: [v.correction] } : null,
                                    v.repeat ? { title: 'Earlier citation', paragraphs: [v.repeat] } : null
                                ],
                                tone: v.repeat ? 'repeat' : 'flagged'
                            });
                        }).join('');
                    } else if (!html) {
                        html += ui.note('The details of this ' + doc.type.toLowerCase() + ' are in the full text below and in the official document.');
                    }
                    html += ui.section('Full document text', ui.docText(doc.raw_content));
                    return html;
                }
            };
        }
    });
}());
