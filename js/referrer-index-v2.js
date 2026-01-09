/**
 * REFERRER DIRECTORY JS - Enhanced High Contrast Version
 */

document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('referrers-container');
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const alphabetFilter = document.getElementById('alphabet-filter');

    if (!container) return;

    let allReferrers = []; 

    const clean = v => (typeof v === 'string' ? v.trim() : (v ? String(v) : ''));
    const esc = v => clean(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c));

    window.clearSearch = () => {
        searchInput.value = '';
        statusFilter.value = '';
        window.currentAlphaFilter = '';
        document.querySelectorAll('.alpha-btn').forEach(b => b.classList.remove('active'));
        filterAndRender();
    };

    fetch('/wp-content/themes/child/api/get-referrers-only.php?t=' + Date.now())
        .then(res => res.json())
        .then(res => {
            if (res.success && res.projects) {
                allReferrers = Object.values(res.projects);
                allReferrers.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
                
                // Location List
                const locs = new Set();
                allReferrers.forEach(p => {
                    const str = JSON.stringify(p);
                    const states = ['AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY'];
                    states.forEach(s => { if(str.includes('"' + s + '"')) locs.add(s); });
                });
                statusFilter.innerHTML = '<option value="">All Locations</option>' + Array.from(locs).sort().map(l => `<option value="${l}">${l}</option>`).join('');

                renderAlphabet();
                filterAndRender();
            }
        });

    function renderAlphabet() {
        const alpha = '#ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
        alphabetFilter.innerHTML = alpha.map(c => `<button type="button" class="alpha-btn" onclick="window.filterByChar('${c}')">${c}</button>`).join('') + 
            `<button type="button" class="alpha-btn" onclick="window.filterByChar('')">All</button>`;
        
        window.filterByChar = (c) => {
            document.querySelectorAll('.alpha-btn').forEach(b => b.classList.toggle('active', b.innerText === (c||'All')));
            window.currentAlphaFilter = c;
            filterAndRender();
        };
    }

    function filterAndRender() {
        const query = (searchInput.value || '').toLowerCase();
        const loc = (statusFilter.value || '').toLowerCase();
        const alpha = (window.currentAlphaFilter || '').toLowerCase();

        const filtered = allReferrers.filter(p => {
            const name = (p.name || '').toLowerCase();
            const dataStr = JSON.stringify(p).toLowerCase();
            if (alpha && alpha === '#' && !/^[0-9]/.test(name)) return false;
            if (alpha && alpha !== '#' && !name.startsWith(alpha)) return false;
            if (query && !name.includes(query) && !dataStr.includes(query)) return false;
            if (loc && !dataStr.includes('"' + loc.toUpperCase() + '"')) return false;
            return true;
        });

        renderList(filtered);
    }

    function renderList(list) {
        container.innerHTML = '';
        const grid = document.createElement('div');
        grid.className = 'referrer-grid';

        list.forEach(p => {
            const card = document.createElement('div');
            card.className = 'referrer-card';
            
            const pData = p.data || {};
            const isAgency = !!(clean(pData.referrerAgency?.name) || clean(pData.referrerGroup?.name));
            
            let profiles = [];
            if (pData.referrerIndividual) profiles.push(pData.referrerIndividual);
            if (Array.isArray(pData.referrerConsultants)) profiles = profiles.concat(pData.referrerConsultants);
            if (profiles.length === 0) profiles.push({ fullName: p.name });

            card.innerHTML = `
                <div class="referrer-card-header">
                    <h3 class="referrer-main-name">${esc(p.name)}</h3>
                    <span class="referrer-sub-label">${isAgency ? 'Referral Agency' : 'Independent Consultant'}</span>
                </div>
                <div class="referrer-card-body">
                    ${profiles.map(c => {
                        const cName = c.fullName || (c.firstName + ' ' + c.lastName).trim() || p.name;
                        const cLoc = clean([c.city, c.state].filter(Boolean).join(', ')) || c.location || pData.referrerAgency?.location || '';

                        return `
                        <div class="consultant-profile">
                            <div class="profile-identity">
                                <div class="consultant-name">${esc(cName)}</div>
                                <div class="consultant-title">${esc(clean(c.role || c.title || 'Educational Consultant'))}</div>
                            </div>
                            
                            <div class="detail-list">
                                ${cLoc ? `<div class="detail-item"><span class="detail-label">📍</span><span class="detail-value">${esc(cLoc)}</span></div>` : ''}
                                ${c.phone ? `<div class="detail-item"><span class="detail-label">📞</span><span class="detail-value">${esc(c.phone)}</span></div>` : ''}
                                ${c.email ? `<div class="detail-item"><span class="detail-label">📧</span><span class="detail-value"><a href="mailto:${c.email}">${esc(c.email)}</a></span></div>` : ''}
                                ${(c.website || pData.referrerAgency?.website) ? `<div class="detail-item"><span class="detail-label">🔗</span><span class="detail-value"><a href="${c.website || pData.referrerAgency?.website}" target="_blank">View Website</a></span></div>` : ''}
                            </div>

                            ${(c.pastTTIJobs && c.pastTTIJobs.length) ? `
                                <details class="referrer-expandable">
                                    <summary>Career History (${c.pastTTIJobs.length})</summary>
                                    <div class="expandable-content">
                                        ${c.pastTTIJobs.map(j => `
                                            <div class="info-item">
                                                <span class="item-primary">${esc(j.role)}</span>
                                                <span class="item-secondary">at ${esc(j.employer || j.organization)}</span>
                                            </div>`).join('')}
                                    </div>
                                </details>` : ''}

                            ${(c.knownReferrals && c.knownReferrals.length && clean(c.knownReferrals[0])) ? `
                                <details class="referrer-expandable">
                                    <summary>Facility Referrals</summary>
                                    <div class="expandable-content">
                                        ${c.knownReferrals.filter(clean).map(r => `
                                            <div class="info-item">
                                                <span class="item-primary">${esc(r)}</span>
                                            </div>`).join('')}
                                    </div>
                                </details>` : ''}
                        </div>`;
                    }).join('')}
                </div>
            `;
            grid.appendChild(card);
        });
        container.appendChild(grid);
    }

    searchInput.addEventListener('input', filterAndRender);
    statusFilter.addEventListener('change', filterAndRender);
});