/**
 * Kadence Navigation Guard - resilient headerless protection.
 *
 * Rather than guessing based on URLs or body classes, the guard watches the
 * DOM itself. If Kadence scripts load before a header exists (or on layouts
 * that never render one) we inject a hidden navigation scaffold and repeatedly
 * neutralise Kadence's initialisers so `getAttribute` is never invoked on
 * missing nodes.
 */
(function() {
    'use strict';

    var STUB_ATTRIBUTE = 'data-kadence-nav-guard-stub';
    var STUB_LINK_TARGET = 'kadence-nav-guard-stub';
    var MAX_STUB_ATTEMPTS = 120; // ~6 seconds of retries.
    var MAX_NEUTRALISE_CHECKS = 200; // ~20 seconds of follow-up protection.
    // Flag to track if guard is active
    window.KADENCE_NAV_GUARD_ACTIVE = true;

    // Check if this is a headerless layout (tti-data-submission page only)
    // Use a function to avoid accessing document.body before it exists
    function isHeaderlessPage() {
        if (window.KADENCE_NAV_DISABLED) {
            return true;
        }

        var pathname = (window.location && window.location.pathname) || '';

        if (pathname) {
            var normalizedPath = pathname.replace(/\/index\.php$/, '').replace(/\/$/, '');

            // Only activate on tti-data-submission page
            if (normalizedPath === '/tti-data-submission' ||
                normalizedPath.indexOf('/tti-data-submission') !== -1) {
                return true;
            }
        }

        if (document.body) {
            // Check for tti-data-submission page body class
            if (document.body.classList.contains('page-tti-data-submission')) {
                return true;
            }

            var bodyClasses = document.body.className || '';
            if (bodyClasses.indexOf('page-slug-tti-data-submission') !== -1 ||
                bodyClasses.indexOf('tti-data-submission') !== -1) {
                return true;
            }
        }

        return false;
    }

    var ensureAttempts = 0;
    var ensureTimer = null;
    var bodyObserver = null;
    var neutraliseInterval = null;
    var neutraliseChecks = 0;

    function hasRealKadenceHeader() {
        return Boolean(document.querySelector('header#masthead, .site-header, [data-kadence-header]'));
    }

    function logDebug(message) {
        if (window.console && typeof window.console.debug === 'function') {
            window.console.debug('[Kadence Nav Guard] ' + message);
        }
    }

    function logInfo(message) {
        if (window.console && typeof window.console.info === 'function') {
            window.console.info('[Kadence Nav Guard] ' + message);
        }
    }

    function stopEnsureTimer() {
        if (ensureTimer) {
            window.clearTimeout(ensureTimer);
            ensureTimer = null;
        }
    }

    function stopBodyObserver() {
        if (bodyObserver) {
            bodyObserver.disconnect();
            bodyObserver = null;
        }
    }

    function stopNeutraliseLoop() {
        if (neutraliseInterval) {
            window.clearInterval(neutraliseInterval);
            neutraliseInterval = null;
            neutraliseChecks = 0;
        }
    }

    function createStubNavigation() {
        if (!document.body) {
            return false;
        }

        if (document.body.querySelector('[' + STUB_ATTRIBUTE + ']')) {
            return true;
        }

        if (hasRealKadenceHeader()) {
            return false;
        }

        var header = document.createElement('header');
        header.id = 'masthead';
        header.className = 'site-header kadence-nav-guard-stub';
        header.setAttribute(STUB_ATTRIBUTE, 'true');
        header.setAttribute('aria-hidden', 'true');
        header.setAttribute('hidden', '');

        header.style.position = 'absolute';
        header.style.width = '1px';
        header.style.height = '1px';
        header.style.padding = '0';
        header.style.margin = '-1px';
        header.style.overflow = 'hidden';
        header.style.clip = 'rect(0 0 0 0)';
        header.style.whiteSpace = 'nowrap';
        header.style.border = '0';

        var nav = document.createElement('nav');
        nav.id = 'site-navigation';
        nav.className = 'main-navigation primary-navigation kadence-nav-guard-stub';
        nav.setAttribute(STUB_ATTRIBUTE, 'true');
        nav.setAttribute('aria-hidden', 'true');
        nav.setAttribute('data-open-delay', '0');
        nav.setAttribute('data-close-delay', '0');
        nav.setAttribute('data-dropdown-target', 'hover');
        nav.setAttribute('data-nav-style', 'dropdown');
        nav.setAttribute('data-breakpoint', '9999');

        var menuList = document.createElement('ul');
        menuList.className = 'menu kadence-nav-guard-stub';
        menuList.setAttribute(STUB_ATTRIBUTE, 'true');

        var menuItem = document.createElement('li');
        menuItem.className = 'menu-item kadence-nav-guard-stub';
        menuItem.setAttribute(STUB_ATTRIBUTE, 'true');

        var menuLink = document.createElement('a');
        menuLink.className = 'menu-link kadence-nav-guard-stub';
        menuLink.setAttribute(STUB_ATTRIBUTE, 'true');
        menuLink.setAttribute('href', '#' + STUB_LINK_TARGET);
        menuLink.setAttribute('tabindex', '-1');
        menuLink.textContent = 'Placeholder';

        menuItem.appendChild(menuLink);
        menuList.appendChild(menuItem);
        nav.appendChild(menuList);
        header.appendChild(nav);

        document.body.insertBefore(header, document.body.firstChild);
        logInfo('Injected hidden navigation stub.');
        return true;
    }

    function applyNeutralisers() {
        if (hasRealKadenceHeader()) {
            stopNeutraliseLoop();
            return;
        }

        window.KADENCE_NAV_DISABLED = true;

        var noop = function() {};
        window.kadence = window.kadence || {};
        window.kadence.initNavigation = noop;

        if (!window.kadence.navigation || typeof window.kadence.navigation !== 'object') {
            window.kadence.navigation = {};
        }

        ['init', 'toggleSubArrow', 'initOutline', 'handleToggle', 'setupEvents'].forEach(function(method) {
            window.kadence.navigation[method] = noop;
        });

        if (window.KadenceNavigation && typeof window.KadenceNavigation === 'object') {
            ['init', 'toggleSubArrow', 'initOutline', 'handleToggle', 'setupEvents'].forEach(function(method) {
                window.KadenceNavigation[method] = noop;
            });
        }
    }

    function startNeutraliseLoop() {
        if (neutraliseInterval) {
            return;
        }

        applyNeutralisers();
        neutraliseInterval = window.setInterval(function() {
            applyNeutralisers();
            neutraliseChecks++;
            if (neutraliseChecks >= MAX_NEUTRALISE_CHECKS) {
                logDebug('Stopped Kadence neutraliser loop after maximum checks.');
                stopNeutraliseLoop();
            }
        }, 100);
    }

    function observeForBody() {
        if (document.body || bodyObserver) {
            return;
        }

        bodyObserver = new MutationObserver(function() {
            if (document.body) {
                ensureNavigationShell();
            }
        });

        bodyObserver.observe(document.documentElement || document, { childList: true });
    }

    function ensureNavigationShell() {
        // Only activate on tti-data-submission page
        if (!isHeaderlessPage()) {
            logDebug('Not on tti-data-submission page, guard inactive.');
            stopEnsureTimer();
            stopBodyObserver();
            stopNeutraliseLoop();
            window.KADENCE_NAV_GUARD_ACTIVE = false;
            return;
        }

        if (hasRealKadenceHeader()) {
            stopEnsureTimer();
            stopBodyObserver();
            stopNeutraliseLoop();
            return;
        }

        if (createStubNavigation()) {
            startNeutraliseLoop();
            stopEnsureTimer();
            stopBodyObserver();
            return;
        }

        if (!document.body) {
            observeForBody();
        }

        if (ensureAttempts++ < MAX_STUB_ATTEMPTS) {
            ensureTimer = window.setTimeout(ensureNavigationShell, 50);
        } else {
            logDebug('Reached maximum stub injection attempts.');
            stopEnsureTimer();
            stopBodyObserver();
        }
    }

    ensureNavigationShell();
    document.addEventListener('DOMContentLoaded', ensureNavigationShell, { passive: true });
    window.addEventListener('load', ensureNavigationShell, { passive: true });

    window.addEventListener('error', function(event) {
        if (!event) {
            return;
        }

        var message = event.message || '';
        if (message.indexOf('getAttribute') !== -1 && message.toLowerCase().indexOf('navigation') !== -1) {
            logDebug('Suppressed navigation error: ' + message);
            if (typeof event.preventDefault === 'function') {
                event.preventDefault();
            }
            if (typeof event.stopImmediatePropagation === 'function') {
                event.stopImmediatePropagation();
            }
            return true;
        }
    }, { capture: true });

    logDebug('Guard armed.');
})();
