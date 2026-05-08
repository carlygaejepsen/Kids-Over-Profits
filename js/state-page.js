(function() {
    'use strict';

    const config = window.statePageConfig;
    if (!config || !config.apiUrl) return;

    const state = {
        data: null,
        inspectionData: null,
        inspectionFiltered: null
    };

    const $ = sel => document.querySelector(sel);
    const $$ = sel => Array.from(document.querySelectorAll(sel));

    const escapeHtml = value => {
        if (value == null) return '';
        return String(value).replace(/[&<>"']/g, ch => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[ch]);
    };

    const formatDate = value => {
        if (!value) return '';
        const ts = Date.parse(value);
        if (isNaN(ts)) return value;
        return new Date(ts).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    };

    const folderContentCache = new Map();
    const fetchFolderContent = async folderId => {
        if (!folderId || !config.folderContentUrl) return [];
        const cacheKey = String(folderId);
        if (folderContentCache.has(cacheKey)) return folderContentCache.get(cacheKey);
        try {
            const res = await fetch(`${config.folderContentUrl}?id=${encodeURIComponent(folderId)}`, { credentials: 'same-origin' });
            const data = await res.json();
            const files = Array.isArray(data) ? data : [];
            folderContentCache.set(cacheKey, files);
            return files;
        } catch (err) {
            console.warn('Folder content load failed', err);
            return [];
        }
    };

    const renderFileGrid = files => {
        if (!Array.isArray(files) || files.length === 0) {
            return '<p class="docs-empty">No documents in this folder.</p>';
        }
        return `<ul class="state-doc-grid">${files.map(file => {
            const title = escapeHtml(file.title || 'Document');
            const url = escapeHtml(file.url || '#');
            const mime = String(file.mime_type || '');
            const isImage = mime.startsWith('image/');
            const ext = (file.url || '').split('.').pop().split('?')[0].split('#')[0].toUpperCase().slice(0, 4) || 'FILE';
            const thumb = file.thumb_url || (isImage ? file.url : '');
            return `
                <li class="state-doc-item">
                    <a href="${url}" target="_blank" rel="noopener">
                        ${thumb
                            ? `<img class="state-doc-thumb" src="${escapeHtml(thumb)}" alt="${title}">`
                            : `<span class="state-doc-icon">${escapeHtml(ext)}</span>`}
                        <span class="state-doc-title">${title}</span>
                    </a>
                </li>`;
        }).join('')}</ul>`;
    };

    const wireDocToggles = container => {
        container.querySelectorAll('.docs-toggle').forEach(btn => {
            btn.addEventListener('click', async () => {
                const folderId = btn.dataset.folderId;
                const target = btn.nextElementSibling;
                if (!target) return;
                if (target.dataset.loaded === '1') {
                    target.hidden = !target.hidden;
                    btn.textContent = target.hidden
                        ? `📂 Show documents (${btn.dataset.count})`
                        : '📂 Hide documents';
                    return;
                }
                btn.disabled = true;
                btn.textContent = 'Loading documents…';
                const files = await fetchFolderContent(folderId);
                target.innerHTML = renderFileGrid(files);
                target.dataset.loaded = '1';
                target.hidden = false;
                btn.textContent = '📂 Hide documents';
                btn.disabled = false;
            });
        });
    };

    const init = async () => {
        wireTabs();
        try {
            const res = await fetch(config.apiUrl, { credentials: 'same-origin' });
            const data = await res.json();
            state.data = data;
            updateCounts();
            renderPrograms();
            renderInspections();
            renderNews();
            renderLawsuits();
            renderLegislation();
        } catch (err) {
            console.error('Failed to load state data', err);
            $$('.section-content').forEach(el => {
                el.innerHTML = '<p class="error">Failed to load data. Please try again.</p>';
            });
        }
    };

    const wireTabs = () => {
        $$('.state-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const target = tab.dataset.tab;
                $$('.state-tab').forEach(t => {
                    t.classList.toggle('active', t === tab);
                    t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                });
                $$('.state-section').forEach(section => {
                    const isMatch = section.dataset.section === target;
                    section.classList.toggle('active', isMatch);
                    section.hidden = !isMatch;
                });
            });
        });
    };

    const updateCounts = () => {
        const counts = state.data.counts || {};
        const inspections = state.data.inspections || {};
        const map = {
            programs: counts.programs ?? 0,
            inspections: inspections.has_reports ? 'available' : 'none',
            news: counts.news ?? 0,
            lawsuits: counts.lawsuits ?? 0,
            legislation: counts.legislation ?? 0
        };
        Object.entries(map).forEach(([section, value]) => {
            const pill = document.querySelector(`.count-pill[data-section="${section}"] em`);
            if (pill) pill.textContent = value;
        });

        // Hide tabs/sections that have nothing.
        const isEmpty = section => {
            if (section === 'inspections') return !inspections.has_reports;
            return (counts[section] ?? 0) === 0;
        };
        ['programs', 'inspections', 'news', 'lawsuits', 'legislation'].forEach(section => {
            const tab = document.querySelector(`.state-tab[data-tab="${section}"]`);
            const pill = document.querySelector(`.count-pill[data-section="${section}"]`);
            if (isEmpty(section)) {
                if (tab) tab.style.display = 'none';
                if (pill) pill.style.display = 'none';
                const sec = document.getElementById(`section-${section}`);
                if (sec) sec.hidden = true;
            }
        });

        // Make sure the first visible tab is active.
        const firstVisibleTab = $$('.state-tab').find(t => t.style.display !== 'none');
        if (firstVisibleTab && !firstVisibleTab.classList.contains('active')) {
            firstVisibleTab.click();
        }
    };

    // ---------- Programs ----------
    const renderPrograms = () => {
        const container = document.querySelector('#section-programs .section-content');
        const programs = state.data.programs || [];
        if (programs.length === 0) {
            container.innerHTML = '<p class="empty">No programs on file for this state yet.</p>';
            return;
        }
        const items = programs.map(p => `
            <li class="program-item">
                <div class="program-name">${escapeHtml(p.facility_name || p.project_name)}</div>
                <div class="program-meta">
                    ${p.operator_name ? `<span class="op">Operator: ${escapeHtml(p.operator_name)}</span>` : ''}
                    ${p.city ? `<span>${escapeHtml(p.city)}, ${escapeHtml(p.state)}</span>` : ''}
                    ${p.type ? `<span class="type">${escapeHtml(p.type)}</span>` : ''}
                    ${p.status ? `<span class="status status-${escapeHtml(String(p.status).toLowerCase())}">${escapeHtml(p.status)}</span>` : ''}
                </div>
            </li>
        `).join('');
        container.innerHTML = `
            <input type="text" class="section-search" id="programSearch" placeholder="Search programs...">
            <ul class="program-list" id="programList">${items}</ul>
        `;
        const search = container.querySelector('#programSearch');
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            container.querySelectorAll('.program-item').forEach(li => {
                li.style.display = li.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    };

    // ---------- Inspections ----------
    const renderInspections = async () => {
        const container = document.querySelector('#section-inspections .section-content');
        const inspections = state.data.inspections || {};

        if (!inspections.has_reports) {
            container.innerHTML = '<p class="empty">No inspection reports are available for this state.</p>';
            return;
        }

        container.innerHTML = `
            <div class="inspections-search-area">
                <input type="text" class="section-search" id="inspectionSearch" placeholder="Search inspection reports by facility name…">
                <a href="${escapeHtml(inspections.page_url)}" class="full-page-link">View full inspection reports →</a>
            </div>
            <div id="inspectionResults" class="inspection-results">
                <p class="loading">Loading inspection data…</p>
            </div>
        `;

        try {
            const datasets = inspections.dataset_urls || [];
            if (datasets.length === 0) {
                container.querySelector('#inspectionResults').innerHTML =
                    '<p class="empty">Inspection page is available but data could not be loaded inline.</p>';
                return;
            }
            const responses = await Promise.all(datasets.map(url =>
                fetch(url, { credentials: 'same-origin' }).then(r => r.ok ? r.json() : null).catch(() => null)
            ));

            const merged = mergeInspectionDatasets(responses.filter(Boolean));
            state.inspectionData = merged;
            state.inspectionFiltered = merged;
            renderInspectionResults('');

            container.querySelector('#inspectionSearch').addEventListener('input', e => {
                renderInspectionResults(e.target.value.trim().toLowerCase());
            });
        } catch (err) {
            console.error('Inspection load failed', err);
            container.querySelector('#inspectionResults').innerHTML =
                '<p class="error">Could not load inspection data inline. Use the link above to view the full reports page.</p>';
        }
    };

    const mergeInspectionDatasets = datasets => {
        const facilities = new Map();
        datasets.forEach(ds => {
            const list = Array.isArray(ds) ? ds : (ds && Array.isArray(ds.facilities) ? ds.facilities : []);
            list.forEach(facility => {
                if (!facility || typeof facility !== 'object') return;
                const name = facility.facility_name || facility.name || '';
                const address = facility.facility_address || facility.address || '';
                const key = (facility.facility_id || (name + '|' + address)).toString().toLowerCase();
                if (!facilities.has(key)) {
                    facilities.set(key, {
                        name,
                        address,
                        inspectionCount: Array.isArray(facility.inspections) ? facility.inspections.length : 0,
                        violationCount: countViolations(facility),
                        latest: getLatestInspection(facility)
                    });
                }
            });
        });
        return Array.from(facilities.values()).sort((a, b) =>
            (a.name || '').localeCompare(b.name || '')
        );
    };

    const countViolations = facility => {
        if (!Array.isArray(facility.inspections)) return 0;
        let total = 0;
        facility.inspections.forEach(inspection => {
            if (Array.isArray(inspection.inspection_findings)) {
                total += inspection.inspection_findings.length;
            } else if (Array.isArray(inspection.findings)) {
                total += inspection.findings.length;
            }
        });
        return total;
    };

    const getLatestInspection = facility => {
        if (!Array.isArray(facility.inspections) || facility.inspections.length === 0) return null;
        let best = null;
        let bestTs = 0;
        facility.inspections.forEach(insp => {
            const dateStr = insp.inspection_date || insp.date || insp.report_date || '';
            const ts = Date.parse(dateStr) || 0;
            if (ts > bestTs) {
                bestTs = ts;
                best = { date: dateStr, type: insp.inspection_type || insp.type || '' };
            }
        });
        return best;
    };

    const renderInspectionResults = query => {
        const container = document.getElementById('inspectionResults');
        if (!container) return;
        const data = state.inspectionData || [];
        const filtered = query
            ? data.filter(f =>
                (f.name || '').toLowerCase().includes(query) ||
                (f.address || '').toLowerCase().includes(query)
              )
            : data;

        if (filtered.length === 0) {
            container.innerHTML = '<p class="empty">No matching facilities.</p>';
            return;
        }

        const top = filtered.slice(0, 50);
        container.innerHTML = `
            <p class="result-count">${filtered.length} facilit${filtered.length === 1 ? 'y' : 'ies'} ${query ? 'matched' : 'on file'}${filtered.length > 50 ? ' (showing first 50)' : ''}.</p>
            <ul class="inspection-list">
                ${top.map(f => `
                    <li class="inspection-item">
                        <div class="facility-name">${escapeHtml(f.name || 'Unnamed facility')}</div>
                        ${f.address ? `<div class="facility-address">${escapeHtml(f.address)}</div>` : ''}
                        <div class="facility-stats">
                            <span>${f.inspectionCount} inspection${f.inspectionCount === 1 ? '' : 's'}</span>
                            <span>${f.violationCount} violation${f.violationCount === 1 ? '' : 's'}</span>
                            ${f.latest ? `<span>Latest: ${escapeHtml(f.latest.date)}</span>` : ''}
                        </div>
                    </li>
                `).join('')}
            </ul>
        `;
    };

    // ---------- News ----------
    const submitNewsButton = () => {
        if (!config.newsSubmitUrl) return '';
        const url = `${config.newsSubmitUrl}${config.newsSubmitUrl.includes('?') ? '&' : '?'}prefill_state=${encodeURIComponent(config.stateName)}`;
        return `<a href="${escapeHtml(url)}" class="submit-news-btn">+ Submit news for ${escapeHtml(config.stateName)}</a>`;
    };

    const renderNews = () => {
        const container = document.querySelector('#section-news .section-content');
        const news = state.data.news || [];
        const submitBtn = submitNewsButton();
        if (news.length === 0) {
            container.innerHTML = `
                <p class="empty">No news items tagged for this state.</p>
                ${submitBtn}
            `;
            return;
        }
        container.innerHTML = `
            <div class="news-toolbar">
                <input type="text" class="section-search" id="newsSearch" placeholder="Search news...">
                ${submitBtn}
            </div>
            <ul class="news-list">
                ${news.map(n => `
                    <li class="news-item">
                        <div class="news-item-header">
                            <span class="news-type-badge type-${escapeHtml(n.article_type)}">${escapeHtml(n.article_type)}</span>
                            <a href="${escapeHtml(n.article_url)}" target="_blank" rel="noopener" class="news-title">
                                ${escapeHtml(n.display_title || n.article_title)}
                            </a>
                        </div>
                        <div class="news-meta">
                            ${n.author ? escapeHtml(n.author) + ' · ' : ''}
                            ${n.publication_name ? escapeHtml(n.publication_name) + ' · ' : ''}
                            ${formatDate(n.publication_date)}
                        </div>
                        ${n.summary ? `<p class="news-summary">${escapeHtml(n.summary)}</p>` : ''}
                    </li>
                `).join('')}
            </ul>
        `;
        const search = container.querySelector('#newsSearch');
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            container.querySelectorAll('.news-item').forEach(li => {
                li.style.display = li.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    };

    // ---------- Lawsuits ----------
    const renderLawsuits = () => {
        const container = document.querySelector('#section-lawsuits .section-content');
        const lawsuits = state.data.lawsuits || [];
        if (lawsuits.length === 0) {
            container.innerHTML = '<p class="empty">No lawsuits on record for this state yet.</p>';
            return;
        }
        container.innerHTML = `
            <input type="text" class="section-search" id="lawsuitSearch" placeholder="Search lawsuits...">
            <ul class="lawsuit-list">
                ${lawsuits.map(l => `
                    <li class="lawsuit-item">
                        <div class="lawsuit-header">
                            <h3 class="lawsuit-name">${escapeHtml(l.case_name)}</h3>
                            <span class="status-pill status-${escapeHtml(l.status)}">${escapeHtml(l.status)}</span>
                        </div>
                        <div class="lawsuit-meta">
                            ${l.case_number ? `<span>${escapeHtml(l.case_number)}</span>` : ''}
                            ${l.court ? `<span>${escapeHtml(l.court)}</span>` : ''}
                            ${l.filing_date ? `<span>Filed ${formatDate(l.filing_date)}</span>` : ''}
                        </div>
                        ${(l.facilities_mentioned || []).length ? `<div class="lawsuit-facilities">Facilities: ${l.facilities_mentioned.map(escapeHtml).join(', ')}</div>` : ''}
                        ${l.summary ? `<p class="lawsuit-summary">${escapeHtml(l.summary)}</p>` : ''}
                        ${(l.source_urls || []).length ? `
                            <div class="lawsuit-sources">
                                ${l.source_urls.map(url => `<a href="${escapeHtml(url)}" target="_blank" rel="noopener">${escapeHtml(url)}</a>`).join(' · ')}
                            </div>` : ''}
                        ${l.filebird_folder_id ? `
                            <div class="docs-block">
                                <button type="button" class="docs-toggle" data-folder-id="${escapeHtml(String(l.filebird_folder_id))}" data-count="">📂 Show documents</button>
                                <div class="docs-target" hidden></div>
                            </div>` : ''}
                    </li>
                `).join('')}
            </ul>
        `;
        wireDocToggles(container);
        const search = container.querySelector('#lawsuitSearch');
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            container.querySelectorAll('.lawsuit-item').forEach(li => {
                li.style.display = li.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    };

    // ---------- Legislation ----------
    const renderLegislation = () => {
        const container = document.querySelector('#section-legislation .section-content');
        const bills = state.data.legislation || [];
        if (bills.length === 0) {
            container.innerHTML = '<p class="empty">No legislation on record for this state yet.</p>';
            return;
        }
        container.innerHTML = `
            <input type="text" class="section-search" id="legislationSearch" placeholder="Search legislation...">
            <ul class="legislation-list">
                ${bills.map(b => `
                    <li class="legislation-item">
                        <div class="legislation-header">
                            <h3 class="legislation-name">
                                ${b.bill_number ? `<span class="bill-number">${escapeHtml(b.bill_number)}</span>` : ''}
                                ${escapeHtml(b.bill_title)}
                            </h3>
                            <span class="status-pill status-${escapeHtml(b.status)}">${escapeHtml(b.status)}</span>
                            ${b.position && b.position !== 'unknown' ? `<span class="position-pill position-${escapeHtml(b.position)}">${escapeHtml(b.position)}</span>` : ''}
                        </div>
                        <div class="legislation-meta">
                            ${b.session_year ? `<span>${escapeHtml(b.session_year)}</span>` : ''}
                            ${b.chamber && b.chamber !== 'unknown' ? `<span>${escapeHtml(b.chamber.replace('_', ' '))}</span>` : ''}
                            ${b.last_action_date ? `<span>Last action ${formatDate(b.last_action_date)}</span>` : ''}
                        </div>
                        ${(b.sponsors || []).length ? `<div class="legislation-sponsors">Sponsors: ${b.sponsors.map(escapeHtml).join(', ')}</div>` : ''}
                        ${b.summary ? `<p class="legislation-summary">${escapeHtml(b.summary)}</p>` : ''}
                        <div class="legislation-links">
                            ${b.official_url ? `<a href="${escapeHtml(b.official_url)}" target="_blank" rel="noopener">Tracker</a>` : ''}
                            ${b.full_text_url ? `<a href="${escapeHtml(b.full_text_url)}" target="_blank" rel="noopener">Full text</a>` : ''}
                        </div>
                        ${b.filebird_folder_id ? `
                            <div class="docs-block">
                                <button type="button" class="docs-toggle" data-folder-id="${escapeHtml(String(b.filebird_folder_id))}" data-count="">📂 Show documents</button>
                                <div class="docs-target" hidden></div>
                            </div>` : ''}
                    </li>
                `).join('')}
            </ul>
        `;
        wireDocToggles(container);
        const search = container.querySelector('#legislationSearch');
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            container.querySelectorAll('.legislation-item').forEach(li => {
                li.style.display = li.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
