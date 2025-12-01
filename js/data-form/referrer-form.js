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
        const mergedNameCandidates = [
            (merged.fullName || '').trim(),
            (merged.name || '').trim(),
            [merged.firstName, merged.lastName].filter(Boolean).join(' ').trim()
        ].filter(Boolean);
        if (mergedNameCandidates.length) {
            const bestName = mergedNameCandidates.reduce((winner, candidate) => candidate.length > winner.length ? candidate : winner, '');
            merged.fullName = bestName;
            if (!merged.firstName && !merged.lastName) {
                const parts = bestName.split(/\s+/);
                merged.firstName = parts.shift() || '';
                merged.lastName = parts.join(' ');
            }
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

    // Ensure currentConsultantIndex is valid
    const consultants = window.formData.referrerConsultants || [];
    if (typeof window.currentConsultantIndex !== 'number' || window.currentConsultantIndex < 0 || window.currentConsultantIndex >= consultants.length) {
        window.currentConsultantIndex = 0;
    }

    const consultant = window.formData.referrerIndividual || consultants[window.currentConsultantIndex] || createDefaultReferrerIndividual();
    window.formData.referrerIndividual = consultant;
    if (typeof debugLog === 'function') {
        debugLog('📋 Consultant data:', consultant);
    }

    // Normalize name parts so UI never collapses to a single-letter fullName
    const mergedNameCandidates = [
        (consultant.fullName || '').trim(),
        (consultant.name || '').trim(),
        [consultant.firstName, consultant.lastName].filter(Boolean).join(' ').trim()
    ].filter(Boolean);
    if (mergedNameCandidates.length) {
        const bestName = mergedNameCandidates.reduce((winner, candidate) => candidate.length > winner.length ? candidate : winner, '');
        consultant.fullName = bestName;
        if (!consultant.firstName && !consultant.lastName) {
            const parts = bestName.split(/\s+/);
            consultant.firstName = parts.shift() || '';
            consultant.lastName = parts.join(' ');
        }
    }

    // Load consultant fields automatically using data-field attributes (like facilities do)
    // This matches the HTML structure with class="consultant-field" data-field="firstName" etc.
    document.querySelectorAll('.consultant-field').forEach(field => {
        const fieldName = field.dataset.field;
        if (fieldName) {
            // Get value from consultant object
            let value = consultant[fieldName];

            // Handle special cases
            if (fieldName === 'credentials' && !value) {
                value = consultant.education; // Fallback to education
            }

            // Set field value
            if (field.type === 'checkbox') {
                field.checked = !!value;
            } else {
                field.value = value ?? '';
            }

            if (typeof debugLog === 'function') {
                debugLog(`  ✓ Set consultant field ${fieldName} = "${value || ''}"`);
            }
        }
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
        { path: 'consultant.affiliations', data: consultant.affiliations },
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
// CONSULTANT NAVIGATION & UI FUNCTIONS
// ============================================

/**
 * Get consultant display name with fallbacks
 */
function getConsultantDisplayName(consultant, index) {
    if (!consultant) {
        return `Consultant ${index + 1}`;
    }

    const fullName = (consultant.fullName || '').trim();
    const nameField = (consultant.name || '').trim();
    const firstLast = [consultant.firstName, consultant.lastName].map(part => (part || '').trim()).filter(Boolean).join(' ').trim();

    // Prefer the longest available string so a 1-letter fullName doesn't win
    const candidates = [fullName, nameField, firstLast].filter(Boolean);
    const best = candidates.reduce((winner, candidate) => {
        if (!winner) return candidate;
        return candidate.length > winner.length ? candidate : winner;
    }, '');

    const displayName = best || `Consultant ${index + 1}`;

    // Backfill missing name parts to keep downstream code consistent
    if (!consultant.fullName && best) {
        consultant.fullName = best;
    }
    if (!consultant.firstName && !consultant.lastName && best) {
        const parts = best.split(/\s+/);
        if (parts.length > 1) {
            consultant.firstName = parts.shift();
            consultant.lastName = parts.join(' ');
        } else {
            consultant.firstName = best;
        }
    }

    return displayName;
}

/**
 * Update consultants overview/TOC list
 */
function updateConsultantsOverview() {
    const consultantsList = document.getElementById('consultants-list');
    const consultantsStats = document.getElementById('consultants-toc-stats');

    if (!consultantsList || !consultantsStats) return;

    const consultants = window.formData?.referrerConsultants || [];
    const currentIndex = window.currentConsultantIndex || 0;

    if (typeof debugLog === 'function') {
        debugLog('updateConsultantsOverview: consultants count =', consultants.length, 'currentIndex =', currentIndex);
    }

    // Update stats
    consultantsStats.textContent = `Total: ${consultants.length} consultant${consultants.length !== 1 ? 's' : ''}`;

    // Clear list
    consultantsList.innerHTML = '';

    // Populate consultant items
    consultants.forEach((consultant, index) => {
        if (typeof debugLog === 'function') {
            debugLog(`Processing consultant ${index}:`, {
                firstName: consultant.firstName,
                lastName: consultant.lastName,
                fullName: consultant.fullName,
                city: consultant.city,
                state: consultant.state,
                allKeys: Object.keys(consultant)
            });
        }

        // Build full name - try multiple possible keys
        const fullName = getConsultantDisplayName(consultant, index);

        // Build location
        const location = [consultant.city, consultant.state].filter(Boolean).join(', ') || 'Location not specified';

        if (typeof debugLog === 'function') {
            debugLog(`Consultant ${index} display: name="${fullName}", location="${location}"`);
        }

        const item = document.createElement('div');
        item.className = 'facility-item' + (index === currentIndex ? ' active' : '');
        item.tabIndex = 0;
        item.setAttribute('role', 'button');
        item.setAttribute('aria-label', `View ${fullName}`);

        // Properly escape HTML
        const nameDiv = document.createElement('div');
        nameDiv.textContent = fullName;
        const nameEscaped = nameDiv.innerHTML;

        const locationDiv = document.createElement('div');
        locationDiv.textContent = location;
        const locationEscaped = locationDiv.innerHTML;

        item.innerHTML = `
            <div class="facility-item-number">${index + 1}</div>
            <div class="facility-item-info">
                <div class="facility-item-name">${nameEscaped}</div>
                <div class="facility-item-details">${locationEscaped}</div>
            </div>
        `;

        const selectConsultant = () => {
            if (typeof debugLog === 'function') {
                debugLog('Selected consultant index:', index);
            }
            window.currentConsultantIndex = index;
            loadConsultantData();
            updateConsultantsOverview();
        };

        item.addEventListener('click', selectConsultant, { passive: true });
        item.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                selectConsultant();
            }
        });

        consultantsList.appendChild(item);
    });
}

/**
 * Load consultant data into form fields
 */
function loadConsultantData() {
    if (!window.formData.referrerConsultants || window.formData.referrerConsultants.length === 0) {
        window.formData.referrerConsultants = [createDefaultReferrerIndividual()];
        window.currentConsultantIndex = 0;
    }

    // Ensure currentConsultantIndex is valid
    if (typeof window.currentConsultantIndex !== 'number' || window.currentConsultantIndex < 0) {
        window.currentConsultantIndex = 0;
    }
    if (window.currentConsultantIndex >= window.formData.referrerConsultants.length) {
        window.currentConsultantIndex = 0;
    }

    const consultant = window.formData.referrerConsultants[window.currentConsultantIndex];

    if (!consultant) {
        console.warn('⚠️ Consultant not found at index', window.currentConsultantIndex);
        return;
    }

    // Ensure fullName is built if not already set (prefer longest available name)
    getConsultantDisplayName(consultant, window.currentConsultantIndex);

    // Load basic fields - map from input IDs to consultant data keys
    const fields = {
        'consultant-firstname': 'firstName',
        'consultant-lastname': 'lastName',
        'consultant-credentials': 'credentials',
        'consultant-education': 'education',
        'consultant-city': 'city',
        'consultant-state': 'state',
        'consultant-email': 'email',
        'consultant-phone': 'phone',
        'consultant-website': 'website',
        'consultant-role': 'role',
        'consultant-status': 'status',
        'consultant-notes': 'notes',
        'consultant-lawsuits': 'lawsuits'
    };

    Object.keys(fields).forEach(fieldId => {
        const element = document.getElementById(fieldId);
        if (element) {
            element.value = consultant[fields[fieldId]] || '';
        }
    });

    // Update dropdown
    updateConsultantDropdown();

    // Update remove button visibility
    const removeBtn = document.getElementById('remove-consultant-btn');
    if (removeBtn) {
        if (window.formData.referrerConsultants.length > 1) {
            removeBtn.classList.remove('d-none');
        } else {
            removeBtn.classList.add('d-none');
        }
    }

    // Initialize array fields
    if (!Array.isArray(consultant.affiliations)) {
        consultant.affiliations = [];
    }
    if (!Array.isArray(consultant.facilitiesReferred)) {
        consultant.facilitiesReferred = [];
    }
    if (!Array.isArray(consultant.schoolDistricts)) {
        consultant.schoolDistricts = [];
    }

    // Render array fields
    const consultantArrays = [
        { path: 'consultant.affiliations', data: consultant.affiliations },
        { path: 'consultant.facilitiesReferred', data: consultant.facilitiesReferred },
        { path: 'consultant.schoolDistricts', data: consultant.schoolDistricts }
    ];

    if (typeof renderArray === 'function') {
        consultantArrays.forEach(({ path, data }) => {
            const container = document.querySelector(`[data-path="${path}"]`);
            if (container) {
                renderArray(container, path, data);
            }
        });
    }
}

/**
 * Update consultant dropdown
 */
function updateConsultantDropdown() {
    const dropdown = document.getElementById('consultant-dropdown');
    if (!dropdown) return;

    const consultants = window.formData?.referrerConsultants || [];
    dropdown.innerHTML = '';

    consultants.forEach((consultant, index) => {
        const fullName = getConsultantDisplayName(consultant, index);

        const option = document.createElement('option');
        option.value = index;
        option.textContent = `${index + 1}. ${fullName}`;
        dropdown.appendChild(option);
    });

    dropdown.value = window.currentConsultantIndex || 0;
    if (typeof debugLog === 'function') {
        debugLog('✅ Consultant dropdown updated with', consultants.length, 'consultants');
    }
}

/**
 * Update all consultant UI components
 */
function updateConsultantsUI() {
    if (typeof debugLog === 'function') {
        debugLog('🔄 updateConsultantsUI called');
    }
    // Ensure data structures exist before loading
    ensureReferrerDataStructures();
    loadConsultantData();
    updateConsultantsOverview();
    updateConsultantDropdown();
    if (typeof updateJSON === 'function') updateJSON();
    if (typeof autoSave === 'function') autoSave();
    if (typeof debugLog === 'function') {
        debugLog('✅ updateConsultantsUI complete');
    }
}

/**
 * Initialize consultant navigation buttons (prev/next/add/remove/dropdown)
 */
function initializeConsultantNavigation() {
    // Initialize consultant navigation buttons
    const addConsultantBtn = document.getElementById('add-consultant-btn');
    const removeConsultantBtn = document.getElementById('remove-consultant-btn');
    const prevConsultantBtn = document.getElementById('prev-consultant-btn');
    const nextConsultantBtn = document.getElementById('next-consultant-btn');
    const consultantDropdown = document.getElementById('consultant-dropdown');

    if (addConsultantBtn && !addConsultantBtn.dataset.listenerAttached) {
        addConsultantBtn.addEventListener('click', function() { // Note: cannot be passive
            if (!window.formData.referrerConsultants) {
                window.formData.referrerConsultants = [];
            }
            window.formData.referrerConsultants.push({
                firstName: '',
                lastName: '',
                credentials: '',
                city: '',
                state: '',
                email: '',
                phone: '',
                website: '',
                affiliations: [],
                facilitiesReferred: [],
                schoolDistricts: [],
                notes: ''
            });
            window.currentConsultantIndex = window.formData.referrerConsultants.length - 1;
            updateConsultantsUI();
        });
        addConsultantBtn.dataset.listenerAttached = 'true';
    }

    if (removeConsultantBtn && !removeConsultantBtn.dataset.listenerAttached) {
        removeConsultantBtn.addEventListener('click', function() {
            if (!window.formData.referrerConsultants || window.formData.referrerConsultants.length <= 1) {
                return;
            }
            if (confirm('Are you sure you want to remove this consultant?')) {
                window.formData.referrerConsultants.splice(window.currentConsultantIndex, 1);
                if (window.currentConsultantIndex >= window.formData.referrerConsultants.length) {
                    window.currentConsultantIndex = window.formData.referrerConsultants.length - 1;
                }
                updateConsultantsUI();
            }
        });
        removeConsultantBtn.dataset.listenerAttached = 'true';
    }

    if (prevConsultantBtn && !prevConsultantBtn.dataset.listenerAttached) {
        prevConsultantBtn.addEventListener('click', function() { // Note: cannot be passive
            if (window.currentConsultantIndex > 0) {
                window.currentConsultantIndex--;
                loadConsultantData();
                updateConsultantsOverview();
            }
        });
        prevConsultantBtn.dataset.listenerAttached = 'true';
    }

    if (nextConsultantBtn && !nextConsultantBtn.dataset.listenerAttached) {
        nextConsultantBtn.addEventListener('click', function() {
            const maxIndex = (window.formData.referrerConsultants?.length || 1) - 1;
            if (window.currentConsultantIndex < maxIndex) {
                window.currentConsultantIndex++;
                loadConsultantData();
                updateConsultantsOverview();
            }
        });
        nextConsultantBtn.dataset.listenerAttached = 'true';
    }

    if (consultantDropdown && !consultantDropdown.dataset.listenerAttached) {
        consultantDropdown.addEventListener('change', function(e) {
            window.currentConsultantIndex = parseInt(e.target.value);
            loadConsultantData();
            updateConsultantsOverview();
        }, { passive: true });
        consultantDropdown.dataset.listenerAttached = 'true';
    }
}

/**
 * Initialize consultants TOC toggle button
 */
function initializeConsultantsTocToggle() {
    const consultantsTocToggle = document.getElementById('consultants-toc-toggle-btn');
    if (consultantsTocToggle && !consultantsTocToggle.dataset.listenerAttached) {
        consultantsTocToggle.addEventListener('click', function() { // UI-only, can be passive
            const toc = document.getElementById('consultants-toc');
            const content = toc.querySelector('.toc-content');
            const isCollapsed = content.style.display === 'none';

            if (isCollapsed) {
                content.style.display = 'block';
                consultantsTocToggle.textContent = '🔎';
            } else {
                content.style.display = 'none';
                consultantsTocToggle.textContent = '👁️';
            }
        }, { passive: true });
        consultantsTocToggle.dataset.listenerAttached = 'true';
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

                // Update fullName when firstName or lastName changes
                if (fieldName === 'firstName' || fieldName === 'lastName') {
                    const consultant = window.formData.referrerConsultants[consultantIndex];
                    consultant.fullName = [consultant.firstName, consultant.lastName].filter(Boolean).join(' ').trim();
                }

                if (typeof updateJSON === 'function') updateJSON();
                if (typeof autoSave === 'function') autoSave();
                if (typeof updateConsultantsOverview === 'function') updateConsultantsOverview();
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

// Expose consultant navigation functions
window.getConsultantDisplayName = getConsultantDisplayName;
window.updateConsultantsOverview = updateConsultantsOverview;
window.loadConsultantData = loadConsultantData;
window.updateConsultantDropdown = updateConsultantDropdown;
window.updateConsultantsUI = updateConsultantsUI;
window.initializeConsultantNavigation = initializeConsultantNavigation;
window.initializeConsultantsTocToggle = initializeConsultantsTocToggle;

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        console.log('📋 Referrer form module loaded');
    });
} else {
    console.log('📋 Referrer form module loaded');
}
