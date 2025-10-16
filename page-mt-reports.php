<?php
/**
 * Template for the Montana facility reports page.
 *
 * @package Kids_Over_Profits
 */

get_header();

$theme_base = kidsoverprofits_normalize_theme_base_uri(get_stylesheet_directory_uri());
printf('<div data-kop-theme-base="%s" style="display:none;"></div>', esc_attr($theme_base));


?>

<main id="primary" class="site-main site-main--facility-report site-main--mt-reports">
    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : the_post(); ?>
            <?php
            get_template_part(
                'template-parts/content',
                'facility-report',
                array(
                    'slug'            => 'mt-reports',
                    'intro_html'      => kidsoverprofits_get_facility_report_intro('mt-reports'),
                    'sort_options'    => kidsoverprofits_get_facility_report_sort_options('mt-reports'),
                    'clear_label'     => __('Clear Search', 'kidsoverprofits'),
                    'loading_message' => __('Loading report data...', 'kidsoverprofits'),
                )
            );
            ?>
        <?php endwhile; ?>
    <?php endif; ?>
</main>

<?php
get_footer();
