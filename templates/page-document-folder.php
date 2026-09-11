<?php
/**
 * Template Name: Document Folder
 * Description: Renders a page's own editor content (kept as-is) followed by
 * the FileBird folder that belongs to the page, using the theme's document
 * library shortcode. Which folder is decided by page slug in
 * kop_document_folder_map(), so no page content has to change and the pages
 * that relied on the inactive CatFolders block show their documents again.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_document_folder_map')) {
    /**
     * Page slug => FileBird folder id (the root-level folder for that
     * organization; merge=name pulls in same-named duplicates elsewhere in
     * the tree). Filter 'kop_document_folder_map' to extend.
     */
    function kop_document_folder_map() {
        return apply_filters('kop_document_folder_map', array(
            'document-library-discovery-ranch'     => 4,
            'document-library-hidden-lake-academy' => 3,
            'document-library-montana-academy'     => 1,
            'document-library-natsap'              => 5,
            'document-library-newport-healthcare'  => 194,
        ));
    }
}

$kop_df_slug      = get_post_field('post_name', get_post());
$kop_df_map       = kop_document_folder_map();
$kop_df_folder_id = isset($kop_df_map[$kop_df_slug]) ? (int) $kop_df_map[$kop_df_slug] : (int) get_post_meta(get_the_ID(), 'kop_document_folder_id', true);

get_header();

while (have_posts()) :
    the_post();
    ?>
<article id="post-<?php the_ID(); ?>" <?php post_class('entry content-bg single-entry kop-document-folder-page'); ?>>
    <header class="entry-header kop-df-header">
        <h1 class="entry-title"><?php the_title(); ?></h1>
    </header>

    <div class="entry-content single-content kop-df-body">
        <?php
        // Whatever the editor holds (file lists, intros) prints first, unchanged.
        the_content();

        if ($kop_df_folder_id > 0) {
            echo '<div class="kop-df-folder">';
            echo do_shortcode('[filebird_folder folder_id="' . $kop_df_folder_id . '" merge="name" layout="grid"]');
            echo '</div>';
        } elseif (current_user_can('edit_pages')) {
            echo '<p class="kop-df-missing"><em>No FileBird folder is mapped to this page yet. Add its slug to kop_document_folder_map() in templates/page-document-folder.php, or set a <code>kop_document_folder_id</code> custom field.</em></p>';
        }
        ?>
    </div>
</article>
    <?php
endwhile;

get_footer();
