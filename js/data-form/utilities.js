/**
 * Utilities Module
 * General helper functions used across the data form system
 * Includes:
 * - Debugging utilities
 * - Data manipulation (deepClone, nested value getters/setters)
 * - String utilities (escapeHtmlForAttr, case conversion, city/state parsing)
 * - UI utilities (showUploadStatus)
 * - Export utilities (copyToClipboard, downloadJSON, buildProjectExport)
 */

// ============================================
// DEBUGGING UTILITIES
// ============================================

/**
 * Conditional debug logging - only logs when DEBUG_LOGGING_ENABLED is true
 * @param {...*} args - Arguments to log
 */
function debugLog(...args) {
    // Prefer a shared global flag if present, otherwise stay silent by default
    const enabled = (typeof DEBUG_LOGGING_ENABLED !== 'undefined' && DEBUG_LOGGING_ENABLED === true) ||
        (typeof window !== 'undefined' && window.DEBUG_LOGGING_ENABLED === true);

    if (!enabled || typeof console === 'undefined') {
        return;
    }

    const logFn = typeof console.debug === 'function' ? console.debug.bind(console) : console.log.bind(console);
    try {
        logFn(...args);
    } catch (debugLogError) {
        // Never let debug logging break runtime execution
    }
}

// ============================================
// STRING UTILITIES
// ============================================

/**
 * Escape HTML special characters for use in HTML attributes
 * @param {string} s - String to escape
 * @returns {string} Escaped string
 */
function escapeHtmlForAttr(s) {
    return String(s).replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * Convert string to camelCase
 * @param {string} str - String to convert
 * @returns {string} camelCase string
 */
function toCamelCase(str) {
    return str.replace(/[-_](.)/g, (_, c) => c.toUpperCase());
}

/**
 * Convert string to snake_case
 * @param {string} str - String to convert
 * @returns {string} snake_case string
 */
function toSnakeCase(str) {
    return str.replace(/([A-Z])/g, '_$1').toLowerCase().replace(/^_/, '');
}

/**
 * Parse "City, State" string into separate components
 * @param {string} str - Location string in format "City, State"
 * @returns {Object} Object with city and state properties
 */
function parseCityState(str) {
    if (!str || typeof str !== 'string') {
        return { city: '', state: '' };
    }
    const trimmed = str.trim();
    if (!trimmed) {
        return { city: '', state: '' };
    }
    // Split on comma and extract city/state
    const parts = trimmed.split(',').map(p => p.trim());
    if (parts.length >= 2) {
        return { city: parts[0], state: parts.slice(1).join(', ').trim() };
    }
    // No comma - could be just a city or state, return as city
    return { city: trimmed, state: '' };
}

/**
 * Combine city and state into "City, State" format
 * @param {string} city - City name
 * @param {string} state - State name
 * @returns {string} Formatted "City, State" string
 */
function combineCityState(city, state) {
    const c = (city || '').trim();
    const s = (state || '').trim();
    if (c && s) {
        return `${c}, ${s}`;
    }
    return c || s || '';
}

// ============================================
// DATA MANIPULATION UTILITIES
// ============================================

/**
 * Deep clone an object
 * @param {*} obj - Object to clone
 * @returns {*} Deep cloned object
 */
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

/**
 * Get nested value from object using dot notation path
 * Tries various case variations to find the value
 * @param {Object} obj - Object to search
 * @param {string} path - Dot notation path (e.g., "user.profile.name")
 * @returns {*} Value at path, or undefined if not found
 */
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

/**
 * Set nested value in object using dot notation path
 * Creates intermediate objects as needed
 * @param {Object} obj - Object to modify
 * @param {string} path - Dot notation path (e.g., "user.profile.name")
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
// UI UTILITIES
// ============================================

/**
 * Show status message in upload status div
 * @param {string} message - Message to display
 * @param {string} type - Message type (success, error, info, etc.)
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
    }
}

// ============================================
// EXPORT UTILITIES
// ============================================

/**
 * Copy current form data to clipboard as JSON
 */
function copyToClipboard() {
    navigator.clipboard.writeText(JSON.stringify(window.formData, null, 2)).then(() => {
        showUploadStatus('JSON copied to clipboard!', 'success');
    });
}

/**
 * Download current form data as JSON file
 */
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

/**
 * Build filtered project export object
 * @param {Array<string>} categories - Array of category names to include, or null for all
 * @returns {Object} Filtered projects object
 */
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

// ============================================
// EXPORTS & INITIALIZATION
// ============================================

// Expose functions to window for global access
window.debugLog = debugLog;
window.escapeHtmlForAttr = escapeHtmlForAttr;
window.toCamelCase = toCamelCase;
window.toSnakeCase = toSnakeCase;
window.parseCityState = parseCityState;
window.combineCityState = combineCityState;
window.deepClone = deepClone;
window.getNestedValue = getNestedValue;
window.setNestedValue = setNestedValue;
window.showUploadStatus = showUploadStatus;
window.copyToClipboard = copyToClipboard;
window.downloadJSON = downloadJSON;
window.buildProjectExport = buildProjectExport;

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        console.log('🔧 Utilities module loaded');
    });
} else {
    console.log('🔧 Utilities module loaded');
}
