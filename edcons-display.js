// Educational Consultants Display - Drop-in JavaScript
// Usage: Include this file on a page with a container element with id="edcons-container"

function displayEdCons(data, containerId) {
    const container = document.getElementById(containerId);
    if (!container) {
        console.warn('EdCons display skipped - container not found:', containerId);
        return;
    }

    const toArray = value => Array.isArray(value) ? value : [];
    const cleanText = value => {
        if (typeof value === 'string') {
            return value.trim();
        }
        if (typeof value === 'number') {
            return String(value);
        }
        return '';
    };
    const htmlEscapeMap = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    };
    const escapeHtml = value => {
        const text = cleanText(value);
        return text ? text.replace(/[&<>"']/g, char => htmlEscapeMap[char] || char) : '';
    };
    const joinList = values => toArray(values).map(item => cleanText(item)).filter(item => item);

    const normalizeProjectCategory = project => {
        if (!project || typeof project !== 'object') {
            return 'companies';
        }
        let rawCategory = '';
        if (typeof project.category === 'string' && project.category) {
            rawCategory = project.category;
        } else if (project.data && typeof project.data === 'object' && typeof project.data.category === 'string' && project.data.category) {
            rawCategory = project.data.category;
        }
        if (!rawCategory) {
            return 'companies';
        }
        return rawCategory.toLowerCase();
    };

    const isReferrerCategory = project => {
        const category = normalizeProjectCategory(project);
        return category === 'referrers' || category === 'referrer';
    };

    let html = '<div class="edcons-database">';

    let referrerProjects = [];
    if (data && data.projects) {
        referrerProjects = Object.values(data.projects).filter(isReferrerCategory);
    } else if (Array.isArray(data)) {
        // Fallback for older array-based structures
        referrerProjects = data.filter(isReferrerCategory);
    }

    if (referrerProjects.length === 0) {
        container.innerHTML = '<p>No educational consultant projects found in the provided data.</p>';
        return;
    }

    // Sort projects alphabetically by name
    referrerProjects.sort((a, b) => {
        const nameA = cleanText(a.name) || '';
        const nameB = cleanText(b.name) || '';
        return nameA.localeCompare(nameB);
    });

    referrerProjects.forEach(project => {
        const projectData = project.data || {};
        const agency = projectData.referrerAgency || {};
        const consultants = toArray(projectData.referrerConsultants);

        const projectName = escapeHtml(project.name || 'Unnamed Project');

        html += `<details class="edcon-project-section" open>
                    <summary class="edcon-project-header">
                        <h1>${projectName}</h1>
                    </summary>
                    <div class="edcon-project-content">`;

        // Display Agency Info if it exists
        if (agency.name) {
            html += `<div class="edcon-agency-card">
                        <h3>Agency: ${escapeHtml(agency.name)}</h3>
                        <p>${escapeHtml(agency.city)}, ${escapeHtml(agency.state)}</p>
                        ${agency.website ? `<p><a href="${escapeHtml(agency.website)}" target="_blank" rel="noopener">${escapeHtml(agency.website)}</a></p>` : ''}
                        ${joinList(agency.keyPersonnel).length > 0 ? `<p><strong>Key Personnel:</strong> ${escapeHtml(joinList(agency.keyPersonnel).join(', '))}</p>` : ''}
                        ${agency.notes ? `<p class="edcon-notes"><strong>Notes:</strong> ${escapeHtml(agency.notes)}</p>` : ''}
                    </div>`;
        }

        // Display Consultants
        if (consultants.length > 0) {
            html += `<div class="edcon-consultants-grid">`;
            consultants.forEach(consultant => {
                const fullName = [consultant.firstName, consultant.lastName].filter(Boolean).join(' ');
                if (!fullName) return;

                html += `<div class="edcon-consultant-card">
                            <h4>${escapeHtml(fullName)}</h4>
                            ${consultant.credentials ? `<p class="edcon-credentials">${escapeHtml(consultant.credentials)}</p>` : ''}
                            ${consultant.city || consultant.state ? `<p>${escapeHtml(consultant.city)}, ${escapeHtml(consultant.state)}</p>` : ''}
                            ${consultant.email ? `<p><a href="mailto:${escapeHtml(consultant.email)}">${escapeHtml(consultant.email)}</a></p>` : ''}
                            ${consultant.phone ? `<p>${escapeHtml(consultant.phone)}</p>` : ''}
                            ${consultant.website ? `<p><a href="${escapeHtml(consultant.website)}" target="_blank" rel="noopener">Website</a></p>` : ''}
                            ${joinList(consultant.affiliations).length > 0 ? `<p><strong>Affiliations:</strong> ${escapeHtml(joinList(consultant.affiliations).join(', '))}</p>` : ''}
                            ${joinList(consultant.facilitiesReferred).length > 0 ? `<p><strong>Referred To:</strong> ${escapeHtml(joinList(consultant.facilitiesReferred).join(', '))}</p>` : ''}
                            ${consultant.notes ? `<p class="edcon-notes"><strong>Notes:</strong> ${escapeHtml(consultant.notes)}</p>` : ''}
                        </div>`;
            });
            html += `</div>`;
        }

        html += `</div></details>`;
    });

    html += '</div>';
    container.innerHTML = html;
}

document.addEventListener('DOMContentLoaded', function() {
    console.log('EdCons script loaded');

    const container = document.getElementById('edcons-container');
    if (!container) {
        console.info('EdCons script: no edcons-container element present, skipping data fetch.');
        return;
    }

    const edconsConfig = window.edconsConfig || {};
    const configUrls = Array.isArray(edconsConfig.jsonFileUrls) ? edconsConfig.jsonFileUrls : [];
    const datasetCandidates = Array.from(new Set([
        edconsConfig.jsonDataUrl,
        ...configUrls
    ].filter(url => typeof url === 'string' && url.trim().length > 0)));

    if (!datasetCandidates.length) {
        console.error('EdCons script: no dataset URLs are configured.');
        container.innerHTML = '<p>Error loading data: no dataset URL is configured.</p>';
        return;
    }

    (async () => {
        for (const candidateUrl of datasetCandidates) {
            try {
                console.log('EdCons script: attempting to load data from', candidateUrl);
                const response = await fetch(candidateUrl, { credentials: 'same-origin' });

                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ' ' + response.statusText);
                }

                const data = await response.json();
                console.log('EdCons script: data loaded successfully from', candidateUrl);
                displayEdCons(data, 'edcons-container');
                return; // Success, exit loop
            } catch (error) {
                console.warn('EdCons script: failed to load dataset from', candidateUrl, error);
            }
        }

        console.error('EdCons script: unable to load any of the configured datasets.');
        container.innerHTML = '<p>Error loading educational consultant data. Please try again later.</p>';
    })();
});