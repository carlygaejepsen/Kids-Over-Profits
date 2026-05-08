(function() {
    'use strict';

    const config = window.statePageConfig;
    if (!config || !config.apiUrl) return;

    const state = {
        data: null
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
            renderFacilities();
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
        const map = {
            facilities: counts.facilities_total ?? 0,
            news: counts.news ?? 0,
            lawsuits: counts.lawsuits ?? 0,
            legislation: counts.legislation ?? 0
        };
        Object.entries(map).forEach(([section, value]) => {
            const pill = document.querySelector(`.count-pill[data-section="${section}"] em`);
            if (pill) pill.textContent = value;
        });

        const isEmpty = section => (map[section] ?? 0) === 0;
        ['facilities', 'news', 'lawsuits', 'legislation'].forEach(section => {
            const tab = document.querySelector(`.state-tab[data-tab="${section}"]`);
            const pill = document.querySelector(`.count-pill[data-section="${section}"]`);
            if (isEmpty(section)) {
                if (tab) tab.style.display = 'none';
                if (pill) pill.style.display = 'none';
                const sec = document.getElementById(`section-${section}`);
                if (sec) sec.hidden = true;
            }
        });

        const firstVisibleTab = $$('.state-tab').find(t => t.style.display !== 'none');
        if (firstVisibleTab && !firstVisibleTab.classList.contains('active')) {
            firstVisibleTab.click();
        }
    };

    // ---------- Facilities (merged Programs + Inspections) ----------
    const facilityCardHtml = facility => {
        const stats = [];
        if (facility.inspection_count > 0) {
            stats.push(`<span class="stat">${facility.inspection_count} inspection${facility.inspection_count === 1 ? '' : 's'}</span>`);
        }
        if (facility.violation_count > 0) {
            stats.push(`<span class="stat stat-violation">${facility.violation_count} violation${facility.violation_count === 1 ? '' : 's'}</span>`);
        }
        if (facility.latest_inspection_date) {
            stats.push(`<span class="stat">Latest: ${escapeHtml(facility.latest_inspection_date)}</span>`);
        }
        if (facility.type) {
            stats.push(`<span class="stat">${escapeHtml(facility.type)}</span>`);
        }
        if (facility.operating_period) {
            stats.push(`<span class="stat">${escapeHtml(facility.operating_period)}</span>`);
        }

        return `
            <li class="facility-card">
                <div class="facility-card-header">
                    <h3 class="facility-card-name">${escapeHtml(facility.name)}</h3>
                    ${facility.status ? `<span class="status-pill status-${escapeHtml(String(facility.status).toLowerCase())}">${escapeHtml(facility.status)}</span>` : ''}
                </div>
                ${facility.address ? `<div class="facility-card-address">${escapeHtml(facility.address)}</div>` : ''}
                ${facility.operator_name ? `<div class="facility-card-operator">Operator: ${escapeHtml(facility.operator_name)}</div>` : ''}
                ${stats.length ? `<div class="facility-card-stats">${stats.join('')}</div>` : ''}
            </li>
        `;
    };

    const renderFacilities = () => {
        const container = document.querySelector('#section-facilities .section-content');
        const facilities = state.data.facilities || { active: [], closed: [], total: 0 };
        const inspections = state.data.inspections || {};

        if ((facilities.total ?? 0) === 0) {
            container.innerHTML = '<p class="empty">No facilities on file for this state yet.</p>';
            return;
        }

        const inspectionsLink = inspections.has_reports
            ? `<a href="${escapeHtml(inspections.page_url)}" class="full-page-link">View full inspection report search →</a>`
            : '';

        const activeHtml = facilities.active.length
            ? `<ul class="facility-list">${facilities.active.map(facilityCardHtml).join('')}</ul>`
            : '<p class="empty">No active facilities listed.</p>';

        const closedHtml = facilities.closed.length
            ? `<ul class="facility-list closed-list">${facilities.closed.map(facilityCardHtml).join('')}</ul>`
            : '';

        container.innerHTML = `
            <div class="facility-toolbar">
                <input type="text" class="section-search" id="facilitySearch" placeholder="Search facilities...">
                ${inspectionsLink}
            </div>

            <div class="facility-group facility-group-active">
                <h3 class="facility-group-heading">
                    Active <span class="count">(${facilities.active.length})</span>
                </h3>
                ${activeHtml}
            </div>

            ${facilities.closed.length ? `
                <div class="facility-group facility-group-closed">
                    <h3 class="facility-group-heading">
                        Closed <span class="count">(${facilities.closed.length})</span>
                    </h3>
                    ${closedHtml}
                </div>
            ` : ''}
        `;

        const search = container.querySelector('#facilitySearch');
        search.addEventListener('input', () => {
            const q = search.value.trim().toLowerCase();
            container.querySelectorAll('.facility-card').forEach(card => {
                card.style.display = card.textContent.toLowerCase().includes(q) ? '' : 'none';
            });
            // Hide group heading if all cards within are hidden.
            container.querySelectorAll('.facility-group').forEach(group => {
                const visible = group.querySelectorAll('.facility-card:not([style*="display: none"])').length;
                group.style.display = visible === 0 ? 'none' : '';
            });
        });
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
