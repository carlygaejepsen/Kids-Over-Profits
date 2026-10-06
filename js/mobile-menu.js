/**
 * Kadence mobile menu (#mobile-drawer): a menu item that goes nowhere must
 * not close the menu.
 *
 * The top-level items that only group others (Monitor, Learn More, Get
 * Involved, ...) are custom links to "#". Kadence closes the drawer on any
 * link tap in it, so tapping one of those labels emptied the screen instead
 * of opening its list; only the small arrow beside it worked. Here a tap on
 * such a label opens or closes its list (through Kadence's own arrow button,
 * so its state and aria-expanded stay right), and a "#" link with no list
 * does nothing. Real links, and anchors on the page, behave as before.
 *
 * Capture phase on the document, so it runs before Kadence's handlers on the
 * link and can stop them.
 */
(function () {
    'use strict';

    /** True when the link only points back at this same spot: "#", "", or this page + "#". */
    function goesNowhere(link) {
        var raw = (link.getAttribute('href') || '').trim();
        if (raw === '' || raw === '#' || /^javascript:/i.test(raw)) {
            return true;
        }
        if (raw.charAt(raw.length - 1) !== '#') {
            return false;
        }
        try {
            var url = new URL(raw, window.location.href);
            return url.origin === window.location.origin
                && url.pathname.replace(/\/+$/, '') === window.location.pathname.replace(/\/+$/, '')
                && url.search === window.location.search;
        } catch (e) {
            return false;
        }
    }

    document.addEventListener('click', function (event) {
        if (event.button !== 0 || event.defaultPrevented) {
            return;
        }
        var drawer = document.getElementById('mobile-drawer');
        var link = event.target && event.target.closest ? event.target.closest('a') : null;
        if (!drawer || !link || !drawer.contains(link) || !goesNowhere(link)) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        var item = link.closest('li');
        var toggle = null;
        if (item && item.classList.contains('menu-item-has-children')) {
            // Kadence: li > .drawer-nav-drop-wrap > (a + button.drawer-sub-toggle)
            var wrap = link.closest('.drawer-nav-drop-wrap');
            toggle = (wrap && wrap.querySelector('.drawer-sub-toggle')) || item.querySelector('.drawer-sub-toggle');
        }
        if (toggle) {
            toggle.click();
        }
    }, true);
})();
