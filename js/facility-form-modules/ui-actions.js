(function() {
    if (window.KOP_UI_Actions) {
        return; // Already loaded
    }

    // Debounce flag to prevent double-clicking navigation buttons
    let isNavigating = false;

    function addNewArrayItem(path) {
        const { target, normalizedPath } = window.resolvePathTarget(path);
        if (!target) {
            return;
        }

        const array = window.getNestedValue(target, normalizedPath);
        let workingArray = array;

        // If the stored value is not an array, normalize it into one while preserving existing data
        if (!Array.isArray(workingArray)) {
            workingArray = [];
            if (array !== undefined && array !== null && array !== '') {
                workingArray.push(array);
            }
            window.setNestedValue(target, normalizedPath, workingArray);
        }

        if (Array.isArray(workingArray)) {
            const isPastTTIJobs = /pastTTIJobs$/.test(path);
            const isStaff = /^staff\./.test(path) || /^operator\.keyStaff\./.test(path);
            const isAdditionalLocation = /locationDetails\.additionalLocations$/.test(path);
            let newItem = '';
            if (isPastTTIJobs) {
                newItem = { role: '', organization: '', employer: '' };
            } else if (isStaff) {
                newItem = { role: '', name: '' };
            } else if (isAdditionalLocation) {
                newItem = { city: '', address: '' };
            }
            workingArray.push(newItem);
            if (path === 'identification.currentOwners') {
                const firstOwner = workingArray[0] || '';
                window.setNestedValue(target, 'identification.currentOwner', firstOwner || '');
            }
            const container = document.querySelector(`[data-path="${path}"]`);
            if (container && window.KOP_UI_Render) window.KOP_UI_Render.renderArray(container, path, workingArray);
            window.updateJSON();
            window.autoSave();
        }
    }

    function removeArrayItemAtIndex(path, index) {
        const { target, normalizedPath } = window.resolvePathTarget(path);
        if (!target) {
            return;
        }

        const array = window.getNestedValue(target, normalizedPath);
        if (Array.isArray(array) && index >= 0 && index < array.length) {
            array.splice(index, 1);
            if (path === 'identification.currentOwners') {
                const firstOwner = array[0] || '';
                window.setNestedValue(target, 'identification.currentOwner', firstOwner || '');
            }
            const container = document.querySelector(`[data-path="${path}"]`);
            if (container && window.KOP_UI_Render) window.KOP_UI_Render.renderArray(container, path, array);
            window.updateJSON();
            window.autoSave();
        }
    }

    function addFacility() {
        // Check which category view is currently active and visible
        const getActiveCategoryView = () => {
            const activeTab = document.querySelector('.category-tab.active');
            const rawCategory = activeTab ? activeTab.dataset.category : 'companies';
            const normalizedCategory = rawCategory === 'states' ? 'locations' : rawCategory;
            const contentId = normalizedCategory === 'locations' ? 'states-content' : `${normalizedCategory}-content`;
            const contentEl = document.getElementById(contentId);
            const isVisible = contentEl ? !contentEl.classList.contains('view-hidden') && !contentEl.classList.contains('d-none') : false;
            return { normalizedCategory, isVisible };
        };
        const { normalizedCategory: activeCategory, isVisible: isActiveContentVisible } = getActiveCategoryView();

        const newFacility = window.KOP_Project.createNewProjectData().facilities[0];

        // Only ask about ownership type in locations view
        const inLocationsView = activeCategory === 'locations' && isActiveContentVisible;
        if (inLocationsView) {
            const isPrivatelyOwned = confirm('Is this a privately owned facility (not part of a chain)?\n\nClick OK for Yes (privately owned)\nClick Cancel for No (part of a chain/corporate owned)');

            // Set the private ownership flag
            newFacility.isPrivatelyOwned = isPrivatelyOwned;
        }

        window.formData.facilities.push(newFacility);
        window.currentFacilityIndex = window.formData.facilities.length - 1;
        window.updateAllUI();

        // Update the private ownership toggle to match the choice (locations view only)
        if (inLocationsView) {
            const privateToggle = document.getElementById('private-ownership-toggle');
            if (privateToggle) {
                privateToggle.checked = newFacility.isPrivatelyOwned;
                // Trigger the toggle change event to update visibility
                const event = new Event('change', { bubbles: true });
                privateToggle.dispatchEvent(event);
            }
        }

        window.autoSave();

        // Dispatch custom event
        document.dispatchEvent(new CustomEvent('facilityChanged', {
            detail: { action: 'add', index: window.currentFacilityIndex }
        }));
    }

    function removeFacility() {
        if (window.formData.facilities.length > 1) {
            if (!confirm('Are you sure you want to remove this facility?')) return;
            window.formData.facilities.splice(window.currentFacilityIndex, 1);
            if (window.currentFacilityIndex >= window.formData.facilities.length) {
                window.currentFacilityIndex = window.formData.facilities.length - 1;
            }
            window.updateAllUI();
            window.autoSave();

            // Dispatch custom event
            document.dispatchEvent(new CustomEvent('facilityChanged', {
                detail: { action: 'remove', index: window.currentFacilityIndex }
            }));
        }
    }

    async function performClone(targetProjectName, targetCategory = null) {
        if (!targetProjectName) {
            alert('No target project specified for cloning.');
            return;
        }

        // IMPORTANT: Capture the facility to clone IMMEDIATELY using deep clone
        // This ensures we get exactly ONE facility, not a reference to the array
        const sourceIndex = window.currentFacilityIndex;
        const sourceFacility = window.formData.facilities[sourceIndex];

        if (!sourceFacility) {
            alert('No facility selected to clone.');
            return;
        }

        // Deep clone the single facility to ensure complete isolation from source
        const facilityToClone = JSON.parse(JSON.stringify(sourceFacility));

        // Give the clone a new name to avoid confusion
        if (facilityToClone.identification && facilityToClone.identification.name) {
            facilityToClone.identification.name = `${facilityToClone.identification.name} (Clone)`;
        }

        window.debugLog(`= Cloning facility "${sourceFacility.identification?.name || 'unnamed'}" (index ${sourceIndex}) to project "${targetProjectName}"`);
        window.debugLog(`   Source project "${window.currentProjectName}" has ${window.formData.facilities.length} facilities`);

        // Case 1: Clone to the current project
        if (targetProjectName === window.currentProjectName) {
            window.formData.facilities.push(facilityToClone);
            window.currentFacilityIndex = window.formData.facilities.length - 1;
            alert(` Facility cloned within project "${targetProjectName}".`);
            window.updateAllUI();
            window.autoSave();
            return;
        }

        // Case 2 & 3: Clone to a new or different existing project
        const isNewProject = !window.projects[targetProjectName];

        // Determine category: use provided category, or fall back to active tab
        let category = targetCategory;
        if (!category) {
            const activeTab = document.querySelector('.category-tab.active');
            category = activeTab ? activeTab.dataset.category : 'companies';
        }

        if (isNewProject) {
            // Create new project with ONLY the cloned facility, no operator data
            // Use JSON.parse/stringify to ensure completely fresh object with no shared references
            const newProjectData = JSON.parse(JSON.stringify(window.KOP_Project.createNewProjectData()));

            // Replace the default empty facility with ONLY our cloned facility
            newProjectData.facilities = [facilityToClone];

            // Store new project
            window.projects[targetProjectName] = {
                name: targetProjectName,
                data: newProjectData,
                currentFacilityIndex: 0,
                timestamp: new Date().toISOString(),
                category: category
            };

            alert(` New project "${targetProjectName}" created with cloned facility.`);
            if (window.KOP_API && window.KOP_API.saveProjectToCloud) {
                await window.KOP_API.saveProjectToCloud(targetProjectName);
            }
        } else {
            // Clone to existing project - load it first to get current data
            const targetProject = window.projects[targetProjectName];
            if (!targetProject) {
                alert(`Error: Project "${targetProjectName}" not found.`);
                return;
            }

            // Ensure facilities array exists
            if (!targetProject.data.facilities) {
                targetProject.data.facilities = [];
            }

            // Add the cloned facility
            targetProject.data.facilities.push(facilityToClone);
            targetProject.timestamp = new Date().toISOString();

            alert(` Facility cloned to project "${targetProjectName}".`);
            if (window.KOP_API && window.KOP_API.saveProjectToCloud) {
                await window.KOP_API.saveProjectToCloud(targetProjectName);
            }
        }

        if (typeof window.refreshSavedProjectPanels === 'function') {
            window.refreshSavedProjectPanels();
        }
    }

    function cloneFacility() {
        const modal = document.getElementById('clone-facility-modal');
        if (!modal) return;

        // --- Populate Modal ---
        const currentProjectNameSpan = document.getElementById('clone-current-project-name');
        if (currentProjectNameSpan) {
            currentProjectNameSpan.textContent = window.currentProjectName || 'New Project';
        }

        const existingProjectSelect = document.getElementById('existing-project-select');
        existingProjectSelect.innerHTML = '<option value="">Select a project...</option>'; // Clear previous
        const availableProjects = Object.keys(window.projects || {}).filter(name => name !== window.currentProjectName);
        availableProjects.sort().forEach(name => {
            const option = document.createElement('option');
            option.value = name;
            option.textContent = name;
            existingProjectSelect.appendChild(option);
        });

        // --- Show Modal ---
        modal.classList.add('active');

        // --- Event Handlers ---
        const confirmBtn = document.getElementById('clone-modal-confirm');
        const cancelBtn = document.getElementById('clone-modal-cancel');
        const closeBtn = document.getElementById('clone-modal-close');
        const radios = document.querySelectorAll('input[name="clone-destination"]');

        const existingProjectContainer = document.getElementById('existing-project-container');
        const newProjectContainer = document.getElementById('new-project-container');

        function handleRadioChange() {
            const selected = document.querySelector('input[name="clone-destination"]:checked').value;
            existingProjectContainer.style.display = selected === 'existing' ? 'block' : 'none';
            newProjectContainer.style.display = selected === 'new' ? 'block' : 'none';
        }

        radios.forEach(radio => radio.addEventListener('change', handleRadioChange, { passive: true }));
        handleRadioChange(); // Set initial state

        function closeModal() {
            modal.classList.remove('active');
        }

        // Remove old listeners by cloning nodes
        const newConfirmBtn = confirmBtn.cloneNode(true);
        const newCancelBtn = cancelBtn.cloneNode(true);
        const newCloseBtn = closeBtn.cloneNode(true);

        confirmBtn.replaceWith(newConfirmBtn);
        cancelBtn.replaceWith(newCancelBtn);
        closeBtn.replaceWith(newCloseBtn);

        newConfirmBtn.onclick = () => {
            const destination = document.querySelector('input[name="clone-destination"]:checked').value;
            let targetProjectName = '';
            let targetCategory = null;

            if (destination === 'current') {
                targetProjectName = window.currentProjectName;
            } else if (destination === 'existing') {
                targetProjectName = document.getElementById('existing-project-select').value;
            } else if (destination === 'new') {
                targetProjectName = document.getElementById('new-project-name-input').value.trim();
                // Get the selected category for new projects
                const categorySelect = document.getElementById('new-project-category-select');
                if (categorySelect) {
                    targetCategory = categorySelect.value;
                }
            }

            if (!targetProjectName) {
                alert('Please select or enter a valid project name.');
                return;
            }

            if (destination === 'new' && window.projects[targetProjectName]) {
                alert(`A project named "${targetProjectName}" already exists. Please choose a different name or select it from the 'existing' list.`);
                return;
            }

            performClone(targetProjectName, targetCategory);
            closeModal();
        };

        newCancelBtn.onclick = closeModal;
        newCloseBtn.onclick = closeModal;
    }

    function sortFacilities() {
        if (window.formData.facilities.length <= 1) return;
        const currentName = window.formData.facilities[window.currentFacilityIndex].identification.name;
        window.formData.facilities.sort((a, b) => (a.identification.name || '').localeCompare(b.identification.name || ''));
        const newIndex = window.formData.facilities.findIndex(f => f.identification.name === currentName);
        window.currentFacilityIndex = newIndex !== -1 ? newIndex : 0;
        window.updateAllUI();
        window.autoSave();
    }

    function previousFacility() {
        if (isNavigating) return;
        if (window.currentFacilityIndex > 0) {
            isNavigating = true;
            window.currentFacilityIndex--;
            if (typeof window.loadFacilityData === 'function') window.loadFacilityData();
            if (window.KOP_UI_Render) {
                window.KOP_UI_Render.updateFacilityControls();
                window.KOP_UI_Render.updateTableOfContents();
                window.KOP_UI_Render.scrollToFormInput();
            }
            setTimeout(() => { isNavigating = false; }, 100);
        }
    }

    function nextFacility() {
        if (isNavigating) return;
        if (window.currentFacilityIndex < window.formData.facilities.length - 1) {
            isNavigating = true;
            window.currentFacilityIndex++;
            if (typeof window.loadFacilityData === 'function') window.loadFacilityData();
            if (window.KOP_UI_Render) {
                window.KOP_UI_Render.updateFacilityControls();
                window.KOP_UI_Render.updateTableOfContents();
                window.KOP_UI_Render.scrollToFormInput();
            }
            setTimeout(() => { isNavigating = false; }, 100);
        }
    }

    // Expose public API
    window.KOP_UI_Actions = {
        addNewArrayItem,
        removeArrayItemAtIndex,
        addFacility,
        removeFacility,
        performClone,
        cloneFacility,
        sortFacilities,
        previousFacility,
        nextFacility
    };
})();
