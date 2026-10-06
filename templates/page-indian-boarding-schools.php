<?php
/**
 * Template Name: Indian Boarding Schools and Residential Schools
 * Description: A short page on the Indian boarding schools of the United
 * States and the Indian residential schools of Canada: why they are not the
 * same as the troubled teen industry, where the two histories touch, and,
 * first of all, where to learn from the survivors and Nations whose history
 * it is.
 *
 * This is not Kids Over Profits' story. The page says so before anything
 * else, and most of it is directions to Indigenous-led organizations. It
 * carries no survivor testimony, no photographs of children and no retelling
 * of anyone's account; those belong where survivors tell them.
 *
 * The text is in js/data/pages/indian-boarding-schools.json and is edited
 * in wp-admin (KOP Data Tools > Page Text); this file only lays it out.
 * Every figure is from a government, a court, the TRC/NCTR, NABS or NICWA,
 * and linked where it is used. Change a figure only with its source.
 * Published (it started as a draft so the owner could read it first).
 * Not yet linked from the History hub (inc/hub-shell.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_ibs_css = get_stylesheet_directory() . '/css/indian-boarding-schools.css';
if (file_exists($kop_ibs_css)) {
    wp_enqueue_style(
        'kop-indian-boarding-schools',
        get_stylesheet_directory_uri() . '/css/indian-boarding-schools.css',
        array('kop-colors'),
        filemtime($kop_ibs_css)
    );
}

get_header();
?>

<div class="kop-ibs-page">

    <header class="kop-ibs-header">
        <h1 class="kop-ibs-title"><?php the_title(); ?></h1>
        <?php if (has_excerpt()) : ?>
            <p class="kop-ibs-summary"><?php echo esc_html(get_the_excerpt()); ?></p>
        <?php endif; ?>
    </header>

    <?php
    /* The words are in js/data/pages/indian-boarding-schools.json, edited in
     * wp-admin at KOP Data Tools > Page Text (inc/page-text.php). */
    kop_page_text_render('indian-boarding-schools', array('only' => array('note')));

    while (have_posts()) :
        the_post();
        $kop_ibs_content = trim(get_the_content());
        if ($kop_ibs_content !== '') :
            ?>
            <div class="kop-ibs-editor-content"><?php the_content(); ?></div>
            <?php
        endif;
    endwhile;

    /* The school records and their articles (inc/indigenous-schools.php),
     * managed at KOP Data Tools > Indigenous Schools. */
    kop_page_text_render('indian-boarding-schools', array(
        'skip'  => array('note'),
        'after' => array('records' => function_exists('kop_ischools_render_public') ? 'kop_ischools_render_public' : null),
    ));
    ?>

</div>

<?php
get_footer();
