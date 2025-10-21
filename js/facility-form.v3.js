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

function logActiveFacilityFormConfigOnce() {
    if (typeof window === 'undefined') {
        return;
    }

    if (window.__KOP_FACILITY_FORM_CONFIG_LOGGED) {
        return;
    }

    window.__KOP_FACILITY_FORM_CONFIG_LOGGED = true;

    if (typeof console === 'undefined' || typeof console.info !== 'function') {
        return;
    }

    try {
        console.info('[KOP Facility Form] Loaded script build %s', SCRIPT_BUILD_VERSION);
        if (normalizedApiBases.length) {
            console.info('[KOP Facility Form] API base candidates:', normalizedApiBases);
        }
        console.info('[KOP Facility Form] Resolved API endpoints:', API_ENDPOINTS);
        console.info('[KOP Facility Form] Active form mode:', FORM_MODE);
        if (FALLBACK_PROJECTS_URL_CANDIDATES.length) {
            console.info('[KOP Facility Form] Fallback dataset URL candidates:', FALLBACK_PROJECTS_URL_CANDIDATES);
        } else {
            console.info('[KOP Facility Form] No fallback dataset configured');
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
    try {
        customOperators = JSON.parse(localStorage.getItem('customOperators') || '[]');
        customFacilityNames = JSON.parse(localStorage.getItem('customFacilityNames') || '[]');
        customHumanNames = JSON.parse(localStorage.getItem('customHumanNames') || '[]');
        customFacilityTypes = JSON.parse(localStorage.getItem('customFacilityTypes') || '[]');
        customCertifications = JSON.parse(localStorage.getItem('customCertifications') || '[]');
        customAccreditations = JSON.parse(localStorage.getItem('customAccreditations') || '[]');
        customMemberships = JSON.parse(localStorage.getItem('customMemberships') || '[]');
        customLicensing = JSON.parse(localStorage.getItem('customLicensing') || '[]');
        customInvestors = JSON.parse(localStorage.getItem('customInvestors') || '[]');
        customStaffRoles = JSON.parse(localStorage.getItem('customStaffRoles') || '[]');
        customStatuses = JSON.parse(localStorage.getItem('customStatuses') || '[]');
        customGenders = JSON.parse(localStorage.getItem('customGenders') || '[]');
        customLocations = JSON.parse(localStorage.getItem('customLocations') || '[]');
        customOperatingPeriods = JSON.parse(localStorage.getItem('customOperatingPeriods') || '[]');
    } catch (e) {
        console.warn('Failed to load custom data from localStorage:', e);
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
        console.log('✅ Autocomplete already initialized for', input.id || input.name);
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
    
    function showDropdown(items) {
        dropdown.innerHTML = '';
        dropdown.style.display = 'block';
        
        if (items.length === 0) {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = 'autocomplete-item';
            emptyDiv.textContent = 'No matches found';
            emptyDiv.style.color = '#9ca3af';
            dropdown.appendChild(emptyDiv);
            return;
        }
        
        items.forEach((item, index) => {
            const div = document.createElement('div');
            div.className = 'autocomplete-item';
            
            const inputValue = input.value.toLowerCase();
            const itemLower = item.toLowerCase();
            const startIdx = itemLower.indexOf(inputValue);
            
            if (startIdx >= 0) {
                const before = item.substring(0, startIdx);
                const match = item.substring(startIdx, startIdx + input.value.length);
                const after = item.substring(startIdx + input.value.length);
                div.innerHTML = `${escapeHtmlForAttr(before)}<strong>${escapeHtmlForAttr(match)}</strong>${escapeHtmlForAttr(after)}`;
            } else {
                div.textContent = item;
            }
            
            div.addEventListener('click', () => {
                input.value = item;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                dropdown.style.display = 'none';
                currentFocus = -1;
            });
            
            dropdown.appendChild(div);
        });
    }
    
    function hideDropdown() {
        dropdown.style.display = 'none';
        currentFocus = -1;
    }
    
    input.addEventListener('input', () => {
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
                    console.log(`✅ Autocomplete loaded ${json.values.length} remote suggestions for "${category}"`);
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
    });
    
    input.addEventListener('focus', () => {
        if (input.value.trim()) {
            input.dispatchEvent(new Event('input'));
        }
    });
    
    input.addEventListener('blur', () => {
        setTimeout(hideDropdown, 200);
    });
    
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
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (currentFocus > -1 && items[currentFocus]) {
                items[currentFocus].click();
            } else {
                // Add as new custom value
                if (input.value.trim() && category) {
                    addCustomValue(category, input.value.trim());
                }
            }
        } else if (e.key === 'Escape') {
            hideDropdown();
        }
    });
    
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
    console.log('✅ Autocomplete initialized for', category, 'on', input.id || input.name || 'unnamed input');
}

function initializeAutocompleteFields() {
    const categoryFunctions = {
        operator: getAllOperators,
        facility: getAllFacilityNames,
        human: getAllHumanNames,
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
        });

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'remove-note-btn';
        removeBtn.textContent = '−';
        removeBtn.addEventListener('click', () => {
            removeFieldNote(scope, key, index);
        });

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
    noteFieldRegistry = [];

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
                addBtn.dataset.noteEventAttached = 'true';
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
            console.log('Loaded projects from cloud:', Object.keys(projects));

            // Force re-initialize autocomplete after cloud data loads
            setTimeout(() => {
                console.log('Re-initializing autocomplete with cloud data...');
                // Clear autocomplete init flags to allow re-initialization
                document.querySelectorAll('input[data-autocomplete-category]').forEach(field => {
                    delete field.dataset.autocompleteInit;
                });
                initializeAutocompleteFields();

                // Also re-populate the project select dropdowns
                console.log('Re-populating project select dropdowns...');
                populateProjectSelectDropdowns();
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
            console.info('Loaded projects from fallback dataset:', fallbackLoadResult.url);
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

    const snapshot = {
        name: projectName,
        data: deepClone(window.formData),
        currentFacilityIndex: window.currentFacilityIndex,
        timestamp: new Date().toISOString()
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

async function saveProjectToCloud(projectName) {
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
            statusMessage: '💾 Draft saved locally. Use “Submit Suggestion for Review” to send updates to Kids Over Profits.'
        });

        if (!saved) {
            showUploadStatus('❌ Unable to save draft locally. Please try again.', 'error');
        } else {
            console.info('Suggestion mode active — skipping remote save for project "%s".', projectName);
        }

        return false;
    }

    try {
        showUploadStatus(`💾 Saving "${projectName}" to cloud...`, 'info');
        console.log('=== SAVE PROJECT START ===');
        console.log('Project name:', projectName);
        console.log('Facility count:', window.formData.facilities?.length || 0);
        console.log('Data size:', JSON.stringify(window.formData).length, 'characters');

        const projectData = {
            name: projectName,
            data: deepClone(window.formData),
            currentFacilityIndex: window.currentFacilityIndex,
            timestamp: new Date().toISOString()
        };

        const payload = {
            projectName: projectName,
            data: projectData.data,
            action: 'save'
        };

        const payloadSize = JSON.stringify(payload).length;
        console.log('Payload size:', payloadSize, 'characters');
        console.log('Sending to:', API_ENDPOINTS.SAVE_PROJECT);

        const response = await fetch(API_ENDPOINTS.SAVE_PROJECT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        console.log('Response status:', response.status, response.statusText);
        console.log('Response headers:', Object.fromEntries(response.headers.entries()));

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
        console.log('Save result:', result);

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

        console.log('✅ Save successful!');
        console.log('=== SAVE PROJECT END ===');
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
            console.log('✅ Saved to localStorage backup');
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
                console.log('Suggestion draft saved locally for', window.currentProjectName);
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

// ============================================
// PROJECT MANAGEMENT
// ============================================
function createNewProjectData() {
    return {
        operator: {
            name: "", currentName: "", otherNames: [], location: "", headquarters: "",
            founded: "", operatingPeriod: "", status: "", parentCompanies: [],
            websites: [], investors: [], keyStaff: { ceo: "", founders: [], keyExecutives: [] },
            notes: [], fieldNotes: {}
        },
        facilities: [{
            identification: { name: "", currentName: "", currentOperator: "", otherNames: [] },
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
        fieldNotes: {}
    };
}

function loadProject(projectName) {
    console.log('🔄 loadProject called with:', projectName);
    console.log('📦 Available projects:', Object.keys(window.projects || {}));

    if (!window.projects[projectName]) {
        console.error('❌ Project not found:', projectName);
        showUploadStatus(`Project "${projectName}" not found.`, 'error');
        return;
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

    if (typeof window.updateAllUI === 'function') {
        console.log('🔄 Calling updateAllUI...');
        window.updateAllUI();
    } else {
        console.error('❌ updateAllUI not available!');
    }

    showUploadStatus(`Project "${projectName}" loaded (${window.formData.facilities.length} facilities)`, 'success');
}

function newProject() {
    if (!confirm('Start a new blank project? Any unsaved changes will be lost.')) return;

    window.currentProjectName = null;
    window.formData = createNewProjectData();
    window.currentFacilityIndex = 0;

    const projectNameInput = document.getElementById('project-name');
    if (projectNameInput) {
        projectNameInput.value = '';
    }

    if (typeof window.updateAllUI === 'function') {
        window.updateAllUI();
    }

    showUploadStatus('New project created', 'info');
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
    const target = path.startsWith('operator.') ? window.formData.operator : window.formData.facilities[window.currentFacilityIndex];
    const array = getNestedValue(target, path.replace('operator.', ''));
    if (Array.isArray(array) && index >= 0 && index < array.length) {
        array[index] = value;
        updateJSON();
        autoSave();
    }
}

function updateArrayObjectItemValue(path, index, field, value) {
    const target = path.startsWith('operator.') ? window.formData.operator : window.formData.facilities[window.currentFacilityIndex];
    const array = getNestedValue(target, path.replace('operator.', ''));
    if (Array.isArray(array) && index >= 0 && index < array.length) {
        if (typeof array[index] !== 'object' || array[index] === null) {
            array[index] = { role: '', name: '' };
        }
        array[index][field] = value;
        
        // Save custom human names and roles
        if (field === 'name' && value.trim()) {
            addCustomValue('human', value.trim());
        }
        if (field === 'role' && value.trim()) {
            addCustomValue('role', value.trim());
        }
        
        updateJSON();
        autoSave();
    }
}

function addNewArrayItem(path) {
    const target = path.startsWith('operator.') ? window.formData.operator : window.formData.facilities[window.currentFacilityIndex];
    const array = getNestedValue(target, path.replace('operator.', ''));
    if (Array.isArray(array)) {
        const isStaff = /^staff\./.test(path) || /^operator\.keyStaff\./.test(path);
        array.push(isStaff ? { role: '', name: '' } : '');
        const container = document.querySelector(`[data-path="${path}"]`);
        if (container) renderArray(container, path, array);
        updateJSON();
        autoSave();
    }
}

function removeArrayItemAtIndex(path, index) {
    const target = path.startsWith('operator.') ? window.formData.operator : window.formData.facilities[window.currentFacilityIndex];
    const array = getNestedValue(target, path.replace('operator.', ''));
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

    const itemsArray = Array.isArray(items) ? items : (items ? [items] : []);

    // If array is empty, initialize it with one empty item so user input gets saved
    if (itemsArray.length === 0) {
        const target = path.startsWith('operator.') ? window.formData.operator : window.formData.facilities[window.currentFacilityIndex];
        const array = getNestedValue(target, path.replace('operator.', ''));
        if (Array.isArray(array) && array.length === 0) {
            const isStaff = /^staff\./.test(path) || /^operator\.keyStaff\./.test(path);
            const emptyItem = isStaff ? { role: '', name: '' } : '';
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
        const scope = path.startsWith('operator.') ? 'operator' : 'facility';
        const noteKey = `${path}.${index}`;

        if (isStaff) {
            const roleInput = document.createElement('input');
            roleInput.type = 'text';
            roleInput.placeholder = 'Role';
            roleInput.value = (item && item.role) ? item.role : '';
            roleInput.className = 'array-input array-input-role';
            roleInput.oninput = () => updateArrayObjectItemValue(path, index, 'role', roleInput.value);
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
            itemDiv.appendChild(nameInput);
            setTimeout(() => {
                createAutocomplete(nameInput, getAllHumanNames, 'human');
                nameInput.dataset.autocompleteInit = 'true';
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
                addFieldNote(scope, noteKey);
            });
            itemDiv.appendChild(addNoteBtn);
        }

        if (itemsToShow.length > 1 || (itemsToShow.length === 1 && (isStaff ? item.name : item) !== '')) {
            const removeBtn = document.createElement('button');
            removeBtn.className = 'btn remove-btn';
            removeBtn.textContent = '−';
            removeBtn.type = 'button';
            removeBtn.onclick = () => removeArrayItemAtIndex(path, index);
            itemDiv.appendChild(removeBtn);
        }

        // Container for notes for this specific item
        const notesContainer = document.createElement('div');
        notesContainer.className = 'field-notes';
        notesContainer.dataset.noteContainerKey = noteKey;
        notesContainer.dataset.noteScope = scope;
        itemDiv.appendChild(notesContainer);

        // Register this container so it can be rendered
        noteFieldRegistry.push({ scope, key: noteKey, container: notesContainer });

        container.appendChild(itemDiv);
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
    
    const operatorLocation = document.getElementById('operator-location');
    if (operatorLocation) operatorLocation.value = operator.location || '';
    
    const operatorHeadquarters = document.getElementById('operator-headquarters');
    if (operatorHeadquarters) operatorHeadquarters.value = operator.headquarters || '';
    
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

    const arrayPaths = ['identification.otherNames', 'otherOperators', 'operatingPeriod.notes', 'staff.administrator', 'staff.notableStaff', 'profileLinks', 'accreditations.current', 'accreditations.past', 'memberships', 'certifications', 'licensing', 'resources.notes', 'notes'];
    arrayPaths.forEach(path => {
        const container = document.querySelector(`[data-path="${path}"]`);
        if (container) {
            renderArray(container, path, getNestedValue(facility, path));
        }
    });
}

window.updateAllUI = function() {
    loadOperatorData();
    loadFacilityData();
    updateFacilityControls();
    updateTableOfContents();
    updateJSON();
    renderSavedProjectsList();
    updateProjectStatus();
    initializeAutocompleteFields();
    initializeNoteControls();

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
    }
}

function addFacility() {
    const newFacility = createNewProjectData().facilities[0];
    window.formData.facilities.push(newFacility);
    window.currentFacilityIndex = window.formData.facilities.length - 1;
    window.updateAllUI();
    autoSave();
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
    }
}

function cloneFacility() {
    // Clone facility to send to another project (NOT the current project)
    const clone = deepClone(window.formData.facilities[window.currentFacilityIndex]);

    // Get list of all projects (excluding current project)
    const availableProjects = Object.keys(window.projects || {}).filter(name => name !== window.currentProjectName);

    if (availableProjects.length === 0) {
        alert('No other projects available. Please create a new project first to clone this facility to.');
        return;
    }

    // Prompt user to select target project
    const projectList = availableProjects.map((name, idx) => `${idx + 1}. ${name}`).join('\n');
    const selection = prompt(
        `Clone facility to which project?\n\n${projectList}\n\nEnter the number of the target project (or type a new project name):`
    );

    if (!selection || selection.trim() === '') {
        console.log('Clone cancelled by user');
        return;
    }

    let targetProjectName;

    // Check if user entered a number (selecting existing project)
    const selectionNum = parseInt(selection);
    if (!isNaN(selectionNum) && selectionNum >= 1 && selectionNum <= availableProjects.length) {
        targetProjectName = availableProjects[selectionNum - 1];
    } else {
        // User typed a new project name
        targetProjectName = selection.trim();
    }

    // Load or create target project
    if (!window.projects[targetProjectName]) {
        // Create new project with the cloned facility
        window.projects[targetProjectName] = {
            name: targetProjectName,
            data: {
                facilities: [clone]
            },
            currentFacilityIndex: 0,
            timestamp: new Date().toISOString()
        };
        console.log(`✅ Created new project "${targetProjectName}" with cloned facility`);
    } else {
        // Add clone to existing project
        if (!window.projects[targetProjectName].data.facilities) {
            window.projects[targetProjectName].data.facilities = [];
        }
        window.projects[targetProjectName].data.facilities.push(clone);
        console.log(`✅ Added cloned facility to existing project "${targetProjectName}"`);
    }

    // Save the updated projects to localStorage and cloud
    saveToLocalStorage('cloudProjects', window.projects);

    // Attempt to save target project to cloud
    const currentProject = window.currentProjectName;
    const currentData = deepClone(window.formData);
    const currentIndex = window.currentFacilityIndex;

    // Temporarily switch to target project for saving
    window.currentProjectName = targetProjectName;
    window.formData = window.projects[targetProjectName].data;
    window.currentFacilityIndex = window.projects[targetProjectName].currentFacilityIndex || 0;

    if (IS_SUGGESTION_MODE) {
        persistProjectLocally(targetProjectName, {
            showStatus: true,
            statusType: 'info',
            statusMessage: '💾 Cloned facility saved locally. Submit your suggestion to share it.'
        });

        window.currentProjectName = currentProject;
        window.formData = currentData;
        window.currentFacilityIndex = currentIndex;

        alert(`✅ Facility cloned to project "${targetProjectName}" (saved locally as a draft).`);
        return;
    }

    saveProjectToCloud(targetProjectName).then(() => {
        // Restore original project
        window.currentProjectName = currentProject;
        window.formData = currentData;
        window.currentFacilityIndex = currentIndex;

        alert(`✅ Facility cloned to project "${targetProjectName}"!`);
    }).catch((err) => {
        console.error('Failed to save cloned facility to cloud:', err);

        // Restore original project even on error
        window.currentProjectName = currentProject;
        window.formData = currentData;
        window.currentFacilityIndex = currentIndex;

        alert(`⚠️ Facility cloned to project "${targetProjectName}" (saved locally, but cloud sync failed)`);
    });
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

function renderSavedProjectsList() {
    const container = document.getElementById('saved-projects-list');
    if (!container) return;

    const projectNames = Object.keys(window.projects);

    if (projectNames.length === 0) {
        container.innerHTML = '<div class="projects-empty">📭 No saved projects yet</div>';
        return;
    }

    projectNames.sort((a, b) => (window.projects[b].timestamp || '').localeCompare(window.projects[a].timestamp || ''));

    container.innerHTML = projectNames.map(name => {
        const project = window.projects[name];
        const date = new Date(project.timestamp || 0);
        const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        const facilityCount = project.data?.facilities?.length || 0;

        return `<div class="project-item" onclick="loadProject('${escapeHtmlForAttr(name)}')">
                    <div class="project-item-name">${escapeHtmlForAttr(name)}</div>
                    <div class="project-item-date">${dateStr}<br><small>${facilityCount} facilities</small></div>
                    <div class="project-item-actions">
                        <button class="project-item-btn project-item-load" onclick="event.stopPropagation(); loadProject('${escapeHtmlForAttr(name)}')">📂 Load</button>
                    </div>
                </div>`;
    }).join('');

    // Also populate the select dropdowns
    populateProjectSelectDropdowns();
}

function populateProjectSelectDropdowns() {
    console.log('🔧 populateProjectSelectDropdowns called');
    console.log('🔧 window.projects:', window.projects);
    console.log('🔧 Project count:', Object.keys(window.projects || {}).length);

    // Define US states and countries for filtering
    const usStates = [
        'alabama', 'alaska', 'arizona', 'arkansas', 'california', 'colorado', 'connecticut', 'delaware', 'florida', 'georgia',
        'hawaii', 'idaho', 'illinois', 'indiana', 'iowa', 'kansas', 'kentucky', 'louisiana', 'maine', 'maryland',
        'massachusetts', 'michigan', 'minnesota', 'mississippi', 'missouri', 'montana', 'nebraska', 'nevada', 'new hampshire', 'new jersey',
        'new mexico', 'new york', 'north carolina', 'north dakota', 'ohio', 'oklahoma', 'oregon', 'pennsylvania', 'rhode island', 'south carolina',
        'south dakota', 'tennessee', 'texas', 'utah', 'vermont', 'virginia', 'washington', 'west virginia', 'wisconsin', 'wyoming'
    ];

    const countries = [
        'canada', 'mexico', 'united kingdom', 'france', 'germany', 'italy', 'spain', 'russia', 'china', 'japan',
        'australia', 'brazil', 'argentina', 'india', 'south africa', 'nigeria', 'egypt', 'saudi arabia', 'iran', 'iraq',
        'norway', 'sweden', 'denmark', 'netherlands', 'belgium', 'switzerland', 'austria', 'poland', 'ukraine', 'turkey'
    ];

    const projectNames = Object.keys(window.projects || {});
    console.log('🔧 Project names:', projectNames);
    projectNames.sort();

    // Populate company/operator select
    const projectSelect = document.getElementById('project-select');
    console.log('🔧 projectSelect element:', projectSelect);
    if (projectSelect) {
        const companyProjects = projectNames.filter(name => {
            const lowerName = name.toLowerCase().trim();
            return !usStates.includes(lowerName) && !countries.includes(lowerName);
        });

        console.log('🔧 Company projects filtered:', companyProjects);

        projectSelect.innerHTML = '<option value="">-- Select a project --</option>' +
            companyProjects.map(name =>
                `<option value="${escapeHtmlForAttr(name)}">${escapeHtmlForAttr(name)}</option>`
            ).join('');

        console.log('🔧 Company dropdown populated with', companyProjects.length, 'projects');

        // Add change event listener
        projectSelect.onchange = (e) => {
            if (e.target.value) {
                console.log('📂 Dropdown loading project:', e.target.value);
                loadProject(e.target.value);
            }
        };
    } else {
        console.error('🔧 project-select element NOT FOUND!');
    }

    // Populate location select
    const locationSelect = document.getElementById('location-project-select');
    if (locationSelect) {
        const locationProjects = projectNames.filter(name => {
            const lowerName = name.toLowerCase().trim();
            return usStates.includes(lowerName) || countries.includes(lowerName);
        });

        locationSelect.innerHTML = '<option value="">-- Select a location project --</option>' +
            locationProjects.map(name =>
                `<option value="${escapeHtmlForAttr(name)}">${escapeHtmlForAttr(name)}</option>`
            ).join('');

        // Add change event listener
        locationSelect.onchange = (e) => {
            if (e.target.value) {
                console.log('📂 Location dropdown loading project:', e.target.value);
                loadProject(e.target.value);
            }
        };
    }
}

function updateProjectStatus() {
    const statusDiv = document.getElementById('project-status');
    if (statusDiv) {
        if (window.currentProjectName) {
            const facilityCount = window.formData?.facilities?.length || 0;
            statusDiv.innerHTML = `<strong>📂 Current Project:</strong> <span style="color: #ff9500;">${escapeHtmlForAttr(window.currentProjectName)}</span> (${facilityCount} facilities)`;
        } else {
            statusDiv.innerHTML = '⚠️ No project loaded - working with temporary data';
        }
    }
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
            if (val.trim()) addCustomValue('operator', val.trim());
            updateJSON();
            autoSave();
        },
        'operator-current-name': (val) => {
            setNestedValue(window.formData, 'operator.currentName', val);
            if (val.trim()) addCustomValue('operator', val.trim());
            updateJSON();
            autoSave();
        },
        'operator-location': (val) => {
            setNestedValue(window.formData, 'operator.location', val);
            updateJSON();
            autoSave();
        },
        'operator-headquarters': (val) => {
            setNestedValue(window.formData, 'operator.headquarters', val);
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
            if (val.trim()) addCustomValue('status', val.trim());
            updateJSON();
            autoSave();
        },
        'operator-ceo': (val) => {
            setNestedValue(window.formData, 'operator.keyStaff.ceo', val);
            if (val.trim()) addCustomValue('human', val.trim());
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
            if (val.trim()) addCustomValue('facility', val.trim());
            window.updateAllUI();
            autoSave();
        },
        'facility-type': (val) => {
            setNestedValue(window.formData, `facilities.${window.currentFacilityIndex}.facilityDetails.type`, val);
            if (val.trim()) addCustomValue('type', val.trim());
            updateJSON();
            autoSave();
        }
    };

    Object.keys(operatorFields).forEach(id => {
        const el = document.getElementById(id);
        if (el && !el.dataset.listenerAttached) {
            el.addEventListener('input', (e) => operatorFields[id](e.target.value));
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
                
                // Save custom values
                if (value && typeof value === 'string' && value.trim()) {
                    if (path.includes('gender')) addCustomValue('gender', value.trim());
                    if (path.includes('location')) addCustomValue('location', value.trim());
                    if (path.includes('status')) addCustomValue('status', value.trim());
                }
                
                updateJSON();
                autoSave();
            });
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

            checkbox.addEventListener('change', changeHandler);
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
            btn.addEventListener('click', facilityButtons[id]);
            btn.dataset.listenerAttached = 'true';
        }
    });

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

    const newBtn = document.getElementById('new-project-btn');
    if (newBtn && !newBtn.dataset.listenerAttached) {
        newBtn.onclick = newProject;
        newBtn.dataset.listenerAttached = 'true';
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
        fileUpload.addEventListener('change', handleFileUpload);
        fileUpload.dataset.listenerAttached = 'true';
    }
}

// ============================================
// INITIALIZATION
// ============================================
async function initializeForm() {
    console.log('Initializing consolidated form with cloud-first storage...');
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
    initializeFieldNotes();

    console.log('Form initialized successfully with', Object.keys(projects).length, 'projects from cloud');
}

// ============================================
// FIELD NOTES FUNCTIONALITY
// ============================================

console.log('Field notes module loading...');

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
            });

            noteBtn.addEventListener('mousedown', (e) => {
                e.stopPropagation();
            });

            noteBtn.addEventListener('mouseup', (e) => {
                e.stopPropagation();
            });

            arrayItem.appendChild(noteBtn);
            arrayItem.classList.add('has-note-button');
        });
    } catch (error) {
        console.warn('Error adding note buttons to array items:', error);
    }
}

// Add note button to form groups
function addNoteButtons() {
    console.log('addNoteButtons function called');
    const formGroups = document.querySelectorAll('.form-group');
    console.log('Found', formGroups.length, 'form groups');

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

        // Skip utility/action fields that don't need notes (be more selective)
        const fieldId = field.id || '';
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
            console.log('✅ Note button clicked! Field:', field, 'Group:', group);
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            const result = createFieldNote(field, group);
            console.log('✅ createFieldNote returned:', result);
        });

        // Prevent button from interfering with input events
        noteBtn.addEventListener('mousedown', (e) => {
            e.stopPropagation();
        });

        noteBtn.addEventListener('mouseup', (e) => {
            e.stopPropagation();
        });

        // Add button inside the same container as the field

        // Check if this field is inside an array-item (dynamic array field)
        const arrayItem = field.closest('.array-item');
        if (arrayItem) {
            // For array items, add the button directly to the array-item flex container
            console.log('Adding button to array item for field:', field);
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

                // Add the note button to the field-content
                fieldContent.appendChild(noteBtn);
            }
        }

        // Add class to form group to apply proper styling
        group.classList.add('has-note-button');
    });
}

// Add note field below the input field (supports multiple notes)
function createFieldNote(field, group) {
    console.log('🔵 createFieldNote START - field:', field, 'group:', group);
    const fieldId = getFieldIdentifier(field);
    console.log('🔵 fieldId:', fieldId);
    const label = group.querySelector('label');
    const fieldName = label ? label.textContent.trim() : 'Field';
    console.log('🔵 fieldName:', fieldName);

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
    });

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
    });

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
        });

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

    console.log('Field notes functionality initialized');
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
window.cloneFacility = cloneFacility;
window.previousFacility = previousFacility;
window.nextFacility = nextFacility;
window.sortFacilities = sortFacilities;
window.navigateToFacility = navigateToFacility;
window.copyToClipboard = copyToClipboard;
window.downloadJSON = downloadJSON;
window.populateProjectSelectDropdowns = populateProjectSelectDropdowns;

// Make field notes functions globally available
window.syncFieldNotes = syncFieldNotes;
window.getFieldNotes = getCurrentFieldNotesSnapshot;
window.addNoteButtons = addNoteButtons;

// Initialize on DOMContentLoaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeForm);
} else {
    initializeForm();
}

// Fallback: sometimes remote resources or slow loads cause UI bits to render incorrectly.
// Re-run lightweight initialization checks on window.load to recover from intermittent failures.
window.addEventListener('load', () => {
    console.log('facility-form.v3.js: window.load fired — verifying form initialization');

    // ONLY run once - use flag to prevent multiple calls (FIX #1: Prevents rendering loops)
    if (window._uiInitializedOnLoad) {
        console.log('✅ UI already initialized on load, skipping duplicate initialization');
        return;
    }
    window._uiInitializedOnLoad = true;

    setTimeout(() => {
        try {
            if (typeof window.updateAllUI === 'function') {
                window.updateAllUI();
                console.log('✅ facility-form.v3.js: updateAllUI re-run on load (once)');
            }
        } catch (e) {
            console.error('❌ facility-form.v3.js: error during load-time UI verification', e);
        }
    }, 150);
});