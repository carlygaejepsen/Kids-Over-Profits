<?php
/**
 * Template Name: Marketing Companies
 * Description: Companies that market troubled teen programs (websites, search, "independent"
 * directories, help lines, lead selling, reputation work, instructions for AI chatbots), from
 * marketers_master (inc/marketers.php), entered on the data forms' Marketers tab.
 *
 * Text typed into the page in the WordPress editor replaces the opening paragraph below.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_mkt_css = get_stylesheet_directory() . '/css/marketer-index.css';
if (file_exists($kop_mkt_css)) {
    wp_enqueue_style('kop-marketer-index', get_stylesheet_directory_uri() . '/css/marketer-index.css', array('kop-colors'), filemtime($kop_mkt_css));
}

get_header();
?>

<div class="kop-mkt-page">
    <header class="kop-mkt-header">
        <h1 class="kop-mkt-title"><?php the_title(); ?></h1>
    </header>

    <?php
    $kop_mkt_content = '';
    while (have_posts()) {
        the_post();
        $kop_mkt_content = trim(get_the_content());
        if ($kop_mkt_content !== '') {
            echo '<div class="kop-mkt-intro">';
            the_content();
            echo '</div>';
        }
    }
    if ($kop_mkt_content === '') :
        ?>
        <div class="kop-mkt-intro">
            <p>Companies hired to market troubled teen programs. They build the programs' websites, publish pages for towns the programs are not in,
                run directories and help lines that present themselves as independent, sell or trade parents' inquiries, answer critics and reviews,
                and write instructions for AI chatbots.</p>
            <p>How it works: <a href="/how-troubled-teen-programs-market-themselves-online/">How Troubled Teen Programs Market Themselves Online</a>.
                Know of a company that belongs here? <a href="/tti-data-submission/?add=marketer">Tell us about it</a>.</p>
        </div>
        <?php
    endif;

    if (function_exists('kop_marketers_render_public')) {
        kop_marketers_render_public();
    }
    ?>
</div>

<?php
get_footer();
