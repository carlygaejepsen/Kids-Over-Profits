(function() {
    if (window.KOP_UI) {
        return; // Already loaded
    }

    // This module provides ONLY the base UI functions that don't fit into the specialized modules.
    // Most UI functionality has been delegated to:
    // - KOP_UI_Events (ui-events.js) - Event handlers
    // - KOP_UI_Render (ui-render.js) - Rendering functions
    // - KOP_UI_State (ui-state.js) - State management
    // - KOP_UI_Actions (ui-actions.js) - UI actions and mutations

    function updateArrayItemValue(path, index, value) {
        const { target, normalizedPath } = window.resolvePathTarget(path);
        if (!target) return;
        let array = window.getNestedValue(target, normalizedPath);
        if (!Array.isArray(array)) {
            array = [];
            window.setNestedValue(target, normalizedPath, array);
        }
        if (Array.isArray(array)) {
            while (array.length <= index) array.push('');
            array[index] = value;
            if (path === 'identification.currentOwners') {
                const firstOwner = array[0] || '';
                window.setNestedValue(target, 'identification.currentOwner', firstOwner || '');
            }
            window.updateJSON();
            window.autoSave();
        }
    }

    function updateArrayObjectItemValue(path, index, field, value) {
        const { target, normalizedPath } = window.resolvePathTarget(path);
        if (!target) return;
        let array = window.getNestedValue(target, normalizedPath);
        if (!Array.isArray(array)) {
            array = [];
            window.setNestedValue(target, normalizedPath, array);
        }
        if (Array.isArray(array) && index >= 0 && index < array.length) {
            const isPastTTIJobs = /pastTTIJobs$/.test(path);
            const isAdditionalLocation = /locationDetails\.additionalLocations$/.test(path);
            if (typeof array[index] !== 'object' || array[index] === null) {
                if (isPastTTIJobs) array[index] = { role: '', organization: '', employer: '' };
                else if (isAdditionalLocation) array[index] = { city: '', address: '' };
                else array[index] = { role: '', name: '' };
            }
            if (isPastTTIJobs && field === 'employer') {
                array[index].employer = value;
                array[index].organization = value;
            } else if (isPastTTIJobs && field === 'organization') {
                array[index].organization = value;
                if (!array[index].employer) array[index].employer = value;
            } else {
                array[index][field] = value;
            }
            window.updateJSON();
            window.autoSave();
        }
    }

    // Expose public API - only the base UI functions defined in this file
    window.KOP_UI = {
        updateArrayItemValue,
        updateArrayObjectItemValue
    };
})();
