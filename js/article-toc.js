/**
 * "Contents" on a long article, once the contents box itself has scrolled away.
 *
 * These articles run to forty thousand characters, and the only way back to
 * the list of sections was a scroll to the top. This puts a small button in
 * the corner of the window that opens the same list wherever the reader has
 * got to.
 *
 * It is fixed rather than sticky, and that is the whole design. A sticky
 * contents box was tried first: it works, but the box is part of the page, so
 * every time it collapsed or opened it changed the height of the article and
 * shoved the text the reader was looking at up or down the screen - measured
 * at 206 pixels on Spiritual Abuse. Nothing here is in the flow of the page,
 * so nothing it does can move the words.
 *
 * The list is a copy of the one the template printed, so it cannot disagree
 * with it, and the button only appears once that original is off screen.
 * Without JavaScript the page is exactly as it was.
 */
(function () {
    'use strict';

    /* Below this the window is too small to give a corner away, and the
     * contents box is a short scroll from anywhere. Matches the breakpoint in
     * css/article.css. */
    var WIDE = '(min-width: 1024px)';

    function build(toc) {
        var doc = document;

        var panel = doc.createElement('nav');
        panel.className = 'kop-article-jump';
        panel.setAttribute('aria-label', 'Contents');
        panel.hidden = true;

        var button = doc.createElement('button');
        button.type = 'button';
        button.className = 'kop-article-jump__button';
        button.setAttribute('aria-expanded', 'false');
        button.textContent = 'Contents';

        var list = doc.createElement('div');
        list.className = 'kop-article-jump__list';
        list.hidden = true;

        /* A copy of the template's own list: same ids, same order, same
         * wording, and nothing to keep in step by hand. */
        var source = toc.querySelector('ol');
        if (!source) {
            return null;
        }
        list.appendChild(source.cloneNode(true));

        panel.appendChild(button);
        panel.appendChild(list);
        doc.body.appendChild(panel);

        function close() {
            list.hidden = true;
            button.setAttribute('aria-expanded', 'false');
        }

        function open() {
            list.hidden = false;
            button.setAttribute('aria-expanded', 'true');
        }

        button.addEventListener('click', function () {
            if (list.hidden) {
                open();
                var first = list.querySelector('a');
                if (first) {
                    first.focus();
                }
            } else {
                close();
            }
        });

        /* Jumping to a section is the end of the list's job. */
        list.addEventListener('click', function (event) {
            if (event.target.closest && event.target.closest('a')) {
                close();
            }
        });

        panel.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' || event.key === 'Esc') {
                close();
                button.focus();
            }
        });

        /* A click anywhere else means the reader has moved on. */
        doc.addEventListener('click', function (event) {
            if (!list.hidden && !panel.contains(event.target)) {
                close();
            }
        });

        return { panel: panel, close: close };
    }

    function start() {
        var toc = document.querySelector('.kop-article-toc');
        if (!toc || !('IntersectionObserver' in window) || !window.matchMedia) {
            return;
        }
        var wide = window.matchMedia(WIDE);
        var built = build(toc);
        if (!built) {
            return;
        }

        var offScreen = false;

        function sync() {
            var show = offScreen && wide.matches;
            built.panel.hidden = !show;
            if (!show) {
                built.close();
            }
        }

        new IntersectionObserver(function (entries) {
            var entry = entries[0];
            if (!entry) {
                return;
            }
            offScreen = !entry.isIntersecting;
            sync();
        }, { threshold: 0 }).observe(toc);

        if (wide.addEventListener) {
            wide.addEventListener('change', sync);
        } else if (wide.addListener) {
            wide.addListener(sync);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
