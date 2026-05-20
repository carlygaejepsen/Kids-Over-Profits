<?php
/**
 * Search Results Template
 *
 * Overrides Kadence's default search to include results from
 * facilities_master and news_submissions in addition to WP posts/pages.
 */

if (!defined('ABSPATH')) {
    exit;
}

wp_enqueue_style(
    'kop-search-results',
    get_stylesheet_directory_uri() . '/css/search-results.css',
    array('kop-colors'),
    filemtime(get_stylesheet_directory() . '/css/search-results.css')
);

get_header();

$search_query = get_search_query();
$safe_query   = esc_html($search_query);

global $wpdb;
?>

<div class="kop-search-results-page">
    <div class="kop-search-header">
        <h1>Search Results</h1>
        <?php if ($search_query): ?>
            <p class="kop-search-for">Results for: <strong><?php echo $safe_query; ?></strong></p>
        <?php endif; ?>
        <form role="search" method="get" class="kop-search-form" action="<?php echo esc_url(home_url('/')); ?>">
            <input type="search" class="kop-search-input" placeholder="Search facilities, news, pages…"
                   value="<?php echo esc_attr($search_query); ?>" name="s">
            <button type="submit" class="kop-search-submit">Search</button>
        </form>
    </div>

    <?php if (!$search_query): ?>
        <p class="kop-search-empty">Enter a search term above to find facilities, news articles, and site content.</p>
    <?php else: ?>

    <?php
    // -------------------------------------------------------------------------
    // 1. Facilities from facilities_master
    // -------------------------------------------------------------------------
    $facility_results = array();
    $table = kop_discover_facilities_master_table(kop_get_facilities_database_connection());

    if ($table) {
        $like = '%' . $wpdb->esc_like($search_query) . '%';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT unique_name, json_data FROM `{$table}` WHERE unique_name LIKE %s OR json_data LIKE %s LIMIT 20",
                $like,
                $like
            ),
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $data        = json_decode($row['json_data'], true);
            $inner       = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : $data;
            $operator    = isset($inner['operator']['name']) ? $inner['operator']['name'] : '';
            $fac_count   = (isset($inner['facilities']) && is_array($inner['facilities'])) ? count($inner['facilities']) : 0;
            $location    = '';
            if (isset($inner['operator']['headquarters'])) {
                $location = $inner['operator']['headquarters'];
            } elseif (isset($inner['operator']['location'])) {
                $location = $inner['operator']['location'];
            }
            $facility_results[] = array(
                'name'      => $row['unique_name'],
                'operator'  => $operator,
                'location'  => $location,
                'fac_count' => $fac_count,
            );
        }
    }
    ?>

    <?php if (!empty($facility_results)): ?>
    <section class="kop-search-section kop-search-facilities">
        <h2 class="kop-search-section-title">Facilities &amp; Parent Companies</h2>
        <ul class="kop-search-result-list">
            <?php foreach ($facility_results as $r): ?>
            <li class="kop-search-result kop-result-facility">
                <div class="kop-result-main">
                    <span class="kop-result-name"><?php echo esc_html($r['name']); ?></span>
                    <?php if ($r['operator']): ?>
                        <span class="kop-result-meta">Operator: <?php echo esc_html($r['operator']); ?></span>
                    <?php endif; ?>
                    <?php if ($r['location']): ?>
                        <span class="kop-result-meta"><?php echo esc_html($r['location']); ?></span>
                    <?php endif; ?>
                    <?php if ($r['fac_count'] > 0): ?>
                        <span class="kop-result-badge"><?php echo $r['fac_count']; ?> facilit<?php echo $r['fac_count'] === 1 ? 'y' : 'ies'; ?></span>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php
    // -------------------------------------------------------------------------
    // 2. News from news_submissions
    // -------------------------------------------------------------------------
    $news_results = array();

    try {
        $like = '%' . $wpdb->esc_like($search_query) . '%';
        $news_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT article_title, alternate_title, article_url, publication_name,
                        publication_date, summary, facilities_mentioned
                 FROM news_submissions
                 WHERE status IN ('approved','published')
                   AND (article_title LIKE %s OR alternate_title LIKE %s
                        OR summary LIKE %s OR facilities_mentioned LIKE %s)
                 ORDER BY publication_date DESC
                 LIMIT 10",
                $like, $like, $like, $like
            ),
            ARRAY_A
        );
        $news_results = (array) $news_rows;
    } catch (Exception $e) {
        // news_submissions may not exist in all environments
    }
    ?>

    <?php if (!empty($news_results)): ?>
    <section class="kop-search-section kop-search-news">
        <h2 class="kop-search-section-title">News Articles</h2>
        <ul class="kop-search-result-list">
            <?php foreach ($news_results as $article):
                $title   = !empty($article['alternate_title']) ? $article['alternate_title'] : $article['article_title'];
                $pub     = $article['publication_name'] ?: '';
                $date    = $article['publication_date'] ? date('M j, Y', strtotime($article['publication_date'])) : '';
                $summary = $article['summary'] ? wp_trim_words($article['summary'], 25) : '';
            ?>
            <li class="kop-search-result kop-result-news">
                <div class="kop-result-main">
                    <a class="kop-result-name kop-result-link"
                       href="<?php echo esc_url($article['article_url']); ?>"
                       target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html($title); ?>
                    </a>
                    <?php if ($pub || $date): ?>
                        <span class="kop-result-meta">
                            <?php echo esc_html(implode(' &bull; ', array_filter([$pub, $date]))); ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($summary): ?>
                        <p class="kop-result-summary"><?php echo esc_html($summary); ?></p>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php
    // -------------------------------------------------------------------------
    // 3. WordPress posts / pages
    // -------------------------------------------------------------------------
    $wp_results = new WP_Query(array(
        's'              => $search_query,
        'posts_per_page' => 10,
    ));
    ?>

    <?php if ($wp_results->have_posts()): ?>
    <section class="kop-search-section kop-search-posts">
        <h2 class="kop-search-section-title">Site Content</h2>
        <ul class="kop-search-result-list">
            <?php while ($wp_results->have_posts()): $wp_results->the_post(); ?>
            <li class="kop-search-result kop-result-post">
                <div class="kop-result-main">
                    <a class="kop-result-name kop-result-link" href="<?php the_permalink(); ?>">
                        <?php the_title(); ?>
                    </a>
                    <span class="kop-result-meta">
                        <?php echo esc_html(get_post_type_object(get_post_type())->labels->singular_name); ?>
                        <?php if (get_the_date()): ?>&bull; <?php echo esc_html(get_the_date()); ?><?php endif; ?>
                    </span>
                    <?php if (has_excerpt()): ?>
                        <p class="kop-result-summary"><?php echo wp_trim_words(get_the_excerpt(), 25); ?></p>
                    <?php endif; ?>
                </div>
            </li>
            <?php endwhile; wp_reset_postdata(); ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if (empty($facility_results) && empty($news_results) && !$wp_results->have_posts()): ?>
        <div class="kop-search-no-results">
            <p>No results found for <strong><?php echo $safe_query; ?></strong>. Try a different term, or <a href="<?php echo esc_url(get_post_type_archive_link('page')); ?>">browse the site</a>.</p>
        </div>
    <?php endif; ?>

    <?php endif; // end search_query check ?>
</div>

<?php get_footer(); ?>
