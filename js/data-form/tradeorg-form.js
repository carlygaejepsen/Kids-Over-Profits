/**
 * Trade Organization Form Module (the data forms' Trade Organizations tab)
 *
 * Associations of programs, consultants, transporters or other industry businesses
 * (NATSAP, the OBH Council, IECA...). One organization per project, stored in
 * tradeorgs_master as { tradeOrg } (api/lib-tradeorgs.php keeps only that block and
 * mirrors createDefaultTradeOrg() below). Listed on /trade-organizations/, where the
 * programs whose records name it as a membership are listed too.
 */

// Same keys as kop_tradeorg_activity_labels() in api/lib-tradeorgs.php
const TRADEORG_ACTIVITY_KEYS = [
    'standards', 'accreditation', 'lobbying', 'publicRelations',
    'research', 'conferences', 'referralNetwork', 'memberSeal'
];

const TRADEORG_TEXT_FIELDS = ['name', 'acronym', 'orgType', 'city', 'state', 'country', 'address', 'founded', 'status', 'notes'];

const TRADEORG_LIST_FIELDS = [
    'otherNames', 'websites', 'keyPersonnel', 'otherActivities', 'memberFacilities', 'memberCompanies',
    'memberOthers', 'publications', 'lobbying', 'funding', 'affiliations', 'lawsuits', 'sourceUrls', 'socialMedia'
];

function createDefaultTradeOrg() {
    const org = {};
    TRADEORG_TEXT_FIELDS.forEach(field => { org[field] = ''; });
    TRADEORG_LIST_FIELDS.forEach(field => { org[field] = []; });
    org.activities = {};
    TRADEORG_ACTIVITY_KEYS.forEach(key => { org.activities[key] = false; });
    org.fieldNotes = {};
    return org;
}

function isTradeOrgContext() {
    const activeTab = document.querySelector('.category-tab.active');
    return !!activeTab && activeTab.dataset.category === 'tradeorgs';
}

/**
 * Give formData.tradeOrg every field. Fills the object in place: list editors hold
 * references to its arrays, so it is never swapped for a new one.
 */
function ensureTradeOrgDataStructures() {
    if (!window.formData) return;
    let org = window.formData.tradeOrg;
    if (!org || typeof org !== 'object' || Array.isArray(org)) {
        org = createDefaultTradeOrg();
        window.formData.tradeOrg = org;
    }
    TRADEORG_TEXT_FIELDS.forEach(field => {
        if (typeof org[field] !== 'string') {
            org[field] = org[field] == null ? '' : String(org[field]);
        }
    });
    TRADEORG_LIST_FIELDS.forEach(field => {
        if (!Array.isArray(org[field])) {
            org[field] = typeof org[field] === 'string' && org[field].trim() ? [org[field].trim()] : [];
        }
    });
    if (!org.activities || typeof org.activities !== 'object') org.activities = {};
    TRADEORG_ACTIVITY_KEYS.forEach(key => { org.activities[key] = !!org.activities[key]; });
    if (!org.fieldNotes || typeof org.fieldNotes !== 'object') org.fieldNotes = {};
}

/** What a trade organization save or submission sends: the tradeOrg block, list blanks dropped. */
function tradeOrgSubmissionData(data, projectName) {
    const source = (data && data.tradeOrg) || createDefaultTradeOrg();
    const org = JSON.parse(JSON.stringify(source));
    TRADEORG_LIST_FIELDS.forEach(field => {
        org[field] = (Array.isArray(org[field]) ? org[field] : [])
            .map(item => (typeof item === 'string' ? item.trim() : ''))
            .filter(Boolean);
    });
    const out = { tradeOrg: org, category: 'tradeorgs' };
    if (projectName) {
        out.projectName = projectName;
        out.name = projectName;
    }
    if (data && data.documentFolderId) out.documentFolderId = data.documentFolderId;
    return out;
}

function tradeOrgFieldId(field) {
    return 'tradeorg-' + field.replace(/[A-Z]/g, letter => '-' + letter.toLowerCase());
}

/** Fill the Trade Organizations tab from formData (only while that tab is active). */
function loadTradeOrgData() {
    if (!isTradeOrgContext()) return;
    ensureTradeOrgDataStructures();
    const org = window.formData.tradeOrg;

    TRADEORG_TEXT_FIELDS.forEach(field => {
        const el = document.getElementById(tradeOrgFieldId(field));
        if (el) el.value = org[field] || '';
    });

    document.querySelectorAll('.tradeorg-activity[data-activity]').forEach(box => {
        box.checked = !!org.activities[box.dataset.activity];
    });

    if (typeof renderArray === 'function') {
        TRADEORG_LIST_FIELDS.forEach(field => {
            const path = 'tradeOrg.' + field;
            const container = document.querySelector(`[data-path="${path}"]`);
            if (container) renderArray(container, path, org[field]);
        });
    }

    const nameInput = document.getElementById('tradeorg-project-name');
    if (nameInput && window.currentProjectName) {
        if (!nameInput.value) nameInput.value = window.currentProjectName;
        nameInput.dataset.followsName = '0';
    }
}

/** Show the trade organization editor on its tab; hide the other editors there. */
function handleTradeOrgToggle() {
    const wrapper = document.getElementById('tradeorg-main-wrapper');
    const show = el => { if (el) { el.classList.remove('view-hidden'); el.style.display = ''; } };
    const hide = el => { if (el) { el.classList.add('view-hidden'); el.style.display = ''; } };
    if (isTradeOrgContext()) {
        ensureTradeOrgDataStructures();
        hide(document.getElementById('facility-main-wrapper'));
        hide(document.querySelector('.facility-loader-panel'));
        hide(document.getElementById('referrer-main-wrapper'));
        hide(document.getElementById('transporter-main-wrapper'));
        hide(document.getElementById('marketer-main-wrapper'));
        show(wrapper);
    } else {
        hide(wrapper);
    }
}

function updateTradeOrg(mutator) {
    ensureTradeOrgDataStructures();
    mutator(window.formData.tradeOrg);
    if (typeof updateJSON === 'function') updateJSON();
    if (typeof autoSave === 'function') autoSave();
}

/**
 * Delegated on the document: ui-events.js swaps text inputs for growing textareas at load,
 * which would drop listeners bound to the original elements.
 */
function attachTradeOrgFieldListeners() {
    if (document.documentElement.dataset.tradeorgFieldsBound) return;
    document.documentElement.dataset.tradeorgFieldsBound = 'true';
    const fieldById = {};
    TRADEORG_TEXT_FIELDS.forEach(field => { fieldById[tradeOrgFieldId(field)] = field; });

    document.addEventListener('input', e => {
        const el = e.target;
        if (!el || !el.id) return;
        if (el.id === 'tradeorg-project-name') {
            el.dataset.followsName = '0';
            return;
        }
        const field = fieldById[el.id];
        if (!field) return;
        const value = el.value;
        updateTradeOrg(org => { org[field] = value; });
        // A new org's name fills the project name until someone types another there;
        // a saved record keeps its project name (renaming it would make a second record)
        if (field === 'name') {
            const nameInput = document.getElementById('tradeorg-project-name');
            if (nameInput && (!nameInput.value || nameInput.dataset.followsName === '1')) {
                nameInput.value = value;
                nameInput.dataset.followsName = '1';
            }
        }
    }, { passive: true });

    document.addEventListener('change', e => {
        const box = e.target;
        if (!box || !box.classList || !box.classList.contains('tradeorg-activity')) return;
        const key = box.dataset.activity;
        const on = box.checked;
        updateTradeOrg(org => { org.activities[key] = on; });
    });
}

function tradeOrgInSuggestionMode() {
    return !!(window.KOP_FormConfig &&
        typeof window.KOP_FormConfig.isSuggestionMode === 'function' &&
        window.KOP_FormConfig.isSuggestionMode());
}

async function saveTradeOrgProject() {
    ensureTradeOrgDataStructures();
    const nameInput = document.getElementById('tradeorg-project-name');
    let projectName = (nameInput && nameInput.value.trim()) || '';
    if (!projectName) projectName = (window.formData.tradeOrg.name || '').trim();
    if (!projectName) {
        const ask = typeof customAlert === 'function' ? customAlert : (msg) => Promise.resolve(alert(msg));
        ask('Please enter the organization\'s name', 'Name Required').then(() => {
            const el = document.getElementById('tradeorg-name');
            if (el) el.focus();
        });
        return;
    }
    if (nameInput) nameInput.value = projectName;

    window.formData.category = 'tradeorgs';
    if (!window.projects) window.projects = {};
    window.projects[projectName] = Object.assign(window.projects[projectName] || {}, { category: 'tradeorgs' });
    window.currentProjectName = projectName;

    // Public form: the suggestion flow (reason, follow-up email) posts to api/save-suggestion.php
    if (tradeOrgInSuggestionMode()) {
        if (typeof window.submitSuggestion === 'function') {
            window.submitSuggestion();
        } else if (typeof showUploadStatus === 'function') {
            showUploadStatus('Submission flow not loaded. Please refresh the page.', 'error');
        }
        return;
    }

    // Admin form: straight to tradeorgs_master
    if (window.KOP_API && typeof window.KOP_API.saveProjectToCloud === 'function') {
        try {
            await window.KOP_API.saveProjectToCloud(projectName);
        } catch (err) {
            console.error('Failed to save trade organization project:', err);
            if (typeof showUploadStatus === 'function') showUploadStatus('Failed to save: ' + (err.message || 'Unknown error'), 'error');
        }
    } else if (typeof showUploadStatus === 'function') {
        showUploadStatus('Save function not available. Please refresh the page.', 'error');
    }
}

function attachTradeOrgProjectButtons() {
    const bind = (id, handler) => {
        const btn = document.getElementById(id);
        if (btn && !btn.dataset.listenerAttached) {
            btn.addEventListener('click', handler);
            btn.dataset.listenerAttached = 'true';
        }
    };
    bind('new-project-btn-tradeorg', () => {
        if (typeof window.newProject === 'function') window.newProject();
    });
    bind('export-all-btn-tradeorg', () => {
        if (typeof window.exportProjectsToFile === 'function') {
            window.exportProjectsToFile({ categories: ['tradeorgs'], filename: 'projects-export-tradeorgs.json' });
        }
    });
    bind('generate-report-btn-tradeorg', () => {
        if (typeof window.generateProjectsReport === 'function') {
            window.generateProjectsReport({ categories: ['tradeorgs'], filename: 'projects-report-tradeorgs.json' });
        }
    });
    bind('save-tradeorg-project-btn', saveTradeOrgProject);
}

function initTradeOrgForm() {
    attachTradeOrgFieldListeners();
    attachTradeOrgProjectButtons();
    handleTradeOrgToggle();
    loadTradeOrgData();
    const tabs = document.querySelector('.category-tabs');
    if (tabs && !tabs.dataset.tradeorgBound) {
        // After project.js has switched tabs and reset the form
        tabs.addEventListener('click', () => setTimeout(() => { handleTradeOrgToggle(); loadTradeOrgData(); }, 0));
        tabs.dataset.tradeorgBound = 'true';
    }
}

window.TRADEORG_ACTIVITY_KEYS = TRADEORG_ACTIVITY_KEYS;
window.createDefaultTradeOrg = createDefaultTradeOrg;
window.isTradeOrgContext = isTradeOrgContext;
window.ensureTradeOrgDataStructures = ensureTradeOrgDataStructures;
window.tradeOrgSubmissionData = tradeOrgSubmissionData;
window.loadTradeOrgData = loadTradeOrgData;
window.handleTradeOrgToggle = handleTradeOrgToggle;
window.attachTradeOrgFieldListeners = attachTradeOrgFieldListeners;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTradeOrgForm);
} else {
    initTradeOrgForm();
}
