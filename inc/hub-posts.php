<?php
/**
 * Hub pages that list the posts in one category: Editorials and
 * Investigatory Spotlight.
 *
 * Both pages were a bare core/query block and nothing else, on the default
 * template: no heading above the list, no introduction, no route anywhere
 * else, and the Spotlight query stopped at three posts with no pagination.
 * They now use templates/page-hub.php. The module here prints every post in
 * the category as a card, and the page's own query block is skipped so the
 * list is not printed twice. Any other editor content on the page still
 * renders above the module.
 *
 * Page slug => category slug, standfirst, and the one next step a reader is
 * offered. Adding a section is one entry here plus the slug in
 * kop_template_assignments() (inc/admin.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

function kop_hub_post_sections() {
    return array(
        'editorials' => array(
            'category'   => 'editorials',
            'heading'    => 'All editorials',
            'noun'       => 'editorial',
            'standfirst' => 'Essays and guides written by Kids Over Profits about the troubled teen industry and the people it harms.',
            'action'     => array('label' => 'Research & Reports', 'slug' => 'researchreports'),
        ),
        'investigatory-spotlight' => array(
            'category'   => 'investigatory-spotlight',
            'heading'    => 'All investigations',
            'noun'       => 'investigation',
            'standfirst' => 'In-depth investigations into the people, companies and trade groups behind troubled teen programs.',
            'action'     => array('label' => 'Explore the network map', 'slug' => 'network-map'),
        ),
    );
}

/** The section for a page slug, or null. */
function kop_hub_post_section($slug) {
    $sections = kop_hub_post_sections();
    return isset($sections[$slug]) ? $sections[$slug] : null;
}

/** The section of the page being viewed, or null. */
function kop_hub_post_current_section() {
    if (!is_page()) {
        return null;
    }
    return kop_hub_post_section(get_post_field('post_name', get_queried_object_id()));
}

/**
 * Newest first, every published post in the category. The two sections hold
 * a handful of long pieces each, so there is nothing to paginate.
 */
function kop_hub_post_items($category_slug) {
    $term = get_category_by_slug($category_slug);
    if (!$term) {
        return array();
    }
    return get_posts(array(
        'post_type'        => 'post',
        'post_status'      => 'publish',
        'cat'              => (int) $term->term_id,
        'posts_per_page'   => 100,
        'orderby'          => 'date',
        'order'            => 'DESC',
        'no_found_rows'    => true,
        'suppress_filters' => false,
    ));
}

/**
 * "12 min read" from the rendered word count, which is what a reader deciding
 * whether to start a 60,000-character piece wants to know.
 */
function kop_hub_post_reading_time($post) {
    $words = str_word_count(wp_strip_all_tags(strip_shortcodes((string) $post->post_content)));
    return max(1, (int) round($words / 230)) . ' min read';
}

/** A two-sentence summary: the stored excerpt, or the opening of the text. */
function kop_hub_post_summary($post) {
    if (has_excerpt($post)) {
        return get_the_excerpt($post);
    }
    $text = wp_strip_all_tags(excerpt_remove_blocks(strip_shortcodes((string) $post->post_content)));
    return wp_trim_words(html_entity_decode($text, ENT_QUOTES, 'UTF-8'), 40, "\u{2026}");
}

/** The module: one card per post, or a plain empty state. */
function kop_hub_module_category_posts() {
    $slug    = get_post_field('post_name', get_the_ID());
    $section = kop_hub_post_section($slug);
    if (!$section) {
        return;
    }
    $posts  = kop_hub_post_items($section['category']);
    $action = null;
    if (!empty($section['action']['slug'])) {
        $target = get_page_by_path($section['action']['slug']);
        if ($target && $target->post_status === 'publish') {
            $action = array('label' => $section['action']['label'], 'url' => get_permalink($target));
        }
    }
    $count = count($posts);
    ?>
    <section class="kop-hub-module kop-hub-posts" aria-labelledby="kop-hub-posts-h">
        <h2 class="kop-hub-h" id="kop-hub-posts-h">
            <?php echo esc_html($section['heading']); ?>
            <?php if ($count) : ?>
                <span class="kop-hub-count"><?php echo esc_html($count . ' ' . $section['noun'] . ($count === 1 ? '' : 's')); ?></span>
            <?php endif; ?>
        </h2>

        <?php if (!$posts) : ?>
            <p class="kop-hub-empty">Nothing has been published here yet.</p>
        <?php else : ?>
            <ul class="kop-hub-post-list">
                <?php foreach ($posts as $p) :
                    $url   = get_permalink($p);
                    $thumb = get_the_post_thumbnail($p, 'medium_large', array('loading' => 'lazy', 'alt' => ''));
                    ?>
                    <li class="kop-hub-post<?php echo $thumb ? ' has-thumb' : ''; ?>">
                        <?php if ($thumb) : ?>
                            <a class="kop-hub-post-thumb" href="<?php echo esc_url($url); ?>" tabindex="-1" aria-hidden="true"><?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput -- core markup ?></a>
                        <?php endif; ?>
                        <div class="kop-hub-post-body">
                            <h3 class="kop-hub-post-title"><a href="<?php echo esc_url($url); ?>"><?php echo esc_html(get_the_title($p)); ?></a></h3>
                            <p class="kop-hub-meta">
                                <time datetime="<?php echo esc_attr(get_the_date('c', $p)); ?>"><?php echo esc_html(get_the_date('', $p)); ?></time>
                                <?php echo esc_html(" \u{00B7} " . kop_hub_post_reading_time($p)); ?>
                            </p>
                            <p class="kop-hub-post-summary"><?php echo esc_html(kop_hub_post_summary($p)); ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($action) : ?>
            <a class="kop-hub-more" href="<?php echo esc_url($action['url']); ?>"><?php echo esc_html($action['label']); ?></a>
        <?php endif; ?>
    </section>
    <?php
}

add_filter('kop_hub_modules', 'kop_hub_posts_register_modules');
function kop_hub_posts_register_modules($modules) {
    foreach (array_keys(kop_hub_post_sections()) as $slug) {
        $modules[$slug] = 'kop_hub_module_category_posts';
    }
    return $modules;
}

/** Standfirst for these pages when the editor has not written an excerpt. */
add_filter('kop_hub_standfirst', 'kop_hub_posts_standfirst', 10, 2);
function kop_hub_posts_standfirst($standfirst, $slug) {
    $section = kop_hub_post_section($slug);
    return ($standfirst === '' && $section) ? $section['standfirst'] : $standfirst;
}

/**
 * Skip the page's own query block for the same category: the module is the
 * list now. A query block for anything else is left alone.
 */
add_filter('pre_render_block', 'kop_hub_posts_skip_query_block', 10, 2);
function kop_hub_posts_skip_query_block($pre, $block) {
    if ($pre !== null || empty($block['blockName']) || $block['blockName'] !== 'core/query') {
        return $pre;
    }
    $section = kop_hub_post_current_section();
    if (!$section) {
        return $pre;
    }
    $term = get_category_by_slug($section['category']);
    $cats = isset($block['attrs']['query']['taxQuery']['category']) ? (array) $block['attrs']['query']['taxQuery']['category'] : array();
    if ($term && in_array((int) $term->term_id, array_map('intval', $cats), true)) {
        return '';
    }
    return $pre;
}
