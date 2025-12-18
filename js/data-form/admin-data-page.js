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
            return;
        }

        activeViewLayout = normalizedView;

        viewManagedElements.forEach(item => {
            // Guard against elements that may have been removed from the DOM
            if (!item.element || !item.element.isConnected) {
                return;
            }

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
// ADMIN DATA PAGE FUNCTIONALITY
// ============================================

// Set mode to master for direct saving
window.FORM_MODE = 'master';

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

    // Force clear current project first to ensure a clean switch
    if (window.projectManager && window.projectManager.newProject) {
        console.log('🧹 Clearing current project first...');
        window.projectManager.newProject(false); // false = don't show status
    }

    // Small delay to ensure clearing is complete, then load the new project
    setTimeout(() => {
        // Use the existing project manager to load the project
        if (window.projectManager && window.projectManager.loadProject) {
            console.log('📥 Loading project:', projectName);
            window.projectManager.loadProject(projectName);

            // Wait a moment for the load to complete, then sync our formData
            setTimeout(() => {
                // Make sure we have access to the global formData
                if (typeof formData !== 'undefined') {
                    window.globalFormData = formData;

                    console.log('Project loaded and synced:', projectName);
                    console.log('📊 Current project data:', formData);
                    console.log('🏢 Project facilities:', formData?.facilities?.length || 0);

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

                    // Update the page title
                    const pageTitle = document.querySelector('h1');
                    if (pageTitle && projectName !== 'New Project') {
                        const actualProject = window.currentProjectName || projectName;
                        pageTitle.innerHTML = `Admin - ${actualProject}`;
                    }

                    // Update toolbar facility info
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
    }, 100);
}

// ============================================
// AGENCY/INDEPENDENT CONSULTANT TOGGLE
// ============================================

const independentToggle = document.getElementById('referrer-independent-toggle');
const independentStatus = document.getElementById('referrer-independent-status');
const independentEditBtn = document.getElementById('referrer-independent-edit-btn');
const agencySection = document.getElementById('referrer-agency-section');
const consultantModal = document.getElementById('consultant-modal');

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

    const isIndependent = !!independentToggle.checked;
    if (independentStatus) {
        independentStatus.textContent = isIndependent ? 'Independent consultant' : 'Agency/Group consultant';
    }
    if (agencySection) agencySection.style.display = isIndependent ? 'none' : 'block';
}

if (independentToggle && !independentToggle.dataset.listenerAttached) {
    independentToggle.addEventListener('change', () => {
        updateAgencySliderAppearance();
    }, { passive: true });
    independentToggle.dataset.listenerAttached = 'true';
}

if (independentEditBtn && independentToggle && !independentEditBtn.dataset.listenerAttached) {
    independentEditBtn.addEventListener('click', () => {
        if (!consultantModal) {
            const choice = confirm('Is this an independent consultant (not part of an agency)?\n\nOK = Independent consultant\nCancel = Part of an agency/group');
            independentToggle.checked = choice;
            independentToggle.dispatchEvent(new Event('change', { bubbles: true }));
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
    modifyOperationsForPrivateOwnership(isPrivate);
}

function clearOperatorFields() {
    console.log('🧹 Clearing operator fields for private facility mode');

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

function modifyOperationsForPrivateOwnership(isPrivate) {
    const operationsSection = document.getElementById('operations-section');

    // Handle Identification section changes
    const currentOperatorGroup = document.getElementById('current-operator-group');
    const currentOwnerGroup = document.getElementById('current-owner-group');
    const pastOwnersGroup = document.getElementById('past-owners-group');

    if (isPrivate) {
        // Show owner fields, hide operator field in Identification
        if (currentOperatorGroup) currentOperatorGroup.style.display = 'none';
        if (currentOwnerGroup) currentOwnerGroup.style.display = 'block';
        if (pastOwnersGroup) pastOwnersGroup.style.display = 'block';
    } else {
        // Show operator field, hide owner fields in Identification
        if (currentOperatorGroup) currentOperatorGroup.style.display = 'block';
        if (currentOwnerGroup) currentOwnerGroup.style.display = 'none';
        if (pastOwnersGroup) pastOwnersGroup.style.display = 'none';
    }

    // Handle Operations section changes
    if (!operationsSection) return;

    const otherOperatorsGroup = operationsSection.querySelector('.array-container[data-path="otherOperators"]');
    const otherOperatorsLabel = otherOperatorsGroup ? otherOperatorsGroup.previousElementSibling : null;

    if (isPrivate) {
        // Hide "Other Operators" and add "Owners" fields
        if (otherOperatorsGroup) otherOperatorsGroup.style.display = 'none';
        if (otherOperatorsLabel && otherOperatorsLabel.textContent === 'Other Operators') {
            otherOperatorsLabel.style.display = 'none';
        }

        // Add owners fields if they don't exist
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

            const sectionContent = operationsSection.querySelector('.section-content');
            if (sectionContent) {
                sectionContent.insertAdjacentHTML('beforeend', ownersHTML);
            }
        } else {
            // Show existing owners fields
            const ownersGroup = document.getElementById('owners-group');
            const otherOwnersGroup = document.getElementById('other-owners-group');
            if (ownersGroup) ownersGroup.style.display = 'block';
            if (otherOwnersGroup) otherOwnersGroup.style.display = 'block';
        }
    } else {
        // Restore "Other Operators" and hide "Owners" fields
        if (otherOperatorsGroup) otherOperatorsGroup.style.display = 'block';
        if (otherOperatorsLabel) otherOperatorsLabel.style.display = 'block';

        const ownersGroup = document.getElementById('owners-group');
        const otherOwnersGroup = document.getElementById('other-owners-group');
        if (ownersGroup) ownersGroup.style.display = 'none';
        if (otherOwnersGroup) otherOwnersGroup.style.display = 'none';
    }
}

if (privateOwnershipToggle && !privateOwnershipToggle.dataset.listenerAttached) {
    privateOwnershipToggle.addEventListener('change', function() {
        const isPrivate = !!privateOwnershipToggle.checked;

        // Save the private ownership flag to the current facility
        if (window.formData && window.formData.facilities && window.formData.facilities[window.currentFacilityIndex]) {
            window.formData.facilities[window.currentFacilityIndex].isPrivatelyOwned = isPrivate;
            console.log(`Set isPrivatelyOwned = ${isPrivate} for facility ${window.currentFacilityIndex}`);
        }

        updatePrivateOwnershipSliderAppearance();

        if (isPrivate) {
            clearOperatorFields();
        }

        // Trigger autosave
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

// ============================================
// SECTION TOGGLES
// ============================================

function initializeSectionToggles() {
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

        const setState = (expanded) => {
            section.classList.toggle('expanded', expanded);
            content.style.display = expanded ? 'block' : 'none';
            toggle.setAttribute('aria-expanded', expanded.toString());
            toggle.setAttribute('title', expanded ? 'Collapse section' : 'Expand section');
        };

        // Initialize with existing expanded state, but collapse on mobile
        const isMobile = window.innerWidth <= 768;
        const shouldExpand = isMobile ? false : section.classList.contains('expanded');
        setState(shouldExpand);

        const handleToggle = (event) => {
            event.preventDefault();
            event.stopPropagation();
            setState(!section.classList.contains('expanded'));
        };

        toggle.addEventListener('click', handleToggle, { passive: false });
        toggle.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                handleToggle(event);
            }
        }, { passive: false });

        header.addEventListener('click', (event) => {
            if (event.target.closest('.section-toggle')) {
                return;
            }
            handleToggle(event);
        }, { passive: false });
    });

    // Initialize mobile section controls
    initializeMobileSectionControls();
}

function initializeMobileSectionControls() {
    // Only add controls if on mobile and not already added
    if (window.innerWidth > 768 || document.querySelector('.mobile-section-controls')) {
        return;
    }

    const controlsBar = document.createElement('div');
    controlsBar.className = 'mobile-section-controls';
    controlsBar.innerHTML = `
        <span class="section-control-label">📋 Sections</span>
        <div class="section-control-btns">
            <button class="btn-section-control" id="expand-all-sections">Expand All</button>
            <button class="btn-section-control" id="collapse-all-sections">Collapse All</button>
        </div>
    `;

    const facilityWrapper = document.getElementById('facility-main-wrapper');
    const referrerWrapper = document.getElementById('referrer-main-wrapper');

    if (facilityWrapper && facilityWrapper.offsetParent !== null) {
        facilityWrapper.insertBefore(controlsBar.cloneNode(true), facilityWrapper.firstChild);
    }
    if (referrerWrapper) {
        referrerWrapper.insertBefore(controlsBar.cloneNode(true), referrerWrapper.firstChild);
    }

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

// ============================================
// PAGE INITIALIZATION
// ============================================

let adminPageInitialized = false;

const initializeAdminPage = () => {
    if (adminPageInitialized) {
        return;
    }

    console.log('🚀 Initializing admin data page...');

    // Initialize facility toolbar toggle (defined in facility-form.v4.js)
    if (typeof window.initializeFacilityToolbarToggle === 'function') {
        window.initializeFacilityToolbarToggle();
    }

    // Initialize correct form visibility based on active tab
    const activeTab = document.querySelector('.category-tab.active');
    const activeCategory = activeTab ? activeTab.dataset.category : 'companies';
    const facilityMainWrapper = document.getElementById('facility-main-wrapper');
    const referrerMainWrapper = document.getElementById('referrer-main-wrapper');

    console.log('Active category:', activeCategory);

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

    // Refresh saved project panels
    if (typeof window.refreshSavedProjectPanels === 'function') {
        console.log('Calling refreshSavedProjectPanels');
        window.refreshSavedProjectPanels();

        // Retry after cloud data loads
        setTimeout(() => window.refreshSavedProjectPanels(), 2000);
        setTimeout(() => window.refreshSavedProjectPanels(), 5000);
    }

    // Initialize overview features
    if (typeof window.initializeConsultantsTocToggle === 'function') {
        window.initializeConsultantsTocToggle();
    }
    if (typeof window.initializeConsultantNavigation === 'function') {
        window.initializeConsultantNavigation();
    }
    if (typeof window.initializeLocationFacilitiesToc === 'function') {
        window.initializeLocationFacilitiesToc();
    }

    // Initialize save draft button
    const saveDraftBtn = document.getElementById('save-draft-locally-btn');
    const draftStatus = document.getElementById('draft-status');
    if (saveDraftBtn && !saveDraftBtn.dataset.draftHandlerAttached) {
        saveDraftBtn.addEventListener('click', function() {
            const projectNameInput = document.getElementById('project-name');
            let projectName = projectNameInput ? projectNameInput.value.trim() : '';

            if (!projectName) {
                if (window.currentProjectName) {
                    projectName = window.currentProjectName;
                } else {
                    projectName = prompt('Enter a name for this draft:');
                    if (!projectName || !projectName.trim()) {
                        if (typeof showUploadStatus === 'function') {
                            showUploadStatus('❌ Draft name is required', 'error');
                        }
                        return;
                    }
                    projectName = projectName.trim();
                    if (projectNameInput) {
                        projectNameInput.value = projectName;
                    }
                }
            }

            if (typeof window.persistProjectLocally === 'function') {
                const saved = window.persistProjectLocally(projectName, {
                    showStatus: true,
                    statusType: 'success',
                    statusMessage: `📋 Draft "${projectName}" saved locally! You can resume work anytime.`
                });

                if (saved) {
                    window.currentProjectName = projectName;

                    if (draftStatus) {
                        const now = new Date();
                        draftStatus.innerHTML = `✅ Draft saved: <strong>${projectName}</strong> at ${now.toLocaleTimeString()}`;
                        draftStatus.style.display = 'block';
                        draftStatus.style.color = '#059669';
                    }

                    if (typeof window.refreshSavedProjectPanels === 'function') {
                        window.refreshSavedProjectPanels();
                    }
                }
            } else {
                console.error('persistProjectLocally not available');
                if (typeof showUploadStatus === 'function') {
                    showUploadStatus('❌ Could not save draft - function not available', 'error');
                }
            }
        });
        saveDraftBtn.dataset.draftHandlerAttached = 'true';
    }

    adminPageInitialized = true;
    console.log('[Admin Data Page] Initialization complete');
};

const ensureAdminPageInitialization = () => {
    if (window.formReady) {
        initializeAdminPage();
    } else if (!adminPageInitialized) {
        document.addEventListener('formReady', initializeAdminPage, { once: true });
    }
};

// Kick off initialization
ensureAdminPageInitialization();

window.addEventListener('load', () => {
    initializeSectionToggles();
    ensureAdminPageInitialization();
});

// Initialize tab-switching for overviews
if (typeof window.initializeOverviewTabSwitching === 'function') {
    window.initializeOverviewTabSwitching();
}
