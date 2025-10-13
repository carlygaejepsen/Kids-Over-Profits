// ============================================
// IMPORTS - New modular architecture
// ============================================

import {
    DEFAULT_FACILITY_TYPES,
    DEFAULT_OPERATORS,
    DEFAULT_FACILITY_NAMES,
    DEFAULT_STAFF_ROLES,
    STORAGE_KEYS,
    ARRAY_FIELD_CONFIG,
    AUTOCOMPLETE_CONFIG,
    API_ENDPOINTS,
    STAFF_STRING_SEPARATORS
} from 'https://kidsoverprofits.org/wp-content/themes/child/js/config.js';

import {
    escapeHtmlForAttr,
    parseStaffString,
    deepClone,
    mergeIntoOriginal,
    getNestedValue,
    setNestedValue,
    getStorageArray,
    setStorageArray,
    addToStorageArray,
    getUniqueFilteredSorted,
    addToSet,
    attachListenersToIds,
    attachListenersToSelector,
    getEl,
    setElValue,
    getElValue,
    toggleElVisibility,
    isEmpty,
    ensureArray,
    ensureObject,
    capitalize,
    truncate
} from 'https://kidsoverprofits.org/wp-content/themes/child/js/utilities.js';

import {
    storageManager,
    saveCustomOperator,
    saveCustomFacilityName,
    saveCustomHumanName,
    saveCustomFacilityType,
    customOperators,
    customFacilityNames,
    customHumanNames,
    customFacilityTypes,
    customCertifications,
    customAccreditations,
    customMemberships,
    customLicensing,
    customInvestors,
    customStaffRoles,
    customStatuses,
    customGenders,
    customLocations,
    refreshLegacyVariables
} from 'https://kidsoverprofits.org/wp-content/themes/child/js/storage-manager.js';

// NOTE: These imports were removed - functionality was either unused or needs to be restored:
// - event-helpers.js: Button/event attachment helpers
// - dropdown-renderer.js: Dropdown/autocomplete rendering
// - data-aggregator.js: Data collection across projects

import { ProjectManager } from 'https://kidsoverprofits.org/wp-content/themes/child/js/project-manager.js';

// ============================================
// GLOBAL DATA & STATE MANAGEMENT
// ============================================
let projects = {};
let currentProjectName = null;
let currentFacilityIndex = 0;
let formData = null;

// Make key variables globally accessible for other modules
window.projects = projects;
window.currentProjectName = currentProjectName;
window.currentFacilityIndex = currentFacilityIndex;
window.formData = formData;

// ============================================
// MAIN BUSINESS LOGIC
// ============================================

export function updateJSON() {
    const jsonDisplay = document.getElementById('json-display');
    if (jsonDisplay) {
        jsonDisplay.textContent = JSON.stringify(window.formData, null, 2);
    }
}

export function showUploadStatus(message, type) {
    const statusDiv = document.getElementById('upload-status');
    if (statusDiv) {
        statusDiv.style.display = 'block';
        statusDiv.textContent = message;
        statusDiv.className = 'upload-status ' + type;
        setTimeout(() => {
            statusDiv.style.display = 'none';
        }, 5000);
    }
}

export function createNewProjectData() {
    return {
        operator: { name: "", currentName: "", otherNames: [], location: "", headquarters: "", founded: "", operatingPeriod: "", status: "", parentCompanies: [], websites: [], investors: [], keyStaff: { ceo: "", founders: [], keyExecutives: [] }, notes: [] },
        facilities: [{ identification: { name: "", currentName: "", currentOperator: "", otherNames: [] }, location: "", address: "", otherOperators: [], operatingPeriod: { startYear: null, endYear: null, status: "", yearsOfOperation: "", notes: [] }, staff: { administrator: [], notableStaff: [] }, profileLinks: [], facilityDetails: { type: "", capacity: null, currentCensus: null, ageRange: { min: null, max: null }, gender: "" }, accreditations: { current: [], past: [] }, memberships: [], certifications: [], licensing: [], resources: { hasNews: false, newsDetails: "", hasPressReleases: false, pressReleasesDetails: "", hasInspections: false, hasStateReports: false, hasRegulatoryFilings: false, hasLawsuits: false, hasPoliceReports: false, hasArticlesOfOrganization: false, hasPropertyRecords: false, hasPromotionalMaterials: false, hasEnrollmentDocuments: false, hasResearch: false, hasFinancial: false, hasStudent: false, hasStaff: false, hasParent: false, hasWebsite: false, hasNATSAP: false, hasSurvivorStories: false, hasOther: false, notes: [] }, treatmentTypes: {}, philosophy: {}, criticalIncidents: {}, notes: [] }]
    };
}

export function updateAllUI() {
    loadOperatorData();
    loadFacilityData();
    updateFacilityControls();
    updateTableOfContents();
    updateJSON();

    if (window.projectManager) {
        window.projectManager.renderSavedProjectsList();
        window.projectManager.updateProjectStatus();
    }
}

// Make updateAllUI globally available
window.updateAllUI = updateAllUI;

function updateArrayItemValue(path, index, value) {
    const target = path.startsWith('operator.') ? window.formData.operator : window.formData.facilities[window.currentFacilityIndex];
    const array = getNestedValue(target, path.replace('operator.', ''));
    if (Array.isArray(array) && index >= 0 && index < array.length) {
        array[index] = value;
        updateJSON();
    }
}

function updateArrayObjectItemValue(path, index, field, value) {
    const target = path.startsWith('operator.') ? window.formData.operator : window.formData.facilities[window.currentFacilityIndex];
    const array = getNestedValue(target, path.replace('operator.', ''));
    if (Array.isArray(array) && index >= 0 && index < array.length) {
        if (typeof array[index] !== 'object' || array[index] === null) {
            array[index] = { role: '', name: '' };
        }
        array[index][field] = value;
        updateJSON();
    }
}

function addNewArrayItem(path) {
    const target = path.startsWith('operator.') ? window.formData.operator : window.formData.facilities[window.currentFacilityIndex];
    const array = getNestedValue(target, path.replace('operator.', ''));
    if (Array.isArray(array)) {
        const isStaff = /^staff\./.test(path) || /^operator\.keyStaff\./.test(path);
        array.push(isStaff ? { role: '', name: '' } : '');
        const container = document.querySelector(`[data-path="${path}"]`);
        if (container) renderArray(container, path, array);
        updateJSON();
    }
}

function removeArrayItemAtIndex(path, index) {
    const target = path.startsWith('operator.') ? window.formData.operator : window.formData.facilities[window.currentFacilityIndex];
    const array = getNestedValue(target, path.replace('operator.', ''));
    if (Array.isArray(array) && index >= 0 && index < array.length) {
        array.splice(index, 1);
        const container = document.querySelector(`[data-path="${path}"]`);
        if (container) renderArray(container, path, array);
        updateJSON();
    }
}

function renderArray(container, path, items) {
    if (!container) return;
    container.innerHTML = '';
    const itemsArray = Array.isArray(items) ? items : (items ? [items] : []);
    const itemsToShow = itemsArray.length > 0 ? itemsArray : [''];

    itemsToShow.forEach((item, index) => {
        const itemDiv = document.createElement('div');
        itemDiv.className = 'array-item';
        const isStaff = /^staff\./.test(path) || /^operator\.keyStaff\./.test(path);

        if (isStaff) {
            const roleInput = document.createElement('input');
            roleInput.type = 'text';
            roleInput.placeholder = 'Role';
            roleInput.value = (item && item.role) ? item.role : '';
            roleInput.className = 'array-input array-input-role';
            roleInput.oninput = () => updateArrayObjectItemValue(path, index, 'role', roleInput.value);
            itemDiv.appendChild(roleInput);

            const nameInput = document.createElement('input');
            nameInput.type = 'text';
            nameInput.placeholder = 'Name';
            nameInput.value = (item && item.name) ? item.name : '';
            nameInput.className = 'array-input array-input-name';
            nameInput.oninput = () => updateArrayObjectItemValue(path, index, 'name', nameInput.value);
            itemDiv.appendChild(nameInput);
        } else {
            const input = document.createElement('input');
            input.type = 'text';
            input.value = item || '';
            input.className = 'array-input';
            input.oninput = () => updateArrayItemValue(path, index, input.value);
            itemDiv.appendChild(input);
        }

        if (itemsToShow.length > 1 || (itemsToShow.length === 1 && itemsToShow[0] !== '')) {
            const removeBtn = document.createElement('button');
            removeBtn.className = 'btn';
            removeBtn.textContent = 'Remove';
            removeBtn.type = 'button';
            removeBtn.onclick = () => removeArrayItemAtIndex(path, index);
            itemDiv.appendChild(removeBtn);
        }
        container.appendChild(itemDiv);
    });

    const addButton = document.createElement('button');
    addButton.className = 'add-item-btn';
    addButton.textContent = 'Add Item';
    addButton.type = 'button';
    addButton.onclick = (e) => {
        e.preventDefault();
        addNewArrayItem(path);
    };
    container.appendChild(addButton);
}

function loadOperatorData() {
    if (!window.formData.operator) window.formData.operator = createNewProjectData().operator;
    const operator = window.formData.operator;

    setElValue('operator-name', operator.name);
    setElValue('operator-current-name', operator.currentName);
    setElValue('operator-other-names', Array.isArray(operator.otherNames) ? operator.otherNames.join(', ') : '');
    setElValue('operator-location', operator.location);
    setElValue('operator-headquarters', operator.headquarters);
    setElValue('operator-founded', operator.founded);
    setElValue('operator-period', operator.operatingPeriod);
    setElValue('operator-status', operator.status);
    setElValue('operator-ceo', operator.keyStaff?.ceo);

    const arrayPaths = ['operator.parentCompanies', 'operator.websites', 'operator.keyStaff.founders', 'operator.keyStaff.keyExecutives', 'operator.investors', 'operator.notes'];
    arrayPaths.forEach(path => {
        const container = document.querySelector(`[data-path="${path}"]`);
        if (container) {
            renderArray(container, path, getNestedValue(operator, path.replace('operator.', '')));
        }
    });
}

function loadFacilityData() {
    if (!window.formData.facilities || window.formData.facilities.length === 0) {
        window.formData.facilities = createNewProjectData().facilities;
    }
    const facility = window.formData.facilities[window.currentFacilityIndex];
    if (!facility) return;

    document.querySelectorAll('.facility-field').forEach(field => {
        const path = field.dataset.field;
        if (path) {
            field.value = getNestedValue(facility, path) ?? '';
        }
    });

    document.querySelectorAll('.facility-checkbox').forEach(checkbox => {
        const path = checkbox.dataset.field;
        checkbox.checked = !!getNestedValue(facility, path);
    });

    setElValue('facility-name', facility.identification?.name);
    setElValue('facility-type', facility.facilityDetails?.type);

    const arrayPaths = ['identification.otherNames', 'otherOperators', 'operatingPeriod.notes', 'staff.administrator', 'staff.notableStaff', 'profileLinks', 'accreditations.current', 'accreditations.past', 'memberships', 'certifications', 'licensing', 'resources.notes', 'notes'];
    arrayPaths.forEach(path => {
        const container = document.querySelector(`[data-path="${path}"]`);
        if (container) {
            renderArray(container, path, getNestedValue(facility, path));
        }
    });
}

function updateTableOfContents() {
    const facilityList = document.getElementById('facility-list');
    const tocStats = document.getElementById('toc-stats');
    const total = window.formData.facilities?.length || 0;

    if (tocStats) tocStats.textContent = `Total: ${total} facilit${total === 1 ? 'y' : 'ies'}`;
    if (facilityList) {
        facilityList.innerHTML = '';
        window.formData.facilities?.forEach((facility, index) => {
            const item = document.createElement('div');
            item.className = `facility-item ${index === window.currentFacilityIndex ? 'active' : ''}`;
            const name = facility.identification?.name || 'Unnamed Facility';
            item.innerHTML = `<span class="facility-name ${name === 'Unnamed Facility' ? 'empty' : ''}">${name}</span><span class="facility-index">${index + 1}</span>`;
            item.onclick = () => navigateToFacility(index);
            facilityList.appendChild(item);
        });
    }
}

function updateFacilityControls() {
    const total = window.formData.facilities?.length || 0;
    setElValue('facility-counter', `${window.currentFacilityIndex + 1} of ${total}`);
    const name = window.formData.facilities?.[window.currentFacilityIndex]?.identification?.name || 'Unnamed Facility';
    setElValue('current-facility-name', name !== 'Unnamed Facility' ? `(${name})` : '');

    const prevBtn = document.getElementById('prev-facility-btn');
    const nextBtn = document.getElementById('next-facility-btn');
    const removeBtn = document.getElementById('remove-facility-btn');

    if (prevBtn) prevBtn.style.display = total > 1 ? 'inline-block' : 'none';
    if (nextBtn) nextBtn.style.display = total > 1 ? 'inline-block' : 'none';
    if (removeBtn) removeBtn.style.display = total > 1 ? 'inline-block' : 'none';
}

function navigateToFacility(index) {
    if (index >= 0 && index < window.formData.facilities.length) {
        window.currentFacilityIndex = index;
        loadFacilityData();
        updateFacilityControls();
        updateTableOfContents();
    }
}

function addFacility() {
    const newFacility = createNewProjectData().facilities[0];
    window.formData.facilities.push(newFacility);
    window.currentFacilityIndex = window.formData.facilities.length - 1;
    updateAllUI();
}

function removeFacility() {
    if (window.formData.facilities.length > 1) {
        window.formData.facilities.splice(window.currentFacilityIndex, 1);
        if (window.currentFacilityIndex >= window.formData.facilities.length) {
            window.currentFacilityIndex = window.formData.facilities.length - 1;
        }
        updateAllUI();
    }
}

function cloneFacility() {
    const clone = deepClone(window.formData.facilities[window.currentFacilityIndex]);
    window.formData.facilities.splice(window.currentFacilityIndex + 1, 0, clone);
    window.currentFacilityIndex++;
    updateAllUI();
}

function sortFacilities() {
    if (window.formData.facilities.length <= 1) return;
    const currentName = window.formData.facilities[window.currentFacilityIndex].identification.name;
    window.formData.facilities.sort((a, b) => (a.identification.name || '').localeCompare(b.identification.name || ''));
    const newIndex = window.formData.facilities.findIndex(f => f.identification.name === currentName);
    window.currentFacilityIndex = newIndex !== -1 ? newIndex : 0;
    updateAllUI();
}

function previousFacility() {
    if (window.currentFacilityIndex > 0) {
        window.currentFacilityIndex--;
        loadFacilityData();
        updateFacilityControls();
        updateTableOfContents();
    }
}

function nextFacility() {
    if (window.currentFacilityIndex < window.formData.facilities.length - 1) {
        window.currentFacilityIndex++;
        loadFacilityData();
        updateFacilityControls();
        updateTableOfContents();
    }
}

function handleFileUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (e) => loadJSONData(e.target.result);
    reader.readAsText(file);
}

function importFromTextarea() {
    const textData = getElValue('json-paste');
    if (textData) loadJSONData(textData);
}

function loadJSONData(jsonString) {
    try {
        const data = JSON.parse(jsonString);
        let importedProjects = {};
        if (data.projects) {
            importedProjects = data.projects;
        } else {
            const projectName = data.operator?.name || `imported-${Date.now()}`;
            importedProjects[projectName] = { name: projectName, data: data, timestamp: new Date().toISOString() };
        }

        Object.keys(importedProjects).forEach(key => {
            const p = importedProjects[key];
            p.data = normalizeProjectData(p.data);
            window.projects[key] = p;
        });

        const firstProject = Object.keys(importedProjects)[0];
        if (firstProject) {
            window.projectManager.loadProject(firstProject);
        }
        showUploadStatus(`Imported ${Object.keys(importedProjects).length} project(s).`, 'success');
    } catch (e) {
        showUploadStatus(`Import failed: ${e.message}`, 'error');
    }
}

function copyToClipboard() {
    navigator.clipboard.writeText(JSON.stringify(window.formData, null, 2)).then(() => {
        showUploadStatus('JSON copied to clipboard!', 'success');
    });
}

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

function initializeForm() {
    if (window._formInitialized) return;

    // REMOVED: attachFieldListeners from event-helpers.js - functionality lost: bulk event listener attachment
    // Manual event listener attachment as replacement:
    const fieldMappings = {
        'operator-name': (val) => { setNestedValue(window.formData, 'operator.name', val); updateJSON(); },
        'operator-current-name': (val) => { setNestedValue(window.formData, 'operator.currentName', val); updateJSON(); },
        'operator-other-names': (val) => { setNestedValue(window.formData, 'operator.otherNames', val.split(',').map(s => s.trim())); updateJSON(); },
        'operator-location': (val) => { setNestedValue(window.formData, 'operator.location', val); updateJSON(); },
        'operator-headquarters': (val) => { setNestedValue(window.formData, 'operator.headquarters', val); updateJSON(); },
        'operator-founded': (val) => { setNestedValue(window.formData, 'operator.founded', val); updateJSON(); },
        'operator-period': (val) => { setNestedValue(window.formData, 'operator.operatingPeriod', val); updateJSON(); },
        'operator-status': (val) => { setNestedValue(window.formData, 'operator.status', val); updateJSON(); },
        'operator-ceo': (val) => { setNestedValue(window.formData, 'operator.keyStaff.ceo', val); updateJSON(); },
        'facility-name': (val) => { setNestedValue(window.formData, `facilities.${window.currentFacilityIndex}.identification.name`, val); updateAllUI(); },
        'facility-type': (val) => { setNestedValue(window.formData, `facilities.${window.currentFacilityIndex}.facilityDetails.type`, val); updateJSON(); },
    };

    Object.keys(fieldMappings).forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', (e) => fieldMappings[id](e.target.value));
    });

    document.querySelectorAll('.facility-field').forEach(field => {
        field.addEventListener('input', () => {
            const path = field.dataset.field;
            let value = field.type === 'number' ? (field.value === '' ? null : parseInt(field.value)) : field.value;
            setNestedValue(window.formData.facilities[window.currentFacilityIndex], path, value);
            updateJSON();
        });
    });

    // REMOVED: attachCheckboxListeners from event-helpers.js - functionality lost: bulk checkbox event attachment
    // Manual checkbox listener attachment as replacement:
    document.querySelectorAll('.facility-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', () => {
            const path = checkbox.dataset.field;
            setNestedValue(window.formData.facilities[window.currentFacilityIndex], path, checkbox.checked);
            updateJSON();
        });
    });

    window._formInitialized = true;
}
/**
 * Initializes all autocomplete dropdowns on the form.
 * DISABLED: Requires dropdown-renderer.js and data-aggregator.js modules
 */
function initializeDropdowns() {
    console.warn('Dropdowns disabled: missing dropdown-renderer.js and data-aggregator.js');
    // REMOVED: All dropdown functionality - requires createUnifiedDropdown from dropdown-renderer.js
    // REMOVED: All data aggregation - requires getAllX functions from data-aggregator.js
    // FUNCTIONALITY LOST:
    // - Autocomplete dropdowns for operator names, facility names, staff names
    // - Data aggregation across all projects
    // - Type-ahead search functionality

    // Fields will still work as plain text inputs
}


document.addEventListener('DOMContentLoaded', function() {
    if (!window.formData) {
        window.formData = createNewProjectData();
    }

    initializeForm();
    // initializeDropdowns(); // DISABLED - missing dependencies

    window.projectManager = new ProjectManager(window.projects, window.formData, window.currentProjectName, window.currentFacilityIndex);

    // REMOVED: attachFacilityNavigation from event-helpers.js - manually attaching button events
    document.getElementById('add-facility-btn')?.addEventListener('click', addFacility);
    document.getElementById('add-facility-main-btn')?.addEventListener('click', addFacility);
    document.getElementById('remove-facility-btn')?.addEventListener('click', removeFacility);
    document.getElementById('clone-facility-btn')?.addEventListener('click', cloneFacility);
    document.getElementById('prev-facility-btn')?.addEventListener('click', previousFacility);
    document.getElementById('next-facility-btn')?.addEventListener('click', nextFacility);
    document.getElementById('sort-facilities-btn')?.addEventListener('click', sortFacilities);

    // REMOVED: attachImportExport from event-helpers.js - manually attaching button events
    document.getElementById('import-json-btn')?.addEventListener('click', importFromTextarea);
    document.getElementById('download-json-btn')?.addEventListener('click', downloadJSON);
    document.getElementById('copy-json-btn')?.addEventListener('click', copyToClipboard);

    // REMOVED: setupFileUpload from event-helpers.js - manually attaching file upload
    const fileUploadEl = document.getElementById('file-upload');
    if (fileUploadEl) fileUploadEl.addEventListener('change', handleFileUpload);

    const saveBtn = document.getElementById('save-project-btn');
    if (saveBtn) saveBtn.onclick = () => window.projectManager.saveProject(getElValue('project-name'));

    const newBtn = document.getElementById('new-project-btn');
    if (newBtn) newBtn.onclick = () => window.projectManager.newProject();

    // Load all data from the server
    window.projectManager.loadAllProjectsFromDB().then(() => {
        console.log("Projects loaded from DB.");
        updateAllUI();
        // initializeDropdowns(); // DISABLED - missing dependencies
    }).catch(err => {
        console.error("Failed to load projects from DB", err);
        updateAllUI(); // Still update UI with default data
        // initializeDropdowns(); // DISABLED - missing dependencies
    });
});