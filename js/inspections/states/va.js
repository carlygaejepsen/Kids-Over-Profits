/**
 * Virginia licensing reports (/va-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=VA (va_scraper.py in the
 * Tools repo), which holds records from two state agencies. Each row is one
 * licence; every report says which agency it comes from (categories.source).
 *
 *   VDSS   Department of Social Services, Division of Licensing Programs:
 *          children's residential facilities. One row = one facility licence
 *          (program_name "VDSS-<licence id>"). One report = one inspection as
 *          the state's inspection page shows it: dates, areas reviewed, the
 *          inspector's comments, and each violation with the standard cited,
 *          the description, the findings and, when the facility submitted one,
 *          its plan of correction.
 *   DBHDS  Department of Behavioral Health and Developmental Services, Office
 *          of Licensing: psychiatric residential treatment facilities,
 *          therapeutic group homes, residential crisis stabilization,
 *          substance use residential and inpatient services, and inpatient
 *          psychiatric services for children and adolescents. One row = one
 *          licensed service (licence number such as 052-14-001); a service
 *          with one location is named after it, one with several after the
 *          provider. One report = one inspection (its purpose as the state
 *          gives it: unannounced, scheduled, human rights, death or serious
 *          incident, in-office review) or one investigation. The state shows
 *          findings only through the finalized corrective action plan; the
 *          scraper reads its table, one row per standard (categories.citations,
 *          full text in categories.detail, fetched on open).
 *
 * What counts as a violation:
 *   VDSS   an inspection whose page lists at least one violation. The count
 *          shown is the number of standards listed under "Violations". An
 *          inspection whose page lists none is clean. When the facility list
 *          says an inspection had violations but none could be read from its
 *          page, it is neutral, never clean.
 *   DBHDS  a corrective action plan row rated N (non-compliance) or NS
 *          (non-compliance, systemic). Rows rated C (substantial compliance)
 *          and ND (not determined) are shown but not counted. A plan that
 *          reads "No Violation" (categories.no_violation) is clean. A report
 *          with no finalized plan posted, or a plan whose table could not be
 *          read, is neutral.
 *
 * Complaint inspections (VDSS): the state's "Complaint Related" field, or an
 * inspector's comment that gives "Type of inspection: Complaint". The field
 * reads NO on every page read so far, including complaint inspections, so the
 * comment is what marks most of them.
 *
 * Filters: agency, service type (the facility's licence category), and record
 * type (inspections, VDSS complaint inspections, DBHDS investigations).
 *
 * The list loads without report text (?lite=1). A VDSS inspection's list entry
 * carries its violations and comments; a DBHDS report's plan text and detail
 * are fetched when it is opened.
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

    var AGENCY_NAMES = {
        vdss: 'Department of Social Services (VDSS)',
        dbhds: 'Department of Behavioral Health and Developmental Services (DBHDS)'
    };

    // The scraper's category labels (DBHDS_SERVICE_TYPES in va_scraper.py).
    var SERVICE_TYPES = [
        { value: 'Children\'s Residential Facility (VDSS)', label: 'Children’s residential facility (VDSS)' },
        { value: 'Psychiatric residential treatment facility (DBHDS)', label: 'Psychiatric residential treatment facility (DBHDS)' },
        { value: 'Therapeutic group home (DBHDS)', label: 'Therapeutic group home (DBHDS)' },
        { value: 'Residential crisis stabilization (DBHDS)', label: 'Residential crisis stabilization (DBHDS)' },
        { value: 'Substance use residential, ASAM 3.5 (DBHDS)', label: 'Substance use residential, ASAM 3.5 (DBHDS)' },
        { value: 'Substance use residential, ASAM 3.1 (DBHDS)', label: 'Substance use residential, ASAM 3.1 (DBHDS)' },
        { value: 'Substance use inpatient, ASAM 3.7 (DBHDS)', label: 'Substance use inpatient, ASAM 3.7 (DBHDS)' },
        { value: 'Inpatient psychiatric service (DBHDS)', label: 'Inpatient psychiatric service (DBHDS)' }
    ];

    var COMP_LABELS = {
        N: 'Non-compliance',
        NS: 'Non-compliance, systemic',
        C: 'Substantial compliance',
        ND: 'Not determined'
    };

    var VDSS_NOTE = 'VDSS posts each inspection with the standards it found violated. '
        + 'The plan of correction is the facility’s own response.';
    var DBHDS_PLAN_NOTE = 'DBHDS shows findings only in the finalized corrective action plan. Each row is one standard, '
        + 'rated C (substantial compliance), N (non-compliance), NS (non-compliance, systemic) or ND (not determined); '
        + 'rows rated N or NS are counted here. The DBHDS link opens the provider search, where the licence number '
        + 'finds the service.';
    var DBHDS_NO_PLAN = 'DBHDS has not posted a finalized corrective action plan for this record, '
        + 'so its findings are not shown. The state lists only its date and purpose.';
    var DBHDS_UNREAD = 'A corrective action plan is posted for this record, but no row could be read from it. '
        + 'Open the archived copy or the DBHDS provider search.';

    // ---- small helpers -----------------------------------------------------

    function list(value) {
        return Array.isArray(value) ? value : [];
    }

    function count(value) {
        var n = parseInt(value, 10);
        return isNaN(n) ? 0 : n;
    }

    // "2025-06-12" -> local Date; anything else through Date; '' -> epoch 0.
    function parseDate(value) {
        var m = safeString(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
        var d = new Date(safeString(value));
        return isNaN(d.getTime()) ? new Date(0) : d;
    }

    function formatIso(ctx, value) {
        var ms = parseDate(value).getTime();
        return ms > 0 ? ctx.formatDate(ms) : '';
    }

    function shorten(text, max) {
        var s = safeString(text).replace(/\s+/g, ' ');
        if (s.length <= max) return s;
        return s.slice(0, max).replace(/\s+\S*$/, '') + '…';
    }

    function sentenceCase(text) {
        var t = safeString(text).toLowerCase();
        return t ? t.charAt(0).toUpperCase() + t.slice(1) : '';
    }

    function lines(text) {
        return safeString(text).split(/\n+/);
    }

    // "N N" (a plan row split across pages) -> "N"; "ns" -> "NS".
    function normalizeComp(value) {
        var tokens = safeString(value).toUpperCase().split(/[\s,\/]+/);
        if (tokens.indexOf('NS') !== -1) return 'NS';
        if (tokens.indexOf('N') !== -1) return 'N';
        if (tokens.indexOf('ND') !== -1) return 'ND';
        if (tokens.indexOf('C') !== -1) return 'C';
        return '';
    }

    function isCited(citation) {
        return citation.comp === 'N' || citation.comp === 'NS';
    }

    // "Other Date(s) of inspection and time the licensing inspector" -> "Other";
    // "Monitoring inspection" -> "Monitoring".
    function cleanInspectionType(value) {
        return safeString(value)
            .replace(/\s+date\(s\)[\s\S]*$/i, '')
            .replace(/\s+inspection$/i, '')
            .trim();
    }

    // ---- normalizing ---------------------------------------------------------

    function convertReport(report, facilitySource) {
        var cats = report.categories || {};
        var source = cats.source === 'dbhds' || cats.source === 'vdss' ? cats.source : facilitySource;
        var out = {
            report_id:   safeString(report.report_id),
            row_id:      report.row_id || null,
            report_date: safeString(report.report_date),
            report_url:  safeString(report.report_url),
            raw_content: String(report.raw_content || ''),
            has_text:    report.has_text !== undefined ? !!report.has_text : !!report.raw_content,
            source:      source,
            kind:        cats.kind === 'investigation' ? 'investigation' : 'inspection'
        };
        if (source === 'vdss') {
            var type = cleanInspectionType(cats.inspection_type);
            out.inspection_type = type;
            out.complaint = !!cats.complaint_related || /^complaint\b/i.test(type);
            out.dates = list(cats.inspection_dates);
            out.inspector = safeString(cats.inspector);
            out.areas = list(cats.areas_reviewed);
            out.comments = safeString(cats.comments);
            out.violations = list(cats.violations);
            out.violation_count = Math.max(out.violations.length, count(cats.violation_count));
            out.listed_with_violations = !!cats.listed_with_violations;
            out.date_corrected = !!cats.date_corrected;
            out.date_as_published = safeString(cats.date_as_published);
        } else {
            out.purpose = safeString(cats.purpose);
            out.provider = safeString(cats.provider);
            out.locations = list(cats.locations);
            out.received = safeString(cats.received);
            out.closed = safeString(cats.closed);
            out.onsite_start = safeString(cats.inspection_start);
            out.onsite_end = safeString(cats.inspection_end);
            out.has_cap = !!cats.has_cap;
            out.no_violation = cats.no_violation === true || cats.result === 'no_violation';
            out.citations = list(cats.citations).map(function (c) {
                return {
                    standard:        safeString(c.standard),
                    comp:            normalizeComp(c.comp),
                    location:        safeString(c.location),
                    noncompliance:   safeString(c.noncompliance),
                    response_status: safeString(c.response_status),
                    response_date:   safeString(c.response_date),
                    planned_date:    safeString(c.planned_date)
                };
            });
            var cited = out.citations.filter(isCited).length;
            // A list without rows but with a count (older payloads) keeps the count.
            out.cited_count = out.citations.length ? cited : count(cats.citation_count);
            // Full lists carry the detail; lite lists get it with the text.
            if (cats.detail) out.detail = cats.detail;
            out.archive_names = list(cats.archive_names).concat(cats.archive_name ? [cats.archive_name] : [])
                .filter(function (name, i, all) { return name && all.indexOf(name) === i; });
        }
        return out;
    }

    function convertApiDataToFacilities(apiFacilities) {
        return list(apiFacilities).map(function (facility) {
            var info = facility.facility_info || {};
            var number = safeString(info.program_name);
            var category = safeString(info.program_category);
            var source = /^VDSS-/.test(number) || /\(VDSS\)$/.test(category) ? 'vdss' : 'dbhds';
            var reports = list(facility.reports).map(function (r) { return convertReport(r, source); })
                .sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });
            var first = reports.filter(function (r) { return r.source === 'dbhds'; })[0];
            var name = safeString(info.facility_name);
            // A closed VDSS licence's page can read "N/A" where the name was.
            if (!name || /^n\/?a$/i.test(name)) name = 'Name not published (licence ' + number.replace(/^VDSS-/, '') + ')';
            var address = safeString(info.full_address);
            if (!address.replace(/[\s,]/g, '')) address = '';
            return {
                name:      name,
                number:    number,
                source:    source,
                category:  category,
                address:   address,
                phone:     safeString(info.phone),
                capacity:  count(info.bed_capacity) > 0 ? safeString(info.bed_capacity) : '',
                contact:   safeString(info.executive_director),
                expires:   safeString(info.license_exp_date),
                status:    safeString(info.action),
                provider:  first ? first.provider : '',
                locations: first ? first.locations : [],
                reports:   reports
            };
        });
    }

    // ---- flags ---------------------------------------------------------------

    function findingCount(report) {
        return report.source === 'vdss' ? report.violation_count : report.cited_count;
    }

    function isFlagged(report) {
        return findingCount(report) > 0;
    }

    function dbhdsState(report) {
        if (report.cited_count > 0) return 'cited';
        if (report.no_violation) return 'no_violation';
        if (report.citations.length) return 'no_cited_rows';   // rows rated C or ND only
        if (report.has_cap) return 'unread';
        return 'no_plan';
    }

    function reportTone(report) {
        if (isFlagged(report)) return 'flagged';
        if (report.source === 'vdss') return report.listed_with_violations ? 'neutral' : 'clean';
        var state = dbhdsState(report);
        return state === 'no_violation' || state === 'no_cited_rows' ? 'clean' : 'neutral';
    }

    // ---- labels --------------------------------------------------------------

    function typeLabel(report) {
        if (report.source === 'vdss') {
            if (report.complaint) return 'Complaint inspection';
            var head = report.inspection_type.split(/\s*[,–—-]\s*/)[0];
            return head ? sentenceCase(head) + ' inspection' : 'Inspection';
        }
        if (report.kind === 'investigation') return 'Investigation';
        return sentenceCase(report.purpose) || 'Inspection';
    }

    function agencyBadge(report) {
        return { text: report.source === 'vdss' ? 'VDSS' : 'DBHDS', tone: 'neutral' };
    }

    function reportBadges(report, ctx) {
        var badges = [agencyBadge(report)];
        if (report.source === 'vdss') {
            if (report.violation_count) {
                badges.push({ text: ctx.plural(report.violation_count, 'violation'), tone: 'flagged' });
            } else if (report.listed_with_violations) {
                badges.push({ text: 'Violations listed, none read', tone: 'neutral' });
            } else {
                badges.push({ text: 'No violations', tone: 'clean' });
            }
            return badges;
        }
        var state = dbhdsState(report);
        if (state === 'cited') {
            badges.push({ text: ctx.plural(report.cited_count, 'standard') + ' not met', tone: 'flagged' });
        } else if (state === 'no_violation') {
            badges.push({ text: 'No violation', tone: 'clean' });
        } else if (state === 'no_cited_rows') {
            badges.push({ text: 'No standard rated N or NS', tone: 'clean' });
        } else if (state === 'unread') {
            badges.push({ text: 'Plan posted, not read', tone: 'neutral' });
        } else {
            badges.push({ text: 'No plan posted', tone: 'neutral' });
        }
        return badges;
    }

    function dateList(ctx, dates) {
        return dates.map(function (d) { return formatIso(ctx, d); }).filter(Boolean).join('; ');
    }

    function reportFacts(report, ctx) {
        var facts = [AGENCY_NAMES[report.source]];
        if (report.source === 'vdss') {
            if (report.dates.length > 1) facts.push('Inspection dates ' + dateList(ctx, report.dates));
            // The full type when the row's label shortened or replaced it.
            var type = report.inspection_type;
            if (type && typeLabel(report).toLowerCase() !== (type + ' inspection').toLowerCase()) {
                facts.push('Type as the state gives it: ' + type);
            }
            if (report.inspector) facts.push('Inspector ' + report.inspector);
            if (report.date_corrected) {
                facts.push('The state’s page gives the date as “' + report.date_as_published
                    + '”; the date here is from the inspector’s comments');
            }
        } else {
            if (report.kind === 'investigation') {
                facts.push('Investigation ' + report.report_id);
                if (report.received) facts.push('Received ' + formatIso(ctx, report.received));
                if (report.onsite_start) {
                    var on = formatIso(ctx, report.onsite_start);
                    if (report.onsite_end && report.onsite_end !== report.onsite_start) on += ' to ' + formatIso(ctx, report.onsite_end);
                    facts.push('Inspection dates in the plan ' + on);
                }
                if (report.closed) facts.push('Closed ' + formatIso(ctx, report.closed));
            }
        }
        return facts;
    }

    function reportPreview(report) {
        if (report.source === 'vdss') {
            return shorten(report.violations.map(function (v) { return violationTitle(v).text; }).join('; '), 160);
        }
        var first = report.citations.filter(isCited)[0];
        return first ? shorten(first.noncompliance, 160) : '';
    }

    // ---- VDSS body -------------------------------------------------------------

    // "Based on ..., the facility failed to include ... face sheet." ->
    // "Failed to include ... face sheet."
    function violationTitle(violation) {
        var d = safeString(violation.description).replace(/\s+/g, ' ');
        var m = d.match(/\b(?:failed|fails|did not|failure) to\b[\s\S]*$/i);
        if (m && m[0].length <= 220) {
            // A short lead-in ("Based on record review and interview, the
            // agency") is not repeated under the title; a longer one is.
            return { text: m[0].charAt(0).toUpperCase() + m[0].slice(1), whole: m.index < 100 };
        }
        if (d.length <= 220) return { text: d || 'Violation', whole: true };
        return { text: shorten(d, 200), whole: false };
    }

    function violationBlock(violation) {
        var title = violationTitle(violation);
        var evidence = [];
        if (!title.whole && violation.description) evidence = evidence.concat(lines(violation.description));
        if (violation.findings) evidence = evidence.concat(['Findings:'], lines(violation.findings));
        var more = [];
        if (violation.plan) more.push({ title: 'The facility’s plan of correction', paragraphs: lines(violation.plan) });
        return ui.finding({
            title: title.text,
            citation: safeString(violation.standard),
            evidence: evidence,
            more: more,
            tone: 'flagged'
        });
    }

    function vdssBody(report, ctx) {
        var html = '';
        if (report.violations.length) {
            html += ui.heading(ctx.plural(report.violations.length, 'violation'));
            html += report.violations.map(violationBlock).join('');
        } else if (report.listed_with_violations) {
            html += ui.note('The state’s list marks this inspection as having violations, '
                + 'but none could be read from its page. Open the state inspection page.');
        } else {
            html += ui.note('The state’s page lists no violations for this inspection.');
        }
        if (report.comments) html += ui.section('Inspector’s comments', ui.paragraphs(lines(report.comments)));
        if (report.areas.length) html += ui.section('Areas reviewed', ui.paragraphs(report.areas));
        html += ui.note(VDSS_NOTE);
        return html;
    }

    // ---- DBHDS body ------------------------------------------------------------

    function citationBlock(citation, detail, multiLocation, ctx) {
        detail = detail || {};
        var requirement = safeString(detail.standard_text);
        var cited = isCited(citation);
        var chips = [];
        if (citation.comp) {
            chips.push({
                text: citation.comp + ': ' + (COMP_LABELS[citation.comp] || citation.comp),
                tone: cited ? 'flagged' : (citation.comp === 'C' ? 'clean' : 'neutral')
            });
        }
        var evidence = [];
        if (multiLocation && citation.location) evidence.push('Location: ' + citation.location);
        var description = safeString(detail.noncompliance) || citation.noncompliance;
        if (description) evidence = evidence.concat(lines(description));
        var status = [];
        if (citation.response_status) {
            status.push('Office of Licensing: ' + citation.response_status
                + (citation.response_date ? ', ' + (formatIso(ctx, citation.response_date) || citation.response_date) : ''));
        }
        if (citation.planned_date) status.push('Planned completion: ' + citation.planned_date);
        evidence = evidence.concat(status);
        var more = [];
        if (detail.provider_action) more.push({ title: 'The provider’s corrective action', paragraphs: lines(detail.provider_action) });
        if (detail.licensing_response) more.push({ title: 'Office of Licensing response', paragraphs: lines(detail.licensing_response) });
        return ui.finding({
            title: requirement ? shorten(requirement, 180) : (citation.standard || 'Standard'),
            citation: citation.standard,
            chips: chips,
            evidence: evidence,
            requirement: requirement && shorten(requirement, 180) !== requirement ? [requirement] : [],
            more: more,
            tone: cited ? 'flagged' : (citation.comp === 'C' ? 'clean' : 'neutral')
        });
    }

    function dbhdsBody(report, ctx) {
        var html = '';
        var state = dbhdsState(report);
        var details = report.detail ? list(report.detail.citations) : [];
        var multi = report.locations.length > 1;
        if (report.citations.length) {
            var cited = report.citations.filter(isCited).length;
            html += ui.heading(cited
                ? ctx.plural(cited, 'standard') + ' rated non-compliance'
                    + (cited < report.citations.length ? ' (' + report.citations.length + ' rows in the plan)' : '')
                : ctx.plural(report.citations.length, 'row') + ' in the plan, none rated N or NS');
            html += report.citations.map(function (c, i) { return citationBlock(c, details[i], multi, ctx); }).join('');
        } else if (state === 'no_violation') {
            html += ui.note('The corrective action plan for this record reads “No Violation”.');
        } else if (state === 'unread') {
            html += ui.note(DBHDS_UNREAD);
        } else {
            html += ui.note(DBHDS_NO_PLAN);
        }
        if (multi) html += ui.paragraphs(['Locations under this licence: ' + report.locations.join('; ')]);
        // The plan's text repeats the rows above; it is shown only when they could not be laid out.
        if (report.raw_content && !details.length) html += ui.section('Text read from the plan', ui.docText(report.raw_content));
        html += ui.note(DBHDS_PLAN_NOTE);
        return html;
    }

    // ---- mount -----------------------------------------------------------------

    page.mount({
        state: 'Virginia',
        archiveState: 'VA',
        emptyMessage: 'No facilities found in the database for Virginia.',

        filters: [{
            id: 'agency',
            label: 'Agency:',
            options: [
                { value: 'ALL', label: 'Both agencies' },
                { value: 'vdss', label: 'Social Services (VDSS)' },
                { value: 'dbhds', label: 'Behavioral Health (DBHDS)' }
            ],
            test: function (f, value) { return f.source === value; }
        }, {
            id: 'service',
            label: 'Service type:',
            options: [{ value: 'ALL', label: 'All service types' }].concat(SERVICE_TYPES),
            test: function (f, value) { return f.category === value; }
        }, {
            id: 'kind',
            label: 'Record type:',
            options: [
                { value: 'ALL', label: 'All records' },
                { value: 'inspection', label: 'Inspections' },
                { value: 'complaint', label: 'Complaint inspections (VDSS)' },
                { value: 'investigation', label: 'Investigations (DBHDS)' }
            ],
            test: function (f, value) {
                return f.reports.some(function (r) { return recordTypeIs(r, value); });
            },
            testReport: function (r, value) { return recordTypeIs(r, value); }
        }],

        load: function () {
            // lite: no report text in the list; withText() fetches a report's
            // text and plan detail when it opens.
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=VA&lite=1')
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

        searchText: function (f) {
            return [f.name, f.number, f.category, f.address, f.phone, f.provider, f.contact]
                .concat(f.locations)
                .map(function (v) { return safeString(v).toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        countFlagged: function (f) {
            return f.reports.reduce(function (sum, r) { return sum + findingCount(r); }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var countOf = function (test) { return reports.filter(test).length; };
            var stats = [];
            if (facility.source === 'vdss') {
                stats.push({ text: ctx.plural(reports.length, 'inspection'), tone: 'neutral' });
                var withViolations = countOf(isFlagged);
                if (withViolations) stats.push({ text: withViolations + ' with violations', tone: 'flagged' });
                var complaints = countOf(function (r) { return r.complaint; });
                if (complaints) stats.push({ text: ctx.plural(complaints, 'complaint inspection'), tone: 'neutral' });
            } else {
                var inspections = countOf(function (r) { return r.kind === 'inspection'; });
                var investigations = reports.length - inspections;
                if (inspections) stats.push({ text: ctx.plural(inspections, 'inspection'), tone: 'neutral' });
                if (investigations) stats.push({ text: ctx.plural(investigations, 'investigation'), tone: 'neutral' });
                var cited = countOf(isFlagged);
                if (cited) stats.push({ text: cited + ' with standards not met', tone: 'flagged' });
                var noPlan = countOf(function (r) { return dbhdsState(r) === 'no_plan'; });
                if (noPlan) stats.push({ text: noPlan + ' with no plan posted', tone: 'neutral' });
            }
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            var vdss = facility.source === 'vdss';
            var licence = vdss ? facility.number.replace(/^VDSS-/, '') : facility.number;
            return {
                meta: [
                    facility.category,
                    licence ? 'Licence ' + licence : '',
                    facility.status ? (vdss ? 'Licence type ' : 'Licence status: ') + facility.status : '',
                    facility.expires ? 'Expires ' + formatIso(ctx, facility.expires) : '',
                    !vdss && facility.provider && facility.provider !== facility.name ? 'Provider: ' + facility.provider : '',
                    !vdss && facility.locations.length > 1 ? 'Locations: ' + facility.locations.join('; ') : '',
                    facility.capacity ? 'Licensed capacity ' + facility.capacity : '',
                    facility.contact ? (vdss ? 'Administrator ' : 'Contact ') + facility.contact : '',
                    facility.phone
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var vdss = report.source === 'vdss';
            var links = vdss ? [] : report.archive_names.map(function (name) {
                return ctx.archiveLink(name, 'Corrective action plan (archived copy)');
            });
            return {
                date: formatIso(ctx, report.report_date) || 'Date unknown',
                type: typeLabel(report),
                tone: reportTone(report),
                badges: reportBadges(report, ctx),
                facts: reportFacts(report, ctx),
                link: report.report_url
                    ? { href: report.report_url, text: vdss ? 'State inspection page' : 'DBHDS provider search' }
                    : null,
                links: links,
                preview: reportPreview(report),
                // A VDSS inspection's list entry already holds everything its
                // body shows; a DBHDS plan's detail comes with its text.
                body: vdss
                    ? function () { return vdssBody(report, ctx); }
                    : function () { return page.withText(report, 'VA', function () { return dbhdsBody(report, ctx); }); }
            };
        }
    });

    function recordTypeIs(report, value) {
        if (value === 'complaint') return report.source === 'vdss' && report.complaint;
        return report.kind === value;
    }
}());
