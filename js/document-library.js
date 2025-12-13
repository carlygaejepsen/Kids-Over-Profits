/**
 * Document Library JavaScript
 * Handles folder toggling and search functionality
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        initFolderToggles();
        initDocumentSearch();
    });

    /**
     * Initialize folder toggle functionality
     */
    function initFolderToggles() {
        $('.doc-folder-header').on('click', function(e) {
            e.preventDefault();

            const $folder = $(this).closest('.doc-folder');
            const $content = $folder.find('.doc-folder-content');
            const $toggle = $folder.find('.doc-folder-toggle');
            const isExpanded = $toggle.attr('aria-expanded') === 'true';

            // Toggle the folder
            if (isExpanded) {
                $content.slideUp(300);
                $toggle.attr('aria-expanded', 'false');
                $content.removeClass('open');
            } else {
                $content.slideDown(300);
                $toggle.attr('aria-expanded', 'true');
                $content.addClass('open');
            }
        });

        // Optional: Expand all folders button
        if ($('.doc-expand-all').length) {
            $('.doc-expand-all').on('click', function(e) {
                e.preventDefault();
                $('.doc-folder-content').slideDown(300);
                $('.doc-folder-toggle').attr('aria-expanded', 'true');
                $('.doc-folder-content').addClass('open');
            });
        }

        // Optional: Collapse all folders button
        if ($('.doc-collapse-all').length) {
            $('.doc-collapse-all').on('click', function(e) {
                e.preventDefault();
                $('.doc-folder-content').slideUp(300);
                $('.doc-folder-toggle').attr('aria-expanded', 'false');
                $('.doc-folder-content').removeClass('open');
            });
        }
    }

    /**
     * Initialize document search functionality
     */
    function initDocumentSearch() {
        const $searchInput = $('#docSearch');

        if (!$searchInput.length) {
            return;
        }

        let searchTimeout;

        $searchInput.on('input', function() {
            clearTimeout(searchTimeout);

            searchTimeout = setTimeout(function() {
                performSearch($searchInput.val());
            }, 300);
        });

        // Clear search on Escape key
        $searchInput.on('keydown', function(e) {
            if (e.key === 'Escape') {
                $(this).val('');
                performSearch('');
            }
        });
    }

    /**
     * Perform search across folders and documents
     */
    function performSearch(searchTerm) {
        const $folders = $('.doc-folder');
        const $noResults = $('.doc-no-results');
        const term = searchTerm.toLowerCase().trim();

        if (term === '') {
            // Show all folders, collapse them
            $folders.show();
            $folders.find('.doc-folder-content').slideUp(300);
            $folders.find('.doc-folder-toggle').attr('aria-expanded', 'false');
            $noResults.hide();
            return;
        }

        let hasResults = false;

        $folders.each(function() {
            const $folder = $(this);
            const folderName = $folder.data('folder-name').toLowerCase();
            const $documents = $folder.find('.doc-item');
            let folderHasMatch = false;

            // Check if folder name matches
            const folderMatches = folderName.includes(term);

            if (folderMatches) {
                // If folder name matches, show entire folder
                $folder.show();
                $documents.show();
                folderHasMatch = true;
                hasResults = true;
            } else {
                // Check individual documents
                let visibleDocCount = 0;

                $documents.each(function() {
                    const $doc = $(this);
                    const docTitle = $doc.data('title').toLowerCase();

                    if (docTitle.includes(term)) {
                        $doc.show();
                        visibleDocCount++;
                        folderHasMatch = true;
                        hasResults = true;
                    } else {
                        $doc.hide();
                    }
                });

                // Show folder only if it has matching documents
                if (visibleDocCount > 0) {
                    $folder.show();
                } else {
                    $folder.hide();
                }
            }

            // Expand folders with matches
            if (folderHasMatch) {
                $folder.find('.doc-folder-content').slideDown(300);
                $folder.find('.doc-folder-toggle').attr('aria-expanded', 'true');
            } else {
                $folder.find('.doc-folder-content').slideUp(300);
                $folder.find('.doc-folder-toggle').attr('aria-expanded', 'false');
            }
        });

        // Show "no results" message if nothing matches
        if (hasResults) {
            $noResults.hide();
        } else {
            $noResults.show();
        }
    }

    /**
     * Highlight search terms in text (optional enhancement)
     */
    function highlightSearchTerm(text, term) {
        if (!term) return text;

        const regex = new RegExp(`(${escapeRegex(term)})`, 'gi');
        return text.replace(regex, '<mark>$1</mark>');
    }

    /**
     * Escape special regex characters
     */
    function escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    /**
     * Add keyboard navigation support
     */
    function initKeyboardNavigation() {
        $(document).on('keydown', '.doc-folder-header', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                $(this).click();
            }
        });
    }

    initKeyboardNavigation();

})(jQuery);
