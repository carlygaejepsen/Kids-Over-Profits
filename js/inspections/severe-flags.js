/**
 * Flags the severe reports in a state tracker's feed (/xx-reports/).
 *
 * The Severe Reports page lists every severe finding an admin has approved
 * (api/review-inspection-highlights.php). This marks the same reports where a
 * reader meets them in the ordinary feed: a "Severe finding" flag on the
 * report's row, a count on its facility, a note inside the opened report that
 * links to the finding, and a banner with a "severe reports only" switch.
 *
 * The trackers read static JSON whose report ids are not the database's, so a
 * report is recognised by the state's own words: the api sends the opening of
 * each quoted finding, lower-cased with the spaces removed (the "needle"), and
 * a report is that finding when its text contains it. A short needle must
 * also sit under the same facility name.
 *
 * It works on the rendered page, so the state viewers need no changes: the
 * legacy viewers (Texas, California) and the shared report-page.js engine use
 * the two markups named in SELECTORS. A facility's text is checked once, whole,
 * and only a hit opens its reports; a report the engine renders lazily sends
 * its facility round again when it is opened.
 *
 * Classic script; attaches to window.KOP. Config: window.KOP_SEVERE_FLAGS =
 * { url, pageUrl }.
 */
(function (global) {
    'use strict';

    var KOP = global.KOP = global.KOP || {};

    var SELECTORS = {
        facility: '.facility-box, .kop-rp-facility',
        facilityName: '.facility-header h1, .kop-rp-facility-name',
        facilitySummary: '.facility-header, .kop-rp-facility-summary',
        report: 'details.inspection-box, details.kop-rp-report',
        reportSummary: '.inspection-header, .kop-rp-report-summary',
        reportBody: '.inspection-content, .kop-rp-report-body'
    };

    // A needle this long is a sentence no other report will repeat; a shorter
    // one ("The restraint resulted in the child being injured.") also has to
    // be under the right facility.
    var UNIQUE_NEEDLE = 60;
    var SHORTEST_NEEDLE = 25;

    function squash(text) {
        return String(text == null ? '' : text).toLowerCase().replace(/\s+/g, '');
    }

    function nameKey(name) {
        return String(name == null ? '' : name).toLowerCase().replace(/[^a-z0-9]+/g, '');
    }

    /**
     * The approved finding a report's text belongs to, or null. Pure. A
     * facility can hold one citation twice with the same opening (Texas
     * lists a few that way), so a finding already claimed by another report
     * (ids in `taken`) yields to an unclaimed one that also matches.
     */
    function find(findings, facilityName, text, taken) {
        var hay = squash(text);
        var key = nameKey(facilityName);
        if (!hay) return null;
        var fallback = null;
        for (var i = 0; i < findings.length; i++) {
            var f = findings[i];
            if (!f || !f.needle || f.needle.length < SHORTEST_NEEDLE) continue;
            // The cheap test first: most findings belong to another facility.
            if (f.needle.length < UNIQUE_NEEDLE && nameKey(f.facility) !== key) continue;
            if (hay.indexOf(f.needle) === -1) continue;
            if (!taken || !taken[f.id]) return f;
            if (!fallback) fallback = f;
        }
        return fallback;
    }

    KOP.severeFlags = { find: find, squash: squash, nameKey: nameKey };

    var doc = global.document;
    var config = global.KOP_SEVERE_FLAGS;
    if (!doc || !config || !config.url || typeof global.fetch !== 'function') return;

    var findings = [];
    var pending = false;

    function el(tag, className, text) {
        var node = doc.createElement(tag);
        node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    // A state can hold tens of thousands of reports (California), so nothing
    // is done per report unless its facility holds a finding: a facility's
    // text is checked once, whole, and only a hit opens its reports. Reading
    // all of Texas takes about a quarter of a second; what costs is the
    // browser re-styling a page this size after each slice (12 ms slices took
    // a minute), so the slices are few and long.
    var SLICE_MS = 200;
    var UNCHECKED = SELECTORS.facility.split(', ').map(function (sel) {
        return sel + ':not([data-kop-severe-count])';
    }).join(', ');

    /** Findings that could sit in this facility: its own, and any long enough to be unique. */
    function candidatesFor(facilityName) {
        var key = nameKey(facilityName);
        return findings.filter(function (f) {
            return f && f.needle && f.needle.length >= SHORTEST_NEEDLE
                && (f.needle.length >= UNIQUE_NEEDLE || nameKey(f.facility) === key);
        });
    }

    function markReport(report, facilityName, candidates, taken) {
        var found = find(candidates, facilityName, report.textContent, taken);
        report.setAttribute('data-kop-severe', found ? 'yes' : 'no');
        if (!found) return false;
        taken[found.id] = true;
        if (report.querySelector('.kop-severe-flag')) return true;

        var summary = report.querySelector(SELECTORS.reportSummary);
        if (summary) summary.appendChild(el('span', 'kop-severe-flag', 'Severe finding'));

        var body = report.querySelector(SELECTORS.reportBody);
        if (body) {
            var note = el('p', 'kop-severe-note', 'Severe finding: ' + found.label + '. Reviewed by Kids Over Profits against this report. ');
            var link = doc.createElement('a');
            link.href = (config.pageUrl || '/severe-reports/') + '#finding-' + found.id;
            link.textContent = 'See it among all severe reports';
            note.appendChild(link);
            body.insertBefore(note, body.firstChild);
        }
        return true;
    }

    function markFacility(facility) {
        var nameNode = facility.querySelector(SELECTORS.facilityName);
        var name = nameNode ? nameNode.textContent : '';
        var hay = squash(facility.textContent);
        var candidates = candidatesFor(name).filter(function (f) { return hay.indexOf(f.needle) !== -1; });

        var count = 0;
        if (candidates.length) {
            var reports = facility.querySelectorAll(SELECTORS.report);
            var taken = {};
            for (var i = 0; i < reports.length; i++) {
                if (markReport(reports[i], name, candidates, taken)) count++;
            }
        }
        facility.setAttribute('data-kop-severe-count', String(count));

        var chip = facility.querySelector('.kop-severe-count');
        if (!count) {
            if (chip) chip.parentNode.removeChild(chip);
            return;
        }
        if (!chip) {
            chip = el('span', 'kop-severe-flag kop-severe-count');
            // Beside the name, never inside it: the name is what a finding is matched on.
            var summary = facility.querySelector(SELECTORS.facilitySummary);
            if (nameNode && nameNode.parentNode) nameNode.parentNode.insertBefore(chip, nameNode.nextSibling);
            else if (summary) summary.appendChild(chip);
            else return;
        }
        var text = count + (count === 1 ? ' severe finding' : ' severe findings');
        // Written only when it changes, so the observer is not woken for nothing.
        if (chip.textContent !== text) chip.textContent = text;
    }

    function scan() {
        pending = false;
        var started = Date.now();
        var facilities = doc.querySelectorAll(UNCHECKED);
        for (var i = 0; i < facilities.length; i++) {
            markFacility(facilities[i]);
            if (Date.now() - started > SLICE_MS && i < facilities.length - 1) {
                schedule();
                return;
            }
        }
    }

    function schedule() {
        if (pending) return;
        pending = true;
        global.setTimeout(scan, 0);
    }

    function addBanner(container) {
        var banner = el('div', 'kop-severe-banner');
        var count = findings.length;
        banner.appendChild(doc.createTextNode(count + (count === 1 ? ' report' : ' reports')
            + ' in this state ' + (count === 1 ? 'is' : 'are') + ' flagged as a severe finding. '));
        var link = doc.createElement('a');
        link.href = config.pageUrl || '/severe-reports/';
        link.textContent = 'All severe reports';
        banner.appendChild(link);

        var label = doc.createElement('label');
        var box = doc.createElement('input');
        box.type = 'checkbox';
        box.addEventListener('change', function () {
            container.classList.toggle('kop-severe-only', box.checked);
        });
        label.appendChild(box);
        label.appendChild(doc.createTextNode(' Severe reports only'));
        banner.appendChild(label);
        container.parentNode.insertBefore(banner, container);
    }

    function start() {
        var container = doc.getElementById('report-container');
        if (!container || !findings.length) return;
        addBanner(container);
        schedule();
        new global.MutationObserver(schedule).observe(container, { childList: true, subtree: true });
        // A lazily rendered report has no text until it is opened: look at its facility again then.
        container.addEventListener('toggle', function (event) {
            var target = event.target;
            if (!target || !target.matches || !target.matches(SELECTORS.report)) return;
            if (target.getAttribute('data-kop-severe') === 'yes') return;
            var facility = target.closest(SELECTORS.facility);
            if (facility) {
                facility.removeAttribute('data-kop-severe-count');
                schedule();
            }
        }, true);
    }

    global.fetch(config.url, { credentials: 'omit' })
        .then(function (response) { return response.ok ? response.json() : { findings: [] }; })
        .then(function (data) {
            findings = (data && data.findings) || [];
            if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', start);
            else start();
        })
        .catch(function () { /* No flags is a safe failure; the feed itself is untouched. */ });
})(typeof window !== 'undefined' ? window : globalThis);
