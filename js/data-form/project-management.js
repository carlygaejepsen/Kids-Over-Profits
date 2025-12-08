

export async function saveProjectToCloud(projectName, action = 'save') {
    // Handle delete action separately
    if (action === 'delete') {
        try {
            window.showUploadStatus(`💀 Deleting "${projectName}"...`, 'info');
            debugLog('=== DELETE PROJECT START ===');
            debugLog('Project name:', projectName);

            const payload = {
                projectName: projectName,
                action: 'delete'
            };

            const API_ENDPOINTS = getAPIEndpoints();
            const response = await fetch(API_ENDPOINTS.SAVE_PROJECT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP ${response.status}: ${errorText.substring(0, 100)}`);
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.error || result.message || 'Unknown server error');
            }

            // Remove from local projects object
            delete window.projects[projectName];
            localStorage.removeItem(`project_${projectName}`);

            // If this was the current project, clear it
            if (window.currentProjectName === projectName) {
                newProject();
            }

            debugLog('✅ Delete successful!');
            debugLog('=== DELETE PROJECT END ===');
            window.showUploadStatus(`✅ Deleted "${projectName}" successfully!`, 'success');

            // Update UI
            if (typeof window.updateAllUI === 'function') {
                window.updateAllUI();
            }

            // Refresh the project panels to remove the deleted project from the list
            if (typeof window.refreshSavedProjectPanels === 'function') {
                window.refreshSavedProjectPanels();
            }

            return true;
        } catch (error) {
            console.error('❌ DELETE FAILED:', error.message);
            window.showUploadStatus(`❌ Failed to delete: ${error.message}`, 'error');
            return false;
        }
    }

    // Normal save action
    if (!projectName || !window.formData) {
        window.showUploadStatus('❌ No project name or data to save', 'error');
        console.error('❌ Save blocked: projectName=', projectName, 'formData exists=', !!window.formData);
        return false;
    }

    if (isSuggestionMode()) {
        window.currentProjectName = projectName;

        const saved = persistProjectLocally(projectName, {
            showStatus: true,
            statusType: 'info',
            statusMessage: '💾 Draft saved locally. Use "Submit Suggestion for Review" to send updates to Kids Over Profits.'
        });

        if (!saved) {
            window.showUploadStatus('❌ Unable to save draft locally. Please try again.', 'error');
        } else {
            debugLog('Suggestion mode active — skipping remote save for project "%s".', projectName);
        }

        return false;
    }

    try {
        window.showUploadStatus(`💾 Saving "${projectName}" to cloud...`, 'info');
        debugLog('=== SAVE PROJECT START ===');
        debugLog('Project name:', projectName);
        debugLog('Facility count:', window.formData.facilities?.length || 0);
        debugLog('Data size:', JSON.stringify(window.formData).length, 'characters');

        if (typeof window.ensureReferrerDataStructures === 'function') {
            window.ensureReferrerDataStructures();
        }

        console.log('💾 SAVE: About to save project to cloud');
        console.log('  - referrerConsultants count:', window.formData.referrerConsultants?.length);
        console.log('  - referrerConsultants[0]:', JSON.stringify(window.formData.referrerConsultants?.[0]));

        const normalizedName = projectName.toLowerCase().trim();
        let category;
        if (window.US_STATE_SET?.has(normalizedName) || window.COUNTRY_SET?.has(normalizedName)) {
            category = 'locations';
            debugLog('Category forced to "locations" for state/country name:', projectName);
        } else {
            category = window.projects?.[projectName]?.category;
            if (!category) {
                const activeTab = document.querySelector('.category-tab.active');
                category = activeTab ? activeTab.dataset.category : 'companies';
            }
        }

        const projectData = {
            name: projectName,
            data: window.deepClone(window.formData),
            currentFacilityIndex: window.currentFacilityIndex,
            timestamp: new Date().toISOString(),
            category: category
        };

        const payload = {
            projectName: projectName,
            data: projectData.data,
            category: projectData.category,
            currentFacilityIndex: projectData.currentFacilityIndex,
            timestamp: projectData.timestamp,
            action: action
        };

        const payloadSize = JSON.stringify(payload).length;
        const API_ENDPOINTS = getAPIEndpoints();
        debugLog('Payload size:', payloadSize, 'characters');
        debugLog('Sending to:', API_ENDPOINTS.SAVE_PROJECT);

        console.log('💾 SAVE: Payload being sent to database:');
        console.log('  - payload.data.referrerConsultants:', JSON.stringify(payload.data.referrerConsultants));

        let response;
        let usedRestApi = false;

        try {
            response = await fetch(API_ENDPOINTS.SAVE_PROJECT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            debugLog('Response status:', response.status, response.statusText);
            debugLog('Response headers:', Object.fromEntries(response.headers.entries()));

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const responseText = await response.text();
                if (responseText.includes('<?php')) {
                    console.warn('⚠️ Direct PHP endpoint not working, trying REST API fallback...');
                    throw new Error('PHP_NOT_EXECUTING');
                }
                console.error('❌ Expected JSON but got:', contentType);
                console.error('Response preview:', responseText.substring(0, 500));
                throw new Error(`Expected JSON response, got ${contentType}`);
            }
        } catch (directError) {
            if (directError.message === 'PHP_NOT_EXECUTING' || directError.message.includes('Failed to fetch')) {
                debugLog('🔄 Trying WordPress REST API fallback...');
                const restEndpoint = API_ENDPOINTS.REST_SAVE_PROJECT || '/wp-json/kop/v1/projects/save';
                const restNonce = API_ENDPOINTS.REST_NONCE || window.wpApiSettings?.nonce || '';
                
                response = await fetch(restEndpoint, {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': restNonce
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload)
                });
                usedRestApi = true;
                debugLog('REST API response status:', response.status);
            } else {
                throw directError;
            }
        }
        if (!response.ok) {
            const errorText = await response.text();
            console.error('❌ Save failed response body:', errorText.substring(0, 500));
            throw new Error(`HTTP ${response.status}: ${errorText.substring(0, 100)}`);
        }

        const result = await response.json();
        debugLog('Save result:', result);

        if (!result.success) {
            throw new Error(result.error || result.message || 'Unknown server error');
        }
        
        window.projects[projectName] = projectData;
        window.invalidateAggregatedData();
        window.currentProjectName = projectName;

        persistProjectLocally(projectName);

        debugLog('✅ Save successful!' + (usedRestApi ? ' (via REST API)' : ''));
        debugLog('=== SAVE PROJECT END ===');
        window.showUploadStatus(`✅ Saved "${projectName}" successfully!`, 'success');

        if (typeof window.updateAllUI === 'function') {
            window.updateAllUI();
        }

        return true;
    } catch (error) {
        console.error('❌ SAVE FAILED:', error.message);
        console.error('Error stack:', error.stack);
        window.showUploadStatus(`❌ Failed to save: ${error.message}`, 'error');

        const fallbackSaved = persistProjectLocally(projectName, {
            showStatus: true,
            statusType: 'info',
            statusMessage: '⚠️ Saved to local storage only (cloud save failed).'
        });

        if (fallbackSaved) {
            debugLog('✅ Saved to localStorage backup');
        } else {
            console.error('❌ localStorage backup also failed.');
            window.showUploadStatus('❌ Save completely failed - check console for details', 'error');
        }

        return false;
    }
}

export function autoSave() {
    if (window.isUpdatingUI) {
        debugLog('⏸️ autoSave skipped - UI update in progress');
        return;
    }

    if (isSuggestionMode()) {
        clearTimeout(window.autoSaveTimer);
        window.autoSaveTimer = setTimeout(() => {
            if (!window.currentProjectName) {
                return;
            }

            const saved = persistProjectLocally(window.currentProjectName);
            if (saved) {
                debugLog('Suggestion draft saved locally for', window.currentProjectName);
            }
        }, 150000);
        return;
    }

    if (window.currentProjectName) {
        clearTimeout(window.autoSaveTimer);
        window.autoSaveTimer = setTimeout(async () => {
            if (window.isSaveInProgress) {
                debugLog('⏸️ Save skipped - another save already in progress');
                return;
            }
            window.isSaveInProgress = true;
            try {
                await saveProjectToCloud(window.currentProjectName);
            } finally {
                window.isSaveInProgress = false;
            }
        }, 150000);
    }
}
window.autoSave = autoSave;

export function loadProject(projectName) {
    debugLog('🔄 loadProject called with:', projectName);
    debugLog('📦 Available projects:', Object.keys(window.projects || {}));
    
    window.showUploadStatus(`Loading project "${projectName}"...`, 'info');

    let resolvedName = projectName;
    if (!window.projects[projectName]) {
        const upperName = projectName.toUpperCase();
        if (window.projects[upperName]) {
            resolvedName = upperName;
            debugLog('📍 Resolved project name to uppercase:', resolvedName);
        }
    }
    
    if (!window.projects[resolvedName]) {
        console.error('❌ Project not found:', projectName, '(also tried:', projectName.toUpperCase(), ')');
        window.showUploadStatus(`Project "${projectName}" not found.`, 'error');
        return Promise.reject(new Error(`Project not found: ${projectName}`));
    }
    
    projectName = resolvedName;

    const projectCategory = determineProjectCategory(projectName);
    debugLog('📂 Project category:', projectCategory);

    const targetTab = document.querySelector(`.category-tab[data-category="${projectCategory}"]`);
    if (targetTab) {
        document.querySelectorAll('.category-tab').forEach(t => t.classList.remove('active'));
        targetTab.classList.add('active');
        debugLog('✅ Switched to', projectCategory, 'tab');
    }

    document.querySelectorAll('.category-content').forEach(content => {
        content.classList.add('view-hidden', 'd-none');
    });
    const contentId = projectCategory === 'locations' ? 'states-content' : `${projectCategory}-content`;
    const activeContent = document.getElementById(contentId);
    if (activeContent) {
        activeContent.classList.remove('view-hidden', 'd-none');
    }
    
    return new Promise((resolve) => {
        setTimeout(() => {
            const projectCategory = determineProjectCategory(projectName);
            window.currentProjectName = projectName;
            
            debugLog('📥 Loading project data structure:', {
                'projectName': projectName,
                'projectObject': window.projects[projectName],
                'projectData': window.projects[projectName].data
            });
            
            if (window.projects[projectName].data && Object.keys(window.projects[projectName].data).length > 0) {
                window.formData = normalizeProjectData(window.deepClone(window.projects[projectName].data));
            } else {
                window.formData = createNewProjectData();
            }
            if (typeof window.ensureReferrerDataStructures === 'function') {
                window.ensureReferrerDataStructures();
            }
            window.currentFacilityIndex = window.projects[projectName].currentFacilityIndex || 0;

            if (!window.formData.facilities || window.currentFacilityIndex >= window.formData.facilities.length) {
                window.currentFacilityIndex = 0;
            }

            if (projectCategory === 'referrers') {
                window.currentConsultantIndex = 0;
            }

            const projectNameInput = document.getElementById('project-name');
            if (projectNameInput) {
                projectNameInput.value = projectName;
            }

            const referrerProjectNameInput = document.getElementById('referrer-project-name');
            if (referrerProjectNameInput) {
                if (projectCategory === 'referrers') {
                    referrerProjectNameInput.value = projectName;
                }
            }

            if (typeof window.handleReferrerToggle === 'function') {
                window.handleReferrerToggle();
                debugLog('✅ Updated form visibility for', projectCategory);
            }

            if (typeof window.updateAllUI === 'function') {
                debugLog('🔄 Calling updateAllUI...');
                window.updateAllUI();
                window.updateLabelsForProjectType(projectName);
            } else {
                console.error('❌ updateAllUI not available!');
            }

            if (projectCategory === 'referrers' && typeof window.updateConsultantsUI === 'function') {
                debugLog('🔄 Updating consultants UI for referrer project...');
                window.updateConsultantsUI();
            }

            if (projectCategory === 'locations' && typeof window.updateLocationFacilitiesOverview === 'function') {
                debugLog('🔄 Updating location facilities overview for location project...');
                window.updateLocationFacilitiesOverview();
            }

            document.dispatchEvent(new CustomEvent('projectLoaded', {
                detail: { projectName: projectName }
            }));

            window.showUploadStatus(`Project "${projectName}" loaded (${window.formData.facilities.length} facilities)`, 'success');
            
            const facilityLoaderPanel = document.querySelector('.facility-loader-panel');
            if (facilityLoaderPanel) {
                facilityLoaderPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                window.scrollToFormInput();
            }
            
            resolve();
        }, 100);
    });
}

export function newProject() {
    if (!confirm('Start a new blank project? Any unsaved changes to the current project will be lost.')) return;

    let projectName = prompt('Enter a name for the new project:');

    if (projectName === null) return;

    projectName = projectName.trim();
    if (projectName === '') {
        alert('Project name cannot be empty. Please try again.');
        return;
    }

    window.currentProjectName = projectName;
    window.formData = createNewProjectData();
    window.currentFacilityIndex = 0;

    const projectNameInput = document.getElementById('project-name');
    if (projectNameInput) {
        projectNameInput.value = projectName;
    }

    const activeTab = document.querySelector('.category-tab.active');
    const activeCategory = activeTab ? activeTab.dataset.category : 'companies';

    if (typeof window.updateAllUI === 'function') {
        window.updateAllUI();
    }

    window.updateLabelsForProjectType();

    if (typeof window.handleReferrerToggle === 'function') {
        window.handleReferrerToggle();
    }

    window.showUploadStatus(`New project "${projectName}" created`, 'info');

    setTimeout(() => {
        let firstSection = null;

        if (activeCategory === 'referrers') {
            firstSection = document.getElementById('referrer-agency-section');
        } else {
            firstSection = document.getElementById('operator-section');
        }

        if (firstSection) {
            if (!firstSection.classList.contains('expanded')) {
                const sectionContent = firstSection.querySelector('.section-content');
                const sectionToggle = firstSection.querySelector('.section-toggle');

                if (sectionContent) {
                    firstSection.classList.add('expanded');
                    sectionContent.style.display = 'block';
                    if (sectionToggle) {
                        sectionToggle.setAttribute('aria-expanded', 'true');
                        sectionToggle.setAttribute('title', 'Collapse section');
                    }
                }
            }

            firstSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, 500);
}

export async function renameProject(oldName) {
    if (!oldName) {
        window.showUploadStatus('❌ No project selected to rename.', 'error');
        return;
    }

    const newName = prompt(`Enter the new name for project "${oldName}":`, oldName);

    if (!newName || newName.trim() === '' || newName.trim() === oldName) {
        window.showUploadStatus('ℹ️ Rename cancelled or name not changed.', 'info');
        return;
    }

    if (window.projects && window.projects[newName.trim()]) {
        window.showUploadStatus(`❌ A project named "${newName.trim()}" already exists.`, 'error');
        return;
    }

    try {
        window.showUploadStatus(`Renaming "${oldName}" to "${newName}"...`, 'info');
        const response = await fetch(API_ENDPOINTS.SAVE_PROJECT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'rename',
                projectName: oldName,
                newProjectName: newName.trim()
            })
        });

        const result = await response.json();

        if (result.success) {
            window.projects[newName.trim()] = window.projects[oldName];
            delete window.projects[oldName];

            if (window.currentProjectName === oldName) {
                window.currentProjectName = newName.trim();
                window.formData.projectName = newName.trim();
                document.getElementById('project-name').value = newName.trim();
            }

            window.showUploadStatus(`✅ Project renamed to "${newName.trim()}"`, 'success');
            window.updateAllUI();
        } else {
            throw new Error(result.error || 'Failed to rename project.');
        }
    } catch (error) {
        window.showUploadStatus(`❌ Rename failed: ${error.message}`, 'error');
        console.error('Rename failed:', error);
    }
}

export async function deleteProject(projectName) {
    if (!projectName) {
        window.showUploadStatus('❌ No project selected to delete.', 'error');
        return;
    }

    if (!confirm(`Are you sure you want to permanently delete the project "${projectName}"? This cannot be undone.`)) {
        return;
    }

    await saveProjectToCloud(projectName, 'delete');
}

export async function recategorizeProject(projectName) {
    if (!projectName || !window.projects || !window.projects[projectName]) {
        window.showUploadStatus('❌ Project not found to reclassify.', 'error');
        return;
    }

    const currentCategory = determineProjectCategory(projectName);
    const newCategory = prompt(`Project "${projectName}" is currently in "${currentCategory}".
Enter new category (companies, locations, or referrers):`, currentCategory);

    if (!newCategory || newCategory.trim() === '' || newCategory.trim().toLowerCase() === currentCategory) {
        window.showUploadStatus('ℹ️ Reclassification cancelled or category not changed.', 'info');
        return;
    }

    const validCategories = ['companies', 'locations', 'referrers'];
    const normalizedCategory = newCategory.trim().toLowerCase();

    if (!validCategories.includes(normalizedCategory)) {
        window.showUploadStatus(`❌ Invalid category. Please use one of: ${validCategories.join(', ')}.`, 'error');
        return;
    }

    window.projects[projectName].category = normalizedCategory;

    if (isSuggestionMode()) {
        persistProjectLocally(projectName);
        window.showUploadStatus(`✅ Project "${projectName}" reclassified to "${normalizedCategory}" in your local drafts.`, 'success');
    } else {
        const originalCurrentProjectName = window.currentProjectName;
        const originalFormData = window.formData ? window.deepClone(window.formData) : null;

        try {
            await loadProject(projectName);
            
            await saveProjectToCloud(projectName);
        } finally {
            if (originalCurrentProjectName && originalCurrentProjectName !== projectName) {
                await loadProject(originalCurrentProjectName);
            } else if (!originalCurrentProjectName) {
                newProject(false);
            }
        }
    }

    window.refreshSavedProjectPanels();
}

export function determineProjectCategory(name = '') {
    const normalized = name.toLowerCase().trim();
    
    if (window.US_STATE_SET?.has(normalized) || window.COUNTRY_SET?.has(normalized)) {
        return 'locations';
    }
    
    const project = window.projects?.[name];
    if (project && project.category) {
        return project.category;
    }

    if (normalized.includes('consultant') ||
        normalized.includes('district') ||
        normalized.includes('agency') ||
        normalized.includes('referrer') ||
        normalized.includes('education') ||
        normalized.includes('school')) {
        return 'referrers';
    }
    
    return 'companies';
}

export function getProjectStates(projectData = {}) {
    const states = new Set();

    (projectData.facilities || []).forEach((facility = {}) => {
        const parsed = window.parseCityState(facility.location || '');
        const state = (facility.locationState || parsed.state || '').trim();
        if (state) {
            states.add(state.toUpperCase());
        }
    });

    if (projectData.referrerAgency?.state) {
        states.add(String(projectData.referrerAgency.state).trim().toUpperCase());
    }

    if (projectData.referrerIndividual?.state) {
        states.add(String(projectData.referrerIndividual.state).trim().toUpperCase());
    }

    (projectData.referrerConsultants || []).forEach((consultant = {}) => {
        if (consultant.state) {
            states.add(String(consultant.state).trim().toUpperCase());
        }
    });

    if (projectData.operator?.state) {
        states.add(String(projectData.operator.state).trim().toUpperCase());
    }

    return Array.from(states).filter(Boolean);
}
