// ============================================
// ADMIN DATA MANAGEMENT
// Admin-specific functionality for managing facility data
// ============================================

console.log('admin-data.js loaded');

// Admin-specific initialization
document.addEventListener('DOMContentLoaded', () => {
    console.log('Admin data page initializing...');

    // Verify we have access to the main form functions
    if (typeof window.updateAllUI !== 'function') {
        console.warn('Main form functions not yet loaded, waiting...');
        setTimeout(() => {
            if (typeof window.updateAllUI === 'function') {
                console.log(' Main form functions now available');
                initializeAdminFeatures();
            } else {
                console.error('L Main form functions failed to load');
            }
        }, 1000);
    } else {
        initializeAdminFeatures();
    }
});

function initializeAdminFeatures() {
    console.log('Initializing admin-specific features...');

    // Add admin-specific event listeners
    setupAdminControls();
    setupBulkOperations();
    setupDataValidation();

    console.log(' Admin features initialized');
}

// ============================================
// ADMIN CONTROLS
// ============================================
function setupAdminControls() {
    // Add any admin-only buttons or controls here
    const adminToolbar = document.getElementById('admin-toolbar');
    if (adminToolbar) {
        console.log('Admin toolbar found, setting up controls');

        // Example: Bulk delete button
        const bulkDeleteBtn = document.getElementById('bulk-delete-btn');
        if (bulkDeleteBtn) {
            bulkDeleteBtn.addEventListener('click', handleBulkDelete);
        }

        // Example: Export all data button
        const exportAllBtn = document.getElementById('export-all-btn');
        if (exportAllBtn) {
            exportAllBtn.addEventListener('click', handleExportAll);
        }

        // Example: Validate all data button
        const validateBtn = document.getElementById('validate-all-btn');
        if (validateBtn) {
            validateBtn.addEventListener('click', handleValidateAll);
        }
    }
}

// ============================================
// BULK OPERATIONS
// ============================================
function setupBulkOperations() {
    console.log('Setting up bulk operations...');

    // Add bulk operation functionality here
}

function handleBulkDelete() {
    if (!confirm('Are you sure you want to delete selected items? This cannot be undone.')) {
        return;
    }

    console.log('Bulk delete initiated...');
    // Add bulk delete logic here
}

function handleExportAll() {
    console.log('Exporting all data...');

    try {
        const allData = {
            projects: window.projects || {},
            customData: {
                operators: JSON.parse(localStorage.getItem('customOperators') || '[]'),
                facilities: JSON.parse(localStorage.getItem('customFacilityNames') || '[]'),
                types: JSON.parse(localStorage.getItem('customFacilityTypes') || '[]'),
                // Add more as needed
            },
            exportDate: new Date().toISOString(),
            totalProjects: Object.keys(window.projects || {}).length
        };

        const jsonString = JSON.stringify(allData, null, 2);
        const blob = new Blob([jsonString], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `kids-over-profits-full-export-${Date.now()}.json`;
        a.click();
        URL.revokeObjectURL(url);

        console.log(' Export complete');
        alert('All data exported successfully!');
    } catch (error) {
        console.error('L Export failed:', error);
        alert('Export failed: ' + error.message);
    }
}

// ============================================
// DATA VALIDATION
// ============================================
function setupDataValidation() {
    console.log('Setting up data validation...');
}

function handleValidateAll() {
    console.log('Validating all data...');

    if (!window.projects || Object.keys(window.projects).length === 0) {
        alert('No projects to validate');
        return;
    }

    const issues = [];
    let totalFacilities = 0;
    let facilitiesWithIssues = 0;

    Object.keys(window.projects).forEach(projectName => {
        const project = window.projects[projectName];

        // Check if project has data
        if (!project.data) {
            issues.push(`Project "${projectName}" has no data`);
            return;
        }

        // Check facilities
        if (!project.data.facilities || !Array.isArray(project.data.facilities)) {
            issues.push(`Project "${projectName}" has no facilities array`);
            return;
        }

        project.data.facilities.forEach((facility, index) => {
            totalFacilities++;
            const facilityIssues = [];

            // Check required fields
            if (!facility.identification || !facility.identification.name) {
                facilityIssues.push('Missing facility name');
            }

            if (!facility.location) {
                facilityIssues.push('Missing location');
            }

            if (!facility.facilityDetails || !facility.facilityDetails.type) {
                facilityIssues.push('Missing facility type');
            }

            if (facilityIssues.length > 0) {
                facilitiesWithIssues++;
                const facilityName = facility.identification?.name || `Facility #${index + 1}`;
                issues.push(`Project "${projectName}" - ${facilityName}: ${facilityIssues.join(', ')}`);
            }
        });
    });

    // Display results
    console.log('Validation complete:', {
        totalProjects: Object.keys(window.projects).length,
        totalFacilities,
        facilitiesWithIssues,
        issuesFound: issues.length
    });

    if (issues.length === 0) {
        alert(` Validation passed!\n\nTotal Projects: ${Object.keys(window.projects).length}\nTotal Facilities: ${totalFacilities}\n\nNo issues found.`);
    } else {
        const message = `  Validation found issues:\n\n` +
            `Total Projects: ${Object.keys(window.projects).length}\n` +
            `Total Facilities: ${totalFacilities}\n` +
            `Facilities with issues: ${facilitiesWithIssues}\n\n` +
            `Issues:\n${issues.slice(0, 20).join('\n')}` +
            (issues.length > 20 ? `\n\n...and ${issues.length - 20} more issues` : '');

        alert(message);
        console.log('All issues:', issues);
    }
}

// ============================================
// ADMIN UTILITIES
// ============================================

// Make admin functions globally available
window.adminData = {
    exportAll: handleExportAll,
    validateAll: handleValidateAll,
    bulkDelete: handleBulkDelete
};

console.log(' admin-data.js loaded and ready');
