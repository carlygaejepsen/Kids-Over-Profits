<?php
/**
 * Template Name: Document Archive
 * Description: The front door to the document library: featured reports,
 * collections by type, the networks with the largest archives, every program
 * A to Z and the newest additions. ?collection=<folder id> opens one
 * collection in place. The work is inc/document-archive.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_da_folder = function_exists('kop_doc_archive_requested_folder') ? kop_doc_archive_requested_folder() : null;

get_header();

while (have_posts()) :
    the_post();
    ?>
<article id="post-<?php the_ID(); ?>" <?php post_class('entry content-bg single-entry kop-doc-archive'); ?>>
    <div class="entry-content-wrap">

        <nav class="kop-article-trail" aria-label="Breadcrumb"><ol>
            <li><a href="<?php echo esc_url(home_url('/')); ?>">Home</a></li>
            <?php if ($kop_da_folder) : ?>
                <li><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></li>
                <li aria-current="page"><?php echo esc_html($kop_da_folder['name']); ?></li>
            <?php else : ?>
                <li aria-current="page"><?php the_title(); ?></li>
            <?php endif; ?>
        </ol></nav>

        <header class="entry-header kop-da-header">
            <?php if ($kop_da_folder) : ?>
                <p class="kop-da-eyebrow"><?php the_title(); ?></p>
                <h1 class="entry-title"><?php echo esc_html($kop_da_folder['name']); ?></h1>
            <?php else : ?>
                <h1 class="entry-title"><?php the_title(); ?></h1>
                <p class="kop-da-standfirst"><?php
                    echo esc_html(has_excerpt()
                        ? get_the_excerpt()
                        : 'The paper trail of the troubled teen industry: government investigations, court filings, research, and the programs\' own handbooks, brochures and newsletters.');
                ?></p>
            <?php endif; ?>
        </header>

        <div class="entry-content single-content">
            <?php
            if ($kop_da_folder) {
                kop_doc_archive_render_collection($kop_da_folder);
            } else {
                // Editor content (an introduction, a notice) prints first; the
                // page's old FileBird blocks are skipped (inc/document-archive.php).
                the_content();
                kop_doc_archive_render_landing();
            }
            ?>
        </div>

        <?php edit_post_link('Edit this page', '<footer class="kop-da-footer">', '</footer>'); ?>

    </div>
</article>
    <?php
endwhile;

get_footer();
