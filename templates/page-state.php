<?php
/**
 * Template Name: State Page
 * Description: Dynamic per-state hub aggregating facilities, news, lawsuits, and legislation by state slug.
 * Template Post Type: page
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

$post_slug = get_post_field('post_name', get_post());
$state_name = kop_state_slug_to_name($post_slug);

if (!$state_name) {
    echo '<div class="state-page-error" style="max-width:900px;margin:2em auto;padding:1em;border:1px solid #f3c7c3;background:#fff5f4;border-radius:8px;">';
    echo '<h2>State not recognized</h2>';
    echo '<p>The page slug <code>' . esc_html($post_slug) . '</code> does not match a known US state. ';
    echo 'Set the page slug to the state name (e.g. <code>utah</code>, <code>new-york</code>).</p>';
    echo '</div>';
    get_footer();
    return;
}

$state_slug = kop_state_slug($state_name);
$rest_url = esc_url_raw(rest_url('kop/v1/state/' . $state_slug));
?>

<link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/css/state-page.css?v=<?php echo time(); ?>">

<div class="state-page" data-state-slug="<?php echo esc_attr($state_slug); ?>" data-state-name="<?php echo esc_attr($state_name); ?>">
    <header class="state-page-header">
        <h1 class="state-page-title"><?php the_title(); ?></h1>
        <?php if (has_excerpt()): ?>
            <p class="state-page-summary"><?php echo esc_html(get_the_excerpt()); ?></p>
        <?php endif; ?>

        <?php if (!empty(get_the_content())): ?>
            <div class="state-page-intro">
                <?php the_content(); ?>
            </div>
        <?php endif; ?>

        <div class="state-page-counts" id="stateCounts" aria-live="polite">
            <span class="count-pill" data-section="facilities">Facilities <em>—</em></span>
            <span class="count-pill" data-section="news">News <em>—</em></span>
            <span class="count-pill" data-section="lawsuits">Lawsuits <em>—</em></span>
            <span class="count-pill" data-section="legislation">Legislation <em>—</em></span>
        </div>
    </header>

    <nav class="state-page-tabs" role="tablist">
        <button class="state-tab active" data-tab="facilities" role="tab" aria-selected="true">Facilities</button>
        <button class="state-tab" data-tab="news" role="tab">News</button>
        <button class="state-tab" data-tab="lawsuits" role="tab">Lawsuits</button>
        <button class="state-tab" data-tab="legislation" role="tab">Legislation</button>
    </nav>

    <section class="state-section active" id="section-facilities" data-section="facilities" role="tabpanel">
        <h2>Facilities in <?php echo esc_html($state_name); ?></h2>
        <p class="facilities-intro">Residential facilities providing religious, behavioral, and mental health treatment to children exist in all 50 states. Some of these facilities are known to be part of the Troubled Teen Industry, while others have not yet been verified. Any facility where children live and receive care requires additional oversight. This directory helps communities monitor local programs and advocate for accountability.</p>
        <div class="section-content"><p class="loading">Loading facilities…</p></div>
    </section>

    <section class="state-section" id="section-news" data-section="news" role="tabpanel" hidden data-kop-bug-feature="state-hub/news" data-kop-bug-label="State News">
        <h2>News</h2>
        <div class="section-content"><p class="loading">Loading news…</p></div>
    </section>

    <section class="state-section" id="section-lawsuits" data-section="lawsuits" role="tabpanel" hidden data-kop-bug-feature="state-hub/lawsuits" data-kop-bug-label="State Lawsuits">
        <h2>Lawsuits</h2>
        <div class="section-content"><p class="loading">Loading lawsuits…</p></div>
    </section>

    <section class="state-section" id="section-legislation" data-section="legislation" role="tabpanel" hidden data-kop-bug-feature="state-hub/legislation" data-kop-bug-label="State Legislation">
        <h2>Legislation</h2>
        <div class="section-content"><p class="loading">Loading legislation…</p></div>
    </section>
</div>

<script>
window.statePageConfig = {
    stateName: <?php echo wp_json_encode($state_name); ?>,
    stateSlug: <?php echo wp_json_encode($state_slug); ?>,
    apiUrl: <?php echo wp_json_encode($rest_url); ?>,
    folderContentUrl: <?php echo wp_json_encode(esc_url_raw(rest_url('kop/v1/folder-content'))); ?>,
    foldersUrl: <?php echo wp_json_encode(esc_url_raw(rest_url('kop/v1/folders'))); ?>,
    newsSubmitUrl: <?php
        $news_url = '';
        if (class_exists('WP_Query')) {
            $news_query = new WP_Query(array(
                'post_type'      => 'page',
                'posts_per_page' => 1,
                'no_found_rows'  => true,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'     => '_wp_page_template',
                        'value'   => array('templates/page-news-processor.php', 'page-news-processor.php'),
                        'compare' => 'IN',
                    ),
                ),
            ));
            if (!empty($news_query->posts)) {
                $news_url = get_permalink($news_query->posts[0]);
            }
            wp_reset_postdata();
        }
        if (empty($news_url)) {
            $news_page = function_exists('get_page_by_path') ? get_page_by_path('news-processor') : null;
            $news_url = $news_page ? get_permalink($news_page) : home_url('/news-processor/');
        }
        echo wp_json_encode($news_url);
    ?>
};
</script>
<script src="<?php echo get_stylesheet_directory_uri(); ?>/js/submit-info.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo get_stylesheet_directory_uri(); ?>/js/state-page.js?v=<?php echo time(); ?>"></script>

<?php get_footer(); ?>
