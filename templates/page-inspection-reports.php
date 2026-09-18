<?php
/**
 * Template Name: Inspection Reports
 * Description: The hub for the state inspection trackers. Each state has its
 * own /xx-reports/ page and the home page carries a grid of them, but there
 * was nowhere to send someone who wants "the inspection reports" as a whole,
 * so the Monitor menu had no entry for the largest body of evidence on the
 * site.
 *
 * Everything here is derived, not curated: the state list comes from
 * kop_report_state_links() (which reads the same map the REST layer uses to
 * link a state to its tracker), and the counts and featured reports come from
 * the inspection tables. The page's editor content, if any, is printed above
 * the grid, so an introduction can be written in wp-admin without touching
 * this file.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_ir_css = get_stylesheet_directory() . '/css/home.css';
if (file_exists($kop_ir_css)) {
    // The grid, the counts strip and the featured cards all reuse the home
    // page's classes, so this page needs no stylesheet of its own.
    wp_enqueue_style('kop-home', get_stylesheet_directory_uri() . '/css/home.css', array('kop-colors'), filemtime($kop_ir_css));
}

get_header();

$kop_ir_states = function_exists('kop_report_state_links') ? kop_report_state_links() : array();

global $wpdb;
$kop_ir_suppress = $wpdb->suppress_errors(true);

// Reports per state, so each button can say how much is behind it.
// inspection_facilities.state holds the two-letter code (varchar(10)), which
// is also the tracker slug's prefix, so the counts key on that.
$kop_ir_counts = get_transient('kop_inspection_hub_counts');
if (!is_array($kop_ir_counts)) {
    $kop_ir_counts = array();
    $rows = $wpdb->get_results(
        "SELECT f.state AS state, COUNT(*) AS reports, COUNT(DISTINCT r.facility_id) AS facilities
         FROM inspection_reports r
         JOIN inspection_facilities f ON f.id = r.facility_id
         GROUP BY f.state",
        ARRAY_A
    );
    foreach ((array) $rows as $row) {
        $kop_ir_counts[strtolower(trim((string) $row['state']))] = array(
            'reports'    => (int) $row['reports'],
            'facilities' => (int) $row['facilities'],
        );
    }
    if ($kop_ir_counts) {
        set_transient('kop_inspection_hub_counts', $kop_ir_counts, 6 * HOUR_IN_SECONDS);
    }
}

$kop_ir_total_reports    = 0;
$kop_ir_total_facilities = 0;
foreach ($kop_ir_counts as $count) {
    $kop_ir_total_reports    += $count['reports'];
    $kop_ir_total_facilities += $count['facilities'];
}

// The same curated set the home page shows
// (api/manage-featured-inspections.php). The featured columns are added by
// api/update-schema.php, so check before asking for them: the block is simply absent on a
// database where nothing has been featured yet.
$kop_ir_has_featured = get_transient('kop_inspection_featured_column_v2');
if ($kop_ir_has_featured === false) {
    $kop_ir_has_featured = $wpdb->get_var("SHOW COLUMNS FROM inspection_reports LIKE 'featured'") ? 'yes' : 'no';
    // A missing column is remembered briefly, so running api/update-schema.php
    // shows the block within minutes instead of a day later. The key carries a
    // version because a "no" cached for a full day under the old name would
    // otherwise outlive the migration.
    set_transient('kop_inspection_featured_column_v2', $kop_ir_has_featured, $kop_ir_has_featured === 'yes' ? DAY_IN_SECONDS : 10 * MINUTE_IN_SECONDS);
}
$kop_ir_flagged = $kop_ir_has_featured === 'yes' ? $wpdb->get_results(
    "SELECT r.report_date, r.report_url, r.featured_note, f.facility_name, f.state
     FROM inspection_reports r
     JOIN inspection_facilities f ON f.id = r.facility_id
     WHERE r.featured = 1
     ORDER BY r.report_date DESC, r.id DESC LIMIT 6",
    ARRAY_A
) : array();
$wpdb->suppress_errors($kop_ir_suppress);

$kop_ir_tracker_slugs = function_exists('kop_state_inspection_page_map')
    ? array_values(kop_state_inspection_page_map()) : array();
?>

<div class="kop-home kop-inspection-hub">

    <section class="kop-home-hero">
        <h1 class="entry-title">State inspection reports</h1>
        <p>State licensing agencies inspect the facilities they license, and what
        the inspectors found is public record. We collect those reports, state by
        state, so a parent, a survivor or a reporter can read them without filing
        a records request.</p>
    </section>

    <?php
    // Whatever an editor has written on the page itself goes here, above the
    // grid, so this template never has to be edited to change the wording.
    $kop_ir_content = trim(apply_filters('the_content', get_the_content(null, false, get_the_ID())));
    if ($kop_ir_content !== '') :
    ?>
    <section class="kop-home-mission"><?php echo $kop_ir_content; // phpcs:ignore WordPress.Security.EscapeOutput ?></section>
    <?php endif; ?>

    <?php if ($kop_ir_total_reports > 0) : ?>
    <section class="kop-home-numbers" aria-label="By the numbers">
        <div class="kop-number">
            <span class="kop-number-value"><?php echo esc_html(number_format($kop_ir_total_reports)); ?></span>
            <span class="kop-number-label">Inspection reports collected</span>
        </div>
        <div class="kop-number">
            <span class="kop-number-value"><?php echo esc_html(number_format($kop_ir_total_facilities)); ?></span>
            <span class="kop-number-label">Facilities with reports</span>
        </div>
        <div class="kop-number">
            <span class="kop-number-value"><?php echo esc_html(number_format(count($kop_ir_states))); ?></span>
            <span class="kop-number-label">States with a tracker</span>
        </div>
    </section>
    <?php endif; ?>

    <section class="kop-home-reports">
        <h2>Choose a state</h2>
        <p>Each tracker lists the facilities that state licenses, the inspections
        on record for them, and the violations the inspectors wrote up. More
        states are added as their records are obtained.</p>
        <div class="kop-home-reports-buttons">
            <?php foreach ($kop_ir_states as $slug => $label) :
                $key = strtolower(substr($slug, 0, 2));
                $count = isset($kop_ir_counts[$key]) ? $kop_ir_counts[$key]['reports'] : 0;
            ?>
                <a class="kop-home-report-btn" href="/<?php echo esc_attr($slug); ?>">
                    <?php echo esc_html($label); ?>
                    <?php if ($count > 0) : ?>
                        <span class="kop-report-btn-count"><?php echo esc_html(number_format($count)); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($kop_ir_flagged) : ?>
    <section class="kop-home-flagged">
        <h2>Reports that demand attention</h2>
        <div class="kop-flagged-grid">
            <?php foreach ($kop_ir_flagged as $fr) :
                $tracker = strtolower($fr['state']) . '-reports';
                $has_tracker = in_array($tracker, $kop_ir_tracker_slugs, true);
            ?>
                <div class="kop-flagged-card">
                    <h3><?php echo esc_html($fr['facility_name']); ?>
                        <span class="kop-flagged-state"><?php echo esc_html($fr['state']); ?></span></h3>
                    <?php if (!empty($fr['report_date'])) : ?>
                        <div class="kop-flagged-date">Inspected <?php echo esc_html($fr['report_date']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($fr['featured_note'])) : ?>
                        <p><?php echo esc_html($fr['featured_note']); ?></p>
                    <?php endif; ?>
                    <div class="kop-flagged-links">
                        <?php if (!empty($fr['report_url'])) : ?>
                            <a href="<?php echo esc_url($fr['report_url']); ?>" target="_blank" rel="noopener noreferrer">View the report</a>
                        <?php endif; ?>
                        <?php if ($has_tracker) : ?>
                            <a href="/<?php echo esc_attr($tracker); ?>"><?php echo esc_html($fr['state']); ?> tracker</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="kop-home-help">
        <h2>Your state is not here yet</h2>
        <p>Trackers are built from records we have obtained. If your state is
        missing, the records usually exist and simply have not been requested.
        <a href="/volunteer/">Volunteering</a> to file or process a request is the
        fastest way to get a state added, and documents you already hold can be
        sent through the <a href="/anon-submit/">anonymous upload</a>.</p>
    </section>

</div>

<?php
get_footer();
