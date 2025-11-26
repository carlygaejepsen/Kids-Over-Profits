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
        AUTOCOMPLETE: autocomplete
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

const defaultApiPaths = {
    SAVE_MASTER: DEFAULT_SAVE_MASTER,
    SAVE_SUGGESTION: DEFAULT_SAVE_SUGGESTION,
    LOAD_PROJECTS:
        FACILITY_FORM_CONFIG.endpoints?.LOAD_PROJECTS ||
        getResolverEndpoint('get-master-data.php', '/wp-content/themes/child/api/get-master-data.php'),
    AUTOCOMPLETE:
        FACILITY_FORM_CONFIG.endpoints?.AUTOCOMPLETE ||
        FACILITY_FORM_CONFIG.endpoints?.SUGGESTIONS ||
        getResolverEndpoint('get-autocomplete.php', '/wp-content/themes/child/api/get-autocomplete.php')
};

const API_ENDPOINTS = Object.keys(defaultApiPaths).reduce((acc, key) => {
    acc[key] = resolveApiUrl(defaultApiPaths[key], normalizedApiBases);
    return acc;
}, {});

API_ENDPOINT_FALLBACKS = {
    SAVE_MASTER: API_ENDPOINTS.SAVE_MASTER || API_ENDPOINTS.SAVE_PROJECT,
    SAVE_SUGGESTION: API_ENDPOINTS.SAVE_SUGGESTION || API_ENDPOINTS.SAVE_MASTER || API_ENDPOINTS.SAVE_PROJECT,
    LOAD_PROJECTS: API_ENDPOINTS.LOAD_PROJECTS,
    AUTOCOMPLETE: API_ENDPOINTS.AUTOCOMPLETE
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

function debugLog(...args) {
    if (!DEBUG_LOGGING_ENABLED || typeof console === 'undefined') {
        return;
    }

    const logFn = typeof console.debug === 'function' ? console.debug.bind(console) : console.log.bind(console);
    try {
        logFn(...args);
    } catch (debugLogError) {
        // Never let debug logging break runtime execution
    }
}

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

const DEFAULT_FACILITY_TYPES = [
    'Residential Treatment Center (RTC)',
    'Therapeutic Boarding School',
    'Wilderness Therapy Program',
    'Long-term Wilderness Program',
    'Boot Camp',
    'Behavior Modification Program',
    'Therapeutic Group Home',
    'Specialty Boarding School',
    'Psychiatric Hospital',
    'Juvenile Detention Center',
    'Adventure Therapy Program',
    'Emotional Growth Boarding School',
    'Ranch Program',
    'Military-Style Academy',
    'Fundamentalist Religious Program',
    'Qualified Residential Treatment Program (QRTP)',
    'Other'
];

const DEFAULT_OPERATORS = [
    'Sequel Youth & Family Services',
    'Vivant Behavioral Health',
    'The Brown Schools',
    'CEDU',
    'Rite of Passage',
    'TrueCore Behavioral Services',
    'Correctional Services Corporation',
    'Youth Services International',
    'Youth Opportunity Investments',
    'Sequel TSI',
    'Three Springs Inc.',
    'Universal Health Services',
    'Wayne Halfway House',
    'Embark Behavioral Health',
    'Acadia Healthcare',
    'CRC Health Group',
    'Altior Healthcare',
    'Aspen Education Group',
    'Eckerd Connects',
    'Family Help & Wellness'
];

const DEFAULT_STAFF_ROLES = [
    'Administrator',
    'Director',
    'CEO',
    'President',
    'Counselor',
    'Therapist',
    'Teacher',
    'Nurse',
    'Medical Director',
    'Case Manager',
    'Supervisor',
    'Staff',
    'Founder',
    'Key Executive',
    'Board Member',
    'Program Director',
    'Clinical Director',
    'Admissions Director',
    'Other'
];

// ============================================
// GLOBAL STATE
// ============================================
let projects = {};
let isUpdatingUI = false; // Flag to prevent autoSave during programmatic UI updates
let isSaveInProgress = false; // Flag to prevent overlapping saves
let currentProjectName = null;
let currentFacilityIndex = 0;
let formData = null;

// Custom data from localStorage (backup only)
let customOperators = [];
let customFacilityNames = [];
let customHumanNames = [];
let customReferrers = [];
let customFacilityTypes = [];
let customCertifications = [];
let customAccreditations = [];
let customMemberships = [];
let customLicensing = [];
let customInvestors = [];
let customStaffRoles = [];
let customStatuses = [];
let customGenders = [];
let customLocations = [];
let customOperatingPeriods = [];

// Cached aggregated values (built from all projects) to prevent repeated heavy recomputation.
const aggregatedDataCache = {
    operators: null,
    facilityNames: null,
    humanNames: null,
    referrers: null,
    facilityTypes: null,
    staffRoles: null,
    certifications: null,
    accreditations: null,
    memberships: null,
    locations: null,
    statuses: null,
    genders: null,
    operatingPeriods: null
};

const CACHE_CATEGORY_MAP = {
    operator: 'operators',
    facility: 'facilityNames',
    human: 'humanNames',
    referrer: 'referrers',
    type: 'facilityTypes',
    role: 'staffRoles',
    certification: 'certifications',
    accreditation: 'accreditations',
    membership: 'memberships',
    location: 'locations',
    status: 'statuses',
    gender: 'genders',
    operatingperiod: 'operatingPeriods'
};

// noteFieldRegistry is now managed by the notes module (notes.js)
// The notes module exports it globally as window.noteFieldRegistry

function invalidateAggregatedData(category = null) {
    if (!category) {
        Object.keys(aggregatedDataCache).forEach(key => {
            aggregatedDataCache[key] = null;
        });
        return;
    }

    const cacheKey = CACHE_CATEGORY_MAP[category];
    if (cacheKey) {
        aggregatedDataCache[cacheKey] = null;
    }
}

// Make globals available
window.projects = projects;
window.currentProjectName = currentProjectName;
window.currentFacilityIndex = currentFacilityIndex;
window.formData = formData;

// ============================================
// UTILITY FUNCTIONS
// ============================================
function escapeHtmlForAttr(s) {
    return String(s).replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function deepClone(obj) {
    if (obj === null || typeof obj !== 'object') return obj;
    if (obj instanceof Date) return new Date(obj.getTime());
    if (obj instanceof Array) return obj.map(item => deepClone(item));
    if (obj instanceof Object) {
        const clonedObj = {};
        for (const key in obj) {
            if (obj.hasOwnProperty(key)) {
                clonedObj[key] = deepClone(obj[key]);
            }
        }
        return clonedObj;
    }
}

function getNestedValue(obj, path) {
    return path.split('.').reduce((current, key) => {
        if (!current) return undefined;

        // Try exact match first
        if (current[key] !== undefined) {
            return current[key];
        }

        // Try common variations
        const variations = [
            key,                                    // original key
            key.toLowerCase(),                      // lowercase
            key.toUpperCase(),                      // uppercase
            key.charAt(0).toUpperCase() + key.slice(1), // capitalize first letter
            key.charAt(0).toLowerCase() + key.slice(1), // lowercase first letter
            key.replace(/_/g, ''),                  // remove underscores
            key.replace(/-/g, ''),                  // remove hyphens
            toCamelCase(key),                       // camelCase version
            toSnakeCase(key),                       // snake_case version
        ];

        // Try each variation
        for (const variant of variations) {
            if (current[variant] !== undefined) {
                return current[variant];
            }
        }

        return undefined;
    }, obj);
}

// Helper function to convert to camelCase
function toCamelCase(str) {
    return str.replace(/[-_](.)/g, (_, c) => c.toUpperCase());
}

// Helper function to convert to snake_case
function toSnakeCase(str) {
    return str.replace(/([A-Z])/g, '_$1').toLowerCase().replace(/^_/, '');
}

// Normalize project data to handle different field name variations
function normalizeProjectData(data) {
    if (!data || typeof data !== 'object') return data;

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

            // Normalize facilityDetails
            if (normalized.facilityDetails) {
                normalized.facilityDetails = normalizeObject(normalized.facilityDetails, {
                    type: ['type', 'Type', 'facilityType', 'facility_type'],
                    capacity: ['capacity', 'Capacity'],
                    currentCensus: ['currentCensus', 'current_census', 'census'],
                    gender: ['gender', 'Gender'],
                    ageRange: ['ageRange', 'age_range', 'AgeRange']
                });
            }

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

    return data;
}

function setNestedValue(obj, path, value) {
    const keys = path.split('.');
    const lastKey = keys.pop();
    const target = keys.reduce((current, key) => {
        if (!current[key] || typeof current[key] !== 'object') {
            current[key] = {};
        }
        return current[key];
    }, obj);
    target[lastKey] = value;
}

function showUploadStatus(message, type) {
    const statusDiv = document.getElementById('upload-status') || document.getElementById('project-status');
    if (statusDiv) {
        statusDiv.style.display = 'block';
        statusDiv.textContent = message;
        statusDiv.className = `upload-status ${type}`;
        setTimeout(() => {
            statusDiv.style.display = 'none';
        }, 5000);
    }
}

function normalizeProjectData(data) {
    if (!data) return createNewProjectData();

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
        data.referrerAgency = createDefaultReferrerGroup();
    } else {
        data.referrerAgency = Object.assign(createDefaultReferrerGroup(), data.referrerAgency);
        if (!Array.isArray(data.referrerAgency.keyPersonnel)) {
            data.referrerAgency.keyPersonnel = [];
        }
        if (!data.referrerAgency.fieldNotes || typeof data.referrerAgency.fieldNotes !== 'object') {
            data.referrerAgency.fieldNotes = {};
        }
    }

    if (!Array.isArray(data.referrerConsultants) || data.referrerConsultants.length === 0) {
        data.referrerConsultants = [createDefaultReferrerIndividual()];
    } else {
        data.referrerConsultants = data.referrerConsultants.map(consultant => {
            const defaults = createDefaultReferrerIndividual();
            const merged = Object.assign(defaults, consultant || {});
            if (!Array.isArray(merged.affiliations)) merged.affiliations = [];
            if (!Array.isArray(merged.facilitiesReferred)) merged.facilitiesReferred = [];
            if (!Array.isArray(merged.schoolDistricts)) merged.schoolDistricts = [];
            if (!merged.fieldNotes || typeof merged.fieldNotes !== 'object') merged.fieldNotes = {};
            return merged;
        });
    }

    if (typeof data.isIndependentConsultant === 'undefined') {
        data.isIndependentConsultant = false;
    }

    if (!data.fieldNotes || typeof data.fieldNotes !== 'object') {
        data.fieldNotes = {};
    }

    data.referrer = buildReferrerEntries(data);

    return data;
}

// ============================================
// LOCALSTORAGE BACKUP FUNCTIONS
// ============================================
function loadCustomDataFromLocalStorage() {
    // Helper to deduplicate and clean arrays
    const dedupe = (arr) => [...new Set(arr.filter(v => v && v.trim()).map(v => v.trim()))];

    try {
        customOperators = dedupe(JSON.parse(localStorage.getItem('customOperators') || '[]'));
        customFacilityNames = dedupe(JSON.parse(localStorage.getItem('customFacilityNames') || '[]'));
        customHumanNames = dedupe(JSON.parse(localStorage.getItem('customHumanNames') || '[]'));
        customReferrers = dedupe(JSON.parse(localStorage.getItem('customReferrers') || '[]'));
        customFacilityTypes = dedupe(JSON.parse(localStorage.getItem('customFacilityTypes') || '[]'));
        customCertifications = dedupe(JSON.parse(localStorage.getItem('customCertifications') || '[]'));
        customAccreditations = dedupe(JSON.parse(localStorage.getItem('customAccreditations') || '[]'));
        customMemberships = dedupe(JSON.parse(localStorage.getItem('customMemberships') || '[]'));
        customLicensing = dedupe(JSON.parse(localStorage.getItem('customLicensing') || '[]'));
        customInvestors = dedupe(JSON.parse(localStorage.getItem('customInvestors') || '[]'));
        customStaffRoles = dedupe(JSON.parse(localStorage.getItem('customStaffRoles') || '[]'));
        customStatuses = dedupe(JSON.parse(localStorage.getItem('customStatuses') || '[]'));
        customGenders = dedupe(JSON.parse(localStorage.getItem('customGenders') || '[]'));
        customLocations = dedupe(JSON.parse(localStorage.getItem('customLocations') || '[]'));
        customOperatingPeriods = dedupe(JSON.parse(localStorage.getItem('customOperatingPeriods') || '[]'));
    } catch (e) {
        console.warn('Failed to load custom data from localStorage:', e);
    }

    // Save the cleaned, deduplicated values back to localStorage
    try {
        localStorage.setItem('customOperators', JSON.stringify(customOperators));
        localStorage.setItem('customFacilityNames', JSON.stringify(customFacilityNames));
        localStorage.setItem('customHumanNames', JSON.stringify(customHumanNames));
        localStorage.setItem('customReferrers', JSON.stringify(customReferrers));
        localStorage.setItem('customFacilityTypes', JSON.stringify(customFacilityTypes));
        localStorage.setItem('customCertifications', JSON.stringify(customCertifications));
        localStorage.setItem('customAccreditations', JSON.stringify(customAccreditations));
        localStorage.setItem('customMemberships', JSON.stringify(customMemberships));
        localStorage.setItem('customLicensing', JSON.stringify(customLicensing));
        localStorage.setItem('customInvestors', JSON.stringify(customInvestors));
        localStorage.setItem('customStaffRoles', JSON.stringify(customStaffRoles));
        localStorage.setItem('customStatuses', JSON.stringify(customStatuses));
        localStorage.setItem('customGenders', JSON.stringify(customGenders));
        localStorage.setItem('customLocations', JSON.stringify(customLocations));
        localStorage.setItem('customOperatingPeriods', JSON.stringify(customOperatingPeriods));
    } catch (e) {
        console.warn('Failed to save cleaned data to localStorage:', e);
    }

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
    const trimmedValue = value?.trim();
    if (!trimmedValue) return false;

    let array;
    let key;
    
    switch(category) {
        case 'operator':
            array = customOperators;
            key = 'customOperators';
            break;
        case 'facility':
            array = customFacilityNames;
            key = 'customFacilityNames';
            break;
        case 'human':
            array = customHumanNames;
            key = 'customHumanNames';
            break;
        case 'referrer':
            array = customReferrers;
            key = 'customReferrers';
            break;
        case 'type':
            array = customFacilityTypes;
            key = 'customFacilityTypes';
            break;
        case 'certification':
            array = customCertifications;
            key = 'customCertifications';
            break;
        case 'accreditation':
            array = customAccreditations;
            key = 'customAccreditations';
            break;
        case 'membership':
            array = customMemberships;
            key = 'customMemberships';
            break;
        case 'licensing':
            array = customLicensing;
            key = 'customLicensing';
            break;
        case 'investor':
            array = customInvestors;
            key = 'customInvestors';
            break;
        case 'role':
            array = customStaffRoles;
            key = 'customStaffRoles';
            break;
        case 'status':
            array = customStatuses;
            key = 'customStatuses';
            break;
        case 'gender':
            array = customGenders;
            key = 'customGenders';
            break;
        case 'location':
            array = customLocations;
            key = 'customLocations';
            break;
        case 'operatingperiod':
            array = customOperatingPeriods;
            key = 'customOperatingPeriods';
            break;
        default:
            return false;
    }
    
    if (!array.includes(trimmedValue)) {
        array.push(trimmedValue);
        saveToLocalStorage(key, array);
        invalidateAggregatedData(category);
        return true;
    }

    return false;
}

function attachCustomValueRecorder(input, category) {
    if (!input || !category) {
        return;
    }

    const recorderKey = 'customValueRecorder';
    if (input.dataset && input.dataset[recorderKey] === category) {
        return;
    }

    const recordValue = () => {
        const trimmed = input.value?.trim();
        if (!trimmed) {
            return;
        }

        if (input.dataset && input.dataset.customValueRecorderLast === trimmed) {
            return;
        }

        addCustomValue(category, trimmed);

        if (input.dataset) {
            input.dataset.customValueRecorderLast = trimmed;
        }
    };

    input.addEventListener('change', recordValue, { passive: true });
    input.addEventListener('blur', recordValue, { passive: true });

    if (input.dataset) {
        input.dataset[recorderKey] = category;
    }
}

// ============================================
// DATA AGGREGATION (across all projects)
// ============================================
function getAllOperators() {
    if (!aggregatedDataCache.operators) {
        const operators = new Set([...DEFAULT_OPERATORS, ...customOperators]);

        Object.values(projects).forEach(project => {
            if (project.data?.operator?.name) operators.add(project.data.operator.name);
            if (project.data?.operator?.currentName) operators.add(project.data.operator.currentName);
            if (project.data?.operator?.otherNames) {
                project.data.operator.otherNames.forEach(name => operators.add(name));
            }

            project.data?.facilities?.forEach(facility => {
                if (facility.identification?.currentOperator) {
                    operators.add(facility.identification.currentOperator);
                }
                facility.otherOperators?.forEach(op => operators.add(op));
            });
        });

        aggregatedDataCache.operators = Array.from(operators).filter(op => op && op.trim()).sort();
    }

    return aggregatedDataCache.operators;
}

function getAllFacilityNames() {
    if (!aggregatedDataCache.facilityNames) {
        const names = new Set(customFacilityNames);

        Object.values(projects).forEach(project => {
            project.data?.facilities?.forEach(facility => {
                if (facility.identification?.name) names.add(facility.identification.name);
                if (facility.identification?.currentName) names.add(facility.identification.currentName);
                facility.identification?.otherNames?.forEach(name => names.add(name));
            });
        });

        aggregatedDataCache.facilityNames = Array.from(names).filter(n => n && n.trim()).sort();
    }

    return aggregatedDataCache.facilityNames;
}

function getAllHumanNames() {
    if (!aggregatedDataCache.humanNames) {
        const names = new Set(customHumanNames);

        Object.values(projects).forEach(project => {
            // Operator staff
            if (project.data?.operator?.keyStaff) {
                const ks = project.data.operator.keyStaff;
                if (ks.ceo) names.add(ks.ceo);
                ks.founders?.forEach(f => {
                    const name = typeof f === 'string' ? f : f?.name;
                    if (name) names.add(name);
                });
                ks.keyExecutives?.forEach(e => {
                    const name = typeof e === 'string' ? e : e?.name;
                    if (name) names.add(name);
                });
            }

            // Facility staff
            project.data?.facilities?.forEach(facility => {
                facility.staff?.administrator?.forEach(admin => {
                    const name = typeof admin === 'string' ? admin : admin?.name;
                    if (name) names.add(name);
                });
                facility.staff?.notableStaff?.forEach(staff => {
                    const name = typeof staff === 'string' ? staff : staff?.name;
                    if (name) names.add(name);
                });
            });
        });

        aggregatedDataCache.humanNames = Array.from(names).filter(n => n && typeof n === 'string' && n.trim()).sort();
    }

    return aggregatedDataCache.humanNames;
}

function getAllReferrers() {
    if (!aggregatedDataCache.referrers) {
        const referrers = new Set(customReferrers || []);

        Object.values(projects || {}).forEach(project => {
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

        aggregatedDataCache.referrers = Array.from(referrers).filter(r => r && typeof r === 'string' && r.trim()).sort();
    }

    return aggregatedDataCache.referrers;
}

function getAllFacilityTypes() {
    if (!aggregatedDataCache.facilityTypes) {
        const types = new Set([...DEFAULT_FACILITY_TYPES, ...customFacilityTypes]);

        Object.values(projects).forEach(project => {
            project.data?.facilities?.forEach(facility => {
                if (facility.facilityDetails?.type) {
                    types.add(facility.facilityDetails.type);
                }
            });
        });

        aggregatedDataCache.facilityTypes = Array.from(types).filter(t => t && t.trim()).sort();
    }

    return aggregatedDataCache.facilityTypes;
}

function getAllStaffRoles() {
    if (!aggregatedDataCache.staffRoles) {
        const roles = new Set([...DEFAULT_STAFF_ROLES, ...customStaffRoles]);

        Object.values(projects).forEach(project => {
            project.data?.facilities?.forEach(facility => {
                facility.staff?.administrator?.forEach(admin => {
                    const role = typeof admin === 'string' ? '' : admin.role;
                    if (role) roles.add(role);
                });
                facility.staff?.notableStaff?.forEach(staff => {
                    const role = typeof staff === 'string' ? '' : staff.role;
                    if (role) roles.add(role);
                });
            });
        });

        aggregatedDataCache.staffRoles = Array.from(roles).filter(r => r && r.trim()).sort();
    }

    return aggregatedDataCache.staffRoles;
}

function getAllCertifications() {
    if (!aggregatedDataCache.certifications) {
        const certs = new Set(customCertifications);

        Object.values(projects).forEach(project => {
            project.data?.facilities?.forEach(facility => {
                facility.certifications?.forEach(cert => certs.add(cert));
            });
        });

        aggregatedDataCache.certifications = Array.from(certs).filter(c => c && c.trim()).sort();
    }

    return aggregatedDataCache.certifications;
}

function getAllAccreditations() {
    if (!aggregatedDataCache.accreditations) {
        const accreds = new Set(customAccreditations);

        Object.values(projects).forEach(project => {
            project.data?.facilities?.forEach(facility => {
                facility.accreditations?.current?.forEach(acc => accreds.add(acc));
                facility.accreditations?.past?.forEach(acc => accreds.add(acc));
            });
        });

        aggregatedDataCache.accreditations = Array.from(accreds).filter(a => a && a.trim()).sort();
    }

    return aggregatedDataCache.accreditations;
}

function getAllMemberships() {
    if (!aggregatedDataCache.memberships) {
        const memberships = new Set(customMemberships);

        Object.values(projects).forEach(project => {
            project.data?.facilities?.forEach(facility => {
                facility.memberships?.forEach(m => memberships.add(m));
            });
        });

        aggregatedDataCache.memberships = Array.from(memberships).filter(m => m && m.trim()).sort();
    }

    return aggregatedDataCache.memberships;
}

function getAllLocations() {
    if (!aggregatedDataCache.locations) {
        const locations = new Set(customLocations);

        Object.values(projects).forEach(project => {
            project.data?.facilities?.forEach(facility => {
                if (facility.location) locations.add(facility.location);
            });
        });

        aggregatedDataCache.locations = Array.from(locations).filter(l => l && l.trim()).sort();
    }

    return aggregatedDataCache.locations;
}

function getAllStatuses() {
    if (!aggregatedDataCache.statuses) {
        const statuses = new Set([...customStatuses, 'Active', 'Closed', 'Acquired', 'Merged', 'Defunct', 'Transferred', 'Open']);

        Object.values(projects).forEach(project => {
            if (project.data?.operator?.status) statuses.add(project.data.operator.status);
            project.data?.facilities?.forEach(facility => {
                if (facility.operatingPeriod?.status) statuses.add(facility.operatingPeriod.status);
            });
        });

        aggregatedDataCache.statuses = Array.from(statuses).filter(s => s && s.trim()).sort();
    }

    return aggregatedDataCache.statuses;
}

function getAllGenders() {
    if (!aggregatedDataCache.genders) {
        const genders = new Set([...customGenders, 'Male', 'Female', 'Co-ed', 'All Genders']);

        Object.values(projects).forEach(project => {
            project.data?.facilities?.forEach(facility => {
                if (facility.facilityDetails?.gender) genders.add(facility.facilityDetails.gender);
            });
        });

        aggregatedDataCache.genders = Array.from(genders).filter(g => g && g.trim()).sort();
    }

    return aggregatedDataCache.genders;
}

function getAllOperatingPeriods() {
    if (!aggregatedDataCache.operatingPeriods) {
        const periods = new Set(customOperatingPeriods);

        Object.values(projects).forEach(project => {
            if (project.data?.operator?.operatingPeriod) {
                periods.add(project.data.operator.operatingPeriod);
            }

            project.data?.facilities?.forEach(facility => {
                const years = facility.operatingPeriod?.yearsOfOperation;
                if (years) periods.add(years);
            });
        });

        aggregatedDataCache.operatingPeriods = Array.from(periods).filter(p => p && p.trim()).sort();
    }

    return aggregatedDataCache.operatingPeriods;
}

// ============================================
// AUTOCOMPLETE DROPDOWN SYSTEM (IMPROVED)
// ============================================
function createAutocomplete(input, getDataFunction, category) {
    // FIX #2: Prevent double-initialization
    if (input.dataset.autocompleteInit === 'true') {
        debugLog('✅ Autocomplete already initialized for', input.id || input.name);
        return;
    }

    // Disable browser's native autocomplete/datalist
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('autocorrect', 'off');
    input.setAttribute('autocapitalize', 'off');
    input.setAttribute('spellcheck', 'false');

    // Check if wrapper already exists
    let wrapper = input.closest('.autocomplete-wrapper');
    if (!wrapper) {
        wrapper = document.createElement('div');
        wrapper.className = 'autocomplete-wrapper';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);
    }

    // Check if dropdown already exists
    let dropdown = wrapper.querySelector('.autocomplete-dropdown');
    if (!dropdown) {
        dropdown = document.createElement('div');
        dropdown.className = 'autocomplete-dropdown';
        wrapper.appendChild(dropdown);
    }

    let currentFocus = -1;
    let abortController = null; // FIX #2: For cancelling pending requests
    let isCommittingSelection = false; // Flag to prevent re-showing dropdown after selection
    let preventBlur = false; // Flag to prevent blur from closing dropdown during selection

    function commitSelection(value, options = {}) {
        const { shouldRefocus = false } = options;
        if (typeof value !== 'string') {
            return;
        }

        // Set flag BEFORE any value changes to prevent input event from triggering
        isCommittingSelection = true;

        // Clear any pending autocomplete fetch to prevent dropdown from re-showing
        if (createAutocomplete._pendingFetch) {
            clearTimeout(createAutocomplete._pendingFetch);
            createAutocomplete._pendingFetch = null;
        }

        // Hide dropdown immediately
        hideDropdown();
        currentFocus = -1;

        // Now set the value
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));

        // Reset flag after a short delay
        setTimeout(() => {
            isCommittingSelection = false;
        }, 150);

        if (shouldRefocus) {
            // Use timeout to ensure dropdown has fully closed before refocusing
            setTimeout(() => input.focus(), 0);
        }
    }

    function renderSuggestionContent(target, suggestion, query) {
        const suggestionText = typeof suggestion === 'string'
            ? suggestion
            : (suggestion === null || suggestion === undefined ? '' : String(suggestion));
        const queryText = typeof query === 'string'
            ? query
            : (query === null || query === undefined ? '' : String(query));
        const normalizedQuery = queryText.toLowerCase();
        const normalizedSuggestion = suggestionText.toLowerCase();
        const matchIndex = normalizedSuggestion.indexOf(normalizedQuery);

        target.textContent = '';

        if (!normalizedQuery || matchIndex === -1) {
            target.textContent = suggestionText;
            return;
        }

        const before = suggestionText.substring(0, matchIndex);
        const match = suggestionText.substring(matchIndex, matchIndex + queryText.length);
        const after = suggestionText.substring(matchIndex + queryText.length);

        if (before) {
            target.appendChild(document.createTextNode(before));
        }

        const strong = document.createElement('strong');
        strong.textContent = match;
        target.appendChild(strong);

        if (after) {
            target.appendChild(document.createTextNode(after));
        }
    }

    function showDropdown(items) {
        dropdown.innerHTML = '';
        dropdown.style.display = 'block';
        dropdown.dataset.empty = items.length === 0 ? 'true' : 'false';

        if (items.length === 0) {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = 'autocomplete-item';
            emptyDiv.textContent = 'No matches found';
            emptyDiv.style.color = '#9ca3af';
            emptyDiv.dataset.placeholder = 'true';
            dropdown.appendChild(emptyDiv);
            return;
        }

        items.forEach((item) => {
            const div = document.createElement('div');
            div.className = 'autocomplete-item';
            const suggestionText = typeof item === 'string'
                ? item
                : (item === null || item === undefined ? '' : String(item));
            div.dataset.value = suggestionText;

            renderSuggestionContent(div, suggestionText, input.value);

            // Use mousedown to fire BEFORE blur event (which hides dropdown)
            div.addEventListener('mousedown', () => {
                preventBlur = true; // Prevent blur from hiding dropdown
                commitSelection(suggestionText, { shouldRefocus: false });
                setTimeout(() => { preventBlur = false; }, 100);
            });

            dropdown.appendChild(div);
        });
    }

    function hideDropdown() {
        dropdown.style.display = 'none';
        currentFocus = -1;
    }
    
    input.addEventListener('input', () => {
        // Skip if we're committing a selection (prevents dropdown from re-showing)
        if (isCommittingSelection) {
            return;
        }

        const value = input.value.trim();
        if (!value) {
            hideDropdown();
            return;
        }
        // Merge local items with remote suggestions (debounced)
        const localItems = (typeof getDataFunction === 'function') ? getDataFunction() : [];
        const localFiltered = localItems.filter(item => item.toLowerCase().includes(value.toLowerCase()));

        showDropdown(localFiltered);

        // FIX #2: Cancel any pending remote fetch
        if (abortController) {
            abortController.abort();
        }

        // Debounced remote fetch with improved error handling
        if (createAutocomplete._pendingFetch) clearTimeout(createAutocomplete._pendingFetch);
        createAutocomplete._pendingFetch = setTimeout(async () => {
            const q = encodeURIComponent(value);
            const params = `?category=${encodeURIComponent(category)}&q=${q}`;
            const API_ENDPOINTS = getAPIEndpoints();
            const remoteUrl = API_ENDPOINTS.AUTOCOMPLETE + params;

            try {
                // FIX #2: Create new AbortController for this request
                abortController = new AbortController();

                const resp = await fetch(remoteUrl, {
                    cache: 'no-store',
                    signal: abortController.signal
                });

                if (!resp.ok) {
                    console.warn(`⚠️ Autocomplete API returned ${resp.status} for category "${category}"`);
                    return; // Keep showing local items
                }

                const contentType = resp.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    console.warn(`⚠️ Autocomplete API returned non-JSON content-type: ${contentType}`);
                    return; // Keep showing local items
                }

                const json = await resp.json();
                if (json && json.success && Array.isArray(json.values)) {
                    const merged = Array.from(new Set([...localFiltered, ...json.values]));
                    showDropdown(merged);
                    debugLog(`✅ Autocomplete loaded ${json.values.length} remote suggestions for "${category}"`);
                } else {
                    console.warn('⚠️ Autocomplete API returned unexpected format:', json);
                }
            } catch (e) {
                if (e.name === 'AbortError') {
                    // Request was cancelled, ignore silently
                    return;
                }
                console.warn(`⚠️ Autocomplete fetch failed for category "${category}":`, e.message);
                // Keep showing local items on error
            }
        }, 300); // Increased from 220ms to 300ms for better performance
    }, { passive: true });

    input.addEventListener('focus', () => {
        // Only trigger autocomplete dropdown if not during a programmatic UI update
        if (input.value.trim() && !isUpdatingUI) {
            input.dispatchEvent(new Event('input'));
        }
    }, { passive: true });

    input.addEventListener('blur', () => {
        setTimeout(() => {
            if (!preventBlur) {
                hideDropdown();
            }
        }, 200);
    }, { passive: true });

    input.addEventListener('keydown', (e) => {
        const items = dropdown.querySelectorAll('.autocomplete-item');

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            currentFocus++;
            if (currentFocus >= items.length) currentFocus = 0;
            setActive(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            currentFocus--;
            if (currentFocus < 0) currentFocus = items.length - 1;
            setActive(items);
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            const actionableItems = Array.from(items).filter((item) => item.dataset && item.dataset.value);
            const hasVisibleOptions = dropdown.style.display !== 'none' && actionableItems.length > 0;
            const isTab = e.key === 'Tab';

            const maybePreventDefault = () => {
                if (!isTab) {
                    e.preventDefault();
                }
            };

            if (currentFocus > -1 && items[currentFocus] && items[currentFocus].dataset && items[currentFocus].dataset.value) {
                maybePreventDefault();
                commitSelection(items[currentFocus].dataset.value);
            } else if (hasVisibleOptions) {
                maybePreventDefault();
                commitSelection(actionableItems[0].dataset.value);
            } else if (input.value.trim() && category) {
                const trimmedValue = input.value.trim();
                addCustomValue(category, trimmedValue);
                maybePreventDefault();
                commitSelection(trimmedValue);
            } else if (!isTab) {
                e.preventDefault();
            }

            if (isTab && dropdown.style.display !== 'none') {
                hideDropdown();
            }
        } else if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            hideDropdown();
            isCommittingSelection = false; // Reset flag
            return; // Exit early - don't process further
        }
    }, { passive: false });
    
    function setActive(items) {
        items.forEach((item, index) => {
            if (index === currentFocus) {
                item.classList.add('active');
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('active');
            }
        });
    }

    // FIX #2: Mark as initialized to prevent double-initialization
    input.dataset.autocompleteInit = 'true';
    debugLog('✅ Autocomplete initialized for', category, 'on', input.id || input.name || 'unnamed input');
}

function initializeAutocompleteFields() {
    const categoryFunctions = {
        operator: getAllOperators,
        facility: getAllFacilityNames,
        human: getAllHumanNames,
        referrer: getAllReferrers,
        type: getAllFacilityTypes,
        status: getAllStatuses,
        gender: getAllGenders,
        location: getAllLocations,
        membership: getAllMemberships,
        accreditation: getAllAccreditations,
        certification: getAllCertifications,
        licensing: () => Array.from(customLicensing),
        investor: () => Array.from(customInvestors),
        role: getAllStaffRoles,
        operatingperiod: getAllOperatingPeriods
    };

    document.querySelectorAll('input[type="text"][data-autocomplete-category]:not(.array-input)').forEach(field => {
        if (field.dataset.autocompleteInit === 'true') {
            return;
        }

        const category = field.dataset.autocompleteCategory;
        const dataFunction = categoryFunctions[category];

        if (typeof dataFunction === 'function') {
            createAutocomplete(field, dataFunction, category);
        } else {
            console.warn('⚠️ No autocomplete data provider configured for category', category, field);
        }
    });
}

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

        // Initialize with existing expanded state
        setState(section.classList.contains('expanded'));

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
}

// ============================================
// FIELD NOTE CONTROLS
// ============================================
function ensureFieldNotesStore(scope, createIfMissing = true) {
    if (!window.formData) {
        return null;
    }

    if (scope === 'operator') {
        if (!window.formData.operator) {
            return null;
        }
        if (!window.formData.operator.fieldNotes) {
            if (!createIfMissing) {
                return null;
            }
            window.formData.operator.fieldNotes = {};
        }
        return window.formData.operator.fieldNotes;
    }

    if (scope === 'facility') {
        const facility = window.formData.facilities?.[window.currentFacilityIndex];
        if (!facility) {
            return null;
        }
        if (!facility.fieldNotes) {
            if (!createIfMissing) {
                return null;
            }
            facility.fieldNotes = {};
        }
        return facility.fieldNotes;
    }

    if (scope === 'referrerGroup') {
        ensureReferrerDataStructures();
        if (!window.formData.referrerGroup.fieldNotes) {
            if (!createIfMissing) {
                return null;
            }
            window.formData.referrerGroup.fieldNotes = {};
        }
        return window.formData.referrerGroup.fieldNotes;
    }

    if (scope === 'referrerIndividual') {
        ensureReferrerDataStructures();
        if (!window.formData.referrerIndividual.fieldNotes) {
            if (!createIfMissing) {
                return null;
            }
            window.formData.referrerIndividual.fieldNotes = {};
        }
        return window.formData.referrerIndividual.fieldNotes;
    }

    if (!window.formData.fieldNotes) {
        if (!createIfMissing) {
            return null;
        }
        window.formData.fieldNotes = {};
    }
    return window.formData.fieldNotes;
}

function getFieldNotes(scope, key, createIfMissing = false) {
    const store = ensureFieldNotesStore(scope, createIfMissing);
    if (!store) {
        return [];
    }

    if (!Object.prototype.hasOwnProperty.call(store, key)) {
        if (!createIfMissing) {
            return [];
        }
        store[key] = [];
    }

    const notes = store[key];
    if (!Array.isArray(notes)) {
        const normalized = [];
        if (notes !== null && notes !== undefined && `${notes}`.trim() !== '') {
            normalized.push(`${notes}`);
        }
        store[key] = normalized;
        return normalized;
    }

    return notes;
}

function addFieldNote(scope, key) {
    const notes = getFieldNotes(scope, key, true);
    notes.push('');
    updateJSON();
    autoSave();
    renderAllFieldNotes();
}

function updateFieldNote(scope, key, index, value) {
    const notes = getFieldNotes(scope, key, true);
    if (index >= 0 && index < notes.length) {
        notes[index] = value;
        updateJSON();
        autoSave();
    }
}

function removeFieldNote(scope, key, index) {
    const notes = getFieldNotes(scope, key, true);
    if (index >= 0 && index < notes.length) {
        notes.splice(index, 1);
        updateJSON();
        autoSave();
        renderAllFieldNotes();
    }
}

function renderFieldNotes(container, scope, key) {
    if (!container) {
        return;
    }

    const notes = getFieldNotes(scope, key, false);
    container.innerHTML = '';

    if (!notes.length) {
        return;
    }

    notes.forEach((note, index) => {
        const row = document.createElement('div');
        row.className = 'note-row';

        const textarea = document.createElement('textarea');
        textarea.className = 'note-textarea';
        textarea.placeholder = 'Add supporting notes...';
        textarea.rows = 3;
        textarea.value = note || '';
        textarea.addEventListener('input', () => {
            updateFieldNote(scope, key, index, textarea.value);
        }, { passive: true });

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'remove-note-btn';
        removeBtn.textContent = '−';
        removeBtn.addEventListener('click', () => {
            removeFieldNote(scope, key, index);
        }, { passive: true });

        row.appendChild(textarea);
        row.appendChild(removeBtn);
        container.appendChild(row);
    });
}

function renderAllFieldNotes() {
    if (!Array.isArray(window.noteFieldRegistry)) {
        return;
    }

    window.noteFieldRegistry.forEach(entry => {
        if (!entry) {
            return;
        }
        const { scope, key, container } = entry;
        renderFieldNotes(container, scope, key);
    });
}

function initializeNoteControls() {
    // Preserve existing array item entries (those with keys containing a dot followed by a number like "staff.administrator.0")
    const arrayItemEntries = (window.noteFieldRegistry || []).filter(entry => {
        if (!entry || !entry.key) return false;
        // Keep entries that look like array items (e.g., "path.0", "path.1", etc.)
        return /\.\d+$/.test(entry.key);
    });

    // Reset registry but keep array item entries
    window.noteFieldRegistry = [...arrayItemEntries];

    document.querySelectorAll('[data-note-scope][data-note-key]').forEach(field => {
        if (field.closest('.array-item')) {
            return;
        }

        const scope = field.dataset.noteScope;
        const key = field.dataset.noteKey;
        const group = field.closest('.form-group') || field.closest('.checkbox-group');

        if (!group || !scope || !key) {
            return;
        }

        const isCheckbox = field.type === 'checkbox';

        // Skip checkboxes entirely - they use the new note system from data.html
        if (isCheckbox) {
            return;
        }
        let container = group.querySelector('.field-notes');
        let controls = group.querySelector('.note-controls');

        if (!controls) {
            // Create the + button
            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'note-add-btn field-note-btn';
            addBtn.textContent = '+';
            addBtn.setAttribute('aria-label', 'Add note');
            addBtn.dataset.noteEventAttached = 'true'; // Note: cannot be passive
            addBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                addFieldNote(scope, key);
            });

            // Insert button after the field (inline)
            field.parentNode.insertBefore(addBtn, field.nextSibling);

            // Create notes container (goes below field and button)
            container = document.createElement('div');
            container.className = 'field-notes';

            controls = document.createElement('div');
            controls.className = 'note-controls';
            controls.appendChild(container);
            group.appendChild(controls);

            // Add class to form-group for proper layout
            group.classList.add('has-note-button');

            field.dataset.noteInit = 'true';
        } else {
            // Controls exist, make sure container reference is correct
            if (!container) {
                container = controls.querySelector('.field-notes');
            }
            // Check if button needs event listener (shouldn't happen, but defensive)
            const addBtn = controls.querySelector('.note-add-btn');
            if (addBtn && !addBtn.dataset.noteEventAttached) {
                addBtn.dataset.noteEventAttached = 'true'; // Note: cannot be passive
                addBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    addFieldNote(scope, key);
                });
            }
            if (field.dataset.noteInit !== 'true') {
                field.dataset.noteInit = 'true';
            }
        }

        if (!window.noteFieldRegistry.some(entry => entry && entry.container === container)) {
            window.noteFieldRegistry.push({ scope, key, container });
        }
    });

    renderAllFieldNotes();
}

// ============================================
// CLOUD STORAGE - PRIMARY
// ============================================
function normalizeProjectsPayload(payload) {
    if (!payload) return null;

    const normalized = {};

    const assignProject = (projectName, projectPayload) => {
        if (!projectName) return;
        const source = projectPayload && typeof projectPayload === 'object' ? projectPayload : {};
        const rawData = source.data ? source.data : source;
        const name = source.name || projectName;
        normalized[projectName] = {
            name,
            data: normalizeProjectData(rawData),
            timestamp: source.timestamp || rawData?.timestamp || new Date().toISOString(),
            currentFacilityIndex: source.currentFacilityIndex ?? rawData?.currentFacilityIndex ?? 0
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
            window.projects = result.projects;
            projects = window.projects;

            invalidateAggregatedData();

            // Backup to localStorage
            saveToLocalStorage('cloudProjects', projects);

            showUploadStatus(`Loaded ${Object.keys(projects).length} projects from cloud`, 'success');
            debugLog('Loaded projects from cloud:', Object.keys(projects));

            // Debug: Check if category metadata is present
            Object.keys(projects).forEach(name => {
                const project = projects[name];
                console.log(`📊 Project "${name}" - Category: ${project.category || 'MISSING'}, Has timestamp: ${!!project.timestamp}, Has currentFacilityIndex: ${!!project.currentFacilityIndex}`);
            });

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

        // Determine category: check project metadata first, then fall back to active tab
        let category = window.projects?.[projectName]?.category;
        if (!category) {
            const activeTab = document.querySelector('.category-tab.active');
            category = activeTab ? activeTab.dataset.category : 'companies';
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

        const response = await fetch(API_ENDPOINTS.SAVE_PROJECT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        debugLog('Response status:', response.status, response.statusText);
        debugLog('Response headers:', Object.fromEntries(response.headers.entries()));

        if (!response.ok) {
            const errorText = await response.text();
            console.error('❌ Save failed response body:', errorText.substring(0, 500));
            throw new Error(`HTTP ${response.status}: ${errorText.substring(0, 100)}`);
        }

        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const responseText = await response.text();
            console.error('❌ Expected JSON but got:', contentType);
            console.error('Response preview:', responseText.substring(0, 500));
            throw new Error(`Expected JSON response, got ${contentType}`);
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

        debugLog('✅ Save successful!');
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
        }, 2000);
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
        }, 2000);
    }
}

window.autoSave = autoSave;

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

function combineCityState(city, state) {
    const trimmedCity = (city || "").trim();
    const trimmedState = (state || "").trim();

    if (trimmedCity && trimmedState) {
        return `${trimmedCity}, ${trimmedState}`;
    }

    return trimmedCity || trimmedState || "";
}

function parseCityState(value) {
    if (!value || typeof value !== 'string') {
        return { city: "", state: "" };
    }

    const parts = value.split(',');
    if (parts.length === 1) {
        return { city: value.trim(), state: "" };
    }

    const city = parts.shift().trim();
    const state = parts.join(',').trim();
    return { city, state };
}

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
            // Pick the longest candidate so we don't collapse to a single letter
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

function resolvePathTarget(path) {
    let scope = 'facility';
    let normalizedPath = path;
    let target = null;

    if (!window.formData) {
        return { scope, normalizedPath, target };
    }

    if (path.startsWith('operator.')) {
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

    if (!window.projects[projectName]) {
        console.error('❌ Project not found:', projectName);
        showUploadStatus(`Project "${projectName}" not found.`, 'error');
        return Promise.reject(new Error(`Project not found: ${projectName}`));
    }

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

            // Dispatch custom event for project loaded
            document.dispatchEvent(new CustomEvent('projectLoaded', {
                detail: { projectName: projectName }
            }));

            showUploadStatus(`Project "${projectName}" loaded (${window.formData.facilities.length} facilities)`, 'success');
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

        const category = tab.dataset.category;

        // Update active tab
        categoryTabsContainer.querySelectorAll('.category-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');

        // Hide all main content wrappers first
        document.querySelectorAll('.category-content').forEach(content => {
            content.classList.add('view-hidden', 'd-none');
        });

        // Determine the ID of the content to show
        // Handles the special case where 'locations' category maps to 'states-content' ID
        const contentId = category === 'locations' ? 'states-content' : `${category}-content`;
        const activeContent = document.getElementById(contentId);

        if (activeContent) {
            activeContent.classList.remove('view-hidden', 'd-none');
        }

        // Handle main form visibility (facility vs. referrer form)
        if (typeof handleReferrerToggle === 'function') {
            handleReferrerToggle();
        }

        // Refresh the list of saved projects for the new tab
        if (typeof refreshSavedProjectPanels === 'function') {
            refreshSavedProjectPanels();
        }

        // Update labels if the function exists
        if (typeof updateLabelsForProjectType === 'function') {
            updateLabelsForProjectType();
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

    const itemsToShow = itemsArray;

    itemsToShow.forEach((item, index) => {
        const itemDiv = document.createElement('div');
        itemDiv.className = 'array-item';
        const isStaff = /^staff\./.test(path) || /^operator\.keyStaff\./.test(path);
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
                    createAutocomplete(roleInput, getAllStaffRoles, 'role');
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
                createAutocomplete(nameInput, getAllHumanNames, 'human');
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
                    createAutocomplete(roleInput, getAllStaffRoles, 'role');
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
                createAutocomplete(orgInput, getAllOperators, 'operator');
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
                dataFunc = () => Array.from(US_STATE_SET);
            } else if (/referrerAgency\.keyPersonnel$/.test(path)) {
                category = 'human';
                dataFunc = getAllHumanNames;
            }

            if (category) {
                itemDiv.appendChild(input); // Must be in DOM for createAutocomplete to find parent
                setTimeout(() => {
                    if (!input.dataset.autocompleteInit) {
                        createAutocomplete(input, dataFunc, category);
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
    if (!window.formData.operator) window.formData.operator = createNewProjectData().operator;
    const operator = window.formData.operator;

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

    const consultant = window.formData.referrerIndividual || window.formData.referrerConsultants[window.currentConsultantIndex] || createDefaultReferrerIndividual();
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
        rebuildLocationAggregates();
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

        // Ensure referrer consultant UI stays in sync when loading referrer projects
        const activeTab = document.querySelector('.category-tab.active');
        if (activeTab && activeTab.dataset.category === 'referrers' && typeof window.updateConsultantsUI === 'function') {
            window.updateConsultantsUI();
        }

        // Reinitialize autocomplete for facility status field
        const facilityStatusField = document.querySelector('.facility-field[data-field="operatingPeriod.status"]');
        if (facilityStatusField && facilityStatusField.dataset.autocompleteInit !== 'true') {
            createAutocomplete(facilityStatusField, getAllStatuses, 'status');
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
        window.formData.facilities?.forEach((facility, index) => {
            const item = document.createElement('div');
            item.className = `facility-item ${index === window.currentFacilityIndex ? 'active' : ''}`;
            const name = facility.identification?.name || 'Unnamed Facility';
            item.innerHTML = `<span class="facility-name ${name === 'Unnamed Facility' ? 'empty' : ''}">${escapeHtmlForAttr(name)}</span><span class="facility-index">${index + 1}</span>`;
            item.tabIndex = 0;
            const accessibleFacilityName = name !== 'Unnamed Facility' ? name : `Facility ${index + 1}`;
            item.setAttribute('role', 'button');
            item.setAttribute('aria-label', `View ${accessibleFacilityName}`);
            const goToFacility = () => navigateToFacility(index);
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
    }
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
        // Clear and populate dropdown
        dropdown.innerHTML = '';
        window.formData.facilities.forEach((facility, index) => {
            const option = document.createElement('option');
            option.value = index;
            const facilityName = facility.identification?.name || `Facility ${index + 1}`;
            option.textContent = `${index + 1}. ${facilityName}`;
            dropdown.appendChild(option);
        });

        debugLog('✅ Populated dropdown with', window.formData.facilities.length, 'facilities');

        // Set current selection
        if (typeof window.currentFacilityIndex !== 'undefined') {
            dropdown.value = window.currentFacilityIndex;
        }

        // Sort options alphabetically by facility name
        const options = Array.from(dropdown.options);
        options.sort((a, b) => {
            const nameA = a.textContent.replace(/^\d+\.\s*/, '').toLowerCase();
            const nameB = b.textContent.replace(/^\d+\.\s*/, '').toLowerCase();
            return nameA.localeCompare(nameB);
        });
        // Re-add sorted options
        dropdown.innerHTML = '';
        options.forEach(opt => dropdown.appendChild(opt));
        // Reselect current facility
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

    const facilityToClone = deepClone(window.formData.facilities[window.currentFacilityIndex]);
    // Give the clone a new name to avoid confusion
    if (facilityToClone.identification && facilityToClone.identification.name) {
        facilityToClone.identification.name = `${facilityToClone.identification.name} (Clone)`;
    }

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

    if (isNewProject) {
        // Create new project with ONLY the cloned facility, no operator data
        const newProjectData = createNewProjectData();
        newProjectData.facilities = [facilityToClone];

        // Determine category: use provided category, or fall back to active tab
        let category = targetCategory;
        if (!category) {
            const activeTab = document.querySelector('.category-tab.active');
            category = activeTab ? activeTab.dataset.category : 'companies';
        }

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
        debugLog(`✅ Added cloned facility to existing project "${targetProjectName}".`);
    }

    // Persist the changes
    if (isSuggestionMode()) {
        persistProjectLocally(targetProjectName);
        alert(`✅ Facility cloned to project "${targetProjectName}" and saved as a local draft.`);
    } else {
        try {
            // Save the target project to the cloud
            const originalProject = { name: window.currentProjectName, data: deepClone(window.formData), index: window.currentFacilityIndex };
            
            // Temporarily switch context to save the target project
            window.currentProjectName = targetProjectName;
            window.formData = window.projects[targetProjectName].data;
            
            await saveProjectToCloud(targetProjectName);
            
            // Restore original context
            window.currentProjectName = originalProject.name;
            window.formData = originalProject.data;
            window.currentFacilityIndex = originalProject.index;

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
        setTimeout(() => { isNavigating = false; }, 100);
    }
}

const US_STATE_NAMES = [
    'alabama', 'alaska', 'arizona', 'arkansas', 'california', 'colorado', 'connecticut', 'delaware', 'florida', 'georgia',
    'hawaii', 'idaho', 'illinois', 'indiana', 'iowa', 'kansas', 'kentucky', 'louisiana', 'maine', 'maryland',
    'massachusetts', 'michigan', 'minnesota', 'mississippi', 'missouri', 'montana', 'nebraska', 'nevada', 'new hampshire', 'new jersey',
    'new mexico', 'new york', 'north carolina', 'north dakota', 'ohio', 'oklahoma', 'oregon', 'pennsylvania', 'rhode island', 'south carolina',
    'south dakota', 'tennessee', 'texas', 'utah', 'vermont', 'virginia', 'washington', 'west virginia', 'wisconsin', 'wyoming'
];

const COUNTRY_NAMES = [
    'canada', 'mexico', 'united kingdom', 'france', 'germany', 'italy', 'spain', 'russia', 'china', 'japan',
    'australia', 'brazil', 'argentina', 'india', 'south africa', 'nigeria', 'egypt', 'saudi arabia', 'iran', 'iraq',
    'norway', 'sweden', 'denmark', 'netherlands', 'belgium', 'switzerland', 'austria', 'poland', 'ukraine', 'turkey'
];

const US_STATE_SET = new Set(US_STATE_NAMES);
const COUNTRY_SET = new Set(COUNTRY_NAMES);

function ensureLocationProjectsFromFacilities() {
    if (!window.formData || !Array.isArray(window.formData.facilities)) return;
    if (!window.projects) window.projects = {};

    const states = new Set();
    window.formData.facilities.forEach((facility = {}) => {
        const rawLocation = facility.location || '';
        const parsed = parseCityState(rawLocation);
        const state = (facility.locationState || parsed.state || '').trim();
        if (state) {
            states.add(state.toUpperCase());
        }
    });

    states.forEach((stateName) => {
        if (!window.projects[stateName]) {
            const locationProject = createNewProjectData();
            locationProject.facilities = [];
            window.projects[stateName] = {
                name: stateName,
                data: locationProject,
                timestamp: new Date().toISOString(),
                category: 'locations'
            };
        } else if (!window.projects[stateName].category) {
            window.projects[stateName].category = 'locations';
        }
    });
}

function ensureAllStateLocationProjects() {
    if (!window.projects) {
        window.projects = {};
    }

    US_STATE_NAMES.forEach((stateName) => {
        if (!window.projects[stateName]) {
            const emptyLocation = createNewProjectData();
            emptyLocation.facilities = [];
            window.projects[stateName] = {
                name: stateName,
                data: emptyLocation,
                timestamp: new Date().toISOString(),
                category: 'locations'
            };
        } else if (!window.projects[stateName].category) {
            window.projects[stateName].category = 'locations';
        }
    });
}

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

function rebuildLocationAggregates() {
    if (!window.projects || typeof deepClone !== 'function') {
        return;
    }

    const stateBuckets = {};
    const registerState = (state) => {
        if (!state) return;
        const normalized = state.trim().toUpperCase();
        if (!normalized) return;
        if (!stateBuckets[normalized]) {
            stateBuckets[normalized] = { facilities: [], referrers: [] };
        }
    };

    // Collect facilities and referrers from every non-location project
    Object.entries(window.projects).forEach(([projectName, project]) => {
        if (!project || project.category === 'locations') return;
        const data = project.data || {};

        // Facilities
        (data.facilities || []).forEach((facility = {}) => {
            const parsed = parseCityState(facility.location || '');
            const state = (facility.locationState || parsed.state || '').trim();
            if (!state) return;
            registerState(state);
            const clone = deepClone(facility);
            clone.sourceProject = projectName;
            stateBuckets[state.toUpperCase()].facilities.push(clone);
        });

        // Referrer consultants/individuals
        const refConsultants = Array.isArray(data.referrerConsultants) ? data.referrerConsultants : [];
        refConsultants.forEach((consultant = {}) => {
            const state = (consultant.state || '').trim();
            if (!state) return;
            registerState(state);
            const clone = deepClone(consultant);
            clone.sourceProject = projectName;
            stateBuckets[state.toUpperCase()].referrers.push(clone);
        });

        const refInd = data.referrerIndividual || null;
        if (refInd && refInd.state) {
            registerState(refInd.state);
            const clone = deepClone(refInd);
            clone.sourceProject = projectName;
            stateBuckets[refInd.state.trim().toUpperCase()].referrers.push(clone);
        }
    });

    // Ensure every US state project exists, then overwrite with the aggregated data
    ensureAllStateLocationProjects();

    Object.keys(window.projects).forEach((projectName) => {
        const isStateProject = US_STATE_SET.has(projectName.toUpperCase());
        if (!isStateProject) return;

        const bucket = stateBuckets[projectName.toUpperCase()] || { facilities: [], referrers: [] };
        const aggregated = createNewProjectData();
        aggregated.facilities = bucket.facilities;
        aggregated.referrerConsultants = bucket.referrers;

        window.projects[projectName] = {
            name: projectName,
            data: aggregated,
            timestamp: new Date().toISOString(),
            category: 'locations'
        };
    });
}

function determineProjectCategory(name = '') {
    // First, check if the project has stored category metadata
    const project = window.projects?.[name];
    if (project && project.category) {
        return project.category;
    }

    // Fallback to name-based heuristic for legacy projects
    const normalized = name.toLowerCase().trim();
    // A simple heuristic: if it contains referrer-related keywords, it's a referrer project
    if (normalized.includes('consultant') ||
        normalized.includes('district') ||
        normalized.includes('agency') ||
        normalized.includes('referrer') ||
        normalized.includes('education') ||
        normalized.includes('school')) {
        return 'referrers';
    }
    if (US_STATE_SET.has(normalized) || COUNTRY_SET.has(normalized)) {
        return 'locations';
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

    if (!companyContainer && !locationContainer && !referrerContainer) {
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
        companyContainer.innerHTML = buildProjectCards(companyNames, '📭 No saved company projects yet');
    }

    if (locationContainer) {
        const locationNames = projectNames.filter(name => determineProjectCategory(name) === 'locations');
        locationContainer.innerHTML = buildProjectCards(locationNames, '📭 No saved location projects yet');
    }

    if (referrerContainer) {
        const referrerNames = projectNames.filter(name => determineProjectCategory(name) === 'referrers');
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
        toolbarTitle: { default: '📋 Facility Editor', referrer: '📋 Referrer Editor' },
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

function copyToClipboard() {
    navigator.clipboard.writeText(JSON.stringify(window.formData, null, 2)).then(() => {
        showUploadStatus('JSON copied to clipboard!', 'success');
    });
}

function downloadJSON() {
    const jsonString = JSON.stringify(window.formData, null, 2);
    const blob = new Blob([jsonString], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${window.currentProjectName || 'facility_data'}.json`;
    a.click();
    URL.revokeObjectURL(url);
}

function buildProjectExport(categories) {
    const projects = window.projects || {};
    const allowed = Array.isArray(categories) && categories.length ? new Set(categories) : null;

    return Object.fromEntries(
        Object.entries(projects).filter(([_, project]) => {
            const category = project?.category || 'companies';
            return !allowed || allowed.has(category);
        })
    );
}

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

// Make key functions globally available
window.loadProject = loadProject;
window.newProject = newProject;
window.saveProjectToCloud = saveProjectToCloud;
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
window.copyToClipboard = copyToClipboard;
window.downloadJSON = downloadJSON;
window.refreshSavedProjectPanels = refreshSavedProjectPanels;
window.initializeAutocompleteFields = initializeAutocompleteFields;
window.invalidateAggregatedData = invalidateAggregatedData;
window.normalizeProjectData = normalizeProjectData;
window.initializeSectionToggles = initializeSectionToggles;

// ============================================
// CONSULTANTS OVERVIEW (for Referrers view)
// ============================================

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

function updateConsultantsOverview() {
    const consultantsList = document.getElementById('consultants-list');
    const consultantsStats = document.getElementById('consultants-toc-stats');

    if (!consultantsList || !consultantsStats) return;

    const consultants = window.formData?.referrerConsultants || [];
    const currentIndex = window.currentConsultantIndex || 0;

    debugLog('updateConsultantsOverview: consultants count =', consultants.length, 'currentIndex =', currentIndex);

    // Update stats
    consultantsStats.textContent = `Total: ${consultants.length} consultant${consultants.length !== 1 ? 's' : ''}`;

    // Clear list
    consultantsList.innerHTML = '';

    // Populate consultant items
    consultants.forEach((consultant, index) => {
        debugLog(`Processing consultant ${index}:`, {
            firstName: consultant.firstName,
            lastName: consultant.lastName,
            fullName: consultant.fullName,
            city: consultant.city,
            state: consultant.state,
            allKeys: Object.keys(consultant)
        });

        // Build full name - try multiple possible keys
        const fullName = getConsultantDisplayName(consultant, index);

        // Build location
        const location = [consultant.city, consultant.state].filter(Boolean).join(', ') || 'Location not specified';

        debugLog(`Consultant ${index} display: name="${fullName}", location="${location}"`);

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
            debugLog('Selected consultant index:', index);
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

function loadConsultantData() {
    if (!window.formData.referrerConsultants || window.formData.referrerConsultants.length === 0) {
        window.formData.referrerConsultants = [createDefaultReferrerIndividual()];
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
    debugLog('✅ Consultant dropdown updated with', consultants.length, 'consultants');
}

function updateConsultantsUI() {
    debugLog('🔄 updateConsultantsUI called');
    loadConsultantData();
    updateConsultantsOverview();
    updateConsultantDropdown();
    if (typeof updateJSON === 'function') updateJSON();
    if (typeof autoSave === 'function') autoSave();
    debugLog('✅ updateConsultantsUI complete');
}

// ============================================
// LOCATION FACILITIES OVERVIEW
// ============================================

function updateLocationFacilitiesOverview() {
    const facilitiesList = document.getElementById('location-facilities-list');
    const facilitiesStats = document.getElementById('location-facilities-toc-stats');

    if (!facilitiesList || !facilitiesStats) return;

    const facilities = window.formData?.facilities || [];
    const currentIndex = window.currentFacilityIndex || 0;

    // Update stats
    facilitiesStats.textContent = `Total: ${facilities.length} facilit${facilities.length !== 1 ? 'ies' : 'y'}`;

    // Clear list
    facilitiesList.innerHTML = '';

    if (facilities.length === 0) {
        facilitiesList.innerHTML = '<div style="padding: 20px; text-align: center; color: #6b7280;">No facilities in this location yet</div>';
        return;
    }

    // Populate facility items
    facilities.forEach((facility, index) => {
        const facilityName = facility.identification?.name || 'Unnamed Facility';
        const operator = facility.identification?.currentOperator || 'Unknown Operator';
        const programType = facility.facilityDetails?.type || '';
        const status = facility.operatingPeriod?.status || '';

        const item = document.createElement('div');
        item.className = 'facility-item' + (index === currentIndex ? ' active' : '');
        item.tabIndex = 0;
        const accessibleFacilityName = facilityName !== 'Unnamed Facility' ? facilityName : `Facility ${index + 1}`;
        item.setAttribute('role', 'button');
        item.setAttribute('aria-label', `View ${accessibleFacilityName}`);

        // Escape HTML
        const div1 = document.createElement('div');
        div1.textContent = facilityName;
        const nameEscaped = div1.innerHTML;

        const div2 = document.createElement('div');
        div2.textContent = operator;
        const operatorEscaped = div2.innerHTML;

        const div3 = document.createElement('div');
        div3.textContent = programType;
        const typeEscaped = div3.innerHTML;

        const div4 = document.createElement('div');
        div4.textContent = status;
        const statusEscaped = div4.innerHTML;

        item.innerHTML = `
            <div class="facility-item-number">${index + 1}</div>
            <div class="facility-item-info">
                <div class="facility-item-name">${nameEscaped}</div>
                <div class="facility-item-details">
                    ${operatorEscaped}${programType ? ' • ' + typeEscaped : ''}${status ? ' • ' + statusEscaped : ''}
                </div>
            </div>
        `;

        const selectFacility = () => {
            window.currentFacilityIndex = index;
            if (typeof window.updateAllUI === 'function') {
                window.updateAllUI();
            }
            updateLocationFacilitiesOverview();
        };

        item.addEventListener('click', selectFacility);
        item.addEventListener('keydown', function(event) { // Note: cannot be passive
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                selectFacility();
            }
        });

        facilitiesList.appendChild(item);
    });
}

// ============================================
// CONSULTANT NAVIGATION INITIALIZATION
// ============================================

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

// ============================================
// TOC TOGGLE INITIALIZATION
// ============================================

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

function initializeLocationFacilitiesToc() {
    const locationFacilitiesTocToggle = document.getElementById('location-facilities-toc-toggle-btn');
    if (locationFacilitiesTocToggle && !locationFacilitiesTocToggle.dataset.listenerAttached) {
        locationFacilitiesTocToggle.addEventListener('click', function() { // UI-only, can be passive
            const toc = document.getElementById('location-facilities-toc');
            const content = toc.querySelector('.toc-content');
            const isCollapsed = content.style.display === 'none';

            if (isCollapsed) {
                content.style.display = 'block';
                locationFacilitiesTocToggle.textContent = '🔎';
            } else {
                content.style.display = 'none';
                locationFacilitiesTocToggle.textContent = '👁️';
            }
        }, { passive: true });
        locationFacilitiesTocToggle.dataset.listenerAttached = 'true';
    }
}

// Expose to global scope
window.updateConsultantsOverview = updateConsultantsOverview;
window.updateConsultantsUI = updateConsultantsUI;
window.loadConsultantData = loadConsultantData;
window.updateLocationFacilitiesOverview = updateLocationFacilitiesOverview;
window.initializeConsultantNavigation = initializeConsultantNavigation;
window.initializeOverviewTabSwitching = initializeOverviewTabSwitching;
window.initializeConsultantsTocToggle = initializeConsultantsTocToggle;
window.initializeLocationFacilitiesToc = initializeLocationFacilitiesToc;

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
