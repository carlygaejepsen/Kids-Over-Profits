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
