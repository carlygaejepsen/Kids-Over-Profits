/**
 * Iowa survey reports (/ia-reports/) -- adapter for report-page.js.
 *
 * Data comes from api/inspections-read.php?state=IA: survey visits by the
 * Department of Inspections, Appeals and Licensing (DIAL) to Psychiatric
 * Medical Institutions for Children (PMICs), from the state's health
 * facilities database (ia_scraper.py in the Tools repo). One row here is one
 * licensed unit as the state lists it; a provider with several cottages or
 * houses ("Orchard Place - Aliber House") has one row for each.
 *
 * Each report is one survey visit and its statement of deficiencies on the
 * federal CMS-2567 form: the surveyors' opening statement, then one "tag" per
 * rule not met, with the finding and the provider's plan of correction. Tags
 * cite the federal rules for psychiatric residential treatment facilities
 * (42 CFR 483 subpart G, "N" tags), the federal emergency preparedness rules
 * (42 CFR 441.184, "E" tags) and Iowa's own PMIC rules (481 IAC chapter 41,
 * "A" and "P" tags). Visit types, as the state names them: Recertification,
 * Initial, Complaint, Incident, and revisits.
 *
 * What counts as a violation: a visit for which the state's database records
 * at least one deficiency (its federal and state violation counts added
 * together, which do not depend on reading the PDF), and that count is the
 * number shown (categories.tag_count). The one exception is set by the
 * scraper: when the database says 0 but the published form cites deficiency
 * tags, tag_count is the number of tags on the form and
 * categories.state_count_zero is true; the visit is flagged and says the
 * database lists none. A visit with no published PDF is shown as "No report
 * published", not as clean.
 *
 * Where the count read from the form differs from the state's count (the older
 * forms are scanned and read by OCR, which can split one deficiency in two at
 * a page break or lose a tag number), the state's count is shown and the
 * report says how many entries were read from the form. A piece of text the
 * scraper filed as a new tag but that continues the deficiency before it (its
 * "rule title" is a sentence fragment) is joined back to that deficiency here.
 *
 * Complaint and incident investigations are marked, and the visit type filter
 * lists them on their own; a recertification visit that also investigated
 * complaints (its form names complaint numbers such as 130840-C) is marked and
 * listed with them too.
 *
 * The list loads without the text (?lite=1); a report's text and its full tag
 * detail (categories.detail) are fetched when it opens.
 */
(function () {
    'use strict';

    var page = window.KOP && window.KOP.reportPage;
    if (!page) {
        console.error('Iowa reports: report-page.js did not load');
        return;
    }

    var safeString = page.safeString;
    var ui = page.ui;

    var FACILITY_URL = 'https://dia-hfd.iowa.gov/Home/PublicEntityDetails?recordid=';
    var NO_PDF_NOTE = 'The state lists this survey visit but has not published a report for it.';

    // Words a rule title never ends on; a "title" ending on one is a sentence
    // fragment from the form's text.
    var FRAGMENT_END = /^(the|of|a|an|to|and|or|on|at|in|by|for|with|was|were|is|no|dated|within|from|that|than)$/i;

    // "2024-03-19" -> local Date; anything else through Date.
    function parseDate(value) {
        var m = safeString(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) return new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10));
        var d = new Date(safeString(value));
        return isNaN(d.getTime()) ? new Date(0) : d;
    }

    function lines(value) {
        return safeString(value).split(/\n+/).map(safeString).filter(Boolean);
    }

    // The CMS-2567's printed footnote ("Any deficiency statement ending with
    // an asterisk ...") sits under both columns and on some scanned pages is
    // read into the text of the deficiency beside it. Its phrases, as OCR
    // reads them, are taken out of findings, rule text and plans.
    var FORM_FOOTER = [
        /(?:any deficiency )?statement ending with a?n? ?asterisk ?\(\*\) denotes a \S+(?: which the institution)?/gi,
        /(?:which the institution )?may be excused from c\S*rrecting providing it is determin\S*(?: that)?(?: other)?/gi,
        /(?:other safeguards )?provide sufficient protection to the\S? ?patients ?\.? ?\(See (?:instructions\.?\)|reverse for)/gi,
        /further instructions\.?\)/gi,
        /Except (?:for nursing homes, the findings stated above are disclosable(?: 90 days)?(?: following the date)? )?of survey whether [ao]r not a plan of correction is provided[.,]?/gi,
        /(?:for nursing homes, )?the findings stated above are disclosable(?: 90 days)?(?: provided\.)?/gi,
        /(?:For nursing )?homes, [lt]he above findings and plans of(?: correction)? are disclosable(?: 14)?/gi,
        /For nursing the date(?: these documents are made available t[oa] the facility\.?)?/gi,
        /the date these documents are made available t[oa] th\S*(?: facility\.?)?/gi,
        /(?:are cited, )?an approved p\S*n o\S* correction is requisite to continued(?: program participation\.?)?/gi,
        /\bIf deficiencies(?: are cited,?)?(?= |$)/g,
        /TITLE \(X8\) DATE/g,
        /HEALTH FACILITIES\s*-\s*STATE OF IOWA/g
    ];

    function scrubFooter(text) {
        var s = safeString(text);
        if (!/disclosable|asterisk|sufficient protection|requisite to continued|See instructions|TITLE \(X8\)|HEALTH FACILITIES/i.test(s)) return s;
        FORM_FOOTER.forEach(function (re) { s = s.replace(re, ' '); });
        return s.split('\n').map(function (l) { return l.replace(/[ \t]{2,}/g, ' ').trim(); })
            .filter(function (l) { return l && /[A-Za-z]{2}/.test(l); }).join('\n');
    }

    function joinText(a, b) {
        a = safeString(a);
        b = safeString(b);
        if (!a) return b;
        if (!b) return a;
        return a + '\n' + b;
    }

    function isClosed(status) {
        return /^closed/i.test(safeString(status));
    }

    // "Closed (closed 2025-12-31T00:00:00)" -> "Closed Dec 31, 2025"
    function statusLabel(status) {
        var s = safeString(status);
        if (!s) return '';
        if (isClosed(s)) {
            var m = s.match(/(\d{4}-\d{2}-\d{2})/);
            return m ? 'Closed ' + page.formatDate(parseDate(m[1]).getTime()) : 'Closed';
        }
        if (/^active$/i.test(s)) return 'Open';
        return s;
    }

    // "..., IA 511020119" -> "..., IA 51102-0119"
    function formatAddress(address) {
        return safeString(address)
            .replace(/\bIA (\d{5})0000$/, 'IA $1')
            .replace(/\bIA (\d{5})(\d{4})$/, 'IA $1-$2');
    }

    // The state's file name from the report link (ViewReport?fileName=...),
    // which is also the archived copy's name.
    function fileNameOf(url) {
        var m = safeString(url).match(/[?&]fileName=([^&#]+)/i);
        if (!m) return '';
        try { return decodeURIComponent(m[1].replace(/\+/g, ' ')); } catch (e) { return m[1]; }
    }

    /**
     * "483.358(f) ORDERS FOR USE OF RESTRAINT OR SECLUSION" ->
     * { cite: '42 CFR 483.358(f)', title: 'ORDERS FOR ...', kind: 'federal' }.
     * fragment is true when the text is not a rule at all but a line of the
     * finding that the form put where a title would be.
     */
    function readRegulation(text) {
        var s = safeString(text);
        var m = s.match(/^(?:§\s*)?((?:481-)?\d{2,3}\.\d+[A-Za-z]?(?:\([A-Za-z0-9]+\))*)\s*(.*)$/);
        if (m) {
            var section = m[1];
            var title = safeString(m[2]).replace(/^\d+\.\s*/, '');
            if (/^(483|441)\./.test(section)) {
                return { cite: '42 CFR ' + section, title: title, kind: /^441\./.test(section) ? 'emergency' : 'federal', fragment: false };
            }
            return { cite: '481 IAC ' + section.replace(/^481-/, ''), title: title, kind: 'iowa', fragment: false };
        }
        var words = s.split(/\s+/).filter(Boolean);
        var capitals = /[A-Z]{3}/.test(s) && s.toUpperCase() === s;
        var shortTitle = words.length > 0 && words.length <= 5 && /^[A-Z]/.test(s)
            && !/[.,:;!#]$/.test(s) && !/\d/.test(s) && !FRAGMENT_END.test(words[words.length - 1]);
        if (capitals || shortTitle) return { cite: '', title: s, kind: '', fragment: false };
        return { cite: '', title: '', kind: '', fragment: !!s, text: s };
    }

    function tagKind(tag, fromRegulation) {
        if (fromRegulation) return fromRegulation;
        var letter = safeString(tag).charAt(0).toUpperCase();
        if (letter === 'N') return 'federal';
        if (letter === 'E') return 'emergency';
        if (letter === 'A' || letter === 'P') return 'iowa';
        return '';
    }

    var KIND_LABELS = {
        federal: 'Federal PRTF rule',
        emergency: 'Federal emergency preparedness rule',
        iowa: 'Iowa PMIC rule'
    };

    function startsFinding(text) {
        return /^based on\b/i.test(safeString(text));
    }

    /**
     * The tags as the form lists them (categories.tags) with their detail laid
     * over by position (detail.tags; empty on the list, filled when a report
     * opens), and fragments joined back to the deficiency they continue.
     */
    function cleanTags(tags, detailTags) {
        var out = [];
        (tags || []).forEach(function (t, i) {
            var full = (detailTags && detailTags[i]) || {};
            var reg = readRegulation(t.regulation);
            var entry = {
                tag: safeString(t.tag),
                cite: reg.cite,
                title: reg.title,
                kind: tagKind(t.tag, reg.kind),
                requirement: scrubFooter(full.requirement),
                finding: scrubFooter(full.finding || t.finding),
                plan: scrubFooter(full.plan || t.plan),
                completion_date: safeString(t.completion_date),
                fromStateList: !!t.tag_from_state_list
            };
            var prev = out[out.length - 1];
            if (reg.fragment && prev) {
                var merge = false;
                if (!startsFinding(entry.finding)) {
                    // The whole entry continues the deficiency before it.
                    prev.finding = joinText(prev.finding, joinText(reg.text, entry.finding));
                    merge = true;
                } else if (!prev.tag && !startsFinding(prev.finding)) {
                    // The entry before held only the rule's wording (no tag
                    // number, no finding); this one holds its finding.
                    prev.requirement = joinText(prev.requirement, joinText(prev.finding, reg.text));
                    prev.finding = entry.finding;
                    merge = true;
                }
                if (merge) {
                    prev.plan = joinText(prev.plan, entry.plan);
                    prev.tag = prev.tag || entry.tag;
                    if (entry.completion_date > prev.completion_date) prev.completion_date = entry.completion_date;
                    return;
                }
                // A new deficiency whose title line was misread: the fragment
                // ends the deficiency before it.
                prev.finding = joinText(prev.finding, reg.text);
            } else if (reg.fragment) {
                entry.finding = joinText(reg.text, entry.finding);
            }
            out.push(entry);
        });
        return out;
    }

    function visitGroup(report) {
        if (report.is_complaint || report.complaint_numbers.length) return 'investigation';
        if (/incident/i.test(report.visit_type)) return 'investigation';
        if (report.is_revisit || /revisit/i.test(report.visit_type)) return 'revisit';
        if (/^initial/i.test(report.visit_type)) return 'initial';
        return 'routine';
    }

    function convertApiDataToFacilities(apiFacilities) {
        return (apiFacilities || []).map(function (facility) {
            var info = facility.facility_info || {};
            var reports = (facility.reports || []).map(function (report) {
                var cats = report.categories || {};
                var tags = Array.isArray(cats.tags) ? cats.tags : [];
                var fed = parseInt(cats.violations_fed, 10) || 0;
                var st = parseInt(cats.violations_state, 10) || 0;
                var url = safeString(report.report_url);
                var fileName = fileNameOf(url);
                var r = {
                    report_id:    safeString(report.report_id),
                    report_date:  safeString(report.report_date),
                    report_url:   url,
                    file_name:    fileName,
                    has_pdf:      !!fileName,
                    raw_content:  String(report.raw_content || ''),
                    has_text:     report.has_text !== undefined ? !!report.has_text : !!report.raw_content,
                    row_id:       report.row_id || null,
                    visit_type:   safeString(cats.visit_type) || 'Survey',
                    is_complaint: !!cats.is_complaint,
                    is_revisit:   !!cats.is_revisit,
                    complaint_numbers: Array.isArray(cats.complaint_numbers) ? cats.complaint_numbers.map(safeString).filter(Boolean) : [],
                    state_count:  fed + st,
                    // The scraper's count: the state's, or the form's parsed
                    // tags when the state's is 0 (state_count_zero).
                    cited:        Math.max(0, parseInt(cats.tag_count, 10) || 0),
                    form_only:    !!cats.state_count_zero,
                    raw_tags:     tags,
                    tags:         cleanTags(tags, null),
                    detail:       cats.detail || null
                };
                r.group = visitGroup(r);
                return r;
            }).sort(function (a, b) { return parseDate(b.report_date) - parseDate(a.report_date); });

            var entityId = safeString(info.program_name).replace(/^IA-/, '');
            return {
                name:      safeString(info.facility_name),
                id:        safeString(info.program_name),
                entityId:  entityId,
                category:  safeString(info.program_category),
                address:   formatAddress(info.full_address),
                phone:     safeString(info.phone),
                capacity:  safeString(info.bed_capacity),
                status:    safeString(info.action),
                reports:   reports
            };
        });
    }

    function isFlagged(report) {
        return report.cited > 0;
    }

    function reportBadges(report, ctx) {
        var badges = [];
        // The visit type already names a complaint or incident visit; a
        // recertification that also investigated complaints gets a mark.
        if (report.group === 'investigation' && !/complaint|incident/i.test(report.visit_type)) {
            badges.push({ text: 'Complaints investigated', tone: 'neutral' });
        }
        if (report.cited) {
            badges.push({ text: ctx.plural(report.cited, 'deficiency', 'deficiencies') + ' cited', tone: 'flagged' });
        } else if (!report.has_pdf) {
            badges.push({ text: 'No report published', tone: 'neutral' });
        } else {
            badges.push({ text: 'No deficiencies cited', tone: 'clean' });
        }
        return badges;
    }

    function tagFinding(t) {
        var chips = [];
        if (t.cite) chips.push({ text: t.cite, tone: 'neutral' });
        if (KIND_LABELS[t.kind]) chips.push({ text: KIND_LABELS[t.kind], tone: 'neutral' });
        var planTitle = 'Provider’s plan of correction'
            + (t.completion_date ? ' (completion date ' + page.formatDate(parseDate(t.completion_date).getTime()) + ')' : '');
        var title = t.title ? page.displayName(t.title) : (t.tag ? 'Tag ' + t.tag : 'Deficiency (tag number not read)');
        return ui.finding({
            title: title,
            citation: t.tag,
            chips: chips,
            evidence: lines(t.finding),
            requirement: lines(t.requirement),
            more: [lines(t.plan).length ? { title: planTitle, paragraphs: lines(t.plan) } : null],
            tone: 'flagged'
        });
    }

    // The opening statement without the form's running footer and label.
    function introLines(report) {
        var intro = report.detail ? safeString(report.detail.intro) : '';
        return lines(intro).filter(function (l) {
            return !/^health facilities\s*-\s*state of iowa$/i.test(l) && !/^initial comments$/i.test(l);
        });
    }

    function reportBody(report, ctx) {
        var html = '';
        if (!report.has_pdf) {
            html += ui.note(NO_PDF_NOTE);
            return html;
        }
        var intro = introLines(report);
        if (intro.length) html += ui.section('Surveyors’ opening statement', ui.paragraphs(intro), { open: true });

        var detailTags = report.detail && Array.isArray(report.detail.tags) ? report.detail.tags : null;
        var tags = detailTags ? cleanTags(report.raw_tags, detailTags) : report.tags;
        if (tags.length) {
            html += ui.heading(ctx.plural(tags.length, 'deficiency', 'deficiencies') + ' on the form');
            if (report.form_only) {
                html += ui.note('The state’s database lists no deficiencies for this visit; the published report cites '
                    + ctx.plural(report.cited, 'deficiency', 'deficiencies') + '.');
            } else if (report.state_count && tags.length !== report.state_count) {
                html += ui.note('The state counts ' + ctx.plural(report.state_count, 'deficiency', 'deficiencies')
                    + ' at this visit. The form’s text was read into ' + ctx.plural(tags.length, 'entry', 'entries')
                    + ', so one deficiency may be split in two, or two run together. The full report text is below.');
            }
            html += tags.map(tagFinding).join('');
        } else if (report.state_count) {
            html += ui.note('The state counts ' + ctx.plural(report.state_count, 'deficiency', 'deficiencies')
                + ' at this visit, but none could be read from the form. The full report text is below.');
        } else if (!intro.length) {
            html += ui.note('This report lists no deficiencies.');
        }
        html += ui.section('Full report text', ui.docText(report.raw_content));
        return html;
    }

    function preview(report) {
        if (!report.has_pdf) return NO_PDF_NOTE;
        var first = report.tags.filter(function (t) { return startsFinding(t.finding); })[0]
            || report.tags.filter(function (t) { return t.finding; })[0];
        var text = first ? safeString(first.finding).replace(/\s+/g, ' ') : '';
        return text.length > 220 ? text.slice(0, 217).replace(/\s+\S*$/, '') + '…' : text;
    }

    function facilityLink(facility) {
        return facility && facility.entityId ? FACILITY_URL + encodeURIComponent(facility.entityId) : '';
    }

    // report() gets no facility, so each report remembers its own.
    function link(facilities) {
        facilities.forEach(function (f) {
            f.reports.forEach(function (r) { r.facility = f; });
        });
        return facilities;
    }

    page.mount({
        state: 'Iowa',
        archiveState: 'IA',
        emptyMessage: 'No facilities found in the database for Iowa.',

        filters: [{
            id: 'visit',
            label: 'Visit type:',
            options: [
                { value: 'ALL', label: 'All visits' },
                { value: 'investigation', label: 'Complaint and incident investigations' },
                { value: 'routine', label: 'Recertification surveys' },
                { value: 'initial', label: 'Initial surveys' },
                { value: 'revisit', label: 'Revisits' }
            ],
            test: function (f, value) {
                return f.reports.some(function (r) { return r.group === value; });
            },
            testReport: function (r, value) { return r.group === value; }
        }, {
            id: 'status',
            label: 'Status:',
            options: [
                { value: 'ALL', label: 'Open and closed' },
                { value: 'open', label: 'Open' },
                { value: 'closed', label: 'Closed' }
            ],
            test: function (f, value) { return (isClosed(f.status) ? 'closed' : 'open') === value; }
        }],

        load: function () {
            // lite: no document text in the list; withText() fetches a
            // report's text and detail when it is opened.
            return fetch('/wp-content/themes/child/api/inspections-read.php?state=IA&lite=1')
                .then(function (resp) {
                    if (!resp.ok) throw new Error('API returned ' + resp.status);
                    return resp.json();
                })
                .then(function (apiData) {
                    return {
                        facilities: link(convertApiDataToFacilities(apiData.facilities)),
                        scrapedTimestamp: apiData.scraped_timestamp || ''
                    };
                });
        },

        facilityName: function (f) { return f.name || ''; },

        searchText: function (f) {
            var complaints = [];
            f.reports.forEach(function (r) { complaints = complaints.concat(r.complaint_numbers); });
            return [f.name, f.id, f.address, complaints.join(' ')]
                .map(function (v) { return (v || '').toLowerCase(); })
                .join(' ');
        },

        reportTime: function (report) { return parseDate(report.report_date).getTime(); },
        isFlagged: isFlagged,

        countFlagged: function (f) {
            return f.reports.reduce(function (sum, r) { return sum + r.cited; }, 0);
        },

        summary: function (facility, ctx) {
            var reports = ctx.reports(facility);
            var investigations = reports.filter(function (r) { return r.group === 'investigation'; }).length;
            var cited = reports.filter(isFlagged).length;
            var latest = reports.reduce(function (max, r) { return Math.max(max, parseDate(r.report_date).getTime()); }, 0);

            var stats = [{ text: ctx.plural(reports.length, 'survey visit'), tone: 'neutral' }];
            if (investigations) stats.push({ text: ctx.plural(investigations, 'investigation'), tone: 'neutral' });
            if (cited) stats.push({ text: ctx.plural(cited, 'visit') + ' with deficiencies', tone: 'flagged' });
            else stats.push({ text: 'No deficiencies cited', tone: 'clean' });
            if (latest > 0) stats.push({ text: 'Latest ' + ctx.formatDate(latest), tone: 'neutral' });

            return {
                meta: [
                    'Psychiatric Medical Institution for Children',
                    statusLabel(facility.status),
                    facility.capacity ? 'Licensed beds: ' + facility.capacity : '',
                    // Non-breaking hyphen: the number never wraps in two.
                    facility.phone.replace(/-/g, '‑')
                ],
                address: facility.address,
                stats: stats
            };
        },

        report: function (report, ctx) {
            var facts = ['Visit ' + report.report_id];
            if (report.complaint_numbers.length) {
                facts.push((report.complaint_numbers.length === 1 ? 'Complaint ' : 'Complaints ') + report.complaint_numbers.join(', '));
            }
            var links = [];
            if (report.has_pdf) {
                links.push(ctx.archiveLink(report.file_name, 'Report (archived copy)'));
                links.push({ href: facilityLink(report.facility), text: 'State facility page' });
            }
            return {
                date: ctx.formatDate(parseDate(report.report_date).getTime()) || 'Date unknown',
                type: report.visit_type,
                tone: isFlagged(report) ? 'flagged' : (report.has_pdf ? 'clean' : 'neutral'),
                badges: reportBadges(report, ctx),
                facts: facts,
                link: report.has_pdf
                    ? { href: report.report_url, text: 'State report (PDF)' }
                    : { href: report.report_url || facilityLink(report.facility), text: 'State facility page' },
                links: links,
                preview: preview(report),
                body: function () {
                    return page.withText(report, 'IA', function () { return reportBody(report, ctx); });
                }
            };
        }
    });
}());
