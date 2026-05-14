<?php
/**
 * Template Name: Admin - Legislation
 *
 * Admin-only entry/edit form and list view for the legislation table.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!is_user_logged_in() || !current_user_can('manage_options')) {
    wp_die('Access denied. Admin privileges required.');
}

$us_states = ['Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut','Delaware','Florida','Georgia','Hawaii','Idaho','Illinois','Indiana','Iowa','Kansas','Kentucky','Louisiana','Maine','Maryland','Massachusetts','Michigan','Minnesota','Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire','New Jersey','New Mexico','New York','North Carolina','North Dakota','Ohio','Oklahoma','Oregon','Pennsylvania','Rhode Island','South Carolina','South Dakota','Tennessee','Texas','Utah','Vermont','Virginia','Washington','West Virginia','Wisconsin','Wyoming'];
$federal_option = 'Federal';

get_header();
?>

<link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/css/admin-state-content.css?v=<?php echo time(); ?>">

<div class="admin-state-content-wrapper">
    <header class="admin-state-content-header">
        <h1>Legislation Records</h1>
        <p>Track bills and laws that affect TTI facilities.</p>
    </header>

    <div class="admin-state-content-layout">
        <section class="admin-state-content-list">
            <div class="list-controls">
                <select id="filterLevel">
                    <option value="">All levels</option>
                    <option value="state">State only</option>
                    <option value="federal">Federal only</option>
                </select>
                <select id="filterJurisdiction">
                    <option value="">All jurisdictions</option>
                    <option value="<?php echo esc_attr($federal_option); ?>"><?php echo esc_html($federal_option); ?></option>
                    <optgroup label="US States">
                        <?php foreach ($us_states as $state): ?>
                            <option value="<?php echo esc_attr($state); ?>"><?php echo esc_html($state); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                <select id="filterPublicationStatus">
                    <option value="">All statuses</option>
                    <option value="draft">Draft</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="published" selected>Published</option>
                    <option value="rejected">Rejected</option>
                </select>
                <button type="button" id="reloadList">Reload</button>
                <button type="button" id="newLegislationBtn" class="primary-btn">+ New Bill</button>
            </div>
            <ul id="legislationList" class="record-list">
                <li class="empty">Loading...</li>
            </ul>
        </section>

        <section class="admin-state-content-form">
            <form id="legislationForm" autocomplete="off">
                <input type="hidden" name="id" id="leg-id" value="">

                <div class="form-row">
                    <label>Legislation level
                        <select name="legislation_level" id="leg-level">
                            <option value="state" selected>State</option>
                            <option value="federal">Federal</option>
                        </select>
                    </label>
                </div>

                <div class="form-row two-col">
                    <label>Bill type
                        <select name="bill_type" id="leg-bill-type">
                            <option value="">— pick jurisdiction first —</option>
                        </select>
                    </label>
                    <label>Bill number
                        <input type="text" name="bill_number" id="leg-bill-number" placeholder="1190">
                    </label>
                </div>

                <div class="form-row two-col">
                    <label>Jurisdiction
                        <select name="jurisdiction" id="leg-jurisdiction">
                            <option value="">--</option>
                            <option value="<?php echo esc_attr($federal_option); ?>"><?php echo esc_html($federal_option); ?></option>
                            <optgroup label="US States">
                                <?php foreach ($us_states as $state): ?>
                                    <option value="<?php echo esc_attr($state); ?>"><?php echo esc_html($state); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </label>
                    <label>Chamber
                        <select name="chamber" id="leg-chamber">
                            <option value="unknown">Unknown</option>
                            <option value="house">House</option>
                            <option value="senate">Senate</option>
                            <option value="assembly">Assembly</option>
                            <option value="joint">Joint</option>
                            <option value="federal_house">US House</option>
                            <option value="federal_senate">US Senate</option>
                            <option value="other">Other</option>
                        </select>
                    </label>
                </div>

                <div class="form-row">
                    <button type="button" id="fetchLegislationBtn" class="primary-btn autofill-btn">✨ Auto-populate details from government sources</button>
                </div>

                <div class="form-row">
                    <label>Bill title
                        <input type="text" name="bill_title" id="leg-bill-title" required>
                    </label>
                </div>

                <div class="form-row two-col">
                    <label>Session
                        <select name="session_year" id="leg-session-year">
                            <option value="">— pick jurisdiction first —</option>
                        </select>
                    </label>
                    <label>Status
                        <select name="status" id="leg-status">
                            <option value="unknown">Unknown</option>
                            <option value="introduced">Introduced</option>
                            <option value="in_committee">In committee</option>
                            <option value="passed_house">Passed house</option>
                            <option value="passed_senate">Passed senate</option>
                            <option value="signed">Signed</option>
                            <option value="enacted">Enacted</option>
                            <option value="vetoed">Vetoed</option>
                            <option value="dead">Dead</option>
                        </select>
                    </label>
                </div>

                <div class="form-row two-col">
                    <label>Introduced date
                        <input type="date" name="introduced_date" id="leg-introduced-date">
                    </label>
                    <label>Last action date
                        <input type="date" name="last_action_date" id="leg-last-action-date">
                    </label>
                </div>

                <div class="form-row">
                    <label>Last action
                        <input type="text" name="last_action_text" id="leg-last-action-text">
                    </label>
                </div>

                <div class="form-row">
                    <label>Sponsors (one per line)
                        <textarea name="sponsors" id="leg-sponsors" rows="2"></textarea>
                    </label>
                </div>

                <div class="form-row">
                    <label>Subject tags (one per line — e.g. transparency, restraints, oversight)
                        <textarea name="subject_tags" id="leg-subject-tags" rows="2"></textarea>
                    </label>
                </div>

                <div class="form-row">
                    <label>Summary
                        <textarea name="summary" id="leg-summary" rows="4"></textarea>
                    </label>
                </div>

                <div class="form-row two-col">
                    <label>Full text URL
                        <input type="url" name="full_text_url" id="leg-full-text-url">
                    </label>
                    <label>Official tracker URL
                        <input type="url" name="official_url" id="leg-official-url">
                    </label>
                </div>

                <div class="form-row two-col">
                    <label>KOP position
                        <select name="position" id="leg-position">
                            <option value="unknown">Unknown</option>
                            <option value="support">Support</option>
                            <option value="oppose">Oppose</option>
                            <option value="watch">Watch</option>
                            <option value="neutral">Neutral</option>
                        </select>
                    </label>
                    <label>Publication status
                        <select name="publication_status" id="leg-pub-status">
                            <option value="draft">Draft</option>
                            <option value="pending">Pending review</option>
                            <option value="approved">Approved</option>
                            <option value="published">Published</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </label>
                </div>

                <div class="form-row">
                    <label>Facilities affected (one per line)
                        <textarea name="facilities_affected" id="leg-facilities-affected" rows="2"></textarea>
                    </label>
                </div>

                <div class="form-row">
                    <label>Tags (one per line)
                        <textarea name="tags" id="leg-tags" rows="2"></textarea>
                    </label>
                </div>

                <div class="form-row filebird-row">
                    <label>FileBird folder (supporting documents)
                        <div class="filebird-picker">
                            <select id="leg-filebird-folder" name="filebird_folder_id">
                                <option value="">— No folder —</option>
                            </select>
                            <a href="<?php echo esc_url(admin_url('upload.php')); ?>" target="_blank" rel="noopener" class="filebird-helper-link">Manage folders / upload files →</a>
                        </div>
                    </label>
                </div>

                <div class="form-row">
                    <label>Reviewer notes (internal)
                        <textarea name="reviewer_notes" id="leg-reviewer-notes" rows="2"></textarea>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="primary-btn">Save</button>
                    <button type="button" id="legislationResetBtn">Reset</button>
                    <span id="legislationFormStatus" class="form-status"></span>
                </div>
            </form>
        </section>
    </div>
</div>

<script>
window.adminLegislationConfig = {
    apiUrl: '<?php echo esc_url_raw(get_stylesheet_directory_uri() . '/api/save-legislation.php'); ?>',
    foldersUrl: '<?php echo esc_url_raw(rest_url('kop/v1/folders')); ?>',
    fetchApiUrl: '<?php echo esc_url_raw(get_stylesheet_directory_uri() . '/api/fetch-bill-details.php'); ?>',
    metaApiUrl: '<?php echo esc_url_raw(get_stylesheet_directory_uri() . '/api/list-jurisdiction-meta.php'); ?>'
};
</script>
<script src="<?php echo esc_url_raw(get_stylesheet_directory_uri() . '/js/admin-state-content.js'); ?>?v=<?php echo time(); ?>"></script>

<?php get_footer(); ?>
