/**
 * Kadence Navigation Guard
 * 
 * This script runs very early (in the head) on headerless data form pages to:
 * 1. Intercept DOM queries that might look for navigation elements
 * 2. Return safe dummy objects instead of null
 * 3. Prevent the Kadence navigation script from throwing errors
 */
(function() {
    // Only run if we're on a page where navigation should be disabled
    // The PHP logic handles conditional enqueueing, but we can double check
    if (document.body && !document.body.classList.contains('page-template-page-data') && 
        !document.body.classList.contains('page-template-page-admin-data')) {
        // Continue anyway as the class might not be added yet
    }

    // Define a safe dummy element that won't throw errors when Kadence tries to access its properties
    const dummy = document.createElement('div');
    dummy.id = 'kadence-nav-dummy';
    dummy.className = 'kadence-nav-dummy';
    dummy.style.display = 'none';
    
    // Add safe methods that Kadence might call
    dummy.getAttribute = function() { return null; };
    dummy.setAttribute = function() {};
    dummy.addEventListener = function() {};
    dummy.removeEventListener = function() {};
    dummy.classList = {
        add: function() {},
        remove: function() {},
        toggle: function() {},
        contains: function() { return false; }
    };
    
    // Override standard DOM query methods to intercept nav-related queries
    const originalQuerySelector = Document.prototype.querySelector;
    const originalGetElementById = Document.prototype.getElementById;
    
    Document.prototype.querySelector = function(selector) {
        // If the query is for a navigation element we've hidden/removed
        if (typeof selector === 'string' && (
            selector.includes('.site-navigation') || 
            selector.includes('#site-navigation') || 
            selector.includes('.mobile-navigation') ||
            selector.includes('kadence') && selector.includes('nav')
        )) {
            // Return our safe dummy instead of null
            return dummy;
        }
        
        return originalQuerySelector.call(this, selector);
    };
    
    Document.prototype.getElementById = function(id) {
        if (typeof id === 'string' && (
            id === 'site-navigation' || 
            id === 'mobile-navigation' ||
            (id.includes('kadence') && id.includes('nav'))
        )) {
            return dummy;
        }
        
        return originalGetElementById.call(this, id);
    };
    
    // Also patch Element.prototype for scoped queries
    const originalElementQuerySelector = Element.prototype.querySelector;
    Element.prototype.querySelector = function(selector) {
        if (typeof selector === 'string' && (
            selector.includes('.site-navigation') || 
            selector.includes('.mobile-navigation')
        )) {
            return dummy;
        }
        return originalElementQuerySelector.call(this, selector);
    };

    // Error suppression for specific Kadence errors
    window.addEventListener('error', function(e) {
        if (e.message && (
            e.message.includes('kadence') || 
            e.message.includes('navigation') || 
            e.message.includes('getAttribute') ||
            e.message.includes('classList')
        )) {
            // If it's a known error from missing nav elements, suppress it
            if (e.filename && (e.filename.includes('navigation') || e.filename.includes('kadence'))) {
                e.preventDefault();
                e.stopPropagation();
                return true;
            }
        }
    }, true); // Capture phase to catch it early

    console.log('🛡️ Kadence Navigation Guard Active');
})();
