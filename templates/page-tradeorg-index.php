<?php
/**
 * Template Name: Trade Organizations
 * Description: Associations of programs, consultants, transporters and other industry businesses
 * (NATSAP, the OBH Council, IECA...), from tradeorgs_master (inc/tradeorgs.php), entered on the data
 * forms' Trade Organizations tab. Each lists the programs whose records name it as a membership.
 *
 * Text typed into the page in the WordPress editor replaces the opening paragraph below.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_tro_css = get_stylesheet_directory() . '/css/tradeorg-index.css';
if (file_exists($kop_tro_css)) {
    wp_enqueue_style('kop-tradeorg-index', get_stylesheet_directory_uri() . '/css/tradeorg-index.css', array('kop-colors'), filemtime($kop_tro_css));
}

get_header();
?>

<div class="kop-tro-page">
    <header class="kop-tro-header">
        <h1 class="kop-tro-title"><?php the_title(); ?></h1>
    </header>

    <?php
    $kop_tro_content = '';
    while (have_posts()) {
        the_post();
        $kop_tro_content = trim(get_the_content());
        if ($kop_tro_content !== '') {
            echo '<div class="kop-tro-intro">';
            the_content();
            echo '</div>';
        }
    }
    if ($kop_tro_content === '') :
        ?>
        <div class="kop-tro-intro">
            <p>The industry's trade groups: associations of programs, of the educational consultants who refer families to them, and of the
                companies that serve them. They write members' ethical principles, publish research, run conferences, speak for the industry
                to lawmakers and the press, and give members a logo to show parents.</p>
            <p>Under each one are the programs whose records on this site list it as a membership. Know of a group that belongs here?
                <a href="/tti-data-submission/?add=tradeorg">Tell us about it</a>.</p>
        </div>
        <?php
    endif;

    if (function_exists('kop_tradeorgs_render_public')) {
        kop_tradeorgs_render_public();
    }
    ?>
</div>

<?php
get_footer();
