<?php
/**
 * The data forms' Trade Organizations tab (js/data-form/tradeorg-form.js), shared by
 * templates/data-form-admin.php and templates/data-form-public.php. Set before including:
 *   $kop_tradeorg_part   'panel' (saved projects, in the category contents) or 'editor' (the organization form)
 *   $kop_tradeorg_public true on the public suggestion form
 */
if (!defined('ABSPATH')) {
    exit;
}
require_once get_stylesheet_directory() . '/api/lib-tradeorgs.php';

$kop_tradeorg_public = !empty($kop_tradeorg_public);
$kop_tradeorg_list = function ($label, $field, $hint = '') {
    ?>
                    <div class="form-group">
                        <label><?php echo esc_html($label); ?></label>
                        <?php if ($hint !== '') : ?><p class="kop-marketer-hint"><?php echo esc_html($hint); ?></p><?php endif; ?>
                        <div class="array-container" data-path="tradeOrg.<?php echo esc_attr($field); ?>"></div>
                    </div>
    <?php
};

if (($kop_tradeorg_part ?? '') === 'panel') : ?>
                <!-- Trade Organizations Content -->
                <div id="tradeorgs-content" class="category-content view-hidden" data-section-views="tradeorgs">
                    <div class="content-header">
                        <h3><?php echo kop_icon('landmark'); ?> Trade Organizations</h3>
                    </div>

                    <div class="project-management" id="tradeorg-project-panel-inner">
                        <h2>Trade Organization Projects</h2>
                        <p style="margin: 0 0 12px; color: #4A5568; font-size: 14px;">Associations of programs, consultants, transporters or other businesses in the industry, such as NATSAP, the OBH Council or IECA. Programs whose records list an organization under memberships are shown on its page by themselves.</p>
                        <div class="form-group">
                            <label for="tradeorg-search-input">Saved Trade Organization Projects</label>
                            <div style="margin-bottom: 10px;">
                                <input type="text" id="tradeorg-search-input" class="input-form project-search-input" placeholder="Search by name, acronym or keyword..." style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                            <div id="tradeorg-saved-projects-list" class="saved-projects-list">
                                <div style="color: #4A5568; font-style: italic;">No saved trade organization projects</div>
                            </div>
                        </div>
                        <div class="project-actions">
                            <button type="button" id="new-project-btn-tradeorg" class="kop-btn project-action-btn">New Trade Organization Project</button>
                            <button type="button" id="export-all-btn-tradeorg" class="kop-btn project-action-btn">Export Trade Organizations</button>
                            <button type="button" id="generate-report-btn-tradeorg" class="kop-btn project-action-btn">Generate Trade Organization Report</button>
                        </div>
                    </div>
                </div>
<?php elseif (($kop_tradeorg_part ?? '') === 'editor') : ?>
        <!-- Trade Organization Sections (shown when the Trade Organizations tab is active) -->
        <div id="tradeorg-main-wrapper" class="view-hidden" data-section-views="tradeorgs"<?php if ($kop_tradeorg_public) : ?> data-kop-bug-feature="data-form/tradeorg-editor" data-kop-bug-label="Trade Organization Editor"<?php endif; ?>>

            <div class="section expanded" id="tradeorg-org-section">
                <div class="section-header">
                    <h2 class="section-title">Trade Organization</h2>
                    <span class="section-toggle"><?php echo kop_icon('search'); ?></span>
                </div>
                <div class="section-content">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="tradeorg-name">Name</label>
                            <input type="text" id="tradeorg-name" class="input-form" placeholder="e.g., National Association of Therapeutic Schools and Programs">
                        </div>
                        <div class="form-group">
                            <label for="tradeorg-acronym">Acronym</label>
                            <input type="text" id="tradeorg-acronym" class="input-form" placeholder="e.g., NATSAP">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="tradeorg-org-type">What kind of organization</label>
                        <input type="text" id="tradeorg-org-type" class="input-form" placeholder="e.g., Trade association of programs, of educational consultants, of transport companies">
                    </div>

                    <?php $kop_tradeorg_list('Other Names / Former Names', 'otherNames', 'Every name it has gone by: programs\' records are matched to it by these too.'); ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="tradeorg-city">Headquarters City</label>
                            <input type="text" id="tradeorg-city" class="input-form">
                        </div>
                        <div class="form-group">
                            <label for="tradeorg-state">State</label>
                            <input type="text" id="tradeorg-state" class="input-form" data-autocomplete-category="location">
                        </div>
                        <div class="form-group">
                            <label for="tradeorg-country">Country</label>
                            <input type="text" id="tradeorg-country" class="input-form" data-autocomplete-category="country">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="tradeorg-address">Address</label>
                            <input type="text" id="tradeorg-address" class="input-form">
                        </div>
                        <div class="form-group">
                            <label for="tradeorg-founded">Founded</label>
                            <input type="text" id="tradeorg-founded" class="input-form" placeholder="e.g., 1999">
                        </div>
                        <div class="form-group">
                            <label for="tradeorg-status">Status</label>
                            <input type="text" id="tradeorg-status" class="input-form" data-autocomplete-category="status" placeholder="Active, Renamed, Dissolved">
                        </div>
                    </div>

                    <?php $kop_tradeorg_list('Websites', 'websites'); ?>
                    <?php $kop_tradeorg_list('Leaders and Key People', 'keyPersonnel', 'One per line, with their role and years if known, e.g. "Jan Moss (executive director, 2007)".'); ?>
                </div>
            </div>

            <div class="section expanded" id="tradeorg-activities-section">
                <div class="section-header">
                    <h2 class="section-title">What It Does</h2>
                    <span class="section-toggle"><?php echo kop_icon('search'); ?></span>
                </div>
                <div class="section-content">
                    <?php foreach (kop_tradeorg_activity_labels() as $key => $label) : ?>
                        <div class="checkbox-group">
                            <input type="checkbox" class="tradeorg-activity" data-activity="<?php echo esc_attr($key); ?>" id="tradeorg-activity-<?php echo esc_attr($key); ?>">
                            <label for="tradeorg-activity-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                        </div>
                    <?php endforeach; ?>
                    <?php $kop_tradeorg_list('Other Activities', 'otherActivities'); ?>
                    <?php $kop_tradeorg_list('Lobbying and Testimony', 'lobbying', 'Bills it supported or fought, hearings it testified at.'); ?>
                    <?php $kop_tradeorg_list('Funding', 'funding', 'Who funds it, and what it funds (research, campaigns).'); ?>
                    <?php $kop_tradeorg_list('Publications', 'publications', 'Journals, newsletters, strategic plans.'); ?>
                </div>
            </div>

            <div class="section expanded" id="tradeorg-members-section">
                <div class="section-header">
                    <h2 class="section-title">Members and Connections</h2>
                    <span class="section-toggle"><?php echo kop_icon('search'); ?></span>
                </div>
                <div class="section-content">
                    <?php $kop_tradeorg_list('Member Programs', 'memberFacilities', 'Only programs whose own records do not already list it: those are shown on its page by themselves.'); ?>
                    <?php $kop_tradeorg_list('Member Companies', 'memberCompanies'); ?>
                    <?php $kop_tradeorg_list('Other Members (consultants, transporters, marketers)', 'memberOthers'); ?>
                    <?php $kop_tradeorg_list('Affiliations (other organizations, board seats)', 'affiliations'); ?>
                    <?php $kop_tradeorg_list('Lawsuits', 'lawsuits'); ?>
                    <?php $kop_tradeorg_list('Source URLs', 'sourceUrls', 'Where each fact comes from: articles, archived pages, filings.'); ?>
                    <?php $kop_tradeorg_list('Social Media Links', 'socialMedia'); ?>
                    <div class="form-group">
                        <label for="tradeorg-notes">Notes</label>
                        <textarea id="tradeorg-notes" class="input-form" rows="4" placeholder="Anything else about this organization..."></textarea>
                    </div>
                </div>
            </div>

            <div class="section expanded" id="tradeorg-submission-section" style="border: 2px solid #1e40af; background: #f8fafc;">
                <div class="section-header" style="background: #1e40af; color: white; cursor: default; pointer-events: none;">
                    <h2 class="section-title" style="color: white; pointer-events: none;"><?php echo kop_icon($kop_tradeorg_public ? 'send' : 'save'); ?> <?php echo $kop_tradeorg_public ? 'Submit Trade Organization for Review' : 'Save Trade Organization Project'; ?></h2>
                </div>
                <div class="section-content" style="display: block;">
                    <div class="form-group">
                        <label for="tradeorg-project-name">Project Name</label>
                        <input type="text" id="tradeorg-project-name" placeholder="The organization's name" style="width: 100%;">
                    </div>
                    <div class="form-group">
                        <button type="button" class="save-master-btn" id="save-tradeorg-project-btn">
                            <?php echo kop_icon($kop_tradeorg_public ? 'send' : 'save'); ?> <?php echo $kop_tradeorg_public ? 'Submit for Review' : 'Save Trade Organization Project'; ?>
                        </button>
                    </div>
                </div>
            </div>

        </div>
<?php endif;
