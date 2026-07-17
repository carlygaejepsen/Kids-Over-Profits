(function() {
    if (window.KOP_API) {
        return; // Already loaded
    }

    // A private helper function for saving to localStorage
    function saveToLocalStorage(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
            console.warn('Failed to save to localStorage:', e);
        }
    }

    function normalizeProjectsPayload(payload) {
        if (!payload) return null;

        const normalized = {};
        const normalizeProjectData = window.KOP_DataNormalizer.normalizeProjectData;

        const inferCategory = (projectName, explicitCategory) => {
            if (explicitCategory) return explicitCategory;
            const upper = (projectName || '').toUpperCase().trim();
            const isLocation = (
                (typeof window !== 'undefined' && window.US_STATE_SET && window.US_STATE_SET.has(upper.toLowerCase())) ||
                (typeof window !== 'undefined' && window.COUNTRY_SET && window.COUNTRY_SET.has(upper.toLowerCase()))
            );
            if (isLocation) return 'locations';
            return 'companies';
        };

        const assignProject = (projectName, projectPayload) => {
            if (!projectName) return;
            const source = projectPayload && typeof projectPayload === 'object' ? projectPayload : {};

            const parseIfString = (val) => {
                if (typeof val !== 'string') return val;
                try {
                    return JSON.parse(val);
                } catch (parseErr) {
                    console.warn('Failed to parse project data string for', projectName, parseErr);
                    return {};
                }
            };

            // Pick the object layer that actually holds project content. Company
            // projects store facilities/operator at the top level alongside a thin
            // `data` sibling (e.g. { documentFolderId }); others nest content under
            // `data` or `data.data`. Blindly preferring `source.data` drops the
            // top-level facilities and shows only a blank "Facility 1".
            const hasProjectContent = (obj) => obj && typeof obj === 'object' && [
                'facilities', 'operator', 'referrerConsultants', 'referrerAgency',
                'referrerIndividual', 'transporters', 'transporterCompany', 'transporterIndividual'
            ].some(key => obj[key] !== undefined);

            const dataLayer = parseIfString(source.data);
            const innerLayer = dataLayer && parseIfString(dataLayer.data);

            let rawData;
            if (hasProjectContent(innerLayer)) {
                rawData = innerLayer; // double-nested { data: { data: {...} } }
            } else if (hasProjectContent(dataLayer)) {
                rawData = dataLayer;
            } else if (hasProjectContent(source)) {
                rawData = source; // content at top level; `data` is just metadata
            } else {
                rawData = dataLayer || source; // nothing obvious — preserve legacy behavior
            }

            const name = source.name || projectName;
            normalized[projectName] = {
                name,
                data: normalizeProjectData(rawData),
                timestamp: source.timestamp || rawData?.timestamp || new Date().toISOString(),
                currentFacilityIndex: source.currentFacilityIndex ?? rawData?.currentFacilityIndex ?? 0,
                category: inferCategory(name, source.category || rawData?.category)
            };
        };

        if (payload.projects && typeof payload.projects === 'object') {
            Object.entries(payload.projects).forEach(([key, value]) => {
                assignProject(value?.name || key, value);
            });
        } else if (Array.isArray(payload)) {
            payload.forEach(item => {
                if (!item) return;
                const projectName = item.name || item.unique_name || item.projectName;
                assignProject(projectName || `project-${Math.random().toString(36).slice(2)}`, item);
            });
        } else if (typeof payload === 'object') {
            Object.entries(payload).forEach(([key, value]) => {
                assignProject(value?.name || key, value);
            });
        } else {
            return null;
        }

        return Object.keys(normalized).length > 0 ? normalized : null;
    }

    async function loadProjectsFromFallbackDatasets() {
        const { FALLBACK_PROJECTS_URL_CANDIDATES } = window.KOP_FormConfig;
        if (!FALLBACK_PROJECTS_URL_CANDIDATES.length) {
            return null;
        }

        const totalCandidates = FALLBACK_PROJECTS_URL_CANDIDATES.length;

        for (let index = 0; index < totalCandidates; index += 1) {
            const fallbackUrl = FALLBACK_PROJECTS_URL_CANDIDATES[index];
            const attemptLabel = `Attempting to load fallback dataset (${index + 1}/${totalCandidates})...`;

            try {
                window.showUploadStatus(attemptLabel, 'info');

                const response = await fetch(fallbackUrl);

                if (!response.ok) {
                    console.warn(`Fallback dataset request for ${fallbackUrl} failed with status ${response.status}`);
                    continue;
                }

                const fallbackText = await response.text();
                let fallbackData;

                try {
                    fallbackData = JSON.parse(fallbackText);
                } catch (parseError) {
                    console.warn(`Fallback dataset at ${fallbackUrl} returned invalid JSON:`, parseError);
                    continue;
                }

                const normalizedProjects = normalizeProjectsPayload(fallbackData);

                if (normalizedProjects && Object.keys(normalizedProjects).length > 0) {
                    return {
                        url: fallbackUrl,
                        projects: normalizedProjects
                    };
                }

                console.warn('Fallback dataset did not contain usable project data at', fallbackUrl);
            } catch (fallbackError) {
                console.warn('Fallback dataset load failed for', fallbackUrl, fallbackError);
            }
        }

        return null;
    }

    async function loadAllProjectsFromCloud() {
        const { API_ENDPOINTS } = window.KOP_FormConfig;
        const { showUploadStatus, debugLog } = window;

        try {
            showUploadStatus('Loading projects from cloud...', 'info');

            const response = await fetch(API_ENDPOINTS.LOAD_PROJECTS);
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const result = await response.json();

            if (result.success && result.projects) {
                const deduplicatedProjects = typeof window.deduplicateLocationProjects === 'function'
                    ? window.deduplicateLocationProjects(result.projects)
                    : result.projects;

                if (typeof window.syncLocationProjectsFromSources === 'function') {
                    window.syncLocationProjectsFromSources(deduplicatedProjects);
                } else {
                    console.error('❌ syncLocationProjectsFromSources function not found!');
                }

                window.projects = deduplicatedProjects;
                
                if (typeof window.invalidateAggregatedData === 'function') {
                    window.invalidateAggregatedData();
                }

                saveToLocalStorage('cloudProjects', window.projects);
                showUploadStatus(`Loaded ${Object.keys(window.projects).length} projects from cloud`, 'success');
                debugLog('Loaded projects from cloud:', Object.keys(window.projects));

                setTimeout(() => {
                    debugLog('Re-initializing autocomplete with cloud data...');
                    document.querySelectorAll('input[data-autocomplete-category]').forEach(field => {
                        delete field.dataset.autocompleteInit;
                    });
                    if (typeof window.delegateInitializeAutocompleteFields === 'function') {
                        window.delegateInitializeAutocompleteFields();
                    } else if (typeof window.initializeAutocompleteFields === 'function') {
                        window.initializeAutocompleteFields();
                    }
                    if(typeof window.refreshSavedProjectPanels === 'function') {
                        window.refreshSavedProjectPanels();
                    }
                }, 500);

                return window.projects;
            } else {
                throw new Error(result.error || 'Failed to load projects');
            }
        } catch (error) {
            console.error('Cloud load error:', error);
            showUploadStatus('Failed to load from cloud. Checking backups...', 'error');

            try {
                const backup = JSON.parse(localStorage.getItem('cloudProjects') || '{}');
                if (Object.keys(backup).length > 0) {
                    window.projects = backup;
                    if (typeof window.invalidateAggregatedData === 'function') {
                        window.invalidateAggregatedData();
                    }
                    showUploadStatus('Loaded from localStorage backup', 'info');
                    return window.projects;
                }
            } catch (e) {
                console.error('localStorage backup failed:', e);
            }

            const fallbackLoadResult = await loadProjectsFromFallbackDatasets();

            if (fallbackLoadResult && fallbackLoadResult.projects) {
                window.projects = fallbackLoadResult.projects;
                if (typeof window.invalidateAggregatedData === 'function') {
                    window.invalidateAggregatedData();
                }
                saveToLocalStorage('cloudProjects', window.projects);
                showUploadStatus(`Loaded ${Object.keys(window.projects).length} projects from fallback dataset`, 'success');
                debugLog('Loaded projects from fallback dataset:', fallbackLoadResult.url);
                return window.projects;
            }

            showUploadStatus('No projects found - starting fresh', 'info');
            if (typeof window.invalidateAggregatedData === 'function') {
                window.invalidateAggregatedData();
            }
            return {};
        }
    }

    function persistProjectLocally(projectName, { showStatus = false, statusType = 'info', statusMessage = '' } = {}) {
        if (!projectName || !window.formData) {
            return false;
        }

        if (!window.projects || typeof window.projects !== 'object') {
            window.projects = {};
        }

        if (typeof window.ensureReferrerDataStructures === 'function') {
            window.ensureReferrerDataStructures();
        }

        const activeTab = document.querySelector('.category-tab.active');
        const category = activeTab ? activeTab.dataset.category : 'companies';

        const snapshot = {
            name: projectName,
            data: window.deepClone(window.formData),
            currentFacilityIndex: window.currentFacilityIndex,
            timestamp: new Date().toISOString(),
            category: category
        };

        window.projects[projectName] = snapshot;

        try {
            saveToLocalStorage('cloudProjects', window.projects);
        } catch (storageError) {
            console.warn('Local persistence failed:', storageError);
        }

        if (typeof window.invalidateAggregatedData === 'function') {
            window.invalidateAggregatedData();
        }
        
        if (showStatus && statusMessage) {
            window.showUploadStatus(statusMessage, statusType);
        }

        return true;
    }

    async function saveProjectToCloud(projectName, action = 'save') {
        const { getAPIEndpoints, isSuggestionMode } = window.KOP_FormConfig;
        const { showUploadStatus, debugLog, deepClone, newProject, updateAllUI, refreshSavedProjectPanels } = window;
        const API_ENDPOINTS = getAPIEndpoints();

        if (action === 'delete') {
            // In suggestion mode there is no server-side delete — the public
            // endpoint only accepts suggestion payloads (data + reason) and
            // would reject this request with a 400. Remove the local draft.
            if (isSuggestionMode()) {
                delete window.projects[projectName];
                localStorage.removeItem(`project_${projectName}`);
                try {
                    saveToLocalStorage('cloudProjects', window.projects);
                } catch (storageError) {
                    console.warn('Local persistence failed:', storageError);
                }
                if (window.currentProjectName === projectName) newProject();
                showUploadStatus(`✅ Removed local draft "${projectName}".`, 'success');
                if (typeof updateAllUI === 'function') updateAllUI();
                if (typeof refreshSavedProjectPanels === 'function') refreshSavedProjectPanels();
                return true;
            }

            try {
                showUploadStatus(`🗑️ Deleting "${projectName}"...`, 'info');
                const payload = { projectName: projectName, action: 'delete' };

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
                if (!result.success) throw new Error(result.error || result.message || 'Unknown server error');

                delete window.projects[projectName];
                localStorage.removeItem(`project_${projectName}`);
                if (window.currentProjectName === projectName) newProject();

                showUploadStatus(`✅ Deleted "${projectName}" successfully!`, 'success');
                if (typeof updateAllUI === 'function') updateAllUI();
                if (typeof refreshSavedProjectPanels === 'function') refreshSavedProjectPanels();
                return true;
            } catch (error) {
                console.error('❌ DELETE FAILED:', error.message);
                showUploadStatus(`❌ Failed to delete: ${error.message}`, 'error');
                return false;
            }
        }

        if (!projectName || !window.formData) {
            showUploadStatus('❌ No project name or data to save', 'error');
            return false;
        }

        if (isSuggestionMode()) {
            window.currentProjectName = projectName;
            const saved = persistProjectLocally(projectName, {
                showStatus: true,
                statusType: 'info',
                statusMessage: '💾 Draft saved locally. Use "Submit Suggestion for Review" to send updates to Kids Over Profits.'
            });
            if (!saved) showUploadStatus('❌ Unable to save draft locally. Please try again.', 'error');
            return false;
        }

        try {
            showUploadStatus(`💾 Saving "${projectName}" to cloud...`, 'info');
            if (typeof window.ensureReferrerDataStructures === 'function') window.ensureReferrerDataStructures();
            
            const normalizedName = projectName.toLowerCase().trim();
            let category;
            if (window.US_STATE_SET?.has(normalizedName) || window.COUNTRY_SET?.has(normalizedName)) {
                category = 'locations';
            } else {
                category = window.projects?.[projectName]?.category;
                if (!category) {
                    const activeTab = document.querySelector('.category-tab.active');
                    category = activeTab ? activeTab.dataset.category : 'companies';
                }
                // The Operators tab is an edit-only VIEW of company projects, not a
                // real data category. Never persist 'operators' — it would route/
                // categorize the project wrong and drop it from the companies list.
                if (category === 'operators' || category === 'operator') {
                    category = 'companies';
                }
            }

            let canonicalProjectName = projectName;
            if (category === 'locations' && typeof window.normalizeLocationProjectName === 'function') {
                canonicalProjectName = window.normalizeLocationProjectName(projectName) || projectName;
                if (canonicalProjectName !== projectName && window.projects?.[projectName]) {
                    window.projects[canonicalProjectName] = {
                        ...window.projects[projectName],
                        ...window.projects[canonicalProjectName],
                        name: canonicalProjectName,
                        category: 'locations'
                    };
                    delete window.projects[projectName];
                }
                projectName = canonicalProjectName;
            }

            const projectData = {
                name: projectName,
                data: deepClone(window.formData),
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
            
            let response;
            let usedRestApi = false;
            try {
                response = await fetch(API_ENDPOINTS.SAVE_PROJECT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('PHP_NOT_EXECUTING');
                }
            } catch (directError) {
                if (directError.message === 'PHP_NOT_EXECUTING' || directError.message.includes('Failed to fetch')) {
                    const restEndpoint = API_ENDPOINTS.REST_SAVE_PROJECT;
                    const restNonce = API_ENDPOINTS.REST_NONCE || window.wpApiSettings?.nonce || '';
                    response = await fetch(restEndpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
                        credentials: 'same-origin',
                        body: JSON.stringify(payload)
                    });
                    usedRestApi = true;
                } else {
                    throw directError;
                }
            }

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(`HTTP ${response.status}: ${errorText.substring(0, 100)}`);
            }

            const result = await response.json();
            if (!result.success) throw new Error(result.error || result.message || 'Unknown server error');

            window.projects[projectName] = projectData;
            if(typeof window.invalidateAggregatedData === 'function') window.invalidateAggregatedData();
            window.currentProjectName = projectName;
            persistProjectLocally(projectName);
            showUploadStatus(`✅ Saved "${projectName}" successfully!`, 'success');
            if (typeof updateAllUI === 'function') updateAllUI();
            return true;
        } catch (error) {
            console.error('❌ SAVE FAILED:', error.message);
            showUploadStatus(`❌ Failed to save: ${error.message}`, 'error');
            persistProjectLocally(projectName, {
                showStatus: true,
                statusType: 'info',
                statusMessage: '⚠️ Saved to local storage only (cloud save failed).'
            });
            return false;
        }
    }
    
    // Expose public API
    window.KOP_API = {
        normalizeProjectsPayload,
        loadProjectsFromFallbackDatasets,
        loadAllProjectsFromCloud,
        persistProjectLocally,
        saveProjectToCloud
    };
})();