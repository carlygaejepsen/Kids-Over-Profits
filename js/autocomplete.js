/**
 * Autocomplete Module for Facility Form
 * Handles autocomplete dropdown functionality in a modular way
 * 
 * This module provides:
 * - createAutocomplete() - Sets up autocomplete on an input field
 * - initializeAutocompleteFields() - Auto-initializes all fields with data-autocomplete-category
 * - Data aggregation functions for various categories (operators, facilities, names, etc.)
 */

// ============================================
// MODULE STATE & CONFIGURATION
// ============================================

const AUTOCOMPLETE_MODULE_VERSION = 'autocomplete.v1.3.2025-11-30';

// Debug logging flag - can be enabled via window.KOP_AUTOCOMPLETE_DEBUG or localStorage
const DEBUG_AUTOCOMPLETE = (() => {
    if (typeof window === 'undefined') return false;
    if (window.KOP_AUTOCOMPLETE_DEBUG === true) return true;
    try {
        return localStorage.getItem('KOP_AUTOCOMPLETE_DEBUG') === 'true';
    } catch (e) {
        return false;
    }
})();

function debugLog(...args) {
    if (DEBUG_AUTOCOMPLETE && typeof console !== 'undefined') {
        console.log('[Autocomplete]', ...args);
    }
}

// ============================================
// DEFAULT VALUES
// ============================================

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
// AGGREGATED DATA CACHE
// ============================================

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

/**
 * Invalidate cached aggregated data
 * @param {string|null} category - Specific category to invalidate, or null for all
 */
function invalidateAggregatedData(category = null) {
    if (!category) {
        Object.keys(aggregatedDataCache).forEach(key => {
            aggregatedDataCache[key] = null;
        });
        debugLog('🔄 All aggregated data caches invalidated');
        return;
    }

    const cacheKey = CACHE_CATEGORY_MAP[category];
    if (cacheKey) {
        aggregatedDataCache[cacheKey] = null;
        debugLog('🔄 Aggregated data cache invalidated for:', cacheKey);
    }
}

// ============================================
// DATA AGGREGATION FUNCTIONS
// ============================================

/**
 * Get all operators from projects and custom storage
 */
function getAllOperators() {
    if (!aggregatedDataCache.operators) {
        const projects = window.projects || {};
        const customOperators = window.customOperators || [];
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

/**
 * Get all facility names from projects and custom storage
 */
function getAllFacilityNames() {
    if (!aggregatedDataCache.facilityNames) {
        const projects = window.projects || {};
        const customFacilityNames = window.customFacilityNames || [];
        const names = new Set(customFacilityNames);

        Object.values(projects).forEach(project => {
            // Collect from facilities array
            project.data?.facilities?.forEach(facility => {
                if (facility.identification?.name) names.add(facility.identification.name);
                if (facility.identification?.currentName) names.add(facility.identification.currentName);
                facility.identification?.otherNames?.forEach(name => names.add(name));
            });

            // Collect from referrer consultants' facilitiesReferred (shared autocomplete)
            if (Array.isArray(project.data?.referrerConsultants)) {
                project.data.referrerConsultants.forEach(consultant => {
                    if (Array.isArray(consultant?.facilitiesReferred)) {
                        consultant.facilitiesReferred.forEach(name => {
                            if (name && typeof name === 'string') names.add(name);
                        });
                    }
                    // Also check knownReferrals (alias field)
                    if (Array.isArray(consultant?.knownReferrals)) {
                        consultant.knownReferrals.forEach(name => {
                            if (name && typeof name === 'string') names.add(name);
                        });
                    }
                });
            }

            // Also check referrerIndividual for facilitiesReferred
            if (project.data?.referrerIndividual) {
                const individual = project.data.referrerIndividual;
                if (Array.isArray(individual.facilitiesReferred)) {
                    individual.facilitiesReferred.forEach(name => {
                        if (name && typeof name === 'string') names.add(name);
                    });
                }
            }

            // Check referrer array entries
            if (Array.isArray(project.data?.referrer)) {
                project.data.referrer.forEach(referrerEntry => {
                    if (Array.isArray(referrerEntry?.facilitiesReferred)) {
                        referrerEntry.facilitiesReferred.forEach(name => {
                            if (name && typeof name === 'string') names.add(name);
                        });
                    }
                });
            }
        });

        aggregatedDataCache.facilityNames = Array.from(names).filter(n => n && n.trim()).sort();
    }

    return aggregatedDataCache.facilityNames;
}

/**
 * Get all human names from projects and custom storage
 */
function getAllHumanNames() {
    if (!aggregatedDataCache.humanNames) {
        const projects = window.projects || {};
        const customHumanNames = window.customHumanNames || [];
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

/**
 * Get all referrers from projects and custom storage
 */
function getAllReferrers() {
    if (!aggregatedDataCache.referrers) {
        const projects = window.projects || {};
        const customReferrers = window.customReferrers || [];
        const referrers = new Set(customReferrers);

        Object.values(projects).forEach(project => {
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

/**
 * Get all facility types from projects and custom storage
 */
function getAllFacilityTypes() {
    if (!aggregatedDataCache.facilityTypes) {
        const projects = window.projects || {};
        const customFacilityTypes = window.customFacilityTypes || [];
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

/**
 * Get all staff roles from projects and custom storage
 */
function getAllStaffRoles() {
    if (!aggregatedDataCache.staffRoles) {
        const projects = window.projects || {};
        const customStaffRoles = window.customStaffRoles || [];
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

/**
 * Get all certifications from projects and custom storage
 */
function getAllCertifications() {
    if (!aggregatedDataCache.certifications) {
        const projects = window.projects || {};
        const customCertifications = window.customCertifications || [];
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

/**
 * Get all accreditations from projects and custom storage
 */
function getAllAccreditations() {
    if (!aggregatedDataCache.accreditations) {
        const projects = window.projects || {};
        const customAccreditations = window.customAccreditations || [];
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

/**
 * Get all memberships from projects and custom storage
 */
function getAllMemberships() {
    if (!aggregatedDataCache.memberships) {
        const projects = window.projects || {};
        const customMemberships = window.customMemberships || [];
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

/**
 * Get all locations from projects and custom storage
 */
function getAllLocations() {
    if (!aggregatedDataCache.locations) {
        const projects = window.projects || {};
        const customLocations = window.customLocations || [];
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

/**
 * Get all statuses from projects and custom storage
 */
function getAllStatuses() {
    if (!aggregatedDataCache.statuses) {
        const projects = window.projects || {};
        const customStatuses = window.customStatuses || [];
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

/**
 * Get all genders from projects and custom storage
 */
function getAllGenders() {
    if (!aggregatedDataCache.genders) {
        const projects = window.projects || {};
        const customGenders = window.customGenders || [];
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

/**
 * Get all operating periods from projects and custom storage
 */
function getAllOperatingPeriods() {
    if (!aggregatedDataCache.operatingPeriods) {
        const projects = window.projects || {};
        const customOperatingPeriods = window.customOperatingPeriods || [];
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
// API ENDPOINT HELPERS
// ============================================

/**
 * Get the autocomplete API endpoint
 * @returns {string} - The resolved autocomplete endpoint URL
 */
function getAutocompleteEndpoint() {
    // First check the form loader API endpoints
    if (window.KOP_FormLoader?.API_ENDPOINTS?.AUTOCOMPLETE) {
        return window.KOP_FormLoader.API_ENDPOINTS.AUTOCOMPLETE;
    }

    // Check global API helper
    if (window.KOP_API && typeof window.KOP_API.getEndpoint === 'function') {
        return window.KOP_API.getEndpoint('autocomplete');
    }

    // Check config endpoints
    const config = window.KOP_FACILITY_FORM_CONFIG || {};
    if (config.endpoints?.AUTOCOMPLETE) {
        return config.endpoints.AUTOCOMPLETE;
    }

    // Use WordPress REST API endpoint (preferred - always works)
    const baseUrl = config.apiBase || window.location.origin;
    return `${baseUrl.replace(/\/$/, '')}/wp-json/kop/v1/autocomplete`;
}

// ============================================
// CUSTOM VALUE MANAGEMENT
// ============================================

/**
 * Add a custom value to localStorage for a category
 * @param {string} category - The category to add to
 * @param {string} value - The value to add
 * @returns {boolean} - Whether the value was added
 */
function addCustomValue(category, value) {
    const trimmedValue = value?.trim();
    if (!trimmedValue) return false;

    let array;
    let key;
    
    switch(category) {
        case 'operator':
            array = window.customOperators || [];
            key = 'customOperators';
            break;
        case 'facility':
            array = window.customFacilityNames || [];
            key = 'customFacilityNames';
            break;
        case 'human':
            array = window.customHumanNames || [];
            key = 'customHumanNames';
            break;
        case 'referrer':
            array = window.customReferrers || [];
            key = 'customReferrers';
            break;
        case 'type':
            array = window.customFacilityTypes || [];
            key = 'customFacilityTypes';
            break;
        case 'role':
            array = window.customStaffRoles || [];
            key = 'customStaffRoles';
            break;
        case 'certification':
            array = window.customCertifications || [];
            key = 'customCertifications';
            break;
        case 'accreditation':
            array = window.customAccreditations || [];
            key = 'customAccreditations';
            break;
        case 'membership':
            array = window.customMemberships || [];
            key = 'customMemberships';
            break;
        case 'licensing':
            array = window.customLicensing || [];
            key = 'customLicensing';
            break;
        case 'investor':
            array = window.customInvestors || [];
            key = 'customInvestors';
            break;
        case 'status':
            array = window.customStatuses || [];
            key = 'customStatuses';
            break;
        case 'gender':
            array = window.customGenders || [];
            key = 'customGenders';
            break;
        case 'location':
            array = window.customLocations || [];
            key = 'customLocations';
            break;
        case 'operatingperiod':
            array = window.customOperatingPeriods || [];
            key = 'customOperatingPeriods';
            break;
        default:
            return false;
    }
    
    if (!array.includes(trimmedValue)) {
        array.push(trimmedValue);
        window[key] = array;
        try {
            localStorage.setItem(key, JSON.stringify(array));
        } catch (e) {
            console.warn('[Autocomplete] Failed to save custom value to localStorage:', e);
        }
        invalidateAggregatedData(category);
        return true;
    }

    return false;
}

/**
 * Attach a custom value recorder to an input
 * Records values when the input loses focus or changes
 */
function attachCustomValueRecorder(input, category) {
    if (!input || !category) {
        return;
    }

    const recorderKey = 'customValueRecorder';
    if (input.dataset && input.dataset[recorderKey] === category) {
        return; // Already attached
    }

    const recordValue = () => {
        const value = input.value?.trim();
        if (!value) return;

        // Only record if it's a genuinely new value
        const categoryFunctions = getCategoryFunctions();
        const existingValues = categoryFunctions[category] ? categoryFunctions[category]() : [];
        
        if (!existingValues.includes(value)) {
            addCustomValue(category, value);
            debugLog(`📝 Recorded new custom value for ${category}:`, value);
        }
    };

    input.addEventListener('change', recordValue, { passive: true });
    input.addEventListener('blur', recordValue, { passive: true });

    if (input.dataset) {
        input.dataset[recorderKey] = category;
    }
}

// ============================================
// AUTOCOMPLETE DROPDOWN SYSTEM
// ============================================

/**
 * Create an autocomplete dropdown for an input field
 * @param {HTMLInputElement} input - The input element to enhance
 * @param {Function} getDataFunction - Function that returns array of suggestions
 * @param {string} category - The category name for remote API requests
 */
function createAutocomplete(input, getDataFunction, category) {
    // Prevent double-initialization
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
    let abortController = null; // For cancelling pending requests
    let isCommittingSelection = false; // Flag to prevent re-showing dropdown after selection
    let preventBlur = false; // Flag to prevent blur from closing dropdown during selection
    let pendingFetch = null; // Reference to pending fetch timeout

    function commitSelection(value, options = {}) {
        const { shouldRefocus = false } = options;
        if (typeof value !== 'string') {
            return;
        }

        // Set flag BEFORE any value changes to prevent input event from triggering
        isCommittingSelection = true;

        // Clear any pending autocomplete fetch to prevent dropdown from re-showing
        if (pendingFetch) {
            clearTimeout(pendingFetch);
            pendingFetch = null;
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
        dropdown.classList.add('active');
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
        dropdown.classList.remove('active');
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

        // Cancel any pending remote fetch
        if (abortController) {
            abortController.abort();
        }

        // Debounced remote fetch with improved error handling
        if (pendingFetch) clearTimeout(pendingFetch);
        pendingFetch = setTimeout(async () => {
            const q = encodeURIComponent(value);
            const params = `?category=${encodeURIComponent(category)}&q=${q}`;
            const remoteUrl = getAutocompleteEndpoint() + params;

            try {
                // Create new AbortController for this request
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
        }, 300); // Debounce delay
    }, { passive: true });

    input.addEventListener('focus', () => {
        // Only trigger autocomplete dropdown if not during a programmatic UI update
        if (input.value.trim() && !window.isUpdatingUI) {
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

    // Mark as initialized to prevent double-initialization
    input.dataset.autocompleteInit = 'true';
    debugLog('✅ Autocomplete initialized for', category, 'on', input.id || input.name || 'unnamed input');
}

// ============================================
// CATEGORY FUNCTIONS MAPPING
// ============================================

/**
 * Get the mapping of categories to their data functions
 * @returns {Object} - Map of category names to data getter functions
 */
function getCategoryFunctions() {
    return {
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
        licensing: () => Array.from(window.customLicensing || []),
        investor: () => Array.from(window.customInvestors || []),
        role: getAllStaffRoles,
        operatingperiod: getAllOperatingPeriods,
        country: () => [
            'United States', 'Mexico', 'Canada', 'Jamaica', 'Costa Rica',
            'Samoa', 'Czech Republic', 'Dominican Republic', 'Honduras',
            'United Kingdom', 'Australia', 'New Zealand'
        ]
    };
}

/**
 * Initialize autocomplete on all fields with data-autocomplete-category attribute
 */
function initializeAutocompleteFields() {
    const categoryFunctions = getCategoryFunctions();

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

    debugLog('✅ Autocomplete fields initialization complete');
}

// ============================================
// GLOBAL EXPORTS
// ============================================

// Export module functions to global scope
window.KOP_Autocomplete = {
    version: AUTOCOMPLETE_MODULE_VERSION,
    createAutocomplete,
    initializeAutocompleteFields,
    invalidateAggregatedData,
    addCustomValue,
    attachCustomValueRecorder,
    getCategoryFunctions,
    autoInitialize,
    // Data aggregation functions
    getAllOperators,
    getAllFacilityNames,
    getAllHumanNames,
    getAllReferrers,
    getAllFacilityTypes,
    getAllStaffRoles,
    getAllCertifications,
    getAllAccreditations,
    getAllMemberships,
    getAllLocations,
    getAllStatuses,
    getAllGenders,
    getAllOperatingPeriods,
    // Default values
    DEFAULT_FACILITY_TYPES,
    DEFAULT_OPERATORS,
    DEFAULT_STAFF_ROLES
};

// Also export individual functions for backward compatibility
window.createAutocomplete = createAutocomplete;
window.initializeAutocompleteFields = initializeAutocompleteFields;
window.invalidateAggregatedData = invalidateAggregatedData;
window.addCustomValue = addCustomValue;
window.attachCustomValueRecorder = attachCustomValueRecorder;

// Data aggregation functions
window.getAllOperators = getAllOperators;
window.getAllFacilityNames = getAllFacilityNames;
window.getAllHumanNames = getAllHumanNames;
window.getAllReferrers = getAllReferrers;
window.getAllFacilityTypes = getAllFacilityTypes;
window.getAllStaffRoles = getAllStaffRoles;
window.getAllCertifications = getAllCertifications;
window.getAllAccreditations = getAllAccreditations;
window.getAllMemberships = getAllMemberships;
window.getAllLocations = getAllLocations;
window.getAllStatuses = getAllStatuses;
window.getAllGenders = getAllGenders;
window.getAllOperatingPeriods = getAllOperatingPeriods;

// Default values
window.DEFAULT_FACILITY_TYPES = DEFAULT_FACILITY_TYPES;
window.DEFAULT_OPERATORS = DEFAULT_OPERATORS;
window.DEFAULT_STAFF_ROLES = DEFAULT_STAFF_ROLES;

// ============================================
// AUTO-INITIALIZATION ON DOMCONTENTLOADED
// ============================================

/**
 * Initialize autocomplete fields when DOM is ready
 * Also set up a MutationObserver to handle dynamically added fields
 */
function autoInitialize() {
    debugLog('🚀 Auto-initializing autocomplete fields...');
    
    // Initialize all existing fields with data-autocomplete-category
    initializeAutocompleteFields();
    
    // Set up MutationObserver to handle dynamically added fields
    const observer = new MutationObserver((mutations) => {
        let shouldReinit = false;
        
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType === Node.ELEMENT_NODE) {
                    // Check if the added node or its descendants have autocomplete fields
                    if (node.matches && node.matches('input[data-autocomplete-category]:not([data-autocomplete-init="true"])')) {
                        shouldReinit = true;
                    } else if (node.querySelectorAll) {
                        const newFields = node.querySelectorAll('input[data-autocomplete-category]:not([data-autocomplete-init="true"])');
                        if (newFields.length > 0) {
                            shouldReinit = true;
                        }
                    }
                }
            });
        });
        
        if (shouldReinit) {
            // Debounce re-initialization
            clearTimeout(autoInitialize._debounceTimer);
            autoInitialize._debounceTimer = setTimeout(() => {
                debugLog('🔄 Re-initializing autocomplete for dynamically added fields...');
                initializeAutocompleteFields();
            }, 100);
        }
    });
    
    // Start observing the document body for changes
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });
    
    debugLog('✅ Autocomplete auto-initialization complete. MutationObserver active.');
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInitialize);
} else {
    // DOM is already ready
    autoInitialize();
}

// Also re-initialize when formReady event fires (after all data is loaded)
document.addEventListener('formReady', () => {
    debugLog('📢 formReady event received, re-initializing autocomplete...');
    // Clear init flags to allow re-initialization with fresh data
    document.querySelectorAll('input[data-autocomplete-category]').forEach(field => {
        delete field.dataset.autocompleteInit;
    });
    initializeAutocompleteFields();
});

console.log(`[Autocomplete Module] v${AUTOCOMPLETE_MODULE_VERSION} loaded`);
