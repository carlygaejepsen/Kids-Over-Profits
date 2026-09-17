/**
 * Florida reports (/fl-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=FL, two agencies:
 *   AHCA  Agency for Health Care Administration licensing surveys of RTCs and
 *         therapeutic group homes. Each survey carries its own structured
 *         deficiency list (code, requirement, correction date).
 *   DJJ   Department of Juvenile Justice documents for residential commitment
 *         and detention programs:
 *           QI Residential / QI Detention  quality improvement reviews; each
 *             finding is an indicator rated "Failed Compliance" or "Limited
 *             Compliance",
 *           PREA  Prison Rape Elimination Act audits, with a standards summary
 *             ("Number of Standards Not Met: 0"),
 *           SPEP  program effectiveness evaluations of a therapy service --
 *             not compliance findings, never flagged.
 *
 * Flagged: AHCA surveys with deficiencies, QI reviews with failed or limited
 * indicators, PREA audits with standards not met. Clean: the same documents
 * showing none (QI only when the text has no failed/limited rating at all,
 * since follow-up reviews quote earlier ratings).
 *
 * Ten AHCA rows are pagination rows the scraper parsed as surveys (survey date
 * "1234567", inspection type "1"); they are not shown. AHCA surveys with no
 * deficiencies carry a placeholder "None" entry, which is not counted.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Florida reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var MONTHS = { january: 0, february: 1, march: 2, april: 3, may: 4, june: 5, july: 6,
                   august: 7, september: 8, october: 9, november: 10, december: 11 };

    function parseDate(dateStr) {
        var text = safeString(dateStr);
        var m = text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
        if (m) return new Date(parseInt(m[3], 10), parseInt(m[1], 10) - 1, parseInt(m[2], 10));
        m = text.match(/^([A-Za-z]+)\s+(\d{1,2}),\s*(\d{4})/);
        if (m && MONTHS[m[1].toLowerCase()] !== undefined) {
            return new Date(parseInt(m[3], 10), MONTHS[m[1].toLowerCase()], parseInt(m[2], 10));
        }
        return new Date(0);
    }

    function flat(text) {
        return String(text || '').replace(/\s+/g, ' ').trim();
    }

    function sentenceCase(text) {
        var t = safeString(text).toLowerCase();
        return t ? t.charAt(0).toUpperCase() + t.slice(1) : '';
    }

    // ---- AHCA ---------------------------------------------------------------

    function isPaginationRow(cats) {
        return /^\d+$/.test(safeString(cats.report_type)) && !(parseDate(cats.survey_date).getTime() > 0);
    }

    // "OPERATING STDS - FACILITY BEDROOMS(5)(b)11. Bedrooms. a. Children ..." ->
    // { heading: "Facility bedrooms", section: "(5)(b)11", rule: "Bedrooms. a. ..." }.
    // The heading is the leading capitals, which often run straight into the
    // rule text ("WRITTEN PROCEDURESWritten procedures.").
    function splitRequirement(text) {
        var t = flat(text);
        var m = t.match(/^([A-Z0-9][A-Z0-9 ,&\/'.;-]*?[A-Z0-9)])\s*(?=\(\d|[A-Z][a-z]|\[|\d{3}\.|$)/);
        if (!m) return { heading: '', section: '', rule: t };
        var rest = t.slice(m[0].length);
        var sec = rest.match(/^(\([0-9a-z]+\)(?:\([0-9a-z]+\))*[0-9a-z]*)\.?\s*/);
        var heading = m[1].replace(/^(?:OPERATING|PROGRAM)\s+(?:STDS|STANDARDS)\s*-\s*/, '').toLowerCase();
        return {
            heading: heading.charAt(0).toUpperCase() + heading.slice(1),
            section: sec ? sec[1] : '',
            rule: sec ? rest.slice(sec[0].length) : rest
        };
    }

    function readAhca(report, cats) {
        // Surveys with no deficiencies still carry one placeholder entry (code
        // "None", requirement "NoneNone", deficiency_count 1): 112 of them. The
        // previous viewer counted that as a deficiency. "INITIAL COMMENTS" is not
        // a deficiency either.
        var deficiencies = (Array.isArray(cats.deficiencies) ? cats.deficiencies : []).map(function (d) {
            return {
                code: safeString(d.deficiency),
                requirement: flat(d.requirement_description),
                corrected: safeString(d.correction_date)
            };
        }).filter(function (d) {
            if (/^none$/i.test(d.code) && /^(?:none)*$/i.test(d.requirement.replace(/\s+/g, ''))) return false;
            return !/^INITIAL COMMENTS$/i.test(d.requirement);
        }).map(function (d) {
            var parts = splitRequirement(d.requirement);
            d.heading = parts.heading;
            d.section = parts.section;
            d.rule = parts.rule;
            return d;
        });
        var count = deficiencies.length;
        var type = safeString(cats.report_type);
        return {
            agency: 'AHCA',
            kind: 'ahca',
            date: safeString(cats.survey_date || report.report_date),
            label: type === 'Complaint' ? 'Complaint investigation'
                : type ? sentenceCase(type) + ' survey' : 'Licensing survey',
            track_id: safeString(cats.track_id),
            items: deficiencies,
            count: count,
            status: count > 0 ? 'flagged' : 'clean',
            url: '',
            raw_content: ''
        };
    }

    // ---- DJJ ----------------------------------------------------------------

    function readDjj(report, cats) {
        var type = safeString(cats.report_type);
        var text = String(report.raw_content || '');
        var base = {
            agency: 'DJJ',
            date: safeString(report.report_date),
            fiscal_year: safeString(cats.fiscal_year),
            service: safeString(cats.service_type),
            cycle: safeString(cats.cycle),
            unmatched: !!cats.unmatched,
            url: safeString(cats.pdf_url),
            raw_content: text,
            items: [],
            count: 0,
            status: 'neutral'
        };

        if (/^QI/.test(type)) {
            base.kind = 'qi';
            base.label = 'Quality improvement review' + (/Detention/.test(type) ? ' (detention)' : ' (residential)');
            base.items = (Array.isArray(cats.findings) ? cats.findings : []).map(function (f) {
                return { rule: safeString(f.rule), text: flat(f.excerpt), rating: safeString(f.rating) };
            }).filter(function (f) { return /failed|limited/i.test(f.rating); });
            base.count = base.items.length;
            if (base.count) base.status = 'flagged';
            else if (!/Failed Compliance|Limited Compliance/i.test(text) && /Satisfactory Compliance/i.test(text)) base.status = 'clean';
            return base;
        }

        if (type === 'PREA') {
            var t = flat(text);
            base.kind = 'prea';
            base.label = 'PREA audit';
            var DATE = '([A-Za-z]+\\s+\\d{1,2},\\s*\\d{4})';
            var dateM = t.match(new RegExp('Date of Final Audit Report:\\s*' + DATE, 'i'))
                || t.match(new RegExp('Date of Report:?\\s*' + DATE, 'i'))
                || t.match(new RegExp('Date of Interim Audit Report:\\s*' + DATE, 'i'))
                || t.match(new RegExp('Date of facility visit:\\s*' + DATE, 'i'));
            if (dateM) base.date = dateM[1];
            var num = function (re) { var m = t.match(re); return m ? parseInt(m[1], 10) : null; };
            base.exceeded = num(/Number of Standards Exceeded:\s*(\d+)/i);
            base.met = num(/Number of Standards Met:\s*(\d+)/i);
            base.notMet = num(/Number of Standards Not Met:\s*(\d+)/i);
            base.items = (Array.isArray(cats.findings) ? cats.findings : [])
                .filter(function (f) { return /does not meet/i.test(f.rating || ''); })
                .map(function (f) { return { rule: safeString(f.rule), text: '', rating: 'Does not meet standard' }; });
            base.count = base.notMet || base.items.length;
            if (base.count > 0) base.status = 'flagged';
            else if (base.notMet === 0) base.status = 'clean';
            return base;
        }

        base.kind = 'spep';
        base.label = 'Program evaluation (SPEP)';
        var period = flat(text).match(/SPEP Review Period:\s*([A-Za-z]+ \d{1,2}, \d{4}\s*-\s*[A-Za-z]+ \d{1,2}, \d{4})/i);
        base.period = period ? period[1].replace(/\s*-\s*/, ' to ') : '';
        return base;
    }

    // ---- Facilities ---------------------------------------------------------

    // Internal program codes ("lakeacademy") are not display names.
    function looksLikeSlug(name) {
        return /^[a-z0-9]+$/.test(safeString(name));
    }

    function preferBetterName(a, b) {
        if (looksLikeSlug(a) && !looksLikeSlug(b)) return b;
        if (!looksLikeSlug(a) && looksLikeSlug(b)) return a;
        return a.length >= b.length ? a : b;
    }

    function convertApiDataToFacilities(apiFacilities) {
        var byCode = {};
        var result = [];
        apiFacilities.forEach(function (facility) {
            var info = facility.facility_info || {};
            var code = safeString(info.program_name);
            var agency = /^AHCA-/.test(code) ? 'AHCA' : /^DJJ-/.test(code) ? 'DJJ' : '';
            var reports = [];
            var seen = {};
            (facility.reports || []).forEach(function (report) {
                var cats = report.categories || {};
                var source = cats.source || agency;
                if (source === 'AHCA' && isPaginationRow(cats)) return;
                var id = safeString(report.report_id);
                if (id && seen[id]) return;
                if (id) seen[id] = true;
                var r = source === 'AHCA' ? readAhca(report, cats) : readDjj(report, cats);
                r.id = id;
                reports.push(r);
            });
            var entry = {
                name: safeString(info.facility_name),
                code: code,
                agency: agency || (reports[0] && reports[0].agency) || '',
                category: safeString(info.program_category),
                address: safeString(info.full_address),
                director: safeString(info.executive_director),
                capacity: safeString(info.bed_capacity),
                reports: reports
            };

            // One program listed twice under the same code: merge.
            if (code && byCode[code]) {
                var existing = byCode[code];
                existing.name = preferBetterName(existing.name, entry.name);
                ['address', 'director', 'capacity', 'category'].forEach(function (k) {
                    if (!existing[k] && entry[k]) existing[k] = entry[k];
                });
                var ids = {};
                existing.reports.forEach(function (r) { if (r.id) ids[r.id] = true; });
                entry.reports.forEach(function (r) { if (!r.id || !ids[r.id]) existing.reports.push(r); });
                return;
            }
            if (code) byCode[code] = entry;
            result.push(entry);
        });
        // A few unmatched DJJ programs are listed only by an internal code
        // ("hillsboroughgirlsacademypreareport"). Their PREA audit names the
        // facility ("Name of facility: Bartow Youth Academy"): use that name, and
        // fold the entry into an existing facility of the same name.
        var byName = {};
        result.forEach(function (f) { if (!looksLikeSlug(f.name)) byName[f.name.toLowerCase()] = f; });
        result = result.filter(function (f) {
            if (!looksLikeSlug(f.name)) return true;
            var named = '';
            f.reports.some(function (r) {
                var m = flat(r.raw_content).match(/Name of (?:facility|program):\s*(.{3,80}?)\s+(?:Physical|Mailing)\s+address/i);
                if (m && !/^facility$/i.test(m[1].trim())) { named = m[1].trim(); return true; }
                return false;
            });
            if (!named) return true;
            var target = byName[named.toLowerCase()];
            if (target) {
                Array.prototype.push.apply(target.reports, f.reports);
                return false;
            }
            f.name = named;
            byName[named.toLowerCase()] = f;
            return true;
        });

        result.forEach(function (f) {
            f.reports.sort(function (a, b) { return parseDate(b.date) - parseDate(a.date); });
        });
        return result;
    }

    function isFlagged(report) {
        return report.status === 'flagged';
    }

    function badgeFor(report, ctx) {
        if (report.kind === 'ahca') {
            return report.count
                ? { text: ctx.plural(report.count, 'deficiency', 'deficiencies'), tone: 'flagged' }
                : { text: 'No deficiencies', tone: 'clean' };
        }
        if (report.kind === 'qi') {
            if (report.count) {
                var failed = report.items.filter(function (i) { return /failed/i.test(i.rating); }).length;
                return { text: failed ? ctx.plural(failed, 'failed indicator') + (report.count > failed ? ', ' + (report.count - failed) + ' limited' : '')
                                      : ctx.plural(report.count, 'limited indicator'), tone: 'flagged' };
            }
            return report.status === 'clean' ? { text: 'Satisfactory', tone: 'clean' } : null;
        }
        if (report.kind === 'prea') {
            if (report.count) return { text: ctx.plural(report.count, 'standard') + ' not met', tone: 'flagged' };
            return report.status === 'clean' ? { text: 'All standards met', tone: 'clean' } : null;
        }
        return null;
    }

    page.mount({
        state: 'Florida',
        emptyMessage: 'No facilities found in the database for Florida.',

        filters: [{
            id: 'agency',
            label: 'Agency:',
            options: [
                { value: 'ALL', label: 'All Florida agencies' },
                { value: 'DJJ', label: 'Juvenile Justice (DJJ)' },
                { value: 'AHCA', label: 'Health Care Administration (AHCA)' }
            ],
            test: function (f, value) { return f.agency === value; }
        }],

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=FL')
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
            return [f.name, f.code, f.category, f.address, f.director]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.date).getTime(); },
        isFlagged: isFlagged,

        countFlagged: function (f) {
            return f.reports.reduce(function (sum, r) { return sum + (isFlagged(r) ? Math.max(1, r.count) : 0); }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var flagged = reports.filter(isFlagged).length;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.date).getTime()); }, 0);
            var stats = [{ text: ctx.plural(reports.length, 'report'), tone: 'neutral' }];
            if (flagged) stats.push({ text: ctx.plural(flagged, 'report') + ' with findings', tone: 'flagged' });
            else if (reports.some(function (r) { return r.status === 'clean'; })) stats.push({ text: 'No findings', tone: 'clean' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });
            return {
                meta: [
                    facility.agency === 'AHCA' ? 'Health Care Administration (AHCA)' : facility.agency === 'DJJ' ? 'Juvenile Justice (DJJ)' : '',
                    facility.category,
                    facility.capacity ? 'Capacity ' + facility.capacity : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var badge = badgeFor(report, ctx);
            var time = parseDate(report.date).getTime();
            var type = report.label + (report.kind === 'spep' && report.service ? ': ' + report.service : '');
            var preview = '';
            if (report.items.length && report.items[0].text) preview = report.items[0].rule + ': ' + report.items[0].text;
            else if (report.kind === 'ahca' && report.items.length) {
                preview = report.items.map(function (d) { return d.heading || d.code; }).filter(Boolean).join(', ');
            }

            return {
                date: ctx.formatDate(time) || report.fiscal_year || 'Date not listed',
                type: type,
                tone: report.status,
                badges: badge ? [badge] : [],
                facts: [
                    report.fiscal_year ? 'Fiscal year ' + report.fiscal_year.replace(/^FY/, '') : '',
                    report.cycle,
                    report.period ? 'Review period ' + report.period : '',
                    report.track_id ? 'Track ID ' + report.track_id : ''
                ],
                link: { href: report.url, text: 'Official report' },
                preview: preview,
                body: function () {
                    var html = '';
                    if (report.unmatched) {
                        html += ui.note('This report’s program name did not match a current DJJ facility, so it is likely a closed or earlier program.');
                    }
                    if (report.kind === 'ahca') {
                        if (report.items.length) {
                            html += ui.heading(ctx.plural(report.count, 'deficiency', 'deficiencies') + ' cited');
                            // AHCA lists the rule cited, not a narrative: heading as the
                            // title, the full rule text collapsed.
                            html += report.items.map(function (d) {
                                return ui.finding({
                                    title: d.heading || d.code || 'Deficiency',
                                    citation: d.heading ? d.code + (d.section ? ' ' + d.section : '') : '',
                                    chips: d.corrected ? [{ text: 'Corrected ' + d.corrected, tone: 'neutral' }] : [],
                                    requirement: d.heading ? [d.rule] : [d.requirement],
                                    tone: 'flagged'
                                });
                            }).join('');
                        } else {
                            html += ui.note('No deficiencies were cited in this survey.');
                        }
                        html += ui.note('Source: Florida Agency for Health Care Administration.');
                        return html;
                    }
                    if (report.kind === 'qi' && report.items.length) {
                        html += ui.heading('Indicators below satisfactory');
                        html += report.items.map(function (i) {
                            return ui.finding({
                                title: i.text || i.rule,
                                citation: i.text ? i.rule : '',
                                chips: [{ text: i.rating, tone: /failed/i.test(i.rating) ? 'flagged' : 'neutral' }],
                                tone: 'flagged'
                            });
                        }).join('');
                    } else if (report.kind === 'qi' && report.status === 'clean') {
                        html += ui.note('Every indicator in this review was rated satisfactory.');
                    }
                    if (report.kind === 'prea') {
                        if (report.met !== null || report.notMet !== null) {
                            html += ui.paragraphs(['Standards exceeded: ' + (report.exceeded || 0)
                                + ' · met: ' + (report.met || 0)
                                + ' · not met: ' + (report.notMet || 0)]);
                        }
                        if (report.items.length) {
                            html += report.items.map(function (i) {
                                return ui.finding({ title: i.rule, chips: [{ text: i.rating, tone: 'flagged' }], tone: 'flagged' });
                            }).join('');
                        }
                    }
                    if (report.kind === 'spep') {
                        html += ui.note('An SPEP report evaluates how well a therapy service is delivered. It is not a compliance inspection.');
                    }
                    if (!html && report.status === 'neutral') {
                        html += ui.note('The result could not be read from this report. The official report has the details.');
                    }
                    html += ui.section('Full report text', ui.docText(report.raw_content));
                    return html;
                }
            };
        }
    });
}());
