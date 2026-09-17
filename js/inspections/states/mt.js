/**
 * Montana reports (/mt-reports/) -- adapter for report-page.js.
 *
 * Data comes from the static js/data/mt_reports.json (localized as
 * mtReportsData.jsonFileUrls): Department of Public Health and Human Services
 * licensing surveys (statements of deficiencies) for youth care facilities,
 * therapeutic group homes and private alternative adolescent residential
 * programs, extracted from PDF text. The API has no Montana rows.
 *
 * What the extraction gives reliably, and what it does not:
 *   - The list of cited rules (Issues[].Rule) is reliable, and a statement of
 *     deficiencies lists only rules that were not met: every survey with
 *     rules is flagged, one deficiency per rule. Repeat Deficiency is read
 *     from the rule's own row.
 *   - The findings text is NOT reliably attached to its rule: in 185 of 749
 *     cases a rule's "Findings" describe the previous rule's topic. So
 *     findings are shown as the survey's findings, in document order
 *     (Header.Description, then each item), never under a specific rule.
 *     Plans of correction are treated the same way.
 *   - Header fields are shifted on 58 surveys (the license number in "Survey
 *     Team Leader", the address in "Survey Type"). Fields are identified by
 *     the shape of their values, not their labels.
 * Thirteen surveys with no rules extracted are neutral.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Montana reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;
    var themeData = window.mtReportsData || {};

    var DATE = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/;
    var LICENSE = /^(?:\d{4,5}-\d{2}|0{3,}\d+)$/;
    var SURVEY_TYPE = /^(?:Renewal|Complaint|Follow[\s-]?Up|Provisional Status|Initial|Annual)\b.*Inspection$/i;
    var ZIP = /^\d{5}(?:-\d{4})?$/;
    var PHONE = /^\(?\d{3}\)?[\s.-]?\d{3}-\d{4}$/;
    var STREET = /^\d+\s+\S+.*\b(?:St|Street|Rd|Road|Dr|Drive|Ave|Avenue|Blvd|Way|Ln|Lane|Ct|Hwy|Pl|Place|Loop|Trail)\.?$|^\d+\s+[NSEW]\.?\s+\S+/i;
    var TEAM_LEADER = /^[A-Z][A-Za-z'-]+,\s+[A-Z][A-Za-z'-]+$/;
    var NOT_CITY = /^(?:Item #|\*+)$/;

    function toDate(text) {
        var m = safeString(text).match(DATE);
        return m ? new Date(parseInt(m[3], 10), parseInt(m[1], 10) - 1, parseInt(m[2], 10)) : null;
    }

    /** Header values by shape; the labels are unreliable on shifted surveys. */
    function readHeader(header) {
        var h = header || {};
        var values = Object.keys(h).filter(function (k) {
            return k !== 'Facility' && k !== 'Administrator' && k !== 'Description';
        }).map(function (k) { return safeString(h[k]); });

        var find = function (re) {
            for (var i = 0; i < values.length; i++) if (re.test(values[i])) return values[i];
            return '';
        };
        // Survey date is the earlier of the survey and response-due dates.
        var dates = values.map(toDate).filter(Boolean).sort(function (a, b) { return a - b; });
        var type = find(SURVEY_TYPE);
        var cityField = safeString(h.City);
        var city = /^[A-Za-z][A-Za-z .]{1,30}$/.test(cityField) && !SURVEY_TYPE.test(cityField) && !NOT_CITY.test(cityField)
            ? cityField
            : (values.filter(function (v) {
                return /^[A-Z][A-Za-z]+(?: [A-Z][A-Za-z]+){0,2}$/.test(v) && !SURVEY_TYPE.test(v) && !NOT_CITY.test(v) && v !== safeString(h['Survey Team Leader']);
            })[0] || '');

        return {
            facility: safeString(h.Facility).replace(/[,\s]+$/, ''),
            administrator: safeString(h.Administrator),
            license: find(LICENSE),
            type: type,
            date: dates.length ? dates[0] : null,
            team_leader: TEAM_LEADER.test(safeString(h['Survey Team Leader'])) ? safeString(h['Survey Team Leader']) : '',
            street: find(STREET),
            city: city,
            zip: find(ZIP),
            phone: find(PHONE)
        };
    }

    /** "37.97.186-11 YOUTH CARE FACILITY (YCF): PHYSICAL ENVIRONMENT" -> parts. */
    function readRule(rule) {
        var text = safeString(rule);
        var m = text.match(/^(\d+\.\d+\.\d+)(?:-(\d+))?\s*(.*)$/);
        if (!m) return { section: '', citation: '', program: '', topic: text };
        var rest = m[3];
        var colon = rest.indexOf(':');
        var program = colon === -1 ? rest : rest.slice(0, colon);
        var topic = colon === -1 ? '' : rest.slice(colon + 1);
        return {
            section: m[1],
            citation: 'ARM ' + m[1] + (m[2] ? '(' + m[2] + ')' : ''),
            program: sentenceCase(program).replace(/^(?:ycf|youth care facilities \(YCF\))$/i, 'Youth care facility (YCF)'),
            topic: sentenceCase(topic)
        };
    }

    function sentenceCase(text) {
        var t = safeString(text);
        if (!t || /[a-z]/.test(t)) return t;
        return t.toLowerCase()
            .replace(/^./, function (c) { return c.toUpperCase(); })
            .replace(/\((ycf|tgh|paarp)\)/g, function (m, a) { return '(' + a.toUpperCase() + ')'; });
    }

    /** PDF text: joins lines broken mid-sentence, keeps numbered items apart. */
    function paragraphs(text) {
        var out = [];
        safeString(text).replace(/\r/g, '').split(/\n\s*\n/).forEach(function (block) {
            var current = '';
            block.split('\n').forEach(function (line) {
                var l = line.trim();
                if (!l) return;
                var startsItem = /^(?:\(?\d{1,2}[).]|[a-z][.)]|FINDINGS?:|Findings?:|THE INTENT|The intent|As evidenced)/.test(l);
                if (current && (startsItem || /[.:;]$/.test(current) && /^[A-Z]/.test(l))) {
                    out.push(current);
                    current = l;
                } else {
                    current = current ? current + ' ' + l : l;
                }
            });
            if (current) out.push(current);
        });
        return out;
    }

    // Form labels the extraction glued onto rule titles.
    var TITLE_NOISE = /\s+(?:providers?\s+plan\s+of\s+correction|f\w*ings?)\s*:?\s*$/i;
    var TITLE_REPEAT = /\s+repeat\s+deficiency\b.*$/i;

    var CUT_TITLES = /^(?:Behavior mana|Discharge sum|Infection con|Placement agr|Privacy and i|Staff backgro|Youth orienta|Youth to awak|Religion and|Money and adolescent training and|Use of crisis)$/;

    function readSurvey(raw) {
        var header = readHeader(raw.Header);
        var issues = Array.isArray(raw.Issues) ? raw.Issues : [];
        var rules = [];
        issues.forEach(function (i) {
            if (!i || !safeString(i.Rule)) return;
            // Some rows hold two rules ("... VOLUNTEERS 37.97.147-2 YOUTH CARE ...").
            safeString(i.Rule).split(/\s+(?=\d+\.\d+\.\d+-\d+\s)/).forEach(function (part) {
                var r = readRule(part);
                r.repeat = i['Repeat Deficiency'] === true || i['Repeat Deficiency'] === 'Yes' || TITLE_REPEAT.test(r.topic);
                r.topic = r.topic.replace(TITLE_REPEAT, '').replace(TITLE_NOISE, '');
                rules.push(r);
            });
        });
        var findings = [safeString(raw.Header && raw.Header.Description)]
            .concat(issues.map(function (i) { return safeString(i && i.Findings); }))
            .filter(Boolean);
        var plans = issues.map(function (i) { return safeString(i && i['Plan of Correction']); }).filter(Boolean);
        return {
            header: header,
            rules: rules,
            findings: findings,
            plans: plans,
            outcome: rules.length ? 'flagged' : 'neutral'
        };
    }

    function facilityKey(name) {
        return safeString(name).replace(/[,.\s]+$/, '').toUpperCase();
    }

    function group(rawSurveys) {
        var byKey = {};
        // On shifted headers the surveyor's name lands in Administrator.
        var surveyors = {};
        rawSurveys.forEach(function (raw) {
            var leader = safeString(raw && raw.Header && raw.Header['Survey Team Leader']);
            if (TEAM_LEADER.test(leader)) surveyors[leader] = true;
        });
        rawSurveys.forEach(function (raw) {
            if (!raw || !raw.Header) return;
            var survey = readSurvey(raw);
            if (surveyors[survey.header.administrator]) survey.header.administrator = '';
            var key = facilityKey(survey.header.facility) || 'UNKNOWN';
            var f = byKey[key];
            if (!f) {
                f = byKey[key] = { name: survey.header.facility, license: '', administrator: '', address: '', reports: [] };
            }
            // Fill facility details from the newest survey that has them.
            var newer = !f._latest || (survey.header.date && survey.header.date > f._latest);
            if (survey.header.license && (newer || !f.license)) f.license = survey.header.license;
            if (survey.header.administrator && (newer || !f.administrator)) f.administrator = survey.header.administrator;
            var address = [survey.header.street, survey.header.city, survey.header.zip ? 'MT ' + survey.header.zip : '']
                .filter(Boolean).join(', ');
            if (survey.header.street && (newer || !f.address)) f.address = address;
            if (newer && survey.header.date) f._latest = survey.header.date;
            f.reports.push(survey);
        });
        // Rule titles are cut at the PDF column edge ("Care and guid"). A title
        // ending mid-word is completed when one longer title in the data
        // continues it ("Care and guidance"); otherwise it is left as is.
        var allRules = [];
        Object.keys(byKey).forEach(function (k) {
            byKey[k].reports.forEach(function (r) { allRules = allRules.concat(r.rules); });
        });
        ['program', 'topic'].forEach(function (field) {
            var known = {};
            allRules.forEach(function (rule) { if (rule[field]) known[rule[field].toLowerCase()] = rule[field]; });
            var names = Object.keys(known);
            allRules.forEach(function (rule) {
                var frag = rule[field].toLowerCase();
                if (!frag) return;
                var longer = names.filter(function (n) {
                    return n.length > frag.length && n.indexOf(frag) === 0 && /[a-z]/.test(n.charAt(frag.length));
                });
                // Several candidates are fine when they are one title cut at different points.
                longer.sort(function (x, y) { return y.length - x.length; });
                if (longer.length && longer.every(function (n) { return longer[0].indexOf(n) === 0; })) {
                    rule[field] = known[longer[0]];
                }
            });
        });

        // A rule section has one title, so a shorter title is also completed from
        // the same section number even at a word boundary ("Treatment" ->
        // "Treatment plan" for ARM 37.97.907).
        var bySection = {};
        allRules.forEach(function (rule) {
            if (rule.section && rule.topic.length > (bySection[rule.section] || '').length) bySection[rule.section] = rule.topic;
        });
        allRules.forEach(function (rule) {
            var full = bySection[rule.section];
            if (full && full.length > rule.topic.length && full.toLowerCase().indexOf(rule.topic.toLowerCase()) === 0) rule.topic = full;
        });

        // Cut titles nothing in the data completes (the file is a fixed snapshot):
        // marked as cut rather than guessed.
        allRules.forEach(function (rule) {
            if (CUT_TITLES.test(rule.topic)) rule.topic += '\u2026';
        });

        return Object.keys(byKey).map(function (k) {
            var f = byKey[k];
            delete f._latest;
            f.reports.sort(function (a, b) { return (b.header.date || 0) - (a.header.date || 0); });
            return f;
        });
    }

    function isFlagged(report) {
        return report.outcome === 'flagged';
    }

    page.mount({
        state: 'Montana',
        emptyMessage: 'No Montana surveys found.',

        load: function () {
            var urls = Array.isArray(themeData.jsonFileUrls) ? themeData.jsonFileUrls : [];
            if (!urls.length) return Promise.reject(new Error('Montana data file is not configured'));
            return fetch(urls[0]).then(function (resp) {
                if (!resp.ok) throw new Error('Failed to fetch data: ' + resp.status);
                return resp.json().then(function (raw) {
                    var facilities = group(Array.isArray(raw) ? raw : []);
                    // The file carries no scrape timestamp, and its Last-Modified
                    // header is reset by every deploy, so "Last updated" is the
                    // newest survey date: the date the data is current through.
                    var newest = facilities.reduce(function (max, f) {
                        return f.reports.reduce(function (m, r) {
                            return r.header.date && r.header.date.getTime() > m ? r.header.date.getTime() : m;
                        }, max);
                    }, 0);
                    return { facilities: facilities, scrapedTimestamp: newest ? new Date(newest).toISOString() : '' };
                });
            });
        },

        facilityName: function (f) { return f.name; },

        searchText: function (f) {
            return [f.name, f.license, f.administrator, f.address].join(' ').toLowerCase();
        },

        reportTime: function (report) { return report.header.date ? report.header.date.getTime() : 0; },
        isFlagged: isFlagged,

        countFlagged: function (facility) {
            return facility.reports.reduce(function (sum, r) { return sum + r.rules.length; }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var cited = reports.reduce(function (sum, r) { return sum + r.rules.length; }, 0);
            var repeats = reports.reduce(function (sum, r) { return sum + r.rules.filter(function (x) { return x.repeat; }).length; }, 0);
            var latest = reports.reduce(function (max, r) { return Math.max(max, r.header.date ? r.header.date.getTime() : 0); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'survey'), tone: 'neutral' }];
            if (cited) stats.push({ text: ctx.plural(cited, 'deficiency', 'deficiencies') + ' cited', tone: 'flagged' });
            if (repeats) stats.push({ text: ctx.plural(repeats, 'repeat deficiency', 'repeat deficiencies'), tone: 'repeat' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [
                    'Montana licensed youth program',
                    facility.license ? 'License\u00a0' + facility.license : '',
                    facility.administrator ? 'Administrator ' + facility.administrator : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var count = report.rules.length;
            var repeats = report.rules.filter(function (r) { return r.repeat; }).length;
            var badges = [];
            if (count) badges.push({ text: ctx.plural(count, 'deficiency', 'deficiencies'), tone: 'flagged' });
            if (repeats) badges.push({ text: ctx.plural(repeats, 'repeat'), tone: 'repeat' });

            var topics = [];
            report.rules.forEach(function (r) {
                if (r.topic && topics.indexOf(r.topic) === -1) topics.push(r.topic);
            });

            return {
                date: report.header.date ? ctx.formatDate(report.header.date.getTime()) : '',
                type: report.header.type || 'Licensing survey',
                tone: report.outcome,
                badges: badges,
                facts: [report.header.team_leader ? 'Surveyor ' + report.header.team_leader : ''],
                link: null,
                preview: topics.join('; '),
                body: function () {
                    var html = '';
                    if (!count) {
                        html += ui.note('No cited rules could be read from this survey. The extracted text is below.');
                    } else {
                        html += ui.heading(ctx.plural(count, 'rule') + ' not met');
                        html += report.rules.map(function (r) {
                            return ui.finding({
                                title: r.topic || r.program || 'Cited rule',
                                citation: r.citation,
                                chips: [
                                    r.topic && r.program ? { text: r.program, tone: 'neutral' } : null,
                                    r.repeat ? { text: 'Repeat deficiency', tone: 'repeat' } : null
                                ],
                                tone: r.repeat ? 'repeat' : 'flagged'
                            });
                        }).join('');
                    }
                    if (report.findings.length) {
                        html += ui.section('Surveyor\u2019s findings',
                            ui.note('As extracted from the survey, in document order. The extraction does not reliably match each finding to its rule.')
                            + ui.paragraphs(report.findings.reduce(function (all, f) { return all.concat(paragraphs(f)); }, [])),
                            { open: true });
                    }
                    if (report.plans.length) {
                        html += ui.section('Facility\u2019s plan of correction',
                            ui.paragraphs(report.plans.reduce(function (all, p) { return all.concat(paragraphs(p)); }, [])));
                    }
                    return html;
                }
            };
        }
    });
}());
