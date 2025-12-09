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
 * Create the toast container if it doesn't exist
 * @returns {HTMLElement} The toast container element
 */
function getOrCreateToastContainer() {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 400px;
            pointer-events: none;
        `;
        document.body.appendChild(container);
    }
    return container;
}

/**
 * Show a prominent toast notification
 * @param {string} message - Message to display
 * @param {string} type - Message type (success, error, info, warning)
 * @param {number} duration - Duration in ms (0 = no auto-hide)
 */
function showToast(message, type = 'info', duration = 5000) {
    const container = getOrCreateToastContainer();
    
    const toast = document.createElement('div');
    toast.className = `toast-notification toast-${type}`;
    
    // Icon based on type
    const icons = {
        success: '✅',
        error: '❌',
        warning: '⚠️',
        info: 'ℹ️'
    };
    
    // Color schemes
    const colors = {
        success: {
            bg: 'linear-gradient(135deg, #10b981 0%, #059669 100%)',
            border: '#047857',
            text: '#ffffff'
        },
        error: {
            bg: 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)',
            border: '#b91c1c',
            text: '#ffffff'
        },
        warning: {
            bg: 'linear-gradient(135deg, #f59e0b 0%, #d97706 100%)',
            border: '#b45309',
            text: '#ffffff'
        },
        info: {
            bg: 'linear-gradient(135deg, #3b82f6 0%, #2563eb 100%)',
            border: '#1d4ed8',
            text: '#ffffff'
        }
    };
    
    const colorScheme = colors[type] || colors.info;
    
    toast.style.cssText = `
        background: ${colorScheme.bg};
        border: 2px solid ${colorScheme.border};
        color: ${colorScheme.text};
        padding: 16px 20px;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25), 0 4px 8px rgba(0, 0, 0, 0.15);
        font-size: 15px;
        font-weight: 500;
        line-height: 1.4;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        animation: toastSlideIn 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        pointer-events: auto;
        cursor: pointer;
        max-width: 100%;
        word-wrap: break-word;
    `;
    
    // Add keyframes if not already present
    if (!document.getElementById('toast-keyframes')) {
        const style = document.createElement('style');
        style.id = 'toast-keyframes';
        style.textContent = `
            @keyframes toastSlideIn {
                from {
                    opacity: 0;
                    transform: translateX(100%) scale(0.8);
                }
                to {
                    opacity: 1;
                    transform: translateX(0) scale(1);
                }
            }
            @keyframes toastSlideOut {
                from {
                    opacity: 1;
                    transform: translateX(0) scale(1);
                }
                to {
                    opacity: 0;
                    transform: translateX(100%) scale(0.8);
                }
            }
        `;
        document.head.appendChild(style);
    }
    
    const icon = document.createElement('span');
    icon.style.cssText = 'font-size: 20px; flex-shrink: 0;';
    icon.textContent = icons[type] || icons.info;
    
    const text = document.createElement('span');
    text.style.cssText = 'flex: 1;';
    text.textContent = message;
    
    const closeBtn = document.createElement('button');
    closeBtn.innerHTML = '×';
    closeBtn.style.cssText = `
        background: none;
        border: none;
        color: ${colorScheme.text};
        font-size: 24px;
        font-weight: bold;
        cursor: pointer;
        padding: 0;
        margin: -4px -4px -4px 8px;
        opacity: 0.7;
        transition: opacity 0.2s;
        line-height: 1;
    `;
    closeBtn.onmouseenter = () => closeBtn.style.opacity = '1';
    closeBtn.onmouseleave = () => closeBtn.style.opacity = '0.7';
    
    toast.appendChild(icon);
    toast.appendChild(text);
    toast.appendChild(closeBtn);
    
    // Remove function
    const removeToast = () => {
        toast.style.animation = 'toastSlideOut 0.3s ease-in forwards';
        setTimeout(() => toast.remove(), 300);
    };
    
    // Click to dismiss
    toast.onclick = removeToast;
    closeBtn.onclick = (e) => {
        e.stopPropagation();
        removeToast();
    };
    
    container.appendChild(toast);
    
    // Auto-hide
    if (duration > 0) {
        setTimeout(removeToast, duration);
    }
    
    return toast;
}

/**
 * Show status message in upload status div AND as a toast
 * @param {string} message - Message to display
 * @param {string} type - Message type (success, error, info, etc.)
 */
function showUploadStatus(message, type) {
    // Show in the inline status div (for backward compatibility)
    const statusDiv = document.getElementById('upload-status') || document.getElementById('project-status');
    if (statusDiv) {
        statusDiv.style.display = 'block';
        statusDiv.textContent = message;
        statusDiv.className = `upload-status ${type}`;
        setTimeout(() => {
            statusDiv.style.display = 'none';
        }, 5000);
    }
    
    // Also show as a toast for better visibility
    showToast(message, type);
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

/**
 * Resolve a given path to its target object and normalized path within window.formData
 * Supports 'operator', 'referrerGroup', 'referrerIndividual', 'consultant', 'referrerAgency', and 'facility' scopes.
 *
 * @param {string} path - The original dot-separated path (e.g., "operator.name", "facilities.0.identification.name")
 * @returns {{scope: string, normalizedPath: string, target: object|null}} An object containing the scope, normalized path, and target object.
 */
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
        if (typeof window.ensureReferrerDataStructures === 'function') {
            window.ensureReferrerDataStructures();
        }
        scope = 'referrerGroup';
        normalizedPath = path.replace('referrerGroup.', '');
        target = window.formData.referrerGroup;
        return { scope, normalizedPath, target };
    }

    if (path.startsWith('referrerIndividual.')) {
        if (typeof window.ensureReferrerDataStructures === 'function') {
            window.ensureReferrerDataStructures();
        }
        scope = 'referrerIndividual';
        normalizedPath = path.replace('referrerIndividual.', '');
        target = window.formData.referrerIndividual;
        return { scope, normalizedPath, target };
    }

    if (path.startsWith('consultant.')) {
        if (typeof window.ensureReferrerDataStructures === 'function') {
            window.ensureReferrerDataStructures();
        }
        scope = 'consultant';
        normalizedPath = path.replace('consultant.', '');
        target = window.formData.referrerIndividual;
        return { scope, normalizedPath, target };
    }

    if (path.startsWith('referrerAgency.')) {
        if (typeof window.ensureReferrerDataStructures === 'function') {
            window.ensureReferrerDataStructures();
        }
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

// Expose resolvePathTarget globally
window.resolvePathTarget = resolvePathTarget;
window.debugLog = debugLog;
window.escapeHtmlForAttr = escapeHtmlForAttr;
window.toCamelCase = toCamelCase;
window.toSnakeCase = toSnakeCase;
window.parseCityState = parseCityState;
window.combineCityState = combineCityState;
window.deepClone = deepClone;
window.getNestedValue = getNestedValue;
window.setNestedValue = setNestedValue;
window.showToast = showToast;
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

