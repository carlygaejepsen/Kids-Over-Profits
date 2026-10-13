<?php
/**
 * The data forms' Marketers tab (marketing companies; js/data-form/marketer-form.js), shared by
 * templates/data-form-admin.php and templates/data-form-public.php. Set before including:
 *   $kop_marketer_part   'panel' (saved projects, in the category contents) or 'editor' (the company form)
 *   $kop_marketer_public true on the public suggestion form
 */
if (!defined('ABSPATH')) {
    exit;
}
require_once get_stylesheet_directory() . '/api/lib-marketers.php';

$kop_marketer_public = !empty($kop_marketer_public);
$kop_marketer_list = function ($label, $field, $hint = '') {
    ?>
                    <div class="form-group">
                        <label><?php echo esc_html($label); ?></label>
                        <?php if ($hint !== '') : ?><p class="kop-marketer-hint"><?php echo esc_html($hint); ?></p><?php endif; ?>
                        <div class="array-container" data-path="marketerCompany.<?php echo esc_attr($field); ?>"></div>
                    </div>
    <?php
};

if (($kop_marketer_part ?? '') === 'panel') : ?>
                <!-- Marketing Companies Content -->
                <div id="marketers-content" class="category-content view-hidden" data-section-views="marketers">
                    <div class="content-header">
                        <h3><?php echo kop_icon('megaphone'); ?> Marketers (Marketing Companies)</h3>
                    </div>

                    <div class="project-management" id="marketer-project-panel-inner">
                        <h2>Marketer Projects</h2>
                        <p style="margin: 0 0 12px; color: #4A5568; font-size: 14px;">Companies that market troubled teen programs: they build the programs' websites, run "independent" directories and help lines, sell parents' inquiries, manage reputations or write instructions for AI chatbots.</p>
                        <div class="form-group">
                            <label for="marketer-search-input">Saved Marketer Projects</label>
                            <div style="margin-bottom: 10px;">
                                <input type="text" id="marketer-search-input" class="input-form project-search-input" placeholder="Search by company name or keyword..." style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                            </div>
                            <div id="marketer-saved-projects-list" class="saved-projects-list">
                                <div style="color: #4A5568; font-style: italic;">No saved marketer projects</div>
                            </div>
                        </div>
                        <div class="project-actions">
                            <button type="button" id="new-project-btn-marketer" class="kop-btn project-action-btn">New Marketer Project</button>
                            <button type="button" id="export-all-btn-marketer" class="kop-btn project-action-btn">Export Marketers</button>
                            <button type="button" id="generate-report-btn-marketer" class="kop-btn project-action-btn">Generate Marketer Report</button>
                        </div>
                    </div>
                </div>
<?php elseif (($kop_marketer_part ?? '') === 'editor') : ?>
        <!-- Marketer-Specific Sections (shown when the Marketers tab is active) -->
        <div id="marketer-main-wrapper" class="view-hidden" data-section-views="marketers"<?php if ($kop_marketer_public) : ?> data-kop-bug-feature="data-form/marketer-editor" data-kop-bug-label="Marketer Editor"<?php endif; ?>>

            <div class="section expanded" id="marketer-company-section">
                <div class="section-header">
                    <h2 class="section-title">Marketing Company</h2>
                    <span class="section-toggle"><?php echo kop_icon('search'); ?></span>
                </div>
                <div class="section-content">
                    <div class="form-group">
                        <label for="marketer-company-name">Company Name</label>
                        <input type="text" id="marketer-company-name" class="input-form" placeholder="e.g., Exceed Marketing Solutions">
                    </div>

                    <?php $kop_marketer_list('Other Names / DBAs / Former Names', 'otherNames'); ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="marketer-company-city">Headquarters City</label>
                            <input type="text" id="marketer-company-city" class="input-form" placeholder="e.g., St. George">
                        </div>
                        <div class="form-group">
                            <label for="marketer-company-state">State</label>
                            <input type="text" id="marketer-company-state" class="input-form" data-autocomplete-category="location" placeholder="e.g., UT">
                        </div>
                        <div class="form-group">
                            <label for="marketer-company-country">Country</label>
                            <input type="text" id="marketer-company-country" class="input-form" data-autocomplete-category="country" placeholder="e.g., United States">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="marketer-company-address">Address</label>
                            <input type="text" id="marketer-company-address" class="input-form" placeholder="Street address">
                        </div>
                        <div class="form-group">
                            <label for="marketer-company-founded">Founded</label>
                            <input type="text" id="marketer-company-founded" class="input-form" placeholder="e.g., 2009">
                        </div>
                        <div class="form-group">
                            <label for="marketer-company-status">Status</label>
                            <input type="text" id="marketer-company-status" class="input-form" data-autocomplete-category="status" placeholder="Active, Renamed, Out of business">
                        </div>
                    </div>

                    <?php $kop_marketer_list('Websites', 'websites', 'The company\'s own websites.'); ?>
                    <?php $kop_marketer_list('Parent Companies', 'parentCompanies'); ?>
                    <?php $kop_marketer_list('Owners and Key People', 'keyPersonnel', 'One per line, with their role if known, e.g. "Jane Doe (founder)".'); ?>
                </div>
            </div>

            <div class="section expanded" id="marketer-services-section">
                <div class="section-header">
                    <h2 class="section-title">What They Do for Programs</h2>
                    <span class="section-toggle"><?php echo kop_icon('search'); ?></span>
                </div>
                <div class="section-content">
                    <?php foreach (kop_marketer_service_labels() as $key => $label) : ?>
                        <div class="checkbox-group">
                            <input type="checkbox" class="marketer-service" data-service="<?php echo esc_attr($key); ?>" id="marketer-service-<?php echo esc_attr($key); ?>">
                            <label for="marketer-service-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
                        </div>
                    <?php endforeach; ?>
                    <?php $kop_marketer_list('Other Services', 'otherServices'); ?>
                    <?php $kop_marketer_list('Directories, Help Lines and Review Sites They Run', 'sitesRun', 'Websites that look independent but belong to this company, e.g. a "best boarding schools" directory.'); ?>
                </div>
            </div>

            <div class="section expanded" id="marketer-clients-section">
                <div class="section-header">
                    <h2 class="section-title">Clients and Connections</h2>
                    <span class="section-toggle"><?php echo kop_icon('search'); ?></span>
                </div>
                <div class="section-content">
                    <?php $kop_marketer_list('Programs They Market', 'clientFacilities', 'Start typing a program\'s name to pick its record.'); ?>
                    <?php $kop_marketer_list('Parent Companies They Market', 'clientCompanies'); ?>
                    <?php $kop_marketer_list('Industry Affiliations (NATSAP, trade groups, sponsorships)', 'affiliations'); ?>
                    <?php $kop_marketer_list('Lawsuits', 'lawsuits'); ?>
                    <?php $kop_marketer_list('Source URLs', 'sourceUrls', 'Where each fact comes from: articles, archived pages, filings.'); ?>
                    <?php $kop_marketer_list('Social Media Links', 'socialMedia'); ?>
                    <div class="form-group">
                        <label for="marketer-company-notes">Notes</label>
                        <textarea id="marketer-company-notes" class="input-form" rows="4" placeholder="Anything else about this company..."></textarea>
                    </div>
                </div>
            </div>

            <div class="section expanded" id="marketer-submission-section" style="border: 2px solid #1e40af; background: #f8fafc;">
                <div class="section-header" style="background: #1e40af; color: white; cursor: default; pointer-events: none;">
                    <h2 class="section-title" style="color: white; pointer-events: none;"><?php echo kop_icon($kop_marketer_public ? 'send' : 'save'); ?> <?php echo $kop_marketer_public ? 'Submit Marketer for Review' : 'Save Marketer Project'; ?></h2>
                </div>
                <div class="section-content" style="display: block;">
                    <div class="form-group">
                        <label for="marketer-project-name">Project Name</label>
                        <input type="text" id="marketer-project-name" placeholder="The company's name" style="width: 100%;">
                    </div>
                    <div class="form-group">
                        <button type="button" class="save-master-btn" id="save-marketer-project-btn">
                            <?php echo kop_icon($kop_marketer_public ? 'send' : 'save'); ?> <?php echo $kop_marketer_public ? 'Submit for Review' : 'Save Marketer Project'; ?>
                        </button>
                    </div>
                </div>
            </div>

        </div>
<?php endif;
