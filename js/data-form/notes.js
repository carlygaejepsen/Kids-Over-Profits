/**
 * Notes Module for Facility Form
 * Handles field notes functionality in a modular way
 */

// Module state
let noteFieldRegistry = [];
let allFacilityNotes = {};
let notesCurrentFacilityIndex = 0;
let fieldNotes = {}; // Legacy fallback storage

// Debug logging flag
const DEBUG_NOTES = false;

function debugLog(...args) {
    if (DEBUG_NOTES) {
        console.log('[Notes]', ...args);
    }
}

/**
 * Ensures the fieldNotes store exists for a given scope
 * @param {string} scope - The scope (operator, facility, referrerGroup, referrerIndividual, or default)
 * @param {boolean} createIfMissing - Whether to create the store if it doesn't exist
 * @returns {object|null} - The fieldNotes store or null
 */
function ensureFieldNotesStore(scope, createIfMissing = true) {
    if (!window.formData) {
        return null;
    }

    if (scope === 'operator') {
        if (!window.formData.operator) {
            return null;
        }
        if (!window.formData.operator.fieldNotes) {
            if (!createIfMissing) {
                return null;
            }
            window.formData.operator.fieldNotes = {};
        }
        return window.formData.operator.fieldNotes;
    }

    if (scope === 'facility') {
        const facility = window.formData.facilities?.[window.currentFacilityIndex];
        if (!facility) {
            return null;
        }
        if (!facility.fieldNotes) {
            if (!createIfMissing) {
                return null;
            }
            facility.fieldNotes = {};
        }
        return facility.fieldNotes;
    }

    if (scope === 'referrerGroup') {
        if (typeof window.ensureReferrerDataStructures === 'function') {
            window.ensureReferrerDataStructures();
        }
        if (!window.formData.referrerGroup) {
            return null;
        }
        if (!window.formData.referrerGroup.fieldNotes) {
            if (!createIfMissing) {
                return null;
            }
            window.formData.referrerGroup.fieldNotes = {};
        }
        return window.formData.referrerGroup.fieldNotes;
    }

    if (scope === 'referrerIndividual') {
        if (typeof window.ensureReferrerDataStructures === 'function') {
            window.ensureReferrerDataStructures();
        }
        if (!window.formData.referrerIndividual) {
            return null;
        }
        if (!window.formData.referrerIndividual.fieldNotes) {
            if (!createIfMissing) {
                return null;
            }
            window.formData.referrerIndividual.fieldNotes = {};
        }
        return window.formData.referrerIndividual.fieldNotes;
    }

    if (!window.formData.fieldNotes) {
        if (!createIfMissing) {
            return null;
        }
        window.formData.fieldNotes = {};
    }
    return window.formData.fieldNotes;
}

/**
 * Gets field notes for a specific scope and key, ensuring it's always an array
 * @param {string} scope - The scope
 * @param {string} key - The field key
 * @param {boolean} createIfMissing - Whether to create if missing
 * @returns {Array} - Always returns an array of notes
 */
function getFieldNotes(scope, key, createIfMissing = false) {
    const store = ensureFieldNotesStore(scope, createIfMissing);
    if (!store) {
        return [];
    }

    if (!Object.prototype.hasOwnProperty.call(store, key)) {
        if (!createIfMissing) {
            return [];
        }
        store[key] = [];
    }

    const notes = store[key];

    // CRITICAL BUG FIX: Always ensure we return an array
    // Handle case where notes is an object {} instead of an array []
    if (!Array.isArray(notes)) {
        const normalized = [];
        if (notes !== null && notes !== undefined) {
            // If it's an object with values, try to extract them
            if (typeof notes === 'object') {
                const values = Object.values(notes);
                values.forEach(v => {
                    if (v !== null && v !== undefined && `${v}`.trim() !== '') {
                        normalized.push(`${v}`);
                    }
                });
            } else if (`${notes}`.trim() !== '') {
                normalized.push(`${notes}`);
            }
        }
        store[key] = normalized;
        return normalized;
    }

    return notes;
}

/**
 * Adds a new empty note to a field
 * @param {string} scope - The scope
 * @param {string} key - The field key
 */
function addFieldNote(scope, key) {
    let notes = getFieldNotes(scope, key, true);

    // Additional safety check - ensure notes is definitely an array
    if (!Array.isArray(notes)) {
        console.warn('[Notes] getFieldNotes returned non-array, normalizing:', notes);
        // Force it to be an array
        const store = ensureFieldNotesStore(scope, true);
        if (store) {
            store[key] = [];
            notes = store[key];
        } else {
            console.error('[Notes] Could not get field notes store');
            return;
        }
    }

    notes.push('');

    if (typeof window.updateJSON === 'function') {
        window.updateJSON();
    }
    if (typeof window.autoSave === 'function') {
        window.autoSave();
    }
    renderAllFieldNotes();
}

/**
 * Updates a specific note at an index
 * @param {string} scope - The scope
 * @param {string} key - The field key
 * @param {number} index - The note index
 * @param {string} value - The new value
 */
function updateFieldNote(scope, key, index, value) {
    const notes = getFieldNotes(scope, key, true);
    if (index >= 0 && index < notes.length) {
        notes[index] = value;
        if (typeof window.updateJSON === 'function') {
            window.updateJSON();
        }
        if (typeof window.autoSave === 'function') {
            window.autoSave();
        }
    }
}

/**
 * Removes a note at a specific index
 * @param {string} scope - The scope
 * @param {string} key - The field key
 * @param {number} index - The note index to remove
 */
function removeFieldNote(scope, key, index) {
    const notes = getFieldNotes(scope, key, true);
    if (index >= 0 && index < notes.length) {
        notes.splice(index, 1);
        if (typeof window.updateJSON === 'function') {
            window.updateJSON();
        }
        if (typeof window.autoSave === 'function') {
            window.autoSave();
        }
        renderAllFieldNotes();
    }
}

/**
 * Renders notes for a specific field
 * @param {HTMLElement} container - The container to render notes in
 * @param {string} scope - The scope
 * @param {string} key - The field key
 */
function renderFieldNotes(container, scope, key) {
    if (!container) {
        return;
    }

    const notes = getFieldNotes(scope, key, false);
    container.innerHTML = '';

    if (!notes.length) {
        return;
    }

    notes.forEach((note, index) => {
        const row = document.createElement('div');
        row.className = 'note-row';

        const textarea = document.createElement('textarea');
        textarea.className = 'note-textarea';
        textarea.placeholder = 'Add supporting notes...';
        textarea.rows = 3;
        textarea.value = note || '';
        textarea.addEventListener('input', () => {
            updateFieldNote(scope, key, index, textarea.value);
        }, { passive: true });

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'remove-note-btn';
        removeBtn.textContent = '−';
        removeBtn.addEventListener('click', () => {
            removeFieldNote(scope, key, index);
        }, { passive: true });

        row.appendChild(textarea);
        row.appendChild(removeBtn);
        container.appendChild(row);
    });
}

/**
 * Renders all registered field notes
 */
function renderAllFieldNotes() {
    if (!Array.isArray(noteFieldRegistry)) {
        return;
    }

    noteFieldRegistry.forEach(entry => {
        if (!entry) {
            return;
        }
        const { scope, key, container } = entry;
        renderFieldNotes(container, scope, key);
    });
}

/**
 * Initializes note controls for fields with data-note-scope and data-note-key attributes
 */
function initializeNoteControls() {
    // Preserve existing array item entries (those with keys containing a dot followed by a number like "staff.administrator.0")
    const arrayItemEntries = (noteFieldRegistry || []).filter(entry => {
        if (!entry || !entry.key) return false;
        // Keep entries that look like array items (e.g., "path.0", "path.1", etc.)
        return /\.\d+$/.test(entry.key);
    });

    // Reset registry but keep array item entries
    noteFieldRegistry = [...arrayItemEntries];

    document.querySelectorAll('[data-note-scope][data-note-key]').forEach(field => {
        if (field.closest('.array-item')) {
            return;
        }

        const scope = field.dataset.noteScope;
        const key = field.dataset.noteKey;
        const group = field.closest('.form-group') || field.closest('.checkbox-group');

        if (!group || !scope || !key) {
            return;
        }

        const isCheckbox = field.type === 'checkbox';

        // Skip checkboxes entirely - they use the new note system from data.html
        if (isCheckbox) {
            return;
        }

        let container = group.querySelector('.field-notes');
        let controls = group.querySelector('.note-controls');

        if (!controls) {
            // Create the + button
            const addBtn = document.createElement('button');
            addBtn.type = 'button';
            addBtn.className = 'note-add-btn field-note-btn';
            addBtn.textContent = '+';
            addBtn.setAttribute('aria-label', 'Add note');
            addBtn.dataset.noteEventAttached = 'true';
            addBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopImmediatePropagation();
                addFieldNote(scope, key);
            });

            // Insert button after the field (inline)
            field.parentNode.insertBefore(addBtn, field.nextSibling);

            // Create notes container (goes below field and button)
            container = document.createElement('div');
            container.className = 'field-notes';

            controls = document.createElement('div');
            controls.className = 'note-controls';
            controls.appendChild(container);
            group.appendChild(controls);

            // Add class to form-group for proper layout
            group.classList.add('has-note-button');

            field.dataset.noteInit = 'true';
        } else {
            // Controls exist, make sure container reference is correct
            if (!container) {
                container = controls.querySelector('.field-notes');
            }
            // Check if button needs event listener (shouldn't happen, but defensive)
            const addBtn = controls.querySelector('.note-add-btn');
            if (addBtn && !addBtn.dataset.noteEventAttached) {
                addBtn.dataset.noteEventAttached = 'true';
                addBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    addFieldNote(scope, key);
                });
            }
            if (field.dataset.noteInit !== 'true') {
                field.dataset.noteInit = 'true';
            }
        }

        if (!noteFieldRegistry.some(entry => entry && entry.container === container)) {
            noteFieldRegistry.push({ scope, key, container });
        }
    });

    renderAllFieldNotes();
}

/**
 * Gets the current facility's notes
 * @returns {object} - The notes object for the current facility
 */
function getCurrentFacilityNotes() {
    if (!allFacilityNotes[notesCurrentFacilityIndex]) {
        allFacilityNotes[notesCurrentFacilityIndex] = {};
    }
    return allFacilityNotes[notesCurrentFacilityIndex];
}

/**
 * Updates the current facility index
 */
function updateCurrentFacility() {
    // Get current facility index from the external system
    if (typeof window.currentFacilityIndex !== 'undefined') {
        notesCurrentFacilityIndex = window.currentFacilityIndex;
    }
    // Refresh note buttons for the new facility
    setTimeout(() => {
        addNoteButtons();
    }, 100);
}

/**
 * Loads field notes from current facility data or localStorage
 */
function loadFieldNotes() {
    try {
        // Update current facility index
        updateCurrentFacility();

        // Try to get notes from the current facility data if it exists
        if (typeof window.getCurrentFacilityData === 'function') {
            const facilityData = window.getCurrentFacilityData();
            if (facilityData && facilityData.fieldNotes) {
                allFacilityNotes[notesCurrentFacilityIndex] = facilityData.fieldNotes;
                return;
            }
        }

        // Fallback to localStorage if facility system not available
        const stored = localStorage.getItem('fieldNotes');
        if (stored) {
            fieldNotes = JSON.parse(stored);
        }
    } catch (e) {
        console.warn('Failed to load field notes:', e);
        fieldNotes = {};
    }
}

/**
 * Saves field notes to facility data and/or localStorage
 */
function saveFieldNotes() {
    try {
        const currentNotes = getCurrentFacilityNotes();

        // Save to facility data structure if available
        if (typeof window.updateCurrentFacilityData === 'function') {
            window.updateCurrentFacilityData({ fieldNotes: currentNotes });
        } else if (typeof window.saveCurrentFacility === 'function') {
            // Try alternative save function
            const facilityData = window.getCurrentFacilityData ? window.getCurrentFacilityData() : {};
            facilityData.fieldNotes = currentNotes;
            window.saveCurrentFacility(facilityData);
        } else {
            // If facility functions not available, trigger any existing save mechanism
            if (typeof window.triggerSave === 'function') {
                window.triggerSave();
            }
            localStorage.setItem('fieldNotes', JSON.stringify(fieldNotes));
        }

        // Also save to localStorage as backup
        localStorage.setItem('fieldNotes', JSON.stringify(fieldNotes));

        // Trigger any existing auto-save mechanism
        if (typeof window.autoSave === 'function') {
            window.autoSave();
        }

    } catch (e) {
        console.warn('Failed to save field notes to cloud, saving locally:', e);
        localStorage.setItem('fieldNotes', JSON.stringify(fieldNotes));
    }
}

/**
 * Generates a unique field identifier
 * @param {HTMLElement} element - The field element
 * @returns {string} - Unique identifier
 */
function getFieldIdentifier(element) {
    // If element already has a unique note ID, use it
    if (element.dataset.noteId) {
        return element.dataset.noteId;
    }

    // Create a unique ID and store it on the element
    const uniqueId = `field-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
    element.dataset.noteId = uniqueId;
    return uniqueId;
}

/**
 * Adds note buttons to array items
 * @param {HTMLElement} group - The form group containing array items
 */
function addNoteButtonsToArrayItems(group) {
    try {
        const arrayItems = group.querySelectorAll('.array-item');

        arrayItems.forEach(arrayItem => {
            if (arrayItem.querySelector('.field-note-btn')) {
                return;
            }

            const field = arrayItem.querySelector('input:not([type="hidden"]):not([style*="display: none"]), textarea:not([style*="display: none"]), select:not([style*="display: none"])');
            if (!field) {
                return;
            }

            if (field.type === 'hidden') {
                return;
            }

            const fieldGroup = arrayItem.closest('.form-group') || group;

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
                e.stopImmediatePropagation();
                const renderedNote = createFieldNote(field, fieldGroup);
                if (renderedNote) {
                    const noteInput = renderedNote.querySelector('.note-input');
                    if (noteInput) {
                        noteInput.focus();
                    }
                }
            }, { passive: false });

            noteBtn.addEventListener('mousedown', (e) => {
                e.stopPropagation();
            }, { passive: true });

            noteBtn.addEventListener('mouseup', (e) => {
                e.stopPropagation();
            }, { passive: true });

            arrayItem.appendChild(noteBtn);
            arrayItem.classList.add('has-note-button');
        });
    } catch (error) {
        console.warn('Error adding note buttons to array items:', error);
    }
}

/**
 * Adds note buttons to all form groups
 */
function addNoteButtons() {
    debugLog('addNoteButtons function called');
    const formGroups = document.querySelectorAll('.form-group');
    debugLog('Found', formGroups.length, 'form groups');

    formGroups.forEach(group => {
        // Skip if button already exists
        if (group.querySelector('.field-note-btn')) {
            return;
        }

        // First, handle array items within this group
        addNoteButtonsToArrayItems(group);

        // Then handle the main field if it's not in an array item
        let field = group.querySelector('input:not([type="hidden"]):not([style*="display: none"]), textarea:not([style*="display: none"]), select:not([style*="display: none"])');

        // Skip if this field is already inside an array item (handled above)
        if (field && field.closest('.array-item')) {
            return;
        }

        // If no visible field found, try any field as fallback
        if (!field) {
            field = group.querySelector('input, textarea, select');
        }

        if (!field) {
            return;
        }

        // Skip certain field types
        if (field.type === 'hidden' || field.style.display === 'none') {
            return;
        }

        // Skip note fields themselves
        const fieldId = field.id || '';
        const dataField = field.dataset.field || '';
        if (fieldId.endsWith('-notes') || dataField === 'notes' || dataField.endsWith('.notes')) {
            return;
        }

        // Skip utility/action fields
        const skipFieldIds = [
            'import-file',
            'json-data',
            'toc-toggle',
            'facility-counter',
            'project-name',
            'organize-by',
            'organize-value'
        ];

        if (skipFieldIds.includes(fieldId)) {
            return;
        }

        // Skip fields within project management section
        if (field.closest('.project-management')) {
            return;
        }

        // Skip fields within data organizer section
        if (field.closest('#data-organizer-section')) {
            return;
        }

        // Skip fields with certain labels
        const label = group.querySelector('label');
        if (label) {
            const labelText = label.textContent.toLowerCase();
            const skipLabels = [
                'import data',
                'paste json',
                'file upload',
                'import file',
                'project management',
                'saved projects'
            ];

            if (skipLabels.some(skipLabel => labelText.includes(skipLabel))) {
                return;
            }
        }

        // Create note button
        const noteBtn = document.createElement('button');
        noteBtn.type = 'button';
        noteBtn.className = 'field-note-btn';
        noteBtn.innerHTML = '+';
        noteBtn.title = 'Add note for this field';

        // Check if this field has a note
        const fieldIdentifier = getFieldIdentifier(field);
        const currentNotes = getCurrentFacilityNotes();
        if (currentNotes[fieldIdentifier]) {
            noteBtn.classList.add('has-note');
            noteBtn.title = 'Edit note for this field';
        }

        // Add click handler
        noteBtn.addEventListener('click', (e) => {
            debugLog('✅ Note button clicked! Field:', field, 'Group:', group);
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            const result = createFieldNote(field, group);
            debugLog('✅ createFieldNote returned:', result);
        }, { passive: false });

        // Prevent button from interfering with input events
        noteBtn.addEventListener('mousedown', (e) => {
            e.stopPropagation();
        }, { passive: true });

        noteBtn.addEventListener('mouseup', (e) => {
            e.stopPropagation();
        }, { passive: true });

        // Add button inside the same container as the field
        const arrayItem = field.closest('.array-item');
        if (arrayItem) {
            // For array items, add the button directly to the array-item flex container
            debugLog('Adding button to array item for field:', field);
            arrayItem.appendChild(noteBtn);
        } else {
            // Find the appropriate inner container (autocomplete-wrapper)
            const innerContainer = field.closest('.autocomplete-wrapper');

            if (innerContainer) {
                // For autocomplete-wrapper, add button directly to it
                innerContainer.style.display = 'flex';
                innerContainer.style.alignItems = 'flex-start';
                innerContainer.style.gap = '8px';

                // Make the field take remaining space
                field.style.flex = '1';
                field.style.minWidth = '0';
                field.style.width = 'auto';

                // Add the button to the inner container
                innerContainer.appendChild(noteBtn);
            } else {
                // Fallback: create field-content wrapper
                let fieldContent = group.querySelector('.field-content');
                if (!fieldContent) {
                    fieldContent = document.createElement('div');
                    fieldContent.className = 'field-content';

                    // Move all existing children except label to the field-content wrapper
                    const label = group.querySelector('label');
                    const children = Array.from(group.children);

                    children.forEach(child => {
                        if (child !== label) {
                            fieldContent.appendChild(child);
                        }
                    });

                    // Add the field-content wrapper to the group
                    group.appendChild(fieldContent);
                }

                // Create a horizontal wrapper for the field and plus button
                let fieldInputWrapper = group.querySelector('.field-input-wrapper');
                if (!fieldInputWrapper) {
                    fieldInputWrapper = document.createElement('div');
                    fieldInputWrapper.className = 'field-input-wrapper';

                    // Move the field into the wrapper
                    fieldInputWrapper.appendChild(field);

                    // Insert wrapper as first child of field-content
                    fieldContent.insertBefore(fieldInputWrapper, fieldContent.firstChild);
                }

                // Add the note button to the wrapper (inline with field)
                fieldInputWrapper.appendChild(noteBtn);
            }
        }

        // Add class to form group to apply proper styling
        group.classList.add('has-note-button');
    });
}

/**
 * Creates a field note container for a specific field
 * @param {HTMLElement} field - The field element
 * @param {HTMLElement} group - The form group containing the field
 * @returns {HTMLElement} - The created note container
 */
function createFieldNote(field, group) {
    debugLog('🔵 createFieldNote START - field:', field, 'group:', group);
    const fieldId = getFieldIdentifier(field);
    debugLog('🔵 fieldId:', fieldId);
    const label = group.querySelector('label');
    const fieldName = label ? label.textContent.trim() : 'Field';
    debugLog('🔵 fieldName:', fieldName);

    // Create a new note container
    const noteContainer = document.createElement('div');
    noteContainer.className = 'note-container';
    noteContainer.dataset.fieldId = fieldId;
    if (field.type === 'checkbox') {
        noteContainer.classList.add('checkbox-note');
        noteContainer.style.marginLeft = '30px';
    }
    noteContainer.style.marginTop = '8px';
    noteContainer.style.marginBottom = '8px';
    noteContainer.style.padding = '8px';
    noteContainer.style.border = '1px solid #e5e7eb';
    noteContainer.style.borderRadius = '4px';
    noteContainer.style.backgroundColor = '#f9fafb';

    const noteHeader = document.createElement('div');
    noteHeader.style.display = 'flex';
    noteHeader.style.justifyContent = 'space-between';
    noteHeader.style.alignItems = 'center';
    noteHeader.style.marginBottom = '4px';

    const noteLabel = document.createElement('label');
    noteLabel.textContent = `${fieldName} Note`;
    noteLabel.style.fontSize = '13px';
    noteLabel.style.color = '#6b7280';
    noteLabel.style.margin = '0';

    // Add a remove button for this specific note
    const removeNoteBtn = document.createElement('button');
    removeNoteBtn.type = 'button';
    removeNoteBtn.innerHTML = '×';
    removeNoteBtn.style.background = 'none';
    removeNoteBtn.style.border = 'none';
    removeNoteBtn.style.color = '#9ca3af';
    removeNoteBtn.style.cursor = 'pointer';
    removeNoteBtn.style.fontSize = '16px';
    removeNoteBtn.style.padding = '0';
    removeNoteBtn.style.width = '20px';
    removeNoteBtn.style.height = '20px';
    removeNoteBtn.title = 'Remove this note';

    const noteInput = document.createElement('input');
    noteInput.type = 'text';
    noteInput.className = 'note-input';
    noteInput.placeholder = 'Add notes or context for this field...';
    noteInput.style.fontSize = '14px';
    noteInput.style.padding = '8px 12px';
    noteInput.style.width = '100%';
    noteInput.style.border = '1px solid #d1d5db';
    noteInput.style.borderRadius = '4px';

    // Generate a unique note ID
    const noteId = `${fieldId}_note_${Date.now()}`;

    // Save note on input
    noteInput.addEventListener('input', () => {
        const noteText = noteInput.value.trim();
        const currentNotes = getCurrentFacilityNotes();

        // Ensure currentNotes[fieldId] is an array
        if (!currentNotes[fieldId]) {
            currentNotes[fieldId] = [];
        } else if (!Array.isArray(currentNotes[fieldId])) {
            // Convert old string-based notes to array format
            const oldNote = currentNotes[fieldId];
            currentNotes[fieldId] = [{
                id: `${fieldId}_note_${Date.now()}_legacy`,
                text: oldNote,
                timestamp: new Date().toISOString()
            }];
        }

        // Find this note in the array and update it
        const existingNoteIndex = currentNotes[fieldId].findIndex(note => note.id === noteId);
        if (noteText) {
            const noteData = { id: noteId, text: noteText, timestamp: new Date().toISOString() };
            if (existingNoteIndex >= 0) {
                currentNotes[fieldId][existingNoteIndex] = noteData;
            } else {
                currentNotes[fieldId].push(noteData);
            }
        } else {
            // Remove empty note
            if (existingNoteIndex >= 0) {
                currentNotes[fieldId].splice(existingNoteIndex, 1);
            }
            if (currentNotes[fieldId].length === 0) {
                delete currentNotes[fieldId];
            }
        }

        saveFieldNotes();
        updateNoteButton(field);

        // Trigger any existing form change detection
        if (typeof window.onFormChange === 'function') {
            window.onFormChange();
        }

        // Dispatch a custom event for form changes
        const facilityNotes = getCurrentFacilityNotes();
        const changeEvent = new CustomEvent('facilityDataChanged', {
            detail: { type: 'fieldNote', fieldId: fieldId, value: facilityNotes[fieldId] }
        });
        document.dispatchEvent(changeEvent);
    }, { passive: true });

    // Remove note handler
    removeNoteBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();

        const currentNotes = getCurrentFacilityNotes();

        // Remove this specific note from the array
        if (currentNotes[fieldId]) {
            // Ensure it's an array before calling findIndex
            if (!Array.isArray(currentNotes[fieldId])) {
                // Convert old string format to array and remove
                delete currentNotes[fieldId];
            } else {
                const noteIndex = currentNotes[fieldId].findIndex(note => note.id === noteId);
                if (noteIndex >= 0) {
                    currentNotes[fieldId].splice(noteIndex, 1);
                }
                if (currentNotes[fieldId].length === 0) {
                    delete currentNotes[fieldId];
                }
            }
        }

        saveFieldNotes();
        updateNoteButton(field);
        noteContainer.remove();

        // Trigger form change detection
        if (typeof window.onFormChange === 'function') {
            window.onFormChange();
        }
    }, { passive: false });

    noteHeader.appendChild(noteLabel);
    noteHeader.appendChild(removeNoteBtn);
    noteContainer.appendChild(noteHeader);
    noteContainer.appendChild(noteInput);

    // Position notes BELOW the field
    const arrayItem = field.closest('.array-item');
    if (arrayItem) {
        // For array items, insert note after the entire array item
        const existingNotes = [];
        let sibling = arrayItem.nextElementSibling;
        while (sibling && sibling.classList?.contains('note-container') && sibling.dataset.fieldId === fieldId) {
            existingNotes.push(sibling);
            sibling = sibling.nextElementSibling;
        }

        if (existingNotes.length > 0) {
            // Insert after the last existing note for this field
            existingNotes[existingNotes.length - 1].insertAdjacentElement('afterend', noteContainer);
        } else {
            // Insert right after the array item
            arrayItem.insertAdjacentElement('afterend', noteContainer);
        }
    } else if (field.type === 'checkbox') {
        // For checkboxes, insert after the checkbox group or field
        const checkboxGroup = field.closest('.checkbox-group');
        const insertionBase = checkboxGroup || field;
        let lastNote = insertionBase;
        let sibling = insertionBase.nextElementSibling;
        while (sibling && sibling.classList?.contains('note-container') && sibling.dataset.fieldId === fieldId) {
            lastNote = sibling;
            sibling = sibling.nextElementSibling;
        }
        lastNote.insertAdjacentElement('afterend', noteContainer);
    } else {
        // For regular fields, insert after the field's parent container
        const wrapper = field.closest('.autocomplete-wrapper') || field.closest('.field-content') || field.parentElement;

        // Check for existing notes after this wrapper
        const existingNotes = [];
        let sibling = wrapper.nextElementSibling;
        while (sibling && sibling.classList?.contains('note-container') && sibling.dataset.fieldId === fieldId) {
            existingNotes.push(sibling);
            sibling = sibling.nextElementSibling;
        }

        if (existingNotes.length > 0) {
            // Insert after the last existing note for this field
            existingNotes[existingNotes.length - 1].insertAdjacentElement('afterend', noteContainer);
        } else {
            // Insert right after the wrapper
            wrapper.insertAdjacentElement('afterend', noteContainer);
        }
    }

    // Focus the note input
    noteInput.focus();

    updateNoteButton(field);
    return noteContainer;
}

/**
 * Updates the note button appearance based on whether notes exist
 * @param {HTMLElement} field - The field element
 */
function updateNoteButton(field) {
    const fieldId = getFieldIdentifier(field);
    const formGroup = field.closest('.form-group');
    const noteBtn = formGroup?.querySelector('.field-note-btn');

    if (noteBtn) {
        const currentNotes = getCurrentFacilityNotes();
        const notes = currentNotes[fieldId];
        if (notes && Array.isArray(notes) && notes.length > 0) {
            noteBtn.classList.add('has-note');
            noteBtn.title = 'Has notes - Click to add another';
            noteBtn.innerHTML = '+';
        } else {
            noteBtn.classList.remove('has-note');
            noteBtn.title = 'Add note for this field';
            noteBtn.innerHTML = '+';
        }
    }
}

/**
 * Gets all rendered note containers for a field
 * @param {HTMLElement} field - The field element
 * @returns {Array<HTMLElement>} - Array of note containers
 */
function getRenderedNotesForField(field) {
    const fieldId = getFieldIdentifier(field);
    return Array.from(document.querySelectorAll(`.note-container[data-field-id="${fieldId}"]`));
}

/**
 * Ensures a checkbox has a note (creates one if needed)
 * @param {HTMLElement} checkbox - The checkbox element
 */
function ensureCheckboxNote(checkbox) {
    const group = checkbox.closest('.form-group') || checkbox.closest('.checkbox-group') || checkbox.parentElement;
    if (!group) {
        return;
    }

    const existingNotes = getRenderedNotesForField(checkbox);
    if (existingNotes.length > 0) {
        const lastNoteInput = existingNotes[existingNotes.length - 1].querySelector('.note-input');
        if (lastNoteInput) {
            lastNoteInput.focus();
        }
        return;
    }

    const noteContainer = createFieldNote(checkbox, group);
    if (noteContainer) {
        const noteInput = noteContainer.querySelector('.note-input');
        if (noteInput) {
            noteInput.focus();
        }
    }
}

/**
 * Initializes checkbox note triggers (auto-create note when checked)
 */
function initializeCheckboxNoteTriggers() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"]');
    checkboxes.forEach(checkbox => {
        if (checkbox.dataset.noteAutoInit === 'true') {
            return;
        }

        checkbox.dataset.noteAutoInit = 'true';

        checkbox.addEventListener('change', () => {
            if (checkbox.checked) {
                ensureCheckboxNote(checkbox);
            }
        }, { passive: true });

        if (checkbox.checked) {
            ensureCheckboxNote(checkbox);
        }
    });
}

/**
 * Initializes the field notes functionality
 */
function initializeFieldNotes() {
    loadFieldNotes();

    // Add note buttons to existing form groups
    addNoteButtons();
    initializeCheckboxNoteTriggers();

    // Load existing notes and create inline fields
    setTimeout(() => {
        const currentNotes = getCurrentFacilityNotes();
        Object.keys(currentNotes).forEach(fieldId => {
            const field = document.querySelector(`#${fieldId}`) ||
                          document.querySelector(`[name="${fieldId}"]`) ||
                          Array.from(document.querySelectorAll('input, textarea, select')).find(f => getFieldIdentifier(f) === fieldId);

            if (field) {
                const group = field.closest('.form-group');
                if (group && !group.querySelector('.note-container')) {
                    createFieldNote(field, group);
                }
            }
        });
    }, 500);

    // Re-add buttons when content changes (for dynamic content)
    const observer = new MutationObserver(() => {
        setTimeout(() => {
            addNoteButtons();
            initializeCheckboxNoteTriggers();
        }, 100);
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    debugLog('Field notes functionality initialized');
}

/**
 * Syncs field notes when facility data changes (called by external scripts)
 * @param {object} facilityData - The facility data object
 */
function syncFieldNotes(facilityData) {
    if (facilityData && facilityData.fieldNotes) {
        const currentNotes = getCurrentFacilityNotes();

        // Migrate old string-based notes to new array format
        Object.keys(facilityData.fieldNotes).forEach(fieldId => {
            const noteValue = facilityData.fieldNotes[fieldId];
            if (typeof noteValue === 'string' && noteValue.trim()) {
                // Convert old string notes to new array format
                currentNotes[fieldId] = [{
                    id: `${fieldId}_note_${Date.now()}`,
                    text: noteValue,
                    timestamp: new Date().toISOString()
                }];
            } else if (Array.isArray(noteValue)) {
                currentNotes[fieldId] = noteValue;
            }
        });
    }
}

// Export noteFieldRegistry globally for backward compatibility with facility-form.v3.js
window.noteFieldRegistry = noteFieldRegistry;

// Export functions for use in other modules
window.NotesModule = {
    // Core functions
    ensureFieldNotesStore,
    getFieldNotes,
    addFieldNote,
    updateFieldNote,
    removeFieldNote,
    renderFieldNotes,
    renderAllFieldNotes,
    initializeNoteControls,

    // Extended functions
    getCurrentFacilityNotes,
    updateCurrentFacility,
    loadFieldNotes,
    saveFieldNotes,
    getFieldIdentifier,
    addNoteButtonsToArrayItems,
    addNoteButtons,
    createFieldNote,
    updateNoteButton,
    getRenderedNotesForField,
    ensureCheckboxNote,
    initializeCheckboxNoteTriggers,
    initializeFieldNotes,
    syncFieldNotes,

    // State accessors (for debugging/testing)
    getNoteFieldRegistry: () => noteFieldRegistry,
    setNoteFieldRegistry: (value) => {
        noteFieldRegistry = value;
        window.noteFieldRegistry = value; // Keep global in sync
    },
    getAllFacilityNotes: () => allFacilityNotes,
    setAllFacilityNotes: (value) => { allFacilityNotes = value; }
};

// Log that the module has loaded
console.log('📝 Notes module loaded and window.NotesModule is available');
