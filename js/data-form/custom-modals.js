// ============================================
// CUSTOM MODAL SYSTEM - Modern UI Replacement for alert/confirm/prompt
// Provides beautiful, customizable modals with dropdowns and clear options
// ============================================

/**
 * Custom Modal System
 * Replaces ugly browser alert(), confirm(), and prompt() with beautiful modals
 */
class CustomModal {
    constructor() {
        this.modalContainer = null;
        this.resolveCallback = null;
        this.rejectCallback = null;
        this.createModalContainer();
    }

    createModalContainer() {
        // Remove existing modal container if it exists
        const existing = document.getElementById('custom-modal-container');
        if (existing) {
            existing.remove();
        }

        this.modalContainer = document.createElement('div');
        this.modalContainer.id = 'custom-modal-container';
        this.modalContainer.className = 'modal custom-modal';
        document.body.appendChild(this.modalContainer);

        // Close modal when clicking outside
        this.modalContainer.addEventListener('click', (e) => {
            if (e.target === this.modalContainer) {
                this.close(null);
            }
        });

        // Close modal on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.modalContainer.classList.contains('active')) {
                this.close(null);
            }
        });
    }

    /**
     * Show an alert modal (OK button only)
     * @param {string} message - The message to display
     * @param {string} title - Optional title
     * @returns {Promise<void>}
     */
    alert(message, title = 'Alert') {
        return new Promise((resolve) => {
            this.resolveCallback = resolve;

            const content = `
                <div class="modal-content custom-modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">${this.escapeHtml(title)}</h2>
                        <button class="modal-close" data-action="close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p class="modal-message">${this.escapeHtml(message)}</p>
                    </div>
                    <div class="modal-footer">
                        <button class="modal-btn modal-btn-primary" data-action="ok">OK</button>
                    </div>
                </div>
            `;

            this.show(content);
        });
    }

    /**
     * Show a confirm modal (Yes/No or OK/Cancel buttons)
     * @param {string} message - The message to display
     * @param {string} title - Optional title
     * @param {Object} options - Optional configuration
     * @returns {Promise<boolean>}
     */
    confirm(message, title = 'Confirm', options = {}) {
        return new Promise((resolve) => {
            this.resolveCallback = resolve;

            const yesText = options.yesText || 'OK';
            const noText = options.noText || 'Cancel';

            const content = `
                <div class="modal-content custom-modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">${this.escapeHtml(title)}</h2>
                        <button class="modal-close" data-action="close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p class="modal-message">${this.escapeHtml(message)}</p>
                    </div>
                    <div class="modal-footer">
                        <button class="modal-btn modal-btn-secondary" data-action="no">${this.escapeHtml(noText)}</button>
                        <button class="modal-btn modal-btn-primary" data-action="yes">${this.escapeHtml(yesText)}</button>
                    </div>
                </div>
            `;

            this.show(content);
        });
    }

    /**
     * Show a prompt modal (input field with OK/Cancel)
     * @param {string} message - The message to display
     * @param {string} defaultValue - Default value for input
     * @param {string} title - Optional title
     * @returns {Promise<string|null>}
     */
    prompt(message, defaultValue = '', title = 'Input Required') {
        return new Promise((resolve) => {
            this.resolveCallback = resolve;

            const content = `
                <div class="modal-content custom-modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">${this.escapeHtml(title)}</h2>
                        <button class="modal-close" data-action="close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p class="modal-message">${this.escapeHtml(message)}</p>
                        <input type="text" class="modal-input" value="${this.escapeHtml(defaultValue)}" id="modal-prompt-input">
                    </div>
                    <div class="modal-footer">
                        <button class="modal-btn modal-btn-secondary" data-action="cancel">Cancel</button>
                        <button class="modal-btn modal-btn-primary" data-action="submit">OK</button>
                    </div>
                </div>
            `;

            this.show(content);

            // Focus on input field
            setTimeout(() => {
                const input = document.getElementById('modal-prompt-input');
                if (input) {
                    input.focus();
                    input.select();

                    // Submit on Enter key
                    input.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            this.close(input.value);
                        }
                    });
                }
            }, 100);
        });
    }

    /**
     * Show a select modal (dropdown menu with options)
     * @param {string} message - The message to display
     * @param {Array} options - Array of options {value, label} or strings
     * @param {string} title - Optional title
     * @param {Object} config - Optional configuration
     * @returns {Promise<string|null>}
     */
    select(message, options = [], title = 'Select an Option', config = {}) {
        return new Promise((resolve) => {
            this.resolveCallback = resolve;

            // Normalize options to {value, label} format
            const normalizedOptions = options.map(opt => {
                if (typeof opt === 'string') {
                    return { value: opt, label: opt };
                }
                return opt;
            });

            // Generate option HTML
            const optionsHtml = normalizedOptions.map((opt, index) => {
                const selected = index === 0 ? 'selected' : '';
                return `<option value="${this.escapeHtml(opt.value)}" ${selected}>${this.escapeHtml(opt.label)}</option>`;
            }).join('');

            const showDescription = config.showDescription !== false;
            const descriptions = config.descriptions || {};

            // Generate description HTML
            let descriptionHtml = '';
            if (showDescription && Object.keys(descriptions).length > 0) {
                const firstOption = normalizedOptions[0];
                const firstDesc = descriptions[firstOption.value] || '';
                descriptionHtml = `
                    <div class="modal-option-description" id="modal-option-description">
                        ${firstDesc ? this.escapeHtml(firstDesc) : '<em>Select an option to see details</em>'}
                    </div>
                `;
            }

            const content = `
                <div class="modal-content custom-modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">${this.escapeHtml(title)}</h2>
                        <button class="modal-close" data-action="close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p class="modal-message">${this.escapeHtml(message)}</p>
                        <select class="modal-select" id="modal-select-input">
                            ${optionsHtml}
                        </select>
                        ${descriptionHtml}
                    </div>
                    <div class="modal-footer">
                        <button class="modal-btn modal-btn-secondary" data-action="cancel">Cancel</button>
                        <button class="modal-btn modal-btn-primary" data-action="submit">OK</button>
                    </div>
                </div>
            `;

            this.show(content);

            // Update description when selection changes
            if (showDescription && Object.keys(descriptions).length > 0) {
                setTimeout(() => {
                    const select = document.getElementById('modal-select-input');
                    const descDiv = document.getElementById('modal-option-description');
                    if (select && descDiv) {
                        select.addEventListener('change', () => {
                            const selectedValue = select.value;
                            const desc = descriptions[selectedValue] || '';
                            descDiv.innerHTML = desc ? this.escapeHtml(desc) : '<em>No description available</em>';
                        });
                    }
                }, 100);
            }

            // Focus on select field
            setTimeout(() => {
                const select = document.getElementById('modal-select-input');
                if (select) {
                    select.focus();
                }
            }, 100);
        });
    }

    /**
     * Show a custom modal with radio buttons or checkboxes
     * @param {string} message - The message to display
     * @param {Array} options - Array of options {value, label, description}
     * @param {string} title - Optional title
     * @param {Object} config - Optional configuration {type: 'radio'|'checkbox', multiple: boolean}
     * @returns {Promise<string|string[]|null>}
     */
    choice(message, options = [], title = 'Choose Option', config = {}) {
        return new Promise((resolve) => {
            this.resolveCallback = resolve;

            const inputType = config.type || 'radio';
            const inputName = 'modal-choice';

            // Generate options HTML with descriptions
            const optionsHtml = options.map((opt, index) => {
                const checked = index === 0 ? 'checked' : '';
                const optValue = opt.value || opt.label || opt;
                const optLabel = opt.label || opt.value || opt;
                const optDesc = opt.description || '';

                return `
                    <label class="modal-choice-option">
                        <input type="${inputType}" name="${inputName}" value="${this.escapeHtml(optValue)}" ${checked}>
                        <div class="modal-choice-content">
                            <div class="modal-choice-label">${this.escapeHtml(optLabel)}</div>
                            ${optDesc ? `<div class="modal-choice-description">${this.escapeHtml(optDesc)}</div>` : ''}
                        </div>
                    </label>
                `;
            }).join('');

            const content = `
                <div class="modal-content custom-modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">${this.escapeHtml(title)}</h2>
                        <button class="modal-close" data-action="close">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p class="modal-message">${this.escapeHtml(message)}</p>
                        <div class="modal-choices">
                            ${optionsHtml}
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="modal-btn modal-btn-secondary" data-action="cancel">Cancel</button>
                        <button class="modal-btn modal-btn-primary" data-action="submit">OK</button>
                    </div>
                </div>
            `;

            this.show(content);
        });
    }

    show(content) {
        this.modalContainer.innerHTML = content;
        this.modalContainer.classList.add('active');

        // Attach event listeners
        this.attachEventListeners();
    }

    attachEventListeners() {
        // Handle all button clicks
        const buttons = this.modalContainer.querySelectorAll('[data-action]');
        buttons.forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                const action = button.getAttribute('data-action');
                this.handleAction(action);
            });
        });
    }

    handleAction(action) {
        switch (action) {
            case 'ok':
            case 'yes':
                this.close(true);
                break;
            case 'no':
            case 'cancel':
            case 'close':
                this.close(false);
                break;
            case 'submit':
                // Get value from input or select
                const input = this.modalContainer.querySelector('#modal-prompt-input');
                const select = this.modalContainer.querySelector('#modal-select-input');
                const radioInputs = this.modalContainer.querySelectorAll('input[type="radio"]:checked');
                const checkboxInputs = this.modalContainer.querySelectorAll('input[type="checkbox"]:checked');

                if (input) {
                    this.close(input.value);
                } else if (select) {
                    this.close(select.value);
                } else if (radioInputs.length > 0) {
                    this.close(radioInputs[0].value);
                } else if (checkboxInputs.length > 0) {
                    const values = Array.from(checkboxInputs).map(cb => cb.value);
                    this.close(values);
                } else {
                    this.close(null);
                }
                break;
        }
    }

    close(result) {
        this.modalContainer.classList.remove('active');
        if (this.resolveCallback) {
            this.resolveCallback(result);
            this.resolveCallback = null;
        }
    }

    escapeHtml(text) {
        if (text == null) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }
}

// Create a singleton instance
const modalSystem = new CustomModal();

// Export wrapper functions for easy use
window.customAlert = (message, title) => modalSystem.alert(message, title);
window.customConfirm = (message, title, options) => modalSystem.confirm(message, title, options);
window.customPrompt = (message, defaultValue, title) => modalSystem.prompt(message, defaultValue, title);
window.customSelect = (message, options, title, config) => modalSystem.select(message, options, title, config);
window.customChoice = (message, options, title, config) => modalSystem.choice(message, options, title, config);

// Export the modal system itself
window.CustomModal = CustomModal;
window.modalSystem = modalSystem;

// ============================================
// DATA ORGANIZER MODAL
// Handles search and organization of facility data
// ============================================

/**
 * Organizer Modal - Facility Search & Organization
 * Provides database search functionality with modal interface
 */
class OrganizerModal {
    constructor() {
        this.modalEl = null;
        this.init();
    }

    init() {
        // Modal will be initialized by data-page.js
        // This class just provides the search/display/clear methods
    }

    /**
     * Helper to announce status messages
     */
    announceStatus(message, type = 'info') {
        if (typeof showSuggestionStatus === 'function') {
            showSuggestionStatus(message, type);
        } else if (typeof showUploadStatus === 'function') {
            showUploadStatus(message, type);
        } else {
            console.log(`[${type}] ${message}`);
        }
    }

    /**
     * Perform search across database
     */
    async performSearch() {
        // Get modal elements dynamically
        const modalBySelect = document.getElementById('organize-by-modal');
        const modalValueInput = document.getElementById('organize-value-modal');
        const modalResults = document.getElementById('organize-results-modal');
        const modalMatches = document.getElementById('organize-matches-modal');

        console.log('🔍 Modal search triggered', {
            modalBySelect: !!modalBySelect,
            modalValueInput: !!modalValueInput,
            modalResults: !!modalResults
        });

        if (!modalBySelect || !modalValueInput) {
            console.error('Modal elements not found!');
            this.announceStatus('Search modal elements not found. Please try again.', 'error');
            return;
        }

        const searchType = modalBySelect.value;
        const searchValue = modalValueInput.value.trim();

        console.log('🔍 Search params:', { searchType, searchValue });

        if (!searchType || !searchValue) {
            this.announceStatus('Select a data point and enter a search value, then click Search.', 'error');
            modalValueInput.focus();
            return;
        }

        // Show loading state
        this.announceStatus(`Searching for "${searchValue}"...`, 'info');
        if (modalResults) {
            modalResults.classList.remove('d-none');
            if (modalMatches) {
                modalMatches.innerHTML = '<p style="padding: 40px; text-align: center; color: #6b7280;"><span style="font-size: 24px;">🔍</span><br>Searching database...</p>';
            }
        }

        // Build query parameters exactly like data-search.js
        const params = new URLSearchParams();

        // Map search type to parameter name
        if (searchType === 'keyword') {
            // General keyword searches all contents
            params.append('keyword', searchValue);
        } else if (searchType === 'staff') {
            params.append('staff', searchValue);
        } else if (searchType === 'location') {
            params.append('location', searchValue);
        } else if (searchType === 'programType') {
            params.append('programType', searchValue);
        } else {
            // Fallback: use the type as parameter name
            params.append(searchType, searchValue);
        }
        params.append('limit', '20');

        // Get REST URL exactly like data-search.js
        const restUrl = window.kopData?.restUrl || '/wp-json/';
        const searchUrl = `${restUrl}kop/v1/search?${params.toString()}`;

        // Fetch from database exactly like data-search.js
        fetch(searchUrl)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.results) {
                    const results = [];

                    // Process results
                    data.results.forEach(result => {
                        const facilities = result.data?.facilities || [];
                        const projectOperator = result.data?.operator || null;

                        facilities.forEach((facility, facilityIndex) => {
                            const matches = window.extractDataPointsForSearch ?
                                window.extractDataPointsForSearch(facility, searchType, searchValue, projectOperator) :
                                [searchValue];

                            if (matches.length > 0) {
                                results.push({
                                    projectName: result.name,
                                    facility: facility,
                                    facilityIndex: facilityIndex,
                                    matches: matches,
                                    operator: result.data?.operator?.name || facility?.identification?.operator
                                });
                            }
                        });
                    });

                    if (results.length === 0) {
                        this.announceStatus(`No matches found for "${searchValue}".`, 'warning');
                    } else {
                        this.announceStatus(`Found ${results.length} result${results.length === 1 ? '' : 's'} for "${searchValue}".`, 'success');
                    }

                    this.displayResults(results, searchType, searchValue);
                } else {
                    this.announceStatus('No results found.', 'warning');
                    this.displayResults([], searchType, searchValue);
                }
            })
            .catch(error => {
                console.error('Database search error:', error);
                this.announceStatus('Search failed. Please try again.', 'error');
                if (modalMatches) {
                    modalMatches.innerHTML = '<p style="padding: 20px; text-align: center; color: #ef4444;">Search failed. Please try again.</p>';
                }
            });
    }

    /**
     * Display search results in modal
     */
    displayResults(results, searchType, searchValue) {
        const modalResults = document.getElementById('organize-results-modal');
        const modalResultsTitle = document.getElementById('organize-results-title-modal');
        const modalResultsCount = document.getElementById('organize-results-count-modal');
        const modalMatches = document.getElementById('organize-matches-modal');
        const modalClearBtn = document.getElementById('organize-clear-btn-modal');

        console.log('📊 Displaying results:', { count: results.length, modalResults: !!modalResults, modalMatches: !!modalMatches });

        if (!modalResults) {
            console.error('organize-results-modal not found!');
            return;
        }

        const searchTypeLabels = {
            'staff': 'Staff Member',
            'location': 'Location',
            'programType': 'Program Type',
            'keyword': 'General Keyword'
        };
        const searchTypeLabel = searchTypeLabels[searchType] || searchType;

        modalResults.classList.remove('d-none');

        if (modalResultsTitle) {
            modalResultsTitle.textContent = `Facilities with ${searchTypeLabel}: "${searchValue}"`;
        }
        if (modalResultsCount) {
            modalResultsCount.textContent = `Found ${results.length} result${results.length === 1 ? '' : 's'}`;
        }

        if (modalClearBtn) {
            modalClearBtn.classList.remove('d-none');
        }

        if (modalMatches) {
            if (results.length === 0) {
                modalMatches.innerHTML = '<p style="padding: 20px; text-align: center; color: #6b7280;">No matching facilities found.</p>';
            } else {
                modalMatches.innerHTML = results.map(result => {
                    const facilityName = result.facility.identification?.name || result.facility.identification?.currentName || 'Unnamed Facility';
                    const location = result.facility.location || '';
                    return `
                        <div style="padding: 15px; border-bottom: 1px solid #e5e7eb; cursor: pointer; transition: background 0.2s;"
                             onclick="window.organizerModal.goToFacility('${result.projectName.replace(/'/g, "\\'")}', ${result.facilityIndex})"
                             onmouseover="this.style.background='#f3f4f6'"
                             onmouseout="this.style.background='transparent'">
                            <div style="font-weight: 600; color: #1f2937;">${facilityName}</div>
                            <div style="font-size: 13px; color: #6b7280; margin-top: 4px;">
                                <span style="color: #33A7B5;">${result.projectName}</span>
                                ${location ? ` • ${location}` : ''}
                            </div>
                            <div style="font-size: 12px; color: #9ca3af; margin-top: 4px;">
                                Matches: ${result.matches.join(', ')}
                            </div>
                        </div>
                    `;
                }).join('');
            }
        }
    }

    /**
     * Clear search results
     */
    clearResults() {
        const modalResults = document.getElementById('organize-results-modal');
        const modalMatches = document.getElementById('organize-matches-modal');
        const modalClearBtn = document.getElementById('organize-clear-btn-modal');
        const modalValueInput = document.getElementById('organize-value-modal');

        if (modalResults) {
            modalResults.classList.add('d-none');
        }
        if (modalClearBtn) {
            modalClearBtn.classList.add('d-none');
        }
        if (modalValueInput) {
            modalValueInput.value = '';
        }
        if (modalMatches) {
            modalMatches.innerHTML = '';
        }
    }

    /**
     * Navigate to a specific facility
     */
    async goToFacility(projectName, facilityIndex) {
        console.log(`🎯 goToFacility called: project="${projectName}", facility=${facilityIndex}`);

        // Hide the modal first
        const modal = document.getElementById('data-organizer-modal');
        if (modal) {
            modal.classList.remove('active');
        }

        // Show the requested facility using whichever navigation API this page provides.
        const showFacility = (index) => {
            if (typeof window.loadFacility === 'function') {
                window.loadFacility(index);
            } else if (typeof window.navigateToFacility === 'function') {
                window.navigateToFacility(index);
            } else if (window.currentFacilityIndex !== undefined) {
                window.currentFacilityIndex = index;
                if (typeof window.updateAllUI === 'function') {
                    window.updateAllUI();
                } else if (typeof window.renderFacilityToForm === 'function') {
                    window.renderFacilityToForm();
                }
            }
        };

        // Poll until the project's facilities are actually loaded (up to ~5s)
        // instead of a single fixed delay that loses the race on slow loads.
        const navigateWhenReady = (index, attempts = 20) => {
            const facilities = window.formData?.facilities;
            if (Array.isArray(facilities) && facilities.length > index) {
                showFacility(index);
                return;
            }
            if (attempts > 0) {
                setTimeout(() => navigateWhenReady(index, attempts - 1), 250);
            } else {
                console.error('goToFacility: project facilities never loaded');
                this.announceStatus('Unable to open that facility. Please try manually.', 'error');
            }
        };

        // Use loadProjectAndSync if available (admin page)
        if (typeof window.loadProjectAndSync === 'function') {
            await window.loadProjectAndSync(projectName);
            navigateWhenReady(facilityIndex);
        }
        // Use loadProject if available (standard data form)
        else if (window.projectManager && typeof window.projectManager.loadProject === 'function') {
            window.projectManager.loadProject(projectName);
            navigateWhenReady(facilityIndex);
        }
        else {
            console.error('No project loading function available');
            this.announceStatus('Unable to load project. Please try manually.', 'error');
        }
    }
}

// Create singleton instance
const organizerModal = new OrganizerModal();
window.organizerModal = organizerModal;

// Export global functions for backward compatibility
window.performOrganizedSearchModal = () => organizerModal.performSearch();
window.displayOrganizerResultsModal = (results, searchType, searchValue) => organizerModal.displayResults(results, searchType, searchValue);
window.clearOrganizerResultsModal = () => organizerModal.clearResults();
window.goToFacility = (projectName, facilityIndex) => organizerModal.goToFacility(projectName, facilityIndex);

console.log('[Custom Modals] Loaded successfully - modern UI ready!');
