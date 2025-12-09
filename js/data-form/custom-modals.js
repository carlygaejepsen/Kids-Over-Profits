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

console.log('[Custom Modals] Loaded successfully - modern UI ready!');
