<?php
/**
 * Facility pages: a public profile page for every facility record that
 * holds more than a name and an address.
 *
 * URL: /facility/<slug>/ (kop_facility_pages_base()). The page is rendered
 * from facilities_v2 by templates/facility-page.php on each request, so it
 * never goes stale and nothing is written to wp_posts. Records that carry
 * only a name, an address and the default "Open" status have no page of
 * their own; their URL sends the visitor to the state or country hub.
 *
 * The hand-written Facility Profile posts (templates/single-facility-profile.php)
 * stay the canonical page for their facility: the generated URL 301s to them.
 *
 * Pieces, in file order:
 *   routing      rewrite rule, query var, the template_redirect handler
 *   index        slug <-> id map of the facilities that have a page (transient)
 *   eligibility  kop_facility_page_signals(): what counts as "meaningful"
 *   view model   kop_facility_page_data(): everything the template prints
 *   links        kop_facility_page_url() for cards, search and the sitemap
 *   sitemap      Yoast (facility-sitemap.xml) with a core-sitemaps fallback
 *
 * Every function is guarded with function_exists so the file can be loaded
 * by the offline test harness (scripts/test-facility-pages.php) next to
 * WordPress stubs.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_FACILITY_PAGES_REWRITE_VERSION')) {
    // Bump when the rewrite rule changes; the rules are re-flushed once.
    define('KOP_FACILITY_PAGES_REWRITE_VERSION', '1');
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_pages_base')) {
    /** URL segment the pages live under ("facility"). */
    function kop_facility_pages_base() {
        return apply_filters('kop_facility_pages_base', 'facility');
    }
}

if (!function_exists('kop_facility_pages_register_rewrite')) {
    function kop_facility_pages_register_rewrite() {
        $base = kop_facility_pages_base();
        add_rewrite_rule('^' . preg_quote($base, '#') . '/([^/]+)/?$', 'index.php?kop_facility=$matches[1]', 'top');
        if (get_option('kop_facility_pages_rewrite') !== KOP_FACILITY_PAGES_REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option('kop_facility_pages_rewrite', KOP_FACILITY_PAGES_REWRITE_VERSION);
        }
    }
    add_action('init', 'kop_facility_pages_register_rewrite', 30);
}

if (!function_exists('kop_facility_pages_query_vars')) {
    function kop_facility_pages_query_vars($vars) {
        $vars[] = 'kop_facility';
        return $vars;
    }
    add_filter('query_vars', 'kop_facility_pages_query_vars');
}

if (!function_exists('kop_facility_pages_requested_slug')) {
    /** The slug in the current request, or '' when this is not a facility page request. */
    function kop_facility_pages_requested_slug() {
        if (!function_exists('get_query_var')) return '';
        $slug = get_query_var('kop_facility');
        return is_string($slug) ? trim($slug) : '';
    }
}

if (!function_exists('kop_facility_pages_is_page')) {
    /** True while a facility page is being rendered (the view model is loaded). */
    function kop_facility_pages_is_page() {
        return !empty($GLOBALS['kop_facility_page']) && is_array($GLOBALS['kop_facility_page']);
    }
}

if (!function_exists('kop_facility_pages_pre_get_posts')) {
    /**
     * The request carries no post query var, so WordPress would treat it as
     * the blog home and load the latest posts. Unset the home flag and skip
     * the query; the page reads facilities_v2 instead.
     */
    function kop_facility_pages_pre_get_posts($query) {
        if (is_admin() || !$query->is_main_query()) return;
        if (trim((string) $query->get('kop_facility')) === '') return;
        $query->is_home = false;
        $query->is_posts_page = false;
        $query->set('posts_per_page', 1);
        $query->set('no_found_rows', true);
    }
    add_action('pre_get_posts', 'kop_facility_pages_pre_get_posts');
}

if (!function_exists('kop_facility_pages_posts_pre_query')) {
    function kop_facility_pages_posts_pre_query($posts, $query) {
        if (is_admin() || !$query->is_main_query()) return $posts;
        if (trim((string) $query->get('kop_facility')) === '') return $posts;
        return array();
    }
    add_filter('posts_pre_query', 'kop_facility_pages_posts_pre_query', 10, 2);
}

if (!function_exists('kop_facility_pages_pre_handle_404')) {
    /** An empty main query must not become a 404 here; the handler decides. */
    function kop_facility_pages_pre_handle_404($preempt, $query) {
        if (trim((string) $query->get('kop_facility')) !== '') return true;
        return $preempt;
    }
    add_filter('pre_handle_404', 'kop_facility_pages_pre_handle_404', 10, 2);
}

if (!function_exists('kop_facility_pages_route')) {
    /**
     * Resolve the requested slug and either load the view model, redirect,
     * or 404. Runs before the theme's slug redirect map has a chance to
     * misread the path (that map only looks at single-segment paths).
     */
    function kop_facility_pages_route() {
        $slug = kop_facility_pages_requested_slug();
        if ($slug === '') return;

        $index = kop_facility_pages_index();
        $id = 0;
        if (ctype_digit($slug)) {
            $id = (int) $slug;
        } else {
            $key = strtolower($slug);
            $id = isset($index['slugs'][$key]) ? (int) $index['slugs'][$key] : 0;
            if ($id === 0 && preg_match('/-(\d+)$/', $key, $m) && isset($index['ids'][(int) $m[1]])) {
                // A slug whose name part is stale still carries the id.
                $id = (int) $m[1];
            }
        }

        if ($id > 0 && isset($index['ids'][$id])) {
            $entry = $index['ids'][$id];
            if ($entry['editorial'] !== '') {
                wp_safe_redirect($entry['editorial'], 301);
                exit;
            }
            if ($slug !== $entry['slug']) {
                wp_safe_redirect(kop_facility_pages_url_for_slug($entry['slug']), 301);
                exit;
            }
            $data = kop_facility_page_data($id);
            if ($data) {
                $GLOBALS['kop_facility_page'] = $data;
                status_header(200);
                return;
            }
        } elseif ($id > 0) {
            // A real record without enough on file for a page of its own.
            $thin = kop_facility_pages_thin_target($id);
            if ($thin !== '') {
                wp_safe_redirect($thin, 302);
                exit;
            }
        }

        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }
    add_action('template_redirect', 'kop_facility_pages_route', 0);
}

if (!function_exists('kop_facility_pages_template_include')) {
    function kop_facility_pages_template_include($template) {
        if (!kop_facility_pages_is_page()) return $template;
        $own = get_stylesheet_directory() . '/templates/facility-page.php';
        return file_exists($own) ? $own : $template;
    }
    add_filter('template_include', 'kop_facility_pages_template_include', 99);
}

if (!function_exists('kop_facility_pages_body_class')) {
    function kop_facility_pages_body_class($classes) {
        if (kop_facility_pages_is_page()) {
            $classes[] = 'kop-facility-page';
        }
        return $classes;
    }
    add_filter('body_class', 'kop_facility_pages_body_class');
}

if (!function_exists('kop_facility_pages_enqueue')) {
    function kop_facility_pages_enqueue() {
        if (!kop_facility_pages_is_page()) return;
        $theme_dir = get_stylesheet_directory();
        $theme_uri = get_stylesheet_directory_uri();
        if (function_exists('kop_enqueue_shared_facility_ui')) {
            kop_enqueue_shared_facility_ui();
        }
        $css = $theme_dir . '/css/facility-profile.css';
        if (file_exists($css)) {
            wp_enqueue_style('kop-facility-profile', $theme_uri . '/css/facility-profile.css', array('kop-colors', 'kop-components'), filemtime($css));
        }
        $doc_css = $theme_dir . '/css/document-library.css';
        if (file_exists($doc_css) && !empty($GLOBALS['kop_facility_page']['documents']['html'])) {
            wp_enqueue_style('kop-document-library-style', $theme_uri . '/css/document-library.css', array('kop-colors'), filemtime($doc_css));
        }
        $js = $theme_dir . '/js/submit-info.js';
        if (file_exists($js)) {
            wp_enqueue_script('kop-submit-info', $theme_uri . '/js/submit-info.js', array(), filemtime($js), true);
        }
    }
    add_action('wp_enqueue_scripts', 'kop_facility_pages_enqueue', 20);
}

// ---------------------------------------------------------------------------
// Titles, canonical and robots
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_pages_document_title')) {
    function kop_facility_pages_document_title($title) {
        if (!kop_facility_pages_is_page()) return $title;
        return $GLOBALS['kop_facility_page']['seo_title'];
    }
    add_filter('pre_get_document_title', 'kop_facility_pages_document_title', 20);
    add_filter('wpseo_title', 'kop_facility_pages_document_title', 20);
    add_filter('wpseo_opengraph_title', 'kop_facility_pages_document_title', 20);
}

if (!function_exists('kop_facility_pages_meta_description')) {
    function kop_facility_pages_meta_description($desc) {
        if (!kop_facility_pages_is_page()) return $desc;
        return $GLOBALS['kop_facility_page']['seo_description'];
    }
    add_filter('wpseo_metadesc', 'kop_facility_pages_meta_description', 20);
    add_filter('wpseo_opengraph_desc', 'kop_facility_pages_meta_description', 20);
}

if (!function_exists('kop_facility_pages_canonical')) {
    function kop_facility_pages_canonical($url) {
        if (!kop_facility_pages_is_page()) return $url;
        return $GLOBALS['kop_facility_page']['url'];
    }
    add_filter('wpseo_canonical', 'kop_facility_pages_canonical', 20);
    add_filter('wpseo_opengraph_url', 'kop_facility_pages_canonical', 20);
}

if (!function_exists('kop_facility_pages_robots')) {
    function kop_facility_pages_robots($robots) {
        if (!kop_facility_pages_is_page()) return $robots;
        return 'index, follow, max-image-preview:large';
    }
    add_filter('wpseo_robots', 'kop_facility_pages_robots', 20);
}

if (!function_exists('kop_facility_pages_head_fallback')) {
    /** Canonical and description tags when Yoast is not printing them. */
    function kop_facility_pages_head_fallback() {
        if (!kop_facility_pages_is_page() || defined('WPSEO_VERSION')) return;
        $page = $GLOBALS['kop_facility_page'];
        echo '<link rel="canonical" href="' . esc_url($page['url']) . '" />' . "\n";
        echo '<meta name="description" content="' . esc_attr($page['seo_description']) . '" />' . "\n";
    }
    add_action('wp_head', 'kop_facility_pages_head_fallback', 5);
}

// ---------------------------------------------------------------------------
// Index: which facilities have a page, and at which slug
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_pages_table_exists')) {
    function kop_facility_pages_table_exists($table) {
        global $wpdb;
        static $cache = array();
        if (!isset($cache[$table])) {
            $cache[$table] = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
        }
        return $cache[$table];
    }
}

if (!function_exists('kop_facility_pages_fingerprint')) {
    /**
     * Changes whenever a facility or one of the linked tables changes, so the
     * cached index is rebuilt on the next request instead of waiting for the
     * transient to expire.
     */
    function kop_facility_pages_fingerprint() {
        global $wpdb;
        $parts = array();
        $counts = array(
            'facilities_v2'                          => 'COUNT(*), MAX(id), MAX(updated_at)',
            'news_facility_links'                    => 'COUNT(*)',
            'lawsuit_facility_links'                 => 'COUNT(*)',
            'lawsuits'                               => 'COUNT(*), MAX(updated_at)',
            'wiki_submissions'                       => 'COUNT(*), MAX(updated_at)',
            'memorial_victims'                       => 'COUNT(*), MAX(updated_at)',
            'inspection_facilities'                  => 'COUNT(*), MAX(id)',
            'inspection_reports'                     => 'MAX(id)',
            $wpdb->prefix . 'kop_operator_facilities' => 'COUNT(*)',
            $wpdb->prefix . 'fbv'                     => 'COUNT(*), MAX(id)',
            $wpdb->prefix . 'fbv_attachment_folder'   => 'COUNT(*)',
        );
        foreach ($counts as $table => $select) {
            if (!kop_facility_pages_table_exists($table)) {
                $parts[] = $table . ':-';
                continue;
            }
            $row = $wpdb->get_row("SELECT {$select} FROM `{$table}`", ARRAY_N);
            $parts[] = $table . ':' . (is_array($row) ? implode('|', array_map('strval', $row)) : '?');
        }
        // The editorial profiles decide which ids redirect to a post.
        $posts = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*), MAX(p.post_modified_gmt) FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_page_template'
              WHERE m.meta_value = %s AND p.post_status = 'publish'",
            'templates/single-facility-profile.php'
        ), ARRAY_N);
        $parts[] = 'posts:' . (is_array($posts) ? implode('|', array_map('strval', $posts)) : '?');
        $parts[] = 'v:3';
        return md5(implode(';', $parts));
    }
}

if (!function_exists('kop_facility_pages_index')) {
    /**
     * {fingerprint, built, ids: {id: {slug, name, updated, editorial, folder,
     * signals}}, slugs: {slug: id}} for every facility that has a page.
     * Cached in a transient for a day and rebuilt when the fingerprint
     * moves. Empty until the v2 tables exist.
     */
    function kop_facility_pages_index($force = false) {
        static $index = null;
        if ($index !== null && !$force) return $index;

        $empty = array('fingerprint' => '', 'built' => 0, 'ids' => array(), 'slugs' => array());
        if (!function_exists('kop_v2_tables_ready') || !kop_v2_tables_ready()) {
            return $index = $empty;
        }
        $fingerprint = kop_facility_pages_fingerprint();
        if (!$force) {
            $cached = get_transient('kop_facility_pages_index');
            if (is_array($cached) && ($cached['fingerprint'] ?? '') === $fingerprint && isset($cached['ids'], $cached['slugs'])) {
                return $index = $cached;
            }
        }
        $index = kop_facility_pages_build_index($fingerprint);
        set_transient('kop_facility_pages_index', $index, DAY_IN_SECONDS);
        return $index;
    }
}

if (!function_exists('kop_facility_pages_flush_index')) {
    function kop_facility_pages_flush_index() {
        delete_transient('kop_facility_pages_index');
    }
    add_action('kop_facility_v2_sync', 'kop_facility_pages_flush_index', 20);
}

if (!function_exists('kop_facility_page_slug_candidate')) {
    /**
     * "hyde-school-ct": the name plus the state code, or the country for a
     * facility outside the US. The place is always included so a slug does
     * not change when a same-named facility is added elsewhere.
     */
    function kop_facility_page_slug_candidate($name, $state, $country) {
        $slug = sanitize_title((string) $name);
        if ($slug === '') $slug = 'facility';
        $state = strtoupper(trim((string) $state));
        $country = trim((string) $country);
        if ($state !== '') {
            $slug .= '-' . strtolower($state);
        } elseif ($country !== '' && strcasecmp($country, 'United States') !== 0) {
            $place = function_exists('kop_country_slug') ? kop_country_slug($country) : sanitize_title($country);
            if ($place !== '') $slug .= '-' . $place;
        }
        return $slug;
    }
}

if (!function_exists('kop_facility_pages_url_for_slug')) {
    function kop_facility_pages_url_for_slug($slug) {
        return home_url('/' . kop_facility_pages_base() . '/' . rawurlencode($slug) . '/');
    }
}

if (!function_exists('kop_facility_pages_build_index')) {
    function kop_facility_pages_build_index($fingerprint) {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id, unique_name, name, state, city, country, updated_at, json_data FROM facilities_v2 ORDER BY id", ARRAY_A);
        $links = kop_facility_pages_link_sets();
        $editorial = kop_facility_pages_editorial_map();

        $entries = array();
        $groups = array();
        foreach ((array) $rows as $row) {
            $id = (int) $row['id'];
            $doc = kop_v2_decode($row['json_data']);
            if ($doc === null) continue;
            $name = trim((string) $row['name']);
            if ($name === '') $name = trim((string) ($doc['identification']['name'] ?? ''));
            if ($name === '') continue;
            $state = strtoupper(trim((string) $row['state']));
            $country = trim((string) $row['country']);
            $candidate = kop_facility_page_slug_candidate($name, $state, $country);
            // The document folder is settled here, once, so a page render
            // never has to scan the FileBird tree.
            $folder = !empty($doc['documentFolderId']) ? (int) $doc['documentFolderId'] : 0;
            if ($folder <= 0 && !empty($links['folders'])) {
                foreach (kop_facility_pages_doc_name_keys($doc, (string) $row['unique_name']) as $fk) {
                    if (isset($links['folders'][$fk])) { $folder = (int) $links['folders'][$fk]; break; }
                }
            }
            $entries[$id] = array(
                'slug'      => $candidate,
                'name'      => $name,
                'city'      => trim((string) $row['city']),
                'updated'   => (string) $row['updated_at'],
                'editorial' => isset($editorial[$id]) ? $editorial[$id] : '',
                'folder'    => $folder,
                'signals'   => kop_facility_page_signals($doc, $id, $links, (string) $row['unique_name']),
            );
            $groups[$candidate][] = $id;
        }

        // Same name in the same place: add the city, then the id.
        foreach ($groups as $candidate => $ids) {
            if (count($ids) < 2) continue;
            $sub = array();
            foreach ($ids as $id) {
                $with_city = $candidate;
                $city = sanitize_title($entries[$id]['city']);
                if ($city !== '') $with_city .= '-' . $city;
                $sub[$with_city][] = $id;
            }
            foreach ($sub as $with_city => $sids) {
                foreach ($sids as $id) {
                    $entries[$id]['slug'] = count($sids) > 1 ? $with_city . '-' . $id : $with_city;
                }
            }
        }

        $ids = array();
        $slugs = array();
        foreach ($entries as $id => $e) {
            if (empty($e['signals']) && $e['editorial'] === '') continue;
            $slug = $e['slug'];
            if (isset($slugs[$slug])) $slug .= '-' . $id;
            $slugs[$slug] = $id;
            $ids[$id] = array(
                'slug'      => $slug,
                'name'      => $e['name'],
                'updated'   => $e['updated'],
                'editorial' => $e['editorial'],
                'folder'    => $e['folder'],
                'signals'   => count($e['signals']),
            );
        }
        return array('fingerprint' => $fingerprint, 'built' => time(), 'ids' => $ids, 'slugs' => $slugs);
    }
}

if (!function_exists('kop_facility_profile_record_names')) {
    /**
     * Editorial profile post slug => facilities_v2 unique_name, for profiles
     * whose title is not spelled the way the record is. Shared with
     * templates/single-facility-profile.php.
     */
    function kop_facility_profile_record_names() {
        return apply_filters('kop_facility_profile_record_names', array(
            'the-ridge-rtc-maine' => 'Ridge RTC Maine',
        ));
    }
}

if (!function_exists('kop_facility_pages_editorial_map')) {
    /**
     * facilities_v2 id => permalink of the published post or page that uses
     * the hand-written Facility Profile template for that facility.
     */
    function kop_facility_pages_editorial_map() {
        global $wpdb;
        $map = array();
        if (!function_exists('get_posts')) return $map;
        $posts = get_posts(array(
            'post_type'      => array('post', 'page'),
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'meta_key'       => '_wp_page_template',
            'meta_value'     => 'templates/single-facility-profile.php',
            'no_found_rows'  => true,
        ));
        $overrides = kop_facility_profile_record_names();
        foreach ((array) $posts as $post) {
            $name = trim((string) get_post_meta($post->ID, 'facility_name', true));
            if (isset($overrides[$post->post_name])) {
                $name = $overrides[$post->post_name];
            } elseif ($name === '') {
                $name = trim((string) $post->post_title);
            }
            if ($name === '') continue;
            $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM facilities_v2 WHERE unique_name = %s ORDER BY id LIMIT 1', $name));
            if ($id === 0) {
                $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM facilities_v2 WHERE name = %s ORDER BY id LIMIT 1', $name));
            }
            if ($id > 0 && !isset($map[$id])) {
                $map[$id] = get_permalink($post);
            }
        }
        return $map;
    }
}

if (!function_exists('kop_facility_pages_name_key')) {
    /** Name key used to match a facility against other tables' free-text names. */
    function kop_facility_pages_name_key($name) {
        $name = trim((string) $name);
        if ($name === '') return '';
        if (function_exists('kop_project_facility_name_key')) return kop_project_facility_name_key($name);
        $name = preg_replace('/\s*\([^)]*\)\s*$/u', '', $name);
        $s = strtolower($name);
        $s = preg_replace('/[^\w\s]/u', '', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}

if (!function_exists('kop_facility_pages_key_matches')) {
    /** Exact match, or the record name extends the facility name (12+ chars). */
    function kop_facility_pages_key_matches($facility_key, $record_key) {
        if (function_exists('kop_state_related_key_matches')) return kop_state_related_key_matches($facility_key, $record_key);
        if ($facility_key === '' || $record_key === '') return false;
        if ($facility_key === $record_key) return true;
        if (mb_strlen($facility_key) < 12 || mb_strlen($record_key) < 12) return false;
        return mb_strpos($record_key, $facility_key) === 0;
    }
}

if (!function_exists('kop_facility_pages_doc_name_keys')) {
    /** Name keys of the facility's current, other and past names. */
    function kop_facility_pages_doc_name_keys(array $doc, $unique_name = '') {
        $ident = isset($doc['identification']) && is_array($doc['identification']) ? $doc['identification'] : array();
        $names = array($ident['name'] ?? '', $ident['currentName'] ?? '', $unique_name);
        foreach (array('otherNames', 'pastNames') as $k) {
            foreach (kop_facility_pages_text_items($ident[$k] ?? null) as $n) $names[] = $n;
        }
        $keys = array();
        foreach ($names as $n) {
            $k = kop_facility_pages_name_key($n);
            if ($k !== '') $keys[$k] = true;
        }
        return array_keys($keys);
    }
}

if (!function_exists('kop_facility_pages_link_sets')) {
    /**
     * Everything outside facilities_v2 that can make a record worth a page,
     * loaded once for the index build:
     *   news, lawsuits   facility id => count of published records linked by id
     *   lawsuit_keys     name key => lawsuit ids (facilities_mentioned)
     *   memorial_keys    name key => [{location}] (published deaths)
     *   wiki             lowercase name => count (approved wiki entries)
     *   inspections      state code => name key => {rows, reports}
     *   operators        facility id => operator id
     *   folders          name key => FileBird folder id (folders holding files)
     */
    function kop_facility_pages_link_sets() {
        global $wpdb;
        $sets = array('news' => array(), 'lawsuits' => array(), 'lawsuit_keys' => array(), 'memorial_keys' => array(),
                      'wiki' => array(), 'inspections' => array(), 'operators' => array(), 'folders' => array());

        if (kop_facility_pages_table_exists('news_facility_links') && kop_facility_pages_table_exists('news_submissions')) {
            $rows = $wpdb->get_results("SELECT l.facility_id, COUNT(*) AS n FROM news_facility_links l JOIN news_submissions n ON n.id = l.news_id WHERE n.status IN ('approved','published') GROUP BY l.facility_id", ARRAY_A);
            foreach ((array) $rows as $r) $sets['news'][(int) $r['facility_id']] = (int) $r['n'];
        }
        if (kop_facility_pages_table_exists('lawsuits')) {
            if (kop_facility_pages_table_exists('lawsuit_facility_links')) {
                $rows = $wpdb->get_results("SELECT lf.facility_id, COUNT(*) AS n FROM lawsuit_facility_links lf JOIN lawsuits l ON l.id = lf.lawsuit_id WHERE l.publication_status IN ('approved','published') GROUP BY lf.facility_id", ARRAY_A);
                foreach ((array) $rows as $r) $sets['lawsuits'][(int) $r['facility_id']] = (int) $r['n'];
            }
            $rows = $wpdb->get_results("SELECT id, facilities_mentioned FROM lawsuits WHERE publication_status IN ('approved','published')", ARRAY_A);
            foreach ((array) $rows as $r) {
                foreach (kop_facility_pages_mentioned_names($r['facilities_mentioned']) as $n) {
                    $k = kop_facility_pages_name_key($n);
                    if ($k !== '') $sets['lawsuit_keys'][$k][] = (int) $r['id'];
                }
            }
        }
        if (kop_facility_pages_table_exists('memorial_victims')) {
            $rows = $wpdb->get_results("SELECT program, location FROM memorial_victims WHERE publication_status = 'published'", ARRAY_A);
            foreach ((array) $rows as $r) {
                $k = kop_facility_pages_name_key($r['program']);
                if ($k !== '') $sets['memorial_keys'][$k][] = array('location' => trim((string) $r['location']));
            }
        }
        if (kop_facility_pages_table_exists('wiki_submissions')) {
            $rows = $wpdb->get_results("SELECT facility_unique_name, program_name FROM wiki_submissions WHERE status IN ('approved','published')", ARRAY_A);
            foreach ((array) $rows as $r) {
                foreach (array($r['facility_unique_name'], $r['program_name']) as $n) {
                    $n = strtolower(trim((string) $n));
                    if ($n !== '') $sets['wiki'][$n] = ($sets['wiki'][$n] ?? 0) + 1;
                }
            }
        }
        if (kop_facility_pages_table_exists('inspection_facilities')) {
            $reports_join = kop_facility_pages_table_exists('inspection_reports')
                ? 'LEFT JOIN inspection_reports r ON r.facility_id = f.id'
                : '';
            $count = $reports_join !== '' ? 'COUNT(r.id)' : '0';
            $rows = $wpdb->get_results("SELECT f.state, f.facility_name, {$count} AS reports FROM inspection_facilities f {$reports_join} GROUP BY f.id", ARRAY_A);
            foreach ((array) $rows as $r) {
                $name = trim((string) $r['facility_name']);
                if ($name === '' || (function_exists('kop_facility_name_looks_junky') && kop_facility_name_looks_junky($name))) continue;
                $k = kop_facility_pages_name_key($name);
                if ($k === '') continue;
                $state = strtoupper(trim((string) $r['state']));
                if (!isset($sets['inspections'][$state][$k])) $sets['inspections'][$state][$k] = array('rows' => 0, 'reports' => 0);
                $sets['inspections'][$state][$k]['rows']++;
                $sets['inspections'][$state][$k]['reports'] += (int) $r['reports'];
            }
        }
        $ofc = $wpdb->prefix . 'kop_operator_facilities';
        if (kop_facility_pages_table_exists($ofc)) {
            $rows = $wpdb->get_results("SELECT facility_id, operator_id FROM `{$ofc}` ORDER BY sort_order, operator_id", ARRAY_A);
            foreach ((array) $rows as $r) {
                $fid = (int) $r['facility_id'];
                if (!isset($sets['operators'][$fid])) $sets['operators'][$fid] = (int) $r['operator_id'];
            }
        }
        $sets['folders'] = kop_facility_pages_folder_map();
        return $sets;
    }
}

if (!function_exists('kop_facility_pages_mentioned_names')) {
    /** Names out of a facilities_mentioned column (JSON list of strings or {name}). */
    function kop_facility_pages_mentioned_names($value) {
        if (function_exists('kop_normalize_facility_mentions')) {
            $out = array();
            foreach (kop_normalize_facility_mentions($value) as $m) {
                $n = is_array($m) ? ($m['name'] ?? '') : $m;
                if (is_string($n) && trim($n) !== '') $out[] = trim($n);
            }
            return $out;
        }
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        $out = array();
        foreach ((array) $decoded as $m) {
            $n = is_array($m) ? ($m['name'] ?? '') : $m;
            if (is_string($n) && trim($n) !== '') $out[] = trim($n);
        }
        return $out;
    }
}

if (!function_exists('kop_facility_pages_folder_map')) {
    /**
     * FileBird folder name key => folder id, for folders that hold at least
     * one document. Where the tree has duplicates of a name (most of it does,
     * see the media restore notes), the copy with the most files wins.
     */
    function kop_facility_pages_folder_map() {
        $map = array();
        if (!function_exists('kop_get_filebird_folders') || !function_exists('kop_attach_folder_file_counts')) return $map;
        $folders = kop_get_filebird_folders();
        if (empty($folders)) return $map;
        $best = array();
        foreach (kop_attach_folder_file_counts($folders) as $f) {
            $files = (int) ($f['files'] ?? 0);
            if ($files <= 0) continue;
            $k = kop_facility_pages_name_key($f['name'] ?? '');
            if ($k === '') continue;
            if (!isset($best[$k]) || $files > $best[$k]['files']) {
                $best[$k] = array('id' => (int) $f['id'], 'files' => $files);
            }
        }
        foreach ($best as $k => $b) $map[$k] = $b['id'];
        return $map;
    }
}

// ---------------------------------------------------------------------------
// Eligibility
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_pages_has_value')) {
    /** Non-blank string, number, true, or an array holding one of those. */
    function kop_facility_pages_has_value($v) {
        if ($v === null || $v === false || $v === '') return false;
        if (is_string($v)) return trim($v) !== '';
        if (is_bool($v)) return $v;
        if (is_int($v) || is_float($v)) return $v != 0;
        if (is_array($v)) {
            foreach ($v as $item) {
                if (kop_facility_pages_has_value($item)) return true;
            }
        }
        return false;
    }
}

if (!function_exists('kop_facility_pages_text_items')) {
    /**
     * A list field as a flat list of display strings. Entries may be strings
     * or objects ({name, role}, {url, displayText}, {current: [], past: []}).
     * Blank entries (the form's empty rows) are dropped.
     */
    function kop_facility_pages_text_items($value) {
        $out = array();
        if (is_string($value)) {
            if (trim($value) !== '') $out[] = trim($value);
            return $out;
        }
        if (!is_array($value)) return $out;
        foreach ($value as $item) {
            if (is_string($item) || is_numeric($item)) {
                $t = trim((string) $item);
                if ($t !== '') $out[] = $t;
            } elseif (is_array($item)) {
                $text = kop_facility_pages_entry_text($item);
                if ($text !== '') $out[] = $text;
            }
        }
        return $out;
    }
}

if (!function_exists('kop_facility_pages_entry_text')) {
    /**
     * One object entry as "Name (Role), employer" style text. Known keys are
     * printed in a fixed order; other scalar values follow.
     */
    function kop_facility_pages_entry_text(array $item) {
        $get = static function ($k) use ($item) {
            return isset($item[$k]) && is_scalar($item[$k]) ? trim((string) $item[$k]) : '';
        };
        $name = $get('name') ?: $get('displayText') ?: $get('title') ?: $get('text') ?: $get('value');
        $role = $get('role');
        $org = $get('organization') ?: $get('employer');
        $employer = ($get('organization') !== '' && $get('employer') !== '' && $get('employer') !== $org) ? $get('employer') : '';
        $past = $get('pastJobs');
        $url = $get('url') ?: $get('href') ?: $get('link');

        $parts = array();
        if ($name !== '') $parts[] = $name;
        if ($role !== '') $parts[] = $name !== '' ? '(' . $role . ')' : $role;
        $text = implode(' ', $parts);
        if ($org !== '') $text = $text !== '' ? $text . ', ' . $org : $org;
        if ($employer !== '') $text .= ' at ' . $employer;
        if ($past !== '') $text = $text !== '' ? $text . '. Previously: ' . $past : $past;
        if ($text === '' && $url !== '') $text = $url;
        if ($text === '') {
            // Nothing recognizable: print the scalar values in order.
            $vals = array();
            foreach ($item as $k => $v) {
                if (is_scalar($v) && !is_bool($v) && trim((string) $v) !== '' && !in_array($k, array('id', 'timestamp'), true)) $vals[] = trim((string) $v);
            }
            $text = implode(' - ', $vals);
        }
        return $text;
    }
}

if (!function_exists('kop_facility_pages_clean_notes')) {
    /** Free-text notes, minus the migration bookkeeping lines. */
    function kop_facility_pages_clean_notes($value) {
        $out = array();
        foreach (kop_facility_pages_text_items($value) as $t) {
            if (stripos($t, 'migration:') === 0) continue;
            $out[] = $t;
        }
        return $out;
    }
}

if (!function_exists('kop_facility_pages_staff_entries')) {
    /** {administrator: [...], notableStaff: [...], pastTTIJobs: [...]} as text lists, empty groups removed. */
    function kop_facility_pages_staff_entries($staff) {
        $out = array();
        if (!is_array($staff)) return $out;
        foreach (array('administrator', 'notableStaff', 'pastTTIJobs') as $k) {
            $items = kop_facility_pages_text_items($staff[$k] ?? null);
            if ($items) $out[$k] = $items;
        }
        return $out;
    }
}

if (!function_exists('kop_facility_checklist_labels')) {
    /** Checklist key => label, as worded in templates/data-form-admin.php. */
    function kop_facility_checklist_labels() {
        return array(
            'treatmentTypes.hasABA'                   => 'Applied Behavior Analysis',
            'treatmentTypes.hasEquineTherapy'         => 'Equine therapy',
            'treatmentTypes.hasWorkTherapy'           => 'Work therapy',
            'treatmentTypes.hasWildernessTherapy'     => 'Wilderness therapy',
            'treatmentTypes.hasRealityTherapy'        => 'Reality therapy',
            'treatmentTypes.hasLGATSeminars'          => 'Large Group Awareness Training seminars',
            'treatmentTypes.hasFeedbackHotseatGroups' => 'Feedback or hotseat groups (the Game)',
            'treatmentTypes.hasPrimalScreamTherapy'   => 'Primal scream therapy',
            'treatmentTypes.hasRepressedMemoryTherapy' => 'Repressed memory therapy',
            'treatmentTypes.hasBehaviorModification'  => 'Behavior modification',
            'treatmentTypes.hasKetamineTherapy'       => 'Ketamine therapy',
            'treatmentTypes.hasExposureTherapy'       => 'Exposure therapy',
            'treatmentTypes.hasUnlicensedProvider'    => 'Therapy with an unlicensed provider',
            'treatmentTypes.hasConversionTherapy'     => 'Conversion therapy (SOGICE)',
            'treatmentTypes.hasAttachmentTherapy'     => 'Attachment therapy',
            'treatmentTypes.hasRebirthingTherapy'     => 'Rebirthing therapy',
            'treatmentTypes.hasTappingTherapy'        => 'Tapping or Thought Field Therapy',
            'treatmentTypes.hasPsychoanalysis'        => 'Psychoanalysis',
            'treatmentTypes.hasEMDR'                  => 'EMDR',
            'treatmentTypes.hasHypnosis'              => 'Hypnosis',
            'philosophy.hasPositivePeerCulture'       => 'Positive Peer Culture',
            'philosophy.has12Steps'                   => '12 Steps',
            'philosophy.hasFundamentalistBaptist'     => 'Fundamentalist Baptist',
            'philosophy.hasPentecostal'               => 'Pentecostal',
            'philosophy.hasScientology'               => 'Scientology',
            'philosophy.hasTherapeuticCommunity'      => 'Therapeutic Community',
            'philosophy.hasWildernessRoad'            => 'Wilderness Road',
            'philosophy.hasPsychoanalytic'            => 'Psychoanalytic',
            'philosophy.hasLawOfAttraction'           => 'Law of Attraction',
            'philosophy.hasHumanPotentialMovement'    => 'Human Potential Movement',
            'criticalIncidents.hasDeaths'             => 'Deaths',
            'criticalIncidents.hasStaffArrests'       => 'Staff arrests',
            'criticalIncidents.hasStudentHospitalizations' => 'Student hospitalizations',
            'criticalIncidents.hasRiots'              => 'Riots',
        );
    }
}

if (!function_exists('kop_facility_pages_humanize_key')) {
    /** "hasWildernessTherapy" -> "Wilderness therapy". */
    function kop_facility_pages_humanize_key($key) {
        $text = preg_replace('/^has(?=[A-Z0-9])/', '', (string) $key);
        $text = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $text);
        $text = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $text);
        $text = trim($text);
        if ($text === '') return '';
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_strtolower(mb_substr($text, 1));
    }
}

if (!function_exists('kop_facility_pages_checklist_items')) {
    /**
     * Labels of the checked entries in a checklist map (treatmentTypes,
     * philosophy, conditions, criticalIncidents), plus any custom or legacy
     * free-text entries it carries.
     */
    function kop_facility_pages_checklist_items($map, $section = '') {
        $out = array();
        if (!is_array($map)) return $out;
        $labels = kop_facility_checklist_labels();
        foreach ($map as $k => $v) {
            if ($k === '_legacy' || strpos((string) $k, 'custom') === 0) {
                foreach (kop_facility_pages_text_items($v) as $t) $out[] = $t;
                continue;
            }
            if ($v === true || (is_string($v) && strtolower(trim($v)) === 'true')) {
                $label = $labels[$section . '.' . $k] ?? kop_facility_pages_humanize_key($k);
                if ($label !== '') $out[] = $label;
            } elseif (is_string($v) && trim($v) !== '' && !in_array(strtolower(trim($v)), array('false', '0', 'no'), true)) {
                $label = $labels[$section . '.' . $k] ?? kop_facility_pages_humanize_key($k);
                $out[] = $label !== '' ? $label . ': ' . trim($v) : trim($v);
            }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('kop_facility_pages_field_notes')) {
    /**
     * Per-field research notes as [{label, text}]. Keys are dotted field
     * paths ("treatmentTypes.hasABA") or generated "field-<id>" keys with no
     * readable label; values are strings, lists of strings, or lists of
     * {id, text} objects.
     */
    function kop_facility_pages_field_notes($value) {
        $out = array();
        if (!is_array($value)) return $out;
        $labels = kop_facility_checklist_labels();
        foreach ($value as $key => $v) {
            $key = (string) $key;
            $label = '';
            if (!preg_match('/^field-\d/', $key) && $key !== '_legacy') {
                if (isset($labels[$key])) {
                    $label = $labels[$key];
                } else {
                    $bits = array();
                    foreach (explode('.', $key) as $part) {
                        $h = kop_facility_pages_humanize_key($part);
                        if ($h !== '') $bits[] = $h;
                    }
                    $label = implode(': ', $bits);
                }
            }
            $texts = array();
            if (is_string($v)) {
                if (trim($v) !== '') $texts[] = trim($v);
            } elseif (is_array($v)) {
                foreach ($v as $note) {
                    if (is_string($note) && trim($note) !== '') {
                        $texts[] = trim($note);
                    } elseif (is_array($note) && isset($note['text']) && is_string($note['text']) && trim($note['text']) !== '') {
                        $texts[] = trim($note['text']);
                    }
                }
            }
            foreach ($texts as $t) $out[] = array('label' => $label, 'text' => $t);
        }
        return $out;
    }
}

if (!function_exists('kop_facility_resource_catalog')) {
    /**
     * resources.* flag => [label, group]. Mirrors the CATALOG in
     * js/shared/facility-resources.js; keep the two in step.
     */
    function kop_facility_resource_catalog() {
        return array(
            'hasNews'                   => array('News articles', 'News and media'),
            'hasPressReleases'          => array('Press releases', 'News and media'),
            'hasSocialMedia'            => array('Social media', 'News and media'),
            'hasVideo'                  => array('Video', 'News and media'),
            'hasAudio'                  => array('Audio', 'News and media'),
            'hasInspections'            => array('Inspection reports', 'Official documentation'),
            'hasStateReports'           => array('State reports', 'Official documentation'),
            'hasRegulatoryFilings'      => array('Regulatory filings', 'Official documentation'),
            'hasLawsuits'               => array('Lawsuits', 'Legal and compliance'),
            'hasPoliceReports'          => array('Police reports', 'Legal and compliance'),
            'hasSettlements'            => array('Settlements', 'Legal and compliance'),
            'hasViolations'             => array('Documented violations', 'Legal and compliance'),
            'hasArticlesOfOrganization' => array('Articles of organization', 'Business and property'),
            'hasPropertyRecords'        => array('Property records', 'Business and property'),
            'hasPromotionalMaterials'   => array('Promotional materials', 'Business and property'),
            'hasEnrollmentDocuments'    => array('Enrollment documents', 'Business and property'),
            'hasFinancial'              => array('Financial reports', 'Business and property'),
            'hasStudent'                => array('Student or resident manual', 'Manuals and handbooks'),
            'hasStaff'                  => array('Staff manual', 'Manuals and handbooks'),
            'hasParent'                 => array('Parent manual', 'Manuals and handbooks'),
            'hasResearch'               => array('Academic research', 'Other documentation'),
            'hasSurvivorStories'        => array('Survivor stories', 'Other documentation'),
            'hasNATSAP'                 => array('NATSAP profile', 'Other documentation'),
            'hasWebsite'                => array('Archived website', 'Other documentation'),
            'hasOther'                  => array('Other documentation', 'Other documentation'),
        );
    }
}

if (!function_exists('kop_facility_pages_resources_held')) {
    /**
     * Materials the project holds for this facility: [{label, group, detail}].
     * Only flags set true, detail text, custom entries and notes count.
     */
    function kop_facility_pages_resources_held($resources) {
        $out = array();
        if (!is_array($resources)) return $out;
        $catalog = kop_facility_resource_catalog();
        $seen = array();
        foreach ($resources as $k => $v) {
            if (strpos((string) $k, 'has') === 0) {
                if ($v !== true) continue;
                $meta = $catalog[$k] ?? array(kop_facility_pages_humanize_key($k), 'Other documentation');
                $detail_key = preg_replace('/^has/', '', $k);
                $detail_key = lcfirst($detail_key) . 'Details';
                $detail = isset($resources[$detail_key]) && is_string($resources[$detail_key]) ? trim($resources[$detail_key]) : '';
                $out[] = array('label' => $meta[0], 'group' => $meta[1], 'detail' => $detail);
                $seen[$detail_key] = true;
            }
        }
        foreach ($resources as $k => $v) {
            if (substr((string) $k, -7) === 'Details' && !isset($seen[$k]) && is_string($v) && trim($v) !== '') {
                $flag = 'has' . ucfirst(substr($k, 0, -7));
                $meta = $catalog[$flag] ?? array(kop_facility_pages_humanize_key($flag), 'Other documentation');
                $out[] = array('label' => $meta[0], 'group' => $meta[1], 'detail' => trim($v));
            }
        }
        foreach (kop_facility_pages_text_items($resources['customResources'] ?? null) as $t) {
            $out[] = array('label' => $t, 'group' => 'Other documentation', 'detail' => '');
        }
        foreach (kop_facility_pages_text_items($resources['notes'] ?? null) as $t) {
            $out[] = array('label' => 'Note', 'group' => 'Other documentation', 'detail' => $t);
        }
        return $out;
    }
}

if (!function_exists('kop_facility_page_signals')) {
    /**
     * What a record has beyond its name, address and the default status.
     * A facility gets a page when this list is not empty. Each entry names
     * the field or linked table that qualified it, which the test harness
     * reports and 'kop_facility_page_signals' can filter.
     *
     * @param array  $doc         v2 document
     * @param int    $id          facilities_v2 id
     * @param array  $links       kop_facility_pages_link_sets()
     * @param string $unique_name the row's unique_name (matched against wiki entries)
     * @return string[]
     */
    function kop_facility_page_signals(array $doc, $id, array $links = array(), $unique_name = '') {
        $s = array();
        $ident = isset($doc['identification']) && is_array($doc['identification']) ? $doc['identification'] : array();
        $name = trim((string) ($ident['name'] ?? ''));

        foreach (array('otherNames', 'pastNames', 'currentOwners', 'otherOperators', 'pastOperators', 'knownReferrers', 'investors') as $k) {
            if (kop_facility_pages_text_items($ident[$k] ?? null)) $s[] = 'identification.' . $k;
        }
        if (kop_facility_pages_has_value($ident['currentOperator'] ?? null)) $s[] = 'identification.currentOperator';
        $current = trim((string) ($ident['currentName'] ?? ''));
        if ($current !== '' && strcasecmp($current, $name) !== 0) $s[] = 'identification.currentName';

        $op = isset($doc['operatingPeriod']) && is_array($doc['operatingPeriod']) ? $doc['operatingPeriod'] : array();
        if (!empty($op['startYear'])) $s[] = 'operatingPeriod.startYear';
        if (!empty($op['endYear'])) $s[] = 'operatingPeriod.endYear';
        if (kop_facility_pages_has_value($op['yearsOfOperation'] ?? null)) $s[] = 'operatingPeriod.yearsOfOperation';
        // "Open" is the default and "Closed" without a year is a one-word
        // page; a suspended license or a transfer is worth stating.
        $status = trim((string) ($op['status'] ?? ''));
        if (in_array($status, array('Suspended', 'Transferred'), true)) $s[] = 'operatingPeriod.status';
        if (kop_facility_pages_clean_notes($op['notes'] ?? null)) $s[] = 'operatingPeriod.notes';

        $fd = isset($doc['facilityDetails']) && is_array($doc['facilityDetails']) ? $doc['facilityDetails'] : array();
        foreach (array('type', 'capacity', 'currentCensus', 'gender') as $k) {
            if (kop_facility_pages_has_value($fd[$k] ?? null)) $s[] = 'facilityDetails.' . $k;
        }
        if (kop_facility_pages_has_value($fd['ageRange'] ?? null)) $s[] = 'facilityDetails.ageRange';

        if (kop_facility_pages_staff_entries($doc['staff'] ?? null)) $s[] = 'staff';
        foreach (array('accreditations', 'memberships', 'certifications', 'licensing', 'profileLinks', 'notes') as $k) {
            $v = $doc[$k] ?? null;
            $items = $k === 'accreditations' && is_array($v)
                ? array_merge(kop_facility_pages_text_items($v['current'] ?? null), kop_facility_pages_text_items($v['past'] ?? null))
                : ($k === 'notes' ? kop_facility_pages_clean_notes($v) : kop_facility_pages_text_items($v));
            if ($items) $s[] = $k;
        }
        foreach (array('treatmentTypes', 'philosophy', 'conditions', 'criticalIncidents') as $k) {
            if (kop_facility_pages_checklist_items($doc[$k] ?? null, $k)) $s[] = $k;
        }
        if (kop_facility_pages_field_notes($doc['fieldNotes'] ?? null)) $s[] = 'fieldNotes';
        if (kop_facility_pages_resources_held($doc['resources'] ?? null)) $s[] = 'resources';

        $loc = isset($doc['location']) && is_array($doc['location']) ? $doc['location'] : array();
        if (kop_facility_pages_has_value($loc['additionalLocations'] ?? null)) $s[] = 'location.additionalLocations';
        if (kop_facility_pages_has_value($loc['formerLocations'] ?? null)) $s[] = 'location.formerLocations';
        if (!empty($doc['documentFolderId'])) $s[] = 'documentFolderId';

        // Linked records.
        $id = (int) $id;
        if (!empty($links['news'][$id])) $s[] = 'news';
        if (!empty($links['lawsuits'][$id])) $s[] = 'lawsuits';
        if (!empty($links['operators'][$id])) $s[] = 'operator';

        $keys = kop_facility_pages_doc_name_keys($doc, $unique_name);
        $state_code = strtoupper(trim((string) ($loc['state'] ?? '')));
        $state_name = ($state_code !== '' && function_exists('kop_state_canonical_name')) ? kop_state_canonical_name($state_code) : $state_code;

        if (!in_array('lawsuits', $s, true) && !empty($links['lawsuit_keys'])) {
            foreach ($keys as $fk) {
                foreach ($links['lawsuit_keys'] as $rk => $ids) {
                    if (kop_facility_pages_key_matches($fk, $rk)) { $s[] = 'lawsuits'; break 2; }
                }
            }
        }
        if (!empty($links['memorial_keys'])) {
            foreach ($keys as $fk) {
                foreach ($links['memorial_keys'] as $rk => $entries) {
                    $exact = ($fk === $rk);
                    if (!$exact && !kop_facility_pages_key_matches($fk, $rk)) continue;
                    foreach ($entries as $e) {
                        if (!$exact && $state_name !== '' && $e['location'] !== '') {
                            $loc_name = function_exists('kop_state_canonical_name') ? kop_state_canonical_name($e['location']) : $e['location'];
                            if (strcasecmp($loc_name, $state_name) !== 0) continue;
                        }
                        $s[] = 'memorials';
                        break 3;
                    }
                }
            }
        }
        if (!empty($links['wiki'])) {
            foreach (array($name, $unique_name, $current) as $n) {
                $n = strtolower(trim((string) $n));
                if ($n !== '' && isset($links['wiki'][$n])) { $s[] = 'wiki'; break; }
            }
        }
        if (!empty($links['inspections'])) {
            $pools = $state_code !== '' ? array($state_code) : array_keys($links['inspections']);
            foreach ($pools as $pool) {
                foreach ($keys as $fk) {
                    if (isset($links['inspections'][$pool][$fk])) { $s[] = 'inspections'; break 2; }
                }
            }
        }
        if (!empty($links['folders']) && !in_array('documentFolderId', $s, true)) {
            foreach ($keys as $fk) {
                if (isset($links['folders'][$fk])) { $s[] = 'documents'; break; }
            }
        }

        return apply_filters('kop_facility_page_signals', array_values(array_unique($s)), $doc, $id);
    }
}

// ---------------------------------------------------------------------------
// Links
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_page_url')) {
    /**
     * The page for a facilities_v2 id: the editorial profile when one exists,
     * the generated page when the record qualifies, '' otherwise.
     */
    function kop_facility_page_url($facility_id) {
        $facility_id = (int) $facility_id;
        if ($facility_id <= 0) return '';
        $index = kop_facility_pages_index();
        if (!isset($index['ids'][$facility_id])) return '';
        $entry = $index['ids'][$facility_id];
        if ($entry['editorial'] !== '') return $entry['editorial'];
        return kop_facility_pages_url_for_slug($entry['slug']);
    }
}

if (!function_exists('kop_facility_page_url_for_name')) {
    /**
     * Profile URL for a facility named in free text (a story arc's facility
     * label, a mention in an article), or '' when no record of that name has
     * a page. Only records in the page index are considered, so an operator
     * of the same name can never be mistaken for the facility. An exact
     * (case-insensitive) name match wins over the normalized spelling; within
     * a pass the lowest id wins, as in kop_v2_name_id_map().
     */
    function kop_facility_page_url_for_name($name) {
        $name = trim((string) $name);
        if ($name === '') return '';
        $index = kop_facility_pages_index();
        if (empty($index['ids'])) return '';
        $exact = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
        $loose = kop_facility_pages_name_key($name);
        $loose_hit = 0;
        foreach ($index['ids'] as $id => $entry) {
            $entry_name = (string) $entry['name'];
            $entry_exact = function_exists('mb_strtolower') ? mb_strtolower($entry_name) : strtolower($entry_name);
            if ($entry_exact === $exact) return kop_facility_page_url((int) $id);
            if ($loose_hit === 0 && $loose !== '' && kop_facility_pages_name_key($entry_name) === $loose) $loose_hit = (int) $id;
        }
        return $loose_hit > 0 ? kop_facility_page_url($loose_hit) : '';
    }
}

if (!function_exists('kop_facility_pages_thin_target')) {
    /** Where a record without a page of its own sends the visitor. */
    function kop_facility_pages_thin_target($facility_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT name, state, country FROM facilities_v2 WHERE id = %d', (int) $facility_id), ARRAY_A);
        if (!$row) return '';
        $hub = function_exists('kop_v2_place_page_url') ? kop_v2_place_page_url($row['state'] ?: null, $row['country'] ?: null) : '';
        // No hub for the place: the location index lists every facility, the
        // program index only operators and chains.
        if ($hub === '') return kop_facility_pages_location_search_url($row['name']);
        return add_query_arg('search', rawurlencode((string) $row['name']), $hub);
    }
}

// ---------------------------------------------------------------------------
// View model
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_pages_format_address')) {
    /** One address line from a {raw, street, city, state, zip, country} part. */
    function kop_facility_pages_format_address(array $part) {
        $raw = trim((string) ($part['raw'] ?? ''));
        if ($raw !== '') return $raw;
        $bits = array();
        foreach (array('street', 'city') as $k) {
            $v = trim((string) ($part[$k] ?? ''));
            if ($v !== '') $bits[] = $v;
        }
        $state = trim((string) ($part['state'] ?? ''));
        $zip = trim((string) ($part['zip'] ?? ''));
        if ($state !== '' || $zip !== '') $bits[] = trim($state . ' ' . $zip);
        $country = trim((string) ($part['country'] ?? ''));
        if ($country !== '' && strcasecmp($country, 'United States') !== 0) $bits[] = $country;
        return implode(', ', $bits);
    }
}

if (!function_exists('kop_facility_pages_parse_date')) {
    /** First date in a report_date value (M/D/YYYY, YYYY-MM-DD, ranges) as a timestamp, or 0. */
    function kop_facility_pages_parse_date($value) {
        $value = trim((string) $value);
        if ($value === '') return 0;
        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $value, $m)) {
            $ts = mktime(0, 0, 0, (int) $m[1], (int) $m[2], (int) $m[3]);
            return $ts ?: 0;
        }
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            $ts = mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
            return $ts ?: 0;
        }
        $ts = strtotime($value);
        return $ts ?: 0;
    }
}

if (!function_exists('kop_facility_pages_date_label')) {
    function kop_facility_pages_date_label($value) {
        $ts = kop_facility_pages_parse_date($value);
        return $ts ? date('M j, Y', $ts) : trim((string) $value);
    }
}

if (!function_exists('kop_facility_pages_location_search_url')) {
    /** The location index filtered to a facility name. Every facility is listed there. */
    function kop_facility_pages_location_search_url($name) {
        $index_url = function_exists('kop_asl_page_url_by_template') ? kop_asl_page_url_by_template('page-location-index.php') : '';
        if ($index_url === '') $index_url = home_url('/location-index/');
        return add_query_arg('search', rawurlencode((string) $name), $index_url);
    }
}

if (!function_exists('kop_facility_pages_index_search_url')) {
    /** The program index filtered to a name. Operators and chains only; never a facility. */
    function kop_facility_pages_index_search_url($name) {
        $index_url = function_exists('kop_asl_page_url_by_template') ? kop_asl_page_url_by_template('page-tti-program-index.php') : '';
        if ($index_url === '') $index_url = home_url('/tti-program-index/');
        return add_query_arg('search', rawurlencode((string) $name), $index_url);
    }
}

if (!function_exists('kop_facility_pages_page_url_by_template')) {
    function kop_facility_pages_page_url_by_template($template, $fallback) {
        $url = function_exists('kop_asl_page_url_by_template') ? kop_asl_page_url_by_template($template) : '';
        return $url !== '' ? $url : home_url($fallback);
    }
}

if (!function_exists('kop_facility_pages_type_phrase')) {
    /** "Residential Treatment Center" -> "residential treatment center"; acronyms keep their case. */
    function kop_facility_pages_type_phrase($type) {
        $words = preg_split('/\s+/', trim((string) $type));
        $out = array();
        foreach ($words as $w) {
            if ($w === '') continue;
            $out[] = preg_match('/[A-Z].*[A-Z]/', $w) ? $w : mb_strtolower($w);
        }
        return implode(' ', $out);
    }
}

if (!function_exists('kop_facility_pages_summary_sentence')) {
    /**
     * A factual opening line built only from fields on file:
     * "Provo Canyon School is a residential treatment center in Provo, Utah,
     * operated by Universal Health Services. It has operated since 1971."
     */
    function kop_facility_pages_summary_sentence($name, $type, $place, $operator, $status, $start, $end, $years_text) {
        $closed = in_array($status, array('Closed', 'Transferred'), true) || ($end !== '' && $end !== null);
        $verb = $closed ? 'was' : 'is';
        $noun = $type !== '' ? kop_facility_pages_type_phrase($type) : 'program';
        $article = preg_match('/^[aeiou]/i', $noun) ? 'an' : 'a';
        $first = $name . ' ' . $verb . ' ' . $article . ' ' . $noun;
        if ($place !== '') $first .= ' in ' . $place;
        if ($operator !== '') $first .= ', operated by ' . $operator;
        if (substr($first, -1) !== '.') $first .= '.';   // "Three Springs Inc." already ends the sentence

        $second = '';
        $start = trim((string) $start);
        $end = trim((string) $end);
        if ($start !== '' && $end !== '') {
            $second = 'It operated from ' . $start . ' to ' . $end . '.';
        } elseif ($start !== '') {
            $second = $closed ? 'It opened in ' . $start . '.' : 'It has operated since ' . $start . '.';
        } elseif ($end !== '') {
            $second = 'It closed in ' . $end . '.';
        } elseif ($years_text !== '') {
            $second = 'Years of operation: ' . $years_text . '.';
        }
        if ($status === 'Suspended') $second = trim($second . ' Its license is listed as suspended.');
        if ($status === 'Transferred') $second = trim($second . ' The program was transferred to another operator.');
        return trim($first . ' ' . $second);
    }
}

if (!function_exists('kop_facility_page_data')) {
    /**
     * Everything templates/facility-page.php prints for one facility, or
     * null when the row is missing. Reads facilities_v2 and the linked
     * tables; nothing here writes.
     */
    function kop_facility_page_data($facility_id) {
        global $wpdb;
        $facility_id = (int) $facility_id;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, unique_name, name, state, city, country, status, updated_at, json_data FROM facilities_v2 WHERE id = %d',
            $facility_id
        ), ARRAY_A);
        if (!$row) return null;
        $doc = kop_v2_decode($row['json_data']);
        if ($doc === null) return null;

        $index = kop_facility_pages_index();
        $entry = isset($index['ids'][$facility_id]) ? $index['ids'][$facility_id] : null;

        $ident = $doc['identification'];
        $loc = $doc['location'];
        $op = isset($doc['operatingPeriod']) && is_array($doc['operatingPeriod']) ? $doc['operatingPeriod'] : array();
        $fd = isset($doc['facilityDetails']) && is_array($doc['facilityDetails']) ? $doc['facilityDetails'] : array();

        $name = trim((string) $row['name']) ?: trim((string) ($ident['name'] ?? ''));
        $unique_name = (string) $row['unique_name'];
        $current_name = trim((string) ($ident['currentName'] ?? ''));
        if (strcasecmp($current_name, $name) === 0) $current_name = '';

        $state_code = strtoupper(trim((string) ($loc['state'] ?? '')));
        $state_name = ($state_code !== '' && function_exists('kop_state_canonical_name')) ? kop_state_canonical_name($state_code) : '';
        if (strlen($state_name) <= 2) $state_name = '';
        $country = trim((string) ($loc['country'] ?? ''));
        $city = trim((string) ($loc['city'] ?? ''));
        $place_bits = array();
        if ($city !== '') $place_bits[] = $city;
        if ($state_name !== '') {
            $place_bits[] = $state_name;
        } elseif ($country !== '' && strcasecmp($country, 'United States') !== 0) {
            $place_bits[] = $country;
        }
        $place = implode(', ', $place_bits);
        $hub_name = $state_name !== '' ? $state_name : (($country !== '' && strcasecmp($country, 'United States') !== 0) ? $country : '');
        $hub_url = function_exists('kop_v2_place_page_url') ? kop_v2_place_page_url($state_code ?: null, $country ?: null) : '';

        $status = trim((string) ($op['status'] ?? ''));
        if ($status === '' ) $status = 'Unknown';
        $start = $op['startYear'] ?? null;
        $end = $op['endYear'] ?? null;
        $start = ($start === null || $start === '') ? '' : (string) $start;
        $end = ($end === null || $end === '') ? '' : (string) $end;
        $years_text = trim((string) ($op['yearsOfOperation'] ?? ''));
        if ($start !== '' && $end !== '') {
            $operated = $start . ' to ' . $end;
        } elseif ($start !== '') {
            $operated = $status === 'Closed' ? 'Opened ' . $start : $start . ' to present';
        } elseif ($end !== '') {
            $operated = 'Closed ' . $end;
        } else {
            $operated = $years_text;
        }

        // ---- Operator and sibling programs --------------------------------
        $operator_name = trim((string) ($ident['currentOperator'] ?? ''));
        $operator_row = null;
        if (function_exists('kop_v2_operators_for_facilities')) {
            $ops = kop_v2_operators_for_facilities(array($facility_id));
            if (isset($ops[$facility_id])) $operator_row = $ops[$facility_id];
        }
        if ($operator_name === '' && $operator_row) $operator_name = trim((string) $operator_row['name']);
        $siblings = array();
        if ($operator_row) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT f.id, f.name, f.city, f.state, f.country, f.status
                   FROM {$wpdb->prefix}kop_operator_facilities ofc
                   JOIN facilities_v2 f ON f.id = ofc.facility_id
                  WHERE ofc.operator_id = %d AND f.id <> %d
                  ORDER BY f.name
                  LIMIT 80",
                (int) $operator_row['id'], $facility_id
            ), ARRAY_A);
            foreach ((array) $rows as $r) {
                $s_state = ($r['state'] && function_exists('kop_state_canonical_name')) ? kop_state_canonical_name($r['state']) : '';
                $s_place = trim(($r['city'] ? $r['city'] . ', ' : '') . ($s_state ?: ($r['country'] && $r['country'] !== 'United States' ? $r['country'] : '')), ', ');
                $siblings[] = array(
                    'name'   => (string) $r['name'],
                    'place'  => $s_place,
                    'status' => (string) $r['status'],
                    'url'    => kop_facility_page_url((int) $r['id']),
                );
            }
        }

        // ---- Addresses ------------------------------------------------------
        $addresses = array();
        $primary = kop_facility_pages_format_address($loc);
        if ($primary === '' && trim((string) ($loc['text'] ?? '')) !== '') $primary = trim((string) $loc['text']);
        if ($primary !== '') $addresses[] = $primary;
        foreach ((array) ($loc['additionalLocations'] ?? array()) as $alt) {
            if (!is_array($alt)) continue;
            $line = kop_facility_pages_format_address($alt);
            if ($line !== '' && !in_array(strtolower($line), array_map('strtolower', $addresses), true)) $addresses[] = $line;
        }
        $former = array();
        foreach ((array) ($loc['formerLocations'] ?? array()) as $fl) {
            if (!is_array($fl)) continue;
            $line = kop_facility_pages_format_address($fl);
            if ($line === '') {
                $fl_state = ($fl['state'] ?? '') && function_exists('kop_state_canonical_name') ? kop_state_canonical_name($fl['state']) : (string) ($fl['state'] ?? '');
                $line = trim(implode(', ', array_filter(array($fl['city'] ?? '', $fl_state, $fl['country'] ?? ''))));
            }
            $from = ($fl['fromYear'] ?? null) ? (string) $fl['fromYear'] : '';
            $to = ($fl['toYear'] ?? null) ? (string) $fl['toYear'] : '';
            $years = ($from !== '' && $to !== '') ? $from . ' to ' . $to : ($from !== '' ? 'from ' . $from : ($to !== '' ? 'until ' . $to : ''));
            if ($line !== '') $former[] = array('line' => $line, 'years' => $years);
        }

        // ---- Names ------------------------------------------------------------
        $lower_name = strtolower($name);
        $formerly = array();
        foreach (kop_facility_pages_text_items($ident['pastNames'] ?? null) as $n) {
            if (strtolower($n) !== $lower_name) $formerly[] = $n;
        }
        $aka = array();
        foreach (kop_facility_pages_text_items($ident['otherNames'] ?? null) as $n) {
            if (strtolower($n) !== $lower_name && !in_array($n, $formerly, true)) $aka[] = $n;
        }
        $formerly = array_values(array_unique($formerly));
        $aka = array_values(array_unique($aka));

        // ---- Facts rail -------------------------------------------------------
        $facts = array();
        $add_fact = static function ($label, $value) use (&$facts) {
            if (is_array($value)) {
                $value = array_values(array_filter(array_map('strval', $value), 'strlen'));
                if (!$value) return;
            } else {
                $value = trim((string) $value);
                if ($value === '') return;
            }
            $facts[] = array('label' => $label, 'value' => $value);
        };
        $type = trim((string) ($fd['type'] ?? ''));
        $add_fact('Type', $type);
        if ($operated !== '') $add_fact('Operated', $operated);
        $ages = '';
        $age_min = $fd['ageRange']['min'] ?? null;
        $age_max = $fd['ageRange']['max'] ?? null;
        if ($age_min !== null && $age_min !== '' && $age_max !== null && $age_max !== '') {
            $ages = $age_min . ' to ' . $age_max;
        } elseif ($age_min !== null && $age_min !== '') {
            $ages = $age_min . ' and up';
        } elseif ($age_max !== null && $age_max !== '') {
            $ages = 'up to ' . $age_max;
        }
        $gender = trim((string) ($fd['gender'] ?? ''));
        $serves = trim(($gender !== '' ? $gender : '') . ($ages !== '' ? ($gender !== '' ? ', ages ' : 'Ages ') . $ages : ''), ', ');
        $add_fact('Serves', $serves);
        $add_fact('Capacity', isset($fd['capacity']) && $fd['capacity'] !== null && $fd['capacity'] !== '' ? (string) $fd['capacity'] : '');
        $add_fact('Current census', isset($fd['currentCensus']) && $fd['currentCensus'] !== null && $fd['currentCensus'] !== '' ? (string) $fd['currentCensus'] : '');
        if (isset($fd['isPrivatelyOwned']) && $fd['isPrivatelyOwned'] !== null) {
            $add_fact('Ownership', $fd['isPrivatelyOwned'] ? 'Privately owned' : 'Publicly operated');
        }
        $add_fact('Operator', $operator_name);
        $add_fact('Owners', kop_facility_pages_text_items($ident['currentOwners'] ?? null));
        $add_fact('Other operators', kop_facility_pages_text_items($ident['otherOperators'] ?? null));
        $add_fact('Past operators', kop_facility_pages_text_items($ident['pastOperators'] ?? null));
        $add_fact('Investors', kop_facility_pages_text_items($ident['investors'] ?? null));
        $add_fact('Known referrers', kop_facility_pages_text_items($ident['knownReferrers'] ?? null));
        $accr = isset($doc['accreditations']) && is_array($doc['accreditations']) ? $doc['accreditations'] : array();
        $add_fact('Accreditation', kop_facility_pages_text_items($accr['current'] ?? null));
        $add_fact('Past accreditation', kop_facility_pages_text_items($accr['past'] ?? null));
        $add_fact('Memberships', kop_facility_pages_text_items($doc['memberships'] ?? null));
        $add_fact('Certifications', kop_facility_pages_text_items($doc['certifications'] ?? null));
        $add_fact('Licensing', kop_facility_pages_text_items($doc['licensing'] ?? null));

        // ---- Practices, staff, notes, links --------------------------------------
        $practices = array();
        foreach (array('treatmentTypes' => 'Treatment methods', 'philosophy' => 'Program philosophy', 'conditions' => 'Conditions treated', 'criticalIncidents' => 'Critical incidents on record') as $k => $label) {
            $items = kop_facility_pages_checklist_items($doc[$k] ?? null, $k);
            if ($items) $practices[] = array('label' => $label, 'items' => $items);
        }
        $staff = kop_facility_pages_staff_entries($doc['staff'] ?? null);
        $notes = array_merge(kop_facility_pages_clean_notes($doc['notes'] ?? null), kop_facility_pages_clean_notes($op['notes'] ?? null));
        $field_notes = kop_facility_pages_field_notes($doc['fieldNotes'] ?? null);
        $profile_links = array();
        foreach ((array) ($doc['profileLinks'] ?? array()) as $link) {
            $url = '';
            $label = '';
            if (is_string($link)) {
                $url = trim($link);
            } elseif (is_array($link)) {
                foreach (array('url', 'href', 'link') as $k) {
                    if (!empty($link[$k]) && is_string($link[$k])) { $url = trim($link[$k]); break; }
                }
                foreach (array('displayText', 'label', 'title', 'name') as $k) {
                    if (!empty($link[$k]) && is_string($link[$k])) { $label = trim($link[$k]); break; }
                }
            }
            if ($url === '' || !preg_match('#^https?://#i', $url)) continue;
            if ($label === '') {
                $host = wp_parse_url($url, PHP_URL_HOST);
                $label = $host ? preg_replace('/^www\./', '', $host) : $url;
                if (stripos($url, 'web.archive.org') !== false) $label = 'Archived website (Wayback Machine)';
            }
            $profile_links[] = array('url' => $url, 'label' => $label);
        }
        $resources = kop_facility_pages_resources_held($doc['resources'] ?? null);

        // ---- Linked records ----------------------------------------------------
        $name_keys = kop_facility_pages_doc_name_keys($doc, $unique_name);
        $news = kop_facility_pages_news($facility_id);
        $lawsuits = kop_facility_pages_lawsuits($facility_id, $name_keys);
        $memorials = kop_facility_pages_memorials($name_keys, $state_name);
        $wiki = kop_facility_pages_wiki($name, $unique_name, $current_name);
        $inspections = kop_facility_pages_inspections($name_keys, $state_code, $state_name);
        $documents = kop_facility_pages_documents($doc, $entry ? $entry['folder'] : 0);

        // ---- Copy ----------------------------------------------------------------
        $summary = kop_facility_pages_summary_sentence($name, $type, $place, $operator_name, $status, $start, $end, $years_text);
        $seo_title = $name . ($place !== '' ? ' (' . $place . ')' : '') . ' | Kids Over Profits';
        $seo_description = $summary;
        if (mb_strlen($seo_description) > 155) {
            $seo_description = rtrim(mb_substr($seo_description, 0, 152), " ,;:") . '...';
        }

        $slug = $entry ? $entry['slug'] : kop_facility_page_slug_candidate($name, $state_code, $country);
        $updated = (string) $row['updated_at'];

        return array(
            'id'            => $facility_id,
            'slug'          => $slug,
            'url'           => kop_facility_pages_url_for_slug($slug),
            'name'          => $name,
            'unique_name'   => $unique_name,
            'current_name'  => $current_name,
            'formerly'      => $formerly,
            'aka'           => $aka,
            'status'        => $status,
            'status_class'  => sanitize_html_class(strtolower($status)),
            'place'         => $place,
            'city'          => $city,
            'state_code'    => $state_code,
            'state_name'    => $state_name,
            'country'       => $country,
            'hub_name'      => $hub_name,
            'hub_url'       => $hub_url,
            'operator'      => array(
                'name' => $operator_name,
                'url'  => $operator_name !== '' ? kop_facility_pages_index_search_url($operator_name) : '',
            ),
            'siblings'      => $siblings,
            'addresses'     => $addresses,
            'former_locations' => $former,
            'operated'      => $operated,
            'summary'       => $summary,
            'facts'         => $facts,
            'practices'     => $practices,
            'staff'         => $staff,
            'notes'         => $notes,
            'field_notes'   => $field_notes,
            'profile_links' => $profile_links,
            'resources'     => $resources,
            'news'          => $news,
            'lawsuits'      => $lawsuits,
            'memorials'     => $memorials,
            'wiki'          => $wiki,
            'inspections'   => $inspections,
            'documents'     => $documents,
            'updated_at'    => $updated,
            'updated_label' => $updated !== '' ? date_i18n(get_option('date_format') ?: 'F j, Y', strtotime($updated) ?: time()) : '',
            'index_url'     => kop_facility_pages_location_search_url($name),
            'lawsuits_url'  => kop_facility_pages_page_url_by_template('page-lawsuits.php', '/lawsuits/'),
            'memorial_url'  => kop_facility_pages_page_url_by_template('page-memorial.php', '/memorial/'),
            'news_url'      => kop_facility_pages_page_url_by_template('page-news-feed.php', '/news/'),
            'wiki_url'      => add_query_arg('search', rawurlencode($name), kop_facility_pages_page_url_by_template('page-wiki-feed.php', '/wiki-feed/')),
            'submit_url'    => home_url('/tti-data-submission/'),
            'seo_title'     => $seo_title,
            'seo_description' => $seo_description,
            'doc'           => $doc,
        );
    }
}

if (!function_exists('kop_facility_pages_news')) {
    /** Published articles linked to the facility by id, newest first. */
    function kop_facility_pages_news($facility_id) {
        global $wpdb;
        if (!kop_facility_pages_table_exists('news_facility_links') || !kop_facility_pages_table_exists('news_submissions')) return array();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT n.id, n.article_title, n.alternate_title, n.publication_name, n.publication_date, n.article_url, n.article_type, n.summary, l.link_type
               FROM news_facility_links l
               JOIN news_submissions n ON n.id = l.news_id
              WHERE l.facility_id = %d AND n.status IN ('approved','published')
              ORDER BY n.publication_date DESC, n.id DESC",
            (int) $facility_id
        ), ARRAY_A);
        $out = array();
        foreach ((array) $rows as $r) {
            $out[] = array(
                'id'        => (int) $r['id'],
                'title'     => trim((string) ($r['alternate_title'] ?: $r['article_title'])),
                'outlet'    => trim((string) $r['publication_name']),
                'date'      => (string) $r['publication_date'],
                'date_label' => $r['publication_date'] ? kop_facility_pages_date_label($r['publication_date']) : '',
                'url'       => (string) $r['article_url'],
                'type'      => trim((string) $r['article_type']),
                'summary'   => trim((string) $r['summary']),
                'link_type' => (string) $r['link_type'],
            );
        }
        return $out;
    }
}

if (!function_exists('kop_facility_pages_lawsuits')) {
    /** Published cases linked by id or naming the facility, newest first. */
    function kop_facility_pages_lawsuits($facility_id, array $name_keys) {
        global $wpdb;
        if (!kop_facility_pages_table_exists('lawsuits')) return array();
        $rows = $wpdb->get_results(
            "SELECT id, case_name, case_number, court, jurisdiction, filing_date, status, outcome, settlement_amount, summary, facilities_mentioned
               FROM lawsuits WHERE publication_status IN ('approved','published')
              ORDER BY filing_date DESC, id DESC",
            ARRAY_A
        );
        if (!$rows) return array();
        $linked = array();
        if (kop_facility_pages_table_exists('lawsuit_facility_links')) {
            $links = $wpdb->get_results($wpdb->prepare('SELECT lawsuit_id, link_type FROM lawsuit_facility_links WHERE facility_id = %d', (int) $facility_id), ARRAY_A);
            foreach ((array) $links as $l) $linked[(int) $l['lawsuit_id']] = (string) $l['link_type'];
        }
        $out = array();
        foreach ($rows as $r) {
            $lid = (int) $r['id'];
            $link_type = isset($linked[$lid]) ? $linked[$lid] : '';
            if ($link_type === '') {
                foreach (kop_facility_pages_mentioned_names($r['facilities_mentioned']) as $n) {
                    $rk = kop_facility_pages_name_key($n);
                    foreach ($name_keys as $fk) {
                        if (kop_facility_pages_key_matches($fk, $rk)) { $link_type = 'mentioned'; break 2; }
                    }
                }
            }
            if ($link_type === '') continue;
            $year = $r['filing_date'] ? substr((string) $r['filing_date'], 0, 4) : '';
            $out[] = array(
                'id'         => $lid,
                'case_name'  => trim((string) $r['case_name']),
                'case_number' => trim((string) $r['case_number']),
                'court'      => trim((string) $r['court']),
                'year'       => $year,
                'status'     => ucfirst(str_replace('_', ' ', trim((string) $r['status']))),
                'outcome'    => trim((string) $r['outcome']),
                'summary'    => trim((string) $r['summary']),
                'link_type'  => $link_type,
            );
        }
        return $out;
    }
}

if (!function_exists('kop_facility_pages_memorials')) {
    /** Published memorial entries whose program names this facility. */
    function kop_facility_pages_memorials(array $name_keys, $state_name) {
        global $wpdb;
        if (!kop_facility_pages_table_exists('memorial_victims') || !$name_keys) return array();
        $rows = $wpdb->get_results(
            "SELECT id, name, age, program, date_of_death, date_precision, cause_of_death, cause_category, location, source_name, source_url, kop_url
               FROM memorial_victims WHERE publication_status = 'published'
              ORDER BY date_of_death DESC, id DESC",
            ARRAY_A
        );
        $out = array();
        $seen = array();
        foreach ((array) $rows as $r) {
            $rk = kop_facility_pages_name_key($r['program']);
            if ($rk === '') continue;
            $hit = false;
            foreach ($name_keys as $fk) {
                $exact = ($fk === $rk);
                if (!$exact && !kop_facility_pages_key_matches($fk, $rk)) continue;
                if (!$exact && $state_name !== '' && trim((string) $r['location']) !== '') {
                    $loc = function_exists('kop_state_canonical_name') ? kop_state_canonical_name($r['location']) : $r['location'];
                    if (strcasecmp($loc, $state_name) !== 0) continue;
                }
                $hit = true;
                break;
            }
            if (!$hit || isset($seen[(int) $r['id']])) continue;
            $seen[(int) $r['id']] = true;
            $date = trim((string) $r['date_of_death']);
            $precision = trim((string) $r['date_precision']);
            $date_label = '';
            if ($date !== '') {
                $ts = strtotime($date);
                if ($precision === 'year') $date_label = $ts ? date('Y', $ts) : substr($date, 0, 4);
                elseif ($precision === 'month') $date_label = $ts ? date('F Y', $ts) : $date;
                else $date_label = $ts ? date('F j, Y', $ts) : $date;
            }
            $out[] = array(
                'id'         => (int) $r['id'],
                'name'       => trim((string) $r['name']),
                'age'        => ($r['age'] === null || $r['age'] === '') ? '' : (string) (int) $r['age'],
                'program'    => trim((string) $r['program']),
                'date_label' => $date_label,
                'cause'      => trim((string) $r['cause_of_death']),
                'category'   => trim((string) $r['cause_category']),
                'source_name' => trim((string) $r['source_name']),
                'source_url' => trim((string) $r['source_url']),
                'kop_url'    => trim((string) $r['kop_url']),
            );
        }
        return $out;
    }
}

if (!function_exists('kop_facility_pages_wiki')) {
    /** Approved wiki entries filed under this facility. */
    function kop_facility_pages_wiki($name, $unique_name, $current_name) {
        global $wpdb;
        if (!kop_facility_pages_table_exists('wiki_submissions')) return array();
        $names = array_values(array_unique(array_filter(array_map('trim', array((string) $name, (string) $unique_name, (string) $current_name)), 'strlen')));
        if (!$names) return array();
        $placeholders = implode(',', array_fill(0, count($names), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, program_name, city_state, organization, program_type, years_active, updated_at
               FROM wiki_submissions
              WHERE status IN ('approved','published')
                AND (facility_unique_name IN ($placeholders) OR program_name IN ($placeholders))
              ORDER BY updated_at DESC, id DESC",
            array_merge($names, $names)
        ), ARRAY_A);
        $out = array();
        foreach ((array) $rows as $r) {
            $out[] = array(
                'id'      => (int) $r['id'],
                'title'   => trim((string) $r['program_name']),
                'place'   => trim((string) $r['city_state']),
                'organization' => trim((string) $r['organization']),
                'type'    => trim((string) $r['program_type']),
                'years'   => trim((string) $r['years_active']),
            );
        }
        return $out;
    }
}

if (!function_exists('kop_facility_pages_inspections')) {
    /**
     * Licensing record and inspection reports from the state scrapes:
     * {summary: {...}, reports: [...], total, page_url} or null. Rows are
     * matched by name key within the facility's state (any state when the
     * record has none).
     */
    function kop_facility_pages_inspections(array $name_keys, $state_code, $state_name) {
        global $wpdb;
        if (!kop_facility_pages_table_exists('inspection_facilities') || !$name_keys) return null;
        $where = '';
        $params = array();
        if ($state_code !== '') {
            $where = 'WHERE state = %s';
            $params[] = $state_code;
        }
        $sql = "SELECT id, state, facility_name, full_address, phone, program_category, program_name, executive_director, bed_capacity, license_exp_date, relicense_visit_date, action FROM inspection_facilities {$where}";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        $matched = array();
        foreach ((array) $rows as $r) {
            $fname = trim((string) $r['facility_name']);
            if ($fname === '' || (function_exists('kop_facility_name_looks_junky') && kop_facility_name_looks_junky($fname))) continue;
            $rk = kop_facility_pages_name_key($fname);
            if ($rk === '') continue;
            foreach ($name_keys as $fk) {
                if ($fk === $rk || kop_facility_pages_key_matches($fk, $rk)) { $matched[(int) $r['id']] = $r; break; }
            }
        }
        if (!$matched) return null;

        $abbrev_to_name = function_exists('kop_state_abbrev_to_name') ? kop_state_abbrev_to_name() : array();
        $page_map = function_exists('kop_state_inspection_page_map') ? kop_state_inspection_page_map() : array();
        $summary = array(
            'licensed_names' => array(), 'addresses' => array(), 'phone' => '', 'program_category' => '',
            'licensed_program_name' => '', 'executive_director' => '', 'bed_capacity' => '',
            'license_expiration' => '', 'relicense_visit_date' => '', 'licensing_action' => '', 'states' => array(),
        );
        $page_urls = array();
        foreach ($matched as $r) {
            $abbrev = strtoupper(trim((string) $r['state']));
            $sname = $abbrev_to_name[$abbrev] ?? $abbrev;
            if ($sname !== '' && !in_array($sname, $summary['states'], true)) $summary['states'][] = $sname;
            if (isset($page_map[$sname])) $page_urls[home_url('/' . $page_map[$sname] . '/')] = $sname;
            $fname = trim((string) $r['facility_name']);
            if ($fname !== '' && !in_array($fname, $summary['licensed_names'], true)) $summary['licensed_names'][] = $fname;
            $addr = trim((string) $r['full_address']);
            if ($addr !== '' && !in_array($addr, $summary['addresses'], true)) $summary['addresses'][] = $addr;
            $licensed = trim((string) $r['program_name']);
            if (strcasecmp($licensed, $fname) === 0 || ctype_digit($licensed)) $licensed = '';
            $fields = array(
                'phone' => trim((string) $r['phone']), 'program_category' => trim((string) $r['program_category']),
                'licensed_program_name' => $licensed, 'executive_director' => trim((string) $r['executive_director']),
                'bed_capacity' => trim((string) $r['bed_capacity']), 'license_expiration' => trim((string) $r['license_exp_date']),
                'relicense_visit_date' => trim((string) $r['relicense_visit_date']), 'licensing_action' => trim((string) $r['action']),
            );
            foreach ($fields as $k => $v) {
                if ($summary[$k] === '' && $v !== '') $summary[$k] = $v;
            }
        }

        $reports = array();
        $total = 0;
        if (kop_facility_pages_table_exists('inspection_reports')) {
            $ids = array_keys($matched);
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, facility_id, report_id, report_date, report_url, summary, content_length FROM inspection_reports WHERE facility_id IN ($placeholders)",
                $ids
            ), ARRAY_A);
            $total = count((array) $rows);
            foreach ((array) $rows as $r) {
                $reports[] = array(
                    'id'         => (int) $r['id'],
                    'ts'         => kop_facility_pages_parse_date($r['report_date']),
                    'date'       => trim((string) $r['report_date']),
                    'date_label' => kop_facility_pages_date_label($r['report_date']),
                    'url'        => trim((string) $r['report_url']),
                    'summary'    => trim((string) $r['summary']),
                    'report_id'  => trim((string) $r['report_id']),
                    'licensed_as' => trim((string) ($matched[(int) $r['facility_id']]['facility_name'] ?? '')),
                );
            }
            usort($reports, static function ($a, $b) {
                if ($a['ts'] !== $b['ts']) return $b['ts'] <=> $a['ts'];
                return $b['id'] <=> $a['id'];
            });
            $reports = array_slice($reports, 0, 25);
        }

        return array(
            'summary'   => $summary,
            'reports'   => $reports,
            'total'     => $total,
            'page_urls' => $page_urls,
            'page_url'  => $page_urls ? array_key_first($page_urls) : '',
        );
    }
}

if (!function_exists('kop_facility_pages_documents')) {
    /**
     * The facility's FileBird folder (documentFolderId, or the folder named
     * after it that the index build matched) rendered through the theme's
     * folder shortcode.
     */
    function kop_facility_pages_documents(array $doc, $folder_id) {
        $folder_id = (int) $folder_id;
        if ($folder_id <= 0 && !empty($doc['documentFolderId'])) $folder_id = (int) $doc['documentFolderId'];
        if ($folder_id <= 0) return array('folder_id' => 0, 'html' => '');
        $html = '';
        if (function_exists('shortcode_exists') && shortcode_exists('filebird_folder')) {
            $html = do_shortcode('[filebird_folder folder_id="' . $folder_id . '" merge="name" layout="grid" title="Documents on file" show_count="yes"]');
            if (stripos($html, 'no-documents') !== false) $html = '';
        }
        return array('folder_id' => $folder_id, 'html' => $html);
    }
}

// ---------------------------------------------------------------------------
// Sitemaps
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_pages_sitemap_entries')) {
    /**
     * [{loc, mod}] for every generated page, editorial ones excluded (their
     * posts are already in the post sitemap). Newest first.
     */
    function kop_facility_pages_sitemap_entries() {
        $index = kop_facility_pages_index();
        $out = array();
        foreach ($index['ids'] as $id => $e) {
            if ($e['editorial'] !== '') continue;
            $out[] = array(
                'loc' => kop_facility_pages_url_for_slug($e['slug']),
                'mod' => $e['updated'] !== '' ? $e['updated'] : gmdate('Y-m-d H:i:s', (int) $index['built']),
            );
        }
        usort($out, static function ($a, $b) { return strcmp($b['mod'], $a['mod']); });
        return $out;
    }
}

if (!function_exists('kop_facility_pages_yoast_index')) {
    function kop_facility_pages_yoast_index($xml) {
        $entries = kop_facility_pages_sitemap_entries();
        if (!$entries) return $xml;
        $per_page = 1000;
        $pages = (int) ceil(count($entries) / $per_page);
        for ($n = 1; $n <= $pages; $n++) {
            $chunk = array_slice($entries, ($n - 1) * $per_page, $per_page);
            $mod = $chunk ? $chunk[0]['mod'] : '';
            $ts = $mod !== '' ? strtotime($mod) : false;
            $xml .= '<sitemap>' . "\n"
                . "\t" . '<loc>' . esc_url(home_url('/facility-sitemap' . ($n > 1 ? $n : '') . '.xml')) . '</loc>' . "\n"
                . ($ts ? "\t" . '<lastmod>' . esc_html(date('c', $ts)) . '</lastmod>' . "\n" : '')
                . '</sitemap>' . "\n";
        }
        return $xml;
    }
    add_filter('wpseo_sitemap_index', 'kop_facility_pages_yoast_index');
}

if (!function_exists('kop_facility_pages_yoast_sitemap')) {
    function kop_facility_pages_yoast_sitemap() {
        if (empty($GLOBALS['wpseo_sitemaps']) || !is_object($GLOBALS['wpseo_sitemaps'])) return;
        $sitemaps = $GLOBALS['wpseo_sitemaps'];
        $page = max(1, (int) get_query_var('sitemap_n'));
        $entries = kop_facility_pages_sitemap_entries();
        $chunk = array_slice($entries, ($page - 1) * 1000, 1000);
        $links = array();
        foreach ($chunk as $e) {
            $links[] = array('loc' => $e['loc'], 'mod' => $e['mod'], 'chf' => 'weekly', 'pri' => 0.6);
        }
        $xml = '';
        if (isset($sitemaps->renderer) && is_object($sitemaps->renderer) && method_exists($sitemaps->renderer, 'get_sitemap')) {
            $xml = $sitemaps->renderer->get_sitemap($links, 'facility', $page);
        } else {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($links as $l) {
                $ts = strtotime($l['mod']);
                $xml .= '<url><loc>' . esc_url($l['loc']) . '</loc>' . ($ts ? '<lastmod>' . date('c', $ts) . '</lastmod>' : '') . '</url>' . "\n";
            }
            $xml .= '</urlset>';
        }
        if (method_exists($sitemaps, 'set_sitemap')) $sitemaps->set_sitemap($xml);
    }
    add_action('wpseo_do_sitemap_facility', 'kop_facility_pages_yoast_sitemap');
}

if (class_exists('WP_Sitemaps_Provider') && !class_exists('KOP_Facility_Pages_Sitemap_Provider')) {
    /** Core sitemaps provider (used when Yoast's sitemaps are off; Yoast disables core's when on). */
    class KOP_Facility_Pages_Sitemap_Provider extends WP_Sitemaps_Provider {
        public function __construct() {
            $this->name = 'facility';
            $this->object_type = 'facility';
        }
        public function get_url_list($page_num, $object_subtype = '') {
            $entries = kop_facility_pages_sitemap_entries();
            $chunk = array_slice($entries, ($page_num - 1) * 1000, 1000);
            $out = array();
            foreach ($chunk as $e) {
                $ts = strtotime($e['mod']);
                $item = array('loc' => $e['loc']);
                if ($ts) $item['lastmod'] = date('c', $ts);
                $out[] = $item;
            }
            return $out;
        }
        public function get_max_num_pages($object_subtype = '') {
            return max(1, (int) ceil(count(kop_facility_pages_sitemap_entries()) / 1000));
        }
    }
}

if (!function_exists('kop_facility_pages_register_core_sitemap')) {
    function kop_facility_pages_register_core_sitemap() {
        if (!class_exists('KOP_Facility_Pages_Sitemap_Provider') || !function_exists('wp_sitemaps_add_provider')) return;
        wp_sitemaps_add_provider('facility', new KOP_Facility_Pages_Sitemap_Provider());
    }
    add_action('init', 'kop_facility_pages_register_core_sitemap', 40);
}
