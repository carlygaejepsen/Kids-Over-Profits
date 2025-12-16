// ============================================
// DATA SEARCH MODULE
// Handles search functionality for projects
// ============================================

/**
 * KOP_Search - Search functionality module
 * Provides search input event listeners for filtering projects
 */
const KOP_Search = (function() {
    'use strict';

    /**
     * Get current search queries from all search inputs
     * @returns {Object} Object with company, location, and referrer search values
     */
    function getSearchQueries() {
        return {
            company: document.getElementById('company-search-input')?.value || '',
            location: document.getElementById('location-search-input')?.value || '',
            referrer: document.getElementById('referrer-search-input')?.value || ''
        };
    }

    /**
     * Trigger project list refresh with current search queries
     */
    function refreshProjectsWithSearch() {
        const searchQueries = getSearchQueries();
        if (window.KOP_UI_Render && typeof window.KOP_UI_Render.refreshSavedProjectPanels === 'function') {
            window.KOP_UI_Render.refreshSavedProjectPanels(searchQueries);
        }
    }

    /**
     * Attach event listeners to search input fields
     * This function is called during form initialization
     */
    function attachSearchListeners() {
        // Company search input
        const companySearchInput = document.getElementById('company-search-input');
        if (companySearchInput && !companySearchInput.dataset.listenerAttached) {
            companySearchInput.addEventListener('input', refreshProjectsWithSearch, { passive: true });
            companySearchInput.dataset.listenerAttached = 'true';
        }

        // Location search input
        const locationSearchInput = document.getElementById('location-search-input');
        if (locationSearchInput && !locationSearchInput.dataset.listenerAttached) {
            locationSearchInput.addEventListener('input', refreshProjectsWithSearch, { passive: true });
            locationSearchInput.dataset.listenerAttached = 'true';
        }

        // Referrer search input
        const referrerSearchInput = document.getElementById('referrer-search-input');
        if (referrerSearchInput && !referrerSearchInput.dataset.listenerAttached) {
            referrerSearchInput.addEventListener('input', refreshProjectsWithSearch, { passive: true });
            referrerSearchInput.dataset.listenerAttached = 'true';
        }
    }

    /**
     * Clear all search inputs
     */
    function clearSearchInputs() {
        const companySearchInput = document.getElementById('company-search-input');
        if (companySearchInput) companySearchInput.value = '';

        const locationSearchInput = document.getElementById('location-search-input');
        if (locationSearchInput) locationSearchInput.value = '';

        const referrerSearchInput = document.getElementById('referrer-search-input');
        if (referrerSearchInput) referrerSearchInput.value = '';

        // Refresh projects list to show all items
        refreshProjectsWithSearch();
    }

    // Public API
    return {
        attachSearchListeners,
        getSearchQueries,
        refreshProjectsWithSearch,
        clearSearchInputs
    };
})();

// Export to global scope
if (typeof window !== 'undefined') {
    window.KOP_Search = KOP_Search;
}
