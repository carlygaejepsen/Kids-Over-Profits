<?php
/**
 * Global search REST endpoint.
 *
 * Backs the site-wide search bar (js/global-search.js). One query fans out
 * across every KOP data source and returns grouped, dropdown-sized results:
 *
 *   - facilities_master / referrers_master / transporters_master / locations_master
 *   - wiki_submissions (approved/published)
 *   - news_submissions (approved/published)
 *   - inspection_facilities (state report pages)
 *   - WP media library attachments (documents)
 *   - {prefix}kop_addresses + {prefix}kop_facility_addresses
 *   - WP pages/posts
 *   - suggested_edits (admins only — requires the wp_rest nonce the widget sends)
 *
 * Reuses kop_asl_table_exists() / kop_asl_page_url_by_template() from
 * inc/ajax-search-lite.php. Full-page results remain search.php (?s=).
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/global-search', array(
        'methods'             => 'GET',
        'callback'            => 'kop_global_search_rest_callback',
        'permission_callback' => '__return_true',
        'args'                => array(
            'q' => array(
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ));
});

function kop_global_search_rest_callback(WP_REST_Request $request) {
    $phrase = trim((string) $request->get_param('q'));

    if (strlen($phrase) < 2) {
        return new WP_REST_Response(array(
            'query'  => $phrase,
            'total'  => 0,
            'groups' => array(),
        ), 200);
    }

    $groups = kop_global_search_collect($phrase);
    $total  = 0;
    foreach ($groups as $group) {
        $total += count($group['items']);
    }

    return new WP_REST_Response(array(
        'query'          => $phrase,
        'total'          => $total,
        'groups'         => $groups,
        'allResultsUrl'  => add_query_arg('s', rawurlencode($phrase), home_url('/')),
    ), 200);
}

/**
 * Fan a phrase out across every data source. Returns non-empty groups only:
 * [ ['key' => ..., 'label' => ..., 'items' => [['title','url','meta'], ...]], ... ]
 */
function kop_global_search_collect($phrase) {
    global $wpdb;

    $like   = '%' . $wpdb->esc_like($phrase) . '%';
    $groups = array();

    // --- Master tables -----------------------------------------------------
    $facilities_table = null;
    if (function_exists('kop_discover_facilities_master_table') && function_exists('kop_get_facilities_database_connection')) {
        $facilities_table = kop_discover_facilities_master_table(kop_get_facilities_database_connection());
    }
    if (!$facilities_table) {
        $facilities_table = 'facilities_master';
    }

    $master_tables = array(
        array('key' => 'facilities',   'label' => 'Facilities & programs', 'table' => $facilities_table,     'template' => 'page-tti-program-index.php', 'limit' => 6),
        array('key' => 'referrers',    'label' => 'Referrers',             'table' => 'referrers_master',    'template' => 'page-referrer-index.php',    'limit' => 4),
        array('key' => 'transporters', 'label' => 'Transporters',          'table' => 'transporters_master', 'template' => 'page-transporter-index.php', 'limit' => 4),
        array('key' => 'locations',    'label' => 'Locations',             'table' => 'locations_master',    'template' => 'page-location-index.php',    'limit' => 4),
    );

    foreach ($master_tables as $cfg) {
        if (!kop_asl_table_exists($cfg['table'])) {
            continue;
        }
        $index_url = kop_asl_page_url_by_template($cfg['template']);
        // Over-fetch: some LIKE hits are __facility_ref stubs that get skipped.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT unique_name, json_data FROM `{$cfg['table']}`
                 WHERE unique_name LIKE %s OR json_data LIKE %s
                 LIMIT %d",
                $like,
                $like,
                $cfg['limit'] * 3
            ),
            ARRAY_A
        );
        $items = array();
        foreach ((array) $rows as $row) {
            if (count($items) >= $cfg['limit']) {
                break;
            }
            $data = json_decode($row['json_data'], true);
            if (isset($data['__facility_ref'])) {
                continue; // promotion stub
            }
            $inner    = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : $data;
            $operator = '';
            if (isset($inner['operator']['name']) && is_string($inner['operator']['name'])) {
                $operator = $inner['operator']['name'];
            } elseif (isset($inner['referrerAgency']['name']) && is_string($inner['referrerAgency']['name'])) {
                $operator = $inner['referrerAgency']['name'];
            }
            $display = $operator !== '' ? $operator : $row['unique_name'];
            $items[] = array(
                'title' => $display,
                'url'   => $index_url ? add_query_arg('search', rawurlencode($display), $index_url) : add_query_arg('s', rawurlencode($display), home_url('/')),
                'meta'  => '',
            );
        }
        if ($items) {
            $groups[] = array('key' => $cfg['key'], 'label' => $cfg['label'], 'items' => $items);
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
                 ORDER BY created_at DESC LIMIT 4",
                $like, $like, $like
            ),
            ARRAY_A
        );
        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = array(
                'title' => $row['program_name'],
                'url'   => $wiki_url ? add_query_arg('search', rawurlencode($row['program_name']), $wiki_url) : add_query_arg('s', rawurlencode($row['program_name']), home_url('/')),
                'meta'  => (string) $row['city_state'],
            );
        }
        if ($items) {
            $groups[] = array('key' => 'wiki', 'label' => 'Wiki entries', 'items' => $items);
        }
    }

    // --- Inspection facilities (state report pages) ------------------------
    if (kop_asl_table_exists('inspection_facilities')) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT facility_name, state FROM inspection_facilities
                 WHERE facility_name LIKE %s OR program_name LIKE %s
                 ORDER BY facility_name LIMIT 8",
                $like, $like
            ),
            ARRAY_A
        );
        $items = array();
        foreach ((array) $rows as $row) {
            if (count($items) >= 5) {
                break;
            }
            $state      = strtoupper((string) $row['state']);
            $state_page = $state ? get_page_by_path(strtolower($state) . '-reports') : null;
            if (!$state_page) {
                continue; // no public page for this state's reports
            }
            $items[] = array(
                'title' => $row['facility_name'],
                'url'   => add_query_arg('search', rawurlencode($row['facility_name']), get_permalink($state_page)),
                'meta'  => $state,
            );
        }
        if ($items) {
            $groups[] = array('key' => 'inspections', 'label' => 'Inspection records', 'items' => $items);
        }
    }

    // --- News submissions --------------------------------------------------
    if (kop_asl_table_exists('news_submissions')) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT article_title, alternate_title, article_url, publication_name FROM news_submissions
                 WHERE status IN ('approved','published')
                   AND (article_title LIKE %s OR alternate_title LIKE %s OR facilities_mentioned LIKE %s)
                 ORDER BY publication_date DESC LIMIT 5",
                $like, $like, $like
            ),
            ARRAY_A
        );
        $items = array();
        foreach ((array) $rows as $row) {
            if (empty($row['article_url'])) {
                continue;
            }
            $title   = !empty($row['alternate_title']) ? $row['alternate_title'] : $row['article_title'];
            $items[] = array(
                'title' => $title,
                'url'   => $row['article_url'],
                'meta'  => (string) $row['publication_name'],
            );
        }
        if ($items) {
            $groups[] = array('key' => 'news', 'label' => 'News coverage', 'items' => $items);
        }
    }

    // --- Documents (media library) -----------------------------------------
    $attachments = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ID, post_title, post_mime_type FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_title LIKE %s
             ORDER BY post_date DESC LIMIT 5",
            $like
        ),
        ARRAY_A
    );
    $items = array();
    foreach ((array) $attachments as $row) {
        $url = wp_get_attachment_url((int) $row['ID']);
        if (!$url) {
            continue;
        }
        $mime    = (string) $row['post_mime_type'];
        $items[] = array(
            'title' => $row['post_title'] !== '' ? $row['post_title'] : basename($url),
            'url'   => $url,
            'meta'  => $mime ? strtoupper(preg_replace('#^.*/#', '', $mime)) : '',
        );
    }
    if ($items) {
        $groups[] = array('key' => 'documents', 'label' => 'Documents & media', 'items' => $items);
    }

    // --- Addresses ---------------------------------------------------------
    $addresses_table  = $wpdb->prefix . 'kop_addresses';
    $fac_addr_table   = $wpdb->prefix . 'kop_facility_addresses';
    if (kop_asl_table_exists($addresses_table)) {
        $has_join  = kop_asl_table_exists($fac_addr_table);
        $index_url = kop_asl_page_url_by_template('page-tti-program-index.php');
        $rows      = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, street, city, state, zip FROM `{$addresses_table}`
                 WHERE street LIKE %s OR city LIKE %s OR zip LIKE %s
                 LIMIT 4",
                $like, $like, $like
            ),
            ARRAY_A
        );
        $items = array();
        foreach ((array) $rows as $row) {
            $title = implode(', ', array_filter(array($row['street'], $row['city'], trim($row['state'] . ' ' . $row['zip']))));
            $facilities = array();
            if ($has_join) {
                $facilities = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT DISTINCT facility FROM `{$fac_addr_table}` WHERE address_id = %d LIMIT 3",
                        (int) $row['id']
                    )
                );
            }
            $first_facility = $facilities ? (string) $facilities[0] : '';
            $items[] = array(
                'title' => $title,
                'url'   => ($first_facility && $index_url)
                    ? add_query_arg('search', rawurlencode($first_facility), $index_url)
                    : add_query_arg('s', rawurlencode($title), home_url('/')),
                'meta'  => $facilities ? implode(', ', $facilities) : 'Address record',
            );
        }
        if ($items) {
            $groups[] = array('key' => 'addresses', 'label' => 'Addresses', 'items' => $items);
        }
    }

    // --- WP pages & posts --------------------------------------------------
    $wp_query = new WP_Query(array(
        's'              => $phrase,
        'post_type'      => array('page', 'post'),
        'post_status'    => 'publish',
        'posts_per_page' => 4,
        'no_found_rows'  => true,
    ));
    $items = array();
    foreach ($wp_query->posts as $post) {
        $items[] = array(
            'title' => get_the_title($post),
            'url'   => get_permalink($post),
            'meta'  => $post->post_type === 'page' ? 'Page' : 'Post',
        );
    }
    if ($items) {
        $groups[] = array('key' => 'site', 'label' => 'Site pages', 'items' => $items);
    }

    // --- Suggested edits (admins only; needs the wp_rest nonce) ------------
    if (current_user_can('manage_options') && kop_asl_table_exists('suggested_edits')) {
        $admin_url = kop_asl_page_url_by_template('page-admin-data.php');
        $rows      = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT master_id, status, created_at FROM suggested_edits
                 WHERE master_id LIKE %s OR edited_json_data LIKE %s
                 ORDER BY created_at DESC LIMIT 4",
                $like, $like
            ),
            ARRAY_A
        );
        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = array(
                'title' => $row['master_id'],
                'url'   => $admin_url ? add_query_arg('search', rawurlencode($row['master_id']), $admin_url) : admin_url(),
                'meta'  => ucfirst((string) $row['status']) . ' suggestion',
            );
        }
        if ($items) {
            $groups[] = array('key' => 'suggestions', 'label' => 'Suggested edits (admin)', 'items' => $items);
        }
    }

    return $groups;
}
