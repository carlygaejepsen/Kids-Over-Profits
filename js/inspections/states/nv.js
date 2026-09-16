/**
 * Nevada inspection records (/nv-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=NV. Two agencies:
 *   DSS-CCL  Division of Social Services, Child Care Licensing (institutions)
 *   HCQC     Nevada Health Authority, Health Care Quality and Compliance (PRTFs,
 *            substance use treatment facilities)
 *
 * Each record is inspection metadata only: inspection number, date and time,
 * reason ("Annual", "Complaint - 9233", "Ad-hoc"), a rarely filled grade and
 * how many documents the agency holds. There are no findings and no report
 * link, so nothing here can be flagged as a violation. The old viewer read a
 * deficiency_count field that is not in the data, so it never flagged anything
 * either; this adapter says so plainly instead of implying a clean record.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Nevada reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var AGENCIES = {
        'DSS-CCL': 'Nevada DSS Child Care Licensing',
        'HCQC': 'Nevada Health Authority (HCQC)'
    };

    // "12/29/2023" -> Date; placeholders like "1" or "..." -> unknown.
    function parseDate(dateStr) {
        var m = String(dateStr || '').trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/);
        if (!m) return new Date(0);
        var y = parseInt(m[3], 10);
        if (y < 100) y += (y < 50) ? 2000 : 1900;
        return new Date(y, parseInt(m[1], 10) - 1, parseInt(m[2], 10));
    }

    function inspectionNumber(raw) {
        var m = String(raw || '').match(/Inspection #(\d+)/);
        return m ? m[1] : '';
    }

    // Grade and event id arrive as null or "N/A" when absent.
    function present(value) {
        var text = safeString(value);
        return /^(n\/a|null|none)$/i.test(text) ? '' : text;
    }

    function describeReason(reason, agency) {
        var text = safeString(reason);
        var complaint = text.match(/^Complaint\s*-\s*(\S+)/i);
        if (complaint) return { label: 'Complaint investigation', complaint: true, number: complaint[1] };
        if (/^annual$/i.test(text)) return { label: 'Annual inspection', complaint: false };
        if (/^bi-?annual$/i.test(text)) return { label: 'Biannual inspection', complaint: false };
        if (/^ad-?hoc$/i.test(text)) return { label: 'Unscheduled inspection', complaint: false };
        if (text && !/^\d+$/.test(text)) return { label: text, complaint: /complaint/i.test(text) };
        return { label: agency === 'HCQC' ? 'Health Authority inspection' : 'Inspection', complaint: false };
    }

    function convertApiDataToFacilities(apiFacilities) {
        return apiFacilities.map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var agency = safeString(cats.agency);
                var reason = describeReason(cats.inspection_reason, agency);
                var time = safeString(cats.inspection_time);
                return {
                    report_date:     report.report_date || '',
                    agency:          agency,
                    reason:          reason,
                    inspection_no:   inspectionNumber(report.raw_content),
                    time:            /^0:00 AM$/.test(time) ? '' : time,   // 0:00 AM means no time recorded
                    grade:           present(cats.grade),
                    event_id:        present(cats.event_id),
                    license_number:  safeString(cats.license_number),
                    credential_type: safeString(cats.credential_type),
                    doc_count:       parseInt(cats.doc_count, 10) || 0
                };
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            var license = reports.map(function (r) { return r.license_number; }).filter(Boolean)[0] || '';
            return {
                name:             safeString(info.facility_name),
                program_category: safeString(info.program_category),
                address:          safeString(info.full_address),
                license_number:   license,
                license_status:   safeString(info.action),
                license_exp_date: safeString(info.license_exp_date),
                reports:          reports
            };
        });
    }

    page.mount({
        state: 'Nevada',
        emptyMessage: 'No facilities found in the database for Nevada.',
        violationsNote: 'Nevada’s licensing records list inspection dates and reasons but not their findings, so these reports cannot be filtered by violations.',

        load: function () {
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=NV')
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
            return [f.name, f.program_category, f.address, f.license_number]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },

        // No findings in the data; see the file comment.
        isFlagged: function () { return false; },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var complaints = reports.filter(function (r) { return r.reason.complaint; }).length;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'inspection'), tone: 'neutral' }];
            if (complaints) stats.push({ text: ctx.plural(complaints, 'complaint investigation'), tone: 'neutral' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [
                    facility.program_category || 'Nevada residential treatment',
                    facility.license_number ? 'License\u00a0' + facility.license_number : '',
                    facility.license_status && facility.license_exp_date
                        ? facility.license_status + ' license, expires ' + facility.license_exp_date
                        : ''
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var badges = [];
            if (report.reason.complaint) badges.push({ text: 'Complaint', tone: 'neutral' });
            if (report.grade) badges.push({ text: 'Grade ' + report.grade, tone: 'neutral' });

            var agencyName = AGENCIES[report.agency] || report.agency || 'the state licensing agency';
            var docs = report.doc_count
                ? ctx.plural(report.doc_count, 'document') + ' on file with ' + agencyName + '.'
                : 'No documents are listed for this inspection.';

            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || 'Date unknown',
                type: report.reason.label,
                tone: 'neutral',
                badges: badges,
                facts: [
                    agencyName,
                    report.inspection_no ? 'Inspection #' + report.inspection_no : '',
                    report.reason.number ? 'Complaint #' + report.reason.number : '',
                    report.time,
                    report.event_id ? 'Event ID ' + report.event_id : ''
                ],
                body: ui.note('Nevada’s licensing records list this inspection but not its findings. ' + docs)
            };
        }
    });
}());
