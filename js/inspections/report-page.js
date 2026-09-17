/**
 * Shared state inspection report page (/xx-reports/).
 *
 * Each of the fifteen state viewers used to carry its own copy of the same
 * machinery: alphabet filter, search, sort, "new reports only", the
 * last-updated stamp and the facility list. This file owns all of that plus
 * the page's visual layout (css/report-page.css). A state supplies only what
 * genuinely differs -- how its data loads and normalizes, which reports carry
 * violations, and the content of a facility summary and a single report --
 * through KOP.reportPage.mount(adapter).
 *
 * Classic script (no module loader on these pages); attaches to window.KOP.
 *
 * Adapter contract -- required:
 *   state              Display name, used in messages ("Georgia").
 *   load()             async -> { facilities: [...], scrapedTimestamp: '' }.
 *                      Facilities are already normalized by the adapter.
 *   facilityName(f)    Raw name, used for grouping, sorting and search.
 *   reportTime(r)      Report date as epoch ms; 0 when unknown.
 *   isFlagged(r)       True when the report records violations.
 *   summary(f, ctx)    -> { meta: [text], address: text, stats: [{ text, tone }] }
 *   report(r, ctx)     -> { date: text, type: text, tone, badges: [{ text, tone }],
 *                           facts: [text], link: { href, text },
 *                           links: [{ href, text }], preview: text, body: html }
 *                      link is the official source; links are any extras.
 *                      preview is a one-line gist shown on the closed row.
 *                      body must be built with ctx.ui helpers (they escape).
 *                      body may be a function returning that html: it then
 *                      runs the first time the report is opened, which keeps
 *                      states with large document text fast to list.
 *
 * Adapter contract -- optional:
 *   reportsKey         Property holding a facility's reports (default 'reports').
 *   searchText(f)      Haystack for the search box (default: facilityName).
 *   countFlagged(f)    Sort weight for "Most Violations First"
 *                      (default: number of flagged reports).
 *   emptyMessage       Shown when load() returns no facilities.
 *   flaggedFilter      'reports' (default): violation sorts keep only flagged
 *                      reports and drop facilities left with none.
 *                      'facilities': keep a facility's full report list and drop
 *                      facilities with no reports at all (Texas behaviour).
 *   defaultSort(a, b)  Comparator for the "Default Order" option.
 *   filters            Extra dropdowns added to the page controls:
 *                      [{ id, label, options: [{ value, label }], test(f, value) }].
 *                      The first option is the "all" choice and filters nothing.
 *   violationsNote     For states whose data carries no findings: shown instead
 *                      of an empty list when a violations sort is chosen, so
 *                      "no results" is not mistaken for "no violations".
 *
 * Tones: 'flagged' (violations), 'clean' (inspected, none found), 'repeat'
 * (a repeat violation), 'neutral'.
 *
 * ctx: { escapeHtml, countFlagged, reports, formatDate, ui }.
 */
(function (global) {
    'use strict';

    var KOP = global.KOP = global.KOP || {};

    var NEW_REPORT_WINDOW_MS = 30 * 24 * 60 * 60 * 1000;
    var TONES = { flagged: 1, clean: 1, repeat: 1, neutral: 1 };

    function safeString(value) {
        return value === null || value === undefined ? '' : String(value).trim();
    }

    function escapeHtml(value) {
        return safeString(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function toneClass(tone) {
        return TONES[tone] ? ' is-' + tone : '';
    }

    function plural(count, one, many) {
        return count + ' ' + (count === 1 ? one : (many || one + 's'));
    }

    /** epoch ms -> "Nov 25, 2025"; '' when unknown. */
    function formatDate(ms) {
        if (!(ms > 0)) return '';
        return new Date(ms).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    // Licensing databases publish names in capitals. Shouting a whole list of
    // facility names is hard to scan, so all-caps names are title-cased for
    // display only; search and sorting still use the name as published.
    var KEEP_UPPER = /^(LLC|LLP|LP|PC|PLLC|PA|II|III|IV|VI|VII|VIII|IX|USA|US|YMCA|YWCA|DBA|NFP|CCI|CPA|DHS|RTC|PRTF|RHYP|MH|GA|NC|SC|ABA|DD|ID|HIV|AIDS)$/;
    var LOWER_WORDS = /^(and|of|the|for|in|at|by|to|on|or|with|from)$/;

    function displayName(name) {
        var text = safeString(name);
        var letters = text.replace(/[^A-Za-z]/g, '');
        var upper = letters.replace(/[^A-Z]/g, '').length;
        // Mostly capitals ("MCM, Inc. DBA CAMP DAVID VISION OF HOPE") still reads
        // as shouting; names already in mixed case are left exactly as published.
        if (!letters || upper / letters.length < 0.8) return text;
        return text.split(/\s+/).map(function (token, index) {
            if (/[a-z]/.test(token)) return token;   // "Inc." is already fine
            return token.split('-').map(function (part) {
                var letters = part.replace(/[^A-Za-z]/g, '');
                if (!letters) return part;
                if (KEEP_UPPER.test(letters)) return part;
                if (/^\d+(ST|ND|RD|TH)$/.test(part)) return part.toLowerCase();
                if (letters.length <= 4 && !/[AEIOUY]/.test(letters)) return part;   // "KBW", "CRS"
                var lower = part.toLowerCase();
                if (index > 0 && LOWER_WORDS.test(lower)) return lower;
                // Capitalize the first letter; "O'BRIEN" -> "O'Brien", "SLADE'S" -> "Slade's".
                return lower.replace(/[a-z]/, function (c) { return c.toUpperCase(); })
                    .replace(/^([^a-z]*[a-z])'([a-z])(?=[a-z]{2})/i, function (m, a, b) { return a + "'" + b.toUpperCase(); });
            }).join('-');
        }).join(' ');
    }

    // ---- Building blocks for adapter-rendered report bodies ----------------

    var ui = {
        /**
         * Paragraphs of source text. A form lead-in of one to three words
         * ending in a colon ("Findings include:") reads as a label; longer
         * colon lines are the inspector's own sentences and stay prose.
         */
        paragraphs: function (list, className) {
            var items = (list || []).map(safeString).filter(Boolean);
            if (!items.length) return '';
            return '<div class="kop-rp-prose' + (className ? ' ' + className : '') + '">'
                + items.map(function (text) {
                    if (/:$/.test(text) && text.split(/\s+/).length <= 3) {
                        return '<p class="kop-rp-label">' + escapeHtml(text.replace(/:$/, '')) + '</p>';
                    }
                    return '<p>' + escapeHtml(text) + '</p>';
                }).join('')
                + '</div>';
        },

        chips: function (chips) {
            var list = (chips || []).filter(function (c) { return c && safeString(c.text); });
            if (!list.length) return '';
            return list.map(function (c) {
                return '<span class="kop-rp-chip' + toneClass(c.tone) + '">' + escapeHtml(c.text) + '</span>';
            }).join('');
        },

        /** A row of chips under a report ("Self Harm", "Peer Violence"). */
        chipRow: function (chips) {
            var html = ui.chips(chips);
            return html ? '<div class="kop-rp-chiprow">' + html + '</div>' : '';
        },

        /**
         * Extracted document text that has no reliable structure to parse
         * (letters, notices, forms). Blank-line runs collapse; line breaks stay,
         * since forms put one field per line.
         */
        docText: function (text) {
            var clean = safeString(text)
                .replace(/\r/g, '')
                .replace(/[ \t]+\n/g, '\n')
                .replace(/\n{3,}/g, '\n\n');
            return clean ? '<div class="kop-rp-doctext">' + escapeHtml(clean) + '</div>' : '';
        },

        /** A collapsible sub-section inside a report ("What the rule requires"). */
        section: function (title, html, opts) {
            if (!html) return '';
            opts = opts || {};
            return '<details class="kop-rp-section"' + (opts.open ? ' open' : '') + '>'
                + '<summary>' + escapeHtml(title) + '</summary>'
                + '<div class="kop-rp-section-body">' + html + '</div>'
                + '</details>';
        },

        /**
         * One cited violation.
         * { title, citation, chips: [{text, tone}], evidence: [text],
         *   requirement: [text], more: [{ title, paragraphs: [text] }], tone }
         */
        finding: function (f) {
            var head = '<div class="kop-rp-finding-head">'
                + '<span class="kop-rp-finding-title">' + escapeHtml(f.title || 'Cited rule') + '</span>'
                + '<span class="kop-rp-finding-tags">'
                + (f.citation ? '<code class="kop-rp-cite">' + escapeHtml(f.citation) + '</code>' : '')
                + ui.chips(f.chips)
                + '</span></div>';
            return '<section class="kop-rp-finding' + toneClass(f.tone || 'flagged') + '">'
                + head
                + ui.paragraphs(f.evidence, 'kop-rp-evidence')
                + ui.section('What the rule requires', ui.paragraphs(f.requirement, 'kop-rp-requirement'))
                + (f.more || []).map(function (m) {
                    return m ? ui.section(m.title, ui.paragraphs(m.paragraphs, 'kop-rp-requirement')) : '';
                }).join('')
                + '</section>';
        },

        note: function (text) {
            return text ? '<p class="kop-rp-note">' + escapeHtml(text) + '</p>' : '';
        },

        heading: function (text) {
            return text ? '<h4 class="kop-rp-subhead">' + escapeHtml(text) + '</h4>' : '';
        }
    };

    function mount(adapter) {
        if (!adapter || typeof adapter.load !== 'function') {
            throw new Error('KOP.reportPage.mount: adapter.load is required');
        }
        if (global.document.readyState === 'loading') {
            global.document.addEventListener('DOMContentLoaded', function () { start(adapter); });
        } else {
            start(adapter);
        }
    }

    function start(adapter) {
        var doc = global.document;
        var reportContainer = doc.getElementById('report-container');
        if (!reportContainer) {
            console.error('ERROR: #report-container element not found on page');
            return;
        }

        // Scopes css/report-page.css; pages still on a per-state script are untouched.
        var pageRoot = doc.querySelector('.facility-report-container') || reportContainer.parentNode;
        if (pageRoot && pageRoot.classList) pageRoot.classList.add('kop-rp');

        var alphabetFilter  = doc.getElementById('alphabet-filter');
        var searchInput     = doc.getElementById('searchInput');
        var sortSelect      = doc.getElementById('sortBy');
        var clearButton     = doc.getElementById('clearSearch');
        var newOnlyCheckbox = doc.getElementById('newReportsOnly');

        var reportsKey    = adapter.reportsKey || 'reports';
        var flaggedFilter = adapter.flaggedFilter || 'reports';
        var facilityName  = function (f) { return safeString(adapter.facilityName(f)); };
        var searchText    = adapter.searchText || facilityName;

        var allFacilitiesData = {};
        var lazyBodies        = [];
        var filterValues      = {};
        var currentLetter     = null;
        var isSearching       = false;
        var scrapedTimestamp  = '';

        function reportsOf(f) {
            var list = f && f[reportsKey];
            return Array.isArray(list) ? list : [];
        }

        function withReports(f, reports) {
            var copy = Object.assign({}, f);
            copy[reportsKey] = reports;
            return copy;
        }

        function countFlagged(f) {
            if (typeof adapter.countFlagged === 'function') return adapter.countFlagged(f);
            return reportsOf(f).filter(adapter.isFlagged).length;
        }

        var ctx = {
            escapeHtml: escapeHtml,
            countFlagged: countFlagged,
            reports: reportsOf,
            formatDate: formatDate,
            plural: plural,
            ui: ui
        };

        function isRecentReport(report) {
            if (!report) return false;
            var t = adapter.reportTime(report);
            return t > 0 && (Date.now() - t) <= NEW_REPORT_WINDOW_MS;
        }

        function mostRecentTime(f) {
            var times = reportsOf(f).map(adapter.reportTime).filter(function (t) { return t > 0; });
            return times.length ? Math.max.apply(null, times) : 0;
        }

        // Adapter-defined dropdowns (e.g. Florida's agency filter).
        (adapter.filters || []).forEach(function (filter) {
            var controls = doc.querySelector('.controls');
            if (!controls || !filter.options || !filter.options.length) return;
            var wrap = doc.createElement('label');
            wrap.className = 'kop-rp-filter';
            var select = doc.createElement('select');
            select.id = 'kop-rp-filter-' + filter.id;
            filter.options.forEach(function (opt) {
                var option = doc.createElement('option');
                option.value = opt.value;
                option.textContent = opt.label;
                select.appendChild(option);
            });
            wrap.appendChild(doc.createTextNode(filter.label + ' '));
            wrap.appendChild(select);
            controls.appendChild(wrap);
            filterValues[filter.id] = filter.options[0].value;
            select.addEventListener('change', function () {
                filterValues[filter.id] = select.value;
                filterAndSort();
            });
        });

        function passesFilters(f) {
            return (adapter.filters || []).every(function (filter) {
                var value = filterValues[filter.id];
                if (value === undefined || value === filter.options[0].value) return true;
                return filter.test(f, value);
            });
        }

        if (searchInput)     searchInput.addEventListener('input', filterAndSort);
        if (sortSelect)      sortSelect.addEventListener('change', filterAndSort);
        if (newOnlyCheckbox) newOnlyCheckbox.addEventListener('change', filterAndSort);

        function initializeReport() {
            Promise.resolve()
                .then(function () { return adapter.load(); })
                .then(function (result) {
                    var facilities = (result && result.facilities) || [];
                    if (!facilities.length) {
                        reportContainer.innerHTML = '<p class="kop-rp-empty">' + escapeHtml(adapter.emptyMessage
                            || ('No facilities found in the database for ' + adapter.state + '.')) + '</p>';
                        return;
                    }

                    scrapedTimestamp = (result && result.scrapedTimestamp) || '';
                    renderLastUpdated();

                    allFacilitiesData = groupFacilities(facilities);
                    renderAlphabetFilter();
                    renderFacilitiesForLetter('ALL');
                })
                .catch(function (error) {
                    console.error('Failed to load ' + adapter.state + ' report data:', error);
                    reportContainer.innerHTML = '<p class="error">Error loading data: ' + escapeHtml(error.message) + '</p>';
                });
        }

        function groupFacilities(list) {
            var grouped = list.reduce(function (acc, f) {
                var first = facilityName(f).charAt(0).toUpperCase();
                var key = (first >= 'A' && first <= 'Z') ? first : '#';
                (acc[key] = acc[key] || []).push(f);
                return acc;
            }, {});
            Object.keys(grouped).forEach(function (k) {
                grouped[k].sort(function (a, b) { return facilityName(a).localeCompare(facilityName(b)); });
            });
            return grouped;
        }

        function renderAlphabetFilter() {
            if (!alphabetFilter) return;
            var letters = Object.keys(allFacilitiesData).sort();
            alphabetFilter.innerHTML = ['<a href="#" data-letter="ALL">All</a>']
                .concat(letters.map(function (l) { return '<a href="#" data-letter="' + l + '">' + l + '</a>'; }))
                .join('');
            alphabetFilter.addEventListener('click', function (e) {
                e.preventDefault();
                if (e.target.tagName === 'A') renderFacilitiesForLetter(e.target.dataset.letter);
            });
        }

        function getFacilitiesForSelection(letter) {
            if (letter === 'ALL') {
                return Object.keys(allFacilitiesData).sort().reduce(function (all, l) {
                    return all.concat(allFacilitiesData[l] || []);
                }, []);
            }
            return allFacilitiesData[letter] || [];
        }

        function setActiveLetter(letter) {
            doc.querySelectorAll('#alphabet-filter a').forEach(function (a) {
                var active = a.dataset.letter === letter;
                a.classList.toggle('active', active);
                if (active) a.setAttribute('aria-current', 'true');
                else a.removeAttribute('aria-current');
            });
        }

        function renderFacilitiesForLetter(letter) {
            if (isSearching) return;
            currentLetter = letter;
            setActiveLetter(letter);
            var facilities = getFacilitiesForSelection(letter);
            renderFilteredFacilities(sortFacilities(facilities, sortSelect ? sortSelect.value : ''), letter);
        }

        function filterAndSort() {
            var term   = searchInput ? searchInput.value.toLowerCase().trim() : '';
            var sortBy = sortSelect ? sortSelect.value : '';
            if (!Object.keys(allFacilitiesData).length) return;

            if (term) {
                isSearching = true;
                if (clearButton) clearButton.style.display = 'inline-block';
                setActiveLetter(null);
                var all = [].concat.apply([], Object.keys(allFacilitiesData).map(function (k) { return allFacilitiesData[k]; }));
                var filtered = all.filter(function (f) {
                    return safeString(searchText(f)).toLowerCase().indexOf(term) !== -1;
                });
                renderFilteredFacilities(sortFacilities(filtered, sortBy), 'Search Results');
            } else {
                isSearching = false;
                if (clearButton) clearButton.style.display = 'none';
                renderFacilitiesForLetter(currentLetter || 'ALL');
            }
        }

        // The template's Clear button calls clearSearch() inline.
        global.clearSearch = function clearSearch() {
            if (searchInput) searchInput.value = '';
            if (sortSelect)  sortSelect.value = '';
            filterAndSort();
        };

        function sortFacilities(facilities, sortBy) {
            var processed = facilities.filter(passesFilters);

            if (newOnlyCheckbox && newOnlyCheckbox.checked) {
                processed = processed.map(function (f) {
                    var recent = reportsOf(f).filter(isRecentReport);
                    return recent.length ? withReports(f, recent) : null;
                }).filter(Boolean);
            }

            if (!sortBy) {
                return typeof adapter.defaultSort === 'function' ? processed.sort(adapter.defaultSort) : processed;
            }

            if (sortBy === 'violations-only' || sortBy === 'violations-desc') {
                if (flaggedFilter === 'facilities') {
                    if (sortBy === 'violations-only') {
                        processed = processed.filter(function (f) { return reportsOf(f).length > 0; });
                    }
                } else {
                    processed = processed.map(function (f) {
                        var flagged = reportsOf(f).filter(adapter.isFlagged);
                        return flagged.length ? withReports(f, flagged) : null;
                    }).filter(Boolean);
                }
            }

            return processed.sort(function (a, b) {
                switch (sortBy) {
                    case 'name':
                    case 'violations-only':
                        return facilityName(a).localeCompare(facilityName(b));
                    case 'violations-desc':
                        return countFlagged(b) - countFlagged(a);
                    case 'recent-inspection':
                        return mostRecentTime(b) - mostRecentTime(a);
                    default:
                        return typeof adapter.defaultSort === 'function' ? adapter.defaultSort(a, b) : 0;
                }
            });
        }

        function renderFacility(f) {
            var reports = reportsOf(f);
            var info = adapter.summary(f, ctx) || {};
            var tone = !reports.length ? 'neutral' : (reports.some(adapter.isFlagged) ? 'flagged' : 'clean');
            var meta = (info.meta || []).map(safeString).filter(Boolean);

            var stats = (info.stats || []).filter(function (s) { return s && safeString(s.text); }).map(function (s) {
                return '<span class="kop-rp-stat' + toneClass(s.tone) + '">' + escapeHtml(s.text) + '</span>';
            }).join('');

            return '<article class="kop-rp-facility' + toneClass(tone) + '">'
                + '<details>'
                + '<summary class="kop-rp-facility-summary">'
                + '<span class="kop-rp-facility-head">'
                + '<h3 class="kop-rp-facility-name">' + (escapeHtml(displayName(adapter.facilityName(f))) || 'Unnamed facility') + '</h3>'
                + (meta.length ? '<span class="kop-rp-facility-meta">' + meta.map(escapeHtml).join(' &middot; ') + '</span>' : '')
                + (info.address ? '<span class="kop-rp-facility-address">' + escapeHtml(info.address) + '</span>' : '')
                + '</span>'
                + '<span class="kop-rp-toggle" aria-hidden="true"></span>'
                + (stats ? '<span class="kop-rp-stats">' + stats + '</span>' : '')
                + '</summary>'
                + '<div class="kop-rp-reports">'
                + (reports.length
                    ? reports.map(renderReport).join('')
                    : '<p class="kop-rp-note">No reports on file.</p>')
                + '</div>'
                + '</details>'
                + '</article>';
        }

        function renderReport(r) {
            var view = adapter.report(r, ctx) || {};
            var badges = (view.badges || []).filter(function (b) { return b && safeString(b.text); }).map(function (b) {
                return '<span class="kop-rp-badge' + toneClass(b.tone) + '">' + escapeHtml(b.text) + '</span>';
            }).join('');

            var facts = (view.facts || []).map(safeString).filter(Boolean).map(escapeHtml);
            [view.link].concat(view.links || []).forEach(function (link, index) {
                if (!link || !safeString(link.href)) return;
                facts.push('<a class="kop-rp-official' + (index ? ' is-secondary' : '') + '" href="' + escapeHtml(link.href) + '" target="_blank" rel="noopener">'
                    + escapeHtml(link.text || 'Official report')
                    + '<span class="kop-rp-sr"> (opens in a new tab)</span></a>');
            });

            var bodyHtml = view.body || '';
            var lazyAttr = '';
            if (typeof view.body === 'function') {
                lazyAttr = ' data-kop-rp-body="' + lazyBodies.length + '"';
                lazyBodies.push(view.body);
                bodyHtml = '';
            }

            return '<details class="kop-rp-report' + toneClass(view.tone) + '"' + lazyAttr + '>'
                + '<summary class="kop-rp-report-summary">'
                + '<span class="kop-rp-report-date">' + (escapeHtml(view.date) || 'Date unknown') + '</span>'
                + '<span class="kop-rp-report-type">' + (escapeHtml(view.type) || 'Report') + '</span>'
                + (badges ? '<span class="kop-rp-badges">' + badges + '</span>' : '')
                + (safeString(view.preview) ? '<span class="kop-rp-report-preview">' + escapeHtml(view.preview) + '</span>' : '')
                + '</summary>'
                + '<div class="kop-rp-report-body">'
                + (facts.length ? '<p class="kop-rp-facts">' + facts.join('<span aria-hidden="true"> &middot; </span>') + '</p>' : '')
                + '<div class="kop-rp-report-content">' + bodyHtml + '</div>'
                + '</div>'
                + '</details>';
        }

        function fillLazyBody(report) {
            if (!report || !report.hasAttribute('data-kop-rp-body')) return;
            var index = parseInt(report.getAttribute('data-kop-rp-body'), 10);
            report.removeAttribute('data-kop-rp-body');
            var build = lazyBodies[index];
            lazyBodies[index] = null;
            var slot = report.querySelector('.kop-rp-report-content');
            if (build && slot) slot.innerHTML = build() || '';
        }

        // Build a lazy body just before a report opens (click on its summary),
        // and on toggle for opens that do not come from a click.
        reportContainer.addEventListener('click', function (e) {
            var summary = e.target.closest && e.target.closest('.kop-rp-report-summary');
            if (summary) fillLazyBody(summary.parentNode);
        });
        reportContainer.addEventListener('toggle', function (e) {
            if (e.target.open && e.target.classList && e.target.classList.contains('kop-rp-report')) fillLazyBody(e.target);
        }, true);

        function renderFilteredFacilities(facilities, context) {
            reportContainer.innerHTML = '';
            lazyBodies = [];
            if (!facilities || !facilities.length) {
                var sortBy = sortSelect ? sortSelect.value : '';
                var violationSort = sortBy === 'violations-only' || sortBy === 'violations-desc';
                var msg = (violationSort && adapter.violationsNote)
                    ? adapter.violationsNote
                    : isSearching
                        ? 'No facilities match your search.'
                        : 'No facilities found for "' + context + '".';
                reportContainer.innerHTML = '<p class="kop-rp-empty">' + escapeHtml(msg) + '</p>';
                return;
            }

            var html = '';
            if (isSearching) {
                html += '<p class="kop-rp-results" role="status">'
                    + escapeHtml(plural(facilities.length, 'facility', 'facilities')) + (facilities.length === 1 ? ' matches' : ' match') + ' your search</p>';
            }
            html += facilities.map(renderFacility).join('');
            reportContainer.innerHTML = html;
        }

        function renderLastUpdated() {
            var el = doc.getElementById('last-updated');
            if (!el) {
                el = doc.createElement('div');
                el.id = 'last-updated';
                el.className = 'last-updated';
                var anchor = doc.querySelector('.facility-report-container') || reportContainer.parentNode;
                anchor.appendChild(el);
            }
            if (!scrapedTimestamp) { el.innerHTML = ''; return; }
            var parsed = new Date(scrapedTimestamp);
            var updateDate = isNaN(parsed.getTime())
                ? scrapedTimestamp
                : parsed.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            el.innerHTML = '<p>Last updated: ' + escapeHtml(updateDate) + '</p>';
        }

        initializeReport();
    }

    KOP.reportPage = {
        mount: mount,
        escapeHtml: escapeHtml,
        safeString: safeString,
        displayName: displayName,
        formatDate: formatDate,
        ui: ui,
        NEW_REPORT_WINDOW_MS: NEW_REPORT_WINDOW_MS
    };
}(window));
