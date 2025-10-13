// ============================================
// SHARED APPLICATION LOGIC
// ============================================

// ============================================
// COLLAPSIBLE SECTIONS
// ============================================
function initializeCollapsibleSections() {
    document.querySelectorAll('.section-header').forEach(header => {
        // Check if a listener is already attached to avoid duplicates
        if (header.dataset.listenerAttached) return;

        header.addEventListener('click', () => {
            const section = header.closest('.section');
            if (section) {
                section.classList.toggle('expanded');
            }
        });

        // Mark as having a listener
        header.dataset.listenerAttached = 'true';
    });
}
window.initializeCollapsibleSections = initializeCollapsibleSections;


// ============================================
// DATA ORGANIZER FUNCTIONALITY
// ============================================
function initializeDataOrganizer() {
    const showOrganizerBtn = document.getElementById('show-organizer-btn');
    const organizerSection = document.getElementById('data-organizer-section');
    const organizeBySelect = document.getElementById('organize-by');
    const organizeValueGroup = document.getElementById('organize-value-group');
    const organizeValueInput = document.getElementById('organize-value');
    const organizeSearchBtn = document.getElementById('organize-search-btn');
    const organizeClearBtn = document.getElementById('organize-clear-btn');
    const organizeResults = document.getElementById('organize-results');
    const organizeResultsTitle = document.getElementById('organize-results-title');
    const organizeResultsCount = document.getElementById('organize-results-count');
    const organizeMatches = document.getElementById('organize-matches');

    if (!showOrganizerBtn) return; // Exit if organizer elements aren't on the page

    let organizerVisible = true;

    // Set initial state - organizer visible by default
    organizerSection.style.display = 'block';
    showOrganizerBtn.textContent = '📊 Hide Organizer';

    // Toggle organizer visibility
    showOrganizerBtn.addEventListener('click', () => {
        organizerVisible = !organizerVisible;
        organizerSection.style.display = organizerVisible ? 'block' : 'none';
        showOrganizerBtn.textContent = organizerVisible ? '📊 Hide Organizer' : '📊 Data Organizer';

        if (organizerVisible) {
            organizerSection.scrollIntoView({ behavior: 'smooth' });
        }
    });

    // Handle organize by selection
    organizeBySelect.addEventListener('change', () => {
        const value = organizeBySelect.value;
        if (value) {
            organizeValueGroup.style.display = 'block';
            organizeSearchBtn.style.display = 'inline-block';
            organizeValueInput.focus();
        } else {
            organizeValueGroup.style.display = 'none';
            organizeSearchBtn.style.display = 'none';
            clearOrganizerResults();
        }
    });

    // Handle search
    organizeSearchBtn.addEventListener('click', performOrganizedSearch);
    organizeValueInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') performOrganizedSearch();
    });

    // Handle clear
    organizeClearBtn.addEventListener('click', clearOrganizerResults);

    function performOrganizedSearch() {
        const searchType = organizeBySelect.value;
        const searchValue = organizeValueInput.value.trim();

        if (!searchType || !searchValue) {
            console.log('Please select a data point type and enter a search value.');
            return;
        }

        if ((!window.formData || !window.formData.facilities || window.formData.facilities.length === 0) &&
            (!window.projects || Object.keys(window.projects).length === 0)) {
            console.log('No data available. Please load or create some facility data first.');
            return;
        }

        const results = [];
        const uniqueFacilities = new Map();

        // Search through current formData facilities
        if (window.formData && window.formData.facilities) {
            window.formData.facilities.forEach((facility, facilityIndex) => {
                const matches = extractDataPointsForSearch(facility, searchType, searchValue);
                if (matches.length > 0) {
                    const facilityId = `${window.currentProjectName}-${facilityIndex}`;
                    if (!uniqueFacilities.has(facilityId)) {
                        uniqueFacilities.set(facilityId, {
                            projectName: window.currentProjectName || 'Current Project',
                            facility: facility,
                            facilityIndex: facilityIndex,
                            matches: matches
                        });
                    }
                }
            });
        }

        // Search through all saved projects
        if (window.projects) {
            Object.keys(window.projects).forEach(projectName => {
                const project = window.projects[projectName];
                if (project && project.data && project.data.facilities) {
                    project.data.facilities.forEach((facility, facilityIndex) => {
                        const matches = extractDataPointsForSearch(facility, searchType, searchValue);
                        if (matches.length > 0) {
                            const facilityId = `${projectName}-${facilityIndex}`;
                             if (!uniqueFacilities.has(facilityId)) {
                                uniqueFacilities.set(facilityId, {
                                    projectName: projectName,
                                    facility: facility,
                                    facilityIndex: facilityIndex,
                                    matches: matches
                                });
                            }
                        }
                    });
                }
            });
        }

        displayOrganizerResults(Array.from(uniqueFacilities.values()), searchType, searchValue);
    }

    function extractDataPointsForSearch(facility, type, searchValue) {
        const matches = [];
        const searchLower = searchValue.toLowerCase();

        switch (type) {
            case 'staff':
                if (facility.staff) {
                    if (facility.staff.administrator) {
                        facility.staff.administrator.forEach(admin => {
                            const adminStr = typeof admin === 'string' ? admin : (admin.name || '');
                            if (adminStr.toLowerCase().includes(searchLower)) {
                                matches.push(`Administrator: ${adminStr}`);
                            }
                        });
                    }
                    if (facility.staff.notableStaff) {
                        facility.staff.notableStaff.forEach(staff => {
                            const staffStr = typeof staff === 'string' ? staff : (staff.name || '');
                            if (staffStr.toLowerCase().includes(searchLower)) {
                                matches.push(`Notable Staff: ${staffStr}`);
                            }
                        });
                    }
                }
                break;

            case 'operator':
                if (facility.identification?.currentOperator &&
                    facility.identification.currentOperator.toLowerCase().includes(searchLower)) {
                    matches.push(facility.identification.currentOperator);
                }
                if (facility.otherOperators) {
                    facility.otherOperators.forEach(op => {
                        if (op.toLowerCase().includes(searchLower)) {
                            matches.push(op);
                        }
                    });
                }
                break;

            case 'location':
                if (facility.location && facility.location.toLowerCase().includes(searchLower)) {
                    matches.push(facility.location);
                }
                break;

            case 'programType':
                if (facility.facilityDetails?.type &&
                    facility.facilityDetails.type.toLowerCase().includes(searchLower)) {
                    matches.push(facility.facilityDetails.type);
                }
                break;

            case 'status':
                if (facility.operatingPeriod?.status &&
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
                    if (facility.accreditations.current) {
                        facility.accreditations.current.forEach(acc => {
                            if (acc.toLowerCase().includes(searchLower)) {
                                matches.push(`Current: ${acc}`);
                            }
                        });
                    }
                    if (facility.accreditations.past) {
                        facility.accreditations.past.forEach(acc => {
                            if (acc.toLowerCase().includes(searchLower)) {
                                matches.push(`Past: ${acc}`);
                            }
                        });
                    }
                }
                break;

            case 'certification':
                if (facility.certifications) {
                    facility.certifications.forEach(cert => {
                        if (cert.toLowerCase().includes(searchLower)) {
                            matches.push(cert);
                        }
                    });
                }
                break;
        }

        return matches;
    }

    function displayOrganizerResults(results, searchType, searchValue) {
        const searchTypeLabel = organizeBySelect.options[organizeBySelect.selectedIndex].text;

        organizeResultsTitle.textContent = `Facilities with ${searchTypeLabel}: "${searchValue}"`;
        organizeResultsCount.textContent = `Found ${results.length} facilities`;

        if (results.length === 0) {
            organizeMatches.innerHTML = '<div style="padding: 20px; text-align: center; color: #6b7280;">No matches found</div>';
        } else {
            let html = '';
            results.forEach(result => {
                const facilityName = result.facility.identification?.name || 'Unnamed Facility';
                const operator = result.facility.identification?.currentOperator || 'Unknown Operator';
                const location = result.facility.location || 'Unknown Location';

                html += `
                    <div style="border-bottom: 1px solid #e2e8f0; padding: 15px; cursor: pointer; transition: background-color 0.2s;"
                         onmouseover="this.style.backgroundColor='#f8fafc'"
                         onmouseout="this.style.backgroundColor='white'"
                         onclick="goToFacility('${result.projectName}', ${result.facilityIndex})">
                        <div style="font-weight: 600; color: #1f2937; margin-bottom: 5px;">${escapeHtmlForAttr(facilityName)}</div>
                        <div style="font-size: 14px; color: #6b7280; margin-bottom: 8px;">
                            ${escapeHtmlForAttr(operator)} • ${escapeHtmlForAttr(location)}
                        </div>
                        <div style="font-size: 13px;">
                            <strong>Matches:</strong>
                            ${result.matches.map(match => `<span style="background: #fef3c7; padding: 2px 6px; border-radius: 3px; margin-right: 5px; color: #92400e;">${escapeHtmlForAttr(match)}</span>`).join('')}
                        </div>
                        <div style="font-size: 12px; color: #9ca3af; margin-top: 5px;">
                            Project: ${escapeHtmlForAttr(result.projectName)} • Facility #${result.facilityIndex + 1}
                        </div>
                    </div>
                `;
            });
            organizeMatches.innerHTML = html;
        }

        organizeResults.style.display = 'block';
        organizeClearBtn.style.display = 'inline-block';
    }

    function clearOrganizerResults() {
        organizeResults.style.display = 'none';
        organizeClearBtn.style.display = 'none';
        organizeValueInput.value = '';
    }

    // Function to navigate to a specific facility
    window.goToFacility = function(projectName, facilityIndex) {
        if (typeof loadProjectAndSync !== 'function') {
            console.error("loadProjectAndSync is not available to switch projects.");
            return;
        }

        // Switch to the project if it's different
        if (window.currentProjectName !== projectName) {
            loadProjectAndSync(projectName);
        }

        // Wait for project to load, then switch facility
        setTimeout(() => {
            if (typeof window.updateUI === 'function') {
                window.currentFacilityIndex = facilityIndex;
                window.updateUI();
            }

            // Hide organizer and scroll to top
            organizerVisible = false;
            organizerSection.style.display = 'none';
            showOrganizerBtn.textContent = '📊 Data Organizer';

            window.scrollTo({ top: 0, behavior: 'smooth' });

            console.log(`Navigated to ${projectName} - Facility #${facilityIndex + 1}`);
        }, 500);
    };
}

// ============================================
// FIELD NOTES FUNCTIONALITY
// ============================================
function initializeFieldNotes() {
    console.log('Note buttons script starting...');

    let allFacilityNotes = {};
    let notesCurrentFacilityIndex = 0;

    function getCurrentFacilityNotes() {
        if (!allFacilityNotes[notesCurrentFacilityIndex]) {
            allFacilityNotes[notesCurrentFacilityIndex] = {};
        }
        return allFacilityNotes[notesCurrentFacilityIndex];
    }

    function updateCurrentFacility() {
        if (typeof window.currentFacilityIndex !== 'undefined') {
            notesCurrentFacilityIndex = window.currentFacilityIndex;
        }
        setTimeout(addNoteButtons, 100);
    }

    function loadFieldNotes() {
        try {
            updateCurrentFacility();
            if (typeof window.getCurrentFacilityData === 'function') {
                const facilityData = window.getCurrentFacilityData();
                if (facilityData && facilityData.fieldNotes) {
                    allFacilityNotes[notesCurrentFacilityIndex] = facilityData.fieldNotes;
                    return;
                }
            }
            const stored = localStorage.getItem('fieldNotes');
            if (stored) {
                allFacilityNotes = JSON.parse(stored);
            }
        } catch (e) {
            console.warn('Failed to load field notes:', e);
            allFacilityNotes = {};
        }
    }

    function saveFieldNotes() {
        try {
            const currentNotes = getCurrentFacilityNotes();
            if (typeof window.updateCurrentFacilityData === 'function') {
                window.updateCurrentFacilityData({ fieldNotes: currentNotes });
            }
            localStorage.setItem('fieldNotes', JSON.stringify(allFacilityNotes));
            if (typeof window.autoSave === 'function') window.autoSave();
        } catch (e) {
            console.warn('Failed to save field notes:', e);
            localStorage.setItem('fieldNotes', JSON.stringify(allFacilityNotes));
        }
    }

    function getFieldIdentifier(element) {
        if (element.dataset.noteId) return element.dataset.noteId;
        const uniqueId = `field-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
        element.dataset.noteId = uniqueId;
        return uniqueId;
    }

    function runWithFacilityObserverPaused(work) {
        const pause = window.pauseFacilityFormObserver;
        const resume = window.resumeFacilityFormObserver;
        let paused = false;

        if (typeof pause === 'function' && typeof resume === 'function') {
            pause();
            paused = true;
        }

        try {
            return work();
        } finally {
            if (paused) {
                resume();
            }
        }
    }

    function addNoteButtonsToArrayItems(group) {
        runWithFacilityObserverPaused(() => {
            try {
                group.querySelectorAll('.array-item').forEach(arrayItem => {
                    if (arrayItem.querySelector('.field-note-btn') || arrayItem.children.length === 0) return;
                    const field = arrayItem.querySelector('input, textarea, select');
                    if (!field || field.type === 'hidden' || field.style.display === 'none') return;

                    const noteBtn = document.createElement('button');
                    noteBtn.type = 'button';
                    noteBtn.className = 'field-note-btn';
                    noteBtn.innerHTML = '+';
                    noteBtn.title = 'Add note for this field';

                    const fieldIdentifier = getFieldIdentifier(field);
                    const currentNotes = getCurrentFacilityNotes();
                    if (currentNotes[fieldIdentifier]) {
                        noteBtn.classList.add('has-note');
                        noteBtn.title = 'Edit note for this field';
                    }

                    noteBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        addInlineNote(field, group);
                    });

                    restructureArrayItem(arrayItem, field, noteBtn);
                });
            } catch (error) {
                console.warn('Error adding note buttons to array items:', error);
            }
        });
    }

    function restructureArrayItem(arrayItem, field, noteBtn) {
        runWithFacilityObserverPaused(() => {
            try {
                if (arrayItem.querySelector('.array-item-top')) {
                    arrayItem.querySelector('.array-item-top').appendChild(noteBtn);
                    return;
                }

                const removeBtn = arrayItem.querySelector('.btn, button[type="button"]:not(.field-note-btn)');
                const topSection = document.createElement('div');
                topSection.className = 'array-item-top';
                const bottomSection = document.createElement('div');
                bottomSection.className = 'array-item-bottom';

                Array.from(arrayItem.children).forEach(child => {
                    if (child === removeBtn) bottomSection.appendChild(child);
                    else topSection.appendChild(child);
                });

                topSection.appendChild(noteBtn);
                arrayItem.innerHTML = '';
                arrayItem.appendChild(topSection);
                if (removeBtn) arrayItem.appendChild(bottomSection);
            } catch (error) {
                console.warn('Error restructuring array item:', error);
                arrayItem.appendChild(noteBtn);
            }
        });
    }

    function addNoteButtons() {
        runWithFacilityObserverPaused(() => {
            document.querySelectorAll('.form-group').forEach(group => {
                if (group.querySelector('.field-note-btn')) return;

                addNoteButtonsToArrayItems(group);

                let field = group.querySelector('input:not([type="hidden"]):not([style*="display: none"]), textarea:not([style*="display: none"]), select:not([style*="display: none"])');
                if (field && field.closest('.array-item')) return;
                if (!field) field = group.querySelector('input, textarea, select');
                if (!field || field.type === 'hidden' || field.style.display === 'none') return;

                const skipFieldIds = ['project-name', 'organize-by', 'organize-value'];
                if (skipFieldIds.includes(field.id || '')) return;
                if (field.closest('.project-management') || field.closest('#data-organizer-section')) return;

                const noteBtn = document.createElement('button');
                noteBtn.type = 'button';
                noteBtn.className = 'field-note-btn';
                noteBtn.innerHTML = '+';
                noteBtn.title = 'Add note for this field';

                const fieldIdentifier = getFieldIdentifier(field);
                const currentNotes = getCurrentFacilityNotes();
                if (currentNotes[fieldIdentifier]) {
                    noteBtn.classList.add('has-note');
                    noteBtn.title = 'Edit note for this field';
                }

                noteBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    addInlineNote(field, group);
                });

                const arrayItem = field.closest('.array-item');
                if (arrayItem) {
                    arrayItem.appendChild(noteBtn);
                } else {
                    const innerContainer = field.closest('.autocomplete-wrapper');
                    if (innerContainer) {
                        innerContainer.style.display = 'flex';
                        innerContainer.style.alignItems = 'flex-start';
                        field.style.flex = '1';
                        innerContainer.appendChild(noteBtn);
                    } else {
                        let fieldContent = group.querySelector('.field-content');
                        if (!fieldContent) {
                            fieldContent = document.createElement('div');
                            fieldContent.className = 'field-content';
                            const label = group.querySelector('label');
                            Array.from(group.children).forEach(child => {
                                if (child !== label) fieldContent.appendChild(child);
                            });
                            group.appendChild(fieldContent);
                        }
                        fieldContent.appendChild(noteBtn);
                    }
                }
                group.classList.add('has-note-button');
            });
        });
    }

    function addInlineNote(field, group) {
        runWithFacilityObserverPaused(() => {
            const fieldId = getFieldIdentifier(field);
            const label = group.querySelector('label');
            const fieldName = label ? label.textContent.trim() : 'Field';

            const noteContainer = document.createElement('div');
            noteContainer.className = 'note-container';
            noteContainer.style.cssText = 'margin-top: 8px; padding: 8px; border: 1px solid #e5e7eb; border-radius: 4px; background-color: #f9fafb;';

            const noteHeader = document.createElement('div');
            noteHeader.style.cssText = 'display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;';

            const noteLabel = document.createElement('label');
            noteLabel.textContent = `${fieldName} Note`;
            noteLabel.style.cssText = 'font-size: 13px; color: #6b7280; margin: 0;';

            const removeNoteBtn = document.createElement('button');
            removeNoteBtn.type = 'button';
            removeNoteBtn.innerHTML = '×';
            removeNoteBtn.title = 'Remove this note';
            removeNoteBtn.style.cssText = 'background: none; border: none; color: #9ca3af; cursor: pointer; font-size: 16px; padding: 0; width: 20px; height: 20px;';

            const noteInput = document.createElement('input');
            noteInput.type = 'text';
            noteInput.className = 'note-input';
            noteInput.placeholder = 'Add notes or context...';
            noteInput.style.cssText = 'font-size: 14px; padding: 8px 12px; width: 100%; border: 1px solid #d1d5db; border-radius: 4px;';

            const noteId = `${fieldId}_note_${Date.now()}`;

            noteInput.addEventListener('input', () => {
                runWithFacilityObserverPaused(() => {
                    const noteText = noteInput.value.trim();
                    const currentNotes = getCurrentFacilityNotes();

                    if (!Array.isArray(currentNotes[fieldId])) {
                        currentNotes[fieldId] = currentNotes[fieldId] ? [{ id: `${fieldId}_note_legacy`, text: currentNotes[fieldId], timestamp: new Date().toISOString() }] : [];
                    }

                    const existingNoteIndex = currentNotes[fieldId].findIndex(note => note.id === noteId);
                    if (noteText) {
                        const noteData = { id: noteId, text: noteText, timestamp: new Date().toISOString() };
                        if (existingNoteIndex >= 0) currentNotes[fieldId][existingNoteIndex] = noteData;
                        else currentNotes[fieldId].push(noteData);
                    } else if (existingNoteIndex >= 0) {
                        currentNotes[fieldId].splice(existingNoteIndex, 1);
                        if (currentNotes[fieldId].length === 0) delete currentNotes[fieldId];
                    }

                    saveFieldNotes();
                    updateNoteButton(field);
                    document.dispatchEvent(new CustomEvent('facilityDataChanged', { detail: { type: 'fieldNote', fieldId, value: currentNotes[fieldId] } }));
                });
            });

            removeNoteBtn.addEventListener('click', (e) => {
                e.preventDefault();
                runWithFacilityObserverPaused(() => {
                    const currentNotes = getCurrentFacilityNotes();
                    if (currentNotes[fieldId]) {
                        if (Array.isArray(currentNotes[fieldId])) {
                            const noteIndex = currentNotes[fieldId].findIndex(note => note.id === noteId);
                            if (noteIndex >= 0) currentNotes[fieldId].splice(noteIndex, 1);
                            if (currentNotes[fieldId].length === 0) delete currentNotes[fieldId];
                        } else {
                            delete currentNotes[fieldId];
                        }
                    }
                    saveFieldNotes();
                    updateNoteButton(field);
                    noteContainer.remove();
                });
            });

            noteHeader.appendChild(noteLabel);
            noteHeader.appendChild(removeNoteBtn);
            noteContainer.appendChild(noteHeader);
            noteContainer.appendChild(noteInput);

            const fieldParent = field.closest('.array-item') || field.parentElement;
            const existingNotes = fieldParent.querySelectorAll('.note-container');
            if (existingNotes.length > 0) {
                existingNotes[existingNotes.length - 1].insertAdjacentElement('afterend', noteContainer);
            } else {
                field.insertAdjacentElement('afterend', noteContainer);
            }

            noteInput.focus();
            updateNoteButton(field);
        });
    }

    function updateNoteButton(field) {
        runWithFacilityObserverPaused(() => {
            const fieldId = getFieldIdentifier(field);
            const formGroup = field.closest('.form-group');
            const noteBtn = formGroup?.querySelector('.field-note-btn');

            if (noteBtn) {
                const currentNotes = getCurrentFacilityNotes();
                const notes = currentNotes[fieldId];
                const hasNotes = notes && ( (Array.isArray(notes) && notes.length > 0) || (typeof notes === 'string' && notes.trim() !== '') );
                noteBtn.classList.toggle('has-note', hasNotes);
                noteBtn.title = hasNotes ? 'Has notes - Click to add another' : 'Add note for this field';
            }
        });
    }

    loadFieldNotes();
    addNoteButtons();

    setTimeout(() => {
        const currentNotes = getCurrentFacilityNotes();
        Object.keys(currentNotes).forEach(fieldId => {
            const field = document.querySelector(`[data-note-id="${fieldId}"]`);
            if (field) {
                const group = field.closest('.form-group');
                if (group && !group.querySelector('.note-container')) {
                    addInlineNote(field, group);
                }
            }
        });
    }, 500);

    const observerTarget = document.getElementById('facility-form-app') ||
        document.querySelector('[data-facility-form-root]') ||
        document.body;

    const observer = new MutationObserver((mutations) => {
        const hasMeaningfulChange = mutations.some((mutation) => {
            if (mutation.type === 'attributes') return true;
            return (mutation.addedNodes && mutation.addedNodes.length > 0) ||
                (mutation.removedNodes && mutation.removedNodes.length > 0);
        });

        if (hasMeaningfulChange) {
            setTimeout(addNoteButtons, 100);
        }
    });
    observer.observe(observerTarget, { childList: true, subtree: true, attributes: true, attributeFilter: ['class'] });

    window.syncFieldNotes = function(facilityData) {
        if (facilityData && facilityData.fieldNotes) {
            const currentNotes = getCurrentFacilityNotes();
            Object.keys(facilityData.fieldNotes).forEach(fieldId => {
                const noteValue = facilityData.fieldNotes[fieldId];
                if (typeof noteValue === 'string' && noteValue.trim()) {
                    currentNotes[fieldId] = [{ id: `${fieldId}_note_legacy`, text: noteValue, timestamp: new Date().toISOString() }];
                } else if (Array.isArray(noteValue)) {
                    currentNotes[fieldId] = noteValue;
                }
            });
            Object.keys(currentNotes).forEach(fieldId => {
                const field = document.querySelector(`[data-note-id="${fieldId}"]`);
                if (field) updateNoteButton(field);
            });
            saveFieldNotes();
        }
    };

    window.getFieldNotes = getCurrentFacilityNotes;
    window.addNoteButtons = addNoteButtons;

    document.addEventListener('facilityChanged', updateCurrentFacility);
    document.addEventListener('arrayItemAdded', (event) => {
        setTimeout(() => {
            if (event.detail && event.detail.itemDiv) {
                const formGroup = event.detail.itemDiv.closest('.form-group');
                if (formGroup) addNoteButtonsToArrayItems(formGroup);
            }
            addNoteButtons();
        }, 100);
    });
}

// ============================================
// CATEGORY NAVIGATION (COMPANIES vs LOCATIONS)
// ============================================
function initializeCategoryNavigation() {
    const facilitiesTab = document.getElementById('facilities-tab');
    const statesTab = document.getElementById('states-tab');
    const addLocationBtn = document.getElementById('add-location-project-btn');
    const locationInput = document.getElementById('new-location-project-input');
    const addOperatorBtn = document.getElementById('add-operator-btn');
    const newOperatorInput = document.getElementById('new-operator-input');

    if (!facilitiesTab) return; // Exit if category elements aren't on the page

    facilitiesTab.addEventListener('click', () => switchCategory('facilities'));
    statesTab.addEventListener('click', () => switchCategory('states'));

    if (addLocationBtn && locationInput) {
        addLocationBtn.addEventListener('click', () => {
            const projectName = locationInput.value.trim();
            if (projectName) {
                createNewLocationProject(projectName);
                locationInput.value = '';
            } else {
                alert('Please enter a location project name');
            }
        });
        locationInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') addLocationBtn.click();
        });
    }

    if (addOperatorBtn && newOperatorInput) {
        addOperatorBtn.addEventListener('click', function() {
            const operatorName = newOperatorInput.value.trim();
            if (operatorName) {
                const currentOperatorField = document.querySelector('input[data-field="identification.currentOperator"]');
                if (currentOperatorField) {
                    currentOperatorField.value = operatorName;
                    currentOperatorField.dispatchEvent(new Event('input', { bubbles: true }));
                }
                newOperatorInput.value = '';
                renderFilteredProjectsList('saved-projects-list', 'companies');
            }
        });
        newOperatorInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') addOperatorBtn.click();
        });
    }

    // Initial render
    switchCategory('facilities');
}

function switchCategory(category) {
    document.querySelectorAll('.category-tab').forEach(tab => tab.classList.remove('active'));
    document.getElementById(`${category}-tab`).classList.add('active');

    document.getElementById('companies-content').style.display = category === 'facilities' ? 'block' : 'none';
    document.getElementById('states-content').style.display = category === 'states' ? 'block' : 'none';

    const containerId = category === 'facilities' ? 'saved-projects-list' : 'locations-list';
    const filterType = category === 'facilities' ? 'companies' : 'locations';
    setTimeout(() => renderFilteredProjectsList(containerId, filterType), 100);

    // Handle private ownership toggle visibility
    const privateOwnershipToggle = document.getElementById('private-ownership-toggle-section');
    if (privateOwnershipToggle) {
        privateOwnershipToggle.style.display = category === 'states' ? 'block' : 'none';
        if (category === 'facilities') {
            const operatorSection = document.getElementById('operator-section');
            if (operatorSection) operatorSection.style.display = 'block';
        }
    }
}

function renderFilteredProjectsList(containerId, filterType) {
    const container = document.getElementById(containerId);
    if (!container) return;

    const projectList = window.projects || {};
    const projectNames = Object.keys(projectList);

    const usStates = ['alabama', 'alaska', 'arizona', 'arkansas', 'california', 'colorado', 'connecticut', 'delaware', 'florida', 'georgia', 'hawaii', 'idaho', 'illinois', 'indiana', 'iowa', 'kansas', 'kentucky', 'louisiana', 'maine', 'maryland', 'massachusetts', 'michigan', 'minnesota', 'mississippi', 'missouri', 'montana', 'nebraska', 'nevada', 'new hampshire', 'new jersey', 'new mexico', 'new york', 'north carolina', 'north dakota', 'ohio', 'oklahoma', 'oregon', 'pennsylvania', 'rhode island', 'south carolina', 'south dakota', 'tennessee', 'texas', 'utah', 'vermont', 'virginia', 'washington', 'west virginia', 'wisconsin', 'wyoming'];
    const countries = ['canada', 'mexico', 'united kingdom', 'france', 'germany', 'italy', 'spain', 'russia', 'china', 'japan', 'australia', 'brazil', 'argentina', 'india', 'south africa', 'nigeria', 'egypt', 'saudi arabia', 'iran', 'iraq', 'norway', 'sweden', 'denmark', 'netherlands', 'belgium', 'switzerland', 'austria', 'poland', 'ukraine', 'turkey'];

    const filteredNames = projectNames.filter(name => {
        const lowerName = name.toLowerCase().trim();
        const isLocation = usStates.includes(lowerName) || countries.includes(lowerName);
        return filterType === 'locations' ? isLocation : !isLocation;
    });

    if (filteredNames.length === 0) {
        const emptyMessage = filterType === 'locations' ?
            'No state/country projects found' :
            'No company projects found';
        container.innerHTML = `<div class="projects-empty">📭 ${emptyMessage}</div>`;
        return;
    }

    filteredNames.sort((a, b) => (projectList[b].timestamp || '').localeCompare(projectList[a].timestamp || ''));

    container.innerHTML = filteredNames.map(name => {
        const project = projectList[name];
        const date = new Date(project.timestamp || 0);
        const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const facilityCount = project.data?.facilities?.length || 0;

        return `<div class="project-item" onclick="loadProjectAndSync('${escapeHtmlForAttr(name)}')">
                    <div class="project-item-name" title="Click to load project">${escapeHtmlForAttr(name)}</div>
                    <div class="project-item-date">${dateStr}<br><small>${facilityCount} facilities</small></div>
                    <div class="project-item-actions">
                        <button class="project-item-btn project-item-load" onclick="event.stopPropagation(); loadProjectAndSync('${escapeHtmlForAttr(name)}')">📂 Load</button>
                    </div>
                </div>`;
    }).join('');
}

function createNewLocationProject(projectName) {
    if (window.projectManager && window.projectManager.saveProject) {
        try {
            window.projectManager.saveProject(projectName);
            setTimeout(() => renderFilteredProjectsList('locations-list', 'locations'), 500);
            alert(`Location project "${projectName}" created successfully!`);
        } catch (error) {
            console.error('Error creating project:', error);
            alert('Error creating project. Please try again.');
        }
    } else {
        alert('Unable to create project. Project manager not available.');
    }
}

function createPrivateOwnershipToggle() {
    const section = document.createElement('div');
    section.id = 'private-ownership-toggle-section';
    section.style.cssText = 'background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin-bottom: 20px; display: none;';

    section.innerHTML = `
        <div style="display: flex; align-items: center; gap: 15px; font-weight: 600; color: #1f2937;">
            <span>Privately Owned Facility:</span>
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
            Select "Yes" for independently owned facilities to hide the operator section.
        </p>
    `;

    const toggle = section.querySelector('#private-ownership-toggle');
    const sliderTrack = section.querySelector('#slider-track');
    const sliderKnob = section.querySelector('#slider-knob');

    const updateSliderAppearance = () => {
        if (toggle.checked) {
            sliderTrack.style.backgroundColor = '#10b981';
            sliderKnob.style.transform = 'translateX(24px)';
        } else {
            sliderTrack.style.backgroundColor = '#e5e7eb';
            sliderKnob.style.transform = 'translateX(0px)';
        }
    };

    sliderTrack.addEventListener('click', function() {
        toggle.checked = !toggle.checked;
        updateSliderAppearance();

        const operatorSection = document.getElementById('operator-section');
        if (operatorSection) {
            operatorSection.style.display = toggle.checked ? 'none' : 'block';
        }
    });

    return section;
}

// ============================================
// INITIALIZATION
// ============================================
window.addEventListener('DOMContentLoaded', () => {
    console.log('app-logic.js DOMContentLoaded fired');

    // Initialize all shared components directly
    initializeCollapsibleSections();
    initializeDataOrganizer();
    initializeFieldNotes();
    initializeCategoryNavigation();

    // Insert the private ownership toggle if it doesn't exist
    if (!document.getElementById('private-ownership-toggle-section')) {
        const toggleSection = createPrivateOwnershipToggle();
        const categoryNav = document.getElementById('category-navigation');
        if (categoryNav) {
            categoryNav.parentNode.insertBefore(toggleSection, categoryNav.nextSibling);
        }
    }
});

// Defensive re-checks: some environments (slow network, deferred script loading) cause
// DOM initialization to miss or render incorrectly. Retry a few times after load.
function runDefensiveInitChecks() {
    try {
        console.log('app-logic.js: running defensive init checks');
        if (typeof initializeCollapsibleSections === 'function') initializeCollapsibleSections();
        if (typeof initializeDataOrganizer === 'function') initializeDataOrganizer();
        if (typeof initializeFieldNotes === 'function') initializeFieldNotes();
        if (typeof initializeCategoryNavigation === 'function') initializeCategoryNavigation();
        // Ensure note buttons are present
        if (typeof window.addNoteButtons === 'function') window.addNoteButtons();
    } catch (e) {
        console.error('app-logic.js defensive init error:', e);
    }
}

window.addEventListener('load', () => {
    // Run immediately on load
    runDefensiveInitChecks();

    // Also schedule a couple of retries in case of late mutations
    setTimeout(runDefensiveInitChecks, 250);
    setTimeout(runDefensiveInitChecks, 1000);
});