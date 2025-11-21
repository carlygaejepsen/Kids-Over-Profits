/**
 * Referrer Form Module
 * Handles all referrer-specific functionality including:
 * - Referrer data structures (agencies and individual consultants)
 * - Loading and saving referrer data
 * - Referrer form field event handlers
 * - Referrer UI updates
 */

// ============================================
// REFERRER DATA STRUCTURES
// ============================================

/**
 * Create default referrer group/agency structure
 */
function createDefaultReferrerGroup() {
    return {
        name: "",
        city: "",
        state: "",
        website: "",
        address: "",
        founded: "",
        affiliations: [],
        keyPersonnel: [],
        notes: "",
        fieldNotes: {}
    };
}

/**
 * Create default individual consultant structure
 */
function createDefaultReferrerIndividual() {
    return {
        firstName: "",
        lastName: "",
        fullName: "",
        role: "",
        status: "",
        education: "",
        credentials: "",
        city: "",
        state: "",
        email: "",
        phone: "",
        website: "",
        affiliations: [],
        facilitiesReferred: [],
        knownReferrals: [],
        pastTTIJobs: [],
        schoolDistricts: [],
        lawsuits: "",
        notes: "",
        fieldNotes: {}
    };
}

/**
 * Build referrer entries from form data for backward compatibility
 */
function buildReferrerEntries(formData) {
    const source = formData || {};
    const agency = (source.referrerAgency && typeof source.referrerAgency === 'object')
        ? source.referrerAgency
        : createDefaultReferrerGroup();

    const rawKeyPersonnel = Array.isArray(agency.keyPersonnel) ? agency.keyPersonnel : [];
    const keyPersonnel = rawKeyPersonnel
        .map(person => (typeof person === 'string' ? person.trim() : ''))
        .filter(person => person);

    const dataTemplate = {};
    keyPersonnel.forEach((person, index) => {
        dataTemplate[`referrerAgency.keyPersonnel.${index}`] = [person];
    });

    const cloneTemplate = () => {
        const clone = {};
        Object.entries(dataTemplate).forEach(([key, value]) => {
            clone[key] = Array.isArray(value) ? value.slice() : value;
        });
        return clone;
    };

    const consultants = Array.isArray(source.referrerConsultants) ? source.referrerConsultants : [];
    const agencyName = (agency.name || '').trim();

    if (!consultants.length) {
        return [{
            name: agencyName,
            data: cloneTemplate(),
            referrerAgency: {
                keyPersonnel: keyPersonnel.slice()
            },
            consultant: {
                affiliations: [],
                facilitiesReferred: [],
                schoolDistricts: []
            }
        }];
    }

    return consultants.map(consultant => {
        const affiliations = Array.isArray(consultant?.affiliations) ? consultant.affiliations.filter(item => item !== undefined && item !== null) : [];
        const facilities = Array.isArray(consultant?.facilitiesReferred) ? consultant.facilitiesReferred.filter(item => item !== undefined && item !== null) : [];
        const districts = Array.isArray(consultant?.schoolDistricts) ? consultant.schoolDistricts.filter(item => item !== undefined && item !== null) : [];
        const consultantName = [consultant?.firstName, consultant?.lastName]
            .map(part => (typeof part === 'string' ? part.trim() : ''))
            .filter(Boolean)
            .join(' ');

        return {
            name: agencyName || consultantName,
            data: cloneTemplate(),
            referrerAgency: {
                keyPersonnel: keyPersonnel.slice()
            },
            consultant: {
                affiliations: affiliations.map(item => typeof item === 'string' ? item.trim() : String(item || '').trim()),
                facilitiesReferred: facilities.map(item => typeof item === 'string' ? item.trim() : String(item || '').trim()),
                schoolDistricts: districts.map(item => typeof item === 'string' ? item.trim() : String(item || '').trim())
            }
        };
    });
}

/**
 * Ensure referrer data structures exist and are properly initialized
 */
function ensureReferrerDataStructures() {
    if (!window.formData) {
        return;
    }

    const legacyAgency = window.formData.referrerAgency || window.formData.referrerGroup || {};
    const agency = Object.assign(createDefaultReferrerGroup(), legacyAgency);
    if (!Array.isArray(agency.affiliations)) {
        agency.affiliations = [];
    }
    if (!Array.isArray(agency.keyPersonnel)) {
        agency.keyPersonnel = [];
    }
    window.formData.referrerAgency = agency;
    window.formData.referrerGroup = agency;

    if (!Array.isArray(window.formData.referrerConsultants)) {
        window.formData.referrerConsultants = [];
    }

    // Merge legacy single referrerIndividual objects into the consultants array
    if (window.formData.referrerIndividual && window.formData.referrerConsultants.length === 0) {
        window.formData.referrerConsultants.push(window.formData.referrerIndividual);
    }

    window.formData.referrerConsultants = window.formData.referrerConsultants.map((consultant) => {
        const merged = Object.assign(createDefaultReferrerIndividual(), consultant || {});
        if (!Array.isArray(merged.affiliations)) {
            merged.affiliations = [];
        }
        if (!Array.isArray(merged.knownReferrals)) {
            merged.knownReferrals = Array.isArray(merged.facilitiesReferred) ? merged.facilitiesReferred.slice() : [];
        }
        // Keep legacy facilitiesReferred array in sync with new knownReferrals field
        merged.facilitiesReferred = merged.knownReferrals;
        if (!Array.isArray(merged.pastTTIJobs)) {
            merged.pastTTIJobs = [];
        }
        if (!merged.fullName) {
            const fullName = [merged.firstName, merged.lastName].filter(Boolean).join(' ');
            merged.fullName = fullName;
        }
        if (!merged.education && merged.credentials) {
            merged.education = merged.credentials;
        }
        return merged;
    });

    if (window.formData.referrerConsultants.length === 0) {
        window.formData.referrerConsultants.push(createDefaultReferrerIndividual());
    }

    if (typeof window.currentConsultantIndex !== 'number' || window.currentConsultantIndex < 0) {
        window.currentConsultantIndex = 0;
    }
    if (window.currentConsultantIndex >= window.formData.referrerConsultants.length) {
        window.currentConsultantIndex = 0;
    }

    const activeConsultant = window.formData.referrerConsultants[window.currentConsultantIndex] || createDefaultReferrerIndividual();
    window.formData.referrerConsultants[window.currentConsultantIndex] = activeConsultant;
    window.formData.referrerIndividual = activeConsultant;

    if (!window.formData.referrerType) {
        window.formData.referrerType = window.formData.isIndependentConsultant ? 'individual' : 'group';
    }

    window.formData.referrer = buildReferrerEntries(window.formData);
}

/**
 * Get all referrer names from projects (for autocomplete)
 */
function getAllReferrers() {
    if (!window.aggregatedDataCache) {
        window.aggregatedDataCache = {};
    }

    if (!window.aggregatedDataCache.referrers) {
        const referrers = new Set(window.customReferrers || []);

        Object.values(window.projects || {}).forEach(project => {
            // Collect from facility projects' knownReferrers fields
            project.data?.facilities?.forEach(facility => {
                facility.identification?.knownReferrers?.forEach(ref => {
                    if (ref && typeof ref === 'string') {
                        referrers.add(ref);
                    }
                });
            });

            // Collect from referrer projects themselves
            if (project.category === 'referrers' && project.data) {
                // Add referrer agency name
                if (project.data.referrerAgency?.name) {
                    referrers.add(project.data.referrerAgency.name);
                }

                // Add individual consultant names
                if (Array.isArray(project.data.referrerConsultants)) {
                    project.data.referrerConsultants.forEach(consultant => {
                        if (consultant) {
                            // Use fullName if available, otherwise construct from firstName + lastName
                            const name = consultant.fullName ||
                                [consultant.firstName, consultant.lastName].filter(Boolean).join(' ');
                            if (name && name.trim()) {
                                referrers.add(name.trim());
                            }
                        }
                    });
                }
            }
        });

        window.aggregatedDataCache.referrers = Array.from(referrers).filter(r => r && typeof r === 'string' && r.trim()).sort();
    }

    return window.aggregatedDataCache.referrers;
}

// ============================================
// REFERRER UI FUNCTIONS
// ============================================

/**
 * Load referrer data into form fields
 */
function loadReferrerData() {
    ensureReferrerDataStructures();

    const agency = window.formData.referrerAgency || createDefaultReferrerGroup();

    if (typeof debugLog === 'function') {
        debugLog('📋 loadReferrerData called', {
            'currentProject': window.currentProjectName,
            'referrerAgency': agency,
            'referrerIndividual': window.formData.referrerIndividual,
            'referrerConsultants': window.formData.referrerConsultants
        });
    }

    const groupFieldMap = [
        { ids: ['referrer-group-name', 'referrer-agency-name'], key: 'name' },
        { ids: ['referrer-group-city', 'referrer-agency-city'], key: 'city' },
        { ids: ['referrer-group-state', 'referrer-agency-state'], key: 'state' },
        { ids: ['referrer-group-website', 'referrer-agency-website'], key: 'website' },
        { ids: ['referrer-group-address'], key: 'address' },
        { ids: ['referrer-group-founded'], key: 'founded' },
        { ids: ['referrer-group-notes', 'referrer-agency-notes'], key: 'notes' },
    ];

    groupFieldMap.forEach(({ ids, key }) => {
        ids.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.value = agency[key] || '';
                if (typeof debugLog === 'function') {
                    debugLog(`  ✓ Set ${id} = "${agency[key] || ''}"`);
                }
            }
        });
    });

    const groupAffiliationsContainer = document.querySelector('[data-path="referrerGroup.affiliations"]');
    if (groupAffiliationsContainer && typeof renderArray === 'function') {
        if (!Array.isArray(agency.affiliations)) {
            agency.affiliations = [];
        }
        renderArray(groupAffiliationsContainer, 'referrerGroup.affiliations', agency.affiliations);
    }

    const keyPersonnelContainer = document.querySelector('[data-path="referrerAgency.keyPersonnel"]');
    if (keyPersonnelContainer && typeof renderArray === 'function') {
        if (!Array.isArray(agency.keyPersonnel)) {
            agency.keyPersonnel = [];
        }
        renderArray(keyPersonnelContainer, 'referrerAgency.keyPersonnel', agency.keyPersonnel);
    }

    const referrerType = window.formData.referrerType || (window.formData.isIndependentConsultant ? 'individual' : 'group');
    if (typeof window.applyReferrerToggleState === 'function') {
        window.applyReferrerToggleState(referrerType === 'individual');
    }

    const consultant = window.formData.referrerIndividual || window.formData.referrerConsultants[window.currentConsultantIndex] || createDefaultReferrerIndividual();
    window.formData.referrerIndividual = consultant;
    if (typeof debugLog === 'function') {
        debugLog('📋 Consultant data:', consultant);
    }

    const consultantName = consultant.fullName || [consultant.firstName, consultant.lastName].filter(Boolean).join(' ');
    const individualFieldMap = [
        { ids: ['referrer-individual-name'], value: consultantName },
        { ids: ['referrer-individual-role'], value: consultant.role || '' },
        { ids: ['referrer-individual-status'], value: consultant.status || '' },
        { ids: ['referrer-individual-education'], value: consultant.education || consultant.credentials || '' },
        { ids: ['referrer-individual-lawsuits'], value: consultant.lawsuits || '' },
        { ids: ['referrer-individual-notes'], value: consultant.notes || '' },
    ];

    individualFieldMap.forEach(({ ids, value }) => {
        ids.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.value = value;
            }
        });
    });

    if (!Array.isArray(consultant.pastTTIJobs)) {
        consultant.pastTTIJobs = [];
    }
    if (!Array.isArray(consultant.knownReferrals)) {
        consultant.knownReferrals = [];
    }
    if (!Array.isArray(consultant.affiliations)) {
        consultant.affiliations = [];
    }
    if (!Array.isArray(consultant.facilitiesReferred)) {
        consultant.facilitiesReferred = consultant.knownReferrals.slice();
    }
    if (!Array.isArray(consultant.schoolDistricts)) {
        consultant.schoolDistricts = [];
    }

    const individualArrays = [
        { path: 'referrerIndividual.pastTTIJobs', data: consultant.pastTTIJobs },
        { path: 'referrerIndividual.knownReferrals', data: consultant.knownReferrals },
        { path: 'referrerIndividual.affiliations', data: consultant.affiliations },
        { path: 'consultant.facilitiesReferred', data: consultant.facilitiesReferred },
        { path: 'consultant.schoolDistricts', data: consultant.schoolDistricts }
    ];

    if (typeof renderArray === 'function') {
        individualArrays.forEach(({ path, data }) => {
            const container = document.querySelector(`[data-path="${path}"]`);
            if (container) {
                renderArray(container, path, data);
            }
        });
    }
}

/**
 * Handle showing/hiding referrer vs facility forms based on active category
 */
function handleReferrerToggle() {
    const activeTab = document.querySelector('.category-tab.active');
    const activeCategory = activeTab ? activeTab.dataset.category : 'companies';

    const referrerMainWrapper = document.getElementById('referrer-main-wrapper');
    const facilityMainWrapper = document.getElementById('facility-main-wrapper');

    const showElement = (element) => {
        if (!element) return;
        element.classList.remove('view-hidden');
        element.style.display = '';
    };

    const hideElement = (element) => {
        if (!element) return;
        element.classList.add('view-hidden');
        element.style.display = '';
    };

    if (activeCategory === 'referrers') {
        hideElement(facilityMainWrapper);
        showElement(referrerMainWrapper);
        if (typeof window.updateAgencySliderAppearance === 'function') {
            window.updateAgencySliderAppearance();
        }
    } else {
        hideElement(referrerMainWrapper);
        showElement(facilityMainWrapper);
    }
}

// ============================================
// REFERRER EVENT HANDLERS
// ============================================

/**
 * Attach event listeners to referrer form fields
 */
function attachReferrerFieldListeners() {
    // Referrer agency fields
    const updateReferrerAgency = (mutator) => {
        ensureReferrerDataStructures();
        const agency = window.formData.referrerAgency;
        mutator(agency);
        window.formData.referrerGroup = agency;
        window.formData.referrer = buildReferrerEntries(window.formData);
        if (typeof updateJSON === 'function') updateJSON();
        if (typeof autoSave === 'function') autoSave();
    };

    const referrerAgencyFieldHandlers = {
        'referrer-agency-name': (val) => updateReferrerAgency(agency => { agency.name = val; }),
        'referrer-group-name': (val) => updateReferrerAgency(agency => { agency.name = val; }),
        'referrer-agency-city': (val) => updateReferrerAgency(agency => { agency.city = val; }),
        'referrer-group-city': (val) => updateReferrerAgency(agency => { agency.city = val; }),
        'referrer-agency-state': (val) => updateReferrerAgency(agency => { agency.state = val; }),
        'referrer-group-state': (val) => updateReferrerAgency(agency => { agency.state = val; }),
        'referrer-agency-website': (val) => updateReferrerAgency(agency => { agency.website = val; }),
        'referrer-group-website': (val) => updateReferrerAgency(agency => { agency.website = val; }),
        'referrer-group-address': (val) => updateReferrerAgency(agency => { agency.address = val; }),
        'referrer-group-founded': (val) => updateReferrerAgency(agency => { agency.founded = val; }),
        'referrer-agency-notes': (val) => updateReferrerAgency(agency => { agency.notes = val; }),
        'referrer-group-notes': (val) => updateReferrerAgency(agency => { agency.notes = val; })
    };

    Object.entries(referrerAgencyFieldHandlers).forEach(([id, handler]) => {
        const el = document.getElementById(id);
        if (el && !el.dataset.listenerAttached) {
            el.addEventListener('input', (e) => handler(e.target.value), { passive: true });
            el.dataset.listenerAttached = 'true';
        }
    });

    const updateReferrerConsultant = (mutator) => {
        ensureReferrerDataStructures();
        const index = window.currentConsultantIndex || 0;
        if (!Array.isArray(window.formData.referrerConsultants)) {
            window.formData.referrerConsultants = [createDefaultReferrerIndividual()];
        }
        const consultant = window.formData.referrerConsultants[index] || createDefaultReferrerIndividual();
        mutator(consultant);
        window.formData.referrerConsultants[index] = consultant;
        window.formData.referrerIndividual = consultant;
        window.formData.referrer = buildReferrerEntries(window.formData);
        if (typeof updateJSON === 'function') updateJSON();
        if (typeof autoSave === 'function') autoSave();
    };

    const referrerIndividualFieldHandlers = {
        'referrer-individual-name': (val) => {
            updateReferrerConsultant(consultant => {
                consultant.fullName = val;
                if (typeof val === 'string') {
                    const trimmed = val.trim();
                    if (trimmed.length) {
                        const parts = trimmed.split(/\s+/);
                        consultant.firstName = parts.shift() || '';
                        consultant.lastName = parts.length ? parts.join(' ') : '';
                    } else {
                        consultant.firstName = '';
                        consultant.lastName = '';
                    }
                } else {
                    consultant.firstName = '';
                    consultant.lastName = '';
                }
            });
        },
        'referrer-individual-role': (val) => updateReferrerConsultant(consultant => { consultant.role = val; }),
        'referrer-individual-status': (val) => updateReferrerConsultant(consultant => { consultant.status = val; }),
        'referrer-individual-education': (val) => updateReferrerConsultant(consultant => {
            consultant.education = val;
            consultant.credentials = val;
        }),
        'referrer-individual-lawsuits': (val) => updateReferrerConsultant(consultant => { consultant.lawsuits = val; }),
        'referrer-individual-notes': (val) => updateReferrerConsultant(consultant => { consultant.notes = val; })
    };

    Object.entries(referrerIndividualFieldHandlers).forEach(([id, handler]) => {
        const el = document.getElementById(id);
        if (el && !el.dataset.listenerAttached) {
            const eventName = el.tagName === 'SELECT' ? 'change' : 'input';
            el.addEventListener(eventName, (e) => handler(e.target.value), { passive: true });
            el.dataset.listenerAttached = 'true';
        }
    });

    // Individual consultant fields - attach via class selector
    const consultantFields = document.querySelectorAll('.consultant-field');
    consultantFields.forEach(field => {
        if (!field.dataset.listenerAttached) {
            field.addEventListener('input', (e) => {
                ensureReferrerDataStructures();
                const fieldName = e.target.dataset.field;
                const consultantIndex = window.currentConsultantIndex || 0;
                if (!window.formData.referrerConsultants[consultantIndex]) {
                    window.formData.referrerConsultants[consultantIndex] = createDefaultReferrerIndividual();
                }
                window.formData.referrerConsultants[consultantIndex][fieldName] = e.target.value;
                if (typeof updateJSON === 'function') updateJSON();
                if (typeof autoSave === 'function') autoSave();
            }, { passive: true });
            field.dataset.listenerAttached = 'true';
        }
    });

    // Independent consultant toggle
    const independentToggle = document.getElementById('referrer-independent-toggle');
    if (independentToggle && !independentToggle.dataset.listenerAttached) {
        independentToggle.addEventListener('change', (e) => {
            ensureReferrerDataStructures();
            window.formData.isIndependentConsultant = e.target.checked;
            if (typeof window.updateAgencySliderAppearance === 'function') {
                window.updateAgencySliderAppearance();
            }
            if (typeof updateJSON === 'function') updateJSON();
            if (typeof autoSave === 'function') autoSave();
        }, { passive: true });
        independentToggle.dataset.listenerAttached = 'true';
    }
}

// ============================================
// EXPORTS & INITIALIZATION
// ============================================

// Expose functions to window for global access
window.createDefaultReferrerGroup = createDefaultReferrerGroup;
window.createDefaultReferrerIndividual = createDefaultReferrerIndividual;
window.buildReferrerEntries = buildReferrerEntries;
window.ensureReferrerDataStructures = ensureReferrerDataStructures;
window.getAllReferrers = getAllReferrers;
window.loadReferrerData = loadReferrerData;
window.handleReferrerToggle = handleReferrerToggle;
window.attachReferrerFieldListeners = attachReferrerFieldListeners;

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        console.log('📋 Referrer form module loaded');
    });
} else {
    console.log('📋 Referrer form module loaded');
}
