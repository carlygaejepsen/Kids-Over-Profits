// ============================================
// CONSOLIDATED DATA FORM - CLOUD FIRST
// All functionality restored, cloud-first storage
// ============================================

// ============================================
// CONFIGURATION - DELEGATED
// All configuration is now handled by the
// `KOP_FormConfig` module in `config.js`.
// Access via `window.KOP_FormConfig.*`
// ============================================

// Compatibility shim: KOP_CONFIG -> KOP_FormConfig
const KOP_CONFIG = window.KOP_FormConfig || window.KOP_CONFIG || {};

const SCRIPT_BUILD_VERSION = KOP_CONFIG.SCRIPT_BUILD_VERSION || 'data-form.v4.refactored';

// These constants are now pointers to the config module for convenience.
const API_ENDPOINTS = KOP_CONFIG.API_ENDPOINTS || {};
const FORM_MODE = KOP_CONFIG.FORM_MODE || 'master';
const IS_SUGGESTION_MODE = KOP_CONFIG.IS_SUGGESTION_MODE || false;
const FALLBACK_PROJECTS_URL_CANDIDATES = KOP_CONFIG.FALLBACK_PROJECTS_URL_CANDIDATES || [];
const FALLBACK_PROJECTS_URL = KOP_CONFIG.FALLBACK_PROJECTS_URL || null;
const DEBUG_LOGGING_ENABLED = KOP_CONFIG.DEBUG_LOGGING_ENABLED || false;

// Ensure global helper functions are available.
function isSuggestionMode() {
    // Use the central function from the config module.
    return KOP_CONFIG.isSuggestionMode ? KOP_CONFIG.isSuggestionMode() : false;
}

function getAPIEndpoints() {
    // Use the central function from the config module.
    return KOP_CONFIG.getAPIEndpoints ? KOP_CONFIG.getAPIEndpoints() : {};
}

// debugLog function now defined in utilities.js module
// Access via window.debugLog

function logActiveDataFormConfigOnce() {
    if (typeof window === 'undefined' || !window.KOP_FormConfig) {
        return;
    }

    if (window.__KOP_DATA_FORM_CONFIG_LOGGED) {
        return;
    }

    window.__KOP_DATA_FORM_CONFIG_LOGGED = true;

    if (!KOP_FormConfig.DEBUG_LOGGING_ENABLED || typeof console === 'undefined') {
        return;
    }

    try {
        debugLog('[KOP Data Form] Loaded script build %s', KOP_FormConfig.SCRIPT_BUILD_VERSION);
        if (KOP_FormConfig.normalizedApiBases && KOP_FormConfig.normalizedApiBases.length) {
            debugLog('[KOP Data Form] API base candidates:', KOP_FormConfig.normalizedApiBases);
        }
        debugLog('[KOP Data Form] Resolved API endpoints:', KOP_FormConfig.API_ENDPOINTS);
        debugLog('[KOP Data Form] Active form mode:', KOP_FormConfig.FORM_MODE);
        if (KOP_FormConfig.FALLBACK_PROJECTS_URL_CANDIDATES && KOP_FormConfig.FALLBACK_PROJECTS_URL_CANDIDATES.length) {
            debugLog('[KOP Data Form] Fallback dataset URL candidates:', KOP_FormConfig.FALLBACK_PROJECTS_URL_CANDIDATES);
        } else {
            debugLog('[KOP Data Form] No fallback dataset configured');
        }
    } catch (logError) {
        // Swallow logging errors to avoid breaking initialization if console is locked down
    }
}

// ============================================
// DEFAULT VALUES - Delegated to autocomplete.js module
// These constants are now defined in autocomplete.js and exported globally.
// We reference them via window to avoid redeclaration errors.
// ============================================
// Note: DEFAULT_FACILITY_TYPES, DEFAULT_OPERATORS, DEFAULT_STAFF_ROLES
// are now available via window.DEFAULT_FACILITY_TYPES etc. from autocomplete.js

// ============================================
// HELPER FUNCTIONS
// ============================================

// Note: customChoice, customAlert, customConfirm, etc. are provided by custom-modals.js
// They are available globally as window.customChoice, window.customAlert, etc.

// ============================================
// GLOBAL STATE
// ============================================
let projects = {};
let isUpdatingUI = false; // Flag to prevent autoSave during programmatic UI updates
let isSaveInProgress = false; // Flag to prevent overlapping saves
let currentProjectName = null;
let currentFacilityIndex = 0;
let formData = null;

// Custom data is now managed by the autocomplete.js module
// Access via window.customOperators, window.customFacilityNames, etc.

// ============================================
// AGGREGATED DATA CACHE - Delegated to autocomplete.js module
// The aggregatedDataCache, CACHE_CATEGORY_MAP, and invalidateAggregatedData
// are now defined in autocomplete.js. No need to redeclare here.
// ============================================
// noteFieldRegistry is now managed by the notes module (notes.js)
// The notes module exports it globally as window.noteFieldRegistry

// invalidateAggregatedData - delegate to autocomplete module
function invalidateAggregatedData(category = null) {
    if (typeof window.invalidateAggregatedData === 'function' && window.invalidateAggregatedData !== invalidateAggregatedData) {
        return window.invalidateAggregatedData(category);
    }
    // Autocomplete module not loaded - cannot invalidate cache
    debugLog('[Data Form] Cannot invalidate cache - autocomplete module not loaded');
}

// Make globals available
window.projects = projects;
window.currentProjectName = currentProjectName;
window.currentFacilityIndex = currentFacilityIndex;
window.formData = formData;

// ============================================
// UTILITY FUNCTIONS - Delegated to utilities.js module
// ============================================
// The following utility functions are now defined in utilities.js and exported globally:
// - escapeHtmlForAttr(s)
// - deepClone(obj)
// - getNestedValue(obj, path)
// - toCamelCase(str)
// - toSnakeCase(str)
// - parseCityState(str)
// - combineCityState(city, state)
// Access via window.escapeHtmlForAttr, window.deepClone, etc.

// ============================================
// DATA NORMALIZATION - DELEGATED
// Normalization is now handled by the KOP_DataNormalizer module.
// ============================================
function normalizeProjectData(data) {
    if (window.KOP_DataNormalizer && typeof window.KOP_DataNormalizer.normalizeProjectData === 'function') {
        return window.KOP_DataNormalizer.normalizeProjectData(data);
    }
    console.error('[Data Form] Data Normalizer module not found. Returning raw data.');
    return data;
}

// setNestedValue and showUploadStatus functions now defined in utilities.js module
// Access via window.setNestedValue and window.showUploadStatus

// NOTE: normalizeProjectData function is defined earlier in this file (around line 372)
// It handles both field name normalization AND default structure initialization
// The following function was removed as it was a duplicate that shadowed the first definition

// ============================================
// LOCALSTORAGE BACKUP FUNCTIONS - Delegated to autocomplete.js and db-form-loader.js
// ============================================
function loadCustomDataFromLocalStorage() {
    // Delegate to form loader if available
    if (window.KOP_FormLoader && typeof window.KOP_FormLoader.loadCustomDataFromLocalStorage === 'function') {
        return window.KOP_FormLoader.loadCustomDataFromLocalStorage();
    }
    // Fallback - just invalidate cache so fresh data is pulled
    invalidateAggregatedData();
}

// The saveToLocalStorage function has been moved to api.js as a private helper.

function addCustomValue(category, value) {
    if (window.KOP_Autocomplete?.addCustomValue) {
        return window.KOP_Autocomplete.addCustomValue(category, value);
    }
    // Delegate to autocomplete module if available
    if (window.addCustomValue && window.addCustomValue !== addCustomValue) {
        return window.addCustomValue(category, value);
    }
    // Fallback - just return false if module not loaded
    console.warn('[Data Form] addCustomValue - autocomplete module not loaded');
    return false;
}

function attachCustomValueRecorder(input, category) {
    if (window.KOP_Autocomplete?.attachCustomValueRecorder) {
        return window.KOP_Autocomplete.attachCustomValueRecorder(input, category);
    }
    // Delegate to autocomplete module if available
    if (window.attachCustomValueRecorder && window.attachCustomValueRecorder !== attachCustomValueRecorder) {
        return window.attachCustomValueRecorder(input, category);
    }
    // Fallback - skip if module not loaded
    debugLog('[Data Form] attachCustomValueRecorder - autocomplete module not loaded');
}

// ============================================
// DATA AGGREGATION - Delegated to autocomplete.js module
// All data aggregation functions (getAllOperators, getAllFacilityNames, etc.)
// are now defined in autocomplete.js and exported globally.
// These wrapper functions delegate to the KOP_Autocomplete namespace for backward compatibility.
// NOTE: We use window.KOP_Autocomplete.* instead of window.* to avoid circular reference
// since this file's local functions would shadow the window.* exports.
// ============================================

function getAllOperators() {
    return window.KOP_Autocomplete?.getAllOperators ? window.KOP_Autocomplete.getAllOperators() : [];
}

function getAllFacilityNames() {
    return window.KOP_Autocomplete?.getAllFacilityNames ? window.KOP_Autocomplete.getAllFacilityNames() : [];
}

function getAllHumanNames() {
    return window.KOP_Autocomplete?.getAllHumanNames ? window.KOP_Autocomplete.getAllHumanNames() : [];
}

function getAllReferrers() {
    return window.KOP_Autocomplete?.getAllReferrers ? window.KOP_Autocomplete.getAllReferrers() : [];
}

function getAllFacilityTypes() {
    return window.KOP_Autocomplete?.getAllFacilityTypes ? window.KOP_Autocomplete.getAllFacilityTypes() : [];
}

function getAllStaffRoles() {
    return window.KOP_Autocomplete?.getAllStaffRoles ? window.KOP_Autocomplete.getAllStaffRoles() : [];
}

function getAllCertifications() {
    return window.KOP_Autocomplete?.getAllCertifications ? window.KOP_Autocomplete.getAllCertifications() : [];
}

function getAllAccreditations() {
    return window.KOP_Autocomplete?.getAllAccreditations ? window.KOP_Autocomplete.getAllAccreditations() : [];
}

function getAllMemberships() {
    return window.KOP_Autocomplete?.getAllMemberships ? window.KOP_Autocomplete.getAllMemberships() : [];
}

function getAllLocations() {
    return window.KOP_Autocomplete?.getAllLocations ? window.KOP_Autocomplete.getAllLocations() : [];
}

function getAllStatuses() {
    return window.KOP_Autocomplete?.getAllStatuses ? window.KOP_Autocomplete.getAllStatuses() : [];
}

function getAllGenders() {
    return window.KOP_Autocomplete?.getAllGenders ? window.KOP_Autocomplete.getAllGenders() : [];
}

function getAllOperatingPeriods() {
    return window.KOP_Autocomplete?.getAllOperatingPeriods ? window.KOP_Autocomplete.getAllOperatingPeriods() : [];
}

// ============================================
// AUTOCOMPLETE - Delegated to autocomplete.js module
// The createAutocomplete and initializeAutocompleteFields functions
// are now defined in autocomplete.js and exported globally.
// These wrapper functions delegate to the module for backward compatibility.
// ============================================

// Capture the real module implementations before defining our delegates so we don't overwrite them.
// Use lets so we can pick up late-loaded modules if dependency order is altered by plugins/caching.
// Priority: window.KOP_Autocomplete.* (namespaced) > window.* (legacy exports)
let moduleCreateAutocomplete = (typeof window !== 'undefined' && window.KOP_Autocomplete?.createAutocomplete)
    ? window.KOP_Autocomplete.createAutocomplete
    : (typeof window !== 'undefined' && typeof window.createAutocomplete === 'function')
        ? window.createAutocomplete
        : null;
let moduleInitializeAutocompleteFields = (typeof window !== 'undefined' && window.KOP_Autocomplete?.initializeAutocompleteFields)
    ? window.KOP_Autocomplete.initializeAutocompleteFields
    : (typeof window !== 'undefined' && typeof window.initializeAutocompleteFields === 'function')
        ? window.initializeAutocompleteFields
        : null;

function resolveCreateAutocomplete() {
    if (moduleCreateAutocomplete && moduleCreateAutocomplete !== delegateCreateAutocomplete) {
        return moduleCreateAutocomplete;
    }
    // Try KOP_Autocomplete namespace first (avoids circular reference issues)
    if (typeof window !== 'undefined' && window.KOP_Autocomplete?.createAutocomplete) {
        moduleCreateAutocomplete = window.KOP_Autocomplete.createAutocomplete;
        return moduleCreateAutocomplete;
    }
    if (typeof window !== 'undefined' && typeof window.createAutocomplete === 'function' && window.createAutocomplete !== delegateCreateAutocomplete) {
        moduleCreateAutocomplete = window.createAutocomplete;
        return moduleCreateAutocomplete;
    }
    return null;
}

function resolveInitializeAutocompleteFields() {
    if (moduleInitializeAutocompleteFields && moduleInitializeAutocompleteFields !== delegateInitializeAutocompleteFields) {
        return moduleInitializeAutocompleteFields;
    }
    // Try KOP_Autocomplete namespace first (avoids circular reference issues)
    if (typeof window !== 'undefined' && window.KOP_Autocomplete?.initializeAutocompleteFields) {
        moduleInitializeAutocompleteFields = window.KOP_Autocomplete.initializeAutocompleteFields;
        return moduleInitializeAutocompleteFields;
    }
    if (typeof window !== 'undefined' && typeof window.initializeAutocompleteFields === 'function' && window.initializeAutocompleteFields !== delegateInitializeAutocompleteFields) {
        moduleInitializeAutocompleteFields = window.initializeAutocompleteFields;
        return moduleInitializeAutocompleteFields;
    }
    return null;
}

const delegateCreateAutocomplete = (input, getDataFunction, category) => {
    const delegate = resolveCreateAutocomplete();
    if (delegate) {
        return delegate(input, getDataFunction, category);
    }
    // Autocomplete module not loaded - skip initialization
    console.warn('[Data Form] Autocomplete module not loaded, skipping createAutocomplete');
};

const delegateInitializeAutocompleteFields = () => {
    const delegate = resolveInitializeAutocompleteFields();
    if (delegate) {
        return delegate();
    }
    // Autocomplete module not loaded - skip initialization
    console.warn('[Data Form] Autocomplete module not loaded, skipping initializeAutocompleteFields');
};

// ============================================
// UI FUNCTIONS - DELEGATED
// All UI functions are now handled by the
// `KOP_UI` module in `ui.js`.
// ============================================
function initializeSectionToggles() { if (window.KOP_UI_Events) window.KOP_UI_Events.initializeSectionToggles(); }
function initializeMobileSectionControls() { if (window.KOP_UI_Events) window.KOP_UI_Events.initializeMobileSectionControls(); }
function expandAllSections() { if (window.KOP_UI_Events) window.KOP_UI_Events.expandAllSections(); }
function collapseAllSections() { if (window.KOP_UI_Events) window.KOP_UI_Events.collapseAllSections(); }
function attachFieldListeners() { if (window.KOP_UI_Events) window.KOP_UI_Events.attachFieldListeners(); }
function attachButtonListeners() { if (window.KOP_UI_Events) window.KOP_UI_Events.attachButtonListeners(); }
function initializeOverviewTabSwitching() { if (window.KOP_UI_Events) window.KOP_UI_Events.initializeOverviewTabSwitching(); }


// ============================================
// FIELD NOTE CONTROLS - DELEGATED
// All field note controls are now handled by the
// `NotesModule` in `notes.js`.
// ============================================
function ensureFieldNotesStore(scope, createIfMissing) { if (window.NotesModule) return window.NotesModule.ensureFieldNotesStore(scope, createIfMissing); }
function getFieldNotes(scope, key, createIfMissing) { if (window.NotesModule) return window.NotesModule.getFieldNotes(scope, key, createIfMissing); return []; }
function addFieldNote(scope, key) { if (window.NotesModule) window.NotesModule.addFieldNote(scope, key); }
function updateFieldNote(scope, key, index, value) { if (window.NotesModule) window.NotesModule.updateFieldNote(scope, key, index, value); }
function removeFieldNote(scope, key, index) { if (window.NotesModule) window.NotesModule.removeFieldNote(scope, key, index); }
function renderFieldNotes(container, scope, key) { if (window.NotesModule) window.NotesModule.renderFieldNotes(container, scope, key); }
function renderAllFieldNotes() { if (window.NotesModule) window.NotesModule.renderAllFieldNotes(); }
function initializeNoteControls() { if (window.NotesModule) window.NotesModule.initializeNoteControls(); }

// ============================================
// CLOUD STORAGE - DELEGATED
// All cloud storage and data loading is now handled by the
// `KOP_API` module in `api.js`.
// ============================================

function autoSave() {
    // Skip autoSave if we're in the middle of a programmatic UI update
    if (isUpdatingUI) {
        debugLog('⏸️ autoSave skipped - UI update in progress');
        return;
    }

    if (window.KOP_FormConfig.isSuggestionMode()) {
        clearTimeout(window.autoSaveTimer);
        window.autoSaveTimer = setTimeout(() => {
            if (!window.currentProjectName) {
                return;
            }

            // Uses the new API module (safe access)
            const saved = window.KOP_API?.persistProjectLocally?.(window.currentProjectName);
            if (saved) {
                debugLog('Suggestion draft saved locally for', window.currentProjectName);
            }
        }, 150000); // 2.5 minutes
        return;
    }

    if (window.currentProjectName) {
        // Debounced auto-save to cloud
        clearTimeout(window.autoSaveTimer);
        window.autoSaveTimer = setTimeout(async () => {
            // Prevent overlapping saves
            if (isSaveInProgress) {
                debugLog('⏸️ Save skipped - another save already in progress');
                return;
            }
            isSaveInProgress = true;
            try {
                // Uses the new API module (safe access)
                await window.KOP_API?.saveProjectToCloud?.(window.currentProjectName);
            } finally {
                isSaveInProgress = false;
            }
        }, 150000); // 2.5 minutes
    }
}

window.autoSave = autoSave;

// ============================================
// REFERRER FUNCTIONS - Delegated to referrer-form.js module
// These wrapper functions delegate to the module for backward compatibility.
// ============================================



// ============================================
// PROJECT MANAGEMENT - DELEGATED
// All project management is now handled by the
// `KOP_Project` module in `project.js`.
// ============================================
function createNewProjectData() {
    if (window.KOP_Project && typeof window.KOP_Project.createNewProjectData === 'function') {
        return window.KOP_Project.createNewProjectData();
    }
    console.error('[Data Form] Project module not found.');
    // Fallback in case module is not loaded
    return { operator: {}, facilities: [{}], referrer: [], referrerAgency: {}, referrerConsultants: [{}], fieldNotes: {} };
}

function loadProject(projectName) {
    if (window.KOP_Project && typeof window.KOP_Project.loadProject === 'function') {
        return window.KOP_Project.loadProject(projectName);
    }
    console.error('[Data Form] Project module not found.');
    return Promise.reject(new Error('Project module not found.'));
}

function newProject() {
    if (window.KOP_Project && typeof window.KOP_Project.newProject === 'function') {
        return window.KOP_Project.newProject();
    }
    console.error('[Data Form] Project module not found.');
}

function initializeCategoryTabs() {
    if (window.KOP_Project && typeof window.KOP_Project.initializeCategoryTabs === 'function') {
        return window.KOP_Project.initializeCategoryTabs();
    }
    console.error('[Data Form] Project module not found.');
}
window.initializeCategoryTabs = initializeCategoryTabs;

async function renameProject(oldName) {
    if (window.KOP_Project && typeof window.KOP_Project.renameProject === 'function') {
        return window.KOP_Project.renameProject(oldName);
    }
    console.error('[Data Form] Project module not found.');
}

async function deleteProject(projectName) {
    if (window.KOP_Project && typeof window.KOP_Project.deleteProject === 'function') {
        return window.KOP_Project.deleteProject(projectName);
    }
    console.error('[Data Form] Project module not found.');
}

async function recategorizeProject(projectName) {
    if (window.KOP_Project && typeof window.KOP_Project.recategorizeProject === 'function') {
        return window.KOP_Project.recategorizeProject(projectName);
    }
    console.error('[Data Form] Project module not found.');
}
// ============================================
// FORM DATA MANAGEMENT
// ============================================
function updateJSON() {
    if (typeof window.ensureReferrerDataStructures === 'function') {
        window.ensureReferrerDataStructures();
    }
    const jsonDisplay = document.getElementById('json-display');
    if (jsonDisplay) {
        jsonDisplay.textContent = JSON.stringify(window.formData, null, 2);
    }
}

window.updateJSON = updateJSON;

function updateArrayItemValue(path, index, value) {
    const { target, normalizedPath } = resolvePathTarget(path);
    if (!target) {
        return;
    }

    let array = getNestedValue(target, normalizedPath);
    if (!Array.isArray(array)) {
        array = [];
        setNestedValue(target, normalizedPath, array);
    }

    if (Array.isArray(array)) {
        // Ensure array has a slot for this index
        while (array.length <= index) {
            array.push('');
        }
        array[index] = value;
        if (path === 'identification.currentOwners') {
            const firstOwner = array[0] || '';
            setNestedValue(target, 'identification.currentOwner', firstOwner || '');
        }
        updateJSON();
        autoSave();
    }
}

function updateArrayObjectItemValue(path, index, field, value) {
    const { target, normalizedPath } = resolvePathTarget(path);
    if (!target) {
        return;
    }

    let array = getNestedValue(target, normalizedPath);
    if (!Array.isArray(array)) {
        array = [];
        setNestedValue(target, normalizedPath, array);
    }

    if (Array.isArray(array) && index >= 0 && index < array.length) {
        const isPastTTIJobs = /pastTTIJobs$/.test(path);
        const isAdditionalLocation = /locationDetails\.additionalLocations$/.test(path);
        if (typeof array[index] !== 'object' || array[index] === null) {
            if (isPastTTIJobs) {
                array[index] = { role: '', organization: '', employer: '' };
            } else if (isAdditionalLocation) {
                array[index] = { city: '', address: '', zip: '' };
            } else {
                array[index] = { role: '', name: '' };
            }
        }
        if (isPastTTIJobs && field === 'employer') {
            array[index].employer = value;
            array[index].organization = value;
        } else if (isPastTTIJobs && field === 'organization') {
            array[index].organization = value;
            if (!array[index].employer) {
                array[index].employer = value;
            }
        } else {
            array[index][field] = value;
        }

        updateJSON();
        autoSave();
    }
}

function addNewArrayItem(path) { if (window.KOP_UI_Actions) window.KOP_UI_Actions.addNewArrayItem(path); }

function removeArrayItemAtIndex(path, index) { if (window.KOP_UI_Actions) window.KOP_UI_Actions.removeArrayItemAtIndex(path, index); }

function renderArray(container, path, items) { if (window.KOP_UI_Render) window.KOP_UI_Render.renderArray(container, path, items); }

function loadOperatorData() {
    if (!window.formData) {
        debugLog('⚠️ loadOperatorData: formData not ready yet');
        return;
    }
    
    // Determine if we're in a location project - if so, use the facility's sourceOperator
    const activeTab = document.querySelector('.category-tab.active');
    const isLocationProject = activeTab && activeTab.dataset.category === 'locations';
    
    let operator;
    if (isLocationProject && window.formData.facilities && window.formData.facilities[window.currentFacilityIndex]) {
        // For location projects, use the current facility's sourceOperator
        const currentFacility = window.formData.facilities[window.currentFacilityIndex];
        operator = currentFacility.sourceOperator || {};
        debugLog('📍 Location project: loading operator from facility.sourceOperator:', operator.name);
    } else {
        // For company/referrer projects, use the project-level operator
        if (!window.formData.operator) window.formData.operator = createNewProjectData().operator;
        operator = window.formData.operator;
    }

    // Load Profit Status
    const profitStatus = operator.profitStatus || 'for-profit';
    const profitRadio = document.querySelector(`input[name="profitStatus"][value="${profitStatus}"]`);
    if (profitRadio) {
        profitRadio.checked = true;
    }

    const operatorName = document.getElementById('operator-name');
    if (operatorName) operatorName.value = operator.name || '';

    const operatorCurrentName = document.getElementById('operator-current-name');
    if (operatorCurrentName) operatorCurrentName.value = operator.currentName || '';

    const locationParts = parseCityState(operator.location || '');
    if (!operator.locationCity && locationParts.city) operator.locationCity = locationParts.city;
    if (!operator.locationState && locationParts.state) operator.locationState = locationParts.state;

    const headquartersParts = parseCityState(operator.headquarters || '');
    if (!operator.headquartersCity && headquartersParts.city) operator.headquartersCity = headquartersParts.city;
    if (!operator.headquartersState && headquartersParts.state) operator.headquartersState = headquartersParts.state;

    const operatorLocationCity = document.getElementById('operator-location-city');
    if (operatorLocationCity) operatorLocationCity.value = operator.locationCity || '';

    const operatorLocationState = document.getElementById('operator-location-state');
    if (operatorLocationState) operatorLocationState.value = operator.locationState || '';

    const operatorHeadquartersCity = document.getElementById('operator-headquarters-city');
    if (operatorHeadquartersCity) operatorHeadquartersCity.value = operator.headquartersCity || '';

    const operatorHeadquartersState = document.getElementById('operator-headquarters-state');
    if (operatorHeadquartersState) operatorHeadquartersState.value = operator.headquartersState || '';

    operator.location = combineCityState(operator.locationCity, operator.locationState);
    operator.headquarters = combineCityState(operator.headquartersCity, operator.headquartersState);

    const operatorFounded = document.getElementById('operator-founded');
    if (operatorFounded) operatorFounded.value = operator.founded || '';

    const operatorPeriod = document.getElementById('operator-period');
    if (operatorPeriod) operatorPeriod.value = operator.operatingPeriod || '';
    
    const operatorStatus = document.getElementById('operator-status');
    if (operatorStatus) operatorStatus.value = operator.status || '';
    
    const operatorCeo = document.getElementById('operator-ceo');
    if (operatorCeo) operatorCeo.value = operator.keyStaff?.ceo || '';

    const operatorNotes = document.getElementById('operator-notes');
    if (operatorNotes) operatorNotes.value = operator.notes || '';

    const arrayPaths = ['operator.otherNames', 'operator.parentCompanies', 'operator.websites', 'operator.keyStaff.founders', 'operator.keyStaff.keyExecutives', 'operator.investors'];
    arrayPaths.forEach(path => {
        const container = document.querySelector(`[data-path="${path}"]`);
        if (container) {
            renderArray(container, path, getNestedValue(operator, path.replace('operator.', '')));
        }
    });
}

function loadFacilityData() {
    if (!window.formData.facilities || window.formData.facilities.length === 0) {
        window.formData.facilities = createNewProjectData().facilities;
    }
    const facility = window.formData.facilities[window.currentFacilityIndex];
    if (!facility) return;

    document.querySelectorAll('.facility-field').forEach(field => {
        const path = field.dataset.field;
        if (path) {
            const value = getNestedValue(facility, path);
            if (field.type === 'checkbox') {
                field.checked = !!value;
            } else {
                field.value = value ?? '';
            }
        }
    });

    document.querySelectorAll('.facility-checkbox').forEach(checkbox => {
        const path = checkbox.dataset.field;
        checkbox.checked = !!getNestedValue(facility, path);
    });

    // Backfill empty Opened/Closed pickers from a legacy "Years of Operation"
    // string (e.g. "2016-2022") so old records adopt the picker-as-source model.
    // Staged into the inputs/formData only — persists when the user saves.
    if (facility.operatingPeriod && typeof window.kopParseYearsOfOperation === 'function') {
        const op = facility.operatingPeriod;
        const emptyS = op.startYear === null || op.startYear === undefined || String(op.startYear).trim() === '';
        const emptyE = op.endYear === null || op.endYear === undefined || String(op.endYear).trim() === '';
        const yoo = String(op.yearsOfOperation || '').trim();
        if (emptyS && emptyE && yoo) {
            const parsed = window.kopParseYearsOfOperation(yoo, op.status);
            if (parsed) {
                if (parsed.startYear != null) {
                    op.startYear = parsed.startYear;
                    const sEl = document.querySelector('.facility-field[data-field="operatingPeriod.startYear"]');
                    if (sEl) sEl.value = parsed.startYear;
                }
                if (parsed.endYear != null) {
                    op.endYear = parsed.endYear;
                    const eEl = document.querySelector('.facility-field[data-field="operatingPeriod.endYear"]');
                    if (eEl) eEl.value = parsed.endYear;
                }
            }
        }
    }

    // "Years of Operation" is derived from the Opened/Closed pickers; re-derive it
    // on load so it reflects the pickers (preserving any legacy freeform-only value).
    if (typeof window.kopSyncYearsOfOperation === 'function') {
        window.kopSyncYearsOfOperation(window.currentFacilityIndex, { preserveLegacy: true });
    }

    const facilityName = document.getElementById('facility-name');
    if (facilityName) facilityName.value = facility.identification?.name || '';
    
    const facilityType = document.getElementById('facility-type');
    if (facilityType) facilityType.value = facility.facilityDetails?.type || '';

    const arrayPaths = ['identification.otherNames', 'identification.knownReferrers', 'otherOperators', 'operatingPeriod.notes', 'staff.administrator', 'staff.notableStaff', 'staff.pastTTIJobs', 'profileLinks', 'accreditations.current', 'accreditations.past', 'memberships', 'certifications', 'licensing', 'resources.notes', 'notes', 'locationDetails.additionalLocations', 'locationDetails.formerLocations'];
    arrayPaths.forEach(path => {
        const container = document.querySelector(`[data-path="${path}"]`);
        if (container) {
            renderArray(container, path, getNestedValue(facility, path));
        }
    });
}

window.updateAllUI = function() {
    // Set flag to prevent autoSave during UI update
    isUpdatingUI = true;
    try {
        loadOperatorData();
        if (typeof window.loadReferrerData === 'function') {
            window.loadReferrerData();
        }
        loadFacilityData();
        updateFacilityControls();
        updateTableOfContents();
        updateJSON();
        window.KOP_UI_Render.renderSavedProjectsList();
        initializeSectionToggles();
        if (window.KOP_UI_State && typeof window.KOP_UI_State.updateProjectStatus === 'function') {
            window.KOP_UI_State.updateProjectStatus();
        }
        
        // Initialize autocomplete fields - use delegate or window function
        if (typeof delegateInitializeAutocompleteFields === 'function') {
            delegateInitializeAutocompleteFields();
        } else if (typeof window.initializeAutocompleteFields === 'function') {
            window.initializeAutocompleteFields();
        }
        
        if (window.KOP_UI_State && typeof window.KOP_UI_State.updateLabelsForProjectType === 'function') {
            window.KOP_UI_State.updateLabelsForProjectType(window.currentProjectName || '');
        }
        initializeNoteControls();
        if (typeof window.updateToolbarFacilityInfo === 'function') {
            window.updateToolbarFacilityInfo(); // Update toolbar when UI updates
        }

        // Update private ownership toggle state from facility data
        if (typeof window.updatePrivateOwnershipSliderAppearance === 'function') {
            window.updatePrivateOwnershipSliderAppearance();
        }

        // Update location facilities overview for location projects
        const activeTab = document.querySelector('.category-tab.active');
        if (activeTab && activeTab.dataset.category === 'locations' && typeof window.updateLocationFacilitiesOverview === 'function') {
            window.updateLocationFacilitiesOverview();
        }

        // Ensure referrer consultant UI stays in sync when loading referrer projects
        if (activeTab && activeTab.dataset.category === 'referrers' && typeof window.updateConsultantsUI === 'function') {
            window.updateConsultantsUI();
        }

        // Reinitialize autocomplete for facility status field
        const facilityStatusField = document.querySelector('.facility-field[data-field="operatingPeriod.status"]');
        if (facilityStatusField && facilityStatusField.dataset.autocompleteInit !== 'true') {
            delegateCreateAutocomplete(facilityStatusField, getAllStatuses, 'status');
        }
    } finally {
        // Clear flag after a short delay to allow any triggered events to complete
        setTimeout(() => { isUpdatingUI = false; }, 100);
    }
};

function updateTableOfContents() { if (window.KOP_UI_Render) window.KOP_UI_Render.updateTableOfContents(); }

function updateFacilityControls() { if (window.KOP_UI_Render) window.KOP_UI_Render.updateFacilityControls(); }

function navigateToFacility(index) { if (window.KOP_UI_Render) window.KOP_UI_Render.navigateToFacility(index); }

/**
 * Scroll the view to the form input section after loading a facility
 * Targets the facility controls or first form section for best UX
 */
function scrollToFormInput() { if (window.KOP_UI_Render) window.KOP_UI_Render.scrollToFormInput(); }

function addFacility() { if (window.KOP_UI_Actions) window.KOP_UI_Actions.addFacility(); }

function removeFacility() { if (window.KOP_UI_Actions) window.KOP_UI_Actions.removeFacility(); }

async function performClone(targetProjectName, targetCategory = null) { if (window.KOP_UI_Actions) return await window.KOP_UI_Actions.performClone(targetProjectName, targetCategory); }

function cloneFacility() { if (window.KOP_UI_Actions) window.KOP_UI_Actions.cloneFacility(); }

function sortFacilities() { if (window.KOP_UI_Actions) window.KOP_UI_Actions.sortFacilities(); }

// Debounce flag to prevent double-clicking navigation buttons
let isNavigating = false;

function previousFacility() { if (window.KOP_UI_Actions) window.KOP_UI_Actions.previousFacility(); }

function nextFacility() { if (window.KOP_UI_Actions) window.KOP_UI_Actions.nextFacility(); }

// Location constants now defined in location-form.js module
// Access via window.US_STATE_NAMES, window.COUNTRY_NAMES, window.US_STATE_SET, window.COUNTRY_SET

function getProjectStates(projectData = {}) {
    const states = new Set();

    // Facilities states
    (projectData.facilities || []).forEach((facility = {}) => {
        const parsed = parseCityState(facility.location || '');
        const state = (facility.locationState || parsed.state || '').trim();
        if (state) {
            states.add(state.toUpperCase());
        }
    });

    // Referrer agency state
    if (projectData.referrerAgency?.state) {
        states.add(String(projectData.referrerAgency.state).trim().toUpperCase());
    }

    // Individual consultant state
    if (projectData.referrerIndividual?.state) {
        states.add(String(projectData.referrerIndividual.state).trim().toUpperCase());
    }

    // Consultant list states
    (projectData.referrerConsultants || []).forEach((consultant = {}) => {
        if (consultant.state) {
            states.add(String(consultant.state).trim().toUpperCase());
        }
    });

    // Operator HQ state (if tracked)
    if (projectData.operator?.state) {
        states.add(String(projectData.operator.state).trim().toUpperCase());
    }

    return Array.from(states).filter(Boolean);
}

function determineProjectCategory(name) {
    if (window.KOP_Project && typeof window.KOP_Project.determineProjectCategory === 'function') {
        return window.KOP_Project.determineProjectCategory(name);
    }
    console.error('[Data Form] Project module not found.');
    return 'companies'; // Fallback
}

function renderSavedProjectsList() { if (window.KOP_UI_Render) window.KOP_UI_Render.renderSavedProjectsList(); }

function refreshSavedProjectPanels() { if (window.KOP_UI_Render) window.KOP_UI_Render.refreshSavedProjectPanels(); }

function refreshQuickFacilitiesList() { if (window.KOP_UI_Render) window.KOP_UI_Render.refreshQuickFacilitiesList(); }

window.jumpToFacility = function(index) { if (window.KOP_UI_Render) window.KOP_UI_Render.jumpToFacility(index); }

window.jumpToItem = function(index) { if (window.KOP_UI_Render) window.KOP_UI_Render.jumpToItem(index); }

function updateProjectStatus() { if (window.KOP_UI_State) window.KOP_UI_State.updateProjectStatus(); }
function updateLabelsForProjectType() { if (window.KOP_UI_State) window.KOP_UI_State.updateLabelsForProjectType(); }
// ============================================
// FILE IMPORT/EXPORT
// ============================================
function handleFileUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (e) => {
        try {
            const data = JSON.parse(e.target.result);
            let importedProjects = {};
            
            if (data.projects) {
                importedProjects = data.projects;
            } else {
                const projectName = data.operator?.name || `imported-${Date.now()}`;
                importedProjects[projectName] = {
                    name: projectName,
                    data: normalizeProjectData(data),
                    timestamp: new Date().toISOString()
                };
            }

            Object.entries(importedProjects).forEach(([key, project]) => {
                const isLocationProject = project?.category === 'locations'
                    || window.US_STATE_SET?.has?.(String(key).trim().toLowerCase())
                    || window.COUNTRY_SET?.has?.(String(key).trim().toLowerCase());
                const normalizedKey = isLocationProject && typeof window.normalizeLocationProjectName === 'function'
                    ? window.normalizeLocationProjectName(project?.name || key)
                    : key;

                window.projects[normalizedKey] = {
                    ...project,
                    name: normalizedKey
                };
            });

            if (typeof window.deduplicateLocationProjects === 'function') {
                window.projects = window.deduplicateLocationProjects(window.projects);
            }

            const importedProjectNames = Object.keys(importedProjects);
            const firstProject = importedProjectNames.length
                ? (typeof window.normalizeLocationProjectName === 'function'
                    ? window.normalizeLocationProjectName(importedProjects[importedProjectNames[0]]?.name || importedProjectNames[0])
                    : importedProjectNames[0])
                : null;
            if (firstProject) {
                loadProject(firstProject);
            }
            
            showUploadStatus(`Imported ${Object.keys(importedProjects).length} project(s).`, 'success');
        } catch (error) {
            showUploadStatus(`Import failed: ${error.message}`, 'error');
        }
    };
    reader.readAsText(file);
}

// copyToClipboard, downloadJSON, and buildProjectExport functions now defined in utilities.js module
// Access via window.copyToClipboard, window.downloadJSON, and window.buildProjectExport

function exportProjectsToFile({ categories = null, filename } = {}) {
    const filtered = buildProjectExport(categories);
    const count = Object.keys(filtered).length;

    if (!count) {
        const label = categories && categories.length ? categories.join(', ') : 'any';
        showUploadStatus(`No ${label} projects available to export.`, 'error');
        return;
    }

    const payload = {
        exportedAt: new Date().toISOString(),
        categories: categories && categories.length ? categories : ['all'],
        projectCount: count,
        projects: filtered
    };

    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename || `projects-export-${(categories && categories.length ? categories.join('-') : 'all')}.json`;
    a.click();
    URL.revokeObjectURL(url);
    showUploadStatus(`Exported ${count} project(s).`, 'success');
}

/**
 * Show modal to select projects and report type
 * @param {Array} categories - Categories to filter projects by
 * @returns {Promise<Object|null>} - {selectedProjects: [], reportType: 'quick'|'standard'|'full'}
 */
async function showProjectSelectionModal(categories = null) {
    // Step 1: Get projects from current category
    const filtered = buildProjectExport(categories);
    const projectEntries = Object.entries(filtered);

    if (projectEntries.length === 0) {
        const categoryLabel = categories ? categories.join(', ') : 'this category';
        await window.customAlert(`No projects found in ${categoryLabel}.`, 'No Projects');
        return null;
    }

    // Step 2: Sort alphabetically by project name (case-insensitive)
    projectEntries.sort((a, b) => a[0].toLowerCase().localeCompare(b[0].toLowerCase()));

    // Step 3: Build project selection options with detailed information
    const projectOptions = projectEntries.map(([name, project]) => {
        const data = project.data || project; // Handle wrapped data structure

        const savedAt = project.savedAt
            ? new Date(project.savedAt).toLocaleDateString('en-US', {
                year: 'numeric', month: 'short', day: 'numeric'
              })
            : 'Unknown date';

        // Extract location information based on project type
        let locationInfo = '';

        // For companies/operators - show headquarters and locations
        if (data.operator) {
            const hq = data.operator.headquarters ||
                      (data.operator.headquartersCity && data.operator.headquartersState
                        ? `${data.operator.headquartersCity}, ${data.operator.headquartersState}`
                        : '') ||
                      data.operator.location ||
                      (data.operator.locationCity && data.operator.locationState
                        ? `${data.operator.locationCity}, ${data.operator.locationState}`
                        : '');

            if (hq) {
                locationInfo = `HQ: ${hq}`;
            }

            // Add facility locations if available
            if (data.facilities && data.facilities.length > 0) {
                const states = new Set();
                const countries = new Set();

                data.facilities.forEach(facility => {
                    if (facility.state) states.add(facility.state);
                    if (facility.country && facility.country !== 'USA' && facility.country !== 'United States') {
                        countries.add(facility.country);
                    }
                });

                const locations = [];
                if (states.size > 0) {
                    locations.push(`States: ${Array.from(states).sort().join(', ')}`);
                }
                if (countries.size > 0) {
                    locations.push(`Countries: ${Array.from(countries).sort().join(', ')}`);
                }

                if (locations.length > 0) {
                    locationInfo += (locationInfo ? ' | ' : '') + locations.join(' | ');
                }
            }
        }

        // For locations - show state/country info
        if (data.state || data.city || data.location) {
            const loc = data.location ||
                       (data.city && data.state ? `${data.city}, ${data.state}` : '') ||
                       data.state ||
                       data.city;
            if (loc) {
                locationInfo = `Location: ${loc}`;
            }
        }

        const description = locationInfo
            ? `${locationInfo} | Last saved: ${savedAt}`
            : `Last saved: ${savedAt}`;

        return {
            value: name,
            label: name,
            description: description
        };
    });





    // Step 4: Show project selection modal (checkboxes)
    const selectionPromise = customChoice(
        `Select one or more projects to include in the report (${projectEntries.length} available):`,
        projectOptions,
        'Select Projects',
        { type: 'checkbox' }
    );

    // Add Select All / Deselect All buttons after modal is shown
    setTimeout(() => {
        const modal = document.querySelector('.custom-modal-container.active');
        if (modal) {
            const modalBody = modal.querySelector('.modal-body');
            const modalChoices = modal.querySelector('.modal-choices');

            if (modalBody && modalChoices && !modalBody.querySelector('.select-all-controls')) {
                // Create button container
                const buttonContainer = document.createElement('div');
                buttonContainer.className = 'select-all-controls';
                buttonContainer.style.cssText = 'margin: 10px 0; display: flex; gap: 10px;';

                // Select All button
                const selectAllBtn = document.createElement('button');
                selectAllBtn.textContent = 'Select All';
                selectAllBtn.className = 'modal-btn modal-btn-secondary';
                selectAllBtn.style.cssText = 'padding: 6px 12px; font-size: 13px;';
                selectAllBtn.onclick = (e) => {
                    e.preventDefault();
                    const checkboxes = modal.querySelectorAll('input[type="checkbox"]');
                    checkboxes.forEach(cb => cb.checked = true);
                };

                // Deselect All button
                const deselectAllBtn = document.createElement('button');
                deselectAllBtn.textContent = 'Deselect All';
                deselectAllBtn.className = 'modal-btn modal-btn-secondary';
                deselectAllBtn.style.cssText = 'padding: 6px 12px; font-size: 13px;';
                deselectAllBtn.onclick = (e) => {
                    e.preventDefault();
                    const checkboxes = modal.querySelectorAll('input[type="checkbox"]');
                    checkboxes.forEach(cb => cb.checked = false);
                };

                buttonContainer.appendChild(selectAllBtn);
                buttonContainer.appendChild(deselectAllBtn);

                // Insert before the choices
                modalBody.insertBefore(buttonContainer, modalChoices);
            }
        }
    }, 50);

    const selectedProjectNames = await selectionPromise;

    if (!selectedProjectNames || selectedProjectNames.length === 0) {
        return null; // User cancelled or selected nothing
    }

    // Step 5: Show report type selection (radio buttons)
    const reportConfig = new window.ReportConfig();
    const reportTypeOptions = reportConfig.getAllPresets();

    const reportType = await window.customChoice(
        `Selected ${selectedProjectNames.length} project(s). Choose report detail level:`,
        reportTypeOptions,
        'Select Report Type',
        { type: 'radio' }
    );

    if (!reportType) {
        return null; // User cancelled
    }

    return {
        selectedProjects: selectedProjectNames,
        reportType: reportType
    };
}

async function generateProjectsReport({ categories = null, filename } = {}) {
    console.log('📄 generateProjectsReport called', { categories, filename });
    const hasCategories = categories && categories.length > 0;
    let projectsToReport;
    let reportType = 'full'; // Default to full report

    console.log('🔍 Report Context:', {
        hasCategories,
        hasFormData: !!window.formData,
        currentProjectName: window.currentProjectName
    });

    if (hasCategories) {
        // Multiple project selection flow
        const selection = await showProjectSelectionModal(categories);

        if (!selection) {
            return; // User cancelled
        }

        const { selectedProjects, reportType: selectedReportType } = selection;
        reportType = selectedReportType;

        // Get selected project data and ensure alphabetical order
        const filtered = buildProjectExport(categories);

        // Sort selected project names alphabetically (case-insensitive)
        const sortedProjectNames = selectedProjects.sort((a, b) =>
            a.toLowerCase().localeCompare(b.toLowerCase())
        );

        projectsToReport = sortedProjectNames.map(name => {
            const project = filtered[name];
            if (!project) return null;

            // Apply report preset filtering
            if (reportType !== 'full' && window.ReportConfig) {
                const config = new window.ReportConfig();
                return config.filterFormData(project, reportType);
            }
            return project;
        }).filter(Boolean);

        if (projectsToReport.length === 0) {
            showUploadStatus('No valid projects selected.', 'error');
            return;
        }
    } else {
        // Single project flow
        const data = window.formData;

        if (!data || !window.currentProjectName) {
            // Show options for different types of reports using custom modal
            const options = [
                {
                    value: '1',
                    label: 'All Companies/Operators',
                    description: 'Generate a comprehensive report of all company operators in the database'
                },
                {
                    value: '2',
                    label: 'All Locations/States',
                    description: 'Generate a report organized by geographic locations and states'
                },
                {
                    value: '3',
                    label: 'All Referrers',
                    description: 'Generate a report of all referrer agencies and consultants'
                }
            ];

            // Use custom modal with radio buttons for clear options
            const choice = await window.customChoice(
                'No project is currently loaded. What kind of report would you like to generate?',
                options,
                'Select Report Type',
                { type: 'radio' }
            );

            if (!choice) return; // User cancelled

            let reportCategories, reportFilename;

            if (choice === '1') {
                reportCategories = ['companies'];
                reportFilename = 'projects-report-companies.json';
            } else if (choice === '2') {
                reportCategories = ['locations'];
                reportFilename = 'projects-report-locations.json';
            } else if (choice === '3') {
                reportCategories = ['referrers'];
                reportFilename = 'projects-report-referrers.json';
            }

            // Recursively call with the selected category
            if (reportCategories) {
                return generateProjectsReport({ categories: reportCategories, filename: reportFilename });
            }

            return;
        }

        // Show report type selection for single project
        const reportConfig = new window.ReportConfig();
        const reportTypeOptions = reportConfig.getAllPresets();

        const selectedReportType = await window.customChoice(
            `Generate report for: ${window.currentProjectName}`,
            reportTypeOptions,
            'Select Report Type',
            { type: 'radio' }
        );

        if (!selectedReportType) {
            return; // User cancelled
        }

        reportType = selectedReportType;

        // Apply preset filtering
        let reportData = data;
        if (reportType !== 'full' && window.ReportConfig) {
            const config = new window.ReportConfig();
            reportData = config.filterFormData(data, reportType);
        }

        projectsToReport = [reportData];
    }

    // Generate the report
    if (typeof window.FacilityReportGenerator !== 'function') {
        showUploadStatus('Report generator unavailable. Please reload the page.', 'error');
        return;
    }

    const title = hasCategories
        ? `${categories.join(', ').toUpperCase()} Report (${reportType.toUpperCase()})`
        : (window.currentProjectName ? `Report: ${window.currentProjectName} (${reportType.toUpperCase()})` : 'Project Report');

    const generator = new window.FacilityReportGenerator(projectsToReport, { title });
    generator.generateReport();
}

function generateReportHTML(data, skipHeader = false) { if (window.KOP_UI_Render) return window.KOP_UI_Render.generateReportHTML(data, skipHeader); }

// ============================================
// EVENT LISTENER ATTACHMENT
// ============================================
// Old local implementation - DEPRECATED - now using modular version from ui-events.js
// This function is replaced by the shim at line 328 which calls window.KOP_UI_Events.attachFieldListeners()
function attachFieldListeners_DEPRECATED_LOCAL() {
    // Profit Status Toggle Listener - OLD IMPLEMENTATION (radio buttons, not badge)
    const profitRadios = document.querySelectorAll('input[name="profitStatus"]');
    profitRadios.forEach(radio => {
        radio.addEventListener('change', () => {
            if (window.formData && window.formData.operator) {
                window.formData.operator.profitStatus = radio.value;
                updateJSON();
                autoSave();
            }
            if (window.KOP_UI_State && typeof window.KOP_UI_State.updateLabelsForProjectType === 'function') {
                window.KOP_UI_State.updateLabelsForProjectType();
            }
        });
    });

    // Operator fields
    const operatorFields = {
        'operator-name': (val) => {
            setNestedValue(window.formData, 'operator.name', val);
            // Custom values are saved by custom value recorder on blur/change
            updateJSON();
            autoSave();
        },
        'operator-current-name': (val) => {
            setNestedValue(window.formData, 'operator.currentName', val);
            // Custom values are saved by custom value recorder on blur/change
            updateJSON();
            autoSave();
        },
        'operator-location-city': (val) => {
            setNestedValue(window.formData, 'operator.locationCity', val);
            window.formData.operator.location = combineCityState(window.formData.operator.locationCity, window.formData.operator.locationState);
            updateJSON();
            autoSave();
        },
        'operator-location-state': (val) => {
            setNestedValue(window.formData, 'operator.locationState', val);
            window.formData.operator.location = combineCityState(window.formData.operator.locationCity, window.formData.operator.locationState);
            updateJSON();
            autoSave();
        },
        'operator-headquarters-city': (val) => {
            setNestedValue(window.formData, 'operator.headquartersCity', val);
            window.formData.operator.headquarters = combineCityState(window.formData.operator.headquartersCity, window.formData.operator.headquartersState);
            updateJSON();
            autoSave();
        },
        'operator-headquarters-state': (val) => {
            setNestedValue(window.formData, 'operator.headquartersState', val);
            window.formData.operator.headquarters = combineCityState(window.formData.operator.headquartersCity, window.formData.operator.headquartersState);
            updateJSON();
            autoSave();
        },
        'operator-founded': (val) => {
            setNestedValue(window.formData, 'operator.founded', val);
            updateJSON();
            autoSave();
        },
        'operator-period': (val) => {
            setNestedValue(window.formData, 'operator.operatingPeriod', val);
            updateJSON();
            autoSave();
        },
        'operator-status': (val) => {
            setNestedValue(window.formData, 'operator.status', val);
            // Custom values are saved by custom value recorder on blur/change
            updateJSON();
            autoSave();
        },
        'operator-ceo': (val) => {
            setNestedValue(window.formData, 'operator.keyStaff.ceo', val);
            // Custom values are saved by custom value recorder on blur/change
            updateJSON();
            autoSave();
        },
        'operator-notes': (val) => {
            setNestedValue(window.formData, 'operator.notes', val);
            updateJSON();
            autoSave();
        },
        'facility-name': (val) => {
            setNestedValue(window.formData, `facilities.${window.currentFacilityIndex}.identification.name`, val);
            // Custom values are saved by custom value recorder on blur/change
            window.updateAllUI();
            autoSave();
        },
        'facility-type': (val) => {
            setNestedValue(window.formData, `facilities.${window.currentFacilityIndex}.facilityDetails.type`, val);
            // Custom values are saved by custom value recorder on blur/change
            updateJSON();
            autoSave();
        }
    };

    Object.keys(operatorFields).forEach(id => {
        const el = document.getElementById(id);
        if (el && !el.dataset.listenerAttached) {
            el.addEventListener('input', (e) => operatorFields[id](e.target.value), { passive: true });
            el.dataset.listenerAttached = 'true';
        }
    });

    // Referrer field event listeners - handled by referrer-form.js module
    if (typeof window.attachReferrerFieldListeners === 'function') {
        window.attachReferrerFieldListeners();
    }

    // Consultant navigation buttons - handled by referrer-form.js module
    if (typeof window.initializeConsultantNavigation === 'function') {
        window.initializeConsultantNavigation();
    }

    // Consultants TOC toggle - handled by referrer-form.js module
    if (typeof window.initializeConsultantsTocToggle === 'function') {
        window.initializeConsultantsTocToggle();
    }

    // Facility fields
    document.querySelectorAll('.facility-field').forEach(field => {
        if (!field.dataset.listenerAttached) {
            field.addEventListener('input', () => {
                const path = field.dataset.field;
                let value = field.type === 'number' ? (field.value === '' ? null : parseInt(field.value)) : field.value;
                setNestedValue(window.formData.facilities[window.currentFacilityIndex], path, value);

                // Custom values are saved by custom value recorder on blur/change
                // No need to add them here on every keystroke

                updateJSON();
                autoSave();
            }, { passive: true });
            field.dataset.listenerAttached = 'true';
        }
    });

    // Checkboxes
    document.querySelectorAll('.facility-checkbox').forEach(checkbox => {
        if (!checkbox.dataset.listenerAttached) {
            const changeHandler = () => {
                const path = checkbox.dataset.field;
                if (path) {
                    setNestedValue(window.formData.facilities[window.currentFacilityIndex], path, checkbox.checked);
                }

                const controlledElementId = checkbox.dataset.controls;
                if (controlledElementId) {
                    const controlledElement = document.getElementById(controlledElementId);
                    if (controlledElement) {
                        controlledElement.style.display = checkbox.checked ? '' : 'none';
                    }
                }

                // Special handling for notes on checkboxes
                if (checkbox.checked) {
                    const scope = checkbox.dataset.noteScope;
                    const key = checkbox.dataset.noteKey;
                    if (scope && key) {
                        const notes = getFieldNotes(scope, key);
                        if (notes.length === 0) {
                            addFieldNote(scope, key);
                        }
                    }
                }

                updateJSON();
                autoSave();
            };

            checkbox.addEventListener('change', changeHandler, { passive: true });
            checkbox.dataset.listenerAttached = 'true';

            // Run handler once on init to set initial state, but without adding notes
            const initHandler = () => {
                const path = checkbox.dataset.field;
                const isChecked = path ? getNestedValue(window.formData.facilities[window.currentFacilityIndex], path) : checkbox.checked;
                checkbox.checked = !!isChecked;

                const controlledElementId = checkbox.dataset.controls;
                if (controlledElementId) {
                    const controlledElement = document.getElementById(controlledElementId);
                    if (controlledElement) {
                        controlledElement.style.display = checkbox.checked ? '' : 'none';
                    }
                }
            };
            initHandler();
        }
    });
}

function attachButtonListeners() {
    // Facility navigation
    const facilityButtons = {
        'add-facility-btn': addFacility,
        'add-facility-main-btn': addFacility, // Note: cannot be passive
        'remove-facility-btn': removeFacility,
        'clone-facility-btn': cloneFacility,
        'prev-facility-btn': previousFacility, // Note: cannot be passive
        'next-facility-btn': nextFacility, // Note: cannot be passive
        'sort-facilities-btn': sortFacilities
    };

    Object.keys(facilityButtons).forEach(id => {
        const btn = document.getElementById(id);
        if (btn && !btn.dataset.listenerAttached) {
            btn.addEventListener('click', facilityButtons[id]);
            btn.dataset.listenerAttached = 'true';
        }
    });

    // Toolbar buttons (if toolbar exists on this page)
    if (typeof window.initializeToolbarButtons === 'function') {
        window.initializeToolbarButtons();
    }

    // Project management
    const saveBtn = document.getElementById('save-project-btn');
    if (saveBtn && !saveBtn.dataset.listenerAttached) {
        saveBtn.onclick = () => {
            const projectName = document.getElementById('project-name')?.value?.trim();
            if (projectName) {
                window.KOP_API?.saveProjectToCloud?.(projectName);
            } else {;
                showUploadStatus('Please enter a project name', 'error');
            }
        };
        saveBtn.dataset.listenerAttached = 'true';
    }

    const deleteBtn = document.getElementById('delete-project-btn');
    if (deleteBtn && !deleteBtn.dataset.listenerAttached) {
        deleteBtn.onclick = () => {
            const projectName = document.getElementById('project-name')?.value?.trim();
            deleteProject(projectName);
        };
        deleteBtn.dataset.listenerAttached = 'true';
    }

    const newBtn = document.getElementById('new-project-btn');
    if (newBtn && !newBtn.dataset.listenerAttached) {
        newBtn.onclick = () => {
            newProject();
            if (window.KOP_UI_Render && typeof window.KOP_UI_Render.scrollToFormInput === 'function') {
                window.KOP_UI_Render.scrollToFormInput();
            }
        };
        newBtn.dataset.listenerAttached = 'true';
    }

    const exportAllBtn = document.getElementById('export-all-btn');
    if (exportAllBtn && !exportAllBtn.dataset.listenerAttached) {
        exportAllBtn.onclick = () => {
            exportProjectsToFile({ filename: 'projects-export-all.json' });
        };
        exportAllBtn.dataset.listenerAttached = 'true';
    }

    const generateReportBtn = document.getElementById('generate-report-btn');
    if (generateReportBtn && !generateReportBtn.dataset.listenerAttached) {
        generateReportBtn.onclick = () => {
            generateProjectsReport({ filename: 'projects-report-all.json' });
        };
        generateReportBtn.dataset.listenerAttached = 'true';
    }

    // Location project buttons
    const newBtnLocation = document.getElementById('new-project-btn-location');
    if (newBtnLocation && !newBtnLocation.dataset.listenerAttached) {
        newBtnLocation.onclick = () => {
            newProject();
            if (window.KOP_UI_Render && typeof window.KOP_UI_Render.scrollToFormInput === 'function') {
                window.KOP_UI_Render.scrollToFormInput();
            }
        };
        newBtnLocation.dataset.listenerAttached = 'true';
    }

    const exportAllBtnLocation = document.getElementById('export-all-btn-location');
    if (exportAllBtnLocation && !exportAllBtnLocation.dataset.listenerAttached) {
        exportAllBtnLocation.onclick = () => {
            exportProjectsToFile({ categories: ['locations'], filename: 'projects-export-locations.json' });
        };
        exportAllBtnLocation.dataset.listenerAttached = 'true';
    }

    const generateReportBtnLocation = document.getElementById('generate-report-btn-location');
    if (generateReportBtnLocation && !generateReportBtnLocation.dataset.listenerAttached) {
        generateReportBtnLocation.onclick = () => {
            generateProjectsReport({ categories: ['locations'], filename: 'projects-report-locations.json' });
        };
        generateReportBtnLocation.dataset.listenerAttached = 'true';
    }

    // Export All Locations button (bottom section with unique ID)
    const exportAllLocationsBtn = document.getElementById('export-all-locations-btn');
    if (exportAllLocationsBtn && !exportAllLocationsBtn.dataset.listenerAttached) {
        exportAllLocationsBtn.onclick = () => {
            exportProjectsToFile({ categories: ['locations'], filename: 'projects-export-locations.json' });
        };
        exportAllLocationsBtn.dataset.listenerAttached = 'true';
    }

    // Quick buttons (duplicate panel outside toolbar)
    const newBtnQuick = document.getElementById('new-project-quick-btn');
    if (newBtnQuick && !newBtnQuick.dataset.listenerAttached) {
        newBtnQuick.onclick = () => {
            newProject();
            if (window.KOP_UI_Render && typeof window.KOP_UI_Render.scrollToFormInput === 'function') {
                window.KOP_UI_Render.scrollToFormInput();
            }
        };
        newBtnQuick.dataset.listenerAttached = 'true';
    }

    const exportAllBtnQuick = document.getElementById('export-all-quick-btn');
    if (exportAllBtnQuick && !exportAllBtnQuick.dataset.listenerAttached) {
        exportAllBtnQuick.onclick = () => {
            exportProjectsToFile({ filename: 'projects-export-all.json' });
        };
        exportAllBtnQuick.dataset.listenerAttached = 'true';
    }

    const generateReportBtnQuick = document.getElementById('generate-report-quick-btn');
    if (generateReportBtnQuick && !generateReportBtnQuick.dataset.listenerAttached) {
        generateReportBtnQuick.onclick = () => {
            generateProjectsReport({ filename: 'projects-report-all.json' });
        };
        generateReportBtnQuick.dataset.listenerAttached = 'true';
    }

    const newBtnReferrer = document.getElementById('new-project-btn-referrer');
    if (newBtnReferrer && !newBtnReferrer.dataset.listenerAttached) {
        newBtnReferrer.onclick = () => {
            newProject();
            if (window.KOP_UI_Render && typeof window.KOP_UI_Render.scrollToFormInput === 'function') {
                window.KOP_UI_Render.scrollToFormInput();
            }
        };
        newBtnReferrer.dataset.listenerAttached = 'true';
    }

    const exportAllBtnReferrer = document.getElementById('export-all-btn-referrer');
    if (exportAllBtnReferrer && !exportAllBtnReferrer.dataset.listenerAttached) {
        exportAllBtnReferrer.onclick = () => {
            exportProjectsToFile({ categories: ['referrers'], filename: 'projects-export-referrers.json' });
        };
        exportAllBtnReferrer.dataset.listenerAttached = 'true';
    }

    const generateReportBtnReferrer = document.getElementById('generate-report-btn-referrer');
    if (generateReportBtnReferrer && !generateReportBtnReferrer.dataset.listenerAttached) {
        generateReportBtnReferrer.onclick = () => {
            generateProjectsReport({ categories: ['referrers'], filename: 'projects-report-referrers.json' });
        };
        generateReportBtnReferrer.dataset.listenerAttached = 'true';
    }

    const newReferrerBtn = document.getElementById('new-referrer-project-btn');
    if (newReferrerBtn && !newReferrerBtn.dataset.listenerAttached) {
        newReferrerBtn.onclick = () => {
            newProject();
            if (window.KOP_UI_Render && typeof window.KOP_UI_Render.scrollToFormInput === 'function') {
                window.KOP_UI_Render.scrollToFormInput();
            }
        };
        newReferrerBtn.dataset.listenerAttached = 'true';
    }

    const exportReferrerBtn = document.getElementById('export-referrer-projects-btn');
    if (exportReferrerBtn && !exportReferrerBtn.dataset.listenerAttached) {
        exportReferrerBtn.onclick = () => {
            exportProjectsToFile({ categories: ['referrers'], filename: 'projects-export-referrers.json' });
        };
        exportReferrerBtn.dataset.listenerAttached = 'true';
    }

    const generateReferrerReportBtn = document.getElementById('generate-referrer-report-btn');
    if (generateReferrerReportBtn && !generateReferrerReportBtn.dataset.listenerAttached) {
        generateReferrerReportBtn.onclick = () => {
            generateProjectsReport({ categories: ['referrers'], filename: 'projects-report-referrers.json' });
        };
        generateReferrerReportBtn.dataset.listenerAttached = 'true';
    }

    const saveReferrerBtn = document.getElementById('save-referrer-project-btn');
    if (saveReferrerBtn && !saveReferrerBtn.dataset.listenerAttached) {
        saveReferrerBtn.onclick = async () => {
            const projectName = document.getElementById('referrer-project-name')?.value?.trim();
            if (!projectName) {
                customAlert('Please enter a project name', 'Project Name Required').then(() => {
                    const projectNameInput = document.getElementById('referrer-project-name');
                    if (projectNameInput) projectNameInput.focus();
                });
                return;
            }

            // Ensure category is set correctly in both formData and projects cache
            if (window.formData) {
                window.formData.category = 'referrers';
            }
            if (!window.projects) window.projects = {};
            if (!window.projects[projectName]) {
                window.projects[projectName] = { category: 'referrers' };
            } else {
                window.projects[projectName].category = 'referrers';
            }
            // performSuggestionSubmission reads window.currentProjectName, not
            // #referrer-project-name, so make sure they agree before submitting.
            window.currentProjectName = projectName;

            // Public form: route through the suggestion modal so the data
            // actually reaches api/save-suggestion.php. saveProjectToCloud()
            // only persists a local draft in suggestion mode.
            const inSuggestionMode = window.KOP_FormConfig &&
                typeof window.KOP_FormConfig.isSuggestionMode === 'function' &&
                window.KOP_FormConfig.isSuggestionMode();

            if (inSuggestionMode) {
                if (typeof window.submitSuggestion === 'function') {
                    window.submitSuggestion();
                } else {
                    console.error('submitSuggestion not available');
                    showUploadStatus('Submission flow not loaded. Please refresh the page.', 'error');
                }
                return;
            }

            // Admin form: write directly to referrers_master via the cloud save path.
            if (window.KOP_API && typeof window.KOP_API.saveProjectToCloud === 'function') {
                try {
                    await window.KOP_API.saveProjectToCloud(projectName);
                } catch (err) {
                    console.error('Failed to save referrer project:', err);
                    showUploadStatus('Failed to save: ' + (err.message || 'Unknown error'), 'error');
                }
            } else {
                console.error('KOP_API.saveProjectToCloud not available');
                showUploadStatus('Save function not available. Please refresh the page.', 'error');
            }
        };
        saveReferrerBtn.dataset.listenerAttached = 'true';
    }

    const saveTransporterBtn = document.getElementById('save-transporter-project-btn');
    if (saveTransporterBtn && !saveTransporterBtn.dataset.listenerAttached) {
        saveTransporterBtn.onclick = async () => {
            const projectName = document.getElementById('transporter-project-name')?.value?.trim();
            if (!projectName) {
                customAlert('Please enter a project name', 'Project Name Required').then(() => {
                    const projectNameInput = document.getElementById('transporter-project-name');
                    if (projectNameInput) projectNameInput.focus();
                });
                return;
            }

            if (window.formData) {
                window.formData.category = 'transporters';
            }
            if (!window.projects) window.projects = {};
            if (!window.projects[projectName]) {
                window.projects[projectName] = { category: 'transporters' };
            } else {
                window.projects[projectName].category = 'transporters';
            }
            window.currentProjectName = projectName;

            const inSuggestionMode = window.KOP_FormConfig &&
                typeof window.KOP_FormConfig.isSuggestionMode === 'function' &&
                window.KOP_FormConfig.isSuggestionMode();

            if (inSuggestionMode) {
                if (typeof window.submitSuggestion === 'function') {
                    window.submitSuggestion();
                } else {
                    console.error('submitSuggestion not available');
                    showUploadStatus('Submission flow not loaded. Please refresh the page.', 'error');
                }
                return;
            }

            if (window.KOP_API && typeof window.KOP_API.saveProjectToCloud === 'function') {
                try {
                    await window.KOP_API.saveProjectToCloud(projectName);
                } catch (err) {
                    console.error('Failed to save transporter project:', err);
                    showUploadStatus('Failed to save: ' + (err.message || 'Unknown error'), 'error');
                }
            } else {
                console.error('KOP_API.saveProjectToCloud not available');
                showUploadStatus('Save function not available. Please refresh the page.', 'error');
            }
        };
        saveTransporterBtn.dataset.listenerAttached = 'true';
    }

    // Import/Export
    const copyBtn = document.getElementById('copy-json-btn');
    if (copyBtn && !copyBtn.dataset.listenerAttached) {
        copyBtn.addEventListener('click', copyToClipboard);
        copyBtn.dataset.listenerAttached = 'true';
    }

    const downloadBtn = document.getElementById('download-json-btn');
    if (downloadBtn && !downloadBtn.dataset.listenerAttached) {
        downloadBtn.addEventListener('click', downloadJSON);
        downloadBtn.dataset.listenerAttached = 'true';
    }

    const fileUpload = document.getElementById('file-upload');
    if (fileUpload && !fileUpload.dataset.listenerAttached) {
        fileUpload.addEventListener('change', handleFileUpload, { passive: true });
        fileUpload.dataset.listenerAttached = 'true';
    }

    // Search input event listeners - Delegated to data-search.js module
    if (window.KOP_Search && typeof window.KOP_Search.attachSearchListeners === 'function') {
        window.KOP_Search.attachSearchListeners();
    }
}

// ============================================
// INITIALIZATION
// ============================================
async function initializeForm() {
    debugLog('Initializing consolidated form with cloud-first storage...');
    logActiveDataFormConfigOnce();

    // Wait for referrer module to be ready
    if (typeof window.ensureReferrerDataStructures !== 'function') {
        console.warn('⚠️ Referrer module not ready, waiting...');
        for (let i = 0; i < 50; i++) { // Wait up to 5 seconds
            await new Promise(resolve => setTimeout(resolve, 100));
            if (typeof window.ensureReferrerDataStructures === 'function') {
                debugLog('✅ Referrer module loaded successfully after', i * 100, 'ms');
                break;
            }
        }
        if (typeof window.ensureReferrerDataStructures !== 'function') {
            console.error('❌ Referrer module failed to load after 5 seconds. Referrer form may not work.');
            showUploadStatus('Error: Referrer module failed to load.', 'error');
        }
    }

    // Wait for autocomplete module to be ready
    if (typeof window.initializeAutocompleteFields !== 'function' || window.initializeAutocompleteFields.toString().includes('module not loaded')) {
        console.warn('⚠️ Autocomplete module not ready, waiting...');
        for (let i = 0; i < 50; i++) { // Wait up to 5 seconds
            await new Promise(resolve => setTimeout(resolve, 100));
            if (typeof window.initializeAutocompleteFields === 'function' && !window.initializeAutocompleteFields.toString().includes('module not loaded')) {
                debugLog('✅ Autocomplete module loaded successfully after', i * 100, 'ms');
                break;
            }
        }
        if (typeof window.initializeAutocompleteFields !== 'function' || window.initializeAutocompleteFields.toString().includes('module not loaded')) {
            console.error('❌ Autocomplete module failed to load after 5 seconds. Autocomplete will not work.');
            showUploadStatus('Error: Autocomplete module failed to load.', 'error');
        }
    }

    // Wait for notes module to be ready
    if (typeof window.NotesModule === 'undefined') {
        console.warn('⚠️ Notes module not ready, waiting...');
        for (let i = 0; i < 50; i++) { // Wait up to 5 seconds
            await new Promise(resolve => setTimeout(resolve, 100));
            if (typeof window.NotesModule !== 'undefined') {
                debugLog('✅ Notes module loaded successfully after', i * 100, 'ms');
                break;
            }
        }
        if (typeof window.NotesModule === 'undefined') {
            console.error('❌ Notes module failed to load after 5 seconds. Field notes may not work.');
            showUploadStatus('Warning: Notes module failed to load.', 'error');
        }
    }

    // Wait for loader to be available (with better diagnostics)
    if (typeof window.KOP_FormLoader === 'undefined' || !window.KOP_LOADER_READY) {
        console.warn('⚠️ KOP_FormLoader not ready, waiting...', {
            'KOP_FormLoader exists': typeof window.KOP_FormLoader !== 'undefined',
            'KOP_LOADER_READY': window.KOP_LOADER_READY
        });

        // Wait up to 5 seconds for the loader
        for (let i = 0; i < 50; i++) {
            await new Promise(resolve => setTimeout(resolve, 100));
            if (typeof window.KOP_FormLoader !== 'undefined' && window.KOP_LOADER_READY) {
                debugLog('✅ KOP_FormLoader loaded successfully after', i * 100, 'ms');
                break;
            }
        }

        if (typeof window.KOP_FormLoader === 'undefined' || !window.KOP_LOADER_READY) {
            console.error('❌ KOP_FormLoader failed to load after 5 seconds');
            console.error('Debug info:', {
                'window.KOP_FormLoader': window.KOP_FormLoader,
                'window.KOP_LOADER_READY': window.KOP_LOADER_READY,
                'KOP_DATA_FORM_CONFIG': window.KOP_DATA_FORM_CONFIG
            });
            showUploadStatus('Failed to load data loader module', 'error');

            // Don't completely fail - try to continue with limited functionality
            console.warn('⚠️ Continuing with limited functionality...');
            window.projects = {};
        }
    }

    // Load custom data from localStorage (backup only) - from db-form-loader.js
    if (typeof window.KOP_FormLoader !== 'undefined' && typeof window.KOP_FormLoader.loadCustomDataFromLocalStorage === 'function') {
        window.KOP_FormLoader.loadCustomDataFromLocalStorage();
    } else {
        console.error('❌ loadCustomDataFromLocalStorage not available');
        // Initialize empty arrays as fallback
        window.customOperators = window.customOperators || [];
        window.customFacilityNames = window.customFacilityNames || [];
        window.customHumanNames = window.customHumanNames || [];
        window.customReferrers = window.customReferrers || [];
    }

    // Load all projects from cloud - from db-form-loader.js
    if (typeof window.KOP_FormLoader !== 'undefined' && typeof window.KOP_FormLoader.loadAllProjectsFromCloud === 'function') {
        try {
            await window.KOP_FormLoader.loadAllProjectsFromCloud();
            // Keep local reference in sync with loader output
            projects = window.projects || projects;
        } catch (error) {
            console.error('❌ Error loading projects:', error);
            showUploadStatus('Error loading projects: ' + error.message, 'error');
            // Ensure projects object exists even if loading failed
            window.projects = window.projects || {};
            projects = window.projects;
        }
    } else {
        console.error('❌ loadAllProjectsFromCloud not available');
        // Ensure projects object exists
        window.projects = window.projects || {};
        projects = window.projects;
    }

    // Initialize form data if needed
    if (!window.formData) {
        window.formData = createNewProjectData();
    }

    // Attach all event listeners
    attachFieldListeners();
    attachButtonListeners();

    // Initialize array containers
    document.querySelectorAll('.array-container').forEach(container => {
        const path = container.dataset.path;
        if (path) {
            const target = path.startsWith('operator.') ? window.formData.operator : window.formData.facilities[window.currentFacilityIndex];
            const array = getNestedValue(target, path.replace('operator.', ''));
            renderArray(container, path, array);
        }
    });

    // Initialize autocomplete fields
    if (typeof delegateInitializeAutocompleteFields === 'function') {
        delegateInitializeAutocompleteFields();
    } else if (typeof window.initializeAutocompleteFields === 'function') {
        window.initializeAutocompleteFields();
    }

    initializeCategoryTabs();
    // Update all UI
    window.updateAllUI();

    // Initialize field notes functionality
    try {
        initializeFieldNotes();
    } catch (error) {
        console.error('Error initializing field notes:', error);
    }

    // Signal that the form and its functions are ready
    window.formReady = true;
    document.dispatchEvent(new CustomEvent('formReady'));
    debugLog('🚀 Dispatched formReady event.');

    debugLog('Form initialized successfully with', Object.keys(projects).length, 'projects from cloud');
}

// ============================================
// FIELD NOTES - Delegated to notes.js module
// ============================================
// All field notes functionality is now provided by the NotesModule in notes.js
// The following functions delegate to NotesModule for backward compatibility:

function getCurrentFacilityNotes() {
    if (window.NotesModule && typeof window.NotesModule.getCurrentFacilityNotes === 'function') {
        return window.NotesModule.getCurrentFacilityNotes();
    }
    return {};
}

/**
 * Expose the current facility record to modules (e.g., NotesModule)
 */
function getCurrentFacilityData() {
    if (!window.formData || !Array.isArray(window.formData.facilities)) {
        return null;
    }
    return window.formData.facilities[window.currentFacilityIndex] || null;
}

/**
 * Allow modules to update the current facility (primarily fieldNotes) and persist
 */
function updateCurrentFacilityData(partial = {}) {
    const facility = getCurrentFacilityData();
    if (!facility || typeof partial !== 'object') {
        return;
    }

    // Normalize and persist fieldNotes if provided
    if (partial.fieldNotes && typeof partial.fieldNotes === 'object') {
        if (typeof normalizeFieldNotesEntries === 'function') {
            normalizeFieldNotesEntries(partial.fieldNotes);
        }
        facility.fieldNotes = partial.fieldNotes;
    }

    // Apply any additional updates (defensive copy)
    Object.keys(partial).forEach((key) => {
        if (key === 'fieldNotes') return;
        facility[key] = partial[key];
    });

    if (typeof window.updateJSON === 'function') {
        window.updateJSON();
    }
    if (typeof window.autoSave === 'function') {
        window.autoSave();
    }
}

function initializeFieldNotes() {
    if (window.NotesModule && typeof window.NotesModule.initializeFieldNotes === 'function') {
        window.NotesModule.initializeFieldNotes();
    }
}

function syncFieldNotes(facilityData) {
    if (window.NotesModule && typeof window.NotesModule.syncFieldNotes === 'function') {
        window.NotesModule.syncFieldNotes(facilityData);
    }
}

function getCurrentFieldNotesSnapshot() {
    return getCurrentFacilityNotes();
}

function addNoteButtons() {
    if (window.NotesModule && typeof window.NotesModule.addNoteButtons === 'function') {
        window.NotesModule.addNoteButtons();
    }
}

// Make key functions globally available
window.loadProject = loadProject;
window.newProject = newProject;
// Safe accessors for API functions (may not be loaded yet)
window.saveProjectToCloud = function() {
    return window.KOP_API?.saveProjectToCloud?.apply(this, arguments);
};
window.persistProjectLocally = function() {
    return window.KOP_API?.persistProjectLocally?.apply(this, arguments);
};
window.addFacility = addFacility;
window.removeFacility = removeFacility;
window.renameProject = renameProject;
window.recategorizeProject = recategorizeProject;
window.deleteProject = deleteProject;
window.cloneFacility = cloneFacility;
window.previousFacility = previousFacility;
window.nextFacility = nextFacility;
window.sortFacilities = sortFacilities;
window.navigateToFacility = navigateToFacility;
window.scrollToFormInput = scrollToFormInput;
window.generateProjectsReport = generateProjectsReport;
// copyToClipboard and downloadJSON now exported from utilities.js module
window.refreshSavedProjectPanels = refreshSavedProjectPanels;
// Only expose autocomplete helpers if the module did not already register them
if (typeof window.createAutocomplete !== 'function') {
    window.createAutocomplete = delegateCreateAutocomplete;
}
if (typeof window.initializeAutocompleteFields !== 'function') {
    window.initializeAutocompleteFields = delegateInitializeAutocompleteFields;
}
window.invalidateAggregatedData = invalidateAggregatedData;
window.normalizeProjectData = normalizeProjectData;
window.initializeSectionToggles = initializeSectionToggles;
window.initializeMobileSectionControls = initializeMobileSectionControls;
window.expandAllSections = expandAllSections;
window.collapseAllSections = collapseAllSections;

// ============================================
// TAB-SWITCHING INITIALIZATION FOR OVERVIEWS
// ============================================

function initializeOverviewTabSwitching() {
    // Initialize overviews based on active tab when page loads
    const initializeActiveTabOverview = () => {
        setTimeout(() => {
            const activeTab = document.querySelector('.category-tab.active');
            if (activeTab) {
                if (activeTab.dataset.category === 'referrers' && typeof window.updateConsultantsUI === 'function') {
                    window.updateConsultantsUI();
                } else if (activeTab.dataset.category === 'locations' && typeof window.updateLocationFacilitiesOverview === 'function') {
                    window.updateLocationFacilitiesOverview();
                }
            }
        }, 100);
    };

    // Call on page load
    window.addEventListener('load', initializeActiveTabOverview);

    // Also update overviews when switching tabs
    document.addEventListener('click', function(e) {
        const tab = e.target.closest('.category-tab');
        if (tab) {
            setTimeout(() => {
                if (tab.dataset.category === 'referrers') {
                    if (typeof window.updateConsultantsUI === 'function') {
                        window.updateConsultantsUI();
                    }
                } else if (tab.dataset.category === 'locations') {
                    if (typeof window.updateLocationFacilitiesOverview === 'function') {
                        window.updateLocationFacilitiesOverview();
                    }
                }
            }, 100);
        }
    });
}

// Expose to global scope
window.initializeOverviewTabSwitching = initializeOverviewTabSwitching;

// Expose project loading function for rebuild operations (safe accessor)
window.loadProjectsFromServer = function() {
    return window.KOP_API?.loadAllProjectsFromCloud?.apply(this, arguments);
};

// Create projectManager object for backwards compatibility
window.projectManager = {
    loadProject: loadProject,
    newProject: newProject,
    saveProjectToCloud: function() {
        return window.KOP_API?.saveProjectToCloud?.apply(this, arguments);
    }
};

// Make field notes functions globally available
window.syncFieldNotes = syncFieldNotes;
window.getFieldNotes = getCurrentFieldNotesSnapshot;
window.addNoteButtons = addNoteButtons;

// ============================================
// AUTOCOMPLETE CLICK HANDLERS
// Click handlers are now attached directly to each dropdown
// when it's created (see setupAutocomplete function above)
// This prevents interference with other page elements like toggles
// ============================================

// Initialize on DOMContentLoaded
// Log script execution
console.log('[Data Form] Script started executing');
console.log('[Data Form] Checking loader:', {
    'KOP_FormLoader exists': typeof window.KOP_FormLoader !== 'undefined',
    'KOP_LOADER_READY': window.KOP_LOADER_READY,
    'KOP_DATA_FORM_CONFIG': window.KOP_DATA_FORM_CONFIG
});

if (document.readyState === 'loading') {
    console.log('[Data Form] DOM still loading, waiting for DOMContentLoaded');
    document.addEventListener('DOMContentLoaded', initializeForm);
} else {
    console.log('[Data Form] DOM already loaded, calling initializeForm immediately');
    initializeForm();
}


// Fallback: sometimes remote resources or slow loads cause UI bits to render incorrectly.
// Re-run lightweight initialization checks on window.load to recover from intermittent failures.
window.addEventListener('load', () => {
    debugLog('data-form.v4.js: window.load fired — verifying form initialization');

    // ONLY run once - use flag to prevent multiple calls (FIX #1: Prevents rendering loops)
    if (window._uiInitializedOnLoad) {
        debugLog('✅ UI already initialized on load, skipping duplicate initialization');
        return;
    }
    window._uiInitializedOnLoad = true;

    setTimeout(() => {
        try {
            // Only update UI if formData is ready
            if (window.formData && typeof window.updateAllUI === 'function') {
                window.updateAllUI();
                debugLog('✅ data-form.v4.js: updateAllUI re-run on load (once)');
            } else {
                debugLog('⚠️ Skipping updateAllUI on load - formData not ready');
            }
        } catch (e) {
            console.error('❌ data-form.v4.js: error during load-time UI verification', e);
        }
    }, 150);
}, { passive: true });

