/**
 * Mental Health Provider Form Module
 *
 * The "providers" category covers mental health providers that are not part of
 * the TTI but use its practices and refer children into it: psychiatric wards,
 * PHP/IOP programs, day schools, respite care and outpatient therapy.
 *
 * It reuses the facility editor. Each provider site is an entry in
 * formData.facilities, with the provider-only fields under
 * facility.providerDetails. Projects save to providers_master, never to the
 * facility tables, so providers do not appear as TTI facilities.
 */

const PROVIDER_CATEGORY = 'providers';

function createDefaultProviderDetails() {
    return {
        careTypes: {},
        otherCareTypes: [],
        ttiPractices: {},
        otherTtiPractices: [],
        ttiReferrals: [],
        transportersUsed: [],
        ttiAffiliations: [],
        referralNotes: ''
    };
}

function isProviderContext() {
    const activeTab = document.querySelector('.category-tab.active');
    if (activeTab) {
        return activeTab.dataset.category === PROVIDER_CATEGORY;
    }
    return window.formData?.category === PROVIDER_CATEGORY;
}

/**
 * In the providers view, tag the project and give every site a providerDetails
 * block. Outside it, leave the data alone (stripProviderData cleans submissions).
 */
function ensureProviderDataStructures() {
    if (!window.formData || !isProviderContext()) {
        return;
    }

    window.formData.category = PROVIDER_CATEGORY;
    const facilities = Array.isArray(window.formData.facilities) ? window.formData.facilities : [];
    facilities.forEach(facility => {
        if (!facility || typeof facility !== 'object') return;
        const details = Object.assign(createDefaultProviderDetails(), facility.providerDetails || {});
        ['otherCareTypes', 'otherTtiPractices', 'ttiReferrals', 'transportersUsed', 'ttiAffiliations'].forEach(field => {
            if (!Array.isArray(details[field])) details[field] = [];
        });
        ['careTypes', 'ttiPractices'].forEach(field => {
            if (!details[field] || typeof details[field] !== 'object' || Array.isArray(details[field])) details[field] = {};
        });
        facility.providerDetails = details;
    });
}

/**
 * Remove provider-only data from a payload that is not a provider project, so
 * company and location records never carry it.
 */
function stripProviderData(data) {
    if (!data || typeof data !== 'object') {
        return;
    }
    if (data.category === PROVIDER_CATEGORY) {
        delete data.category;
    }
    (Array.isArray(data.facilities) ? data.facilities : []).forEach(facility => {
        if (facility && typeof facility === 'object') {
            delete facility.providerDetails;
        }
    });
}

// [section id, facility-form wording, provider wording]. Matched as a substring
// of text-only headings and labels, so an emoji prefix is kept. The operator
// section title, operator name and current-operator labels are set by
// updateLabelsForProjectType (js/data-form-modules/ui-state.js), not here.
const PROVIDER_LABELS = [
    ['operator-section', 'Parent Company Notes', 'Parent Organization Notes'],
    ['operator-section', 'Parent Companies', 'Parent Organizations'],
    ['identification-section', 'Facility Ownership', 'Provider Ownership'],
    ['identification-section', 'Known Referrers', 'Referrers They Work With'],
    ['operations-section', 'Facility Operations', 'Provider Operations'],
    ['operations-section', 'Other Parent Companies', 'Other Parent Organizations'],
    ['operations-section', 'Facility Operating Dates', 'Operating Dates'],
    ['operations-section', 'Facility Opened (Year)', 'Opened (Year)'],
    ['operations-section', 'Facility Closed (Year)', 'Closed (Year)'],
    ['staff-section', 'Staff & Links', 'Staff & TTI Connections'],
    ['staff-section', 'Key Staff Positions', 'Clinicians & Staff'],
    ['staff-section', 'Administrator', 'Medical / Program Director'],
    ['staff-section', 'Notable Staff', 'Clinicians & Other Staff'],
    ['facility-section', 'Facility Details', 'Program Details'],
    ['facility-section', 'Program Type', 'Program / Unit Name'],
    ['facility-section', 'Capacity', 'Beds / Capacity'],
    ['incidents-section', 'Student Hospitalizations', 'Patient Hospitalizations'],
    ['notes-section', 'Facility Notes', 'Provider Notes'],
    ['quick-loader-heading', 'Jump to Facility', 'Jump to Provider Site'],
    ['quick-loader-label', 'All Facilities in Current Project', 'All Sites in Current Project']
];

// Text node => its facility wording, so switching back restores it exactly.
const providerLabelOriginals = new WeakMap();

function applyProviderLabels() {
    const useProviderWording = isProviderContext();

    // Rewrite text nodes rather than textContent: labels also hold the
    // tooltip "?" icon and note buttons, which must survive.
    const rewrites = new Map();
    PROVIDER_LABELS.forEach(([rootId, facilityText, providerText]) => {
        const root = document.getElementById(rootId);
        if (!root) return;
        const candidates = root.matches('h2, h3, h4, label') ? [root] : root.querySelectorAll('h2, h3, h4, label');
        candidates.forEach(el => {
            el.childNodes.forEach(node => {
                if (node.nodeType !== Node.TEXT_NODE) return;
                if (!providerLabelOriginals.has(node)) {
                    if (!node.nodeValue.includes(facilityText)) return;
                    providerLabelOriginals.set(node, node.nodeValue);
                }
                const original = providerLabelOriginals.get(node);
                if (!original.includes(facilityText)) return;
                if (!rewrites.has(node)) rewrites.set(node, original);
                rewrites.set(node, rewrites.get(node).replace(facilityText, providerText));
            });
        });
    });

    rewrites.forEach((providerValue, node) => {
        node.nodeValue = useProviderWording ? providerValue : providerLabelOriginals.get(node);
    });
}

/**
 * Called on tab switch and project load, next to handleReferrerToggle and
 * handleTransporterToggle.
 */
function handleProviderToggle() {
    ensureProviderDataStructures();
    applyProviderLabels();
}

window.createDefaultProviderDetails = createDefaultProviderDetails;
window.isProviderContext = isProviderContext;
window.ensureProviderDataStructures = ensureProviderDataStructures;
window.stripProviderData = stripProviderData;
window.applyProviderLabels = applyProviderLabels;
window.handleProviderToggle = handleProviderToggle;
