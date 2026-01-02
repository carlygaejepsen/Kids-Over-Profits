<?php
/**
 * Template Name: Admin - Wiki Submissions Review
 *
 * Admin interface for reviewing, approving, and managing wiki submissions
 */

// Check if user is admin (you can customize this check based on your auth system)
// if (!current_user_can('manage_options')) {
//     wp_die('Access denied');
// }

get_header();
?>

<div class="admin-submissions-page">
    <div class="admin-submissions-container">
        <header class="admin-header">
            <h1>Wiki Submissions Review</h1>
            <div class="admin-stats" id="adminStats">
                <div class="stat-card">
                    <span class="stat-label">Pending</span>
                    <span class="stat-value" id="statPending">-</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Approved</span>
                    <span class="stat-value" id="statApproved">-</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Published</span>
                    <span class="stat-value" id="statPublished">-</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Rejected</span>
                    <span class="stat-value" id="statRejected">-</span>
                </div>
            </div>
        </header>

        <div class="admin-controls">
            <div class="filter-controls">
                <label for="typeFilter">Type:</label>
                <select id="typeFilter">
                    <option value="wiki" selected>Wiki Submissions</option>
                    <option value="news">News Submissions</option>
                    <option value="data">Data Form Submissions</option>
                </select>

                <label for="statusFilter">Status:</label>
                <select id="statusFilter">
                    <option value="">All</option>
                    <option value="submitted" selected>Pending Review</option>
                    <option value="approved">Approved</option>
                    <option value="published">Published</option>
                    <option value="rejected">Rejected</option>
                </select>

                <label for="searchFilter">Search:</label>
                <input type="text" id="searchFilter" placeholder="Program name or location...">

                <button type="button" id="refreshBtn" class="btn-secondary">🔄 Refresh</button>
            </div>
        </div>

        <div class="submissions-list-container">
            <div id="loadingMessage" class="loading-message">Loading submissions...</div>
            <div id="submissionsList" class="submissions-list"></div>
            <div id="noSubmissions" class="no-submissions" style="display: none;">
                No submissions found.
            </div>
        </div>

        <!-- Submission Detail Modal -->
        <div id="submissionModal" class="submission-modal" style="display: none;">
            <div class="submission-modal-content">
                <div class="modal-header">
                    <h2 id="modalProgramName">Program Name</h2>
                    <button type="button" class="modal-close" id="closeModalBtn">&times;</button>
                </div>

                <div class="modal-body">
                    <!-- Submission Info -->
                    <div class="submission-info">
                        <div class="info-row">
                            <span class="info-label">Status:</span>
                            <span class="status-badge" id="modalStatus">submitted</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Submitted:</span>
                            <span id="modalSubmittedDate">-</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Submitted by:</span>
                            <span id="modalSubmittedBy">-</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Location:</span>
                            <span id="modalLocation">-</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Type:</span>
                            <span id="modalProgramType">-</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Years Active:</span>
                            <span id="modalYearsActive">-</span>
                        </div>
                    </div>

                    <!-- Submitter Notes -->
                    <div class="submission-notes" id="submitterNotesSection" style="display: none;">
                        <h3>Submitter Notes</h3>
                        <div id="submitterNotes" class="notes-content"></div>
                    </div>

                    <!-- Generated Markdown -->
                    <div class="markdown-preview">
                        <div class="markdown-header">
                            <h3>Generated Wiki Markdown</h3>
                            <button type="button" id="copyMarkdownBtn" class="btn-secondary">📋 Copy Markdown</button>
                        </div>
                        <textarea id="modalMarkdown"></textarea>
                    </div>

                    <div id="modalDiffSection" class="modal-diff-section">
                        <div class="modal-diff-header">
                            <h3>Uploaded Markdown vs. Generated Output</h3>
                            <p>Lines highlighted in coral show where the original submission diverged from the generated version.</p>
                        </div>
                        <div id="modalDiff" class="modal-diff-grid"></div>
                    </div>

                    <div class="markdown-preview original-markdown-preview">
                        <div class="markdown-header">
                            <h3>Uploaded Markdown (Read Only)</h3>
                            <span class="markdown-note">Full, unedited content that was uploaded or imported.</span>
                        </div>
                        <textarea id="modalOriginalMarkdown" rows="8" readonly placeholder="No uploaded markdown was provided for this submission."></textarea>
                    </div>

                    <!-- Form Data Preview -->
                    <div class="form-data-preview">
                        <details>
                            <summary>View Full Form Data (JSON)</summary>
                            <pre id="modalFormData"></pre>
                        </details>
                    </div>

                    <!-- Reviewer Section -->
                    <div class="reviewer-section">
                        <h3>Review Actions</h3>

                        <div class="reviewer-notes-input">
                            <label for="reviewerNotes">Reviewer Notes:</label>
                            <textarea id="reviewerNotes" rows="3" placeholder="Add notes about this submission..."></textarea>
                        </div>

                        <div class="reviewer-identity">
                            <label for="reviewerEmail">Your Email/Name:</label>
                            <input type="text" id="reviewerEmail" placeholder="admin@example.com">
                        </div>

                        <div id="existingReviewSection" style="display: none;">
                            <h4>Previous Review</h4>
                            <div class="info-row">
                                <span class="info-label">Reviewed by:</span>
                                <span id="modalReviewedBy">-</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Reviewed at:</span>
                                <span id="modalReviewedAt">-</span>
                            </div>
                            <div class="existing-notes" id="existingReviewerNotes"></div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <div class="action-buttons">
                        <button type="button" id="approveBtn" class="btn-approve">✓ Approve</button>
                        <button type="button" id="rejectBtn" class="btn-reject">✗ Reject</button>
                        <button type="button" id="publishBtn" class="btn-publish">📤 Mark as Published</button>
                        <button type="button" id="deleteBtn" class="btn-delete">🗑️ Delete</button>
                    </div>
                    <div id="actionStatus" class="action-status"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
get_footer();
?>
