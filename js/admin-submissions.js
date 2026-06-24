/**
 * Admin Submissions Management JavaScript
 * Handles wiki and data submission review, approval, and management
 */

document.addEventListener('DOMContentLoaded', () => {
    const adminPage = document.querySelector('.admin-submissions-page');
    if (!adminPage) return;

    // DOM Elements
    const statusFilter = document.getElementById('statusFilter');
    const typeFilter = document.getElementById('typeFilter'); // New type filter
    const searchFilter = document.getElementById('searchFilter');
    const refreshBtn = document.getElementById('refreshBtn');
    const submissionsList = document.getElementById('submissionsList');
    const loadingMessage = document.getElementById('loadingMessage');
    const noSubmissions = document.getElementById('noSubmissions');
    const submissionModal = document.getElementById('submissionModal');
    const closeModalBtn = document.getElementById('closeModalBtn');

    // Modal elements
    const modalProgramName = document.getElementById('modalProgramName');
    const modalStatus = document.getElementById('modalStatus');
    const modalSubmittedDate = document.getElementById('modalSubmittedDate');
    const modalSubmittedBy = document.getElementById('modalSubmittedBy');
    const modalLocation = document.getElementById('modalLocation');
    const modalProgramType = document.getElementById('modalProgramType');
    const modalYearsActive = document.getElementById('modalYearsActive');
    const submitterNotesSection = document.getElementById('submitterNotesSection');
    const submitterNotes = document.getElementById('submitterNotes');
    const modalMarkdown = document.getElementById('modalMarkdown');
    const modalOriginalMarkdown = document.getElementById('modalOriginalMarkdown');
    const modalFormData = document.getElementById('modalFormData');
    const copyMarkdownBtn = document.getElementById('copyMarkdownBtn');
    const saveEditsBtn = document.getElementById('saveEditsBtn');
    const showDiffHighlights = document.getElementById('showDiffHighlights');
    const diffSummary = document.getElementById('diffSummary');
    const diffCount = document.getElementById('diffCount');
    const diffView = document.getElementById('diffView');
    const markdownEditorSection = document.getElementById('markdownEditorSection');
    const originalLineNumbers = document.getElementById('originalLineNumbers');
    const generatedLineNumbers = document.getElementById('generatedLineNumbers');
    const reviewerNotes = document.getElementById('reviewerNotes');
    const reviewerEmail = document.getElementById('reviewerEmail');
    const existingReviewSection = document.getElementById('existingReviewSection');
    const modalReviewedBy = document.getElementById('modalReviewedBy');
    const modalReviewedAt = document.getElementById('modalReviewedAt');
    const existingReviewerNotes = document.getElementById('existingReviewerNotes');
    const actionStatus = document.getElementById('actionStatus');

    // Action buttons
    const approveBtn = document.getElementById('approveBtn');
    const rejectBtn = document.getElementById('rejectBtn');
    const publishBtn = document.getElementById('publishBtn');
    const deleteBtn = document.getElementById('deleteBtn');
    const rejectAllBtn = document.getElementById('rejectAllBtn');

    // Stats elements
    const statPending = document.getElementById('statPending');
    const statApproved = document.getElementById('statApproved');
    const statPublished = document.getElementById('statPublished');
    const statRejected = document.getElementById('statRejected');

    // State
    let currentSubmission = null;
    let allSubmissions = [];
    let currentOriginalMarkdown = '';
    let duplicateUrlMap = new Map(); // Map of normalized URLs to array of submission IDs

    // Inline-expansion state. The submissionModal element gets physically
    // moved into the active card when View Details is clicked; we cache its
    // original parent so we can put it back on collapse / re-render.
    const modalOriginalParent = submissionModal.parentNode;
    const modalOriginalNextSibling = submissionModal.nextSibling;
    let expandedCardId = null;

    /**
     * Move the submission modal back to its original DOM location and reset
     * any expanded-card visual state. Does NOT change modal visibility â€” the
     * caller decides whether to hide (closeModal) or leave it shown so a
     * subsequent viewSubmission can re-place it in a new card.
     *
     * Must be called before submissionsList.innerHTML='' so the modal isn't
     * destroyed along with the card that contains it.
     */
    function detachModal() {
        if (expandedCardId !== null) {
            const card = submissionsList.querySelector(`.submission-card[data-id="${expandedCardId}"]`);
            if (card) {
                card.classList.remove('expanded');
                const btn = card.querySelector('.btn-view');
                if (btn) btn.textContent = 'View Details';
            }
        }
        if (submissionModal.parentNode !== modalOriginalParent) {
            if (modalOriginalNextSibling && modalOriginalNextSibling.parentNode === modalOriginalParent) {
                modalOriginalParent.insertBefore(submissionModal, modalOriginalNextSibling);
            } else {
                modalOriginalParent.appendChild(submissionModal);
            }
        }
        expandedCardId = null;
    }

    // API endpoints
    // Use localized config if available, otherwise fallback to default (though default might be wrong if theme folder differs)
    const config = window.adminSubmissionsConfig || {};
    const API_BASE = config.apiBase || '/wp-content/themes/child/api';
    const MANAGE_API = config.manageApi || `${API_BASE}/manage-submissions.php`;
    const SCAN_API = config.scanApi || `${API_BASE}/scan-submission-urls.php`;

    // Initialize
    loadStats();
    loadSubmissions();

    // Event Listeners
    if (typeFilter) {
        typeFilter.addEventListener('change', () => {
            loadStats();
            loadSubmissions();
        });
    }
    statusFilter.addEventListener('change', loadSubmissions);
    searchFilter.addEventListener('input', debounce(loadSubmissions, 500));
    refreshBtn.addEventListener('click', () => {
        loadStats();
        loadSubmissions();
    });

    closeModalBtn.addEventListener('click', closeModal);
    submissionModal.addEventListener('click', (e) => {
        if (e.target === submissionModal) closeModal();
    });

    if (copyMarkdownBtn) {
        copyMarkdownBtn.addEventListener('click', copyMarkdown);
    }
    
    approveBtn.addEventListener('click', () => performAction('approve'));
    rejectBtn.addEventListener('click', () => performAction('reject'));
    publishBtn.addEventListener('click', () => performAction('publish'));
    deleteBtn.addEventListener('click', () => {
        if (confirm('Are you sure you want to delete this submission? This cannot be undone.')) {
            performAction('delete');
        }
    });

    if (rejectAllBtn) {
        rejectAllBtn.addEventListener('click', rejectAllPending);
    }

    if (saveEditsBtn) {
        saveEditsBtn.addEventListener('click', saveMarkdownEdits);
    }

    if (showDiffHighlights) {
        showDiffHighlights.addEventListener('change', updateDiffHighlighting);
    }

    if (modalMarkdown) {
        modalMarkdown.addEventListener('input', () => {
            updateDiffHighlighting();
            updateLineNumbers();
        });
        modalMarkdown.addEventListener('scroll', syncScroll);
    }

    if (modalOriginalMarkdown) {
        modalOriginalMarkdown.addEventListener('scroll', syncScroll);
    }

    // Functions

    /**
     * Normalize URL for duplicate detection
     */
    function normalizeUrl(url) {
        if (!url) return null;
        try {
            // Remove protocol, trailing slashes, and convert to lowercase
            return url.toLowerCase()
                .replace(/^https?:\/\//, '')
                .replace(/\/+$/, '')
                .replace(/www\./, '');
        } catch (e) {
            return url.toLowerCase().trim();
        }
    }

    /**
     * Build duplicate URL map from submissions
     */
    function buildDuplicateMap(submissions) {
        duplicateUrlMap.clear();
        const currentType = typeFilter ? typeFilter.value : 'wiki';
        
        submissions.forEach(submission => {
            let url = null;
            
            // Extract URL based on submission type
            if (currentType === 'news') {
                url = submission.article_url;
            } else {
                // For wiki/data submissions, check if there's a URL in json_data
                try {
                    const jsonData = typeof submission.json_data === 'string' 
                        ? JSON.parse(submission.json_data) 
                        : submission.json_data;
                    url = jsonData?.url || jsonData?.website || null;
                } catch (e) {
                    url = null;
                }
            }
            
            if (url) {
                const normalized = normalizeUrl(url);
                if (normalized) {
                    if (!duplicateUrlMap.has(normalized)) {
                        duplicateUrlMap.set(normalized, []);
                    }
                    duplicateUrlMap.get(normalized).push({
                        id: submission.id,
                        title: currentType === 'news' ? submission.article_title : submission.program_name,
                        status: submission.status,
                        created_at: submission.created_at,
                        submitted_by: submission.submitted_by,
                        url: url
                    });
                }
            }
        });
        
        console.log('Duplicate URL map built:', duplicateUrlMap);
    }

    /**
     * Get duplicates for a specific URL
     */
    function getDuplicatesForUrl(url) {
        if (!url) return [];
        const normalized = normalizeUrl(url);
        const duplicates = duplicateUrlMap.get(normalized) || [];
        return duplicates.filter(d => d.id !== currentSubmission?.id); // Exclude current submission
    }

    /**
     * Load submission statistics
     */
    async function loadStats() {
        try {
            const currentType = typeFilter ? typeFilter.value : 'wiki';
            const response = await fetch(MANAGE_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'stats', type: currentType })
            });

            const result = await response.json();
            if (result.success) {
                let stats = {};
                if (currentType === 'news' && result.news) {
                    stats = result.news.by_status || {};
                } else if (result.wiki) {
                    stats = result.wiki.by_status || {};
                }
                
                statPending.textContent = stats.submitted || 0;
                statApproved.textContent = stats.approved || 0;
                statPublished.textContent = stats.published || 0;
                statRejected.textContent = stats.rejected || 0;
            }
        } catch (error) {
            console.error('Failed to load stats:', error);
        }
    }

    /**
     * Load submissions based on current filters
     */
    async function loadSubmissions() {
        const status = statusFilter.value;
        const search = searchFilter.value.trim();
        const currentType = typeFilter ? typeFilter.value : 'wiki';

        // Build query parameters
        const params = new URLSearchParams({
            action: 'list',
            type: currentType,
            limit: '100'
        });
        if (status) params.set('status', status);
        if (search) params.set('search', search);

        loadingMessage.style.display = 'block';
        detachModal();              // restore modal to original parent before wiping list
        submissionsList.innerHTML = '';
        noSubmissions.style.display = 'none';

        try {
            const url = `${MANAGE_API}?${params.toString()}`;
            console.log('Fetching submissions from:', url);

            const response = await fetch(url);
            console.log('Response status:', response.status);

            const result = await response.json();
            console.log('API Response:', result);

            loadingMessage.style.display = 'none';

            if (result.success && result.data && result.data.length > 0) {
                console.log('Found submissions:', result.data.length);
                allSubmissions = result.data;
                buildDuplicateMap(result.data);
                renderSubmissions(result.data);
            } else {
                console.log('No submissions found or result structure unexpected:', {
                    success: result.success,
                    hasData: !!result.data,
                    dataLength: result.data?.length,
                    fullResult: result
                });
                noSubmissions.style.display = 'block';
            }
        } catch (error) {
            console.error('Failed to load submissions:', error);
            loadingMessage.style.display = 'none';
            submissionsList.innerHTML = '<div class="error-message">Failed to load submissions. Please try again.</div>';
        }
    }

    /**
     * Render submissions list
     */
    function renderSubmissions(submissions) {
        detachModal();              // see comment in loadSubmissions â€” must precede wiping list
        submissionsList.innerHTML = '';
        const currentType = typeFilter ? typeFilter.value : 'wiki';

        submissions.forEach(submission => {
            const card = document.createElement('div');
            card.className = 'submission-card';
            card.dataset.id = submission.id;

            const statusClass = `status-${submission.status}`;
            const date = formatDate(submission.created_at);

            if (currentType === 'news') {
                const title = submission.article_title || 'Untitled Article';
                const source = submission.publication_name || 'Unknown Source';
                const author = submission.author || 'Unknown Author';
                
                // Check for duplicates
                const url = submission.article_url;
                const normalized = normalizeUrl(url);
                const hasDuplicates = normalized && duplicateUrlMap.has(normalized) && duplicateUrlMap.get(normalized).length > 1;
                
                if (hasDuplicates) {
                    card.classList.add('has-duplicate');
                }
                
                const duplicateBadge = hasDuplicates ? '<span class="duplicate-badge">âš  Duplicate</span>' : '';
                
                card.innerHTML = `
                    <div class="submission-header">
                        <h3>${escapeHtml(title)}${duplicateBadge}</h3>
                        <span class="status-badge ${statusClass}">${submission.status}</span>
                    </div>
                    <div class="submission-meta">
                        <span>ðŸ“° ${escapeHtml(source)}</span>
                        <span>âœï¸ ${escapeHtml(author)}</span>
                        <span>ðŸ·ï¸ ${escapeHtml(submission.article_type || 'general')}</span>
                    </div>
                    <div class="submission-footer">
                        <span class="submission-date">Submitted: ${date}</span>
                        <button type="button" class="btn-view" data-id="${submission.id}">View Details</button>
                    </div>
                `;
            } else if (currentType === 'data') {
                card.innerHTML = `
                    <div class="submission-header">
                        <h3>${escapeHtml(submission.program_name)}</h3>
                        <span class="status-badge ${statusClass}">${submission.status}</span>
                    </div>
                    <div class="submission-meta">
                        <span>ðŸ”„ Data Update</span>
                    </div>
                    <div class="submission-footer">
                        <span class="submission-date">Submitted: ${date}</span>
                        <button type="button" class="btn-view" data-id="${submission.id}">View Details</button>
                    </div>
                `;
            } else {
                card.innerHTML = `
                    <div class="submission-header">
                        <h3>${escapeHtml(submission.program_name)}</h3>
                        <span class="status-badge ${statusClass}">${submission.status}</span>
                    </div>
                    <div class="submission-meta">
                        <span>ðŸ“ ${escapeHtml(submission.city_state || 'Location unknown')}</span>
                        <span>ðŸ“… ${escapeHtml(submission.years_active || 'Years unknown')}</span>
                        <span>ðŸ·ï¸ ${escapeHtml(submission.program_type || 'Type unknown')}</span>
                    </div>
                    <div class="submission-footer">
                        <span class="submission-date">Submitted: ${date}</span>
                        <button type="button" class="btn-view" data-id="${submission.id}">View Details</button>
                    </div>
                `;
            }

            card.querySelector('.btn-view').addEventListener('click', () => viewSubmission(submission.id));
            submissionsList.appendChild(card);
        });
    }

    /**
     * View submission details â€” inline expansion. Clicking on the
     * already-expanded card collapses it; clicking another card collapses
     * the current one first, then expands the new one. The detail panel
     * (#submissionModal) is physically moved into the active card.
     */
    async function viewSubmission(id) {
        console.log('viewSubmission called with id:', id);
        // Toggle: clicking the already-open card collapses it
        if (expandedCardId !== null && String(expandedCardId) === String(id)) {
            closeModal();
            return;
        }
        // Collapse any other open card before opening the new one
        // (don't hide the modal â€” we'll move it into the new card)
        if (expandedCardId !== null) detachModal();
        try {
            const currentType = typeFilter ? typeFilter.value : 'wiki';
            const detailParams = new URLSearchParams({
                action: 'get',
                type: currentType,
                id: id
            });
            const url = `${MANAGE_API}?${detailParams.toString()}`;
            console.log('Fetching submission from:', url);

            const response = await fetch(url);
            console.log('Response status:', response.status);

            const result = await response.json();
            console.log('API Result:', result);

            if (result.success && result.data) {
                currentSubmission = result.data;
                showModal(result.data);
            } else {
                console.error('API returned failure:', result);
                alert('Failed to load submission details: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Failed to load submission:', error);
            alert('Failed to load submission details: ' + error.message);
        }
    }

    /**
     * Show submission modal
     */
    function showModal(submission) {
        console.log('showModal called with submission:', submission);
        const currentType = typeFilter ? typeFilter.value : 'wiki';
        console.log('Current type:', currentType);

        // Parse JSON data safely
        let jsonData = {};
        try {
            if (typeof submission.json_data === 'string' && submission.json_data) {
                jsonData = JSON.parse(submission.json_data);
            } else if (submission.json_data && typeof submission.json_data === 'object') {
                jsonData = submission.json_data;
            }
        } catch (e) {
            console.warn('Failed to parse json_data:', e);
            jsonData = {};
        }
        console.log('Parsed jsonData:', jsonData);

        // Basic info
        modalStatus.textContent = submission.status;
        modalStatus.className = `status-badge status-${submission.status}`;
        modalSubmittedDate.textContent = formatDateTime(submission.created_at);
        modalSubmittedBy.textContent = submission.submitted_by || 'Anonymous';
        
        if (currentType === 'news') {
            modalProgramName.textContent = submission.article_title || 'News Article';

            // Re-purpose existing fields for news info
            const infoRows = document.querySelectorAll('.submission-info .info-row');
            if (infoRows[3]) infoRows[3].querySelector('.info-label').textContent = 'Author:';
            modalLocation.textContent = submission.author || '-';

            if (infoRows[4]) infoRows[4].querySelector('.info-label').textContent = 'Publication:';
            modalProgramType.textContent = submission.publication_name || '-';

            if (infoRows[5]) infoRows[5].querySelector('.info-label').textContent = 'URL:';
            modalYearsActive.textContent = '';
            const safeArticleUrl = safeUrl(submission.article_url);
            if (safeArticleUrl) {
                const a = document.createElement('a');
                a.href = safeArticleUrl;
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
                a.textContent = safeArticleUrl;
                modalYearsActive.appendChild(a);
            } else if (submission.article_url) {
                // Non-http(s) URL: show the raw text but don't make it clickable.
                modalYearsActive.textContent = submission.article_url;
            } else {
                modalYearsActive.textContent = '-';
            }

            // Check for duplicate URLs
            const duplicates = getDuplicatesForUrl(submission.article_url);
            displayDuplicateWarning(duplicates, 'news');

            // Hide markdown editor section for news (they have summaries, not markdown)
            if (markdownEditorSection) markdownEditorSection.style.display = 'none';

        } else if (currentType === 'data') {
            modalProgramName.textContent = submission.program_name;

            // Restore labels
            const dataInfoRows = document.querySelectorAll('.submission-info .info-row');
            if (dataInfoRows[3]) dataInfoRows[3].querySelector('.info-label').textContent = 'Location:';
            if (dataInfoRows[4]) dataInfoRows[4].querySelector('.info-label').textContent = 'Type:';
            if (dataInfoRows[5]) dataInfoRows[5].querySelector('.info-label').textContent = 'Years Active:';

            modalLocation.textContent = jsonData?.cityState || jsonData?.location || '-';
            modalProgramType.textContent = 'Data Update';
            modalYearsActive.textContent = '-';

            // Hide markdown editor section
            if (markdownEditorSection) markdownEditorSection.style.display = 'none';

        } else {
            // Wiki submissions
            modalProgramName.textContent = submission.program_name;

            // Restore labels
            const wikiInfoRows = document.querySelectorAll('.submission-info .info-row');
            if (wikiInfoRows[3]) wikiInfoRows[3].querySelector('.info-label').textContent = 'Location:';
            if (wikiInfoRows[4]) wikiInfoRows[4].querySelector('.info-label').textContent = 'Type:';
            if (wikiInfoRows[5]) wikiInfoRows[5].querySelector('.info-label').textContent = 'Years Active:';

            modalLocation.textContent = submission.city_state || '-';
            modalProgramType.textContent = submission.program_type || '-';
            modalYearsActive.textContent = submission.years_active || '-';

            // Check for duplicate URLs
            const wikiUrl = jsonData?.url || jsonData?.website;
            const duplicates = getDuplicatesForUrl(wikiUrl);
            displayDuplicateWarning(duplicates, 'wiki');

            // Show markdown editor section
            if (markdownEditorSection) markdownEditorSection.style.display = 'block';

            // Populate markdown textareas
            const originalMarkdown = submission.original_markdown || '';
            const generatedMarkdown = submission.generated_markdown || '';
            currentOriginalMarkdown = originalMarkdown;

            if (modalOriginalMarkdown) {
                modalOriginalMarkdown.value = originalMarkdown;
            }
            if (modalMarkdown) {
                modalMarkdown.value = generatedMarkdown;
            }

            // Update line numbers and diff highlighting
            updateLineNumbers();
            updateDiffHighlighting();
        }

        // Submitter notes
        if (submission.submission_notes) {
            submitterNotesSection.style.display = 'block';
            submitterNotes.textContent = submission.submission_notes;
        } else {
            submitterNotesSection.style.display = 'none';
        }

        // Form data
        modalFormData.textContent = JSON.stringify(jsonData, null, 2);

        // Clear reviewer inputs
        reviewerNotes.value = '';
        reviewerEmail.value = localStorage.getItem('adminEmail') || '';

        // Show existing review if available
        if (submission.reviewed_by) {
            existingReviewSection.style.display = 'block';
            modalReviewedBy.textContent = submission.reviewed_by;
            modalReviewedAt.textContent = formatDateTime(submission.reviewed_at);
            existingReviewerNotes.textContent = submission.reviewer_notes || 'No notes';
        } else {
            existingReviewSection.style.display = 'none';
        }

        // Update button states based on status
        updateButtonStates(submission.status);

        // Clear action status
        actionStatus.innerHTML = '';

        // Kick off Cloudmersive URL safety check (async; results render when ready).
        runUrlScan(currentType, submission.id);

        // -- Relocate the detail panel into the active card (inline expansion) --
        // Falls back to the original "panel at bottom" placement if the card
        // can't be found â€” most commonly during the brief race window after
        // an approve/reject when the list is being re-fetched. In that case
        // we scrollIntoView so the user can still see the result.
        const card = submissionsList.querySelector(`.submission-card[data-id="${submission.id}"]`);
        if (card) {
            card.appendChild(submissionModal);
            card.classList.add('expanded');
            const btn = card.querySelector('.btn-view');
            if (btn) btn.textContent = 'Hide Details';
            expandedCardId = submission.id;
            submissionModal.style.display = 'flex';
        } else {
            submissionModal.style.display = 'flex';
            submissionModal.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    /**
     * Update button states based on submission status
     */
    function updateButtonStates(status) {
        // Reset all buttons
        approveBtn.disabled = false;
        rejectBtn.disabled = false;
        publishBtn.disabled = false;
        deleteBtn.disabled = false;

        // Publish is a wiki/news concept (approved â†’ live on wiki). The
        // suggested_edits enum for data submissions only allows
        // ('pending','approved','rejected'), so hide the button entirely
        // for data â€” approve already applies the edit.
        const currentType = typeFilter ? typeFilter.value : 'wiki';
        publishBtn.style.display = currentType === 'data' ? 'none' : '';

        // Disable based on current status
        if (status === 'approved') {
            approveBtn.disabled = true;
        } else if (status === 'rejected') {
            rejectBtn.disabled = true;
        } else if (status === 'published') {
            publishBtn.disabled = true;
        }
    }

    /**
     * Display duplicate warning section
     */
    function displayDuplicateWarning(duplicates, type) {
        const duplicateWarningSection = document.getElementById('duplicateWarningSection');
        const duplicateWarningMessage = document.getElementById('duplicateWarningMessage');
        const duplicateSubmissionsList = document.getElementById('duplicateSubmissionsList');
        
        if (!duplicateWarningSection) return;
        
        if (duplicates && duplicates.length > 0) {
            duplicateWarningSection.style.display = 'block';
            
            const count = duplicates.length;
            duplicateWarningMessage.textContent = `This URL has been submitted ${count} other time${count > 1 ? 's' : ''}.`;
            
            // Build duplicate submission cards
            duplicateSubmissionsList.innerHTML = duplicates.map(dup => {
                const statusClass = `status-${dup.status}`;
                const submittedDate = formatDate(dup.created_at);
                
                return `
                    <div class="duplicate-submission-card">
                        <div class="duplicate-submission-header">
                            <div class="duplicate-submission-title">${escapeHtml(dup.title)}</div>
                            <span class="duplicate-submission-status ${statusClass}">${dup.status}</span>
                        </div>
                        <div class="duplicate-submission-meta">
                            <strong>Submitted:</strong> ${submittedDate}
                            ${dup.submitted_by ? ` | <strong>By:</strong> ${escapeHtml(dup.submitted_by)}` : ''}
                        </div>
                        <div class="duplicate-submission-link">
                            <a href="javascript:void(0)" onclick="document.querySelector('[data-id=\"${dup.id}\"] .btn-view').click()">
                                View Submission #${dup.id} â†’
                            </a>
                        </div>
                    </div>
                `;
            }).join('');
        } else {
            duplicateWarningSection.style.display = 'none';
        }
    }

    /**
     * Run Cloudmersive URL threat scan for this submission and render results.
     * The endpoint requires admin auth; on 403 the section is hidden.
     */
    async function runUrlScan(submissionType, submissionId) {
        const section = document.getElementById('urlSafetySection');
        const status = document.getElementById('urlSafetyStatus');
        const list = document.getElementById('urlSafetyList');
        if (!section || !status || !list) return;

        section.style.display = 'block';
        status.textContent = 'Scanning URLsâ€¦';
        status.className = 'url-safety-status scanning';
        list.innerHTML = '';

        const scanType = submissionType === 'data' ? 'data' : submissionType;
        const requestedId = submissionId;

        try {
            const response = await fetch(SCAN_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ type: scanType, id: requestedId })
            });

            // The modal may have closed or switched submissions while we were waiting â€”
            // bail out so we don't paint stale results onto a different submission.
            if (!currentSubmission || currentSubmission.id !== requestedId) return;

            if (response.status === 403) {
                section.style.display = 'none';
                return;
            }

            const result = await response.json();
            if (!result.success) {
                status.textContent = 'URL scan failed: ' + (result.error || 'Unknown error');
                status.className = 'url-safety-status error';
                return;
            }

            if (!result.results || result.results.length === 0) {
                status.textContent = 'No URLs found in this submission.';
                status.className = 'url-safety-status empty';
                return;
            }

            if (result.flagged > 0) {
                status.textContent = `âš  ${result.flagged} of ${result.scanned} URL(s) flagged as unsafe.`;
                status.className = 'url-safety-status flagged';
            } else {
                status.textContent = `âœ“ All ${result.scanned} URL(s) clean.`;
                status.className = 'url-safety-status clean';
            }

            list.innerHTML = '';
            result.results.forEach(r => {
                const li = document.createElement('li');
                li.className = 'url-safety-item ' + (
                    r.clean === true ? 'clean'
                    : r.clean === false ? 'flagged'
                    : 'unknown'
                );

                const icon = document.createElement('span');
                icon.className = 'url-safety-icon';
                icon.textContent = r.clean === true ? 'âœ“' : r.clean === false ? 'âš ' : '?';
                li.appendChild(icon);

                const safe = safeUrl(r.url);
                if (safe) {
                    const a = document.createElement('a');
                    a.href = safe;
                    a.target = '_blank';
                    a.rel = 'noopener noreferrer';
                    a.textContent = safe;
                    li.appendChild(a);
                } else {
                    const span = document.createElement('span');
                    span.textContent = r.url;
                    li.appendChild(span);
                }

                if (r.clean === false && r.threats && Object.keys(r.threats).length > 0) {
                    const threatList = Object.entries(r.threats)
                        .map(([k, v]) => `${k}: ${v}`)
                        .join('; ');
                    const t = document.createElement('div');
                    t.className = 'url-safety-threats';
                    t.textContent = threatList;
                    li.appendChild(t);
                } else if (r.error) {
                    const e = document.createElement('div');
                    e.className = 'url-safety-error';
                    e.textContent = 'Could not scan: ' + r.error;
                    li.appendChild(e);
                }

                list.appendChild(li);
            });
        } catch (err) {
            console.error('URL scan failed:', err);
            if (!currentSubmission || currentSubmission.id !== requestedId) return;
            status.textContent = 'URL scan request failed: ' + err.message;
            status.className = 'url-safety-status error';
        }
    }

    /**
     * Close modal
     */
    function closeModal() {
        detachModal();                  // returns modal to original parent + resets card state
        submissionModal.style.display = 'none';
        currentSubmission = null;
        const safetySection = document.getElementById('urlSafetySection');
        if (safetySection) safetySection.style.display = 'none';
    }

    /**
     * Copy markdown to clipboard
     */
    async function copyMarkdown() {
        try {
            await navigator.clipboard.writeText(modalMarkdown.value);
            copyMarkdownBtn.textContent = 'âœ“ Copied!';
            setTimeout(() => {
                copyMarkdownBtn.textContent = 'ðŸ“‹ Copy';
            }, 2000);
        } catch (error) {
            alert('Failed to copy to clipboard');
        }
    }

    /**
     * Update line numbers for both textareas
     */
    function updateLineNumbers() {
        if (!modalOriginalMarkdown || !modalMarkdown) return;

        const originalLines = (modalOriginalMarkdown.value || '').split('\n');
        const generatedLines = (modalMarkdown.value || '').split('\n');

        if (originalLineNumbers) {
            originalLineNumbers.innerHTML = originalLines.map((_, i) =>
                `<div class="line-num">${i + 1}</div>`
            ).join('');
        }

        if (generatedLineNumbers) {
            generatedLineNumbers.innerHTML = generatedLines.map((_, i) =>
                `<div class="line-num">${i + 1}</div>`
            ).join('');
        }
    }

    function diffEscapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /**
     * Ordered line diff via LCS. Returns a sequence of operations:
     *   { type:'equal'|'del'|'ins', text }
     * 'del' = present in original only (removed), 'ins' = in generated only (added).
     * Comparison is on trimmed text; the original text is kept for display.
     */
    function computeDiffOps(aLines, bLines) {
        const at = aLines.map(s => s.trim());
        const bt = bLines.map(s => s.trim());
        const n = aLines.length, m = bLines.length;
        const ops = [];

        // O(n*m) guard — fall back to a positional diff for huge inputs.
        if (n * m > 4000000) {
            const max = Math.max(n, m);
            for (let i = 0; i < max; i++) {
                if (i < n && i < m && at[i] === bt[i]) ops.push({ type: 'equal', text: aLines[i] });
                else {
                    if (i < n) ops.push({ type: 'del', text: aLines[i] });
                    if (i < m) ops.push({ type: 'ins', text: bLines[i] });
                }
            }
            return ops;
        }

        const dp = Array.from({ length: n + 1 }, () => new Int32Array(m + 1));
        for (let i = n - 1; i >= 0; i--) {
            for (let j = m - 1; j >= 0; j--) {
                dp[i][j] = at[i] === bt[j]
                    ? dp[i + 1][j + 1] + 1
                    : Math.max(dp[i + 1][j], dp[i][j + 1]);
            }
        }
        let i = 0, j = 0;
        while (i < n && j < m) {
            if (at[i] === bt[j]) { ops.push({ type: 'equal', text: aLines[i] }); i++; j++; }
            else if (dp[i + 1][j] >= dp[i][j + 1]) { ops.push({ type: 'del', text: aLines[i] }); i++; }
            else { ops.push({ type: 'ins', text: bLines[j] }); j++; }
        }
        while (i < n) { ops.push({ type: 'del', text: aLines[i] }); i++; }
        while (j < m) { ops.push({ type: 'ins', text: bLines[j] }); j++; }
        return ops;
    }

    /** Word-level diff between two changed lines; wraps differing tokens. */
    function diffWords(aStr, bStr) {
        const a = String(aStr).split(/(\s+)/);
        const b = String(bStr).split(/(\s+)/);
        const n = a.length, m = b.length;
        const dp = Array.from({ length: n + 1 }, () => new Int32Array(m + 1));
        for (let i = n - 1; i >= 0; i--) {
            for (let j = m - 1; j >= 0; j--) {
                dp[i][j] = a[i] === b[j]
                    ? dp[i + 1][j + 1] + 1
                    : Math.max(dp[i + 1][j], dp[i][j + 1]);
            }
        }
        let i = 0, j = 0, aHtml = '', bHtml = '';
        while (i < n && j < m) {
            if (a[i] === b[j]) { const t = diffEscapeHtml(a[i]); aHtml += t; bHtml += t; i++; j++; }
            else if (dp[i + 1][j] >= dp[i][j + 1]) { aHtml += '<span class="diff-word-del">' + diffEscapeHtml(a[i]) + '</span>'; i++; }
            else { bHtml += '<span class="diff-word-ins">' + diffEscapeHtml(b[j]) + '</span>'; j++; }
        }
        while (i < n) { aHtml += '<span class="diff-word-del">' + diffEscapeHtml(a[i]) + '</span>'; i++; }
        while (j < m) { bHtml += '<span class="diff-word-ins">' + diffEscapeHtml(b[j]) + '</span>'; j++; }
        return { aHtml, bHtml };
    }

    function diffLineHtml(type, gutter, contentHtml) {
        return '<div class="diff-line diff-' + type + '">' +
            '<span class="diff-gutter">' + gutter + '</span>' +
            '<span class="diff-text">' + (contentHtml || '&nbsp;') + '</span></div>';
    }

    /** Render the ordered ops into colored diff HTML, with inline word emphasis
     *  on paired del/ins lines (edited lines). */
    function buildDiffHtml(ops) {
        let html = '';
        let k = 0;
        while (k < ops.length) {
            if (ops[k].type === 'equal') {
                html += diffLineHtml('equal', ' ', diffEscapeHtml(ops[k].text));
                k++;
                continue;
            }
            const dels = [], inses = [];
            while (k < ops.length && ops[k].type === 'del') { dels.push(ops[k].text); k++; }
            while (k < ops.length && ops[k].type === 'ins') { inses.push(ops[k].text); k++; }
            const pairs = Math.min(dels.length, inses.length);
            for (let p = 0; p < pairs; p++) {
                const { aHtml, bHtml } = diffWords(dels[p], inses[p]);
                html += diffLineHtml('del', '−', aHtml);
                html += diffLineHtml('ins', '+', bHtml);
            }
            for (let p = pairs; p < dels.length; p++)  html += diffLineHtml('del', '−', diffEscapeHtml(dels[p]));
            for (let p = pairs; p < inses.length; p++) html += diffLineHtml('ins', '+', diffEscapeHtml(inses[p]));
        }
        return html || '<div class="diff-empty">No content to compare.</div>';
    }

    /**
     * Render the colored diff (when Diff view is on) or fall back to the
     * editable side-by-side textareas (when off, for editing).
     */
    function updateDiffHighlighting() {
        if (!modalOriginalMarkdown || !modalMarkdown) return;

        const show = showDiffHighlights ? showDiffHighlights.checked : true;
        const originalLines = (modalOriginalMarkdown.value || '').split('\n');
        const generatedLines = (modalMarkdown.value || '').split('\n');
        const ops = computeDiffOps(originalLines, generatedLines);

        let added = 0, removed = 0;
        ops.forEach(o => { if (o.type === 'ins') added++; else if (o.type === 'del') removed++; });

        if (diffCount) {
            diffCount.textContent = `${added} added, ${removed} removed`;
            if (diffSummary) {
                diffSummary.className = 'diff-summary' + ((added + removed) > 0 ? ' has-diffs' : '');
            }
        }

        const sideBySide = markdownEditorSection
            ? markdownEditorSection.querySelector('.markdown-side-by-side')
            : document.querySelector('.markdown-side-by-side');

        if (show && diffView) {
            diffView.innerHTML = buildDiffHtml(ops);
            diffView.style.display = 'block';
            if (sideBySide) sideBySide.style.display = 'none';
        } else {
            if (diffView) diffView.style.display = 'none';
            if (sideBySide) sideBySide.style.display = '';
        }
    }

    /**
     * Sync scroll position between the two textareas
     */
    function syncScroll(e) {
        const source = e.target;
        const target = source === modalOriginalMarkdown ? modalMarkdown : modalOriginalMarkdown;
        const sourceLineNumbers = source === modalOriginalMarkdown ? originalLineNumbers : generatedLineNumbers;
        const targetLineNumbers = source === modalOriginalMarkdown ? generatedLineNumbers : originalLineNumbers;

        if (target) {
            target.scrollTop = source.scrollTop;
        }
        if (sourceLineNumbers) {
            sourceLineNumbers.scrollTop = source.scrollTop;
        }
        if (targetLineNumbers) {
            targetLineNumbers.scrollTop = source.scrollTop;
        }
    }

    /**
     * Save markdown edits to the database
     */
    async function saveMarkdownEdits() {
        if (!currentSubmission || !modalMarkdown) return;

        const currentType = typeFilter ? typeFilter.value : 'wiki';
        const editedMarkdown = modalMarkdown.value;

        saveEditsBtn.disabled = true;
        saveEditsBtn.textContent = 'Saving...';

        try {
            const response = await fetch(MANAGE_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update_markdown',
                    type: currentType,
                    id: currentSubmission.id,
                    generated_markdown: editedMarkdown
                })
            });

            const result = await response.json();

            if (result.success) {
                saveEditsBtn.textContent = 'âœ“ Saved!';
                currentSubmission.generated_markdown = editedMarkdown;
                setTimeout(() => {
                    saveEditsBtn.textContent = 'ðŸ’¾ Save Edits';
                    saveEditsBtn.disabled = false;
                }, 2000);
            } else {
                throw new Error(result.error || 'Save failed');
            }
        } catch (error) {
            console.error('Save failed:', error);
            saveEditsBtn.textContent = 'âœ— Error';
            setTimeout(() => {
                saveEditsBtn.textContent = 'ðŸ’¾ Save Edits';
                saveEditsBtn.disabled = false;
            }, 2000);
            alert(`Failed to save edits: ${error.message}`);
        }
    }

    /**
     * Perform action (approve, reject, publish, delete)
     */
    async function performAction(action) {
        if (!currentSubmission) return;
        const currentType = typeFilter ? typeFilter.value : 'wiki';

        const notes = reviewerNotes.value.trim();
        const email = reviewerEmail.value.trim();

        if (!email && action !== 'delete') {
            alert('Please enter your email/name');
            return;
        }

        // Save email for future use
        if (email) {
            localStorage.setItem('adminEmail', email);
        }

        // Disable all buttons during action
        approveBtn.disabled = true;
        rejectBtn.disabled = true;
        publishBtn.disabled = true;
        deleteBtn.disabled = true;

        actionStatus.innerHTML = '<span class="loading">Processing...</span>';

        try {
            const response = await fetch(MANAGE_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: action,
                    type: currentType, // Dynamic type
                    ids: [currentSubmission.id],
                    reviewerNotes: notes,
                    reviewedBy: email
                })
            });

            const result = await response.json();

            if (result.success) {
                actionStatus.innerHTML = `<span class="success">âœ“ ${result.message}</span>`;

                // Refresh data
                setTimeout(() => {
                    loadStats();
                    loadSubmissions();
                    if (action === 'delete') {
                        closeModal();
                    } else {
                        // Reload current submission to show updated status
                        viewSubmission(currentSubmission.id);
                    }
                }, 1000);
            } else {
                actionStatus.innerHTML = `<span class="error">âœ— ${result.error || 'Action failed'}</span>`;
                updateButtonStates(currentSubmission.status);
            }
        } catch (error) {
            console.error('Action failed:', error);
            actionStatus.innerHTML = '<span class="error">âœ— Network error</span>';
            updateButtonStates(currentSubmission.status);
        }
    }

    /**
     * Reject all pending submissions currently displayed
     */
    async function rejectAllPending() {
        // Get all pending submissions from the current list
        const pendingSubmissions = allSubmissions.filter(s => s.status === 'submitted');

        if (pendingSubmissions.length === 0) {
            alert('No pending submissions to reject.');
            return;
        }

        const confirmMessage = `Are you sure you want to reject all ${pendingSubmissions.length} pending submission(s)?\n\nThis will mark them all as rejected.`;
        if (!confirm(confirmMessage)) {
            return;
        }

        // Get reviewer email
        const email = localStorage.getItem('adminEmail') || prompt('Enter your email/name for the review record:');
        if (!email) {
            alert('Reviewer email/name is required.');
            return;
        }
        localStorage.setItem('adminEmail', email);

        const currentType = typeFilter ? typeFilter.value : 'wiki';
        const ids = pendingSubmissions.map(s => s.id);

        // Disable the button during processing
        rejectAllBtn.disabled = true;
        rejectAllBtn.textContent = 'Rejecting...';

        try {
            const response = await fetch(MANAGE_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'reject',
                    type: currentType,
                    ids: ids,
                    reviewerNotes: 'Bulk rejection',
                    reviewedBy: email
                })
            });

            const result = await response.json();

            if (result.success) {
                alert(`Successfully rejected ${ids.length} submission(s).`);
                loadStats();
                loadSubmissions();
            } else {
                alert(`Failed to reject submissions: ${result.error || 'Unknown error'}`);
            }
        } catch (error) {
            console.error('Reject all failed:', error);
            alert('Network error while rejecting submissions.');
        } finally {
            rejectAllBtn.disabled = false;
            rejectAllBtn.textContent = 'âœ— Reject All Pending';
        }
    }

    /**
     * Format date
     */
    function formatDate(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleDateString();
    }

    /**
     * Format date and time
     */
    function formatDateTime(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleString();
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Return the URL only if it parses and uses http(s); otherwise null.
     * Defends against javascript: / data: schemes and attribute-breakout payloads
     * before we interpolate a submitter-supplied URL into an href.
     */
    function safeUrl(url) {
        if (!url || typeof url !== 'string') return null;
        try {
            const parsed = new URL(url);
            return (parsed.protocol === 'http:' || parsed.protocol === 'https:') ? parsed.href : null;
        } catch (e) {
            return null;
        }
    }

    /**
     * Debounce function
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
    // =========================================================================
    // Facility Link UI
    // =========================================================================

    const LINK_API = (config.linkApi) || `${API_BASE}/link-wiki-facility.php`;

    const facilityLinkSection         = document.getElementById('facilityLinkSection');
    const facilityLinkStatusBadge     = document.getElementById('facilityLinkStatusBadge');
    const facilityLinkName            = document.getElementById('facilityLinkName');
    const facilityLinkExistingActions = document.getElementById('facilityLinkExistingActions');
    const confirmLinkBtn              = document.getElementById('confirmLinkBtn');
    const unlinkBtn                   = document.getElementById('unlinkBtn');
    const suggestLinksBtn             = document.getElementById('suggestLinksBtn');
    const facilityLinkCandidates      = document.getElementById('facilityLinkCandidates');
    const facilityLinkCandidateList   = document.getElementById('facilityLinkCandidateList');
    const manualFacilityName          = document.getElementById('manualFacilityName');
    const manualLinkBtn               = document.getElementById('manualLinkBtn');
    const facilityLinkMessage         = document.getElementById('facilityLinkMessage');

    /** Show the facility-link section and load the current link state. */
    async function initFacilityLink(submissionId) {
        if (!facilityLinkSection) return;
        const currentType = typeFilter ? typeFilter.value : 'wiki';
        if (currentType !== 'wiki') {
            facilityLinkSection.style.display = 'none';
            return;
        }
        facilityLinkSection.style.display = 'block';
        if (facilityLinkCandidates) facilityLinkCandidates.style.display = 'none';
        hideLinkMessage();
        try {
            const params = new URLSearchParams({
                action: 'get', type: 'submission', wiki_id: submissionId
            });
            const res = await fetch(`${LINK_API}?${params}`);
            const result = await res.json();
            if (result.success) renderLinkState(result.data);
        } catch (e) {
            console.warn('Could not load facility link state:', e);
        }
    }

    /** Render the current link state into the panel. */
    function renderLinkState(data) {
        const status = data.facility_link_status;
        const name   = data.facility_unique_name || '';
        if (facilityLinkName) facilityLinkName.textContent = name ? `\u2192 ${name}` : '';
        if (!facilityLinkStatusBadge) return;
        if (!status) {
            facilityLinkStatusBadge.textContent = 'Unlinked';
            facilityLinkStatusBadge.className = 'facility-link-badge badge-unlinked';
            if (facilityLinkExistingActions) facilityLinkExistingActions.style.display = 'none';
        } else if (status === 'suggested') {
            facilityLinkStatusBadge.textContent = 'Suggested \u2013 awaiting confirmation';
            facilityLinkStatusBadge.className = 'facility-link-badge badge-suggested';
            if (facilityLinkExistingActions) facilityLinkExistingActions.style.display = 'flex';
            if (confirmLinkBtn) confirmLinkBtn.style.display = 'inline-block';
        } else if (status === 'confirmed') {
            facilityLinkStatusBadge.textContent = 'Confirmed';
            facilityLinkStatusBadge.className = 'facility-link-badge badge-confirmed';
            if (facilityLinkExistingActions) facilityLinkExistingActions.style.display = 'flex';
            if (confirmLinkBtn) confirmLinkBtn.style.display = 'none';
        }
    }

    function showLinkMessage(text, isError = false) {
        if (!facilityLinkMessage) return;
        facilityLinkMessage.textContent = text;
        facilityLinkMessage.className = 'facility-link-message ' + (isError ? 'link-msg-error' : 'link-msg-ok');
        facilityLinkMessage.style.display = 'block';
    }

    function hideLinkMessage() {
        if (facilityLinkMessage) facilityLinkMessage.style.display = 'none';
    }

    /** Fetch and render ranked facility candidates. */
    async function loadLinkCandidates() {
        if (!currentSubmission || !suggestLinksBtn) return;
        suggestLinksBtn.disabled = true;
        suggestLinksBtn.textContent = 'Searching\u2026';
        try {
            const params = new URLSearchParams({
                action: 'suggest', type: 'submission', wiki_id: currentSubmission.id
            });
            const res = await fetch(`${LINK_API}?${params}`);
            const result = await res.json();
            if (facilityLinkCandidates) facilityLinkCandidates.style.display = 'block';
            if (!facilityLinkCandidateList) return;
            facilityLinkCandidateList.innerHTML = '';
            if (!result.success || !result.candidates || !result.candidates.length) {
                facilityLinkCandidateList.innerHTML =
                    '<p class="no-candidates">No close matches found. Use the manual field below.</p>';
            } else {
                result.candidates.forEach(c => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'facility-candidate-btn';
                    btn.innerHTML =
                        `<span class="candidate-name">${escapeHtml(c.display_name || c.unique_name)}</span>` +
                        `<span class="candidate-score">${c.score}% match \u00B7 ${escapeHtml(c.match_reason)}</span>`;
                    btn.addEventListener('click', () => linkFacility(c.unique_name));
                    facilityLinkCandidateList.appendChild(btn);
                });
            }
        } catch (e) {
            showLinkMessage('Failed to load suggestions: ' + e.message, true);
        } finally {
            suggestLinksBtn.disabled = false;
            suggestLinksBtn.textContent = '\uD83D\uDD0D Find Matching Facilities';
        }
    }

    /** POST a link action and refresh the panel. */
    async function postLinkAction(payload) {
        hideLinkMessage();
        try {
            const res = await fetch(LINK_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const result = await res.json();
            if (result.success) {
                showLinkMessage(result.message || 'Done.');
                await initFacilityLink(currentSubmission.id);
            } else {
                showLinkMessage(result.error || 'Request failed.', true);
            }
        } catch (e) {
            showLinkMessage('Network error: ' + e.message, true);
        }
    }

    function linkFacility(uniqueName) {
        if (!currentSubmission) return;
        postLinkAction({
            action: 'link', type: 'submission',
            wiki_id: currentSubmission.id,
            facility_unique_name: uniqueName,
        });
        if (facilityLinkCandidates) facilityLinkCandidates.style.display = 'none';
    }

    // Wire up static button events
    if (suggestLinksBtn)  suggestLinksBtn.addEventListener('click', loadLinkCandidates);
    if (confirmLinkBtn) {
        confirmLinkBtn.addEventListener('click', () => {
            if (!currentSubmission) return;
            postLinkAction({ action: 'confirm', type: 'submission', wiki_id: currentSubmission.id });
        });
    }
    if (unlinkBtn) {
        unlinkBtn.addEventListener('click', () => {
            if (!currentSubmission) return;
            if (!confirm('Remove the facility link from this wiki entry?')) return;
            postLinkAction({ action: 'unlink', type: 'submission', wiki_id: currentSubmission.id });
        });
    }
    if (manualLinkBtn) {
        manualLinkBtn.addEventListener('click', () => {
            const name = manualFacilityName ? manualFacilityName.value.trim() : '';
            if (!name) { showLinkMessage('Enter a facility unique_name first.', true); return; }
            linkFacility(name);
        });
    }

    // Trigger initFacilityLink whenever the modal becomes visible for a wiki submission.
    // showModal() is a function declaration so we cannot wrap it; instead we observe
    // the modal element's style attribute for display changes.
    const _facilityLinkObserver = new MutationObserver((_mutations) => {
        if (
            facilityLinkSection &&
            submissionModal &&
            submissionModal.style.display !== 'none' &&
            currentSubmission
        ) {
            const currentType = typeFilter ? typeFilter.value : 'wiki';
            if (currentType === 'wiki') {
                initFacilityLink(currentSubmission.id);
            }
        }
    });
    if (submissionModal) {
        _facilityLinkObserver.observe(submissionModal, { attributes: true, attributeFilter: ['style'] });
    }
});