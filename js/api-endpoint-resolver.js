/**
 * API Endpoint Resolver for Kids Over Profits
 * Ensures consistent API endpoint URLs across the application
 */
(function initKopApiEndpoints() {
    'use strict';

    var API_PATH_VARIANTS = [
        '/wp-content/themes/child/api/data_form',
        '/themes/child/api/data_form',
        'api/data_form'
    ];

    function testEndpoint(baseUrl, endpoint) {
        return new Promise(function (resolve) {
            var url = baseUrl.replace(/\/$/, '') + '/' + endpoint;
            var xhr = new XMLHttpRequest();

            xhr.open('HEAD', url, true);
            xhr.timeout = 3000;

            xhr.onload = function () {
                resolve(xhr.status >= 200 && xhr.status < 400);
            };

            xhr.onerror = function () {
                resolve(false);
            };

            xhr.ontimeout = function () {
                resolve(false);
            };

            xhr.send();
        });
    }

    async function detectApiBase() {
        var testFile = 'get-master-data.php';
        var origin = window.location.origin;

        for (var i = 0; i < API_PATH_VARIANTS.length; i += 1) {
            var basePath = API_PATH_VARIANTS[i];
            var fullBase = basePath.charAt(0) === '/' ? origin + basePath : basePath;

            var works = await testEndpoint(fullBase, testFile);

            if (works) {
                console.log('[KOP] API base detected:', fullBase);
                return fullBase;
            }
        }

        console.warn('[KOP] Could not detect API base, using default');
        return origin + API_PATH_VARIANTS[0];
    }

    function getApiEndpoint(filename) {
        if (!window.KOP_API_BASE) {
            console.error('[KOP] API base not initialized. Call KOP_API.init() first.');
            return window.location.origin + API_PATH_VARIANTS[0] + '/' + filename;
        }

        return window.KOP_API_BASE.replace(/\/$/, '') + '/' + filename;
    }

    async function init() {
        if (window.KOP_API_BASE) {
            console.log('[KOP] API already initialized:', window.KOP_API_BASE);
            return window.KOP_API_BASE;
        }

        var base = await detectApiBase();
        window.KOP_API_BASE = base;

        var event = new CustomEvent('kop-api-ready', { detail: { base: base } });
        document.dispatchEvent(event);

        return base;
    }

    window.KOP_API = {
        init: init,
        getEndpoint: getApiEndpoint,
        getBase: function () {
            return window.KOP_API_BASE;
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    console.log('[KOP] API endpoint resolver loaded');
})();
