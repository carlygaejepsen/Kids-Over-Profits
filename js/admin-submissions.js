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

    // Structured field editor (legislation / lawsuit / news / data)
    const structuredEditorSection = document.getElementById('structuredEditorSection');
    const structuredEditorBody = document.getElementById('structuredEditorBody');
    const saveFieldsBtn = document.getElementById('saveFieldsBtn');
    const structuredEditorStatus = document.getElementById('structuredEditorStatus');

    // Action buttons
    const approveBtn = document.getElementById('approveBtn');
    const rejectBtn = document.getElementById('rejectBtn');
    const promoBtn = document.getElementById('promoBtn');
    const publishBtn = document.getElementById('publishBtn');
    const deleteBtn = document.getElementById('deleteBtn');
    const rejectAllBtn = document.getElementById('rejectAllBtn');
    const selectAllPending = document.getElementById('selectAllPending');
    const selectedCount = document.getElementById('selectedCount');
    const approveSelectedBtn = document.getElementById('approveSelectedBtn');
    const rejectSelectedBtn = document.getElementById('rejectSelectedBtn');
    const promoSelectedBtn = document.getElementById('promoSelectedBtn');

    // Stats elements
    const statPending = document.getElementById('statPending');
    const statApproved = document.getElementById('statApproved');
    const statPublished = document.getElementById('statPublished');
    const statRejected = document.getElementById('statRejected');
    const statPromo = document.getElementById('statPromo');

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
     * any expanded-card visual state. Does NOT change modal visibility — the
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
    // Reviewer identity comes from the logged-in WordPress user (localized by
    // PHP). Admins are already authenticated, so we never need to ask them to
    // type a name/email. Fall back to any previously-saved value for safety.
    const REVIEWER = config.reviewer || localStorage.getItem('adminEmail') || '';
    // Endpoints of the old Lawsuit, Legislation and News Processor pages
    // (printed by templates/page-admin-submissions.php).
    const TOOLS = window.kopSubmissionsTools || {};

    // Type and status are tab rows; the hidden inputs hold the current value.
    const typeTabs = Array.from(document.querySelectorAll('.type-tabs [data-type]'));
    const statusTabs = Array.from(document.querySelectorAll('.status-tabs [data-status]'));

    function markTabs(tabs, attr, value) {
        tabs.forEach(tab => {
            const on = tab.getAttribute(attr) === value;
            tab.classList.toggle('is-active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }

    // Open on the tab a notification email links to (?type=wiki and so on).
    const linkedType = new URLSearchParams(window.location.search).get('type');
    if (typeFilter && linkedType && typeTabs.some(tab => tab.dataset.type === linkedType)) {
        typeFilter.value = linkedType;
    }
    /**
     * The Industry PR index (status 'promotional': fundraisers, anniversaries,
     * marketing and expansions the facilities put out) exists only for news,
     * so its tab, stat and buttons show on the news type alone.
     */
    function syncNewsOnly() {
        const type = typeFilter.value;
        const isNews = type === 'news';
        const isRecord = type === 'lawsuit' || type === 'legislation';
        document.querySelectorAll('.news-only').forEach(el => { el.hidden = !isNews; });
        // The old Lawsuit / Legislation / News Processor pages' controls.
        document.querySelectorAll('.record-only').forEach(el => { el.hidden = !isRecord; });
        document.querySelectorAll('.legislation-only').forEach(el => { el.hidden = type !== 'legislation'; });
        document.querySelectorAll('.dated-only').forEach(el => { el.hidden = !(isRecord || isNews); });
        document.querySelectorAll('.can-add').forEach(el => { el.hidden = !(isRecord || isNews); });
        document.querySelectorAll('.not-data').forEach(el => { el.hidden = type === 'data'; });
        if (!isNews && statusFilter.value === 'promotional') {
            statusFilter.value = 'submitted';
        }
        if (type === 'data' && statusFilter.value === 'draft') {
            statusFilter.value = 'submitted';
        }
        // "Already on file" exists for news, lawsuits and bills only.
        if (!(isRecord || isNews) && statusFilter.value === 'on_file') {
            statusFilter.value = 'submitted';
        }
        if (typeof syncAddNew === 'function') syncAddNew();
    }

    markTabs(typeTabs, 'data-type', typeFilter.value);
    syncNewsOnly();
    markTabs(statusTabs, 'data-status', statusFilter.value);

    typeTabs.forEach(tab => tab.addEventListener('click', () => {
        if (typeFilter.value === tab.dataset.type) return;
        typeFilter.value = tab.dataset.type;
        markTabs(typeTabs, 'data-type', typeFilter.value);
        syncNewsOnly();
        markTabs(statusTabs, 'data-status', statusFilter.value);
        const url = new URL(window.location.href);
        url.searchParams.set('type', typeFilter.value);
        window.history.replaceState(null, '', url);
        typeFilter.dispatchEvent(new Event('change'));
    }));
    statusTabs.forEach(tab => tab.addEventListener('click', () => {
        if (statusFilter.value === tab.dataset.status) return;
        statusFilter.value = tab.dataset.status;
        markTabs(statusTabs, 'data-status', statusFilter.value);
        statusFilter.dispatchEvent(new Event('change'));
    }));

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
    ['jurisdictionFilter', 'levelFilter', 'sortFilter'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', loadSubmissions);
    });
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
    if (promoBtn) {
        promoBtn.addEventListener('click', () => performAction('promo'));
    }
    /*
     * "Not a news article?": move the link onto a facility record as its
     * website (profileLinks) or an additional resource (resourceLinks). The
     * server files the article as rejected and keeps json_data.movedTo for Undo.
     */
    const refileSection = document.getElementById('refileSection');
    const refileForm = document.getElementById('refileForm');
    const refileResourceFields = document.getElementById('refileResourceFields');
    const refileKind = document.getElementById('refileKind');
    const refileLabel = document.getElementById('refileLabel');
    const refileFacility = document.getElementById('refileFacility');
    const refileMentions = document.getElementById('refileMentions');
    const refileBtn = document.getElementById('refileBtn');
    const refileMoved = document.getElementById('refileMoved');
    const refileMovedText = document.getElementById('refileMovedText');
    const refileUndoBtn = document.getElementById('refileUndoBtn');
    const refileStatus = document.getElementById('refileStatus');

    function refileTarget() {
        const on = refileSection && refileSection.querySelector('input[name="refileTarget"]:checked');
        return on ? on.value : 'website';
    }

    function showRefile(submission) {
        if (!refileSection) return;
        const moved = submission.json_data && submission.json_data.movedTo;
        refileStatus.textContent = '';
        refileStatus.className = 'action-status';
        refileForm.hidden = !!moved;
        refileMoved.hidden = !moved;
        if (moved) {
            refileMovedText.textContent = `Moved to ${moved.facility_name || 'facility #' + moved.facility_id} as `
                + (moved.target === 'website' ? 'its website.' : 'an additional resource.');
            return;
        }
        refileLabel.value = '';
        refileFacility.value = '';
        const picked = refileSection.querySelector('.kop-ff-picked');
        if (picked) picked.textContent = '';
        // The names the article mentions, one click to search each.
        refileMentions.textContent = '';
        const names = coerceList(submission.facilities_mentioned || (submission.json_data && submission.json_data.facilities));
        if (names.length) {
            refileMentions.appendChild(document.createTextNode('Mentions: '));
            names.slice(0, 8).forEach(name => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'btn-refile-mention';
                b.textContent = name;
                b.addEventListener('click', () => {
                    const q = refileSection.querySelector('.kop-ff-q');
                    if (!q) return;
                    q.value = name;
                    q.dispatchEvent(new Event('input', { bubbles: true }));
                    q.focus();
                });
                refileMentions.appendChild(b);
            });
        }
    }

    async function runRefile(action) {
        if (!currentSubmission) return;
        const body = {
            action: action,
            type: 'news',
            ids: [currentSubmission.id],
            reviewedBy: (reviewerEmail && reviewerEmail.value.trim()) || REVIEWER || ''
        };
        if (action === 'refile') {
            body.target = refileTarget();
            body.facilityId = parseInt(refileFacility.value, 10) || 0;
            body.resourceKind = refileKind.value;
            body.label = refileLabel.value.trim();
            if (!body.facilityId) {
                refileStatus.className = 'action-status';
                refileStatus.innerHTML = '<span class="error">✗ Pick the facility first.</span>';
                return;
            }
        }
        refileBtn.disabled = refileUndoBtn.disabled = true;
        refileStatus.innerHTML = '<span class="loading">Working...</span>';
        try {
            const res = await fetch(MANAGE_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const result = await res.json();
            if (result.success) {
                refileStatus.innerHTML = `<span class="success">${kopIcon('check')} ${escapeHtml(result.message)}</span>`;
                setTimeout(() => {
                    loadStats();
                    loadSubmissions();
                    viewSubmission(currentSubmission.id);
                }, 1000);
            } else {
                refileStatus.innerHTML = `<span class="error">✗ ${escapeHtml(result.error || 'Move failed')}</span>`;
            }
        } catch (error) {
            console.error('Move failed:', error);
            refileStatus.innerHTML = '<span class="error">✗ Network error</span>';
        } finally {
            refileBtn.disabled = refileUndoBtn.disabled = false;
        }
    }

    if (refileSection) {
        refileSection.querySelectorAll('input[name="refileTarget"]').forEach(r => {
            r.addEventListener('change', () => { refileResourceFields.hidden = refileTarget() !== 'resource'; });
        });
        refileBtn.addEventListener('click', () => runRefile('refile'));
        refileUndoBtn.addEventListener('click', () => {
            if (confirm('Take the link off the facility record and put the article back in the news queue?')) runRefile('unrefile');
        });
    }

    publishBtn.addEventListener('click', () => performAction('publish'));
    deleteBtn.addEventListener('click', () => {
        if (confirm('Are you sure you want to delete this submission? This cannot be undone.')) {
            performAction('delete');
        }
    });

    if (rejectAllBtn) {
        rejectAllBtn.addEventListener('click', rejectAllPending);
    }
    if (approveSelectedBtn) {
        approveSelectedBtn.addEventListener('click', () => bulkAction('approve'));
    }
    if (rejectSelectedBtn) {
        rejectSelectedBtn.addEventListener('click', () => bulkAction('reject'));
    }
    if (promoSelectedBtn) {
        promoSelectedBtn.addEventListener('click', () => bulkAction('promo'));
    }
    if (selectAllPending) {
        selectAllPending.addEventListener('change', () => {
            submissionsList.querySelectorAll('.card-select:not(:disabled)').forEach(cb => {
                cb.checked = selectAllPending.checked;
            });
            updateSelectionState();
        });
    }
    // Quick approve / reject / publish buttons and the select boxes live on
    // every card, so one delegated listener covers them all across re-renders.
    submissionsList.addEventListener('click', (e) => {
        const move = e.target.closest('.btn-quick-move[data-move], .btn-quick-unmove');
        if (move && !move.disabled) {
            const card = move.closest('.submission-card');
            if (!card) return;
            if (move.dataset.move) {
                toggleCardMove(card, move.dataset.move);
            } else if (confirm('Take the link off the facility record and put the article back in the news queue?')) {
                cardMove(card, 'unrefile');
            }
            return;
        }
        const btn = e.target.closest('.btn-quick[data-action]');
        if (!btn || btn.disabled) return;
        const card = btn.closest('.submission-card');
        if (!card) return;
        quickAction(btn.dataset.action, card.dataset.id, card);
    });

    /*
     * The card's own "move to facility record" panel: the facility finder,
     * the article's mentions one click away (the first is searched at once),
     * and for a resource its kind. Same refile action as the detail panel.
     */
    function toggleCardMove(card, target) {
        let panel = card.querySelector(':scope > .card-move');
        if (panel && panel.dataset.target === target) {
            panel.remove();
            return;
        }
        if (panel) panel.remove();
        const submission = allSubmissions.find(s => String(s.id) === String(card.dataset.id));
        if (!submission) return;
        panel = document.createElement('div');
        panel.className = 'card-move';
        panel.dataset.target = target;
        const kinds = refileKind ? refileKind.innerHTML : '<option value="other">Other links</option>';
        panel.innerHTML = `
            <span class="card-move-title">${target === 'website' ? 'Program website of' : 'Resource for'}</span>
            <input type="number" min="1" class="card-move-fid" data-kop-facility-finder="1" placeholder="id" aria-label="Facility id">
            ${target === 'resource' ? `<label class="card-move-kind">Kind <select>${kinds}</select></label>` : ''}
            <button type="button" class="btn-quick btn-quick-move-go">Move</button>
            <span class="card-move-status" aria-live="polite"></span>
            <div class="card-move-mentions"></div>`;
        card.querySelector(':scope > .submission-footer').insertAdjacentElement('afterend', panel);
        const fid = panel.querySelector('.card-move-fid');
        if (window.kopFacilityFinderAttach) window.kopFacilityFinderAttach(fid);
        const q = panel.querySelector('.kop-ff-q');
        const search = (name) => {
            if (!q) return;
            q.value = name;
            q.dispatchEvent(new Event('input', { bubbles: true }));
            q.focus();
        };
        const names = coerceList(submission.facilities_mentioned || (submission.json_data && submission.json_data.facilities));
        const mentions = panel.querySelector('.card-move-mentions');
        if (names.length) {
            mentions.appendChild(document.createTextNode('Mentions: '));
            names.slice(0, 8).forEach(name => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'btn-refile-mention';
                b.textContent = name;
                b.addEventListener('click', () => search(name));
                mentions.appendChild(b);
            });
            search(names[0]);
        } else if (q) {
            q.focus();
        }
        panel.querySelector('.btn-quick-move-go').addEventListener('click', () => cardMove(card, 'refile'));
    }

    async function cardMove(card, action) {
        const submission = allSubmissions.find(s => String(s.id) === String(card.dataset.id));
        if (!submission) return;
        const panel = card.querySelector(':scope > .card-move');
        const body = {
            action: action,
            type: 'news',
            ids: [submission.id],
            reviewedBy: (reviewerEmail && reviewerEmail.value.trim()) || REVIEWER || localStorage.getItem('adminEmail') || ''
        };
        const say = (cls, text) => {
            const el = panel ? panel.querySelector('.card-move-status') : card.querySelector('.card-action-status');
            if (el) { el.className = (panel ? 'card-move-status ' : 'card-action-status ') + cls; el.textContent = text; }
        };
        if (action === 'refile') {
            body.target = panel.dataset.target;
            body.facilityId = parseInt(panel.querySelector('.card-move-fid').value, 10) || 0;
            const kind = panel.querySelector('.card-move-kind select');
            body.resourceKind = kind ? kind.value : 'other';
            if (!body.facilityId) {
                say('error', 'Pick the facility first.');
                return;
            }
        }
        card.querySelectorAll('.btn-quick').forEach(b => { b.disabled = true; });
        say('loading', 'Working...');
        try {
            const res = await fetch(MANAGE_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const result = await res.json();
            if (!result.success) {
                card.querySelectorAll('.btn-quick').forEach(b => { b.disabled = false; });
                say('error', result.error || 'Move failed');
                return;
            }
            submission.status = result.status || (action === 'refile' ? 'rejected' : 'submitted');
            submission.json_data = Object.assign({}, submission.json_data || {}, { movedTo: result.movedTo || null });
            if (!result.movedTo) delete submission.json_data.movedTo;
            if (panel) panel.remove();
            refreshCard(card, submission);
            const fresh = card.querySelector('.card-action-status');
            if (fresh) { fresh.className = 'card-action-status success'; fresh.textContent = action === 'refile' ? 'moved' : 'back in queue'; }
            if (currentSubmission && String(currentSubmission.id) === String(submission.id)) {
                viewSubmission(submission.id);
            }
            loadStats();
            updateSelectionState();
        } catch (error) {
            console.error('Move failed:', error);
            card.querySelectorAll('.btn-quick').forEach(b => { b.disabled = false; });
            say('error', 'Network error');
        }
    }
    submissionsList.addEventListener('change', (e) => {
        if (e.target.classList && e.target.classList.contains('card-select')) {
            updateSelectionState();
        }
    });

    if (saveEditsBtn) {
        saveEditsBtn.addEventListener('click', saveMarkdownEdits);
    }

    if (saveFieldsBtn) {
        saveFieldsBtn.addEventListener('click', saveStructuredFields);
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
    async function fetchStats(type) {
        const response = await fetch(MANAGE_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'stats', type: type })
        });
        const result = await response.json();
        if (!result.success) return null;
        if (type === 'news' && result.news) {
            return result.news.by_status || {};
        }
        if (result.stats) {
            // legislation / lawsuits return a generic per-type stats block
            return result.stats.by_status || {};
        }
        if (result.wiki) {
            return result.wiki.by_status || {};
        }
        return {};
    }

    // "Pending" is called 'submitted' in wiki/news and 'pending' in the
    // data / legislation / lawsuit tables.
    function pendingCount(stats) {
        return Number(stats.submitted || stats.pending || 0);
    }

    async function loadStats() {
        loadTabCounts();
        try {
            const stats = await fetchStats(typeFilter ? typeFilter.value : 'wiki');
            if (stats) {
                statPending.textContent = pendingCount(stats);
                statApproved.textContent = stats.approved || 0;
                statPublished.textContent = stats.published || 0;
                statRejected.textContent = stats.rejected || 0;
                if (statPromo) statPromo.textContent = stats.promotional || 0;
                const statOnFile = document.getElementById('statOnFile');
                if (statOnFile) {
                    const n = Number(stats.on_file || 0);
                    statOnFile.textContent = n > 0 ? String(n) : '';
                    statOnFile.hidden = n === 0;
                }
            }
        } catch (error) {
            console.error('Failed to load stats:', error);
        }
    }

    /**
     * Pending count on every type tab, so a queue with work in it is visible
     * without opening it.
     */
    function loadTabCounts() {
        typeTabs.forEach(async tab => {
            const badge = tab.querySelector('.tab-count');
            if (!badge) return;
            try {
                const stats = await fetchStats(tab.dataset.type);
                const n = stats ? pendingCount(stats) : 0;
                badge.textContent = n > 0 ? String(n) : '';
                badge.hidden = n === 0;
            } catch (error) {
                badge.hidden = true;
            }
        });
    }

    /**
     * Load submissions based on current filters
     */
    async function loadSubmissions() {
        const status = statusFilter.value;
        const search = searchFilter.value.trim();
        const currentType = typeFilter ? typeFilter.value : 'wiki';

        // Build query parameters. The API caps a page at 200, so page
        // through until the whole filtered list is in (a status tab such as
        // Rejected can hold more than one page, and refiling works off it).
        const PAGE_SIZE = 200;
        const MAX_ROWS = 3000;
        const params = new URLSearchParams({
            action: 'list',
            type: currentType,
            limit: String(PAGE_SIZE)
        });
        // "Already on file": pending items the server found in our records
        // (inc/review-inbox/_on-file.php); Pending itself leaves them out.
        if (status === 'on_file') {
            params.set('status', 'submitted');
            params.set('on_file', 'only');
        } else if (status) {
            params.set('status', status);
        }
        if (search) params.set('search', search);
        // "Came from" (filled by js/review-inbox.js): the scraper, an import, the extension, people.
        const originFilter = document.getElementById('originFilter');
        if (originFilter && originFilter.value) params.set('origin', originFilter.value);
        // Place, level and date order (the old Lawsuit / Legislation pages' list filters).
        [['jurisdictionFilter', 'jurisdiction'], ['levelFilter', 'level'], ['sortFilter', 'sort']].forEach(([id, key]) => {
            const el = document.getElementById(id);
            if (el && el.value && !el.closest('[hidden]')) params.set(key, el.value);
        });

        loadingMessage.style.display = 'block';
        detachModal();              // restore modal to original parent before wiping list
        submissionsList.innerHTML = '';
        noSubmissions.style.display = 'none';

        try {
            let result = null;
            let rows = [];
            for (let offset = 0; offset < MAX_ROWS; offset += PAGE_SIZE) {
                params.set('offset', String(offset));
                const response = await fetch(`${MANAGE_API}?${params.toString()}`);
                const page = await response.json();
                if (!page.success || !Array.isArray(page.data)) {
                    result = page;
                    break;
                }
                rows = rows.concat(page.data);
                result = Object.assign({}, page, { data: rows });
                if (page.data.length < PAGE_SIZE || rows.length >= (page.total || 0)) break;
            }

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
    function isPendingStatus(status) {
        return status === 'submitted' || status === 'pending';
    }

    /**
     * The status a submission lands in after an action, mirroring the API:
     * legislation and lawsuits publish on approve, data has no publish step.
     */
    function statusAfter(action, type) {
        if (action === 'reject') return 'rejected';
        if (action === 'promo') return 'promotional';
        if (action === 'publish') return type === 'data' ? 'approved' : 'published';
        return (type === 'legislation' || type === 'lawsuit') ? 'published' : 'approved';
    }

    /**
     * Card footer: select box, date, quick actions and the details toggle.
     * Every submission type shares it so approve / reject never needs the
     * detail panel open.
     */
    function cardFooterHtml(submission, currentType) {
        const status = submission.status;
        const date = formatDate(submission.created_at);
        const pending = isPendingStatus(status);
        const canApprove = pending || status === 'rejected' || status === 'promotional';
        const canReject = status !== 'rejected';
        const canPublish = currentType !== 'data' && status === 'approved';
        const canPromo = currentType === 'news' && status !== 'promotional';
        const selectable = pending || status === 'rejected';
        // News that is really a program's own site or a resource for its page:
        // put it on the facility record straight from the card.
        const moved = currentType === 'news' && submission.json_data && submission.json_data.movedTo;
        const canMove = currentType === 'news' && !moved && /^https?:\/\//i.test(submission.article_url || '');
        const moveHtml = moved
            ? `<span class="card-moved">On ${escapeHtml(moved.facility_name || 'facility #' + moved.facility_id)} as ${moved.target === 'website' ? 'its website' : 'a resource'}</span>
               <button type="button" class="btn-quick btn-quick-unmove" data-unmove="1">Undo move</button>`
            : (canMove ? `<button type="button" class="btn-quick btn-quick-move" data-move="website" title="Put the link on a facility record as the program's website">${kopIcon('globe')} Website</button>
               <button type="button" class="btn-quick btn-quick-move" data-move="resource" title="Put the link on a facility record under Materials and links">${kopIcon('link')} Resource</button>` : '');
        return `
            <div class="submission-footer">
                <label class="card-select-wrap">
                    <input type="checkbox" class="card-select" data-id="${submission.id}" ${selectable ? '' : 'disabled'} aria-label="Select this submission">
                    <span class="submission-date">Submitted: ${date}</span>
                </label>
                <div class="card-actions">
                    <span class="card-action-status" aria-live="polite"></span>
                    <button type="button" class="btn-quick btn-quick-approve" data-action="approve" ${canApprove ? '' : 'disabled'}>${kopIcon('check')} Approve</button>
                    ${canPublish ? `<button type="button" class="btn-quick btn-quick-publish" data-action="publish">${kopIcon('upload')} Publish</button>` : ''}
                    <button type="button" class="btn-quick btn-quick-reject" data-action="reject" ${canReject ? '' : 'disabled'}>${kopIcon('x')} Reject</button>
                    ${canPromo ? `<button type="button" class="btn-quick btn-quick-promo" data-action="promo" title="File as industry PR: internal index, never public">${kopIcon('megaphone')} PR</button>` : ''}
                    ${moveHtml}
                    <button type="button" class="btn-view" data-id="${submission.id}">View Details</button>
                </div>
            </div>`;
    }

    /**
     * Swap a card's status badge and footer for the submission's current
     * state without rebuilding the card, so an expanded detail panel inside
     * it survives.
     */
    function refreshCard(card, submission) {
        const currentType = typeFilter ? typeFilter.value : 'wiki';
        const badge = card.querySelector('.submission-header .status-badge');
        if (badge) {
            badge.className = `status-badge status-${submission.status}`;
            badge.textContent = submission.status;
        }
        const footer = card.querySelector(':scope > .submission-footer');
        if (footer) {
            const tpl = document.createElement('template');
            tpl.innerHTML = cardFooterHtml(submission, currentType).trim();
            const fresh = tpl.content.firstElementChild;
            footer.replaceWith(fresh);
            const btn = fresh.querySelector('.btn-view');
            btn.addEventListener('click', () => viewSubmission(submission.id));
            if (String(expandedCardId) === String(submission.id)) btn.textContent = 'Hide Details';
        }
        card.classList.toggle('is-pending', isPendingStatus(submission.status));
    }

    function renderSubmissions(submissions) {
        detachModal();              // see comment in loadSubmissions — must precede wiping list
        submissionsList.innerHTML = '';
        const currentType = typeFilter ? typeFilter.value : 'wiki';

        submissions.forEach(submission => {
            const card = document.createElement('div');
            card.className = 'submission-card' + (isPendingStatus(submission.status) ? ' is-pending' : '');
            card.dataset.id = submission.id;

            const statusClass = `status-${submission.status}`;
            const footer = cardFooterHtml(submission, currentType);

            if (currentType === 'news') {
                const title = submission.article_title || 'Untitled Article';
                const source = submission.publication_name || 'Unknown Source';
                const author = String(submission.author || '').trim();
                
                // Check for duplicates
                const url = submission.article_url;
                const normalized = normalizeUrl(url);
                const hasDuplicates = normalized && duplicateUrlMap.has(normalized) && duplicateUrlMap.get(normalized).length > 1;
                
                if (hasDuplicates) {
                    card.classList.add('has-duplicate');
                }
                
                const duplicateBadge = hasDuplicates ? `<span class="duplicate-badge">${kopIcon('alert-triangle')} Duplicate</span>` : '';
                // In the Industry PR index, show the kind and the article date.
                const promoKind = submission.status === 'promotional'
                    ? ((submission.json_data && submission.json_data.promoKind) || 'unsorted')
                    : '';
                const promoMeta = promoKind
                    ? (submission.years_active ? `<span>${kopIcon('calendar')} ${escapeHtml(String(submission.years_active).slice(0, 10))}</span>` : '')
                        + `<span class="promo-kind">${kopIcon('megaphone')} PR: ${escapeHtml(promoKind)}</span>`
                    : '';
                
                card.innerHTML = `
                    <div class="submission-header">
                        <h3>${escapeHtml(title)}${duplicateBadge}</h3>
                        <span class="status-badge ${statusClass}">${submission.status}</span>
                    </div>
                    <div class="submission-meta">
                        <span>${kopIcon('newspaper')} ${escapeHtml(source)}</span>
                        ${author ? `<span>${kopIcon('pen-line')} ${escapeHtml(author)}</span>` : ''}
                        <span>${kopIcon('tag')} ${escapeHtml(submission.article_type || 'general')}</span>
                        ${promoMeta}
                    </div>
                    ${footer}
                `;
            } else if (currentType === 'data') {
                card.innerHTML = `
                    <div class="submission-header">
                        <h3>${escapeHtml(submission.program_name)}</h3>
                        <span class="status-badge ${statusClass}">${submission.status}</span>
                    </div>
                    <div class="submission-meta">
                        <span>${kopIcon('refresh')} Data Update</span>
                    </div>
                    ${footer}
                `;
            } else if (currentType === 'legislation' || currentType === 'lawsuit') {
                const icon  = currentType === 'legislation' ? kopIcon('landmark') : kopIcon('scale');
                const label = currentType === 'legislation' ? 'Bill' : 'Court case';
                // What the old admin lists showed: filing date, or bill number and bill status.
                const rec = submission.json_data || {};
                const extra = currentType === 'lawsuit'
                    ? (rec.filing_date ? `<span>${kopIcon('calendar')} Filed ${escapeHtml(String(rec.filing_date).slice(0, 10))}</span>` : '')
                    : ((rec.bill_number ? `<span>${escapeHtml(rec.bill_number)}</span>` : '')
                        + (rec.status && rec.status !== 'unknown' ? `<span>${escapeHtml(String(rec.status).replace(/_/g, ' '))}</span>` : ''));
                card.innerHTML = `
                    <div class="submission-header">
                        <h3>${escapeHtml(submission.program_name || 'Untitled')}</h3>
                        <span class="status-badge ${statusClass}">${submission.status}</span>
                    </div>
                    <div class="submission-meta">
                        <span>${icon} ${label}</span>
                        <span>${kopIcon('map-pin')} ${escapeHtml(submission.city_state || 'Jurisdiction unknown')}</span>
                        ${extra}
                    </div>
                    ${footer}
                `;
            } else {
                card.innerHTML = `
                    <div class="submission-header">
                        <h3>${escapeHtml(submission.program_name)}</h3>
                        <span class="status-badge ${statusClass}">${submission.status}</span>
                    </div>
                    <div class="submission-meta">
                        <span>${kopIcon('map-pin')} ${escapeHtml(submission.city_state || 'Location unknown')}</span>
                        <span>${kopIcon('calendar')} ${escapeHtml(submission.years_active || 'Years unknown')}</span>
                        <span>${kopIcon('tag')} ${escapeHtml(submission.program_type || 'Type unknown')}</span>
                    </div>
                    ${footer}
                `;
            }

            if (submission.on_file && submission.on_file.label) {
                const note = document.createElement('p');
                note.className = 'on-file-note';
                const where = submission.on_file.url
                    ? `<a href="${escapeHtml(submission.on_file.url)}" target="_blank" rel="noopener">${escapeHtml(submission.on_file.label)}</a>`
                    : escapeHtml(submission.on_file.label);
                note.innerHTML = `${kopIcon('check-circle')} Already on file: ${where}`;
                const meta = card.querySelector('.submission-meta');
                if (meta) meta.after(note); else card.prepend(note);
            }
            card.querySelector('.btn-view').addEventListener('click', () => viewSubmission(submission.id));
            submissionsList.appendChild(card);
        });
        updateSelectionState();
    }

    /**
     * Enable the bulk buttons only when something is ticked, and keep the
     * "select all" box in step with the individual boxes.
     */
    function selectedIds() {
        return Array.from(submissionsList.querySelectorAll('.card-select:checked')).map(cb => cb.dataset.id);
    }

    function updateSelectionState() {
        const ids = selectedIds();
        const selectable = submissionsList.querySelectorAll('.card-select:not(:disabled)').length;
        if (selectedCount) {
            selectedCount.textContent = `${ids.length} selected`;
        }
        if (approveSelectedBtn) approveSelectedBtn.disabled = ids.length === 0;
        if (rejectSelectedBtn) rejectSelectedBtn.disabled = ids.length === 0;
        if (promoSelectedBtn) promoSelectedBtn.disabled = ids.length === 0;
        if (selectAllPending) {
            selectAllPending.disabled = selectable === 0;
            selectAllPending.checked = selectable > 0 && ids.length === selectable;
            selectAllPending.indeterminate = ids.length > 0 && ids.length < selectable;
        }
    }

    /**
     * POST one review action for a set of IDs. Shared by the detail panel,
     * the quick buttons on each card, and the bulk buttons.
     */
    async function runAction(action, ids, notes) {
        const currentType = typeFilter ? typeFilter.value : 'wiki';
        const email = (reviewerEmail && reviewerEmail.value.trim()) || REVIEWER || localStorage.getItem('adminEmail') || '';
        if (email) localStorage.setItem('adminEmail', email);
        const response = await fetch(MANAGE_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: action,
                type: currentType,
                ids: ids,
                reviewerNotes: notes || '',
                reviewedBy: email
            })
        });
        return response.json();
    }

    /**
     * Approve / reject / publish straight from the card. The card updates in
     * place (badge, buttons, stats); the list is not re-fetched so the rest
     * of the queue stays where it was.
     */
    async function quickAction(action, id, card) {
        const currentType = typeFilter ? typeFilter.value : 'wiki';
        const submission = allSubmissions.find(s => String(s.id) === String(id));
        if (!submission) return;

        const status = card.querySelector('.card-action-status');
        card.querySelectorAll('.btn-quick').forEach(b => { b.disabled = true; });
        if (status) { status.className = 'card-action-status loading'; status.textContent = 'Working...'; }

        try {
            const result = await runAction(action, [id], '');
            if (result.success) {
                submission.status = statusAfter(action, currentType);
                // Approving or rejecting closes this card's open details.
                if ((action === 'approve' || action === 'reject') && String(expandedCardId) === String(id)) {
                    closeModal();
                } else if (currentSubmission && String(currentSubmission.id) === String(id)) {
                    currentSubmission.status = submission.status;
                    modalStatus.textContent = submission.status;
                    modalStatus.className = `status-badge status-${submission.status}`;
                    updateButtonStates(submission.status);
                }
                refreshCard(card, submission);
                const fresh = card.querySelector('.card-action-status');
                if (fresh) { fresh.className = 'card-action-status success'; fresh.textContent = submission.status; }
                loadStats();
                updateSelectionState();
            } else {
                refreshCard(card, submission);
                const fresh = card.querySelector('.card-action-status');
                if (fresh) { fresh.className = 'card-action-status error'; fresh.textContent = result.error || 'Action failed'; }
            }
        } catch (error) {
            console.error('Quick action failed:', error);
            refreshCard(card, submission);
            const fresh = card.querySelector('.card-action-status');
            if (fresh) { fresh.className = 'card-action-status error'; fresh.textContent = 'Network error'; }
        }
    }

    /**
     * Approve, reject or file as industry PR every ticked card in one request.
     */
    const BULK_WORDS = {
        approve: { verb: 'Approve', doing: 'Approving', done: 'approved' },
        reject: { verb: 'Reject', doing: 'Rejecting', done: 'rejected' },
        promo: { verb: 'File as industry PR', doing: 'Filing', done: 'filed as industry PR' },
    };

    async function bulkAction(action) {
        const ids = selectedIds();
        if (ids.length === 0) return;
        const words = BULK_WORDS[action];
        if (!confirm(`${words.verb}: ${ids.length} selected submission(s)?`)) return;

        const btn = { approve: approveSelectedBtn, reject: rejectSelectedBtn, promo: promoSelectedBtn }[action];
        const label = btn.innerHTML;
        [approveSelectedBtn, rejectSelectedBtn, promoSelectedBtn].forEach(b => { if (b) b.disabled = true; });
        btn.textContent = `${words.doing} ${ids.length}...`;

        try {
            const result = await runAction(action, ids, action === 'reject' ? 'Bulk rejection' : '');
            if (!result.success) {
                alert(`Some submissions could not be ${words.done}: ${result.error || 'Unknown error'}`);
            }
        } catch (error) {
            console.error('Bulk action failed:', error);
            alert('Network error while updating submissions.');
        } finally {
            btn.innerHTML = label;
            loadStats();
            loadSubmissions();
        }
    }

    /**
     * View submission details — inline expansion. Clicking on the
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
        // (don't hide the modal — we'll move it into the new card)
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
            showRefile(submission);

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

        } else if (currentType === 'legislation' || currentType === 'lawsuit') {
            modalProgramName.textContent = submission.program_name || '(untitled)';

            // Re-label the generic info rows for record-style submissions.
            const recRows = document.querySelectorAll('.submission-info .info-row');
            if (recRows[3]) recRows[3].querySelector('.info-label').textContent = 'Jurisdiction:';
            if (recRows[4]) recRows[4].querySelector('.info-label').textContent = 'Type:';
            if (recRows[5]) recRows[5].querySelector('.info-label').textContent = 'Current status:';

            modalLocation.textContent = submission.city_state || '-';
            modalProgramType.textContent = currentType === 'legislation' ? 'Legislation' : 'Lawsuit';
            modalYearsActive.textContent = submission.status || '-';

            // No markdown / facility-link tooling for these — the full record is
            // shown in the "View Full Form Data" panel below.
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

        // Structured field editor (legislation / lawsuit / news / data; hidden
        // for wiki, which uses the markdown editor above).
        renderStructuredEditor(currentType, submission, jsonData);

        // Clear reviewer inputs
        reviewerNotes.value = '';
        reviewerEmail.value = reviewerEmail.value || REVIEWER;

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
        // can't be found — most commonly during the brief race window after
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
        if (promoBtn) promoBtn.disabled = status === 'promotional';
        const statusSel = document.getElementById('setStatusSelect');
        if (statusSel) statusSel.value = (status === 'pending' ? 'submitted' : status) || 'submitted';

        // Publish is a wiki/news concept (approved → live on wiki). The
        // suggested_edits enum for data submissions only allows
        // ('pending','approved','rejected'), so hide the button entirely
        // for data — approve already applies the edit.
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

        // The URL scanner only understands the wiki/news/data staging tables.
        if (submissionType === 'legislation' || submissionType === 'lawsuit') {
            section.style.display = 'none';
            return;
        }

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
                status.textContent = `${result.flagged} of ${result.scanned} URL(s) flagged as unsafe.`;
                status.insertAdjacentHTML('afterbegin', kopIcon('alert-triangle') + ' ');
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
                icon.innerHTML = r.clean === true ? '✓' : r.clean === false ? kopIcon('alert-triangle') : '?';
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
            copyMarkdownBtn.textContent = '✓ Copied!';
            setTimeout(() => {
                copyMarkdownBtn.innerHTML = `${kopIcon('clipboard')} Copy`;
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
                saveEditsBtn.textContent = '✓ Saved!';
                currentSubmission.generated_markdown = editedMarkdown;
                setTimeout(() => {
                    saveEditsBtn.innerHTML = `${kopIcon('save')} Save Edits`;
                    saveEditsBtn.disabled = false;
                }, 2000);
            } else {
                throw new Error(result.error || 'Save failed');
            }
        } catch (error) {
            console.error('Save failed:', error);
            saveEditsBtn.textContent = '✗ Error';
            setTimeout(() => {
                saveEditsBtn.innerHTML = `${kopIcon('save')} Save Edits`;
                saveEditsBtn.disabled = false;
            }, 2000);
            alert(`Failed to save edits: ${error.message}`);
        }
    }

    // =========================================================================
    // Structured field editor
    // =========================================================================

    // Per-type editable field definitions. Mirrors the server-side whitelist in
    // manage-submissions.php (kop_editable_fields). type: text | textarea | date
    // | url | select | list (newline-separated -> JSON array).
    const FIELD_CONFIGS = {
        legislation: [
            { key: 'bill_title', label: 'Bill title', type: 'text' },
            { key: 'bill_number', label: 'Bill number', type: 'text' },
            { key: 'jurisdiction', label: 'Jurisdiction', type: 'jurisdiction' },
            { key: 'chamber', label: 'Chamber', type: 'select', options: ['unknown','house','senate','assembly','joint','federal_house','federal_senate','other'] },
            { key: 'session_year', label: 'Session / year (choices follow the jurisdiction)', type: 'suggest' },
            { key: 'bill_type', label: 'Bill type (choices follow the jurisdiction)', type: 'suggest' },
            { key: 'status', label: 'Bill status', type: 'select', options: ['unknown','proposed','introduced','in_committee','passed_house','passed_senate','signed','vetoed','dead','enacted'] },
            { key: 'position', label: 'KOP position', type: 'select', options: ['unknown','support','oppose','neutral','watch'] },
            { key: 'introduced_date', label: 'Introduced date', type: 'date' },
            { key: 'last_action_date', label: 'Last action date', type: 'date' },
            { key: 'last_action_text', label: 'Last action', type: 'text' },
            { key: 'summary', label: 'Summary', type: 'textarea' },
            { key: 'sponsors', label: 'Sponsors (one per line)', type: 'list' },
            { key: 'subject_tags', label: 'Subject tags (one per line)', type: 'list' },
            { key: 'facilities_affected', label: 'Facilities affected (one per line)', type: 'list' },
            { key: 'tags', label: 'Tags (one per line)', type: 'list' },
            { key: 'full_text_url', label: 'Full text URL', type: 'url' },
            { key: 'official_url', label: 'Official tracker URL', type: 'url' },
            { key: 'filebird_folder_id', label: 'FileBird folder (supporting documents)', type: 'folder' },
            { key: 'reviewer_notes', label: 'Reviewer notes (internal)', type: 'textarea' },
        ],
        lawsuit: [
            { key: 'case_name', label: 'Case name', type: 'text' },
            { key: 'case_number', label: 'Case number', type: 'text' },
            { key: 'court', label: 'Court', type: 'text' },
            { key: 'jurisdiction', label: 'Jurisdiction', type: 'jurisdiction' },
            { key: 'status', label: 'Case status', type: 'select', options: ['unknown','filed','in_progress','settled','dismissed','ruling','appeal','closed'] },
            { key: 'filing_date', label: 'Filing date', type: 'date' },
            { key: 'settlement_amount', label: 'Settlement amount', type: 'text' },
            { key: 'summary', label: 'Summary', type: 'textarea' },
            { key: 'outcome', label: 'Outcome / disposition', type: 'textarea' },
            { key: 'plaintiffs', label: 'Plaintiffs (one per line)', type: 'list' },
            { key: 'defendants', label: 'Defendants (one per line)', type: 'list' },
            { key: 'facilities_mentioned', label: 'Facilities mentioned (one per line)', type: 'list' },
            { key: 'staff_mentioned', label: 'Staff/owners mentioned (one per line)', type: 'list' },
            { key: 'organizations_mentioned', label: 'Organizations mentioned (one per line)', type: 'list' },
            { key: 'claims', label: 'Claims (one per line)', type: 'list' },
            { key: 'source_urls', label: 'Source URLs (one per line)', type: 'list' },
            { key: 'document_urls', label: 'Document URLs (one per line)', type: 'list' },
            { key: 'tags', label: 'Tags (one per line)', type: 'list' },
            { key: 'filebird_folder_id', label: 'FileBird folder (case documents)', type: 'folder' },
            { key: 'reviewer_notes', label: 'Reviewer notes (internal)', type: 'textarea' },
        ],
        news: [
            { key: 'article_title', label: 'Article title', type: 'text' },
            { key: 'alternate_title', label: 'Alternate (trauma-sensitive) title', type: 'text' },
            { key: 'author', label: 'Author', type: 'text' },
            { key: 'publication_name', label: 'Publication', type: 'text' },
            { key: 'publication_date', label: 'Publication date', type: 'date' },
            { key: 'article_url', label: 'Article URL (the real link)', type: 'url' },
            { key: 'archive_url', label: 'Archived copy (Wayback Machine or archive.today)', type: 'url' },
            { key: 'article_type', label: 'Article type', type: 'select', options: ['general','lawsuit','event','expose','arrest','closure','corporate'] },
            { key: 'article_location', label: 'Location (City, State or Country)', type: 'text' },
            { key: 'summary', label: 'Summary (2-3 factual, trauma-sensitive sentences)', type: 'textarea' },
            { key: 'tags', label: 'Tags (one per line)', type: 'list' },
            { key: 'promoKind', label: 'Industry PR kind (for articles filed as PR)', type: 'select', options: ['', 'fundraiser', 'anniversary', 'marketing', 'expansion', 'award', 'hiring', 'community', 'other'] },
            { key: 'organizationLogoName', label: 'Featured company / organization', type: 'text' },
            { key: 'organizationLogoUrl', label: 'Company logo image URL (HTTPS)', type: 'url' },
            { key: 'facilities_mentioned', label: 'Facilities mentioned (one per line)', type: 'list' },
            { key: 'staff_mentioned', label: 'Staff/owners mentioned (one per line)', type: 'list' },
            { key: 'survivors_mentioned', label: 'Survivors mentioned (one per line)', type: 'list' },
            { key: 'content_warnings', label: 'Content warnings', type: 'checks' },
            // The News Processor's "Article type and details" (kept in json_data); each shows for its type.
            { key: 'plaintiffs', label: 'Plaintiffs', type: 'text', showFor: 'lawsuit' },
            { key: 'defendants', label: 'Defendants', type: 'text', showFor: 'lawsuit' },
            { key: 'legalRep', label: 'Legal representation', type: 'text', showFor: 'lawsuit' },
            { key: 'dateFiled', label: 'Date filed', type: 'date', showFor: 'lawsuit' },
            { key: 'jurisdiction', label: 'Jurisdiction', type: 'text', showFor: 'lawsuit' },
            { key: 'pressReleases', label: 'Press releases (one URL per line)', type: 'textarea', showFor: 'lawsuit' },
            { key: 'relatedCoverage', label: 'Related coverage (one URL per line)', type: 'textarea', showFor: 'event' },
            { key: 'staffMemberName', label: 'Staff member name', type: 'text', showFor: 'arrest' },
            { key: 'arrestFacilityName', label: 'Facility name', type: 'text', showFor: 'arrest' },
            { key: 'misconductDates', label: 'Date(s) of alleged misconduct', type: 'text', showFor: 'arrest' },
            { key: 'charges', label: 'Charges', type: 'textarea', showFor: 'arrest' },
            { key: 'caseStatus', label: 'Case status (e.g. awaiting trial, convicted)', type: 'text', showFor: 'arrest' },
            { key: 'closureFacilityName', label: 'Facility name', type: 'text', showFor: 'closure' },
            { key: 'closureLocation', label: 'Location (City, State)', type: 'text', showFor: 'closure' },
            { key: 'closureDate', label: 'Date of closure', type: 'date', showFor: 'closure' },
            { key: 'closureContext', label: 'Context / reason', type: 'textarea', showFor: 'closure' },
            { key: 'corporateFacilityNames', label: 'Facility name(s), old and new', type: 'textarea', showFor: 'corporate' },
            { key: 'corporateLocation', label: 'Location (City, State)', type: 'text', showFor: 'corporate' },
            { key: 'keyPersonnel', label: 'Key personnel', type: 'textarea', showFor: 'corporate' },
            { key: 'ownership', label: 'Ownership / funding', type: 'textarea', showFor: 'corporate' },
            { key: 'reviewer_notes', label: 'Reviewer notes (internal)', type: 'textarea' },
        ],
    };

    const US_STATES = Array.isArray(TOOLS.states) ? TOOLS.states : [];
    const CONTENT_WARNINGS = Array.isArray(TOOLS.contentWarnings) ? TOOLS.contentWarnings : [];
    // Keys of the AI / auto-fill results that hold lists.
    const LIST_KEYS = new Set(['plaintiffs', 'defendants', 'facilities_mentioned', 'staff_mentioned', 'organizations_mentioned',
        'claims', 'source_urls', 'document_urls', 'tags', 'sponsors', 'subject_tags', 'facilities_affected',
        'survivors_mentioned', 'content_warnings']);

    // Types whose real column values are nested in decoded json_data (the GET
    // 'get' response remaps top-level status/submitted_by for these).
    const RECORD_TYPES = new Set(['legislation', 'lawsuit']);

    /** Coerce a stored list value (array, JSON string, or text) to an array of strings. */
    function coerceList(raw) {
        // Entries may be objects — facilities_mentioned stores {name, facility_id}
        // since the news-linking migration — so render the name, not the object.
        const toText = x => {
            if (x == null) return '';
            if (typeof x === 'object') return String(x.name || '').trim();
            return String(x).trim();
        };
        if (Array.isArray(raw)) return raw.map(toText).filter(Boolean);
        if (raw == null || raw === '') return [];
        if (typeof raw === 'string') {
            const s = raw.trim();
            if (s.startsWith('[')) {
                try {
                    const a = JSON.parse(s);
                    if (Array.isArray(a)) return a.map(toText).filter(Boolean);
                } catch (e) { /* fall through */ }
            }
            return s.split(/[\r\n]+/).map(x => x.trim()).filter(Boolean);
        }
        return [];
    }

    /** Where to read a field's current value from, per type. */
    function editorValueSource(type, submission, jsonData) {
        if (RECORD_TYPES.has(type)) return jsonData || submission.json_data || {};
        if (type === 'news') return Object.assign({}, jsonData || {}, submission);
        return submission;
    }

    /**
     * Render the structured editor for the current submission. Shows a labeled
     * field form for legislation/lawsuit/news, a raw-JSON editor for data, and
     * hides itself for wiki (which uses the markdown editor).
     */
    function renderStructuredEditor(type, submission, jsonData) {
        if (!structuredEditorSection || !structuredEditorBody) return;
        setEditorStatus('');

        if (type === 'data') {
            structuredEditorSection.style.display = 'block';
            structuredEditorBody.innerHTML = '';
            const wrap = document.createElement('div');
            wrap.className = 'kop-edit-field kop-edit-full';
            const label = document.createElement('label');
            label.setAttribute('for', 'dataJsonEditor');
            label.textContent = 'Edited facility data (JSON)';
            const ta = document.createElement('textarea');
            ta.id = 'dataJsonEditor';
            ta.className = 'kop-edit-input kop-edit-json';
            ta.rows = 20;
            ta.spellcheck = false;
            ta.value = JSON.stringify(jsonData || {}, null, 2);
            const hint = document.createElement('p');
            hint.className = 'kop-edit-hint';
            hint.textContent = 'This submission is a full facility record. Edit the JSON directly — it must stay valid JSON.';
            if (config.dataFormUrl && submission && submission.id) {
                // The full data form is easier than raw JSON: it opens this
                // submission on its own tab and saves back to it.
                const formLink = document.createElement('a');
                const url = new URL(config.dataFormUrl, window.location.href);
                url.searchParams.set('submission', submission.id);
                formLink.href = url.toString();
                formLink.className = 'btn-view';
                formLink.textContent = 'Edit in data form';
                formLink.style.display = 'inline-block';
                formLink.style.marginBottom = '10px';
                wrap.appendChild(formLink);
            }
            wrap.appendChild(label);
            wrap.appendChild(ta);
            wrap.appendChild(hint);
            structuredEditorBody.appendChild(wrap);
            return;
        }

        const fields = FIELD_CONFIGS[type];
        if (!fields) {
            structuredEditorSection.style.display = 'none';
            structuredEditorBody.innerHTML = '';
            return;
        }

        structuredEditorSection.style.display = 'block';
        structuredEditorBody.innerHTML = '';
        const src = editorValueSource(type, submission, jsonData);
        const grid = document.createElement('div');
        grid.className = 'kop-edit-grid';

        fields.forEach(f => {
            const wrap = document.createElement('div');
            wrap.className = 'kop-edit-field' + (f.type === 'textarea' || f.type === 'list' ? ' kop-edit-full' : '');
            const id = `edit-${type}-${f.key}`;

            const label = document.createElement('label');
            label.setAttribute('for', id);
            label.textContent = f.label;
            wrap.appendChild(label);

            let input;
            if (f.type === 'select') {
                input = document.createElement('select');
                (f.options || []).forEach(opt => {
                    const o = document.createElement('option');
                    o.value = opt;
                    o.textContent = opt || '(not set)';
                    input.appendChild(o);
                });
                input.value = (src[f.key] != null && src[f.key] !== '') ? String(src[f.key]) : (f.options[0] || '');
            } else if (f.type === 'textarea') {
                input = document.createElement('textarea');
                input.rows = 3;
                input.value = src[f.key] != null ? String(src[f.key]) : '';
            } else if (f.type === 'list') {
                input = document.createElement('textarea');
                input.rows = 3;
                input.value = coerceList(src[f.key]).join('\n');
            } else if (f.type === 'jurisdiction') {
                // Federal or a state, as on the old admin pages; an unusual stored value stays choosable.
                input = document.createElement('select');
                const cur = src[f.key] != null ? String(src[f.key]) : '';
                ['', 'Federal'].concat(US_STATES).concat(cur && cur !== 'Federal' && !US_STATES.includes(cur) ? [cur] : []).forEach(opt => {
                    const o = document.createElement('option');
                    o.value = opt;
                    o.textContent = opt || '(not set)';
                    input.appendChild(o);
                });
                input.value = cur;
            } else if (f.type === 'folder') {
                input = document.createElement('select');
                const cur = src[f.key] != null && src[f.key] !== '' ? String(src[f.key]) : '';
                input.innerHTML = '<option value="">No folder</option>'
                    + (cur ? `<option value="${escapeHtml(cur)}">Folder #${escapeHtml(cur)}</option>` : '');
                input.value = cur;
                input.dataset.want = cur;
                fillFolderSelect(input);
                if (TOOLS.mediaAdmin) {
                    const a = document.createElement('a');
                    a.href = TOOLS.mediaAdmin;
                    a.target = '_blank';
                    a.rel = 'noopener';
                    a.className = 'kop-edit-hint';
                    a.textContent = 'Manage folders or upload files (opens the media library)';
                    input.kopAfter = a;
                }
            } else if (f.type === 'checks') {
                // The News Processor's content warning boxes; a warning not on the list stays ticked.
                input = document.createElement('div');
                input.className = 'kop-edit-checks';
                const have = coerceList(src[f.key]);
                CONTENT_WARNINGS.concat(have.filter(w => !CONTENT_WARNINGS.includes(w))).forEach(w => {
                    const l = document.createElement('label');
                    const cb = document.createElement('input');
                    cb.type = 'checkbox';
                    cb.value = w;
                    cb.checked = have.includes(w);
                    l.appendChild(cb);
                    l.appendChild(document.createTextNode(' ' + w));
                    input.appendChild(l);
                });
            } else if (f.type === 'suggest') {
                // Typed freely; choices come from the jurisdiction (api/list-jurisdiction-meta.php).
                input = document.createElement('input');
                input.type = 'text';
                input.value = src[f.key] != null ? String(src[f.key]) : '';
                input.setAttribute('list', `dl-${type}-${f.key}`);
                const dl = document.createElement('datalist');
                dl.id = `dl-${type}-${f.key}`;
                wrap.appendChild(dl);
            } else {
                input = document.createElement('input');
                input.type = (f.type === 'date') ? 'date' : (f.type === 'url' ? 'url' : 'text');
                let v = src[f.key];
                if (f.type === 'date' && typeof v === 'string') v = v.slice(0, 10); // YYYY-MM-DD
                input.value = (v != null) ? String(v) : '';
            }
            input.id = id;
            input.className = (input.className ? input.className + ' ' : '') + 'kop-edit-input';
            input.dataset.fieldKey = f.key;
            input.dataset.fieldType = f.type;
            wrap.appendChild(input);
            if (input.kopAfter) wrap.appendChild(input.kopAfter);
            if (f.type === 'checks') wrap.classList.add('kop-edit-full');
            if (f.showFor) {
                wrap.dataset.showFor = f.showFor;
                wrap.classList.add('kop-edit-detail');
            }
            grid.appendChild(wrap);
        });

        structuredEditorBody.appendChild(grid);
        // The old pages' auto-fill and AI buttons, above the fields.
        const tools = buildEditorTools(type, submission);
        if (tools) structuredEditorBody.insertBefore(tools, grid);
        wireEditorFields(type);
    }

    // =========================================================================
    // Tools from the old Lawsuit, Legislation and News Processor pages
    // =========================================================================

    /** The editor's input for a field key. */
    function editorField(key) {
        return structuredEditorBody ? structuredEditorBody.querySelector(`[data-field-key="${key}"]`) : null;
    }

    /** A field's current value in the editor (lists as arrays). */
    function editorValue(key) {
        const el = editorField(key);
        if (!el) return '';
        if (el.dataset.fieldType === 'list') return el.value.split(/[\r\n]+/).map(s => s.trim()).filter(Boolean);
        if (el.dataset.fieldType === 'checks') return Array.from(el.querySelectorAll('input:checked')).map(cb => cb.value);
        return el.value;
    }

    /**
     * Put auto-fill / AI results into the editor. Only keys the editor has and
     * that came back with something are changed; nothing is saved until Save
     * Edits. document_urls is added to, never replaced. Returns what changed.
     */
    function fillEditor(values) {
        const changed = [];
        Object.entries(values || {}).forEach(([key, raw]) => {
            const el = editorField(key);
            if (!el || raw == null) return;
            let list = Array.isArray(raw) ? raw.map(x => (x && typeof x === 'object') ? (x.name || '') : String(x)).map(s => s.trim()).filter(Boolean) : null;
            const kind = el.dataset.fieldType;
            if (kind === 'list') {
                if (!list) list = String(raw).split(/\r?\n/).map(s => s.trim()).filter(Boolean);
                if (!list.length) return;
                if (key === 'document_urls') list = Array.from(new Set(editorValue(key).concat(list)));
                el.value = list.join('\n');
            } else if (kind === 'checks') {
                if (!list) list = String(raw).split(/\n|,\s*/).map(s => s.trim()).filter(Boolean);
                if (!list.length) return;
                list.forEach(w => {
                    let cb = Array.from(el.querySelectorAll('input')).find(c => c.value.toLowerCase() === w.toLowerCase());
                    if (!cb) {
                        const l = document.createElement('label');
                        cb = document.createElement('input');
                        cb.type = 'checkbox';
                        cb.value = w;
                        l.appendChild(cb);
                        l.appendChild(document.createTextNode(' ' + w));
                        el.appendChild(l);
                    }
                    cb.checked = true;
                });
            } else {
                let v = list ? list.join(', ') : String(raw).trim();
                if (v === '') return;
                if (el.type === 'date') v = v.slice(0, 10);
                if (el.tagName === 'SELECT') {
                    if (!Array.from(el.options).some(o => o.value === v)) {
                        if (kind !== 'jurisdiction') return; // not one of the choices
                        const o = document.createElement('option');
                        o.value = v;
                        o.textContent = v;
                        el.appendChild(o);
                    }
                }
                if (el.value === v) return;
                el.value = v;
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
            changed.push(key);
        });
        return changed;
    }

    let folderCache = null;
    /** FileBird folders for the folder pickers, indented under their parents (as the old pages listed them). */
    async function loadFolders() {
        if (folderCache) return folderCache;
        if (!TOOLS.folders) return [];
        try {
            const res = await fetch(TOOLS.folders, { credentials: 'same-origin' });
            const data = await res.json();
            const folders = Array.isArray(data) ? data : [];
            const byId = new Map(folders.map(f => [String(f.id), Object.assign({}, f, { children: [] })]));
            const roots = [];
            byId.forEach(f => {
                const parent = String(f.parent || 0);
                if (parent === '0' || !byId.has(parent)) roots.push(f); else byId.get(parent).children.push(f);
            });
            const flat = [];
            const walk = (nodes, depth) => nodes.slice()
                .sort((a, b) => String(a.name).localeCompare(String(b.name)))
                .forEach(n => { flat.push({ id: String(n.id), name: n.name, depth }); walk(n.children, depth + 1); });
            walk(roots, 0);
            folderCache = flat;
        } catch (e) {
            console.warn('Could not load FileBird folders', e);
            folderCache = [];
        }
        return folderCache;
    }

    async function fillFolderSelect(select) {
        const folders = await loadFolders();
        if (!folders.length || !select.isConnected) return;
        const want = select.value || select.dataset.want || '';
        select.innerHTML = '<option value="">No folder</option>' + folders.map(f =>
            `<option value="${escapeHtml(f.id)}">${'— '.repeat(f.depth)}${escapeHtml(f.name)}</option>`).join('');
        if (want && !folders.some(f => f.id === want)) {
            select.insertAdjacentHTML('beforeend', `<option value="${escapeHtml(want)}">Folder #${escapeHtml(want)}</option>`);
        }
        select.value = want;
    }

    /** Bill types and sessions for a jurisdiction (the old Legislation page's dropdowns). */
    async function loadBillMeta(type) {
        if (type !== 'legislation' || !TOOLS.billMeta) return;
        const jurisdiction = editorValue('jurisdiction');
        const levelSel = structuredEditorBody.querySelector('.kop-tool-level');
        const level = jurisdiction === 'Federal' ? 'federal' : (levelSel ? levelSel.value : 'state');
        const fill = (key, items) => {
            const dl = document.getElementById(`dl-${type}-${key}`);
            if (!dl) return;
            dl.innerHTML = items.map(i => `<option value="${escapeHtml(i.value)}">${escapeHtml(i.label)}</option>`).join('');
        };
        if (!jurisdiction) { fill('bill_type', []); fill('session_year', []); return; }
        try {
            const params = new URLSearchParams({ jurisdiction, level });
            const res = await fetch(`${TOOLS.billMeta}?${params}`, { credentials: 'same-origin' });
            const data = await res.json();
            fill('bill_type', (data.bill_types || []).map(b => ({ value: b, label: b })));
            fill('session_year', (data.sessions || []).map(s => ({ value: s.identifier, label: s.name || s.identifier })));
        } catch (e) {
            console.warn('Bill choices failed', e);
        }
    }

    let savedValuesCache = null;
    /** The News Processor's saved authors and publications, offered as you type. */
    async function attachSavedValues() {
        if (!TOOLS.savedValues) return;
        try {
            if (!savedValuesCache) {
                const res = await fetch(`${TOOLS.savedValues}?form=news`, { credentials: 'same-origin' });
                const data = await res.json();
                savedValuesCache = (data && data.data && data.data.news) || {};
            }
        } catch (e) {
            savedValuesCache = {};
        }
        [['author', 'authors'], ['publication_name', 'publications']].forEach(([key, cat]) => {
            const el = editorField(key);
            const vals = (savedValuesCache[cat] || []).map(v => v.value).filter(Boolean);
            if (!el || !vals.length) return;
            const dl = document.createElement('datalist');
            dl.id = `dl-news-${key}`;
            dl.innerHTML = vals.map(v => `<option value="${escapeHtml(v)}"></option>`).join('');
            el.insertAdjacentElement('afterend', dl);
            el.setAttribute('list', dl.id);
        });
    }

    /** Field behaviour after the editor is drawn: detail fields per article type, bill choices. */
    function wireEditorFields(type) {
        if (type === 'news') {
            const typeSel = editorField('article_type');
            const sync = () => {
                const t = typeSel ? typeSel.value : '';
                structuredEditorBody.querySelectorAll('.kop-edit-detail').forEach(w => { w.hidden = w.dataset.showFor !== t; });
            };
            if (typeSel) typeSel.addEventListener('change', sync);
            sync();
            attachSavedValues();
        }
        if (type === 'legislation') {
            const j = editorField('jurisdiction');
            if (j) j.addEventListener('change', () => loadBillMeta(type));
            loadBillMeta(type);
        }
    }

    /**
     * Records already holding these addresses (api/check-duplicate-url.php, the
     * check the old pages ran), leaving out this one. Never blocks on an error.
     */
    async function findDuplicates(type, body) {
        if (!TOOLS.checkDuplicate || !['news', 'lawsuit', 'legislation'].includes(type)) return [];
        try {
            const res = await fetch(TOOLS.checkDuplicate, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(Object.assign({ type }, body))
            });
            const data = await res.json();
            return (data && data.success && data.duplicates) || [];
        } catch (e) {
            console.warn('Duplicate check failed', e);
            return [];
        }
    }

    /** The address fields the duplicate check reads, per type, from the editor. */
    function duplicateQuery(type, excludeId) {
        if (type === 'lawsuit') return { fields: { source_urls: editorValue('source_urls'), document_urls: editorValue('document_urls') }, exclude_id: excludeId || 0 };
        if (type === 'legislation') return { fields: { full_text_url: editorValue('full_text_url') }, exclude_id: excludeId || 0 };
        if (type === 'news') return { url: editorValue('article_url'), title: editorValue('article_title'), outlet: editorValue('publication_name'), exclude_id: excludeId || 0 };
        return null;
    }

    /** Ask before going on when another record has the same address. True = go on. */
    async function okDespiteDuplicates(type, query, doing) {
        if (!query) return true;
        if (query.fields) {
            Object.keys(query.fields).forEach(k => {
                const v = query.fields[k];
                if (Array.isArray(v) ? !v.length : !String(v || '').trim()) delete query.fields[k];
            });
            if (!Object.keys(query.fields).length) return true;
        } else if (!String(query.url || '').trim() && !String(query.title || '').trim()) {
            return true;
        }
        const dupes = await findDuplicates(type, query);
        if (!dupes.length) return true;
        const list = dupes.slice(0, 3).map(d => `- ${d.title || '(untitled)'} (#${d.id}, ${d.status || 'unknown status'})`).join('\n');
        return confirm(`This already seems to be in our records:\n${list}\n\n${doing} anyway?`);
    }

    /** A row of tool buttons for the record type, or null. */
    function buildEditorTools(type, submission) {
        if (!['lawsuit', 'legislation', 'news'].includes(type)) return null;
        const box = document.createElement('div');
        box.className = 'kop-edit-tools';
        const status = document.createElement('p');
        status.className = 'kop-edit-tools-status';
        status.setAttribute('aria-live', 'polite');
        const say = (msg, isError) => {
            status.textContent = msg || '';
            status.className = 'kop-edit-tools-status' + (msg ? (isError ? ' error' : ' success') : '');
        };
        const button = (label, icon) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn-secondary kop-tool-btn';
            b.innerHTML = (icon ? kopIcon(icon) + ' ' : '') + escapeHtml(label);
            return b;
        };
        const busy = async (b, text, fn) => {
            const html = b.innerHTML;
            b.disabled = true;
            b.textContent = text;
            try { await fn(); } finally { b.disabled = false; b.innerHTML = html; }
        };
        const filled = (changed, what) => say(changed.length
            ? `${what} Filled: ${changed.map(k => k.replace(/_/g, ' ')).join(', ')}. Check them, then Save Edits.`
            : `${what} Nothing new came back; the fields are unchanged.`);
        const id = submission && submission.id;
        const row = document.createElement('div');
        row.className = 'kop-edit-tools-row';
        box.appendChild(row);

        if (type === 'lawsuit') {
            const cl = button('Auto-fill from CourtListener (federal cases)', 'sparkles');
            cl.addEventListener('click', () => busy(cl, 'Fetching...', async () => {
                const caseNumber = editorValue('case_number').trim();
                const jurisdiction = editorValue('jurisdiction');
                if (!caseNumber || !jurisdiction) { say('Fill in the case number and jurisdiction first.', true); return; }
                if (!await okDespiteDuplicates(type, duplicateQuery(type, id), 'Fetch')) return;
                say('Fetching the case from CourtListener...');
                try {
                    const params = new URLSearchParams({ case_number: caseNumber, jurisdiction, court: editorValue('court') });
                    const res = await fetch(`${TOOLS.fetchLawsuit}?${params}`, { credentials: 'same-origin' });
                    const data = await res.json();
                    if (data.success) filled(fillEditor(data.data), 'Fetched.');
                    else say('Fetch failed: ' + (data.error || 'check the case number and jurisdiction.'), true);
                } catch (e) {
                    say('Could not reach the CourtListener lookup.', true);
                }
            }));

            const file = document.createElement('input');
            file.type = 'file';
            file.accept = '.pdf,.doc,.docx,.txt,application/pdf';
            file.hidden = true;
            const up = button('Upload the complaint and read it with AI', 'file-text');
            up.title = 'PDF, DOCX or TXT up to 20 MB. The file is saved to the media library.';
            up.addEventListener('click', () => { if (!up.disabled) { file.value = ''; file.click(); } });
            file.addEventListener('change', () => {
                const f = file.files && file.files[0];
                if (!f) return;
                busy(up, 'Uploading...', () => readComplaint(f, up, say, filled));
            });

            const paste = button('Paste JSON from another AI', 'clipboard');
            const panel = document.createElement('div');
            panel.className = 'kop-edit-paste';
            panel.hidden = true;
            panel.innerHTML = `<textarea rows="8" class="kop-edit-input" aria-label="JSON to paste" spellcheck="false"></textarea>
                <div class="kop-edit-paste-actions"><button type="button" class="btn-save-edits kop-paste-apply">Fill the fields</button>
                <button type="button" class="btn-secondary kop-paste-cancel">Cancel</button></div>`;
            const ta = panel.querySelector('textarea');
            const empty = JSON.stringify({ case_name: '', case_number: '', court: '', jurisdiction: '', filing_date: '', status: '',
                outcome: '', settlement_amount: '', summary: '', plaintiffs: [], defendants: [], facilities_mentioned: [],
                staff_mentioned: [], organizations_mentioned: [], claims: [], source_urls: [], document_urls: [], tags: [] }, null, 2);
            paste.addEventListener('click', () => {
                panel.hidden = !panel.hidden;
                if (!panel.hidden) { if (!ta.value.trim()) ta.value = empty; ta.focus(); ta.select(); }
            });
            panel.querySelector('.kop-paste-cancel').addEventListener('click', () => { panel.hidden = true; ta.value = ''; say(''); });
            panel.querySelector('.kop-paste-apply').addEventListener('click', () => {
                let parsed;
                try {
                    parsed = JSON.parse(ta.value.trim().replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, ''));
                } catch (e) {
                    say('That is not valid JSON: ' + e.message, true);
                    return;
                }
                Object.keys(parsed || {}).forEach(k => {
                    if (LIST_KEYS.has(k) && typeof parsed[k] === 'string') parsed[k] = parsed[k].split(/\n|,\s*/).map(s => s.trim()).filter(Boolean);
                });
                filled(fillEditor(parsed), 'Pasted.');
                panel.hidden = true;
                ta.value = '';
            });
            row.append(cl, up, file, paste);
            box.appendChild(panel);
        }

        if (type === 'legislation') {
            const lvl = document.createElement('label');
            lvl.className = 'kop-tool-label';
            lvl.innerHTML = 'Level <select class="kop-tool-level"><option value="state">State</option><option value="federal">Federal</option></select>';
            const lvlSel = lvl.querySelector('select');
            const rec = (submission && submission.json_data) || {};
            lvlSel.value = rec.jurisdiction === 'Federal' ? 'federal' : 'state';
            lvlSel.addEventListener('change', () => loadBillMeta(type));
            const fb = button('Auto-fill from government sources', 'sparkles');
            fb.addEventListener('click', () => busy(fb, 'Fetching...', async () => {
                const raw = editorValue('bill_number').trim();
                const billType = editorValue('bill_type').trim();
                const jurisdiction = editorValue('jurisdiction');
                const level = jurisdiction === 'Federal' ? 'federal' : lvlSel.value;
                if (!raw || !jurisdiction) { say('Fill in the bill number and jurisdiction first.', true); return; }
                if (!await okDespiteDuplicates(type, duplicateQuery(type, id), 'Fetch')) return;
                // Type "SB" + number "1190" -> "SB 1190"; a number already starting with letters stays.
                const number = (billType && !/^[a-zA-Z]/.test(raw)) ? `${billType} ${raw}` : raw;
                say('Fetching the bill...');
                try {
                    const params = new URLSearchParams({ bill_number: number, jurisdiction, level });
                    const session = editorValue('session_year');
                    if (session) params.set('session', session);
                    const res = await fetch(`${TOOLS.fetchBill}?${params}`, { credentials: 'same-origin' });
                    const data = await res.json();
                    if (data.success) {
                        const d = Object.assign({}, data.data);
                        if (d.position === 'unknown') delete d.position; // never overwrite our own position
                        filled(fillEditor(d), 'Fetched.');
                        loadBillMeta(type);
                    } else {
                        say('Fetch failed: ' + (data.error || 'check the bill number and jurisdiction.'), true);
                    }
                } catch (e) {
                    say('Could not reach the bill lookup.', true);
                }
            }));
            row.append(lvl, fb);
        }

        if (type === 'news') {
            const ai = document.createElement('div');
            ai.className = 'kop-edit-ai';
            ai.innerHTML = `<p class="kop-edit-hint">Read the article with AI, as the News Processor did: it fills title, author, date, outlet,
                    location, tags, names, summary, alternate title, content warnings, type and details. Check the fields, then Save Edits.</p>
                <label>Article address <input type="url" class="kop-edit-input kop-ai-url"></label>
                <label>Or paste the article text (when the address does not work)
                    <textarea rows="3" class="kop-edit-input kop-ai-text"></textarea></label>
                <label>Extra instructions for the AI (optional)
                    <textarea rows="2" class="kop-edit-input kop-ai-instr" placeholder="e.g. Focus on the financial connections"></textarea></label>`;
            const urlIn = ai.querySelector('.kop-ai-url');
            const textIn = ai.querySelector('.kop-ai-text');
            const instrIn = ai.querySelector('.kop-ai-instr');
            urlIn.value = (submission && submission.article_url) || '';
            try { instrIn.value = localStorage.getItem('news_ai_custom_instructions') || ''; } catch (e) { /* no storage */ }
            const go = button('Read with AI', 'bot');
            go.addEventListener('click', () => busy(go, 'Reading...', async () => {
                const url = urlIn.value.trim();
                const text = textIn.value.trim();
                const instr = instrIn.value.trim();
                if (!url && !text) { say('Give the article address or paste its text.', true); return; }
                if (url && !await okDespiteDuplicates(type, { url, exclude_id: id || 0 }, 'Read it')) return;
                try { if (instr) localStorage.setItem('news_ai_custom_instructions', instr); } catch (e) { /* no storage */ }
                say('Reading the article with AI (Groq and Gemini take turns)...');
                try {
                    const res = await fetch(TOOLS.newsAi, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ url, articleText: text, provider: 'auto', customInstructions: instr })
                    });
                    const result = await res.json();
                    if (!result.success) { say('The AI could not read it: ' + (result.error || 'unknown error'), true); return; }
                    filled(fillEditor(newsAiToFields(result.data || {}, url)), 'Read.');
                } catch (e) {
                    say('Could not reach the AI reader: ' + e.message, true);
                }
            }));
            ai.appendChild(go);
            box.appendChild(ai);
        }

        if (type === 'news' || type === 'lawsuit') {
            // Pick facilities by name, as the News Processor's facility picker did.
            const key = 'facilities_mentioned';
            const pick = document.createElement('div');
            pick.className = 'kop-edit-pick';
            pick.innerHTML = '<span>Add a facility by name:</span> <input type="number" class="kop-edit-pick-box" data-kop-facility-finder="multi" hidden aria-label="Facility">';
            const boxIn = pick.querySelector('input');
            boxIn.addEventListener('kop-facility-picked', (e) => {
                const f = e.detail || {};
                if (!f.name) return;
                const el = editorField(key);
                if (!el) return;
                const have = editorValue(key);
                if (!have.some(n => n.toLowerCase() === f.name.toLowerCase())) el.value = have.concat([f.name]).join('\n');
                say(`Added ${f.name} to the facilities mentioned. Save Edits to link it.`);
            });
            box.appendChild(pick);
            setTimeout(() => { if (window.kopFacilityFinderAttach) window.kopFacilityFinderAttach(boxIn); }, 0);
        }

        box.appendChild(status);
        return box;
    }

    /** The News Processor's AI answer, mapped onto this editor's fields. */
    function newsAiToFields(d, url) {
        const out = {};
        const map = { title: 'article_title', author: 'author', publicationDate: 'publication_date', publicationName: 'publication_name',
            location: 'article_location', tags: 'tags', facilities: 'facilities_mentioned', staff: 'staff_mentioned',
            survivors: 'survivors_mentioned', summary: 'summary', alternateTitle: 'alternate_title',
            contentWarnings: 'content_warnings', articleType: 'article_type' };
        Object.entries(map).forEach(([from, to]) => { if (d[from] != null && d[from] !== '') out[to] = d[from]; });
        if (url) out.article_url = url;
        // typeSpecificData is flat ({plaintiffs: ...}) or grouped by type ({"for lawsuit": {...}}).
        const spec = d.typeSpecificData && typeof d.typeSpecificData === 'object' ? d.typeSpecificData : {};
        Object.entries(spec).forEach(([k, v]) => {
            if (v && typeof v === 'object' && !Array.isArray(v)) Object.assign(out, v);
            else out[k] = v;
        });
        if (out.article_type) out.article_type = String(out.article_type).toLowerCase();
        return out;
    }

    /** Upload a complaint, read it chunk by chunk, merge (api/extract-lawsuit-from-document.php). */
    async function readComplaint(file, btn, say, filled) {
        if (!TOOLS.extractLawsuit) { say('The complaint reader is not available.', true); return; }
        const post = body => fetch(TOOLS.extractLawsuit, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body), signal: AbortSignal.timeout(90 * 1000)
        }).then(r => r.json());
        try {
            say(`Uploading "${file.name}"...`);
            const form = new FormData();
            form.append('action', 'upload');
            form.append('complaint', file);
            const folder = editorValue('filebird_folder_id');
            if (folder) form.append('filebird_folder_id', folder);
            const upload = await fetch(TOOLS.extractLawsuit, { method: 'POST', credentials: 'same-origin', body: form, signal: AbortSignal.timeout(90 * 1000) }).then(r => r.json());
            if (!upload.success) throw new Error(upload.error || 'Upload failed');
            const wait = upload.wait_ms || 65000;
            for (let i = 0; i < upload.total_chunks; i++) {
                if (i > 0) {
                    btn.textContent = `Waiting (${i}/${upload.total_chunks})...`;
                    say(`Part ${i} of ${upload.total_chunks} read. Waiting ${Math.round(wait / 1000)} seconds before the next (free AI limits)...`);
                    await new Promise(r => setTimeout(r, wait));
                }
                btn.textContent = `Part ${i + 1}/${upload.total_chunks}...`;
                say(`Reading part ${i + 1} of ${upload.total_chunks}...`);
                const chunk = await post({ action: 'chunk', job_id: upload.job_id, chunk_index: i });
                if (!chunk.success) throw new Error(chunk.error || `Part ${i + 1} failed`);
            }
            btn.textContent = 'Finishing...';
            const done = await post({ action: 'finalize', job_id: upload.job_id });
            if (!done.success) throw new Error(done.error || 'Finishing failed');
            filled(fillEditor(done.data || {}), 'Read the complaint.');
        } catch (e) {
            console.error('Complaint read failed', e);
            say('Reading the complaint failed: ' + e.message, true);
        }
    }

    /** Gather edited values into a {col: value} map for update_fields. */
    function collectStructuredFields(type) {
        if (type === 'data') {
            const ta = document.getElementById('dataJsonEditor');
            if (!ta) return null;
            let parsed;
            try {
                parsed = JSON.parse(ta.value);
            } catch (e) {
                return { __error: 'The JSON is invalid: ' + e.message };
            }
            return { edited_json_data: parsed };
        }
        if (!structuredEditorBody) return null;
        const fields = {};
        structuredEditorBody.querySelectorAll('[data-field-key]').forEach(el => {
            const key = el.dataset.fieldKey;
            if (el.dataset.fieldType === 'list' || el.dataset.fieldType === 'checks') {
                fields[key] = editorValue(key);
            } else {
                fields[key] = el.value;
            }
        });
        return fields;
    }

    function setEditorStatus(msg, isError) {
        if (!structuredEditorStatus) return;
        structuredEditorStatus.textContent = msg || '';
        structuredEditorStatus.className = 'structured-editor-status' + (msg ? (isError ? ' error' : ' success') : '');
    }

    /** Persist structured-editor changes via the update_fields action. */
    async function saveStructuredFields() {
        if (!currentSubmission) return;
        const type = typeFilter ? typeFilter.value : 'wiki';
        const fields = collectStructuredFields(type);
        if (!fields) return;
        if (fields.__error) { setEditorStatus(fields.__error, true); return; }

        const id = currentSubmission.id;
        // The duplicate check the old Lawsuit, Legislation and News Processor pages ran before saving.
        if (!await okDespiteDuplicates(type, duplicateQuery(type, id), 'Save')) return;
        saveFieldsBtn.disabled = true;
        const original = saveFieldsBtn.innerHTML;
        saveFieldsBtn.textContent = 'Saving...';
        setEditorStatus('');

        try {
            const response = await fetch(MANAGE_API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'update_fields', type, id, fields })
            });
            const result = await response.json();
            if (result.success) {
                setEditorStatus('✓ Saved' + (result.documents_filed ? `. ${result.documents_filed} case document(s) are in its FileBird folder.` : '')
                    + (result.link_warning ? ' ' + result.link_warning : ''), false);
                // Refresh the list (titles/locations on cards may have changed)
                // and re-open this submission so the editor shows stored values.
                loadSubmissions();
                viewSubmission(id);
            } else {
                setEditorStatus('✗ ' + (result.error || 'Save failed'), true);
            }
        } catch (error) {
            console.error('Save fields failed:', error);
            setEditorStatus('✗ Network error', true);
        } finally {
            saveFieldsBtn.disabled = false;
            saveFieldsBtn.innerHTML = original;
        }
    }

    /**
     * Perform action (approve, reject, publish, delete)
     */
    async function performAction(action) {
        if (!currentSubmission) return;

        const notes = reviewerNotes.value.trim();
        // Reviewer identity is the logged-in admin (REVIEWER), or whatever
        // the editable field holds; runAction picks it up.

        // Disable all buttons during action
        approveBtn.disabled = true;
        rejectBtn.disabled = true;
        publishBtn.disabled = true;
        deleteBtn.disabled = true;
        if (promoBtn) promoBtn.disabled = true;

        actionStatus.innerHTML = '<span class="loading">Processing...</span>';

        try {
            const result = await runAction(action, [currentSubmission.id], notes);

            if (result.success) {
                actionStatus.innerHTML = `<span class="success">${kopIcon('check')} ${result.message}</span>`;

                // Refresh data
                setTimeout(() => {
                    loadStats();
                    loadSubmissions();
                    // A decision closes the details; the next card is what matters now.
                    if (action === 'delete' || action === 'approve' || action === 'reject' || action === 'promo') {
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
        // Get all pending submissions from the current list. "Pending" is
        // 'submitted' in wiki/news and 'pending' in data/legislation/lawsuit.
        const pendingSubmissions = allSubmissions.filter(s => s.status === 'submitted' || s.status === 'pending');

        if (pendingSubmissions.length === 0) {
            alert('No pending submissions to reject.');
            return;
        }

        const confirmMessage = `Are you sure you want to reject all ${pendingSubmissions.length} pending submission(s)?\n\nThis will mark them all as rejected.`;
        if (!confirm(confirmMessage)) {
            return;
        }

        // Reviewer identity is the logged-in admin — no prompt needed.
        const email = REVIEWER || localStorage.getItem('adminEmail') || '';
        if (email) {
            localStorage.setItem('adminEmail', email);
        }

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
            rejectAllBtn.innerHTML = `${kopIcon('x')} Reject All Pending`;
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
        const name   = data.facility_link_label || data.facility_unique_name || '';
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

    // =========================================================================
    // Set any status, download the record, add a new one (old admin pages)
    // =========================================================================

    const setStatusSelect = document.getElementById('setStatusSelect');
    const setStatusBtn = document.getElementById('setStatusBtn');
    if (setStatusBtn && setStatusSelect) {
        setStatusBtn.addEventListener('click', async () => {
            if (!currentSubmission) return;
            const want = setStatusSelect.value;
            // Published goes through Publish, which also stamps the date and links a lawsuit's facilities.
            if (want === 'published') { performAction('publish'); return; }
            const type = typeFilter ? typeFilter.value : 'wiki';
            setStatusBtn.disabled = true;
            actionStatus.innerHTML = '<span class="loading">Processing...</span>';
            try {
                const res = await fetch(MANAGE_API, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'update_status', type, ids: [currentSubmission.id], status: want })
                });
                const result = await res.json();
                if (result.success) {
                    actionStatus.innerHTML = `<span class="success">${kopIcon('check')} ${escapeHtml(result.message)}</span>`;
                    const id = currentSubmission.id;
                    setTimeout(() => { loadStats(); loadSubmissions(); viewSubmission(id); }, 800);
                } else {
                    actionStatus.innerHTML = `<span class="error">✗ ${escapeHtml(result.error || 'Could not set the status')}</span>`;
                }
            } catch (e) {
                actionStatus.innerHTML = '<span class="error">✗ Network error</span>';
            } finally {
                setStatusBtn.disabled = false;
            }
        });
    }

    // The News Processor's "Export as JSON".
    const downloadJsonBtn = document.getElementById('downloadJsonBtn');
    if (downloadJsonBtn) {
        downloadJsonBtn.addEventListener('click', () => {
            if (!currentSubmission) return;
            const type = typeFilter ? typeFilter.value : 'wiki';
            const blob = new Blob([modalFormData.textContent || '{}'], { type: 'application/json' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `${type}-${currentSubmission.id}.json`;
            document.body.appendChild(a);
            a.click();
            setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
        });
    }

    // "+ New Lawsuit" / "+ New Bill" / the News Processor's "Submit to Database".
    // A function, not a const: syncNewsOnly() runs before this line is reached.
    function addNewWords() { return {
        news: { button: 'Add an article', name: 'Article title', url: 'Article web address (optional)',
            help: 'It opens in the Pending tab. Use Read with AI there to fill in the rest, then approve it.' },
        lawsuit: { button: 'Add a lawsuit', name: 'Case name', url: 'Source web address (optional)',
            help: 'It opens in the Pending tab, where the CourtListener, complaint and paste buttons fill in the rest.' },
        legislation: { button: 'Add a bill', name: 'Bill title', url: 'Bill text web address (optional)',
            help: 'It opens in the Pending tab, where "Auto-fill from government sources" fills in the rest.' },
    }; }

    function syncAddNew() {
        const type = (document.getElementById('typeFilter') || {}).value;
        const words = addNewWords()[type];
        const label = document.getElementById('addNewLabel');
        const panel = document.getElementById('addNewPanel');
        if (label && words) label.textContent = words.button;
        if (panel && (!words || panel.dataset.type !== type)) panel.hidden = true;
    }

    const addNewBtn = document.getElementById('addNewBtn');
    const addNewPanel = document.getElementById('addNewPanel');
    if (addNewBtn && addNewPanel) {
        const nameIn = document.getElementById('addNewName');
        const urlIn = document.getElementById('addNewUrl');
        const saveBtn = document.getElementById('addNewSaveBtn');
        const statusEl = document.getElementById('addNewStatus');
        const say = (msg, isError) => {
            statusEl.textContent = msg || '';
            statusEl.className = 'structured-editor-status' + (msg ? (isError ? ' error' : ' success') : '');
        };
        addNewBtn.addEventListener('click', () => {
            const type = typeFilter.value;
            const words = addNewWords()[type];
            if (!words) return;
            addNewPanel.dataset.type = type;
            addNewPanel.hidden = !addNewPanel.hidden;
            document.getElementById('addNewTitle').textContent = words.button;
            document.getElementById('addNewNameLabel').textContent = words.name;
            document.getElementById('addNewUrlLabel').textContent = words.url;
            document.getElementById('addNewHelp').textContent = words.help;
            say('');
            if (!addNewPanel.hidden) nameIn.focus();
        });
        document.getElementById('addNewCancelBtn').addEventListener('click', () => { addNewPanel.hidden = true; });
        saveBtn.addEventListener('click', async () => {
            const type = addNewPanel.dataset.type;
            const name = nameIn.value.trim();
            const url = urlIn.value.trim();
            if (!name) { say(`Write the ${addNewWords()[type].name.toLowerCase()} first.`, true); return; }
            if (url && !/^https?:\/\//i.test(url)) { say('The web address must start with http:// or https://', true); return; }
            let endpoint, body, dupe;
            if (type === 'lawsuit') {
                endpoint = TOOLS.saveLawsuit;
                body = { case_name: name, source_urls: url ? [url] : [], publication_status: 'pending' };
                dupe = url ? { fields: { source_urls: [url] } } : null;
            } else if (type === 'legislation') {
                endpoint = TOOLS.saveLegislation;
                body = { bill_title: name, full_text_url: url, publication_status: 'pending' };
                dupe = url ? { fields: { full_text_url: url } } : null;
            } else {
                endpoint = TOOLS.saveNews;
                body = { title: name, url, status: 'submitted', submittedBy: REVIEWER };
                dupe = { url, title: name };
            }
            if (!endpoint) { say('This page is missing its save address; reload it.', true); return; }
            if (!await okDespiteDuplicates(type, dupe, 'Add it')) return;
            saveBtn.disabled = true;
            say('Saving...');
            try {
                const res = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                const result = await res.json();
                if (!result.success || !result.id) {
                    say(result.message || result.error || 'Could not add it.', true);
                    return;
                }
                nameIn.value = '';
                urlIn.value = '';
                addNewPanel.hidden = true;
                statusFilter.value = 'submitted';
                markTabs(statusTabs, 'data-status', 'submitted');
                loadStats();
                await loadSubmissions();
                viewSubmission(result.id);
            } catch (e) {
                say('Network error: ' + e.message, true);
            } finally {
                saveBtn.disabled = false;
            }
        });
    }
});
