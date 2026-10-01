<?php
/**
 * Template Name: Glossary
 * Description: The TTI glossary. The language of the Troubled Teen Industry,
 * from program handbooks, staff manuals, state records and survivor accounts.
 *
 * Everything below the title comes from js/data/glossary/glossary.json through
 * inc/glossary.php. The page's own editor content, if any, is printed above
 * the glossary, so a note can be added in wp-admin without touching this file.
 *
 * ?program=<slug> and ?q=<words> filter the list; the form is a plain GET
 * form, so it works with JavaScript off, and js/glossary.js makes both
 * filters instant and keeps the URL in step so a filtered view can be shared.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_gl_css = get_stylesheet_directory() . '/css/glossary.css';
if (file_exists($kop_gl_css)) {
    wp_enqueue_style(
        'kop-glossary',
        get_stylesheet_directory_uri() . '/css/glossary.css',
        array('kop-colors'),
        filemtime($kop_gl_css)
    );
}

$kop_gl_js = get_stylesheet_directory() . '/js/glossary.js';
if (file_exists($kop_gl_js)) {
    wp_enqueue_script(
        'kop-glossary',
        get_stylesheet_directory_uri() . '/js/glossary.js',
        array(),
        filemtime($kop_gl_js),
        true
    );
}

// Guided tour (js/glossary-tutorial.js) on the shared tutorial overlay.
$kop_gl_tour_css = get_stylesheet_directory() . '/css/tutorial-overlay.css';
$kop_gl_tour_base = get_stylesheet_directory() . '/js/tutorial-overlay.js';
$kop_gl_tour_js = get_stylesheet_directory() . '/js/glossary-tutorial.js';
if (file_exists($kop_gl_tour_css) && file_exists($kop_gl_tour_base) && file_exists($kop_gl_tour_js)) {
    wp_enqueue_style('kop-tutorial-overlay-style', get_stylesheet_directory_uri() . '/css/tutorial-overlay.css', array(), filemtime($kop_gl_tour_css));
    wp_enqueue_script('kop-tutorial-overlay-script', get_stylesheet_directory_uri() . '/js/tutorial-overlay.js', array('jquery'), filemtime($kop_gl_tour_base), true);
    wp_enqueue_script('kop-glossary-tutorial', get_stylesheet_directory_uri() . '/js/glossary-tutorial.js', array('kop-tutorial-overlay-script', 'kop-glossary'), filemtime($kop_gl_tour_js), true);
}

get_header();

/* Both filters are only ever compared against the built file, never printed
 * unescaped, so an unknown ?program= simply shows everything. */
$kop_gl_program = isset($_GET['program']) ? sanitize_title(wp_unslash($_GET['program'])) : '';
$kop_gl_query   = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
$kop_gl_query   = function_exists('mb_substr') ? mb_substr($kop_gl_query, 0, 100) : substr($kop_gl_query, 0, 100);
?>

<div class="kop-gl-page">

    <header class="kop-gl-header">
        <h1 class="kop-gl-title"><?php the_title(); ?></h1>
        <?php if (has_excerpt()) : ?>
            <p class="kop-gl-summary"><?php echo esc_html(get_the_excerpt()); ?></p>
        <?php endif; ?>
    </header>

    <?php
    while (have_posts()) :
        the_post();
        if (trim(get_the_content()) !== '') :
            ?>
            <div class="kop-gl-editor-content"><?php the_content(); ?></div>
            <?php
        endif;
    endwhile;
    ?>

    <?php kop_glossary_render_page($kop_gl_program, $kop_gl_query, get_permalink()); ?>

</div>

<?php
get_footer();
