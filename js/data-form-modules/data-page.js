// ============================================
// VIEW MANAGEMENT SYSTEM
// ============================================

(() => {
    const viewManagedElements = [];
    let activeViewLayout = null;

    function registerViewManagedElement(element, views, options = {}) {
        if (!element) {
            return;
        }

        const viewList = Array.isArray(views)
            ? views
            : String(views || '')
                .split(',')
                .map(view => view.trim().toLowerCase())
                .filter(Boolean);

        const hiddenClasses = Array.isArray(options.hiddenClasses) && options.hiddenClasses.length
            ? options.hiddenClasses
            : ['view-hidden'];

        if (!element.dataset.originalDisplay) {
            element.dataset.originalDisplay = element.style.display || '';
        }

        viewManagedElements.push({
            element,
            views: viewList,
            hiddenClasses,
            toggleDisplay: options.toggleDisplay !== false
        });
    }

    function applyViewLayout(viewName) {
        const normalizedView = typeof viewName === 'string' && viewName.trim()
            ? viewName.trim().toLowerCase()
            : 'companies';

        if (normalizedView === activeViewLayout && normalizedView !== 'referrers') {
            // Allow referrers to reapply in case of toggles that alter nested forms.
            return;
        }

        activeViewLayout = normalizedView;

        viewManagedElements.forEach(item => {
            const shouldShow = !item.views.length || item.views.includes(normalizedView);

            if (shouldShow) {
                if (item.toggleDisplay) {
                    item.element.style.display = item.element.dataset.originalDisplay || '';
                }
                item.hiddenClasses.forEach(cls => cls && item.element.classList.remove(cls));
            } else {
                if (item.toggleDisplay) {
                    item.element.style.display = 'none';
                }
                item.hiddenClasses.forEach(cls => cls && item.element.classList.add(cls));
            }
        });

        // Re-add note buttons to newly visible fields
        if (window.NotesModule && typeof window.NotesModule.addNoteButtons === 'function') {
            setTimeout(() => {
                window.NotesModule.addNoteButtons();
            }, 50);
        }
    }

    window.registerViewManagedElement = registerViewManagedElement;
    window.applyViewLayout = applyViewLayout;

    document.addEventListener('DOMContentLoaded', () => {
        const managedNodes = document.querySelectorAll('[data-section-views]');
        managedNodes.forEach(node => {
            const views = node.dataset.sectionViews || '';
            const hiddenClasses = ['view-hidden'];
            if (node.classList.contains('d-none')) {
                hiddenClasses.push('d-none');
            }
            registerViewManagedElement(node, views, { hiddenClasses });
        });

        const syncLayoutWithActiveTab = () => {
            const activeTab = document.querySelector('.category-tab.active');
            const rawCategory = activeTab ? activeTab.dataset.category : 'companies';
            applyViewLayout(rawCategory === 'states' ? 'locations' : rawCategory);
        };

        const tabsContainer = document.querySelector('.category-tabs');
        if (tabsContainer && !tabsContainer.dataset.viewLayoutBound) {
            tabsContainer.addEventListener('click', () => {
                setTimeout(syncLayoutWithActiveTab, 0);
            });
            tabsContainer.dataset.viewLayoutBound = 'true';
        }

        const patchHandleReferrerToggle = () => {
            if (typeof window.handleReferrerToggle === 'function' && !window.handleReferrerToggle.__viewLayoutPatched) {
                const original = window.handleReferrerToggle;
                window.handleReferrerToggle = function patchedHandleReferrerToggle(...args) {
                    const result = original.apply(this, args);
                    syncLayoutWithActiveTab();
                    return result;
                };
                window.handleReferrerToggle.__viewLayoutPatched = true;
            }
        };

        patchHandleReferrerToggle();
        document.addEventListener('formReady', patchHandleReferrerToggle, { once: true });
        setTimeout(syncLayoutWithActiveTab, 0);
    });
})();

// ============================================
// DATA PAGE FUNCTIONALITY
// Page-specific scripts for data.html (data slug - suggestions mode)
// ============================================

        // Set mode to master for direct saving
        window.FORM_MODE = 'suggestions';
        
        // Override save function for suggestions
        // Function to load project and sync with our form
        function loadProjectAndSync(projectName) {
            console.log('📂 Loading and syncing project:', projectName);

            // Check if this project is already loaded
            const currentProject = window.currentProjectName;
            if (currentProject === projectName) {
                console.log('Project already loaded:', projectName);
                if (typeof showUploadStatus === 'function') {
                    showUploadStatus(`ℹ️ Already working on "${projectName}"`, 'info');
                }
                return;
            }
            
            // Show loading status
            if (typeof showUploadStatus === 'function') {
                showUploadStatus(`🔄 Switching from "${currentProject || 'none'}" to "${projectName}"...`, 'info');
            }
            
            // Don't call newProject() - it shows a confirmation dialog
            // loadProject handles clearing and loading in one step
            
            // Use the existing project manager to load the project
            if (window.projectManager && window.projectManager.loadProject) {
                console.log('📥 Loading project:', projectName);
                window.projectManager.loadProject(projectName);
                
                // Wait a moment for the load to complete, then sync our formData
                setTimeout(() => {
                    // Make sure we have access to the global formData
                    if (typeof formData !== 'undefined') {
                        window.globalFormData = formData;
                        
                        // Extensive debugging
                        console.log('Project loaded and synced:', projectName);
                        console.log('📊 Current project data:', formData);
                        console.log('🏢 Project facilities:', formData?.facilities?.length || 0);
                        console.log('📝 Current project name from variables:', {
                            'window.currentProjectName': window.currentProjectName,
                            'formData.projectName': formData?.projectName,
                            'requested': projectName
                        });

                        // Verify we loaded the right project
                        const actualCurrentProject = window.currentProjectName;
                        if (actualCurrentProject !== projectName) {
                            console.warn(`⚠️ PROJECT MISMATCH! Requested: "${projectName}", Actually loaded: "${actualCurrentProject}"`);
                            if (typeof showUploadStatus === 'function') {
                                showUploadStatus(`⚠️ Warning: Loaded "${actualCurrentProject}" instead of "${projectName}"`, 'error');
                            }
                        } else {
                            // Show additional success status
                            if (typeof showUploadStatus === 'function') {
                                const facilityCount = formData?.facilities?.length || 0;
                                showUploadStatus(`✅ Now working on "${actualCurrentProject}" (${facilityCount} facilities)`, 'success');
                            }
                        }
                        
                        // Also update the page title or some indicator
                        const pageTitle = document.querySelector('h1');
                        if (pageTitle && projectName !== 'New Project') {
                            const actualProject = window.currentProjectName || projectName;
                            pageTitle.innerHTML = `Admin - ${actualProject}`;
                        }

                        if (typeof window.updateToolbarFacilityInfo === 'function') {
                            console.log('🔔 Calling updateToolbarFacilityInfo from loadProjectAndSync');
                            window.updateToolbarFacilityInfo();
                            setTimeout(window.updateToolbarFacilityInfo, 100);
                            setTimeout(window.updateToolbarFacilityInfo, 300);
                            setTimeout(window.updateToolbarFacilityInfo, 500);
                            setTimeout(window.updateToolbarFacilityInfo, 1000);
                        }
                    } else {
                        console.warn('formData not found after project load');
                        if (typeof showUploadStatus === 'function') {
                            showUploadStatus('⚠️ Project loaded but data sync failed', 'error');
                        }
                    }
                }, 300);
            } else {
                console.error('Project manager not available');
                if (typeof showUploadStatus === 'function') {
                    showUploadStatus('❌ Project manager not available', 'error');
                }
            }
        }

        // Independent consultant toggle functionality
        let suggestionSubmissionInProgress = false;

        function hasMeaningfulFacilityValue(value) {
            if (typeof value === 'string') {
                return value.trim() !== '';
            }

            if (typeof value === 'number') {
                return !Number.isNaN(value);
            }

            if (typeof value === 'boolean') {
                return value === true;
            }

            if (Array.isArray(value)) {
                return value.some(item => hasMeaningfulFacilityValue(item));
            }

            if (value && typeof value === 'object') {
                return Object.values(value).some(item => hasMeaningfulFacilityValue(item));
            }

            return false;
        }

        function facilityHasMeaningfulSubmissionData(facility) {
            if (!facility || typeof facility !== 'object') {
                return false;
            }

            return hasMeaningfulFacilityValue([
                facility.identification?.currentName,
                facility.identification?.currentOperator,
                facility.identification?.currentOwner,
                facility.identification?.currentOwners,
                facility.identification?.otherNames,
                facility.identification?.pastNames,
                facility.identification?.knownReferrers,
                facility.location,
                facility.address,
                facility.locationDetails?.city,
                facility.locationDetails?.state,
                facility.locationDetails?.country,
                facility.locationDetails?.additionalLocations,
                facility.otherOperators,
                facility.operatingPeriod,
                facility.staff,
                facility.profileLinks,
                facility.facilityDetails,
                facility.accreditations,
                facility.memberships,
                facility.certifications,
                facility.licensing,
                facility.resources,
                facility.treatmentTypes,
                facility.philosophy,
                facility.criticalIncidents,
                facility.notes,
                facility.fieldNotes,
                facility.isPrivatelyOwned
            ]);
        }

        function getIncompleteUnnamedFacilities(facilities) {
            if (!Array.isArray(facilities)) {
                return [];
            }

            return facilities.reduce((issues, facility, index) => {
                const facilityName = typeof facility?.identification?.name === 'string'
                    ? facility.identification.name.trim()
                    : '';

                if (facilityName || !facilityHasMeaningfulSubmissionData(facility)) {
                    return issues;
                }

                const summaryParts = [
                    facility?.locationDetails?.city,
                    facility?.locationDetails?.state,
                    facility?.facilityDetails?.type,
                    facility?.identification?.currentName
                ].filter(part => typeof part === 'string' && part.trim() !== '');

                issues.push({
                    index,
                    summary: summaryParts.join(', ')
                });
                return issues;
            }, []);
        }

        function normalizeSuggestionCategory(category) {
            const normalized = String(category || '').trim().toLowerCase();
            if (normalized === 'states') {
                return 'locations';
            }
            return normalized || 'companies';
        }

        function stripReferrerSubmissionData(data) {
            if (!data || typeof data !== 'object') {
                return;
            }

            delete data.referrer;
            delete data.referrerAgency;
            delete data.referrerConsultants;
            delete data.referrerGroup;
            delete data.referrerIndividual;
            delete data.isIndependentConsultant;
            delete data.referrerType;
        }

        function cloneSubmissionValue(value) {
            if (typeof value === 'undefined') {
                return undefined;
            }

            if (typeof window.deepClone === 'function') {
                return window.deepClone(value);
            }

            return JSON.parse(JSON.stringify(value));
        }

        function getDefaultFacilityTemplate() {
            const projectTemplate = window.KOP_Project?.createNewProjectData?.()
                || (typeof window.createNewProjectData === 'function' ? window.createNewProjectData() : null)
                || { facilities: [{}] };

            if (Array.isArray(projectTemplate.facilities) && projectTemplate.facilities[0]) {
                return cloneSubmissionValue(projectTemplate.facilities[0]);
            }

            return {};
        }

        function mergeWithTemplate(template, value) {
            if (Array.isArray(template)) {
                return Array.isArray(value) ? cloneSubmissionValue(value) : cloneSubmissionValue(template);
            }

            if (template && typeof template === 'object') {
                const result = cloneSubmissionValue(template);
                const source = value && typeof value === 'object' ? value : {};

                Object.keys(source).forEach((key) => {
                    result[key] = mergeWithTemplate(template[key], source[key]);
                });

                return result;
            }

            return value === undefined ? cloneSubmissionValue(template) : cloneSubmissionValue(value);
        }

        function getFacilityComparisonKey(facility) {
            return typeof facility?.identification?.name === 'string'
                ? facility.identification.name.trim().toLowerCase()
                : '';
        }

        function getCanonicalFacilityForComparison(facility) {
            return mergeWithTemplate(getDefaultFacilityTemplate(), facility || {});
        }

        function facilitiesMatchForSubmission(currentFacility, baselineFacility) {
            return JSON.stringify(getCanonicalFacilityForComparison(currentFacility)) === JSON.stringify(getCanonicalFacilityForComparison(baselineFacility));
        }

        function getLoadedProjectBaseline() {
            const baselineMatchesCurrentProject = (
                !!window.__kopLoadedProjectBaseline &&
                !!window.currentProjectName &&
                window.__kopLoadedProjectBaselineProjectName === window.currentProjectName
            );

            if (baselineMatchesCurrentProject) {
                return cloneSubmissionValue(window.__kopLoadedProjectBaseline);
            }

            const currentProjectData = window.currentProjectName
                ? window.projects?.[window.currentProjectName]?.data
                : null;

            if (
                currentProjectData &&
                typeof window.KOP_DataNormalizer?.normalizeProjectData === 'function'
            ) {
                return cloneSubmissionValue(
                    window.KOP_DataNormalizer.normalizeProjectData(
                        cloneSubmissionValue(currentProjectData)
                    )
                );
            }

            return null;
        }

        function getChangedLocationFacilities(currentFacilities, baselineFacilities) {
            if (!Array.isArray(currentFacilities)) {
                return [];
            }

            const baselineByName = new Map();
            if (Array.isArray(baselineFacilities)) {
                baselineFacilities.forEach((facility) => {
                    const key = getFacilityComparisonKey(facility);
                    if (key) {
                        baselineByName.set(key, facility);
                    }
                });
            }

            return currentFacilities.reduce((changedFacilities, facility) => {
                const facilityName = typeof facility?.identification?.name === 'string'
                    ? facility.identification.name.trim()
                    : '';

                if (!facilityName) {
                    return changedFacilities;
                }

                const baselineFacility = baselineByName.get(facilityName.toLowerCase());
                if (!baselineFacility || !facilitiesMatchForSubmission(facility, baselineFacility)) {
                    changedFacilities.push(cloneSubmissionValue(facility));
                }

                return changedFacilities;
            }, []);
        }

        function buildLocationSuggestionPayload(currentData) {
            const baseline = getLoadedProjectBaseline();
            const baselineFacilities = Array.isArray(baseline?.facilities) ? baseline.facilities : [];
            const changedFacilities = getChangedLocationFacilities(currentData?.facilities, baselineFacilities);

            return {
                facilities: changedFacilities
            };
        }

        function ensureSuggestionButtonBinding() {
            const toolbarButton = document.getElementById('submit-suggestion-btn-toolbar');
            if (toolbarButton && !toolbarButton.dataset.suggestionBound) {
                toolbarButton.addEventListener('click', (event) => {
                    event.preventDefault();
                    submitSuggestion();
                });
                toolbarButton.dataset.suggestionBound = 'true';
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', ensureSuggestionButtonBinding, { once: true });
        } else {
            ensureSuggestionButtonBinding();
        }

        document.addEventListener('formReady', ensureSuggestionButtonBinding, { once: true });

        async function performSuggestionSubmission(changesSummary) {
            const trimmedSummary = (changesSummary || '').trim();

            if (!trimmedSummary) {
                showSuggestionStatus('Please provide a summary of your changes to continue.', 'error');
                return false;
            }

            if (suggestionSubmissionInProgress) {
                console.warn('Suggestion submission already in progress; ignoring duplicate request.');
                return false;
            }

            const currentFormData = window.formData;

            if (!currentFormData) {
                console.error('❌ No formData found for submission', {
                    formData: window.formData,
                    projects: window.projects ? Object.keys(window.projects) : 'undefined',
                    currentProjectName: window.currentProjectName
                });

                if (window.projects && Object.keys(window.projects).length > 0) {
                    showSuggestionStatus('❌ Error: No project loaded. Please load a project from the list or create a new project before submitting.', 'error');
                } else {
                    showSuggestionStatus('❌ Error: The form data is still initializing. Please wait a moment and try again.', 'error');
                }

                return false;
            }

            suggestionSubmissionInProgress = true;
            showSuggestionStatus('📤 Preparing your suggestion for submission...', 'info');

            let dataToSubmit;

            try {
                dataToSubmit = JSON.parse(JSON.stringify(currentFormData));
            } catch (error) {
                console.error('❌ Failed to clone form data for submission', error);
                showSuggestionStatus('❌ Error: Unable to prepare your data for submission. Please try again.', 'error');
                suggestionSubmissionInProgress = false;
                return false;
            }

            const privateToggle = document.getElementById('private-ownership-toggle');
            if (privateToggle && privateToggle.checked) {
                console.log('🔒 Private facility mode detected - clearing operator data before submission');

                if (dataToSubmit.operator) {
                    dataToSubmit.operator = {
                        name: '',
                        currentName: '',
                        pastNames: [],
                        otherNames: [],
                        foundingDate: '',
                        keyPersonnel: [],
                        headquarters: '',
                        website: ''
                    };
                }

                if (Array.isArray(dataToSubmit.facilities)) {
                    dataToSubmit.facilities.forEach(facility => {
                        if (facility && facility.operator) {
                            facility.operator = {
                                name: '',
                                currentName: '',
                                pastNames: [],
                                otherNames: [],
                                foundingDate: '',
                                keyPersonnel: [],
                                headquarters: '',
                                website: ''
                            };
                        }
                    });
                }

                console.log('🧹 Operator data cleared for private facility submission');
            }

            const projectNameInput = document.getElementById('project-name');
            const activeTab = document.querySelector('.category-tab.active');
            const activeCategory = normalizeSuggestionCategory(activeTab ? activeTab.dataset.category : 'companies');

            if (activeCategory !== 'referrers') {
                stripReferrerSubmissionData(dataToSubmit);
            }

            const actualProjectName = (
                window.currentProjectName ||
                dataToSubmit.projectName ||
                dataToSubmit.name ||
                (projectNameInput ? projectNameInput.value.trim() : '') ||
                'Unknown Project'
            );

            dataToSubmit.projectName = actualProjectName;
            dataToSubmit.name = actualProjectName;

            const validationFacilities = Array.isArray(dataToSubmit.facilities) ? dataToSubmit.facilities : [];
            const incompleteUnnamedFacilities = getIncompleteUnnamedFacilities(validationFacilities);
            if (incompleteUnnamedFacilities.length > 0) {
                const facilityList = incompleteUnnamedFacilities
                    .map(({ index, summary }) => summary ? `#${index + 1} (${summary})` : `#${index + 1}`)
                    .join(', ');

                showSuggestionStatus(
                    `Please add a facility name for ${facilityList} before submitting, or clear the partial entry.`,
                    'error'
                );
                suggestionSubmissionInProgress = false;
                return false;
            }

            if (activeCategory === 'locations') {
                dataToSubmit = buildLocationSuggestionPayload(dataToSubmit);
            }

            const facilityCount = Array.isArray(dataToSubmit.facilities) ? dataToSubmit.facilities.length : 0;

            if (activeCategory === 'locations' && facilityCount === 0) {
                showSuggestionStatus('No facility changes were detected to submit for this location project.', 'error');
                suggestionSubmissionInProgress = false;
                return false;
            }

            console.log('📤 Submitting changes for project:', actualProjectName, {
                facilityCount,
                facilities: dataToSubmit.facilities?.map(f => f?.identification?.name || 'Unnamed Facility') || []
            });

            try {
                const response = await fetch(window.KOP_FormConfig.API_ENDPOINTS.SAVE_SUGGESTION, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        data: dataToSubmit,
                        reason: trimmedSummary,
                        projectName: actualProjectName,
                        metadata: {
                            actualProjectName,
                            submittedFrom: 'data.html suggestions form',
                            timestamp: new Date().toISOString(),
                            facilityCount,
                            activeCategory
                        }
                    })
                });

                let result = null;
                try {
                    result = await response.json();
                } catch (parseError) {
                    console.error('❌ Failed to parse suggestion submission response', parseError);
                }

                if (!response.ok || !result || !result.success) {
                    const errorMessage = result?.error || response.statusText || 'Failed to submit suggestion';
                    showSuggestionStatus(`❌ Error: ${errorMessage}`, 'error');
                    return false;
                }

                showSuggestionStatus(`☑️ Suggestion submitted successfully for "${actualProjectName}"! It will be reviewed before being added to the database.`, 'success');

                document.dispatchEvent(new CustomEvent('suggestionSubmitted', {
                    detail: {
                        projectName: actualProjectName,
                        summary: trimmedSummary,
                        response: result
                    }
                }));

                if (window.currentProjectName === actualProjectName) {
                    window.__kopLoadedProjectBaselineProjectName = actualProjectName;
                    window.__kopLoadedProjectBaseline = cloneSubmissionValue(currentFormData);
                }

                return true;
            } catch (error) {
                console.error('❌ Error while submitting suggestion', error);
                showSuggestionStatus(`❌ Error: ${error.message}`, 'error');
                return false;
            } finally {
                suggestionSubmissionInProgress = false;
            }
        }

        function submitSuggestion() {
            const modal = document.getElementById('suggestion-reason-modal');
            const summaryInput = document.getElementById('suggestion-summary');
            const errorMessage = document.getElementById('suggestion-error');
            const cancelButton = document.getElementById('suggestion-modal-cancel');
            const confirmButton = document.getElementById('suggestion-modal-confirm');
            const closeButton = document.getElementById('suggestion-modal-close');

            if (!modal || !summaryInput || !cancelButton || !confirmButton) {
                console.warn('Suggestion modal not available; falling back to custom prompt.');
                customPrompt(
                    'Please summarize the key changes you made:\n\n' +
                    '(Examples: "Added missing facility location", "Corrected operator founding date", "Added 3 new staff members", "Updated facility closure information", etc.)',
                    '',
                    'Summarize Your Changes'
                ).then(fallbackSummary => {
                    if (!fallbackSummary || !fallbackSummary.trim()) {
                        showSuggestionStatus('Please provide a summary of your changes to continue.', 'error');
                        return;
                    }

                    performSuggestionSubmission(fallbackSummary);
                });
                return;
            }

            const defaultConfirmText = confirmButton.dataset.originalText || confirmButton.textContent;
            confirmButton.dataset.originalText = defaultConfirmText;

            if (errorMessage) {
                errorMessage.style.display = 'none';
            }

            modal.classList.add('active');

            function cleanup() {
                confirmButton.removeEventListener('click', handleConfirm);
                cancelButton.removeEventListener('click', handleCancel);
                if (closeButton) {
                    closeButton.removeEventListener('click', handleClose);
                }
                modal.removeEventListener('keydown', handleKeydown);
            }

            function hideModal() {
                modal.classList.remove('active');
                cleanup();
            }

            async function handleConfirm(event) {
                event.preventDefault();

                if (errorMessage) {
                    errorMessage.style.display = 'none';
                }

                const summary = summaryInput.value.trim();
                if (!summary) {
                    if (errorMessage) {
                        errorMessage.style.display = 'block';
                    }
                    summaryInput.focus();
                    return;
                }

                confirmButton.disabled = true;
                confirmButton.textContent = 'Submitting...';

                const success = await performSuggestionSubmission(summary);

                confirmButton.disabled = false;
                confirmButton.textContent = defaultConfirmText;

                if (success) {
                    summaryInput.value = '';
                    hideModal();
                } else if (errorMessage) {
                    errorMessage.style.display = 'block';
                    summaryInput.focus();
                }
            }

            function handleCancel(event) {
                event.preventDefault();
                hideModal();
            }

            function handleClose(event) {
                event.preventDefault();
                hideModal();
            }

            function handleKeydown(event) {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    hideModal();
                } else if ((event.key === 'Enter' || event.key === 'NumpadEnter') && (event.metaKey || event.ctrlKey)) {
                    event.preventDefault();
                    handleConfirm(event);
                }
            }

            confirmButton.addEventListener('click', handleConfirm);
            cancelButton.addEventListener('click', handleCancel);
            if (closeButton) {
                closeButton.addEventListener('click', handleClose);
            }
            modal.addEventListener('keydown', handleKeydown);

            setTimeout(() => summaryInput.focus(), 50);
        }

        window.submitSuggestion = submitSuggestion;
        window.performSuggestionSubmission = performSuggestionSubmission;

        // Save Draft Locally button handler
        const saveDraftBtn = document.getElementById('save-draft-locally-btn');
        if (saveDraftBtn) {
            saveDraftBtn.addEventListener('click', () => {
                // Get project name from input field or current project
                const projectNameInput = document.getElementById('project-name');
                let projectName = projectNameInput ? projectNameInput.value.trim() : '';

                // Function to save the draft once we have the project name
                const saveDraft = (finalProjectName) => {
                    if (typeof window.persistProjectLocally === 'function') {
                        const result = window.persistProjectLocally(finalProjectName, {
                            showNotification: true,
                            statusMessage: `💾 Draft "${finalProjectName}" saved locally! Your work is stored in this browser.`
                        });
                        if (result) {
                            window.currentProjectName = finalProjectName;
                            console.log('✅ Draft saved locally for:', finalProjectName);

                            // Refresh the project lists if available
                            if (typeof window.refreshSavedProjectPanels === 'function') {
                                window.refreshSavedProjectPanels();
                            }
                        }
                    } else {
                        console.error('❌ persistProjectLocally function not available');
                        if (typeof showUploadStatus === 'function') {
                            showUploadStatus('❌ Unable to save draft - function not available', 'error');
                        }
                    }
                };

                // If no project name entered, use current project name or prompt
                if (!projectName) {
                    if (window.currentProjectName) {
                        projectName = window.currentProjectName;
                        saveDraft(projectName);
                    } else {
                        customPrompt('Enter a name for this draft:', '', 'Draft Name').then(enteredName => {
                            if (!enteredName || !enteredName.trim()) {
                                if (typeof showUploadStatus === 'function') {
                                    showUploadStatus('❌ Draft name is required', 'error');
                                }
                                return;
                            }
                            projectName = enteredName.trim();
                            // Update the input field with the name
                            if (projectNameInput) {
                                projectNameInput.value = projectName;
                            }
                            saveDraft(projectName);
                        });
                    }
                } else {
                    saveDraft(projectName);
                }
            });
        }

        const independentToggle = document.getElementById('referrer-independent-toggle');
        const independentStatus = document.getElementById('referrer-independent-status');
        const independentEditBtn = document.getElementById('referrer-independent-edit-btn');
        const consultantModal = document.getElementById('consultant-modal');
        const agencySection = document.getElementById('referrer-agency-section');

        function isReferrersViewActive() {
            const activeTab = document.querySelector('.category-tab.active');
            const activeCategory = activeTab ? activeTab.dataset.category : 'companies';

            if (activeCategory === 'referrers') {
                return true;
            }

            const referrerWrapper = document.getElementById('referrer-main-wrapper');
            return referrerWrapper ? !referrerWrapper.classList.contains('view-hidden') && !referrerWrapper.classList.contains('d-none') : false;
        }

        function updateAgencySliderAppearance() {
            if (!independentToggle) return;
            if (!isReferrersViewActive()) return;

            // Sync toggle state from formData if available
            if (window.formData && typeof window.formData.isIndependentConsultant === 'boolean') {
                independentToggle.checked = window.formData.isIndependentConsultant;
            }

            const isIndependent = !!independentToggle.checked;
            if (independentStatus) {
                independentStatus.textContent = isIndependent ? 'Independent consultant' : 'Agency/Group consultant';
            }
            if (agencySection) agencySection.style.display = isIndependent ? 'none' : 'block';
        }

        /**
         * Apply referrer toggle state - called by referrer-form.js
         * @param {boolean} isIndependent - true for independent consultant, false for agency
         */
        function applyReferrerToggleState(isIndependent) {
            if (!independentToggle) return;

            // Update toggle UI
            independentToggle.checked = !!isIndependent;

            // Save to formData
            if (window.formData) {
                window.formData.isIndependentConsultant = !!isIndependent;
                window.formData.referrerType = isIndependent ? 'individual' : 'group';
            }

            // Update UI appearance
            updateAgencySliderAppearance();
        }

        window.applyReferrerToggleState = applyReferrerToggleState;

        if (independentToggle && !independentToggle.dataset.listenerAttached) {
            independentToggle.addEventListener('change', () => {
                // Save to formData when toggle changes
                if (window.formData) {
                    window.formData.isIndependentConsultant = !!independentToggle.checked;
                    window.formData.referrerType = independentToggle.checked ? 'individual' : 'group';
                }
                updateAgencySliderAppearance();
                // Trigger autosave
                if (typeof autoSave === 'function') autoSave();
                if (typeof updateJSON === 'function') updateJSON();
            }, { passive: true });
            independentToggle.dataset.listenerAttached = 'true';
        }

        if (independentEditBtn && independentToggle && !independentEditBtn.dataset.listenerAttached) {
            independentEditBtn.addEventListener('click', () => {
                if (!consultantModal) {
                    customConfirm(
                        'Is this an independent consultant or part of an agency/group?',
                        'Consultant Type',
                        { yesText: 'Independent Consultant', noText: 'Part of Agency/Group' }
                    ).then(choice => {
                        independentToggle.checked = choice;
                        independentToggle.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                    return;
                }
                consultantModal.style.display = 'flex';
                consultantModal.setAttribute('aria-hidden', 'false');
            });
            independentEditBtn.dataset.listenerAttached = 'true';
        }

        if (consultantModal && !consultantModal.dataset.bound) {
            const yesBtn = consultantModal.querySelector('[data-action="consultant-yes"]');
            const noBtn = consultantModal.querySelector('[data-action="consultant-no"]');
            const closeBtn = consultantModal.querySelector('[data-action="consultant-close"]');
            const hideModal = () => {
                consultantModal.style.display = 'none';
                consultantModal.setAttribute('aria-hidden', 'true');
            };

            const applyChoice = (isIndependent) => {
                if (!independentToggle) return;
                independentToggle.checked = isIndependent;
                independentToggle.dispatchEvent(new Event('change', { bubbles: true }));
                hideModal();
            };

            if (yesBtn) yesBtn.addEventListener('click', () => applyChoice(true), { passive: true });
            if (noBtn) noBtn.addEventListener('click', () => applyChoice(false), { passive: true });
            if (closeBtn) closeBtn.addEventListener('click', hideModal, { passive: true });
            consultantModal.addEventListener('click', (e) => {
                if (e.target === consultantModal) hideModal();
            });

            consultantModal.dataset.bound = 'true';
        }
        window.updateAgencySliderAppearance = updateAgencySliderAppearance;

        // ============================================
        // INTERNATIONAL PROGRAM TOGGLE
        // ============================================
        const internationalToggle = document.getElementById('international-program-toggle');
        const internationalSliderTrack = document.getElementById('international-slider-track');
        const internationalSliderKnob = document.getElementById('international-slider-knob');
        const stateFieldGroup = document.getElementById('state-field-group');
        const countryFieldGroup = document.getElementById('country-field-group');

        function updateInternationalSliderAppearance() {
            if (!internationalSliderTrack || !internationalSliderKnob || !internationalToggle) return;

            if (internationalToggle.checked) {
                // International program - toggle is ON, show Country field
                internationalSliderTrack.style.backgroundColor = '#33A7B5'; // Teal
                internationalSliderKnob.style.transform = 'translateX(24px)';
                if (stateFieldGroup) stateFieldGroup.style.display = 'none';
                if (countryFieldGroup) countryFieldGroup.style.display = 'block';
            } else {
                // US program - toggle is OFF, show State field
                internationalSliderTrack.style.backgroundColor = '#e5e7eb';
                internationalSliderKnob.style.transform = 'translateX(0px)';
                if (stateFieldGroup) stateFieldGroup.style.display = 'block';
                if (countryFieldGroup) countryFieldGroup.style.display = 'none';
            }

            // Sync with formData if available
            if (window.formData) {
                window.formData.isInternational = internationalToggle.checked;
            }
        }

        function applyInternationalToggleState(isInternational) {
            if (!internationalToggle) return;
            internationalToggle.checked = !!isInternational;
            updateInternationalSliderAppearance();
        }

        if (internationalSliderTrack && internationalToggle) {
            internationalSliderTrack.addEventListener('click', function() {
                internationalToggle.checked = !internationalToggle.checked;
                updateInternationalSliderAppearance();
                // Trigger change event for form tracking
                internationalToggle.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }

        window.updateInternationalSliderAppearance = updateInternationalSliderAppearance;
        window.applyInternationalToggleState = applyInternationalToggleState;

        // Initialize on form ready
        document.addEventListener('formReady', () => {
            if (window.formData && typeof window.formData.isInternational !== 'undefined') {
                applyInternationalToggleState(window.formData.isInternational);
            }
        }, { once: true });

                // ============================================
        // PRIVATE OWNERSHIP (popup-controlled)
        // ============================================
        const privateOwnershipToggle = document.getElementById('private-ownership-toggle');
        const privateOwnershipStatus = document.getElementById('private-ownership-status');
        const privateOwnershipEditBtn = document.getElementById('private-ownership-edit-btn');
        const operatorSection = document.getElementById('operator-section');
        const ownershipModal = document.getElementById('ownership-modal');

        function updatePrivateOwnershipSliderAppearance() {
            if (!privateOwnershipToggle) return;

            // Restore toggle state from facility data
            const currentFacility = window.formData?.facilities?.[window.currentFacilityIndex];
            if (currentFacility && typeof currentFacility.isPrivatelyOwned === 'boolean') {
                privateOwnershipToggle.checked = currentFacility.isPrivatelyOwned;
            }

            const isPrivate = !!privateOwnershipToggle.checked;

            if (privateOwnershipStatus) {
                privateOwnershipStatus.textContent = isPrivate ? 'Privately owned' : 'Part of a chain/corporate';
            }

            if (operatorSection) operatorSection.style.display = isPrivate ? 'none' : 'block';
            applyPrivateOwnershipFormState(isPrivate);
        }

        function clearOperatorFields() {
            console.log('Clearing operator fields for private facility mode');

            // Clear operator section fields
            const operatorInputs = document.querySelectorAll('#operator-section input, #operator-section textarea');
            operatorInputs.forEach(input => {
                input.value = '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });

            // Also clear operator data from formData if available
            if (window.formData && window.formData.operator) {
                window.formData.operator = {
                    name: '',
                    currentName: '',
                    pastNames: [],
                    otherNames: [],
                    foundingDate: '',
                    keyPersonnel: [],
                    headquarters: '',
                    website: ''
                };
                console.log('Cleared operator data from formData');
            }
        }

        function applyPrivateOwnershipFormState(isPrivate) {
            const operationsSection = document.getElementById('operations-section');

            const currentOperatorGroup = document.getElementById('current-operator-group');
            const currentOwnerGroup = document.getElementById('current-owner-group');
            const pastOwnersGroup = document.getElementById('past-owners-group');

            if (isPrivate) {
                if (currentOperatorGroup) currentOperatorGroup.style.display = 'none';
                if (currentOwnerGroup) currentOwnerGroup.style.display = 'block';
                if (pastOwnersGroup) pastOwnersGroup.style.display = 'block';
            } else {
                if (currentOperatorGroup) currentOperatorGroup.style.display = 'block';
                if (currentOwnerGroup) currentOwnerGroup.style.display = 'none';
                if (pastOwnersGroup) pastOwnersGroup.style.display = 'none';
            }

            if (!operationsSection) return;

            const otherOperatorsContainer = operationsSection.querySelector('.array-container[data-path="otherOperators"]');
            const otherOperatorsGroup = otherOperatorsContainer ? otherOperatorsContainer.closest('.form-group') : null;
            if (otherOperatorsGroup) {
                otherOperatorsGroup.style.display = isPrivate ? 'none' : 'block';
            }

            const legacyOwnersGroup = document.getElementById('owners-group');
            const legacyOtherOwnersGroup = document.getElementById('other-owners-group');
            if (legacyOwnersGroup) legacyOwnersGroup.style.display = 'none';
            if (legacyOtherOwnersGroup) legacyOtherOwnersGroup.style.display = 'none';
        }

        if (privateOwnershipToggle && !privateOwnershipToggle.dataset.listenerAttached) {
            privateOwnershipToggle.addEventListener('change', function() {
                const isPrivate = !!privateOwnershipToggle.checked;

                if (window.formData && window.formData.facilities && window.formData.facilities[window.currentFacilityIndex]) {
                    window.formData.facilities[window.currentFacilityIndex].isPrivatelyOwned = isPrivate;
                    console.log(`Set isPrivatelyOwned = ${isPrivate} for facility ${window.currentFacilityIndex}`);
                }

                updatePrivateOwnershipSliderAppearance();

                if (isPrivate) {
                    clearOperatorFields();
                }

                if (typeof autoSave === 'function') {
                    autoSave();
                }
            }, { passive: true });
            privateOwnershipToggle.dataset.listenerAttached = 'true';
        }

        if (privateOwnershipEditBtn && privateOwnershipToggle && !privateOwnershipEditBtn.dataset.listenerAttached) {
            privateOwnershipEditBtn.addEventListener('click', () => {
                if (!ownershipModal) {
                    const choice = confirm('Is this a privately owned facility (not part of a chain)?\n\nOK = Privately owned\nCancel = Part of a chain/corporate');
                    privateOwnershipToggle.checked = choice;
                    privateOwnershipToggle.dispatchEvent(new Event('change', { bubbles: true }));
                    return;
                }
                ownershipModal.style.display = 'flex';
                ownershipModal.setAttribute('aria-hidden', 'false');
            });
            privateOwnershipEditBtn.dataset.listenerAttached = 'true';
        }

        if (ownershipModal && !ownershipModal.dataset.bound) {
            const yesBtn = ownershipModal.querySelector('[data-action="ownership-yes"]');
            const noBtn = ownershipModal.querySelector('[data-action="ownership-no"]');
            const closeBtn = ownershipModal.querySelector('[data-action="ownership-close"]');
            const hideModal = () => {
                ownershipModal.style.display = 'none';
                ownershipModal.setAttribute('aria-hidden', 'true');
            };

            const applyChoice = (isPrivate) => {
                if (!privateOwnershipToggle) return;
                privateOwnershipToggle.checked = isPrivate;
                privateOwnershipToggle.dispatchEvent(new Event('change', { bubbles: true }));
                hideModal();
            };

            if (yesBtn) yesBtn.addEventListener('click', () => applyChoice(true), { passive: true });
            if (noBtn) noBtn.addEventListener('click', () => applyChoice(false), { passive: true });
            if (closeBtn) closeBtn.addEventListener('click', hideModal, { passive: true });
            ownershipModal.addEventListener('click', (e) => {
                if (e.target === ownershipModal) hideModal();
            });

            ownershipModal.dataset.bound = 'true';
        }

window.updatePrivateOwnershipSliderAppearance = updatePrivateOwnershipSliderAppearance;

        function showSuggestionStatus(message, type) {
            // Show in the inline status div (backward compatibility)
            const statusDiv = document.getElementById('suggestion-status');
            if (statusDiv) {
                statusDiv.className = `upload-status ${type}`;
                statusDiv.textContent = message;
                statusDiv.style.display = 'block';
                
                // Auto-hide success messages after 5 seconds
                if (type === 'success') {
                    setTimeout(() => {
                        statusDiv.style.display = 'none';
                    }, 5000);
                }
            }
            
            // Also show as a toast for better visibility
            if (typeof window.showToast === 'function') {
                // Map 'info' to appropriate duration - keep info/error visible longer
                const duration = type === 'info' ? 3000 : (type === 'error' ? 8000 : 5000);
                window.showToast(message, type, duration);
            }
        }
        
        // Generate Report function (fallback if facility-report-generator.js doesn't provide one)
        if (!window.generateReport) {
            window.generateReport = function() {
                // Get form data from the JSON display element
                const jsonDisplay = document.getElementById('json-display');
                let reportData = null;
                
                try {
                    if (jsonDisplay && jsonDisplay.textContent) {
                        reportData = JSON.parse(jsonDisplay.textContent);
                    }
                } catch (e) {
                    console.error('Failed to parse form data:', e);
                }
                
                // Check if there's form data
                if (!reportData || !reportData.facilities || reportData.facilities.length === 0) {
                    console.log('No facility data to generate report. Please add facility information first.');
                    return;
                }
                
                // If facility-report-generator.js is loaded and has a report function, use it
                if (typeof window.FacilityReportGenerator !== 'undefined') {
                    const generator = new window.FacilityReportGenerator(reportData);
                    generator.generateReport();
                } else {
                    // Fallback: create comprehensive formatted report
                    const reportWindow = window.open('', '_blank');
                    
                    // Helper functions
                    const escapeHtml = (text) => String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                    
                    const makeLink = (url) => {
                        const escaped = escapeHtml(url);
                        return '<a href="' + escaped + '" target="_blank" style="color: #1e40af; text-decoration: none; border-bottom: 1px solid #1e40af;">' + escaped + '</a>';
                    };
                    
                    const hasValue = (value) => {
                        if (value === null || value === undefined || value === '') return false;
                        if (typeof value === 'string' && value.trim() === '') return false;
                        return true;
                    };
                    
                    const renderValue = (value) => {
                        if (typeof value === 'string' && (value.startsWith('http://') || value.startsWith('https://'))) {
                            return makeLink(value);
                        }
                        return escapeHtml(value);
                    };
                    
                    const renderField = (label, value) => {
                        if (!hasValue(value)) return '';
                        return '<div class="detail-row"><div class="detail-label">' + label + '</div><div class="detail-value">' + renderValue(value) + '</div></div>';
                    };
                    
                    const renderArray = (arr, label) => {
                        if (!arr || arr.length === 0) return '';
                        let html = '<div class="detail-row"><div class="detail-label">' + label + '</div><div class="detail-value"><ul style="margin: 0; padding-left: 20px;">';
                        arr.forEach(item => {
                            if (typeof item === 'object' && item.name) {
                                html += '<li>' + (item.role ? '<strong>' + escapeHtml(item.role) + ':</strong> ' : '') + renderValue(item.name) + '</li>';
                            } else if (hasValue(item)) {
                                html += '<li>' + renderValue(item) + '</li>';
                            }
                        });
                        html += '</ul></div></div>';
                        return html;
                    };
                    
                    reportWindow.document.write('<html><head><title>Facility Report</title>');
                    reportWindow.document.write('<link rel="stylesheet" href="css/print-report.css">');
                    reportWindow.document.write('</head><body>');
                    
                    // Header
                    reportWindow.document.write('<div class="header">');
                    reportWindow.document.write('<h1>📋 Kids Over Profits TTI Data Report</h1>');
                    reportWindow.document.write('<p>Generated on ' + new Date().toLocaleString() + '</p>');
                    reportWindow.document.write('</div>');
                    
                    // Operator Section - COMPLETE (only non-empty fields)
                    if (reportData.operator) {
                        const op = reportData.operator;
                        reportWindow.document.write('<div class="section">');
                        reportWindow.document.write('<h2>🏢 Operator Information</h2>');
                        
                        reportWindow.document.write(renderField('Operator Name', op.name));
                        reportWindow.document.write(renderField('Current Name', op.currentName));
                        if (op.otherNames && op.otherNames.length > 0) reportWindow.document.write(renderArray(op.otherNames, 'Other Names'));
                        reportWindow.document.write(renderField('Primary Location', op.location));
                        reportWindow.document.write(renderField('Headquarters', op.headquarters));
                        reportWindow.document.write(renderField('Founded', op.founded));
                        reportWindow.document.write(renderField('Operating Period', op.operatingPeriod));
                        reportWindow.document.write(renderField('Status', op.status));
                        if (op.parentCompanies && op.parentCompanies.length > 0) reportWindow.document.write(renderArray(op.parentCompanies, 'Parent Companies'));
                        if (op.websites && op.websites.length > 0) reportWindow.document.write(renderArray(op.websites, 'Websites'));
                        if (op.investors && op.investors.length > 0) reportWindow.document.write(renderArray(op.investors, 'Investors'));
                        
                        if (op.keyStaff && (hasValue(op.keyStaff.ceo) || (op.keyStaff.founders && op.keyStaff.founders.length > 0) || (op.keyStaff.keyExecutives && op.keyStaff.keyExecutives.length > 0))) {
                            reportWindow.document.write('<h3>Key Staff</h3>');
                            reportWindow.document.write(renderField('CEO/President', op.keyStaff.ceo));
                            if (op.keyStaff.founders && op.keyStaff.founders.length > 0) reportWindow.document.write(renderArray(op.keyStaff.founders, 'Founders'));
                            if (op.keyStaff.keyExecutives && op.keyStaff.keyExecutives.length > 0) reportWindow.document.write(renderArray(op.keyStaff.keyExecutives, 'Key Executives'));
                        }
                        
                        if (op.notes && op.notes.length > 0) reportWindow.document.write(renderArray(op.notes, 'Operator Notes'));
                        
                        reportWindow.document.write('</div>');
                    }
                    
                    // Facilities Section - COMPLETE
                    reportWindow.document.write('<div class="section">');
                    reportWindow.document.write('<h2>🏫 Facilities (' + reportData.facilities.length + ')</h2>');
                    
                    reportData.facilities.forEach((facility, index) => {
                        reportWindow.document.write('<div class="facility-card">');
                        reportWindow.document.write('<h3>#' + (index + 1) + ' - ' + (facility.identification?.name || 'Unnamed Facility') + '</h3>');
                        
                        // Identification
                        if (facility.identification) {
                            const id = facility.identification;
                            reportWindow.document.write(renderField('Current Name', id.currentName));
                            reportWindow.document.write(renderField('Current Operator', id.currentOperator));
                            if (id.otherNames && id.otherNames.length > 0) reportWindow.document.write(renderArray(id.otherNames, 'Other Names'));
                        }
                        
                        // Location
                        reportWindow.document.write(renderField('Location', facility.location));
                        if (hasValue(facility.address)) {
                            reportWindow.document.write('<div class="detail-row"><div class="detail-label">Address</div><div class="detail-value">' + escapeHtml(facility.address).replace(/\n/g, '<br>') + '</div></div>');
                        }
                        
                        // Other Operators
                        if (facility.otherOperators && facility.otherOperators.length > 0) reportWindow.document.write(renderArray(facility.otherOperators, 'Other Operators'));
                        
                        // Operating Period
                        if (facility.operatingPeriod) {
                            const op = facility.operatingPeriod;
                            reportWindow.document.write(renderField('Opened', op.startYear));
                            reportWindow.document.write(renderField('Closed', op.endYear));
                            reportWindow.document.write(renderField('Current Status', op.status));
                            reportWindow.document.write(renderField('Years of Operation', op.yearsOfOperation));
                            if (op.notes && op.notes.length > 0) reportWindow.document.write(renderArray(op.notes, 'Operational Notes'));
                        }
                        
                        // Staff
                        if (facility.staff) {
                            if (facility.staff.administrator && facility.staff.administrator.length > 0) reportWindow.document.write(renderArray(facility.staff.administrator, 'Administrators'));
                            if (facility.staff.notableStaff && facility.staff.notableStaff.length > 0) reportWindow.document.write(renderArray(facility.staff.notableStaff, 'Notable Staff'));
                        }
                        
                        // Profile Links
                        if (facility.profileLinks && facility.profileLinks.length > 0) reportWindow.document.write(renderArray(facility.profileLinks, 'Profile Links'));
                        
                        // Facility Details
                        if (facility.facilityDetails) {
                            const fd = facility.facilityDetails;
                            reportWindow.document.write(renderField('Program Type', fd.type));
                            reportWindow.document.write(renderField('Capacity', fd.capacity));
                            reportWindow.document.write(renderField('Current Census', fd.currentCensus));
                            if (fd.ageRange && (hasValue(fd.ageRange.min) || hasValue(fd.ageRange.max))) {
                                const ageRange = (hasValue(fd.ageRange.min) ? fd.ageRange.min : '?') + ' - ' + (hasValue(fd.ageRange.max) ? fd.ageRange.max : '?');
                                reportWindow.document.write('<div class="detail-row"><div class="detail-label">Age Range</div><div class="detail-value">' + ageRange + '</div></div>');
                            }
                            reportWindow.document.write(renderField('Gender', fd.gender));
                        }
                        
                        // Accreditations
                        if (facility.accreditations) {
                            if (facility.accreditations.current && facility.accreditations.current.length > 0) reportWindow.document.write(renderArray(facility.accreditations.current, 'Current Accreditations'));
                            if (facility.accreditations.past && facility.accreditations.past.length > 0) reportWindow.document.write(renderArray(facility.accreditations.past, 'Past Accreditations'));
                        }
                        if (facility.memberships && facility.memberships.length > 0) reportWindow.document.write(renderArray(facility.memberships, 'Professional Memberships'));
                        if (facility.certifications && facility.certifications.length > 0) reportWindow.document.write(renderArray(facility.certifications, 'Certifications'));
                        if (facility.licensing && facility.licensing.length > 0) reportWindow.document.write(renderArray(facility.licensing, 'Licensing Information'));
                        
                        // Resources
                        if (facility.resources) {
                            const res = facility.resources;
                            let resourceBadges = [];
                            Object.keys(res).forEach(key => {
                                if (key.startsWith('has') && res[key] === true) {
                                    const label = key.replace('has', '').replace(/([A-Z])/g, ' $1').trim();
                                    resourceBadges.push(label);
                                }
                            });
                            if (resourceBadges.length > 0) {
                                reportWindow.document.write('<div class="detail-row"><div class="detail-label">Available Resources</div><div class="detail-value">');
                                resourceBadges.forEach(r => reportWindow.document.write('<span class="badge badge-green">' + r + '</span>'));
                                reportWindow.document.write('</div></div>');
                            }
                            if (res.notes && res.notes.length > 0) reportWindow.document.write(renderArray(res.notes, 'Resource Notes'));
                        }
                        
                        // Treatment Types
                        if (facility.treatmentTypes) {
                            let treatments = [];
                            Object.keys(facility.treatmentTypes).forEach(key => {
                                if (facility.treatmentTypes[key] === true) {
                                    const label = key.replace('has', '').replace(/([A-Z])/g, ' $1').trim();
                                    treatments.push(label);
                                }
                            });
                            if (treatments.length > 0) {
                                reportWindow.document.write('<div class="detail-row"><div class="detail-label">Treatment Types</div><div class="detail-value">');
                                treatments.forEach(t => reportWindow.document.write('<span class="badge">' + t + '</span>'));
                                reportWindow.document.write('</div></div>');
                            }
                        }
                        
                        // Philosophy
                        if (facility.philosophy) {
                            let philosophies = [];
                            Object.keys(facility.philosophy).forEach(key => {
                                if (facility.philosophy[key] === true) {
                                    const label = key.replace('has', '').replace(/([A-Z])/g, ' $1').trim();
                                    philosophies.push(label);
                                }
                            });
                            if (philosophies.length > 0) {
                                reportWindow.document.write('<div class="detail-row"><div class="detail-label">Philosophies</div><div class="detail-value">');
                                philosophies.forEach(p => reportWindow.document.write('<span class="badge">' + p + '</span>'));
                                reportWindow.document.write('</div></div>');
                            }
                        }
                        
                        // Critical Incidents
                        if (facility.criticalIncidents) {
                            let incidents = [];
                            Object.keys(facility.criticalIncidents).forEach(key => {
                                if (facility.criticalIncidents[key] === true) {
                                    const label = key.replace('has', '').replace(/([A-Z])/g, ' $1').trim();
                                    incidents.push(label);
                                }
                            });
                            if (incidents.length > 0) {
                                reportWindow.document.write('<div class="detail-row"><div class="detail-label">Critical Incidents</div><div class="detail-value">');
                                incidents.forEach(i => reportWindow.document.write('<span class="badge" style="background: #fee2e2; color: #dc2626;">' + i + '</span>'));
                                reportWindow.document.write('</div></div>');
                            }
                        }
                        
                        // General Notes
                        if (facility.notes && facility.notes.length > 0) reportWindow.document.write(renderArray(facility.notes, 'Facility Notes'));
                        
                        reportWindow.document.write('</div>');
                    });
                    
                    reportWindow.document.write('</div>');
                    
                    // Raw JSON (collapsible)
                    reportWindow.document.write('<div class="section">');
                    reportWindow.document.write('<h2>📄 Raw Data (JSON)</h2>');
                    reportWindow.document.write('<details><summary style="cursor: pointer; font-weight: 600; padding: 10px; background: #f8fafc; border-radius: 6px;">Click to expand JSON data</summary>');
                    reportWindow.document.write('<pre style="background: #1f2937; color: #f8fafc; padding: 20px; border-radius: 6px; overflow-x: auto; margin-top: 10px; font-size: 12px;">' + escapeHtml(JSON.stringify(reportData, null, 2)) + '</pre>');
                    reportWindow.document.write('</details></div>');
                    
                    reportWindow.document.write('</body></html>');
                    reportWindow.document.close();
                }
            };
        }

        function initializeLegacySectionToggles() {
            const sections = document.querySelectorAll('.section');

            sections.forEach(section => {
                // Prevent re-initialization
                if (section.dataset.toggleInit === 'true') return;
                section.dataset.toggleInit = 'true';

                const header = section.querySelector('.section-header');
                const toggle = section.querySelector('.section-toggle');
                const content = section.querySelector('.section-content');

                if (!header || !toggle || !content) {
                    return;
                }

                toggle.setAttribute('role', 'button');
                toggle.setAttribute('tabindex', '0');

                // Toggle state function
                const setState = (expanded) => {
                    section.classList.toggle('expanded', expanded);
                    content.style.display = expanded ? 'block' : 'none';
                    toggle.setAttribute('aria-expanded', expanded.toString());
                    toggle.setAttribute('title', expanded ? 'Collapse section' : 'Expand section');
                };

                // Default expanded state logic
                const isMobile = window.innerWidth <= 768;
                const shouldExpand = isMobile ? false : section.classList.contains('expanded');
                setState(shouldExpand);

                // Handle toggle action
                const handleToggle = (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    const currentlyExpanded = section.classList.contains('expanded');
                    setState(!currentlyExpanded);
                };

                // Add listeners
                toggle.addEventListener('click', handleToggle, { passive: false });
                header.addEventListener('click', (event) => {
                    if (event.target.closest('.section-toggle')) return;
                    handleToggle(event);
                });
            });
        }

        function initializeSectionToggles() {
            initializeLegacySectionToggles();
            
            if (window.KOP_UI_Events && typeof window.KOP_UI_Events.initializeSubSectionToggles === 'function') {
                window.KOP_UI_Events.initializeSubSectionToggles();
            }
        }

        /**
         * Initialize mobile section controls for expand/collapse all
         */
        function initializeMobileSectionControls() {
            // Only add controls if on mobile and not already added
            if (window.innerWidth > 768 || document.querySelector('.mobile-section-controls')) {
                return;
            }

            // Create the mobile controls bar
            const controlsBar = document.createElement('div');
            controlsBar.className = 'mobile-section-controls';
            controlsBar.innerHTML = `
                <span class="section-control-label">📋 Sections</span>
                <div class="section-control-btns">
                    <button class="btn-section-control" id="expand-all-sections">Expand All</button>
                    <button class="btn-section-control" id="collapse-all-sections">Collapse All</button>
                </div>
            `;

            // Insert at the beginning of the form wrapper or container
            const facilityWrapper = document.getElementById('facility-main-wrapper');
            const referrerWrapper = document.getElementById('referrer-main-wrapper');

            if (facilityWrapper && facilityWrapper.offsetParent !== null) {
                facilityWrapper.insertBefore(controlsBar.cloneNode(true), facilityWrapper.firstChild);
            }
            if (referrerWrapper) {
                referrerWrapper.insertBefore(controlsBar.cloneNode(true), referrerWrapper.firstChild);
            }

            // Attach event listeners using event delegation
            document.addEventListener('click', (e) => {
                if (e.target.id === 'expand-all-sections' || e.target.closest('#expand-all-sections')) {
                    e.preventDefault();
                    expandAllSections();
                }
                if (e.target.id === 'collapse-all-sections' || e.target.closest('#collapse-all-sections')) {
                    e.preventDefault();
                    collapseAllSections();
                }
            });

            console.log('[Mobile] Section controls initialized');
        }

        /**
         * Expand all collapsible sections
         */
        function expandAllSections() {
            const sections = document.querySelectorAll('.section:not(.view-hidden)');
            sections.forEach(section => {
                const content = section.querySelector('.section-content');
                const toggle = section.querySelector('.section-toggle');
                if (content) {
                    section.classList.add('expanded');
                    content.style.display = 'block';
                    if (toggle) {
                        toggle.setAttribute('aria-expanded', 'true');
                        toggle.setAttribute('title', 'Collapse section');
                    }
                }
            });
        }

        /**
         * Collapse all collapsible sections
         */
        function collapseAllSections() {
            const sections = document.querySelectorAll('.section:not(.view-hidden):not(#submission-section):not(#referrer-submission-section):not(#advanced-mode-section)');
            sections.forEach(section => {
                const content = section.querySelector('.section-content');
                const toggle = section.querySelector('.section-toggle');
                if (content) {
                    section.classList.remove('expanded');
                    content.style.display = 'none';
                    if (toggle) {
                        toggle.setAttribute('aria-expanded', 'false');
                        toggle.setAttribute('title', 'Expand section');
                    }
                }
            });
        }

        // Fallback to make sure toggles are always bound even if updateAllUI didn't run yet
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeSectionToggles, { once: true });
        } else {
            initializeSectionToggles();
        }

        // Re-bind after the main form bootstraps in case sections are injected later
        document.addEventListener('formReady', () => {
            setTimeout(initializeSectionToggles, 0);
        }, { once: true });

        // DATA ORGANIZER FUNCTIONALITY
        function initializeDataOrganizer() {
        const showOrganizerBtn = document.getElementById('show-organizer-btn');
        const showOrganizerModalBtn = document.getElementById('show-organizer-modal-btn');
            const organizerSection = document.getElementById('data-organizer-section');
            const organizerModal = document.getElementById('data-organizer-modal');
            const organizerModalClose = document.getElementById('organizer-modal-close');
            const organizeBySelect = document.getElementById('organize-by');
            const organizeValueGroup = document.getElementById('organize-value-group');
            const organizeValueInput = document.getElementById('organize-value');
            const organizeSearchBtn = document.getElementById('organize-search-btn');
            const organizeClearBtn = document.getElementById('organize-clear-btn');
            const organizeResults = document.getElementById('organize-results');
            const organizeResultsTitle = document.getElementById('organize-results-title');
            const organizeResultsCount = document.getElementById('organize-results-count');
            const organizeMatches = document.getElementById('organize-matches');
            
            // Modal elements
            const organizeBySelectModal = document.getElementById('organize-by-modal');
            const organizeValueInputModal = document.getElementById('organize-value-modal');
            const organizeSearchBtnModal = document.getElementById('organize-search-btn-modal');
            const organizeClearBtnModal = document.getElementById('organize-clear-btn-modal');
            const organizeResultsModal = document.getElementById('organize-results-modal');
            const organizeResultsTitleModal = document.getElementById('organize-results-title-modal');
            const organizeResultsCountModal = document.getElementById('organize-results-count-modal');
            const organizeMatchesModal = document.getElementById('organize-matches-modal');
            
            const announceOrganizerStatus = (message, type = 'info') => {
                if (typeof showSuggestionStatus === 'function') {
                    showSuggestionStatus(message, type);
                } else if (typeof showUploadStatus === 'function') {
                    showUploadStatus(message, type);
                } else {
                    console.log(`[${type}] ${message}`);
                }
            };
            
        // Exit early if the organizer container is missing
        if (!organizerSection && !organizerModal) {
            console.warn('Data Organizer elements not found in DOM');
            return false;
        }
            
        let organizerVisible = false; // Collapsed by default
        
        // Set initial state - organizer hidden by default
        if (organizerSection) {
            organizerSection.style.display = 'none';
        }
        if (showOrganizerBtn) {
            showOrganizerBtn.textContent = '📊 Data Organizer';
        }
        
        const showOrganizerArea = () => {
            if (organizerModal) {
                organizerModal.classList.add('active');
            } else if (organizerSection) {
                organizerVisible = true;
                organizerSection.style.display = 'block';
                organizerSection.scrollIntoView({ behavior: 'smooth' });
            }
        };

        const hideOrganizerArea = () => {
            if (organizerModal) {
                organizerModal.classList.remove('active');
            } else if (organizerSection) {
                organizerVisible = false;
                organizerSection.style.display = 'none';
            }
        };
        
        // Toggle organizer visibility
        if (showOrganizerBtn && !showOrganizerBtn.dataset.organizerToggleAttached) {
            showOrganizerBtn.addEventListener('click', () => {
                organizerVisible = !organizerVisible;
                if (organizerSection) {
                    organizerSection.style.display = organizerVisible ? 'block' : 'none';
                }
                showOrganizerBtn.textContent = organizerVisible ? '📊 Hide Organizer' : '📊 Data Organizer'; // Corrected text

                if (organizerVisible && organizerSection) {
                    organizerSection.scrollIntoView({ behavior: 'smooth' });
                }
            }, { passive: true });
            showOrganizerBtn.dataset.organizerToggleAttached = 'true';
        }

        if (showOrganizerModalBtn && !showOrganizerModalBtn.dataset.organizerToggleAttached) {
            showOrganizerModalBtn.addEventListener('click', (e) => {
                e.preventDefault();
                showOrganizerArea();
                if (showOrganizerBtn) {
                    showOrganizerBtn.textContent = '📊 Hide Organizer';
                }
                // Attach modal handlers when modal opens
                setTimeout(() => {
                    // Modal search button
                    const searchBtn = document.getElementById('organize-search-btn-modal');
                    if (searchBtn && !searchBtn.dataset.organizerSearchAttached) {
                        console.log('✅ Attaching modal search button handler on modal open');
                        searchBtn.addEventListener('click', () => {
                            if (typeof window.performOrganizedSearchModal === 'function') {
                                window.performOrganizedSearchModal();
                            }
                        }, { passive: true });
                        searchBtn.dataset.organizerSearchAttached = 'true';
                    }
                    
                    // Modal enter key
                    const valueInput = document.getElementById('organize-value-modal');
                    if (valueInput && !valueInput.dataset.organizerKeypressAttached) {
                        valueInput.addEventListener('keypress', (e) => {
                            if (e.key === 'Enter' && typeof window.performOrganizedSearchModal === 'function') {
                                window.performOrganizedSearchModal();
                            }
                        }, { passive: true });
                        valueInput.dataset.organizerKeypressAttached = 'true';
                    }
                    
                    // Modal clear button  
                    const clearBtn = document.getElementById('organize-clear-btn-modal');
                    if (clearBtn && !clearBtn.dataset.organizerClearAttached) {
                        clearBtn.addEventListener('click', () => {
                            if (typeof window.clearOrganizerResultsModal === 'function') {
                                window.clearOrganizerResultsModal();
                            }
                        }, { passive: true });
                        clearBtn.dataset.organizerClearAttached = 'true';
                    }
                }, 100);
            }, { passive: false });
            showOrganizerModalBtn.dataset.organizerToggleAttached = 'true';
        }

        if (organizerModalClose && organizerModal && !organizerModalClose.dataset.organizerCloseAttached) {
            organizerModalClose.addEventListener('click', hideOrganizerArea, { passive: true });
            organizerModalClose.dataset.organizerCloseAttached = 'true';
        }

        if (organizerModal && !organizerModal.dataset.organizerBackdropAttached) {
            organizerModal.addEventListener('click', (e) => {
                if (e.target === organizerModal) {
                    hideOrganizerArea();
                }
            }, { passive: true });
            organizerModal.dataset.organizerBackdropAttached = 'true';
        }

            // Handle organize by selection
            if (!organizeBySelect.dataset.organizerChangeAttached) {
                organizeBySelect.addEventListener('change', () => {
                    const value = organizeBySelect.value;
                    if (value) {
                        organizeValueGroup.classList.remove('d-none');
                        organizeSearchBtn.classList.remove('d-none');
                        organizeValueInput.focus();
                        
                        // Set up autocomplete based on selected search type
                        const autocompleteCategory = getAutocompleteCategory(value);
                        if (autocompleteCategory) {
                            organizeValueInput.setAttribute('data-autocomplete-category', autocompleteCategory);
                            // Reinitialize autocomplete for this field
                            if (typeof window.initializeAutocompleteFields === 'function') {
                                window.initializeAutocompleteFields();
                            }
                        } else {
                            organizeValueInput.removeAttribute('data-autocomplete-category');
                        }
                    } else {
                        organizeValueGroup.classList.add('d-none');
                        organizeSearchBtn.classList.add('d-none');
                        clearOrganizerResults();
                    }
                }, { passive: true });
                organizeBySelect.dataset.organizerChangeAttached = 'true';
            }
            
            // Map search types to autocomplete categories
            function getAutocompleteCategory(searchType) {
                const categoryMap = {
                    'staff': 'human',
                    'operator': 'operator',
                    'location': 'location',
                    'programType': 'type',
                    'status': 'status',
                    'accreditation': 'accreditation',
                    'certification': 'certification'
                };
                return categoryMap[searchType] || null;
            }

            // Handle search
            if (!organizeSearchBtn.dataset.organizerSearchAttached) {
                organizeSearchBtn.addEventListener('click', performOrganizedSearch, { passive: true });
                organizeSearchBtn.dataset.organizerSearchAttached = 'true';
            }

            if (!organizeValueInput.dataset.organizerKeypressAttached) {
                organizeValueInput.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') performOrganizedSearch();
                }, { passive: true });
                organizeValueInput.dataset.organizerKeypressAttached = 'true';
            }

            // Handle clear
            if (!organizeClearBtn.dataset.organizerClearAttached) {
                organizeClearBtn.addEventListener('click', clearOrganizerResults, { passive: true });
                organizeClearBtn.dataset.organizerClearAttached = 'true';
            }
            
            // Modal search button - get element dynamically and attach handler
            const attachModalSearchHandler = () => {
                const searchBtn = document.getElementById('organize-search-btn-modal');
                if (searchBtn && !searchBtn.dataset.organizerSearchAttached) {
                    console.log('✅ Attaching modal search button handler');
                    searchBtn.addEventListener('click', window.performOrganizedSearchModal, { passive: true });
                    searchBtn.dataset.organizerSearchAttached = 'true';
                }
            };
            attachModalSearchHandler();
            
            // Modal value input enter key
            const attachModalEnterHandler = () => {
                const valueInput = document.getElementById('organize-value-modal');
                if (valueInput && !valueInput.dataset.organizerKeypressAttached) {
                    valueInput.addEventListener('keypress', (e) => {
                        if (e.key === 'Enter') window.performOrganizedSearchModal();
                    }, { passive: true });
                    valueInput.dataset.organizerKeypressAttached = 'true';
                }
            };
            attachModalEnterHandler();
            
            // Modal clear button
            const attachModalClearHandler = () => {
                const clearBtn = document.getElementById('organize-clear-btn-modal');
                if (clearBtn && !clearBtn.dataset.organizerClearAttached) {
                    clearBtn.addEventListener('click', window.clearOrganizerResultsModal, { passive: true });
                    clearBtn.dataset.organizerClearAttached = 'true';
                }
            };
            attachModalClearHandler();
            
            // Modal organize-by select - set up autocomplete
            const attachModalSelectHandler = () => {
                const selectEl = document.getElementById('organize-by-modal');
                const valueInput = document.getElementById('organize-value-modal');
                if (selectEl && !selectEl.dataset.organizerChangeAttached) {
                    selectEl.addEventListener('change', () => {
                        const value = selectEl.value;
                        if (value && valueInput) {
                            valueInput.focus();
                            
                            // Set up autocomplete based on selected search type
                            const autocompleteCategory = getAutocompleteCategory(value);
                            if (autocompleteCategory) {
                                valueInput.setAttribute('data-autocomplete-category', autocompleteCategory);
                                // Reinitialize autocomplete for this field
                                if (typeof window.initializeAutocompleteFields === 'function') {
                                    window.initializeAutocompleteFields();
                                }
                            } else {
                                valueInput.removeAttribute('data-autocomplete-category');
                            }
                        }
                    }, { passive: true });
                    selectEl.dataset.organizerChangeAttached = 'true';
                }
            };
            attachModalSelectHandler();

            function performOrganizedSearch() {
                const searchType = organizeBySelect.value;
                const searchValue = organizeValueInput.value.trim();
                const activeFormData = typeof formData !== 'undefined' ? formData : window.formData;
                const projectStore = (typeof projects !== 'undefined' && projects) ? projects : (window.projects || {});
                
                if (!searchType || !searchValue) {
                    announceOrganizerStatus('Select a data point and enter a search value, then click Search.', 'error');
                    if (organizeValueInput) {
                        organizeValueInput.focus();
                    }
                    return;
                }
                
                // Get all facilities from all projects
                const hasFormData = Boolean(activeFormData && Array.isArray(activeFormData.facilities) && activeFormData.facilities.length);
                const hasProjects = Boolean(projectStore && Object.keys(projectStore).length);
                if (!hasFormData && !hasProjects) {
                    announceOrganizerStatus('No facility data is loaded yet. Load a project or add facilities before searching.', 'error');
                    showOrganizerArea();
                    return;
                }
                
                announceOrganizerStatus(`Searching for "${searchValue}"...`, 'info');
                const results = [];
                
                // Search through current formData facilities
                if (activeFormData && activeFormData.facilities) {
                    activeFormData.facilities.forEach((facility, facilityIndex) => {
                        const matches = extractDataPointsForSearch(facility, searchType, searchValue);
                        if (matches.length > 0) {
                            results.push({
                                projectName: window.currentProjectName || 'Current Project',
                                facility: facility,
                                facilityIndex: facilityIndex,
                                matches: matches,
                                operator: formData.operator?.name  // Include operator from project level
                            });
                        }
                    });
                }
                
                // Search through all saved projects
                Object.keys(projectStore || {}).forEach(projectName => {
                    const project = projectStore[projectName];
                    if (project && project.data && project.data.facilities) {
                        project.data.facilities.forEach((facility, facilityIndex) => {
                            const matches = extractDataPointsForSearch(facility, searchType, searchValue);
                            if (matches.length > 0) {
                                results.push({
                                    projectName: projectName,
                                    facility: facility,
                                    facilityIndex: facilityIndex,
                                    matches: matches,
                                    operator: project.data.operator?.name  // Include operator from project level
                                });
                            }
                        });
                    }
                });
                
                if (results.length === 0) {
                    announceOrganizerStatus(`No matches found for "${searchValue}".`, 'warning');
                } else {
                    announceOrganizerStatus(`Found ${results.length} result${results.length === 1 ? '' : 's'} for "${searchValue}".`, 'success');
                }
                displayOrganizerResults(results, searchType, searchValue);
            }
            
            function extractDataPointsForSearch(facility, type, searchValue, projectOperator = null) {
                const matches = [];
                const searchLower = searchValue.toLowerCase();

                switch (type) {
                    case 'keyword':
                        // General keyword search across all major fields
                        // Facility name
                        if (facility.identification?.name && facility.identification.name.toLowerCase().includes(searchLower)) {
                            matches.push(`Facility Name: ${facility.identification.name}`);
                        }
                        if (facility.identification?.currentName && facility.identification.currentName.toLowerCase().includes(searchLower)) {
                            matches.push(`Current Name: ${facility.identification.currentName}`);
                        }
                        if (facility.identification?.otherNames && Array.isArray(facility.identification.otherNames)) {
                            facility.identification.otherNames.forEach(name => {
                                if (name.toLowerCase().includes(searchLower)) {
                                    matches.push(`Other Name: ${name}`);
                                }
                            });
                        }

                        // Operator
                        if (facility.identification?.currentOperator && facility.identification.currentOperator.toLowerCase().includes(searchLower)) {
                            matches.push(`Operator: ${facility.identification.currentOperator}`);
                        }
                        if (facility.otherOperators && Array.isArray(facility.otherOperators)) {
                            facility.otherOperators.forEach(op => {
                                const opStr = typeof op === 'string' ? op : (op?.name || '');
                                if (opStr && opStr.toLowerCase().includes(searchLower)) {
                                    matches.push(`Other Operator: ${opStr}`);
                                }
                            });
                        }

                        // Location
                        if (facility.location && typeof facility.location === 'string' && facility.location.toLowerCase().includes(searchLower)) {
                            matches.push(`Location: ${facility.location}`);
                        }
                        if (facility.locationDetails?.city && facility.locationDetails.city.toLowerCase().includes(searchLower)) {
                            matches.push(`City: ${facility.locationDetails.city}`);
                        }
                        if (facility.locationDetails?.state && facility.locationDetails.state.toLowerCase().includes(searchLower)) {
                            matches.push(`State: ${facility.locationDetails.state}`);
                        }

                        // Staff
                        if (facility.staff) {
                            if (facility.staff.administrator) {
                                const admins = Array.isArray(facility.staff.administrator) ? facility.staff.administrator : [facility.staff.administrator];
                                admins.forEach(admin => {
                                    const adminStr = typeof admin === 'string' ? admin : (admin?.name || '');
                                    if (adminStr && adminStr.toLowerCase().includes(searchLower)) {
                                        matches.push(`Administrator: ${adminStr}`);
                                    }
                                });
                            }
                            if (facility.staff.notableStaff && Array.isArray(facility.staff.notableStaff)) {
                                facility.staff.notableStaff.forEach(staff => {
                                    const staffStr = typeof staff === 'string' ? staff : (staff?.name || '');
                                    if (staffStr && staffStr.toLowerCase().includes(searchLower)) {
                                        matches.push(`Staff: ${staffStr}`);
                                    }
                                });
                            }
                        }

                        // Program type
                        if (facility.facilityDetails?.type && typeof facility.facilityDetails.type === 'string' && facility.facilityDetails.type.toLowerCase().includes(searchLower)) {
                            matches.push(`Program Type: ${facility.facilityDetails.type}`);
                        }

                        // Status
                        if (facility.operatingPeriod?.status && typeof facility.operatingPeriod.status === 'string' && facility.operatingPeriod.status.toLowerCase().includes(searchLower)) {
                            matches.push(`Status: ${facility.operatingPeriod.status}`);
                        }

                        // Notes - handle string, array, or object
                        if (facility.notes) {
                            let notesText = '';
                            if (typeof facility.notes === 'string') {
                                notesText = facility.notes;
                            } else if (Array.isArray(facility.notes)) {
                                notesText = facility.notes.filter(n => typeof n === 'string').join(' ');
                            } else if (typeof facility.notes === 'object') {
                                notesText = JSON.stringify(facility.notes);
                            }
                            if (notesText && notesText.toLowerCase().includes(searchLower)) {
                                matches.push('Notes contain keyword');
                            }
                        }

                        // Accreditations
                        if (facility.accreditations) {
                            if (facility.accreditations.current && Array.isArray(facility.accreditations.current)) {
                                facility.accreditations.current.forEach(acc => {
                                    const accStr = typeof acc === 'string' ? acc : (acc?.name || '');
                                    if (accStr && accStr.toLowerCase().includes(searchLower)) {
                                        matches.push(`Accreditation: ${accStr}`);
                                    }
                                });
                            }
                            if (facility.accreditations.past && Array.isArray(facility.accreditations.past)) {
                                facility.accreditations.past.forEach(acc => {
                                    const accStr = typeof acc === 'string' ? acc : (acc?.name || '');
                                    if (accStr && accStr.toLowerCase().includes(searchLower)) {
                                        matches.push(`Past Accreditation: ${accStr}`);
                                    }
                                });
                            }
                        }

                        // Certifications
                        if (facility.certifications && Array.isArray(facility.certifications)) {
                            facility.certifications.forEach(cert => {
                                const certStr = typeof cert === 'string' ? cert : (cert?.name || '');
                                if (certStr && certStr.toLowerCase().includes(searchLower)) {
                                    matches.push(`Certification: ${certStr}`);
                                }
                            });
                        }

                        // Known referrers
                        if (facility.identification?.knownReferrers && Array.isArray(facility.identification.knownReferrers)) {
                            facility.identification.knownReferrers.forEach(ref => {
                                const refStr = typeof ref === 'string' ? ref : (ref?.name || '');
                                if (refStr && refStr.toLowerCase().includes(searchLower)) {
                                    matches.push(`Referrer: ${refStr}`);
                                }
                            });
                        }
                        break;

                    case 'staff':
                        if (facility.staff) {
                            if (facility.staff.administrator) {
                                const admins = Array.isArray(facility.staff.administrator) ? facility.staff.administrator : [facility.staff.administrator];
                                admins.forEach(admin => {
                                    const adminStr = typeof admin === 'string' ? admin : (admin?.name || '');
                                    if (adminStr && adminStr.toLowerCase().includes(searchLower)) {
                                        matches.push(`Administrator: ${adminStr}`);
                                    }
                                });
                            }
                            if (facility.staff.notableStaff) {
                                const staffList = Array.isArray(facility.staff.notableStaff) ? facility.staff.notableStaff : [facility.staff.notableStaff];
                                staffList.forEach(staff => {
                                    const staffStr = typeof staff === 'string' ? staff : (staff?.name || '');
                                    if (staffStr && staffStr.toLowerCase().includes(searchLower)) {
                                        matches.push(`Notable Staff: ${staffStr}`);
                                    }
                                });
                            }
                        }
                        break;
                        
                    case 'operator':
                        // Check facility's current operator
                        if (facility.identification?.currentOperator &&
                            typeof facility.identification.currentOperator === 'string' &&
                            facility.identification.currentOperator.toLowerCase().includes(searchLower)) {
                            matches.push(`Current Operator: ${facility.identification.currentOperator}`);
                        }
                        // Check facility's other/past operators
                        if (facility.otherOperators && Array.isArray(facility.otherOperators)) {
                            facility.otherOperators.forEach(op => {
                                const opStr = typeof op === 'string' ? op : (op?.name || '');
                                if (opStr && opStr.toLowerCase().includes(searchLower)) {
                                    matches.push(`Other Operator: ${opStr}`);
                                }
                            });
                        }
                        // Check project-level operator (parent company)
                        if (projectOperator) {
                            if (projectOperator.name && typeof projectOperator.name === 'string' && projectOperator.name.toLowerCase().includes(searchLower)) {
                                matches.push(`Parent Company: ${projectOperator.name}`);
                            }
                            if (projectOperator.otherNames && Array.isArray(projectOperator.otherNames)) {
                                projectOperator.otherNames.forEach(name => {
                                    if (name && typeof name === 'string' && name.toLowerCase().includes(searchLower)) {
                                        matches.push(`Parent Company (alias): ${name}`);
                                    }
                                });
                            }
                        }
                        // Legacy: check old operator field path
                        if (facility.identification?.operator &&
                            typeof facility.identification.operator === 'string' &&
                            facility.identification.operator.toLowerCase().includes(searchLower)) {
                            matches.push(facility.identification.operator);
                        }
                        break;
                        
                    case 'location':
                        // Check facility location fields
                        if (facility.location && typeof facility.location === 'string' && facility.location.toLowerCase().includes(searchLower)) {
                            matches.push(facility.location);
                        }
                        if (facility.address && typeof facility.address === 'string' && facility.address.toLowerCase().includes(searchLower)) {
                            matches.push(`Address: ${facility.address}`);
                        }
                        if (facility.locationCity && typeof facility.locationCity === 'string' && facility.locationCity.toLowerCase().includes(searchLower)) {
                            matches.push(`City: ${facility.locationCity}`);
                        }
                        if (facility.locationState && typeof facility.locationState === 'string' && facility.locationState.toLowerCase().includes(searchLower)) {
                            matches.push(`State: ${facility.locationState}`);
                        }
                        // Check project-level operator location/headquarters
                        if (projectOperator) {
                            if (projectOperator.location && typeof projectOperator.location === 'string' && projectOperator.location.toLowerCase().includes(searchLower)) {
                                matches.push(`Operator Location: ${projectOperator.location}`);
                            }
                            if (projectOperator.headquarters && typeof projectOperator.headquarters === 'string' && projectOperator.headquarters.toLowerCase().includes(searchLower)) {
                                matches.push(`Headquarters: ${projectOperator.headquarters}`);
                            }
                            if (projectOperator.headquartersCity && typeof projectOperator.headquartersCity === 'string' && projectOperator.headquartersCity.toLowerCase().includes(searchLower)) {
                                matches.push(`HQ City: ${projectOperator.headquartersCity}`);
                            }
                            if (projectOperator.headquartersState && typeof projectOperator.headquartersState === 'string' && projectOperator.headquartersState.toLowerCase().includes(searchLower)) {
                                matches.push(`HQ State: ${projectOperator.headquartersState}`);
                            }
                            if (projectOperator.locationCity && typeof projectOperator.locationCity === 'string' && projectOperator.locationCity.toLowerCase().includes(searchLower)) {
                                matches.push(`Operator City: ${projectOperator.locationCity}`);
                            }
                            if (projectOperator.locationState && typeof projectOperator.locationState === 'string' && projectOperator.locationState.toLowerCase().includes(searchLower)) {
                                matches.push(`Operator State: ${projectOperator.locationState}`);
                            }
                        }
                        break;
                        
                    case 'programType':
                        // Check facilityDetails.type (primary and normalized location)
                        if (facility.facilityDetails?.type &&
                            typeof facility.facilityDetails.type === 'string' &&
                            facility.facilityDetails.type.toLowerCase().includes(searchLower)) {
                            matches.push(facility.facilityDetails.type);
                        }
                        break;
                        
                    case 'status':
                        if (facility.operatingPeriod?.status &&
                            typeof facility.operatingPeriod.status === 'string' &&
                            facility.operatingPeriod.status.toLowerCase().includes(searchLower)) {
                            matches.push(facility.operatingPeriod.status);
                        }
                        break;

                    case 'year':
                        if (facility.operatingPeriod?.startYear &&
                            facility.operatingPeriod.startYear.toString().includes(searchValue)) {
                            matches.push(`Opened: ${facility.operatingPeriod.startYear}`);
                        }
                        if (facility.operatingPeriod?.endYear &&
                            facility.operatingPeriod.endYear.toString().includes(searchValue)) {
                            matches.push(`Closed: ${facility.operatingPeriod.endYear}`);
                        }
                        break;

                    case 'accreditation':
                        if (facility.accreditations) {
                            if (facility.accreditations.current && Array.isArray(facility.accreditations.current)) {
                                facility.accreditations.current.forEach(acc => {
                                    const accStr = typeof acc === 'string' ? acc : (acc?.name || '');
                                    if (accStr && accStr.toLowerCase().includes(searchLower)) {
                                        matches.push(`Current: ${accStr}`);
                                    }
                                });
                            }
                            if (facility.accreditations.past && Array.isArray(facility.accreditations.past)) {
                                facility.accreditations.past.forEach(acc => {
                                    const accStr = typeof acc === 'string' ? acc : (acc?.name || '');
                                    if (accStr && accStr.toLowerCase().includes(searchLower)) {
                                        matches.push(`Past: ${accStr}`);
                                    }
                                });
                            }
                        }
                        break;

                    case 'certification':
                        if (facility.certifications && Array.isArray(facility.certifications)) {
                            facility.certifications.forEach(cert => {
                                const certStr = typeof cert === 'string' ? cert : (cert?.name || '');
                                if (certStr && certStr.toLowerCase().includes(searchLower)) {
                                    matches.push(certStr);
                                }
                            });
                        }
                        break;
                }
                
                return matches;
            }

            window.extractDataPointsForSearch = extractDataPointsForSearch;

            function displayOrganizerResults(results, searchType, searchValue) {
                const searchTypeLabel = organizeBySelect.options[organizeBySelect.selectedIndex].text;
                
                organizeResultsTitle.textContent = `Facilities with ${searchTypeLabel}: "${searchValue}"`;
                organizeResultsCount.textContent = `Found ${results.length} facilities`;
                
                if (results.length === 0) {
                    organizeMatches.innerHTML = '<div style="padding: 20px; text-align: center; color: #6b7280;">No matches found</div>';
                } else {
                    organizeMatches.innerHTML = ''; // Clear previous results
                    results.forEach(result => {
                        const facilityName = result.facility.identification?.name || 'Unnamed Facility';
                        // Check for privately owned facilities - use isPrivatelyOwned flag
                        const isPrivate = result.facility.isPrivatelyOwned === true;
                        const operator = result.operator || result.facility.identification?.currentOperator || (isPrivate ? 'Privately Owned' : 'Unknown Operator');
                        const location = result.facility.location || 'Unknown Location';
                        
                        const resultDiv = document.createElement('div');
                        resultDiv.style.cssText = "border-bottom: 1px solid #e2e8f0; padding: 15px; cursor: pointer; transition: background-color 0.2s;";

                        resultDiv.innerHTML = `
                            <div style="font-weight: 600; color: #1f2937; margin-bottom: 5px;"></div>
                            <div style="font-size: 14px; color: #6b7280; margin-bottom: 8px;"></div>
                            <div style="font-size: 13px;">
                                <strong>Matches:</strong> 
                                ${result.matches.map(match => `<span style="background: #fef3c7; padding: 2px 6px; border-radius: 3px; margin-right: 5px; color: #92400e;">${escapeHtmlForAttr(match)}</span>`).join('')}
                            </div>
                            <div style="font-size: 12px; color: #9ca3af; margin-top: 5px;">
                                Project: ${escapeHtmlForAttr(result.projectName)} • Facility #${result.facilityIndex + 1}
                            </div>
                        `;
                        // Use textContent to prevent HTML injection issues
                        resultDiv.children[0].textContent = facilityName;
                        resultDiv.children[1].textContent = `${operator} • ${location}`;

                        // Add event listeners with the passive option
                        resultDiv.addEventListener('mouseover', () => {
                            resultDiv.style.backgroundColor = '#f8fafc';
                        }, { passive: true });

                        resultDiv.addEventListener('mouseout', () => {
                            resultDiv.style.backgroundColor = 'white';
                        }, { passive: true });

                        resultDiv.addEventListener('click', () => {
                            goToFacility(result.projectName, result.facilityIndex);
                        }, { passive: true });

                        organizeMatches.appendChild(resultDiv);
                    });
                }
                
                organizeResults.style.display = 'block';
                organizeClearBtn.style.display = 'inline-block';
            }
            
            function clearOrganizerResults() {
                organizeResults.style.display = 'none';
                organizeClearBtn.style.display = 'none';
                organizeValueInput.value = '';
            }

            // NOTE: Modal search functions (performOrganizedSearchModal, displayOrganizerResultsModal,
            // clearOrganizerResultsModal, goToFacility) have been migrated to custom-modals.js
            // This prevents duplicate code and ensures proper functionality with the database API.
        }
        let organizerInitialized = false;

        const initializeOrganizerWhenReady = () => {
            if (organizerInitialized || document.readyState === 'loading') {
                return;
            }

            console.log('🚀 Form is ready. Initializing page-specific components.');

            if (typeof initializeDataOrganizer === 'function') {
                const didInit = initializeDataOrganizer();
                if (didInit !== false) {
                    organizerInitialized = true;
                }
            } else {
                console.warn('initializeDataOrganizer not available on ready.');
            }
        };

        const ensureOrganizerInitialization = () => {
            if (window.formReady) {
                initializeOrganizerWhenReady();
            } else if (!organizerInitialized) {
                document.addEventListener('formReady', initializeOrganizerWhenReady, { once: true });
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeOrganizerWhenReady, { once: true });
        } else {
            initializeOrganizerWhenReady();
        }

        // Run immediately in case the custom event already fired
        ensureOrganizerInitialization();

        // Initialize data organizer when the page loads or once the form becomes ready
        window.addEventListener('load', () => {
            initializeOrganizerWhenReady();
        }, { passive: true });

        // ============================================
        // FIELD NOTES FUNCTIONALITY
// Note: Field notes functionality has been moved to facility-form.v4.js
        // Note: Field notes functionality has been moved to facility-form.v4.js
        // It is automatically initialized when the form loads
        
        // ============================================
        // CATEGORY NAVIGATION (COMPANIES vs STATES)
        // ============================================
        
        function initializeCategoryNavigation() {
            // This function is now primarily handled by the main script,
            // but we ensure the correct panels are displayed based on the active tab.
            console.log('Admin page category navigation initialized.');
        }
        
        function populateCompaniesList() {
            console.log('🎨 Rendering projects list...');
            const container = document.getElementById('operators-list');
            if (!container) {
                console.error('operators-list container not found!');
                return;
            }

            const projectList = projects;
            const projectNames = Object.keys(projectList);
            
            console.log('🎨 Projects to render:', projectNames.length, projectNames);

            if (projectNames.length === 0) {
                container.innerHTML = '<div class="projects-empty">No saved projects yet<br><small>Save your current form data as a project to see it here</small></div>';
                console.log('📭 No projects to display');
                return;
            }
            
            projectNames.sort(function(a, b) {
                return (projectList[b].timestamp || '').localeCompare(projectList[a].timestamp || '');
            });
            
            const projectsHtml = projectNames.map(function(name) {
                const project = projectList[name];
                const date = new Date(project.timestamp || 0);
                const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                const facilityCount = project.data?.facilities?.length || 0;
                
                return `<div class="project-item" onclick="loadProjectAndSync('${escapeHtmlForAttr(name)}')">
                            <div class="project-item-name" title="Click to load project">${escapeHtmlForAttr(name)}</div>
                            <div class="project-item-date">${dateStr}<br><small>${facilityCount} facilities</small></div>
                            <div class="project-item-actions">
                                <button class="project-item-btn project-item-load" onclick="event.stopPropagation(); loadProjectAndSync('${escapeHtmlForAttr(name)}')">Load</button>
                            </div>
                        </div>`;
            }).join('');

            container.innerHTML = projectsHtml;
            console.log('Projects list rendered successfully');
        }

        function populateOperatorsList() {
            if (typeof window.refreshSavedProjectPanels === 'function') {
                window.refreshSavedProjectPanels();
            }

            if (document.getElementById('operators-list')) {
                populateCompaniesList();
            }
        }

        function populateLocationsList() {
            console.log('🎨 Rendering location projects list...');
            const container = document.getElementById('locations-list');
            if (!container) {
                console.error('locations-list container not found!');
                return;
            }
            
            const projectList = projects;
            const projectNames = Object.keys(projectList);
            
            console.log('🌍 Location projects to render:', projectNames.length, projectNames);

            if (projectNames.length === 0) {
                container.innerHTML = '<div class="projects-empty">No saved location projects yet<br><small>Save your current form data as a project to see it here</small></div>';
                console.log('📭 No location projects to display');
                return;
            }
            
            projectNames.sort(function(a, b) {
                return (projectList[b].timestamp || '').localeCompare(projectList[a].timestamp || '');
            });
            
            const projectsHtml = projectNames.map(function(name) {
                const project = projectList[name];
                const date = new Date(project.timestamp || 0);
                const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                const facilityCount = project.data?.facilities?.length || 0;
                
                return `<div class="project-item" onclick="loadProjectAndSync('${escapeHtmlForAttr(name)}')">
                            <div class="project-item-name" title="Click to load project">${escapeHtmlForAttr(name)}</div>
                            <div class="project-item-date">${dateStr}<br><small>${facilityCount} facilities</small></div>
                            <div class="project-item-actions">
                                <button class="project-item-btn project-item-load" onclick="event.stopPropagation(); loadProjectAndSync('${escapeHtmlForAttr(name)}')">Load</button>
                            </div>
                        </div>`;
            }).join('');
            
            container.innerHTML = projectsHtml;
            console.log('Location projects list rendered successfully');
        }
        
        function escapeHtmlForAttr(text) {
            return text.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        
        function renderFilteredProjectsList(containerId, filterType) {
            console.log('🎨 Rendering filtered projects list...', filterType);
            const container = document.getElementById(containerId);
            if (!container) {
                console.error('Container not found:', containerId);
                return;
            }
            
            const projectList = projects;
            const projectNames = Object.keys(projectList);
            
            // Define US states and countries
            const usStates = [
                'alabama', 'alaska', 'arizona', 'arkansas', 'california', 'colorado', 'connecticut', 'delaware', 'florida', 'georgia',
                'hawaii', 'idaho', 'illinois', 'indiana', 'iowa', 'kansas', 'kentucky', 'louisiana', 'maine', 'maryland',
                'massachusetts', 'michigan', 'minnesota', 'mississippi', 'missouri', 'montana', 'nebraska', 'nevada', 'new hampshire', 'new jersey',
                'new mexico', 'new york', 'north carolina', 'north dakota', 'ohio', 'oklahoma', 'oregon', 'pennsylvania', 'rhode island', 'south carolina',
                'south dakota', 'tennessee', 'texas', 'utah', 'vermont', 'virginia', 'washington', 'west virginia', 'wisconsin', 'wyoming'
            ];
            
            const countries = [
                'canada', 'mexico', 'united kingdom', 'france', 'germany', 'italy', 'spain', 'russia', 'china', 'japan',
                'australia', 'brazil', 'argentina', 'india', 'south africa', 'nigeria', 'egypt', 'saudi arabia', 'iran', 'iraq',
                'norway', 'sweden', 'denmark', 'netherlands', 'belgium', 'switzerland', 'austria', 'poland', 'ukraine', 'turkey'
            ];
            
            // Filter projects based on type
            const filteredNames = projectNames.filter(name => {
                const lowerName = name.toLowerCase().trim();
                if (filterType === 'locations') {
                    // Only show projects EXACTLY named after US states or countries
                    return usStates.includes(lowerName) || countries.includes(lowerName);
                } else {
                    // Companies: show projects that are NOT exactly named after states or countries
                    const isExactLocation = usStates.includes(lowerName) || countries.includes(lowerName);
                    return !isExactLocation;
                }
            });
            
            console.log('🔍 Filtered projects:', filteredNames.length, 'of', projectNames.length);

            if (filteredNames.length === 0) {
                const emptyMessage = filterType === 'locations' ?
                    'No state/country projects found<br><small>Create projects named after states like "California" or countries like "Canada"</small>' :
                    'No company projects found<br><small>Create projects with company/operator names</small>';
                container.innerHTML = `<div class="projects-empty">📭 ${emptyMessage}</div>`;
                console.log('📭 No filtered projects to display');
                return;
            }
            
            filteredNames.sort(function(a, b) {
                return (projectList[b].timestamp || '').localeCompare(projectList[a].timestamp || '');
            });
            
            const projectsHtml = filteredNames.map(function(name) {
                const project = projectList[name];
                const date = new Date(project.timestamp || 0);
                const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                const facilityCount = project.data?.facilities?.length || 0;
                
                return `<div class="project-item" onclick="loadProjectAndSync('${escapeHtmlForAttr(name)}')">
                            <div class="project-item-name" title="Click to load project">${escapeHtmlForAttr(name)}</div>
                            <div class="project-item-date">${dateStr}<br><small>${facilityCount} facilities</small></div>
                            <div class="project-item-actions">
                                <button class="project-item-btn project-item-load" onclick="event.stopPropagation(); loadProjectAndSync('${escapeHtmlForAttr(name)}')">Load</button>
                            </div>
                        </div>`;
            }).join('');
            
            container.innerHTML = projectsHtml;
            console.log('Filtered projects list rendered successfully');
        }
        
        function createNewLocationProject(projectName) {
            console.log('Creating new location project:', projectName);
            
            // Use the project manager to create a new project
            if (window.projectManager && window.projectManager.saveProject) {
                try {
                    // Save current form data as the new project
                    window.projectManager.saveProject(projectName);
                    
                    // Refresh the locations list using filtered approach
                    setTimeout(() => {
                        renderFilteredProjectsList('locations-list', 'locations');
                    }, 500);
                    
                    console.log('Location project created successfully:', projectName);
                    alert(`Location project "${projectName}" created successfully!`);
                } catch (error) {
                    console.error('Error creating project:', error);
                    alert('Error creating project. Please try again.');
                }
            } else {
                console.error('Project manager not available');
                alert('Unable to create project. Please try again.');
            }
        }
        
        function showStatesSpecificElements() {
            // Create and show the private ownership toggle if it doesn't exist
            let toggleSection = document.getElementById('private-ownership-toggle-section');
            if (!toggleSection) {
                toggleSection = createPrivateOwnershipToggle();
                // Insert it right after the category navigation
                const categoryNav = document.getElementById('category-navigation');
                categoryNav.parentNode.insertBefore(toggleSection, categoryNav.nextSibling);
            }
            toggleSection.style.display = 'block';
        }
        
        function hideStatesSpecificElements() {
            const toggleSection = document.getElementById('private-ownership-toggle-section');
            if (toggleSection) {
                toggleSection.style.display = 'none';
            }
            // Always show operator section when in companies view
            const operatorSection = document.getElementById('operator-section');
            if (operatorSection) {
                operatorSection.style.display = 'block';
            }
        }
        
        function createPrivateOwnershipToggle() {
            const section = document.createElement('div');
            section.id = 'private-ownership-toggle-section';
            section.style.cssText = `
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 20px;
                display: none;
            `;
            
            section.innerHTML = `
                <div style="display: flex; align-items: center; gap: 15px; font-weight: 600; color: #1f2937;">
                    <span>Privately Owned Facility (not part of a chain):</span>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span>No</span>
                        <div style="position: relative; display: inline-block;">
                            <input type="checkbox" id="private-ownership-toggle" style="display: none;">
                            <span id="slider-track" style="display: block; width: 48px; height: 24px; background-color: #e5e7eb; border-radius: 12px; cursor: pointer; transition: all 0.3s ease; position: relative;">
                                <span id="slider-knob" style="display: block; width: 20px; height: 20px; background-color: white; border-radius: 50%; position: absolute; top: 2px; left: 2px; transition: all 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"></span>
                            </span>
                        </div>
                        <span>Yes</span>
                    </div>
                </div>
                <p style="margin-top: 10px; color: #6b7280; font-size: 14px;">
                    Select "Yes" for independently owned facilities to hide the operator section and show owner fields.
                </p>
            `;
            
            // Add toggle functionality
            const toggle = section.querySelector('#private-ownership-toggle');
            const sliderTrack = section.querySelector('#slider-track');
            const sliderKnob = section.querySelector('#slider-knob');
            
            // Make the entire slider clickable
            sliderTrack.addEventListener('click', function() {
                toggle.checked = !toggle.checked;
                updateSliderAppearance();
                
                const isPrivate = toggle.checked;
                const operatorSection = document.getElementById('operator-section');
                
                // Save the private ownership flag to the current facility
                if (window.formData && window.formData.facilities && window.formData.facilities[window.currentFacilityIndex]) {
                    window.formData.facilities[window.currentFacilityIndex].isPrivatelyOwned = isPrivate;
                    console.log(`Set isPrivatelyOwned = ${isPrivate} for facility ${window.currentFacilityIndex}`);
                }
                
                if (isPrivate) {
                    if (operatorSection) operatorSection.style.display = 'none';
                    // Clear operator data when switching to private mode
                    clearOperatorFields();
                    // Modify Operations section for private ownership
                    modifyOperationsForPrivateOwnership(true);
                } else {
                    if (operatorSection) operatorSection.style.display = 'block';
                    // Restore Operations section for corporate ownership
                    modifyOperationsForPrivateOwnership(false);
                }
                
                // Trigger autosave
                if (typeof autoSave === 'function') {
                    autoSave();
                }
            }, { passive: true });

            // Function to clear operator fields when switching to private mode
            function clearOperatorFields() {
                console.log('🧹 Clearing operator fields for private facility mode');
                
                // Clear operator section fields
                const operatorInputs = document.querySelectorAll('#operator-section input, #operator-section textarea');
                operatorInputs.forEach(input => {
                    input.value = '';
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                });
                
                // Also clear operator data from formData if available
                if (typeof formData !== 'undefined' && formData && formData.operator) {
                    formData.operator = {
                        name: '',
                        currentName: '',
                        pastNames: [],
                        otherNames: [],
                        foundingDate: '',
                        keyPersonnel: [],
                        headquarters: '',
                        website: ''
                    };
                    console.log('Cleared operator data from formData');
                }
            }
            
            function updateSliderAppearance() {
                if (toggle.checked) {
                    sliderTrack.style.backgroundColor = '#10b981';
                    sliderKnob.style.transform = 'translateX(24px)';
                } else {
                    sliderTrack.style.backgroundColor = '#e5e7eb';
                    sliderKnob.style.transform = 'translateX(0px)';
                }
            }
            
            return section;
        }
        
        function modifyOperationsForPrivateOwnership(isPrivate) {
            const operationsSection = document.getElementById('operations-section');
            if (!operationsSection) return;
            
            const otherOperatorsGroup = operationsSection.querySelector('.array-container[data-path="otherOperators"]');
            const otherOperatorsLabel = otherOperatorsGroup ? otherOperatorsGroup.previousElementSibling : null;
            
            if (isPrivate) {
                // Hide "Other Operators" and add "Owners" and "Other Owners" as proper autocomplete inputs
                if (otherOperatorsGroup) otherOperatorsGroup.style.display = 'none';
                if (otherOperatorsLabel && otherOperatorsLabel.textContent === 'Other Operators') {
                    otherOperatorsLabel.style.display = 'none';
                }
                
                // Add owners fields if they don't exist - as proper autocomplete inputs
                if (!document.getElementById('owners-group')) {
                    const ownersHTML = `
                        <div class="form-group" id="owners-group">
                            <label>Current Owner</label>
                            <div class="autocomplete-wrapper">
                                <input type="text" class="facility-field" data-field="owner" data-field-type="human-name" placeholder="Type owner name..." style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                            </div>
                        </div>
                        <div class="form-group" id="other-owners-group">
                            <label>Previous/Other Owners</label>
                            <div class="autocomplete-wrapper">
                                <input type="text" class="facility-field" data-field="otherOwners" data-field-type="human-name" placeholder="Type owner name..." style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                            </div>
                        </div>
                    `;
                    
                    // Insert after the existing content in operations section
                    const sectionContent = operationsSection.querySelector('.section-content');
                    sectionContent.insertAdjacentHTML('beforeend', ownersHTML);
                    
                    // Initialize the autocomplete functionality
                    setTimeout(() => {
                        const ownerInput = document.querySelector('input[data-field="owner"]');
                        const otherOwnersContainer = document.querySelector('.array-container[data-path="otherOwners"]');
                        
                        // Initialize autocomplete for the other owners input
                        if (ownerInput && window.attachAutocompleteToInput) {
                            window.attachAutocompleteToInput(ownerInput, window.getHumanNames, 'human', function(selectedValue) {
                                ownerInput.value = selectedValue;
                                ownerInput.dispatchEvent(new Event('input', { bubbles: true }));
                            });
                        }
                        
                        const otherOwnersInput = document.querySelector('input[data-field="otherOwners"]');
                        if (otherOwnersInput && window.attachAutocompleteToInput) {
                            window.attachAutocompleteToInput(otherOwnersInput, window.getHumanNames, 'human', function(selectedValue) {
                                otherOwnersInput.value = selectedValue;
                                otherOwnersInput.dispatchEvent(new Event('input', { bubbles: true }));
                            });
                        }
                    }, 100);
                }
            } else {
                // Show "Other Operators" and hide owners fields
                if (otherOperatorsGroup) otherOperatorsGroup.style.display = 'block';
                if (otherOperatorsLabel && otherOperatorsLabel.textContent === 'Other Operators') {
                    otherOperatorsLabel.style.display = 'block';
                }
                
                // Hide owners fields
                const ownersGroup = document.getElementById('owners-group');
                const otherOwnersGroup = document.getElementById('other-owners-group');
                if (ownersGroup) ownersGroup.style.display = 'none';
                if (otherOwnersGroup) otherOwnersGroup.style.display = 'none';
            }
        }
        
        let adminPageInitialized = false;

        const initializeAdminPage = () => {
            if (adminPageInitialized) {
                return;
            }

            initializeCategoryNavigation();

            // Initialize facility toolbar toggle (defined in facility-form.v4.js)
            if (typeof window.initializeFacilityToolbarToggle === 'function') {
                window.initializeFacilityToolbarToggle();
            }

            // Initialize correct form visibility based on active tab
            const activeTab = document.querySelector('.category-tab.active');
            const activeCategory = activeTab ? activeTab.dataset.category : 'companies';
            const facilityMainWrapper = document.getElementById('facility-main-wrapper');
            const referrerMainWrapper = document.getElementById('referrer-main-wrapper');

            console.log('🚀 Initializing page with active category:', activeCategory);

            if (activeCategory === 'referrers') {
                if (facilityMainWrapper) facilityMainWrapper.style.display = 'none';
                if (referrerMainWrapper) referrerMainWrapper.style.display = 'block';
                console.log('✅ Initialized referrers view');
            } else {
                // Companies or locations view - show data forms
                if (facilityMainWrapper) facilityMainWrapper.style.display = 'block';
                if (referrerMainWrapper) referrerMainWrapper.style.display = 'none';
                console.log('✅ Initialized', activeCategory, 'view');
            }

            // Refresh saved project panels (from facility-form.v4.js)
            // facility-form.v4.js handles this automatically after cloud load,
            // but we also trigger it here in case the timing is different
            if (typeof window.refreshSavedProjectPanels === 'function') {
                console.log('Calling refreshSavedProjectPanels from data.html');
                window.refreshSavedProjectPanels();

                // Retry after cloud data loads
                setTimeout(() => {
                    window.refreshSavedProjectPanels();
                }, 2000);

                setTimeout(() => {
                    window.refreshSavedProjectPanels();
                }, 5000);
            } else {
                console.warn('refreshSavedProjectPanels not available yet');
            }

            // Add click handlers for state items (delegated to container)
            const statesList = document.getElementById('states-list');
            if (statesList && !statesList.dataset.stateClickAttached) {
                statesList.addEventListener('click', (event) => {
                    const item = event.target.closest('.state-item');
                    if (!item || !statesList.contains(item)) {
                        return;
                    }

                    // Remove previous selection
                    statesList.querySelectorAll('.state-item').forEach(state => state.classList.remove('selected'));
                    // Add selection to clicked item
                    item.classList.add('selected');

                    const locationName = item.dataset.state;
                    console.log('Selected location:', locationName);
                    // TODO: Filter facilities by location
                }, { passive: true });
                statesList.dataset.stateClickAttached = 'true';
            }

            // Add functionality for "Add New Operator" button
            const addOperatorBtn = document.getElementById('add-operator-btn');
            const newOperatorInput = document.getElementById('new-operator-input');

            if (addOperatorBtn && newOperatorInput) {
                if (!addOperatorBtn.dataset.operatorClickAttached) {
                    addOperatorBtn.addEventListener('click', function() {
                        const operatorName = newOperatorInput.value.trim();
                        if (operatorName) {
                            // Set the operator name in the form
                            const currentOperatorField = document.querySelector('input[data-field="identification.currentOperator"]');
                            if (currentOperatorField) {
                                currentOperatorField.value = operatorName;
                                currentOperatorField.dispatchEvent(new Event('input', { bubbles: true }));
                            }

                            // Clear the input
                            newOperatorInput.value = '';

                            // Refresh the operators list
                            populateOperatorsList();

                            console.log('Added new operator:', operatorName);
                        }
                    });
                    addOperatorBtn.dataset.operatorClickAttached = 'true';
                }

                // Allow Enter key to add operator
                if (!newOperatorInput.dataset.operatorKeypressAttached) {
                    newOperatorInput.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            addOperatorBtn.click();
                        }
                    });
                    newOperatorInput.dataset.operatorKeypressAttached = 'true';
                }
            }

            // Add functionality for "Add Location" button
            const addLocationBtn = document.getElementById('add-location-btn');
            const newLocationInput = document.getElementById('new-location-input');

            if (addLocationBtn && newLocationInput) {
                if (!addLocationBtn.dataset.locationClickAttached) {
                    addLocationBtn.addEventListener('click', function() {
                        const locationName = newLocationInput.value.trim();
                        if (locationName) {
                            // Add the new location to the list
                            const statesList = document.getElementById('states-list');
                            if (statesList) {
                                // Create new location item
                                const newLocationDiv = document.createElement('div');
                                newLocationDiv.className = 'state-item';
                                newLocationDiv.dataset.state = locationName;
                                newLocationDiv.textContent = locationName;
                                newLocationDiv.style.backgroundColor = '#f0f9ff'; // Light blue to indicate it's custom

                                // Insert at the top of the list (after header if present)
                                const firstStateItem = statesList.querySelector('.state-item');
                                if (firstStateItem) {
                                    statesList.insertBefore(newLocationDiv, firstStateItem);
                                } else {
                                    statesList.appendChild(newLocationDiv);
                                }

                                // Auto-select the new location
                                statesList.querySelectorAll('.state-item').forEach(state => state.classList.remove('selected'));
                                newLocationDiv.classList.add('selected');
                                console.log('Selected location:', locationName);
                            }

                            // Clear the input
                            newLocationInput.value = '';
                        }
                    }, { passive: true });
                    addLocationBtn.dataset.locationClickAttached = 'true';
                }

                if (!newLocationInput.dataset.locationKeypressAttached) {
                    newLocationInput.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            addLocationBtn.click();
                        }
                    });
                    newLocationInput.dataset.locationKeypressAttached = 'true';
                }
            }

            // Initialize overview features (functions defined in facility-form.v4.js)
            if (typeof window.initializeConsultantsTocToggle === 'function') {
                initializeConsultantsTocToggle();
            }
            if (typeof window.initializeConsultantNavigation === 'function') {
                initializeConsultantNavigation();
            }
            if (typeof window.initializeLocationFacilitiesToc === 'function') {
                initializeLocationFacilitiesToc();
            }

            adminPageInitialized = true;
        };

        const ensureAdminPageInitialization = () => {
            if (window.formReady) {
                initializeAdminPage();
            } else if (!adminPageInitialized) {
                document.addEventListener('formReady', initializeAdminPage, { once: true });
            }
        };

        // Kick off initialization immediately in case formReady already fired
        ensureAdminPageInitialization();

        // Initialize category navigation when page loads
        window.addEventListener('load', () => {
            initializeSectionToggles();
            ensureAdminPageInitialization();
        });

        // Initialize tab-switching for overviews (function defined in facility-form.v4.js)
        if (typeof window.initializeOverviewTabSwitching === 'function') {
            initializeOverviewTabSwitching();
        }

