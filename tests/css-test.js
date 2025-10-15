/**
 * CSS Loading Test Script
 *
 * Uses the configuration provided by kidsoverprofits_build_test_config()
 * to verify that the expected stylesheets are enqueued and that the
 * selectors defined for each stylesheet expose the anticipated styles.
 */

(function() {
    function createPanel() {
        const container = document.createElement('div');
        container.id = 'css-test-container';
        container.style.position = 'fixed';
        container.style.top = '10px';
        container.style.right = '10px';
        container.style.backgroundColor = 'rgba(255, 255, 255, 0.95)';
        container.style.border = '1px solid #d1d5db';
        container.style.padding = '12px';
        container.style.zIndex = '9999';
        container.style.maxWidth = '360px';
        container.style.maxHeight = '85vh';
        container.style.overflowY = 'auto';
        container.style.fontSize = '12px';
        container.style.fontFamily = 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        container.style.boxShadow = '0 10px 25px rgba(15, 23, 42, 0.15)';
        container.style.borderRadius = '10px';
        container.style.display = 'flex';
        container.style.flexDirection = 'column';
        container.style.gap = '10px';
        return container;
    }

    function createHeader(panel) {
        const header = document.createElement('div');
        header.style.display = 'flex';
        header.style.justifyContent = 'space-between';
        header.style.alignItems = 'center';

        const title = document.createElement('h3');
        title.textContent = 'CSS Loading Diagnostics';
        title.style.margin = '0';
        title.style.fontSize = '14px';
        title.style.fontWeight = '600';
        header.appendChild(title);

        const closeButton = document.createElement('button');
        closeButton.textContent = '×';
        closeButton.style.background = 'transparent';
        closeButton.style.border = 'none';
        closeButton.style.fontSize = '18px';
        closeButton.style.cursor = 'pointer';
        closeButton.style.lineHeight = '1';
        closeButton.style.color = '#334155';
        closeButton.addEventListener('click', function() {
            panel.remove();
        });
        header.appendChild(closeButton);

        return header;
    }

    function renderSummary(config, evaluations) {
        const summary = document.createElement('div');
        summary.style.border = '1px solid #e2e8f0';
        summary.style.borderRadius = '8px';
        summary.style.padding = '8px';
        summary.style.background = '#f8fafc';

        const page = config.page || {};
        const counts = { pass: 0, error: 0, info: 0, skipped: 0 };

        evaluations.forEach(function(evalResult) {
            if (counts.hasOwnProperty(evalResult.status)) {
                counts[evalResult.status] += 1;
            }
        });

        const summaryLines = [
            `<strong>Page:</strong> ${page.title || page.slug || 'Unknown'}${page.url ? ` — ${page.url}` : ''}`,
            `<strong>Generated:</strong> ${config.generatedAt || new Date().toLocaleString()}`,
            `<strong>Results:</strong> ✅ ${counts.pass} • ❌ ${counts.error} • ℹ️ ${counts.info} • ⏭ ${counts.skipped}`
        ];

        summary.innerHTML = summaryLines.map(function(line) {
            return `<div>${line}</div>`;
        }).join('');

        return summary;
    }

    function evaluateSelector(check) {
        const result = {
            description: check.description || `${check.selector} (${check.property})`,
            selector: check.selector,
            property: check.property,
            value: '',
            message: '',
            passed: false,
        };

        const sandbox = document.createElement('div');
        sandbox.style.position = 'absolute';
        sandbox.style.left = '-9999px';
        sandbox.style.top = '0';
        sandbox.style.width = '0';
        sandbox.style.height = '0';
        sandbox.style.overflow = 'hidden';

        document.body.appendChild(sandbox);

        try {
            if (check.markup) {
                sandbox.innerHTML = check.markup;
            } else {
                const fallbackElement = document.createElement('div');
                if (check.selector && check.selector.startsWith('.')) {
                    fallbackElement.className = check.selector.replace('.', '').trim();
                }
                sandbox.appendChild(fallbackElement);
            }

            const element = sandbox.querySelector(check.selector);

            if (!element) {
                result.message = 'Selector not present in test markup';
                return result;
            }

            const computed = window.getComputedStyle(element);
            const value = computed ? computed.getPropertyValue(check.property) : '';
            const trimmedValue = typeof value === 'string' ? value.trim() : '';

            result.value = trimmedValue;

            if (check.expectedValue !== undefined) {
                result.passed = trimmedValue === check.expectedValue;
                result.message = result.passed ? 'Matches expected value' : `Expected ${check.expectedValue}`;
                return result;
            }

            if (check.valueContains) {
                result.passed = typeof trimmedValue === 'string' && trimmedValue.indexOf(check.valueContains) !== -1;
                result.message = result.passed ? 'Contains expected fragment' : `Missing fragment "${check.valueContains}"`;
                return result;
            }

            const invalidValues = Array.isArray(check.invalidValues) ? check.invalidValues : [''];
            result.passed = !invalidValues.includes(trimmedValue);
            result.message = result.passed ? 'Value looks valid' : `Invalid value "${trimmedValue}"`;
            return result;
        } catch (error) {
            result.message = `Error: ${error.message}`;
            result.passed = false;
            return result;
        } finally {
            document.body.removeChild(sandbox);
        }
    }

    function evaluateTarget(target) {
        const evaluation = {
            target: target,
            checks: [],
            status: 'skipped',
            message: '',
            statusDetails: {},
        };

        const fallbackStatus = {
            isEnqueued: typeof target.isEnqueued === 'boolean' ? target.isEnqueued : undefined,
            expected: typeof target.expected === 'boolean' ? target.expected : undefined,
        };

        const rawStatus = target.status && typeof target.status === 'object' ? target.status : {};
        const status = Object.assign({}, fallbackStatus, rawStatus);

        status.isEnqueued = Boolean(status.isEnqueued);
        status.expected = Boolean(status.expected);

        const selectors = Array.isArray(target.selectors)
            ? target.selectors
            : (Array.isArray(target.selectorTests) ? target.selectorTests : []);

        evaluation.statusDetails = status;

        if (!status.isEnqueued && !status.expected) {
            evaluation.status = 'skipped';
            evaluation.message = 'Stylesheet not enqueued for this page.';
            return evaluation;
        }

        if (!status.isEnqueued && status.expected) {
            evaluation.status = 'error';
            evaluation.message = 'Expected stylesheet is missing.';
            return evaluation;
        }

        evaluation.checks = selectors.map(evaluateSelector);

        if (!selectors.length) {
            if (status.expected) {
                evaluation.status = 'pass';
                evaluation.message = 'Stylesheet enqueued; no selector checks configured.';
            } else {
                evaluation.status = 'info';
                evaluation.message = 'Stylesheet loaded (not explicitly required).';
            }
            return evaluation;
        }

        const allPassed = evaluation.checks.every(function(check) { return check.passed; });

        if (allPassed) {
            evaluation.status = status.expected ? 'pass' : 'info';
            evaluation.message = status.expected ? 'All selector checks passed.' : 'Stylesheet loaded (not specifically required).';
        } else {
            evaluation.status = 'error';
            evaluation.message = 'One or more selector checks failed.';
        }

        return evaluation;
    }

    function renderTargetResult(evaluation) {
        const listItem = document.createElement('li');
        listItem.style.border = '1px solid #e2e8f0';
        listItem.style.borderRadius = '8px';
        listItem.style.padding = '8px';
        listItem.style.marginBottom = '8px';
        listItem.style.background = '#ffffff';

        const statusIcon = evaluation.status === 'pass' ? '✓' : (evaluation.status === 'error' ? '✗' : (evaluation.status === 'info' ? 'ℹ' : '⏭'));
        const statusColor = evaluation.status === 'pass' ? '#16a34a' : (evaluation.status === 'error' ? '#dc2626' : (evaluation.status === 'info' ? '#2563eb' : '#64748b'));

        const header = document.createElement('div');
        header.style.display = 'flex';
        header.style.flexDirection = 'column';
        header.style.gap = '4px';

        const title = document.createElement('div');
        title.innerHTML = `
            <span style="color: ${statusColor}; font-weight: 700; margin-right: 6px;">${statusIcon}</span>
            <strong>${evaluation.target.label}</strong>
            <small style="color: #475569;">(${evaluation.target.handle}${evaluation.target.source ? ` • ${evaluation.target.source}` : ''})</small>
        `;
        header.appendChild(title);

        const statusDetails = evaluation.statusDetails || {};

        const metadata = document.createElement('div');
        metadata.style.color = '#475569';
        metadata.innerHTML = [
            `<strong>Expected:</strong> ${statusDetails.expected ? 'Yes' : 'No'}`,
            `<strong>Enqueued:</strong> ${statusDetails.isEnqueued ? 'Yes' : 'No'}`,
            selectorsSummary(evaluation.checks)
        ].filter(Boolean).join(' • ');
        header.appendChild(metadata);

        const message = document.createElement('div');
        message.textContent = evaluation.message;
        message.style.color = statusColor;
        header.appendChild(message);

        listItem.appendChild(header);

        if (evaluation.checks.length) {
            const checksList = document.createElement('ul');
            checksList.style.listStyle = 'none';
            checksList.style.padding = '0';
            checksList.style.margin = '6px 0 0 0';
            checksList.style.borderTop = '1px solid #e2e8f0';
            checksList.style.paddingTop = '6px';

            evaluation.checks.forEach(function(check) {
                const checkItem = document.createElement('li');
                const checkIcon = check.passed ? '✓' : '✗';
                const checkColor = check.passed ? '#16a34a' : '#dc2626';

                checkItem.innerHTML = `
                    <span style="color: ${checkColor}; font-weight: 600; margin-right: 4px;">${checkIcon}</span>
                    <strong>${check.description}</strong>
                    <div style="margin-left: 16px; color: #475569;">
                        <div><code>${check.selector}</code> • <code>${check.property}</code> → <code>${check.value || 'n/a'}</code></div>
                        <div>${check.message}</div>
                    </div>
                `;

                checksList.appendChild(checkItem);
            });

            listItem.appendChild(checksList);
        }

        return listItem;
    }

    function selectorsSummary(checks) {
        if (!Array.isArray(checks) || !checks.length) {
            return '';
        }

        const passed = checks.filter(function(check) { return check.passed; }).length;
        return `<strong>Selector checks:</strong> ${passed}/${checks.length}`;
    }

    document.addEventListener('DOMContentLoaded', function() {
        const config = window.kidsOverProfitsTestConfig || {};
        const cssTargets = Array.isArray(config.css) ? config.css : [];

        const panel = createPanel();
        panel.appendChild(createHeader(panel));

        if (!cssTargets.length) {
            const empty = document.createElement('div');
            empty.textContent = 'No CSS handles were provided for testing. Ensure kidsoverprofits_build_test_config() is running.';
            empty.style.color = '#dc2626';
            panel.appendChild(empty);
            document.body.appendChild(panel);
            return;
        }

        const evaluations = cssTargets.map(evaluateTarget);
        panel.appendChild(renderSummary(config, evaluations));

        const list = document.createElement('ul');
        list.style.listStyle = 'none';
        list.style.padding = '0';
        list.style.margin = '0';

        evaluations.forEach(function(evaluation) {
            list.appendChild(renderTargetResult(evaluation));
        });

        panel.appendChild(list);
        document.body.appendChild(panel);
    });
})();
