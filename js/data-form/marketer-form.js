/**
 * Marketer Form Module (the data forms' Marketers tab)
 *
 * Marketing companies that sell troubled teen programs to parents: they build the programs'
 * websites, run "independent" directories and help lines, trade parents' inquiries, manage
 * reputations and write instructions for AI chatbots. One company per project, stored in
 * marketers_master as { marketerCompany } (api/lib-marketers.php keeps only that block and
 * mirrors createDefaultMarketerCompany() below). Listed on /marketing-companies/.
 */

// Same keys as kop_marketer_service_labels() in api/lib-marketers.php
const MARKETER_SERVICE_KEYS = [
    'websiteDesign', 'seo', 'cityPages', 'directories', 'helpLine',
    'leadGeneration', 'aiInstructions', 'reputationManagement', 'advertising', 'publicRelations'
];

const MARKETER_TEXT_FIELDS = ['name', 'city', 'state', 'country', 'address', 'founded', 'status', 'notes'];

const MARKETER_LIST_FIELDS = [
    'otherNames', 'websites', 'parentCompanies', 'keyPersonnel', 'otherServices', 'sitesRun',
    'clientFacilities', 'clientCompanies', 'affiliations', 'lawsuits', 'sourceUrls', 'socialMedia'
];

function createDefaultMarketerCompany() {
    const company = {};
    MARKETER_TEXT_FIELDS.forEach(field => { company[field] = ''; });
    MARKETER_LIST_FIELDS.forEach(field => { company[field] = []; });
    company.services = {};
    MARKETER_SERVICE_KEYS.forEach(key => { company.services[key] = false; });
    company.fieldNotes = {};
    return company;
}

function isMarketerContext() {
    const activeTab = document.querySelector('.category-tab.active');
    return !!activeTab && activeTab.dataset.category === 'marketers';
}

/**
 * Give formData.marketerCompany every field. Fills the object in place: list editors hold
 * references to its arrays, so it is never swapped for a new one.
 */
function ensureMarketerDataStructures() {
    if (!window.formData) return;
    let company = window.formData.marketerCompany;
    if (!company || typeof company !== 'object' || Array.isArray(company)) {
        company = createDefaultMarketerCompany();
        window.formData.marketerCompany = company;
    }
    MARKETER_TEXT_FIELDS.forEach(field => {
        if (typeof company[field] !== 'string') {
            company[field] = company[field] == null ? '' : String(company[field]);
        }
    });
    MARKETER_LIST_FIELDS.forEach(field => {
        if (!Array.isArray(company[field])) {
            company[field] = typeof company[field] === 'string' && company[field].trim() ? [company[field].trim()] : [];
        }
    });
    if (!company.services || typeof company.services !== 'object') company.services = {};
    MARKETER_SERVICE_KEYS.forEach(key => { company.services[key] = !!company.services[key]; });
    if (!company.fieldNotes || typeof company.fieldNotes !== 'object') company.fieldNotes = {};
}

/** What a marketer save or submission sends: the company block, list blanks dropped. */
function marketerSubmissionData(data, projectName) {
    const source = (data && data.marketerCompany) || createDefaultMarketerCompany();
    const company = JSON.parse(JSON.stringify(source));
    MARKETER_LIST_FIELDS.forEach(field => {
        company[field] = (Array.isArray(company[field]) ? company[field] : [])
            .map(item => (typeof item === 'string' ? item.trim() : ''))
            .filter(Boolean);
    });
    const out = { marketerCompany: company, category: 'marketers' };
    if (projectName) {
        out.projectName = projectName;
        out.name = projectName;
    }
    if (data && data.documentFolderId) out.documentFolderId = data.documentFolderId;
    return out;
}

function marketerFieldId(field) {
    return 'marketer-company-' + field.replace(/[A-Z]/g, letter => '-' + letter.toLowerCase());
}

/** Fill the Marketers tab from formData (only while that tab is active). */
function loadMarketerData() {
    if (!isMarketerContext()) return;
    ensureMarketerDataStructures();
    const company = window.formData.marketerCompany;

    MARKETER_TEXT_FIELDS.forEach(field => {
        const el = document.getElementById(marketerFieldId(field));
        if (el) el.value = company[field] || '';
    });

    document.querySelectorAll('.marketer-service[data-service]').forEach(box => {
        box.checked = !!company.services[box.dataset.service];
    });

    if (typeof renderArray === 'function') {
        MARKETER_LIST_FIELDS.forEach(field => {
            const path = 'marketerCompany.' + field;
            const container = document.querySelector(`[data-path="${path}"]`);
            if (container) renderArray(container, path, company[field]);
        });
    }

    const nameInput = document.getElementById('marketer-project-name');
    if (nameInput && window.currentProjectName) {
        if (!nameInput.value) nameInput.value = window.currentProjectName;
        nameInput.dataset.followsName = '0';
    }
}

/** Show the marketer editor on its tab; hide the facility and referrer editors there. */
function handleMarketerToggle() {
    const wrapper = document.getElementById('marketer-main-wrapper');
    const show = el => { if (el) { el.classList.remove('view-hidden'); el.style.display = ''; } };
    const hide = el => { if (el) { el.classList.add('view-hidden'); el.style.display = ''; } };
    if (isMarketerContext()) {
        ensureMarketerDataStructures();
        hide(document.getElementById('facility-main-wrapper'));
        hide(document.querySelector('.facility-loader-panel'));
        hide(document.getElementById('referrer-main-wrapper'));
        hide(document.getElementById('transporter-main-wrapper'));
        show(wrapper);
    } else {
        hide(wrapper);
    }
}

function updateMarketerCompany(mutator) {
    ensureMarketerDataStructures();
    mutator(window.formData.marketerCompany);
    if (typeof updateJSON === 'function') updateJSON();
    if (typeof autoSave === 'function') autoSave();
}

/**
 * Delegated on the document: ui-events.js swaps text inputs for growing textareas at load,
 * which would drop listeners bound to the original elements.
 */
function attachMarketerFieldListeners() {
    if (document.documentElement.dataset.marketerFieldsBound) return;
    document.documentElement.dataset.marketerFieldsBound = 'true';
    const fieldById = {};
    MARKETER_TEXT_FIELDS.forEach(field => { fieldById[marketerFieldId(field)] = field; });

    document.addEventListener('input', e => {
        const el = e.target;
        if (!el || !el.id) return;
        if (el.id === 'marketer-project-name') {
            el.dataset.followsName = '0';
            return;
        }
        const field = fieldById[el.id];
        if (!field) return;
        const value = el.value;
        updateMarketerCompany(company => { company[field] = value; });
        // A new company's name fills the project name until someone types another there;
        // a saved record keeps its project name (renaming it would make a second record)
        if (field === 'name') {
            const nameInput = document.getElementById('marketer-project-name');
            if (nameInput && (!nameInput.value || nameInput.dataset.followsName === '1')) {
                nameInput.value = value;
                nameInput.dataset.followsName = '1';
            }
        }
    }, { passive: true });

    document.addEventListener('change', e => {
        const box = e.target;
        if (!box || !box.classList || !box.classList.contains('marketer-service')) return;
        const key = box.dataset.service;
        const on = box.checked;
        updateMarketerCompany(company => { company.services[key] = on; });
    });
}

function marketerInSuggestionMode() {
    return !!(window.KOP_FormConfig &&
        typeof window.KOP_FormConfig.isSuggestionMode === 'function' &&
        window.KOP_FormConfig.isSuggestionMode());
}

async function saveMarketerProject() {
    ensureMarketerDataStructures();
    const nameInput = document.getElementById('marketer-project-name');
    let projectName = (nameInput && nameInput.value.trim()) || '';
    if (!projectName) projectName = (window.formData.marketerCompany.name || '').trim();
    if (!projectName) {
        const ask = typeof customAlert === 'function' ? customAlert : (msg) => Promise.resolve(alert(msg));
        ask('Please enter the marketing company\'s name', 'Name Required').then(() => {
            const el = document.getElementById('marketer-company-name');
            if (el) el.focus();
        });
        return;
    }
    if (nameInput) nameInput.value = projectName;

    window.formData.category = 'marketers';
    if (!window.projects) window.projects = {};
    window.projects[projectName] = Object.assign(window.projects[projectName] || {}, { category: 'marketers' });
    window.currentProjectName = projectName;

    // Public form: the suggestion flow (reason, follow-up email) posts to api/save-suggestion.php
    if (marketerInSuggestionMode()) {
        if (typeof window.submitSuggestion === 'function') {
            window.submitSuggestion();
        } else if (typeof showUploadStatus === 'function') {
            showUploadStatus('Submission flow not loaded. Please refresh the page.', 'error');
        }
        return;
    }

    // Admin form: straight to marketers_master
    if (window.KOP_API && typeof window.KOP_API.saveProjectToCloud === 'function') {
        try {
            await window.KOP_API.saveProjectToCloud(projectName);
        } catch (err) {
            console.error('Failed to save marketer project:', err);
            if (typeof showUploadStatus === 'function') showUploadStatus('Failed to save: ' + (err.message || 'Unknown error'), 'error');
        }
    } else if (typeof showUploadStatus === 'function') {
        showUploadStatus('Save function not available. Please refresh the page.', 'error');
    }
}

function attachMarketerProjectButtons() {
    const bind = (id, handler) => {
        const btn = document.getElementById(id);
        if (btn && !btn.dataset.listenerAttached) {
            btn.addEventListener('click', handler);
            btn.dataset.listenerAttached = 'true';
        }
    };
    bind('new-project-btn-marketer', () => {
        if (typeof window.newProject === 'function') window.newProject();
    });
    bind('export-all-btn-marketer', () => {
        if (typeof window.exportProjectsToFile === 'function') {
            window.exportProjectsToFile({ categories: ['marketers'], filename: 'projects-export-marketers.json' });
        }
    });
    bind('generate-report-btn-marketer', () => {
        if (typeof window.generateProjectsReport === 'function') {
            window.generateProjectsReport({ categories: ['marketers'], filename: 'projects-report-marketers.json' });
        }
    });
    bind('save-marketer-project-btn', saveMarketerProject);
}

function initMarketerForm() {
    attachMarketerFieldListeners();
    attachMarketerProjectButtons();
    handleMarketerToggle();
    loadMarketerData();
    const tabs = document.querySelector('.category-tabs');
    if (tabs && !tabs.dataset.marketerBound) {
        // After project.js has switched tabs and reset the form
        tabs.addEventListener('click', () => setTimeout(() => { handleMarketerToggle(); loadMarketerData(); }, 0));
        tabs.dataset.marketerBound = 'true';
    }
}

window.MARKETER_SERVICE_KEYS = MARKETER_SERVICE_KEYS;
window.createDefaultMarketerCompany = createDefaultMarketerCompany;
window.isMarketerContext = isMarketerContext;
window.ensureMarketerDataStructures = ensureMarketerDataStructures;
window.marketerSubmissionData = marketerSubmissionData;
window.loadMarketerData = loadMarketerData;
window.handleMarketerToggle = handleMarketerToggle;
window.attachMarketerFieldListeners = attachMarketerFieldListeners;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMarketerForm);
} else {
    initMarketerForm();
}
