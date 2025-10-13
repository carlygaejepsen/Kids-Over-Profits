// ============================================
// CONSOLIDATED FACILITY FORM - CLOUD FIRST
// All functionality restored, cloud-first storage
// ============================================

// ============================================
// CONSTANTS & CONFIGURATION
// ============================================
const API_ENDPOINTS = {
    SAVE_PROJECT: 'https://kidsoverprofits.org/wp-content/themes/child/api/save-master.php',
    LOAD_PROJECTS: 'https://kidsoverprofits.org/wp-content/themes/child/api/get-master-data.php'
};

// Remote suggestions endpoint (hosted on the real site). If this endpoint is not
// available from your local environment (CORS), the createAutocomplete() function
// will fall back to the local `/php/get-suggestions.php` implemented in this repo.
API_ENDPOINTS.SUGGESTIONS = 'https://kidsoverprofits.org/wp-content/themes/child/api/get-suggestions.php';

const DEFAULT_FACILITY_TYPES = [
    'Residential Treatment Center (RTC)',
    'Therapeutic Boarding School',
    'Wilderness Therapy Program',
    'Long-term Wilderness Program',
    'Boot Camp',
    'Behavior Modification Program',
    'Therapeutic Group Home',
    'Specialty Boarding School',
    'Psychiatric Hospital',
    'Juvenile Detention Center',
    'Adventure Therapy Program',
    'Emotional Growth Boarding School',
    'Ranch Program',
    'Military-Style Academy',
    'Fundamentalist Religious Program',
    'Qualified Residential Treatment Program (QRTP)',
    'Other'
];

const DEFAULT_OPERATORS = [
    'Sequel Youth & Family Services',
    'Vivant Behavioral Health',
    'The Brown Schools',
    'CEDU',
    'Rite of Passage',
    'TrueCore Behavioral Services',
    'Correctional Services Corporation',
    'Youth Services International',
    'Youth Opportunity Investments',
    'Sequel TSI',
    'Three Springs Inc.',
    'Universal Health Services',
    'Wayne Halfway House',
    'Embark Behavioral Health',
    'Acadia Healthcare',
    'CRC Health Group',
    'Altior Healthcare',
    'Aspen Education Group',
    'Eckerd Connects',
    'Family Help & Wellness'
];

const DEFAULT_STAFF_ROLES = [
    'Administrator',
    'Director',
    'CEO',
    'President',
    'Counselor',
    'Therapist',
    'Teacher',
    'Nurse',
    'Medical Director',
    'Case Manager',
    'Supervisor',
    'Staff',
    'Founder',
    'Key Executive',
    'Board Member',
    'Program Director',
    'Clinical Director',
    'Admissions Director',
    'Other'
];

// ============================================
// GLOBAL STATE
// ============================================
let projects = {};
let currentProjectName = null;
let currentFacilityIndex = 0;
let formData = null;

// Custom data from localStorage (backup only)
let customOperators = [];
let customFacilityNames = [];
let customHumanNames = [];
let customFacilityTypes = [];
let customCertifications = [];
let customAccreditations = [];
let customMemberships = [];
let customLicensing = [];
let customInvestors = [];
let customStaffRoles = [];
let customStatuses = [];
let customGenders = [];
let customLocations = [];

// Make globals available
window.projects = projects;
window.currentProjectName = currentProjectName;
window.currentFacilityIndex = currentFacilityIndex;
window.formData = formData;

// ============================================
// UTILITY FUNCTIONS
// ============================================
function escapeHtmlForAttr(s) {
    return String(s).replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
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

function getNestedValue(obj, path) {
    return path.split('.').reduce((current, key) => current?.[key], obj);
}

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

function showUploadStatus(message, type) {
    const statusDiv = document.getElementById('upload-status') || document.getElementById('project-status');
    if (statusDiv) {
        statusDiv.style.display = 'block';
        statusDiv.textContent = message;
        statusDiv.className = `upload-status ${type}`;
        setTimeout(() => {
            statusDiv.style.display = 'none';
        }, 5000);
    }
}

function normalizeProjectData(data) {
    if (!data) return createNewProjectData();
    
    if (!data.operator) {
        data.operator = {
            name: "", currentName: "", otherNames: [], location: "", headquarters: "",
            founded: "", operatingPeriod: "", status: "", parentCompanies: [],
            websites: [], investors: [], keyStaff: { ceo: "", founders: [], keyExecutives: [] },
            notes: []
        };
    }
    
    if (!data.facilities || !Array.isArray(data.facilities)) {
        data.facilities = [];
    }
    
    return data;
}

// ============================================
// LOCALSTORAGE BACKUP FUNCTIONS
// ============================================
function loadCustomDataFromLocalStorage() {
    try {
        customOperators = JSON.parse(localStorage.getItem('customOperators') || '[]');
        customFacilityNames = JSON.parse(localStorage.getItem('customFacilityNames') || '[]');
        customHumanNames = JSON.parse(localStorage.getItem('customHumanNames') || '[]');
        customFacilityTypes = JSON.parse(localStorage.getItem('customFacilityTypes') || '[]');
        customCertifications = JSON.parse(localStorage.getItem('customCertifications') || '[]');
        customAccreditations = JSON.parse(localStorage.getItem('customAccreditations') || '[]');
        customMemberships = JSON.parse(localStorage.getItem('customMemberships') || '[]');
        customLicensing = JSON.parse(localStorage.getItem('customLicensing') || '[]');
        customInvestors = JSON.parse(localStorage.getItem('customInvestors') || '[]');
        customStaffRoles = JSON.parse(localStorage.getItem('customStaffRoles') || '[]');
        customStatuses = JSON.parse(localStorage.getItem('customStatuses') || '[]');
        customGenders = JSON.parse(localStorage.getItem('customGenders') || '[]');
        customLocations = JSON.parse(localStorage.getItem('customLocations') || '[]');
    } catch (e) {
        console.warn('Failed to load custom data from localStorage:', e);
    }
}

function saveToLocalStorage(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch (e) {
        console.warn('Failed to save to localStorage:', e);
    }
}

function addCustomValue(category, value) {
    const trimmedValue = value?.trim();
    if (!trimmedValue) return false;
    
    let array;
    let key;
    
    switch(category) {
        case 'operator':
            array = customOperators;
            key = 'customOperators';
            break;
        case 'facility':
            array = customFacilityNames;
            key = 'customFacilityNames';
            break;
        case 'human':
            array = customHumanNames;
            key = 'customHumanNames';
            break;
        case 'type':
            array = customFacilityTypes;
            key = 'customFacilityTypes';
            break;
        case 'certification':
            array = customCertifications;
            key = 'customCertifications';
            break;
        case 'accreditation':
            array = customAccreditations;
            key = 'customAccreditations';
            break;
        case 'membership':
            array = customMemberships;
            key = 'customMemberships';
            break;
        case 'licensing':
            array = customLicensing;
            key = 'customLicensing';
            break;
        case 'investor':
            array = customInvestors;
            key = 'customInvestors';
            break;
        case 'role':
            array = customStaffRoles;
            key = 'customStaffRoles';
            break;
        case 'status':
            array = customStatuses;
            key = 'customStatuses';
            break;
        case 'gender':
            array = customGenders;
            key = 'customGenders';
            break;
        case 'location':
            array = customLocations;
            key = 'customLocations';
            break;
        default:
            return false;
    }
    
    if (!array.includes(trimmedValue)) {
        array.push(trimmedValue);
        saveToLocalStorage(key, array);
        return true;
    }
    
    return false;
}

// ============================================
// DATA AGGREGATION (across all projects)
// ============================================
function getAllOperators() {
    const operators = new Set([...DEFAULT_OPERATORS, ...customOperators]);
    
    Object.values(projects).forEach(project => {
        if (project.data?.operator?.name) operators.add(project.data.operator.name);
        if (project.data?.operator?.currentName) operators.add(project.data.operator.currentName);
        if (project.data?.operator?.otherNames) {
            project.data.operator.otherNames.forEach(name => operators.add(name));
        }
        
        project.data?.facilities?.forEach(facility => {
            if (facility.identification?.currentOperator) {
                operators.add(facility.identification.currentOperator);
            }
            facility.otherOperators?.forEach(op => operators.add(op));
        });
    });
    
    return Array.from(operators).filter(op => op && op.trim()).sort();
}

function getAllFacilityNames() {
    const names = new Set(customFacilityNames);
    
    Object.values(projects).forEach(project => {
        project.data?.facilities?.forEach(facility => {
            if (facility.identification?.name) names.add(facility.identification.name);
            if (facility.identification?.currentName) names.add(facility.identification.currentName);
            facility.identification?.otherNames?.forEach(name => names.add(name));
        });
    });
    
    return Array.from(names).filter(n => n && n.trim()).sort();
}

function getAllHumanNames() {
    const names = new Set(customHumanNames);
    
    Object.values(projects).forEach(project => {
        // Operator staff
        if (project.data?.operator?.keyStaff) {
            const ks = project.data.operator.keyStaff;
            if (ks.ceo) names.add(ks.ceo);
            ks.founders?.forEach(f => names.add(f));
            ks.keyExecutives?.forEach(e => names.add(e));
        }
        
        // Facility staff
        project.data?.facilities?.forEach(facility => {
            facility.staff?.administrator?.forEach(admin => {
                const name = typeof admin === 'string' ? admin : admin.name;
                if (name) names.add(name);
            });
            facility.staff?.notableStaff?.forEach(staff => {
                const name = typeof staff === 'string' ? staff : staff.name;
                if (name) names.add(name);
            });
        });
    });
    
    return Array.from(names).filter(n => n && n.trim()).sort();
}

function getAllFacilityTypes() {
    const types = new Set([...DEFAULT_FACILITY_TYPES, ...customFacilityTypes]);
    
    Object.values(projects).forEach(project => {
        project.data?.facilities?.forEach(facility => {
            if (facility.facilityDetails?.type) {
                types.add(facility.facilityDetails.type);
            }
        });
    });
    
    return Array.from(types).filter(t => t && t.trim()).sort();
}

function getAllStaffRoles() {
    const roles = new Set([...DEFAULT_STAFF_ROLES, ...customStaffRoles]);
    
    Object.values(projects).forEach(project => {
        project.data?.facilities?.forEach(facility => {
            facility.staff?.administrator?.forEach(admin => {
                const role = typeof admin === 'string' ? '' : admin.role;
                if (role) roles.add(role);
            });
            facility.staff?.notableStaff?.forEach(staff => {
                const role = typeof staff === 'string' ? '' : staff.role;
                if (role) roles.add(role);
            });
        });
    });
    
    return Array.from(roles).filter(r => r && r.trim()).sort();
}

function getAllCertifications() {
    const certs = new Set(customCertifications);
    
    Object.values(projects).forEach(project => {
        project.data?.facilities?.forEach(facility => {
            facility.certifications?.forEach(cert => certs.add(cert));
        });
    });
    
    return Array.from(certs).filter(c => c && c.trim()).sort();
}

function getAllAccreditations() {
    const accreds = new Set(customAccreditations);
    
    Object.values(projects).forEach(project => {
        project.data?.facilities?.forEach(facility => {
            facility.accreditations?.current?.forEach(acc => accreds.add(acc));
            facility.accreditations?.past?.forEach(acc => accreds.add(acc));
        });
    });
    
    return Array.from(accreds).filter(a => a && a.trim()).sort();
}

function getAllMemberships() {
    const memberships = new Set(customMemberships);
    
    Object.values(projects).forEach(project => {
        project.data?.facilities?.forEach(facility => {
            facility.memberships?.forEach(m => memberships.add(m));
        });
    });
    
    return Array.from(memberships).filter(m => m && m.trim()).sort();
}

function getAllLocations() {
    const locations = new Set(customLocations);
    
    Object.values(projects).forEach(project => {
        project.data?.facilities?.forEach(facility => {
            if (facility.location) locations.add(facility.location);
        });
    });
    
    return Array.from(locations).filter(l => l && l.trim()).sort();
}

function getAllStatuses() {
    const statuses = new Set([...customStatuses, 'Active', 'Closed', 'Acquired', 'Merged', 'Defunct', 'Transferred', 'Open']);
    
    Object.values(projects).forEach(project => {
        if (project.data?.operator?.status) statuses.add(project.data.operator.status);
        project.data?.facilities?.forEach(facility => {
            if (facility.operatingPeriod?.status) statuses.add(facility.operatingPeriod.status);
        });
    });
    
    return Array.from(statuses).filter(s => s && s.trim()).sort();
}

function getAllGenders() {
    const genders = new Set([...customGenders, 'Male', 'Female', 'Co-ed', 'All Genders']);
    
    Object.values(projects).forEach(project => {
        project.data?.facilities?.forEach(facility => {
            if (facility.facilityDetails?.gender) genders.add(facility.facilityDetails.gender);
        });
    });
    
    return Array.from(genders).filter(g => g && g.trim()).sort();
}

// ============================================
// AUTOCOMPLETE DROPDOWN SYSTEM
// ============================================
function createAutocomplete(input, getDataFunction, category) {
    const wrapper = document.createElement('div');
    wrapper.className = 'autocomplete-wrapper';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);
    
    const dropdown = document.createElement('div');
    dropdown.className = 'autocomplete-dropdown';
    wrapper.appendChild(dropdown);
    
    let currentFocus = -1;
    
    function showDropdown(items) {
        dropdown.innerHTML = '';
        dropdown.style.display = 'block';
        
        if (items.length === 0) {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = 'autocomplete-item';
            emptyDiv.textContent = 'No matches found';
            emptyDiv.style.color = '#9ca3af';
            dropdown.appendChild(emptyDiv);
            return;
        }
        
        items.forEach((item, index) => {
            const div = document.createElement('div');
            div.className = 'autocomplete-item';
            
            const inputValue = input.value.toLowerCase();
            const itemLower = item.toLowerCase();
            const startIdx = itemLower.indexOf(inputValue);
            
            if (startIdx >= 0) {
                const before = item.substring(0, startIdx);
                const match = item.substring(startIdx, startIdx + input.value.length);
                const after = item.substring(startIdx + input.value.length);
                div.innerHTML = `${escapeHtmlForAttr(before)}<strong>${escapeHtmlForAttr(match)}</strong>${escapeHtmlForAttr(after)}`;
            } else {
                div.textContent = item;
            }
            
            div.addEventListener('click', () => {
                input.value = item;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                dropdown.style.display = 'none';
                currentFocus = -1;
            });
            
            dropdown.appendChild(div);
        });
    }
    
    function hideDropdown() {
        dropdown.style.display = 'none';
        currentFocus = -1;
    }
    
    input.addEventListener('input', () => {
        const value = input.value.trim();
        if (!value) {
            hideDropdown();
            return;
        }
        // Merge local items with remote suggestions (debounced)
        const localItems = (typeof getDataFunction === 'function') ? getDataFunction() : [];
        const localFiltered = localItems.filter(item => item.toLowerCase().includes(value.toLowerCase()));

        showDropdown(localFiltered);

        // Debounced remote fetch: try the real (remote) endpoint first, then fall back to local PHP endpoint.
        if (createAutocomplete._pendingFetch) clearTimeout(createAutocomplete._pendingFetch);
        createAutocomplete._pendingFetch = setTimeout(async () => {
            const q = encodeURIComponent(value);
            const params = `?category=${encodeURIComponent(category)}&q=${q}`;
            const remoteUrl = (typeof API_ENDPOINTS !== 'undefined' && API_ENDPOINTS.SUGGESTIONS) ? API_ENDPOINTS.SUGGESTIONS + params : null;

            if (!remoteUrl) return;
            try {
                const resp = await fetch(remoteUrl, { cache: 'no-store' });
                if (!resp.ok) {
                    console.warn('Autocomplete remote suggestions returned non-ok status', resp.status);
                    return;
                }
                const json = await resp.json();
                if (json && json.success && Array.isArray(json.values)) {
                    const merged = Array.from(new Set([...localFiltered, ...json.values]));
                    showDropdown(merged);
                }
            } catch (e) {
                console.warn('Autocomplete remote fetch failed', e);
            }
        }, 220);
    });
    
    input.addEventListener('focus', () => {
        if (input.value.trim()) {
            input.dispatchEvent(new Event('input'));
        }
    });
    
    input.addEventListener('blur', () => {
        setTimeout(hideDropdown, 200);
    });
    
    input.addEventListener('keydown', (e) => {
        const items = dropdown.querySelectorAll('.autocomplete-item');
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            currentFocus++;
            if (currentFocus >= items.length) currentFocus = 0;
            setActive(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            currentFocus--;
            if (currentFocus < 0) currentFocus = items.length - 1;
            setActive(items);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (currentFocus > -1 && items[currentFocus]) {
                items[currentFocus].click();
            } else {
                // Add as new custom value
                if (input.value.trim() && category) {
                    addCustomValue(category, input.value.trim());
                }
            }
        } else if (e.key === 'Escape') {
            hideDropdown();
        }
    });
    
    function setActive(items) {
        items.forEach((item, index) => {
            if (index === currentFocus) {
                item.classList.add('active');
                item.scrollIntoView({ block: 'nearest' });
            } else {
                item.classList.remove('active');
            }
        });
    }
}

function initializeAutocompleteFields() {
    // Operator name
    const operatorNameField = document.getElementById('operator-name');
    if (operatorNameField && !operatorNameField.dataset.autocompleteInit) {
        createAutocomplete(operatorNameField, getAllOperators, 'operator');
        operatorNameField.dataset.autocompleteInit = 'true';
    }
    
    // Facility name
    const facilityNameField = document.getElementById('facility-name');
    if (facilityNameField && !facilityNameField.dataset.autocompleteInit) {
        createAutocomplete(facilityNameField, getAllFacilityNames, 'facility');
        facilityNameField.dataset.autocompleteInit = 'true';
    }
    
    // Facility type
    const facilityTypeField = document.getElementById('facility-type');
    if (facilityTypeField && !facilityTypeField.dataset.autocompleteInit) {
        createAutocomplete(facilityTypeField, getAllFacilityTypes, 'type');
        facilityTypeField.dataset.autocompleteInit = 'true';
    }
    
    // Status fields
    document.querySelectorAll('input[id*="status"]').forEach(field => {
        if (!field.dataset.autocompleteInit) {
            createAutocomplete(field, getAllStatuses, 'status');
            field.dataset.autocompleteInit = 'true';
        }
    });
    
    // Gender fields
    document.querySelectorAll('input[data-field*="gender"]').forEach(field => {
        if (!field.dataset.autocompleteInit) {
            createAutocomplete(field, getAllGenders, 'gender');
            field.dataset.autocompleteInit = 'true';
        }
    });
    
    // Location fields
    document.querySelectorAll('input[data-field="location"]').forEach(field => {
        if (!field.dataset.autocompleteInit) {
            createAutocomplete(field, getAllLocations, 'location');
            field.dataset.autocompleteInit = 'true';
        }
    });
}

// ============================================
// CLOUD STORAGE - PRIMARY
// ============================================
async function loadAllProjectsFromCloud() {
    try {
        showUploadStatus('Loading projects from cloud...', 'info');
        
        const response = await fetch(API_ENDPOINTS.LOAD_PROJECTS);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.success && result.projects) {
            window.projects = result.projects;
            projects = window.projects;
            
            // Backup to localStorage
            saveToLocalStorage('cloudProjects', projects);
            
            showUploadStatus(`Loaded ${Object.keys(projects).length} projects from cloud`, 'success');
            console.log('Loaded projects from cloud:', Object.keys(projects));
            
            return projects;
        } else {
            throw new Error(result.error || 'Failed to load projects');
        }
    } catch (error) {
        console.error('Cloud load error:', error);
        showUploadStatus('Failed to load from cloud, trying localStorage backup...', 'error');
        
        // Fallback to localStorage
        try {
            const backup = JSON.parse(localStorage.getItem('cloudProjects') || '{}');
            if (Object.keys(backup).length > 0) {
                window.projects = backup;
                projects = window.projects;
                showUploadStatus('Loaded from localStorage backup', 'info');
                return projects;
            }
        } catch (e) {
            console.error('localStorage backup failed:', e);
        }
        
        showUploadStatus('No projects found - starting fresh', 'info');
        return {};
    }
}

async function saveProjectToCloud(projectName) {
    if (!projectName || !window.formData) {
        showUploadStatus('No project name or data to save', 'error');
        return false;
    }
    
    try {
        showUploadStatus(`Saving "${projectName}" to cloud...`, 'info');
        
        const projectData = {
            name: projectName,
            data: deepClone(window.formData),
            currentFacilityIndex: window.currentFacilityIndex,
            timestamp: new Date().toISOString()
        };
        
        const response = await fetch(API_ENDPOINTS.SAVE_PROJECT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                projectName: projectName,
                data: projectData.data,
                action: 'save'
            })
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        
        if (!result.success) {
            throw new Error(result.error || 'Unknown server error');
        }
        
        // Update local projects object
        window.projects[projectName] = projectData;
        projects = window.projects;
        window.currentProjectName = projectName;
        
        // Backup to localStorage
        saveToLocalStorage('cloudProjects', projects);
        
        showUploadStatus(`✅ Saved "${projectName}" to cloud`, 'success');
        
        // Update UI
        if (typeof window.updateAllUI === 'function') {
            window.updateAllUI();
        }
        
        return true;
    } catch (error) {
        console.error('Cloud save error:', error);
        showUploadStatus(`Failed to save to cloud: ${error.message}`, 'error');
        
        // Still save to localStorage as backup
        try {
            window.projects[projectName] = {
                name: projectName,
                data: deepClone(window.formData),
                currentFacilityIndex: window.currentFacilityIndex,
                timestamp: new Date().toISOString()
            };
            projects = window.projects;
            saveToLocalStorage('cloudProjects', projects);
            showUploadStatus('Saved to localStorage backup only', 'info');
        } catch (e) {
            console.error('localStorage backup failed:', e);
        }
        
        return false;
    }
}

function autoSave() {
    if (window.currentProjectName) {
        // Debounced auto-save to cloud
        clearTimeout(window.autoSaveTimer);
        window.autoSaveTimer = setTimeout(() => {
            saveProjectToCloud(window.currentProjectName);
        }, 2000);
    }
}

window.autoSave = autoSave;

// ============================================
// PROJECT MANAGEMENT
// ============================================
function createNewProjectData() {
    return {
        operator: {
            name: "", currentName: "", otherNames: [], location: "", headquarters: "",
            founded: "", operatingPeriod: "", status: "", parentCompanies: [],
            websites: [], investors: [], keyStaff: { ceo: "", founders: [], keyExecutives: [] },
            notes: []
        },
        facilities: [{
            identification: { name: "", currentName: "", currentOperator: "", otherNames: [] },
            location: "", address: "", otherOperators: [],
            operatingPeriod: { startYear: null, endYear: null, status: "", yearsOfOperation: "", notes: [] },
            staff: { administrator: [], notableStaff: [] },
            profileLinks: [],
            facilityDetails: { type: "", capacity: null, currentCensus: null, ageRange: { min: null, max: null }, gender: "" },
            accreditations: { current: [], past: [] },
            memberships: [], certifications: [], licensing: [],
            resources: {
                hasNews: false, newsDetails: "", hasPressReleases: false, pressReleasesDetails: "",
                hasInspections: false, hasStateReports: false, hasRegulatoryFilings: false,
                hasLawsuits: false, hasPoliceReports: false, hasArticlesOfOrganization: false,
                hasPropertyRecords: false, hasPromotionalMaterials: false, hasEnrollmentDocuments: false,
                hasResearch: false, hasFinancial: false, hasStudent: false, hasStaff: false,
                hasParent: false, hasWebsite: false, hasNATSAP: false, hasSurvivorStories: false,
                hasOther: false, notes: []
            },
            treatmentTypes: {}, philosophy: {}, criticalIncidents: {}, notes: []
        }]
    };
}

function loadProject(projectName) {
    if (!window.projects[projectName]) {
        showUploadStatus(`Project "${projectName}" not found.`, 'error');
        return;
    }

    window.currentProjectName = projectName;
    window.formData = deepClone(window.projects[projectName].data);
    window.currentFacilityIndex = window.projects[projectName].currentFacilityIndex || 0;

    if (window.currentFacilityIndex >= window.formData.facilities.length) {
        window.currentFacilityIndex = 0;
    }

    const projectNameInput = document.getElementById('project-name');
    if (projectNameInput) {
        projectNameInput.value = projectName;
    }

    if (typeof window.updateAllUI === 'function') {
        window.updateAllUI();
    }

    showUploadStatus(`Project "${projectName}" loaded from cloud`, 'success');
}

function newProject() {
    if (!confirm('Start a new blank project? Any unsaved changes will be lost.')) return;

    window.currentProjectName = null;
    window.formData = createNewProjectData();
    window.currentFacilityIndex = 0;

    const projectNameInput = document.getElementById('project-name');
    if (projectNameInput) {
        projectNameInput.value = '';
    }

    if (typeof window.updateAllUI === 'function') {
        window.updateAllUI();
    }

    showUploadStatus('New project created', 'info');
}

// ============================================
// FORM DATA MANAGEMENT
// ============================================
function updateJSON() {
    const jsonDisplay = document.getElementById('json-display');
    if (jsonDisplay) {
        jsonDisplay.textContent = JSON.stringify(window.formData, null, 2);
    }
}

window.updateJSON = updateJSON;

function updateArrayItemValue(path, index, value) {
    const target = path.startsWith('operator.') ? window.formData.operator : window.formData.facilities[window.currentFacilityIndex];
    const array = getNestedValue(target, path.replace('operator.', ''));
    if (Array.isArray(array) && index >= 0 && index < array.length) {
        array[index] = value;
        updateJSON();
        autoSave();
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
        
        // Save custom human names and roles
        if (field === 'name' && value.trim()) {
            addCustomValue('human', value.trim());
        }
        if (field === 'role' && value.trim()) {
            addCustomValue('role', value.trim());
        }
        
        updateJSON();
        autoSave();
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
        autoSave();
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
        autoSave();
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
            
            // Add autocomplete for role
            setTimeout(() => {
                if (!roleInput.dataset.autocompleteInit) {
                    createAutocomplete(roleInput, getAllStaffRoles, 'role');
                    roleInput.dataset.autocompleteInit = 'true';
                }
            }, 100);
            
            itemDiv.appendChild(roleInput);

            const nameInput = document.createElement('input');
            nameInput.type = 'text';
            nameInput.placeholder = 'Name';
            nameInput.value = (item && item.name) ? item.name : '';
            nameInput.className = 'array-input array-input-name';
            nameInput.oninput = () => updateArrayObjectItemValue(path, index, 'name', nameInput.value);
            
            // Add autocomplete for name
            setTimeout(() => {
                if (!nameInput.dataset.autocompleteInit) {
                    createAutocomplete(nameInput, getAllHumanNames, 'human');
                    nameInput.dataset.autocompleteInit = 'true';
                }
            }, 100);
            
            itemDiv.appendChild(nameInput);
        } else {
            const input = document.createElement('input');
            input.type = 'text';
            input.value = item || '';
            input.className = 'array-input';
            input.oninput = () => updateArrayItemValue(path, index, input.value);
            
            // Determine category for autocomplete
            let category = null;
            if (path.includes('operator') || path.includes('Operator')) category = 'operator';
            else if (path.includes('certification')) category = 'certification';
            else if (path.includes('accreditation')) category = 'accreditation';
            else if (path.includes('membership')) category = 'membership';
            else if (path.includes('licensing')) category = 'licensing';
            else if (path.includes('investor')) category = 'investor';
            
            if (category) {
                setTimeout(() => {
                    if (!input.dataset.autocompleteInit) {
                        let dataFunc;
                        switch(category) {
                            case 'operator': dataFunc = getAllOperators; break;
                            case 'certification': dataFunc = getAllCertifications; break;
                            case 'accreditation': dataFunc = getAllAccreditations; break;
                            case 'membership': dataFunc = getAllMemberships; break;
                            case 'licensing': dataFunc = () => Array.from(customLicensing); break;
                            case 'investor': dataFunc = () => Array.from(customInvestors); break;
                            default: dataFunc = () => [];
                        }
                        createAutocomplete(input, dataFunc, category);
                        input.dataset.autocompleteInit = 'true';
                    }
                }, 100);
            }
            
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

    const operatorName = document.getElementById('operator-name');
    if (operatorName) operatorName.value = operator.name || '';
    
    const operatorCurrentName = document.getElementById('operator-current-name');
    if (operatorCurrentName) operatorCurrentName.value = operator.currentName || '';
    
    const operatorLocation = document.getElementById('operator-location');
    if (operatorLocation) operatorLocation.value = operator.location || '';
    
    const operatorHeadquarters = document.getElementById('operator-headquarters');
    if (operatorHeadquarters) operatorHeadquarters.value = operator.headquarters || '';
    
    const operatorFounded = document.getElementById('operator-founded');
    if (operatorFounded) operatorFounded.value = operator.founded || '';
    
    const operatorPeriod = document.getElementById('operator-period');
    if (operatorPeriod) operatorPeriod.value = operator.operatingPeriod || '';
    
    const operatorStatus = document.getElementById('operator-status');
    if (operatorStatus) operatorStatus.value = operator.status || '';
    
    const operatorCeo = document.getElementById('operator-ceo');
    if (operatorCeo) operatorCeo.value = operator.keyStaff?.ceo || '';

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
            const value = getNestedValue(facility, path);
            if (field.type === 'checkbox') {
                field.checked = !!value;
            } else {
                field.value = value ?? '';
            }
        }
    });

    document.querySelectorAll('.facility-checkbox').forEach(checkbox => {
        const path = checkbox.dataset.field;
        checkbox.checked = !!getNestedValue(facility, path);
    });

    const facilityName = document.getElementById('facility-name');
    if (facilityName) facilityName.value = facility.identification?.name || '';
    
    const facilityType = document.getElementById('facility-type');
    if (facilityType) facilityType.value = facility.facilityDetails?.type || '';

    const arrayPaths = ['identification.otherNames', 'otherOperators', 'operatingPeriod.notes', 'staff.administrator', 'staff.notableStaff', 'profileLinks', 'accreditations.current', 'accreditations.past', 'memberships', 'certifications', 'licensing', 'resources.notes', 'notes'];
    arrayPaths.forEach(path => {
        const container = document.querySelector(`[data-path="${path}"]`);
        if (container) {
            renderArray(container, path, getNestedValue(facility, path));
        }
    });
}

window.updateAllUI = function() {
    loadOperatorData();
    loadFacilityData();
    updateFacilityControls();
    updateTableOfContents();
    updateJSON();
    renderSavedProjectsList();
    updateProjectStatus();
    initializeAutocompleteFields();
};

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
            item.innerHTML = `<span class="facility-name ${name === 'Unnamed Facility' ? 'empty' : ''}">${escapeHtmlForAttr(name)}</span><span class="facility-index">${index + 1}</span>`;
            item.onclick = () => navigateToFacility(index);
            facilityList.appendChild(item);
        });
    }
}

function updateFacilityControls() {
    const total = window.formData.facilities?.length || 0;
    const facilityCounter = document.getElementById('facility-counter');
    if (facilityCounter) facilityCounter.textContent = `${window.currentFacilityIndex + 1} of ${total}`;
    
    const name = window.formData.facilities?.[window.currentFacilityIndex]?.identification?.name || 'Unnamed Facility';
    const currentFacilityName = document.getElementById('current-facility-name');
    if (currentFacilityName) currentFacilityName.textContent = name !== 'Unnamed Facility' ? `(${name})` : '';

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
    window.updateAllUI();
    autoSave();
}

function removeFacility() {
    if (window.formData.facilities.length > 1) {
        if (!confirm('Are you sure you want to remove this facility?')) return;
        window.formData.facilities.splice(window.currentFacilityIndex, 1);
        if (window.currentFacilityIndex >= window.formData.facilities.length) {
            window.currentFacilityIndex = window.formData.facilities.length - 1;
        }
        window.updateAllUI();
        autoSave();
    }
}

function cloneFacility() {
    const clone = deepClone(window.formData.facilities[window.currentFacilityIndex]);
    window.formData.facilities.splice(window.currentFacilityIndex + 1, 0, clone);
    window.currentFacilityIndex++;
    window.updateAllUI();
    autoSave();
}

function sortFacilities() {
    if (window.formData.facilities.length <= 1) return;
    const currentName = window.formData.facilities[window.currentFacilityIndex].identification.name;
    window.formData.facilities.sort((a, b) => (a.identification.name || '').localeCompare(b.identification.name || ''));
    const newIndex = window.formData.facilities.findIndex(f => f.identification.name === currentName);
    window.currentFacilityIndex = newIndex !== -1 ? newIndex : 0;
    window.updateAllUI();
    autoSave();
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

function renderSavedProjectsList() {
    const container = document.getElementById('saved-projects-list');
    if (!container) return;
    
    const projectNames = Object.keys(window.projects);
    
    if (projectNames.length === 0) {
        container.innerHTML = '<div class="projects-empty">📭 No saved projects yet</div>';
        return;
    }
    
    projectNames.sort((a, b) => (window.projects[b].timestamp || '').localeCompare(window.projects[a].timestamp || ''));
    
    container.innerHTML = projectNames.map(name => {
        const project = window.projects[name];
        const date = new Date(project.timestamp || 0);
        const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        const facilityCount = project.data?.facilities?.length || 0;
        
        return `<div class="project-item" onclick="loadProject('${escapeHtmlForAttr(name)}')">
                    <div class="project-item-name">${escapeHtmlForAttr(name)}</div>
                    <div class="project-item-date">${dateStr}<br><small>${facilityCount} facilities</small></div>
                    <div class="project-item-actions">
                        <button class="project-item-btn project-item-load" onclick="event.stopPropagation(); loadProject('${escapeHtmlForAttr(name)}')">📂 Load</button>
                    </div>
                </div>`;
    }).join('');
}

function updateProjectStatus() {
    const statusDiv = document.getElementById('project-status');
    if (statusDiv) {
        if (window.currentProjectName) {
            const facilityCount = window.formData?.facilities?.length || 0;
            statusDiv.innerHTML = `<strong>📂 Current Project:</strong> <span style="color: #ff9500;">${escapeHtmlForAttr(window.currentProjectName)}</span> (${facilityCount} facilities)`;
        } else {
            statusDiv.innerHTML = '⚠️ No project loaded - working with temporary data';
        }
    }
}

// ============================================
// FILE IMPORT/EXPORT
// ============================================
function handleFileUpload(event) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (e) => {
        try {
            const data = JSON.parse(e.target.result);
            let importedProjects = {};
            
            if (data.projects) {
                importedProjects = data.projects;
            } else {
                const projectName = data.operator?.name || `imported-${Date.now()}`;
                importedProjects[projectName] = {
                    name: projectName,
                    data: normalizeProjectData(data),
                    timestamp: new Date().toISOString()
                };
            }

            Object.keys(importedProjects).forEach(key => {
                window.projects[key] = importedProjects[key];
            });

            const firstProject = Object.keys(importedProjects)[0];
            if (firstProject) {
                loadProject(firstProject);
            }
            
            showUploadStatus(`Imported ${Object.keys(importedProjects).length} project(s).`, 'success');
        } catch (error) {
            showUploadStatus(`Import failed: ${error.message}`, 'error');
        }
    };
    reader.readAsText(file);
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

// ============================================
// EVENT LISTENER ATTACHMENT
// ============================================
function attachFieldListeners() {
    // Operator fields
    const operatorFields = {
        'operator-name': (val) => {
            setNestedValue(window.formData, 'operator.name', val);
            if (val.trim()) addCustomValue('operator', val.trim());
            updateJSON();
            autoSave();
        },
        'operator-current-name': (val) => {
            setNestedValue(window.formData, 'operator.currentName', val);
            if (val.trim()) addCustomValue('operator', val.trim());
            updateJSON();
            autoSave();
        },
        'operator-location': (val) => {
            setNestedValue(window.formData, 'operator.location', val);
            updateJSON();
            autoSave();
        },
        'operator-headquarters': (val) => {
            setNestedValue(window.formData, 'operator.headquarters', val);
            updateJSON();
            autoSave();
        },
        'operator-founded': (val) => {
            setNestedValue(window.formData, 'operator.founded', val);
            updateJSON();
            autoSave();
        },
        'operator-period': (val) => {
            setNestedValue(window.formData, 'operator.operatingPeriod', val);
            updateJSON();
            autoSave();
        },
        'operator-status': (val) => {
            setNestedValue(window.formData, 'operator.status', val);
            if (val.trim()) addCustomValue('status', val.trim());
            updateJSON();
            autoSave();
        },
        'operator-ceo': (val) => {
            setNestedValue(window.formData, 'operator.keyStaff.ceo', val);
            if (val.trim()) addCustomValue('human', val.trim());
            updateJSON();
            autoSave();
        },
        'facility-name': (val) => {
            setNestedValue(window.formData, `facilities.${window.currentFacilityIndex}.identification.name`, val);
            if (val.trim()) addCustomValue('facility', val.trim());
            window.updateAllUI();
            autoSave();
        },
        'facility-type': (val) => {
            setNestedValue(window.formData, `facilities.${window.currentFacilityIndex}.facilityDetails.type`, val);
            if (val.trim()) addCustomValue('type', val.trim());
            updateJSON();
            autoSave();
        }
    };

    Object.keys(operatorFields).forEach(id => {
        const el = document.getElementById(id);
        if (el && !el.dataset.listenerAttached) {
            el.addEventListener('input', (e) => operatorFields[id](e.target.value));
            el.dataset.listenerAttached = 'true';
        }
    });

    // Facility fields
    document.querySelectorAll('.facility-field').forEach(field => {
        if (!field.dataset.listenerAttached) {
            field.addEventListener('input', () => {
                const path = field.dataset.field;
                let value = field.type === 'number' ? (field.value === '' ? null : parseInt(field.value)) : field.value;
                setNestedValue(window.formData.facilities[window.currentFacilityIndex], path, value);
                
                // Save custom values
                if (value && typeof value === 'string' && value.trim()) {
                    if (path.includes('gender')) addCustomValue('gender', value.trim());
                    if (path.includes('location')) addCustomValue('location', value.trim());
                    if (path.includes('status')) addCustomValue('status', value.trim());
                }
                
                updateJSON();
                autoSave();
            });
            field.dataset.listenerAttached = 'true';
        }
    });

    // Checkboxes
    document.querySelectorAll('.facility-checkbox').forEach(checkbox => {
        if (!checkbox.dataset.listenerAttached) {
            checkbox.addEventListener('change', () => {
                const path = checkbox.dataset.field;
                setNestedValue(window.formData.facilities[window.currentFacilityIndex], path, checkbox.checked);
                updateJSON();
                autoSave();
            });
            checkbox.dataset.listenerAttached = 'true';
        }
    });
}

function attachButtonListeners() {
    // Facility navigation
    const facilityButtons = {
        'add-facility-btn': addFacility,
        'add-facility-main-btn': addFacility,
        'remove-facility-btn': removeFacility,
        'clone-facility-btn': cloneFacility,
        'prev-facility-btn': previousFacility,
        'next-facility-btn': nextFacility,
        'sort-facilities-btn': sortFacilities
    };

    Object.keys(facilityButtons).forEach(id => {
        const btn = document.getElementById(id);
        if (btn && !btn.dataset.listenerAttached) {
            btn.addEventListener('click', facilityButtons[id]);
            btn.dataset.listenerAttached = 'true';
        }
    });

    // Project management
    const saveBtn = document.getElementById('save-project-btn');
    if (saveBtn && !saveBtn.dataset.listenerAttached) {
        saveBtn.onclick = () => {
            const projectName = document.getElementById('project-name')?.value?.trim();
            if (projectName) {
                saveProjectToCloud(projectName);
            } else {
                showUploadStatus('Please enter a project name', 'error');
            }
        };
        saveBtn.dataset.listenerAttached = 'true';
    }

    const newBtn = document.getElementById('new-project-btn');
    if (newBtn && !newBtn.dataset.listenerAttached) {
        newBtn.onclick = newProject;
        newBtn.dataset.listenerAttached = 'true';
    }

    // Import/Export
    const copyBtn = document.getElementById('copy-json-btn');
    if (copyBtn && !copyBtn.dataset.listenerAttached) {
        copyBtn.addEventListener('click', copyToClipboard);
        copyBtn.dataset.listenerAttached = 'true';
    }

    const downloadBtn = document.getElementById('download-json-btn');
    if (downloadBtn && !downloadBtn.dataset.listenerAttached) {
        downloadBtn.addEventListener('click', downloadJSON);
        downloadBtn.dataset.listenerAttached = 'true';
    }

    const fileUpload = document.getElementById('file-upload');
    if (fileUpload && !fileUpload.dataset.listenerAttached) {
        fileUpload.addEventListener('change', handleFileUpload);
        fileUpload.dataset.listenerAttached = 'true';
    }
}

// ============================================
// INITIALIZATION
// ============================================
async function initializeForm() {
    console.log('Initializing consolidated form with cloud-first storage...');
    
    // Load custom data from localStorage (backup only)
    loadCustomDataFromLocalStorage();
    
    // Load all projects from cloud
    await loadAllProjectsFromCloud();
    
    // Initialize form data if needed
    if (!window.formData) {
        window.formData = createNewProjectData();
    }
    
    // Attach all event listeners
    attachFieldListeners();
    attachButtonListeners();
    
    // Initialize array containers
    document.querySelectorAll('.array-container').forEach(container => {
        const path = container.dataset.path;
        if (path) {
            const target = path.startsWith('operator.') ? window.formData.operator : window.formData.facilities[window.currentFacilityIndex];
            const array = getNestedValue(target, path.replace('operator.', ''));
            renderArray(container, path, array);
        }
    });
    
    // Initialize autocomplete fields
    initializeAutocompleteFields();
    
    // Update all UI
    window.updateAllUI();
    
    console.log('Form initialized successfully with', Object.keys(projects).length, 'projects from cloud');
}

// Make key functions globally available
window.loadProject = loadProject;
window.newProject = newProject;
window.saveProjectToCloud = saveProjectToCloud;
window.addFacility = addFacility;
window.removeFacility = removeFacility;
window.cloneFacility = cloneFacility;
window.previousFacility = previousFacility;
window.nextFacility = nextFacility;
window.sortFacilities = sortFacilities;
window.navigateToFacility = navigateToFacility;
window.copyToClipboard = copyToClipboard;
window.downloadJSON = downloadJSON;

// Initialize on DOMContentLoaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeForm);
} else {
    initializeForm();
}

// Fallback: sometimes remote resources or slow loads cause UI bits to render incorrectly.
// Re-run lightweight initialization checks on window.load to recover from intermittent failures.
window.addEventListener('load', () => {
    console.log('facility-form.v3.js: window.load fired — verifying form initialization');
    setTimeout(() => {
        try {
            if (typeof window.updateAllUI === 'function') {
                window.updateAllUI();
                console.log('facility-form.v3.js: updateAllUI re-run on load');
            }
        } catch (e) {
            console.error('facility-form.v3.js: error during load-time UI verification', e);
        }
    }, 150);
});

// Guarded initialization retry loop: some browsers/extensions defer script execution
// or mutate DOM after scripts run, causing array containers or inputs to be missing.
// Retry updateAllUI a few times with backoff and attach a MutationObserver to
// watch for later DOM changes that should trigger re-rendering.
(function setupInitRetriesAndObservers() {
    const maxAttempts = 5;
    let attempt = 0;

    function tryInit() {
        attempt++;
        try {
            if (typeof window.updateAllUI === 'function') {
                window.updateAllUI();
                console.log(`facility-form.v3.js: defensive updateAllUI attempt ${attempt}`);
            }
        } catch (e) {
            console.warn('facility-form.v3.js: defensive updateAllUI failed on attempt', attempt, e);
        }

        // Check for expected DOM pieces; if missing, schedule another attempt
        const toc = document.getElementById('facility-list');
        const projectList = document.getElementById('saved-projects-list');
        const arrayContainers = document.querySelectorAll('.array-container');

        const missing = (!toc || !projectList || arrayContainers.length === 0);
        if (missing && attempt < maxAttempts) {
            setTimeout(tryInit, attempt * 300);
        } else if (missing) {
            console.error('facility-form.v3.js: UI still missing key elements after retries — capture console for debugging');
        }
    }

    // Start after a small delay so DOM mutations (if any) finish
    setTimeout(tryInit, 200);

    // Watch for DOM changes that may affect array rendering or inputs
    try {
        const observer = new MutationObserver((mutations) => {
            let shouldRerender = false;
            for (const m of mutations) {
                if (m.type === 'childList' && m.addedNodes && m.addedNodes.length > 0) {
                    shouldRerender = true;
                    break;
                }
                if (m.type === 'attributes' && (m.attributeName === 'data-path' || m.attributeName === 'class')) {
                    shouldRerender = true;
                    break;
                }
            }
            if (shouldRerender) {
                try {
                    if (typeof window.updateAllUI === 'function') {
                        window.updateAllUI();
                        // Also call addNoteButtons if available
                        if (typeof window.addNoteButtons === 'function') window.addNoteButtons();
                        console.log('facility-form.v3.js: MutationObserver triggered updateAllUI');
                    }
                } catch (e) {
                    console.warn('facility-form.v3.js: MutationObserver updateAllUI error', e);
                }
            }
        });

        observer.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-path', 'class'] });
    } catch (e) {
        console.warn('facility-form.v3.js: failed to attach MutationObserver', e);
    }
})();