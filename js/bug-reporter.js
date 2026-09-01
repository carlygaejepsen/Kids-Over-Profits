/**
 * KOP Bug Reporter — feature-scoped reporting
 *
 * No global button. Instead, small "Report a problem" links attach to
 * SPECIFIC features, so every report says exactly which feature broke:
 *
 *   - Markup:  any element with `data-kop-bug-feature="wiki-editor/import"`
 *     (optional `data-kop-bug-label="Markdown Import"`) gets a report link
 *     appended automatically on page load.
 *   - JS-built UIs: KOPBugReporter.attach(el, feature, label) injects the
 *     same link into a dynamically created container.
 *   - Programmatic: KOPBugReporter.open({feature, featureLabel, category,
 *     description, context}) — e.g. from a failed save's error message.
 *
 * The script still loads site-wide to auto-capture recent JS errors and
 * failed API calls (fetch and XHR: theme /api/ endpoints, the WP REST API,
 * and admin-ajax), so reports arrive with technical context attached.
 *
 * Config comes from `kopBugReporterSettings` (localized by inc/enqueue.php):
 *   { endpoint: '.../api/save-bug-report.php', fallbackEmail: '...' }
 *
 * KOPBugReporter.captureError(label) pushes a custom entry into the
 * technical log (e.g. a caught save failure).
 */
(function () {
    'use strict';

    var settings = window.kopBugReporterSettings || {};
    var endpoint = settings.endpoint || '/wp-content/themes/child/api/save-bug-report.php';
    var fallbackEmail = settings.fallbackEmail || '';

    // ---------------------------------------------------------------
    // Error capture — ring buffer of the last 20 problems on the page
    // ---------------------------------------------------------------
    var errorLog = [];
    var MAX_LOG = 20;

    function logEntry(type, message) {
        errorLog.push({
            type: type,
            message: String(message).slice(0, 1000),
            time: new Date().toISOString()
        });
        if (errorLog.length > MAX_LOG) errorLog.shift();
    }

    window.addEventListener('error', function (event) {
        if (event.message) {
            logEntry('js-error', event.message + ' (' + (event.filename || '?') + ':' + (event.lineno || '?') + ')');
        }
    });

    window.addEventListener('unhandledrejection', function (event) {
        var reason = event.reason && (event.reason.message || event.reason);
        logEntry('promise-rejection', reason || 'Unhandled promise rejection');
    });

    // URLs whose failures are worth logging: theme API endpoints, the WP
    // REST API, and admin-ajax (used by the anonymous document portal).
    function isTrackedUrl(url) {
        return url.indexOf('/api/') !== -1
            || url.indexOf('/wp-json/') !== -1
            || url.indexOf('admin-ajax.php') !== -1;
    }

    // Record failed API calls (status >= 400 or network failure) without
    // changing fetch behavior for callers.
    var origFetch = window.fetch;
    if (typeof origFetch === 'function') {
        window.fetch = function (input, init) {
            var url = (typeof input === 'string') ? input : (input && input.url) || '';
            return origFetch.apply(this, arguments).then(function (response) {
                if (!response.ok && isTrackedUrl(url)) {
                    logEntry('api-error', ((init && init.method) || 'GET') + ' ' + url + ' → HTTP ' + response.status);
                }
                return response;
            }, function (err) {
                logEntry('network-error', ((init && init.method) || 'GET') + ' ' + url + ' → ' + (err && err.message ? err.message : 'network failure'));
                throw err;
            });
        };
    }

    // Record failed XHR calls too — jQuery ($.ajax) uses XMLHttpRequest, not
    // fetch, so admin-ajax features like the anonymous document portal would
    // otherwise fail invisibly. admin-ajax also reports many failures as
    // HTTP 200 with {"success":false}, so peek at JSON bodies for that.
    if (window.XMLHttpRequest && XMLHttpRequest.prototype) {
        var origOpen = XMLHttpRequest.prototype.open;
        var origSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (method, url) {
            this._kopBugInfo = { method: String(method || 'GET').toUpperCase(), url: String(url || '') };
            return origOpen.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function () {
            var xhr = this;
            var info = xhr._kopBugInfo;
            if (info && isTrackedUrl(info.url)) {
                xhr.addEventListener('load', function () {
                    if (xhr.status >= 400) {
                        logEntry('api-error', info.method + ' ' + info.url + ' → HTTP ' + xhr.status);
                        return;
                    }
                    if (info.url.indexOf('admin-ajax.php') === -1) return;
                    try {
                        if ((xhr.responseType === '' || xhr.responseType === 'text')
                            && /^\s*\{/.test(xhr.responseText || '')) {
                            var body = JSON.parse(xhr.responseText);
                            if (body && body.success === false) {
                                var detail = body.data && (body.data.message || body.data);
                                logEntry('api-error', info.method + ' ' + info.url + ' → success:false'
                                    + (typeof detail === 'string' ? ' (' + detail.slice(0, 200) + ')' : ''));
                            }
                        }
                    } catch (e) { /* non-JSON response — ignore */ }
                });
                xhr.addEventListener('error', function () {
                    logEntry('network-error', info.method + ' ' + info.url + ' → network failure');
                });
                xhr.addEventListener('timeout', function () {
                    logEntry('network-error', info.method + ' ' + info.url + ' → timed out');
                });
            }
            return origSend.apply(this, arguments);
        };
    }

    // ---------------------------------------------------------------
    // Widget UI
    // ---------------------------------------------------------------
    var CATEGORIES = [
        { value: 'save-failed', label: 'Saving or submitting didn’t work' },
        { value: 'load-failed', label: 'Something didn’t load' },
        { value: 'broken-ui', label: 'Something looks broken or misbehaves' },
        { value: 'wrong-data', label: 'Information is wrong or missing' },
        { value: 'other', label: 'Something else' }
    ];

    var modal = null;
    var lastFocused = null;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    }

    /**
     * Inject a small "Report a problem" link into a feature's container.
     * `feature` is a stable id like "wiki-editor/bulk-upload"; `label` is the
     * human name shown in the modal ("Bulk Upload").
     */
    function attach(container, feature, label) {
        if (!container || !feature) return null;
        if (container.querySelector(':scope > .kop-bug-report-link')) return null; // already attached
        var link = el('button', 'kop-bug-report-link');
        link.type = 'button';
        // Icon-only; the text expands on hover/focus (CSS). The icon is an
        // inline SVG flag ("flag an issue") — it inherits currentColor, so it
        // follows the link's styling instead of platform emoji rendering.
        link.innerHTML = '<span class="kop-bug-report-link__icon" aria-hidden="true">'
            + '<svg viewBox="0 0 16 16" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
            + '<path d="M3.5 14.5V2"/><path d="M3.5 2.5h8.8l-2.1 3 2.1 3H3.5"/></svg>'
            + '</span>'
            + '<span class="kop-bug-report-link__text">Report a problem</span>';
        link.setAttribute('aria-label', 'Report a problem with ' + (label || feature));
        link.title = 'Report a problem';
        link.addEventListener('click', function () {
            openModal({ feature: feature, featureLabel: label || feature });
        });
        container.appendChild(link);
        return link;
    }

    // Auto-attach to any markup tagged with data-kop-bug-feature.
    function scanForFeatures() {
        var tagged = document.querySelectorAll('[data-kop-bug-feature]');
        Array.prototype.forEach.call(tagged, function (node) {
            attach(node, node.getAttribute('data-kop-bug-feature'),
                node.getAttribute('data-kop-bug-label') || '');
        });
    }

    // Many pages re-render tagged containers with innerHTML (wiping injected
    // links) or add tagged markup after load — re-scan on DOM changes.
    // attach() skips containers that already have a link, so this is cheap.
    function watchForFeatures() {
        if (!window.MutationObserver) return;
        var pending = null;
        var observer = new MutationObserver(function () {
            if (pending) return;
            pending = setTimeout(function () {
                pending = null;
                scanForFeatures();
            }, 250);
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    function buildModal() {
        if (modal) return;

        modal = el('div', 'kop-bug-reporter__overlay');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-label', 'Report a problem');
        modal.hidden = true;

        var panel = el('div', 'kop-bug-reporter__panel');

        var header = el('div', 'kop-bug-reporter__header');
        header.appendChild(el('h2', 'kop-bug-reporter__title', 'Report a problem'));
        var closeBtn = el('button', 'kop-bug-reporter__close');
        closeBtn.type = 'button';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', closeModal);
        header.appendChild(closeBtn);
        panel.appendChild(header);

        var form = el('form', 'kop-bug-reporter__form');
        form.noValidate = true;

        // Which feature this report is about — filled by openModal().
        var featureChip = el('p', 'kop-bug-reporter__feature');
        featureChip.hidden = true;
        form.appendChild(featureChip);

        // Category
        var catLabel = el('label', 'kop-bug-reporter__label', 'What kind of problem is it?');
        catLabel.setAttribute('for', 'kop-bug-category');
        var catSelect = el('select', 'kop-bug-reporter__input');
        catSelect.id = 'kop-bug-category';
        CATEGORIES.forEach(function (cat) {
            var opt = document.createElement('option');
            opt.value = cat.value;
            opt.textContent = cat.label;
            catSelect.appendChild(opt);
        });
        form.appendChild(catLabel);
        form.appendChild(catSelect);

        // Description — optional; the auto-captured technical details usually
        // carry enough context on their own.
        var descLabel = el('label', 'kop-bug-reporter__label', 'What happened? (optional)');
        descLabel.setAttribute('for', 'kop-bug-description');
        var desc = el('textarea', 'kop-bug-reporter__input kop-bug-reporter__textarea');
        desc.id = 'kop-bug-description';
        desc.rows = 4;
        desc.placeholder = 'Example: I clicked Save Draft and got an error message, and my entry disappeared.';
        form.appendChild(descLabel);
        form.appendChild(desc);

        // Steps
        var stepsLabel = el('label', 'kop-bug-reporter__label', 'What were you doing right before? (optional)');
        stepsLabel.setAttribute('for', 'kop-bug-steps');
        var steps = el('textarea', 'kop-bug-reporter__input kop-bug-reporter__textarea');
        steps.id = 'kop-bug-steps';
        steps.rows = 3;
        steps.placeholder = 'Example: 1. Opened the wiki editor  2. Loaded the entry for X  3. Clicked Submit';
        form.appendChild(stepsLabel);
        form.appendChild(steps);

        // Contact
        var contactLabel = el('label', 'kop-bug-reporter__label', 'Email, if you’d like a follow-up (optional)');
        contactLabel.setAttribute('for', 'kop-bug-contact');
        var contact = el('input', 'kop-bug-reporter__input');
        contact.id = 'kop-bug-contact';
        contact.type = 'email';
        contact.autocomplete = 'email';
        contact.placeholder = 'you@example.com';
        form.appendChild(contactLabel);
        form.appendChild(contact);

        // Honeypot — visually hidden; bots fill it, humans never see it.
        var hpWrap = el('div', 'kop-bug-reporter__hp');
        hpWrap.setAttribute('aria-hidden', 'true');
        var hp = el('input');
        hp.type = 'text';
        hp.name = 'website';
        hp.tabIndex = -1;
        hp.autocomplete = 'off';
        hpWrap.appendChild(hp);
        form.appendChild(hpWrap);

        // Technical details toggle
        var techWrap = el('div', 'kop-bug-reporter__tech');
        var techToggle = el('label', 'kop-bug-reporter__checkbox-label');
        var techCheck = el('input');
        techCheck.type = 'checkbox';
        techCheck.checked = true;
        techToggle.appendChild(techCheck);
        techToggle.appendChild(document.createTextNode(' Include technical details (recent errors on this page, browser, screen size)'));
        techWrap.appendChild(techToggle);

        var techDetails = el('details', 'kop-bug-reporter__tech-preview');
        techDetails.appendChild(el('summary', null, 'See what will be sent'));
        var techPre = el('pre', 'kop-bug-reporter__tech-pre');
        techDetails.appendChild(techPre);
        techWrap.appendChild(techDetails);
        form.appendChild(techWrap);

        // Status line + actions
        var status = el('p', 'kop-bug-reporter__status');
        status.setAttribute('role', 'status');
        form.appendChild(status);

        var actions = el('div', 'kop-bug-reporter__actions');
        var cancel = el('button', 'kop-bug-reporter__btn kop-bug-reporter__btn--ghost', 'Cancel');
        cancel.type = 'button';
        cancel.addEventListener('click', closeModal);
        var submit = el('button', 'kop-bug-reporter__btn kop-bug-reporter__btn--primary', 'Send report');
        submit.type = 'submit';
        actions.appendChild(cancel);
        actions.appendChild(submit);
        form.appendChild(actions);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitReport();
        });

        panel.appendChild(form);
        modal.appendChild(panel);

        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) closeModal();
        });

        document.body.appendChild(modal);

        modal._fields = {
            featureChip: featureChip,
            category: catSelect,
            description: desc,
            steps: steps,
            contact: contact,
            honeypot: hp,
            includeTech: techCheck,
            techPre: techPre,
            status: status,
            submit: submit
        };
    }

    var prefillContext = null;
    var currentFeature = null; // { id, label } for the report being written

    function techSnapshot() {
        return {
            errors: errorLog.slice(),
            viewport: window.innerWidth + 'x' + window.innerHeight,
            url: window.location.href
        };
    }

    function refreshTechPreview() {
        var f = modal._fields;
        var snap = techSnapshot();
        var lines = [
            'Page: ' + snap.url,
            'Screen: ' + snap.viewport,
            'Browser: ' + navigator.userAgent
        ];
        if (snap.errors.length) {
            lines.push('', 'Recent errors on this page:');
            snap.errors.forEach(function (e) {
                lines.push('  [' + e.type + '] ' + e.message);
            });
        } else {
            lines.push('', 'No errors captured on this page so far.');
        }
        f.techPre.textContent = lines.join('\n');
    }

    function openModal(prefill) {
        buildModal();
        var f = modal._fields;

        prefill = prefill || {};
        prefillContext = prefill.context || null;
        currentFeature = prefill.feature
            ? { id: String(prefill.feature), label: String(prefill.featureLabel || prefill.feature) }
            : null;
        if (currentFeature) {
            f.featureChip.textContent = 'Reporting a problem with: ' + currentFeature.label;
            f.featureChip.hidden = false;
        } else {
            f.featureChip.hidden = true;
        }
        if (prefill.category) f.category.value = prefill.category;
        if (prefill.description) f.description.value = prefill.description;

        f.status.textContent = '';
        f.status.className = 'kop-bug-reporter__status';
        f.submit.disabled = false;
        f.submit.textContent = 'Send report';
        refreshTechPreview();

        lastFocused = document.activeElement;
        modal.hidden = false;
        document.body.classList.add('kop-bug-reporter--modal-open');
        f.description.focus();
    }

    function closeModal() {
        if (!modal || modal.hidden) return;
        modal.hidden = true;
        document.body.classList.remove('kop-bug-reporter--modal-open');
        if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
    }

    function resetForm() {
        var f = modal._fields;
        f.description.value = '';
        f.steps.value = '';
        f.category.value = 'other';
        prefillContext = null;
        currentFeature = null;
        f.featureChip.hidden = true;
    }

    function submitReport() {
        var f = modal._fields;
        var description = f.description.value.trim();
        var includeTech = f.includeTech.checked;
        var snap = techSnapshot();

        var payload = {
            feature: currentFeature ? currentFeature.id : '',
            featureLabel: currentFeature ? currentFeature.label : '',
            category: f.category.value,
            description: description,
            steps: f.steps.value.trim(),
            contact: f.contact.value.trim(),
            website: f.honeypot.value,
            pageUrl: snap.url,
            pageTitle: document.title,
            viewport: includeTech ? snap.viewport : '',
            consoleErrors: includeTech ? snap.errors : [],
            context: includeTech && prefillContext ? prefillContext : null
        };

        f.submit.disabled = true;
        f.submit.textContent = 'Sending…';
        f.status.textContent = '';
        f.status.className = 'kop-bug-reporter__status';

        // Use the original fetch so our own wrapper doesn't log a failed
        // bug-report call and grow the payload next time.
        var doFetch = origFetch || window.fetch;
        doFetch.call(window, endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok || !data.success) {
                    throw new Error(data && data.error ? data.error : 'The report could not be sent (HTTP ' + response.status + ').');
                }
                return data;
            });
        }).then(function () {
            f.status.textContent = 'Thank you! Your report was sent.';
            f.status.className = 'kop-bug-reporter__status kop-bug-reporter__status--success';
            f.submit.textContent = 'Sent ✓';
            setTimeout(function () {
                resetForm();
                closeModal();
                f.submit.disabled = false;
                f.submit.textContent = 'Send report';
            }, 1600);
        }).catch(function (err) {
            f.submit.disabled = false;
            f.submit.textContent = 'Send report';
            var msg = (err && err.message) ? err.message : 'The report could not be sent.';
            f.status.className = 'kop-bug-reporter__status kop-bug-reporter__status--error';
            f.status.textContent = msg + ' ';
            if (fallbackEmail) {
                var mailto = document.createElement('a');
                mailto.href = 'mailto:' + fallbackEmail
                    + '?subject=' + encodeURIComponent('Bug report: ' + document.title)
                    + '&body=' + encodeURIComponent(description + '\n\nPage: ' + snap.url);
                mailto.textContent = 'Email it instead';
                f.status.appendChild(mailto);
            }
        });
    }

    // ---------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------
    window.KOPBugReporter = {
        open: openModal,
        attach: attach,
        captureError: function (label) { logEntry('app', label); }
    };

    function init() {
        scanForFeatures();
        watchForFeatures();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
