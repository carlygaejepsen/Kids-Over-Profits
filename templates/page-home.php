<?php
/**
 * Template Name: Home Page
 *
 * PHP replacement for the block-built home page. Reproduces the original
 * content (hero, audience buttons, mission, inspection report links, state
 * map, directory + volunteer text) and adds the dynamic Ongoing Stories
 * section (news story arcs curated in api/manage-story-arcs.php).
 *
 * The Kadence sidebar still renders per the page's layout settings, so the
 * search / newsletter / donation widgets are unaffected. The WP editor
 * content of the page is ignored once this template is assigned.
 */

function kop_enqueue_home_styles() {
    $css = get_stylesheet_directory() . '/css/home.css';
    if (file_exists($css)) {
        wp_enqueue_style('kop-home', get_stylesheet_directory_uri() . '/css/home.css', array('kop-colors'), filemtime($css));
    }
    // Ongoing Stories cards share the news feed stylesheet.
    $nf = get_stylesheet_directory() . '/css/news-feed.css';
    if (file_exists($nf)) {
        wp_enqueue_style('news-feed-css', get_stylesheet_directory_uri() . '/css/news-feed.css', array(), filemtime($nf));
    }
}
add_action('wp_enqueue_scripts', 'kop_enqueue_home_styles');

get_header();

/** Permalink of the page using $template, or home_url($fallback). */
if (!function_exists('kop_home_template_page_url')) {
    function kop_home_template_page_url($template, $fallback) {
        $pages = get_pages(array(
            'meta_key'   => '_wp_page_template',
            'meta_value' => $template,
            'number'     => 1,
        ));
        return !empty($pages) ? get_permalink($pages[0]->ID) : home_url($fallback);
    }
}

// Preview data: latest legislation and lawsuits, plus the curated featured
// inspection reports (api/manage-featured-inspections.php). All queries are
// suppressed so a missing table just hides its block.
global $wpdb;
$kop_suppress = $wpdb->suppress_errors(true);
$kop_bills = $wpdb->get_results(
    "SELECT bill_title, jurisdiction, status, last_action_date FROM legislation
     WHERE publication_status IN ('approved','published')
     ORDER BY last_action_date DESC, introduced_date DESC, id DESC LIMIT 3",
    ARRAY_A
);
$kop_suits = $wpdb->get_results(
    "SELECT case_name, jurisdiction, status, filing_date FROM lawsuits
     WHERE publication_status IN ('approved','published')
     ORDER BY filing_date DESC, id DESC LIMIT 3",
    ARRAY_A
);
$kop_flagged = $wpdb->get_results(
    "SELECT r.report_date, r.report_url, r.featured_note, f.facility_name, f.state
     FROM inspection_reports r
     JOIN inspection_facilities f ON f.id = r.facility_id
     WHERE r.featured = 1
     ORDER BY r.report_date DESC, r.id DESC LIMIT 4",
    ARRAY_A
);
$wpdb->suppress_errors($kop_suppress);

$kop_memorial = get_page_by_path('in-loving-memory');
$kop_legislation_url = kop_home_template_page_url('templates/page-legislation.php', '/legislation/');
$kop_lawsuits_url = kop_home_template_page_url('templates/page-lawsuits.php', '/lawsuits/');

// Featured inspections link to the state's tracker page when one exists.
$kop_tracker_slugs = function_exists('kop_state_inspection_page_map')
    ? array_values(kop_state_inspection_page_map()) : array();

// State inspection trackers currently available (slug prefix => label).
$kop_report_states = array(
    'or' => 'Oregon',
    'mn' => 'Minnesota',
    'ar' => 'Arkansas',
    'ct' => 'Connecticut',
    'mt' => 'Montana',
    'tx' => 'Texas',
    'ca' => 'California',
    'wa' => 'Washington',
    'ut' => 'Utah',
    'az' => 'Arizona',
);
?>

<div class="kop-home">

    <?php if (has_post_thumbnail()): ?>
        <div class="kop-home-banner"><?php the_post_thumbnail('full'); ?></div>
    <?php endif; ?>

    <section class="kop-home-hero">
        <h2>Survivor-led accountability for the Troubled Teen Industry.</h2>
        <p>Click the button that best describes you to get started.</p>
        <div class="kop-home-audience">
            <a class="kop-home-btn" href="/survivors">Survivors</a>
            <a class="kop-home-btn" href="/advocates">Concerned Citizens</a>
            <a class="kop-home-btn" href="/families">Friends &amp; Families</a>
            <a class="kop-home-btn" href="/journalists">Journalists</a>
        </div>
    </section>

    <section class="kop-home-search-section">
        <form role="search" method="get" class="kop-home-search" action="<?php echo esc_url(home_url('/')); ?>">
            <label class="screen-reader-text" for="kop-home-search-input">Search the site</label>
            <input type="search" id="kop-home-search-input" name="s" value=""
                   placeholder="Search facilities, news, reports&hellip;">
            <button type="submit">Search</button>
        </form>
    </section>

    <section class="kop-home-mission">
        <p><strong>We are a collaborative of Troubled Teen Industry (TTI) survivors and advocates.
        Our mission is to educate the public about the current and historical dangers of the TTI,
        in pursuit of the ultimate goal of keeping all children safe from abuse.</strong>
        We are not affiliated with any political party, group, or candidate.</p>
    </section>

    <?php
    // Ongoing Stories — the big developing stories (news story arcs).
    // Renders nothing until arcs exist, so it's safe from day one.
    if (function_exists('kop_ongoing_stories_shortcode')) {
        echo kop_ongoing_stories_shortcode(array());
    }
    ?>

    <?php if ($kop_memorial): ?>
    <section class="kop-home-memorial">
        <a class="kop-memorial-card" href="<?php echo esc_url(get_permalink($kop_memorial->ID)); ?>">
            <h2>In Loving Memory</h2>
            <p>Remembering the children whose deaths in the Troubled Teen Industry were preventable.
            Their stories are why this work exists.</p>
            <span class="kop-memorial-more">Visit the memorial &raquo;</span>
        </a>
    </section>
    <?php endif; ?>

    <?php if ($kop_bills || $kop_suits): ?>
    <section class="kop-home-previews">
        <?php if ($kop_bills): ?>
        <div class="kop-preview-card">
            <h2>Legislation We're Tracking</h2>
            <ul class="kop-preview-list">
                <?php foreach ($kop_bills as $b):
                    $meta = array_filter(array(
                        $b['jurisdiction'],
                        $b['status'] ? ucfirst(str_replace('_', ' ', $b['status'])) : '',
                        $b['last_action_date'] ? date('M j, Y', strtotime($b['last_action_date'])) : '',
                    ));
                ?>
                    <li>
                        <span class="kop-preview-title"><?php echo esc_html($b['bill_title']); ?></span>
                        <span class="kop-preview-meta"><?php echo esc_html(implode(' · ', $meta)); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <a class="kop-preview-more" href="<?php echo esc_url($kop_legislation_url); ?>">All legislation &raquo;</a>
        </div>
        <?php endif; ?>
        <?php if ($kop_suits): ?>
        <div class="kop-preview-card">
            <h2>Lawsuit Tracker</h2>
            <ul class="kop-preview-list">
                <?php foreach ($kop_suits as $s):
                    $meta = array_filter(array(
                        $s['jurisdiction'],
                        $s['status'] ? ucfirst(str_replace('_', ' ', $s['status'])) : '',
                        $s['filing_date'] ? 'filed ' . date('M j, Y', strtotime($s['filing_date'])) : '',
                    ));
                ?>
                    <li>
                        <span class="kop-preview-title"><?php echo esc_html($s['case_name']); ?></span>
                        <span class="kop-preview-meta"><?php echo esc_html(implode(' · ', $meta)); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <a class="kop-preview-more" href="<?php echo esc_url($kop_lawsuits_url); ?>">All lawsuits &raquo;</a>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($kop_flagged): ?>
    <section class="kop-home-flagged">
        <h2>Inspection Reports That Demand Attention</h2>
        <div class="kop-flagged-grid">
            <?php foreach ($kop_flagged as $fr):
                $tracker = strtolower($fr['state']) . '-reports';
                $has_tracker = in_array($tracker, $kop_tracker_slugs, true);
            ?>
                <div class="kop-flagged-card">
                    <h3><?php echo esc_html($fr['facility_name']); ?>
                        <span class="kop-flagged-state"><?php echo esc_html($fr['state']); ?></span></h3>
                    <?php if (!empty($fr['report_date'])): ?>
                        <div class="kop-flagged-date">Inspected <?php echo esc_html($fr['report_date']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($fr['featured_note'])): ?>
                        <p><?php echo esc_html($fr['featured_note']); ?></p>
                    <?php endif; ?>
                    <div class="kop-flagged-links">
                        <?php if (!empty($fr['report_url'])): ?>
                            <a href="<?php echo esc_url($fr['report_url']); ?>" target="_blank" rel="noopener noreferrer">View the report</a>
                        <?php endif; ?>
                        <?php if ($has_tracker): ?>
                            <a href="/<?php echo esc_attr($tracker); ?>"><?php echo esc_html(strtoupper($fr['state'])); ?> tracker</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="kop-home-reports">
        <h2>New Inspection Reports Available!</h2>
        <p>We created inspection trackers to make it easier for everyone to monitor violations in
        facilities for kids. Oregon, Minnesota, Arkansas, Connecticut, Montana, Texas, California,
        Washington, Utah, and Arizona are available now. More trackers are coming soon!</p>
        <div class="kop-home-reports-buttons">
            <?php foreach ($kop_report_states as $abbr => $label): ?>
                <a class="kop-home-report-btn" href="/<?php echo esc_attr($abbr); ?>-reports"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="kop-home-map">
        <?php
        // Interactive Geo Maps block, rendered via the plugin's shortcode.
        if (shortcode_exists('display-map')) {
            echo do_shortcode('[display-map id="592"]');
        }
        ?>
        <p>Residential facilities providing religious, behavioral, and mental health treatment to
        children exist all over the world. Some of these facilities are known to be part of the
        Troubled Teen Industry, while others have not yet been verified. Any facility where children
        live and receive care requires additional oversight. This directory helps communities monitor
        local programs and advocate for accountability. Click your home state for a list of
        facilities near you, or <a href="/international">click here for international programs.</a></p>
    </section>

    <section class="kop-home-help">
        <p>Want to help? Contact
        <a href="mailto:dani@kidsoverprofits.org">dani@kidsoverprofits.org</a>
        for volunteer opportunities or
        <a href="/support/">click here to help fund our mission.</a></p>
    </section>

    <?php if (shortcode_exists('addtoany')): ?>
        <div class="kop-home-share"><?php echo do_shortcode('[addtoany]'); ?></div>
    <?php endif; ?>

</div>

<?php
get_footer();
