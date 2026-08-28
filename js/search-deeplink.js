/**
 * Search deep-link support.
 *
 * Pre-fills #searchInput from a ?search= URL parameter and nudges the page's
 * existing filter listeners (the index/report pages all listen for input or
 * keyup on #searchInput). Those pages render their lists client-side after an
 * async fetch, so the filter is re-announced for a few seconds until the data
 * has arrived — stopping early if the user edits the box themselves.
 *
 * Loaded site-wide; does nothing unless both the parameter and the input exist.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var term = new URLSearchParams(window.location.search).get('search');
        if (!term) return;

        var input = document.getElementById('searchInput');
        if (!input) return;

        input.value = term;
        var clearBtn = document.getElementById('clearSearch');
        if (clearBtn) clearBtn.style.display = 'inline-block';

        var announce = function () {
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('keyup', { bubbles: true }));
        };
        announce();

        var attempts = 0;
        var timer = setInterval(function () {
            attempts += 1;
            if (input.value !== term || attempts >= 10) {
                clearInterval(timer);
                return;
            }
            announce();
        }, 800);
    });
})();
