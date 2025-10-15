/**
 * Report Data Loading Test Script
 *
 * Enhances the diagnostics panel with context gathered from
 * kidsoverprofits_build_test_config() so editors can verify that the
 * correct scripts are enqueued and that JSON data sources are available.
 */

(function() {
    function createPanel() {
        const container = document.createElement('div');
        container.id = 'report-test-container';
        container.style.position = 'fixed';
        container.style.top = '10px';
        container.style.right = '10px';
        container.style.backgroundColor = 'rgba(255, 255, 255, 0.97)';
        container.style.border = '1px solid #cbd5f5';
        container.style.padding = '14px';
        container.style.zIndex = '9999';
        container.style.maxWidth = '420px';
        container.style.maxHeight = '85vh';
        container.style.overflowY = 'auto';
        container.style.fontSize = '12px';
        container.style.fontFamily = 'Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        container.style.boxShadow = '0 12px 30px rgba(30, 64, 175, 0.15)';
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
        title.textContent = 'Report Data Diagnostics';
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
        closeButton.style.color = '#1e3a8a';
        closeButton.addEventListener('click', function() {
            panel.remove();
        });
        header.appendChild(closeButton);

        return header;
    }

    function renderPageSummary(config) {
        const summary = document.createElement('div');
        summary.style.border = '1px solid #e0e7ff';
        summary.style.borderRadius = '8px';
        summary.style.padding = '8px';
        summary.style.background = '#eef2ff';
        summary.style.color = '#312e81';

        const page = config.page || {};
        const lines = [
            `<strong>Page:</strong> ${page.title || page.slug || 'Unknown'}${page.url ? ` — ${page.url}` : ''}`,
            `<strong>Generated:</strong> ${config.generatedAt || new Date().toLocaleString()}`
        ];

        summary.innerHTML = lines.map(function(line) {
            return `<div>${line}</div>`;
        }).join('');

        return summary;
    }

    function renderScriptStatus(scripts) {
        if (!Array.isArray(scripts) || !scripts.length) {
            return null;
        }

        const wrapper = document.createElement('div');
        wrapper.style.border = '1px solid #e2e8f0';
        wrapper.style.borderRadius = '8px';
        wrapper.style.padding = '8px';
        wrapper.style.background = '#ffffff';

        const heading = document.createElement('h4');
        heading.textContent = 'Script handles';
        heading.style.margin = '0 0 6px 0';
        heading.style.fontSize = '13px';
        heading.style.fontWeight = '600';
        wrapper.appendChild(heading);

        const list = document.createElement('ul');
        list.style.listStyle = 'none';
        list.style.padding = '0';
        list.style.margin = '0';

        scripts.forEach(function(script) {
            const li = document.createElement('li');
            li.style.padding = '4px 0';

            let icon = '⏭';
            let color = '#64748b';
            let note = 'Not enqueued';

            if (script.isEnqueued && script.expected) {
                icon = '✓';
                color = '#16a34a';
                note = 'Loaded';
            } else if (!script.isEnqueued && script.expected) {
                icon = '✗';
                color = '#dc2626';
                note = 'Missing';
            } else if (script.isEnqueued && !script.expected) {
                icon = 'ℹ';
                color = '#2563eb';
                note = 'Loaded (optional)';
            }

            li.innerHTML = `
                <span style="color: ${color}; font-weight: 700; margin-right: 6px;">${icon}</span>
                <strong>${script.label}</strong>
                <div style="margin-left: 18px; color: #475569;">
                    <div><code>${script.handle}</code>${script.source ? ` • ${script.source}` : ''}</div>
                    <div>${note}</div>
                </div>
            `;

            list.appendChild(li);
        });

        wrapper.appendChild(list);
        return wrapper;
    }

    function gatherJsonUrls(config, dataObject) {
        const urls = [];

        function pushUnique(value) {
            if (!value) {
                return;
            }
            if (!urls.includes(value)) {
                urls.push(value);
            }
        }

        if (dataObject) {
            if (Array.isArray(dataObject.jsonFileUrls)) {
                dataObject.jsonFileUrls.forEach(pushUnique);
            }
            if (Array.isArray(dataObject.jsonUrls)) {
                dataObject.jsonUrls.forEach(pushUnique);
            }
            if (dataObject.jsonDataUrl) {
                pushUnique(dataObject.jsonDataUrl);
            }
        }

        const localizations = Array.isArray(config.localizations) ? config.localizations : [];
        localizations.forEach(function(entry) {
            const data = entry.data || {};
            if (Array.isArray(data.jsonFileUrls)) {
                data.jsonFileUrls.forEach(pushUnique);
            }
            if (Array.isArray(data.jsonUrls)) {
                data.jsonUrls.forEach(pushUnique);
            }
            if (data.jsonDataUrl) {
                pushUnique(data.jsonDataUrl);
            }
        });

        return urls;
    }

    function renderLocalizationSummary(localizations) {
        if (!Array.isArray(localizations) || !localizations.length) {
            return null;
        }

        const wrapper = document.createElement('div');
        wrapper.style.border = '1px solid #e2e8f0';
        wrapper.style.borderRadius = '8px';
        wrapper.style.padding = '8px';
        wrapper.style.background = '#f9fafb';

        const heading = document.createElement('h4');
        heading.textContent = 'Localized data objects';
        heading.style.margin = '0 0 6px 0';
        heading.style.fontSize = '13px';
        heading.style.fontWeight = '600';
        wrapper.appendChild(heading);

        const list = document.createElement('ul');
        list.style.listStyle = 'none';
        list.style.padding = '0';
        list.style.margin = '0';

        localizations.forEach(function(entry) {
            const li = document.createElement('li');
            li.style.padding = '4px 0';
            const keySummary = entry.keys && entry.keys.length ? entry.keys.join(', ') : 'No keys detected';
            li.innerHTML = `
                <strong>${entry.name}</strong> <small style="color:#475569;">(${entry.handle})</small>
                <div style="margin-left: 16px; color: #475569;">Keys: ${keySummary}</div>
            `;
            list.appendChild(li);
        });

        wrapper.appendChild(list);
        return wrapper;
    }

    function detectActiveDataObject() {
        if (typeof myThemeData !== 'undefined' && myThemeData) {
            return { name: 'myThemeData', data: myThemeData };
        }
        if (typeof facilitiesConfig !== 'undefined' && facilitiesConfig) {
            return { name: 'facilitiesConfig', data: facilitiesConfig };
        }
        return { name: null, data: null };
    }

    function renderDataObjectSummary(active, urls) {
        const wrapper = document.createElement('div');
        wrapper.style.border = '1px solid #e2e8f0';
        wrapper.style.borderRadius = '8px';
        wrapper.style.padding = '8px';
        wrapper.style.background = '#ffffff';

        const heading = document.createElement('h4');
        heading.textContent = 'Data object';
        heading.style.margin = '0 0 6px 0';
        heading.style.fontSize = '13px';
        heading.style.fontWeight = '600';
        wrapper.appendChild(heading);

        const statusLine = document.createElement('div');
        statusLine.style.marginBottom = '6px';

        if (active.name) {
            statusLine.innerHTML = `<span style="color:#16a34a; font-weight:600;">✓</span> Found <code>${active.name}</code>`;
        } else {
            statusLine.innerHTML = `<span style="color:#dc2626; font-weight:600;">✗</span> No data object detected`;
        }

        wrapper.appendChild(statusLine);

        const urlList = document.createElement('ul');
        urlList.style.listStyle = 'none';
        urlList.style.padding = '0';
        urlList.style.margin = '0';

        if (urls.length) {
            urls.forEach(function(url) {
                const li = document.createElement('li');
                li.style.padding = '2px 0';
                li.innerHTML = `<code>${url}</code>`;
                urlList.appendChild(li);
            });
        } else {
            const li = document.createElement('li');
            li.textContent = 'No JSON URLs were discovered.';
            li.style.color = '#dc2626';
            urlList.appendChild(li);
        }

        wrapper.appendChild(urlList);
        return wrapper;
    }

    function testJsonUrl(url) {
        const result = { url: url, status: 'pending', message: '', detail: '' };

        return fetch(url, { cache: 'no-store' })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                let count = null;
                let hint = '';

                if (Array.isArray(data)) {
                    count = data.length;
                    hint = 'Array root';
                } else if (data && typeof data === 'object') {
                    if (Array.isArray(data.facilities)) {
                        count = data.facilities.length;
                        hint = 'facilities[]';
                    } else if (Array.isArray(data.reports)) {
                        count = data.reports.length;
                        hint = 'reports[]';
                    } else if (data.projects && typeof data.projects === 'object') {
                        count = Object.keys(data.projects).length;
                        hint = 'projects{}';
                    }
                }

                if (count === null) {
                    result.status = 'warning';
                    result.message = 'Loaded but structure is unfamiliar';
                } else {
                    result.status = 'success';
                    result.message = `Loaded (${count} items — ${hint})`;
                }

                const sample = JSON.stringify(data, null, 2);
                result.detail = sample.length > 400 ? sample.substring(0, 400) + '…' : sample;
                return result;
            })
            .catch(function(error) {
                result.status = 'error';
                result.message = error.message;
                return result;
            });
    }

    function renderResults(results, container) {
        const section = document.createElement('div');
        section.style.border = '1px solid #e2e8f0';
        section.style.borderRadius = '8px';
        section.style.padding = '8px';
        section.style.background = '#ffffff';

        const heading = document.createElement('h4');
        heading.textContent = 'JSON fetch results';
        heading.style.margin = '0 0 6px 0';
        heading.style.fontSize = '13px';
        heading.style.fontWeight = '600';
        section.appendChild(heading);

        const list = document.createElement('ul');
        list.style.listStyle = 'none';
        list.style.padding = '0';
        list.style.margin = '0';

        results.forEach(function(item) {
            const li = document.createElement('li');
            li.style.padding = '6px 0';

            let icon = '⏳';
            let color = '#2563eb';

            if (item.status === 'success') {
                icon = '✓';
                color = '#16a34a';
            } else if (item.status === 'warning') {
                icon = '⚠';
                color = '#f59e0b';
            } else if (item.status === 'error') {
                icon = '✗';
                color = '#dc2626';
            }

            li.innerHTML = `
                <span style="color:${color}; font-weight:700; margin-right:6px;">${icon}</span>
                <code>${item.url}</code>
                <div style="margin-left:18px; color:#475569;">${item.message}</div>
                ${item.detail ? `<pre style="margin:6px 0 0 18px; max-height:120px; overflow:auto; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:6px;">${item.detail}</pre>` : ''}
            `;

            list.appendChild(li);
        });

        section.appendChild(list);
        container.appendChild(section);
    }

    document.addEventListener('DOMContentLoaded', function() {
        const config = window.kidsOverProfitsTestConfig || {};
        const panel = createPanel();
        panel.appendChild(createHeader(panel));
        panel.appendChild(renderPageSummary(config));

        const scriptsSection = renderScriptStatus(config.scripts || []);
        if (scriptsSection) {
            panel.appendChild(scriptsSection);
        }

        const localizationSection = renderLocalizationSummary(config.localizations || []);
        if (localizationSection) {
            panel.appendChild(localizationSection);
        }

        const active = detectActiveDataObject();
        const urls = gatherJsonUrls(config, active.data);
        panel.appendChild(renderDataObjectSummary(active, urls));

        if (!urls.length) {
            const warning = document.createElement('div');
            warning.textContent = 'No JSON sources were found. Check localized data in functions.php.';
            warning.style.color = '#dc2626';
            warning.style.fontWeight = '600';
            panel.appendChild(warning);
            document.body.appendChild(panel);
            return;
        }

        document.body.appendChild(panel);

        Promise.all(urls.map(testJsonUrl)).then(function(results) {
            renderResults(results, panel);
        }).catch(function(error) {
            const failure = document.createElement('div');
            failure.textContent = 'Failed to execute JSON tests: ' + error.message;
            failure.style.color = '#dc2626';
            failure.style.fontWeight = '600';
            panel.appendChild(failure);
        });
    });
})();
