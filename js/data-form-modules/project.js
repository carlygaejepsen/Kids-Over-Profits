(function() {
    if (window.KOP_Project) {
        return; // Already loaded
    }

    // These functions depend on other modules and global state like:
    // - KOP_CONFIG, KOP_API, KOP_DataNormalizer
    // - Global state: window.projects, window.formData, window.currentProjectName, etc.
    // - Global UI functions: showUploadStatus, updateAllUI, debugLog, etc.
    // This module must be loaded AFTER all its dependencies.

    function determineProjectCategory(name = '') {
        const { projects, US_STATE_SET, COUNTRY_SET } = window;
        const normalizedName = (name || '').toLowerCase().trim();

        // Check explicit category first if project is loaded
        if (projects && projects[name] && projects[name].category) {
            return projects[name].category;
        }

        // Infer from name for locations
        if (US_STATE_SET?.has(normalizedName) || COUNTRY_SET?.has(normalizedName)) {
            return 'locations';
        }

        // Fallback for referrers if the name suggests it (this is a heuristic)
        if (normalizedName.includes('consultant') || normalizedName.includes('edcons')) {
            return 'referrers';
        }

        // Default to companies
        return 'companies';
    }
    
    function createNewProjectData() {
        const project = {
            operator: {
                name: "", currentName: "", otherNames: [],
                location: "", locationCity: "", locationState: "",
                headquarters: "", headquartersCity: "", headquartersState: "",
                founded: "", operatingPeriod: "", status: "", parentCompanies: [],
                websites: [], investors: [], keyStaff: { ceo: "", founders: [], keyExecutives: [] },
                notes: [], fieldNotes: {}
            },
            facilities: [{
                identification: { name: "", currentName: "", currentOperator: "", currentOwner: "", currentOwners: [], otherNames: [], knownReferrers: [] },
                locationDetails: { city: "", state: "", country: "", additionalLocations: [] },
                location: "", address: "", otherOperators: [],
                operatingPeriod: { startYear: null, endYear: null, status: "", yearsOfOperation: "", notes: [] },
                staff: { administrator: [], notableStaff: [], pastTTIJobs: [] },
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
                treatmentTypes: {}, philosophy: {}, criticalIncidents: {}, notes: [], fieldNotes: {}
            }],
            referrer: [],
            referrerAgency: typeof window.createDefaultReferrerGroup === 'function' ? window.createDefaultReferrerGroup() : { name: "", affiliations: [], keyPersonnel: [], notes: "", fieldNotes: {} },
            referrerConsultants: [typeof window.createDefaultReferrerIndividual === 'function' ? window.createDefaultReferrerIndividual() : { firstName: "", lastName: "", knownReferrals: [], facilitiesReferred: [], affiliations: [], pastTTIJobs: [], schoolDistricts: [], fieldNotes: {} }],
            isIndependentConsultant: false,
            fieldNotes: {}
        };

        project.referrer = typeof window.buildReferrerEntries === 'function' ? window.buildReferrerEntries(project) : [];
        return project;
    }

    function captureLoadedProjectBaseline(projectName, deepClone) {
        if (!projectName || !window.formData) {
            return;
        }

        const cloneFn = typeof deepClone === 'function'
            ? deepClone
            : (value) => JSON.parse(JSON.stringify(value || {}));

        window.__kopLoadedProjectBaselineProjectName = projectName;
        window.__kopLoadedProjectBaseline = cloneFn(window.formData);
    }

    function loadProject(projectName) {
        const { debugLog, showUploadStatus, deepClone, updateAllUI, updateLabelsForProjectType, handleReferrerToggle, updateConsultantsUI, updateLocationFacilitiesOverview, scrollToFormInput } = window;
        const { normalizeProjectData } = window.KOP_DataNormalizer;

        debugLog('🔄 loadProject called with:', projectName);
        debugLog('📦 Available projects:', Object.keys(window.projects || {}));
        showUploadStatus(`Loading project "${projectName}"...`, 'info');

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
            showUploadStatus(`Project "${projectName}" not found.`, 'error');
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

        document.querySelectorAll('.category-content').forEach(content => content.classList.add('view-hidden', 'd-none'));
        const contentId = projectCategory === 'locations' ? 'states-content' : `${projectCategory}-content`;
        const activeContent = document.getElementById(contentId);
        if (activeContent) activeContent.classList.remove('view-hidden', 'd-none');
        
        return new Promise((resolve) => {
            setTimeout(() => {
                window.currentProjectName = projectName;
                
                if (window.projects[projectName].data && Object.keys(window.projects[projectName].data).length > 0) {
                    window.formData = normalizeProjectData(deepClone(window.projects[projectName].data));
                } else {
                    window.formData = createNewProjectData();
                }
                if (typeof window.ensureReferrerDataStructures === 'function') window.ensureReferrerDataStructures();
                window.currentFacilityIndex = window.projects[projectName].currentFacilityIndex || 0;
                if (!window.formData.facilities || window.currentFacilityIndex >= window.formData.facilities.length) {
                    window.currentFacilityIndex = 0;
                }
                if (projectCategory === 'referrers') window.currentConsultantIndex = 0;

                const projectNameInput = document.getElementById('project-name');
                if (projectNameInput) projectNameInput.value = projectName;
                const referrerProjectNameInput = document.getElementById('referrer-project-name');
                if (referrerProjectNameInput && projectCategory === 'referrers') referrerProjectNameInput.value = projectName;

                if (typeof handleReferrerToggle === 'function') handleReferrerToggle();
                if (typeof updateAllUI === 'function') {
                    updateAllUI();
                    updateLabelsForProjectType(projectName);
                }
                if (projectCategory === 'referrers' && typeof updateConsultantsUI === 'function') updateConsultantsUI();
                if (projectCategory === 'locations' && typeof updateLocationFacilitiesOverview === 'function') updateLocationFacilitiesOverview();
                captureLoadedProjectBaseline(projectName, deepClone);

                document.dispatchEvent(new CustomEvent('projectLoaded', { detail: { projectName: projectName } }));
                showUploadStatus(`Project "${projectName}" loaded (${window.formData.facilities.length} facilities)`, 'success');
                
                const facilityLoaderPanel = document.querySelector('.facility-loader-panel');
                if (facilityLoaderPanel) facilityLoaderPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                else scrollToFormInput();
                
                resolve();
            }, 100);
        });
    }

    function newProject() {
        const { showUploadStatus, updateAllUI, updateLabelsForProjectType, handleReferrerToggle, deepClone } = window;
        if (!confirm('Start a new blank project? Any unsaved changes will be lost.')) return;

        let projectName = prompt('Enter a name for the new project:');
        if (projectName === null) return;
        projectName = projectName.trim();
        if (projectName === '') {
            alert('Project name cannot be empty.');
            return;
        }

        window.currentProjectName = projectName;
        window.formData = createNewProjectData();
        captureLoadedProjectBaseline(projectName, deepClone);
        window.currentFacilityIndex = 0;

        const projectNameInput = document.getElementById('project-name');
        if (projectNameInput) projectNameInput.value = projectName;

        const activeTab = document.querySelector('.category-tab.active');
        const activeCategory = activeTab ? activeTab.dataset.category : 'companies';

        if (typeof updateAllUI === 'function') updateAllUI();
        if (typeof updateLabelsForProjectType === 'function') updateLabelsForProjectType();
        if (typeof handleReferrerToggle === 'function') handleReferrerToggle();

        showUploadStatus(`New project "${projectName}" created`, 'info');

        setTimeout(() => {
            let firstSection = (activeCategory === 'referrers') ? document.getElementById('referrer-agency-section') : document.getElementById('operator-section');
            if (firstSection) {
                if (!firstSection.classList.contains('expanded')) {
                    const sectionContent = firstSection.querySelector('.section-content');
                    const sectionToggle = firstSection.querySelector('.section-toggle');
                    if (sectionContent) {
                        firstSection.classList.add('expanded');
                        sectionContent.style.display = 'block';
                        if (sectionToggle) sectionToggle.setAttribute('aria-expanded', 'true');
                    }
                }
                firstSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }, 500);
    }

    function initializeCategoryTabs() {
        const { debugLog, deepClone } = window;
        const categoryTabsContainer = document.querySelector('.category-tabs');
        if (!categoryTabsContainer || categoryTabsContainer.dataset.tabsInitialized === 'true') return;

        categoryTabsContainer.addEventListener('click', (event) => {
            const tab = event.target.closest('.category-tab');
            if (!tab) return;

            const newCategory = tab.dataset.category;
            const currentTab = document.querySelector('.category-tab.active');
            const currentCategory = currentTab ? currentTab.dataset.category : null;
            if (newCategory === currentCategory) return;

            const currentProjectCategory = window.currentProjectName ? (window.projects?.[window.currentProjectName]?.category || determineProjectCategory(window.currentProjectName)) : null;
            
            if (window.currentProjectName && currentProjectCategory && currentProjectCategory !== newCategory) {
                const hasUnsavedWork = window.formData && (window.formData.facilities?.some(f => f.identification?.name) || window.formData.operator?.name || window.formData.referrerConsultants?.length > 0);
                if (hasUnsavedWork) {
                    const choice = confirm(`You have "${window.currentProjectName}" (${currentProjectCategory}) loaded.

Switching to ${newCategory} will clear this from the form.

Click OK to continue (your data is auto-saved locally).
Click Cancel to stay on the current tab.`);
                    if (!choice) return;
                    if (typeof window.KOP_API?.persistProjectLocally === 'function') {
                        window.KOP_API.persistProjectLocally(window.currentProjectName);
                    }
                }
                window.currentProjectName = null;
                window.formData = createNewProjectData();
                window.__kopLoadedProjectBaselineProjectName = null;
                window.__kopLoadedProjectBaseline = typeof deepClone === 'function' ? deepClone(window.formData) : JSON.parse(JSON.stringify(window.formData || {}));
                window.currentFacilityIndex = 0;
                const projectNameInput = document.getElementById('project-name');
                if (projectNameInput) projectNameInput.value = '';
            }

            categoryTabsContainer.querySelectorAll('.category-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            document.querySelectorAll('.category-content').forEach(content => content.classList.add('view-hidden', 'd-none'));
            const contentId = newCategory === 'locations' ? 'states-content' : `${newCategory}-content`;
            const activeContent = document.getElementById(contentId);
            if (activeContent) activeContent.classList.remove('view-hidden', 'd-none');
            
            if (typeof window.handleReferrerToggle === 'function') window.handleReferrerToggle();
            if (typeof window.applyViewLayout === 'function') window.applyViewLayout(newCategory);
            if (typeof window.refreshSavedProjectPanels === 'function') window.refreshSavedProjectPanels();
            if (typeof window.updateLabelsForProjectType === 'function') window.updateLabelsForProjectType();
            if (typeof window.updateAllUI === 'function') window.updateAllUI();
        });
        categoryTabsContainer.dataset.tabsInitialized = 'true';
        debugLog('✅ Category tab switching logic initialized.');
    }

    async function renameProject(oldName) {
        const { showUploadStatus, updateAllUI } = window;
        const { API_ENDPOINTS } = window.KOP_FormConfig;
        if (!oldName) {
            showUploadStatus('❌ No project selected to rename.', 'error');
            return;
        }
        const newName = prompt(`Enter the new name for project "${oldName}":`, oldName);
        if (!newName || newName.trim() === '' || newName.trim() === oldName) {
            showUploadStatus('ℹ️ Rename cancelled or name not changed.', 'info');
            return;
        }
        if (window.projects && window.projects[newName.trim()]) {
            showUploadStatus(`❌ A project named "${newName.trim()}" already exists.`, 'error');
            return;
        }
        try {
            showUploadStatus(`Renaming "${oldName}" to "${newName}"...`, 'info');
            const response = await fetch(API_ENDPOINTS.SAVE_PROJECT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'rename', projectName: oldName, newProjectName: newName.trim() })
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
                showUploadStatus(`✅ Project renamed to "${newName.trim()}"`, 'success');
                updateAllUI();
            } else {
                throw new Error(result.error || 'Failed to rename project.');
            }
        } catch (error) {
            showUploadStatus(`❌ Rename failed: ${error.message}`, 'error');
            console.error('Rename failed:', error);
        }
    }

    async function deleteProject(projectName) {
        const { showUploadStatus } = window;
        const { saveProjectToCloud } = window.KOP_API;
        if (!projectName) {
            showUploadStatus('❌ No project selected to delete.', 'error');
            return;
        }
        if (!confirm(`Are you sure you want to permanently delete the project "${projectName}"? This cannot be undone.`)) return;
        await saveProjectToCloud(projectName, 'delete');
    }

    async function recategorizeProject(projectName) {
        const { showUploadStatus, isSuggestionMode, deepClone, refreshSavedProjectPanels } = window;
        const { persistProjectLocally, saveProjectToCloud } = window.KOP_API;

        if (!projectName || !window.projects || !window.projects[projectName]) {
            showUploadStatus('❌ Project not found to reclassify.', 'error');
            return;
        }
        const currentCategory = determineProjectCategory(projectName);
        const newCategory = prompt(`Project "${projectName}" is currently in "${currentCategory}".
Enter new category (companies, locations, or referrers):`, currentCategory);
        if (!newCategory || newCategory.trim().toLowerCase() === currentCategory) {
            showUploadStatus('ℹ️ Reclassification cancelled or category not changed.', 'info');
            return;
        }
        const validCategories = ['companies', 'locations', 'referrers'];
        const normalizedCategory = newCategory.trim().toLowerCase();
        if (!validCategories.includes(normalizedCategory)) {
            showUploadStatus(`❌ Invalid category. Please use one of: ${validCategories.join(', ')}.`, 'error');
            return;
        }
        window.projects[projectName].category = normalizedCategory;
        if (isSuggestionMode()) {
            persistProjectLocally(projectName);
            showUploadStatus(`✅ Project "${projectName}" reclassified to "${normalizedCategory}" in your local drafts.`, 'success');
        } else {
            const originalCurrentProjectName = window.currentProjectName;
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
        refreshSavedProjectPanels();
    }

    window.KOP_Project = {
        createNewProjectData,
        loadProject,
        newProject,
        initializeCategoryTabs,
        renameProject,
        deleteProject,
        recategorizeProject,
        determineProjectCategory
    };

    // Compatibility shims for toolbar and legacy code
    window.newProject = newProject;
    window.loadProject = loadProject;
    window.deleteProject = deleteProject;
    window.renameProject = renameProject;
})();
