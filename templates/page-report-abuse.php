<?php
/**
 * Template Name: Report Abuse
 * Description: The reporting directory. Where somebody reports an abusive
 * therapist or an abusive program, state by state, both to the bodies that
 * hold the licence and to the ones that can open a case.
 *
 * The page's own editor content, if any, is printed above the directory, so
 * an introduction can be written in wp-admin without touching this file.
 * Everything below it comes from js/data/reporting/directory.json through
 * inc/reporting-directory.php.
 *
 * ?state=utah selects a state. The picker is a plain GET form, so the page
 * works with JavaScript off; js/report-abuse.js upgrades it to an instant
 * switch and pushes the same URL into the history, which keeps the link
 * shareable either way.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_rep_css = get_stylesheet_directory() . '/css/report-abuse.css';
if (file_exists($kop_rep_css)) {
    wp_enqueue_style(
        'kop-report-abuse',
        get_stylesheet_directory_uri() . '/css/report-abuse.css',
        array('kop-colors'),
        filemtime($kop_rep_css)
    );
}

$kop_rep_js = get_stylesheet_directory() . '/js/report-abuse.js';
if (file_exists($kop_rep_js)) {
    wp_enqueue_script(
        'kop-report-abuse',
        get_stylesheet_directory_uri() . '/js/report-abuse.js',
        array(),
        filemtime($kop_rep_js),
        true
    );
}

get_header();

/* Only the slug is taken from the query string; kop_reporting_state() resolves
 * it against the built file and returns null for anything it does not know,
 * so an unknown ?state= falls through to the national list rather than
 * reaching any output. */
$kop_rep_state = isset($_GET['state']) ? sanitize_title(wp_unslash($_GET['state'])) : '';
?>

<div class="kop-rep-page">

    <header class="kop-rep-header">
        <h1 class="kop-rep-title"><?php the_title(); ?></h1>
        <?php if (has_excerpt()) : ?>
            <p class="kop-rep-summary"><?php echo esc_html(get_the_excerpt()); ?></p>
        <?php endif; ?>
    </header>

    <?php
    while (have_posts()) :
        the_post();
        $kop_rep_content = trim(get_the_content());
        if ($kop_rep_content !== '') :
            ?>
            <div class="kop-rep-editor-content"><?php the_content(); ?></div>
            <?php
        endif;
    endwhile;
    ?>

    <?php kop_reporting_render_page($kop_rep_state); ?>

</div>

<?php
get_footer();
