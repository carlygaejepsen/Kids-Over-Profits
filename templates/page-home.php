<?php
/**
 * Template Name: Home Page
 *
 * PHP replacement for the block-built home page. Reproduces the original
 * content (hero, audience buttons, mission, inspection report links, state
 * map, directory + volunteer text) and adds the dynamic Ongoing Stories
 * section (news story arcs curated in api/manage-story-arcs.php) and a
 * browse-by-topic grid.
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

/** Permalink of the published page using $template, or '' when there is none. */
if (!function_exists('kop_home_existing_page_url')) {
    function kop_home_existing_page_url($template) {
        $pages = get_pages(array(
            'meta_key'   => '_wp_page_template',
            'meta_value' => $template,
            'number'     => 1,
        ));
        return !empty($pages) ? get_permalink($pages[0]->ID) : '';
    }
}

// Preview data: latest legislation and lawsuits. Those tables live in the
// records database api/config.php connects to, not WordPress's, so $wpdb
// cannot see them (silently finds nothing on prod); read them through
// kop_seed_pdo() instead, the same way inc/legal-documents.php does. A null
// PDO handle or a failed query just hides its block, same as before; it
// never fatals. Inspection findings are not previewed here: quoted abuse
// findings on the front page were too much to meet without warning, so they
// stay on the inspection hub and /severe-reports/.
$kop_records_pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
$kop_bills = array();
$kop_suits = array();
if ($kop_records_pdo instanceof PDO) {
    try {
        $kop_bills = $kop_records_pdo->query(
            "SELECT bill_title, jurisdiction, status, last_action_date FROM legislation
             WHERE publication_status IN ('approved','published')
             ORDER BY last_action_date DESC, introduced_date DESC, id DESC LIMIT 3"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $kop_bills = array();
    }
    try {
        $kop_suits = $kop_records_pdo->query(
            "SELECT case_name, jurisdiction, status, filing_date FROM lawsuits
             WHERE publication_status IN ('approved','published')
             ORDER BY filing_date DESC, id DESC LIMIT 3"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $kop_suits = array();
    }
}

// By the numbers: live counts from the inspection database and the facility
// directory, cached for six hours so the home page doesn't re-run COUNT
// queries on every visit. A failed or empty result hides the whole strip.
// inspection_reports, inspection_facilities and facilities_master are
// WordPress-side tables, so $wpdb is correct here.
global $wpdb;
$kop_numbers = get_transient('kop_home_numbers');
if (!is_array($kop_numbers)) {
    $kop_suppress = $wpdb->suppress_errors(true);
    $kop_numbers = array(
        'reports'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM inspection_reports"),
        'inspected'  => (int) $wpdb->get_var("SELECT COUNT(DISTINCT facility_id) FROM inspection_reports"),
        'licensed'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM inspection_facilities"),
        'directory'  => 0,
    );
    if (function_exists('kop_v2_active') && kop_v2_active('homepage_stats')) {
        $kop_numbers['directory'] = kop_v2_facility_count();
    } elseif (function_exists('kop_get_facilities_database_connection') && function_exists('kop_discover_facilities_master_table')) {
        $kop_master = kop_discover_facilities_master_table(kop_get_facilities_database_connection());
        if ($kop_master) {
            $kop_numbers['directory'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$kop_master}`");
        }
    }
    $wpdb->suppress_errors($kop_suppress);
    if ($kop_numbers['reports'] > 0 || $kop_numbers['directory'] > 0) {
        set_transient('kop_home_numbers', $kop_numbers, 6 * HOUR_IN_SECONDS);
    }
}
$kop_show_numbers = !empty($kop_numbers['reports']) && !empty($kop_numbers['directory']);

$kop_memorial = get_page_by_path('in-loving-memory');
$kop_legislation_url = kop_home_template_page_url('templates/page-legislation.php', '/legislation/');
$kop_lawsuits_url = kop_home_template_page_url('templates/page-lawsuits.php', '/lawsuits/');

// Ways to help: the contribution tools (each has a built-in tutorial),
// mirroring the links curated on the /volunteer/ page.
$kop_volunteer_links = array(
    array('url' => '/tti-data-submission', 'label' => 'Data Submission Tool',
          'desc' => 'Create or update TTI facility profiles.'),
    array('url' => '/wiki-editor/', 'label' => 'Wiki Editor',
          'desc' => 'Improve program wiki pages.'),
    array('url' => '/anon-submit/', 'label' => 'Encrypted Upload',
          'desc' => 'Share documents anonymously.'),
    array('url' => '/news-processor/', 'label' => 'News Processor',
          'desc' => 'Submit TTI news articles and summaries.'),
    array('url' => '/submit-legislation', 'label' => 'Submit Legislation',
          'desc' => 'Report bills related to the TTI.'),
    array('url' => '/submit-lawsuit', 'label' => 'Submit a Lawsuit',
          'desc' => 'Report lawsuits involving TTI programs or staff.'),
);

// Browse by topic: one tile per kind of record the site keeps, each linked to
// the published page that lists it. A tile whose page does not exist (or is
// still a draft) is left out rather than linking into a 404.
$kop_topics = array(
    array('href' => '#kop-home-map', 'icon' => 'map-pin', 'label' => 'Facilities by state',
          'desc' => 'Programs near you, state by state.'),
    array('template' => 'templates/page-tti-program-index.php', 'icon' => 'building', 'label' => 'Parent companies',
          'desc' => 'The operators and chains behind the programs.'),
    array('template' => 'templates/page-network-map.php', 'icon' => 'link', 'label' => 'Network map',
          'desc' => 'Who owns, staffs and refers to whom.'),
    array('template' => 'templates/page-inspection-reports.php', 'icon' => 'clipboard', 'label' => 'Inspection reports',
          'desc' => 'State licensing inspections, searchable by facility.'),
    array('template' => 'templates/page-lawsuits.php', 'icon' => 'scale', 'label' => 'Lawsuits',
          'desc' => 'Cases filed against programs and staff.'),
    array('template' => 'templates/page-legislation.php', 'icon' => 'landmark', 'label' => 'Legislation',
          'desc' => 'Bills that would regulate the industry.'),
    array('template' => 'templates/page-news-feed.php', 'icon' => 'newspaper', 'label' => 'News coverage',
          'desc' => 'Reporting on the TTI, newest first.'),
    array('template' => 'templates/page-referrer-index.php', 'icon' => 'users', 'label' => 'Referrers',
          'desc' => 'Educational consultants who place kids in programs.'),
    array('template' => 'templates/page-transporter-index.php', 'icon' => 'van', 'label' => 'Transport companies',
          'desc' => 'Services hired to take teens to programs.'),
    array('template' => 'templates/page-glossary.php', 'icon' => 'book', 'label' => 'Glossary',
          'desc' => 'The terms programs use, explained.'),
    array('template' => 'templates/page-report-abuse.php', 'icon' => 'shield', 'label' => 'Where to report abuse',
          'desc' => 'The agencies to contact in each state.'),
);
foreach ($kop_topics as $i => $topic) {
    if (empty($topic['href'])) {
        $kop_topics[$i]['href'] = kop_home_existing_page_url($topic['template']);
    }
}
$kop_topics = array_filter($kop_topics, function ($topic) {
    return $topic['href'] !== '';
});

// State inspection trackers currently available (tracker slug => state name),
// from the one list in inc/utilities.php that the hub page reads too.
$kop_report_states = function_exists('kop_report_state_links') ? kop_report_state_links() : array();
// Linked only when the hub page actually exists, so a site that has not run
// the page seeding yet never shows a link into a 404.
$kop_reports_hub_pages = get_pages(array(
    'meta_key'   => '_wp_page_template',
    'meta_value' => 'templates/page-inspection-reports.php',
    'number'     => 1,
));
$kop_reports_hub_url = !empty($kop_reports_hub_pages) ? get_permalink($kop_reports_hub_pages[0]->ID) : '';
?>

<div class="kop-home">

    <?php if (has_post_thumbnail()): ?>
        <div class="kop-home-banner"><?php the_post_thumbnail('full'); ?></div>
    <?php endif; ?>

    <?php kop_ie_html_start('home:hero', 'Home page: opening and audience buttons'); ?>
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
    <?php kop_ie_html_end(); ?>

    <section class="kop-home-search-section">
        <form role="search" method="get" class="kop-home-search" action="<?php echo esc_url(home_url('/')); ?>">
            <label class="screen-reader-text" for="kop-home-search-input">Search the site</label>
            <input type="search" id="kop-home-search-input" name="s" value=""
                   placeholder="Search facilities, news, reports&hellip;">
            <button type="submit">Search</button>
        </form>
    </section>

    <?php kop_ie_html_start('home:mission', 'Home page: mission'); ?>
    <section class="kop-home-mission">
        <p><strong>We are a collaborative of Troubled Teen Industry (TTI) survivors and advocates.
        Our mission is to educate the public about the current and historical dangers of the TTI,
        in pursuit of the ultimate goal of keeping all children safe from abuse.</strong>
        We are not affiliated with any political party, group, or candidate.</p>
    </section>
    <?php kop_ie_html_end(); ?>

    <?php if ($kop_show_numbers): ?>
    <section class="kop-home-numbers" aria-label="By the numbers">
        <div class="kop-number">
            <span class="kop-number-value"><?php echo esc_html(number_format($kop_numbers['reports'])); ?></span>
            <span class="kop-number-label">Inspection reports</span>
        </div>
        <div class="kop-number">
            <span class="kop-number-value"><?php echo esc_html(number_format($kop_numbers['inspected'])); ?></span>
            <span class="kop-number-label">Facilities with reports</span>
            <?php if ($kop_numbers['licensed'] > $kop_numbers['inspected']): ?>
                <span class="kop-number-note">of <?php echo esc_html(number_format($kop_numbers['licensed'])); ?> licensed facilities in our inspection data</span>
            <?php endif; ?>
        </div>
        <div class="kop-number">
            <span class="kop-number-value"><?php echo esc_html(number_format($kop_numbers['directory'])); ?></span>
            <span class="kop-number-label">Facility records in the directory</span>
        </div>
    </section>
    <?php endif; ?>

    <?php
    // Ongoing Stories — the big developing stories (news story arcs).
    // Renders nothing until arcs exist, so it's safe from day one.
    if (function_exists('kop_ongoing_stories_shortcode')) {
        echo kop_ongoing_stories_shortcode(array('limit' => 4));
    }
    ?>

    <?php if ($kop_memorial): ?>
    <section class="kop-home-memorial">
        <a class="kop-memorial-card" href="<?php echo esc_url(get_permalink($kop_memorial->ID)); ?>">
            <?php kop_ie_html_start('home:memorial', 'Home page: memorial card'); ?>
            <h2>In Loving Memory</h2>
            <p>Remembering the children whose deaths in the Troubled Teen Industry were preventable.
            We grieve them today and every day.</p>
            <?php kop_ie_html_end(); ?>
            <span class="kop-memorial-more">Visit the memorial &raquo;</span>
        </a>
    </section>
    <?php endif; ?>

    <?php if ($kop_bills || $kop_suits): ?>
    <section class="kop-home-previews">
        <?php if ($kop_bills): ?>
        <div class="kop-preview-card">
            <h2<?php echo kop_text_attr('home:legislation-heading', 'Home page: legislation heading'); ?>><?php echo esc_html(kop_text('home:legislation-heading', 'Legislation We\'re Tracking', 'Home page: legislation heading')); ?></h2>
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
            <h2<?php echo kop_text_attr('home:lawsuits-heading', 'Home page: lawsuits heading'); ?>><?php echo esc_html(kop_text('home:lawsuits-heading', 'Lawsuit Tracker', 'Home page: lawsuits heading')); ?></h2>
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

    <?php if ($kop_topics): ?>
    <section class="kop-home-topics" aria-labelledby="kop-home-topics-title">
        <h2 id="kop-home-topics-title"<?php echo kop_text_attr('home:topics-heading', 'Home page: topics heading'); ?>><?php echo esc_html(kop_text('home:topics-heading', 'Browse by Topic', 'Home page: topics heading')); ?></h2>
        <p<?php echo kop_text_attr('home:topics-text', 'Home page: topics line'); ?>><?php echo esc_html(kop_text('home:topics-text', 'Pick a kind of record to start from, or use the search above.', 'Home page: topics line')); ?></p>
        <div class="kop-topics-grid">
            <?php foreach ($kop_topics as $topic): ?>
                <a class="kop-topic-link" href="<?php echo esc_url($topic['href']); ?>">
                    <?php if (function_exists('kop_icon')) echo kop_icon($topic['icon'], array('class' => 'kop-topic-icon')); ?>
                    <span class="kop-topic-text">
                        <?php $kop_tk = 'home:topic-' . sanitize_title($topic['label']); ?>
                        <span class="kop-topic-label"<?php echo kop_text_attr($kop_tk . '-label', 'Home page: topic label'); ?>><?php echo esc_html(kop_text($kop_tk . '-label', $topic['label'], 'Home page: topic label')); ?></span>
                        <span class="kop-topic-desc"<?php echo kop_text_attr($kop_tk . '-desc', 'Home page: topic description'); ?>><?php echo esc_html(kop_text($kop_tk . '-desc', $topic['desc'], 'Home page: topic description')); ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="kop-home-reports">
        <h2<?php echo kop_text_attr('home:reports-heading', 'Home page: inspection reports heading'); ?>><?php echo esc_html(kop_text('home:reports-heading', 'New Inspection Reports Available!', 'Home page: inspection reports heading')); ?></h2>
        <p><span<?php echo kop_text_attr('home:reports-before', 'Home page: inspection reports line'); ?>><?php echo esc_html(kop_text('home:reports-before', 'We created inspection trackers to make it easier for everyone to monitor violations in facilities for kids.', 'Home page: inspection reports line')); ?></span> <?php echo esc_html(kop_report_state_sentence()); ?> are available now.
        <span<?php echo kop_text_attr('home:reports-after', 'Home page: inspection reports line'); ?>><?php echo esc_html(kop_text('home:reports-after', 'More trackers are coming soon!', 'Home page: inspection reports line')); ?></span></p>
        <div class="kop-home-reports-buttons">
            <?php foreach ($kop_report_states as $slug => $label): ?>
                <a class="kop-home-report-btn" href="/<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </div>
        <?php if ($kop_reports_hub_url !== ''): ?>
            <a class="kop-volunteer-more" href="<?php echo esc_url($kop_reports_hub_url); ?>">All inspection reports &raquo;</a>
        <?php endif; ?>
    </section>

    <section class="kop-home-map" id="kop-home-map">
        <?php
        // Interactive Geo Maps block, rendered via the plugin's shortcode.
        if (shortcode_exists('display-map')) {
            echo do_shortcode('[display-map id="592"]');
        }
        ?>
        <?php kop_ie_html_start('home:map-text', 'Home page: map text'); ?>
        <p>Residential facilities providing religious, behavioral, and mental health treatment to
        children exist all over the world. Some of these facilities are known to be part of the
        Troubled Teen Industry, while others have not yet been verified. Any facility where children
        live and receive care requires additional oversight. This directory helps communities monitor
        local programs and advocate for accountability. Click your home state for a list of
        facilities near you, or <a href="/international">click here for international programs.</a></p>
        <?php kop_ie_html_end(); ?>
    </section>

    <section class="kop-home-volunteer">
        <?php kop_ie_html_start('home:volunteer', 'Home page: ways to help'); ?>
        <h2>Ways to Help Right Now</h2>
        <p>Every tool below has a built-in tutorial — no experience needed.</p>
        <?php kop_ie_html_end(); ?>
        <div class="kop-volunteer-grid">
            <?php foreach ($kop_volunteer_links as $vl): ?>
                <a class="kop-volunteer-link" href="<?php echo esc_url($vl['url']); ?>">
                    <?php $kop_vk = 'home:help-' . sanitize_title($vl['label']); ?>
                    <span class="kop-volunteer-label"<?php echo kop_text_attr($kop_vk . '-label', 'Home page: tool label'); ?>><?php echo esc_html(kop_text($kop_vk . '-label', $vl['label'], 'Home page: tool label')); ?></span>
                    <span class="kop-volunteer-desc"<?php echo kop_text_attr($kop_vk . '-desc', 'Home page: tool description'); ?>><?php echo esc_html(kop_text($kop_vk . '-desc', $vl['desc'], 'Home page: tool description')); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <a class="kop-volunteer-more" href="/volunteer/">More ways to volunteer &raquo;</a>
    </section>

    <?php kop_ie_html_start('home:help', 'Home page: contact line'); ?>
    <section class="kop-home-help">
        <p>Want to help? Contact
        <a href="mailto:dani@kidsoverprofits.org">dani@kidsoverprofits.org</a>
        for volunteer opportunities or
        <a href="/donate/">click here to help fund our mission.</a></p>
    </section>
    <?php kop_ie_html_end(); ?>

    <?php if (shortcode_exists('addtoany')): ?>
        <div class="kop-home-share"><?php echo do_shortcode('[addtoany]'); ?></div>
    <?php endif; ?>

</div>

<?php
get_footer();
