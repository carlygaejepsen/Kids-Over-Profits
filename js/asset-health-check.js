/**
 * Asset Health Check for Kids Over Profits
 * Comprehensive diagnostics for CSS, JS, and API endpoint loading
 */
(function initAssetHealthCheck() {
    'use strict';

    var HEALTH_CHECK_DELAY = 8000;
    var CSS_LOAD_TIMEOUT = 5000;

    var healthReport = {
        timestamp: new Date().toISOString(),
        themeBase: {
            detected: null,
            resolved: null,
            variants: []
        },
        css: {
            expected: [],
            loaded: [],
            failed: []
        },
        js: {
            expected: [],
            loaded: [],
            failed: []
        },
        api: {
            base: null,
            endpoints: {}
        },
        errors: []
    };

    function checkStylesheet(link) {
        try {
            if (link.sheet && link.sheet.cssRules) {
                return link.sheet.cssRules.length > 0;
            }

            if (link.sheet && !link.sheet.disabled) {
                return true;
            }
        } catch (error) {
            if (error.name === 'SecurityError') {
                return true;
            }
        }

        return false;
    }

    function checkCssHealth() {
        var stylesheets = document.querySelectorAll('link[rel="stylesheet"]');

        Array.prototype.forEach.call(stylesheets, function (link) {
            var id = link.id || link.href;
            healthReport.css.expected.push(id);

            if (checkStylesheet(link)) {
                healthReport.css.loaded.push({
                    id: id,
                    href: link.href
                });
            } else {
                healthReport.css.failed.push({
                    id: id,
                    href: link.href,
                    error: 'Failed to load or contains no CSS rules'
                });
            }
        });
    }

    function checkJsHealth() {
        var scripts = document.querySelectorAll('script[src]');

        Array.prototype.forEach.call(scripts, function (script) {
            var id = script.id || script.src;
            healthReport.js.expected.push(id);

            if (script.hasAttribute('data-error')) {
                healthReport.js.failed.push({
                    id: id,
                    src: script.src,
                    error: script.getAttribute('data-error')
                });
            } else {
                healthReport.js.loaded.push({
                    id: id,
                    src: script.src
                });
            }
        });
    }

    function checkThemeBase() {
        healthReport.themeBase.detected = window.KOP_DETECTED_THEME_BASE || null;
        healthReport.themeBase.resolved = window.KOP_RESOLVED_THEME_BASE || null;
        healthReport.themeBase.variants = window.KOP_THEME_BASES || [];

        if (!healthReport.themeBase.resolved) {
            healthReport.errors.push('Theme base not resolved');
        }
    }

    function checkApiEndpoint(url) {
        return fetch(url, {
            method: 'HEAD',
            cache: 'no-cache'
        }).then(function (response) {
            return {
                url: url,
                status: response.status,
                ok: response.ok
            };
        }).catch(function (error) {
            return {
                url: url,
                ok: false,
                error: error && error.message ? error.message : 'Unknown error'
            };
        });
    }

    function checkApiHealth() {
        healthReport.api.base = window.KOP_API_BASE || (window.KOP_API && typeof window.KOP_API.getBase === 'function' ? window.KOP_API.getBase() : null);

        if (!healthReport.api.base) {
            healthReport.errors.push('API base not initialized');
            return Promise.resolve();
        }

        var endpoints = ['get-master-data.php', 'get-autocomplete.php'];
        var checks = endpoints.map(function (endpoint) {
            var url = healthReport.api.base.replace(/\/$/, '') + '/' + endpoint;
            return checkApiEndpoint(url).then(function (result) {
                healthReport.api.endpoints[endpoint] = result;
            });
        });

        return Promise.all(checks);
    }

    function generateSummary() {
        return {
            cssLoadRate: healthReport.css.expected.length ? ((healthReport.css.loaded.length / healthReport.css.expected.length) * 100).toFixed(1) + '%' : 'N/A',
            jsLoadRate: healthReport.js.expected.length ? ((healthReport.js.loaded.length / healthReport.js.expected.length) * 100).toFixed(1) + '%' : 'N/A',
            hasErrors: healthReport.errors.length > 0 || healthReport.css.failed.length > 0 || healthReport.js.failed.length > 0
        };
    }

    function displayHealthIndicator(report) {
        var params = new URLSearchParams(window.location.search);
        if (!params.has('debug')) {
            return;
        }

        var indicator = document.createElement('div');
        indicator.id = 'kop-health-indicator';
        indicator.style.cssText = [
            'position:fixed',
            'bottom:10px',
            'right:10px',
            'background:' + (report.summary.hasErrors ? '#ff4444' : '#44aa44'),
            'color:#fff',
            'padding:10px 16px',
            'border-radius:6px',
            'font-family:monospace',
            'font-size:12px',
            'z-index:999999',
            'cursor:pointer',
            'box-shadow:0 2px 10px rgba(0,0,0,0.3)'
        ].join(';');
        indicator.textContent = report.summary.hasErrors ? '⚠ Assets: Issues Detected' : '✓ Assets: OK';

        indicator.addEventListener('click', function () {
            console.table(report.css.failed);
            console.table(report.js.failed);
            console.log('Full asset health report:', report);
        });

        document.body.appendChild(indicator);
    }

    function runDiagnostics() {
        checkThemeBase();
        checkCssHealth();
        checkJsHealth();

        return checkApiHealth().then(function () {
            healthReport.summary = generateSummary();
            window.KOP_HEALTH_REPORT = healthReport;

            if (healthReport.summary.hasErrors) {
                console.warn('[KOP Health Check] Issues detected', healthReport);
            } else {
                console.log('[KOP Health Check] All systems operational');
            }

            var event = new CustomEvent('kop-health-check-complete', {
                detail: healthReport
            });
            document.dispatchEvent(event);

            displayHealthIndicator(healthReport);

            return healthReport;
        });
    }

    function runHealthCheck() {
        return new Promise(function (resolve) {
            setTimeout(function () {
                runDiagnostics().then(resolve);
            }, HEALTH_CHECK_DELAY);
        });
    }

    window.KOP_HEALTH_CHECK = {
        run: runDiagnostics,
        runWithDelay: runHealthCheck,
        getReport: function () {
            return window.KOP_HEALTH_REPORT;
        }
    };

    runHealthCheck();

    console.log('[KOP Health Check] Diagnostic system loaded');
})();
