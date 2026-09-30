/**
 * MENTAL HEALTH PROVIDER DIRECTORY - collapsible cards, one per provider
 * project in providers_master (api/get-providers-only.php). A project holds
 * one or more sites in data.facilities, each with providerDetails (care types,
 * TTI practices, referrals into the TTI, transporters used). Laid out like the
 * transporter directory (js/transporter-index-v2.js); documents on file come
 * from js/directory-documents.js.
 */

document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('providers-container');
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const alphabetFilter = document.getElementById('alphabet-filter');

    if (!container) return;

    let allProviders = [];

    const clean = v => (typeof v === 'string' ? v.trim() : (v ? String(v) : ''));
    const esc = v => clean(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] || c));

    const STATE_CODES = ['AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY'];

    function projectData(p) {
        const payload = p && p.payload ? p.payload : {};
        return payload.data && typeof payload.data === 'object' ? payload.data : payload;
    }

    function sites(p) {
        const data = projectData(p);
        return (Array.isArray(data.facilities) ? data.facilities : []).filter(s => s && typeof s === 'object');
    }

    function siteName(s, p) {
        return clean(s.identification && s.identification.name) || clean(s.name) || clean(p.db_name);
    }

    function siteState(s) {
        const st = clean((s.locationDetails && s.locationDetails.state) || s.locationState).toUpperCase();
        return STATE_CODES.indexOf(st) !== -1 ? st : '';
    }

    function sitePlace(s) {
        const loc = s.locationDetails || {};
        return [clean(loc.city || s.locationCity), clean(loc.state || s.locationState), clean(loc.country) === 'United States' ? '' : clean(loc.country)]
            .filter(Boolean).join(', ');
    }

    function recordStates(p) {
        const found = new Set();
        sites(p).forEach(s => { const st = siteState(s); if (st) found.add(st); });
        return found;
    }

    fetch('/wp-content/themes/child/api/get-providers-only.php?t=' + Date.now())
        .then(res => res.json())
        .then(res => {
            if (res.success && res.projects && Object.values(res.projects).length) {
                allProviders = Object.values(res.projects);
                allProviders.sort((a, b) => (a.db_name || '').localeCompare(b.db_name || ''));
                const locs = new Set();
                allProviders.forEach(p => recordStates(p).forEach(s => locs.add(s)));
                statusFilter.innerHTML = '<option value="">All Locations</option>' + Array.from(locs).sort().map(l => `<option value="${l}">${l}</option>`).join('');
                renderAlphabet();
                filterAndRender();
            } else {
                container.innerHTML = '<div class="provider-empty">No provider records yet.</div>';
            }
        })
        .catch(err => {
            container.innerHTML = '<div class="provider-empty">Could not load the provider directory.</div>';
            console.error('Provider directory load error:', err);
        });

    function renderAlphabet() {
        const alpha = '#ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
        alphabetFilter.innerHTML = alpha.map(c => `<button type="button" class="alpha-btn" data-char="${c}">${c}</button>`).join('')
            + '<button type="button" class="alpha-btn" data-char="">All</button>';
        alphabetFilter.querySelectorAll('.alpha-btn').forEach(b => b.addEventListener('click', () => {
            const c = b.getAttribute('data-char');
            alphabetFilter.querySelectorAll('.alpha-btn').forEach(x => x.classList.toggle('active', x === b));
            window.currentAlphaFilter = c;
            filterAndRender();
        }));
    }

    function filterAndRender() {
        const q = (searchInput.value || '').toLowerCase();
        const l = (statusFilter.value || '').toUpperCase();
        const a = (window.currentAlphaFilter || '').toLowerCase();
        const filtered = allProviders.filter(p => {
            const name = (p.db_name || '').toLowerCase();
            if (a && a === '#' && !/^[0-9]/.test(name)) return false;
            if (a && a !== '#' && !name.startsWith(a)) return false;
            if (q && !name.includes(q) && !JSON.stringify(p).toLowerCase().includes(q)) return false;
            if (l && !recordStates(p).has(l)) return false;
            return true;
        });
        renderList(filtered);
    }

    function renderField(label, value) {
        const val = clean(value);
        if (!val || val === 'null') return '';
        if (/^https?:\/\//i.test(val)) {
            // A provider's own site: archived copy, live site only via /go/.
            const link = (window.KOP && window.KOP.programLinks) ? window.KOP.programLinks.html(val, { max: 50 }) : esc(val);
            return `<div class="data-row"><span class="data-label">${esc(label)}</span><span class="data-value">${link}</span></div>`;
        }
        return `<div class="data-row"><span class="data-label">${esc(label)}</span><span class="data-value">${esc(val)}</span></div>`;
    }

    function itemText(item) {
        if (item == null) return '';
        if (typeof item === 'object' && !Array.isArray(item)) {
            const name = clean(item.name || item.text || item.value || item.displayText || item.url);
            const role = clean(item.role || item.title);
            return role && name ? `${name} (${role})` : (name || role);
        }
        return clean(item);
    }

    function renderArrayList(label, arr) {
        const items = (Array.isArray(arr) ? arr : (arr ? [arr] : [])).map(itemText).filter(Boolean);
        if (!items.length) return '';
        return `<div class="list-section"><div class="section-label">${esc(label)}</div><ul class="data-list">`
            + items.map(t => `<li class="data-list-item"><span class="job-role">${esc(t)}</span></li>`).join('') + '</ul></div>';
    }

    /** {hasPartialHospitalization: true} -> "Partial Hospitalization". */
    function checkedLabels(map) {
        if (!map || typeof map !== 'object' || Array.isArray(map)) return [];
        return Object.keys(map).filter(k => map[k] === true || map[k] === 'true' || map[k] === 1).map(k => k
            .replace(/^has(?=[A-Z0-9])/, '')
            .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
            .trim().replace(/^./, c => c.toUpperCase()));
    }

    function renderNotes(label, notes) {
        const items = (Array.isArray(notes) ? notes : (notes ? [notes] : []))
            .map(n => (typeof n === 'object' && n) ? clean(n.text || n.value) : clean(n))
            .filter(Boolean);
        if (!items.length) return '';
        return `<div class="list-section"><div class="section-label">${esc(label)}</div>${items.map(t => `<div class="data-value">${esc(t)}</div>`).join('')}</div>`;
    }

    function renderSite(s, p, showName) {
        const d = s.providerDetails || {};
        const type = clean(s.facilityDetails && s.facilityDetails.type);
        return `
            <div class="provider-sub-card">
                ${showName ? `<div class="provider-name-header">${esc(siteName(s, p))}</div>` : ''}
                ${renderField('Location', sitePlace(s))}
                ${renderField('Type', type)}
                ${renderArrayList('Care types', checkedLabels(d.careTypes).concat(Array.isArray(d.otherCareTypes) ? d.otherCareTypes : []))}
                ${renderArrayList('TTI practices used', checkedLabels(d.ttiPractices).concat(Array.isArray(d.otherTtiPractices) ? d.otherTtiPractices : []))}
                ${renderArrayList('Refers young people to', d.ttiReferrals)}
                ${renderArrayList('Transporters used', d.transportersUsed)}
                ${renderArrayList('TTI affiliations', d.ttiAffiliations)}
                ${renderNotes('Referral notes', d.referralNotes)}
                ${renderNotes('Notes', s.notes)}
                ${renderField('Website', s.website || (s.identification && s.identification.website))}
            </div>`;
    }

    function renderList(list) {
        container.innerHTML = '';
        if (!list.length) {
            container.innerHTML = '<div class="provider-empty">No providers match your filters.</div>';
            return;
        }
        const grid = document.createElement('div');
        grid.className = 'provider-grid';
        list.forEach(p => {
            const card = document.createElement('details');
            card.className = 'provider-card';
            card.setAttribute('data-kop-bug-feature', 'provider-index/card');
            card.setAttribute('data-kop-bug-label', 'Provider: ' + (p.db_name || ''));
            const all = sites(p);
            const data = projectData(p);
            const parent = clean(data.operator && data.operator.name);
            const first = all[0] || {};
            const sub = [clean(first.facilityDetails && first.facilityDetails.type), sitePlace(first)].filter(Boolean).join(' · ')
                || 'Mental health provider';
            let html = `
                <summary class="provider-card-summary">
                    <h3 class="provider-main-name">${esc(p.db_name)}</h3>
                    <span class="provider-sub-label">${esc(sub)}</span>
                </summary>
                <div class="provider-card-body">
                    ${renderField('Parent organization', parent)}`;
            all.forEach(s => { html += renderSite(s, p, all.length > 1 || siteName(s, p) !== clean(p.db_name)); });
            if (window.kopDirectoryDocs) html += window.kopDirectoryDocs.slot(p.payload);
            html += `
                    <div class="kop-submit-info-row">
                        <button type="button" class="kop-submit-info-btn" data-kop-submit-type="provider" data-kop-submit-name="${esc(p.db_name)}">Submit info about this provider</button>
                    </div>
                </div>`;
            card.innerHTML = html;
            grid.appendChild(card);
            if (window.kopDirectoryDocs) window.kopDirectoryDocs.attach(card);
        });
        container.appendChild(grid);
    }

    searchInput.addEventListener('input', filterAndRender);
    statusFilter.addEventListener('change', filterAndRender);
    window.clearSearch = function () {
        searchInput.value = '';
        statusFilter.value = '';
        window.currentAlphaFilter = '';
        alphabetFilter.querySelectorAll('.alpha-btn').forEach(b => b.classList.toggle('active', b.getAttribute('data-char') === ''));
        filterAndRender();
    };
});
