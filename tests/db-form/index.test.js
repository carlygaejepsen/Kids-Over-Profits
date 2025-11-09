import { attachToWindow, DEFAULT_FACILITY_TYPES } from '../../js/db-form/src/index.js';

describe('attachToWindow', () => {
    it('exposes constants and config factory on the provided window object', () => {
        const mockWindow = {};
        const namespace = attachToWindow(mockWindow);

        expect(mockWindow.KOP_DB_FORM).toBe(namespace);
        expect(namespace.constants.DEFAULT_FACILITY_TYPES).toBe(DEFAULT_FACILITY_TYPES);
        expect(typeof namespace.createFacilityFormConfig).toBe('function');
    });
});
