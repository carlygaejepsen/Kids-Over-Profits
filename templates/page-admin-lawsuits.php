<?php
/**
 * Template Name: Admin - Lawsuits
 *
 * Admin-only entry/edit form and list view for the lawsuits table.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!is_user_logged_in() || !current_user_can('edit_posts')) {
    wp_die('Access denied. Contributor privileges or higher required.');
}

$us_states = ['Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut','Delaware','Florida','Georgia','Hawaii','Idaho','Illinois','Indiana','Iowa','Kansas','Kentucky','Louisiana','Maine','Maryland','Massachusetts','Michigan','Minnesota','Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire','New Jersey','New Mexico','New York','North Carolina','North Dakota','Ohio','Oklahoma','Oregon','Pennsylvania','Rhode Island','South Carolina','South Dakota','Tennessee','Texas','Utah','Vermont','Virginia','Washington','West Virginia','Wisconsin','Wyoming'];
$federal_option = 'Federal';

get_header();
?>

<link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/css/admin-state-content.css?v=<?php echo time(); ?>">

<div class="admin-state-content-wrapper">
    <header class="admin-state-content-header">
        <h1>Lawsuit Records</h1>
        <p>Add and edit lawsuit records that show up on state pages.</p>
    </header>

    <div class="admin-state-content-layout">
        <section class="admin-state-content-list">
            <div class="list-controls">
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
                <button type="button" id="newLawsuitBtn" class="primary-btn">+ New Lawsuit</button>
            </div>
            <ul id="lawsuitList" class="record-list">
                <li class="empty">Loading...</li>
            </ul>
        </section>

        <section class="admin-state-content-form">
            <form id="lawsuitForm" autocomplete="off">
                <input type="hidden" name="id" id="lawsuit-id" value="">

                <div class="form-row">
                    <label>Case name *
                        <input type="text" name="case_name" id="lawsuit-case-name" required>
                    </label>
                </div>

                <div class="form-row two-col">
                    <label>Case number
                        <input type="text" name="case_number" id="lawsuit-case-number">
                    </label>
                    <label>Court
                        <input type="text" name="court" id="lawsuit-court" placeholder="e.g. US District Court, D. Utah">
                    </label>
                </div>

                <div class="form-row two-col">
                    <label>Jurisdiction
                        <select name="jurisdiction" id="lawsuit-jurisdiction">
                            <option value="">--</option>
                            <option value="<?php echo esc_attr($federal_option); ?>"><?php echo esc_html($federal_option); ?></option>
                            <optgroup label="US States">
                                <?php foreach ($us_states as $state): ?>
                                    <option value="<?php echo esc_attr($state); ?>"><?php echo esc_html($state); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </label>
                    <label>Filing date
                        <input type="date" name="filing_date" id="lawsuit-filing-date">
                    </label>
                    <label>Status
                        <select name="status" id="lawsuit-status">
                            <option value="unknown">Unknown</option>
                            <option value="filed">Filed</option>
                            <option value="in_progress">In progress</option>
                            <option value="settled">Settled</option>
                            <option value="dismissed">Dismissed</option>
                            <option value="ruling">Ruling</option>
                            <option value="appeal">On appeal</option>
                            <option value="closed">Closed</option>
                        </select>
                    </label>
                    <label>Publication status
                        <select name="publication_status" id="lawsuit-pub-status">
                            <option value="draft">Draft</option>
                            <option value="pending">Pending review</option>
                            <option value="approved">Approved</option>
                            <option value="published">Published</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </label>
                </div>

                <div class="form-row">
                    <button type="button" id="fetchLawsuitBtn" class="primary-btn autofill-btn">✨ Auto-populate details from court records</button>
                </div>

                <div class="form-row two-col">
                    <label>Plaintiffs (one per line)
                        <textarea name="plaintiffs" id="lawsuit-plaintiffs" rows="3"></textarea>
                    </label>
                    <label>Defendants (one per line)
                        <textarea name="defendants" id="lawsuit-defendants" rows="3"></textarea>
                    </label>
                </div>

                <div class="form-row two-col">
                    <label>Facilities mentioned (one per line)
                        <textarea name="facilities_mentioned" id="lawsuit-facilities" rows="3"></textarea>
                    </label>
                    <label>Staff/owners mentioned (one per line)
                        <textarea name="staff_mentioned" id="lawsuit-staff" rows="3"></textarea>
                    </label>
                </div>

                <div class="form-row two-col">
                    <label>Organizations mentioned (one per line)
                        <textarea name="organizations_mentioned" id="lawsuit-orgs" rows="2"></textarea>
                    </label>
                    <label>Claims (one per line — e.g. physical_abuse, wrongful_death, fraud)
                        <textarea name="claims" id="lawsuit-claims" rows="2"></textarea>
                    </label>
                </div>

                <div class="form-row two-col">
                    <label>Outcome
                        <input type="text" name="outcome" id="lawsuit-outcome">
                    </label>
                    <label>Settlement amount (display string)
                        <input type="text" name="settlement_amount" id="lawsuit-settlement" placeholder="e.g. $1.2M">
                    </label>
                </div>

                <div class="form-row">
                    <label>Summary
                        <textarea name="summary" id="lawsuit-summary" rows="4"></textarea>
                    </label>
                </div>

                <div class="form-row two-col">
                    <label>Source URLs (one per line)
                        <textarea name="source_urls" id="lawsuit-sources" rows="3"></textarea>
                    </label>
                    <label>Court document URLs (one per line)
                        <textarea name="document_urls" id="lawsuit-docs" rows="3"></textarea>
                    </label>
                </div>

                <div class="form-row">
                    <label>Tags (one per line)
                        <textarea name="tags" id="lawsuit-tags" rows="2"></textarea>
                    </label>
                </div>

                <div class="form-row filebird-row">
                    <label>FileBird folder (case documents)
                        <div class="filebird-picker">
                            <select id="lawsuit-filebird-folder" name="filebird_folder_id">
                                <option value="">— No folder —</option>
                            </select>
                            <a href="<?php echo esc_url(admin_url('upload.php')); ?>" target="_blank" rel="noopener" class="filebird-helper-link">Manage folders / upload files →</a>
                        </div>
                    </label>
                </div>

                <div class="form-row">
                    <label>Reviewer notes (internal)
                        <textarea name="reviewer_notes" id="lawsuit-reviewer-notes" rows="2"></textarea>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="primary-btn">Save</button>
                    <button type="button" id="lawsuitResetBtn">Reset</button>
                    <span id="lawsuitFormStatus" class="form-status"></span>
                </div>
            </form>
        </section>
    </div>
</div>

<script>
window.adminLawsuitsConfig = {
    apiUrl: '<?php echo esc_url_raw(get_stylesheet_directory_uri() . '/api/save-lawsuit.php'); ?>',
    foldersUrl: '<?php echo esc_url_raw(rest_url('kop/v1/folders')); ?>',
    fetchApiUrl: '<?php echo esc_url_raw(get_stylesheet_directory_uri() . '/api/fetch-lawsuit-details.php'); ?>'
};
</script>
<script src="<?php echo esc_url_raw(get_stylesheet_directory_uri() . '/js/admin-state-content.js'); ?>?v=<?php echo time(); ?>"></script>

<?php get_footer(); ?>
