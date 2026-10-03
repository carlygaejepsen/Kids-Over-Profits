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

    if (function_exists('kop_v2_active') && kop_v2_active('search')) {
        $v2 = kop_v2_search($phrase, 4, 2, 2);
        $labels = array('operator' => 'Company', 'facility' => 'Facility record', 'place' => 'Location');
        foreach (array_merge($v2['operators'], $v2['facilities'], $v2['places']) as $r) {
            $link = kop_search_v2_result_url($r);
            $alias_hint = function_exists('kop_v2_search_alias_hint') ? kop_v2_search_alias_hint($r) : '';
            $items[] = array(
                'title' => $r['display'],
                'link'  => $link !== '' ? $link : home_url('/?s=' . rawurlencode($phrase)),
                'meta'  => $labels[$r['kind']] . ($r['location'] !== '' ? ' - ' . $r['location'] : '') . ($alias_hint !== '' ? ' (' . $alias_hint . ')' : ''),
            );
        }
        $master_tables = array_values(array_filter($master_tables, function ($cfg) {
            return in_array($cfg['table'], array('referrers_master', 'transporters_master'), true);
        }));
    }

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
            $profile_url = '';
            if ($cfg['table'] === $facilities_table) {
                $profile_url = kop_search_record_page_url($operator === '' ? $row['unique_name'] : $operator);
            }
            $items[] = array(
                'title' => $display,
                'link'  => $profile_url ?: ($index_url ? add_query_arg('search', rawurlencode($display), $index_url) : home_url('/?s=' . rawurlencode($phrase))),
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
            // The program's own page when it has one; else its wiki entry.
            $profile_url = kop_search_record_page_url($row['program_name']);
            $items[] = array(
                'title' => $row['program_name'],
                'link'  => $profile_url !== '' ? $profile_url : ($wiki_url ? add_query_arg('search', rawurlencode($row['program_name']), $wiki_url) : home_url('/?s=' . rawurlencode($phrase))),
                'meta'  => trim('Wiki entry' . ($row['city_state'] ? ' · ' . $row['city_state'] : '')),
            );
        }
    }

    // --- Inspection facilities --------------------------------------------
    if (kop_asl_table_exists('inspection_facilities')) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, facility_name, state FROM inspection_facilities
                 WHERE facility_name LIKE %s OR program_name LIKE %s
                 ORDER BY facility_name LIMIT 3",
                $like, $like
            ),
            ARRAY_A
        );
        foreach ((array) $rows as $row) {
            $state      = strtoupper((string) $row['state']);
            $profile_url = kop_search_inspection_page_url($row['id'], $row['facility_name'], $state);
            $state_page = $state ? get_page_by_path(strtolower($state) . '-reports') : null;
            if ($profile_url === '' && !$state_page) {
                continue; // no public page for this state's reports
            }
            $items[] = array(
                'title' => $row['facility_name'],
                'link'  => $profile_url !== '' ? $profile_url : add_query_arg('search', rawurlencode($row['facility_name']), get_permalink($state_page)),
                'meta'  => 'Inspection records · ' . $state,
            );
        }
    }

    // Report text (kop_global_search_inspection_text_matches) is left out of
    // the as-you-type dropdown: it takes seconds per phrase. The search page
    // and the search bar load it after their other results.

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

/**
 * The facility directory's "By location" tab, which lists every facility
 * (page-tti-program-index.php ?view=location; the separate location index
 * page was folded into it on 2026-09-29 and now redirects there).
 */
function kop_location_index_url() {
    $url = kop_asl_page_url_by_template('page-tti-program-index.php');
    if ($url === '') $url = home_url('/tti-program-index/');
    return add_query_arg('view', 'location', $url);
}

/**
 * Where a kop_v2_search() facility/operator/place result links: its own
 * /facility/ or /operator/ page first, then its state page, then the
 * directory filtered to its name. Shared by the header dropdown, the
 * search bar (inc/global-search.php) and the results page (search.php).
 */
function kop_search_v2_result_url($r) {
    if (!empty($r['profile_url'])) return $r['profile_url'];
    if ($r['kind'] === 'operator' && function_exists('kop_operator_page_url_for_name')) {
        $url = kop_operator_page_url_for_name($r['display']);
        if ($url !== '') return $url;
    }
    if (!empty($r['url'])) return $r['url'];
    $index_url = $r['kind'] === 'operator' ? kop_asl_page_url_by_template('page-tti-program-index.php') : kop_location_index_url();
    return $index_url ? add_query_arg('search', rawurlencode($r['display']), $index_url) : '';
}

/**
 * The /facility/ or /operator/ page for a name found in another table (a
 * wiki entry's program, an inspection row's facility), or ''. With a state
 * code only a facility page in that state counts, so a group home on a state
 * report never links to a same-named program elsewhere.
 */
function kop_search_record_page_url($name, $state = '') {
    $name = trim((string) $name);
    if ($name === '') return '';
    $state = strtolower(trim((string) $state));
    if ($state === '') {
        $url = function_exists('kop_facility_page_url_for_name') ? kop_facility_page_url_for_name($name) : '';
        if ($url === '' && function_exists('kop_operator_page_url_for_name')) $url = kop_operator_page_url_for_name($name);
        return $url;
    }
    if (!function_exists('kop_facility_pages_index') || !function_exists('kop_facility_pages_name_key')) return '';
    $key = kop_facility_pages_name_key($name);
    if ($key === '') return '';
    $index = kop_facility_pages_index();
    foreach ((array) $index['ids'] as $id => $entry) {
        if (kop_facility_pages_name_key($entry['name']) !== $key) continue;
        // Slugs are "<name>-<state>[-<city>][-<id>]" (kop_facility_page_slug_candidate()).
        $prefix = sanitize_title($entry['name']) . '-' . $state;
        $slug = (string) $entry['slug'];
        if ($slug === $prefix || strpos($slug, $prefix . '-') === 0) return kop_facility_page_url((int) $id);
    }
    return '';
}

/**
 * The facility page for an inspection_facilities row: a record an admin
 * linked to it at KOP Tools > Inspection Links, else a record of the same
 * name in the same state, else ''.
 */
function kop_search_inspection_page_url($inspection_id, $facility_name, $state) {
    static $by_row = null;
    if ($by_row === null) {
        $by_row = array();
        if (function_exists('kop_inspection_links_get')) {
            $all = kop_inspection_links_get();
            foreach ($all['links'] as $fid => $rows) {
                foreach ((array) $rows as $row_id) $by_row[(int) $row_id] = (int) $fid;
            }
        }
    }
    $inspection_id = (int) $inspection_id;
    if ($inspection_id > 0 && isset($by_row[$inspection_id]) && function_exists('kop_facility_page_url')) {
        $url = kop_facility_page_url($by_row[$inspection_id]);
        if ($url !== '') return $url;
    }
    return kop_search_record_page_url($facility_name, $state);
}
