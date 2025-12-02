// ============================================
// CONSOLIDATED FACILITY FORM - CLOUD FIRST
// All functionality restored, cloud-first storage
// ============================================

// ============================================
// CONSTANTS & CONFIGURATION
// ============================================
const SCRIPT_BUILD_VERSION = 'facility-form.v3.sql-autocomplete.2025-10-15.notes';
if (typeof window !== 'undefined') {
    window.KOP_FACILITY_FORM_VERSION = SCRIPT_BUILD_VERSION;
}

const FACILITY_FORM_CONFIG = window.KOP_FACILITY_FORM_CONFIG || {};

// Helper to get API_ENDPOINTS from the loader (merged with our fallbacks below)
let API_ENDPOINT_FALLBACKS = {};
const getAPIEndpoints = () => {
    const loaderEndpoints = window.KOP_FormLoader?.API_ENDPOINTS || {};

    const saveMaster = loaderEndpoints.SAVE_MASTER || loaderEndpoints.SAVE_PROJECT || API_ENDPOINT_FALLBACKS.SAVE_MASTER;
    const saveSuggestion = loaderEndpoints.SAVE_SUGGESTION || loaderEndpoints.SAVE_PROJECT_SUGGESTION || API_ENDPOINT_FALLBACKS.SAVE_SUGGESTION || saveMaster;
    const loadProjects = loaderEndpoints.LOAD_PROJECTS || API_ENDPOINT_FALLBACKS.LOAD_PROJECTS;
    const autocomplete = loaderEndpoints.AUTOCOMPLETE || loaderEndpoints.SUGGESTIONS || API_ENDPOINT_FALLBACKS.AUTOCOMPLETE;

    return {
        SAVE_MASTER: saveMaster,
        SAVE_SUGGESTION: saveSuggestion,
        SAVE_PROJECT: (typeof window !== 'undefined' && window.FORM_MODE === 'suggestions') ? saveSuggestion : saveMaster,
        LOAD_PROJECTS: loadProjects,
        AUTOCOMPLETE: autocomplete,
        REST_SAVE_PROJECT: FACILITY_FORM_CONFIG.restSaveUrl || API_ENDPOINT_FALLBACKS.REST_SAVE_PROJECT || '/wp-json/kop/v1/projects/save',
        REST_DELETE_PROJECT: FACILITY_FORM_CONFIG.restDeleteUrl || API_ENDPOINT_FALLBACKS.REST_DELETE_PROJECT || '/wp-json/kop/v1/projects/delete',
        REST_NONCE: FACILITY_FORM_CONFIG.restNonce || ''
    };
};

function getResolverEndpoint(filename, fallback) {
    if (typeof window !== 'undefined' && window.KOP_API && typeof window.KOP_API.getEndpoint === 'function') {
        return window.KOP_API.getEndpoint(filename);
    }

    return fallback;
}

function resolveApiUrl(path, bases) {
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) {
        return path;
    }

    const normalizedPath = path.startsWith('/') ? path : `/${path}`;
    const baseCandidates = Array.isArray(bases)
        ? bases
        : (bases ? [bases] : []);

    for (const candidate of baseCandidates) {
        if (typeof candidate !== 'string') {
            continue;
        }

        const normalizedBase = candidate.replace(/\/$/, '');

        if (!normalizedBase) {
            continue;
        }

        return `${normalizedBase}${normalizedPath}`;
    }

    return normalizedPath;
}

const explicitBase = FACILITY_FORM_CONFIG.apiBase;
const fallbackBases = FACILITY_FORM_CONFIG.apiBaseFallbacks;

const apiBaseCandidates = [];

if (Array.isArray(explicitBase)) {
    apiBaseCandidates.push(...explicitBase);
} else if (typeof explicitBase === 'string' && explicitBase) {
    apiBaseCandidates.push(explicitBase);
}

if (Array.isArray(fallbackBases)) {
    apiBaseCandidates.push(...fallbackBases);
}

if (typeof window !== 'undefined' && Array.isArray(window.KOP_THEME_BASES)) {
    apiBaseCandidates.push(...window.KOP_THEME_BASES);
}

if (!apiBaseCandidates.length && typeof window !== 'undefined' && window.location && window.location.origin) {
    apiBaseCandidates.push(window.location.origin);
}

const normalizedApiBases = Array.from(new Set(
    apiBaseCandidates
        .map((base) => (typeof base === 'string' ? base.trim() : ''))
        .filter(Boolean)
        .map((base) => base.replace(/\/$/, ''))
        .filter(Boolean)
));

const DEFAULT_SAVE_MASTER = FACILITY_FORM_CONFIG.endpoints?.SAVE_PROJECT ||
    getResolverEndpoint('save-master.php', '/wp-content/themes/child/api/save-master.php');

const DEFAULT_SAVE_SUGGESTION = FACILITY_FORM_CONFIG.endpoints?.SAVE_SUGGESTION ||
    FACILITY_FORM_CONFIG.endpoints?.SAVE_PROJECT_SUGGESTION ||
    getResolverEndpoint('save-suggestion.php', '/wp-content/themes/child/api/save-suggestion.php');

// WordPress REST API endpoints as reliable fallbacks
const REST_API_BASE = '/wp-json/kop/v1';
const REST_SAVE_PROJECT = REST_API_BASE + '/projects/save';
const REST_DELETE_PROJECT = REST_API_BASE + '/projects/delete';
const REST_LOAD_PROJECTS = REST_API_BASE + '/projects';

const defaultApiPaths = {
    SAVE_MASTER: DEFAULT_SAVE_MASTER,
    SAVE_SUGGESTION: DEFAULT_SAVE_SUGGESTION,
    LOAD_PROJECTS:
        FACILITY_FORM_CONFIG.endpoints?.LOAD_PROJECTS ||
        getResolverEndpoint('get-master-data.php', '/wp-content/themes/child/api/get-master-data.php'),
    AUTOCOMPLETE:
        FACILITY_FORM_CONFIG.endpoints?.AUTOCOMPLETE ||
        FACILITY_FORM_CONFIG.endpoints?.SUGGESTIONS ||
        getResolverEndpoint('get-autocomplete.php', '/wp-content/themes/child/api/get-autocomplete.php'),
    // REST API fallbacks
    REST_SAVE_PROJECT: REST_SAVE_PROJECT,
    REST_DELETE_PROJECT: REST_DELETE_PROJECT,
    REST_LOAD_PROJECTS: REST_LOAD_PROJECTS
};

const API_ENDPOINTS = Object.keys(defaultApiPaths).reduce((acc, key) => {
    acc[key] = resolveApiUrl(defaultApiPaths[key], normalizedApiBases);
    return acc;
}, {});

API_ENDPOINT_FALLBACKS = {
    SAVE_MASTER: API_ENDPOINTS.SAVE_MASTER || API_ENDPOINTS.SAVE_PROJECT,
    SAVE_SUGGESTION: API_ENDPOINTS.SAVE_SUGGESTION || API_ENDPOINTS.SAVE_MASTER || API_ENDPOINTS.SAVE_PROJECT,
    LOAD_PROJECTS: API_ENDPOINTS.LOAD_PROJECTS,
    AUTOCOMPLETE: API_ENDPOINTS.AUTOCOMPLETE,
    REST_SAVE_PROJECT: API_ENDPOINTS.REST_SAVE_PROJECT,
    REST_DELETE_PROJECT: API_ENDPOINTS.REST_DELETE_PROJECT
};

const resolvedFormMode = typeof FACILITY_FORM_CONFIG.mode === 'string'
    ? FACILITY_FORM_CONFIG.mode
    : (typeof window !== 'undefined' && typeof window.FORM_MODE === 'string' ? window.FORM_MODE : 'master');

const FORM_MODE = typeof resolvedFormMode === 'string'
    ? resolvedFormMode.toLowerCase()
    : 'master';

const IS_SUGGESTION_MODE = FORM_MODE === 'suggestions';

function isSuggestionMode() {
    if (typeof window !== 'undefined' && typeof window.FORM_MODE === 'string') {
        return window.FORM_MODE.toLowerCase() === 'suggestions';
    }
    return IS_SUGGESTION_MODE;
}

const fallbackProjectsConfigValues = Array.isArray(FACILITY_FORM_CONFIG.fallbackProjectsUrls)
    ? FACILITY_FORM_CONFIG.fallbackProjectsUrls.slice()
    : [];

if (typeof FACILITY_FORM_CONFIG.fallbackProjectsUrl === 'string' && FACILITY_FORM_CONFIG.fallbackProjectsUrl.trim()) {
    fallbackProjectsConfigValues.unshift(FACILITY_FORM_CONFIG.fallbackProjectsUrl);
}

const FALLBACK_PROJECTS_URL_CANDIDATES = Array.from(new Set(
    fallbackProjectsConfigValues
        .map((value) => (typeof value === 'string' ? value.trim() : ''))
        .filter(Boolean)
        .map((value) => resolveApiUrl(value, normalizedApiBases))
        .filter(Boolean)
));

const FALLBACK_PROJECTS_URL = FALLBACK_PROJECTS_URL_CANDIDATES.length
    ? FALLBACK_PROJECTS_URL_CANDIDATES[0]
    : null;

function isTruthyFlag(value) {
    if (typeof value === 'boolean') {
        return value;
    }

    if (typeof value === 'string') {
        const normalized = value.trim().toLowerCase();
        if (!normalized) {
            return false;
        }

        return ['1', 'true', 'yes', 'on', 'enable', 'enabled'].includes(normalized);
    }

    return false;
}

const DEBUG_LOGGING_ENABLED = (() => {
    if (isTruthyFlag(FACILITY_FORM_CONFIG.debugLogging)) {
        return true;
    }

    if (isTruthyFlag(FACILITY_FORM_CONFIG.debug)) {
        return true;
    }

    if (typeof window !== 'undefined') {
        if (isTruthyFlag(window.KOP_FACILITY_FORM_DEBUG)) {
            return true;
        }

        try {
            if (window.localStorage && isTruthyFlag(window.localStorage.getItem('KOP_FACILITY_FORM_DEBUG'))) {
                return true;
            }

            if (window.sessionStorage && isTruthyFlag(window.sessionStorage.getItem('KOP_FACILITY_FORM_DEBUG'))) {
                return true;
            }
        } catch (storageFlagError) {
            // Ignore storage errors caused by privacy settings
        }
    }

    return false;
})();

// debugLog function now defined in utilities.js module
// Access via window.debugLog

function logActiveFacilityFormConfigOnce() {
    if (typeof window === 'undefined') {
        return;
    }

    if (window.__KOP_FACILITY_FORM_CONFIG_LOGGED) {
        return;
    }

    window.__KOP_FACILITY_FORM_CONFIG_LOGGED = true;

    if (!DEBUG_LOGGING_ENABLED || typeof console === 'undefined') {
        return;
    }

    try {
        debugLog('[KOP Facility Form] Loaded script build %s', SCRIPT_BUILD_VERSION);
        if (normalizedApiBases.length) {
            debugLog('[KOP Facility Form] API base candidates:', normalizedApiBases);
        }
        debugLog('[KOP Facility Form] Resolved API endpoints:', API_ENDPOINTS);
        debugLog('[KOP Facility Form] Active form mode:', FORM_MODE);
        if (FALLBACK_PROJECTS_URL_CANDIDATES.length) {
            debugLog('[KOP Facility Form] Fallback dataset URL candidates:', FALLBACK_PROJECTS_URL_CANDIDATES);
        } else {
            debugLog('[KOP Facility Form] No fallback dataset configured');
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
    debugLog('[Facility Form] Cannot invalidate cache - autocomplete module not loaded');
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

// Normalize project data to handle different field name variations
// Also ensures default structures exist for all required fields
function normalizeProjectData(data) {
    // If the payload is stringified JSON, parse it first
    if (typeof data === 'string') {
        try {
            data = JSON.parse(data);
        } catch (err) {
            console.warn('normalizeProjectData: failed to parse string data', err);
            data = {};
        }
    }

    // Handle null/undefined data - create default structure
    if (!data) {
        return typeof createNewProjectData === 'function' ? createNewProjectData() : {
            operator: { name: "", otherNames: [], websites: [], investors: [], keyStaff: { ceo: "", founders: [], keyExecutives: [] }, notes: [] },
            facilities: [],
            referrerAgency: typeof createDefaultReferrerGroup === 'function' ? createDefaultReferrerGroup() : { name: "", affiliations: [], keyPersonnel: [], notes: "", fieldNotes: {} },
            referrerConsultants: [typeof createDefaultReferrerIndividual === 'function' ? createDefaultReferrerIndividual() : { firstName: "", lastName: "", affiliations: [], facilitiesReferred: [], fieldNotes: {} }],
            fieldNotes: {}
        };
    }
    
    if (typeof data !== 'object') return data;

    // Helper to normalize fieldNotes entries - ensures all values are arrays, not objects
    // This fixes a bug where database can store empty notes as {} instead of []
    const normalizeFieldNotesEntries = (fieldNotesObj) => {
        if (!fieldNotesObj || typeof fieldNotesObj !== 'object') return;
        Object.keys(fieldNotesObj).forEach(key => {
            const value = fieldNotesObj[key];
            if (!Array.isArray(value)) {
                // Convert non-array to array
                if (value === null || value === undefined) {
                    fieldNotesObj[key] = [];
                } else if (typeof value === 'object') {
                    // Extract values from object (handles {} case)
                    const values = Object.values(value);
                    fieldNotesObj[key] = values.filter(v => v !== null && v !== undefined && `${v}`.trim() !== '');
                } else if (`${value}`.trim() !== '') {
                    fieldNotesObj[key] = [`${value}`];
                } else {
                    fieldNotesObj[key] = [];
                }
            }
        });
    };

    // Helper to get value trying different key variations
    const getValue = (obj, ...keys) => {
        for (const key of keys) {
            if (obj && obj[key] !== undefined) return obj[key];
        }
        return undefined;
    };

    // Helper to normalize an object's keys
    const normalizeObject = (obj, fieldMap) => {
        if (!obj) return obj;
        const normalized = { ...obj };

        Object.entries(fieldMap).forEach(([targetKey, sourceKeys]) => {
            const value = getValue(obj, ...sourceKeys);
            if (value !== undefined && normalized[targetKey] === undefined) {
                normalized[targetKey] = value;
            }
        });

        return normalized;
    };

    // Normalize operator data
    if (data.operator) {
        data.operator = normalizeObject(data.operator, {
            name: ['name', 'Name', 'companyName', 'company_name', 'operatorName', 'operator_name'],
            currentName: ['currentName', 'current_name', 'CurrentName'],
            location: ['location', 'Location'],
            locationCity: ['locationCity', 'location_city', 'city'],
            locationState: ['locationState', 'location_state', 'state'],
            headquarters: ['headquarters', 'Headquarters', 'hq'],
            headquartersCity: ['headquartersCity', 'headquarters_city'],
            headquartersState: ['headquartersState', 'headquarters_state'],
            founded: ['founded', 'Founded', 'foundedYear', 'founded_year'],
            operatingPeriod: ['operatingPeriod', 'operating_period', 'OperatingPeriod'],
            status: ['status', 'Status'],
            notes: ['notes', 'Notes'],
            otherNames: ['otherNames', 'other_names', 'aliases', 'alternateNames'],
            parentCompanies: ['parentCompanies', 'parent_companies', 'parents'],
            websites: ['websites', 'Websites', 'urls'],
            investors: ['investors', 'Investors']
        });

        // Normalize array fields to ensure they're arrays
        ['otherNames', 'parentCompanies', 'websites', 'investors'].forEach(field => {
            if (data.operator[field] && !Array.isArray(data.operator[field])) {
                // Convert string to array
                if (typeof data.operator[field] === 'string') {
                    data.operator[field] = [data.operator[field]];
                } else {
                    data.operator[field] = [];
                }
            } else if (!data.operator[field]) {
                data.operator[field] = [];
            }
        });

        // Normalize nested keyStaff
        if (data.operator.keyStaff) {
            data.operator.keyStaff = normalizeObject(data.operator.keyStaff, {
                ceo: ['ceo', 'CEO', 'chiefExecutiveOfficer'],
                founders: ['founders', 'Founders'],
                keyExecutives: ['keyExecutives', 'key_executives', 'executives']
            });

            // Ensure keyStaff arrays are actually arrays
            ['founders', 'keyExecutives'].forEach(field => {
                if (data.operator.keyStaff[field] && !Array.isArray(data.operator.keyStaff[field])) {
                    if (typeof data.operator.keyStaff[field] === 'string') {
                        data.operator.keyStaff[field] = [data.operator.keyStaff[field]];
                    } else {
                        data.operator.keyStaff[field] = [];
                    }
                } else if (!data.operator.keyStaff[field]) {
                    data.operator.keyStaff[field] = [];
                }
            });
    } else {
        // Create keyStaff if it doesn't exist
        data.operator.keyStaff = {
            ceo: '',
            founders: [],
            keyExecutives: []
        };
    }

        // Normalize operator fieldNotes entries
        if (data.operator.fieldNotes && typeof data.operator.fieldNotes === 'object') {
            normalizeFieldNotesEntries(data.operator.fieldNotes);
        }
}

    // Accept legacy facilities shapes (stringified, nested, or alternate keys)
    if (!Array.isArray(data.facilities)) {
        let facilitiesCandidate = [];

        if (typeof data.facilities === 'string') {
            try {
                const parsed = JSON.parse(data.facilities);
                if (Array.isArray(parsed)) facilitiesCandidate = parsed;
            } catch (err) {
                console.warn('normalizeProjectData: failed to parse string facilities', err);
            }
        } else if (data.facilities && typeof data.facilities === 'object' && Array.isArray(data.facilities.facilities)) {
            facilitiesCandidate = data.facilities.facilities;
        } else if (Array.isArray(data.facility)) {
            facilitiesCandidate = data.facility;
        } else if (typeof data.facility === 'string') {
            try {
                const parsed = JSON.parse(data.facility);
                if (Array.isArray(parsed)) facilitiesCandidate = parsed;
            } catch (err) {
                console.warn('normalizeProjectData: failed to parse string facility', err);
            }
        }

        data.facilities = Array.isArray(data.facilities) ? data.facilities : facilitiesCandidate;
    }

    // Normalize facilities array
    if (Array.isArray(data.facilities)) {
        data.facilities = data.facilities.map(facility => {
            const normalized = { ...facility };

            // Normalize identification
            if (normalized.identification) {
                normalized.identification = normalizeObject(normalized.identification, {
                    name: ['name', 'Name', 'facilityName', 'facility_name'],
                    currentName: ['currentName', 'current_name', 'CurrentName'],
                    currentOperator: ['currentOperator', 'current_operator', 'CurrentOperator'],
                    otherNames: ['otherNames', 'other_names', 'aliases'],
                    pastNames: ['pastNames', 'past_names', 'formerNames'],
                    knownReferrers: ['knownReferrers', 'known_referrers', 'referrers']
                });
            }

            // Normalize facilityDetails - ensure it exists and check for root-level type fields
            if (!normalized.facilityDetails) {
                normalized.facilityDetails = {};
            }
            // Check for type at root level and move to facilityDetails
            if (!normalized.facilityDetails.type) {
                const rootType = getValue(normalized, 'type', 'Type', 'facilityType', 'facility_type', 'programType', 'program_type');
                if (rootType) {
                    normalized.facilityDetails.type = rootType;
                }
            }
            normalized.facilityDetails = normalizeObject(normalized.facilityDetails, {
                type: ['type', 'Type', 'facilityType', 'facility_type', 'programType', 'program_type'],
                capacity: ['capacity', 'Capacity'],
                currentCensus: ['currentCensus', 'current_census', 'census'],
                gender: ['gender', 'Gender'],
                ageRange: ['ageRange', 'age_range', 'AgeRange']
            });

            // Normalize operatingPeriod
            if (normalized.operatingPeriod) {
                normalized.operatingPeriod = normalizeObject(normalized.operatingPeriod, {
                    startYear: ['startYear', 'start_year', 'opened'],
                    endYear: ['endYear', 'end_year', 'closed'],
                    status: ['status', 'Status'],
                    notes: ['notes', 'Notes']
                });
            }

            // Normalize staff
            if (normalized.staff) {
                normalized.staff = normalizeObject(normalized.staff, {
                    administrator: ['administrator', 'Administrator', 'administrators'],
                    notableStaff: ['notableStaff', 'notable_staff', 'NotableStaff', 'staff']
                });
            }

            // Normalize accreditations
            if (normalized.accreditations) {
                normalized.accreditations = normalizeObject(normalized.accreditations, {
                    current: ['current', 'Current', 'currentAccreditations'],
                    past: ['past', 'Past', 'pastAccreditations', 'former']
                });
            }

            // Normalize location fields
            normalized.location = getValue(normalized, 'location', 'Location');
            normalized.address = getValue(normalized, 'address', 'Address');

            // Ensure array fields are actually arrays
            const arrayFields = ['otherOperators', 'memberships', 'certifications', 'licensing', 'profileLinks', 'notes'];
            arrayFields.forEach(field => {
                if (normalized[field] && !Array.isArray(normalized[field])) {
                    if (typeof normalized[field] === 'string') {
                        normalized[field] = [normalized[field]];
                    } else {
                        normalized[field] = [];
                    }
                } else if (!normalized[field]) {
                    normalized[field] = [];
                }
            });

            // Ensure nested array fields are arrays
            if (normalized.identification) {
                ['otherNames', 'pastNames', 'knownReferrers'].forEach(field => {
                    if (normalized.identification[field] && !Array.isArray(normalized.identification[field])) {
                        if (typeof normalized.identification[field] === 'string') {
                            normalized.identification[field] = [normalized.identification[field]];
                        } else {
                            normalized.identification[field] = [];
                        }
                    } else if (!normalized.identification[field]) {
                        normalized.identification[field] = [];
                    }
                });
            }

            if (normalized.operatingPeriod && normalized.operatingPeriod.notes) {
                if (!Array.isArray(normalized.operatingPeriod.notes)) {
                    if (typeof normalized.operatingPeriod.notes === 'string') {
                        normalized.operatingPeriod.notes = [normalized.operatingPeriod.notes];
                    } else {
                        normalized.operatingPeriod.notes = [];
                    }
                }
            } else if (normalized.operatingPeriod) {
                normalized.operatingPeriod.notes = [];
            }

            if (normalized.staff) {
                ['administrator', 'notableStaff'].forEach(field => {
                    if (normalized.staff[field] && !Array.isArray(normalized.staff[field])) {
                        if (typeof normalized.staff[field] === 'string') {
                            normalized.staff[field] = [normalized.staff[field]];
                        } else {
                            normalized.staff[field] = [];
                        }
                    } else if (!normalized.staff[field]) {
                        normalized.staff[field] = [];
                    }
                });
            }

            if (normalized.accreditations) {
                ['current', 'past'].forEach(field => {
                    if (normalized.accreditations[field] && !Array.isArray(normalized.accreditations[field])) {
                        if (typeof normalized.accreditations[field] === 'string') {
                            normalized.accreditations[field] = [normalized.accreditations[field]];
                        } else {
                            normalized.accreditations[field] = [];
                        }
                    } else if (!normalized.accreditations[field]) {
                        normalized.accreditations[field] = [];
                    }
                });
            }

            if (normalized.resources && normalized.resources.notes) {
                if (!Array.isArray(normalized.resources.notes)) {
                    if (typeof normalized.resources.notes === 'string') {
                        normalized.resources.notes = [normalized.resources.notes];
                    } else {
                        normalized.resources.notes = [];
                    }
                }
            } else if (normalized.resources) {
                normalized.resources.notes = [];
            }

            // Normalize fieldNotes entries for each facility
            if (normalized.fieldNotes && typeof normalized.fieldNotes === 'object') {
                normalizeFieldNotesEntries(normalized.fieldNotes);
            }

            return normalized;
        });
    }

    // Normalize referrer agency/group data
    if (data.referrerAgency) {
        data.referrerAgency = normalizeObject(data.referrerAgency, {
            name: ['name', 'Name', 'organizationName', 'organization_name'],
            city: ['city', 'City'],
            state: ['state', 'State'],
            website: ['website', 'Website', 'url'],
            address: ['address', 'Address'],
            founded: ['founded', 'Founded'],
            notes: ['notes', 'Notes'],
            affiliations: ['affiliations', 'Affiliations']
        });

        // Ensure affiliations is an array
        if (data.referrerAgency.affiliations && !Array.isArray(data.referrerAgency.affiliations)) {
            if (typeof data.referrerAgency.affiliations === 'string') {
                data.referrerAgency.affiliations = [data.referrerAgency.affiliations];
            } else {
                data.referrerAgency.affiliations = [];
            }
        } else if (!data.referrerAgency.affiliations) {
            data.referrerAgency.affiliations = [];
        }
    }

    // Normalize referrer group (alternative field)
    if (data.referrerGroup) {
        data.referrerGroup = normalizeObject(data.referrerGroup, {
            name: ['name', 'Name', 'organizationName', 'organization_name'],
            city: ['city', 'City'],
            state: ['state', 'State'],
            website: ['website', 'Website', 'url'],
            address: ['address', 'Address'],
            founded: ['founded', 'Founded'],
            notes: ['notes', 'Notes'],
            affiliations: ['affiliations', 'Affiliations']
        });

        // Ensure affiliations is an array
        if (data.referrerGroup.affiliations && !Array.isArray(data.referrerGroup.affiliations)) {
            if (typeof data.referrerGroup.affiliations === 'string') {
                data.referrerGroup.affiliations = [data.referrerGroup.affiliations];
            } else {
                data.referrerGroup.affiliations = [];
            }
        } else if (!data.referrerGroup.affiliations) {
            data.referrerGroup.affiliations = [];
        }
    }

    // Normalize consultants array
    if (Array.isArray(data.referrerConsultants)) {
        data.referrerConsultants = data.referrerConsultants.map(consultant => {
            return normalizeObject(consultant, {
                name: ['name', 'Name', 'fullName', 'full_name'],
                title: ['title', 'Title'],
                city: ['city', 'City'],
                state: ['state', 'State'],
                website: ['website', 'Website', 'url'],
                email: ['email', 'Email'],
                phone: ['phone', 'Phone', 'phoneNumber'],
                notes: ['notes', 'Notes']
            });
        });
    }

    // Ensure default structures exist (merged from previous duplicate function)
    if (!data.operator) {
        data.operator = {
            name: "", currentName: "", otherNames: [], location: "", headquarters: "",
            founded: "", operatingPeriod: "", status: "", parentCompanies: [],
            websites: [], investors: [], keyStaff: { ceo: "", founders: [], keyExecutives: [] },
            notes: []
        };
    }

    if (!data.facilities || !Array.isArray(data.facilities)) {
        data.facilities = [];
    }

    if (!data.referrerAgency || typeof data.referrerAgency !== 'object') {
        data.referrerAgency = typeof createDefaultReferrerGroup === 'function' ? createDefaultReferrerGroup() : { name: "", affiliations: [], keyPersonnel: [], notes: "", fieldNotes: {} };
    } else {
        const defaults = typeof createDefaultReferrerGroup === 'function' ? createDefaultReferrerGroup() : {};
        data.referrerAgency = Object.assign(defaults, data.referrerAgency);
        if (!Array.isArray(data.referrerAgency.keyPersonnel)) {
            data.referrerAgency.keyPersonnel = [];
        }
        if (!data.referrerAgency.fieldNotes || typeof data.referrerAgency.fieldNotes !== 'object') {
            data.referrerAgency.fieldNotes = {};
        }
        normalizeFieldNotesEntries(data.referrerAgency.fieldNotes);
    }

    if (!Array.isArray(data.referrerConsultants) || data.referrerConsultants.length === 0) {
        const defaultIndividual = typeof createDefaultReferrerIndividual === 'function' ? createDefaultReferrerIndividual() : { firstName: "", lastName: "", affiliations: [], facilitiesReferred: [], fieldNotes: {} };
        data.referrerConsultants = [defaultIndividual];
    } else {
        data.referrerConsultants = data.referrerConsultants.map(consultant => {
            const defaults = typeof createDefaultReferrerIndividual === 'function' ? createDefaultReferrerIndividual() : {};
            const merged = Object.assign(defaults, consultant || {});
            if (!Array.isArray(merged.affiliations)) merged.affiliations = [];
            if (!Array.isArray(merged.facilitiesReferred)) merged.facilitiesReferred = [];
            if (!Array.isArray(merged.schoolDistricts)) merged.schoolDistricts = [];
            if (!merged.fieldNotes || typeof merged.fieldNotes !== 'object') merged.fieldNotes = {};
            normalizeFieldNotesEntries(merged.fieldNotes);
            return merged;
        });
    }

    if (typeof data.isIndependentConsultant === 'undefined') {
        data.isIndependentConsultant = false;
    }

    // Preserve or set referrerType based on existing data
    if (!data.referrerType) {
        data.referrerType = data.isIndependentConsultant ? 'individual' : 'group';
    }

    // Keep referrerGroup in sync with referrerAgency
    if (data.referrerAgency && !data.referrerGroup) {
        data.referrerGroup = data.referrerAgency;
    } else if (data.referrerGroup && !data.referrerAgency) {
        data.referrerAgency = data.referrerGroup;
    }

    // Keep referrerIndividual in sync with current consultant
    if (Array.isArray(data.referrerConsultants) && data.referrerConsultants.length > 0) {
        data.referrerIndividual = data.referrerConsultants[0];
    }

    if (!data.fieldNotes || typeof data.fieldNotes !== 'object') {
        data.fieldNotes = {};
    }
    normalizeFieldNotesEntries(data.fieldNotes);

    // Build referrer entries from the data
    if (typeof buildReferrerEntries === 'function') {
        data.referrer = buildReferrerEntries(data);
    }

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

function saveToLocalStorage(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
        console.warn('Failed to save to localStorage:', e);
    }
}

function addCustomValue(category, value) {
    // Delegate to autocomplete module if available
    if (window.addCustomValue && window.addCustomValue !== addCustomValue) {
        return window.addCustomValue(category, value);
    }
    // Fallback - just return false if module not loaded
    console.warn('[Facility Form] addCustomValue - autocomplete module not loaded');
    return false;
}

function attachCustomValueRecorder(input, category) {
    // Delegate to autocomplete module if available
    if (window.attachCustomValueRecorder && window.attachCustomValueRecorder !== attachCustomValueRecorder) {
        return window.attachCustomValueRecorder(input, category);
    }
    // Fallback - skip if module not loaded
    debugLog('[Facility Form] attachCustomValueRecorder - autocomplete module not loaded');
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
    console.warn('[Facility Form] Autocomplete module not loaded, skipping createAutocomplete');
};

const delegateInitializeAutocompleteFields = () => {
    const delegate = resolveInitializeAutocompleteFields();
    if (delegate) {
        return delegate();
    }
    // Autocomplete module not loaded - skip initialization
    console.warn('[Facility Form] Autocomplete module not loaded, skipping initializeAutocompleteFields');
};

function initializeSectionToggles() {
    const sections = document.querySelectorAll('.section');

    sections.forEach(section => {
        // Prevent re-initialization
        if (section.dataset.toggleInit === 'true') return;
        section.dataset.toggleInit = 'true';

        const header = section.querySelector('.section-header');
        const toggle = section.querySelector('.section-toggle');
        const content = section.querySelector('.section-content');

        if (!header || !toggle || !content) {
            return;
        }

        toggle.setAttribute('role', 'button');
        toggle.setAttribute('tabindex', '0');

        const setState = (expanded) => {
            section.classList.toggle('expanded', expanded);
            content.style.display = expanded ? 'block' : 'none';
            toggle.setAttribute('aria-expanded', expanded.toString());
            toggle.setAttribute('title', expanded ? 'Collapse section' : 'Expand section');
        };

        // Initialize with existing expanded state, but collapse on mobile
        const isMobile = window.innerWidth <= 768;
        const shouldExpand = isMobile ? false : section.classList.contains('expanded');
        setState(shouldExpand);

        const handleToggle = (event) => {
            event.preventDefault();
            event.stopPropagation();
            setState(!section.classList.contains('expanded'));
        };

        // Click handler for the toggle icon itself
        toggle.addEventListener('click', handleToggle, { passive: false });

        // Click handler for the header (excluding the toggle) - cannot be passive
        header.addEventListener('click', (event) => {
            if (event.target.closest('.section-toggle')) { return; }
            handleToggle(event);
        });
    });

    // Initialize mobile section controls
    initializeMobileSectionControls();
}

/**
 * Initialize mobile section controls for expand/collapse all
 */
function initializeMobileSectionControls() {
    // Only add controls if on mobile and not already added
    if (window.innerWidth > 768 || document.querySelector('.mobile-section-controls')) {
        return;
    }

    // Create the mobile controls bar
    const controlsBar = document.createElement('div');
    controlsBar.className = 'mobile-section-controls';
    controlsBar.innerHTML = `
        <span class="section-control-label">📋 Sections</span>
        <div class="section-control-btns">
            <button class="btn-section-control" id="expand-all-sections">Expand All</button>
            <button class="btn-section-control" id="collapse-all-sections">Collapse All</button>
        </div>
    `;

    // Insert at the beginning of the form wrapper or container
    const facilityWrapper = document.getElementById('facility-main-wrapper');
    const referrerWrapper = document.getElementById('referrer-main-wrapper');
    const categoryNav = document.getElementById('category-navigation');

    if (facilityWrapper && facilityWrapper.offsetParent !== null) {
        facilityWrapper.insertBefore(controlsBar.cloneNode(true), facilityWrapper.firstChild);
    }
    if (referrerWrapper) {
        referrerWrapper.insertBefore(controlsBar.cloneNode(true), referrerWrapper.firstChild);
    }

    // Attach event listeners using event delegation
    document.addEventListener('click', (e) => {
        if (e.target.id === 'expand-all-sections' || e.target.closest('#expand-all-sections')) {
            e.preventDefault();
            expandAllSections();
        }
        if (e.target.id === 'collapse-all-sections' || e.target.closest('#collapse-all-sections')) {
            e.preventDefault();
            collapseAllSections();
        }
    });

    console.log('[Mobile] Section controls initialized');
}

/**
 * Expand all collapsible sections
 */
function expandAllSections() {
    const sections = document.querySelectorAll('.section:not(.view-hidden)');
    sections.forEach(section => {
        const content = section.querySelector('.section-content');
        const toggle = section.querySelector('.section-toggle');
        if (content) {
            section.classList.add('expanded');
            content.style.display = 'block';
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'true');
                toggle.setAttribute('title', 'Collapse section');
            }
        }
    });
}

/**
 * Collapse all collapsible sections
 */
function collapseAllSections() {
    const sections = document.querySelectorAll('.section:not(.view-hidden)');
    sections.forEach(section => {
        const content = section.querySelector('.section-content');
        const toggle = section.querySelector('.section-toggle');
        if (content) {
            section.classList.remove('expanded');
            content.style.display = 'none';
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
                toggle.setAttribute('title', 'Expand section');
            }
        }
    });
}


// ============================================
// FIELD NOTE CONTROLS - Delegated to notes.js module
// These wrapper functions delegate to the module for backward compatibility.
// ============================================

function ensureFieldNotesStore(scope, createIfMissing = true) {
    if (window.NotesModule && typeof window.NotesModule.ensureFieldNotesStore === 'function') {
        return window.NotesModule.ensureFieldNotesStore(scope, createIfMissing);
    }
    // Minimal fallback
    if (!window.formData) return null;
    if (!window.formData.fieldNotes) window.formData.fieldNotes = {};
    return window.formData.fieldNotes;
}

function getFieldNotes(scope, key, createIfMissing = false) {
    if (window.NotesModule && typeof window.NotesModule.getFieldNotes === 'function') {
        return window.NotesModule.getFieldNotes(scope, key, createIfMissing);
    }
    return [];
}

function addFieldNote(scope, key) {
    if (window.NotesModule && typeof window.NotesModule.addFieldNote === 'function') {
        return window.NotesModule.addFieldNote(scope, key);
    }
}

function updateFieldNote(scope, key, index, value) {
    if (window.NotesModule && typeof window.NotesModule.updateFieldNote === 'function') {
        return window.NotesModule.updateFieldNote(scope, key, index, value);
    }
}

function removeFieldNote(scope, key, index) {
    if (window.NotesModule && typeof window.NotesModule.removeFieldNote === 'function') {
        return window.NotesModule.removeFieldNote(scope, key, index);
    }
}

function renderFieldNotes(container, scope, key) {
    if (window.NotesModule && typeof window.NotesModule.renderFieldNotes === 'function') {
        return window.NotesModule.renderFieldNotes(container, scope, key);
    }
}

function renderAllFieldNotes() {
    if (window.NotesModule && typeof window.NotesModule.renderAllFieldNotes === 'function') {
        return window.NotesModule.renderAllFieldNotes();
    }
}

function initializeNoteControls() {
    if (window.NotesModule && typeof window.NotesModule.initializeNoteControls === 'function') {
        return window.NotesModule.initializeNoteControls();
    }
}

// ============================================
// CLOUD STORAGE - PRIMARY
// ============================================
function normalizeProjectsPayload(payload) {
    if (!payload) return null;

    const normalized = {};

    const inferCategory = (projectName, explicitCategory) => {
        if (explicitCategory) return explicitCategory;
        const upper = (projectName || '').toUpperCase().trim();
        const isLocation = (
            (typeof window !== 'undefined' && window.US_STATE_SET && window.US_STATE_SET.has(upper.toLowerCase())) ||
            (typeof window !== 'undefined' && window.COUNTRY_SET && window.COUNTRY_SET.has(upper.toLowerCase()))
        );
        if (isLocation) return 'locations';
        return 'companies';
    };

    const assignProject = (projectName, projectPayload) => {
        if (!projectName) return;
        const source = projectPayload && typeof projectPayload === 'object' ? projectPayload : {};

        // Handle cases where data is stringified JSON
        let rawData = source.data ? source.data : source;
        if (typeof rawData === 'string') {
            try {
                rawData = JSON.parse(rawData);
            } catch (parseErr) {
                console.warn('Failed to parse project data string for', projectName, parseErr);
                rawData = {};
            }
        }

        const name = source.name || projectName;
        normalized[projectName] = {
            name,
            data: normalizeProjectData(rawData),
            timestamp: source.timestamp || rawData?.timestamp || new Date().toISOString(),
            currentFacilityIndex: source.currentFacilityIndex ?? rawData?.currentFacilityIndex ?? 0,
            category: inferCategory(name, source.category || rawData?.category)
        };
    };

    if (payload.projects && typeof payload.projects === 'object') {
        Object.entries(payload.projects).forEach(([key, value]) => {
            assignProject(value?.name || key, value);
        });
    } else if (Array.isArray(payload)) {
        payload.forEach(item => {
            if (!item) return;
            const projectName = item.name || item.unique_name || item.projectName;
            assignProject(projectName || `project-${Math.random().toString(36).slice(2)}`, item);
        });
    } else if (typeof payload === 'object') {
        Object.entries(payload).forEach(([key, value]) => {
            assignProject(value?.name || key, value);
        });
    } else {
        return null;
    }

    return Object.keys(normalized).length > 0 ? normalized : null;
}

async function loadProjectsFromFallbackDatasets() {
    if (!FALLBACK_PROJECTS_URL_CANDIDATES.length) {
        return null;
    }

    const totalCandidates = FALLBACK_PROJECTS_URL_CANDIDATES.length;

    for (let index = 0; index < totalCandidates; index += 1) {
        const fallbackUrl = FALLBACK_PROJECTS_URL_CANDIDATES[index];
        const attemptLabel = `Attempting to load fallback dataset (${index + 1}/${totalCandidates})...`;

        try {
            showUploadStatus(attemptLabel, 'info');

            const response = await fetch(fallbackUrl);

            if (!response.ok) {
                console.warn(`Fallback dataset request for ${fallbackUrl} failed with status ${response.status}`);
                continue;
            }

            const fallbackText = await response.text();
            let fallbackData;

            try {
                fallbackData = JSON.parse(fallbackText);
            } catch (parseError) {
                console.warn(`Fallback dataset at ${fallbackUrl} returned invalid JSON:`, parseError);
                continue;
            }

            const normalizedProjects = normalizeProjectsPayload(fallbackData);

            if (normalizedProjects && Object.keys(normalizedProjects).length > 0) {
                return {
                    url: fallbackUrl,
                    projects: normalizedProjects
                };
            }

            console.warn('Fallback dataset did not contain usable project data at', fallbackUrl);
        } catch (fallbackError) {
            console.warn('Fallback dataset load failed for', fallbackUrl, fallbackError);
        }
    }

    return null;
}

async function loadAllProjectsFromCloud() {
    try {
        showUploadStatus('Loading projects from cloud...', 'info');

        const response = await fetch(API_ENDPOINTS.LOAD_PROJECTS);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const result = await response.json();

        if (result.success && result.projects) {
            // Debug: Log raw data structure from a few sample projects
            const sampleProjects = Object.entries(result.projects).slice(0, 3);
            console.log('🔬 SAMPLE RAW PROJECT STRUCTURES:');
            sampleProjects.forEach(([name, proj]) => {
                console.log(`  "${name}":`, {
                    hasData: !!proj.data,
                    dataKeys: proj.data ? Object.keys(proj.data) : 'NO DATA',
                    hasFacilitiesInData: !!proj.data?.facilities,
                    facilitiesInDataCount: proj.data?.facilities?.length,
                    hasFacilitiesAtRoot: !!proj.facilities,
                    facilitiesAtRootCount: proj.facilities?.length,
                    category: proj.category,
                    fullStructure: JSON.stringify(proj).substring(0, 500)
                });
            });
            
            // Debug: Log raw location projects from server
            const rawLocationProjects = Object.entries(result.projects).filter(([name, proj]) => {
                const normalized = name.toLowerCase();
                return window.US_STATE_SET.has(normalized) || window.COUNTRY_SET.has(normalized) || proj.category === 'locations';
            });
            console.log('🗺️ Raw location projects from server:', rawLocationProjects.length, rawLocationProjects.map(([name, proj]) => ({
                name,
                category: proj.category,
                facilitiesInData: proj.data?.facilities?.length || 0,
                facilitiesAtRoot: proj.facilities?.length || 0
            })));

            // Deduplicate location projects: prefer uppercase versions with data
            console.log('🔍 About to deduplicate and sync location projects');
            console.log('window.deduplicateLocationProjects available?', typeof window.deduplicateLocationProjects !== 'undefined');
            console.log('window.syncLocationProjectsFromSources available?', typeof window.syncLocationProjectsFromSources !== 'undefined');

            const deduplicatedProjects = typeof window.deduplicateLocationProjects === 'function'
                ? window.deduplicateLocationProjects(result.projects)
                : result.projects;

            // Sync location projects with facilities from company/referrer projects
            if (typeof window.syncLocationProjectsFromSources === 'function') {
                console.log('✅ Calling syncLocationProjectsFromSources...');
                window.syncLocationProjectsFromSources(deduplicatedProjects);
                console.log('✅ Sync complete. Total projects after sync:', Object.keys(deduplicatedProjects).length);
            } else {
                console.error('❌ syncLocationProjectsFromSources function not found! Location projects will NOT auto-populate.');
                console.error('This means location-form.js did not load properly or did not expose the function.');
            }

            window.projects = deduplicatedProjects;
            projects = window.projects;

            invalidateAggregatedData();

            // Backup to localStorage
            saveToLocalStorage('cloudProjects', projects);

            showUploadStatus(`Loaded ${Object.keys(projects).length} projects from cloud`, 'success');
            debugLog('Loaded projects from cloud:', Object.keys(projects));

            // Force re-initialize autocomplete after cloud data loads
            setTimeout(() => {
                debugLog('Re-initializing autocomplete with cloud data...');
                // Clear autocomplete init flags to allow re-initialization
                document.querySelectorAll('input[data-autocomplete-category]').forEach(field => {
                    delete field.dataset.autocompleteInit;
                });
                initializeAutocompleteFields();

                // Refresh saved project panels now that projects are available
                debugLog('Refreshing saved project panels...');
                refreshSavedProjectPanels();
            }, 500);

            return projects;
        } else {
            throw new Error(result.error || 'Failed to load projects');
        }
    } catch (error) {
        console.error('Cloud load error:', error);
        showUploadStatus('Failed to load from cloud. Checking backups...', 'error');

        // Fallback to localStorage
        try {
            const backup = JSON.parse(localStorage.getItem('cloudProjects') || '{}');
            if (Object.keys(backup).length > 0) {
                window.projects = backup;
                projects = window.projects;
                invalidateAggregatedData();
                showUploadStatus('Loaded from localStorage backup', 'info');
                return projects;
            }
        } catch (e) {
            console.error('localStorage backup failed:', e);
        }

        const fallbackLoadResult = await loadProjectsFromFallbackDatasets();

        if (fallbackLoadResult && fallbackLoadResult.projects) {
            projects = fallbackLoadResult.projects;
            window.projects = projects;
            invalidateAggregatedData();
            saveToLocalStorage('cloudProjects', projects);
            showUploadStatus(`Loaded ${Object.keys(projects).length} projects from fallback dataset`, 'success');
            debugLog('Loaded projects from fallback dataset:', fallbackLoadResult.url);
            return projects;
        }

        showUploadStatus('No projects found - starting fresh', 'info');
        invalidateAggregatedData();
        return {};
    }
}

function persistProjectLocally(projectName, { showStatus = false, statusType = 'info', statusMessage = '' } = {}) {
    if (!projectName || !window.formData) {
        return false;
    }

    if (!window.projects || typeof window.projects !== 'object') {
        window.projects = {};
    }

    ensureReferrerDataStructures();

    // Determine category based on active tab
    const activeTab = document.querySelector('.category-tab.active');
    const category = activeTab ? activeTab.dataset.category : 'companies';

    const snapshot = {
        name: projectName,
        data: deepClone(window.formData),
        currentFacilityIndex: window.currentFacilityIndex,
        timestamp: new Date().toISOString(),
        category: category  // Store the category based on active tab
    };

    window.projects[projectName] = snapshot;
    projects = window.projects;

    try {
        saveToLocalStorage('cloudProjects', window.projects);
    } catch (storageError) {
        console.warn('Local persistence failed:', storageError);
    }

    invalidateAggregatedData();

    if (showStatus && statusMessage) {
        showUploadStatus(statusMessage, statusType);
    }

    return true;
}

async function saveProjectToCloud(projectName, action = 'save') {
    // Handle delete action separately
    if (action === 'delete') {
        try {
            showUploadStatus(`🗑️ Deleting "${projectName}"...`, 'info');
            debugLog('=== DELETE PROJECT START ===');
            debugLog('Project name:', projectName);

            const payload = {
                projectName: projectName,
                action: 'delete'
            };

            const API_ENDPOINTS = getAPIEndpoints();
            const response = await fetch(API_ENDPOINTS.SAVE_PROJECT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP ${response.status}: ${errorText.substring(0, 100)}`);
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.error || result.message || 'Unknown server error');
            }

            // Remove from local projects object
            delete window.projects[projectName];
            localStorage.removeItem(`project_${projectName}`);

            // If this was the current project, clear it
            if (window.currentProjectName === projectName) {
                newProject();
            }

            debugLog('✅ Delete successful!');
            debugLog('=== DELETE PROJECT END ===');
            showUploadStatus(`✅ Deleted "${projectName}" successfully!`, 'success');

            // Update UI
            if (typeof window.updateAllUI === 'function') {
                window.updateAllUI();
            }

            // Refresh the project panels to remove the deleted project from the list
            if (typeof window.refreshSavedProjectPanels === 'function') {
                window.refreshSavedProjectPanels();
            }

            return true;
        } catch (error) {
            console.error('❌ DELETE FAILED:', error.message);
            showUploadStatus(`❌ Failed to delete: ${error.message}`, 'error');
            return false;
        }
    }

    // Normal save action
    if (!projectName || !window.formData) {
        showUploadStatus('❌ No project name or data to save', 'error');
        console.error('❌ Save blocked: projectName=', projectName, 'formData exists=', !!window.formData);
        return false;
    }

    if (isSuggestionMode()) {
        window.currentProjectName = projectName;

        const saved = persistProjectLocally(projectName, {
            showStatus: true,
            statusType: 'info',
            statusMessage: '💾 Draft saved locally. Use "Submit Suggestion for Review" to send updates to Kids Over Profits.'
        });

        if (!saved) {
            showUploadStatus('❌ Unable to save draft locally. Please try again.', 'error');
        } else {
            debugLog('Suggestion mode active — skipping remote save for project "%s".', projectName);
        }

        return false;
    }

    try {
        showUploadStatus(`💾 Saving "${projectName}" to cloud...`, 'info');
        debugLog('=== SAVE PROJECT START ===');
        debugLog('Project name:', projectName);
        debugLog('Facility count:', window.formData.facilities?.length || 0);
        debugLog('Data size:', JSON.stringify(window.formData).length, 'characters');

        ensureReferrerDataStructures();

        // Determine category: state/country names MUST be locations, regardless of stored metadata
        // This prevents state projects from being miscategorized if saved from wrong tab
        const normalizedName = projectName.toLowerCase().trim();
        let category;
        if (window.US_STATE_SET?.has(normalizedName) || window.COUNTRY_SET?.has(normalizedName)) {
            category = 'locations';
            debugLog('Category forced to "locations" for state/country name:', projectName);
        } else {
            // For non-location projects, check stored metadata first, then fall back to active tab
            category = window.projects?.[projectName]?.category;
            if (!category) {
                const activeTab = document.querySelector('.category-tab.active');
                category = activeTab ? activeTab.dataset.category : 'companies';
            }
        }

        const projectData = {
            name: projectName,
            data: deepClone(window.formData),
            currentFacilityIndex: window.currentFacilityIndex,
            timestamp: new Date().toISOString(),
            category: category  // Store the category based on active tab
        };

        const payload = {
            projectName: projectName,
            data: projectData.data,
            category: projectData.category,
            currentFacilityIndex: projectData.currentFacilityIndex,
            timestamp: projectData.timestamp,
            action: action
        };

        const payloadSize = JSON.stringify(payload).length;
        const API_ENDPOINTS = getAPIEndpoints();
        debugLog('Payload size:', payloadSize, 'characters');
        debugLog('Sending to:', API_ENDPOINTS.SAVE_PROJECT);

        let response;
        let usedRestApi = false;

        try {
            response = await fetch(API_ENDPOINTS.SAVE_PROJECT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            debugLog('Response status:', response.status, response.statusText);
            debugLog('Response headers:', Object.fromEntries(response.headers.entries()));

            // Check if we got PHP source code instead of JSON (server misconfiguration)
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const responseText = await response.text();
                if (responseText.includes('<?php')) {
                    console.warn('⚠️ Direct PHP endpoint not working, trying REST API fallback...');
                    throw new Error('PHP_NOT_EXECUTING');
                }
                console.error('❌ Expected JSON but got:', contentType);
                console.error('Response preview:', responseText.substring(0, 500));
                throw new Error(`Expected JSON response, got ${contentType}`);
            }
        } catch (directError) {
            // If direct PHP failed, try REST API fallback
            if (directError.message === 'PHP_NOT_EXECUTING' || directError.message.includes('Failed to fetch')) {
                debugLog('🔄 Trying WordPress REST API fallback...');
                const restEndpoint = API_ENDPOINTS.REST_SAVE_PROJECT || '/wp-json/kop/v1/projects/save';
                const restNonce = API_ENDPOINTS.REST_NONCE || window.wpApiSettings?.nonce || '';
                
                response = await fetch(restEndpoint, {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': restNonce
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload)
                });
                usedRestApi = true;
                debugLog('REST API response status:', response.status);
            } else {
                throw directError;
            }
        }
        if (!response.ok) {
            const errorText = await response.text();
            console.error('❌ Save failed response body:', errorText.substring(0, 500));
            throw new Error(`HTTP ${response.status}: ${errorText.substring(0, 100)}`);
        }

        const result = await response.json();
        debugLog('Save result:', result);

        if (!result.success) {
            throw new Error(result.error || result.message || 'Unknown server error');
        }
        
        // Update local projects object
        window.projects[projectName] = projectData;
        projects = window.projects;
        invalidateAggregatedData();
        window.currentProjectName = projectName;

        // Backup to localStorage
        persistProjectLocally(projectName);

        debugLog('✅ Save successful!' + (usedRestApi ? ' (via REST API)' : ''));
        debugLog('=== SAVE PROJECT END ===');
        showUploadStatus(`✅ Saved "${projectName}" successfully!`, 'success');

        // Update UI
        if (typeof window.updateAllUI === 'function') {
            window.updateAllUI();
        }

        return true;
    } catch (error) {
        console.error('❌ SAVE FAILED:', error.message);
        console.error('Error stack:', error.stack);
        showUploadStatus(`❌ Failed to save: ${error.message}`, 'error');

        // Still save to localStorage as backup
        const fallbackSaved = persistProjectLocally(projectName, {
            showStatus: true,
            statusType: 'info',
            statusMessage: '⚠️ Saved to local storage only (cloud save failed).'
        });

        if (fallbackSaved) {
            debugLog('✅ Saved to localStorage backup');
        } else {
            console.error('❌ localStorage backup also failed.');
            showUploadStatus('❌ Save completely failed - check console for details', 'error');
        }

        return false;
    }
}

function autoSave() {
    // Skip autoSave if we're in the middle of a programmatic UI update
    if (isUpdatingUI) {
        debugLog('⏸️ autoSave skipped - UI update in progress');
        return;
    }

    if (isSuggestionMode()) {
        clearTimeout(window.autoSaveTimer);
        window.autoSaveTimer = setTimeout(() => {
            if (!window.currentProjectName) {
                return;
            }

            const saved = persistProjectLocally(window.currentProjectName);
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
                await saveProjectToCloud(window.currentProjectName);
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

function createDefaultReferrerGroup() {
    if (window.createDefaultReferrerGroup && window.createDefaultReferrerGroup !== createDefaultReferrerGroup) {
        return window.createDefaultReferrerGroup();
    }
    // Fallback implementation
    return {
        name: "", city: "", state: "", website: "", address: "", founded: "",
        affiliations: [], keyPersonnel: [], notes: "", fieldNotes: {}
    };
}

function createDefaultReferrerIndividual() {
    if (window.createDefaultReferrerIndividual && window.createDefaultReferrerIndividual !== createDefaultReferrerIndividual) {
        return window.createDefaultReferrerIndividual();
    }
    // Fallback implementation
    return {
        firstName: "", lastName: "", fullName: "", role: "", status: "",
        education: "", credentials: "", city: "", state: "", email: "", phone: "",
        website: "", affiliations: [], facilitiesReferred: [], knownReferrals: [],
        pastTTIJobs: [], schoolDistricts: [], lawsuits: "", notes: "", fieldNotes: {}
    };
}

function buildReferrerEntries(formDataArg) {
    if (window.buildReferrerEntries && window.buildReferrerEntries !== buildReferrerEntries) {
        return window.buildReferrerEntries(formDataArg);
    }
    // Fallback - return empty array
    return [];
}

function ensureReferrerDataStructures() {
    if (window.ensureReferrerDataStructures && window.ensureReferrerDataStructures !== ensureReferrerDataStructures) {
        return window.ensureReferrerDataStructures();
    }
    // Minimal fallback implementation
    if (!window.formData) return;
    if (!window.formData.referrerAgency) {
        window.formData.referrerAgency = createDefaultReferrerGroup();
    }
    window.formData.referrerGroup = window.formData.referrerAgency;
    if (!Array.isArray(window.formData.referrerConsultants)) {
        window.formData.referrerConsultants = [createDefaultReferrerIndividual()];
    }
    window.formData.referrerIndividual = window.formData.referrerConsultants[0];
}

function resolvePathTarget(path) {
    let scope = 'facility';
    let normalizedPath = path;
    let target = null;

    if (!window.formData) {
        return { scope, normalizedPath, target };
    }

    if (path.startsWith('operator.')) {
        // Check if we're in a location project - if so, use facility's sourceOperator
        const activeTab = document.querySelector('.category-tab.active');
        const isLocationProject = activeTab && activeTab.dataset.category === 'locations';
        
        if (isLocationProject && window.formData.facilities && window.formData.facilities[window.currentFacilityIndex]) {
            const currentFacility = window.formData.facilities[window.currentFacilityIndex];
            if (!currentFacility.sourceOperator) {
                currentFacility.sourceOperator = {};
            }
            scope = 'operator';
            normalizedPath = path.replace('operator.', '');
            target = currentFacility.sourceOperator;
            return { scope, normalizedPath, target };
        }
        
        // For non-location projects, use project-level operator
        if (!window.formData.operator) {
            window.formData.operator = createNewProjectData().operator;
        }
        scope = 'operator';
        normalizedPath = path.replace('operator.', '');
        target = window.formData.operator;
        return { scope, normalizedPath, target };
    }

    if (path.startsWith('referrerGroup.')) {
        ensureReferrerDataStructures();
        scope = 'referrerGroup';
        normalizedPath = path.replace('referrerGroup.', '');
        target = window.formData.referrerGroup;
        return { scope, normalizedPath, target };
    }

    if (path.startsWith('referrerIndividual.')) {
        ensureReferrerDataStructures();
        scope = 'referrerIndividual';
        normalizedPath = path.replace('referrerIndividual.', '');
        target = window.formData.referrerIndividual;
        return { scope, normalizedPath, target };
    }

    if (path.startsWith('consultant.')) {
        ensureReferrerDataStructures();
        scope = 'consultant';
        normalizedPath = path.replace('consultant.', '');
        target = window.formData.referrerIndividual;
        return { scope, normalizedPath, target };
    }

    if (path.startsWith('referrerAgency.')) {
        ensureReferrerDataStructures();
        scope = 'referrerAgency';
        normalizedPath = path.replace('referrerAgency.', '');
        target = window.formData.referrerAgency;
        return { scope, normalizedPath, target };
    }

    scope = 'facility';
    normalizedPath = path;
    if (!Array.isArray(window.formData.facilities) || !window.formData.facilities.length) {
        window.formData.facilities = createNewProjectData().facilities;
        window.currentFacilityIndex = 0;
    }

    if (!window.formData.facilities[window.currentFacilityIndex]) {
        window.formData.facilities[window.currentFacilityIndex] = deepClone(createNewProjectData().facilities[0]);
    }

    target = window.formData.facilities[window.currentFacilityIndex];
    return { scope, normalizedPath, target };
}

// ============================================
// PROJECT MANAGEMENT
// ============================================
function createNewProjectData() {
    const project = {
        operator: {
            name: "", currentName: "", otherNames: [],
            location: "", locationCity: "", locationState: "",
            headquarters: "", headquartersCity: "", headquartersState: "",
            founded: "", operatingPeriod: "", status: "", parentCompanies: [],
            websites: [], investors: [], keyStaff: { ceo: "", founders: [], keyExecutives: [] },
            notes: [], fieldNotes: {}
        },
        facilities: [{
            identification: { name: "", currentName: "", currentOperator: "", otherNames: [], knownReferrers: [] },
            location: "", address: "", otherOperators: [],
            operatingPeriod: { startYear: null, endYear: null, status: "", yearsOfOperation: "", notes: [] },
            staff: { administrator: [], notableStaff: [] },
            profileLinks: [],
            facilityDetails: { type: "", capacity: null, currentCensus: null, ageRange: { min: null, max: null }, gender: "" },
            accreditations: { current: [], past: [] },
            memberships: [], certifications: [], licensing: [],
            resources: {
                hasNews: false, newsDetails: "", hasPressReleases: false, pressReleasesDetails: "",
                hasInspections: false, hasStateReports: false, hasRegulatoryFilings: false,
                hasLawsuits: false, hasPoliceReports: false, hasArticlesOfOrganization: false,
                hasPropertyRecords: false, hasPromotionalMaterials: false, hasEnrollmentDocuments: false,
                hasResearch: false, hasFinancial: false, hasStudent: false, hasStaff: false,
                hasParent: false, hasWebsite: false, hasNATSAP: false, hasSurvivorStories: false,
                hasOther: false, notes: []
            },
            treatmentTypes: {}, philosophy: {}, criticalIncidents: {}, notes: [], fieldNotes: {}
        }],
        // Referrer data structure
        referrer: [],
        referrerAgency: createDefaultReferrerGroup(),
        referrerConsultants: [createDefaultReferrerIndividual()],
        isIndependentConsultant: false,
        fieldNotes: {}
    };

    project.referrer = buildReferrerEntries(project);

    return project;
}

function loadProject(projectName) { // Note: This function is now asynchronous
    debugLog('🔄 loadProject called with:', projectName);
    debugLog('📦 Available projects:', Object.keys(window.projects || {}));
    
    // Show immediate loading feedback
    showUploadStatus(`Loading project "${projectName}"...`, 'info');

    // Try exact match first, then uppercase (for location projects stored as uppercase)
    let resolvedName = projectName;
    if (!window.projects[projectName]) {
        const upperName = projectName.toUpperCase();
        if (window.projects[upperName]) {
            resolvedName = upperName;
            debugLog('📍 Resolved project name to uppercase:', resolvedName);
        }
    }
    
    if (!window.projects[resolvedName]) {
        console.error('❌ Project not found:', projectName, '(also tried:', projectName.toUpperCase(), ')');
        showUploadStatus(`Project "${projectName}" not found.`, 'error');
        return Promise.reject(new Error(`Project not found: ${projectName}`));
    }
    
    // Use resolved name for the rest of the function
    projectName = resolvedName;

    // Determine the project category and switch to the correct tab
    const projectCategory = determineProjectCategory(projectName);
    debugLog('📂 Project category:', projectCategory);

    // Switch to the correct category tab
    const targetTab = document.querySelector(`.category-tab[data-category="${projectCategory}"]`);
    if (targetTab) {
        // Remove active class from all tabs
        document.querySelectorAll('.category-tab').forEach(t => t.classList.remove('active'));
        // Add active class to the target tab
        targetTab.classList.add('active');
        debugLog('✅ Switched to', projectCategory, 'tab');
    }

    // Also switch category content panels (this was missing before)
    document.querySelectorAll('.category-content').forEach(content => {
        content.classList.add('view-hidden', 'd-none');
    });
    const contentId = projectCategory === 'locations' ? 'states-content' : `${projectCategory}-content`;
    const activeContent = document.getElementById(contentId);
    if (activeContent) {
        activeContent.classList.remove('view-hidden', 'd-none');
    }
    
    return new Promise((resolve) => {
        setTimeout(() => {
            const projectCategory = determineProjectCategory(projectName); // Define category inside the timeout scope
            window.currentProjectName = projectName;
            
            debugLog('📥 Loading project data structure:', {
                'projectName': projectName,
                'projectObject': window.projects[projectName],
                'projectData': window.projects[projectName].data
            });
            
            if (window.projects[projectName].data && Object.keys(window.projects[projectName].data).length > 0) {
                window.formData = normalizeProjectData(deepClone(window.projects[projectName].data));
            } else {
                window.formData = createNewProjectData();
            }
            ensureReferrerDataStructures();
            window.currentFacilityIndex = window.projects[projectName].currentFacilityIndex || 0;

            if (!window.formData.facilities || window.currentFacilityIndex >= window.formData.facilities.length) {
                window.currentFacilityIndex = 0;
            }

            // Reset consultant index for referrer projects to avoid stale state
            if (projectCategory === 'referrers') {
                window.currentConsultantIndex = 0;
            }

            const projectNameInput = document.getElementById('project-name');
            if (projectNameInput) {
                projectNameInput.value = projectName;
            }

            // Also populate the referrer project name input if it exists and category matches
            const referrerProjectNameInput = document.getElementById('referrer-project-name');
            if (referrerProjectNameInput) {
                if (projectCategory === 'referrers') {
                    referrerProjectNameInput.value = projectName;
                }
            }

            // Ensure the correct form wrapper is visible BEFORE updating UI
            if (typeof handleReferrerToggle === 'function') {
                handleReferrerToggle();
                debugLog('✅ Updated form visibility for', projectCategory);
            }

            if (typeof window.updateAllUI === 'function') {
                debugLog('🔄 Calling updateAllUI...');
                window.updateAllUI();
                updateLabelsForProjectType(projectName);
            } else {
                console.error('❌ updateAllUI not available!');
            }

            // Explicitly update consultant UI for referrer projects
            if (projectCategory === 'referrers' && typeof window.updateConsultantsUI === 'function') {
                debugLog('🔄 Updating consultants UI for referrer project...');
                window.updateConsultantsUI();
            }

            // Explicitly update location facilities overview for location projects
            if (projectCategory === 'locations' && typeof window.updateLocationFacilitiesOverview === 'function') {
                debugLog('🔄 Updating location facilities overview for location project...');
                window.updateLocationFacilitiesOverview();
            }

            // Dispatch custom event for project loaded
            document.dispatchEvent(new CustomEvent('projectLoaded', {
                detail: { projectName: projectName }
            }));

            showUploadStatus(`Project "${projectName}" loaded (${window.formData.facilities.length} facilities)`, 'success');
            
            // Scroll to form input area after loading project
            scrollToFormInput();
            
            resolve();
        }, 100); // A small delay to ensure DOM updates can happen
    });
}

function newProject() {
    if (!confirm('Start a new blank project? Any unsaved changes to the current project will be lost.')) return;

    window.currentProjectName = null;
    window.formData = createNewProjectData();
    window.currentFacilityIndex = 0;

    // Clear the main project name input
    const projectNameInput = document.getElementById('project-name');
    if (projectNameInput) {
        projectNameInput.value = '';
    }

    // Get the currently active category tab
    const activeTab = document.querySelector('.category-tab.active');
    const activeCategory = activeTab ? activeTab.dataset.category : 'companies';

    // Update all UI elements to reflect the new, blank data
    if (typeof window.updateAllUI === 'function') {
        window.updateAllUI();
    }

    // Apply the correct labels based on the active tab.
    updateLabelsForProjectType();

    // After labels are set, ensure the referrer toggle state is correct
    handleReferrerToggle(); // Apply visibility rules based on the active tab

    showUploadStatus('New project created', 'info');
}

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

function initializeCategoryTabs() {
    const categoryTabsContainer = document.querySelector('.category-tabs');
    if (!categoryTabsContainer || categoryTabsContainer.dataset.tabsInitialized === 'true') {
        return;
    }

    const handleCategoryTabClick = (event) => {
        const tab = event.target.closest('.category-tab');
        if (!tab) return;

        const newCategory = tab.dataset.category;
        const currentTab = document.querySelector('.category-tab.active');
        const currentCategory = currentTab ? currentTab.dataset.category : null;
        
        // If clicking the same tab, do nothing
        if (newCategory === currentCategory) return;
        
        // Check if there's a loaded project from a different category
        const currentProjectCategory = window.currentProjectName ? 
            (window.projects?.[window.currentProjectName]?.category || determineProjectCategory(window.currentProjectName)) : null;
        
        // If there's a project loaded AND it's from a different category than where we're going
        if (window.currentProjectName && currentProjectCategory && currentProjectCategory !== newCategory) {
            // Check if there are unsaved changes (formData exists and has meaningful content)
            const hasUnsavedWork = window.formData && (
                (window.formData.facilities && window.formData.facilities.length > 0 && 
                 window.formData.facilities.some(f => f.identification?.name)) ||
                (window.formData.operator && window.formData.operator.name) ||
                (window.formData.referrerConsultants && window.formData.referrerConsultants.length > 0)
            );
            
            if (hasUnsavedWork) {
                const choice = confirm(
                    `You have "${window.currentProjectName}" (${currentProjectCategory}) loaded.\n\n` +
                    `Switching to ${newCategory} will clear this from the form.\n\n` +
                    `Click OK to continue (your data is auto-saved locally).\n` +
                    `Click Cancel to stay on the current tab.`
                );
                
                if (!choice) {
                    return; // User cancelled, don't switch tabs
                }
                
                // Auto-save the current project locally before switching
                if (typeof persistProjectLocally === 'function') {
                    persistProjectLocally(window.currentProjectName);
                    debugLog(`Auto-saved "${window.currentProjectName}" locally before tab switch`);
                }
            }
            
            // Clear the current project when switching categories
            window.currentProjectName = null;
            window.formData = createNewProjectData();
            window.currentFacilityIndex = 0;
            
            const projectNameInput = document.getElementById('project-name');
            if (projectNameInput) {
                projectNameInput.value = '';
            }
        }

        // Update active tab
        categoryTabsContainer.querySelectorAll('.category-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');

        // Hide all main content wrappers first
        document.querySelectorAll('.category-content').forEach(content => {
            content.classList.add('view-hidden', 'd-none');
        });

        // Determine the ID of the content to show
        // Handles the special case where 'locations' category maps to 'states-content' ID
        const contentId = newCategory === 'locations' ? 'states-content' : `${newCategory}-content`;
        const activeContent = document.getElementById(contentId);

        if (activeContent) {
            activeContent.classList.remove('view-hidden', 'd-none');
        }

        // Handle main form visibility (facility vs. referrer form)
        if (typeof handleReferrerToggle === 'function') {
            handleReferrerToggle();
        }

        // Apply view layout to show/hide elements based on data-section-views
        if (typeof window.applyViewLayout === 'function') {
            window.applyViewLayout(newCategory === 'locations' ? 'locations' : newCategory);
        }

        // Refresh the list of saved projects for the new tab
        if (typeof refreshSavedProjectPanels === 'function') {
            refreshSavedProjectPanels();
        }

        // Update labels if the function exists
        if (typeof updateLabelsForProjectType === 'function') {
            updateLabelsForProjectType();
        }
        
        // Update UI to reflect cleared form if we switched
        if (typeof window.updateAllUI === 'function') {
            window.updateAllUI();
        }
    };

    categoryTabsContainer.addEventListener('click', handleCategoryTabClick);
    categoryTabsContainer.dataset.tabsInitialized = 'true';
    debugLog('✅ Category tab switching logic initialized.');
}
window.initializeCategoryTabs = initializeCategoryTabs;

async function renameProject(oldName) {
    if (!oldName) {
        showUploadStatus('❌ No project selected to rename.', 'error');
        return;
    }

    const newName = prompt(`Enter the new name for project "${oldName}":`, oldName);

    if (!newName || newName.trim() === '' || newName.trim() === oldName) {
        showUploadStatus('ℹ️ Rename cancelled or name not changed.', 'info');
        return;
    }

    if (window.projects && window.projects[newName.trim()]) {
        showUploadStatus(`❌ A project named "${newName.trim()}" already exists.`, 'error');
        return;
    }

    try {
        showUploadStatus(`Renaming "${oldName}" to "${newName}"...`, 'info');
        const response = await fetch(API_ENDPOINTS.SAVE_PROJECT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'rename',
                projectName: oldName,
                newProjectName: newName.trim()
            })
        });

        const result = await response.json();

        if (result.success) {
            // Update local projects object
            window.projects[newName.trim()] = window.projects[oldName];
            delete window.projects[oldName];

            // If the renamed project is the current one, update the state
            if (window.currentProjectName === oldName) {
                window.currentProjectName = newName.trim();
                window.formData.projectName = newName.trim();
                document.getElementById('project-name').value = newName.trim();
            }

            showUploadStatus(`✅ Project renamed to "${newName.trim()}"`, 'success');
            updateAllUI();
        } else {
            throw new Error(result.error || 'Failed to rename project.');
        }
    } catch (error) {
        showUploadStatus(`❌ Rename failed: ${error.message}`, 'error');
        console.error('Rename failed:', error);
    }
}

async function deleteProject(projectName) {
    if (!projectName) {
        showUploadStatus('❌ No project selected to delete.', 'error');
        return;
    }

    if (!confirm(`Are you sure you want to permanently delete the project "${projectName}"? This cannot be undone.`)) {
        return;
    }

    // Use the existing saveProjectToCloud logic but with a delete action
    await saveProjectToCloud(projectName, 'delete');
}

async function recategorizeProject(projectName) {
    if (!projectName || !window.projects || !window.projects[projectName]) {
        showUploadStatus('❌ Project not found to reclassify.', 'error');
        return;
    }

    const currentCategory = determineProjectCategory(projectName);
    const newCategory = prompt(`Project "${projectName}" is currently in "${currentCategory}".\nEnter new category (companies, locations, or referrers):`, currentCategory);

    if (!newCategory || newCategory.trim() === '' || newCategory.trim().toLowerCase() === currentCategory) {
        showUploadStatus('ℹ️ Reclassification cancelled or category not changed.', 'info');
        return;
    }

    const validCategories = ['companies', 'locations', 'referrers'];
    const normalizedCategory = newCategory.trim().toLowerCase();

    if (!validCategories.includes(normalizedCategory)) {
        showUploadStatus(`❌ Invalid category. Please use one of: ${validCategories.join(', ')}.`, 'error');
        return;
    }

    // Update the category in the local project object
    window.projects[projectName].category = normalizedCategory;

    // Persist the change
    if (isSuggestionMode()) {
        persistProjectLocally(projectName);
        showUploadStatus(`✅ Project "${projectName}" reclassified to "${normalizedCategory}" in your local drafts.`, 'success');
    } else {
        const originalCurrentProjectName = window.currentProjectName;
        const originalFormData = window.formData ? deepClone(window.formData) : null;

        try {
            // Load the project to be re-categorized into the main state.
            // This now returns a promise, so we await it.
            await loadProject(projectName);
            
            // Now that the correct data is loaded, save it.
            await saveProjectToCloud(projectName);
        } finally {
            // Restore the original project context if there was one
            if (originalCurrentProjectName && originalCurrentProjectName !== projectName) {
                await loadProject(originalCurrentProjectName);
            } else if (!originalCurrentProjectName) {
                newProject(false); // Create a new blank project if nothing was loaded before
            }
        }
    }

    refreshSavedProjectPanels();
}
// ============================================
// FORM DATA MANAGEMENT
// ============================================
function updateJSON() {
    ensureReferrerDataStructures();
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

    const array = getNestedValue(target, normalizedPath);
    if (Array.isArray(array) && index >= 0 && index < array.length) {
        array[index] = value;
        updateJSON();
        autoSave();
    }
}

function updateArrayObjectItemValue(path, index, field, value) {
    const { target, normalizedPath } = resolvePathTarget(path);
    if (!target) {
        return;
    }

    const array = getNestedValue(target, normalizedPath);
    if (Array.isArray(array) && index >= 0 && index < array.length) {
        const isPastTTIJobs = /pastTTIJobs$/.test(path);
        if (typeof array[index] !== 'object' || array[index] === null) {
            array[index] = isPastTTIJobs ? { role: '', organization: '' } : { role: '', name: '' };
        }
        array[index][field] = value;

        updateJSON();
        autoSave();
    }
}

function addNewArrayItem(path) {
    const { target, normalizedPath } = resolvePathTarget(path);
    if (!target) {
        return;
    }

    const array = getNestedValue(target, normalizedPath);
    if (Array.isArray(array)) {
        const isStaff = /^staff\./.test(path) || /^operator\.keyStaff\./.test(path);
        const isPastTTIJobs = /pastTTIJobs$/.test(path);
        let newItem = '';
        if (isStaff) {
            newItem = { role: '', name: '' };
        } else if (isPastTTIJobs) {
            newItem = { role: '', organization: '' };
        }
        array.push(newItem);
        const container = document.querySelector(`[data-path="${path}"]`);
        if (container) renderArray(container, path, array);
        updateJSON();
        autoSave();
    }
}

function removeArrayItemAtIndex(path, index) {
    const { target, normalizedPath } = resolvePathTarget(path);
    if (!target) {
        return;
    }

    const array = getNestedValue(target, normalizedPath);
    if (Array.isArray(array) && index >= 0 && index < array.length) {
        array.splice(index, 1);
        const container = document.querySelector(`[data-path="${path}"]`);
        if (container) renderArray(container, path, array);
        updateJSON();
        autoSave();
    }
}

function renderArray(container, path, items) {
    if (!container) return;

    const { scope, normalizedPath, target } = resolvePathTarget(path);
    if (!target) {
        return;
    }

    if (!Array.isArray(window.noteFieldRegistry)) {
        window.noteFieldRegistry = [];
    } else {
        const pathPrefix = `${path}.`;
        window.noteFieldRegistry = window.noteFieldRegistry.filter(entry => {
            if (!entry || !entry.key) {
                return false;
            }
            return !entry.key.startsWith(pathPrefix);
        });
    }

    // Set up event delegation on container if not already done
    if (!container.dataset.delegationInit) {
        container.addEventListener('click', (e) => { // Note: cannot be passive
            if (e.target.classList.contains('add-item-btn')) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                const btnPath = e.target.dataset.arrayPath;
                if (btnPath) {
                    addNewArrayItem(btnPath);
                }
            }
        });
        container.dataset.delegationInit = 'true';
    }

    // Preserve the add button to avoid click issues, but remove it temporarily
    let addButton = container.querySelector('.add-item-btn');
    if (addButton) {
        addButton.remove();
    }

    // Remove only array items
    const existingItems = container.querySelectorAll('.array-item');
    existingItems.forEach(item => item.remove());

    const existingNoteWrappers = container.querySelectorAll('.array-item-notes');
    existingNoteWrappers.forEach(wrapper => wrapper.remove());

    const sourceItems = items !== undefined ? items : getNestedValue(target, normalizedPath);
    const itemsArray = Array.isArray(sourceItems) ? sourceItems : (sourceItems ? [sourceItems] : []);

    // If array is empty, initialize it with one empty item so user input gets saved
    if (itemsArray.length === 0) {
        let array = getNestedValue(target, normalizedPath);

        // If array doesn't exist, create it
        if (!Array.isArray(array)) {
            array = [];
            setNestedValue(target, normalizedPath, array);
        }

        if (array.length === 0) {
            const isStaff = /^staff\./.test(path) || /^operator\.keyStaff\./.test(path);
            const isPastTTIJobs = /pastTTIJobs$/.test(path);
            let emptyItem = '';
            if (isStaff) {
                emptyItem = { role: '', name: '' };
            } else if (isPastTTIJobs) {
                emptyItem = { role: '', organization: '' };
            }
            array.push(emptyItem);
            // Re-render with the updated array
            renderArray(container, path, array);
            return;
        }
    }

    // Normalize items: convert strings to {role, name} for staff arrays
    const isStaff = /^staff\./.test(path) || /^operator\.keyStaff\./.test(path);
    const itemsToShow = itemsArray.map((item, idx) => {
        if (isStaff && typeof item === 'string') {
            // Parse legacy string format - check for "Role: Name" pattern
            const colonMatch = item.match(/^([^:]+):\s*(.+)$/);
            let role = '';
            let name = item;
            if (colonMatch) {
                role = colonMatch[1].trim();
                name = colonMatch[2].trim();
            }
            // Update the actual data array to the new format
            const array = getNestedValue(target, normalizedPath);
            if (Array.isArray(array)) {
                array[idx] = { role, name };
            }
            return { role, name };
        }
        return item;
    });

    itemsToShow.forEach((item, index) => {
        const itemDiv = document.createElement('div');
        itemDiv.className = 'array-item';
        const isPastTTIJobs = /pastTTIJobs$/.test(path);
        const scopeForNotes = scope;
        const noteKey = `${path}.${index}`;

        if (isStaff) {
            const roleInput = document.createElement('input');
            roleInput.type = 'text';
            roleInput.placeholder = 'Role';
            roleInput.value = (item && item.role) ? item.role : '';
            roleInput.className = 'array-input array-input-role';
            roleInput.oninput = () => updateArrayObjectItemValue(path, index, 'role', roleInput.value);
            attachCustomValueRecorder(roleInput, 'role');
            setTimeout(() => {
                if (!roleInput.dataset.autocompleteInit) {
                    delegateCreateAutocomplete(roleInput, getAllStaffRoles, 'role');
                    roleInput.dataset.autocompleteInit = 'true';
                }
            }, 100);
            itemDiv.appendChild(roleInput);

            const nameInput = document.createElement('input');
            nameInput.type = 'text';
            nameInput.placeholder = 'Name';
            nameInput.value = (item && item.name) ? item.name : '';
            nameInput.className = 'array-input array-input-name';
            nameInput.oninput = () => updateArrayObjectItemValue(path, index, 'name', nameInput.value);
            attachCustomValueRecorder(nameInput, 'human');
            itemDiv.appendChild(nameInput);
            setTimeout(() => {
                delegateCreateAutocomplete(nameInput, getAllHumanNames, 'human');
                nameInput.dataset.autocompleteInit = 'true';
            }, 100);
        } else if (isPastTTIJobs) {
            const roleInput = document.createElement('input');
            roleInput.type = 'text';
            roleInput.placeholder = 'Role';
            roleInput.value = (item && item.role) ? item.role : '';
            roleInput.className = 'array-input array-input-role';
            roleInput.oninput = () => updateArrayObjectItemValue(path, index, 'role', roleInput.value);
            attachCustomValueRecorder(roleInput, 'role');
            setTimeout(() => {
                if (!roleInput.dataset.autocompleteInit) {
                    delegateCreateAutocomplete(roleInput, getAllStaffRoles, 'role');
                    roleInput.dataset.autocompleteInit = 'true';
                }
            }, 100);
            itemDiv.appendChild(roleInput);

            const orgInput = document.createElement('input');
            orgInput.type = 'text';
            orgInput.placeholder = 'Organization/Company';
            orgInput.value = (item && item.organization) ? item.organization : '';
            orgInput.className = 'array-input array-input-name';
            orgInput.oninput = () => updateArrayObjectItemValue(path, index, 'organization', orgInput.value);
            attachCustomValueRecorder(orgInput, 'operator');
            itemDiv.appendChild(orgInput);
            setTimeout(() => {
                delegateCreateAutocomplete(orgInput, getAllOperators, 'operator');
                orgInput.dataset.autocompleteInit = 'true';
            }, 100);
        } else {
            const input = document.createElement('input');
            input.type = 'text';
            input.value = item || '';
            input.className = 'array-input';
            input.oninput = () => updateArrayItemValue(path, index, input.value);

            let category = null;
            let dataFunc = () => [];
            if (/identification\.otherNames$/.test(path)) {
                category = 'facility';
                dataFunc = getAllFacilityNames;
            } else if (/identification\.knownReferrers$/.test(path)) {
                category = 'referrer';
                dataFunc = getAllReferrers;
            } else if (/operator\.otherNames$/.test(path) || /operator\.parentCompanies$/.test(path) || /otherOperators$/.test(path)) {
                category = 'operator';
                dataFunc = getAllOperators;
            } else if (/operator\.keyStaff\.founders$/.test(path) || /operator\.keyStaff\.keyExecutives$/.test(path)) {
                category = 'human';
                dataFunc = getAllHumanNames;
            } else if (/accreditations\.current$/.test(path) || /accreditations\.past$/.test(path)) {
                category = 'accreditation';
                dataFunc = getAllAccreditations;
            } else if (/memberships$/.test(path)) {
                category = 'membership';
                dataFunc = getAllMemberships;
            } else if (/certifications$/.test(path)) {
                category = 'certification';
                dataFunc = getAllCertifications;
            } else if (/licensing$/.test(path)) {
                category = 'licensing';
                dataFunc = () => Array.from(customLicensing);
            } else if (/investors$/.test(path)) {
                category = 'investor';
                dataFunc = () => Array.from(customInvestors);
            } else if (/referrerIndividual\.knownReferrals$/.test(path)) {
                category = 'facility';
                dataFunc = getAllFacilityNames;
            } else if (/referrerGroup\.affiliations$/.test(path) || /referrerIndividual\.affiliations$/.test(path) || /consultant\.affiliations$/.test(path)) {
                category = 'membership';
                dataFunc = getAllMemberships;
            } else if (/consultant\.facilitiesReferred$/.test(path)) {
                category = 'facility';
                dataFunc = getAllFacilityNames;
            } else if (/consultant\.schoolDistricts$/.test(path)) {
                category = 'location';
                dataFunc = () => Array.from(window.US_STATE_SET);
            } else if (/referrerAgency\.keyPersonnel$/.test(path)) {
                category = 'human';
                dataFunc = getAllHumanNames;
            }

            if (category) {
                itemDiv.appendChild(input); // Must be in DOM for createAutocomplete to find parent
                setTimeout(() => {
                    if (!input.dataset.autocompleteInit) {
                        delegateCreateAutocomplete(input, dataFunc, category);
                        input.dataset.autocompleteInit = 'true';
                    }
                }, 100);
            } else {
                // No autocomplete, so we must wrap it ourselves for consistent styling
                const wrapper = document.createElement('div');
                // We can reuse the autocomplete-wrapper class as it provides the flex behavior we need
                wrapper.className = 'autocomplete-wrapper';
                wrapper.appendChild(input);
                itemDiv.appendChild(wrapper);
            }
        }

        // Add Note button for each array item, unless it's a notes array
        const isNoteArray = /notes$/.test(path);
        if (!isNoteArray) {
            const addNoteBtn = document.createElement('button');
            addNoteBtn.type = 'button';
            addNoteBtn.className = 'note-add-btn field-note-btn';
            addNoteBtn.textContent = '+';
            addNoteBtn.setAttribute('aria-label', 'Add note');
            addNoteBtn.addEventListener('click', (e) => { // Note: cannot be passive
                e.preventDefault();
                e.stopImmediatePropagation();
                addFieldNote(scopeForNotes, noteKey);
            });
            itemDiv.appendChild(addNoteBtn);
        }

        // Show remove button when there are 2+ items (so users can remove extras)
        // Don't show when there's only 1 item (removing it would just auto-create an empty one)
        if (itemsToShow.length > 1) {
            const removeBtn = document.createElement('button');
            removeBtn.className = 'btn remove-btn';
            removeBtn.textContent = '−';
            removeBtn.type = 'button';
            removeBtn.onclick = () => removeArrayItemAtIndex(path, index);
            itemDiv.appendChild(removeBtn);
        }

        // Container for notes for this specific item
        let notesContainer = null;
        let noteWrapper = null;

        if (!isNoteArray) {
            notesContainer = document.createElement('div');
            notesContainer.className = 'field-notes';
            notesContainer.dataset.noteContainerKey = noteKey;
            notesContainer.dataset.noteScope = scopeForNotes;

            noteWrapper = document.createElement('div');
            noteWrapper.className = 'array-item-notes';
            noteWrapper.dataset.arrayPath = path;
            noteWrapper.dataset.arrayIndex = `${index}`;
            noteWrapper.appendChild(notesContainer);

            // Register this container so it can be rendered
            window.noteFieldRegistry.push({ scope: scopeForNotes, key: noteKey, container: notesContainer });
        }

        container.appendChild(itemDiv);
        if (noteWrapper) {
            container.appendChild(noteWrapper);
        }
    });

    // Generate descriptive button label based on path
    let buttonLabel = 'Add More';
    if (/^staff\.administrator$/.test(path)) {
        buttonLabel = 'Add More Administrators';
    } else if (/^staff\.notableStaff$/.test(path)) {
        buttonLabel = 'Add More Notable Staff';
    } else if (/operator\.keyStaff\.founders$/.test(path)) {
        buttonLabel = 'Add More Founders';
    } else if (/operator\.keyStaff\.keyExecutives$/.test(path)) {
        buttonLabel = 'Add More Key Executives';
    } else if (/operator\.parentCompanies$/.test(path)) {
        buttonLabel = 'Add More Parent Companies';
    } else if (/operator\.investors$/.test(path)) {
        buttonLabel = 'Add More Investors';
    } else if (/operator\.otherNames$/.test(path)) {
        buttonLabel = 'Add More Names';
    } else if (/identification\.otherNames$/.test(path)) {
        buttonLabel = 'Add More Names';
    } else if (/identification\.knownReferrers$/.test(path)) {
        buttonLabel = 'Add More Referrers';
    } else if (/otherOperators$/.test(path)) {
        buttonLabel = 'Add More Operators';
    } else if (/accreditations\.current$/.test(path)) {
        buttonLabel = 'Add More Accreditations';
    } else if (/accreditations\.past$/.test(path)) {
        buttonLabel = 'Add More Past Accreditations';
    } else if (/memberships$/.test(path)) {
        buttonLabel = 'Add More Memberships';
    } else if (/certifications$/.test(path)) {
        buttonLabel = 'Add More Certifications';
    } else if (/licensing$/.test(path)) {
        buttonLabel = 'Add More Licensing Info';
    } else if (/websites$/.test(path)) {
        buttonLabel = 'Add More Websites';
    } else if (/profileLinks$/.test(path)) {
        buttonLabel = 'Add More Profile Links';
    } else if (/notes$/.test(path)) {
        buttonLabel = 'Add More Notes';
    } else if (/operatingPeriod\.notes$/.test(path)) {
        buttonLabel = 'Add More Operational Notes';
    } else if (/resources\.notes$/.test(path)) {
        buttonLabel = 'Add More Resource Notes';
    } else if (/referrerGroup\.affiliations$/.test(path)) {
        buttonLabel = 'Add More Affiliations';
    } else if (/referrerIndividual\.affiliations$/.test(path)) {
        buttonLabel = 'Add More Affiliations';
    } else if (/referrerIndividual\.knownReferrals$/.test(path)) {
        buttonLabel = 'Add More Referrals';
    } else if (/referrerIndividual\.pastTTIJobs$/.test(path)) {
        buttonLabel = 'Add More TTI Roles';
    }

    // Re-create the add button if it was removed
    if (!addButton) {
        addButton = document.createElement('button');
        addButton.className = 'add-item-btn';
        addButton.type = 'button';
    }

    // Always append at the end to ensure correct position
    container.appendChild(addButton);

    // Update button text and ensure path is set
    addButton.textContent = buttonLabel;
    addButton.dataset.arrayPath = path;

    // Render notes for the array items
    renderAllFieldNotes();
}

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

    const facilityName = document.getElementById('facility-name');
    if (facilityName) facilityName.value = facility.identification?.name || '';
    
    const facilityType = document.getElementById('facility-type');
    if (facilityType) facilityType.value = facility.facilityDetails?.type || '';

    const arrayPaths = ['identification.otherNames', 'identification.knownReferrers', 'otherOperators', 'operatingPeriod.notes', 'staff.administrator', 'staff.notableStaff', 'profileLinks', 'accreditations.current', 'accreditations.past', 'memberships', 'certifications', 'licensing', 'resources.notes', 'notes'];
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
        loadReferrerData();
        loadFacilityData();
        updateFacilityControls();
        updateTableOfContents();
        updateJSON();
        renderSavedProjectsList();
        initializeSectionToggles();
        updateProjectStatus();
        initializeAutocompleteFields();
        updateLabelsForProjectType(window.currentProjectName || '');
        initializeNoteControls();
        updateToolbarFacilityInfo(); // Update toolbar when UI updates

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

function updateTableOfContents() {
    const facilityList = document.getElementById('facility-list');
    const tocStats = document.getElementById('toc-stats');
    const total = window.formData.facilities?.length || 0;

    // If no project is loaded, show a helpful message with a link.
    // The link and message are different for the admin page vs. the suggestions page.
    if (!window.currentProjectName) {
        if (tocStats) tocStats.textContent = 'No project loaded';
        if (facilityList) {
            if (isSuggestionMode()) {
                facilityList.innerHTML = `
                    <div class="toc-no-project">Please <a href="#submission-section">create a draft or load a project</a> to see the list of facilities.</div>
                `;
            } else {
                facilityList.innerHTML = `
                    <div class="toc-no-project">Please <a href="#advanced-mode-section">load or create a project</a> to see the list of facilities.</div>
                `;
            }
        }
        return;
    }

    if (tocStats) tocStats.textContent = `Total: ${total} facilit${total === 1 ? 'y' : 'ies'}`;
    if (facilityList) {
        facilityList.innerHTML = '';

        // Create array with facility data and original index, then sort alphabetically
        const facilitiesWithIndex = window.formData.facilities?.map((facility, index) => ({
            facility,
            originalIndex: index,
            name: facility.identification?.name || 'Unnamed Facility'
        })) || [];

        // Sort alphabetically by name (case-insensitive)
        facilitiesWithIndex.sort((a, b) => {
            const nameA = a.name.toLowerCase();
            const nameB = b.name.toLowerCase();
            if (nameA === 'unnamed facility' && nameB !== 'unnamed facility') return 1;
            if (nameB === 'unnamed facility' && nameA !== 'unnamed facility') return -1;
            return nameA.localeCompare(nameB);
        });

        // Display facilities in alphabetical order
        facilitiesWithIndex.forEach(({ facility, originalIndex, name }, alphabeticalIndex) => {
            const item = document.createElement('div');
            item.className = `facility-item ${originalIndex === window.currentFacilityIndex ? 'active' : ''}`;
            item.innerHTML = `<span class="facility-name ${name === 'Unnamed Facility' ? 'empty' : ''}">${escapeHtmlForAttr(name)}</span><span class="facility-index">${alphabeticalIndex + 1}</span>`;
            item.tabIndex = 0;
            const accessibleFacilityName = name !== 'Unnamed Facility' ? name : `Facility ${alphabeticalIndex + 1}`;
            item.setAttribute('role', 'button');
            item.setAttribute('aria-label', `View ${accessibleFacilityName}`);
            const goToFacility = () => navigateToFacility(originalIndex);
            item.addEventListener('click', goToFacility);
            item.addEventListener('keydown', function(event) { // Note: cannot be passive
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    goToFacility();
                }
            });
            facilityList.appendChild(item);
        });
    }
}

function updateFacilityControls() {
    const total = window.formData.facilities?.length || 0;
    const facilityCounter = document.getElementById('facility-counter');
    if (facilityCounter) facilityCounter.textContent = `${window.currentFacilityIndex + 1} of ${total}`;
    
    const name = window.formData.facilities?.[window.currentFacilityIndex]?.identification?.name || 'Unnamed Facility';
    const currentFacilityName = document.getElementById('current-facility-name');
    if (currentFacilityName) currentFacilityName.textContent = name !== 'Unnamed Facility' ? `(${name})` : '';

    const prevBtn = document.getElementById('prev-facility-btn');
    const nextBtn = document.getElementById('next-facility-btn');
    const removeBtn = document.getElementById('remove-facility-btn');

    if (prevBtn) prevBtn.style.display = total > 1 ? 'inline-block' : 'none';
    if (nextBtn) nextBtn.style.display = total > 1 ? 'inline-block' : 'none';
    if (removeBtn) removeBtn.style.display = total > 1 ? 'inline-block' : 'none';
}

function navigateToFacility(index) {
    if (index >= 0 && index < window.formData.facilities.length) {
        window.currentFacilityIndex = index;
        loadFacilityData();
        updateFacilityControls();
        updateTableOfContents();
        updateToolbarFacilityInfo();
        scrollToFormInput();
    }
}

/**
 * Scroll the view to the form input section after loading a facility
 * Targets the facility controls or first form section for best UX
 */
function scrollToFormInput() {
    // Small delay to ensure DOM is updated
    setTimeout(() => {
        // Try to scroll to the facility controls (where input starts)
        const facilityControls = document.querySelector('.facility-controls');
        const operatorSection = document.getElementById('operator-section');
        const identificationSection = document.getElementById('identification-section');
        const facilityMainWrapper = document.getElementById('facility-main-wrapper');
        
        // Find the best target to scroll to
        const scrollTarget = facilityControls || operatorSection || identificationSection || facilityMainWrapper;
        
        if (scrollTarget) {
            // Account for fixed toolbar height
            const toolbarHeight = document.querySelector('.fixed-toolbar')?.offsetHeight || 0;
            const offset = toolbarHeight + 20; // Extra padding
            
            const targetPosition = scrollTarget.getBoundingClientRect().top + window.pageYOffset - offset;
            
            window.scrollTo({
                top: targetPosition,
                behavior: 'smooth'
            });
        }
    }, 50);
}

// ============================================
// TOOLBAR FUNCTIONALITY
// ============================================
function updateToolbarFacilityInfo() {
    const dropdown = document.getElementById('facility-dropdown');
    const projectNameSpan = document.getElementById('toolbar-project-name');

    // Only proceed if toolbar elements exist (not all pages have the toolbar)
    if (!dropdown) return;

    debugLog('🔄 Updating toolbar facility info...', {
        'formData exists': !!window.formData,
        'facilities count': window.formData?.facilities?.length || 0,
        'currentProjectName': window.currentProjectName
    });

    if (window.formData && window.formData.facilities) {
        // Create array with facility data and original index
        const facilitiesWithIndex = window.formData.facilities.map((facility, index) => ({
            facility,
            originalIndex: index,
            name: facility.identification?.name || 'Unnamed Facility'
        }));

        // Sort alphabetically by name (case-insensitive, unnamed facilities last)
        facilitiesWithIndex.sort((a, b) => {
            const nameA = a.name.toLowerCase();
            const nameB = b.name.toLowerCase();
            if (nameA === 'unnamed facility' && nameB !== 'unnamed facility') return 1;
            if (nameB === 'unnamed facility' && nameA !== 'unnamed facility') return -1;
            return nameA.localeCompare(nameB);
        });

        // Clear and populate dropdown with alphabetically sorted facilities
        dropdown.innerHTML = '';
        facilitiesWithIndex.forEach(({ facility, originalIndex, name }, alphabeticalIndex) => {
            const option = document.createElement('option');
            option.value = originalIndex;
            option.textContent = `${alphabeticalIndex + 1}. ${name}`;
            dropdown.appendChild(option);
        });

        debugLog('✅ Populated dropdown with', window.formData.facilities.length, 'facilities (alphabetically sorted)');

        // Set current selection
        if (typeof window.currentFacilityIndex !== 'undefined') {
            dropdown.value = window.currentFacilityIndex;
        }
    } else {
        debugLog('⚠️ Could not update toolbar: no formData or facilities');
        dropdown.innerHTML = '<option>No facilities</option>';
    }

    // Update project name
    if (projectNameSpan) {
        projectNameSpan.textContent = window.currentProjectName ? `(${window.currentProjectName})` : '';
    }

    // Show/hide remove button
    const removeBtn = document.getElementById('remove-facility-btn-toolbar');
    if (removeBtn && window.formData && window.formData.facilities) {
        if (window.formData.facilities.length > 1) {
            removeBtn.classList.remove('d-none');
        } else {
            removeBtn.classList.add('d-none');
        }
    }

    // Update prev/next button states
    updateToolbarNavButtons();
}

function updateToolbarNavButtons() {
    const prevBtn = document.getElementById('prev-facility-btn-toolbar');
    const nextBtn = document.getElementById('next-facility-btn-toolbar');
    const dropdown = document.getElementById('facility-dropdown');

    if (!prevBtn || !nextBtn || !dropdown) return;

    const currentIndex = parseInt(dropdown.value) || 0;
    const totalFacilities = dropdown.options.length;

    prevBtn.disabled = currentIndex === 0;
    nextBtn.disabled = currentIndex === totalFacilities - 1;

    prevBtn.style.opacity = currentIndex === 0 ? '0.5' : '1';
    nextBtn.style.opacity = currentIndex === totalFacilities - 1 ? '0.5' : '1';
}

// Make toolbar function globally accessible
window.updateToolbarFacilityInfo = updateToolbarFacilityInfo;

// Initialize toolbar button event listeners
function initializeToolbarButtons() {
    // Toolbar toggle (minimize/expand)
    const toolbarToggle = document.getElementById('toolbar-toggle-btn');
    const toolbar = document.getElementById('fixed-toolbar');
    const toolbarContent = document.getElementById('toolbar-content');

    if (toolbar) {
        document.body.classList.add('toolbar-active');
        document.body.classList.toggle('toolbar-minimized', toolbar.classList.contains('minimized'));
        if (toolbarContent) {
            toolbarContent.setAttribute('aria-hidden', toolbar.classList.contains('minimized') ? 'true' : 'false');
        }
    }

    if (toolbarToggle && toolbar && !toolbarToggle.dataset.listenerAttached) {
        const applyToolbarState = (isMinimized) => {
            document.body.classList.toggle('toolbar-minimized', isMinimized);
            if (toolbarContent) {
                toolbarContent.setAttribute('aria-hidden', isMinimized ? 'true' : 'false');
            }
            toolbarToggle.textContent = isMinimized ? '▼' : '−';
            toolbarToggle.title = isMinimized ? 'Expand toolbar' : 'Minimize toolbar';
            toolbarToggle.setAttribute('aria-expanded', isMinimized ? 'false' : 'true');
        };

        toolbarToggle.setAttribute('aria-controls', 'toolbar-content');
        applyToolbarState(toolbar.classList.contains('minimized'));

        toolbarToggle.addEventListener('click', () => { // UI-only, can be passive
            const isMinimized = toolbar.classList.toggle('minimized');
            applyToolbarState(isMinimized);
        }, { passive: true });

        toolbarToggle.dataset.listenerAttached = 'true';
    }

    // Facility dropdown change handler
    const facilityDropdown = document.getElementById('facility-dropdown');
    if (facilityDropdown && !facilityDropdown.dataset.listenerAttached) {
        facilityDropdown.addEventListener('change', (e) => {
            const newIndex = parseInt(e.target.value);
            if (!isNaN(newIndex)) {
                navigateToFacility(newIndex);
            }
        }, { passive: true });
        facilityDropdown.dataset.listenerAttached = 'true';
    }

    // Previous/Next facility buttons
    const prevBtnToolbar = document.getElementById('prev-facility-btn-toolbar');
    const nextBtnToolbar = document.getElementById('next-facility-btn-toolbar');

    if (prevBtnToolbar && !prevBtnToolbar.dataset.listenerAttached) {
        prevBtnToolbar.addEventListener('click', () => { // UI-only, can be passive
            const dropdown = document.getElementById('facility-dropdown');
            if (dropdown && dropdown.selectedIndex > 0) {
                dropdown.selectedIndex--;
                navigateToFacility(parseInt(dropdown.value));
            }
        }, { passive: true });
        prevBtnToolbar.dataset.listenerAttached = 'true';
    }

    if (nextBtnToolbar && !nextBtnToolbar.dataset.listenerAttached) {
        nextBtnToolbar.addEventListener('click', () => { // UI-only, can be passive
            const dropdown = document.getElementById('facility-dropdown');
            if (dropdown && dropdown.selectedIndex < dropdown.options.length - 1) {
                dropdown.selectedIndex++;
                navigateToFacility(parseInt(dropdown.value));
            }
        }, { passive: true });
        nextBtnToolbar.dataset.listenerAttached = 'true';
    }

    // Add Facility button
    const addFacilityBtnToolbar = document.getElementById('add-facility-btn-toolbar');
    if (addFacilityBtnToolbar && !addFacilityBtnToolbar.dataset.listenerAttached) {
        addFacilityBtnToolbar.addEventListener('click', addFacility);
        addFacilityBtnToolbar.dataset.listenerAttached = 'true';
    }

    // Clone Facility button
    const cloneFacilityBtnToolbar = document.getElementById('clone-facility-btn-toolbar');
    if (cloneFacilityBtnToolbar && !cloneFacilityBtnToolbar.dataset.listenerAttached) {
        cloneFacilityBtnToolbar.addEventListener('click', cloneFacility);
        cloneFacilityBtnToolbar.dataset.listenerAttached = 'true';
    }

    // Remove Facility button
    const removeFacilityBtnToolbar = document.getElementById('remove-facility-btn-toolbar');
    if (removeFacilityBtnToolbar && !removeFacilityBtnToolbar.dataset.listenerAttached) {
        removeFacilityBtnToolbar.addEventListener('click', removeFacility);
        removeFacilityBtnToolbar.dataset.listenerAttached = 'true';
    }

    debugLog('✅ Toolbar buttons initialized');
}

function addFacility() {
    const newFacility = createNewProjectData().facilities[0];
    window.formData.facilities.push(newFacility);
    window.currentFacilityIndex = window.formData.facilities.length - 1;
    window.updateAllUI();
    autoSave();

    // Dispatch custom event
    document.dispatchEvent(new CustomEvent('facilityChanged', {
        detail: { action: 'add', index: window.currentFacilityIndex }
    }));
}

function removeFacility() {
    if (window.formData.facilities.length > 1) {
        if (!confirm('Are you sure you want to remove this facility?')) return;
        window.formData.facilities.splice(window.currentFacilityIndex, 1);
        if (window.currentFacilityIndex >= window.formData.facilities.length) {
            window.currentFacilityIndex = window.formData.facilities.length - 1;
        }
        window.updateAllUI();
        autoSave();

        // Dispatch custom event
        document.dispatchEvent(new CustomEvent('facilityChanged', {
            detail: { action: 'remove', index: window.currentFacilityIndex }
        }));
    }
}

async function performClone(targetProjectName, targetCategory = null) {
    if (!targetProjectName) {
        alert('No target project specified for cloning.');
        return;
    }

    // IMPORTANT: Capture the facility to clone IMMEDIATELY using deep clone
    // This ensures we get exactly ONE facility, not a reference to the array
    const sourceIndex = window.currentFacilityIndex;
    const sourceFacility = window.formData.facilities[sourceIndex];
    
    if (!sourceFacility) {
        alert('No facility selected to clone.');
        return;
    }
    
    // Deep clone the single facility to ensure complete isolation from source
    const facilityToClone = JSON.parse(JSON.stringify(sourceFacility));
    
    // Give the clone a new name to avoid confusion
    if (facilityToClone.identification && facilityToClone.identification.name) {
        facilityToClone.identification.name = `${facilityToClone.identification.name} (Clone)`;
    }
    
    debugLog(`🔄 Cloning facility "${sourceFacility.identification?.name || 'unnamed'}" (index ${sourceIndex}) to project "${targetProjectName}"`);
    debugLog(`   Source project "${window.currentProjectName}" has ${window.formData.facilities.length} facilities`);

    // Case 1: Clone to the current project
    if (targetProjectName === window.currentProjectName) {
        window.formData.facilities.push(facilityToClone);
        window.currentFacilityIndex = window.formData.facilities.length - 1;
        alert(`✅ Facility cloned within project "${targetProjectName}".`);
        updateAllUI();
        autoSave();
        return;
    }

    // Case 2 & 3: Clone to a new or different existing project
    const isNewProject = !window.projects[targetProjectName];

    // Determine category: use provided category, or fall back to active tab
    let category = targetCategory;
    if (!category) {
        const activeTab = document.querySelector('.category-tab.active');
        category = activeTab ? activeTab.dataset.category : 'companies';
    }

    if (isNewProject) {
        // Create new project with ONLY the cloned facility, no operator data
        // Use JSON.parse/stringify to ensure completely fresh object with no shared references
        const newProjectData = JSON.parse(JSON.stringify(createNewProjectData()));
        
        // Replace the default empty facility with ONLY our cloned facility
        newProjectData.facilities = [facilityToClone];
        
        debugLog(`   New project will have ${newProjectData.facilities.length} facility (should be 1)`);

        window.projects[targetProjectName] = {
            name: targetProjectName,
            data: newProjectData,
            currentFacilityIndex: 0,
            timestamp: new Date().toISOString(),
            category: category
        };
        debugLog(`✅ Created new project "${targetProjectName}" with cloned facility in category "${category}" (no operator data).`);
    } else {
        if (!window.projects[targetProjectName].data.facilities) {
            window.projects[targetProjectName].data.facilities = [];
        }
        window.projects[targetProjectName].data.facilities.push(facilityToClone);
        debugLog(`✅ Added cloned facility to existing project "${targetProjectName}". Now has ${window.projects[targetProjectName].data.facilities.length} facilities.`);
    }

    // Persist the changes
    if (isSuggestionMode()) {
        persistProjectLocally(targetProjectName);
        alert(`✅ Facility cloned to project "${targetProjectName}" and saved as a local draft.`);
    } else {
        try {
            // Save ONLY the target project to the cloud
            // We need to temporarily change context, but use deep clones to avoid any reference issues
            const originalProjectName = window.currentProjectName;
            const originalFormData = JSON.parse(JSON.stringify(window.formData));
            const originalIndex = window.currentFacilityIndex;
            
            // Get a fresh deep clone of the target project's data for saving
            const targetProjectData = JSON.parse(JSON.stringify(window.projects[targetProjectName].data));
            
            debugLog(`   Saving target project with ${targetProjectData.facilities.length} facilities`);
            
            // Temporarily switch context to save the target project
            window.currentProjectName = targetProjectName;
            window.formData = targetProjectData;
            window.currentFacilityIndex = 0;
            
            await saveProjectToCloud(targetProjectName);
            
            // Restore original context using our saved deep clones
            window.currentProjectName = originalProjectName;
            window.formData = originalFormData;
            window.currentFacilityIndex = originalIndex;

            alert(`✅ Facility cloned and saved to project "${targetProjectName}" in the cloud.`);
        } catch (error) {
            alert(`⚠️ Facility cloned, but failed to save project "${targetProjectName}" to the cloud. It is saved locally.`);
            console.error("Error saving cloned project to cloud:", error);
        }
    }

    // Refresh UI to show the new/updated project in the list
    refreshSavedProjectPanels();
}

function cloneFacility() {
    const modal = document.getElementById('clone-facility-modal');
    if (!modal) return;

    // --- Populate Modal ---
    const currentProjectNameSpan = document.getElementById('clone-current-project-name');
    if (currentProjectNameSpan) {
        currentProjectNameSpan.textContent = window.currentProjectName || 'New Project';
    }

    const existingProjectSelect = document.getElementById('existing-project-select');
    existingProjectSelect.innerHTML = '<option value="">Select a project...</option>'; // Clear previous
    const availableProjects = Object.keys(window.projects || {}).filter(name => name !== window.currentProjectName);
    availableProjects.sort().forEach(name => {
        const option = document.createElement('option');
        option.value = name;
        option.textContent = name;
        existingProjectSelect.appendChild(option);
    });

    // --- Show Modal ---
    modal.classList.add('active');

    // --- Event Handlers ---
    const confirmBtn = document.getElementById('clone-modal-confirm');
    const cancelBtn = document.getElementById('clone-modal-cancel');
    const closeBtn = document.getElementById('clone-modal-close');
    const radios = document.querySelectorAll('input[name="clone-destination"]');

    const existingProjectContainer = document.getElementById('existing-project-container');
    const newProjectContainer = document.getElementById('new-project-container');

    function handleRadioChange() {
        const selected = document.querySelector('input[name="clone-destination"]:checked').value;
        existingProjectContainer.style.display = selected === 'existing' ? 'block' : 'none';
        newProjectContainer.style.display = selected === 'new' ? 'block' : 'none';
    }

    radios.forEach(radio => radio.addEventListener('change', handleRadioChange, { passive: true }));
    handleRadioChange(); // Set initial state

    function closeModal() {
        modal.classList.remove('active');
    }

    // Remove old listeners by cloning nodes
    const newConfirmBtn = confirmBtn.cloneNode(true);
    const newCancelBtn = cancelBtn.cloneNode(true);
    const newCloseBtn = closeBtn.cloneNode(true);

    confirmBtn.replaceWith(newConfirmBtn);
    cancelBtn.replaceWith(newCancelBtn);
    closeBtn.replaceWith(newCloseBtn);

    newConfirmBtn.onclick = () => {
        const destination = document.querySelector('input[name="clone-destination"]:checked').value;
        let targetProjectName = '';
        let targetCategory = null;

        if (destination === 'current') {
            targetProjectName = window.currentProjectName;
        } else if (destination === 'existing') {
            targetProjectName = document.getElementById('existing-project-select').value;
        } else if (destination === 'new') {
            targetProjectName = document.getElementById('new-project-name-input').value.trim();
            // Get the selected category for new projects
            const categorySelect = document.getElementById('new-project-category-select');
            if (categorySelect) {
                targetCategory = categorySelect.value;
            }
        }

        if (!targetProjectName) {
            alert('Please select or enter a valid project name.');
            return;
        }

        if (destination === 'new' && window.projects[targetProjectName]) {
            alert(`A project named "${targetProjectName}" already exists. Please choose a different name or select it from the 'existing' list.`);
            return;
        }

        performClone(targetProjectName, targetCategory);
        closeModal();
    };

    newCancelBtn.onclick = closeModal;
    newCloseBtn.onclick = closeModal;
}

function sortFacilities() {
    if (window.formData.facilities.length <= 1) return;
    const currentName = window.formData.facilities[window.currentFacilityIndex].identification.name;
    window.formData.facilities.sort((a, b) => (a.identification.name || '').localeCompare(b.identification.name || ''));
    const newIndex = window.formData.facilities.findIndex(f => f.identification.name === currentName);
    window.currentFacilityIndex = newIndex !== -1 ? newIndex : 0;
    window.updateAllUI();
    autoSave();
}

// Debounce flag to prevent double-clicking navigation buttons
let isNavigating = false;

function previousFacility() {
    if (isNavigating) return;
    if (window.currentFacilityIndex > 0) {
        isNavigating = true;
        window.currentFacilityIndex--;
        loadFacilityData();
        updateFacilityControls();
        updateTableOfContents();
        scrollToFormInput();
        setTimeout(() => { isNavigating = false; }, 100);
    }
}

function nextFacility() {
    if (isNavigating) return;
    if (window.currentFacilityIndex < window.formData.facilities.length - 1) {
        isNavigating = true;
        window.currentFacilityIndex++;
        loadFacilityData();
        updateFacilityControls();
        updateTableOfContents();
        scrollToFormInput();
        setTimeout(() => { isNavigating = false; }, 100);
    }
}

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

function determineProjectCategory(name = '') {
    const normalized = name.toLowerCase().trim();
    
    // PRIORITY 1: State/country names are ALWAYS locations, regardless of stored metadata
    // This prevents state projects from being miscategorized
    if (window.US_STATE_SET?.has(normalized) || window.COUNTRY_SET?.has(normalized)) {
        return 'locations';
    }
    
    // PRIORITY 2: Check stored category metadata for non-location projects
    const project = window.projects?.[name];
    if (project && project.category) {
        return project.category;
    }

    // PRIORITY 3: Name-based heuristic for legacy projects
    // A simple heuristic: if it contains referrer-related keywords, it's a referrer project
    if (normalized.includes('consultant') ||
        normalized.includes('district') ||
        normalized.includes('agency') ||
        normalized.includes('referrer') ||
        normalized.includes('education') ||
        normalized.includes('school')) {
        return 'referrers';
    }
    
    return 'companies';
}

function renderSavedProjectsList() {
    refreshSavedProjectPanels();
}

function refreshSavedProjectPanels() {
    const projects = window.projects || {};
    const projectNames = Object.keys(projects);
    const companyContainer = document.getElementById('company-saved-projects-list');
    const locationContainer = document.getElementById('location-saved-projects-list');
    const referrerContainer = document.getElementById('referrer-saved-projects-list');

    // DEBUG: Log project categorization
    console.log('🔍 refreshSavedProjectPanels called');
    console.log('Total projects:', projectNames.length);
    console.log('US_STATE_SET defined?', typeof window.US_STATE_SET !== 'undefined');
    console.log('COUNTRY_SET defined?', typeof window.COUNTRY_SET !== 'undefined');

    // Log each project's category
    projectNames.forEach(name => {
        const category = determineProjectCategory(name);
        console.log(`  - "${name}" → category: ${category}`);
    });

    if (!companyContainer && !locationContainer && !referrerContainer) {
        console.warn('⚠️ No project containers found in DOM');
        return;
    }

    const buildProjectCards = (names, emptyMessage) => {
        if (!names.length) {
            return `<div class="projects-empty">${emptyMessage}</div>`;
        }

        const sortedNames = names.slice().sort((a, b) => {
            const timeA = projects[a]?.timestamp || '';
            const timeB = projects[b]?.timestamp || '';
            if (timeA === timeB) {
                return a.localeCompare(b);
            }
            return timeB.localeCompare(timeA);
        });

        return sortedNames.map(name => {
            const project = window.projects[name];
            const date = new Date(project.timestamp || 0);
            const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            
            const facilityCount = project.data?.facilities?.length || 0;
            const facilityLabel = determineProjectCategory(name) === 'referrers' ? (facilityCount === 1 ? 'individual' : 'individuals') : (facilityCount === 1 ? 'facility' : 'facilities');
            
            // Admin-only buttons
            const adminButtons = isSuggestionMode() ? '' : `
                        <button class="project-item-btn project-item-reclassify" onclick="event.stopPropagation(); recategorizeProject('${escapeHtmlForAttr(name)}')">Reclassify</button>
                        <button class="project-item-btn project-item-rename" onclick="event.stopPropagation(); renameProject('${escapeHtmlForAttr(name)}')">Rename</button>
                        <button class="project-item-btn project-item-delete" onclick="event.stopPropagation(); deleteProject('${escapeHtmlForAttr(name)}')">Delete</button>
                    `;

            return `<div class="project-item" onclick="loadProject('${escapeHtmlForAttr(name)}')">
                    <div class="project-item-name" title="${escapeHtmlForAttr(name)}">${escapeHtmlForAttr(name)}</div>
                    <div class="project-item-date">${escapeHtmlForAttr(dateStr)}<br><small>${facilityCount} ${facilityLabel}</small></div>
                    <div class="project-item-actions">
                        <button class="project-item-btn project-item-load" onclick="event.stopPropagation(); loadProject('${escapeHtmlForAttr(name)}')">Load</button>
                        ${adminButtons}
                    </div>
                </div>`;
        }).join('');
    };

    if (companyContainer) {
        const companyNames = projectNames.filter(name => determineProjectCategory(name) === 'companies');
        console.log(`📦 Company projects: ${companyNames.length}`, companyNames);
        companyContainer.innerHTML = buildProjectCards(companyNames, '📭 No saved company projects yet');
    }

    if (locationContainer) {
        const locationNames = projectNames.filter(name => determineProjectCategory(name) === 'locations');
        console.log(`📍 Location projects: ${locationNames.length}`, locationNames);
        locationContainer.innerHTML = buildProjectCards(locationNames, '📭 No saved location projects yet');
    }

    if (referrerContainer) {
        const referrerNames = projectNames.filter(name => determineProjectCategory(name) === 'referrers');
        console.log(`👥 Referrer projects: ${referrerNames.length}`, referrerNames);
        referrerContainer.innerHTML = buildProjectCards(referrerNames, '📭 No saved referrer projects yet. Create one using the "New Project" button.');
    }
}

function updateProjectStatus() {
    const statusTargets = [
        document.getElementById('project-status'),
        document.getElementById('project-status-location'),
        document.getElementById('referrer-project-status')
    ].filter(Boolean);

    if (!statusTargets.length) {
        return;
    }

    if (window.currentProjectName) {
        const facilityCount = window.formData?.facilities?.length || 0;
        const baseMessage = `<strong>📂 Current Project:</strong> <span style="color: #ff9500;">${escapeHtmlForAttr(window.currentProjectName)}</span> (${facilityCount} facilities)`;

        statusTargets.forEach(target => {
            if (target.id === 'referrer-project-status') {
                target.innerHTML = `${baseMessage}<div style="margin-top: 6px; font-size: 13px; color: #4b5563;">Referrer profiles for this project are saved alongside the operator & facility data.</div>`;
            } else if (target.id === 'project-status-location') {
                target.innerHTML = `${baseMessage}<div style="margin-top: 6px; font-size: 13px; color: #4b5563;">Location-focused projects load the same dataset for geographic review.</div>`;
            } else {
                target.innerHTML = baseMessage;
            }
        });
    } else {
        statusTargets.forEach(target => {
            if (target.id === 'referrer-project-status') {
                target.innerHTML = '⚠️ No project loaded - referrer entries will be stored temporarily';
            } else if (target.id === 'project-status-location') {
                target.innerHTML = '⚠️ No project loaded - viewing temporary location data';
            } else {
                target.innerHTML = '⚠️ No project loaded - working with temporary data';
            }
        });
    }
}

function updateLabelsForProjectType() {
    const activeTab = document.querySelector('.category-tab.active');
    const category = activeTab ? activeTab.dataset.category : 'companies';

    // Define default and referrer-specific labels
    const labels = {
        toolbarTitle: { default: '📋 Project Editor', referrer: '📋 Referrer Editor' },
        operatorSectionTitle: { default: 'Operator Information', referrer: 'Group/Agency Information' },
        operatorNameLabel: { default: 'Operator Name', referrer: 'Group/Agency Name' },
        facilitiesOverviewTitle: { default: 'Facilities Overview', referrer: 'Individuals Overview' },
        addFacilityButton: { default: 'Add New Facility', referrer: 'Add New Individual' },
        addFacilityTOC: { default: 'Add New Facility', referrer: 'Add New Individual' },
        currentFacilityLabel: { default: 'Current Facility', referrer: 'Current Individual' },
        addFacilityToolbar: { default: '➕ Add Facility', referrer: '➕ Add Individual' },
        cloneFacilityToolbar: { default: '📋 Clone Facility', referrer: '📋 Clone Individual' },
        removeFacilityToolbar: { default: '🗑️ Remove Facility', referrer: '🗑️ Remove Individual' },
        facilityNameLabel: { default: 'Facility Name', referrer: 'Individual\'s Name' },
        facilityIdentificationTitle: { default: 'Identification & Names', referrer: 'Individual Identification' },
        facilityDetailsTitle: { default: 'Facility Details', referrer: 'Individual Details' },
        facilityOperationsTitle: { default: 'Facility Operations', referrer: 'Individual\'s Operations' },
        cloneModalTitle: { default: 'Clone Facility', referrer: 'Clone Individual' },
        cloneModalButton: { default: 'Clone Facility', referrer: 'Clone Individual' }
    };

    const setLabel = (elementId, text) => {
        const el = document.getElementById(elementId);
        if (el) {
            // Preserve icons if they exist
            const icon = el.querySelector('span[aria-hidden="true"], i');
            if (icon) {
                el.innerHTML = `${icon.outerHTML} ${text}`;
            } else {
                el.textContent = text;
            }
        }
    };

    const setLabelForQuery = (selector, text) => {
        const el = document.querySelector(selector);
        if (el) el.textContent = text;
    };
    const mode = (category === 'referrers') ? 'referrer' : 'default';

    // Update main section titles
    setLabelForQuery('#operator-section .section-title', labels.operatorSectionTitle[mode]);
    setLabelForQuery('#operator-name + .field-note-btn + .field-notes + label', labels.operatorNameLabel[mode]); // This is tricky, might need better selector
    setLabelForQuery('label[for="operator-name"]', labels.operatorNameLabel[mode]);

    // Update TOC and Facility Controls
    setLabelForQuery('.facility-toc .toc-title', labels.facilitiesOverviewTitle[mode]);
    setLabel('add-facility-main-btn', labels.addFacilityTOC[mode]);
    setLabelForQuery('.facility-controls strong', `${labels.currentFacilityLabel[mode]}: `);

    // Update Toolbar
    setLabelForQuery('.toolbar-title strong', labels.toolbarTitle[mode]);
    setLabel('toolbar-current-item-label', `${labels.currentFacilityLabel[mode]}:`);
    setLabel('add-facility-btn-toolbar', labels.addFacilityToolbar[mode]);
    setLabel('clone-facility-btn-toolbar', labels.cloneFacilityToolbar[mode]);
    setLabel('remove-facility-btn-toolbar', labels.removeFacilityToolbar[mode]);

    // Update Facility-specific sections
    setLabelForQuery('#identification-section .section-title', labels.facilityIdentificationTitle[mode]);
    setLabelForQuery('label[for="facility-name"]', labels.facilityNameLabel[mode]);

    // Update Clone Modal
    setLabelForQuery('#clone-facility-modal .modal-title', labels.cloneModalTitle[mode]);
    setLabel('clone-modal-confirm', labels.cloneModalButton[mode]);

}
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

            Object.keys(importedProjects).forEach(key => {
                window.projects[key] = importedProjects[key];
            });

            const firstProject = Object.keys(importedProjects)[0];
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

function generateProjectsReport({ categories = null, filename } = {}) {
    // Show report for projects, filtered by category if specified
    let projectsToReport;

    if (categories && categories.length > 0) {
        // Filter projects by category
        const filtered = buildProjectExport(categories);
        projectsToReport = Object.values(filtered);

        if (projectsToReport.length === 0) {
            const categoryLabel = categories.join(', ');
            showUploadStatus(`No ${categoryLabel} projects available to generate report.`, 'error');
            return;
        }
    } else {
        // Use current project's formData
        const data = window.formData;

        if (!data) {
            showUploadStatus('No project data available. Please load or create a project first.', 'error');
            return;
        }

        projectsToReport = [data];
    }

    // Create or get existing report modal
    let modal = document.getElementById('report-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'report-modal';
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 900px; width: 95%;">
                <div class="modal-header">
                    <h2 class="modal-title">Project Report</h2>
                    <button class="modal-close" id="report-modal-close">&times;</button>
                </div>
                <div class="modal-body" id="report-modal-body" style="max-height: 70vh; overflow-y: auto;">
                </div>
                <div class="modal-footer">
                    <button class="modal-btn modal-btn-secondary" id="report-modal-print">🖨️ Print</button>
                    <button class="modal-btn modal-btn-primary" id="report-modal-close-btn">Close</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        // Event listeners for modal controls
        document.getElementById('report-modal-close').addEventListener('click', () => {
            modal.classList.remove('active');
        });
        document.getElementById('report-modal-close-btn').addEventListener('click', () => {
            modal.classList.remove('active');
        });
        document.getElementById('report-modal-print').addEventListener('click', () => {
            const printContent = document.getElementById('report-modal-body').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${window.currentProjectName || 'Project Report'}</title>
                    <style>
                        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #1f2937; padding: 20px; }
                        .report-header { border-bottom: 3px solid #33A7B5; padding-bottom: 15px; margin-bottom: 20px; }
                        .report-title { font-size: 28px; color: #33A7B5; margin: 0 0 5px 0; }
                        .report-meta { color: #6b7280; font-size: 14px; }
                        .report-section { margin-bottom: 25px; page-break-inside: avoid; }
                        .section-title { font-size: 20px; color: #33A7B5; border-bottom: 2px solid #e5e7eb; padding-bottom: 8px; margin-bottom: 15px; }
                        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 15px; }
                        .info-item { display: flex; gap: 8px; }
                        .info-label { font-weight: 600; color: #374151; min-width: 120px; }
                        .info-value { color: #1f2937; }
                        .facility-card { background: #f9fafb; border-left: 4px solid #33A7B5; padding: 15px; margin-bottom: 20px; page-break-inside: avoid; }
                        .facility-name { font-size: 18px; font-weight: 700; color: #1f2937; margin-bottom: 10px; }
                        .list-section { margin-top: 10px; }
                        .list-title { font-weight: 600; color: #374151; margin-bottom: 5px; }
                        .list-items { margin: 0; padding-left: 20px; }
                        .list-items li { padding: 3px 0; }
                        .subsection-title { font-size: 16px; color: #6b7280; font-weight: 600; margin: 15px 0 10px 0; }
                        @media print { body { padding: 0; } }
                    </style>
                </head>
                <body>${printContent}</body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        });
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    }

    // Generate report content
    const reportBody = document.getElementById('report-modal-body');

    if (projectsToReport.length === 1) {
        // Single project report
        reportBody.innerHTML = generateReportHTML(projectsToReport[0]);
    } else {
        // Multiple projects report
        const categoryLabel = categories ? categories.join(', ').toUpperCase() : 'ALL PROJECTS';
        let multiReportHTML = `
            <div class="report-header">
                <h1 class="report-title">${categoryLabel} Report</h1>
                <div class="report-meta">Generated: ${new Date().toLocaleDateString('en-US', {
                    year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'
                })} | Total Projects: ${projectsToReport.length}</div>
            </div>
        `;

        projectsToReport.forEach((project, index) => {
            multiReportHTML += `
                <div style="page-break-before: ${index > 0 ? 'always' : 'auto'}; margin-bottom: 40px; padding-bottom: 30px; border-bottom: 3px solid #e5e7eb;">
                    ${generateReportHTML(project, true)}
                </div>
            `;
        });

        reportBody.innerHTML = multiReportHTML;
    }

    // Update modal title
    const modalTitle = modal.querySelector('.modal-title');
    if (modalTitle) {
        if (categories && categories.length > 0) {
            const categoryLabel = categories.join(', ').toUpperCase();
            modalTitle.textContent = `${categoryLabel} Report (${projectsToReport.length} project${projectsToReport.length > 1 ? 's' : ''})`;
        } else {
            modalTitle.textContent = window.currentProjectName ? `Report: ${window.currentProjectName}` : 'Project Report';
        }
    }

    // Show modal
    modal.classList.add('active');
}

function generateReportHTML(data, skipHeader = false) {
    const escapeHtml = (text) => {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };

    const renderField = (label, value) => {
        if (!value || (typeof value === 'string' && !value.trim())) return '';
        return `<div class="info-item"><span class="info-label">${escapeHtml(label)}:</span><span class="info-value">${escapeHtml(value)}</span></div>`;
    };

    const renderList = (title, items) => {
        if (!items || !Array.isArray(items) || items.length === 0) return '';
        const filteredItems = items.filter(item => item && (typeof item === 'string' ? item.trim() : true));
        if (filteredItems.length === 0) return '';

        const listItems = filteredItems.map(item => {
            if (typeof item === 'object' && item.name) {
                return `<li>${item.role ? `<strong>${escapeHtml(item.role)}:</strong> ` : ''}${escapeHtml(item.name)}</li>`;
            }
            return `<li>${escapeHtml(String(item))}</li>`;
        }).join('');

        return `
            <div class="list-section">
                <div class="list-title">${escapeHtml(title)}</div>
                <ul class="list-items">${listItems}</ul>
            </div>
        `;
    };

    let html = '';

    if (!skipHeader) {
        html = `
            <div class="report-header">
                <h1 class="report-title">${escapeHtml(window.currentProjectName || 'Project Data Report')}</h1>
                <div class="report-meta">Generated: ${new Date().toLocaleDateString('en-US', {
                    year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'
                })}</div>
            </div>
        `;
    } else {
        // For multi-project reports, add a project identifier
        const projectName = data.operator?.name ||
                          data.operator?.currentName ||
                          data.referrerAgency?.organizationName ||
                          data.referrerAgency?.name ||
                          data.projectName ||
                          'Unnamed Project';
        html += `
            <div class="report-header" style="margin-bottom: 20px;">
                <h2 class="report-title" style="font-size: 24px;">${escapeHtml(projectName)}</h2>
            </div>
        `;
    }

    // Operator Section
    const op = data.operator;
    if (op && (op.name || op.currentName)) {
        html += `
            <div class="report-section">
                <h2 class="section-title">Operator Information</h2>
                <div class="info-grid">
                    ${renderField('Name', op.name)}
                    ${renderField('Current Name', op.currentName)}
                    ${renderField('Location', op.location)}
                    ${renderField('Headquarters', op.headquarters)}
                    ${renderField('Founded', op.founded)}
                    ${renderField('Operating Period', op.operatingPeriod)}
                    ${renderField('Status', op.status)}
                    ${op.keyStaff?.ceo ? renderField('CEO', op.keyStaff.ceo) : ''}
                </div>
                ${renderList('Other Names', op.otherNames)}
                ${renderList('Parent Companies', op.parentCompanies)}
                ${renderList('Websites', op.websites)}
                ${renderList('Founders', op.keyStaff?.founders)}
                ${renderList('Key Executives', op.keyStaff?.keyExecutives)}
                ${renderList('Investors', op.investors)}
                ${renderList('Notes', op.notes)}
            </div>
        `;
    }

    // Facilities Section
    if (data.facilities && data.facilities.length > 0) {
        const facilitiesHTML = data.facilities.map((facility, index) => {
            const id = facility.identification || {};
            const name = id.name || id.currentName || `Facility ${index + 1}`;
            const details = facility.facilityDetails || {};
            const period = facility.operatingPeriod || {};
            const staff = facility.staff || {};
            const acc = facility.accreditations || {};

            return `
                <div class="facility-card">
                    <h3 class="facility-name">${escapeHtml(name)}</h3>
                    
                    <div class="info-grid">
                        ${renderField('Current Name', id.currentName)}
                        ${renderField('Current Operator', id.currentOperator)}
                        ${renderField('Location', facility.location)}
                        ${renderField('Address', facility.address)}
                        ${renderField('Type', details.type)}
                        ${renderField('Capacity', details.capacity)}
                        ${renderField('Current Census', details.currentCensus)}
                        ${details.ageRange?.min || details.ageRange?.max ? renderField('Age Range', `${details.ageRange.min || '?'} - ${details.ageRange.max || '?'}`) : ''}
                        ${renderField('Gender', details.gender)}
                        ${renderField('Opened', period.startYear)}
                        ${renderField('Closed', period.endYear)}
                        ${renderField('Status', period.status)}
                    </div>
                    
                    ${renderList('Other Names', id.otherNames)}
                    ${renderList('Other Operators', facility.otherOperators)}
                    ${renderList('Administrators', staff.administrator)}
                    ${renderList('Notable Staff', staff.notableStaff)}
                    ${renderList('Current Accreditations', acc.current)}
                    ${renderList('Past Accreditations', acc.past)}
                    ${renderList('Memberships', facility.memberships)}
                    ${renderList('Certifications', facility.certifications)}
                    ${renderList('Licensing', facility.licensing)}
                    ${renderList('Notes', period.notes)}
                </div>
            `;
        }).join('');

        html += `
            <div class="report-section">
                <h2 class="section-title">Facilities (${data.facilities.length})</h2>
                ${facilitiesHTML}
            </div>
        `;
    } else {
        html += `
            <div class="report-section">
                <h2 class="section-title">Facilities</h2>
                <p style="color: #6b7280; font-style: italic;">No facilities data available.</p>
            </div>
        `;
    }

    // Referrer Section (if present)
    if (data.referrerAgency && (data.referrerAgency.name || data.referrerAgency.organizationName)) {
        const ref = data.referrerAgency;
        html += `
            <div class="report-section">
                <h2 class="section-title">Referrer Information</h2>
                <div class="info-grid">
                    ${renderField('Organization Name', ref.organizationName || ref.name)}
                    ${renderField('Type', ref.type)}
                    ${renderField('Location', ref.location)}
                    ${renderField('Contact', ref.contact)}
                    ${renderField('Website', ref.website)}
                </div>
                ${renderList('Facilities Referred To', ref.facilitiesReferred)}
                ${renderList('Key Personnel', ref.keyPersonnel)}
                ${renderList('Notes', ref.notes)}
            </div>
        `;
    }

    // Individual Consultants (if present)
    if (data.referrerConsultants && data.referrerConsultants.length > 0) {
        const validConsultants = data.referrerConsultants.filter(c => c && c.name);
        if (validConsultants.length > 0) {
            const consultantsHTML = validConsultants.map((consultant, index) => `
                <div class="facility-card">
                    <h3 class="facility-name">${escapeHtml(consultant.name || `Consultant ${index + 1}`)}</h3>
                    <div class="info-grid">
                        ${renderField('Title', consultant.title)}
                        ${renderField('Location', consultant.location)}
                        ${renderField('Contact', consultant.contact)}
                        ${renderField('Website', consultant.website)}
                    </div>
                    ${renderList('Affiliations', consultant.affiliations)}
                    ${renderList('Facilities Referred To', consultant.facilitiesReferred)}
                    ${renderList('School Districts', consultant.schoolDistricts)}
                </div>
            `).join('');

            html += `
                <div class="report-section">
                    <h2 class="section-title">Independent Consultants (${validConsultants.length})</h2>
                    ${consultantsHTML}
                </div>
            `;
        }
    }

    return html;
}

// ============================================
// EVENT LISTENER ATTACHMENT
// ============================================
function attachFieldListeners() {
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

window.handleReferrerTypeToggle = function(type) {
    ensureReferrerDataStructures();
    const normalized = type === 'individual' ? 'individual' : 'group';
    window.formData.referrerType = normalized;

    if (typeof window.applyReferrerToggleState === 'function') {
        window.applyReferrerToggleState(normalized === 'individual');
    }

    updateJSON();
    autoSave();
};

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
    initializeToolbarButtons();

    // Project management
    const saveBtn = document.getElementById('save-project-btn');
    if (saveBtn && !saveBtn.dataset.listenerAttached) {
        saveBtn.onclick = () => {
            const projectName = document.getElementById('project-name')?.value?.trim();
            if (projectName) {
                saveProjectToCloud(projectName);
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
        newBtn.onclick = newProject;
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
        newBtnLocation.onclick = newProject;
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

    const newReferrerBtn = document.getElementById('new-referrer-project-btn');
    if (newReferrerBtn && !newReferrerBtn.dataset.listenerAttached) {
        newReferrerBtn.onclick = newProject;
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
        saveReferrerBtn.onclick = () => {
            const projectName = document.getElementById('referrer-project-name')?.value?.trim();
            if (projectName) {
                saveProjectToCloud(projectName);
            } else {
                alert('Please enter a project name');
                const projectNameInput = document.getElementById('referrer-project-name');
                if (projectNameInput) projectNameInput.focus();
            }
        };
        saveReferrerBtn.dataset.listenerAttached = 'true';
    }

    // Toolbar navigation buttons
    const prevBtnToolbar = document.getElementById('prev-facility-btn-toolbar');
    if (prevBtnToolbar && !prevBtnToolbar.dataset.listenerAttached) {
        prevBtnToolbar.addEventListener('click', () => {
            const dropdown = document.getElementById('facility-dropdown');
            if (dropdown && dropdown.selectedIndex > 0) {
                dropdown.selectedIndex--;
                dropdown.dispatchEvent(new Event('change'));
            }
        });
        prevBtnToolbar.dataset.listenerAttached = 'true';
    }

    const nextBtnToolbar = document.getElementById('next-facility-btn-toolbar');
    if (nextBtnToolbar && !nextBtnToolbar.dataset.listenerAttached) {
        nextBtnToolbar.addEventListener('click', () => {
            const dropdown = document.getElementById('facility-dropdown');
            if (dropdown && dropdown.selectedIndex < dropdown.options.length - 1) {
                dropdown.selectedIndex++;
                dropdown.dispatchEvent(new Event('change'));
            }
        });
        nextBtnToolbar.dataset.listenerAttached = 'true';
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
}

// ============================================
// INITIALIZATION
// ============================================
async function initializeForm() {
    debugLog('Initializing consolidated form with cloud-first storage...');
    logActiveFacilityFormConfigOnce();

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
                'window.KOP_FACILITY_FORM_CONFIG': window.KOP_FACILITY_FORM_CONFIG
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
        } catch (error) {
            console.error('❌ Error loading projects:', error);
            showUploadStatus('Error loading projects: ' + error.message, 'error');
            // Ensure projects object exists even if loading failed
            window.projects = window.projects || {};
        }
    } else {
        console.error('❌ loadAllProjectsFromCloud not available');
        // Ensure projects object exists
        window.projects = window.projects || {};
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
    initializeAutocompleteFields();

    initializeCategoryTabs();
    // Update all UI
    window.updateAllUI();

    // Initialize field notes functionality
    try {
        initializeFieldNotes();
    } catch (error) {
        console.error('Error initializing field notes:', error);
    }

    // Initialize fixed toolbar (for data.html)
    console.log('📍 About to call initializeFixedToolbar...');
    try {
        initializeFixedToolbar();
    } catch (error) {
        console.error('❌ Error initializing fixed toolbar:', error);
    }
    console.log('📍 Finished calling initializeFixedToolbar');

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
window.saveProjectToCloud = saveProjectToCloud;
window.persistProjectLocally = persistProjectLocally;
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

// Expose project loading function for rebuild operations
window.loadProjectsFromServer = loadAllProjectsFromCloud;

// Create projectManager object for backwards compatibility
window.projectManager = {
    loadProject: loadProject,
    newProject: newProject,
    saveProjectToCloud: saveProjectToCloud
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
console.log('[Facility Form] Script started executing');
console.log('[Facility Form] Checking loader:', {
    'KOP_FormLoader exists': typeof window.KOP_FormLoader !== 'undefined',
    'KOP_LOADER_READY': window.KOP_LOADER_READY,
    'KOP_FACILITY_FORM_CONFIG': window.KOP_FACILITY_FORM_CONFIG
});

if (document.readyState === 'loading') {
    console.log('[Facility Form] DOM still loading, waiting for DOMContentLoaded');
    document.addEventListener('DOMContentLoaded', initializeForm);
} else {
    console.log('[Facility Form] DOM already loaded, calling initializeForm immediately');
    initializeForm();
}

// ============================================
// FIXED TOOLBAR INITIALIZATION (for data.html)
// ============================================
function initializeFixedToolbar() {
    const toolbar = document.getElementById('fixed-toolbar');
    if (!toolbar) {
        debugLog('⚠️ Fixed toolbar element not found, skipping initialization.');
        return;
    }
    debugLog('🔧 initializeFixedToolbar() called, delegating to initializeToolbarButtons()');
    // The initializeToolbarButtons function already contains the correct logic.
    initializeToolbarButtons();
}

// Make function globally accessible
window.initializeFixedToolbar = initializeFixedToolbar;

// BACKUP: Try to initialize toolbar immediately when DOM is ready
console.log('⚡ Setting up toolbar initialization backups...');
document.addEventListener('DOMContentLoaded', () => {
    debugLog('⚡ DOMContentLoaded - attempting toolbar init');
    // This is now redundant with the main initializeForm call, but safe.
    initializeFixedToolbar();
}, { once: true });

// ============================================
// FACILITY TOOLBAR TOGGLE
// ============================================
function initializeFacilityToolbarToggle() {
    const toggleBtn = document.getElementById('facility-toolbar-toggle');
    const expandable = document.getElementById('facility-toolbar-expandable');

    if (!toggleBtn || !expandable) return;

    // Start collapsed by default to save space
    let isCollapsed = true;
    expandable.style.display = 'none';
    toggleBtn.textContent = '🔎';
    toggleBtn.title = 'Expand toolbar';

    // Remove any old listeners by cloning
    const newToggleBtn = toggleBtn.cloneNode(true);
    toggleBtn.parentNode.replaceChild(newToggleBtn, toggleBtn);

    newToggleBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();

        isCollapsed = !isCollapsed;
        expandable.style.display = isCollapsed ? 'none' : 'block';
        newToggleBtn.textContent = isCollapsed ? '🔎' : '🔽';
        newToggleBtn.title = isCollapsed ? 'Expand toolbar' : 'Minimize toolbar';
    }, { passive: false });

    debugLog('✅ Facility toolbar toggle initialized (collapsed by default)');
}

window.initializeFacilityToolbarToggle = initializeFacilityToolbarToggle;

// Fallback: sometimes remote resources or slow loads cause UI bits to render incorrectly.
// Re-run lightweight initialization checks on window.load to recover from intermittent failures.
window.addEventListener('load', () => {
    debugLog('facility-form.v3.js: window.load fired — verifying form initialization');

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
                debugLog('✅ facility-form.v3.js: updateAllUI re-run on load (once)');
            } else {
                debugLog('⚠️ Skipping updateAllUI on load - formData not ready');
            }
        } catch (e) {
            console.error('❌ facility-form.v3.js: error during load-time UI verification', e);
        }
    }, 150);
}, { passive: true });

// ============================================
// COMPREHENSIVE DIAGNOSTIC & TEST SUITE
// ============================================

/**
 * Run comprehensive data loading diagnostics
 * Use in browser console: window.runDiagnostics()
 */
window.runDiagnostics = function() {
    console.clear();
    console.log('🔍 ========== FORM LOADING DIAGNOSTICS ==========');
    
    const issues = [];
    
    // Test 1: API Endpoints
    console.log('\n1️⃣  API ENDPOINTS:');
    console.log('  LOAD_PROJECTS:', API_ENDPOINTS.LOAD_PROJECTS);
    console.log('  SAVE_PROJECT:', API_ENDPOINTS.SAVE_PROJECT);
    console.log('  AUTOCOMPLETE:', API_ENDPOINTS.AUTOCOMPLETE);
    
    // Test 2: Projects loaded
    console.log('\n2️⃣  PROJECTS LOADED:');
    console.log('  Total projects:', Object.keys(window.projects || {}).length);
    if (Object.keys(window.projects || {}).length === 0) {
        issues.push('❌ NO PROJECTS LOADED - Check API_ENDPOINTS.LOAD_PROJECTS');
    } else {
        Object.keys(window.projects).slice(0, 5).forEach(name => {
            console.log(`    - "${name}" (category: ${window.projects[name].category || 'MISSING'})`);
        });
    }
    
    // Test 3: Form data structure
    console.log('\n3️⃣  FORM DATA STRUCTURE:');
    if (!window.formData) {
        issues.push('❌ formData is NULL - Should be initialized in initializeForm()');
    } else {
        console.log('  ✅ formData exists');
        console.log('    - operator:', !!window.formData.operator);
        console.log('    - referrerAgency:', !!window.formData.referrerAgency);
        console.log('    - referrerConsultants:', Array.isArray(window.formData.referrerConsultants) ? window.formData.referrerConsultants.length : 'NOT ARRAY');
        console.log('    - facilities:', Array.isArray(window.formData.facilities) ? window.formData.facilities.length : 'NOT ARRAY');
    }
    
    // Test 4: Consultant data
    console.log('\n4️⃣  CONSULTANT DATA (referrerConsultants):');
    if (!window.formData || !Array.isArray(window.formData.referrerConsultants)) {
        issues.push('❌ referrerConsultants is not an array');
    } else if (window.formData.referrerConsultants.length === 0) {
        console.log('  ⚠️  No consultants loaded');
    } else {
        window.formData.referrerConsultants.forEach((c, i) => {
            const keys = Object.keys(c);
            console.log(`  Consultant ${i}:`);
            console.log(`    - name: "${c.firstName} ${c.lastName}" (fullName: "${c.fullName}")`);
            console.log(`    - location: ${c.city}, ${c.state}`);
            console.log(`    - keys: ${keys.join(', ')}`);
            
            // Check for expected keys
            const expectedKeys = ['firstName', 'lastName', 'fullName', 'email', 'phone', 'city', 'state'];
            const missingKeys = expectedKeys.filter(k => !keys.includes(k));
            if (missingKeys.length > 0) {
                issues.push(`⚠️  Consultant ${i} missing keys: ${missingKeys.join(', ')}`);
            }
        });
    }
    
    // Test 5: Form field mapping
    console.log('\n5️⃣  FORM FIELD MAPPING:');
    const testFieldIds = [
        'consultant-firstname',
        'consultant-lastname',
        'consultant-city',
        'consultant-state',
        'consultant-email',
        'consultant-phone',
        'consultant-credentials'
    ];
    
    testFieldIds.forEach(id => {
        const el = document.getElementById(id);
        if (!el) {
            issues.push(`❌ Field not found in DOM: #${id}`);
        } else {
            console.log(`  ✅ #${id} exists (value: "${el.value}")`);
        }
    });
    
    // Test 6: Autocomplete initialization
    console.log('\n6️⃣  AUTOCOMPLETE INITIALIZATION:');
    const autocompleteFields = document.querySelectorAll('[data-autocomplete-category]');
    console.log(`  Total autocomplete fields: ${autocompleteFields.length}`);
    autocompleteFields.forEach(field => {
        const cat = field.dataset.autocompleteCategory;
        const initialized = field.dataset.autocompleteInit === 'true';
        console.log(`    - #${field.id || field.name} (category: ${cat}, initialized: ${initialized})`);
    });
    
    // Test 7: Category tabs
    console.log('\n7️⃣  CATEGORY TABS:');
    const tabs = document.querySelectorAll('.category-tab');
    console.log(`  Total tabs: ${tabs.length}`);
    tabs.forEach(tab => {
        const active = tab.classList.contains('active') ? '✅' : '  ';
        console.log(`  ${active} [${tab.dataset.category}]`);
    });
    
    // Test 8: Current state
    console.log('\n8️⃣  CURRENT STATE:');
    console.log('  currentProjectName:', window.currentProjectName || 'NONE');
    console.log('  currentConsultantIndex:', window.currentConsultantIndex || 0);
    console.log('  currentFacilityIndex:', window.currentFacilityIndex || 0);
    console.log('  formReady:', window.formReady || false);
    
    // Summary
    console.log('\n' + '='.repeat(45));
    if (issues.length === 0) {
        console.log('✅ ALL TESTS PASSED - Form should load correctly');
    } else {
        console.log(`❌ FOUND ${issues.length} ISSUE(S):`);
        issues.forEach((issue, i) => {
            console.log(`  ${i + 1}. ${issue}`);
        });
    }
    console.log('='.repeat(45));
    
    return { passed: issues.length === 0, issues };
};

/**
 * Test specific consultant loading
 */
window.testConsultantLoad = function(projectName, consultantIndex = 0) {
    console.log(`\n🧪 Testing consultant load for "${projectName}" [index: ${consultantIndex}]`);
    
    if (!window.projects[projectName]) {
        console.error(`❌ Project not found: ${projectName}`);
        return false;
    }
    
    const project = window.projects[projectName];
    console.log('  Project category:', project.category);
    console.log('  Has referrerConsultants:', Array.isArray(project.data?.referrerConsultants));
    
    if (!Array.isArray(project.data?.referrerConsultants)) {
        console.error('❌ Project has no referrerConsultants array');
        return false;
    }
    
    const consultant = project.data.referrerConsultants[consultantIndex];
    if (!consultant) {
        console.error(`❌ Consultant not found at index ${consultantIndex}`);
        return false;
    }
    
    console.log('✅ Consultant found:');
    console.log('  Keys:', Object.keys(consultant));
    console.log('  firstName:', consultant.firstName);
    console.log('  lastName:', consultant.lastName);
    console.log('  fullName:', consultant.fullName);
    console.log('  email:', consultant.email);
    console.log('  city:', consultant.city);
    console.log('  state:', consultant.state);
    
    // Now load it
    window.currentProjectName = projectName;
    window.currentConsultantIndex = consultantIndex;
    window.formData = window.projects[projectName].data;
    
    if (typeof loadConsultantData === 'function') {
        loadConsultantData();
        console.log('✅ loadConsultantData() called');
    }
    
    if (typeof updateConsultantsUI === 'function') {
        updateConsultantsUI();
        console.log('✅ updateConsultantsUI() called');
    }
    
    return true;
};

/**
 * List all projects with their types
 */
window.listAllProjects = function() {
    console.log('\n📋 ALL PROJECTS:');
    const categories = {};
    
    Object.keys(window.projects || {}).forEach(name => {
        const cat = window.projects[name].category || 'UNKNOWN';
        if (!categories[cat]) categories[cat] = [];
        categories[cat].push(name);
    });
    
    Object.keys(categories).forEach(cat => {
        console.log(`\n${cat.toUpperCase()} (${categories[cat].length}):`);
        categories[cat].forEach(name => {
            console.log(`  - ${name}`);
        });
    });
};
