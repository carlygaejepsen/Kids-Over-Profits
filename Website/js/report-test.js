/**
 * Report Data Loading Test Script
 * 
 * This script checks if state report data is properly loaded
 * and displays diagnostic information.
 */

document.addEventListener('DOMContentLoaded', function() {
    // Create test container
    const testContainer = document.createElement('div');
    testContainer.id = 'report-test-container';
    testContainer.style.position = 'fixed';
    testContainer.style.top = '10px';
    testContainer.style.right = '10px';
    testContainer.style.backgroundColor = 'rgba(255, 255, 255, 0.9)';
    testContainer.style.border = '1px solid #ccc';
    testContainer.style.padding = '10px';
    testContainer.style.zIndex = '9999';
    testContainer.style.maxWidth = '400px';
    testContainer.style.maxHeight = '80vh';
    testContainer.style.overflowY = 'auto';
    testContainer.style.fontSize = '12px';
    testContainer.style.fontFamily = 'monospace';
    
    // Add header
    const header = document.createElement('h3');
    header.textContent = 'Report Data Loading Test';
    header.style.margin = '0 0 10px 0';
    header.style.fontSize = '14px';
    testContainer.appendChild(header);
    
    // Add close button
    const closeButton = document.createElement('button');
    closeButton.textContent = 'Close';
    closeButton.style.position = 'absolute';
    closeButton.style.top = '5px';
    closeButton.style.right = '5px';
    closeButton.style.fontSize = '10px';
    closeButton.style.padding = '2px 5px';
    closeButton.addEventListener('click', function() {
        document.body.removeChild(testContainer);
    });
    testContainer.appendChild(closeButton);
    
    // Create results container
    const resultsContainer = document.createElement('div');
    
    // Check if myThemeData is defined
    if (typeof myThemeData !== 'undefined') {
        resultsContainer.innerHTML += `<p><strong>Data Object:</strong> Found</p>`;
        
        // Check if jsonFileUrls exists
        if (myThemeData.jsonFileUrls) {
            resultsContainer.innerHTML += `
                <p><strong>JSON URLs:</strong> ${myThemeData.jsonFileUrls.length} file(s) configured</p>
                <ul style="margin: 0; padding-left: 20px;">
                    ${myThemeData.jsonFileUrls.map(url => `<li>${url}</li>`).join('')}
                </ul>
            `;
            
            // Test loading each JSON file
            resultsContainer.innerHTML += `<p><strong>Testing JSON loading:</strong></p>`;
            
            const resultsList = document.createElement('ul');
            resultsList.style.margin = '0';
            resultsList.style.paddingLeft = '20px';
            
            let completedRequests = 0;
            
            myThemeData.jsonFileUrls.forEach((url, index) => {
                const listItem = document.createElement('li');
                listItem.textContent = `${url}: Testing...`;
                resultsList.appendChild(listItem);
                
                fetch(url)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP error ${response.status}`);
                        }
                        return response.json();
                    })
                    .then(data => {
                        // Check if data has expected structure
                        const hasExpectedStructure = data && 
                            (data.facilities || data.reports || 
                             (Array.isArray(data) && data.length > 0));
                        
                        if (hasExpectedStructure) {
                            const facilityCount = data.facilities ? 
                                data.facilities.length : 
                                (Array.isArray(data) ? data.length : 'N/A');
                            
                            listItem.innerHTML = `
                                <span style="color: green">✓</span> 
                                ${url}: Loaded successfully 
                                (${facilityCount} facilities/reports)
                            `;
                        } else {
                            listItem.innerHTML = `
                                <span style="color: orange">⚠</span> 
                                ${url}: Loaded but has unexpected structure
                            `;
                        }
                    })
                    .catch(error => {
                        listItem.innerHTML = `
                            <span style="color: red">✗</span> 
                            ${url}: Error - ${error.message}
                        `;
                    })
                    .finally(() => {
                        completedRequests++;
                        if (completedRequests === myThemeData.jsonFileUrls.length) {
                            // All requests completed, add summary
                            const summary = document.createElement('p');
                            summary.innerHTML = `<strong>All tests completed at ${new Date().toLocaleTimeString()}</strong>`;
                            resultsContainer.appendChild(summary);
                        }
                    });
            });
            
            resultsContainer.appendChild(resultsList);
        } else if (typeof facilitiesConfig !== 'undefined' && facilitiesConfig.jsonDataUrl) {
            // Special case for facilities display
            resultsContainer.innerHTML += `
                <p><strong>Facilities Config:</strong> Found</p>
                <p><strong>JSON URL:</strong> ${facilitiesConfig.jsonDataUrl}</p>
            `;
            
            // Test loading the JSON file
            resultsContainer.innerHTML += `<p><strong>Testing JSON loading:</strong></p>`;
            
            const resultsList = document.createElement('ul');
            resultsList.style.margin = '0';
            resultsList.style.paddingLeft = '20px';
            
            const listItem = document.createElement('li');
            listItem.textContent = `${facilitiesConfig.jsonDataUrl}: Testing...`;
            resultsList.appendChild(listItem);
            
            fetch(facilitiesConfig.jsonDataUrl)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    // Check if data has expected structure
                    const hasExpectedStructure = data && 
                        (data.facilities || data.projects || 
                         (Array.isArray(data) && data.length > 0));
                    
                    if (hasExpectedStructure) {
                        const itemCount = data.facilities ? data.facilities.length : 
                            (data.projects ? data.projects.length : 
                             (Array.isArray(data) ? data.length : 'N/A'));
                        
                        listItem.innerHTML = `
                            <span style="color: green">✓</span> 
                            ${facilitiesConfig.jsonDataUrl}: Loaded successfully 
                            (${itemCount} items)
                        `;
                    } else {
                        listItem.innerHTML = `
                            <span style="color: orange">⚠</span> 
                            ${facilitiesConfig.jsonDataUrl}: Loaded but has unexpected structure
                        `;
                    }
                })
                .catch(error => {
                    listItem.innerHTML = `
                        <span style="color: red">✗</span> 
                        ${facilitiesConfig.jsonDataUrl}: Error - ${error.message}
                    `;
                })
                .finally(() => {
                    // Add summary
                    const summary = document.createElement('p');
                    summary.innerHTML = `<strong>Test completed at ${new Date().toLocaleTimeString()}</strong>`;
                    resultsContainer.appendChild(summary);
                });
            
            resultsContainer.appendChild(resultsList);
        } else {
            resultsContainer.innerHTML += `
                <p style="color: red"><strong>Error:</strong> No JSON URLs found in myThemeData</p>
            `;
        }
    } else if (typeof facilitiesConfig !== 'undefined') {
        resultsContainer.innerHTML += `
            <p><strong>Data Object:</strong> facilitiesConfig found</p>
            <p><strong>JSON URL:</strong> ${facilitiesConfig.jsonDataUrl || 'Not defined'}</p>
        `;
        
        // Test loading if URL exists
        if (facilitiesConfig.jsonDataUrl) {
            // Similar fetch test as above
            // (Code omitted for brevity - same pattern as the facilitiesConfig test above)
        }
    } else {
        resultsContainer.innerHTML += `
            <p style="color: red"><strong>Error:</strong> No data object found (myThemeData or facilitiesConfig)</p>
            <p>This could indicate that the JavaScript for this page is not loading correctly.</p>
        `;
    }
    
    // Add page info
    const pageInfo = document.createElement('div');
    pageInfo.style.marginTop = '10px';
    pageInfo.style.borderTop = '1px solid #ccc';
    pageInfo.style.paddingTop = '5px';
    pageInfo.innerHTML = `
        <strong>Page:</strong> ${window.location.pathname}<br>
        <strong>Slug:</strong> ${window.location.pathname.split('/').filter(p => p).pop() || 'home'}<br>
        <strong>Scripts Loaded:</strong> ${document.scripts.length}<br>
        <strong>Time:</strong> ${new Date().toLocaleTimeString()}
    `;
    
    testContainer.appendChild(resultsContainer);
    testContainer.appendChild(pageInfo);
    
    // Add to body
    document.body.appendChild(testContainer);
});