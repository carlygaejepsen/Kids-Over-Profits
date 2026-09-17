/**
 * Utah reports (/ut-reports/) -- adapter for report-page.js.
 *
 * Data comes from the ut_reports*.json datasets (localized as
 * utReportsData.jsonFileUrls): Office of Licensing inspections from the state
 * CCL API, one file per scrape. Each inspection carries the state's own
 * findings list {rule_number, rule_description, finding_text} and inspection
 * checklists (census, capacity, licensor, checklist_id).
 *
 * Outcome: the findings list comes from the state API, not from document
 * text, so an inspection with findings is flagged and one without is clean.
 * ut_reports_09112026.json reports findings_count_reported, and it matches
 * the list on all 339 of its inspections.
 *
 * Sources: the files overlap (1,898 inspections appear in two), and the
 * database (api/inspections-read.php?state=UT) has 27 inspections from
 * October 2025 to February 2026 that no file has, findings included. A
 * facility can have several distinct inspections on one day of the same type
 * (three investigation inspections, one with a finding), so inspections are
 * grouped by facility, date and type and paired across sources by the rules
 * they cite: copies collapse, same-day inspections stay separate. Newer
 * files come first and the database last; checklists are combined.
 *
 * Checklist PDFs link to the state's download by checklist_id. Local copies
 * exist for only part of the oldest file, and the March 2026 file points at
 * a scraper machine's disk, so the old viewer's links 404'd for those.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Utah reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;
    var themeData = window.utReportsData || {};

    var CHECKLIST_URL = 'https://cclapi.dlbc.utah.gov/api/public/checklist/';

    // "2024-12-16" -> local Date.
    function parseDate(text) {
        var m = safeString(text).match(/^(\d{4})-(\d{2})-(\d{2})/);
        return m ? new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10)) : new Date(0);
    }

    /** ut_reports_03182026.json -> 20260318; ut_reports.json -> 0 (oldest). */
    function datasetRank(url) {
        var m = safeString(url).match(/ut_reports[-_]?(\d{8})\.json/i);
        if (!m) return 0;
        var t = m[1];
        return /^(19|20)/.test(t) ? parseInt(t, 10) : parseInt(t.slice(4) + t.slice(0, 4), 10);
    }

    function readChecklists(list) {
        return (Array.isArray(list) ? list : []).filter(function (c) { return c && c.checklist_id; }).map(function (c) {
            return {
                id: String(c.checklist_id),
                census: c.census === null || c.census === undefined ? '' : String(c.census),
                capacity: c.capacity === null || c.capacity === undefined ? '' : String(c.capacity),
                licensor: safeString(c.licensor)
            };
        });
    }

    function readJsonFindings(list) {
        return (Array.isArray(list) ? list : []).filter(Boolean).map(function (x) {
            return { rule: safeString(x.rule_number), title: safeString(x.rule_description), text: safeString(x.finding_text) };
        });
    }

    /**
     * The API rows are written by utah_citation_scraper.py in a fixed format:
     * "R380-80-5(4): Rule title \u2014 Finding text" per finding and
     * "Checklist 797279: Census: 16; Capacity: 16; Contact: X; Licensor: Y".
     */
    function readApiReport(report) {
        var findings = [];
        var checklists = [];
        safeString(report.raw_content).split('\n').forEach(function (line) {
            var l = line.trim();
            var c = l.match(/^Checklist\s+(\d+):\s*(.*)$/);
            if (c) {
                var fields = {};
                c[2].split(/;\s*/).forEach(function (pair) {
                    var kv = pair.match(/^(\w+):\s*(.*)$/);
                    if (kv) fields[kv[1].toLowerCase()] = kv[2];
                });
                checklists.push({ id: c[1], census: fields.census || '', capacity: fields.capacity || '', licensor: fields.licensor || '' });
                return;
            }
            var f = l.match(/^(R\d[\w.()\-]*):\s*(.*?)\s+\u2014\s+(.*)$/);
            if (f) {
                findings.push({ rule: f[1], title: f[2], text: f[3] });
            } else if (l && findings.length && !checklists.length) {
                // Finding text with its own line breaks continues here.
                findings[findings.length - 1].text += ' ' + l;
            }
        });
        var cats = report.categories || {};
        var m = safeString(cats['Inspection Date'] || report.report_date).match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
        return {
            date: m ? m[3] + '-' + m[1] + '-' + m[2] : '',
            types: safeString(cats['Inspection Type']),
            findings: findings,
            checklists: checklists
        };
    }

    /** Share of the smaller finding list's rules that the other list also cites. */
    function ruleOverlap(a, b) {
        var rulesA = {};
        a.forEach(function (f) { rulesA[f.rule] = true; });
        var shared = b.filter(function (f) { return rulesA[f.rule]; }).length;
        var smaller = Math.min(a.length, b.length);
        return smaller ? shared / smaller : 0;
    }

    function ruleSignature(findings) {
        return findings.map(function (f) { return f.rule; }).sort().join('|');
    }

    /**
     * sources: newest first, each { facilities: [{ id, fields, inspections:
     * [{ date, types, findings, checklists }] }] }. Inspections are grouped by
     * facility, date and type; each source's entries pair with merged ones by
     * the rules they cite, so cross-source copies collapse while same-day
     * inspections stay separate.
     */
    function merge(sources) {
        var facilities = {};
        sources.forEach(function (source) {
            var grouped = {};
            var groupOrder = [];
            source.facilities.forEach(function (raw) {
                var fid = raw.id;
                var f = facilities[fid];
                if (!f) f = facilities[fid] = { id: fid, fields: {}, groups: {}, order: [] };
                Object.keys(raw.fields).forEach(function (k) {
                    if (f.fields[k] === undefined || f.fields[k] === '') f.fields[k] = raw.fields[k];
                });
                raw.inspections.forEach(function (insp) {
                    if (!insp.date) return;
                    var key = insp.date + '#' + insp.types.toLowerCase();
                    var gk = fid + '@' + key;
                    if (!grouped[gk]) { grouped[gk] = { fid: fid, key: key, list: [] }; groupOrder.push(gk); }
                    grouped[gk].list.push(insp);
                });
            });
            groupOrder.forEach(function (gk) {
                var g = grouped[gk];
                var f = facilities[g.fid];
                var merged = f.groups[g.key];
                if (!merged) { merged = f.groups[g.key] = []; f.order.push(g.key); }
                var used = {};
                g.list.forEach(function (insp) {
                    var sig = ruleSignature(insp.findings);
                    var idx = -1;
                    for (var i = 0; i < merged.length; i++) {
                        if (!used[i] && merged[i].sig === sig) { idx = i; break; }
                    }
                    // Sources scraped at different times can list more or fewer
                    // findings for one inspection (12 vs 16): pair when most
                    // cited rules overlap, and keep the fuller list.
                    if (idx === -1 && sig) {
                        for (var j = 0; j < merged.length; j++) {
                            if (!used[j] && merged[j].sig && ruleOverlap(merged[j].findings, insp.findings) >= 0.5) { idx = j; break; }
                        }
                    }
                    if (idx === -1) {
                        merged.push({ sig: sig, date: insp.date, types: insp.types, findings: insp.findings, checklists: [] });
                        idx = merged.length - 1;
                    }
                    used[idx] = true;
                    var target = merged[idx];
                    if (insp.findings.length > target.findings.length) {
                        target.findings = insp.findings;
                        target.sig = sig;
                    }
                    var seen = {};
                    target.checklists.forEach(function (c) { seen[c.id] = true; });
                    insp.checklists.forEach(function (c) {
                        if (!seen[c.id]) { seen[c.id] = true; target.checklists.push(c); }
                    });
                });
            });
        });

        return Object.keys(facilities).map(function (fid) {
            var f = facilities[fid];
            var reports = [];
            f.order.forEach(function (key) { reports = reports.concat(f.groups[key]); });
            reports.sort(function (a, b) { return parseDate(b.date) - parseDate(a.date); });
            var x = f.fields;
            return {
                id: fid.indexOf('name:') === 0 ? '' : fid,
                name: x.name || ('Facility ' + fid),
                address: x.address || '',
                category: x.category || '',
                capacity: x.capacity || '',
                director: x.director || '',
                conditional: x.conditional === true,
                reports: reports
            };
        });
    }

    function jsonSource(data) {
        return {
            facilities: (Array.isArray(data) ? data : []).filter(Boolean).map(function (raw) {
                return {
                    id: safeString(raw.facility_id) || ('name:' + safeString(raw.name).toLowerCase()),
                    fields: {
                        name: safeString(raw.name),
                        address: safeString(raw.address),
                        category: safeString(raw.program_category),
                        capacity: safeString(raw.capacity),
                        director: safeString(raw.executive_director),
                        conditional: raw.conditional === true || raw.licensing_action === 'Conditional' ? true : ''
                    },
                    inspections: (raw.inspections || []).filter(Boolean).map(function (insp) {
                        return {
                            date: safeString(insp.inspection_date),
                            types: Array.isArray(insp.inspection_types) ? insp.inspection_types.join(', ') : safeString(insp.inspection_types),
                            findings: readJsonFindings(insp.findings),
                            checklists: readChecklists(insp.checklists)
                        };
                    })
                };
            })
        };
    }

    function apiSource(data) {
        return {
            facilities: ((data && data.facilities) || []).map(function (raw) {
                var info = raw.facility_info || {};
                return {
                    id: safeString(info.program_name) || ('name:' + safeString(info.facility_name).toLowerCase()),
                    fields: {
                        name: safeString(info.facility_name),
                        address: safeString(info.full_address),
                        category: safeString(info.program_category),
                        capacity: safeString(info.bed_capacity),
                        conditional: safeString(info.action) === 'Conditional' ? true : ''
                    },
                    inspections: (raw.reports || []).map(readApiReport)
                };
            })
        };
    }

    function isFlagged(report) {
        return report.findings.length > 0;
    }

    function fetchJson(url) {
        return fetch(url).then(function (resp) {
            if (!resp.ok) throw new Error('Failed to fetch ' + url + ': ' + resp.status);
            return resp.json();
        });
    }

    page.mount({
        state: 'Utah',
        emptyMessage: 'No Utah inspection data found.',

        load: function () {
            var urls = (Array.isArray(themeData.jsonFileUrls) ? themeData.jsonFileUrls : [])
                .filter(function (u) { return /ut_reports.*\.json(?:[?#].*)?$/i.test(u); });
            var ranked = urls.map(function (url) { return { url: url, rank: datasetRank(url) }; })
                .sort(function (a, b) { return b.rank - a.rank; });
            var files = Promise.all(ranked.map(function (ds) { return fetchJson(ds.url); }));
            // The database has inspections no file does (Oct 2025 - Feb 2026).
            var api = fetchJson('/wp-content/themes/child/api/inspections-read.php?state=UT').catch(function (error) {
                console.warn('Utah reports: API unavailable, using the data files only', error);
                return null;
            });
            return Promise.all([files, api]).then(function (results) {
                var sources = results[0].map(jsonSource);
                if (results[1]) sources.push(apiSource(results[1]));
                if (!sources.length) throw new Error('No Utah data could be loaded');
                // The later of the newest file's date and the database scrape.
                var newest = ranked.length ? String(ranked[0].rank) : '';
                var fileStamp = newest.length === 8 ? newest.slice(0, 4) + '-' + newest.slice(4, 6) + '-' + newest.slice(6, 8) + 'T12:00:00' : '';
                var apiStamp = safeString(results[1] && results[1].scraped_timestamp);
                var stamp = (Date.parse(apiStamp) || 0) > (Date.parse(fileStamp) || 0) ? apiStamp : (fileStamp || apiStamp);
                return { facilities: merge(sources), scrapedTimestamp: stamp };
            });
        },

        facilityName: function (f) { return f.name; },

        searchText: function (f) {
            return [f.name, f.id, f.address, f.director].join(' ').toLowerCase();
        },

        reportTime: function (report) { return parseDate(report.date).getTime(); },
        isFlagged: isFlagged,

        countFlagged: function (facility) {
            return facility.reports.reduce(function (sum, r) { return sum + r.findings.length; }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var findings = reports.reduce(function (sum, r) { return sum + r.findings.length; }, 0);
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'inspection'), tone: 'neutral' }];
            if (findings) stats.push({ text: ctx.plural(findings, 'finding'), tone: 'flagged' });
            else if (reports.length) stats.push({ text: 'No findings', tone: 'clean' });
            if (facility.conditional) stats.push({ text: 'Conditional license', tone: 'flagged' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [
                    facility.category || 'Utah licensed program',
                    facility.id ? 'Facility\u00a0ID\u00a0' + facility.id : '',
                    facility.capacity ? 'Capacity ' + facility.capacity : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var count = report.findings.length;
            var first = report.checklists[0] || {};
            var census = first.census ? 'Census ' + first.census + (first.capacity ? ' of ' + first.capacity : '') : '';

            var links = report.checklists.map(function (c, i) {
                return {
                    href: CHECKLIST_URL + encodeURIComponent(c.id) + '?dl=1',
                    text: report.checklists.length > 1 ? 'Checklist ' + (i + 1) + ' (PDF)' : 'Checklist (PDF)'
                };
            });

            return {
                date: ctx.formatDate(parseDate(report.date).getTime()) || report.date,
                type: report.types || 'Inspection',
                tone: count ? 'flagged' : 'clean',
                badges: [count
                    ? { text: ctx.plural(count, 'finding'), tone: 'flagged' }
                    : { text: 'No findings', tone: 'clean' }],
                facts: [
                    first.licensor ? 'Licensor ' + first.licensor : '',
                    census
                ],
                link: links[0] || null,
                links: links.slice(1),
                preview: report.findings.map(function (f) { return f.title || f.rule; })
                    .filter(function (t, i, all) { return t && all.indexOf(t) === i; }).join('; '),
                body: function () {
                    if (!count) {
                        return ui.note(report.checklists.length
                            ? 'The Office of Licensing recorded no findings for this inspection. The checklist has the details.'
                            : 'The Office of Licensing recorded no findings for this inspection.');
                    }
                    return ui.heading(ctx.plural(count, 'finding'))
                        + report.findings.map(function (f) {
                            return ui.finding({
                                title: f.title || 'Rule not met',
                                citation: f.rule,
                                evidence: [f.text]
                            });
                        }).join('');
                }
            };
        }
    });
}());
