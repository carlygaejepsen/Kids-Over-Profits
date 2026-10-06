<?php
/**
 * Template Name: Start Here
 * Description: How to use the site, for someone arriving for the first time:
 * how to find a program, what a facility or company page holds, where the
 * site-wide records are, the pages for each audience, how to help, and how
 * to read a record (no reports is not a clean record).
 *
 * The text is in js/data/pages/start-here.json and is edited in wp-admin (KOP
 * Data Tools > Page Text); this file only lays it out. Every section with a
 * heading is listed at the top as a jump link, and a search box sits under the
 * opening note because searching is the first thing the page tells people to do.
 * Created by kop_tool_page_specs(), linked from the home page and the menu.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_start_css = get_stylesheet_directory() . '/css/start-here.css';
if (file_exists($kop_start_css)) {
    wp_enqueue_style(
        'kop-start-here',
        get_stylesheet_directory_uri() . '/css/start-here.css',
        array('kop-colors'),
        filemtime($kop_start_css)
    );
}

$kop_start_sections = kop_page_text_sections('start-here');
$kop_start_toc = array_values(array_filter($kop_start_sections, function ($s) {
    return trim((string) $s['heading']) !== '';
}));

get_header();
?>

<div class="kop-start-page">

    <header class="kop-start-header">
        <h1 class="kop-start-title"><?php the_title(); ?></h1>
        <?php if (has_excerpt()) : ?>
            <p class="kop-start-summary"><?php echo esc_html(get_the_excerpt()); ?></p>
        <?php endif; ?>
    </header>

    <?php kop_page_text_render('start-here', array('only' => array('intro'))); ?>

    <form role="search" method="get" class="kop-start-search" action="<?php echo esc_url(home_url('/')); ?>">
        <label for="kop-start-search-input">Search for a program, company or person</label>
        <div class="kop-start-search-row">
            <input type="search" id="kop-start-search-input" name="s" value=""
                   placeholder="Program, company or former name&hellip;">
            <button type="submit" class="kop-start-search-btn">Search</button>
        </div>
    </form>

    <?php if ($kop_start_toc) : ?>
        <nav class="kop-start-toc" aria-label="On this page">
            <h2 class="kop-start-toc-title">On this page</h2>
            <ul>
                <?php foreach ($kop_start_toc as $s) : ?>
                    <li><a href="#kop-start-<?php echo esc_attr($s['key']); ?>"><?php echo kop_page_text_inline($s['heading'], 'kop-start'); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </nav>
    <?php endif; ?>

    <?php
    while (have_posts()) :
        the_post();
        if (trim(get_the_content()) !== '') :
            ?>
            <div class="kop-start-editor-content"><?php the_content(); ?></div>
            <?php
        endif;
    endwhile;

    kop_page_text_render('start-here', array('skip' => array('intro')));
    ?>

</div>

<?php
get_footer();
