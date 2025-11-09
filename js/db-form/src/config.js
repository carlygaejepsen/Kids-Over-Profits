const DEFAULT_SCRIPT_BUILD_VERSION = 'facility-form.v3.sql-autocomplete.2025-10-15.notes';

function getResolverEndpoint(filename, fallback, runtimeWindow) {
    if (runtimeWindow && runtimeWindow.KOP_API && typeof runtimeWindow.KOP_API.getEndpoint === 'function') {
        return runtimeWindow.KOP_API.getEndpoint(filename);
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

export function createFacilityFormConfig(runtimeWindow = (typeof window !== 'undefined' ? window : undefined)) {
    const FACILITY_FORM_CONFIG = runtimeWindow?.KOP_FACILITY_FORM_CONFIG || {};

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

    if (runtimeWindow && Array.isArray(runtimeWindow.KOP_THEME_BASES)) {
        apiBaseCandidates.push(...runtimeWindow.KOP_THEME_BASES);
    }

    if (!apiBaseCandidates.length && runtimeWindow && runtimeWindow.location && runtimeWindow.location.origin) {
        apiBaseCandidates.push(runtimeWindow.location.origin);
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
            getResolverEndpoint('save-master.php', '/wp-content/themes/child/api/save-master.php', runtimeWindow),
        LOAD_PROJECTS:
            FACILITY_FORM_CONFIG.endpoints?.LOAD_PROJECTS ||
            getResolverEndpoint('get-master-data.php', '/wp-content/themes/child/api/get-master-data.php', runtimeWindow),
        AUTOCOMPLETE:
            FACILITY_FORM_CONFIG.endpoints?.AUTOCOMPLETE ||
            FACILITY_FORM_CONFIG.endpoints?.SUGGESTIONS ||
            getResolverEndpoint('get-autocomplete.php', '/wp-content/themes/child/api/get-autocomplete.php', runtimeWindow)
    };

    const API_ENDPOINTS = Object.keys(defaultApiPaths).reduce((acc, key) => {
        acc[key] = resolveApiUrl(defaultApiPaths[key], normalizedApiBases);
        return acc;
    }, {});

    const resolvedFormMode = typeof FACILITY_FORM_CONFIG.mode === 'string'
        ? FACILITY_FORM_CONFIG.mode
        : (runtimeWindow && typeof runtimeWindow.FORM_MODE === 'string' ? runtimeWindow.FORM_MODE : 'master');

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

    const DEBUG_LOGGING_ENABLED = (() => {
        if (isTruthyFlag(FACILITY_FORM_CONFIG.debugLogging)) {
            return true;
        }

        if (isTruthyFlag(FACILITY_FORM_CONFIG.debug)) {
            return true;
        }

        if (runtimeWindow) {
            if (isTruthyFlag(runtimeWindow.KOP_FACILITY_FORM_DEBUG)) {
                return true;
            }

            try {
                if (runtimeWindow.localStorage && isTruthyFlag(runtimeWindow.localStorage.getItem('KOP_FACILITY_FORM_DEBUG'))) {
                    return true;
                }

                if (runtimeWindow.sessionStorage && isTruthyFlag(runtimeWindow.sessionStorage.getItem('KOP_FACILITY_FORM_DEBUG'))) {
                    return true;
                }
            } catch (storageFlagError) {
                // Ignore storage errors caused by privacy settings
            }
        }

        return false;
    })();

    return {
        SCRIPT_BUILD_VERSION: DEFAULT_SCRIPT_BUILD_VERSION,
        FACILITY_FORM_CONFIG,
        API_ENDPOINTS,
        FORM_MODE,
        IS_SUGGESTION_MODE,
        FALLBACK_PROJECTS_URL,
        FALLBACK_PROJECTS_URL_CANDIDATES,
        fallbackProjectsConfigValues,
        normalizedApiBases,
        DEBUG_LOGGING_ENABLED
    };
}

export const SCRIPT_BUILD_VERSION = DEFAULT_SCRIPT_BUILD_VERSION;
