<?php
/**
 * Template Name: Related Industry
 * Description: A page about an industry outside the troubled teen industry
 * that meets it: foster care, group homes for adults with developmental
 * disabilities, nursing homes, psychiatric hospitals. Also the
 * /related-industries/ index that lists them.
 *
 * These are not troubled teen programs and Kids Over Profits does not track
 * their facilities. Each page says what the industry is, where it meets the
 * troubled teen industry (the same companies, the same practices, the same
 * weak oversight), what investigations have found, where to report abuse,
 * and who to learn from.
 *
 * One template for every page: the words are in js/data/pages/<page slug>.json
 * (registered in kop_page_text_pages()) and are edited in wp-admin at
 * KOP Data Tools > Page Text; this file only lays them out. Every figure is
 * linked where it is used. Change a figure only with its source.
 * Created as drafts so the owner can read them first (kop_tool_page_specs()).
 * Linked from the History hub only in its boxed section about institutional
 * abuse outside the troubled teen industry (kop_hub_config() 'outside').
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_ri_css = get_stylesheet_directory() . '/css/related-industries.css';
if (file_exists($kop_ri_css)) {
    wp_enqueue_style(
        'kop-related-industries',
        get_stylesheet_directory_uri() . '/css/related-industries.css',
        array('kop-colors'),
        filemtime($kop_ri_css)
    );
}

$kop_ri_slug  = (string) get_post_field('post_name', get_the_ID());
$kop_ri_known = function_exists('kop_page_text_page') && kop_page_text_page($kop_ri_slug);
$kop_ri_index = $kop_ri_slug === 'related-industries';

get_header();
?>

<div class="kop-ri-page">

    <header class="kop-ri-header">
        <p class="kop-ri-tag">Not the troubled teen industry</p>
        <h1 class="kop-ri-title"><?php the_title(); ?></h1>
        <?php if (has_excerpt()) : ?>
            <p class="kop-ri-summary"><?php echo esc_html(get_the_excerpt()); ?></p>
        <?php endif; ?>
    </header>

    <?php
    if ($kop_ri_known) {
        kop_page_text_render($kop_ri_slug, array('only' => array('note')));
    }

    while (have_posts()) :
        the_post();
        $kop_ri_content = trim(get_the_content());
        if ($kop_ri_content !== '') :
            ?>
            <div class="kop-ri-editor-content"><?php the_content(); ?></div>
            <?php
        endif;
    endwhile;

    if ($kop_ri_known) {
        kop_page_text_render($kop_ri_slug, array('skip' => array('note')));
    }

    $kop_ri_parent = !$kop_ri_index && function_exists('kop_article_page') ? kop_article_page('related-industries') : null;
    if ($kop_ri_parent) :
        ?>
        <p class="kop-ri-back"><a href="<?php echo esc_url($kop_ri_parent['url']); ?>">Other industries that meet the troubled teen industry</a></p>
        <?php
    endif;
    ?>

</div>

<?php
get_footer();
