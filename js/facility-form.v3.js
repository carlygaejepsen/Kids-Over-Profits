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

const defaultApiPaths = {
    SAVE_PROJECT:
        FACILITY_FORM_CONFIG.endpoints?.SAVE_PROJECT ||
        getResolverEndpoint('save-master.php', '/wp-content/themes/child/api/save-master.php'),
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

const resolvedFormMode = typeof FACILITY_FORM_CONFIG.mode === 'string'
    ? FACILITY_FORM_CONFIG.mode
    : (typeof window !== 'undefined' && typeof window.FORM_MODE === 'string' ? window.FORM_MODE : 'master');

const FORM_MODE = typeof resolvedFormMode === 'string'
    ? resolvedFormMode.toLowerCase()
    : 'master';

const IS_SUGGESTION_MODE = FORM_MODE === 'suggestions';

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

let noteFieldRegistry = [];

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
    return path.split('.').reduce((current, key) => current?.[key], obj);
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
        const referrers = new Set(customReferrers);

        Object.values(projects).forEach(project => {
            project.data?.facilities?.forEach(facility => {
                facility.identification?.knownReferrers?.forEach(ref => {
                    if (ref && typeof ref === 'string') {
                        referrers.add(ref);
                    }
                });
            });
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
        if (input.value.trim()) {
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
            hideDropdown();
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

        // Click handler for the header (excluding the toggle)
        header.addEventListener('click', (event) => {
            if (event.target.closest('.section-toggle')) { return; }
            handleToggle(event);
        }, { passive: false });
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
    if (!Array.isArray(noteFieldRegistry)) {
        return;
    }

    noteFieldRegistry.forEach(entry => {
        if (!entry) {
            return;
        }
        const { scope, key, container } = entry;
        renderFieldNotes(container, scope, key);
    });
}

function initializeNoteControls() {
    // Preserve existing array item entries (those with keys containing a dot followed by a number like "staff.administrator.0")
    const arrayItemEntries = (noteFieldRegistry || []).filter(entry => {
        if (!entry || !entry.key) return false;
        // Keep entries that look like array items (e.g., "path.0", "path.1", etc.)
        return /\.\d+$/.test(entry.key);
    });

    // Reset registry but keep array item entries
    noteFieldRegistry = [...arrayItemEntries];

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
            addBtn.innerHTML = '<span aria-hidden="true">＋</span><span class="sr-only">Add note</span>';
            addBtn.dataset.noteEventAttached = 'true';
            addBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                addFieldNote(scope, key);
            }, { passive: false });

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
                addBtn.dataset.noteEventAttached = 'true';
                addBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    addFieldNote(scope, key);
                }, { passive: false });
            }
            if (field.dataset.noteInit !== 'true') {
                field.dataset.noteInit = 'true';
            }
        }

        if (!noteFieldRegistry.some(entry => entry && entry.container === container)) {
            noteFieldRegistry.push({ scope, key, container });
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

    if (IS_SUGGESTION_MODE) {
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
    if (IS_SUGGESTION_MODE) {
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
        window.autoSaveTimer = setTimeout(() => {
            saveProjectToCloud(window.currentProjectName);
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
        keyPersonnel: [],
        notes: "",
        fieldNotes: {}
    };
}

function createDefaultReferrerIndividual() {
    return {
        firstName: "",
        lastName: "",
        credentials: "",
        city: "",
        state: "",
        email: "",
        phone: "",
        website: "",
        affiliations: [],
        facilitiesReferred: [],
        schoolDistricts: [],
        notes: "",
        fieldNotes: {}
    };
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

    // Ensure referrer agency structure exists
    if (!window.formData.referrerAgency || typeof window.formData.referrerAgency !== 'object') {
        window.formData.referrerAgency = createDefaultReferrerGroup();
    }

    // Ensure referrer consultants array exists
    if (!Array.isArray(window.formData.referrerConsultants) || window.formData.referrerConsultants.length === 0) {
        window.formData.referrerConsultants = [createDefaultReferrerIndividual()];
    }

    // Ensure isIndependentConsultant flag exists
    if (typeof window.formData.isIndependentConsultant === 'undefined') {
        window.formData.isIndependentConsultant = false;
    }

    // Legacy support - migrate old data structures if they exist
    if (window.formData.referrerGroup && !window.formData.referrerAgency) {
        window.formData.referrerAgency = window.formData.referrerGroup;
    }
    if (window.formData.referrerIndividual && !window.formData.referrerConsultants) {
        window.formData.referrerConsultants = [window.formData.referrerIndividual];
    }
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
    return {
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
        referrerAgency: createDefaultReferrerGroup(),
        referrerConsultants: [createDefaultReferrerIndividual()],
        isIndependentConsultant: false,
        fieldNotes: {}
    };
}

function loadProject(projectName) {
    debugLog('🔄 loadProject called with:', projectName);
    debugLog('📦 Available projects:', Object.keys(window.projects || {}));

    if (!window.projects[projectName]) {
        console.error('❌ Project not found:', projectName);
        showUploadStatus(`Project "${projectName}" not found.`, 'error');
        return;
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

    window.currentProjectName = projectName;
    if (window.projects[projectName].data && Object.keys(window.projects[projectName].data).length > 0) {
        window.formData = deepClone(window.projects[projectName].data);
    } else {
        window.formData = createNewProjectData();
    }
    window.currentFacilityIndex = window.projects[projectName].currentFacilityIndex || 0;

    if (!window.formData.facilities || window.currentFacilityIndex >= window.formData.facilities.length) {
        window.currentFacilityIndex = 0;
    }

    const projectNameInput = document.getElementById('project-name');
    if (projectNameInput) {
        projectNameInput.value = projectName;
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
    // This function is now the single source of truth for showing/hiding main content areas.
    const activeTab = document.querySelector('.category-tab.active');
    const activeCategory = activeTab ? activeTab.dataset.category : 'companies';

    const referrerMainWrapper = document.getElementById('referrer-main-wrapper');
    const facilityMainWrapper = document.getElementById('facility-main-wrapper');
    // Use querySelectorAll to handle cases where the element might be duplicated by mistake.
    const fixedToolbars = document.querySelectorAll('#fixed-toolbar');

    if (activeCategory === 'referrers') {
        if (facilityMainWrapper) facilityMainWrapper.style.display = 'none';
        if (referrerMainWrapper) referrerMainWrapper.style.display = 'block';
        fixedToolbars.forEach(tb => tb.style.display = 'none');
        if (typeof window.updateAgencySliderAppearance === 'function') {
            window.updateAgencySliderAppearance();
        }
    } else {
        if (facilityMainWrapper) facilityMainWrapper.style.display = 'block';
        if (referrerMainWrapper) referrerMainWrapper.style.display = 'none';
        fixedToolbars.forEach(tb => tb.style.display = 'block');
    }
}

// Expose to global scope for access from inline scripts
window.handleReferrerToggle = handleReferrerToggle;

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

// ============================================
// FORM DATA MANAGEMENT
// ============================================
function updateJSON() {
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

    if (!Array.isArray(noteFieldRegistry)) {
        noteFieldRegistry = [];
    } else {
        const pathPrefix = `${path}.`;
        noteFieldRegistry = noteFieldRegistry.filter(entry => {
            if (!entry || !entry.key) {
                return false;
            }
            return !entry.key.startsWith(pathPrefix);
        });
    }

    // Set up event delegation on container if not already done
    if (!container.dataset.delegationInit) {
        container.addEventListener('click', (e) => {
            if (e.target.classList.contains('add-item-btn')) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                const btnPath = e.target.dataset.arrayPath;
                if (btnPath) {
                    addNewArrayItem(btnPath);
                }
            }
        }, { passive: false });
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
            addNoteBtn.innerHTML = '<span aria-hidden="true">＋</span><span class="sr-only">Add note</span>';
            addNoteBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                addFieldNote(scopeForNotes, noteKey);
            }, { passive: false });
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
            noteFieldRegistry.push({ scope: scopeForNotes, key: noteKey, container: notesContainer });
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

    const arrayPaths = ['operator.parentCompanies', 'operator.websites', 'operator.keyStaff.founders', 'operator.keyStaff.keyExecutives', 'operator.investors'];
    arrayPaths.forEach(path => {
        const container = document.querySelector(`[data-path="${path}"]`);
        if (container) {
            renderArray(container, path, getNestedValue(operator, path.replace('operator.', '')));
        }
    });
}

function loadReferrerData() {
    ensureReferrerDataStructures();

    // Load agency data
    const agency = window.formData.referrerAgency || createDefaultReferrerGroup();
    window.formData.referrerAgency = agency;

    // Ensure consultants array exists
    if (!Array.isArray(window.formData.referrerConsultants) || window.formData.referrerConsultants.length === 0) {
        window.formData.referrerConsultants = [createDefaultReferrerIndividual()];
    }

    // Load independent consultant toggle
    const independentToggle = document.getElementById('referrer-independent-toggle');
    if (independentToggle) {
        independentToggle.checked = !!window.formData.isIndependentConsultant;
        if (typeof window.updateAgencySliderAppearance === 'function') {
            window.updateAgencySliderAppearance();
        }
    }

    // Load agency fields
    const agencyFieldMap = {
        'referrer-agency-name': 'name',
        'referrer-agency-city': 'city',
        'referrer-agency-state': 'state',
        'referrer-agency-website': 'website',
        'referrer-agency-notes': 'notes'
    };

    Object.entries(agencyFieldMap).forEach(([id, key]) => {
        const el = document.getElementById(id);
        if (el) {
            el.value = agency[key] || '';
        }
    });

    // Load agency key personnel array
    const keyPersonnelContainer = document.querySelector('[data-path="referrerAgency.keyPersonnel"]');
    if (keyPersonnelContainer) {
        if (!Array.isArray(agency.keyPersonnel)) agency.keyPersonnel = [];
        renderArray(keyPersonnelContainer, 'referrerAgency.keyPersonnel', agency.keyPersonnel);
    }

    // Load current consultant (first one for now - navigation will be added later)
    if (!window.currentConsultantIndex) window.currentConsultantIndex = 0;
    const consultant = window.formData.referrerConsultants[window.currentConsultantIndex] || createDefaultReferrerIndividual();

    const consultantFieldMap = {
        'consultant-firstname': 'firstName',
        'consultant-lastname': 'lastName',
        'consultant-credentials': 'credentials',
        'consultant-city': 'city',
        'consultant-state': 'state',
        'consultant-email': 'email',
        'consultant-phone': 'phone',
        'consultant-website': 'website',
        'consultant-notes': 'notes'
    };

    Object.entries(consultantFieldMap).forEach(([id, key]) => {
        const el = document.getElementById(id);
        if (el) {
            el.value = consultant[key] || '';
        }
    });

    // Load consultant arrays
    const consultantArrays = [
        {path: 'consultant.affiliations', data: consultant.affiliations},
        {path: 'consultant.facilitiesReferred', data: consultant.facilitiesReferred},
        {path: 'consultant.schoolDistricts', data: consultant.schoolDistricts}
    ];

    consultantArrays.forEach(({path, data}) => {
        const container = document.querySelector(`[data-path="${path}"]`);
        if (container) {
            if (!Array.isArray(data)) data = [];
            renderArray(container, path, data);
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

    // Reinitialize autocomplete for facility status field
    const facilityStatusField = document.querySelector('.facility-field[data-field="operatingPeriod.status"]');
    if (facilityStatusField && facilityStatusField.dataset.autocompleteInit !== 'true') {
        createAutocomplete(facilityStatusField, getAllStatuses, 'status');
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
            if (IS_SUGGESTION_MODE) {
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
            item.onclick = () => navigateToFacility(index);
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

        toolbarToggle.addEventListener('click', () => {
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
        prevBtnToolbar.addEventListener('click', () => {
            const dropdown = document.getElementById('facility-dropdown');
            if (dropdown && dropdown.selectedIndex > 0) {
                dropdown.selectedIndex--;
                navigateToFacility(parseInt(dropdown.value));
            }
        }, { passive: true });
        prevBtnToolbar.dataset.listenerAttached = 'true';
    }

    if (nextBtnToolbar && !nextBtnToolbar.dataset.listenerAttached) {
        nextBtnToolbar.addEventListener('click', () => {
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
        addFacilityBtnToolbar.addEventListener('click', addFacility, { passive: true });
        addFacilityBtnToolbar.dataset.listenerAttached = 'true';
    }

    // Clone Facility button
    const cloneFacilityBtnToolbar = document.getElementById('clone-facility-btn-toolbar');
    if (cloneFacilityBtnToolbar && !cloneFacilityBtnToolbar.dataset.listenerAttached) {
        cloneFacilityBtnToolbar.addEventListener('click', cloneFacility, { passive: true });
        cloneFacilityBtnToolbar.dataset.listenerAttached = 'true';
    }

    // Remove Facility button
    const removeFacilityBtnToolbar = document.getElementById('remove-facility-btn-toolbar');
    if (removeFacilityBtnToolbar && !removeFacilityBtnToolbar.dataset.listenerAttached) {
        removeFacilityBtnToolbar.addEventListener('click', removeFacility, { passive: true });
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
    if (IS_SUGGESTION_MODE) {
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

function previousFacility() {
    if (window.currentFacilityIndex > 0) {
        window.currentFacilityIndex--;
        loadFacilityData();
        updateFacilityControls();
        updateTableOfContents();
    }
}

function nextFacility() {
    if (window.currentFacilityIndex < window.formData.facilities.length - 1) {
        window.currentFacilityIndex++;
        loadFacilityData();
        updateFacilityControls();
        updateTableOfContents();
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
            const adminButtons = !IS_SUGGESTION_MODE ? `
                        <button class="project-item-btn project-item-rename" onclick="event.stopPropagation(); renameProject('${escapeHtmlForAttr(name)}')">Rename</button>
                        <button class="project-item-btn project-item-delete" onclick="event.stopPropagation(); deleteProject('${escapeHtmlForAttr(name)}')">Delete</button>
        ` : '';

            return `<div class="project-item" onclick="loadProject('${escapeHtmlForAttr(name)}')">
                    <div class="project-item-name">${escapeHtmlForAttr(name)}</div>
                    <div class="project-item-date">${escapeHtmlForAttr(dateStr)}<br><small>${facilityCount} facilities</small></div>
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
        operatorSectionTitle: { default: 'Operator Information', referrer: 'Group/Agency Information' },
        operatorNameLabel: { default: 'Operator Name', referrer: 'Group/Agency Name' },
        facilitiesOverviewTitle: { default: 'Facilities Overview', referrer: 'Individuals Overview' },
        addFacilityButton: { default: 'Add New Facility', referrer: 'Add New Individual' },
        currentFacilityLabel: { default: 'Current Facility', referrer: 'Current Individual' },
        addFacilityToolbar: { default: '➕ Add Facility', referrer: '➕ Add Individual' },
        cloneFacilityToolbar: { default: '📋 Clone Facility', referrer: '📋 Clone Individual' },
        removeFacilityToolbar: { default: '🗑️ Remove', referrer: '🗑️ Remove' }, // Stays the same
        facilityNameLabel: { default: 'Name', referrer: 'Individual\'s Name' },
        facilityIdentificationTitle: { default: 'Identification & Names', referrer: 'Individual Identification' },
        facilityDetailsTitle: { default: 'Facility Details', referrer: 'Individual Details' },
        facilityOperationsTitle: { default: 'Facility Operations', referrer: 'Individual\'s Operations' }
    };

    const setLabel = (elementId, text) => {
        const el = document.getElementById(elementId);
        if (el) el.textContent = text;
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
    setLabelForQuery('#add-facility-main-btn', labels.addFacilityButton[mode]);
    setLabelForQuery('.facility-controls strong', `${labels.currentFacilityLabel[mode]}: `);

    // Update Toolbar
    setLabel('add-facility-btn-toolbar', labels.addFacilityToolbar[mode]);
    setLabel('clone-facility-btn-toolbar', labels.cloneFacilityToolbar[mode]);

    // Update Facility-specific sections
    setLabelForQuery('#identification-section .section-title', labels.facilityIdentificationTitle[mode]);
    setLabelForQuery('label[for="facility-name"]', labels.facilityNameLabel[mode]);
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

    // Referrer agency fields
    const referrerAgencyFields = {
        'referrer-agency-name': (val) => {
            ensureReferrerDataStructures();
            setNestedValue(window.formData, 'referrerAgency.name', val);
            updateJSON();
            autoSave();
        },
        'referrer-agency-city': (val) => {
            ensureReferrerDataStructures();
            setNestedValue(window.formData, 'referrerAgency.city', val);
            updateJSON();
            autoSave();
        },
        'referrer-agency-state': (val) => {
            ensureReferrerDataStructures();
            setNestedValue(window.formData, 'referrerAgency.state', val);
            updateJSON();
            autoSave();
        },
        'referrer-agency-website': (val) => {
            ensureReferrerDataStructures();
            setNestedValue(window.formData, 'referrerAgency.website', val);
            updateJSON();
            autoSave();
        },
        'referrer-agency-notes': (val) => {
            ensureReferrerDataStructures();
            setNestedValue(window.formData, 'referrerAgency.notes', val);
            updateJSON();
            autoSave();
        }
    };

    Object.keys(referrerAgencyFields).forEach(id => {
        const el = document.getElementById(id);
        if (el && !el.dataset.listenerAttached) {
            el.addEventListener('input', (e) => referrerAgencyFields[id](e.target.value), { passive: true });
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
                updateJSON();
                autoSave();
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
            updateJSON();
            autoSave();
        }, { passive: true });
        independentToggle.dataset.listenerAttached = 'true';
    }

    // Legacy referrer fields (kept for backwards compatibility, but not used in new UI)
    const referrerFields = {}

    Object.keys(referrerFields).forEach(id => {
        const el = document.getElementById(id);
        if (el && !el.dataset.listenerAttached) {
            const handler = (event) => referrerFields[id](event.target.value);
            const eventName = el.tagName === 'SELECT' ? 'change' : 'input';
            el.addEventListener(eventName, handler, { passive: true });
            if (eventName !== 'input') {
                el.addEventListener('input', handler, { passive: true });
            }
            el.dataset.listenerAttached = 'true';
        }
    });

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
        'add-facility-main-btn': addFacility,
        'remove-facility-btn': removeFacility,
        'clone-facility-btn': cloneFacility,
        'prev-facility-btn': previousFacility,
        'next-facility-btn': nextFacility,
        'sort-facilities-btn': sortFacilities
    };

    Object.keys(facilityButtons).forEach(id => {
        const btn = document.getElementById(id);
        if (btn && !btn.dataset.listenerAttached) {
            btn.addEventListener('click', facilityButtons[id], { passive: true });
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
            } else {
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
            const dataStr = JSON.stringify(window.projects || {}, null, 2);
            const blob = new Blob([dataStr], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'all-projects-export.json';
            a.click();
            URL.revokeObjectURL(url);
        };
        exportAllBtn.dataset.listenerAttached = 'true';
    }

    const generateReportBtn = document.getElementById('generate-report-btn');
    if (generateReportBtn && !generateReportBtn.dataset.listenerAttached) {
        generateReportBtn.onclick = () => {
            if (typeof generateReport === 'function') {
                generateReport();
            } else {
                alert('Report generation is not available yet.');
            }
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
            // Export all projects as JSON
            const dataStr = JSON.stringify(window.projects || {}, null, 2);
            const blob = new Blob([dataStr], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'all-projects-export.json';
            a.click();
            URL.revokeObjectURL(url);
        };
        exportAllBtnLocation.dataset.listenerAttached = 'true';
    }

    const generateReportBtnLocation = document.getElementById('generate-report-btn-location');
    if (generateReportBtnLocation && !generateReportBtnLocation.dataset.listenerAttached) {
        generateReportBtnLocation.onclick = () => {
            if (typeof generateReport === 'function') {
                generateReport();
            } else {
                alert('Report generation is not available yet.');
            }
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
            const dataStr = JSON.stringify(window.projects || {}, null, 2);
            const blob = new Blob([dataStr], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'all-projects-export.json';
            a.click();
            URL.revokeObjectURL(url);
        };
        exportReferrerBtn.dataset.listenerAttached = 'true';
    }

    const generateReferrerReportBtn = document.getElementById('generate-referrer-report-btn');
    if (generateReferrerReportBtn && !generateReferrerReportBtn.dataset.listenerAttached) {
        generateReferrerReportBtn.onclick = () => {
            if (typeof generateReport === 'function') {
                generateReport();
            } else {
                alert('Report generation is not available yet.');
            }
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

    // Import/Export
    const copyBtn = document.getElementById('copy-json-btn');
    if (copyBtn && !copyBtn.dataset.listenerAttached) {
        copyBtn.addEventListener('click', copyToClipboard, { passive: true });
        copyBtn.dataset.listenerAttached = 'true';
    }

    const downloadBtn = document.getElementById('download-json-btn');
    if (downloadBtn && !downloadBtn.dataset.listenerAttached) {
        downloadBtn.addEventListener('click', downloadJSON, { passive: true });
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

    // Load custom data from localStorage (backup only)
    loadCustomDataFromLocalStorage();
    
    // Load all projects from cloud
    await loadAllProjectsFromCloud();
    
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
// FIELD NOTES FUNCTIONALITY
// ============================================

debugLog('Field notes module loading...');

// Store for field notes - facility-specific
let allFacilityNotes = {}; // Object to store notes for all facilities
let notesCurrentFacilityIndex = 0; // Track current facility for notes
let fieldNotes = {}; // Legacy fallback storage

// Get notes for current facility
function getCurrentFacilityNotes() {
    if (!allFacilityNotes[notesCurrentFacilityIndex]) {
        allFacilityNotes[notesCurrentFacilityIndex] = {};
    }
    return allFacilityNotes[notesCurrentFacilityIndex];
}

// Update when facility changes
function updateCurrentFacility() {
    // Get current facility index from the external system
    if (typeof window.currentFacilityIndex !== 'undefined') {
        notesCurrentFacilityIndex = window.currentFacilityIndex;
    }
    // Refresh note buttons for the new facility
    setTimeout(() => {
        addNoteButtons();
    }, 100);
}

// Load notes from current facility data (integrates with existing system)
function loadFieldNotes() {
    try {
        // Update current facility index
        updateCurrentFacility();

        // Try to get notes from the current facility data if it exists
        if (typeof window.getCurrentFacilityData === 'function') {
            const facilityData = window.getCurrentFacilityData();
            if (facilityData && facilityData.fieldNotes) {
                allFacilityNotes[notesCurrentFacilityIndex] = facilityData.fieldNotes;
                return;
            }
        }

        // Fallback to localStorage if facility system not available
        const stored = localStorage.getItem('fieldNotes');
        if (stored) {
            fieldNotes = JSON.parse(stored);
        }
    } catch (e) {
        console.warn('Failed to load field notes:', e);
        fieldNotes = {};
    }
}

// Save notes to facility data (integrates with existing cloud save system)
function saveFieldNotes() {
    try {
        const currentNotes = getCurrentFacilityNotes();

        // Save to facility data structure if available
        if (typeof window.updateCurrentFacilityData === 'function') {
            window.updateCurrentFacilityData({ fieldNotes: currentNotes });
        } else if (typeof window.saveCurrentFacility === 'function') {
            // Try alternative save function
            const facilityData = window.getCurrentFacilityData ? window.getCurrentFacilityData() : {};
            facilityData.fieldNotes = currentNotes;
            window.saveCurrentFacility(facilityData);
        } else {
            // If facility functions not available, trigger any existing save mechanism
            // and also save to localStorage as backup
            if (typeof window.triggerSave === 'function') {
                window.triggerSave();
            }
            localStorage.setItem('fieldNotes', JSON.stringify(fieldNotes));
        }

        // Also save to localStorage as backup
        localStorage.setItem('fieldNotes', JSON.stringify(fieldNotes));

        // Trigger any existing auto-save mechanism
        if (typeof window.autoSave === 'function') {
            window.autoSave();
        }

    } catch (e) {
        console.warn('Failed to save field notes to cloud, saving locally:', e);
        localStorage.setItem('fieldNotes', JSON.stringify(fieldNotes));
    }
}

// Generate truly unique field identifier
function getFieldIdentifier(element) {
    // If element already has a unique note ID, use it
    if (element.dataset.noteId) {
        return element.dataset.noteId;
    }

    // Create a unique ID and store it on the element
    const uniqueId = `field-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
    element.dataset.noteId = uniqueId;
    return uniqueId;
}

// Add note buttons specifically to array items
function addNoteButtonsToArrayItems(group) {
    try {
        const arrayItems = group.querySelectorAll('.array-item');

        arrayItems.forEach(arrayItem => {
            if (arrayItem.querySelector('.field-note-btn')) {
                return;
            }

            const field = arrayItem.querySelector('input:not([type="hidden"]):not([style*="display: none"]), textarea:not([style*="display: none"]), select:not([style*="display: none"])');
            if (!field) {
                return;
            }

            if (field.type === 'hidden') {
                return;
            }

            const fieldGroup = arrayItem.closest('.form-group') || group;

            const noteBtn = document.createElement('button');
            noteBtn.type = 'button';
            noteBtn.className = 'field-note-btn';
            noteBtn.innerHTML = '+';
            noteBtn.title = 'Add note for this field';

            const fieldIdentifier = getFieldIdentifier(field);
            const currentNotes = getCurrentFacilityNotes();
            if (currentNotes[fieldIdentifier]) {
                noteBtn.classList.add('has-note');
                noteBtn.title = 'Edit note for this field';
            }

            noteBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                const renderedNote = createFieldNote(field, fieldGroup);
                if (renderedNote) {
                    const noteInput = renderedNote.querySelector('.note-input');
                    if (noteInput) {
                        noteInput.focus();
                    }
                }
            }, { passive: false });

            noteBtn.addEventListener('mousedown', (e) => {
                e.stopPropagation();
            }, { passive: true });

            noteBtn.addEventListener('mouseup', (e) => {
                e.stopPropagation();
            }, { passive: true });

            arrayItem.appendChild(noteBtn);
            arrayItem.classList.add('has-note-button');
        });
    } catch (error) {
        console.warn('Error adding note buttons to array items:', error);
    }
}

// Add note button to form groups
function addNoteButtons() {
    debugLog('addNoteButtons function called');
    const formGroups = document.querySelectorAll('.form-group');
    debugLog('Found', formGroups.length, 'form groups');

    formGroups.forEach(group => {
        // Skip if button already exists
        if (group.querySelector('.field-note-btn')) {
            return;
        }

        // First, handle array items within this group
        addNoteButtonsToArrayItems(group);

        // Then handle the main field if it's not in an array item
        // Find input, textarea, or select in this group (including deeply nested in wrappers)
        // Look for the primary interactive field, prioritizing visible ones
        let field = group.querySelector('input:not([type="hidden"]):not([style*="display: none"]), textarea:not([style*="display: none"]), select:not([style*="display: none"])');

        // Skip if this field is already inside an array item (handled above)
        if (field && field.closest('.array-item')) {
            return;
        }

        // If no visible field found, try any field as fallback
        if (!field) {
            field = group.querySelector('input, textarea, select');
        }

        if (!field) {
            return;
        }

        // Skip certain field types
        if (field.type === 'hidden' || field.style.display === 'none') {
            return;
        }

        // Skip note fields themselves (they don't need + buttons)
        const fieldId = field.id || '';
        const dataField = field.dataset.field || '';
        if (fieldId.endsWith('-notes') || dataField === 'notes' || dataField.endsWith('.notes')) {
            return;
        }

        // Skip utility/action fields that don't need notes (be more selective)
        const skipFieldIds = [
            'import-file',
            'json-data',
            'toc-toggle',
            'facility-counter',
            'project-name',
            'organize-by',
            'organize-value'
        ];

        if (skipFieldIds.includes(fieldId)) {
            return;
        }

        // Skip fields within project management section
        if (field.closest('.project-management')) {
            return;
        }

        // Skip fields within data organizer section
        if (field.closest('#data-organizer-section')) {
            return;
        }

        // Skip fields with certain labels that are utility functions (be more selective)
        const label = group.querySelector('label');
        if (label) {
            const labelText = label.textContent.toLowerCase();
            const skipLabels = [
                'import data',
                'paste json',
                'file upload',
                'import file',
                'project management',
                'saved projects'
            ];

            if (skipLabels.some(skipLabel => labelText.includes(skipLabel))) {
                return;
            }
        }

        // Create note button
        const noteBtn = document.createElement('button');
        noteBtn.type = 'button';
        noteBtn.className = 'field-note-btn';
        noteBtn.innerHTML = '+';
        noteBtn.title = 'Add note for this field';

        // Check if this field has a note
        const fieldIdentifier = getFieldIdentifier(field);
        const currentNotes = getCurrentFacilityNotes();
        if (currentNotes[fieldIdentifier]) {
            noteBtn.classList.add('has-note');
            noteBtn.title = 'Edit note for this field';
        }

        // Add click handler
        noteBtn.addEventListener('click', (e) => {
            debugLog('✅ Note button clicked! Field:', field, 'Group:', group);
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            const result = createFieldNote(field, group);
            debugLog('✅ createFieldNote returned:', result);
        }, { passive: false });

        // Prevent button from interfering with input events
        noteBtn.addEventListener('mousedown', (e) => {
            e.stopPropagation();
        }, { passive: true });

        noteBtn.addEventListener('mouseup', (e) => {
            e.stopPropagation();
        }, { passive: true });

        // Add button inside the same container as the field

        // Check if this field is inside an array-item (dynamic array field)
        const arrayItem = field.closest('.array-item');
        if (arrayItem) {
            // For array items, add the button directly to the array-item flex container
            debugLog('Adding button to array item for field:', field);
            arrayItem.appendChild(noteBtn);
        } else {
            // Find the appropriate inner container (autocomplete-wrapper)
            const innerContainer = field.closest('.autocomplete-wrapper');

            if (innerContainer) {
                // For autocomplete-wrapper, add button directly to it
                innerContainer.style.display = 'flex';
                innerContainer.style.alignItems = 'flex-start';
                innerContainer.style.gap = '8px';

                // Make the field take remaining space
                field.style.flex = '1';
                field.style.minWidth = '0';
                field.style.width = 'auto';

                // Add the button to the inner container
                innerContainer.appendChild(noteBtn);
            } else {
                // Fallback: create field-content wrapper as before
                let fieldContent = group.querySelector('.field-content');
                if (!fieldContent) {
                    fieldContent = document.createElement('div');
                    fieldContent.className = 'field-content';

                    // Move all existing children except label to the field-content wrapper
                    const label = group.querySelector('label');
                    const children = Array.from(group.children);

                    children.forEach(child => {
                        if (child !== label) {
                            fieldContent.appendChild(child);
                        }
                    });

                    // Add the field-content wrapper to the group
                    group.appendChild(fieldContent);
                }

                // Create a horizontal wrapper for the field and plus button
                let fieldInputWrapper = group.querySelector('.field-input-wrapper');
                if (!fieldInputWrapper) {
                    fieldInputWrapper = document.createElement('div');
                    fieldInputWrapper.className = 'field-input-wrapper';

                    // Move the field into the wrapper
                    fieldInputWrapper.appendChild(field);

                    // Insert wrapper as first child of field-content
                    fieldContent.insertBefore(fieldInputWrapper, fieldContent.firstChild);
                }

                // Add the note button to the wrapper (inline with field)
                fieldInputWrapper.appendChild(noteBtn);
            }
        }

        // Add class to form group to apply proper styling
        group.classList.add('has-note-button');
    });
}

// Add note field below the input field (supports multiple notes)
function createFieldNote(field, group) {
    debugLog('🔵 createFieldNote START - field:', field, 'group:', group);
    const fieldId = getFieldIdentifier(field);
    debugLog('🔵 fieldId:', fieldId);
    const label = group.querySelector('label');
    const fieldName = label ? label.textContent.trim() : 'Field';
    debugLog('🔵 fieldName:', fieldName);

    // Create a new note container
    const noteContainer = document.createElement('div');
    noteContainer.className = 'note-container';
    noteContainer.dataset.fieldId = fieldId;
    if (field.type === 'checkbox') {
        noteContainer.classList.add('checkbox-note');
        noteContainer.style.marginLeft = '30px';
    }
    noteContainer.style.marginTop = '8px';
    noteContainer.style.marginBottom = '8px';
    noteContainer.style.padding = '8px';
    noteContainer.style.border = '1px solid #e5e7eb';
    noteContainer.style.borderRadius = '4px';
    noteContainer.style.backgroundColor = '#f9fafb';

    const noteHeader = document.createElement('div');
    noteHeader.style.display = 'flex';
    noteHeader.style.justifyContent = 'space-between';
    noteHeader.style.alignItems = 'center';
    noteHeader.style.marginBottom = '4px';

    const noteLabel = document.createElement('label');
    noteLabel.textContent = `${fieldName} Note`;
    noteLabel.style.fontSize = '13px';
    noteLabel.style.color = '#6b7280';
    noteLabel.style.margin = '0';

    // Add a remove button for this specific note
    const removeNoteBtn = document.createElement('button');
    removeNoteBtn.type = 'button';
    removeNoteBtn.innerHTML = '×';
    removeNoteBtn.style.background = 'none';
    removeNoteBtn.style.border = 'none';
    removeNoteBtn.style.color = '#9ca3af';
    removeNoteBtn.style.cursor = 'pointer';
    removeNoteBtn.style.fontSize = '16px';
    removeNoteBtn.style.padding = '0';
    removeNoteBtn.style.width = '20px';
    removeNoteBtn.style.height = '20px';
    removeNoteBtn.title = 'Remove this note';

    const noteInput = document.createElement('input');
    noteInput.type = 'text';
    noteInput.className = 'note-input';
    noteInput.placeholder = 'Add notes or context for this field...';
    noteInput.style.fontSize = '14px';
    noteInput.style.padding = '8px 12px';
    noteInput.style.width = '100%';
    noteInput.style.border = '1px solid #d1d5db';
    noteInput.style.borderRadius = '4px';

    // Generate a unique note ID
    const noteId = `${fieldId}_note_${Date.now()}`;

    // Save note on input
    noteInput.addEventListener('input', () => {
        const noteText = noteInput.value.trim();
        const currentNotes = getCurrentFacilityNotes();

        // Ensure currentNotes[fieldId] is an array
        if (!currentNotes[fieldId]) {
            currentNotes[fieldId] = [];
        } else if (!Array.isArray(currentNotes[fieldId])) {
            // Convert old string-based notes to array format
            const oldNote = currentNotes[fieldId];
            currentNotes[fieldId] = [{
                id: `${fieldId}_note_${Date.now()}_legacy`,
                text: oldNote,
                timestamp: new Date().toISOString()
            }];
        }

        // Find this note in the array and update it
        const existingNoteIndex = currentNotes[fieldId].findIndex(note => note.id === noteId);
        if (noteText) {
            const noteData = { id: noteId, text: noteText, timestamp: new Date().toISOString() };
            if (existingNoteIndex >= 0) {
                currentNotes[fieldId][existingNoteIndex] = noteData;
            } else {
                currentNotes[fieldId].push(noteData);
            }
        } else {
            // Remove empty note
            if (existingNoteIndex >= 0) {
                currentNotes[fieldId].splice(existingNoteIndex, 1);
            }
            if (currentNotes[fieldId].length === 0) {
                delete currentNotes[fieldId];
            }
        }

        saveFieldNotes();
        updateNoteButton(field);

        // Trigger any existing form change detection
        if (typeof window.onFormChange === 'function') {
            window.onFormChange();
        }

        // Dispatch a custom event for form changes
        const facilityNotes = getCurrentFacilityNotes();
        const changeEvent = new CustomEvent('facilityDataChanged', {
            detail: { type: 'fieldNote', fieldId: fieldId, value: facilityNotes[fieldId] }
        });
        document.dispatchEvent(changeEvent);
    }, { passive: true });

    // Remove note handler
    removeNoteBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();

        const currentNotes = getCurrentFacilityNotes();

        // Remove this specific note from the array
        if (currentNotes[fieldId]) {
            // Ensure it's an array before calling findIndex
            if (!Array.isArray(currentNotes[fieldId])) {
                // Convert old string format to array and remove
                delete currentNotes[fieldId];
            } else {
                const noteIndex = currentNotes[fieldId].findIndex(note => note.id === noteId);
                if (noteIndex >= 0) {
                    currentNotes[fieldId].splice(noteIndex, 1);
                }
                if (currentNotes[fieldId].length === 0) {
                    delete currentNotes[fieldId];
                }
            }
        }

        saveFieldNotes();
        updateNoteButton(field);
        noteContainer.remove();

        // Trigger form change detection
        if (typeof window.onFormChange === 'function') {
            window.onFormChange();
        }
    }, { passive: false });

    noteHeader.appendChild(noteLabel);
    noteHeader.appendChild(removeNoteBtn);
    noteContainer.appendChild(noteHeader);
    noteContainer.appendChild(noteInput);

    // Position notes BELOW the field (on the next line), not inline
    // For all fields, insert the note container after the field's parent wrapper

    const arrayItem = field.closest('.array-item');
    if (arrayItem) {
        // For array items (like administrators), insert note after the entire array item
        // Check if there are already notes after this array item
        const existingNotes = [];
        let sibling = arrayItem.nextElementSibling;
        while (sibling && sibling.classList?.contains('note-container') && sibling.dataset.fieldId === fieldId) {
            existingNotes.push(sibling);
            sibling = sibling.nextElementSibling;
        }

        if (existingNotes.length > 0) {
            // Insert after the last existing note for this field
            existingNotes[existingNotes.length - 1].insertAdjacentElement('afterend', noteContainer);
        } else {
            // Insert right after the array item
            arrayItem.insertAdjacentElement('afterend', noteContainer);
        }
    } else if (field.type === 'checkbox') {
        // For checkboxes, insert after the checkbox group or field
        const checkboxGroup = field.closest('.checkbox-group');
        const insertionBase = checkboxGroup || field;
        let lastNote = insertionBase;
        let sibling = insertionBase.nextElementSibling;
        while (sibling && sibling.classList?.contains('note-container') && sibling.dataset.fieldId === fieldId) {
            lastNote = sibling;
            sibling = sibling.nextElementSibling;
        }
        lastNote.insertAdjacentElement('afterend', noteContainer);
    } else {
        // For regular fields, insert after the field's parent container
        const wrapper = field.closest('.autocomplete-wrapper') || field.closest('.field-content') || field.parentElement;

        // Check for existing notes after this wrapper
        const existingNotes = [];
        let sibling = wrapper.nextElementSibling;
        while (sibling && sibling.classList?.contains('note-container') && sibling.dataset.fieldId === fieldId) {
            existingNotes.push(sibling);
            sibling = sibling.nextElementSibling;
        }

        if (existingNotes.length > 0) {
            // Insert after the last existing note for this field
            existingNotes[existingNotes.length - 1].insertAdjacentElement('afterend', noteContainer);
        } else {
            // Insert right after the wrapper
            wrapper.insertAdjacentElement('afterend', noteContainer);
        }
    }

    // Focus the note input
    noteInput.focus();

    updateNoteButton(field);
    return noteContainer;
}

// Update note button appearance (simplified)
function updateNoteButton(field) {
    const fieldId = getFieldIdentifier(field);
    const formGroup = field.closest('.form-group');
    const noteBtn = formGroup?.querySelector('.field-note-btn');

    if (noteBtn) {
        const currentNotes = getCurrentFacilityNotes();
        const notes = currentNotes[fieldId];
        if (notes && Array.isArray(notes) && notes.length > 0) {
            noteBtn.classList.add('has-note');
            noteBtn.title = 'Has notes - Click to add another';
            noteBtn.innerHTML = '+';
        } else {
            noteBtn.classList.remove('has-note');
            noteBtn.title = 'Add note for this field';
            noteBtn.innerHTML = '+';
        }
    }
}

function getRenderedNotesForField(field) {
    const fieldId = getFieldIdentifier(field);
    return Array.from(document.querySelectorAll(`.note-container[data-field-id="${fieldId}"]`));
}

function ensureCheckboxNote(checkbox) {
    const group = checkbox.closest('.form-group') || checkbox.closest('.checkbox-group') || checkbox.parentElement;
    if (!group) {
        return;
    }

    const existingNotes = getRenderedNotesForField(checkbox);
    if (existingNotes.length > 0) {
        const lastNoteInput = existingNotes[existingNotes.length - 1].querySelector('.note-input');
        if (lastNoteInput) {
            lastNoteInput.focus();
        }
        return;
    }

    const noteContainer = createFieldNote(checkbox, group);
    if (noteContainer) {
        const noteInput = noteContainer.querySelector('.note-input');
        if (noteInput) {
            noteInput.focus();
        }
    }
}

function initializeCheckboxNoteTriggers() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"]');
    checkboxes.forEach(checkbox => {
        if (checkbox.dataset.noteAutoInit === 'true') {
            return;
        }

        checkbox.dataset.noteAutoInit = 'true';

        checkbox.addEventListener('change', () => {
            if (checkbox.checked) {
                ensureCheckboxNote(checkbox);
            }
        }, { passive: true });

        if (checkbox.checked) {
            ensureCheckboxNote(checkbox);
        }
    });
}

// Initialize field notes functionality
function initializeFieldNotes() {
    loadFieldNotes();

    // Add note buttons to existing form groups
    addNoteButtons();
    initializeCheckboxNoteTriggers();

    // Load existing notes and create inline fields
    setTimeout(() => {
        const currentNotes = getCurrentFacilityNotes();
        Object.keys(currentNotes).forEach(fieldId => {
            const field = document.querySelector(`#${fieldId}`) ||
                          document.querySelector(`[name="${fieldId}"]`) ||
                          Array.from(document.querySelectorAll('input, textarea, select')).find(f => getFieldIdentifier(f) === fieldId);

            if (field) {
                const group = field.closest('.form-group');
                if (group && !group.querySelector('.note-container')) {
                    createFieldNote(field, group);
                }
            }
        });
    }, 500);

    // Re-add buttons when content changes (for dynamic content)
    const observer = new MutationObserver(() => {
        setTimeout(() => {
            addNoteButtons();
            initializeCheckboxNoteTriggers();
        }, 100);
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    debugLog('Field notes functionality initialized');
}

// Function to sync notes when facility data changes (called by external scripts)
function syncFieldNotes(facilityData) {
    if (facilityData && facilityData.fieldNotes) {
        const currentNotes = getCurrentFacilityNotes();

        // Migrate old string-based notes to new array format
        Object.keys(facilityData.fieldNotes).forEach(fieldId => {
            const noteValue = facilityData.fieldNotes[fieldId];
            if (typeof noteValue === 'string' && noteValue.trim()) {
                // Convert old string notes to new array format
                currentNotes[fieldId] = [{
                    id: `${fieldId}_note_${Date.now()}`,
                    text: noteValue,
                    timestamp: new Date().toISOString()
                }];
            } else if (Array.isArray(noteValue)) {
                // Keep new array format as is
                currentNotes[fieldId] = noteValue;
            }
        });

        // Update existing note fields and buttons
        Object.keys(currentNotes).forEach(fieldId => {
            const field = document.querySelector(`#${fieldId}`) ||
                          document.querySelector(`[name="${fieldId}"]`) ||
                          Array.from(document.querySelectorAll('input, textarea, select')).find(f => getFieldIdentifier(f) === fieldId);

            if (field) {
                updateNoteButton(field);
            }
        });

        saveFieldNotes();
    }
}

// Function to get current field notes (for external scripts)
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
window.deleteProject = deleteProject;
window.cloneFacility = cloneFacility;
window.previousFacility = previousFacility;
window.nextFacility = nextFacility;
window.sortFacilities = sortFacilities;
window.navigateToFacility = navigateToFacility;
window.copyToClipboard = copyToClipboard;
window.downloadJSON = downloadJSON;
window.refreshSavedProjectPanels = refreshSavedProjectPanels;
window.initializeSectionToggles = initializeSectionToggles;

// ============================================
// CONSULTANTS OVERVIEW (for Referrers view)
// ============================================

function updateConsultantsOverview() {
    const consultantsList = document.getElementById('consultants-list');
    const consultantsStats = document.getElementById('consultants-toc-stats');

    if (!consultantsList || !consultantsStats) return;

    const consultants = window.formData?.referrerConsultants || [];
    const currentIndex = window.currentConsultantIndex || 0;

    // Update stats
    consultantsStats.textContent = `Total: ${consultants.length} consultant${consultants.length !== 1 ? 's' : ''}`;

    // Clear list
    consultantsList.innerHTML = '';

    // Populate consultant items
    consultants.forEach((consultant, index) => {
        const fullName = [consultant.firstName, consultant.lastName].filter(Boolean).join(' ') || 'Unnamed Consultant';
        const location = [consultant.city, consultant.state].filter(Boolean).join(', ') || 'Location not specified';

        const item = document.createElement('div');
        item.className = 'facility-item' + (index === currentIndex ? ' active' : '');

        const div = document.createElement('div');
        div.textContent = fullName;
        const nameEscaped = div.innerHTML;

        const div2 = document.createElement('div');
        div2.textContent = location;
        const locationEscaped = div2.innerHTML;

        item.innerHTML = `
            <div class="facility-item-number">${index + 1}</div>
            <div class="facility-item-info">
                <div class="facility-item-name">${nameEscaped}</div>
                <div class="facility-item-details">${locationEscaped}</div>
            </div>
        `;

        item.addEventListener('click', function() {
            window.currentConsultantIndex = index;
            loadConsultantData();
            updateConsultantsOverview();
        }, { passive: true });

        consultantsList.appendChild(item);
    });
}

function loadConsultantData() {
    if (!window.formData.referrerConsultants || window.formData.referrerConsultants.length === 0) {
        window.formData.referrerConsultants = [{
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
        }];
        window.currentConsultantIndex = 0;
    }

    const consultant = window.formData.referrerConsultants[window.currentConsultantIndex];

    // Load basic fields
    const fields = {
        'consultant-firstname': 'firstName',
        'consultant-lastname': 'lastName',
        'consultant-credentials': 'credentials',
        'consultant-city': 'city',
        'consultant-state': 'state',
        'consultant-email': 'email',
        'consultant-phone': 'phone',
        'consultant-website': 'website',
        'consultant-notes': 'notes'
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
}

function updateConsultantDropdown() {
    const dropdown = document.getElementById('consultant-dropdown');
    if (!dropdown) return;

    const consultants = window.formData?.referrerConsultants || [];
    dropdown.innerHTML = '';

    consultants.forEach((consultant, index) => {
        const option = document.createElement('option');
        option.value = index;
        const fullName = [consultant.firstName, consultant.lastName].filter(Boolean).join(' ') || 'New Consultant';
        option.textContent = `${index + 1}. ${fullName}`;
        dropdown.appendChild(option);
    });

    dropdown.value = window.currentConsultantIndex || 0;
}

function updateConsultantsUI() {
    loadConsultantData();
    updateConsultantsOverview();
    updateConsultantDropdown();
    if (typeof updateJSON === 'function') updateJSON();
    if (typeof autoSave === 'function') autoSave();
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

        item.addEventListener('click', function() {
            window.currentFacilityIndex = index;
            if (typeof window.updateAllUI === 'function') {
                window.updateAllUI();
            }
            updateLocationFacilitiesOverview();
        }, { passive: true });

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
        addConsultantBtn.addEventListener('click', function() {
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
        }, { passive: true });
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
        }, { passive: true });
        removeConsultantBtn.dataset.listenerAttached = 'true';
    }

    if (prevConsultantBtn && !prevConsultantBtn.dataset.listenerAttached) {
        prevConsultantBtn.addEventListener('click', function() {
            if (window.currentConsultantIndex > 0) {
                window.currentConsultantIndex--;
                loadConsultantData();
                updateConsultantsOverview();
            }
        }, { passive: true });
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
        }, { passive: true });
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
    window.addEventListener('load', initializeActiveTabOverview, { passive: true });

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
    }, { passive: true });
}

// ============================================
// TOC TOGGLE INITIALIZATION
// ============================================

function initializeConsultantsTocToggle() {
    const consultantsTocToggle = document.getElementById('consultants-toc-toggle-btn');
    if (consultantsTocToggle && !consultantsTocToggle.dataset.listenerAttached) {
        consultantsTocToggle.addEventListener('click', function() {
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
        locationFacilitiesTocToggle.addEventListener('click', function() {
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
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeForm, { passive: true });
} else {
    initializeForm();
}

// ============================================
// FIXED TOOLBAR INITIALIZATION (for data.html)
// ============================================
function initializeFixedToolbar() {
    console.log('🔧 initializeFixedToolbar() called');
    const toolbar = document.getElementById('fixed-toolbar');
    const toolbarToggle = document.getElementById('toolbar-toggle-btn');

    console.log('Toolbar elements:', { toolbar: !!toolbar, toolbarToggle: !!toolbarToggle });

    if (!toolbar || !toolbarToggle) {
        console.warn('⚠️ Fixed toolbar elements not found (likely not on data.html page)');
        return;
    }

    // Add toolbar-active class to body for proper padding
    document.body.classList.add('toolbar-active');
    console.log('✅ Added toolbar-active class to body');
    console.log('Body classes:', document.body.className);

    // Set up toggle event listener
    toolbarToggle.addEventListener('click', (e) => {
        console.log('🖱️ Toolbar toggle clicked!');
        e.preventDefault();
        e.stopPropagation();

        toolbar.classList.toggle('minimized');
        document.body.classList.toggle('toolbar-minimized');
        toolbarToggle.textContent = toolbar.classList.contains('minimized') ? '▼' : '−';
        toolbarToggle.title = toolbar.classList.contains('minimized') ? 'Expand toolbar' : 'Minimize toolbar';

        console.log(`Toolbar is now: ${toolbar.classList.contains('minimized') ? 'MINIMIZED' : 'EXPANDED'}`);
        console.log('Toolbar classes:', toolbar.className);
        console.log('Body classes:', document.body.className);
    });

    console.log('✅ Fixed toolbar initialized with toggle functionality');
    console.log('Initial toolbar classes:', toolbar.className);
}

// Make function globally accessible
window.initializeFixedToolbar = initializeFixedToolbar;

// BACKUP: Try to initialize toolbar immediately when DOM is ready
console.log('⚡ Setting up toolbar initialization backups...');
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        console.log('⚡ DOMContentLoaded - attempting toolbar init');
        setTimeout(initializeFixedToolbar, 100);
    });
} else {
    console.log('⚡ DOM already loaded - attempting toolbar init now');
    setTimeout(initializeFixedToolbar, 100);
}

// Also try on window load as final backup
window.addEventListener('load', () => {
    console.log('⚡ Window load - attempting toolbar init');
    setTimeout(initializeFixedToolbar, 100);
});

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
            if (typeof window.updateAllUI === 'function') {
                window.updateAllUI();
                debugLog('✅ facility-form.v3.js: updateAllUI re-run on load (once)');
            }
        } catch (e) {
            console.error('❌ facility-form.v3.js: error during load-time UI verification', e);
        }
    }, 150);
}, { passive: true });