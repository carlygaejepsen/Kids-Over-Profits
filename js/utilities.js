// Utility Functions
// Extracted from facility-form.js for better reusability

(function initUtilities(globalScope) {
    'use strict';

    const globalTarget = globalScope || (typeof globalThis !== 'undefined' ? globalThis : {});

    // Constants (previously in config.js - now inline to avoid import errors)
    const STAFF_STRING_SEPARATORS = [':', ' - ', '–', '—', '|'];

    // ============================================
    // HTML & STRING UTILITIES
    // ============================================

    /**
     * Escape HTML for use in HTML attributes
     * @param {string} s - String to escape
     * @returns {string} Escaped string
     */
    function escapeHtmlForAttr(s) {
        return String(s).replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /**
     * Parse legacy staff strings like "Role: Name" or "Role - Name" into { role, name }
     * @param {string} s - Staff string to parse
     * @returns {{role: string, name: string}} Parsed role and name
     */
    function parseStaffString(s) {
        if (!s || typeof s !== 'string') return { role: '', name: '' };
        const txt = s.trim();

        // Try common separators
        for (const sep of STAFF_STRING_SEPARATORS) {
            if (txt.includes(sep)) {
                const parts = txt.split(sep);
                if (parts.length >= 2) {
                    const role = parts[0].trim();
                    const name = parts.slice(1).join(sep).trim();
                    if (name) return { role, name };
                }
            }
        }

        // Fallback: return whole string as name
        return { role: '', name: txt };
    }

    // ============================================
    // OBJECT MANIPULATION UTILITIES
    // ============================================

    /**
     * Deep clone an object (simple implementation)
     * @param {*} obj - Object to clone
     * @returns {*} Cloned object
     */
    function deepClone(obj) {
        if (obj === null || typeof obj !== 'object') return obj;
        if (obj instanceof Date) return new Date(obj.getTime());
        if (Array.isArray(obj)) return obj.map(item => deepClone(item));
        if (obj instanceof Object) {
            const clonedObj = {};
            for (const key in obj) {
                if (Object.prototype.hasOwnProperty.call(obj, key)) {
                    clonedObj[key] = deepClone(obj[key]);
                }
            }
            return clonedObj;
        }
        return obj;
    }

    /**
     * Merge normalized data back into original object
     * @param {Object} original - Original object
     * @param {Object} normalized - Normalized object
     */
    function mergeIntoOriginal(original, normalized) {
        for (const key in normalized) {
            if (Object.prototype.hasOwnProperty.call(normalized, key)) {
                if (typeof normalized[key] === 'object' && normalized[key] !== null && !Array.isArray(normalized[key])) {
                    if (!original[key] || typeof original[key] !== 'object') {
                        original[key] = {};
                    }
                    mergeIntoOriginal(original[key], normalized[key]);
                } else {
                    original[key] = normalized[key];
                }
            }
        }
    }

    /**
     * Get nested value from object using dot notation path
     * @param {Object} obj - Object to query
     * @param {string} path - Dot notation path (e.g., 'operator.name')
     * @returns {*} Value at path
     */
    function getNestedValue(obj, path) {
        return path.split('.').reduce((current, key) => (current == null ? undefined : current[key]), obj);
    }

    /**
     * Set nested value in object using dot notation path
     * @param {Object} obj - Object to modify
     * @param {string} path - Dot notation path (e.g., 'operator.name')
     * @param {*} value - Value to set
     */
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

    // ============================================
    // LOCALSTORAGE UTILITIES
    // ============================================

    /**
     * Get array from localStorage
     * @param {string} key - localStorage key
     * @returns {Array} Parsed array or empty array
     */
    function getStorageArray(key) {
        try {
            return JSON.parse(localStorage.getItem(key) || '[]');
        } catch (e) {
            console.warn(`Failed to parse localStorage key "${key}":`, e);
            return [];
        }
    }

    /**
     * Save array to localStorage
     * @param {string} key - localStorage key
     * @param {Array} array - Array to save
     */
    function setStorageArray(key, array) {
        try {
            localStorage.setItem(key, JSON.stringify(array));
        } catch (e) {
            console.error(`Failed to save to localStorage key "${key}":`, e);
        }
    }

    /**
     * Add unique value to localStorage array
     * @param {string} key - localStorage key
     * @param {string} value - Value to add
     * @returns {Array} Updated array
     */
    function addToStorageArray(key, value) {
        const array = getStorageArray(key);
        const trimmedValue = value?.trim();

        if (trimmedValue && !array.includes(trimmedValue)) {
            array.push(trimmedValue);
            setStorageArray(key, array);
        }

        return array;
    }

    // ============================================
    // ARRAY UTILITIES
    // ============================================

    /**
     * Get unique values from array, filtered and sorted
     * @param {Array} arr - Array to process
     * @returns {Array} Unique, filtered, sorted values
     */
    function getUniqueFilteredSorted(arr) {
        return Array.from(new Set(arr))
            .filter(item => item && String(item).trim() !== '')
            .sort();
    }

    /**
     * Safely add items to a Set, filtering out nullish values
     * @param {Set} set - Set to add to
     * @param {Array} items - Items to add
     */
    function addToSet(set, items) {
        if (!Array.isArray(items)) return;
        items.forEach(item => {
            if (item && String(item).trim() !== '') {
                set.add(item);
            }
        });
    }

    // ============================================
    // EVENT LISTENER UTILITIES
    // ============================================

    /**
     * Attach event listener to multiple elements by IDs
     * @param {Array<string>} ids - Array of element IDs
     * @param {string} eventType - Event type (e.g., 'click')
     * @param {Function} handler - Event handler function
     */
    function attachListenersToIds(ids, eventType, handler) {
        ids.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.addEventListener(eventType, handler);
            }
        });
    }

    /**
     * Attach event listener to elements matching a selector
     * @param {string} selector - CSS selector
     * @param {string} eventType - Event type (e.g., 'click')
     * @param {Function} handler - Event handler function
     */
    function attachListenersToSelector(selector, eventType, handler) {
        document.querySelectorAll(selector).forEach(element => {
            element.addEventListener(eventType, handler);
        });
    }

    // ============================================
    // DOM UTILITIES
    // ============================================

    /**
     * Safely get element by ID
     * @param {string} id - Element ID
     * @returns {HTMLElement|null} Element or null
     */
    function getEl(id) {
        return document.getElementById(id);
    }

    /**
     * Safely set element value
     * @param {string} id - Element ID
     * @param {*} value - Value to set
     */
    function setElValue(id, value) {
        const el = getEl(id);
        if (el) el.value = value || '';
    }

    /**
     * Safely get element value
     * @param {string} id - Element ID
     * @returns {string} Element value or empty string
     */
    function getElValue(id) {
        const el = getEl(id);
        return el ? el.value : '';
    }

    /**
     * Show/hide element
     * @param {string} id - Element ID
     * @param {boolean} show - Whether to show (true) or hide (false)
     */
    function toggleElVisibility(id, show) {
        const el = getEl(id);
        if (el) {
            el.style.display = show ? '' : 'none';
        }
    }

    // ============================================
    // DATA VALIDATION UTILITIES
    // ============================================

    /**
     * Check if value is empty (null, undefined, empty string, or whitespace)
     * @param {*} value - Value to check
     * @returns {boolean} True if empty
     */
    function isEmpty(value) {
        return value === null || value === undefined || String(value).trim() === '';
    }

    /**
     * Ensure value is an array
     * @param {*} value - Value to check
     * @returns {Array} Array or empty array
     */
    function ensureArray(value) {
        if (Array.isArray(value)) return value;
        return [];
    }

    /**
     * Ensure value is an object
     * @param {*} value - Value to check
     * @returns {Object} Object or empty object
     */
    function ensureObject(value) {
        if (value && typeof value === 'object' && !Array.isArray(value)) return value;
        return {};
    }

    // ============================================
    // STRING UTILITIES
    // ============================================

    /**
     * Capitalize first letter of string
     * @param {string} str - String to capitalize
     * @returns {string} Capitalized string
     */
    function capitalize(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    /**
     * Truncate string to max length with ellipsis
     * @param {string} str - String to truncate
     * @param {number} maxLength - Maximum length
     * @returns {string} Truncated string
     */
    function truncate(str, maxLength) {
        if (!str || str.length <= maxLength) return str;
        return str.substring(0, maxLength - 3) + '...';
    }

    // ============================================
    // UI FEEDBACK UTILITIES
    // ============================================

    /**
     * Show upload/status message to user
     * @param {string} message - Message to display
     * @param {string} type - Message type ('success', 'error', 'info')
     */
    function showUploadStatus(message, type) {
        const statusDiv = document.getElementById('upload-status') || document.getElementById('project-status');
        if (statusDiv) {
            statusDiv.style.display = 'block';
            statusDiv.textContent = message;
            statusDiv.className = `upload-status ${type}`;
            setTimeout(() => {
                statusDiv.style.display = 'none';
            }, 5000);
        } else {
            console.log(`[${type.toUpperCase()}] ${message}`);
        }
    }

    /**
     * Normalize project data structure to ensure all required fields exist
     * @param {Object} data - Project data to normalize
     * @returns {Object} Normalized project data
     */
    function normalizeProjectData(data) {
        if (!data) return createDefaultProjectData();

        // Ensure operator exists
        if (!data.operator) {
            data.operator = {
                name: "",
                currentName: "",
                otherNames: [],
                location: "",
                headquarters: "",
                founded: "",
                operatingPeriod: "",
                status: "",
                parentCompanies: [],
                websites: [],
                investors: [],
                keyStaff: { ceo: "", founders: [], keyExecutives: [] },
                notes: []
            };
        }

        // Ensure facilities array exists
        if (!data.facilities || !Array.isArray(data.facilities)) {
            data.facilities = [];
        }

        // Normalize each facility
        data.facilities = data.facilities.map(facility => normalizeFacility(facility));

        return data;
    }

    /**
     * Create default project data structure
     * @returns {Object} Default project data
     */
    function createDefaultProjectData() {
        return {
            operator: {
                name: "",
                currentName: "",
                otherNames: [],
                location: "",
                headquarters: "",
                founded: "",
                operatingPeriod: "",
                status: "",
                parentCompanies: [],
                websites: [],
                investors: [],
                keyStaff: { ceo: "", founders: [], keyExecutives: [] },
                notes: []
            },
            facilities: []
        };
    }

    /**
     * Normalize a single facility object
     * @param {Object} facility - Facility to normalize
     * @returns {Object} Normalized facility
     */
    function normalizeFacility(facility) {
        const defaultFacility = {
            identification: { name: "", currentName: "", currentOperator: "", otherNames: [] },
            location: "",
            address: "",
            otherOperators: [],
            operatingPeriod: { startYear: null, endYear: null, status: "", yearsOfOperation: "", notes: [] },
            staff: { administrator: [], notableStaff: [] },
            profileLinks: [],
            facilityDetails: { type: "", capacity: null, currentCensus: null, ageRange: { min: null, max: null }, gender: "" },
            accreditations: { current: [], past: [] },
            memberships: [],
            certifications: [],
            licensing: [],
            resources: {
                hasNews: false,
                newsDetails: "",
                hasPressReleases: false,
                pressReleasesDetails: "",
                hasInspections: false,
                hasStateReports: false,
                hasRegulatoryFilings: false,
                hasLawsuits: false,
                hasPoliceReports: false,
                hasArticlesOfOrganization: false,
                hasPropertyRecords: false,
                hasPromotionalMaterials: false,
                hasEnrollmentDocuments: false,
                hasResearch: false,
                hasFinancial: false,
                hasStudent: false,
                hasStaff: false,
                hasParent: false,
                hasWebsite: false,
                hasNATSAP: false,
                hasSurvivorStories: false,
                hasOther: false,
                notes: []
            },
            treatmentTypes: {},
            philosophy: {},
            criticalIncidents: {},
            notes: []
        };

        return { ...defaultFacility, ...facility };
    }

    const utilities = {
        STAFF_STRING_SEPARATORS,
        escapeHtmlForAttr,
        parseStaffString,
        deepClone,
        mergeIntoOriginal,
        getNestedValue,
        setNestedValue,
        getStorageArray,
        setStorageArray,
        addToStorageArray,
        getUniqueFilteredSorted,
        addToSet,
        attachListenersToIds,
        attachListenersToSelector,
        getEl,
        setElValue,
        getElValue,
        toggleElVisibility,
        isEmpty,
        ensureArray,
        ensureObject,
        capitalize,
        truncate,
        showUploadStatus,
        normalizeProjectData,
        createDefaultProjectData
    };

    function assignToTarget(target) {
        if (!target) return;
        const namespace = target.KOP_UTILITIES || {};
        Object.assign(namespace, utilities);
        target.KOP_UTILITIES = namespace;

        Object.keys(utilities).forEach(key => {
            if (!(key in target)) {
                target[key] = utilities[key];
            }
        });
    }

    assignToTarget(globalTarget);

    if (typeof globalThis !== 'undefined' && globalThis !== globalTarget) {
        assignToTarget(globalThis);
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = utilities;
    }

    if (typeof define === 'function' && define.amd) {
        define(() => utilities);
    }
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : undefined));
