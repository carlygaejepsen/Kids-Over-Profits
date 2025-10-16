<?php
/**
 * Template for the Utah facility reports page.
 *
 * @package Kids_Over_Profits
 */

get_header();

$theme_base = kidsoverprofits_normalize_theme_base_uri(get_stylesheet_directory_uri());
printf('<div data-kop-theme-base="%s" style="display:none;"></div>', esc_attr($theme_base));


?>

<main id="primary" class="site-main site-main--facility-report site-main--ut-reports">
    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : the_post(); ?>
            <?php
            get_template_part(
                'template-parts/content',
                'facility-report',
                array(
                    'slug'             => 'ut-reports',
                    'intro_html'       => kidsoverprofits_get_facility_report_intro('ut-reports'),
                    'sort_options'     => kidsoverprofits_get_facility_report_sort_options('ut-reports'),
                    'clear_label'      => __('Clear', 'kidsoverprofits'),
                    'loading_message'  => __('Loading Utah facilities data...', 'kidsoverprofits'),
                    'search_placeholder' => __('Search facilities by name or address...', 'kidsoverprofits'),
                    'controls_class'   => 'controls--inline',
                )
            );
            ?>
        <?php endwhile; ?>
    <?php endif; ?>
</main>

<?php
get_footer();
