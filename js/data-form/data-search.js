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
     * Debounce timer for search
     */
    let searchDebounceTimer = null;

    /**
     * Trigger project list refresh with current search queries
     * Also searches the database for matching projects
     */
    function refreshProjectsWithSearch() {
        const searchQueries = getSearchQueries();

        // Clear existing debounce timer
        if (searchDebounceTimer) {
            clearTimeout(searchDebounceTimer);
        }

        // Debounce the database search to avoid too many requests
        searchDebounceTimer = setTimeout(() => {
            searchDatabase(searchQueries);
        }, 300);

        // Refresh local projects immediately
        if (window.KOP_UI_Render && typeof window.KOP_UI_Render.refreshSavedProjectPanels === 'function') {
            window.KOP_UI_Render.refreshSavedProjectPanels(searchQueries);
        }
    }

    /**
     * Search the database for projects
     * @param {Object} searchQueries Object with company, location, and referrer search values
     */
    function searchDatabase(searchQueries) {
        // Skip if all queries are empty
        if (!searchQueries.company && !searchQueries.location && !searchQueries.referrer) {
            window.databaseProjects = {};
            if (window.KOP_UI_Render && typeof window.KOP_UI_Render.refreshSavedProjectPanels === 'function') {
                window.KOP_UI_Render.refreshSavedProjectPanels(searchQueries);
            }
            return;
        }

        // Build query parameters
        const params = new URLSearchParams();
        if (searchQueries.company) params.append('company', searchQueries.company);
        if (searchQueries.location) params.append('location', searchQueries.location);
        if (searchQueries.referrer) params.append('referrer', searchQueries.referrer);
        params.append('limit', '20');

        // Get REST URL
        const restUrl = window.kopData?.restUrl || '/wp-json/';
        const searchUrl = `${restUrl}kop/v1/search?${params.toString()}`;

        // Fetch from database
        fetch(searchUrl)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.results) {
                    // Store database results in a global variable
                    window.databaseProjects = {};
                    data.results.forEach(result => {
                        window.databaseProjects[result.name] = {
                            name: result.name,
                            label: result.label,
                            data: result.data,
                            category: result.category,
                            source: 'database',
                            matchSnippet: result.matchSnippet,
                            timestamp: null
                        };
                    });

                    // Refresh the UI with both local and database results
                    if (window.KOP_UI_Render && typeof window.KOP_UI_Render.refreshSavedProjectPanels === 'function') {
                        window.KOP_UI_Render.refreshSavedProjectPanels(searchQueries);
                    }
                }
            })
            .catch(error => {
                console.error('Database search error:', error);
                window.databaseProjects = {};
            });
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

        // Clear database search results
        window.databaseProjects = {};

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

    // Initialize database projects storage
    if (!window.databaseProjects) {
        window.databaseProjects = {};
    }

    // Auto-initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            KOP_Search.attachSearchListeners();
        });
    } else {
        KOP_Search.attachSearchListeners();
    }
}
