/**
 * Admin: Linked News Articles panel for the facility/project editor.
 *
 * Watches the #project-name input. When the project name matches a row in
 * facilities_master (lookup via api/facility-search.php), enables the link
 * controls and renders existing links from api/link-news-to-facility.php.
 *
 * Adding/removing links hits api/link-news-to-facility.php (POST) and
 * api/unlink-news-from-facility.php (POST).
 */
(function () {
    'use strict';

    const settings = window.KOP_AdminNewsLinks || {};
    const endpoints = {
        facilitySearch: settings.facilitySearchUrl || '/wp-content/themes/child/api/facility-search.php',
        newsSearch:     settings.newsSearchUrl     || '/wp-content/themes/child/api/news-search.php',
        link:           settings.linkUrl           || '/wp-content/themes/child/api/link-news-to-facility.php',
        unlink:         settings.unlinkUrl         || '/wp-content/themes/child/api/unlink-news-from-facility.php',
    };

    let currentFacilityId = null;
    let currentProjectName = '';
    let resolveTimer = null;

    function $(id) { return document.getElementById(id); }

    function escapeHtml(value) {
        if (value == null) return '';
        return String(value).replace(/[&<>"']/g, ch => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[ch]);
    }

    function formatDate(value) {
        if (!value) return '';
        const ts = Date.parse(value);
        if (isNaN(ts)) return value;
        return new Date(ts).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function setStatus(text) {
        const el = $('linked-news-status');
        if (el) el.textContent = text;
    }

    function renderEmpty() {
        const list = $('linked-news-list');
        if (list) {
            list.innerHTML = '<div style="color:#6b7280; font-style:italic; padding:10px; background:#f9fafb; border-radius:4px;">No articles linked yet.</div>';
        }
    }

    function renderLinks(items) {
        const list = $('linked-news-list');
        if (!list) return;
        if (!items || !items.length) {
            renderEmpty();
            return;
        }
        list.innerHTML = '';
        items.forEach(item => {
            const row = document.createElement('div');
            row.style.display = 'flex';
            row.style.alignItems = 'flex-start';
            row.style.gap = '10px';
            row.style.padding = '10px';
            row.style.marginBottom = '6px';
            row.style.background = '#fff';
            row.style.border = '1px solid #e5e7eb';
            row.style.borderRadius = '4px';

            const main = document.createElement('div');
            main.style.flex = '1';

            const titleEl = document.createElement('div');
            titleEl.style.fontWeight = '500';
            if (item.article_url) {
                const a = document.createElement('a');
                a.href = item.article_url;
                a.target = '_blank';
                a.rel = 'noopener';
                a.textContent = item.display_title || item.article_title || '(untitled)';
                titleEl.appendChild(a);
            } else {
                titleEl.textContent = item.display_title || item.article_title || '(untitled)';
            }

            const metaEl = document.createElement('div');
            metaEl.style.fontSize = '0.85em';
            metaEl.style.color = '#6b7280';
            metaEl.style.marginTop = '2px';
            const metaParts = [];
            if (item.publication_name) metaParts.push(escapeHtml(item.publication_name));
            if (item.publication_date) metaParts.push(formatDate(item.publication_date));
            if (item.article_type) metaParts.push(item.article_type);
            if (item.status && item.status !== 'approved' && item.status !== 'published') {
                metaParts.push('(' + item.status + ')');
            }
            metaEl.innerHTML = metaParts.join(' &middot; ');

            main.appendChild(titleEl);
            main.appendChild(metaEl);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'kop-btn';
            removeBtn.textContent = 'Unlink';
            removeBtn.style.background = '#fef2f2';
            removeBtn.style.color = '#991b1b';
            removeBtn.style.border = '1px solid #fecaca';
            removeBtn.style.padding = '4px 10px';
            removeBtn.style.fontSize = '13px';
            removeBtn.addEventListener('click', () => unlinkArticle(item.id, removeBtn));

            row.appendChild(main);
            row.appendChild(removeBtn);
            list.appendChild(row);
        });
    }

    async function loadLinks() {
        if (!currentFacilityId) return;
        try {
            const res = await fetch(`${endpoints.link}?facility_id=${currentFacilityId}`, {
                credentials: 'include'
            });
            const json = await res.json();
            if (!json || !json.success) throw new Error(json && json.error || 'load failed');
            renderLinks(json.data || []);
        } catch (e) {
            console.warn('Failed to load linked news', e);
            setStatus('Failed to load linked articles: ' + e.message);
        }
    }

    async function linkArticle(newsId) {
        if (!currentFacilityId) return;
        try {
            const res = await fetch(endpoints.link, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ news_id: newsId, facility_id: currentFacilityId })
            });
            const json = await res.json();
            if (!json || !json.success) throw new Error(json && json.error || 'link failed');
            await loadLinks();
        } catch (e) {
            alert('Could not link article: ' + e.message);
        }
    }

    async function unlinkArticle(newsId, btn) {
        if (!currentFacilityId) return;
        if (btn) { btn.disabled = true; btn.textContent = 'Unlinking…'; }
        try {
            const res = await fetch(endpoints.unlink, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ news_id: newsId, facility_id: currentFacilityId })
            });
            const json = await res.json();
            if (!json || !json.success) throw new Error(json && json.error || 'unlink failed');
            await loadLinks();
        } catch (e) {
            alert('Could not unlink article: ' + e.message);
            if (btn) { btn.disabled = false; btn.textContent = 'Unlink'; }
        }
    }

    let newsSearchAbort = null;
    let newsSearchDebounce = null;
    function setupAddSearch() {
        const input = $('linked-news-search-input');
        const suggestions = $('linked-news-suggestions');
        if (!input || !suggestions) return;

        async function runSearch() {
            const q = input.value.trim();
            if (q.length < 2) {
                suggestions.innerHTML = '';
                suggestions.style.display = 'none';
                return;
            }
            if (newsSearchAbort) newsSearchAbort.abort();
            newsSearchAbort = new AbortController();
            try {
                const res = await fetch(`${endpoints.newsSearch}?q=${encodeURIComponent(q)}&limit=15`, {
                    signal: newsSearchAbort.signal,
                    credentials: 'include'
                });
                const json = await res.json();
                if (!json || !json.success) throw new Error('search failed');
                renderSuggestions(json.data || [], q);
            } catch (e) {
                if (e.name !== 'AbortError') console.warn('news search failed', e);
            }
        }

        function renderSuggestions(items, q) {
            suggestions.innerHTML = '';
            if (!items.length) {
                const empty = document.createElement('div');
                empty.style.padding = '8px 10px';
                empty.style.color = '#666';
                empty.style.fontStyle = 'italic';
                empty.textContent = 'No articles match "' + q + '".';
                suggestions.appendChild(empty);
                suggestions.style.display = 'block';
                return;
            }
            items.forEach(item => {
                const opt = document.createElement('div');
                opt.style.padding = '8px 10px';
                opt.style.cursor = 'pointer';
                opt.style.borderBottom = '1px solid #eee';
                opt.addEventListener('mouseenter', () => { opt.style.background = '#f2eedf'; });
                opt.addEventListener('mouseleave', () => { opt.style.background = ''; });

                const title = document.createElement('div');
                title.textContent = item.display_title || item.article_title;
                title.style.fontWeight = '500';
                opt.appendChild(title);

                const meta = document.createElement('div');
                meta.style.fontSize = '0.82em';
                meta.style.color = '#6b7280';
                const parts = [];
                if (item.publication_name) parts.push(item.publication_name);
                if (item.publication_date) parts.push(formatDate(item.publication_date));
                meta.textContent = parts.join(' · ');
                opt.appendChild(meta);

                opt.addEventListener('mousedown', e => {
                    e.preventDefault();
                    linkArticle(item.id);
                    input.value = '';
                    suggestions.innerHTML = '';
                    suggestions.style.display = 'none';
                });
                suggestions.appendChild(opt);
            });
            suggestions.style.display = 'block';
        }

        input.addEventListener('input', () => {
            clearTimeout(newsSearchDebounce);
            newsSearchDebounce = setTimeout(runSearch, 200);
        });

        input.addEventListener('blur', () => {
            setTimeout(() => { suggestions.style.display = 'none'; }, 200);
        });

        input.addEventListener('focus', () => {
            if (suggestions.children.length) suggestions.style.display = 'block';
        });
    }

    async function resolveFacilityId(name) {
        const trimmed = (name || '').trim();
        if (!trimmed) return null;
        try {
            const res = await fetch(`${endpoints.facilitySearch}?q=${encodeURIComponent(trimmed)}&limit=10`, {
                credentials: 'include'
            });
            const json = await res.json();
            if (!json || !json.success) return null;
            const exact = (json.data || []).find(r => r.unique_name && r.unique_name.toLowerCase() === trimmed.toLowerCase());
            return exact ? exact.id : null;
        } catch (e) {
            return null;
        }
    }

    async function handleProjectNameChange(name) {
        if (name === currentProjectName) return;
        currentProjectName = name;
        currentFacilityId = null;
        const addEl = $('linked-news-add');
        const listEl = $('linked-news-list');
        if (listEl) listEl.innerHTML = '';

        if (!name || !name.trim()) {
            setStatus('Enter a project name and save the project to enable news linking.');
            if (addEl) addEl.style.display = 'none';
            return;
        }

        setStatus('Looking up "' + name + '" in facilities_master…');
        const fid = await resolveFacilityId(name);
        if (!fid) {
            setStatus('No facilities_master row found for "' + name + '" yet. Save the project first.');
            if (addEl) addEl.style.display = 'none';
            return;
        }

        currentFacilityId = fid;
        setStatus('Linked to facility id ' + fid + ' (' + name + ')');
        if (addEl) addEl.style.display = 'block';
        await loadLinks();
    }

    function watchProjectName() {
        const input = $('project-name');
        if (!input) return;

        const debouncedHandle = (name) => {
            clearTimeout(resolveTimer);
            resolveTimer = setTimeout(() => handleProjectNameChange(name), 300);
        };

        input.addEventListener('input', () => debouncedHandle(input.value));
        input.addEventListener('change', () => debouncedHandle(input.value));

        // Initial run in case the project name is already populated on load.
        if (input.value) debouncedHandle(input.value);

        // Also re-check after a project is loaded externally (the project loader
        // may set the input.value programmatically, which doesn't fire 'input').
        // Poll on a slow interval as a safety net.
        let lastSeen = input.value;
        setInterval(() => {
            if (input.value !== lastSeen) {
                lastSeen = input.value;
                debouncedHandle(input.value);
            }
        }, 1000);
    }

    function init() {
        if (!$('linked-news-section')) return; // not on this page
        setupAddSearch();
        watchProjectName();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
