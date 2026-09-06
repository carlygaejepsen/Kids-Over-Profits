/**
 * KOP Global Search
 *
 * Site-wide search bar: a floating trigger button (plus "/" or Ctrl+K) opens
 * an overlay that queries every KOP database at once through the
 * kop/v1/global-search REST endpoint and renders grouped results.
 *
 * Config comes from wp_localize_script as KOP_GLOBAL_SEARCH:
 *   { endpoint, nonce, allResultsBase, minChars }
 *
 * No dependencies. Mirrors the bug reporter widget's build/mount pattern.
 */
(function () {
    'use strict';

    var settings = window.KOP_GLOBAL_SEARCH || {};
    var ENDPOINT = settings.endpoint || '';
    var NONCE = settings.nonce || '';
    var MIN_CHARS = parseInt(settings.minChars, 10) || 2;
    var DEBOUNCE_MS = 250;

    if (!ENDPOINT || !window.fetch) {
        return;
    }

    var overlay = null;
    var input = null;
    var resultsEl = null;
    var statusEl = null;
    var footerLink = null;
    var trigger = null;
    var debounceTimer = null;
    var abortController = null;
    var activeIndex = -1;
    var lastFocused = null;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    function magnifierSvg(size) {
        var svgNs = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(svgNs, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('width', String(size));
        svg.setAttribute('height', String(size));
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2.2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('aria-hidden', 'true');
        var circle = document.createElementNS(svgNs, 'circle');
        circle.setAttribute('cx', '10.5');
        circle.setAttribute('cy', '10.5');
        circle.setAttribute('r', '6.5');
        var line = document.createElementNS(svgNs, 'line');
        line.setAttribute('x1', '15.3');
        line.setAttribute('y1', '15.3');
        line.setAttribute('x2', '21');
        line.setAttribute('y2', '21');
        svg.appendChild(circle);
        svg.appendChild(line);
        return svg;
    }

    // ------------------------------------------------------------------ UI

    function buildTrigger() {
        trigger = el('button', 'kop-global-search__trigger');
        trigger.type = 'button';
        trigger.setAttribute('aria-label', 'Search the site and databases');
        trigger.appendChild(magnifierSvg(16));
        trigger.appendChild(el('span', 'kop-global-search__trigger-text', 'Search'));
        trigger.addEventListener('click', openOverlay);
        document.body.appendChild(trigger);
    }

    function buildOverlay() {
        overlay = el('div', 'kop-global-search__overlay');
        overlay.hidden = true;
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Site search');

        var panel = el('div', 'kop-global-search__panel');

        var inputWrap = el('div', 'kop-global-search__input-wrap');
        var icon = el('span', 'kop-global-search__input-icon');
        icon.appendChild(magnifierSvg(18));
        input = el('input', 'kop-global-search__input');
        input.type = 'search';
        input.placeholder = 'Search facilities, referrers, news, documents, reports...';
        input.setAttribute('aria-label', 'Search all databases');
        input.autocomplete = 'off';
        var closeBtn = el('button', 'kop-global-search__close');
        closeBtn.type = 'button';
        closeBtn.setAttribute('aria-label', 'Close search');
        closeBtn.textContent = '×';
        closeBtn.addEventListener('click', closeOverlay);
        inputWrap.appendChild(icon);
        inputWrap.appendChild(input);
        inputWrap.appendChild(closeBtn);

        statusEl = el('p', 'kop-global-search__status', 'Type at least ' + MIN_CHARS + ' characters to search everything at once.');
        resultsEl = el('div', 'kop-global-search__results');
        resultsEl.setAttribute('role', 'listbox');

        var footer = el('div', 'kop-global-search__footer');
        footerLink = el('a', 'kop-global-search__all-link', 'View all results');
        footerLink.hidden = true;
        var hints = el('span', 'kop-global-search__hints', 'Enter to open · Arrow keys to move · Esc to close');
        footer.appendChild(footerLink);
        footer.appendChild(hints);

        panel.appendChild(inputWrap);
        panel.appendChild(statusEl);
        panel.appendChild(resultsEl);
        panel.appendChild(footer);
        overlay.appendChild(panel);

        overlay.addEventListener('mousedown', function (e) {
            if (e.target === overlay) closeOverlay();
        });
        input.addEventListener('input', onInput);
        input.addEventListener('keydown', onInputKeydown);

        document.body.appendChild(overlay);
    }

    function openOverlay() {
        if (!overlay) buildOverlay();
        lastFocused = document.activeElement;
        overlay.hidden = false;
        document.body.classList.add('kop-global-search--open');
        input.focus();
        input.select();
    }

    function closeOverlay() {
        if (!overlay || overlay.hidden) return;
        overlay.hidden = true;
        document.body.classList.remove('kop-global-search--open');
        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    }

    // ------------------------------------------------------------ searching

    function onInput() {
        var q = input.value.trim();
        clearTimeout(debounceTimer);
        if (q.length < MIN_CHARS) {
            renderIdle();
            return;
        }
        debounceTimer = setTimeout(function () { runSearch(q); }, DEBOUNCE_MS);
    }

    function runSearch(q, skipNonce) {
        if (abortController) abortController.abort();
        abortController = ('AbortController' in window) ? new AbortController() : null;

        statusEl.textContent = 'Searching…';
        statusEl.hidden = false;

        var url = ENDPOINT + (ENDPOINT.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q);
        var opts = { headers: {} };
        if (NONCE && !skipNonce) {
            opts.headers['X-WP-Nonce'] = NONCE;
            opts.credentials = 'same-origin';
        }
        if (abortController) opts.signal = abortController.signal;

        fetch(url, opts)
            .then(function (res) {
                // A page cache can serve a stale nonce; WP then 403s even on
                // public routes. Retry once anonymously — everything except
                // the admin-only group still works.
                if (res.status === 403 && NONCE && !skipNonce) {
                    runSearch(q, true);
                    return null;
                }
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json();
            })
            .then(function (data) {
                if (!data) return; // retried without nonce
                if (input.value.trim() !== q) return; // stale response
                renderResults(data);
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                statusEl.textContent = 'Search is unavailable right now. Please try again in a moment.';
                statusEl.hidden = false;
                resultsEl.textContent = '';
            });
    }

    function renderIdle() {
        resultsEl.textContent = '';
        activeIndex = -1;
        footerLink.hidden = true;
        statusEl.textContent = 'Type at least ' + MIN_CHARS + ' characters to search everything at once.';
        statusEl.hidden = false;
    }

    function renderResults(data) {
        resultsEl.textContent = '';
        activeIndex = -1;

        var groups = (data && data.groups) || [];
        if (data && data.allResultsUrl) {
            footerLink.href = data.allResultsUrl;
            footerLink.textContent = 'View all results for “' + data.query + '”';
            footerLink.hidden = false;
        }

        if (!groups.length) {
            statusEl.textContent = 'No matches for “' + ((data && data.query) || '') + '” in any database.';
            statusEl.hidden = false;
            return;
        }

        statusEl.hidden = true;

        groups.forEach(function (group) {
            var section = el('div', 'kop-global-search__group');
            section.appendChild(el('h3', 'kop-global-search__group-label', group.label));
            var list = el('div', 'kop-global-search__group-items');
            (group.items || []).forEach(function (item) {
                var link = el('a', 'kop-global-search__result');
                link.href = item.url;
                link.setAttribute('role', 'option');
                if (/^https?:/i.test(item.url) && item.url.indexOf(window.location.origin) !== 0) {
                    link.target = '_blank';
                    link.rel = 'noopener';
                }
                link.appendChild(el('span', 'kop-global-search__result-title', item.title));
                if (item.meta) {
                    link.appendChild(el('span', 'kop-global-search__result-meta', item.meta));
                }
                list.appendChild(link);
            });
            section.appendChild(list);
            resultsEl.appendChild(section);
        });
    }

    // ----------------------------------------------------------- keyboard

    function resultLinks() {
        return resultsEl ? resultsEl.querySelectorAll('.kop-global-search__result') : [];
    }

    function setActive(index) {
        var links = resultLinks();
        if (!links.length) return;
        if (index < 0) index = links.length - 1;
        if (index >= links.length) index = 0;
        if (activeIndex >= 0 && links[activeIndex]) {
            links[activeIndex].classList.remove('kop-global-search__result--active');
        }
        activeIndex = index;
        links[activeIndex].classList.add('kop-global-search__result--active');
        links[activeIndex].scrollIntoView({ block: 'nearest' });
    }

    function onInputKeydown(e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive(activeIndex + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(activeIndex - 1);
        } else if (e.key === 'Enter') {
            var links = resultLinks();
            if (activeIndex >= 0 && links[activeIndex]) {
                e.preventDefault();
                links[activeIndex].click();
            } else if (footerLink && !footerLink.hidden) {
                e.preventDefault();
                window.location.href = footerLink.href;
            }
        } else if (e.key === 'Escape') {
            closeOverlay();
        }
    }

    function isTypingTarget(target) {
        if (!target) return false;
        var tag = (target.tagName || '').toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable;
    }

    function onGlobalKeydown(e) {
        var isOpen = overlay && !overlay.hidden;
        if (isOpen && e.key === 'Escape') {
            closeOverlay();
            return;
        }
        if (isOpen) return;
        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            openOverlay();
        } else if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !isTypingTarget(e.target)) {
            e.preventDefault();
            openOverlay();
        }
    }

    // --------------------------------------------------------------- init

    function init() {
        buildTrigger();
        document.addEventListener('keydown', onGlobalKeydown);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
