<?php
/**
 * Template Name: Data Submission
 * Template for the data submission page.
 *
 * @package Kids_Over_Profits
 */

get_header();

$theme_base = kidsoverprofits_normalize_theme_base_uri(get_stylesheet_directory_uri());
printf('<div data-kop-theme-base="%s" style="display:none;"></div>', esc_attr($theme_base));


?>

<main id="primary" class="site-main site-main--data">
    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                <div class="entry-content">
                    <?php
                    // Include the shared HTML content from the admin-data.html file.
                    // This avoids duplicating hundreds of lines of HTML.
                    $template_path = get_stylesheet_directory() . '/html/admin-data.html';
                    if (file_exists($template_path)) {
                        // Use a function to capture the output of the included file
                        // and modify it before printing.
                        echo kidsoverprofits_get_and_modify_form_template($template_path);
                    } else {
                        echo '<p>Error: Data form template not found.</p>';
                    }
                    ?>
                </div>
            </article>
        <?php endwhile; ?>
    <?php endif; ?>
</main>

<?php
get_footer();
?>
