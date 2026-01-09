/**
 * REFERRER DIRECTORY JS - Final Absolute Version
 * Renders every record received from the API without exception.
 */

document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('referrers-container');
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const alphabetFilter = document.getElementById('alphabet-filter');

    let allData = [];

    const esc = v => (v ? String(v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c] || c)) : '');

    // 1. Fetch from the NEW specific API
    fetch('/wp-content/themes/child/api/get-referrers-only.php?t=' + Date.now())
        .then(res => res.json())
        .then(res => {
            if (res.success && res.projects) {
                allData = Object.values(res.projects);
                renderList(allData);
                // Simple Location Filter Setup
                const locs = new Set();
                allData.forEach(p => {
                    const str = JSON.stringify(p);
                    const states = ['AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY'];
                    states.forEach(s => { if(str.includes('"' + s + '"') || str.includes(', ' + s)) locs.add(s); });
                });
                statusFilter.innerHTML = '<option value="">All Locations</option>' + Array.from(locs).sort().map(l => `<option value="${l}">${l}</option>`).join('');
            }
        });

    function renderList(list) {
        container.innerHTML = '';
        const grid = document.createElement('div');
        grid.className = 'referrer-grid';

        list.forEach(p => {
            const card = document.createElement('div');
            card.className = 'referrer-card';
            
            // We pull name from the root or the data. 
            const displayName = p.name || p.data?.name || 'Unnamed Record';
            
            // Gather ALL consultant data into one list
            let profiles = [];
            if (p.data?.referrerIndividual) profiles.push(p.data.referrerIndividual);
            if (Array.isArray(p.data?.referrerConsultants)) profiles = profiles.concat(p.data.referrerConsultants);
            
            // If no profiles, create a dummy one so the card isn't empty
            if (profiles.length === 0) profiles.push({ fullName: displayName });

            card.innerHTML = `
                <div class="referrer-card-header">
                    <h3 class="referrer-main-name">${esc(displayName)}</h3>
                </div>
                <div class="referrer-card-body">
                    ${profiles.map(c => {
                        if (!c) return '';
                        const cName = c.fullName || (c.firstName + ' ' + c.lastName).trim() || displayName;
                        return `
                        <div class="consultant-profile" style="margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
                            <div style="font-weight:bold; color:#00004d;">${esc(cName)}</div>
                            <div style="font-size:0.85em; font-style:italic; color:#666;">${esc(c.role || c.title || 'Consultant')}</div>
                            ${c.phone ? `<div style="font-size:0.9em;">📞 ${esc(c.phone)}</div>` : ''}
                            ${c.email ? `<div style="font-size:0.9em;">📧 <a href="mailto:${c.email}">${esc(c.email)}</a></div>` : ''}
                            ${(c.pastTTIJobs && c.pastTTIJobs.length) ? `
                                <details style="margin-top:10px; font-size:0.85em;">
                                    <summary style="cursor:pointer; font-weight:bold;">+ View History</summary>
                                    <div style="padding:5px; background:#f9f9f9; border-radius:4px; margin-top:5px;">
                                        ${c.pastTTIJobs.map(j => `<div>• ${esc(j.role)} at ${esc(j.employer || j.organization)}</div>`).join('')}
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

    // Ultra-simple filter
    searchInput.addEventListener('input', () => {
        const q = searchInput.value.toLowerCase();
        renderList(allData.filter(p => JSON.stringify(p).toLowerCase().includes(q)));
    });
    
    statusFilter.addEventListener('change', () => {
        const l = statusFilter.value.toLowerCase();
        renderList(allData.filter(p => JSON.stringify(p).toLowerCase().includes(l)));
    });
});