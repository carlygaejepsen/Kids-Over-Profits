<?php
/**
 * Read-only JSON for the Kids Over Profits mobile app (kids-over-profits-app).
 *
 * Three public GET routes under kop/v1, no login, no nonce. Each one is a thin
 * layer over the data the generated pages already print, cut to what a phone
 * needs and to what is public:
 *
 *   GET kop/v1/facility/<slug or id>    kop_facility_page_data() (inc/facility-pages.php),
 *                                       the whole /facility/ page minus the HTML-only parts
 *   GET kop/v1/operator/<slug or id>    kop_operator_page_data() (inc/operator-pages.php);
 *   GET kop/v1/operator?name=UHS        a duplicate id resolves to its canonical record
 *   GET kop/v1/news                     the public news feed (templates/page-news-feed.php):
 *                                       ?page= ?per_page= (max 50) ?archive=YYYY-MM
 *                                       ?story=<arc slug> ?facility=<id>
 *
 * Privacy: every payload is built by copying NAMED keys out of the page data
 * (kop_mobile_facility_payload(), kop_mobile_operator_payload()) and the news
 * query SELECTs named columns (kop_mobile_news_item()), so a column added to a
 * table later never leaks by accident. The review-workflow columns of
 * news_submissions (author, submitted_by, submission_notes, reviewer_notes,
 * reviewed_by, staff_mentioned, survivors_mentioned, json_data,
 * generated_output) are never read here. Unpublished survivor testimony is
 * stripped from every kop/v1 response by kop_rest_redact_private_testimony()
 * (inc/rest-api.php), and operator history drafts are hidden from anonymous
 * callers by kop_operator_history_written().
 *
 * Caching: ETag + Cache-Control on every response; a matching If-None-Match
 * gets a 304 with no body. Checked offline by scripts/test-mobile-api.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_MOBILE_API_VERSION')) {
    define('KOP_MOBILE_API_VERSION', 1);
}
// Inspection reports listed on a facility payload; the rest are a link to the page.
if (!defined('KOP_MOBILE_API_MAX_REPORTS')) {
    define('KOP_MOBILE_API_MAX_REPORTS', 20);
}

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/facility/(?P<ref>[A-Za-z0-9_-]+)', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'kop_mobile_facility_rest',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('kop/v1', '/operator/(?P<ref>[A-Za-z0-9_-]+)', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'kop_mobile_operator_rest',
        'permission_callback' => '__return_true',
    ));
    register_rest_route('kop/v1', '/operator', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'kop_mobile_operator_rest',
        'permission_callback' => '__return_true',
        'args'                => array(
            'name' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
        ),
    ));
    register_rest_route('kop/v1', '/news', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'kop_mobile_news_rest',
        'permission_callback' => '__return_true',
    ));
});

// ---------------------------------------------------------------------------
// Facilities
// ---------------------------------------------------------------------------

if (!function_exists('kop_mobile_resolve_facility_id')) {
    /** A facility id for a page slug ("hyde-school-ct") or a numeric id with a page; 0 otherwise. */
    function kop_mobile_resolve_facility_id($ref) {
        $ref = strtolower(trim((string) $ref));
        if ($ref === '' || !function_exists('kop_facility_pages_index')) return 0;
        $index = kop_facility_pages_index();
        if (ctype_digit($ref)) {
            return isset($index['ids'][(int) $ref]) ? (int) $ref : 0;
        }
        if (isset($index['slugs'][$ref])) return (int) $index['slugs'][$ref];
        // A slug whose name part is stale still carries the id (facility-pages.php routing rule).
        if (preg_match('/-(\d+)$/', $ref, $m) && isset($index['ids'][(int) $m[1]])) return (int) $m[1];
        return 0;
    }
}

if (!function_exists('kop_mobile_facility_payload')) {
    /**
     * The app's copy of one facility page: named keys only. Everything the page
     * prints except the document library HTML (replaced by a link), the
     * archive blocks and the SEO/page-chrome strings.
     */
    function kop_mobile_facility_payload(array $page) {
        $keep = array(
            'id', 'slug', 'url', 'name', 'unique_name', 'current_name', 'formerly', 'aka', 'status', 'end_year',
            'place', 'city', 'state_code', 'state_name', 'country', 'hub_name', 'hub_url',
            'operator', 'siblings', 'program_homes', 'home_of', 'addresses', 'former_locations', 'operated',
            'summary', 'facts', 'fact_sources', 'practices', 'incidents', 'staff', 'notes', 'field_notes',
            'testimony', 'forum', 'videos', 'profile_links', 'resources', 'resource_links',
            'lawsuits', 'memorials', 'eras', 'wiki', 'updated_at', 'updated_label',
            'news_url', 'lawsuits_url', 'memorial_url', 'wiki_url', 'submit_url',
        );
        $out = array('api_version' => KOP_MOBILE_API_VERSION);
        foreach ($keep as $k) {
            if (array_key_exists($k, $page)) $out[$k] = $page[$k];
        }
        $out['news'] = array_map('kop_mobile_news_card', (array) ($page['news'] ?? array()));

        $insp = isset($page['inspections']) && is_array($page['inspections']) ? $page['inspections'] : null;
        if ($insp) {
            $reports = array_values((array) ($insp['reports'] ?? array()));
            $out['inspections'] = array(
                'summary'  => $insp['summary'] ?? null,
                'total'    => isset($insp['total']) ? (int) $insp['total'] : count($reports),
                'reports'  => array_slice($reports, 0, KOP_MOBILE_API_MAX_REPORTS),
                'more'     => max(0, count($reports) - KOP_MOBILE_API_MAX_REPORTS),
                'page_url' => (string) ($insp['page_url'] ?? ''),
            );
        } else {
            $out['inspections'] = null;
        }

        $docs = isset($page['documents']) && is_array($page['documents']) ? $page['documents'] : array();
        $out['documents'] = array(
            'folder_id' => (int) ($docs['folder_id'] ?? 0),
            'url'       => (!empty($docs['folder_id']) && !empty($page['url'])) ? $page['url'] . '#documents' : '',
        );
        return $out;
    }
}

if (!function_exists('kop_mobile_facility')) {
    /** The payload for a slug or id, or null when there is no such page. */
    function kop_mobile_facility($ref) {
        $id = kop_mobile_resolve_facility_id($ref);
        if ($id <= 0 || !function_exists('kop_facility_page_data')) return null;
        $page = kop_facility_page_data($id);
        return is_array($page) ? kop_mobile_facility_payload($page) : null;
    }
}

if (!function_exists('kop_mobile_facility_rest')) {
    function kop_mobile_facility_rest($request) {
        $ref = (string) $request->get_param('ref');
        $id = kop_mobile_resolve_facility_id($ref);
        if ($id <= 0) return kop_mobile_not_found('No facility page for "' . $ref . '".');
        $index = kop_facility_pages_index();
        $etag = kop_mobile_etag(array('facility', $id, $index['fingerprint'] ?? '', $index['ids'][$id]['updated'] ?? ''));
        if (kop_mobile_etag_matches($request, $etag)) return kop_mobile_not_modified($etag);
        $data = kop_mobile_facility($id);
        if ($data === null) return kop_mobile_not_found('No facility page for "' . $ref . '".');
        return kop_mobile_response($data, $etag);
    }
}

// ---------------------------------------------------------------------------
// Operators (parent companies)
// ---------------------------------------------------------------------------

if (!function_exists('kop_mobile_resolve_operator_id')) {
    /**
     * A canonical operator id for a page slug, a numeric id (duplicates follow
     * alias_of) or, with $by_name, a name as readers write it ("UHS"); 0 otherwise.
     */
    function kop_mobile_resolve_operator_id($ref, $by_name = false) {
        $ref = trim((string) $ref);
        if ($ref === '' || !function_exists('kop_operator_pages_index')) return 0;
        $index = kop_operator_pages_index();
        $id = 0;
        if ($by_name) {
            $key = function_exists('kop_facility_pages_name_key') ? kop_facility_pages_name_key($ref) : '';
            if ($key !== '' && isset($index['names'][$key])) {
                $id = (int) $index['names'][$key];
            } elseif ($key !== '') {
                // "Three Springs" for "Three Springs Inc." (kop_operator_page_url_for_name rule)
                $strip = '/\b(inc|llc|corp|corporation|company|co|ltd)\b\.?$/';
                $trimmed = trim(preg_replace($strip, '', $key));
                foreach ((array) $index['names'] as $nk => $nid) {
                    if ($trimmed !== '' && trim(preg_replace($strip, '', $nk)) === $trimmed) { $id = (int) $nid; break; }
                }
            }
        } else {
            $key = strtolower($ref);
            if (ctype_digit($key)) $id = (int) $key;
            elseif (isset($index['slugs'][$key])) $id = (int) $index['slugs'][$key];
        }
        if ($id > 0 && isset($index['alias_of'][$id])) $id = (int) $index['alias_of'][$id];
        return ($id > 0 && isset($index['ids'][$id])) ? $id : 0;
    }
}

if (!function_exists('kop_mobile_operator_payload')) {
    /** The app's copy of one company page: named keys only, no document HTML or archive blocks. */
    function kop_mobile_operator_payload(array $page) {
        $keep = array(
            'id', 'name', 'full_name', 'url', 'aka', 'current_name', 'status', 'facts', 'parents', 'subsidiaries',
            'facilities', 'program_tree', 'open_count', 'place_count', 'lawsuits', 'memorials', 'websites', 'notes',
            'timeline', 'people', 'summary', 'index_url', 'network_url', 'updated', 'updated_label',
        );
        $out = array('api_version' => KOP_MOBILE_API_VERSION);
        foreach ($keep as $k) {
            if (array_key_exists($k, $page)) $out[$k] = $page[$k];
        }
        $out['news'] = array_map('kop_mobile_news_card', (array) ($page['news'] ?? array()));
        $history = isset($page['history']) && is_array($page['history']) ? $page['history'] : null;
        $out['history'] = $history ? array(
            'status'     => (string) ($history['status'] ?? ''),
            'paragraphs' => array_values(array_map('strval', (array) ($history['paragraphs'] ?? array()))),
            'sources'    => array_values((array) ($history['sources'] ?? array())),
        ) : null;
        $docs = isset($page['documents']) && is_array($page['documents']) ? $page['documents'] : array();
        $pdocs = isset($page['program_docs']) && is_array($page['program_docs']) ? $page['program_docs'] : array();
        $out['documents'] = array(
            'folder_id'      => (int) ($docs['folder_id'] ?? 0),
            'program_total'  => (int) ($pdocs['total'] ?? 0),
            'url'            => (!empty($docs['folder_id']) || !empty($pdocs['total'])) && !empty($page['url']) ? $page['url'] . '#documents' : '',
        );
        return $out;
    }
}

if (!function_exists('kop_mobile_operator')) {
    function kop_mobile_operator($ref, $by_name = false) {
        $id = kop_mobile_resolve_operator_id($ref, $by_name);
        if ($id <= 0 || !function_exists('kop_operator_page_data')) return null;
        $page = kop_operator_page_data($id);
        return is_array($page) ? kop_mobile_operator_payload($page) : null;
    }
}

if (!function_exists('kop_mobile_operator_rest')) {
    function kop_mobile_operator_rest($request) {
        $ref = (string) $request->get_param('ref');
        $name = (string) $request->get_param('name');
        $by_name = $ref === '' && $name !== '';
        $id = kop_mobile_resolve_operator_id($by_name ? $name : $ref, $by_name);
        if ($id <= 0) return kop_mobile_not_found('No company page for "' . ($by_name ? $name : $ref) . '".');
        $index = kop_operator_pages_index();
        $etag = kop_mobile_etag(array('operator', $id, $index['fingerprint'] ?? '', function_exists('kop_facility_pages_index') ? (kop_facility_pages_index()['fingerprint'] ?? '') : ''));
        if (kop_mobile_etag_matches($request, $etag)) return kop_mobile_not_modified($etag);
        $data = kop_mobile_operator($id);
        if ($data === null) return kop_mobile_not_found('No company page for "' . ($by_name ? $name : $ref) . '".');
        return kop_mobile_response($data, $etag);
    }
}

// ---------------------------------------------------------------------------
// News
// ---------------------------------------------------------------------------

if (!function_exists('kop_mobile_news_card')) {
    /**
     * One article as the app draws it, from a kop_facility_pages_news() /
     * operator news item: {id, title, outlet, date, date_label, url, type,
     * summary, image}. Named keys only.
     */
    function kop_mobile_news_card($item) {
        if (!is_array($item)) return null;
        $card = array();
        foreach (array('id', 'title', 'outlet', 'date', 'date_label', 'url', 'type', 'summary', 'image', 'link_type', 'about') as $k) {
            if (array_key_exists($k, $item)) $card[$k] = $item[$k];
        }
        return $card;
    }
}

if (!function_exists('kop_mobile_news_public_columns')) {
    /** The news_submissions columns the feed reads. Nothing else is ever selected here. */
    function kop_mobile_news_public_columns($alias = 'n') {
        $cols = array('id', 'article_title', 'alternate_title', 'publication_name', 'publication_date', 'article_url', 'article_type', 'summary', 'content_warnings', 'story_group_id', 'story_arc_id');
        return implode(', ', array_map(function ($c) use ($alias) { return $alias . '.' . $c; }, $cols));
    }
}

if (!function_exists('kop_mobile_news_has_column')) {
    function kop_mobile_news_has_column($column) {
        global $wpdb;
        static $cols = null;
        if ($cols === null) {
            $cols = array();
            $suppress = $wpdb->suppress_errors(true);
            $rows = $wpdb->get_results('SELECT * FROM news_submissions LIMIT 1', ARRAY_A);
            $wpdb->suppress_errors($suppress);
            if (is_array($rows) && isset($rows[0]) && is_array($rows[0])) $cols = array_keys($rows[0]);
        }
        return in_array($column, $cols, true);
    }
}

if (!function_exists('kop_mobile_news_item')) {
    /**
     * One feed row -> the card the app draws, with the facilities it is linked
     * to (news_facility_links) and its ongoing story. $facilities and $arcs are
     * the batch lookups the feed builds once per page.
     */
    function kop_mobile_news_item(array $row, array $facilities = array(), array $arcs = array()) {
        $id = (int) $row['id'];
        $date = (string) ($row['publication_date'] ?? '');
        $warnings = json_decode((string) ($row['content_warnings'] ?? ''), true);
        $arc_id = (int) ($row['story_arc_id'] ?? 0);
        return array(
            'id'               => $id,
            'title'            => trim((string) (!empty($row['alternate_title']) ? $row['alternate_title'] : $row['article_title'])),
            'outlet'           => trim((string) ($row['publication_name'] ?? '')),
            'date'             => $date,
            'date_label'       => ($date !== '' && function_exists('kop_facility_pages_date_label')) ? kop_facility_pages_date_label($date) : $date,
            'url'              => (string) ($row['article_url'] ?? ''),
            'type'             => trim((string) ($row['article_type'] ?? '')),
            'summary'          => trim((string) ($row['summary'] ?? '')),
            'content_warnings' => is_array($warnings) ? array_values(array_filter(array_map('strval', $warnings), 'strlen')) : array(),
            'image'            => function_exists('kop_news_image') ? kop_news_image($id, (string) ($row['article_url'] ?? '')) : null,
            'facilities'       => array_values($facilities[$id] ?? array()),
            'story_arc'        => ($arc_id > 0 && isset($arcs[$arc_id])) ? array('title' => (string) $arcs[$arc_id]['title'], 'slug' => (string) $arcs[$arc_id]['slug']) : null,
            'story_group_id'   => !empty($row['story_group_id']) ? (int) $row['story_group_id'] : null,
        );
    }
}

if (!function_exists('kop_mobile_news_facilities_for')) {
    /** news id => [{id, name, slug, url}] for the facilities each article is linked to. */
    function kop_mobile_news_facilities_for(array $news_ids) {
        global $wpdb;
        $news_ids = array_values(array_unique(array_filter(array_map('intval', $news_ids))));
        if (!$news_ids || !function_exists('kop_facility_pages_table_exists') || !kop_facility_pages_table_exists('news_facility_links')) return array();
        $rows = $wpdb->get_results(
            'SELECT l.news_id, f.id, f.name FROM news_facility_links l JOIN facilities_v2 f ON f.id = l.facility_id
              WHERE l.news_id IN (' . implode(',', $news_ids) . ') ORDER BY l.news_id, f.name',
            ARRAY_A
        );
        $index = function_exists('kop_facility_pages_index') ? kop_facility_pages_index() : array('ids' => array());
        $out = array();
        foreach ((array) $rows as $r) {
            $fid = (int) $r['id'];
            $entry = $index['ids'][$fid] ?? null;
            $out[(int) $r['news_id']][] = array(
                'id'   => $fid,
                'name' => (string) $r['name'],
                'slug' => $entry ? (string) $entry['slug'] : '',
                'url'  => function_exists('kop_facility_page_url') ? kop_facility_page_url($fid) : '',
            );
        }
        return $out;
    }
}

if (!function_exists('kop_mobile_news_arcs')) {
    /**
     * Every story arc by id: {id, title, slug, description, status, article_count,
     * latest_date, facility: {label, url}|null}. Empty before the news_story_arcs migration.
     */
    function kop_mobile_news_arcs() {
        global $wpdb;
        static $memo = null;
        if ($memo !== null) return $memo;
        $memo = array();
        if (!function_exists('kop_facility_pages_table_exists') || !kop_facility_pages_table_exists('news_story_arcs') || !kop_mobile_news_has_column('story_arc_id')) return $memo;
        $rows = $wpdb->get_results(
            "SELECT a.id, a.title, a.slug, a.description, a.status, a.facility_label, a.facility_url, a.display_order,
                    (SELECT COUNT(*) FROM news_submissions s WHERE s.story_arc_id = a.id AND s.status IN ('approved','published')) AS article_count,
                    (SELECT MAX(s.publication_date) FROM news_submissions s WHERE s.story_arc_id = a.id AND s.status IN ('approved','published')) AS latest_date
               FROM news_story_arcs a
              ORDER BY a.display_order ASC, a.id ASC",
            ARRAY_A
        );
        foreach ((array) $rows as $a) {
            $memo[(int) $a['id']] = array(
                'id'            => (int) $a['id'],
                'title'         => (string) $a['title'],
                'slug'          => (string) $a['slug'],
                'description'   => trim((string) $a['description']),
                'status'        => (string) $a['status'],
                'article_count' => (int) $a['article_count'],
                'latest_date'   => (string) $a['latest_date'],
                'facility'      => function_exists('kop_news_arc_facility_link') ? kop_news_arc_facility_link($a) : null,
            );
        }
        return $memo;
    }
}

if (!function_exists('kop_mobile_news_feed')) {
    /**
     * The news feed page the app lists. $args: page (1+), per_page (1..50),
     * archive (YYYY-MM), story (arc slug), facility (facility id). Same rows
     * and order as templates/page-news-feed.php: approved or published,
     * Indian boarding school articles left to their own page, newest first.
     *
     * Returns {items, total, page, per_page, pages, months: [{month, count}],
     * arcs: [active arcs with articles], story: the filtered arc or null}.
     */
    function kop_mobile_news_feed(array $args = array()) {
        global $wpdb;
        $page = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(1, min(50, (int) ($args['per_page'] ?? 20)));
        $archive = trim((string) ($args['archive'] ?? ''));
        $story = function_exists('sanitize_title') ? sanitize_title((string) ($args['story'] ?? '')) : strtolower(trim((string) ($args['story'] ?? '')));
        $facility = (int) ($args['facility'] ?? 0);

        $empty = array('items' => array(), 'total' => 0, 'page' => $page, 'per_page' => $per_page, 'pages' => 0, 'months' => array(), 'arcs' => array(), 'story' => null);
        if (!function_exists('kop_facility_pages_table_exists') || !kop_facility_pages_table_exists('news_submissions')) return $empty;

        $exclude = function_exists('kop_ischools_news_exclude_sql') ? kop_ischools_news_exclude_sql('n.id') : '';
        $where = "n.status IN ('approved','published')" . $exclude;
        if ($archive !== '' && preg_match('/^\d{4}-\d{2}$/', $archive)) {
            $where .= $wpdb->prepare(' AND SUBSTR(n.publication_date, 1, 7) = %s', $archive);
        }
        $arcs = kop_mobile_news_arcs();
        $current_arc = null;
        if ($story !== '') {
            foreach ($arcs as $a) {
                if ($a['slug'] === $story) { $current_arc = $a; break; }
            }
            if (!$current_arc) return $empty;
            $where .= $wpdb->prepare(' AND n.story_arc_id = %d', $current_arc['id']);
        }
        if ($facility > 0) {
            if (!kop_facility_pages_table_exists('news_facility_links')) return $empty;
            $where .= $wpdb->prepare(' AND EXISTS (SELECT 1 FROM news_facility_links l WHERE l.news_id = n.id AND l.facility_id = %d)', $facility);
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM news_submissions n WHERE $where");
        $cols = kop_mobile_news_public_columns('n');
        if (!kop_mobile_news_has_column('story_arc_id')) $cols = str_replace(', n.story_arc_id', ', NULL AS story_arc_id', $cols);
        if (!kop_mobile_news_has_column('story_group_id')) $cols = str_replace(', n.story_group_id', ', NULL AS story_group_id', $cols);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT $cols FROM news_submissions n WHERE $where ORDER BY n.publication_date DESC, n.created_at DESC, n.id DESC LIMIT %d OFFSET %d",
            $per_page, ($page - 1) * $per_page
        ), ARRAY_A);
        $rows = is_array($rows) ? $rows : array();

        $facilities = kop_mobile_news_facilities_for(wp_list_pluck($rows, 'id'));
        $items = array();
        foreach ($rows as $r) $items[] = kop_mobile_news_item($r, $facilities, $arcs);

        $months = array();
        $month_rows = $wpdb->get_results(
            "SELECT SUBSTR(n.publication_date, 1, 7) AS month, COUNT(*) AS cnt FROM news_submissions n
              WHERE n.status IN ('approved','published') AND n.publication_date IS NOT NULL AND n.publication_date <> ''$exclude
              GROUP BY month ORDER BY month DESC",
            ARRAY_A
        );
        foreach ((array) $month_rows as $m) {
            if (preg_match('/^\d{4}-\d{2}$/', (string) $m['month'])) $months[] = array('month' => (string) $m['month'], 'count' => (int) $m['cnt']);
        }

        $active = array();
        foreach ($arcs as $a) {
            if ($a['status'] === 'active' && $a['article_count'] > 0) $active[] = $a;
        }

        return array(
            'items'    => $items,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) ceil($total / $per_page),
            'months'   => $months,
            'arcs'     => $active,
            'story'    => $current_arc,
        );
    }
}

if (!function_exists('kop_mobile_news_rest')) {
    function kop_mobile_news_rest($request) {
        global $wpdb;
        $args = array(
            'page'     => $request->get_param('page'),
            'per_page' => $request->get_param('per_page') === null ? 20 : $request->get_param('per_page'),
            'archive'  => $request->get_param('archive'),
            'story'    => $request->get_param('story'),
            'facility' => $request->get_param('facility'),
        );
        $stamp = '';
        if (function_exists('kop_facility_pages_table_exists') && kop_facility_pages_table_exists('news_submissions')) {
            $row = $wpdb->get_row("SELECT COUNT(*) AS n, MAX(updated_at) AS u FROM news_submissions WHERE status IN ('approved','published')", ARRAY_A);
            $stamp = is_array($row) ? (int) $row['n'] . '|' . (string) $row['u'] : '';
        }
        $etag = kop_mobile_etag(array('news', $stamp, wp_json_encode($args)));
        if (kop_mobile_etag_matches($request, $etag)) return kop_mobile_not_modified($etag);
        return kop_mobile_response(kop_mobile_news_feed($args), $etag, 300);
    }
}

// ---------------------------------------------------------------------------
// Responses
// ---------------------------------------------------------------------------

if (!function_exists('kop_mobile_etag')) {
    function kop_mobile_etag(array $parts) {
        return '"' . md5(implode('|', array_map('strval', $parts)) . '|v' . KOP_MOBILE_API_VERSION) . '"';
    }
}

if (!function_exists('kop_mobile_etag_matches')) {
    function kop_mobile_etag_matches($request, $etag) {
        $sent = trim((string) $request->get_header('if_none_match'));
        if ($sent === '') return false;
        foreach (explode(',', $sent) as $candidate) {
            $candidate = trim($candidate);
            if (strpos($candidate, 'W/') === 0) $candidate = substr($candidate, 2);
            if ($candidate === $etag) return true;
        }
        return false;
    }
}

if (!function_exists('kop_mobile_response')) {
    function kop_mobile_response($data, $etag, $max_age = 600) {
        $res = new WP_REST_Response($data, 200);
        $res->header('ETag', $etag);
        $res->header('Cache-Control', 'public, max-age=' . (int) $max_age . ', stale-while-revalidate=3600');
        return $res;
    }
}

if (!function_exists('kop_mobile_not_modified')) {
    function kop_mobile_not_modified($etag) {
        $res = new WP_REST_Response(null, 304);
        $res->header('ETag', $etag);
        return $res;
    }
}

if (!function_exists('kop_mobile_not_found')) {
    function kop_mobile_not_found($message) {
        return new WP_Error('kop_mobile_not_found', $message, array('status' => 404));
    }
}
