<?php
/**
 * Template Name: Admin - Volunteer Projects
 *
 * Admin-only entry/edit form and list view for the volunteer_projects table.
 */

if (!defined('ABSPATH')) { exit; }
if (!is_user_logged_in() || !current_user_can('edit_posts')) {
    wp_die('Access denied. Contributor privileges or higher required.');
}

get_header();
?>

<link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/css/admin-state-content.css?v=<?php echo time(); ?>">

<div class="admin-state-content-wrapper">
    <header class="admin-state-content-header">
        <h1>Volunteer Projects</h1>
        <p>Manage volunteer opportunities displayed on the public Volunteer page.</p>
    </header>

    <div class="admin-state-content-layout">
        <section class="admin-state-content-list">
            <div class="list-controls">
                <select id="filterStatus">
                    <option value="">All statuses</option>
                    <option value="open" selected>Open</option>
                    <option value="filled">Filled</option>
                    <option value="completed">Completed</option>
                    <option value="archived">Archived</option>
                </select>
                <select id="filterType">
                    <option value="">All types</option>
                    <option value="research">Research</option>
                    <option value="data_entry">Data Entry</option>
                    <option value="advocacy">Advocacy</option>
                    <option value="tech">Tech</option>
                    <option value="outreach">Outreach</option>
                    <option value="other">Other</option>
                </select>
                <select id="filterPubStatus">
                    <option value="">All pub statuses</option>
                    <option value="draft">Draft</option>
                    <option value="published" selected>Published</option>
                    <option value="archived">Archived</option>
                </select>
                <button type="button" id="reloadList">Reload</button>
                <button type="button" id="newVolunteerBtn" class="primary-btn">+ New Project</button>
            </div>
            <ul id="volunteerList" class="record-list">
                <li class="empty">Loading…</li>
            </ul>
        </section>

        <section class="admin-state-content-form">
            <form id="volunteerForm" autocomplete="off">
                <input type="hidden" name="id" id="vol-id" value="">

                <div class="form-row">
                    <label>Project title *
                        <input type="text" name="title" id="vol-title" required>
                    </label>
                </div>

                <div class="form-row two-col">
                    <label>Project type
                        <select name="project_type" id="vol-type">
                            <option value="research">Research</option>
                            <option value="data_entry">Data Entry</option>
                            <option value="advocacy">Advocacy</option>
                            <option value="tech">Tech</option>
                            <option value="outreach">Outreach</option>
                            <option value="other">Other</option>
                        </select>
                    </label>
                    <label>Status
                        <select name="status" id="vol-status">
                            <option value="open">Open</option>
                            <option value="filled">Filled</option>
                            <option value="completed">Completed</option>
                            <option value="archived">Archived</option>
                        </select>
                    </label>
                </div>

                <div class="form-row">
                    <label>Description
                        <textarea name="description" id="vol-description" rows="5" placeholder="What will the volunteer do? What's the goal?"></textarea>
                    </label>
                </div>

                <div class="form-row two-col">
                    <label>Contact email
                        <input type="email" name="contact_email" id="vol-contact-email" placeholder="volunteer@kidsoverprofits.org">
                    </label>
                    <label>Signup URL
                        <input type="url" name="signup_url" id="vol-signup-url" placeholder="https://…">
                    </label>
                </div>

                <div class="form-row">
                    <label>Skills needed (one per line)
                        <textarea name="skills_needed" id="vol-skills" rows="3" placeholder="e.g. Python&#10;Data analysis&#10;Legal research"></textarea>
                    </label>
                </div>

                <div class="form-row two-col">
                    <label>Related jurisdiction (state or "National")
                        <input type="text" name="jurisdiction" id="vol-jurisdiction" placeholder="e.g. Utah, Federal, National">
                    </label>
                    <label>Publication status
                        <select name="publication_status" id="vol-pub-status">
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                            <option value="archived">Archived</option>
                        </select>
                    </label>
                </div>

                <div class="form-row">
                    <label>Related facilities (one per line)
                        <textarea name="facilities_related" id="vol-facilities" rows="2"></textarea>
                    </label>
                </div>

                <div class="form-row">
                    <label>Reviewer notes (internal)
                        <textarea name="reviewer_notes" id="vol-reviewer-notes" rows="2"></textarea>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="primary-btn">Save</button>
                    <button type="button" id="volDeleteBtn" class="danger-btn" style="display:none">Delete</button>
                    <button type="button" id="volResetBtn">Reset</button>
                    <span id="volFormStatus" class="form-status"></span>
                </div>
            </form>
        </section>
    </div>
</div>

<script>
(function() {
    'use strict';

    const API = '<?php echo esc_url_raw(get_stylesheet_directory_uri() . '/api/save-volunteer.php'); ?>';
    const list  = document.getElementById('volunteerList');
    const form  = document.getElementById('volunteerForm');
    const status = document.getElementById('volFormStatus');
    const deleteBtn = document.getElementById('volDeleteBtn');

    const $ = id => document.getElementById(id);

    function linesToArray(v) {
        return (v || '').split(/\r?\n/).map(s => s.trim()).filter(Boolean);
    }
    function arrayToLines(v) {
        return Array.isArray(v) ? v.join('\n') : '';
    }

    async function loadList() {
        const s = $('filterStatus').value;
        const t = $('filterType').value;
        const p = $('filterPubStatus').value;
        const params = new URLSearchParams();
        if (s) params.set('status', s);
        if (t) params.set('type', t);
        if (p) params.set('publication_status', p);
        list.innerHTML = '<li class="empty">Loading…</li>';
        try {
            const res = await fetch(API + (params.toString() ? '?' + params : ''), { credentials: 'same-origin' });
            const data = await res.json();
            if (!data.success || !data.records.length) {
                list.innerHTML = '<li class="empty">No projects found.</li>';
                return;
            }
            list.innerHTML = data.records.map(r =>
                `<li data-id="${r.id}" class="record-item">
                    <strong>${escHtml(r.title)}</strong>
                    <span class="record-meta">${r.project_type} &bull; ${r.status} &bull; ${r.publication_status}</span>
                </li>`
            ).join('');
            list.querySelectorAll('[data-id]').forEach(li => {
                li.addEventListener('click', () => loadRecord(li.dataset.id));
            });
        } catch (e) {
            list.innerHTML = '<li class="empty">Error loading records.</li>';
        }
    }

    async function loadRecord(id) {
        const res  = await fetch(`${API}?id=${id}`, { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) return;
        const r = data.record;
        $('vol-id').value            = r.id;
        $('vol-title').value         = r.title || '';
        $('vol-type').value          = r.project_type || 'other';
        $('vol-status').value        = r.status || 'open';
        $('vol-description').value   = r.description || '';
        $('vol-contact-email').value = r.contact_email || '';
        $('vol-signup-url').value    = r.signup_url || '';
        $('vol-skills').value        = arrayToLines(r.skills_needed);
        $('vol-jurisdiction').value  = r.jurisdiction || '';
        $('vol-pub-status').value    = r.publication_status || 'draft';
        $('vol-facilities').value    = arrayToLines(r.facilities_related);
        $('vol-reviewer-notes').value = r.reviewer_notes || '';
        deleteBtn.style.display = 'inline-block';
        setStatus('');
    }

    function resetForm() {
        form.reset();
        $('vol-id').value = '';
        deleteBtn.style.display = 'none';
        setStatus('');
    }

    function setStatus(msg, ok = true) {
        status.textContent = msg;
        status.style.color = ok ? 'green' : 'red';
    }

    function escHtml(s) {
        return String(s || '').replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    form.addEventListener('submit', async e => {
        e.preventDefault();
        const payload = {
            id:                 parseInt($('vol-id').value) || 0,
            title:              $('vol-title').value.trim(),
            project_type:       $('vol-type').value,
            status:             $('vol-status').value,
            description:        $('vol-description').value.trim(),
            contact_email:      $('vol-contact-email').value.trim(),
            signup_url:         $('vol-signup-url').value.trim(),
            skills_needed:      linesToArray($('vol-skills').value),
            jurisdiction:       $('vol-jurisdiction').value.trim(),
            facilities_related: linesToArray($('vol-facilities').value),
            publication_status: $('vol-pub-status').value,
            reviewer_notes:     $('vol-reviewer-notes').value.trim(),
        };
        if (!payload.id) delete payload.id;
        try {
            const res  = await fetch(API, { method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) });
            const data = await res.json();
            if (data.success) {
                setStatus(data.action === 'created' ? 'Created!' : 'Saved!', true);
                loadList();
            } else {
                setStatus('Error: ' + (data.error || 'Unknown'), false);
            }
        } catch (err) {
            setStatus('Network error', false);
        }
    });

    deleteBtn.addEventListener('click', async () => {
        const id = parseInt($('vol-id').value);
        if (!id || !confirm('Delete this project?')) return;
        const res  = await fetch(API, { method: 'POST', credentials: 'same-origin', headers: {'Content-Type':'application/json'}, body: JSON.stringify({id, _delete: true}) });
        const data = await res.json();
        if (data.success) { resetForm(); loadList(); }
        else setStatus('Delete failed: ' + (data.error || ''), false);
    });

    document.getElementById('newVolunteerBtn').addEventListener('click', resetForm);
    document.getElementById('volResetBtn').addEventListener('click', resetForm);
    document.getElementById('reloadList').addEventListener('click', loadList);
    ['filterStatus','filterType','filterPubStatus'].forEach(id => {
        $(id).addEventListener('change', loadList);
    });

    loadList();
})();
</script>

<?php get_footer(); ?>
