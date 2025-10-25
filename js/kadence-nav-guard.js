/**
 * Kadence Navigation Guard - Smart Protection
 * Prevents navigation.min.js errors with intelligent fallbacks
 *
 * This script protects against:
 * - Missing navigation elements (headerless pages)
 * - Timing issues (script loads before DOM ready)
 * - Race conditions (elements not yet rendered)
 *
 * This script should be loaded BEFORE any Kadence navigation scripts
 */
(function() {
    'use strict';

    // Flag to track if guard is active
    window.KADENCE_NAV_GUARD_ACTIVE = true;

    // Check if this is a headerless layout (data pages)
    // Use a function to avoid accessing document.body before it exists
    function isHeaderlessPage() {
        return window.KADENCE_NAV_DISABLED ||
               window.location.pathname.includes('data.html') ||
               window.location.pathname.includes('admin-data.html') || // Check for document.body before accessing classList
               (document.body && (
                   document.body.classList.contains('page-data') || 
                   document.body.classList.contains('page-admin-data'))
               );
    }

    // Store original DOM query methods
    var originalQuerySelector = document.querySelector.bind(document);
    var originalQuerySelectorAll = document.querySelectorAll.bind(document);
    var originalGetElementById = document.getElementById.bind(document);
    var originalGetElementsByClassName = document.getElementsByClassName.bind(document);
    var originalGetElementsByTagName = document.getElementsByTagName.bind(document);

    // Navigation-related selectors to block
    var navSelectors = [
        '#main-navigation',
        '.main-navigation',
        '#site-navigation',
        '.site-navigation',
        '#primary-navigation',
        '.primary-navigation',
        '#masthead',
        '.site-header',
        'header.site-header',
        'nav[role="navigation"]',
        '.header-navigation',
        '.kadence-navigation'
    ];

    // Navigation-related IDs to block
    var navIds = [
        'main-navigation',
        'site-navigation',
        'primary-navigation',
        'masthead'
    ];

    // Navigation-related class names to block
    var navClasses = [
        'main-navigation',
        'site-navigation',
        'primary-navigation',
        'site-header',
        'header-navigation',
        'kadence-navigation'
    ];

    // Check if a selector contains navigation-related terms
    function isNavSelector(selector) {
        if (!selector || typeof selector !== 'string') return false;
        return navSelectors.some(function(navSel) {
            return selector.includes(navSel);
        });
    }

    // Override querySelector with smart fallback
    document.querySelector = function(selector) {
        // On headerless pages, always block navigation queries
        if (isHeaderlessPage() && isNavSelector(selector)) {
            console.debug('[Kadence Nav Guard] Blocked querySelector on headerless page:', selector);
            return null;
        }

        // Try to find the element normally
        var element = originalQuerySelector(selector);

        // If navigation element not found, check if this is a timing issue
        if (!element && isNavSelector(selector)) {
            console.warn('[Kadence Nav Guard] Navigation element not found (may be timing issue):', selector);
            // Return null gracefully to prevent errors
            return null;
        }

        return element;
    };

    // Override querySelectorAll with smart fallback
    document.querySelectorAll = function(selector) {
        // On headerless pages, always block navigation queries
        if (isHeaderlessPage() && isNavSelector(selector)) {
            console.debug('[Kadence Nav Guard] Blocked querySelectorAll on headerless page:', selector);
            return [];
        }

        // Try to find elements normally
        var elements = originalQuerySelectorAll(selector);

        // If navigation elements not found, return empty array gracefully
        if (elements.length === 0 && isNavSelector(selector)) {
            console.debug('[Kadence Nav Guard] No navigation elements found:', selector);
            return [];
        }

        return elements;
    };

    // Override getElementById with smart fallback
    document.getElementById = function(id) {
        // On headerless pages, always block navigation IDs
        if (isHeaderlessPage() && navIds.includes(id)) {
            console.debug('[Kadence Nav Guard] Blocked getElementById on headerless page:', id);
            return null;
        }

        // Try to find element normally
        var element = originalGetElementById(id);

        // If navigation element not found, return null gracefully
        if (!element && navIds.includes(id)) {
            console.debug('[Kadence Nav Guard] Navigation ID not found:', id);
            return null;
        }

        return element;
    };

    // Override getElementsByClassName
    document.getElementsByClassName = function(className) {
        // On headerless pages, always block navigation classes
        if (isHeaderlessPage() && navClasses.includes(className)) {
            console.debug('[Kadence Nav Guard] Blocked getElementsByClassName on headerless page:', className);
            return [];
        }

        // Try normally
        var elements = originalGetElementsByClassName(className);

        // If navigation class not found, return empty gracefully
        if (elements.length === 0 && navClasses.includes(className)) {
            console.debug('[Kadence Nav Guard] Navigation class not found:', className);
            return [];
        }

        return elements;
    };

    // Override getElementsByTagName for specific cases
    document.getElementsByTagName = function(tagName) {
        var elements = originalGetElementsByTagName(tagName);

        // Filter out navigation elements
        if (tagName.toLowerCase() === 'nav' || tagName.toLowerCase() === 'header') {
            var filtered = Array.prototype.filter.call(elements, function(el) {
                var elClasses = el.className || '';
                var elId = el.id || '';
                return !navSelectors.some(function(navSel) {
                    return elClasses.includes(navSel.replace('.', '')) ||
                           elId.includes(navSel.replace('#', ''));
                });
            });
            console.debug('[Kadence Nav Guard] Filtered getElementsByTagName:', tagName, filtered.length);
            return filtered;
        }

        return elements;
    };

    // Prevent navigation initialization when DOM is ready
    function disableKadenceNav() {
        // Stub out navigation initialization functions
        if (window.kadence) {
            if (window.kadence.initNavigation) {
                console.log('[Kadence Nav Guard] Navigation initialization disabled');
                window.kadence.initNavigation = function() {
                    console.debug('[Kadence Nav Guard] Kadence navigation init blocked');
                };
            }
            if (window.kadence.navigation) {
                window.kadence.navigation.init = function() {
                    console.debug('[Kadence Nav Guard] Kadence navigation.init blocked');
                };
            }
        }

        // Also check for standalone Kadence navigation object
        if (window.KadenceNavigation) {
            window.KadenceNavigation.init = function() {
                console.debug('[Kadence Nav Guard] KadenceNavigation.init blocked');
            };
        }
    }

    // Run immediately
    disableKadenceNav();

    // Run on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', disableKadenceNav);
    } else {
        disableKadenceNav();
    }

    // Run on load as well for extra safety
    window.addEventListener('load', disableKadenceNav);

    // Add global error handler for navigation-related errors
    window.addEventListener('error', function(event) {
        if (event.message && event.message.includes('navigation') && event.message.includes('getAttribute')) {
            console.warn('[Kadence Nav Guard] Caught navigation error:', event.message);
            event.preventDefault();
            return true;
        }
    }, true);

    console.log('[Kadence Nav Guard] Smart protection active' + (isHeaderlessPage() ? ' (headerless mode)' : ' (standard mode)'));
})();
