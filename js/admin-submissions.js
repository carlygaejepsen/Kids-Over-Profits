/**
 * Admin Submissions Management JavaScript
 * Handles wiki submission review, approval, and management
 */

document.addEventListener('DOMContentLoaded', () => {
    const adminPage = document.querySelector('.admin-submissions-page');
    if (!adminPage) return;

    // DOM Elements
    const statusFilter = document.getElementById('statusFilter');
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
    const modalFormData = document.getElementById('modalFormData');
    const copyMarkdownBtn = document.getElementById('copyMarkdownBtn');
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

    // Stats elements
    const statPending = document.getElementById('statPending');
    const statApproved = document.getElementById('statApproved');
    const statPublished = document.getElementById('statPublished');
    const statRejected = document.getElementById('statRejected');

    // State
    let currentSubmission = null;
    let allSubmissions = [];

    // API endpoints
    const API_BASE = '/wp-content/themes/child/api';
    const MANAGE_API = `${API_BASE}/manage-submissions.php`;

    // Initialize
    loadStats();
    loadSubmissions();

    // Event Listeners
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

    copyMarkdownBtn.addEventListener('click', copyMarkdown);
    approveBtn.addEventListener('click', () => performAction('approve'));
    rejectBtn.addEventListener('click', () => performAction('reject'));
    publishBtn.addEventListener('click', () => performAction('publish'));
    deleteBtn.addEventListener('click', () => {
        if (confirm('Are you sure you want to delete this submission? This cannot be undone.')) {
            performAction('delete');
        }
    });

    // Functions

    /**
     * Load submission statistics
     */
    async function loadStats() {
        try {
            const response = await fetch(MANAGE_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'stats', type: 'wiki' })
            });

            const result = await response.json();
            if (result.success && result.wiki) {
                const stats = result.wiki.by_status || {};
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

        // Build query parameters
        const params = new URLSearchParams({
            action: 'list',
            type: 'wiki',
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

        submissions.forEach(submission => {
            const card = document.createElement('div');
            card.className = 'submission-card';
            card.dataset.id = submission.id;

            const statusClass = `status-${submission.status}`;
            const date = formatDate(submission.created_at);

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

            card.querySelector('.btn-view').addEventListener('click', () => viewSubmission(submission.id));
            submissionsList.appendChild(card);
        });
    }

    /**
     * View submission details
     */
    async function viewSubmission(id) {
        try {
            const detailParams = new URLSearchParams({
                action: 'get',
                type: 'wiki',
                id: id
            });
            const response = await fetch(`${MANAGE_API}?${detailParams.toString()}`);
            const result = await response.json();

            if (result.success && result.data) {
                currentSubmission = result.data;
                showModal(result.data);
            } else {
                alert('Failed to load submission details');
            }
        } catch (error) {
            console.error('Failed to load submission:', error);
            alert('Failed to load submission details');
        }
    }

    /**
     * Show submission modal
     */
    function showModal(submission) {
        // Parse JSON data
        const jsonData = typeof submission.json_data === 'string'
            ? JSON.parse(submission.json_data)
            : submission.json_data;

        // Basic info
        modalProgramName.textContent = submission.program_name;
        modalStatus.textContent = submission.status;
        modalStatus.className = `status-badge status-${submission.status}`;
        modalSubmittedDate.textContent = formatDateTime(submission.created_at);
        modalSubmittedBy.textContent = submission.submitted_by || 'Anonymous';
        modalLocation.textContent = submission.city_state || '-';
        modalProgramType.textContent = submission.program_type || '-';
        modalYearsActive.textContent = submission.years_active || '-';

        // Submitter notes
        if (submission.submission_notes) {
            submitterNotesSection.style.display = 'block';
            submitterNotes.textContent = submission.submission_notes;
        } else {
            submitterNotesSection.style.display = 'none';
        }

        // Markdown
        modalMarkdown.value = submission.generated_markdown || 'No markdown generated';

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

        // Show modal
        submissionModal.style.display = 'flex';
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
     * Close modal
     */
    function closeModal() {
        submissionModal.style.display = 'none';
        currentSubmission = null;
    }

    /**
     * Copy markdown to clipboard
     */
    async function copyMarkdown() {
        try {
            await navigator.clipboard.writeText(modalMarkdown.value);
            copyMarkdownBtn.textContent = '✓ Copied!';
            setTimeout(() => {
                copyMarkdownBtn.textContent = '📋 Copy Markdown';
            }, 2000);
        } catch (error) {
            alert('Failed to copy to clipboard');
        }
    }

    /**
     * Perform action (approve, reject, publish, delete)
     */
    async function performAction(action) {
        if (!currentSubmission) return;

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
                    type: 'wiki',
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
