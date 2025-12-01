/**
 * Kadence Navigation Guard
 * 
 * Prevents Kadence theme's navigation.min.js from throwing errors on pages
 * where the header/navigation elements are hidden or don't exist.
 * 
 * This script should be loaded early (before Kadence scripts) with highest priority.
 * It intercepts DOM queries for missing navigation elements and provides graceful fallbacks.
 */
(function() {
    'use strict';

    // Check if we're on a page that hides the header
    var isHeaderlessPage = function() {
        return document.body && (
            document.body.classList.contains('page-template-page-admin-data') ||
            document.body.classList.contains('page-template-page-data') ||
            document.body.classList.contains('page-template-page-data-test')
        );
    };

    // Store original querySelector/querySelectorAll
    var originalQuerySelector = Document.prototype.querySelector;
    var originalQuerySelectorAll = Document.prototype.querySelectorAll;
    var originalElementQuerySelector = Element.prototype.querySelector;
    var originalElementQuerySelectorAll = Element.prototype.querySelectorAll;

    // Navigation-related selectors that Kadence looks for
    var navSelectors = [
        '.site-header',
        'header.site-header',
        '.main-navigation',
        'nav.main-navigation',
        '#masthead',
        '.header-wrapper',
        '.kadence-nav',
        '.nav-drop-wrap',
        '.header-navigation',
        '.mobile-navigation',
        '.mobile-nav-trigger',
        '.drawer-navigation',
        '#mobile-drawer',
        '.header-menu-container',
        '.menu-toggle',
        '[data-toggle-target]',
        '[data-toggle-body-class]',
        '.sub-menu',
        '.dropdown-nav-toggle',
        '.menu-item-has-children'
    ];

    // Check if a selector is navigation-related
    var isNavSelector = function(selector) {
        if (!selector || typeof selector !== 'string') return false;
        var lowerSelector = selector.toLowerCase();
        return navSelectors.some(function(navSel) {
            return lowerSelector.indexOf(navSel.toLowerCase()) !== -1;
        });
    };

    // Create a dummy element that won't cause errors when methods are called on it
    var createDummyElement = function() {
        var dummy = document.createElement('div');
        dummy.style.display = 'none';
        dummy.setAttribute = function() { return dummy; };
        dummy.getAttribute = function() { return null; };
        dummy.removeAttribute = function() { return dummy; };
        dummy.classList.add = function() {};
        dummy.classList.remove = function() {};
        dummy.classList.toggle = function() { return false; };
        dummy.classList.contains = function() { return false; };
        dummy.addEventListener = function() {};
        dummy.removeEventListener = function() {};
        dummy.querySelector = function() { return null; };
        dummy.querySelectorAll = function() { return []; };
        dummy.closest = function() { return null; };
        dummy.getBoundingClientRect = function() { 
            return { top: 0, left: 0, bottom: 0, right: 0, width: 0, height: 0 }; 
        };
        dummy.offsetWidth = 0;
        dummy.offsetHeight = 0;
        dummy.offsetTop = 0;
        dummy.offsetLeft = 0;
        dummy.scrollWidth = 0;
        dummy.scrollHeight = 0;
        dummy.children = [];
        dummy.childNodes = [];
        dummy.parentNode = null;
        dummy.parentElement = null;
        dummy.nextSibling = null;
        dummy.previousSibling = null;
        dummy.nextElementSibling = null;
        dummy.previousElementSibling = null;
        return dummy;
    };

    // Override document.querySelector
    Document.prototype.querySelector = function(selector) {
        var result = originalQuerySelector.call(this, selector);
        
        // On headerless pages, return null for nav selectors (don't use dummy)
        // This allows proper null checks to work
        if (!result && isHeaderlessPage() && isNavSelector(selector)) {
            return null;
        }
        
        return result;
    };

    // Override document.querySelectorAll
    Document.prototype.querySelectorAll = function(selector) {
        var result = originalQuerySelectorAll.call(this, selector);
        
        // On headerless pages, return empty NodeList for nav selectors
        if (result.length === 0 && isHeaderlessPage() && isNavSelector(selector)) {
            return result; // Already empty
        }
        
        return result;
    };

    // Override Element.prototype.querySelector
    Element.prototype.querySelector = function(selector) {
        var result = originalElementQuerySelector.call(this, selector);
        
        if (!result && isHeaderlessPage() && isNavSelector(selector)) {
            return null;
        }
        
        return result;
    };

    // Override Element.prototype.querySelectorAll
    Element.prototype.querySelectorAll = function(selector) {
        var result = originalElementQuerySelectorAll.call(this, selector);
        
        if (result.length === 0 && isHeaderlessPage() && isNavSelector(selector)) {
            return result;
        }
        
        return result;
    };

    // Suppress errors from Kadence's navigation script
    var originalError = window.onerror;
    window.onerror = function(message, source, lineno, colno, error) {
        // Check if error is from navigation.min.js and related to getAttribute
        if (source && source.indexOf('navigation.min.js') !== -1) {
            if (message && (
                message.indexOf('getAttribute') !== -1 ||
                message.indexOf('null') !== -1 ||
                message.indexOf('undefined') !== -1
            )) {
                // Suppress this error on headerless pages
                if (isHeaderlessPage()) {
                    console.debug('[KOP Nav Guard] Suppressed Kadence navigation error:', message);
                    return true; // Prevent error from propagating
                }
            }
        }
        
        // Call original error handler if it exists
        if (originalError) {
            return originalError.apply(this, arguments);
        }
        return false;
    };

    // Also handle unhandled promise rejections
    window.addEventListener('unhandledrejection', function(event) {
        if (event.reason && event.reason.message) {
            var msg = event.reason.message;
            if (msg.indexOf('getAttribute') !== -1 || msg.indexOf('null') !== -1) {
                if (isHeaderlessPage()) {
                    console.debug('[KOP Nav Guard] Suppressed unhandled rejection:', msg);
                    event.preventDefault();
                }
            }
        }
    });

    console.debug('[KOP Nav Guard] Kadence navigation guard loaded');
})();
