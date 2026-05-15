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
                
                const duplicateBadge = hasDuplicates ? '<span class="duplicate-badge">⚠ Duplicate</span>' : '';
                
                card.innerHTML = `
                    <div class="submission-header">
                        <h3>${escapeHtml(title)}${duplicateBadge}</h3>
                        <span class="status-badge ${statusClass}">${submission.status}</span>
                    </div>
                    <div class="submission-meta">
                        <span>📰 ${escapeHtml(source)}</span>
                        <span>✍️ ${escapeHtml(author)}</span>
                        <span>🏷️ ${escapeHtml(submission.article_type || 'general')}</span>
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
                        <span>🔄 Data Update</span>
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
                        <span>📍 ${escapeHtml(submission.city_state || 'Location unknown')}</span>
                        <span>📅 ${escapeHtml(submission.years_active || 'Years unknown')}</span>
                        <span>🏷️ ${escapeHtml(submission.program_type || 'Type unknown')}</span>
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
     * View submission details
     */
    async function viewSubmission(id) {
        console.log('viewSubmission called with id:', id);
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

        // Show modal
        submissionModal.style.display = 'flex';

        // Scroll to make the modal visible (since it's position: static, not an overlay)
        submissionModal.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
                                View Submission #${dup.id} →
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
        status.textContent = 'Scanning URLs…';
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

            // The modal may have closed or switched submissions while we were waiting —
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
                status.textContent = `⚠ ${result.flagged} of ${result.scanned} URL(s) flagged as unsafe.`;
                status.className = 'url-safety-status flagged';
            } else {
                status.textContent = `✓ All ${result.scanned} URL(s) clean.`;
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
                icon.textContent = r.clean === true ? '✓' : r.clean === false ? '⚠' : '?';
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
            copyMarkdownBtn.textContent = '✓ Copied!';
            setTimeout(() => {
                copyMarkdownBtn.textContent = '📋 Copy';
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

    /**
     * Update diff highlighting between original and generated markdown
     */
    function updateDiffHighlighting() {
        if (!modalOriginalMarkdown || !modalMarkdown) return;

        const showHighlights = showDiffHighlights ? showDiffHighlights.checked : true;
        const originalLines = (modalOriginalMarkdown.value || '').split('\n');
        const generatedLines = (modalMarkdown.value || '').split('\n');
        const maxLines = Math.max(originalLines.length, generatedLines.length);

        let diffCountNum = 0;
        const originalDiffLines = [];
        const generatedDiffLines = [];

        for (let i = 0; i < maxLines; i++) {
            const origLine = originalLines[i] ?? '';
            const genLine = generatedLines[i] ?? '';
            const isDifferent = origLine.trim() !== genLine.trim();

            if (isDifferent) {
                diffCountNum++;
                originalDiffLines.push(i + 1);
                generatedDiffLines.push(i + 1);
            }
        }

        // Update diff count display
        if (diffCount) {
            diffCount.textContent = `${diffCountNum} difference${diffCountNum !== 1 ? 's' : ''}`;
            if (diffSummary) {
                diffSummary.className = 'diff-summary' + (diffCountNum > 0 ? ' has-diffs' : '');
            }
        }

        // Update line number highlighting
        if (showHighlights && originalLineNumbers) {
            const lineNums = originalLineNumbers.querySelectorAll('.line-num');
            lineNums.forEach((el, i) => {
                el.classList.toggle('diff-highlight', originalDiffLines.includes(i + 1));
            });
        } else if (originalLineNumbers) {
            originalLineNumbers.querySelectorAll('.line-num').forEach(el => {
                el.classList.remove('diff-highlight');
            });
        }

        if (showHighlights && generatedLineNumbers) {
            const lineNums = generatedLineNumbers.querySelectorAll('.line-num');
            lineNums.forEach((el, i) => {
                el.classList.toggle('diff-highlight', generatedDiffLines.includes(i + 1));
            });
        } else if (generatedLineNumbers) {
            generatedLineNumbers.querySelectorAll('.line-num').forEach(el => {
                el.classList.remove('diff-highlight');
            });
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
                saveEditsBtn.textContent = '✓ Saved!';
                currentSubmission.generated_markdown = editedMarkdown;
                setTimeout(() => {
                    saveEditsBtn.textContent = '💾 Save Edits';
                    saveEditsBtn.disabled = false;
                }, 2000);
            } else {
                throw new Error(result.error || 'Save failed');
            }
        } catch (error) {
            console.error('Save failed:', error);
            saveEditsBtn.textContent = '✗ Error';
            setTimeout(() => {
                saveEditsBtn.textContent = '💾 Save Edits';
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
                actionStatus.innerHTML = `<span class="success">✓ ${result.message}</span>`;

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
                actionStatus.innerHTML = `<span class="error">✗ ${result.error || 'Action failed'}</span>`;
                updateButtonStates(currentSubmission.status);
            }
        } catch (error) {
            console.error('Action failed:', error);
            actionStatus.innerHTML = '<span class="error">✗ Network error</span>';
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
            rejectAllBtn.textContent = '✗ Reject All Pending';
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
});