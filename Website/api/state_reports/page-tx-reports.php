<?php
/**
 * Template for the Texas facility reports page.
 *
 * @package Kids_Over_Profits
 */

get_header();

?>

<main id="primary" class="site-main site-main--facility-report site-main--tx-reports">
    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : the_post(); ?>
            <?php
            get_template_part(
                'template-parts/content',
                'facility-report',
                array(
                    'slug'            => 'tx-reports',
                    'intro_html'      => kidsoverprofits_get_facility_report_intro('tx-reports'),
                    'sort_options'    => kidsoverprofits_get_facility_report_sort_options('tx-reports'),
                    'clear_label'     => __('Clear Search', 'kidsoverprofits'),
                    'loading_message' => __('Loading Texas provider data...', 'kidsoverprofits'),
                )
            );
            ?>
        <?php endwhile; ?>
    <?php endif; ?>
</main>

<?php
get_footer();
