/**
 * Reporting directory: state picker enhancement.
 *
 * The picker is a plain GET form and the page works entirely without this
 * file. All this does is save the reload: it fetches the same URL the form
 * would have gone to, lifts the rendered state section out of the response
 * and swaps it in. Fetching the server's own HTML rather than rendering from
 * JSON keeps one renderer, in inc/reporting-directory.php, so the page cannot
 * drift into saying two different things about the same agency.
 *
 * Anything unexpected - a failed fetch, a response without the section - falls
 * back to submitting the form for real. Somebody is here looking for a phone
 * number; they should never end up on a page that silently did nothing.
 */
(function () {
    'use strict';

    var root = document.querySelector('.kop-rep');
    var form = root && root.querySelector('.kop-rep-picker');
    var select = form && form.querySelector('#kop-rep-state');
    if (!root || !form || !select) {
        return;
    }

    var button = form.querySelector('.kop-rep-go');
    var busy = false;

    /* The button is only needed when this script has not run. */
    if (button) {
        button.hidden = true;
    }

    function sectionFor(doc) {
        return doc.querySelector('.kop-rep-state') || doc.querySelector('.kop-rep-missing');
    }

    function urlFor(slug) {
        var url = new URL(form.action, window.location.href);
        if (slug) {
            url.searchParams.set('state', slug);
        } else {
            url.searchParams.delete('state');
        }
        return url;
    }

    /** Replace whatever state block is on the page with a new one, or remove it. */
    function swap(next) {
        var current = sectionFor(document);
        if (next && current) {
            current.replaceWith(next);
        } else if (next) {
            var national = root.querySelector('.kop-rep-national');
            if (national) {
                national.parentNode.insertBefore(next, national);
            } else {
                return false;
            }
        } else if (current) {
            current.remove();
        }
        return true;
    }

    function focusResult() {
        var heading = root.querySelector('.kop-rep-state .kop-rep-state-h, .kop-rep-missing');
        if (!heading) {
            return;
        }
        /* Move focus so a screen reader announces the new section, without
         * leaving a permanent tab stop behind. */
        heading.setAttribute('tabindex', '-1');
        heading.focus({ preventScroll: true });
        heading.addEventListener('blur', function handler() {
            heading.removeAttribute('tabindex');
            heading.removeEventListener('blur', handler);
        });
        heading.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function load(slug, push) {
        if (busy) {
            return;
        }
        busy = true;
        root.setAttribute('aria-busy', 'true');

        var url = urlFor(slug);
        fetch(url.toString(), { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var next = sectionFor(doc);
                /* No state chosen is a legitimate result with no section. */
                if (!next && slug) {
                    throw new Error('no state section in response');
                }
                if (!swap(next)) {
                    throw new Error('nowhere to put the state section');
                }
                root.setAttribute('data-selected', slug);
                if (push) {
                    window.history.pushState({ kopReportState: slug }, '', url.toString());
                }
                focusResult();
            })
            .catch(function () {
                /* Let the browser do it the ordinary way. */
                form.submit();
            })
            .finally(function () {
                busy = false;
                root.removeAttribute('aria-busy');
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        load(select.value, true);
    });

    select.addEventListener('change', function () {
        load(select.value, true);
    });

    window.addEventListener('popstate', function (event) {
        var slug = (event.state && typeof event.state.kopReportState === 'string')
            ? event.state.kopReportState
            : new URL(window.location.href).searchParams.get('state') || '';
        select.value = slug;
        load(slug, false);
    });
}());
