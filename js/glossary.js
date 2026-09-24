/**
 * TTI glossary: instant filtering.
 *
 * The page works without this file: every entry is rendered on the server and
 * the search box and program picker are a plain GET form. This makes the same
 * two filters instant, with the same rules as inc/glossary.php (every word of
 * the search must appear; the program must be one of the entry's tags), and
 * keeps ?q= and ?program= in the address bar so a filtered view can be shared.
 *
 * It also keeps the contents rail and the sticky filter bar out of each
 * other's way, and makes a cross-reference to an entry the filter has hidden
 * clear the filter first, so a link never lands on nothing.
 */
(function () {
    'use strict';

    var root = document.querySelector('.kop-gl');
    var form = root && root.querySelector('.kop-gl-filter');
    if (!root || !form) {
        return;
    }

    var input = form.querySelector('#kop-gl-q');
    var select = form.querySelector('#kop-gl-program');
    var status = form.querySelector('.kop-gl-status');
    var clear = form.querySelector('.kop-gl-clear');
    var go = form.querySelector('.kop-gl-go');
    var none = root.querySelector('.kop-gl-none');
    var entries = Array.prototype.slice.call(root.querySelectorAll('.kop-gl-entry'));
    var groups = Array.prototype.slice.call(root.querySelectorAll('.kop-gl-group'));
    var sections = Array.prototype.slice.call(root.querySelectorAll('.kop-gl-section'));
    var notes = Array.prototype.slice.call(root.querySelectorAll('.kop-gl-note, .kop-gl-letters'));
    var page = document.querySelector('.kop-gl-page') || document.documentElement;

    /* The button is only needed when this script has not run. */
    if (go) {
        go.hidden = true;
    }

    /* ---- Sticky offsets ------------------------------------------------ */

    /* A sticky site header (Kadence can make one) would cover the filter
     * bar; measure it and push the bar below it. */
    function measure() {
        var header = document.querySelector('#masthead, .site-header');
        var top = 0;
        if (header) {
            var style = window.getComputedStyle(header);
            if (style.position === 'fixed' || style.position === 'sticky') {
                top = header.getBoundingClientRect().height;
            }
        }
        page.style.setProperty('--kop-gl-sticky-top', top + 'px');
        page.style.setProperty('--kop-gl-filter-height', form.getBoundingClientRect().height + 'px');
    }
    measure();
    window.addEventListener('resize', measure);

    /* Contents open beside the list on a wide screen, folded on a phone. */
    var toc = root.querySelector('.kop-gl-toc details');
    if (toc && window.matchMedia) {
        toc.open = window.matchMedia('(min-width: 64rem)').matches;
    }

    /* ---- Filtering ----------------------------------------------------- */

    function programName(slug) {
        var option = select && select.querySelector('option[value="' + slug + '"]');
        return option ? option.textContent.replace(/\s*\(\d+\)\s*$/, '').trim() : '';
    }

    function statusText(shown, program, query) {
        var text = shown === 1 ? '1 term' : shown.toLocaleString() + ' terms';
        if (program) {
            text += ' tagged ' + programName(program);
        }
        if (query) {
            text += ' matching “' + query + '”';
        }
        return text;
    }

    function apply(push) {
        var query = input ? input.value.replace(/\s+/g, ' ').trim() : '';
        var words = query ? query.toLowerCase().split(' ') : [];
        var program = select ? select.value : '';
        var filtered = !!(query || program);
        var shown = 0;

        entries.forEach(function (entry) {
            var ok = true;
            if (program) {
                ok = (' ' + entry.getAttribute('data-programs') + ' ').indexOf(' ' + program + ' ') !== -1;
            }
            if (ok && words.length) {
                var hay = entry.getAttribute('data-search') || '';
                for (var i = 0; i < words.length; i++) {
                    if (hay.indexOf(words[i]) === -1) {
                        ok = false;
                        break;
                    }
                }
            }
            entry.hidden = !ok;
            if (ok) {
                shown++;
            }
        });

        /* Innermost first, so a family shows when any of its programs does. */
        groups.slice().reverse().concat(sections).forEach(function (block) {
            block.hidden = !block.querySelector('.kop-gl-entry:not([hidden])');
        });
        notes.forEach(function (note) {
            note.hidden = filtered;
        });

        root.querySelectorAll('.kop-gl-tag').forEach(function (tag) {
            tag.classList.toggle('is-active', !!program && tag.getAttribute('data-program') === program);
        });

        if (status) {
            status.textContent = filtered ? statusText(shown, program, query) : '';
        }
        if (clear) {
            clear.hidden = !filtered;
        }
        if (none) {
            none.hidden = !filtered || shown > 0;
        }
        measure();

        if (push !== false && window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.hash = '';
            url.searchParams.delete('q');
            url.searchParams.delete('program');
            if (query) {
                url.searchParams.set('q', query);
            }
            if (program) {
                url.searchParams.set('program', program);
            }
            window.history.replaceState(null, '', url.toString());
        }
    }

    function reset() {
        if (input) {
            input.value = '';
        }
        if (select) {
            select.value = '';
        }
        apply();
    }

    var timer = null;
    if (input) {
        input.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(apply, 150);
        });
    }
    if (select) {
        select.addEventListener('change', function () {
            apply();
        });
    }
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        apply();
    });
    if (clear) {
        clear.addEventListener('click', function (event) {
            event.preventDefault();
            reset();
            if (input) {
                input.focus();
            }
        });
    }

    /* A program tag filters in place instead of reloading. */
    root.addEventListener('click', function (event) {
        var tag = event.target.closest('.kop-gl-tag');
        if (tag && select) {
            event.preventDefault();
            select.value = tag.getAttribute('data-program') || '';
            if (input) {
                input.value = '';
            }
            apply();
            form.scrollIntoView({ block: 'start' });
            return;
        }

        /* A link to an entry the filter hides: clear the filter, then go. */
        var link = event.target.closest('a[href*="#"]');
        if (!link || link.closest('.kop-gl-filter')) {
            return;
        }
        var url = new URL(link.href, window.location.href);
        if (url.pathname !== window.location.pathname) {
            return;
        }
        var id = decodeURIComponent(url.hash.slice(1));
        var target = id && document.getElementById(id);
        if (!target || !root.contains(target)) {
            return;
        }
        event.preventDefault();
        if (target.hidden || target.closest('[hidden]')) {
            reset();
        }
        flash(target);
        target.scrollIntoView({ block: 'start' });
        if (window.history && window.history.pushState) {
            var here = new URL(window.location.href);
            here.hash = id;
            window.history.pushState(null, '', here.toString());
        }
    });

    /* :target only follows real navigation; mark the entry ourselves. */
    var flashed = null;
    function flash(el) {
        if (flashed) {
            flashed.classList.remove('is-target');
        }
        if (el.classList.contains('kop-gl-entry')) {
            el.classList.add('is-target');
            flashed = el;
        }
    }

    /* ---- Contents rail: mark the section being read -------------------- */

    var tocLinks = root.querySelectorAll('.kop-gl-toc a');
    if ('IntersectionObserver' in window && tocLinks.length) {
        var byId = {};
        tocLinks.forEach(function (a) {
            byId[a.getAttribute('href').slice(1)] = a;
        });
        var observer = new IntersectionObserver(function (items) {
            items.forEach(function (item) {
                if (!item.isIntersecting) {
                    return;
                }
                var link = byId[item.target.id];
                if (!link) {
                    return;
                }
                tocLinks.forEach(function (a) {
                    a.removeAttribute('aria-current');
                });
                link.setAttribute('aria-current', 'true');
            });
        }, { rootMargin: '-20% 0px -70% 0px' });
        sections.concat(groups).forEach(function (block) {
            if (byId[block.id]) {
                observer.observe(block);
            }
        });
    }

    /* Arriving on /glossary/#term with a filter that hides it. */
    if (window.location.hash) {
        var arrived = document.getElementById(decodeURIComponent(window.location.hash.slice(1)));
        if (arrived && root.contains(arrived) && (arrived.hidden || arrived.closest('[hidden]'))) {
            reset();
            arrived.scrollIntoView({ block: 'start' });
        }
    }

    apply(false);
})();
