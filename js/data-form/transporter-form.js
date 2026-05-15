/**
 * Transporter Form Module
 * Handles all transporter-specific functionality including:
 * - Transporter data structures (transport companies and individual transporters)
 * - Loading and saving transporter data
 * - Transporter form field event handlers
 * - Transporter UI updates
 */

// ============================================
// TRANSPORTER DATA STRUCTURES
// ============================================

/**
 * Create default transport company structure
 */
function createDefaultTransporterCompany() {
    return {
        name: "",
        otherNames: [],
        city: "",
        state: "",
        country: "",
        website: "",
        websites: [],
        address: "",
        founded: "",
        status: "",
        parentCompanies: [],
        affiliations: [],
        keyPersonnel: [],
        serviceAreas: [],
        vehicleTypes: [],
        pickupMethods: [],
        restraintPractices: [],
        licensing: [],
        bonded: "",
        insured: "",
        bbbRating: "",
        pricingNotes: "",
        knownFacilities: [],
        knownReferrers: [],
        lawsuits: [],
        sourceUrls: [],
        socialMedia: [],
        notes: "",
        fieldNotes: {}
    };
}

/**
 * Create default individual transporter structure
 */
function createDefaultTransporterIndividual() {
    return {
        firstName: "",
        lastName: "",
        fullName: "",
        role: "",
        status: "",
        credentials: "",
        city: "",
        state: "",
        email: "",
        phone: "",
        website: "",
        websites: [],
        affiliations: [],
        pastTTIJobs: [],
        affiliatedCompanies: [],
        lawsuits: "",
        notes: "",
        fieldNotes: {},
        isIndependent: false
    };
}

function transporterCompanyHasMeaningfulData(company) {
    if (!company || typeof company !== 'object') {
        return false;
    }

    const scalarFields = ['name', 'city', 'state', 'country', 'website', 'address', 'founded', 'status', 'bbbRating', 'pricingNotes', 'notes'];
    if (scalarFields.some(field => typeof company[field] === 'string' && company[field].trim() !== '')) {
        return true;
    }

    return [
        'otherNames', 'websites', 'parentCompanies', 'affiliations', 'keyPersonnel',
        'serviceAreas', 'vehicleTypes', 'pickupMethods', 'restraintPractices',
        'licensing', 'knownFacilities', 'knownReferrers', 'lawsuits', 'sourceUrls', 'socialMedia'
    ].some(field =>
        Array.isArray(company[field]) && company[field].some(item => {
            if (typeof item === 'string') {
                return item.trim() !== '';
            }
            if (item && typeof item === 'object') {
                return Object.values(item).some(value => typeof value === 'string' ? value.trim() !== '' : value !== null && value !== undefined);
            }
            return item !== null && item !== undefined;
        })
    );
}

function transporterIndividualHasMeaningfulData(transporter) {
    if (!transporter || typeof transporter !== 'object') {
        return false;
    }

    const scalarFields = ['firstName', 'lastName', 'fullName', 'name', 'role', 'status', 'credentials', 'city', 'state', 'email', 'phone', 'website', 'lawsuits', 'notes'];
    if (scalarFields.some(field => typeof transporter[field] === 'string' && transporter[field].trim() !== '')) {
        return true;
    }

    return ['websites', 'affiliations', 'pastTTIJobs', 'affiliatedCompanies'].some(field =>
        Array.isArray(transporter[field]) && transporter[field].some(item => {
            if (typeof item === 'string') {
                return item.trim() !== '';
            }
            if (item && typeof item === 'object') {
                return Object.values(item).some(value => typeof value === 'string' ? value.trim() !== '' : value !== null && value !== undefined);
            }
            return item !== null && item !== undefined;
        })
    );
}

/**
 * Build transporter entries from form data (mirrors buildReferrerEntries pattern).
 */
function buildTransporterEntries(formData) {
    const source = formData || {};
    const company = (source.transporterCompany && typeof source.transporterCompany === 'object')
        ? source.transporterCompany
        : createDefaultTransporterCompany();

    const rawKeyPersonnel = Array.isArray(company.keyPersonnel) ? company.keyPersonnel : [];
    const keyPersonnel = rawKeyPersonnel
        .map(person => (typeof person === 'string' ? person.trim() : ''))
        .filter(person => person);

    const dataTemplate = {};
    keyPersonnel.forEach((person, index) => {
        dataTemplate[`transporterCompany.keyPersonnel.${index}`] = [person];
    });

    const cloneTemplate = () => {
        const clone = {};
        Object.entries(dataTemplate).forEach(([key, value]) => {
            clone[key] = Array.isArray(value) ? value.slice() : value;
        });
        return clone;
    };

    const transporters = (Array.isArray(source.transporters) ? source.transporters : [])
        .filter(transporterIndividualHasMeaningfulData);
    const companyName = (company.name || '').trim();

    if (!transporters.length) {
        if (!companyName && !transporterCompanyHasMeaningfulData(company)) {
            return [];
        }
        return [{
            name: companyName,
            data: cloneTemplate(),
            transporterCompany: {
                keyPersonnel: keyPersonnel.slice()
            },
            transporter: {
                affiliations: [],
                affiliatedCompanies: [],
                pastTTIJobs: []
            }
        }];
    }

    return transporters.map(transporter => {
        const affiliations = Array.isArray(transporter?.affiliations) ? transporter.affiliations.filter(item => item !== undefined && item !== null) : [];
        const affiliatedCompanies = Array.isArray(transporter?.affiliatedCompanies) ? transporter.affiliatedCompanies.filter(item => item !== undefined && item !== null) : [];
        const pastTTIJobs = Array.isArray(transporter?.pastTTIJobs) ? transporter.pastTTIJobs.filter(item => item !== undefined && item !== null) : [];
        const transporterName = [transporter?.firstName, transporter?.lastName]
            .map(part => (typeof part === 'string' ? part.trim() : ''))
            .filter(Boolean)
            .join(' ');

        return {
            name: companyName || transporterName,
            data: cloneTemplate(),
            transporterCompany: {
                keyPersonnel: keyPersonnel.slice()
            },
            transporter: {
                affiliations: affiliations.map(item => typeof item === 'string' ? item.trim() : String(item || '').trim()),
                affiliatedCompanies: affiliatedCompanies.map(item => typeof item === 'string' ? item.trim() : String(item || '').trim()),
                pastTTIJobs: pastTTIJobs.map(item => typeof item === 'string' ? item.trim() : String(item || '').trim())
            }
        };
    });
}

/**
 * Ensure transporter data structures exist and are properly initialized
 */
function ensureTransporterDataStructures() {
    if (!window.formData) {
        return;
    }

    const activeTab = document.querySelector('.category-tab.active');
    const activeCategory = activeTab ? activeTab.dataset.category : '';
    const currentProjectCategory = window.currentProjectName && window.projects?.[window.currentProjectName]?.category
        ? window.projects[window.currentProjectName].category
        : activeCategory;
    const isTransporterContext = currentProjectCategory === 'transporters' || activeCategory === 'transporters';

    const legacyCompany = window.formData.transporterCompany || window.formData.transporterAgency || window.formData.transporterGroup || {};
    const company = Object.assign(createDefaultTransporterCompany(), legacyCompany);
    if (typeof company.website === 'string' && company.website.trim()) {
        if (!Array.isArray(company.websites) || company.websites.length === 0) {
            company.websites = [{ url: company.website.trim(), displayText: "" }];
        }
    }
    if (!Array.isArray(company.websites)) {
        company.websites = [];
    }
    if (company.websites.length && (!company.website || typeof company.website !== 'string')) {
        company.website = company.websites[0]?.url || "";
    }

    [
        'otherNames', 'parentCompanies', 'affiliations', 'keyPersonnel',
        'serviceAreas', 'vehicleTypes', 'pickupMethods', 'restraintPractices',
        'licensing', 'knownFacilities', 'knownReferrers', 'lawsuits', 'sourceUrls', 'socialMedia'
    ].forEach(field => {
        if (!Array.isArray(company[field])) {
            company[field] = [];
        }
    });

    window.formData.transporterCompany = company;
    window.formData.transporterAgency = company;
    window.formData.transporterGroup = company;

    if (!Array.isArray(window.formData.transporters)) {
        window.formData.transporters = [];
    }

    if (
        window.formData.transporterIndividual &&
        window.formData.transporters.length === 0 &&
        (isTransporterContext || transporterIndividualHasMeaningfulData(window.formData.transporterIndividual))
    ) {
        window.formData.transporters.push(window.formData.transporterIndividual);
    }

    window.formData.transporters = window.formData.transporters.map((transporter) => {
        const merged = Object.assign(createDefaultTransporterIndividual(), transporter || {});
        if (typeof merged.website === 'string' && merged.website.trim()) {
            if (!Array.isArray(merged.websites) || merged.websites.length === 0) {
                merged.websites = [{ url: merged.website.trim(), displayText: "" }];
            }
        }
        if (!Array.isArray(merged.websites)) {
            merged.websites = [];
        }
        if (merged.websites.length && (!merged.website || typeof merged.website !== 'string')) {
            merged.website = merged.websites[0]?.url || "";
        }
        if (!Array.isArray(merged.affiliations)) {
            merged.affiliations = [];
        }
        if (!Array.isArray(merged.pastTTIJobs)) {
            merged.pastTTIJobs = [];
        }
        if (!Array.isArray(merged.affiliatedCompanies)) {
            merged.affiliatedCompanies = [];
        }

        const firstLastName = [merged.firstName, merged.lastName].filter(Boolean).join(' ').trim();
        if (firstLastName) {
            merged.fullName = firstLastName;
        } else if (!merged.fullName && merged.name) {
            merged.fullName = merged.name.trim();
        } else if (!merged.fullName && isTransporterContext && window.currentProjectName && window.formData.transporters.length === 1) {
            merged.fullName = window.currentProjectName;
        }

        if (merged.fullName && !merged.firstName && !merged.lastName) {
            const parts = merged.fullName.split(/\s+/);
            merged.firstName = parts.shift() || '';
            merged.lastName = parts.join(' ');
        }
        return merged;
    }).filter(transporter => isTransporterContext || transporterIndividualHasMeaningfulData(transporter));

    if (window.formData.transporters.length === 0 && isTransporterContext) {
        window.formData.transporters.push(createDefaultTransporterIndividual());
    }

    if (typeof window.currentTransporterIndex !== 'number' || window.currentTransporterIndex < 0) {
        window.currentTransporterIndex = 0;
    }
    if (window.currentTransporterIndex >= window.formData.transporters.length) {
        window.currentTransporterIndex = 0;
    }

    const activeTransporter = window.formData.transporters[window.currentTransporterIndex] || (isTransporterContext ? createDefaultTransporterIndividual() : null);
    if (activeTransporter) {
        window.formData.transporters[window.currentTransporterIndex] = activeTransporter;
        window.formData.transporterIndividual = activeTransporter;
    } else if (!window.formData.transporterIndividual || typeof window.formData.transporterIndividual !== 'object') {
        window.formData.transporterIndividual = {};
    }

    if (!window.formData.transporterType && isTransporterContext) {
        window.formData.transporterType = window.formData.isIndependentTransporter ? 'individual' : 'company';
    }

    window.formData.transporter = buildTransporterEntries(window.formData);
}

/**
 * Get all transporter names from projects (for autocomplete)
 */
function getAllTransporters() {
    if (!window.aggregatedDataCache) {
        window.aggregatedDataCache = {};
    }

    if (!window.aggregatedDataCache.transporters) {
        const transporters = new Set(window.customTransporters || []);

        Object.values(window.projects || {}).forEach(project => {
            project.data?.facilities?.forEach(facility => {
                facility.identification?.knownTransporters?.forEach(ref => {
                    if (ref && typeof ref === 'string') {
                        transporters.add(ref);
                    }
                });
            });

            if (project.category === 'transporters' && project.data) {
                if (project.data.transporterCompany?.name) {
                    transporters.add(project.data.transporterCompany.name);
                }
                if (project.data.transporterAgency?.name) {
                    transporters.add(project.data.transporterAgency.name);
                }

                if (Array.isArray(project.data.transporters)) {
                    project.data.transporters.forEach(transporter => {
                        if (transporter) {
                            const name = transporter.fullName ||
                                [transporter.firstName, transporter.lastName].filter(Boolean).join(' ');
                            if (name && name.trim()) {
                                transporters.add(name.trim());
                            }
                        }
                    });
                }
            }
        });

        window.aggregatedDataCache.transporters = Array.from(transporters).filter(t => t && typeof t === 'string' && t.trim()).sort();
    }

    return window.aggregatedDataCache.transporters;
}

// ============================================
// TRANSPORTER UI FUNCTIONS
// ============================================

/**
 * Load transporter data into form fields
 */
function loadTransporterData() {
    ensureTransporterDataStructures();

    const company = window.formData.transporterCompany || createDefaultTransporterCompany();

    const companyFieldMap = [
        { ids: ['transporter-company-name'], key: 'name' },
        { ids: ['transporter-company-city'], key: 'city' },
        { ids: ['transporter-company-state'], key: 'state' },
        { ids: ['transporter-company-country'], key: 'country' },
        { ids: ['transporter-company-address'], key: 'address' },
        { ids: ['transporter-company-founded'], key: 'founded' },
        { ids: ['transporter-company-status'], key: 'status' },
        { ids: ['transporter-company-bonded'], key: 'bonded' },
        { ids: ['transporter-company-insured'], key: 'insured' },
        { ids: ['transporter-company-bbb-rating'], key: 'bbbRating' },
        { ids: ['transporter-company-pricing-notes'], key: 'pricingNotes' },
        { ids: ['transporter-company-notes'], key: 'notes' },
    ];

    companyFieldMap.forEach(({ ids, key }) => {
        ids.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.value = company[key] || '';
            }
        });
    });

    const companyArrayPaths = [
        'transporterCompany.otherNames',
        'transporterCompany.websites',
        'transporterCompany.parentCompanies',
        'transporterCompany.affiliations',
        'transporterCompany.keyPersonnel',
        'transporterCompany.serviceAreas',
        'transporterCompany.vehicleTypes',
        'transporterCompany.pickupMethods',
        'transporterCompany.restraintPractices',
        'transporterCompany.licensing',
        'transporterCompany.knownFacilities',
        'transporterCompany.knownReferrers',
        'transporterCompany.lawsuits',
        'transporterCompany.sourceUrls',
        'transporterCompany.socialMedia',
    ];

    if (typeof renderArray === 'function') {
        companyArrayPaths.forEach(path => {
            const container = document.querySelector(`[data-path="${path}"]`);
            if (container) {
                const key = path.split('.').pop();
                if (!Array.isArray(company[key])) {
                    company[key] = [];
                }
                renderArray(container, path, company[key]);
            }
        });
    }

    const transporterType = window.formData.transporterType || (window.formData.isIndependentTransporter ? 'individual' : 'company');
    if (typeof window.applyTransporterToggleState === 'function') {
        window.applyTransporterToggleState(transporterType === 'individual');
    }

    const transporters = window.formData.transporters || [];
    if (typeof window.currentTransporterIndex !== 'number' || window.currentTransporterIndex < 0 || window.currentTransporterIndex >= transporters.length) {
        window.currentTransporterIndex = 0;
    }

    const transporter = window.formData.transporterIndividual || transporters[window.currentTransporterIndex] || createDefaultTransporterIndividual();
    window.formData.transporterIndividual = transporter;

    const mergedNameCandidates = [
        (transporter.fullName || '').trim(),
        (transporter.name || '').trim(),
        [transporter.firstName, transporter.lastName].filter(Boolean).join(' ').trim()
    ].filter(Boolean);
    if (mergedNameCandidates.length) {
        const bestName = mergedNameCandidates.reduce((winner, candidate) => candidate.length > winner.length ? candidate : winner, '');
        transporter.fullName = bestName;
        if (!transporter.firstName && !transporter.lastName) {
            const parts = bestName.split(/\s+/);
            transporter.firstName = parts.shift() || '';
            transporter.lastName = parts.join(' ');
        }
    }

    document.querySelectorAll('.transporter-field').forEach(field => {
        const fieldName = field.dataset.field;
        if (fieldName) {
            const value = transporter[fieldName];
            if (field.type === 'checkbox') {
                field.checked = !!value;
            } else {
                field.value = value ?? '';
            }
        }
    });

    const individualArrayPaths = [
        'transporterIndividual.websites',
        'transporterIndividual.affiliations',
        'transporterIndividual.pastTTIJobs',
        'transporterIndividual.affiliatedCompanies',
        'transporter.affiliations',
        'transporter.pastTTIJobs',
        'transporter.affiliatedCompanies',
    ];

    if (typeof renderArray === 'function') {
        individualArrayPaths.forEach(path => {
            const container = document.querySelector(`[data-path="${path}"]`);
            if (container) {
                const key = path.split('.').pop();
                if (!Array.isArray(transporter[key])) {
                    transporter[key] = [];
                }
                renderArray(container, path, transporter[key]);
            }
        });
    }
}

/**
 * Handle showing/hiding transporter vs data forms based on active category
 */
function handleTransporterToggle() {
    const activeTab = document.querySelector('.category-tab.active');
    const activeCategory = activeTab ? activeTab.dataset.category : 'companies';

    const transporterMainWrapper = document.getElementById('transporter-main-wrapper');
    const facilityLoaderPanel = document.querySelector('.facility-loader-panel');
    const facilityMainWrapper = document.getElementById('facility-main-wrapper');
    const referrerMainWrapper = document.getElementById('referrer-main-wrapper');

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

    if (activeCategory === 'transporters') {
        hideElement(facilityMainWrapper);
        hideElement(facilityLoaderPanel);
        hideElement(referrerMainWrapper);
        showElement(transporterMainWrapper);
    } else {
        hideElement(transporterMainWrapper);
    }
}

// ============================================
// TRANSPORTER NAVIGATION & UI FUNCTIONS
// ============================================

function getTransporterDisplayName(transporter, index) {
    if (!transporter) {
        return `Transporter ${index + 1}`;
    }

    const fullName = (transporter.fullName || '').trim();
    const nameField = (transporter.name || '').trim();
    const firstLast = [transporter.firstName, transporter.lastName].map(part => (part || '').trim()).filter(Boolean).join(' ').trim();

    let displayName;
    if (firstLast) {
        displayName = firstLast;
    } else if (fullName) {
        displayName = fullName;
    } else if (nameField) {
        displayName = nameField;
    } else {
        displayName = `Transporter ${index + 1}`;
    }

    if (!transporter.fullName && displayName && !displayName.startsWith('Transporter ')) {
        transporter.fullName = displayName;
    }

    if (!transporter.firstName && !transporter.lastName && displayName && !displayName.startsWith('Transporter ')) {
        const parts = displayName.split(/\s+/);
        if (parts.length > 1) {
            transporter.firstName = parts.shift();
            transporter.lastName = parts.join(' ');
        } else {
            transporter.firstName = displayName;
        }
    }

    return displayName;
}

function updateTransportersOverview() {
    const transportersList = document.getElementById('transporters-list');
    const transportersStats = document.getElementById('transporters-toc-stats');

    if (!transportersList || !transportersStats) return;

    const transporters = window.formData?.transporters || [];
    const currentIndex = window.currentTransporterIndex || 0;

    transportersStats.textContent = `Total: ${transporters.length} transporter${transporters.length !== 1 ? 's' : ''}`;
    transportersList.innerHTML = '';

    const withIndex = transporters.map((transporter, index) => ({
        transporter,
        originalIndex: index,
        name: getTransporterDisplayName(transporter, index).toLowerCase()
    }));

    withIndex.sort((a, b) => {
        const isUnnamedA = a.name.startsWith('transporter ');
        const isUnnamedB = b.name.startsWith('transporter ');
        if (isUnnamedA && !isUnnamedB) return 1;
        if (isUnnamedB && !isUnnamedA) return -1;
        return a.name.localeCompare(b.name);
    });

    withIndex.forEach(({ transporter, originalIndex }) => {
        const index = originalIndex;
        const fullName = getTransporterDisplayName(transporter, index);
        const location = [transporter.city, transporter.state].filter(Boolean).join(', ') || 'Location not specified';

        const item = document.createElement('div');
        item.className = 'transporter-item' + (index === currentIndex ? ' active' : '');
        item.tabIndex = 0;
        item.setAttribute('role', 'button');
        item.setAttribute('aria-label', `View ${fullName}`);

        const nameDiv = document.createElement('div');
        nameDiv.textContent = fullName;
        const nameEscaped = nameDiv.innerHTML;

        const locationDiv = document.createElement('div');
        locationDiv.textContent = location;
        const locationEscaped = locationDiv.innerHTML;

        const isIndependent = transporter.isIndependent || false;
        const badgeHTML = isIndependent
            ? '<span class="transporter-independent-badge" title="Click to toggle independent status">Independent</span>'
            : '<span class="transporter-independent-badge not-independent" title="Click to toggle independent status">Company</span>';

        item.innerHTML = `
            <div class="transporter-item-number">${index + 1}</div>
            <div class="transporter-item-info">
                <div class="transporter-item-name">${nameEscaped}</div>
                <div class="transporter-item-details">${locationEscaped}</div>
            </div>
            ${badgeHTML}
        `;

        const selectTransporter = () => {
            window.currentTransporterIndex = index;
            loadTransporterIndividualData();
            updateTransportersOverview();
        };

        const badge = item.querySelector('.transporter-independent-badge');
        if (badge) {
            badge.addEventListener('click', function(e) {
                e.stopPropagation();
                window.formData.transporters[index].isIndependent = !window.formData.transporters[index].isIndependent;

                if (index === window.currentTransporterIndex) {
                    const companySection = document.getElementById('transporter-company-section');
                    const independent = window.formData.transporters[index].isIndependent;
                    if (companySection) {
                        companySection.style.display = independent ? 'none' : 'block';
                    }
                }

                if (typeof updateJSON === 'function') updateJSON();
                if (typeof autoSave === 'function') autoSave();
                updateTransportersOverview();
            });
        }

        item.addEventListener('click', selectTransporter, { passive: true });
        item.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                selectTransporter();
            }
        });

        transportersList.appendChild(item);
    });
}

function loadTransporterIndividualData() {
    if (!window.formData.transporters || window.formData.transporters.length === 0) {
        window.formData.transporters = [createDefaultTransporterIndividual()];
        window.currentTransporterIndex = 0;
    }

    if (typeof window.currentTransporterIndex !== 'number' || window.currentTransporterIndex < 0) {
        window.currentTransporterIndex = 0;
    }
    if (window.currentTransporterIndex >= window.formData.transporters.length) {
        window.currentTransporterIndex = 0;
    }

    const transporter = window.formData.transporters[window.currentTransporterIndex];
    if (!transporter) return;

    getTransporterDisplayName(transporter, window.currentTransporterIndex);

    document.querySelectorAll('.transporter-field').forEach(field => {
        const fieldName = field.dataset.field;
        if (fieldName) {
            const value = transporter[fieldName];
            if (field.type === 'checkbox') {
                field.checked = !!value;
            } else {
                field.value = value ?? '';
            }
        }
    });

    updateTransporterDropdown();

    const removeBtn = document.getElementById('remove-transporter-btn');
    if (removeBtn) {
        if (window.formData.transporters.length > 1) {
            removeBtn.classList.remove('d-none');
        } else {
            removeBtn.classList.add('d-none');
        }
    }

    ['affiliations', 'pastTTIJobs', 'affiliatedCompanies'].forEach(field => {
        if (!Array.isArray(transporter[field])) {
            transporter[field] = [];
        }
    });

    const transporterArrays = [
        { path: 'transporter.affiliations', data: transporter.affiliations },
        { path: 'transporter.pastTTIJobs', data: transporter.pastTTIJobs },
        { path: 'transporter.affiliatedCompanies', data: transporter.affiliatedCompanies },
    ];

    if (typeof renderArray === 'function') {
        transporterArrays.forEach(({ path, data }) => {
            const container = document.querySelector(`[data-path="${path}"]`);
            if (container) {
                renderArray(container, path, data);
            }
        });
    }
}

function updateTransporterDropdown() {
    const dropdown = document.getElementById('transporter-dropdown');
    if (!dropdown) return;

    const transporters = window.formData?.transporters || [];
    dropdown.innerHTML = '';

    transporters.forEach((transporter, index) => {
        const fullName = getTransporterDisplayName(transporter, index);
        const option = document.createElement('option');
        option.value = index;
        option.textContent = `${index + 1}. ${fullName}`;
        dropdown.appendChild(option);
    });

    dropdown.value = window.currentTransporterIndex || 0;
}

function updateTransportersUI() {
    ensureTransporterDataStructures();
    loadTransporterIndividualData();
    updateTransportersOverview();
    updateTransporterDropdown();
    if (typeof updateJSON === 'function') updateJSON();
    if (typeof autoSave === 'function') autoSave();
}

function initializeTransporterNavigation() {
    const addBtn = document.getElementById('add-transporter-btn');
    const removeBtn = document.getElementById('remove-transporter-btn');
    const prevBtn = document.getElementById('prev-transporter-btn');
    const nextBtn = document.getElementById('next-transporter-btn');
    const dropdown = document.getElementById('transporter-dropdown');

    if (addBtn && !addBtn.dataset.listenerAttached) {
        addBtn.addEventListener('click', function() {
            if (!window.formData.transporters) {
                window.formData.transporters = [];
            }
            window.formData.transporters.push(createDefaultTransporterIndividual());
            window.currentTransporterIndex = window.formData.transporters.length - 1;
            updateTransportersUI();
        });
        addBtn.dataset.listenerAttached = 'true';
    }

    if (removeBtn && !removeBtn.dataset.listenerAttached) {
        removeBtn.addEventListener('click', function() {
            if (!window.formData.transporters || window.formData.transporters.length <= 1) {
                return;
            }
            if (confirm('Are you sure you want to remove this transporter?')) {
                window.formData.transporters.splice(window.currentTransporterIndex, 1);
                if (window.currentTransporterIndex >= window.formData.transporters.length) {
                    window.currentTransporterIndex = window.formData.transporters.length - 1;
                }
                updateTransportersUI();
            }
        });
        removeBtn.dataset.listenerAttached = 'true';
    }

    if (prevBtn && !prevBtn.dataset.listenerAttached) {
        prevBtn.addEventListener('click', function() {
            if (window.currentTransporterIndex > 0) {
                window.currentTransporterIndex--;
                loadTransporterIndividualData();
                updateTransportersOverview();
            }
        });
        prevBtn.dataset.listenerAttached = 'true';
    }

    if (nextBtn && !nextBtn.dataset.listenerAttached) {
        nextBtn.addEventListener('click', function() {
            const maxIndex = (window.formData.transporters?.length || 1) - 1;
            if (window.currentTransporterIndex < maxIndex) {
                window.currentTransporterIndex++;
                loadTransporterIndividualData();
                updateTransportersOverview();
            }
        });
        nextBtn.dataset.listenerAttached = 'true';
    }

    if (dropdown && !dropdown.dataset.listenerAttached) {
        dropdown.addEventListener('change', function(e) {
            window.currentTransporterIndex = parseInt(e.target.value);
            loadTransporterIndividualData();
            updateTransportersOverview();
        }, { passive: true });
        dropdown.dataset.listenerAttached = 'true';
    }
}

function initializeTransportersTocToggle() {
    const toggleBtn = document.getElementById('transporters-toc-toggle-btn');
    if (toggleBtn && !toggleBtn.dataset.listenerAttached) {
        toggleBtn.addEventListener('click', function() {
            const toc = document.getElementById('transporters-toc');
            const content = toc.querySelector('.toc-content');
            const isCollapsed = content.style.display === 'none';

            if (isCollapsed) {
                content.style.display = 'block';
                toggleBtn.textContent = '🔎';
            } else {
                content.style.display = 'none';
                toggleBtn.textContent = '👁️';
            }
        }, { passive: true });
        toggleBtn.dataset.listenerAttached = 'true';
    }
}

// ============================================
// TRANSPORTER EVENT HANDLERS
// ============================================

function attachTransporterFieldListeners() {
    const updateTransporterCompany = (mutator) => {
        if (!window.formData) window.formData = {};
        if (!window.formData.transporterCompany || typeof window.formData.transporterCompany !== 'object') {
            window.formData.transporterCompany = createDefaultTransporterCompany();
        }
        const company = window.formData.transporterCompany;
        mutator(company);
        window.formData.transporterAgency = company;
        window.formData.transporterGroup = company;
        window.formData.transporter = buildTransporterEntries(window.formData);
        if (typeof updateJSON === 'function') updateJSON();
        if (typeof autoSave === 'function') autoSave();
    };

    const companyFieldHandlers = {
        'transporter-company-name': (val) => updateTransporterCompany(c => { c.name = val; }),
        'transporter-company-city': (val) => updateTransporterCompany(c => { c.city = val; }),
        'transporter-company-state': (val) => updateTransporterCompany(c => { c.state = val; }),
        'transporter-company-country': (val) => updateTransporterCompany(c => { c.country = val; }),
        'transporter-company-address': (val) => updateTransporterCompany(c => { c.address = val; }),
        'transporter-company-founded': (val) => updateTransporterCompany(c => { c.founded = val; }),
        'transporter-company-status': (val) => updateTransporterCompany(c => { c.status = val; }),
        'transporter-company-bonded': (val) => updateTransporterCompany(c => { c.bonded = val; }),
        'transporter-company-insured': (val) => updateTransporterCompany(c => { c.insured = val; }),
        'transporter-company-bbb-rating': (val) => updateTransporterCompany(c => { c.bbbRating = val; }),
        'transporter-company-pricing-notes': (val) => updateTransporterCompany(c => { c.pricingNotes = val; }),
        'transporter-company-notes': (val) => updateTransporterCompany(c => { c.notes = val; }),
    };

    Object.entries(companyFieldHandlers).forEach(([id, handler]) => {
        const el = document.getElementById(id);
        if (el && !el.dataset.listenerAttached) {
            const eventName = el.tagName === 'SELECT' ? 'change' : 'input';
            el.addEventListener(eventName, (e) => handler(e.target.value), { passive: true });
            el.dataset.listenerAttached = 'true';
        }
    });

    const transporterFields = document.querySelectorAll('.transporter-field');
    transporterFields.forEach(field => {
        if (!field.dataset.listenerAttached) {
            field.addEventListener('input', (e) => {
                if (!window.formData) window.formData = {};
                if (!Array.isArray(window.formData.transporters)) {
                    window.formData.transporters = [createDefaultTransporterIndividual()];
                }

                const fieldName = e.target.dataset.field;
                const transporterIndex = window.currentTransporterIndex || 0;
                if (!window.formData.transporters[transporterIndex]) {
                    window.formData.transporters[transporterIndex] = createDefaultTransporterIndividual();
                }
                window.formData.transporters[transporterIndex][fieldName] = e.target.value;

                if (fieldName === 'firstName' || fieldName === 'lastName') {
                    const transporter = window.formData.transporters[transporterIndex];
                    transporter.fullName = [transporter.firstName, transporter.lastName].filter(Boolean).join(' ').trim();
                    if (window.formData.transporterIndividual === transporter || transporterIndex === window.currentTransporterIndex) {
                        window.formData.transporterIndividual = transporter;
                    }
                }

                if (typeof updateJSON === 'function') updateJSON();
                if (typeof autoSave === 'function') autoSave();
                if (typeof updateTransportersOverview === 'function') updateTransportersOverview();
            }, { passive: true });
            field.dataset.listenerAttached = 'true';
        }
    });
}

function handleTransporterTypeToggle(type) {
    ensureTransporterDataStructures();
    const normalized = type === 'individual' ? 'individual' : 'company';
    window.formData.transporterType = normalized;

    if (typeof window.applyTransporterToggleState === 'function') {
        window.applyTransporterToggleState(normalized === 'individual');
    }

    if (typeof updateJSON === 'function') updateJSON();
    if (typeof autoSave === 'function') autoSave();
}

// ============================================
// EXPORTS & INITIALIZATION
// ============================================

window.createDefaultTransporterCompany = createDefaultTransporterCompany;
window.createDefaultTransporterIndividual = createDefaultTransporterIndividual;
window.buildTransporterEntries = buildTransporterEntries;
window.ensureTransporterDataStructures = ensureTransporterDataStructures;
window.getAllTransporters = getAllTransporters;
window.loadTransporterData = loadTransporterData;
window.handleTransporterToggle = handleTransporterToggle;
window.handleTransporterTypeToggle = handleTransporterTypeToggle;
window.attachTransporterFieldListeners = attachTransporterFieldListeners;

window.getTransporterDisplayName = getTransporterDisplayName;
window.updateTransportersOverview = updateTransportersOverview;
window.loadTransporterIndividualData = loadTransporterIndividualData;
window.updateTransporterDropdown = updateTransporterDropdown;
window.updateTransportersUI = updateTransportersUI;
window.initializeTransporterNavigation = initializeTransporterNavigation;
window.initializeTransportersTocToggle = initializeTransportersTocToggle;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        console.log('🚐 Transporter form module loaded');
    });
} else {
    console.log('🚐 Transporter form module loaded');
}
