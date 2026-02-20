/**
 * Document Library JavaScript
 * Handles folder toggling and search functionality
 */

(function($) {
    'use strict';


    let docModalBound = false;

    function ensureDocModal() {
        let modal = document.getElementById('kop-doc-modal');
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = 'kop-doc-modal';
        modal.className = 'kop-doc-modal';
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
            <div class="kop-doc-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="kop-doc-modal-title">
                <div class="kop-doc-modal__header">
                    <h3 class="kop-doc-modal__title" id="kop-doc-modal-title">Document</h3>
                    <button type="button" class="kop-doc-modal__close" aria-label="Close">&times;</button>
                </div>
                <div class="kop-doc-modal__body"></div>
                <div class="kop-doc-modal__footer">
                    <a class="kop-doc-modal__btn kop-doc-modal__open" href="#" target="_blank" rel="noopener">Open in new tab</a>
                    <a class="kop-doc-modal__btn kop-doc-modal__download" href="#" download>Download</a>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        const close = () => closeDocModal(modal);
        const closeBtn = modal.querySelector('.kop-doc-modal__close');
        if (closeBtn) closeBtn.addEventListener('click', close);
        modal.addEventListener('click', event => {
            if (event.target === modal) close();
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                close();
            }
        });

        return modal;
    }

    function closeDocModal(modal) {
        const target = modal || document.getElementById('kop-doc-modal');
        if (!target) return;
        target.classList.remove('is-open');
        target.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('kop-doc-modal-open');
    }

    function getFileExtension(url) {
        if (!url || typeof url !== 'string') return '';
        const cleaned = url.split('#')[0].split('?')[0];
        const parts = cleaned.split('.');
        if (parts.length < 2) return '';
        return parts.pop().toLowerCase();
    }

    function openDocModalFromLink(link) {
        if (!link) return;
        const url = link.getAttribute('href') || '';
        if (!url) return;

        const title = link.dataset && link.dataset.title
            ? link.dataset.title
            : (link.querySelector('.doc-title') ? link.querySelector('.doc-title').textContent.trim() : 'Document');
        const mime = link.dataset && link.dataset.mime ? link.dataset.mime : '';
        const thumb = link.dataset && link.dataset.thumb
            ? link.dataset.thumb
            : (link.querySelector('.doc-thumbnail img') ? link.querySelector('.doc-thumbnail img').getAttribute('src') : '');

        const ext = getFileExtension(url);
        const isPdf = ext === 'pdf' || mime === 'application/pdf';
        const isImage = (mime && mime.indexOf('image/') === 0) || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'].includes(ext);
        const isVideo = (mime && mime.indexOf('video/') === 0) || ['mp4', 'webm', 'mov', 'm4v', 'avi'].includes(ext);
        const isAudio = (mime && mime.indexOf('audio/') === 0) || ['mp3', 'wav', 'ogg', 'm4a'].includes(ext);

        const modal = ensureDocModal();
        const titleEl = modal.querySelector('.kop-doc-modal__title');
        const bodyEl = modal.querySelector('.kop-doc-modal__body');
        const openLink = modal.querySelector('.kop-doc-modal__open');
        const downloadLink = modal.querySelector('.kop-doc-modal__download');

        if (titleEl) titleEl.textContent = title || 'Document';
        if (openLink) openLink.href = url;
        if (downloadLink) downloadLink.href = url;

        if (bodyEl) {
            bodyEl.innerHTML = '';
            const viewer = document.createElement('div');
            viewer.className = 'kop-doc-modal__viewer';

            if (isPdf) {
                const iframe = document.createElement('iframe');
                iframe.src = url;
                iframe.setAttribute('title', title || 'PDF preview');
                iframe.setAttribute('loading', 'lazy');
                viewer.appendChild(iframe);
            } else if (isImage) {
                const img = document.createElement('img');
                img.src = url;
                img.alt = title || 'Image preview';
                viewer.appendChild(img);
            } else if (isVideo) {
                const video = document.createElement('video');
                video.controls = true;
                video.src = url;
                viewer.appendChild(video);
            } else if (isAudio) {
                const audio = document.createElement('audio');
                audio.controls = true;
                audio.src = url;
                viewer.appendChild(audio);
            } else if (thumb) {
                const img = document.createElement('img');
                img.src = thumb;
                img.alt = title || 'Document preview';
                viewer.appendChild(img);
            } else {
                const placeholder = document.createElement('div');
                placeholder.className = 'kop-doc-modal__placeholder';
                placeholder.textContent = 'Preview not available.';
                viewer.appendChild(placeholder);
            }

            bodyEl.appendChild(viewer);
        }

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('kop-doc-modal-open');
    }

    function bindDocModalHandlers() {
        if (docModalBound) return;
        docModalBound = true;

        document.addEventListener('click', event => {
            const link = event.target && event.target.closest
                ? event.target.closest('.kop-document-library .doc-link, .kop-document-folder .doc-link, .kop-document-single .doc-link')
                : null;
            if (!link) return;
            if (link.dataset && link.dataset.kopNoModal === 'true') return;
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1) return;

            event.preventDefault();
            openDocModalFromLink(link);
        });
    }

    $(document).ready(function() {
        initFolderToggles();
        initDocumentSearch();
        bindDocModalHandlers();
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
