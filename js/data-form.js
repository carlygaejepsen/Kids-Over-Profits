// Set mode to suggestions
window.FORM_MODE = 'suggestions';

const DATA_FORM_CONFIG = window.KOP_FACILITY_FORM_CONFIG || {};
const DATA_FORM_DEFAULT_ENDPOINT = '/api/data_form/save-suggestion.php';

function normaliseBaseCandidate(value) {
    if (typeof value !== 'string') {
        return '';
    }

    return value.trim().replace(/\/$/, '');
}

function collectBaseCandidates() {
    const candidates = [];
    const { apiBase, apiBaseFallbacks } = DATA_FORM_CONFIG;

    if (Array.isArray(apiBase)) {
        candidates.push(...apiBase);
    } else if (typeof apiBase === 'string' && apiBase) {
        candidates.push(apiBase);
    }

    if (Array.isArray(apiBaseFallbacks)) {
        candidates.push(...apiBaseFallbacks);
    }

    if (Array.isArray(window.KOP_THEME_BASES)) {
        candidates.push(...window.KOP_THEME_BASES);
    }

    if (typeof window.KOP_RESOLVED_THEME_BASE === 'string' && window.KOP_RESOLVED_THEME_BASE) {
        candidates.push(window.KOP_RESOLVED_THEME_BASE);
    }

    if (typeof window.KOP_DETECTED_THEME_BASE === 'string' && window.KOP_DETECTED_THEME_BASE) {
        candidates.push(window.KOP_DETECTED_THEME_BASE);
    }

    if (typeof window.KOP_RESOLVED_LOCAL_BASE === 'string' && window.KOP_RESOLVED_LOCAL_BASE) {
        candidates.push(window.KOP_RESOLVED_LOCAL_BASE);
    }

    if (typeof window.KOP_LOCAL_BASE === 'string' && window.KOP_LOCAL_BASE) {
        candidates.push(window.KOP_LOCAL_BASE);
    }

    if (typeof window.location === 'object' && window.location && window.location.origin) {
        candidates.push(window.location.origin + '/themes/child');
        candidates.push(window.location.origin + '/wp-content/themes/child');
    }

    return Array.from(new Set(candidates.map(normaliseBaseCandidate).filter(Boolean)));
}

function resolveEndpointUrl(path) {
    if (typeof path !== 'string' || !path) {
        return '';
    }

    if (/^https?:\/\//i.test(path)) {
        return path;
    }

    const normalizedPath = path.startsWith('/') ? path : `/${path}`;
    const candidates = collectBaseCandidates();

    for (const base of candidates) {
        if (!base) {
            continue;
        }

        return `${base}${normalizedPath}`;
    }

    return normalizedPath;
}

function getSuggestionEndpoint() {
    if (window.__KOP_SUGGESTION_ENDPOINT) {
        return window.__KOP_SUGGESTION_ENDPOINT;
    }

    const configuredPath =
        (DATA_FORM_CONFIG.endpoints && DATA_FORM_CONFIG.endpoints.SAVE_PROJECT) ||
        DATA_FORM_DEFAULT_ENDPOINT;

    const resolved = resolveEndpointUrl(configuredPath) || configuredPath;
    window.__KOP_SUGGESTION_ENDPOINT = resolved;
    return resolved;
}

async function submitSuggestion() {
    // Ask user to summarize their changes
    const changesSummary = prompt(
        'Please summarize the key changes you made for this suggestion:\n\n' +
        'Please summarize the key changes you made:\n\n' +
        '(Examples: "Added missing facility location", "Corrected operator founding date", ' +
        '"Added 3 new staff members", "Updated facility closure information", etc.)'
    );
    
    if (!changesSummary || changesSummary.trim() === '') {
        showSuggestionStatus('Please provide a summary of your changes to continue.', 'error');
        return;
    }
    
    // Try multiple ways to get the current project data
    let dataToSubmit = null;
    
    if (typeof formData !== 'undefined' && formData) {
        dataToSubmit = formData;
        console.log('✅ Using global formData:', dataToSubmit);
    } else if (window.globalFormData) {
        dataToSubmit = window.globalFormData;
        console.log('✅ Using window.globalFormData:', dataToSubmit);
    } else if (window.formData) {
        dataToSubmit = window.formData;
        console.log('✅ Using window.formData:', dataToSubmit);
    }
    
    if (!dataToSubmit) {
        showSuggestionStatus('❌ Error: No project data loaded. Please load a project first by clicking on one from the list above.', 'error');
        console.error('❌ No formData found. Available variables:', {
            'typeof formData': typeof formData,
            'window.globalFormData': window.globalFormData,
            'window.formData': window.formData
        });
        return;
    }
    
    // Check if we're in private facility mode and clear operator data if so
    const privateToggle = document.getElementById('private-ownership-toggle');
    if (privateToggle && privateToggle.checked) {
        console.log('🔒 Private facility mode detected - clearing operator data before submission');
        dataToSubmit = JSON.parse(JSON.stringify(dataToSubmit));
        if (dataToSubmit.operator) {
            dataToSubmit.operator = {}; // Clear operator data
        }
    }
    
    const actualProjectName = window.currentProjectName || currentProjectName || 'Unknown Project';
    
    // Force set the project name in the data being submitted
    dataToSubmit.projectName = actualProjectName;
    dataToSubmit.name = actualProjectName;
    
    console.log('🔧 FORCED project name in submission data:', actualProjectName);
    
    const suggestionEndpoint = getSuggestionEndpoint();

    try {
        const response = await fetch(suggestionEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                data: dataToSubmit,
                reason: changesSummary.trim(),
                projectName: actualProjectName,
                metadata: {
                    actualProjectName: actualProjectName,
                    submittedFrom: 'data.html suggestions form',
                    suggestionEndpoint: suggestionEndpoint,
                    timestamp: new Date().toISOString()
                }
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuggestionStatus(`☑️ Suggestion submitted successfully for "${actualProjectName}"! It will be reviewed before being added to the database.`, 'success');
        } else {
            showSuggestionStatus('❌ Error: ' + (result.error || 'Failed to submit suggestion'), 'error');
        }
    } catch (error) {
        showSuggestionStatus('❌ Error: ' + error.message, 'error');
    }
}

function showSuggestionStatus(message, type) {
    const statusDiv = document.getElementById('suggestion-status');
    if (statusDiv) {
        statusDiv.className = `upload-status ${type}`;
        statusDiv.textContent = message;
        statusDiv.style.display = 'block';
    }
}

// Make functions globally available
window.submitSuggestion = submitSuggestion;
window.showSuggestionStatus = showSuggestionStatus;