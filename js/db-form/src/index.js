import { getDefaultConstants } from './constants.js';
import { createFacilityFormConfig, SCRIPT_BUILD_VERSION } from './config.js';

const globalWindow = typeof window !== 'undefined' ? window : undefined;

function attachToWindow(targetWindow) {
    if (!targetWindow) {
        return null;
    }

    const namespace = targetWindow.KOP_DB_FORM || {};
    const constants = getDefaultConstants();

    namespace.constants = constants;
    namespace.SCRIPT_BUILD_VERSION = SCRIPT_BUILD_VERSION;
    namespace.createFacilityFormConfig = (overrideWindow) =>
        createFacilityFormConfig(overrideWindow || targetWindow);

    targetWindow.KOP_DB_FORM = namespace;
    return namespace;
}

const namespace = attachToWindow(globalWindow);

export { attachToWindow, createFacilityFormConfig, SCRIPT_BUILD_VERSION };
export * from './constants.js';
export const runtime = namespace;
