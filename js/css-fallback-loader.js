/**
 * CSS Fallback Loader for Kids Over Profits
 * Detects failed CSS loads and retries with alternate paths
 */
(function initCssFallbackLoader() {
    'use strict';

    var LOAD_TIMEOUT = 5000; // 5 seconds
    var RETRY_DELAY = 1000;
    var MAX_RETRIES = 3;

    function isStylesheetLoaded(linkElement) {
        try {
            if (linkElement.sheet && linkElement.sheet.cssRules) {
                return linkElement.sheet.cssRules.length > 0;
            }

            if (linkElement.sheet && !linkElement.sheet.disabled) {
                return true;
            }
        } catch (error) {
            if (error.name === 'SecurityError') {
                return true;
            }
        }

        return false;
    }

    function tryAlternateUrl(handle, urls, attemptIndex) {
        if (attemptIndex >= urls.length || attemptIndex >= MAX_RETRIES) {
            console.error('[KOP] All CSS fallbacks failed for: ' + handle);
            return;
        }

        var url = urls[attemptIndex];
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        link.id = handle + '-fallback-' + attemptIndex;

        var loaded = false;

        link.onload = function () {
            loaded = true;
            console.log('[KOP] CSS loaded via fallback ' + (attemptIndex + 1) + ': ' + handle);
        };

        link.onerror = function () {
            console.warn('[KOP] CSS fallback ' + (attemptIndex + 1) + ' failed for ' + handle + ': ' + url);
            setTimeout(function () {
                tryAlternateUrl(handle, urls, attemptIndex + 1);
            }, RETRY_DELAY);
        };

        document.head.appendChild(link);

        setTimeout(function () {
            if (!loaded && !isStylesheetLoaded(link)) {
                console.warn('[KOP] CSS fallback ' + (attemptIndex + 1) + ' timeout for ' + handle);
                tryAlternateUrl(handle, urls, attemptIndex + 1);
            }
        }, LOAD_TIMEOUT);
    }

    function monitorStylesheets() {
        var stylesheets = document.querySelectorAll('link[rel="stylesheet"]');
        var fallbacks = window.KOP_CSS_FALLBACKS || {};

        Array.prototype.forEach.call(stylesheets, function (link) {
            var id = link.id || '';
            var handle = id.replace(/-css$/, '') || link.getAttribute('data-handle') || link.href;

            setTimeout(function () {
                if (!isStylesheetLoaded(link)) {
                    console.warn('[KOP] CSS failed to load: ' + handle + ' (' + link.href + ')');

                    if (fallbacks[handle] && fallbacks[handle].length > 0) {
                        console.log('[KOP] Attempting CSS fallback for: ' + handle);
                        tryAlternateUrl(handle, fallbacks[handle], 0);
                    }
                }
            }, LOAD_TIMEOUT);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', monitorStylesheets);
    } else {
        monitorStylesheets();
    }

    window.KOP_CSS_MONITOR = {
        check: monitorStylesheets,
        isLoaded: isStylesheetLoaded
    };
})();
