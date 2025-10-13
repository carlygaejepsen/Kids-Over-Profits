// GLOBAL DATA & STATE MANAGEMENT
let projects = {};
let currentProjectName = null;
let currentFacilityIndex = 0;
let formData = createNewProjectData();

function createNewProjectData() {
    return {
        operator: { 
            name: "", 
            currentName: "", 
            pastNames: [], 
            location: "", 
            headquarters: "", 
            founded: "", 
            operatingPeriod: "", 
            status: "", 
            parentCompanies: [], 
            websites: [], 
            keyStaff: { ceo: "", founders: [], keyExecutives: [] }, 
            notes: [] 
        },
        facilities: [{
            identification: { 
                name: "", 
                currentName: "", 
                currentOperator: "", 
                pastNames: [] 
            },
            location: "",
            address: "",
            pastOperators: [],
            operatingPeriod: { 
                startYear: null, 
                endYear: null, 
                status: "", 
                yearsOfOperation: "", 
                notes: [] 
            },
            staff: { 
                administrator: [], 
                notableStaff: [] 
            },
            profileLinks: [],
            facilityDetails: { 
                type: "", 
                capacity: null, 
                currentCensus: null, 
                ageRange: { min: null, max: null }, 
                gender: "" 
            },
            accreditations: { 
                current: [], 
                past: [] 
            },
            memberships: [],
            certifications: [],
            licensing: [],
            resources: { 
                hasNews: false, 
                hasPressReleases: false, 
                hasInspections: false, 
                hasStateReports: false, 
                hasRegulatoryFilings: false, 
                hasLawsuits: false, 
                hasSettlements: false, 
                hasViolations: false, 
                hasResearch: false, 
                hasFinancial: false, 
                hasStudent: false,
                hasStaff: false,
                hasParent: false,
                hasWebsite: false,
                hasOther: false, 
                customResources: {}, 
                notes: [] 
            },
            notes: []
        }]
    };
}

function getFacilityDisplayName(facility) { 
    if (!facility || !facility.identification) return 'Unnamed Facility'; 
    return facility.identification.name || facility.identification.currentName || 'Unnamed Facility'; 
}

function sortFacilities() {
    if (formData.facilities.length <= 1) { 
        showUploadStatus('No need to sort - only one facility exists.', 'info'); 
        return; 
    }
    const currentFacilityName = getFacilityDisplayName(formData.facilities[currentFacilityIndex]);
    formData.facilities.sort((a, b) => { 
        const nameA = getFacilityDisplayName(a).toLowerCase(); 
        const nameB = getFacilityDisplayName(b).toLowerCase(); 
        return nameA.localeCompare(nameB); 
    });
    const newIndex = formData.facilities.findIndex(facility => getFacilityDisplayName(facility) === currentFacilityName);
    if (newIndex !== -1) { 
        currentFacilityIndex = newIndex; 
    } else { 
        currentFacilityIndex = 0; 
    }
    updateAllUI();
    showUploadStatus('Facilities sorted alphabetically.', 'success');
}

function navigateToFacility(index) {
    if (index >= 0 && index < formData.facilities.length) {
        currentFacilityIndex = index;
        loadFacilityData();
        updateFacilityControls();
        updateTableOfContents();
        document.querySelector('.facility-controls').scrollIntoView({ behavior: 'smooth' });
    }
}

function addFacility() {
    const newFacility = createNewProjectData().facilities[0];
    formData.facilities.push(newFacility);
    currentFacilityIndex = formData.facilities.length - 1;
    updateAllUI();
}

function removeFacility() {
    if (formData.facilities.length > 1) {
        formData.facilities.splice(currentFacilityIndex, 1);
        if (currentFacilityIndex >= formData.facilities.length) { 
            currentFacilityIndex = formData.facilities.length - 1; 
        }
        updateAllUI();
    }
}

function previousFacility() { 
    if (currentFacilityIndex > 0) { 
        navigateToFacility(currentFacilityIndex - 1); 
    } 
}

function nextFacility() { 
    if (currentFacilityIndex < formData.facilities.length - 1) { 
        navigateToFacility(currentFacilityIndex + 1); 
    } 
}

function handleFileUpload(event) {
    console.log('File upload triggered');
    const file = event.target.files[0];
    if (!file) {
        console.log('No file selected');
        return;
    }
    
    console.log('File selected:', file.name, file.type, file.size);
    
    const reader = new FileReader();
    reader.onload = function(e) { 
        console.log('File read successfully, content length:', e.target.result.length);
        try {
            loadJSONData(e.target.result); 
        } catch (error) {
            console.error('Error in loadJSONData:', error);
            showUploadStatus(`File processing failed: ${error.message}`, 'error');
        }
    };
    reader.onerror = function(e) {
        console.error('FileReader error:', e);
        showUploadStatus('Failed to read file', 'error');
    };
    reader.readAsText(file);
}

function importFromTextarea() { 
    console.log('Import from textarea triggered');
    const textData = document.getElementById('json-paste').value.trim();
    console.log('Textarea content length:', textData.length);
    
    if (!textData) {
        console.log('No textarea content');
        showUploadStatus('Please paste JSON data first', 'error');
        return;
    }
    
    try {
        loadJSONData(textData); 
    } catch (error) {
        console.error('Error in loadJSONData:', error);
        showUploadStatus(`Import failed: ${error.message}`, 'error');
    }
}

function saveCurrentProject() { 
    const projectName = document.getElementById('project-name').value.trim();
    if (projectName) {
        window.projectManager.saveProject(projectName); 
    } else {
        showUploadStatus('Please enter a project name to save.', 'error');
    }
}

function loadProject() { 
    const projectName = document.getElementById('project-name').value.trim();
    if (projectName) {
        window.projectManager.loadProject(projectName); 
    } else {
        showUploadStatus('Please enter a project name to load.', 'error');
    }
}

function newProject() { 
    window.projectManager.newProject(); 
}

function clearAllData() {
    if (confirm('Are you sure you want to clear the form? This action cannot be undone.')) {
        window.projectManager.newProject(false);
    }
}

function exportAllProjects() {
    if (Object.keys(projects).length === 0) { 
        showUploadStatus('No projects loaded to export.', 'info'); 
        return; 
    }
    const exportData = { 
        exportDate: new Date().toISOString(), 
        version: '1.0', 
        totalProjects: Object.keys(projects).length, 
        projects: projects 
    };
    const jsonString = JSON.stringify(exportData, null, 2);
    const blob = new Blob([jsonString], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `facility-projects-export-${new Date().toISOString().split('T')[0]}.json`;
    document.body.appendChild(a); 
    a.click(); 
    document.body.removeChild(a); 
    URL.revokeObjectURL(url);
    showUploadStatus('Successfully exported all loaded projects.', 'success');
}

function copyToClipboard() { 
    navigator.clipboard.writeText(JSON.stringify(formData, null, 2)).then(() => { 
        showUploadStatus('JSON copied to clipboard!', 'success'); 
    }); 
}

function downloadJSON() {
    const jsonString = JSON.stringify(formData, null, 2);
    const blob = new Blob([jsonString], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${currentProjectName || 'facility_data'}.json`;
    document.body.appendChild(a); 
    a.click(); 
    document.body.removeChild(a); 
    URL.revokeObjectURL(url);
}

// UTILITY FUNCTIONS
function deepClone(obj) { 
    return JSON.parse(JSON.stringify(obj)); 
}

function getNestedValue(obj, path) { 
    return path.split('.').reduce((o, k) => (o && o[k] != null) ? o[k] : null, obj); 
}

function setNestedValue(obj, path, value) {
    const keys = path.split('.');
    const lastKey = keys.pop();
    const target = keys.reduce((o, k) => o[k] = o[k] || {}, obj);
    target[lastKey] = value;
}

function updateJSON() { 
    document.getElementById('json-display').textContent = JSON.stringify(formData, null, 2); 
}

function showUploadStatus(message, type) {
    const statusDiv = document.getElementById('upload-status');
    statusDiv.style.display = 'block';
    statusDiv.textContent = message;
    statusDiv.className = 'upload-status ' + type;
    setTimeout(() => { 
        statusDiv.style.display = 'none'; 
    }, 5000);
}

function updateAllUI() {
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

// ARRAY MANAGEMENT FUNCTIONS
function renderArray(container, path, items = []) {
    if (!container) return;
    container.innerHTML = '';
    
    if (!Array.isArray(items)) { 
        items = []; 
    }
    
    const itemsToShow = items.length > 0 ? items : [''];
    
    itemsToShow.forEach((item, index) => {
        const itemDiv = document.createElement('div');
        itemDiv.className = 'array-item';
        
        const input = document.createElement('input');
        input.type = 'text';
        input.value = typeof item === 'string' ? item : '';
        input.className = 'array-input';
        input.addEventListener('input', function() { 
            updateArrayItemValue(path, index, this.value); 
        });
        itemDiv.appendChild(input);
        
        if (itemsToShow.length > 1) {
            const removeBtn = document.createElement('button');
            removeBtn.className = 'btn';
            removeBtn.textContent = 'Remove';
            removeBtn.type = 'button';
            removeBtn.addEventListener('click', function() { 
                removeArrayItemAtIndex(path, index); 
            });
            itemDiv.appendChild(removeBtn);
        }
        
        container.appendChild(itemDiv);
    });
    
    const addButton = document.createElement('button');
    addButton.className = 'add-item-btn';
    addButton.textContent = 'Add Item';
    addButton.type = 'button';
    addButton.addEventListener('click', function() { 
        addNewArrayItem(path); 
    });
    container.appendChild(addButton);
}

function updateArrayItemValue(path, index, value) {
    const target = path.startsWith('operator.') ? formData.operator : formData.facilities[currentFacilityIndex];
    let array = getNestedValue(target, path.replace('operator.', ''));
    
    if (!Array.isArray(array)) { 
        array = []; 
        setNestedValue(target, path.replace('operator.', ''), array); 
    }
    
    while (array.length <= index) { 
        array.push(''); 
    }
    
    array[index] = value;
    
    if (path === 'identification.name' || path === 'identification.currentName') { 
        updateTableOfContents(); 
        updateFacilityControls(); 
    }
    
    updateJSON();
}

function addNewArrayItem(path) {
    const target = path.startsWith('operator.') ? formData.operator : formData.facilities[currentFacilityIndex];
    let array = getNestedValue(target, path.replace('operator.', ''));
    
    if (!Array.isArray(array)) { 
        array = []; 
        setNestedValue(target, path.replace('operator.', ''), array); 
    }
    
    array.push('');
    const container = document.querySelector(`[data-path="${path}"]`);
    if (container) { 
        renderArray(container, path, array); 
    }
    updateJSON();
}

function removeArrayItemAtIndex(path, index) {
    const target = path.startsWith('operator.') ? formData.operator : formData.facilities[currentFacilityIndex];
    const array = getNestedValue(target, path.replace('operator.', ''));
    
    if (Array.isArray(array) && array.length > 1 && index >= 0 && index < array.length) {
        array.splice(index, 1);
        const container = document.querySelector(`[data-path="${path}"]`);
        if (container) { 
            renderArray(container, path, array); 
        }
        updateJSON();
    }
}

// DATA LOADING FUNCTIONS
function loadOperatorData() {
    if (!formData.operator) formData.operator = createNewProjectData().operator;
    
    document.getElementById('operator-name').value = formData.operator.name || '';
    document.getElementById('operator-current-name').value = formData.operator.currentName || '';
    document.getElementById('operator-location').value = formData.operator.location || '';
    document.getElementById('operator-headquarters').value = formData.operator.headquarters || '';
    document.getElementById('operator-founded').value = formData.operator.founded || '';
    document.getElementById('operator-period').value = formData.operator.operatingPeriod || '';
    document.getElementById('operator-status').value = formData.operator.status || '';
    document.getElementById('operator-ceo').value = formData.operator.keyStaff?.ceo || '';
    
    renderArray(document.querySelector('[data-path="operator.pastNames"]'), 'operator.pastNames', formData.operator.pastNames);
    renderArray(document.querySelector('[data-path="operator.parentCompanies"]'), 'operator.parentCompanies', formData.operator.parentCompanies);
    renderArray(document.querySelector('[data-path="operator.websites"]'), 'operator.websites', formData.operator.websites);
    renderArray(document.querySelector('[data-path="operator.keyStaff.founders"]'), 'operator.keyStaff.founders', formData.operator.keyStaff?.founders);
    renderArray(document.querySelector('[data-path="operator.keyStaff.keyExecutives"]'), 'operator.keyStaff.keyExecutives', formData.operator.keyStaff?.keyExecutives);
    renderArray(document.querySelector('[data-path="operator.notes"]'), 'operator.notes', formData.operator.notes);
}

function loadFacilityData() {
    if (!formData.facilities || formData.facilities.length === 0) {
        formData.facilities = createNewProjectData().facilities;
    }
    
    const currentFacility = formData.facilities[currentFacilityIndex];
    if (!currentFacility) return;
    
    // Load facility fields
    document.querySelectorAll('.facility-field').forEach(field => {
        const path = field.dataset.field;
        const value = getNestedValue(currentFacility, path);
        field.value = (value === null || value === undefined) ? '' : value;
    });
    
    // Load facility checkboxes
    document.querySelectorAll('.facility-checkbox').forEach(checkbox => {
        const path = checkbox.dataset.field;
        checkbox.checked = !!getNestedValue(currentFacility, path);
    });
    
    // Load arrays
    renderArray(document.querySelector('[data-path="identification.pastNames"]'), 'identification.pastNames', currentFacility.identification.pastNames);
    renderArray(document.querySelector('[data-path="pastOperators"]'), 'pastOperators', currentFacility.pastOperators);
    renderArray(document.querySelector('[data-path="operatingPeriod.notes"]'), 'operatingPeriod.notes', currentFacility.operatingPeriod.notes);
    renderArray(document.querySelector('[data-path="staff.administrator"]'), 'staff.administrator', currentFacility.staff.administrator);
    renderArray(document.querySelector('[data-path="staff.notableStaff"]'), 'staff.notableStaff', currentFacility.staff.notableStaff);
    renderArray(document.querySelector('[data-path="profileLinks"]'), 'profileLinks', currentFacility.profileLinks);
    renderArray(document.querySelector('[data-path="accreditations.current"]'), 'accreditations.current', currentFacility.accreditations.current);
    renderArray(document.querySelector('[data-path="accreditations.past"]'), 'accreditations.past', currentFacility.accreditations.past);
    renderArray(document.querySelector('[data-path="memberships"]'), 'memberships', currentFacility.memberships);
    renderArray(document.querySelector('[data-path="certifications"]'), 'certifications', currentFacility.certifications);
    renderArray(document.querySelector('[data-path="licensing"]'), 'licensing', currentFacility.licensing);
    renderArray(document.querySelector('[data-path="resources.notes"]'), 'resources.notes', currentFacility.resources.notes);
    renderArray(document.querySelector('[data-path="notes"]'), 'notes', currentFacility.notes);
}

function updateTableOfContents() {
    const facilityList = document.getElementById('facility-list');
    const tocStats = document.getElementById('toc-stats');
    const total = formData.facilities?.length || 0;
    
    tocStats.textContent = `Total: ${total} facilit${total === 1 ? 'y' : 'ies'}`;
    facilityList.innerHTML = '';
    
    formData.facilities?.forEach((facility, index) => {
        const item = document.createElement('div');
        item.className = `facility-item ${index === currentFacilityIndex ? 'active' : ''}`;
        const name = getFacilityDisplayName(facility);
        item.innerHTML = `<span class="facility-name ${name === 'Unnamed Facility' ? 'empty' : ''}">${name}</span><span class="facility-index">${index + 1}</span>`;
        item.addEventListener('click', () => navigateToFacility(index));
        facilityList.appendChild(item);
    });
}

function updateFacilityControls() {
    const total = formData.facilities?.length || 0;
    document.getElementById('facility-counter').textContent = `${currentFacilityIndex + 1} of ${total}`;
    
    const name = getFacilityDisplayName(formData.facilities?.[currentFacilityIndex]);
    document.getElementById('current-facility-name').textContent = name !== 'Unnamed Facility' ? `(${name})` : '';
    
    document.getElementById('remove-facility-btn').style.display = total > 1 ? '' : 'none';
    document.getElementById('prev-facility-btn').style.display = total > 1 && currentFacilityIndex > 0 ? '' : 'none';
    document.getElementById('next-facility-btn').style.display = total > 1 && currentFacilityIndex < total - 1 ? '' : 'none';
}

// FIXED JSON DATA LOADING FUNCTION
function loadJSONData(jsonString) {
    if (!jsonString) { 
        showUploadStatus('No data to import.', 'error'); 
        return; 
    }
    
    try {
        const data = JSON.parse(jsonString);
        console.log('Parsed JSON data:', data);
        
        let importedProjects = {};
        
        // Check if this is an export format with projects
        if (data && typeof data.projects === 'object' && data.projects !== null) {
            console.log('Detected export format with projects');
            importedProjects = data.projects;
        } 
        // Check if this is a single project with operator and facilities
        else if (data && data.operator && data.facilities) {
            console.log('Detected single project format');
            const projectName = data.operator.name || `imported-project-${Date.now()}`;
            importedProjects[projectName] = { 
                name: projectName, 
                data: data, 
                timestamp: new Date().toISOString(),
                currentFacilityIndex: 0
            };
        } 
        // Check if this looks like just operator data
        else if (data && (data.name || data.currentName) && !data.facilities) {
            console.log('Detected operator-only data, creating project structure');
            const projectName = data.name || data.currentName || `imported-project-${Date.now()}`;
            const newProjectData = createNewProjectData();
            newProjectData.operator = data;
            importedProjects[projectName] = { 
                name: projectName, 
                data: newProjectData, 
                timestamp: new Date().toISOString(),
                currentFacilityIndex: 0
            };
        }
        // Handle array of facilities
        else if (Array.isArray(data)) {
            console.log('Detected array of facilities');
            const projectName = `imported-facilities-${Date.now()}`;
            const newProjectData = createNewProjectData();
            newProjectData.facilities = data;
            importedProjects[projectName] = { 
                name: projectName, 
                data: newProjectData, 
                timestamp: new Date().toISOString(),
                currentFacilityIndex: 0
            };
        }
        // Handle direct facility/operator data structure
        else if (data && (data.operator || data.facilities)) {
            console.log('Detected direct facility/operator structure');
            const projectName = data.operator?.name || `imported-project-${Date.now()}`;
            importedProjects[projectName] = { 
                name: projectName, 
                data: data, 
                timestamp: new Date().toISOString(),
                currentFacilityIndex: 0
            };
        }
        else {
            console.log('Unrecognized JSON structure:', data);
            throw new Error("Unrecognized JSON structure. Expected format with 'operator' and 'facilities' properties, or export format with 'projects'.");
        }
        
        // Merge imported projects into global projects
        let importCount = 0;
        Object.keys(importedProjects).forEach(key => {
            projects[key] = deepClone(importedProjects[key]);
            importCount++;
            console.log(`Added project: ${key}`);
        });
        
        // Load the first imported project
        const projectKeys = Object.keys(importedProjects);
        if (projectKeys.length > 0) {
            window.projectManager.loadProject(projectKeys[0]);
        }
        
        showUploadStatus(`Import successful. ${importCount} project(s) imported.`, 'success');
        
        // Clear the input fields
        document.getElementById('json-paste').value = '';
        const fileInput = document.getElementById('file-upload');
        if (fileInput) fileInput.value = '';
        
    } catch (error) {
        console.error('JSON import error:', error);
        showUploadStatus(`Import failed: ${error.message}`, 'error');
    }
}

// PROJECT MANAGEMENT CLASS (WITH CLOUD STORAGE RESTORED)
class ProjectManager {
    async saveProject(name) {
        if (!name || name.trim() === '') { 
            showUploadStatus('Please enter a project name to save.', 'error'); 
            return; 
        }
        
        const projectName = name.trim();

        // Update the project in memory first
        projects[projectName] = { 
            name: projectName, 
            data: deepClone(formData), 
            currentFacilityIndex: currentFacilityIndex, 
            timestamp: new Date().toISOString() 
        };
        
        // Save to the cloud using your actual API file
        try {
            showUploadStatus(`Saving "${projectName}" to the cloud...`, 'info');
            
            const response = await fetch('https://kidsoverprofits.org/wp-content/themes/child/api/save-master-data.php', { 
                method: 'POST', 
                headers: { 'Content-Type': 'application/json' }, 
                body: JSON.stringify({ 
                    unique_name: projectName, 
                    json_data: formData  // Send just the facility/operator data
                }) 
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            console.log('Save response:', result);
            
            if (!result.success) {
                throw new Error(result.error || 'Unknown server error');
            }
            
            showUploadStatus(result.message, 'success');
        } catch (error) {
            console.error('Cloud save error:', error);
            showUploadStatus(`Cloud save failed: ${error.message}`, 'error');
            return;
        }

        currentProjectName = projectName;
        this.renderSavedProjectsList();
        this.updateProjectStatus();
    }

    loadProject(name) {
        if (!projects[name]) { 
            showUploadStatus(`Project "${name}" not found.`, 'error'); 
            return; 
        }
        
        currentProjectName = name;
        formData = deepClone(projects[name].data);
        currentFacilityIndex = projects[name].currentFacilityIndex || 0;
        
        // Ensure currentFacilityIndex is valid
        if (currentFacilityIndex >= formData.facilities.length) {
            currentFacilityIndex = 0;
        }
        
        document.getElementById('project-name').value = name;
        updateAllUI();
        showUploadStatus(`Project "${name}" loaded successfully.`, 'success');
    }

    async loadAllProjectsFromDB() {
        try {
            showUploadStatus('Loading projects from cloud...', 'info');
            
            // Try multiple possible API paths
            const possiblePaths = [
                'https://kidsoverprofits.org/wp-content/themes/child/api/get-master-data.php',
                './api/get-master-data.php',
                '/wp-content/themes/kadence-child/api/get-master-data.php',
                window.location.origin + 'https://kidsoverprofits.org/wp-content/themes/child/api/get-master-data.php'
            ];
            
            let response;
            let workingUrl;
            
            for (const url of possiblePaths) {
                try {
                    console.log('Trying API URL:', url);
                    response = await fetch(url);
                    if (response.ok) {
                        workingUrl = url;
                        console.log('Success with URL:', url);
                        break;
                    }
                } catch (e) {
                    console.log('Failed with URL:', url, e.message);
                }
            }
            
            if (!response || !response.ok) {
                throw new Error('Could not reach API at any of the expected paths');
            }
            
            console.log('Response status:', response.status);
            
            // Check if response is actually JSON
            const contentType = response.headers.get('content-type');
            console.log('Content-Type:', contentType);
            
            if (!contentType || !contentType.includes('application/json')) {
                const responseText = await response.text();
                console.error('Expected JSON but got:', responseText.substring(0, 500));
                throw new Error(`Expected JSON response but got ${contentType}`);
            }
            
            const result = await response.json();
            console.log('API Response:', result);
            
            if (!result.success) {
                throw new Error(result.error || 'API returned success: false');
            }
            
            // Store the working URL for save operations
            this.workingApiUrl = workingUrl.replace('https://kidsoverprofits.org/wp-content/themes/child/api/get-master-data.php', 'https://kidsoverprofits.org/wp-content/themes/child/api/save-master-data.php');
            
            projects = result.projects || {};
            const projectKeys = Object.keys(projects);
            console.log('Loaded projects:', projectKeys);

            if (projectKeys.length > 0) {
                this.loadProject(projectKeys[0]);
                showUploadStatus(`Loaded ${projectKeys.length} projects from the cloud.`, 'success');
            } else {
                showUploadStatus('No projects in cloud. Ready for new project.', 'info');
                this.newProject(false);
            }
        } catch(error) {
            console.error('Cloud loading error:', error);
            showUploadStatus(`Could not load from cloud: ${error.message}`, 'error');
            showUploadStatus('Working in offline mode. Check console for details.', 'info');
            this.newProject(false);
        }
    }
    
    newProject(confirmFirst = true) {
        if (confirmFirst && !confirm('Start a new blank project? This will clear the current form data.')) return;
        
        currentProjectName = null;
        formData = createNewProjectData();
        currentFacilityIndex = 0;
        document.getElementById('project-name').value = '';
        updateAllUI();
        showUploadStatus('New project created.', 'info');
    }

    renderSavedProjectsList() {
        const container = document.getElementById('saved-projects-list');
        const projectList = projects;
        const projectNames = Object.keys(projectList);
        
        if (projectNames.length === 0) {
            container.innerHTML = '<div style="color: #6b7280; font-style: italic;">No saved projects</div>';
            return;
        }
        
        projectNames.sort((a, b) => (projectList[b].timestamp || '').localeCompare(projectList[a].timestamp || ''));
        
        container.innerHTML = projectNames.map(name => {
            const project = projectList[name];
            const date = new Date(project.timestamp || 0);
            const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            const facilityCount = project.data?.facilities?.length || 0;
            
            return `<div class="project-item">
                        <span class="project-item-name" onclick="window.projectManager.loadProject('${name}')" title="Click to load">${name}</span>
                        <span class="project-item-date">${dateStr} (${facilityCount} facilities)</span>
                        <div class="project-item-actions">
                            <button class="project-item-btn project-item-load" onclick="window.projectManager.loadProject('${name}')">Load</button>
                        </div>
                    </div>`;
        }).join('');
    }
    
    updateProjectStatus() {
        const statusDiv = document.getElementById('project-status');
        if (currentProjectName) {
            const facilityCount = formData?.facilities?.length || 0;
            statusDiv.innerHTML = `Current project: <strong>${currentProjectName}</strong> (${facilityCount} facilities)`;
        } else {
            statusDiv.textContent = 'No project loaded - working with temporary data';
        }
    }
}

function toggleSection(sectionId) { 
    const section = document.getElementById(sectionId); 
    if (section) { 
        section.classList.toggle('expanded'); 
    } 
}

function toggleTableOfContents() { 
    const toc = document.getElementById('facility-toc'); 
    if (toc) { 
        toc.classList.toggle('collapsed'); 
    } 
}

function setupEventListeners() {
    console.log('Setting up event listeners...');
    
    // File upload
    const fileInput = document.getElementById('file-upload');
    if (fileInput) {
        fileInput.addEventListener('change', handleFileUpload);
        console.log('File upload listener attached');
    }
    
    // Import from textarea button
    const importBtn = document.getElementById('import-json-btn');
    if (importBtn) {
        importBtn.addEventListener('click', importFromTextarea);
        console.log('Import button listener attached');
    }
    
    // Save project button
    const saveBtn = document.getElementById('save-project-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', saveCurrentProject);
        console.log('Save button listener attached');
    }
    
    // Load project button
    const loadBtn = document.getElementById('load-project-btn');
    if (loadBtn) {
        loadBtn.addEventListener('click', loadProject);
        console.log('Load button listener attached');
    }
    
    // New project button
    const newBtn = document.getElementById('new-project-btn');
    if (newBtn) {
        newBtn.addEventListener('click', newProject);
        console.log('New project button listener attached');
    }
    
    // Export all button
    const exportBtn = document.getElementById('export-all-btn');
    if (exportBtn) {
        exportBtn.addEventListener('click', exportAllProjects);
        console.log('Export button listener attached');
    }
    
    // Clear data button
    const clearBtn = document.getElementById('clear-all-btn');
    if (clearBtn) {
        clearBtn.addEventListener('click', clearAllData);
        console.log('Clear button listener attached');
    }
    
    // Copy to clipboard button
    const copyBtn = document.querySelector('button[onclick="copyToClipboard()"]');
    if (copyBtn) {
        copyBtn.removeAttribute('onclick');
        copyBtn.addEventListener('click', copyToClipboard);
        console.log('Copy button listener attached');
    }
    
    // Download JSON button
    const downloadBtn = document.querySelector('button[onclick="downloadJSON()"]');
    if (downloadBtn) {
        downloadBtn.removeAttribute('onclick');
        downloadBtn.addEventListener('click', downloadJSON);
        console.log('Download button listener attached');
    }
    
    // Section toggles - remove onclick and add listeners
    document.querySelectorAll('.section-header').forEach(header => {
        const onclick = header.getAttribute('onclick');
        if (onclick) {
            header.removeAttribute('onclick');
            const sectionId = onclick.match(/toggleSection\('([^']+)'\)/)[1];
            header.addEventListener('click', () => toggleSection(sectionId));
        }
    });
    
    // TOC toggle
    const tocToggle = document.getElementById('toc-toggle-btn');
    if (tocToggle) {
        tocToggle.addEventListener('click', toggleTableOfContents);
        console.log('TOC toggle listener attached');
    }
    
    // Facility navigation buttons
    const addFacilityBtn = document.getElementById('add-facility-btn');
    if (addFacilityBtn) {
        addFacilityBtn.addEventListener('click', addFacility);
        console.log('Add facility button listener attached');
    }
    
    const addFacilityMainBtn = document.getElementById('add-facility-main-btn');
    if (addFacilityMainBtn) {
        addFacilityMainBtn.addEventListener('click', addFacility);
        console.log('Add facility main button listener attached');
    }
    
    const removeFacilityBtn = document.getElementById('remove-facility-btn');
    if (removeFacilityBtn) {
        removeFacilityBtn.addEventListener('click', removeFacility);
        console.log('Remove facility button listener attached');
    }
    
    const prevBtn = document.getElementById('prev-facility-btn');
    if (prevBtn) {
        prevBtn.addEventListener('click', previousFacility);
        console.log('Previous facility button listener attached');
    }
    
    const nextBtn = document.getElementById('next-facility-btn');
    if (nextBtn) {
        nextBtn.addEventListener('click', nextFacility);
        console.log('Next facility button listener attached');
    }
    
    const sortBtn = document.getElementById('sort-facilities-btn');
    if (sortBtn) {
        sortBtn.addEventListener('click', sortFacilities);
        console.log('Sort facilities button listener attached');
    }
    
    console.log('All event listeners set up');
}

// INITIALIZATION
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM Content Loaded - Initializing application...');
    
    // Create the project manager
    window.projectManager = new ProjectManager();
    
    // Load projects from cloud on startup
    window.projectManager.loadAllProjectsFromDB();
    
    // Set up all event listeners instead of onclick handlers
    setupEventListeners();
    
    // Set up operator field event listeners
    const operatorFields = [
        { id: 'operator-name', field: 'name' },
        { id: 'operator-current-name', field: 'currentName' },
        { id: 'operator-location', field: 'location' },
        { id: 'operator-headquarters', field: 'headquarters' },
        { id: 'operator-founded', field: 'founded' },
        { id: 'operator-period', field: 'operatingPeriod' },
        { id: 'operator-status', field: 'status' },
        { id: 'operator-ceo', field: 'keyStaff.ceo' }
    ];
    
    operatorFields.forEach(({id, field}) => {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('input', function() {
                setNestedValue(formData.operator, field, this.value);
                updateJSON();
            });
        }
    });
    
    // Set up facility field event listeners
    document.querySelectorAll('.facility-field').forEach(field => {
        field.addEventListener('input', function() {
            const path = this.dataset.field;
            let value = this.value;
            
            // Handle numeric fields
            if (this.type === 'number') {
                value = value === '' ? null : parseInt(value);
            }
            
            setNestedValue(formData.facilities[currentFacilityIndex], path, value);
            
            if (path === 'identification.name' || path === 'identification.currentName') {
                updateTableOfContents();
                updateFacilityControls();
            }
            
            updateJSON();
        });
    });
    
    // Set up facility checkbox event listeners
    document.querySelectorAll('.facility-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const path = this.dataset.field;
            setNestedValue(formData.facilities[currentFacilityIndex], path, this.checked);
            updateJSON();
        });
    });
    
// ADD THIS: Initialize all arrays on page load
    initializeAllArrays();
    
    console.log('Application initialized successfully');
});

// ADD THIS NEW FUNCTION:
function initializeAllArrays() {
    // Initialize all array containers with empty arrays so they're editable
    document.querySelectorAll('[data-path]').forEach(container => {
        const path = container.dataset.path;
        renderArray(container, path, []);
    });
}