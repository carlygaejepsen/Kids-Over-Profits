/**
 * Shared facility "materials on file" catalog.
 *
 * Before this file there were six private copies of the resource key -> label
 * map (facilities-display.js, tti-program-index.js, location-index.js,
 * state-page.js, country-page.js, data-report-generator.js) and they had
 * drifted apart: the facility-card copy knew 13 keys, so eight flags curators
 * type into the data form -- police reports, property records, enrollment
 * documents, the three manuals, articles of organization, survivor stories --
 * were saved and then never rendered anywhere on that surface.
 *
 * The keys below are the resources.* checkboxes in templates/data-form-admin.php
 * and templates/data-form-public.php (both forms carry the same 21 flags), plus
 * the legacy keys that only appear in older exports.
 *
 * Only materials we HOLD are ever rendered. Nothing here emits an unchecked or
 * "missing" row -- a facility with three documents shows three checkmarks, not
 * three checkmarks and eighteen empty boxes.
 *
 * Classic script on purpose: the state report pages load their bundles with a
 * jQuery dependency and no module loader, so this attaches to window.KOP.
 */
(function (global) {
    'use strict';

    var KOP = global.KOP = global.KOP || {};

    /** Display order for grouped output; mirrors the data form's own headings. */
    var GROUPS = [
        { key: 'news',     label: 'News & Media' },
        { key: 'official', label: 'Official Documentation' },
        { key: 'legal',    label: 'Legal & Compliance' },
        { key: 'business', label: 'Business & Property' },
        { key: 'manuals',  label: 'Manuals & Handbooks' },
        { key: 'other',    label: 'Other Documentation' }
    ];

    /**
     * key       - the resources.* boolean written by the data form
     * label     - sentence case, matching the form's own wording
     * group     - one of GROUPS[].key
     * detailKey - sibling resources.* textarea holding free text about it
     * countFrom - facility paths to try for a count badge, first hit wins. A
     *             positive count also marks the material held on its own --
     *             see held() for why linked records beat the manual flag
     * panel     - state-page.js facility panel this material can open
     * legacy    - not in the current form; kept so old records still render
     */
    var CATALOG = [
        { key: 'hasNews',                   label: 'News articles',              group: 'news',     detailKey: 'newsDetails',
          countFrom: ['linked_news.length', 'news.length'], panel: 'news' },
        { key: 'hasPressReleases',          label: 'Press releases',             group: 'news',     detailKey: 'pressReleasesDetails' },
        { key: 'hasSocialMedia',            label: 'Social media',               group: 'news',     legacy: true },
        { key: 'hasVideo',                  label: 'Video',                      group: 'news',     legacy: true },
        { key: 'hasAudio',                  label: 'Audio',                      group: 'news',     legacy: true },

        { key: 'hasInspections',            label: 'Inspection reports',         group: 'official',
          countFrom: ['inspections.length', 'inspection_stats.report_count', 'inspection_count'], panel: 'inspections' },
          // inspections.length first: the state REST tile dedupes that array by report
          // fingerprint, while inspection_count is the raw row total (26 vs 20 for
          // Alliance Youth Services). The badge must match the reports a visitor can
          // open and the panel button's own count. Pages with no array fall through.
        { key: 'hasStateReports',           label: 'State reports',              group: 'official' },
        { key: 'hasRegulatoryFilings',      label: 'Regulatory filings',         group: 'official' },

        { key: 'hasLawsuits',               label: 'Lawsuits',                   group: 'legal',
          countFrom: ['linked_lawsuits.length', 'lawsuits.length'], panel: 'lawsuits' },
        { key: 'hasPoliceReports',          label: 'Police reports',             group: 'legal' },
        { key: 'hasSettlements',            label: 'Settlements',                group: 'legal',    legacy: true },
        { key: 'hasViolations',             label: 'Documented violations',      group: 'legal',    legacy: true },

        { key: 'hasArticlesOfOrganization', label: 'Articles of organization',   group: 'business' },
        { key: 'hasPropertyRecords',        label: 'Property records',           group: 'business' },
        { key: 'hasPromotionalMaterials',   label: 'Promotional materials',      group: 'business' },
        { key: 'hasEnrollmentDocuments',    label: 'Enrollment documents',       group: 'business' },
        { key: 'hasFinancial',              label: 'Financial reports',          group: 'business' },

        { key: 'hasStudent',                label: 'Student or resident manual', group: 'manuals' },
        { key: 'hasStaff',                  label: 'Staff manual',               group: 'manuals' },
        { key: 'hasParent',                 label: 'Parent manual',              group: 'manuals' },

        { key: 'hasResearch',               label: 'Academic research',          group: 'other' },
        { key: 'hasSurvivorStories',        label: 'Survivor stories',           group: 'other' },
        { key: 'hasNATSAP',                 label: 'NATSAP profile',             group: 'other' },
        { key: 'hasWebsite',                label: 'Archived website',           group: 'other' },
        { key: 'hasOther',                  label: 'Other documentation',        group: 'other' }
    ];

    var BY_KEY = {};
    CATALOG.forEach(function (entry) { BY_KEY[entry.key] = entry; });

    /** "hasWildernessTherapy" -> "Wilderness therapy". Fallback for keys the catalog has not caught up with. */
    function humanizeKey(key) {
        var text = String(key || '')
            .replace(/^has(?=[A-Z0-9])/, '')
            .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
            .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')
            .trim();
        if (!text) return '';
        return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
    }

    function cleanText(value) {
        if (value === null || value === undefined) return '';
        if (typeof value === 'string') return value.trim();
        if (typeof value === 'number' || typeof value === 'boolean') return String(value);
        return '';
    }

    /** Walk "inspection_stats.report_count" / "linked_news.length" against a facility. */
    function readPath(facility, path) {
        var node = facility;
        var parts = String(path).split('.');
        for (var i = 0; i < parts.length; i++) {
            if (node === null || node === undefined) return undefined;
            node = node[parts[i]];
        }
        return node;
    }

    function resolveCount(facility, entry) {
        if (!entry.countFrom) return 0;
        for (var i = 0; i < entry.countFrom.length; i++) {
            var num = Number(readPath(facility, entry.countFrom[i]));
            if (isFinite(num) && num > 0) return num;
        }
        return 0;
    }

    /**
     * The two shapes a facility carries its resources in:
     *   facilities_master JSON  -> facility.resources  {hasNews: true, newsDetails: "..."}
     *   state/country REST tile -> facility.resource_flags ["hasNews", ...]
     *                              facility.resource_details {newsDetails: "..."}
     * Both normalize to one flat lookup so held() has a single code path.
     */
    function resourceLookup(facility) {
        if (!facility) return {};
        if (facility.resources && typeof facility.resources === 'object') return facility.resources;

        var lookup = {};
        if (Array.isArray(facility.resource_flags)) {
            facility.resource_flags.forEach(function (key) {
                if (key) lookup[String(key)] = true;
            });
        }
        var details = facility.resource_details;
        if (details && typeof details === 'object') {
            Object.keys(details).forEach(function (key) {
                if (!(key in lookup)) lookup[key] = details[key];
            });
        }
        return lookup;
    }

    /**
     * Every material this facility actually holds, in catalog order.
     *
     * A material counts as held when a curator ticked its flag OR we have linked
     * records proving it (inspection reports, linked news, linked lawsuits).
     * Flags alone badly undercount: on the live Utah hub only 7 of 313
     * facilities had any flag ticked, while 136 held linked inspections, news or
     * lawsuits. Showing only ticked flags would leave nearly every card blank
     * while the page holds the documents a click away.
     *
     * Unknown has* keys set to true are still returned (humanized) so a new form
     * checkbox shows up here the day it is added, ahead of this catalog.
     * Returns [{ key, label, group, count, detail, panel, custom }].
     */
    function held(facility) {
        var resources = resourceLookup(facility);
        var out = [];
        var seen = {};

        CATALOG.forEach(function (entry) {
            var count = resolveCount(facility, entry);
            if (resources[entry.key] !== true && count === 0) return;
            seen[entry.key] = true;
            out.push({
                key: entry.key,
                label: entry.label,
                group: entry.group,
                count: count,
                detail: entry.detailKey ? cleanText(resources[entry.detailKey]) : '',
                panel: entry.panel || '',
                custom: false
            });
        });

        Object.keys(resources).forEach(function (key) {
            if (seen[key] || BY_KEY[key] || resources[key] !== true) return;
            if (!/^has[A-Z0-9]/.test(key)) return;
            var label = humanizeKey(key);
            if (!label) return;
            out.push({ key: key, label: label, group: 'other', count: 0, detail: '', panel: '', custom: false });
        });

        var custom = resources.customResources;
        if (Array.isArray(custom)) {
            custom.forEach(function (item) {
                var label = cleanText(item);
                if (!label) return;
                out.push({ key: '', label: label, group: 'other', count: 0, detail: '', panel: '', custom: true });
            });
        }

        return out;
    }

    /** held(), bucketed into GROUPS order. Empty groups are dropped. */
    function heldByGroup(facility) {
        var items = held(facility);
        return GROUPS.map(function (group) {
            return {
                key: group.key,
                label: group.label,
                items: items.filter(function (item) { return item.group === group.key; })
            };
        }).filter(function (group) { return group.items.length > 0; });
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * One material as a checked row/chip. The check box is drawn in CSS and
     * marked aria-hidden -- the list is already labelled "Materials on file", so
     * announcing "checked" on every item would just be noise.
     *
     * A panel item renders as a button so the state hub can open the matching
     * facility panel; everything else is inert text.
     */
    function renderItem(item, opts) {
        var count = item.count > 0 ? '<span class="kop-holding-count">' + escapeHtml(item.count) + '</span>' : '';
        var detail = (opts.showDetail && item.detail)
            ? '<p class="kop-holding-detail">' + escapeHtml(item.detail) + '</p>'
            : '';
        var body = '<span class="kop-holding-check" aria-hidden="true"></span>'
            + '<span class="kop-holding-label">' + escapeHtml(item.label) + '</span>'
            + count;

        // Prose (a detail line, or a curator's free-text custom entry, which on
        // live records runs to whole sentences) gets the full row width.
        var wide = (detail || item.custom) ? ' kop-holding--wide' : '';

        if (opts.linkPanels && item.panel) {
            return '<li class="kop-holding kop-holding--linked' + wide + '">'
                + '<button type="button" class="kop-holding-btn" data-panel="' + escapeHtml(item.panel) + '">'
                + body + '<span class="kop-holding-go" aria-hidden="true">View</span></button>' + detail + '</li>';
        }
        return '<li class="kop-holding' + wide + '">' + body + detail + '</li>';
    }

    /** Chips shown before the compact strip collapses the rest into "+N more". */
    var SUMMARY_LIMIT = 6;

    /**
     * Compact card-level strip: a count plus chips for the strongest materials.
     * It is for scanning a long list, so it is capped -- the full grouped
     * checklist lives in the expanded card. Items with a record count lead
     * (they are the ones a visitor can open), then catalog order. Free-text
     * custom entries are left to the checklist; they are sentences, not labels.
     *
     * Returns '' when the facility holds nothing, so callers can concatenate it
     * without guarding.
     *
     * opts.linkPanels - render inspections/news/lawsuits chips as panel buttons
     * opts.limit      - override SUMMARY_LIMIT
     */
    function renderSummary(facility, opts) {
        opts = opts || {};
        var items = held(facility);
        if (!items.length) return '';

        var limit = opts.limit > 0 ? opts.limit : SUMMARY_LIMIT;
        var chips = items
            .filter(function (item) { return !item.custom; })
            .map(function (item, index) { return { item: item, index: index }; })
            .sort(function (a, b) {
                return ((b.item.count > 0) - (a.item.count > 0)) || (a.index - b.index);
            })
            .map(function (entry) { return entry.item; });

        var shown = chips.slice(0, limit);
        var hidden = items.length - shown.length;
        var noun = items.length === 1 ? 'material' : 'materials';

        return '<div class="kop-holdings kop-holdings--compact">'
            + '<span class="kop-holdings-count">' + items.length + ' ' + noun + ' on file</span>'
            + '<ul class="kop-holdings-chips">'
            + shown.map(function (item) {
                return renderItem(item, { linkPanels: !!opts.linkPanels, showDetail: false });
            }).join('')
            + (hidden > 0 ? '<li class="kop-holdings-more">+' + hidden + ' more</li>' : '')
            + '</ul></div>';
    }

    /**
     * Full grouped checklist for an expanded card. Groups with nothing on file
     * are omitted entirely rather than shown empty.
     *
     * opts.heading    - heading text, or '' for none (default "Materials on file")
     * opts.showDetail - include the newsDetails / pressReleasesDetails text
     */
    function renderChecklist(facility, opts) {
        opts = opts || {};
        var groups = heldByGroup(facility);
        if (!groups.length) return '';

        var heading = opts.heading === '' ? '' : (opts.heading || 'Materials on file');
        var showDetail = opts.showDetail !== false;

        return '<div class="kop-holdings kop-holdings--full">'
            + (heading ? '<h4 class="kop-holdings-heading">' + escapeHtml(heading) + '</h4>' : '')
            + groups.map(function (group) {
                return '<div class="kop-holdings-group">'
                    + '<h5 class="kop-holdings-group-label">' + escapeHtml(group.label) + '</h5>'
                    + '<ul class="kop-holdings-list">'
                    + group.items.map(function (item) {
                        return renderItem(item, { linkPanels: !!opts.linkPanels, showDetail: showDetail });
                    }).join('')
                    + '</ul></div>';
            }).join('')
            + '</div>';
    }

    KOP.resources = {
        GROUPS: GROUPS,
        CATALOG: CATALOG,
        labelFor: function (key) { return (BY_KEY[key] && BY_KEY[key].label) || humanizeKey(key); },
        humanizeKey: humanizeKey,
        held: held,
        heldByGroup: heldByGroup,
        count: function (facility) { return held(facility).length; },
        renderSummary: renderSummary,
        renderChecklist: renderChecklist
    };
}(window));
