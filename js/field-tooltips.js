/**
 * Field Tooltips System
 * Adds helpful tooltips to form fields explaining expected data
 */

// Tooltip definitions for all form fields
const FIELD_TOOLTIPS = {
    // Parent Company/Operator Fields
    'operator-name': 'Enter the name of the parent company or organization that owns/operates facilities. Check autocomplete suggestions to avoid duplicates.',
    'operator-current-name': 'If the company has changed its name, enter the current/most recent name here.',
    'operator-other-names': 'List any previous names, DBAs (Doing Business As), or alternative names the company has used. Separate multiple names with commas.',
    'operator-location-city': 'City where the parent company is primarily located or registered.',
    'operator-location-state': 'State or province where the parent company is located. Use 2-letter abbreviations (e.g., CA, NY, TN).',
    'operator-location-country': 'Only fill this if the company is outside the USA. Leave blank for U.S. companies.',
    'operator-headquarters-city': 'City where the company\'s main headquarters office is located.',
    'operator-headquarters-state': 'State where headquarters is located. Use 2-letter codes.',
    'operator-headquarters-country': 'Country of headquarters if outside USA.',
    'operator-founded': 'The year the company was founded or established (e.g., 1985).',
    'operator-period': 'Years the company was/has been in operation (e.g., 1985-Present or 1990-2005).',
    'operator-status': 'Current status: Active, Defunct, Acquired, Merged, Bankrupt, etc.',
    'operator-ceo': 'Current or most recent CEO, President, or Executive Director.',
    'operator-notes': 'Any additional information about the parent company, ownership changes, corporate structure, etc.',

    // Facility Identification Fields
    'facility-name': 'The official name of the facility/program. Check autocomplete to see if it already exists in our database.',
    'facility-type': 'Type of program: RTC (Residential Treatment Center), Therapeutic Boarding School, Wilderness Program, Boot Camp, etc.',

    // Location Fields
    'international-program-toggle': 'Toggle ON if this program is located outside the United States.',

    // Operating Details
    'operator-notes': 'General notes about operations, significant events, ownership changes, etc.',

    // Facility Details
    'capacity': 'Maximum number of residents/students the facility can hold.',
    'current-census': 'Current number of residents/students (if known).',
    'min-age': 'Minimum age of residents accepted (e.g., 12).',
    'max-age': 'Maximum age of residents accepted (e.g., 18).',
    'gender': 'Gender served: Male, Female, Co-ed, etc.',

    // Resources
    'has-news': 'Check if you have news articles about this facility.',
    'has-press': 'Check if you have official press releases.',
    'has-inspections': 'Check if you have state inspection reports.',
    'has-lawsuits': 'Check if there are lawsuits filed against the facility.',
    'has-survivor-stories': 'Check if there are survivor testimonies or stories available.',

    // Consultant/Referrer Fields
    'consultant-firstname': 'First name of the education consultant or referrer.',
    'consultant-lastname': 'Last name of the education consultant or referrer.',
    'consultant-credentials': 'Professional credentials, licenses, or titles (e.g., IECA, MA, PhD).',
    'consultant-city': 'City where the consultant is based.',
    'consultant-state': 'State where the consultant operates. Use 2-letter codes.',
    'consultant-email': 'Professional email address of the consultant.',
    'consultant-phone': 'Professional phone number.',
    'consultant-notes': 'Any additional information about this consultant\'s practices, affiliations, or history.',

    // Agency Fields
    'referrer-agency-name': 'Name of the consulting agency or group this consultant belongs to.',
    'referrer-agency-city': 'City where the agency is headquartered.',
    'referrer-agency-state': 'State where the agency is based.',
    'referrer-agency-notes': 'Information about the agency, its practices, and history.',

    // Project Fields
    'company-search-input': 'Search your saved projects by company name, program type, or any keyword.',
    'location-search-input': 'Search your saved location projects by state, country, or keyword.',
    'referrer-search-input': 'Search your saved referrer projects by consultant or agency name.',

    // Profit Status
    'profit-status-badge': 'Click to toggle between For-Profit and Non-Profit status for this organization.'
};

// Toolbar button tooltips
const TOOLBAR_TOOLTIPS = {
    'new-project-btn-toolbar': 'Create a brand new project to start collecting data.',
    'generate-report-btn-toolbar': 'Generate a formatted report of your current project data.',
    'add-facility-btn-toolbar': 'Add a new facility/program entry to your current project.',
    'scroll-to-top-btn-toolbar': 'Quickly scroll back to the top of the page.',
    'prev-facility-btn-toolbar': 'Navigate to the previous facility in your project.',
    'next-facility-btn-toolbar': 'Navigate to the next facility in your project.',
    'show-organizer-modal-btn': 'Search and organize your facility data by various criteria.',
    'submit-suggestion-btn-toolbar': 'Submit your project data for review and inclusion in the master database.',
    'facility-dropdown': 'Select a specific facility from your project to view and edit.'
};

/**
 * Create a tooltip icon with text
 */
function createTooltip(tooltipText) {
    const wrapper = document.createElement('span');
    wrapper.className = 'field-tooltip-wrapper';

    const icon = document.createElement('span');
    icon.className = 'field-tooltip-icon';
    icon.innerHTML = '?';
    icon.setAttribute('tabindex', '0');
    icon.setAttribute('role', 'tooltip');
    icon.setAttribute('aria-label', tooltipText);

    const text = document.createElement('span');
    text.className = 'field-tooltip-text';
    text.textContent = tooltipText;

    icon.appendChild(text);
    wrapper.appendChild(icon);

    // Add mobile touch support
    let touchTimeout;

    // Touch/click to toggle tooltip on mobile
    icon.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();

        // Close any other open tooltips
        document.querySelectorAll('.field-tooltip-icon.active').forEach(other => {
            if (other !== icon) {
                other.classList.remove('active');
            }
        });

        // Toggle this tooltip
        icon.classList.toggle('active');

        // Auto-close after 5 seconds on mobile
        if (icon.classList.contains('active')) {
            clearTimeout(touchTimeout);
            touchTimeout = setTimeout(() => {
                icon.classList.remove('active');
            }, 5000);
        }
    });

    // Close tooltip when clicking outside
    document.addEventListener('click', (e) => {
        if (!icon.contains(e.target) && icon.classList.contains('active')) {
            icon.classList.remove('active');
        }
    });

    // Keyboard accessibility
    icon.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            icon.click();
        }
        if (e.key === 'Escape' && icon.classList.contains('active')) {
            icon.classList.remove('active');
        }
    });

    return wrapper;
}

/**
 * Add tooltip to a form field by its ID
 */
function addTooltipToField(fieldId, tooltipText) {
    const field = document.getElementById(fieldId);
    if (!field) return;

    // Find the label for this field
    const label = document.querySelector(`label[for="${fieldId}"]`) ||
                  field.closest('.form-group')?.querySelector('label');

    if (label && !label.querySelector('.field-tooltip-wrapper')) {
        const tooltip = createTooltip(tooltipText);
        label.appendChild(tooltip);
        label.classList.add('has-tooltip');
    }
}

/**
 * Add tooltip to a label element directly
 */
function addTooltipToLabel(labelText, tooltipText) {
    const labels = document.querySelectorAll('label');
    labels.forEach(label => {
        if (label.textContent.trim() === labelText && !label.querySelector('.field-tooltip-wrapper')) {
            const tooltip = createTooltip(tooltipText);
            label.appendChild(tooltip);
            label.classList.add('has-tooltip');
        }
    });
}

/**
 * Add tooltip to toolbar button
 */
function addTooltipToButton(buttonId, tooltipText) {
    const button = document.getElementById(buttonId);
    if (button && !button.title) {
        button.title = tooltipText;
    }
}

/**
 * Initialize all tooltips
 */
function initializeFieldTooltips() {
    // Add tooltips to all defined fields
    Object.entries(FIELD_TOOLTIPS).forEach(([fieldId, tooltipText]) => {
        addTooltipToField(fieldId, tooltipText);
    });

    // Add tooltips to toolbar buttons
    Object.entries(TOOLBAR_TOOLTIPS).forEach(([buttonId, tooltipText]) => {
        addTooltipToButton(buttonId, tooltipText);
    });

    // Add tooltips to common array fields
    addTooltipToLabel('Parent Companies', 'List all parent companies that own or have owned this operator.');
    addTooltipToLabel('Websites', 'Add official website URLs. One per line.');
    addTooltipToLabel('Founders', 'Names of individuals who founded this company.');
    addTooltipToLabel('Key Executives', 'Names and titles of important executives (e.g., "John Smith - CFO").');
    addTooltipToLabel('Investors', 'Investment firms or individuals who have funded this company.');
    addTooltipToLabel('Other Names', 'Previous facility names, DBAs, or alternative names.');
    addTooltipToLabel('Known Referrers (Education Consultants / School Districts)', 'Education consultants or school districts known to refer students to this facility.');
    addTooltipToLabel('Additional Locations', 'Other addresses or campuses operated by this facility.');
    addTooltipToLabel('Other Parent Companies', 'Additional companies that have owned or operated this facility.');
    addTooltipToLabel('Administrator', 'Directors, administrators, or facility managers.');
    addTooltipToLabel('Notable Staff', 'Staff members of interest (therapists, counselors, etc.).');
    addTooltipToLabel('Past TTI Employment (Role + Employer)', 'Previous TTI jobs held by staff (e.g., "Therapist at Other Facility").');
    addTooltipToLabel('Profile Links', 'LinkedIn profiles, personal websites, or other online profiles for staff.');
    addTooltipToLabel('Current Accreditations', 'Active accreditations (e.g., "Joint Commission", "CARF").');
    addTooltipToLabel('Past Accreditations', 'Previous accreditations that are no longer active.');
    addTooltipToLabel('Professional Memberships', 'Industry associations or memberships (e.g., "NATSAP", "IECA").');
    addTooltipToLabel('Certifications', 'Specialized certifications held by the facility.');
    addTooltipToLabel('Licensing Information', 'State licensing details, license numbers, etc.');
    addTooltipToLabel('Professional Affiliations', 'Organizations the consultant belongs to (e.g., IECA, HECA).');
    addTooltipToLabel('Facilities Referred To', 'Names of TTI facilities this consultant has referred students to.');
    addTooltipToLabel('School Districts Worked With', 'School districts that have hired this consultant.');
    addTooltipToLabel('Key Personnel', 'Important people associated with this agency.');

    console.log('Field tooltips initialized');
}

// Initialize tooltips when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(initializeFieldTooltips, 500));
} else {
    // DOM already loaded
    setTimeout(initializeFieldTooltips, 500);
}

// Re-run once after form data and UI are ready (for dynamic sections/tabs).
document.addEventListener('formReady', initializeFieldTooltips, { once: true });

// Re-initialize when new content is added (for dynamically created fields)
window.addEventListener('contentLoaded', initializeFieldTooltips);

// Export for use in other scripts
window.KOPTooltips = {
    createTooltip,
    addTooltipToField,
    addTooltipToLabel,
    initialize: initializeFieldTooltips
};
