<?php
/**
 * Search Results Template
 *
 * Overrides Kadence's default search to include results from the KOP
 * database tables (facilities/referrers/transporters/locations masters,
 * wiki_submissions, inspection_facilities, news_submissions) in addition
 * to WP posts/pages. Database-backed index pages render client-side, so
 * their content is invisible to WP core search — this template queries
 * the same tables those pages are hydrated from.
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

/**
 * Check a table exists in the WP database (cached per request).
 */
function kop_search_table_exists($table) {
    global $wpdb;
    static $cache = array();
    if (!array_key_exists($table, $cache)) {
        $cache[$table] = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
    }
    return $cache[$table];
}

/**
 * Columns of a table (cached per request). Lets us build LIKE clauses only
 * from columns that actually exist, so schema drift never breaks the page.
 */
function kop_search_table_columns($table) {
    global $wpdb;
    static $cache = array();
    if (!array_key_exists($table, $cache)) {
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`"); // table name validated by kop_search_table_exists()
        $cache[$table] = is_array($cols) ? $cols : array();
    }
    return $cache[$table];
}

/**
 * Permalink of the page using a given template file (cached per request).
 * Matches with LIKE because templates live in templates/ and the stored
 * meta value includes that path prefix.
 */
function kop_search_page_url_by_template($template_basename) {
    static $cache = array();
    if (array_key_exists($template_basename, $cache)) {
        return $cache[$template_basename];
    }
    $url   = '';
    $pages = get_posts(array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'meta_query'     => array(
            array(
                'key'     => '_wp_page_template',
                'value'   => $template_basename,
                'compare' => 'LIKE',
            ),
        ),
    ));
    if (!empty($pages)) {
        $url = get_permalink($pages[0]->ID);
    }
    $cache[$template_basename] = $url;
    return $url;
}

/**
 * Search a master table (unique_name + json_data LIKE) and normalize rows
 * for display. Skips __facility_ref promotion stubs.
 */
function kop_search_master_table($table, $search_query, $limit = 10) {
    global $wpdb;

    if (!$table || !kop_search_table_exists($table)) {
        return array();
    }

    $like = '%' . $wpdb->esc_like($search_query) . '%';
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT unique_name, json_data FROM `{$table}` WHERE unique_name LIKE %s OR json_data LIKE %s LIMIT %d",
            $like,
            $like,
            $limit
        ),
        ARRAY_A
    );

    $results = array();
    foreach ((array) $rows as $row) {
        $data = json_decode($row['json_data'], true);
        if (isset($data['__facility_ref'])) {
            continue; // promotion stub, not a real record
        }
        $inner    = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : $data;
        $operator = isset($inner['operator']['name']) ? $inner['operator']['name'] : '';
        $location = '';
        if (isset($inner['operator']['headquarters'])) {
            $location = is_string($inner['operator']['headquarters']) ? $inner['operator']['headquarters'] : '';
        } elseif (isset($inner['operator']['location'])) {
            $location = is_string($inner['operator']['location']) ? $inner['operator']['location'] : '';
        }
        $snippet = '';
        if (function_exists('kop_search_in_data') && is_array($inner)) {
            $match = kop_search_in_data($inner, $search_query);
            // Only show the snippet when it adds context beyond the name itself.
            if (is_string($match) && stripos($row['unique_name'], $search_query) === false) {
                $snippet = $match;
            }
        }
        $results[] = array(
            'name'      => $row['unique_name'],
            'display'   => $operator !== '' ? $operator : $row['unique_name'],
            'operator'  => $operator,
            'location'  => $location,
            'fac_count' => (isset($inner['facilities']) && is_array($inner['facilities'])) ? count($inner['facilities']) : 0,
            'snippet'   => $snippet,
        );
    }
    return $results;
}

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
            <input type="search" class="kop-search-input" placeholder="Search facilities, inspections, wiki, news, pages…"
                   value="<?php echo esc_attr($search_query); ?>" name="s">
            <button type="submit" class="kop-search-submit">Search</button>
        </form>
    </div>

    <?php if (!$search_query): ?>
        <p class="kop-search-empty">Enter a search term above to find facilities, inspection records, wiki entries, news articles, and site content.</p>
    <?php else: ?>

    <?php
    // -------------------------------------------------------------------------
    // 1. Master tables: facilities, referrers, transporters, locations
    // -------------------------------------------------------------------------
    $facilities_table = kop_discover_facilities_master_table(kop_get_facilities_database_connection());

    $master_sections = array(
        array(
            'title'    => 'Facilities & Parent Companies',
            'table'    => $facilities_table,
            'template' => 'page-tti-program-index.php',
            'linkable' => true, // program index JS supports ?search= deep links
            'results'  => array(),
        ),
        array(
            'title'    => 'Referrers',
            'table'    => 'referrers_master',
            'template' => 'page-referrer-index.php',
            'linkable' => true,
            'results'  => array(),
        ),
        array(
            'title'    => 'Transporters',
            'table'    => 'transporters_master',
            'template' => 'page-transporter-index.php',
            'linkable' => true,
            'results'  => array(),
        ),
        array(
            'title'    => 'Locations',
            'table'    => 'locations_master',
            'template' => 'page-location-index.php',
            'linkable' => true,
            'results'  => array(),
        ),
    );

    $any_master_results = false;
    foreach ($master_sections as $i => $section) {
        $limit = ($i === 0) ? 20 : 10;
        $master_sections[$i]['results'] = kop_search_master_table($section['table'], $search_query, $limit);
        $master_sections[$i]['url']     = kop_search_page_url_by_template($section['template']);
        if (!empty($master_sections[$i]['results'])) {
            $any_master_results = true;
        }
    }
    ?>

    <?php foreach ($master_sections as $section): ?>
        <?php if (empty($section['results'])) { continue; } ?>
        <section class="kop-search-section kop-search-facilities">
            <h2 class="kop-search-section-title"><?php echo esc_html($section['title']); ?></h2>
            <ul class="kop-search-result-list">
                <?php foreach ($section['results'] as $r):
                    $result_url = '';
                    if ($section['url']) {
                        $result_url = $section['linkable']
                            ? add_query_arg('search', rawurlencode($r['display']), $section['url'])
                            : $section['url'];
                    }
                ?>
                <li class="kop-search-result kop-result-facility">
                    <div class="kop-result-main">
                        <?php if ($result_url): ?>
                            <a class="kop-result-name kop-result-link" href="<?php echo esc_url($result_url); ?>">
                                <?php echo esc_html($r['display']); ?>
                            </a>
                        <?php else: ?>
                            <span class="kop-result-name"><?php echo esc_html($r['display']); ?></span>
                        <?php endif; ?>
                        <?php if ($r['operator'] && $r['operator'] !== $r['display']): ?>
                            <span class="kop-result-meta">Operator: <?php echo esc_html($r['operator']); ?></span>
                        <?php endif; ?>
                        <?php if ($r['location']): ?>
                            <span class="kop-result-meta"><?php echo esc_html($r['location']); ?></span>
                        <?php endif; ?>
                        <?php if ($r['fac_count'] > 0): ?>
                            <span class="kop-result-badge"><?php echo (int) $r['fac_count']; ?> facilit<?php echo $r['fac_count'] === 1 ? 'y' : 'ies'; ?></span>
                        <?php endif; ?>
                        <?php if ($r['snippet']): ?>
                            <p class="kop-result-summary">…<?php echo esc_html($r['snippet']); ?>…</p>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>

    <?php
    // -------------------------------------------------------------------------
    // 2. Wiki submissions (rendered on the wiki feed page)
    // -------------------------------------------------------------------------
    $wiki_results = array();
    $wiki_url     = '';

    if (kop_search_table_exists('wiki_submissions')) {
        $wiki_url  = kop_search_page_url_by_template('page-wiki-feed.php');
        $wiki_cols = kop_search_table_columns('wiki_submissions');
        $searchable = array_values(array_intersect(
            array('program_name', 'organization', 'city_state', 'program_type', 'generated_markdown', 'original_markdown', 'json_data'),
            $wiki_cols
        ));
        if (!empty($searchable)) {
            $like       = '%' . $wpdb->esc_like($search_query) . '%';
            $conditions = implode(' OR ', array_map(function ($col) {
                return "`{$col}` LIKE %s";
            }, $searchable));
            $wiki_results = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM wiki_submissions
                     WHERE status IN ('approved','published') AND ({$conditions})
                     ORDER BY created_at DESC
                     LIMIT 10",
                    array_fill(0, count($searchable), $like)
                ),
                ARRAY_A
            );
        }
    }
    ?>

    <?php if (!empty($wiki_results)): ?>
    <section class="kop-search-section kop-search-wiki">
        <h2 class="kop-search-section-title">Program Wiki Entries</h2>
        <ul class="kop-search-result-list">
            <?php foreach ($wiki_results as $entry):
                $name = isset($entry['program_name']) ? $entry['program_name'] : '';
                $entry_url = ($wiki_url && $name !== '') ? add_query_arg('search', rawurlencode($name), $wiki_url) : $wiki_url;
                $meta = array_filter(array(
                    isset($entry['program_type']) ? $entry['program_type'] : '',
                    isset($entry['organization']) ? $entry['organization'] : '',
                    isset($entry['city_state']) ? $entry['city_state'] : '',
                ));
            ?>
            <li class="kop-search-result kop-result-wiki">
                <div class="kop-result-main">
                    <?php if ($entry_url): ?>
                        <a class="kop-result-name kop-result-link" href="<?php echo esc_url($entry_url); ?>">
                            <?php echo esc_html($name); ?>
                        </a>
                    <?php else: ?>
                        <span class="kop-result-name"><?php echo esc_html($name); ?></span>
                    <?php endif; ?>
                    <?php if ($meta): ?>
                        <span class="kop-result-meta"><?php echo esc_html(implode(' • ', $meta)); ?></span>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php
    // -------------------------------------------------------------------------
    // 3. Inspection records (rendered on the per-state *-reports pages)
    // -------------------------------------------------------------------------
    $inspection_results = array();

    if (kop_search_table_exists('inspection_facilities')) {
        $like = '%' . $wpdb->esc_like($search_query) . '%';
        $inspection_results = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT facility_name, program_name, full_address, state
                 FROM inspection_facilities
                 WHERE facility_name LIKE %s OR program_name LIKE %s OR full_address LIKE %s
                 ORDER BY facility_name
                 LIMIT 15",
                $like, $like, $like
            ),
            ARRAY_A
        );
    }
    ?>

    <?php if (!empty($inspection_results)): ?>
    <section class="kop-search-section kop-search-inspections">
        <h2 class="kop-search-section-title">Inspection Records</h2>
        <ul class="kop-search-result-list">
            <?php foreach ($inspection_results as $fac):
                $state      = strtoupper(isset($fac['state']) ? $fac['state'] : '');
                $state_page = $state ? get_page_by_path(strtolower($state) . '-reports') : null;
                $state_url  = $state_page ? get_permalink($state_page) : '';
                if ($state_url && !empty($fac['facility_name'])) {
                    $state_url = add_query_arg('search', rawurlencode($fac['facility_name']), $state_url);
                }
                $meta       = array_filter(array(
                    isset($fac['program_name']) ? $fac['program_name'] : '',
                    isset($fac['full_address']) ? $fac['full_address'] : '',
                ));
            ?>
            <li class="kop-search-result kop-result-inspection">
                <div class="kop-result-main">
                    <?php if ($state_url): ?>
                        <a class="kop-result-name kop-result-link" href="<?php echo esc_url($state_url); ?>">
                            <?php echo esc_html($fac['facility_name']); ?>
                        </a>
                    <?php else: ?>
                        <span class="kop-result-name"><?php echo esc_html($fac['facility_name']); ?></span>
                    <?php endif; ?>
                    <?php if ($state): ?>
                        <span class="kop-result-badge"><?php echo esc_html($state); ?> inspections</span>
                    <?php endif; ?>
                    <?php if ($meta): ?>
                        <span class="kop-result-meta"><?php echo esc_html(implode(' • ', $meta)); ?></span>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php
    // -------------------------------------------------------------------------
    // 4. News from news_submissions
    // -------------------------------------------------------------------------
    $news_results = array();

    if (kop_search_table_exists('news_submissions')) {
        $like = '%' . $wpdb->esc_like($search_query) . '%';
        $news_results = (array) $wpdb->get_results(
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
    // 5. WordPress posts / pages
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

    <?php if (!$any_master_results && empty($wiki_results) && empty($inspection_results) && empty($news_results) && !$wp_results->have_posts()): ?>
        <div class="kop-search-no-results">
            <p>No results found for <strong><?php echo $safe_query; ?></strong>. Try a different term, or <a href="<?php echo esc_url(get_post_type_archive_link('page')); ?>">browse the site</a>.</p>
        </div>
    <?php endif; ?>

    <?php endif; // end search_query check ?>
</div>

<?php get_footer(); ?>
