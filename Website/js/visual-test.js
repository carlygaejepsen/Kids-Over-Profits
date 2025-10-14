/**
 * Visual Regression Test Tool
 * 
 * This script helps identify visual display issues by:
 * 1. Highlighting elements with potential display problems
 * 2. Providing a grid overlay to check alignment
 * 3. Showing element dimensions and spacing
 * 
 * Usage: Add ?debug=visual to any page URL
 */

document.addEventListener('DOMContentLoaded', function() {
    // Create control panel
    const controlPanel = document.createElement('div');
    controlPanel.id = 'visual-test-panel';
    controlPanel.style.position = 'fixed';
    controlPanel.style.bottom = '10px';
    controlPanel.style.right = '10px';
    controlPanel.style.backgroundColor = 'rgba(0, 0, 0, 0.8)';
    controlPanel.style.color = 'white';
    controlPanel.style.padding = '10px';
    controlPanel.style.borderRadius = '5px';
    controlPanel.style.zIndex = '10000';
    controlPanel.style.fontSize = '12px';
    controlPanel.style.fontFamily = 'monospace';
    controlPanel.style.boxShadow = '0 0 10px rgba(0, 0, 0, 0.5)';
    
    // Add title
    const title = document.createElement('h4');
    title.textContent = 'Visual Test Tools';
    title.style.margin = '0 0 10px 0';
    title.style.fontSize = '14px';
    title.style.textAlign = 'center';
    controlPanel.appendChild(title);
    
    // Create button container
    const buttonContainer = document.createElement('div');
    buttonContainer.style.display = 'flex';
    buttonContainer.style.flexWrap = 'wrap';
    buttonContainer.style.gap = '5px';
    
    // Add grid overlay button
    const gridButton = document.createElement('button');
    gridButton.textContent = 'Toggle Grid';
    gridButton.style.flex = '1';
    gridButton.style.padding = '5px';
    gridButton.style.backgroundColor = '#333';
    gridButton.style.color = 'white';
    gridButton.style.border = '1px solid #555';
    gridButton.style.borderRadius = '3px';
    gridButton.style.cursor = 'pointer';
    
    let gridActive = false;
    let gridOverlay = null;
    
    gridButton.addEventListener('click', function() {
        if (gridActive) {
            // Remove grid
            if (gridOverlay) {
                document.body.removeChild(gridOverlay);
                gridOverlay = null;
            }
            gridActive = false;
            gridButton.style.backgroundColor = '#333';
        } else {
            // Create grid overlay
            gridOverlay = document.createElement('div');
            gridOverlay.style.position = 'fixed';
            gridOverlay.style.top = '0';
            gridOverlay.style.left = '0';
            gridOverlay.style.width = '100%';
            gridOverlay.style.height = '100%';
            gridOverlay.style.zIndex = '9999';
            gridOverlay.style.pointerEvents = 'none';
            
            // Create grid pattern
            const gridSize = 20; // pixels
            gridOverlay.style.backgroundImage = `
                linear-gradient(to right, rgba(255,0,0,0.1) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(255,0,0,0.1) 1px, transparent 1px)
            `;
            gridOverlay.style.backgroundSize = `${gridSize}px ${gridSize}px`;
            
            // Add to body
            document.body.appendChild(gridOverlay);
            gridActive = true;
            gridButton.style.backgroundColor = '#007bff';
        }
    });
    
    buttonContainer.appendChild(gridButton);
    
    // Add highlight issues button
    const highlightButton = document.createElement('button');
    highlightButton.textContent = 'Highlight Issues';
    highlightButton.style.flex = '1';
    highlightButton.style.padding = '5px';
    highlightButton.style.backgroundColor = '#333';
    highlightButton.style.color = 'white';
    highlightButton.style.border = '1px solid #555';
    highlightButton.style.borderRadius = '3px';
    highlightButton.style.cursor = 'pointer';
    
    let highlightActive = false;
    let highlightedElements = [];
    
    highlightButton.addEventListener('click', function() {
        if (highlightActive) {
            // Remove highlights
            highlightedElements.forEach(el => {
                el.style.outline = '';
            });
            highlightedElements = [];
            highlightActive = false;
            highlightButton.style.backgroundColor = '#333';
        } else {
            // Find potential issues
            
            // 1. Find elements with overflow issues
            const overflowElements = Array.from(document.querySelectorAll('*')).filter(el => {
                const style = window.getComputedStyle(el);
                const width = el.offsetWidth;
                const parentWidth = el.parentElement ? el.parentElement.offsetWidth : window.innerWidth;
                
                return (
                    width > parentWidth || // Wider than parent
                    (style.overflow === 'visible' && 
                     (el.scrollWidth > width || el.scrollHeight > el.offsetHeight)) // Content overflow
                );
            });
            
            // 2. Find elements with positioning issues
            const positionElements = Array.from(document.querySelectorAll('*')).filter(el => {
                const style = window.getComputedStyle(el);
                return (
                    (style.position === 'absolute' || style.position === 'fixed') &&
                    style.zIndex === 'auto' && // Might cause layering issues
                    (parseInt(style.top) < 0 || parseInt(style.left) < 0) // Negative positioning
                );
            });
            
            // 3. Find elements with unusual dimensions
            const dimensionElements = Array.from(document.querySelectorAll('*')).filter(el => {
                const width = el.offsetWidth;
                const height = el.offsetHeight;
                
                return (
                    (width > 0 && width < 5) || // Very narrow elements
                    (height > 0 && height < 5) || // Very short elements
                    (width > window.innerWidth * 1.1) // Wider than viewport
                );
            });
            
            // Combine and highlight
            const allIssueElements = [
                ...overflowElements.map(el => ({ el, issue: 'overflow' })),
                ...positionElements.map(el => ({ el, issue: 'position' })),
                ...dimensionElements.map(el => ({ el, issue: 'dimension' }))
            ];
            
            // Apply highlights
            allIssueElements.forEach(({ el, issue }) => {
                let color;
                switch (issue) {
                    case 'overflow': color = 'rgba(255, 0, 0, 0.5)'; break; // Red
                    case 'position': color = 'rgba(0, 0, 255, 0.5)'; break; // Blue
                    case 'dimension': color = 'rgba(255, 165, 0, 0.5)'; break; // Orange
                    default: color = 'rgba(128, 0, 128, 0.5)'; // Purple
                }
                
                el.style.outline = `2px solid ${color}`;
                highlightedElements.push(el);
            });
            
            highlightActive = true;
            highlightButton.style.backgroundColor = '#007bff';
            
            // Show count
            alert(`Found ${allIssueElements.length} potential display issues:\n` +
                  `- ${overflowElements.length} overflow issues (red)\n` +
                  `- ${positionElements.length} positioning issues (blue)\n` +
                  `- ${dimensionElements.length} dimension issues (orange)`);
        }
    });
    
    buttonContainer.appendChild(highlightButton);
    
    // Add measure tool button
    const measureButton = document.createElement('button');
    measureButton.textContent = 'Measure Tool';
    measureButton.style.flex = '1';
    measureButton.style.padding = '5px';
    measureButton.style.backgroundColor = '#333';
    measureButton.style.color = 'white';
    measureButton.style.border = '1px solid #555';
    measureButton.style.borderRadius = '3px';
    measureButton.style.cursor = 'pointer';
    
    let measureActive = false;
    let measureOverlay = null;
    let selectedElement = null;
    
    measureButton.addEventListener('click', function() {
        if (measureActive) {
            // Disable measure tool
            if (measureOverlay) {
                document.body.removeChild(measureOverlay);
                measureOverlay = null;
            }
            
            // Remove event listeners
            document.removeEventListener('mousemove', handleMouseMove);
            document.removeEventListener('click', handleClick);
            
            measureActive = false;
            measureButton.style.backgroundColor = '#333';
        } else {
            // Create measure overlay
            measureOverlay = document.createElement('div');
            measureOverlay.style.position = 'fixed';
            measureOverlay.style.top = '0';
            measureOverlay.style.left = '0';
            measureOverlay.style.width = '100%';
            measureOverlay.style.height = '100%';
            measureOverlay.style.zIndex = '9998';
            measureOverlay.style.pointerEvents = 'none';
            
            // Add info box
            const infoBox = document.createElement('div');
            infoBox.style.position = 'fixed';
            infoBox.style.top = '10px';
            infoBox.style.left = '10px';
            infoBox.style.backgroundColor = 'rgba(0, 0, 0, 0.8)';
            infoBox.style.color = 'white';
            infoBox.style.padding = '5px 10px';
            infoBox.style.borderRadius = '3px';
            infoBox.style.fontSize = '12px';
            infoBox.style.fontFamily = 'monospace';
            infoBox.style.zIndex = '10001';
            infoBox.textContent = 'Hover over elements to measure. Click to select.';
            measureOverlay.appendChild(infoBox);
            
            // Add to body
            document.body.appendChild(measureOverlay);
            
            // Add event listeners
            document.addEventListener('mousemove', handleMouseMove);
            document.addEventListener('click', handleClick);
            
            measureActive = true;
            measureButton.style.backgroundColor = '#007bff';
        }
    });
    
    function handleMouseMove(e) {
        const element = document.elementFromPoint(e.clientX, e.clientY);
        if (element && element !== measureOverlay && !measureOverlay.contains(element)) {
            // Highlight element
            measureOverlay.innerHTML = '';
            
            const rect = element.getBoundingClientRect();
            
            // Create highlight box
            const highlightBox = document.createElement('div');
            highlightBox.style.position = 'absolute';
            highlightBox.style.top = `${rect.top}px`;
            highlightBox.style.left = `${rect.left}px`;
            highlightBox.style.width = `${rect.width}px`;
            highlightBox.style.height = `${rect.height}px`;
            highlightBox.style.border = '1px dashed rgba(0, 255, 0, 0.8)';
            highlightBox.style.backgroundColor = 'rgba(0, 255, 0, 0.1)';
            measureOverlay.appendChild(highlightBox);
            
            // Add dimensions label
            const dimensionsLabel = document.createElement('div');
            dimensionsLabel.style.position = 'absolute';
            dimensionsLabel.style.top = `${rect.top - 20}px`;
            dimensionsLabel.style.left = `${rect.left}px`;
            dimensionsLabel.style.backgroundColor = 'rgba(0, 0, 0, 0.8)';
            dimensionsLabel.style.color = 'white';
            dimensionsLabel.style.padding = '2px 5px';
            dimensionsLabel.style.borderRadius = '3px';
            dimensionsLabel.style.fontSize = '10px';
            dimensionsLabel.style.whiteSpace = 'nowrap';
            dimensionsLabel.textContent = `${Math.round(rect.width)} × ${Math.round(rect.height)}`;
            measureOverlay.appendChild(dimensionsLabel);
            
            // Add info box
            const infoBox = document.createElement('div');
            infoBox.style.position = 'fixed';
            infoBox.style.top = '10px';
            infoBox.style.left = '10px';
            infoBox.style.backgroundColor = 'rgba(0, 0, 0, 0.8)';
            infoBox.style.color = 'white';
            infoBox.style.padding = '5px 10px';
            infoBox.style.borderRadius = '3px';
            infoBox.style.fontSize = '12px';
            infoBox.style.fontFamily = 'monospace';
            infoBox.style.zIndex = '10001';
            
            // Get computed style
            const style = window.getComputedStyle(element);
            
            infoBox.innerHTML = `
                <strong>Element:</strong> ${element.tagName.toLowerCase()}${element.id ? '#' + element.id : ''}${element.className ? '.' + element.className.replace(/\s+/g, '.') : ''}<br>
                <strong>Size:</strong> ${Math.round(rect.width)} × ${Math.round(rect.height)}<br>
                <strong>Position:</strong> ${Math.round(rect.left)}, ${Math.round(rect.top)}<br>
                <strong>Margin:</strong> ${style.marginTop} ${style.marginRight} ${style.marginBottom} ${style.marginLeft}<br>
                <strong>Padding:</strong> ${style.paddingTop} ${style.paddingRight} ${style.paddingBottom} ${style.paddingLeft}<br>
                <strong>Display:</strong> ${style.display}<br>
                <strong>Position:</strong> ${style.position}<br>
                <strong>Z-Index:</strong> ${style.zIndex}<br>
                <strong>Overflow:</strong> ${style.overflow}
            `;
            
            measureOverlay.appendChild(infoBox);
        }
    }
    
    function handleClick(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const element = document.elementFromPoint(e.clientX, e.clientY);
        if (element && element !== measureOverlay && !measureOverlay.contains(element)) {
            selectedElement = element;
            
            // Show alert with element details
            const rect = element.getBoundingClientRect();
            const style = window.getComputedStyle(element);
            
            alert(`Selected Element Details:\n\n` +
                  `Tag: ${element.tagName.toLowerCase()}\n` +
                  `ID: ${element.id || 'none'}\n` +
                  `Class: ${element.className || 'none'}\n` +
                  `Size: ${Math.round(rect.width)} × ${Math.round(rect.height)}\n` +
                  `Position: ${Math.round(rect.left)}, ${Math.round(rect.top)}\n` +
                  `Display: ${style.display}\n` +
                  `Position: ${style.position}\n` +
                  `Z-Index: ${style.zIndex}\n` +
                  `Overflow: ${style.overflow}\n` +
                  `Visibility: ${style.visibility}\n` +
                  `Opacity: ${style.opacity}`);
        }
    }
    
    buttonContainer.appendChild(measureButton);
    
    // Add close button
    const closeButton = document.createElement('button');
    closeButton.textContent = 'Close Tools';
    closeButton.style.marginTop = '10px';
    closeButton.style.width = '100%';
    closeButton.style.padding = '5px';
    closeButton.style.backgroundColor = '#dc3545';
    closeButton.style.color = 'white';
    closeButton.style.border = '1px solid #555';
    closeButton.style.borderRadius = '3px';
    closeButton.style.cursor = 'pointer';
    
    closeButton.addEventListener('click', function() {
        // Clean up all tools
        if (gridOverlay) {
            document.body.removeChild(gridOverlay);
        }
        
        if (measureOverlay) {
            document.body.removeChild(measureOverlay);
            document.removeEventListener('mousemove', handleMouseMove);
            document.removeEventListener('click', handleClick);
        }
        
        highlightedElements.forEach(el => {
            el.style.outline = '';
        });
        
        // Remove control panel
        document.body.removeChild(controlPanel);
    });
    
    // Add all elements to control panel
    controlPanel.appendChild(buttonContainer);
    controlPanel.appendChild(closeButton);
    
    // Add to body
    document.body.appendChild(controlPanel);
});