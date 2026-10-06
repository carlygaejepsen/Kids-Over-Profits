<?php
/**
 * Template Name: Young Adult Programs
 * Description: Programs for people 18 and older (transitional living,
 * college support, wilderness, treatment), listed apart from the troubled
 * teen industry's facilities. Records come from young_adult_programs
 * (inc/young-adult-programs.php), managed at KOP Tools > Young Adult
 * Programs and filled from the Young adult programs (18+) tab of Woodbury
 * Facts; every fact cites the Woodbury Reports page it comes from.
 *
 * Text typed into the page in the WordPress editor replaces the opening
 * paragraph below. Published (it started as a draft so the owner could read
 * it first).
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_ya_css = get_stylesheet_directory() . '/css/young-adult-programs.css';
if (file_exists($kop_ya_css)) {
    wp_enqueue_style('kop-young-adult-programs', get_stylesheet_directory_uri() . '/css/young-adult-programs.css', array('kop-colors'), filemtime($kop_ya_css));
}

get_header();
?>

<div class="kop-ya-page">
    <header class="kop-ya-header">
        <h1 class="kop-ya-title"><?php the_title(); ?></h1>
    </header>

    <?php
    $kop_ya_content = '';
    while (have_posts()) {
        the_post();
        $kop_ya_content = trim(get_the_content());
        if ($kop_ya_content !== '') {
            echo '<div class="kop-ya-intro">';
            the_content();
            echo '</div>';
        }
    }
    if ($kop_ya_content === '') :
        ?>
        <div class="kop-ya-intro">
            <p>Programs for people 18 and older: transitional living, college support, wilderness, therapeutic and substance use programs.
                They are listed apart from the troubled teen industry's programs because the people in them are adults, but many are run by the same
                companies and staff as teen programs, or take young people straight from them.</p>
            <p>Each fact below cites the issue and page of Woodbury Reports, the industry newsletter, that it comes from.</p>
        </div>
        <?php
    endif;

    if (function_exists('kop_ya_render_public')) {
        kop_ya_render_public();
    }
    ?>
</div>

<?php
get_footer();
