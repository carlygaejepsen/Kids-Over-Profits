(function() {
    if (window.KOP_FormConfig) {
        return; // Already loaded
    }

    // ============================================
    // CONSTANTS & CONFIGURATION
    // ============================================
    const SCRIPT_BUILD_VERSION = 'data-form.v4.sql-autocomplete.2025-10-15.notes';
    if (typeof window !== 'undefined') {
        window.KOP_DATA_FORM_VERSION = SCRIPT_BUILD_VERSION;
    }

    const DATA_FORM_CONFIG = window.KOP_DATA_FORM_CONFIG || {};

    let API_ENDPOINT_FALLBACKS = {};

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
        const baseCandidates = Array.isArray(bases) ? bases : (bases ? [bases] : []);

        for (const candidate of baseCandidates) {
            if (typeof candidate !== 'string') continue;
            const normalizedBase = candidate.replace(/\/$/, '');
            if (!normalizedBase) continue;
            return `${normalizedBase}${normalizedPath}`;
        }

        return normalizedPath;
    }

    const explicitBase = DATA_FORM_CONFIG.apiBase;
    const fallbackBases = DATA_FORM_CONFIG.apiBaseFallbacks;
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
        SAVE_MASTER: DATA_FORM_CONFIG.endpoints?.SAVE_PROJECT || getResolverEndpoint('save-master.php', '/wp-content/themes/child/api/save-master.php'),
        SAVE_SUGGESTION: DATA_FORM_CONFIG.endpoints?.SAVE_SUGGESTION || DATA_FORM_CONFIG.endpoints?.SAVE_PROJECT_SUGGESTION || getResolverEndpoint('save-suggestion.php', '/wp-content/themes/child/api/save-suggestion.php'),
        LOAD_PROJECTS: DATA_FORM_CONFIG.endpoints?.LOAD_PROJECTS || getResolverEndpoint('get-master-data.php', '/wp-content/themes/child/api/get-master-data.php'),
        AUTOCOMPLETE: DATA_FORM_CONFIG.endpoints?.AUTOCOMPLETE || DATA_FORM_CONFIG.endpoints?.SUGGESTIONS || getResolverEndpoint('get-autocomplete.php', '/wp-content/themes/child/api/get-autocomplete.php'),
        REST_SAVE_PROJECT: '/wp-json/kop/v1/projects/save',
        REST_DELETE_PROJECT: '/wp-json/kop/v1/projects/delete',
        REST_LOAD_PROJECTS: '/wp-json/kop/v1/projects'
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
    
    const getAPIEndpoints = () => {
        const loaderEndpoints = window.KOP_FormLoader?.API_ENDPOINTS || {};
        const formMode = window.KOP_FormConfig ? window.KOP_FormConfig.FORM_MODE : 'master';

        const saveMaster = loaderEndpoints.SAVE_MASTER || loaderEndpoints.SAVE_PROJECT || API_ENDPOINT_FALLBACKS.SAVE_MASTER;
        const saveSuggestion = loaderEndpoints.SAVE_SUGGESTION || loaderEndpoints.SAVE_PROJECT_SUGGESTION || API_ENDPOINT_FALLBACKS.SAVE_SUGGESTION || saveMaster;

        return {
            SAVE_MASTER: saveMaster,
            SAVE_SUGGESTION: saveSuggestion,
            SAVE_PROJECT: formMode === 'suggestions' ? saveSuggestion : saveMaster,
            LOAD_PROJECTS: loaderEndpoints.LOAD_PROJECTS || API_ENDPOINT_FALLBACKS.LOAD_PROJECTS,
            AUTOCOMPLETE: loaderEndpoints.AUTOCOMPLETE || loaderEndpoints.SUGGESTIONS || API_ENDPOINT_FALLBACKS.AUTOCOMPLETE,
            REST_SAVE_PROJECT: DATA_FORM_CONFIG.restSaveUrl || API_ENDPOINT_FALLBACKS.REST_SAVE_PROJECT,
            REST_DELETE_PROJECT: DATA_FORM_CONFIG.restDeleteUrl || API_ENDPOINT_FALLBACKS.REST_DELETE_PROJECT,
            REST_NONCE: DATA_FORM_CONFIG.restNonce || ''
        };
    };

    const resolvedFormMode = typeof DATA_FORM_CONFIG.mode === 'string' ? DATA_FORM_CONFIG.mode : (typeof window !== 'undefined' && typeof window.FORM_MODE === 'string' ? window.FORM_MODE : 'master');
    const FORM_MODE = typeof resolvedFormMode === 'string' ? resolvedFormMode.toLowerCase() : 'master';

    function isSuggestionMode() {
        // Use the centrally defined FORM_MODE
        return window.KOP_FormConfig.FORM_MODE === 'suggestions';
    }

    const fallbackProjectsConfigValues = Array.isArray(DATA_FORM_CONFIG.fallbackProjectsUrls) ? DATA_FORM_CONFIG.fallbackProjectsUrls.slice() : [];
    if (typeof DATA_FORM_CONFIG.fallbackProjectsUrl === 'string' && DATA_FORM_CONFIG.fallbackProjectsUrl.trim()) {
        fallbackProjectsConfigValues.unshift(DATA_FORM_CONFIG.fallbackProjectsUrl);
    }

    const FALLBACK_PROJECTS_URL_CANDIDATES = Array.from(new Set(
        fallbackProjectsConfigValues
            .map(value => (typeof value === 'string' ? value.trim() : ''))
            .filter(Boolean)
            .map(value => resolveApiUrl(value, normalizedApiBases))
            .filter(Boolean)
    ));

    function isTruthyFlag(value) {
        if (typeof value === 'boolean') return value;
        if (typeof value === 'string') {
            const normalized = value.trim().toLowerCase();
            return ['1', 'true', 'yes', 'on', 'enable', 'enabled'].includes(normalized);
        }
        return false;
    }

    function isDebugLoggingEnabled() {
        if (isTruthyFlag(DATA_FORM_CONFIG.debugLogging) || isTruthyFlag(DATA_FORM_CONFIG.debug)) return true;
        if (typeof window === 'undefined') return false;
        if (isTruthyFlag(window.KOP_DATA_FORM_DEBUG)) return true;
        try {
            if (window.localStorage && isTruthyFlag(window.localStorage.getItem('KOP_DATA_FORM_DEBUG'))) return true;
            if (window.sessionStorage && isTruthyFlag(window.sessionStorage.getItem('KOP_DATA_FORM_DEBUG'))) return true;
        } catch (e) { /* ignore storage errors */ }
        return false;
    }

    const DEBUG_LOGGING_ENABLED = isDebugLoggingEnabled();

    // Expose public API
    window.KOP_FormConfig = {
        SCRIPT_BUILD_VERSION,
        DATA_FORM_CONFIG,
        getAPIEndpoints, // Function to re-evaluate
        FORM_MODE,
        IS_SUGGESTION_MODE: FORM_MODE === 'suggestions',
        isSuggestionMode,
        FALLBACK_PROJECTS_URL_CANDIDATES,
        FALLBACK_PROJECTS_URL: FALLBACK_PROJECTS_URL_CANDIDATES.length > 0 ? FALLBACK_PROJECTS_URL_CANDIDATES[0] : null,
        DEBUG_LOGGING_ENABLED,
        isTruthyFlag,
        normalizedApiBases,
    };
    
    // Replace initial static endpoints with dynamic getter
    Object.defineProperty(window.KOP_FormConfig, 'API_ENDPOINTS', {
        get: getAPIEndpoints,
        configurable: true
    });

    if (typeof window !== 'undefined' && !window.KOP_CONFIG) {
        window.KOP_CONFIG = window.KOP_FormConfig;
    }

})();
