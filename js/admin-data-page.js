// ============================================
// ADMIN DATA PAGE FUNCTIONALITY
// Page-specific scripts for admin-data.html (data-admin slug)
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

// Independent consultant toggle functionality
const independentToggle = document.getElementById('referrer-independent-toggle');
const agencySliderTrack = document.getElementById('referrer-agency-slider-track');
const agencySliderKnob = document.getElementById('referrer-agency-slider-knob');
const agencySection = document.getElementById('referrer-agency-section');

function updateAgencySliderAppearance() {
    if (!agencySliderTrack || !agencySliderKnob || !independentToggle) return;

    if (independentToggle.checked) {
        // Independent consultant - toggle is ON
        agencySliderTrack.style.backgroundColor = '#10b981';
        agencySliderKnob.style.transform = 'translateX(24px)';
        if (agencySection) agencySection.style.display = 'none';
    } else {
        // Agency-affiliated - toggle is OFF
        agencySliderTrack.style.backgroundColor = '#e5e7eb';
        agencySliderKnob.style.transform = 'translateX(0px)';
        if (agencySection) agencySection.style.display = 'block';
    }
}

if (agencySliderTrack && independentToggle) {
    agencySliderTrack.addEventListener('click', function() {
        independentToggle.checked = !independentToggle.checked;
        updateAgencySliderAppearance();
    });
}

window.updateAgencySliderAppearance = updateAgencySliderAppearance;

function showSuggestionStatus(message, type) {
    const statusDiv = document.getElementById('suggestion-status');
    if (!statusDiv) return;
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
            console.log('Report generator not available');
        }
    };
}

function initializeSectionToggles() {
    const sections = document.querySelectorAll('.section');

    sections.forEach(section => {
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

        // Initialize with existing expanded state
        setState(section.classList.contains('expanded'));

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
}

// Initialize section toggles when page loads
window.addEventListener('load', () => {
    initializeSectionToggles();
});

// Initialize admin page when form is ready
document.addEventListener('formReady', () => {
    console.log('🚀 Admin page ready');
});
