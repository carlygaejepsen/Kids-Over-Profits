/**
 * Maryland residential child care inspection summaries (/md-reports/) --
 * adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=MD: the "Residential Child
 * Care Report Summary" PDFs the Department of Human Services' Office of
 * Licensing and Monitoring posts in its public file browser, one folder per
 * provider (md_scraper.py in the Tools repo; only the RCC folders are read).
 * One row on this page is one PROVIDER (the licensed legal entity); a report
 * often covers several of its sites at once, and since mid-2019 each citation
 * names the site it applies to.
 *
 * These are one-to-two page summaries, not narrative reports: a table of the
 * sites inspected (licence number, capacity, children placed, licence expiry,
 * date of the visit), the type of inspection, the licence status and the
 * citations, each a COMAR regulation number and a one-line comment. There is
 * no account of what the inspector saw beyond that line.
 *
 * Three forms, all read into categories by the scraper:
 *   summary-2021  revised 10/2021: two citation blocks, violations which "MAY
 *                 present safety risks for children" (safety_citations) and
 *                 violations which "DO NOT present imminent safety risks"
 *                 (other_citations), each citation with a status, CAP
 *                 (corrective action plan) or Resolved.
 *   summary       mid-2019 to late 2021: one block, headed as violations which
 *                 do not present imminent safety risks (other_citations); no
 *                 block for safety risks and no status.
 *   2019          the "Residential Child Care Programs Report" used until
 *                 mid-2019: citations with no site and no safety rating
 *                 (unrated_citations), and a yes/no "Corrective Action Plan"
 *                 tick with its date (cap_required, cap_date).
 * The safety rating is the state's own, by "impact, scope and frequency". It
 * is what the page leads with: the badge on every report, the count on every
 * provider and the "Citations" filter.
 *
 * What counts as a violation: a report with at least one COMAR citation in any
 * block (safety risk, not an imminent risk, or unrated). A citation marked
 * Resolved still counts; the state cited it. A report whose blocks say "No
 * COMAR violations" (or are empty) is clean. The scraper drops OCR noise rows
 * (no regulation number and no readable word) and skips a second file holding
 * the same report, so every citation and report here counts.
 *
 * The list loads with ?lite=1; categories hold everything the page shows, so
 * no report text is fetched on open.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Maryland reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var SUMMARY_NOTE = 'This is the state’s inspection summary, one or two pages long. It is not a narrative report: '
        + 'each citation is a regulation number (COMAR, the Code of Maryland Regulations) and a one-line comment.';
    var SAFETY_LABEL = 'The state’s heading: cited for COMAR violations which may present safety risks for children '
        + 'based on impact, scope and frequency. These issues are either resolved or a corrective action plan has been implemented.';
    var OTHER_LABEL = 'The state’s heading: cited for COMAR violations which do not present imminent safety risks '
        + 'for children based on impact, scope and frequency.';
    var OLD_SUMMARY_NOTE = 'The form the state used from mid-2019 to October 2021 has one citation block, for violations '
        + 'which do not present imminent safety risks. It has no block for citations that may present safety risks.';
    var FORM_2019_NOTE = 'The form the state used until mid-2019 does not rate citations by safety risk and does not say which site each one applies to.';

    var TYPE_LABELS = {
        'Quarterly': 'Quarterly inspection',
        'Re-licensure': 'Re-licensure inspection',
        'Mid-licensure': 'Mid-licensure inspection',
        'Periodic': 'Periodic inspection'
    };

    // Abbreviations the comments use, explained only when a report uses them.
    var ABBREVIATIONS = [
        [/\bRCYCP\b|\bRYCYP\b/, 'RCYCP: Residential Child and Youth Care Practitioner certification, which Maryland requires of direct care staff.'],
        [/\bCAP\b/, 'CAP: corrective action plan.'],
        [/\bCPS\b/, 'CPS: Child Protective Services (a clearance check of staff).'],
        [/\bMAR'?s?\b/, 'MAR: medication administration record.'],
        [/\bISP\b/, 'ISP: individual service plan.'],
        [/\bT\.?B\.?\b/, 'TB: tuberculosis (screening).'],
        [/\bmandt\b/i, 'Mandt: a commercial training in de-escalation and physical restraint.']
    ];

    // "2024-09-03" -> local Date; anything else through Date.
    function parseDate(value) {
        var m = safeString(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
        var d = new Date(safeString(value));
        return isNaN(d.getTime()) ? new Date(0) : d;
    }

    function list(value) {
        return Array.isArray(value) ? value : [];
    }

    function dateText(value) {
        return page.formatDate(parseDate(value).getTime());
    }

    function citations(value) {
        return list(value).map(function (item) {
            return {
                site: safeString(item.site),
                citation: safeString(item.citation).replace(/^\[/, ''),
                comment: safeString(item.comment),
                status: safeString(item.status)
            };
        });
    }

    function statusKind(status) {
        if (/\bcap\b/i.test(status)) return 'cap';
        if (/resolved/i.test(status)) return 'resolved';
        return '';
    }

    // Licence type and status cells sometimes hold another field's value
    // ("DHS" as the type, "Quarterly" as the status): those are not shown.
    function licenceType(value) {
        var v = safeString(value);
        return /^(?:DHS|DJS|MSDE|RCC|)$/i.test(v) ? '' : v.replace(/^RCC\s*-\s*/i, '');
    }

    function licenceStatus(value) {
        var v = safeString(value);
        if (/^rel\s*i?censed$/i.test(v)) return 'Re-licensed';
        if (/quarter|periodic|mid-?lic/i.test(v)) return '';
        return v;
    }

    // "DHS,DJS. DYRS |" -> "DHS, DJS, DYRS"
    function agencies(value) {
        return safeString(value).replace(/\s*\|\s*$/, '').split(/\s*(?:,|\.|\band\b|\s)\s*/i)
            .map(safeString).filter(Boolean)
            .filter(function (v, i, all) { return all.indexOf(v) === i; })
            .join(', ').replace(/AUTISM, WAIVER/i, 'Autism Waiver').replace(/Autism, Waiver/, 'Autism Waiver')
            .replace(/No, Contract/i, 'No contract');
    }

    function convertReport(report) {
        var cats = report.categories || {};
        var safety = citations(cats.safety_citations);
        var other = citations(cats.other_citations);
        var unrated = citations(cats.unrated_citations);
        var all = safety.concat(other, unrated);
        return {
            report_id:      safeString(report.report_id),
            report_date:    safeString(report.report_date),
            report_url:     safeString(report.report_url),
            form:           safeString(cats.form),
            inspection_type: safeString(cats.inspection_type),
            type_text:      safeString(cats.inspection_type_text),
            license_type:   licenceType(cats.license_type),
            license_status: licenceStatus(cats.license_status),
            contracting:    agencies(cats.contracting_agency),
            sites:          list(cats.sites),
            safety:         safety,
            other:          other,
            unrated:        unrated,
            citation_count: all.length,
            cap_count:      all.filter(function (c) { return statusKind(c.status) === 'cap'; }).length,
            cap_required:   cats.cap_required,
            cap_date:       safeString(cats.cap_date),
            date_source:    safeString(cats.date_source),
            ocr:            !!cats.ocr,
            archive_name:   safeString(cats.archive_name) || safeString(cats.file_name)
        };
    }

    function convertApiDataToFacilities(apiFacilities) {
        return (apiFacilities || []).map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(convertReport)
                .sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            // The newest report that fills each field.
            function newest(field) {
                var value = '';
                reports.some(function (r) { value = r[field]; return !!value; });
                return value;
            }
            var sites = [];
            reports.some(function (r) { sites = r.sites; return sites.length > 0; });

            return {
                name:       safeString(info.facility_name),
                id:         safeString(info.program_name),
                capacity:   safeString(info.bed_capacity),
                licence_type: newest('license_type'),
                status:     newest('license_status'),
                contracting: newest('contracting'),
                sites:      sites,
                reports:    reports
            };
        });
    }

    function isFlagged(report) {
        return report.citation_count > 0;
    }

    function siteLine(s) {
        var bits = [];
        var licence = safeString(s.licence).replace(/[\s,;]*exp(?:\.|iration)?(?:\s*date)?\s*:?\s*$/i, '');
        if (licence) bits.push('licence ' + licence);
        if (s.capacity !== undefined) bits.push('capacity ' + s.capacity);
        var placed = [];
        if (s.dhs !== undefined) placed.push(s.dhs + ' by DHS');
        if (s.djs !== undefined) placed.push(s.djs + ' by DJS');
        if (s.other !== undefined) placed.push(s.other + ' by others');
        if (placed.length) bits.push('children placed: ' + placed.join(', '));
        if (s.license_exp) bits.push('licence expires ' + dateText(s.license_exp));
        if (s.date) bits.push('inspected ' + dateText(s.date));
        if (s.note && /closed|empty|not in use/i.test(s.note)) bits.push(safeString(s.note));
        return safeString(s.name) + (bits.length ? ': ' + bits.join('; ') : '');
    }

    function citationBlock(c, rated) {
        var chips = [];
        if (c.site) chips.push({ text: (/;/.test(c.site) ? 'Sites: ' : 'Site: ') + c.site, tone: 'neutral' });
        var kind = statusKind(c.status);
        if (kind === 'cap') chips.push({ text: 'Corrective action plan', tone: 'flagged' });
        else if (kind === 'resolved') chips.push({ text: 'Resolved', tone: 'clean' });
        else if (c.status) chips.push({ text: c.status, tone: 'neutral' });
        return ui.finding({
            title: c.comment || 'No comment given',
            citation: c.citation ? 'COMAR ' + c.citation : '',
            chips: chips,
            tone: rated === 'other' ? 'neutral' : 'flagged'
        });
    }

    function abbreviations(report) {
        var text = report.safety.concat(report.other, report.unrated).map(function (c) {
            return c.comment;
        }).join(' ');
        return ABBREVIATIONS.filter(function (a) { return a[0].test(text); }).map(function (a) { return a[1]; });
    }

    function reportBody(report, ctx) {
        var html = ui.note(SUMMARY_NOTE);

        if (report.safety.length) {
            html += ui.heading(ctx.plural(report.safety.length, 'citation') + ' that may present safety risks for children');
            html += ui.note(SAFETY_LABEL);
            html += report.safety.map(function (c) { return citationBlock(c, 'safety'); }).join('');
        } else if (report.form === 'summary-2021' && report.citation_count) {
            html += ui.note('No citations in the block for violations which may present safety risks for children.');
        }

        if (report.other.length) {
            html += ui.heading(ctx.plural(report.other.length, 'citation') + ' that do not present imminent safety risks');
            html += ui.note(OTHER_LABEL);
            html += report.other.map(function (c) { return citationBlock(c, 'other'); }).join('');
        }
        if (report.form === 'summary' && report.citation_count) html += ui.note(OLD_SUMMARY_NOTE);

        if (report.unrated.length) {
            html += ui.heading(ctx.plural(report.unrated.length, 'citation') + ' (not rated by safety risk)');
            html += report.unrated.map(function (c) { return citationBlock(c, 'unrated'); }).join('');
            html += ui.note(FORM_2019_NOTE);
        }
        if (report.form === '2019' && report.cap_required !== undefined) {
            html += ui.note(report.cap_required
                ? 'Corrective action plan required' + (report.cap_date ? ' (dated ' + dateText(report.cap_date) + ').' : '.')
                : 'The form marks no corrective action plan.');
        }

        if (!report.citation_count) html += ui.note('The report lists no COMAR violations.');

        var abbr = abbreviations(report);
        if (abbr.length) html += ui.section('Abbreviations used', ui.paragraphs(abbr));
        if (report.sites.length) {
            html += ui.section(ctx.plural(report.sites.length, 'site') + ' covered by this report',
                ui.paragraphs(report.sites.map(siteLine)), { open: report.sites.length > 1 && report.citation_count > 0 });
        }
        return html;
    }

    function reportBadges(report, ctx) {
        var badges = [];
        if (report.safety.length) {
            badges.push({ text: report.safety.length + ' may present safety risks', tone: 'flagged' });
        }
        if (report.other.length) {
            badges.push({ text: report.other.length + ' cited, not an imminent risk', tone: 'neutral' });
        }
        if (report.unrated.length) {
            badges.push({ text: ctx.plural(report.unrated.length, 'citation') + ', not rated', tone: 'flagged' });
        }
        if (!report.citation_count) badges.push({ text: 'No COMAR violations', tone: 'clean' });
        if (report.cap_count) badges.push({ text: ctx.plural(report.cap_count, 'corrective action plan'), tone: 'neutral' });
        return badges;
    }

    function preview(report) {
        var first = report.safety[0] || report.other[0] || report.unrated[0];
        if (!first) return '';
        var text = first.comment.replace(/\s+/g, ' ');
        var more = report.citation_count - 1;
        if (text.length > 160) text = text.slice(0, 157).replace(/\s+\S*$/, '') + '…';
        return text + (more > 0 ? ' (and ' + more + ' more)' : '');
    }

    function citationGroup(r, value) {
        if (value === 'safety') return r.safety.length > 0;
        if (value === 'any') return r.citation_count > 0;
        if (value === 'none') return r.citation_count === 0;
        return true;
    }

    function typeGroup(r) {
        return TYPE_LABELS[r.inspection_type] ? r.inspection_type : 'other';
    }

    page.mount({
        state: 'Maryland',
        archiveState: 'MD',
        emptyMessage: 'No providers found in the database for Maryland.',

        filters: [{
            id: 'citations',
            label: 'Citations:',
            options: [
                { value: 'ALL', label: 'All reports' },
                { value: 'safety', label: 'May present safety risks' },
                { value: 'any', label: 'Any citation' },
                { value: 'none', label: 'No COMAR violations' }
            ],
            test: function (f, value) {
                return f.reports.some(function (r) { return citationGroup(r, value); });
            },
            testReport: citationGroup
        }, {
            id: 'type',
            label: 'Inspection type:',
            options: [
                { value: 'ALL', label: 'All inspection types' },
                { value: 'Quarterly', label: 'Quarterly' },
                { value: 'Re-licensure', label: 'Re-licensure' },
                { value: 'Mid-licensure', label: 'Mid-licensure' },
                { value: 'Periodic', label: 'Periodic' },
                { value: 'other', label: 'Not stated' }
            ],
            test: function (f, value) {
                return f.reports.some(function (r) { return typeGroup(r) === value; });
            },
            testReport: function (r, value) { return typeGroup(r) === value; }
        }],

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=MD&lite=1')
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

        // The provider and every site its reports name: a site's own name or
        // licence number finds its provider.
        searchText: function (f) {
            var parts = [f.name, f.licence_type];
            f.reports.forEach(function (r) {
                r.sites.forEach(function (s) { parts.push(s.name, s.licence); });
            });
            return parts.map(function (v) { return safeString(v).toLowerCase(); }).join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        // Most citations first, those that may present safety risks first among equals.
        countFlagged: function (f) {
            return f.reports.reduce(function (sum, r) { return sum + r.citation_count + r.safety.length / 1000; }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var safetyReports = reports.filter(function (r) { return r.safety.length > 0; }).length;
            var safety = reports.reduce(function (sum, r) { return sum + r.safety.length; }, 0);
            var total = reports.reduce(function (sum, r) { return sum + r.citation_count; }, 0);
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'report'), tone: 'neutral' }];
            if (safety) {
                stats.push({
                    text: ctx.plural(safety, 'citation') + ' that may present safety risks (' + ctx.plural(safetyReports, 'report') + ')',
                    tone: 'flagged'
                });
            }
            if (total) {
                stats.push({ text: ctx.plural(total, 'citation') + ' in all', tone: safety ? 'neutral' : 'flagged' });
            } else if (reports.length) {
                stats.push({ text: 'No COMAR violations', tone: 'clean' });
            }
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            var siteNames = facility.sites.map(function (s) { return safeString(s.name); }).filter(Boolean);
            return {
                meta: [
                    facility.licence_type || 'Residential child care',
                    siteNames.length > 1 ? ctx.plural(siteNames.length, 'site') + ': ' + siteNames.slice(0, 4).join('; ')
                        + (siteNames.length > 4 ? '; and ' + (siteNames.length - 4) + ' more' : '') : '',
                    facility.capacity ? 'Licensed capacity ' + facility.capacity : '',
                    facility.status ? 'Licence: ' + facility.status : ''
                ],
                address: siteNames.length === 1 ? siteNames[0] : '',
                stats: stats
            };
        },

        report: function (report, ctx) {
            var facts = [];
            if (report.type_text) facts.push('The form says: ' + report.type_text);
            if (report.license_status) facts.push('Licence status: ' + report.license_status);
            if (report.contracting) facts.push('Placing agencies: ' + report.contracting);
            if (report.date_source === 'signed') facts.push('Dated by the staff signatures');
            if (report.ocr) facts.push('Read from a scanned copy');

            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || 'Date unknown',
                type: TYPE_LABELS[report.inspection_type] || 'Inspection',
                tone: isFlagged(report) ? 'flagged' : 'clean',
                badges: reportBadges(report, ctx),
                facts: facts,
                link: { href: report.report_url, text: 'Report summary (state copy)' },
                links: [ctx.archiveLink(report.archive_name, 'Report (archived copy)')],
                preview: preview(report),
                body: function () { return reportBody(report, ctx); }
            };
        }
    });
}());
