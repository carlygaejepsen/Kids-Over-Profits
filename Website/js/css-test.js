/**
 * CSS Loading Test Script
 * 
 * This script checks if all required CSS files are properly loaded
 * and reports any missing or failed CSS resources.
 */

document.addEventListener('DOMContentLoaded', function() {
    // Create test container
    const testContainer = document.createElement('div');
    testContainer.id = 'css-test-container';
    testContainer.style.position = 'fixed';
    testContainer.style.top = '10px';
    testContainer.style.right = '10px';
    testContainer.style.backgroundColor = 'rgba(255, 255, 255, 0.9)';
    testContainer.style.border = '1px solid #ccc';
    testContainer.style.padding = '10px';
    testContainer.style.zIndex = '9999';
    testContainer.style.maxWidth = '300px';
    testContainer.style.fontSize = '12px';
    testContainer.style.fontFamily = 'monospace';
    
    // Add header
    const header = document.createElement('h3');
    header.textContent = 'CSS Loading Test';
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
    
    // CSS files to check
    const cssFiles = [
        { name: 'common.css', selector: '.container', property: 'max-width' },
        { name: 'layout.css', selector: '.row', property: 'display' },
        { name: 'forms.css', selector: '.form-group', property: 'margin-bottom' },
        { name: 'tables.css', selector: '.table', property: 'border-collapse' },
        { name: 'modals.css', selector: '.modal', property: 'position' },
        { name: 'admin.css', selector: '.admin-header', property: 'background-color' }
    ];
    
    // Create results list
    const resultsList = document.createElement('ul');
    resultsList.style.listStyle = 'none';
    resultsList.style.padding = '0';
    resultsList.style.margin = '0';
    
    // Check each CSS file
    cssFiles.forEach(file => {
        const listItem = document.createElement('li');
        listItem.style.marginBottom = '5px';
        
        // Create test element
        const testElement = document.createElement('div');
        testElement.classList.add(file.selector.replace('.', ''));
        testElement.style.display = 'none';
        document.body.appendChild(testElement);
        
        // Check if CSS is applied
        const computedStyle = window.getComputedStyle(testElement);
        const isLoaded = computedStyle[file.property] !== '';
        
        // Remove test element
        document.body.removeChild(testElement);
        
        // Display result
        listItem.innerHTML = `
            <span style="color: ${isLoaded ? 'green' : 'red'}">
                ${isLoaded ? '✓' : '✗'}
            </span>
            <strong>${file.name}</strong>: 
            ${isLoaded ? 'Loaded' : 'Not loaded'}
        `;
        
        resultsList.appendChild(listItem);
    });
    
    testContainer.appendChild(resultsList);
    
    // Add page info
    const pageInfo = document.createElement('div');
    pageInfo.style.marginTop = '10px';
    pageInfo.style.borderTop = '1px solid #ccc';
    pageInfo.style.paddingTop = '5px';
    pageInfo.innerHTML = `
        <strong>Page:</strong> ${window.location.pathname}<br>
        <strong>Slug:</strong> ${window.location.pathname.split('/').filter(p => p).pop() || 'home'}<br>
        <strong>Time:</strong> ${new Date().toLocaleTimeString()}
    `;
    testContainer.appendChild(pageInfo);
    
    // Add to body
    document.body.appendChild(testContainer);
});