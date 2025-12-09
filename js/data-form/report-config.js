// Report Configuration System v1.0
// Defines report presets and filtering logic for facility reports

/**
 * Report Configuration Class
 * Manages report presets (Quick, Standard, Full) and filters form data accordingly
 */
class ReportConfig {
    constructor() {
        this.presets = {
            quick: {
                name: 'Quick Report',
                description: 'Essential information only - names, locations, status',
                operator: {
                    include: ['name', 'currentName', 'location', 'locationCity', 'locationState',
                             'status', 'founded', 'operatingPeriod'],
                    exclude: []
                },
                facilities: {
                    include: ['identification.name', 'identification.currentName',
                             'identification.currentOperator',
                             'location', 'city', 'state', 'address',
                             'facilityDetails.type', 'facilityDetails.status',
                             'operatingPeriod.status', 'operatingPeriod.startYear',
                             'operatingPeriod.endYear'],
                    exclude: []
                },
                referrerAgency: {
                    include: ['name', 'organizationName', 'city', 'state', 'location', 'type'],
                    exclude: []
                },
                referrerConsultants: {
                    include: ['fullName', 'firstName', 'lastName', 'role', 'city', 'state', 'location'],
                    exclude: []
                }
            },
            standard: {
                name: 'Standard Report',
                description: 'Balanced detail - includes key lists and accreditations',
                operator: {
                    include: [],
                    exclude: ['fieldNotes']
                },
                facilities: {
                    include: [],
                    exclude: ['resources', 'treatmentTypes', 'philosophy', 'fieldNotes']
                },
                referrerAgency: {
                    include: [],
                    exclude: ['fieldNotes']
                },
                referrerConsultants: {
                    include: [],
                    exclude: ['fieldNotes']
                }
            },
            full: {
                name: 'Full Report',
                description: 'Complete documentation with all fields',
                operator: { include: [], exclude: [] },
                facilities: { include: [], exclude: [] },
                referrerAgency: { include: [], exclude: [] },
                referrerConsultants: { include: [], exclude: [] }
            }
        };
    }

    /**
     * Filter form data based on report preset
     * @param {Object} formData - Project data to filter
     * @param {string} presetName - 'quick', 'standard', or 'full'
     * @returns {Object} Filtered form data
     */
    filterFormData(formData, presetName) {
        if (presetName === 'full') {
            return formData; // No filtering for full report
        }

        const config = this.presets[presetName];
        if (!config) {
            console.warn(`[Report Config] Unknown preset: ${presetName}, using full report`);
            return formData;
        }

        // Deep clone to avoid modifying original data
        const filtered = this.deepClone(formData);

        // Filter operator section
        if (filtered.operator && config.operator) {
            filtered.operator = this.filterSection(
                filtered.operator,
                config.operator
            );
        }

        // Filter facilities array
        if (filtered.facilities && Array.isArray(filtered.facilities) && config.facilities) {
            filtered.facilities = filtered.facilities.map(facility =>
                this.filterSection(facility, config.facilities)
            );
        }

        // Filter referrerAgency
        if (filtered.referrerAgency && config.referrerAgency) {
            filtered.referrerAgency = this.filterSection(
                filtered.referrerAgency,
                config.referrerAgency
            );
        }

        // Filter referrerConsultants array
        if (filtered.referrerConsultants && Array.isArray(filtered.referrerConsultants) && config.referrerConsultants) {
            filtered.referrerConsultants = filtered.referrerConsultants.map(consultant =>
                this.filterSection(consultant, config.referrerConsultants)
            );
        }

        return filtered;
    }

    /**
     * Filter a section of data based on include/exclude rules
     * @param {Object} data - Data object to filter
     * @param {Object} sectionConfig - Configuration with include/exclude arrays
     * @returns {Object} Filtered data
     */
    filterSection(data, sectionConfig) {
        if (!data || typeof data !== 'object') {
            return data;
        }

        const filtered = {};
        const hasIncludeList = sectionConfig.include && sectionConfig.include.length > 0;
        const hasExcludeList = sectionConfig.exclude && sectionConfig.exclude.length > 0;

        for (const [key, value] of Object.entries(data)) {
            let shouldInclude = true;

            // Check exclude list (blacklist)
            if (hasExcludeList && sectionConfig.exclude.includes(key)) {
                shouldInclude = false;
            }

            // Check include list (whitelist) - only if include list exists
            if (hasIncludeList) {
                // Check if this field or any nested path matches the include list
                const isInIncludeList = sectionConfig.include.some(includePath => {
                    // Direct match: 'name' matches key 'name'
                    if (includePath === key) return true;
                    // Nested match: 'identification.name' includes key 'identification'
                    if (includePath.startsWith(key + '.')) return true;
                    return false;
                });

                if (!isInIncludeList) {
                    shouldInclude = false;
                }
            }

            // Include the field if it passed all checks
            if (shouldInclude) {
                if (value && typeof value === 'object' && !Array.isArray(value)) {
                    // For nested objects, check if we need to filter deeper
                    const nestedConfig = this.getNestedConfig(key, sectionConfig);
                    if (nestedConfig) {
                        filtered[key] = this.filterSection(value, nestedConfig);
                    } else {
                        filtered[key] = value;
                    }
                } else {
                    // Primitive values or arrays - include as-is
                    filtered[key] = value;
                }
            }
        }

        return filtered;
    }

    /**
     * Get nested configuration for a specific key
     * @param {string} key - Parent key (e.g., 'identification')
     * @param {Object} sectionConfig - Parent section config
     * @returns {Object|null} Nested config or null
     */
    getNestedConfig(key, sectionConfig) {
        if (!sectionConfig.include || sectionConfig.include.length === 0) {
            return null;
        }

        // Find include paths that start with this key
        const nestedPaths = sectionConfig.include
            .filter(path => path.startsWith(key + '.'))
            .map(path => path.substring(key.length + 1)); // Remove 'key.' prefix

        if (nestedPaths.length === 0) {
            return null;
        }

        return {
            include: nestedPaths,
            exclude: []
        };
    }

    /**
     * Deep clone an object (fallback if window.deepClone not available)
     * @param {*} obj - Object to clone
     * @returns {*} Cloned object
     */
    deepClone(obj) {
        // Try to use global deepClone if available
        if (typeof window !== 'undefined' && typeof window.deepClone === 'function') {
            return window.deepClone(obj);
        }

        // Fallback to JSON clone (not perfect but sufficient for most cases)
        if (obj === null || obj === undefined) return obj;
        if (typeof obj !== 'object') return obj;

        try {
            return JSON.parse(JSON.stringify(obj));
        } catch (e) {
            console.warn('[Report Config] Deep clone failed, returning original object', e);
            return obj;
        }
    }

    /**
     * Get preset information
     * @param {string} presetName - Preset name
     * @returns {Object|null} Preset info or null
     */
    getPresetInfo(presetName) {
        return this.presets[presetName] || null;
    }

    /**
     * Get all available presets
     * @returns {Array} Array of preset objects with value, label, description
     */
    getAllPresets() {
        return Object.keys(this.presets).map(key => ({
            value: key,
            label: this.presets[key].name,
            description: this.presets[key].description
        }));
    }
}

// Export globally
window.ReportConfig = ReportConfig;
console.log('[Report Config] Loaded successfully - v1.0');
