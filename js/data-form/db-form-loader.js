// ============================================
// FACILITY FORM DATA LOADER
// Handles loading projects from cloud, fallback datasets, and localStorage
// ============================================

// Execute immediately to ensure availability
(function(window, document) {
    'use strict';

    console.log('[DB Loader] Script started loading...');

    // ============================================
    // CONFIGURATION & CONSTANTS
    // ============================================

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
            FACILITY_FORM_CONFIG.projectsApiUrl ||
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

    // ============================================
    // UTILITY FUNCTIONS
    // ============================================

    function debugLog(...args) {
        if (!DEBUG_LOGGING_ENABLED || typeof console === 'undefined') {
            return;
        }

        const logFn = typeof console.debug === 'function' ? console.debug.bind(console) : console.log.bind(console);
        try {
            logFn('[DB Loader]', ...args);
        } catch (debugLogError) {
            // Never let debug logging break runtime execution
        }
    }

    function showUploadStatus(message, type) {
        const statusDiv = document.getElementById('upload-status') || document.getElementById('project-status');
        if (statusDiv) {
            statusDiv.textContent = message;
            statusDiv.className = `upload-status ${type}`;
            statusDiv.style.display = 'block';
        }
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

    function saveToLocalStorage(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
            console.warn('Failed to save to localStorage:', e);
        }
    }

    function invalidateAggregatedData(category = null) {
        if (typeof window.invalidateAggregatedData === 'function') {
            window.invalidateAggregatedData(category);
        }
    }

    function normalizeProjectData(data) {
        if (typeof window.normalizeProjectData === 'function') {
            return window.normalizeProjectData(data);
        }
        // Fallback: return data as-is if normalization function not available
        return data;
    }

    function normalizeProjectsPayload(payload) {
        if (!payload) return null;

        const normalized = {};

        const assignProject = (projectName, projectPayload) => {
            if (!projectName) return;
            const source = projectPayload && typeof projectPayload === 'object' ? projectPayload : {};
            
            // Handle cases where data is double-nested (e.g., { data: { data: {...} } })
            let rawData = source.data || source;
            if (rawData.data && Object.keys(rawData).length <= 3) { // Heuristic: if outer 'data' has few keys besides 'data' itself
                rawData = rawData.data;
            }

            const name = source.name || projectName;
            normalized[projectName] = {
                name,
                data: normalizeProjectData(rawData),
                timestamp: source.timestamp || rawData?.timestamp || new Date().toISOString(),
                currentFacilityIndex: source.currentFacilityIndex ?? rawData?.currentFacilityIndex ?? 0,
                category: source.category || rawData?.category || 'companies' // Preserve category
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

    // ============================================
    // DATA LOADING FUNCTIONS
    // ============================================

    /**
     * Load custom autocomplete data from localStorage
     */
    function loadCustomDataFromLocalStorage() {
        const dedupe = (arr) => [...new Set(arr.filter(v => v && v.trim()).map(v => v.trim()))];
    
        const customDataKeys = [
            'customOperators', 'customFacilityNames', 'customHumanNames', 'customReferrers',
            'customFacilityTypes', 'customCertifications', 'customAccreditations', 'customMemberships',
            'customLicensing', 'customInvestors', 'customStaffRoles', 'customStatuses',
            'customGenders', 'customLocations', 'customOperatingPeriods'
        ];
    
        try {
            customDataKeys.forEach(key => {
                const storedValue = localStorage.getItem(key) || '[]';
                const cleanedValue = dedupe(JSON.parse(storedValue));
                window[key] = cleanedValue;
                localStorage.setItem(key, JSON.stringify(cleanedValue));
            });
        } catch (e) {
            console.warn('Failed to load or save custom data from/to localStorage:', e);
        }
    }

    /**
     * Load projects from fallback JSON datasets
     */
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

    /**
     * Load all projects from cloud API
     */
    async function loadAllProjectsFromCloud() {
        try {
            showUploadStatus('Loading projects from cloud...', 'info');

            debugLog('Fetching from endpoint:', API_ENDPOINTS.LOAD_PROJECTS);
            const response = await fetch(API_ENDPOINTS.LOAD_PROJECTS);
            if (!response.ok) {
                const errorText = await response.text();
                debugLog('Response error:', response.status, errorText);
                throw new Error(`HTTP error! status: ${response.status} - ${errorText.substring(0, 100)}`);
            }

            const result = await response.json();
            
            // DEBUG: Log RAW Acadia from API
            debugLog('🔬🔬 RAW ACADIA from API:', result.projects?.Acadia 
                ? JSON.stringify(result.projects.Acadia).substring(0, 800) 
                : 'Not found');
            
            debugLog('Received result:', result);

            // Support both formats:
            // 1. Direct PHP API: { success: true, projects: {...} }
            // 2. WordPress REST API: { source: 'database', projects: {...} }
            const hasProjects = result.projects && typeof result.projects === 'object';
            const isSuccess = result.success || result.source || hasProjects;

            if (isSuccess && hasProjects) {
                // DEBUG: Log sample project structures
                debugLog('🔬 LOADED PROJECTS - Sample data structure:');
                const firstFive = Object.keys(result.projects).slice(0, 5);
                firstFive.forEach(name => {
                    const p = result.projects[name];
                    debugLog(`  ${name}: data=${!!p.data}, data.facilities=${p.data?.facilities?.length || 0}, root.facilities=${p.facilities?.length || 0}`);
                });
                
                window.projects = result.projects;
                
                // DEBUG: Verify structure immediately after assignment
                const acadia = window.projects['Acadia'];
                if (acadia && DEBUG_LOGGING_ENABLED) {
                    debugLog('🔬 ACADIA RIGHT AFTER LOAD:', {
                        'acadia.data exists': !!acadia.data,
                        'acadia.data.facilities exists': !!acadia.data?.facilities,
                        'acadia.data.facilities.length': acadia.data?.facilities?.length ?? 'N/A',
                        'acadia.data.data exists': !!acadia.data?.data,
                        'acadia.data keys': acadia.data ? Object.keys(acadia.data) : 'no data'
                    });
                }

                invalidateAggregatedData();

                // Backup to localStorage
                saveToLocalStorage('cloudProjects', window.projects);

                showUploadStatus(`Loaded ${Object.keys(window.projects).length} projects from cloud`, 'success');
                debugLog('Loaded projects from cloud:', Object.keys(window.projects));

                // Debug: Check if category metadata is present
                Object.keys(window.projects).forEach(name => {
                    const project = window.projects[name];
                    debugLog(`📊 Project "${name}" - Category: ${project.category || 'MISSING'}, Has timestamp: ${!!project.timestamp}, Has currentFacilityIndex: ${!!project.currentFacilityIndex}`);
                });

                // Force re-initialize autocomplete after cloud data loads
                setTimeout(() => {
                    debugLog('Re-initializing autocomplete with cloud data...');
                    // Clear autocomplete init flags to allow re-initialization
                    document.querySelectorAll('input[data-autocomplete-category]').forEach(field => {
                        delete field.dataset.autocompleteInit;
                    });
                    if (typeof window.initializeAutocompleteFields === 'function') {
                        window.initializeAutocompleteFields();
                    }

                    // Refresh saved project panels now that projects are available
                    debugLog('Refreshing saved project panels...');
                    if (typeof window.refreshSavedProjectPanels === 'function') {
                        window.refreshSavedProjectPanels();
                    }
                }, 500);

                return window.projects;
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
                    invalidateAggregatedData();
                    showUploadStatus('Loaded from localStorage backup', 'info');
                    return window.projects;
                }
            } catch (e) {
                console.error('localStorage backup failed:', e);
            }

            const fallbackLoadResult = await loadProjectsFromFallbackDatasets();

            if (fallbackLoadResult && fallbackLoadResult.projects) {
                window.projects = fallbackLoadResult.projects;
                invalidateAggregatedData();
                saveToLocalStorage('cloudProjects', window.projects);
                showUploadStatus(`Loaded ${Object.keys(window.projects).length} projects from fallback dataset`, 'success');
                debugLog('Loaded projects from fallback dataset:', fallbackLoadResult.url);
                return window.projects;
            }

            showUploadStatus('No projects found - starting fresh', 'info');
            invalidateAggregatedData();
            return {};
        }
    }

    // ============================================
    // EXPORT TO WINDOW
    // ============================================

    window.KOP_FormLoader = {
        loadCustomDataFromLocalStorage,
        loadProjectsFromFallbackDatasets,
        loadAllProjectsFromCloud,
        API_ENDPOINTS,
        debugLog
    };

    console.log('[DB Loader] ✅ DB Form Loader initialized');
    debugLog('📍 API Endpoints:', API_ENDPOINTS);
    debugLog('📂 Fallback URLs:', FALLBACK_PROJECTS_URL_CANDIDATES);

    // Mark as ready
    window.KOP_LOADER_READY = true;

})(window, document);
