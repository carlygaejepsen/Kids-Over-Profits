<?php
/**
 * Ajax Search Lite integration.
 *
 * Injects KOP database records into the live-search dropdown rendered by the
 * Ajax Search Lite plugin. The plugin only searches WP posts/pages; this hooks
 * its `asl_results` filter to append matches from the master tables, wiki
 * submissions, inspection facilities, and news submissions — mirroring the
 * sections on the full search results page (search.php).
 *
 * Only runs on the plugin's AJAX requests: the non-AJAX path converts result
 * ids back into WP_Post objects, which would drop or break injected rows.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('asl_results', 'kop_asl_inject_database_results', 10, 4);

function kop_asl_inject_database_results($results, $search_id, $is_ajax, $args) {
    if (!$is_ajax || !is_array($results)) {
        return $results;
    }

    $phrase = isset($args['s']) ? trim((string) $args['s']) : '';
    if (strlen($phrase) < 2) {
        return $results;
    }

    foreach (kop_asl_collect_database_matches($phrase) as $i => $item) {
        $r          = new stdClass();
        $r->id      = -1 * ($i + 1); // negative: never collides with a real post ID
        $r->blogid  = get_current_blog_id();
        $r->post_type = 'kop_database';
        $r->title   = $item['title'];
        $r->link    = $item['link'];
        $r->content = $item['meta'];
        $r->excerpt = $item['meta'];
        $r->date    = '';
        $r->author  = '';
        $r->image   = '';
        $results[]  = $r;
    }

    return $results;
}

/**
 * Gather dropdown-sized result sets from the KOP tables.
 * Returns arrays of ['title' => ..., 'link' => ..., 'meta' => ...].
 */
function kop_asl_collect_database_matches($phrase) {
    global $wpdb;

    $items = array();
    $like  = '%' . $wpdb->esc_like($phrase) . '%';

    // --- Master tables -----------------------------------------------------
    $facilities_table = null;
    if (function_exists('kop_discover_facilities_master_table') && function_exists('kop_get_facilities_database_connection')) {
        $facilities_table = kop_discover_facilities_master_table(kop_get_facilities_database_connection());
    }

    $master_tables = array(
        array('table' => $facilities_table,      'label' => 'Facility record',    'template' => 'page-tti-program-index.php', 'limit' => 4),
        array('table' => 'referrers_master',     'label' => 'Referrer',           'template' => 'page-referrer-index.php',    'limit' => 2),
        array('table' => 'transporters_master',  'label' => 'Transporter',        'template' => 'page-transporter-index.php', 'limit' => 2),
        array('table' => 'locations_master',     'label' => 'Location',           'template' => 'page-location-index.php',    'limit' => 2),
    );

    foreach ($master_tables as $cfg) {
        if (!$cfg['table'] || !kop_asl_table_exists($cfg['table'])) {
            continue;
        }
        $index_url = kop_asl_page_url_by_template($cfg['template']);
        $rows      = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT unique_name, json_data FROM `{$cfg['table']}` WHERE unique_name LIKE %s OR json_data LIKE %s LIMIT %d",
                $like,
                $like,
                $cfg['limit']
            ),
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $data = json_decode($row['json_data'], true);
            if (isset($data['__facility_ref'])) {
                continue; // promotion stub
            }
            $inner    = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : $data;
            $operator = isset($inner['operator']['name']) && is_string($inner['operator']['name']) ? $inner['operator']['name'] : '';
            $display  = $operator !== '' ? $operator : $row['unique_name'];
            $items[]  = array(
                'title' => $display,
                'link'  => $index_url ? add_query_arg('search', rawurlencode($display), $index_url) : home_url('/?s=' . rawurlencode($phrase)),
                'meta'  => $cfg['label'],
            );
        }
    }

    // --- Wiki submissions --------------------------------------------------
    if (kop_asl_table_exists('wiki_submissions')) {
        $wiki_url = kop_asl_page_url_by_template('page-wiki-feed.php');
        $rows     = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT program_name, organization, city_state FROM wiki_submissions
                 WHERE status IN ('approved','published')
                   AND (program_name LIKE %s OR organization LIKE %s OR city_state LIKE %s)
                 ORDER BY created_at DESC LIMIT 2",
                $like, $like, $like
            ),
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $items[] = array(
                'title' => $row['program_name'],
                'link'  => $wiki_url ? add_query_arg('search', rawurlencode($row['program_name']), $wiki_url) : home_url('/?s=' . rawurlencode($phrase)),
                'meta'  => trim('Wiki entry' . ($row['city_state'] ? ' · ' . $row['city_state'] : '')),
            );
        }
    }

    // --- Inspection facilities --------------------------------------------
    if (kop_asl_table_exists('inspection_facilities')) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT facility_name, state FROM inspection_facilities
                 WHERE facility_name LIKE %s OR program_name LIKE %s
                 ORDER BY facility_name LIMIT 3",
                $like, $like
            ),
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $state      = strtoupper((string) $row['state']);
            $state_page = $state ? get_page_by_path(strtolower($state) . '-reports') : null;
            if (!$state_page) {
                continue; // no public page for this state's reports
            }
            $items[] = array(
                'title' => $row['facility_name'],
                'link'  => add_query_arg('search', rawurlencode($row['facility_name']), get_permalink($state_page)),
                'meta'  => 'Inspection records · ' . $state,
            );
        }
    }

    // --- News submissions --------------------------------------------------
    if (kop_asl_table_exists('news_submissions')) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT article_title, alternate_title, article_url, publication_name FROM news_submissions
                 WHERE status IN ('approved','published')
                   AND (article_title LIKE %s OR alternate_title LIKE %s OR facilities_mentioned LIKE %s)
                 ORDER BY publication_date DESC LIMIT 2",
                $like, $like, $like
            ),
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $title = !empty($row['alternate_title']) ? $row['alternate_title'] : $row['article_title'];
            if (empty($row['article_url'])) {
                continue;
            }
            $items[] = array(
                'title' => $title,
                'meta'  => trim('News' . ($row['publication_name'] ? ' · ' . $row['publication_name'] : '')),
                'link'  => $row['article_url'],
            );
        }
    }

    return $items;
}

function kop_asl_table_exists($table) {
    global $wpdb;
    static $cache = array();
    if (!array_key_exists($table, $cache)) {
        $cache[$table] = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
    }
    return $cache[$table];
}

function kop_asl_page_url_by_template($template_basename) {
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
