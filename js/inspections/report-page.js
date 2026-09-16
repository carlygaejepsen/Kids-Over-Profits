/**
 * Shared state inspection report page (/xx-reports/).
 *
 * Each of the fifteen state viewers used to carry its own copy of the same
 * machinery: alphabet filter, search, sort, "new reports only", the
 * last-updated stamp and the facility list shell. This file owns all of that.
 * A state now supplies only what genuinely differs between states -- how its
 * data loads and normalizes, which reports count as flagged, and how a facility
 * header and a single report render -- through KOP.reportPage.mount(adapter).
 *
 * Markup is intentionally unchanged from the per-state files so
 * css/facility-reports.css applies as before. Consolidating first means the
 * visual redesign lands in one place instead of fifteen.
 *
 * Classic script (no module loader on these pages); attaches to window.KOP.
 *
 * Adapter contract -- required:
 *   state          Display name, used in messages ("Georgia").
 *   load()         async -> { facilities: [...], scrapedTimestamp: '' }.
 *                  Facilities are already normalized by the adapter.
 *   facilityName(f)       Name used for grouping and name sorts.
 *   reportTime(report)    Report date as epoch ms; 0 when unknown.
 *   isFlagged(report)     True for a report with violations / an incident.
 *   renderSummary(f, ctx) Inner HTML of <summary class="facility-header">.
 *   renderReport(r, ctx)  HTML for one report.
 *
 * Adapter contract -- optional:
 *   reportsKey       Property holding a facility's reports (default 'reports').
 *   searchText(f)    Haystack for the search box (default: facilityName).
 *   renderBody(f, ctx)  HTML between the summary and the report list.
 *   countFlagged(f)  Sort weight for "Most Violations First"
 *                    (default: number of flagged reports).
 *   emptyMessage     Shown when load() returns no facilities.
 *   flaggedFilter    'reports' (default): violation sorts keep only flagged
 *                    reports and drop facilities left with none.
 *                    'facilities': keep a facility's full report list and drop
 *                    facilities with no reports at all (Texas behaviour).
 *   defaultSort(a, b)  Comparator for the "Default Order" option; the default
 *                    leaves the letter grouping's A-Z order untouched.
 *
 * ctx passed to render hooks: { escapeHtml, countFlagged, reports }.
 */
(function (global) {
    'use strict';

    var KOP = global.KOP = global.KOP || {};

    var NEW_REPORT_WINDOW_MS = 30 * 24 * 60 * 60 * 1000;

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

        var ctx = { escapeHtml: escapeHtml, countFlagged: countFlagged, reports: reportsOf };

        function isRecentReport(report) {
            if (!report) return false;
            var t = adapter.reportTime(report);
            return t > 0 && (Date.now() - t) <= NEW_REPORT_WINDOW_MS;
        }

        function mostRecentTime(f) {
            var times = reportsOf(f).map(adapter.reportTime).filter(function (t) { return t > 0; });
            return times.length ? Math.max.apply(null, times) : 0;
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
                        reportContainer.innerHTML = '<p>' + escapeHtml(adapter.emptyMessage
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
                    reportContainer.innerHTML = '<p class="error">Error loading data: ' + error.message + '</p>';
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
                a.classList.toggle('active', a.dataset.letter === letter);
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
                doc.querySelectorAll('#alphabet-filter a').forEach(function (a) { a.classList.remove('active'); });
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
            var processed = facilities.slice();

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

        function renderFilteredFacilities(facilities, context) {
            reportContainer.innerHTML = '';
            if (!facilities || !facilities.length) {
                var msg = isSearching
                    ? 'No facilities found matching your search.'
                    : 'No facilities found for "' + context + '".';
                reportContainer.innerHTML = '<p>' + msg + '</p>';
                return;
            }

            if (isSearching) {
                var header = doc.createElement('div');
                header.style.cssText = 'margin-bottom:20px;padding:10px;background:#e8f4f8;border-radius:4px;font-weight:bold;';
                header.innerHTML = 'Found ' + facilities.length + ' facilities matching your search';
                reportContainer.appendChild(header);
            }

            facilities.forEach(function (f) {
                var el = doc.createElement('div');
                el.className = 'facility-box';
                el.innerHTML = '<details>'
                    + '<summary class="facility-header">' + adapter.renderSummary(f, ctx) + '</summary>'
                    + (typeof adapter.renderBody === 'function' ? adapter.renderBody(f, ctx) : '')
                    + '<div class="inspections-container">'
                    + reportsOf(f).map(function (r) { return adapter.renderReport(r, ctx); }).join('')
                    + '</div>'
                    + '</details>';
                reportContainer.appendChild(el);
            });
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
            el.innerHTML = '<p>Last updated: ' + updateDate + '</p>';
        }

        initializeReport();
    }

    KOP.reportPage = {
        mount: mount,
        escapeHtml: escapeHtml,
        safeString: safeString,
        NEW_REPORT_WINDOW_MS: NEW_REPORT_WINDOW_MS
    };
}(window));
