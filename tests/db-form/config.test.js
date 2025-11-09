import { createFacilityFormConfig, SCRIPT_BUILD_VERSION } from '../../js/db-form/src/config.js';

function createMockWindow(overrides = {}) {
    const base = {
        KOP_FACILITY_FORM_CONFIG: {},
        location: { origin: 'https://example.com' },
        localStorage: new Map(),
        sessionStorage: new Map()
    };

    base.localStorage.getItem = base.localStorage.get.bind(base.localStorage);
    base.sessionStorage.getItem = base.sessionStorage.get.bind(base.sessionStorage);

    return Object.assign({}, base, overrides);
}

describe('createFacilityFormConfig', () => {
    it('provides default values when config is empty', () => {
        const mockWindow = createMockWindow();
        const config = createFacilityFormConfig(mockWindow);

        expect(config.SCRIPT_BUILD_VERSION).toBe(SCRIPT_BUILD_VERSION);
        expect(config.API_ENDPOINTS.SAVE_PROJECT).toBe('https://example.com/wp-content/themes/child/api/save-master.php');
        expect(config.API_ENDPOINTS.LOAD_PROJECTS).toBe('https://example.com/wp-content/themes/child/api/get-master-data.php');
        expect(config.API_ENDPOINTS.AUTOCOMPLETE).toBe('https://example.com/wp-content/themes/child/api/get-autocomplete.php');
        expect(config.FORM_MODE).toBe('master');
        expect(config.IS_SUGGESTION_MODE).toBe(false);
        expect(config.FALLBACK_PROJECTS_URL).toBeNull();
        expect(config.DEBUG_LOGGING_ENABLED).toBe(false);
    });

    it('respects custom overrides from localized config', () => {
        const mockWindow = createMockWindow({
            KOP_FACILITY_FORM_CONFIG: {
                mode: 'suggestions',
                apiBase: 'https://api.example.com',
                endpoints: {
                    SAVE_PROJECT: '/custom/save.php'
                },
                fallbackProjectsUrl: '/projects.json'
            }
        });

        const config = createFacilityFormConfig(mockWindow);

        expect(config.FORM_MODE).toBe('suggestions');
        expect(config.IS_SUGGESTION_MODE).toBe(true);
        expect(config.API_ENDPOINTS.SAVE_PROJECT).toBe('https://api.example.com/custom/save.php');
        expect(config.FALLBACK_PROJECTS_URL_CANDIDATES[0]).toBe('https://api.example.com/projects.json');
        expect(config.FALLBACK_PROJECTS_URL).toBe('https://api.example.com/projects.json');
    });
});
