// Set mode to suggestions
window.FORM_MODE = 'suggestions';

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
    
    try {
        const response = await fetch('https://kidsoverprofits.org/wp-content/themes/child/api/save-suggestion.php', {
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