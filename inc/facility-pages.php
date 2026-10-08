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
 * The ones in kop_facility_pages_merged_profiles() are the other way round:
 * the generated page prints the post's content as it stands and the post
 * 301s to it.
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

if (!function_exists('kop_facility_pages_merged_profile_redirect')) {
    /**
     * A merged profile post (kop_facility_pages_merged_profiles()) sends its
     * readers to the facility page that now prints its content. Previews
     * still show the post, so an editor can check a change before saving.
     */
    function kop_facility_pages_merged_profile_redirect() {
        if (is_admin() || is_preview() || !is_singular()) return;
        $post = get_queried_object();
        if (!is_object($post) || empty($post->ID)) return;
        if (!in_array((string) $post->post_name, kop_facility_pages_merged_profiles(), true)) return;
        $url = kop_facility_pages_merged_profile_url((int) $post->ID);
        if ($url !== '') {
            wp_safe_redirect($url, 301);
            exit;
        }
    }
    add_action('template_redirect', 'kop_facility_pages_merged_profile_redirect', 1);
}

if (!function_exists('kop_facility_pages_merged_profile_url')) {
    /** The facility page a merged profile post now lives on, or ''. */
    function kop_facility_pages_merged_profile_url($post_id) {
        $index = kop_facility_pages_index();
        foreach ($index['ids'] as $e) {
            if ((int) ($e['profile_post'] ?? 0) === (int) $post_id) {
                return kop_facility_pages_url_for_slug($e['slug']);
            }
        }
        return '';
    }
}

if (!function_exists('kop_facility_pages_merged_profile_sitemap')) {
    /** The merged posts leave Yoast's post sitemap; their facility page is listed instead. */
    function kop_facility_pages_merged_profile_sitemap($ids) {
        $index = kop_facility_pages_index();
        foreach ($index['ids'] as $e) {
            if (!empty($e['profile_post'])) $ids[] = (int) $e['profile_post'];
        }
        return $ids;
    }
    add_filter('wpseo_exclude_from_sitemap_by_post_ids', 'kop_facility_pages_merged_profile_sitemap');
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
        // The document viewer: the library's tiles and the page's other PDF
        // links (Woodbury pages, cited reports) open in its modal, so it loads
        // even on pages with no library.
        $doc_css = $theme_dir . '/css/document-library.css';
        if (file_exists($doc_css)) {
            wp_enqueue_style('kop-document-library-style', $theme_uri . '/css/document-library.css', array('kop-colors'), filemtime($doc_css));
        }
        $doc_js = $theme_dir . '/js/document-library.js';
        if (file_exists($doc_js)) {
            wp_enqueue_script('kop-document-library-script', $theme_uri . '/js/document-library.js', array('jquery'), filemtime($doc_js), true);
        }
        // The map in the Network section (inc/network-map.php); its scripts
        // follow in the footer once the section has printed it.
        if (!empty($GLOBALS['kop_facility_page']['network']) && function_exists('kop_network_map_embed_style')) {
            kop_network_map_embed_style();
        }
        $js = $theme_dir . '/js/submit-info.js';
        if (file_exists($js)) {
            wp_enqueue_script('kop-submit-info', $theme_uri . '/js/submit-info.js', array(), filemtime($js), true);
        }
        $fp_js = $theme_dir . '/js/facility-profile.js';
        if (file_exists($fp_js)) {
            wp_enqueue_script('kop-facility-profile', $theme_uri . '/js/facility-profile.js', array(), filemtime($fp_js), true);
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
        // Research documents tagged with a facility give it a page.
        $research = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            'kop_research_facilities'
        ));
        $parts[] = 'research:' . (string) $research;
        // A new build of the network map changes which facilities it draws.
        $parts[] = 'network:' . (function_exists('kop_network_map_cache_key') ? kop_network_map_cache_key() : '-');
        // A new build of the Unsilenced archive lists (inc/unsilenced-archive.php).
        $parts[] = 'unsilenced:' . (function_exists('kop_unsilenced_cache_key') ? kop_unsilenced_cache_key() : '-');
        // ...and of the survivor site lists (inc/survivor-archives.php).
        $parts[] = 'survivor-sites:' . (function_exists('kop_survivor_archives_cache_key') ? kop_survivor_archives_cache_key() : '-');
        // Approved record <-> inspection row links (inc/inspection-links.php).
        $parts[] = 'inspection-links:' . (function_exists('kop_inspection_links_cache_key') ? kop_inspection_links_cache_key() : '-');
        // Which profile posts print on their facility page instead of redirecting.
        $parts[] = 'merged:' . implode(',', kop_facility_pages_merged_profiles());
        // Homes grouped under a program record (inc/program-homes.php).
        $parts[] = 'program-homes:' . (function_exists('kop_program_homes_cache_key') ? kop_program_homes_cache_key() : '-');
        // ...and companies converted into a program, whose history prints there (inc/program-homes-convert.php).
        $parts[] = 'program-conversions:' . (function_exists('kop_phc_cache_key') ? kop_phc_cache_key() : '-');
        $parts[] = 'v:5';
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
        $merged = kop_facility_pages_merged_profile_posts();

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
                'profile_post' => isset($merged[$id]) ? $merged[$id] : 0,
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
            if (empty($e['signals']) && $e['editorial'] === '' && !$e['profile_post']) continue;
            $slug = $e['slug'];
            if (isset($slugs[$slug])) $slug .= '-' . $id;
            $slugs[$slug] = $id;
            $ids[$id] = array(
                'slug'      => $slug,
                'name'      => $e['name'],
                'updated'   => $e['updated'],
                'editorial' => $e['editorial'],
                'profile_post' => $e['profile_post'],
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
            'the-ridge-rtc-maine' => 'The Ridge Maine',
        ));
    }
}

if (!function_exists('kop_facility_pages_merged_profiles')) {
    /**
     * Slugs of the Facility Profile posts moved onto the generated facility
     * page: the page prints the post's content unchanged inside its own
     * layout, and the post 301s there. The post stays the place its words
     * are edited.
     */
    function kop_facility_pages_merged_profiles() {
        return array_values(array_unique(array_map('strval', (array) apply_filters('kop_facility_pages_merged_profiles', array(
            'hyde',
        )))));
    }
}

if (!function_exists('kop_facility_pages_merged_profile_posts')) {
    /** facilities_v2 id => post ID, for the merged profiles only. */
    function kop_facility_pages_merged_profile_posts() {
        $map = array();
        foreach (kop_facility_pages_profile_posts() as $id => $p) {
            if ($p['merged']) $map[$id] = $p['post_id'];
        }
        return $map;
    }
}

if (!function_exists('kop_facility_pages_editorial_map')) {
    /**
     * facilities_v2 id => permalink of the published post or page that uses
     * the hand-written Facility Profile template for that facility (except the
     * merged ones, which do not take over the facility's URL).
     */
    function kop_facility_pages_editorial_map() {
        $map = array();
        foreach (kop_facility_pages_profile_posts() as $id => $p) {
            if (!$p['merged']) $map[$id] = $p['url'];
        }
        return $map;
    }
}

if (!function_exists('kop_facility_pages_profile_posts')) {
    /**
     * facilities_v2 id => {post_id, url, merged} for every published post or
     * page on the Facility Profile template.
     */
    function kop_facility_pages_profile_posts() {
        global $wpdb;
        static $cache = null;
        if ($cache !== null) return $cache;
        $map = array();
        if (!function_exists('get_posts')) return $map;
        $merged = kop_facility_pages_merged_profiles();
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
                $map[$id] = array(
                    'post_id' => (int) $post->ID,
                    'url'     => get_permalink($post),
                    'merged'  => in_array((string) $post->post_name, $merged, true),
                );
            }
        }
        return $cache = $map;
    }
}

if (!function_exists('kop_facility_profile_split_blocks')) {
    /**
     * The top-level blocks of a block-editor string, in order:
     * [{name, attrs, raw, inner}]. raw is the whole block with its comments,
     * inner the markup between its opening and closing comment. Text outside
     * any block (wrapper divs) is skipped.
     */
    function kop_facility_profile_split_blocks($content) {
        $blocks = array();
        $depth = 0;
        $start = 0;
        $inner_start = 0;
        $name = '';
        $attrs = array();
        if (!preg_match_all('/<!--\s+(\/?)wp:([a-z0-9\/-]+)(\s+(\{.*?\}))?\s+(\/?)-->/s', (string) $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $blocks;
        }
        foreach ($m as $tag) {
            $closing = $tag[1][0] === '/';
            $self = isset($tag[5]) && $tag[5][0] === '/';
            $offset = $tag[0][1];
            $end = $offset + strlen($tag[0][0]);
            if ($closing) {
                $depth--;
                if ($depth === 0) {
                    $blocks[] = array(
                        'name'  => $name,
                        'attrs' => $attrs,
                        'raw'   => substr($content, $start, $end - $start),
                        'inner' => substr($content, $inner_start, $offset - $inner_start),
                    );
                }
                continue;
            }
            if ($depth === 0) {
                $name = $tag[2][0];
                $decoded = isset($tag[4][0]) && $tag[4][0] !== '' ? json_decode($tag[4][0], true) : array();
                $attrs = is_array($decoded) ? $decoded : array();
                $start = $offset;
                $inner_start = $end;
                if ($self) {
                    $blocks[] = array('name' => $name, 'attrs' => $attrs, 'raw' => $tag[0][0], 'inner' => '');
                    continue;
                }
            }
            if (!$self) $depth++;
        }
        return $blocks;
    }
}

if (!function_exists('kop_facility_profile_link_card')) {
    /**
     * A Visual Link Preview block's link as a card in the facility page's
     * news-card shape: the title, summary and picture the block stores.
     */
    function kop_facility_profile_link_card(array $attrs) {
        $data = array();
        if (!empty($attrs['encoded'])) {
            $decoded = json_decode((string) base64_decode((string) $attrs['encoded'], true), true);
            if (is_array($decoded)) $data = $decoded;
        }
        $url = (string) ($attrs['url'] ?? ($data['url'] ?? ''));
        $post_id = (int) ($attrs['post'] ?? ($data['post'] ?? 0));
        if (($attrs['type'] ?? '') === 'internal' && $post_id > 0 && function_exists('get_post')) {
            $p = get_post($post_id);
            if ($p) $url = (string) get_permalink($p);
        }
        $image = '';
        $image_id = (int) ($attrs['image_id'] ?? ($data['image_id'] ?? 0));
        if ($image_id > 0 && function_exists('wp_get_attachment_image_url')) {
            $image = (string) wp_get_attachment_image_url($image_id, 'medium_large');
        }
        if ($image === '' && !empty($data['image_url'])) $image = (string) $data['image_url'];
        $host = (string) preg_replace('/^www\./', '', (string) wp_parse_url($url, PHP_URL_HOST));
        $home = (string) preg_replace('/^www\./', '', (string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        return array(
            'id'         => 0,
            'url'        => $url,
            'title'      => trim((string) ($data['title'] ?? '')),
            'summary'    => trim((string) ($data['summary'] ?? '')),
            'outlet'     => ($host === '' || $host === $home) ? 'Kids Over Profits' : $host,
            'date_label' => '',
            'type'       => '',
            'image'      => $image !== '' ? array('src' => $image, 'kind' => 'photo') : null,
        );
    }
}

if (!function_exists('kop_facility_profile_items')) {
    /**
     * A section's blocks as items in order: link preview blocks become
     * {type: card}, every other block {type: html, raw}. Column blocks are
     * opened so the cards inside them line up with the page's own cards.
     */
    function kop_facility_profile_items(array $blocks) {
        $items = array();
        foreach ($blocks as $b) {
            if ($b['name'] === 'visual-link-preview/link') {
                $items[] = array('type' => 'card', 'card' => kop_facility_profile_link_card($b['attrs']));
            } elseif ($b['name'] === 'columns' || $b['name'] === 'column') {
                $items = array_merge($items, kop_facility_profile_items(kop_facility_profile_split_blocks($b['inner'])));
            } else {
                $items[] = array('type' => 'html', 'raw' => $b['raw']);
            }
        }
        return $items;
    }
}

if (!function_exists('kop_facility_profile_render')) {
    /**
     * One piece of a profile post through the_content (blocks, shortcodes,
     * embeds, typography), without the AddToAny share bar that filter adds
     * to the end of every call: the page is printed in a dozen pieces.
     */
    function kop_facility_profile_render($raw) {
        $share = function_exists('has_filter') ? has_filter('the_content', 'A2A_SHARE_SAVE_add_to_content') : false;
        if ($share !== false) remove_filter('the_content', 'A2A_SHARE_SAVE_add_to_content', $share);
        $html = apply_filters('the_content', (string) $raw);
        if ($share !== false) add_filter('the_content', 'A2A_SHARE_SAVE_add_to_content', $share);
        return $html;
    }
}

if (!function_exists('kop_facility_profile_parts')) {
    /**
     * A Facility Profile post's content cut into the parts the facility page
     * template places (templates/facility-page.php), every word kept:
     *   facts     the columns block before the first heading, one
     *             {label, lines[]} per paragraph, for the At a glance rail
     *   nav       the Index box's links, href => label, for the jump links
     *   lead      other blocks before the first heading
     *   sections  one per h2: {id, title, kind, blocks, items}; kind is
     *             lawsuits, news, testimony, documents, videos, related or
     *             prose, so the template can join a section to its own
     *   updated   the "Last updated: ..." line
     * The "Back to index" lines are navigation and are left out; the jump
     * links take their place. null when the post has no h2 to cut at.
     */
    function kop_facility_profile_parts($content) {
        $parts = array('facts' => array(), 'nav' => array(), 'lead' => array(), 'sections' => array(), 'updated' => '');
        $kinds = array(
            'lawsuits' => 'lawsuits', 'news' => 'news', 'survivors' => 'testimony', 'testimony' => 'testimony',
            'doclibrary' => 'documents', 'documents' => 'documents', 'videoplaylist' => 'videos', 'videos' => 'videos',
            'related' => 'related',
        );
        $current = null;
        foreach (kop_facility_profile_split_blocks($content) as $b) {
            $text = trim(html_entity_decode(wp_strip_all_tags($b['raw']), ENT_QUOTES, 'UTF-8'));
            if ($b['name'] === 'heading' && preg_match('/<h2\b([^>]*)>(.*?)<\/h2>/s', $b['raw'], $h)) {
                $id = preg_match('/\bid="([^"]+)"/', $h[1], $im) ? $im[1] : sanitize_title(wp_strip_all_tags($h[2]));
                $title = trim(html_entity_decode(wp_strip_all_tags($h[2]), ENT_QUOTES, 'UTF-8'));
                $key = strtolower($id);
                if (!isset($kinds[$key]) && preg_match('/^(lawsuits|news|survivor|document|video|related)/i', $title, $km)) {
                    $key = array('lawsuits' => 'lawsuits', 'news' => 'news', 'survivor' => 'survivors', 'document' => 'doclibrary', 'video' => 'videoplaylist', 'related' => 'related')[strtolower($km[1])];
                }
                $parts['sections'][] = array('id' => $id, 'title' => $title, 'kind' => $kinds[$key] ?? 'prose', 'blocks' => array());
                $current = count($parts['sections']) - 1;
                continue;
            }
            if ($b['name'] === 'paragraph' && preg_match('/^Back to index$/i', $text)) continue;
            if ($b['name'] === 'paragraph' && preg_match('/^Last updated:/i', $text)) {
                $parts['updated'] = $text;
                continue;
            }
            if ($current === null) {
                if ($b['name'] === 'group' && preg_match('/\bid="index"/', $b['raw'])) {
                    preg_match_all('/<a href="#([^"]+)">(.*?)<\/a>/s', $b['raw'], $links, PREG_SET_ORDER);
                    foreach ($links as $l) $parts['nav'][$l[1]] = trim(html_entity_decode(wp_strip_all_tags($l[2]), ENT_QUOTES, 'UTF-8'));
                    continue;
                }
                if ($b['name'] === 'columns' && !$parts['facts']) {
                    preg_match_all('/<p[^>]*>(.*?)<\/p>/s', $b['raw'], $ps);
                    foreach ($ps[1] as $p) {
                        $lines = array_values(array_filter(array_map('trim', preg_split('/<br\s*\/?>/i', $p)), 'strlen'));
                        $label = '';
                        if ($lines && preg_match('/^<strong>(.*?)<\/strong>$/s', $lines[0], $sm)) {
                            $label = trim($sm[1]);
                            array_shift($lines);
                        } elseif ($lines && count($lines) > 1 && substr(wp_strip_all_tags($lines[0]), -1) === ':') {
                            $label = rtrim(trim($lines[0]), ':');
                            array_shift($lines);
                        }
                        if ($lines) $parts['facts'][] = array('label' => $label, 'lines' => $lines);
                    }
                    continue;
                }
                $parts['lead'][] = $b;
                continue;
            }
            $parts['sections'][$current]['blocks'][] = $b;
        }
        if (!$parts['sections']) return null;
        foreach ($parts['sections'] as $i => $s) {
            $parts['sections'][$i]['items'] = kop_facility_profile_items($s['blocks']);
        }
        return $parts;
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
     *   unsilenced       facility id => Unsilenced archive documents KOP lacks
     *   survivor_sites   facility id => survivor site documents KOP lacks
     */
    function kop_facility_pages_link_sets() {
        global $wpdb;
        $sets = array('news' => array(), 'lawsuits' => array(), 'lawsuit_keys' => array(), 'memorial_keys' => array(),
                      'wiki' => array(), 'inspections' => array(), 'operators' => array(), 'folders' => array(),
                      'network' => array(), 'research' => array(), 'unsilenced' => array(), 'survivor_sites' => array());

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
        // Research documents tagged with the facility at /researchreports/.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value AS facility_id, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value",
            'kop_research_facilities'
        ), ARRAY_A);
        foreach ((array) $rows as $r) $sets['research'][(int) $r['facility_id']] = (int) $r['n'];
        // Facilities the network map draws, with how many connections each has.
        if (function_exists('kop_network_map_facility_connections')) {
            foreach (kop_network_map_facility_connections() as $fid => $entry) {
                if (!empty($entry['links'])) $sets['network'][(int) $fid] = count($entry['links']);
            }
        }
        // Documents in Unsilenced's archive that KOP has no copy of.
        if (function_exists('kop_unsilenced_index')) {
            $sets['unsilenced'] = kop_unsilenced_index()['facilities'];
        }
        // ...and on Surviving Straight Inc. and WWASP Survivors.
        if (function_exists('kop_survivor_archives_facility_counts')) {
            $sets['survivor_sites'] = kop_survivor_archives_facility_counts();
        }
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

if (!function_exists('kop_facility_pages_source_label')) {
    /**
     * The publication and its date, as a link names a source:
     * "Woodbury Reports, October 2010, p. 2" -> "Woodbury Reports, Oct 2010";
     * "r/troubledteens wiki, page "X" (as of 2025-12-18)" -> "r/troubledteens wiki, as of Dec 2025";
     * "HEAL, staff list for X (archived 2012-05-17)" -> "HEAL, archived May 2012";
     * "Fornits forum, post by X, March 2005" -> "Fornits, Mar 2005".
     */
    function kop_facility_pages_source_label($cite) {
        $cite = trim((string) $cite);
        $pub = '';
        foreach (array('Woodbury Reports', 'HEAL', 'r/troubledteens wiki', 'Fornits') as $p) {
            if (stripos($cite, $p) === 0) { $pub = $p; break; }
        }
        if ($pub === '') {
            $pub = trim(strtok($cite, ',('));
            if ($pub === '') $pub = 'Source';
        }
        $months = array('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
        $when = '';
        if (preg_match('/\b(\d{4})-(\d{2})(?:-\d{2})?\b/', $cite, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            $when = substr($months[(int) $m[2] - 1], 0, 3) . ' ' . $m[1];
        } elseif (preg_match('/\b(' . implode('|', $months) . ')\s+(\d{4})\b/', $cite, $m)) {
            $when = substr($m[1], 0, 3) . ' ' . $m[2];
        } elseif (preg_match('/\b(1[89]\d{2}|20\d{2})\b/', $cite, $m)) {
            $when = $m[1];
        }
        if ($when !== '' && preg_match('/\b(as of|archived)\b/i', $cite, $w)) $when = strtolower($w[1]) . ' ' . $when;
        return $when !== '' ? $pub . ', ' . $when : $pub;
    }
}

if (!function_exists('kop_facility_pages_drop_left')) {
    /**
     * "Therapist (2007-2021, r/troubledteens wiki), left" -> without the ", left" the facts build used to add
     * (owner, 2026-10-08: a past date range already says they left).
     */
    function kop_facility_pages_drop_left($text) {
        return trim((string) preg_replace('/,\s*left\s*$/i', '', (string) $text));
    }
}

if (!function_exists('kop_facility_pages_staff_items')) {
    /**
     * The staff lists as the page shows them: {text, source, url} per entry,
     * the source being where the entry came from (Woodbury Facts, Fornits).
     */
    /**
     * The staff lists as display items. A person ({name, role, pastJobs}) also
     * carries 'name', 'role' and 'career': the other places in the industry
     * they worked, read from their pastJobs, the staff lists of the other
     * records and the network map (kop_facility_pages_person_career()).
     * $facility_id leaves this program out of the career.
     */
    function kop_facility_pages_staff_items($staff, $facility_id = 0) {
        $out = array();
        if (!is_array($staff)) return $out;
        foreach (array('administrator', 'notableStaff', 'pastTTIJobs') as $k) {
            $items = array();
            foreach (kop_facility_list($staff[$k] ?? null) as $item) {
                $text = kop_facility_pages_text_items(array($item));
                if (!$text) continue;
                $cite = is_array($item) ? trim((string) ($item['source'] ?? '')) : '';
                $url = is_array($item) ? trim((string) ($item['sourceUrl'] ?? '')) : '';
                $entry = array('text' => $text[0], 'source' => $cite !== '' ? kop_facility_pages_source_label($cite) : '',
                    'cite' => $cite, 'url' => preg_match('#^https?://#i', $url) ? $url : '');
                if ($k !== 'pastTTIJobs' && is_array($item) && trim((string) ($item['name'] ?? '')) !== '') {
                    $name = trim((string) $item['name']);
                    // "Admissions: Jane Doe" from the old forms: the label is her role.
                    $role = kop_facility_pages_drop_left((string) ($item['role'] ?? ''));
                    $entry['text'] = kop_facility_pages_drop_left($entry['text']);
                    if (preg_match('/^([A-Za-z][A-Za-z &\/-]{2,40}):\s*(\S.*)$/', $name, $m)) {
                        $name = $m[2];
                        if ($role === '') $role = $m[1];
                    }
                    $entry['name'] = $name;
                    $entry['role'] = $role;
                    $entry['personId'] = (int) ($item['personId'] ?? 0);
                    $entry['career'] = kop_facility_pages_person_career($name, (string) ($item['pastJobs'] ?? ''), (int) $facility_id, $entry['personId']);
                }
                $items[] = $entry;
            }
            if ($items) $out[$k] = $items;
        }
        return $out;
    }
}

if (!function_exists('kop_facility_pages_incidents')) {
    /**
     * The criticalIncidents lines as a timeline, oldest first:
     * [{when, year, kind, text, source, cite, url}]. A line reads
     * "[Reported] <date>: [<Kind>:] text [(<citation>: url)]"; anything that
     * does not is kept whole, undated, at the end.
     */
    function kop_facility_pages_incidents(array $lines) {
        $out = array();
        foreach ($lines as $i => $line) {
            $text = trim((string) $line);
            if ($text === '') continue;
            $cite = '';
            $url = '';
            if (preg_match('#\s*\(((?:Fornits|Woodbury Reports|HEAL|r/troubledteens wiki)[^()]*(?:\([^()]*\)[^()]*)?)\)\s*\.?$#', $text, $m)) {
                $text = trim(substr($text, 0, -strlen($m[0])));
                if (preg_match('#:?\s*(https?://\S+)$#', $m[1], $u)) {
                    $url = rtrim($u[1], ').');
                    $cite = trim(substr($m[1], 0, -strlen($u[0])));
                } else {
                    $cite = trim($m[1]);
                }
            }
            $when = '';
            $reported = false;
            $sort = 0;
            $names = array('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
            $months = 'Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?';
            if (preg_match('/^(Reported\s+)?(\d{4})-(\d{2})(?:-(\d{2}))?\s*:\s*/u', $text, $m) && (int) $m[3] >= 1 && (int) $m[3] <= 12) {
                // 2000-02-06 / 2025-12, as the wiki and Woodbury facts write them: "February 6, 2000" / "December 2025".
                $reported = $m[1] !== '';
                $day = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
                $when = $names[(int) $m[3] - 1] . ($day ? ' ' . $day . ',' : '') . ' ' . $m[2];
                $sort = (int) $m[3] * 100 + $day;
                $text = substr($text, strlen($m[0]));
            } elseif (preg_match('/^(Reported\s+)?((?:(' . $months . ')\.?\s+(?:(\d{1,2}),?\s+)?)?\d{4}(?:\s*(?:-|to|\x{2013})\s*\d{2,4})?)\s*:\s*/u', $text, $m)) {
                $reported = $m[1] !== '';
                $when = trim($m[2]);
                if (!empty($m[3])) {
                    foreach ($names as $n => $name) {
                        if (stripos($name, substr($m[3], 0, 3)) === 0) $sort = ($n + 1) * 100 + (int) ($m[4] ?? 0);
                    }
                }
                $text = substr($text, strlen($m[0]));
            }
            $kind = '';
            if (preg_match('/^([A-Z][a-z]+(?:\s[a-z]+){0,2}):\s+(?=\S)/', $text, $m)) {
                $kind = $m[1];
                $text = substr($text, strlen($m[0]));
            }
            $year = preg_match('/\d{4}/', $when, $y) ? (int) $y[0] : 9999;
            $out[] = array(
                'when'   => $when !== '' ? ($reported ? 'Reported ' . $when : $when) : '',
                'year'   => $year,
                'kind'   => $kind,
                'text'   => function_exists('mb_strtoupper') ? mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1) : ucfirst($text),
                'source' => $cite !== '' ? kop_facility_pages_source_label($cite) : '',
                'cite'   => $cite,
                'url'    => $url,
                'i'      => $i,
                'sort'   => $sort,
            );
        }
        usort($out, static function ($a, $b) { return ($a['year'] <=> $b['year']) ?: ($a['sort'] <=> $b['sort']) ?: ($a['i'] <=> $b['i']); });
        return kop_facility_pages_incidents_dedupe($out);
    }
}

if (!function_exists('kop_facility_pages_wiki_url')) {
    /**
     * The address of the r/troubledteens wiki page a citation names
     * ('r/troubledteens wiki, page "<title>" (as of <date>)'), from
     * js/data/reddit-wiki/page-urls.json (scripts/build-wiki-page-urls.py):
     * the page of that title and date, else the only page of that title.
     * '' when the citation names none or the title is not known.
     */
    function kop_facility_pages_wiki_url($cite) {
        static $map = null;
        if (!preg_match('/r\/troubledteens wiki, page "([^"]+)"(?: \(as of (\d{4}-\d{2}-\d{2})\))?/', (string) $cite, $m)) return '';
        if ($map === null) {
            $file = dirname(__DIR__) . '/js/data/reddit-wiki/page-urls.json';
            $map = is_readable($file) ? (array) json_decode((string) file_get_contents($file), true) : array();
        }
        $title = trim($m[1]);
        if (!empty($m[2]) && isset($map['dated'][$title . '|' . $m[2]])) return (string) $map['dated'][$title . '|' . $m[2]];
        return (string) ($map['titles'][$title] ?? '');
    }
}

if (!function_exists('kop_facility_pages_woodbury_clean')) {
    /**
     * Text without the Woodbury Reports wording: "(Woodbury Reports, February 2009, p. 20)" goes,
     * "(2009-2010, Woodbury Reports)" keeps its years. The citation is the "source" link, which opens our
     * copy of the issue; the newsletter's name, date and page add nothing a reader needs beside it.
     */
    function kop_facility_pages_woodbury_clean($text) {
        $text = (string) $text;
        if (stripos($text, 'Woodbury Reports') === false) return $text;
        $words = '#(?:,\s*)?Woodbury Reports(?:,\s*[A-Za-z]+\.?\s+\d{4})?(?:\s*\(\#\d+\))?(?:,\s*pp?\.\s*[\d\x{2013}-]+)?(?:,\s*)?#iu';
        $out = preg_replace_callback('#\(((?:[^()]|\([^()]*\))*)\)#', static function ($m) use ($words) {
            if (stripos($m[1], 'Woodbury Reports') === false) return $m[0];
            $kept = trim(preg_replace('/\s+/', ' ', (string) preg_replace($words, ' ', $m[1])), " \t\n,;");
            return $kept !== '' ? '(' . $kept . ')' : '';
        }, $text);
        $out = preg_replace('/[ \t]{2,}/', ' ', (string) $out);
        return trim((string) preg_replace('/\s+([.,;:])/', '$1', $out));
    }
}

if (!function_exists('kop_facility_pages_is_own_source')) {
    /**
     * A citation that only points back to us ("Kids Over Profits network map", a page of this site) says
     * nothing a reader can check, so it is not shown. Our copies of other people's documents in the media
     * library (/wp-content/uploads/) still count: they are the issue, report or filing itself.
     * $src: {source, cite, url}.
     */
    function kop_facility_pages_is_own_source($src) {
        if (!is_array($src)) return false;
        $words = trim((string) ($src['cite'] ?? '') . ' ' . (string) ($src['source'] ?? ''));
        if ($words !== '' && stripos($words, 'woodbury') === false && preg_match('/kids over profits|\bnetwork map\b|\bKOP\b/i', $words)) return true;
        $url = trim((string) ($src['url'] ?? ''));
        if ($url === '') return false;
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $own = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $relative = $host === '' && strpos($url, '/') === 0;
        if (!$relative && ($host === '' || preg_replace('/^www\./', '', $host) !== preg_replace('/^www\./', '', $own))) return false;
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        return strpos($path, '/wp-content/') !== 0;
    }
}

if (!function_exists('kop_facility_pages_tidy_citations')) {
    /**
     * Entries ({text, source, cite, url, also[]}) with the citations that are just us removed and the
     * Woodbury wording taken out of their text. A list of plain strings is cleaned the same way.
     */
    function kop_facility_pages_tidy_citations(array $items) {
        foreach ($items as $k => $it) {
            if (is_string($it)) {
                $items[$k] = kop_facility_pages_woodbury_clean($it);
                continue;
            }
            if (!is_array($it)) continue;
            if (isset($it['text']) && is_string($it['text'])) $it['text'] = kop_facility_pages_woodbury_clean($it['text']);
            if (isset($it['role']) && is_string($it['role'])) $it['role'] = kop_facility_pages_woodbury_clean($it['role']);
            if (kop_facility_pages_is_own_source($it)) {
                foreach (array('source', 'cite', 'url') as $f) if (array_key_exists($f, $it)) $it[$f] = '';
            }
            if (isset($it['also']) && is_array($it['also'])) {
                $it['also'] = array_values(array_filter($it['also'], static function ($a) { return !kop_facility_pages_is_own_source($a); }));
            }
            $items[$k] = $it;
        }
        return $items;
    }
}

if (!function_exists('kop_facility_pages_cited_html')) {
    /**
     * A note's text as HTML with each web address shown as a "source" link
     * (kop_citation_link(), the citation before it as the preview), never the
     * address itself: "(Form 10-K for 2003: https://...)" reads
     * "(Form 10-K for 2003, source)". Everything else is escaped. A Woodbury Reports
     * citation is only the link: "(Woodbury Reports, May 2007, p. 20) https://..." and
     * "(Woodbury Reports, May 2007, p. 20: https://...)" both read "(source)".
     */
    function kop_facility_pages_cited_html($text) {
        $text = (string) $text;
        if (!preg_match_all('#(:\s*)?(https?://[^\s<>"]+)#', $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return esc_html(kop_facility_pages_woodbury_clean($text));
        }
        $out = '';
        $at = 0;
        $link = static function ($url, $cite) {
            return function_exists('kop_citation_link')
                ? kop_citation_link($url, 'source', $cite, true, '', true)
                : '<a href="' . esc_url($url) . '" target="_blank" rel="noopener nofollow">source</a>';
        };
        $woodbury_open = '#\(\s*(?:[^()]|\([^()]*\))*Woodbury Reports(?:[^()]|\([^()]*\))*$#i';        // "(Woodbury Reports, ...: " then the address
        $woodbury_closed = '#\s*\((?:[^()]|\([^()]*\))*Woodbury Reports(?:[^()]|\([^()]*\))*\)\s*$#i'; // "(Woodbury Reports, ...) " then the address
        foreach ($m as $hit) {
            $url = rtrim($hit[2][0], '.,;)');
            $start = $hit[0][1];
            $before = substr($text, $at, $start - $at);
            if ($hit[1][0] !== '' && preg_match($woodbury_open, $before)) {
                $before = preg_replace($woodbury_open, '', $before);
                $out .= esc_html(kop_facility_pages_woodbury_clean($before)) . '(' . $link($url, '');
                $at = $hit[2][1] + strlen($url);
                continue;
            }
            if ($hit[1][0] === '' && preg_match($woodbury_closed, $before)) {
                $before = preg_replace($woodbury_closed, '', $before);
                $out .= esc_html(kop_facility_pages_woodbury_clean($before)) . ' (' . $link($url, '') . ')';
                $at = $hit[2][1] + strlen($url);
                continue;
            }
            // The citation the address belongs to: from the last "(" or ";" before it.
            $cite = trim(preg_replace('#^.*[(;]#s', '', $before), " \t\n)");
            $out .= esc_html(kop_facility_pages_woodbury_clean($before)) . ($hit[1][0] !== '' ? ', ' : '');
            $out .= $link($url, stripos($cite, 'Woodbury Reports') !== false ? '' : $cite);
            $at = $hit[2][1] + strlen($url);
        }
        return $out . esc_html(kop_facility_pages_woodbury_clean(substr($text, $at)));
    }
}

if (!function_exists('kop_facility_pages_incidents_dedupe')) {
    /**
     * One event written twice (two records merged, two wiki pages): the same
     * year, the same "N-year-old", and three more words in common. The fuller
     * one stays (exact date first, then the longer text); the other's source
     * is added to it. Anything less alike is kept as it is.
     */
    function kop_facility_pages_incidents_dedupe(array $items) {
        $stop = array_flip(explode(' ', 'that this with from after into their were have been staff facility program center treatment about which while there where when what they them his her him she'));
        $words = static function ($t) use ($stop) {
            preg_match_all('/[a-z]{4,}/', strtolower((string) $t), $m);
            return array_diff_key(array_flip($m[0]), $stop);
        };
        $keep = array();
        foreach ($items as $inc) {
            $dupe = null;
            if ($inc['year'] !== 9999 && preg_match('/\b(\d{1,2})[- ]year[- ]old\b/i', $inc['text'], $age)) {
                foreach ($keep as $k => $other) {
                    if ($other['year'] !== $inc['year'] || !preg_match('/\b' . $age[1] . '[- ]year[- ]old\b/i', $other['text'])) continue;
                    if (count(array_intersect_key($words($inc['text']), $words($other['text']))) >= 3) { $dupe = $k; break; }
                }
            }
            if ($dupe === null) { $keep[] = $inc; continue; }
            $a = $keep[$dupe];
            $fuller = ($inc['sort'] % 100 > 0) !== ($a['sort'] % 100 > 0) ? ($inc['sort'] % 100 > 0 ? $inc : $a)
                : (mb_strlen($inc['text']) > mb_strlen($a['text']) ? $inc : $a);
            $other = $fuller === $inc ? $a : $inc;
            if ($fuller['kind'] === '') $fuller['kind'] = $other['kind'];
            $fuller['also'] = array_merge($fuller['also'] ?? array(), array($other), $other['also'] ?? array());
            $keep[$dupe] = $fuller;
        }
        return $keep;
    }
}

if (!function_exists('kop_facility_pages_add_map_people')) {
    /**
     * Everyone the network map ties to this program who is not already on
     * its staff lists, as staff cards with their careers: leadership and
     * corporate roles under 'administrator', the rest under 'notableStaff'.
     * Their source is the map (the page's Network section then lists only
     * companies and programs, kop_facility_pages_network()).
     */
    function kop_facility_pages_add_map_people(array $staff, $facility_id) {
        if (!function_exists('kop_network_map_facility_connections')) return $staff;
        $entry = kop_network_map_facility_connections()[(int) $facility_id] ?? null;
        if (!$entry || empty($entry['links'])) return $staff;
        $have = array();
        foreach (array('administrator', 'notableStaff') as $k) {
            foreach ($staff[$k] ?? array() as $p) {
                $key = kop_facility_pages_group_key($p['name'] ?? $p['text'], (int) ($p['personId'] ?? 0));
                if ($key !== '') $have[$key] = true;
            }
        }
        $people = array();
        foreach ($entry['links'] as $link) {
            if (($link['kind'] ?? '') !== 'person' || ($link['relation'] ?? '') !== '') continue;
            $node_pid = kop_facility_pages_map_person_ids()[(string) $link['node']] ?? 0;
            $key = kop_facility_pages_group_key($link['name'], $node_pid);
            if ($key === '' || isset($have[$key])) continue;
            $group = in_array($link['category'], array('leadership', 'corporate'), true) ? 'administrator' : 'notableStaff';
            if (!isset($people[$key])) {
                $people[$key] = array('name' => (string) $link['name'], 'roles' => array(), 'group' => $group, 'node' => (string) $link['node'], 'personId' => $node_pid);
            } elseif ($group === 'administrator') {
                $people[$key]['group'] = 'administrator';
            }
            foreach (explode(' / ', (string) $link['role']) as $r) {
                $r = trim($r);
                if ($r !== '' && strcasecmp($r, 'staff') !== 0 && !in_array($r, $people[$key]['roles'], true)) $people[$key]['roles'][] = $r;
            }
        }
        uasort($people, static function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        $map_url = kop_facility_pages_page_url_by_template('page-network-map.php', '/network-map/');
        foreach ($people as $p) {
            $role = $p['roles'] ? ucfirst(implode(', ', $p['roles'])) : '';
            $staff[$p['group']][] = array(
                'text'   => $p['name'] . ($role !== '' ? ' (' . $role . ')' : ''),
                'source' => 'Network map',
                'cite'   => 'Kids Over Profits network map',
                'url'    => $map_url . '#open=' . rawurlencode($p['node']),
                'name'   => $p['name'],
                'role'   => $role,
                'career' => kop_facility_pages_person_career($p['name'], '', (int) $facility_id, (int) $p['personId']),
                'from_map' => true,
            );
        }
        return $staff;
    }
}

if (!function_exists('kop_facility_pages_person_key')) {
    /**
     * A person's name reduced to "first last" for matching across records:
     * "Dr. Robert H. Crist, MD" and "Robert Crist" are one key; nicknames in
     * quotes, initials, titles and credentials are dropped. '' when fewer than
     * two names remain.
     */
    function kop_facility_pages_person_key($name) {
        $name = (string) $name;
        $name = preg_replace('/^[A-Za-z][A-Za-z &\/-]{2,40}:\s*/', '', $name);
        $name = preg_replace('/["\x{201C}\x{201D}][^"\x{201C}\x{201D}]*["\x{201C}\x{201D}]|\([^)]*\)/u', ' ', $name);
        $name = preg_replace('/,.*$/', '', $name);
        $name = function_exists('remove_accents') ? remove_accents($name) : $name;
        $tokens = preg_split('/[^a-z\']+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
        $drop = array('dr', 'mr', 'mrs', 'ms', 'rev', 'jr', 'sr', 'ii', 'iii', 'iv', 'phd', 'md', 'psyd', 'lcsw', 'lpc', 'lmft', 'rn', 'ma', 'ms', 'msw', 'edd');
        $tokens = array_values(array_filter($tokens, static function ($t) use ($drop) {
            return strlen(trim($t, "'")) > 1 && !in_array($t, $drop, true);
        }));
        if (count($tokens) < 2) return '';
        // "Steve Roach" and "Steven Roach" are one person. Only names that
        // cannot belong to someone else are folded (not Jack/John, Sam, Pat).
        static $nick = array(
            'steve' => 'steven', 'stephen' => 'steven', 'clint' => 'clinton', 'liz' => 'elizabeth', 'beth' => 'elizabeth',
            'bill' => 'william', 'billy' => 'william', 'will' => 'william', 'bob' => 'robert', 'bobby' => 'robert', 'rob' => 'robert',
            'jim' => 'james', 'jimmy' => 'james', 'mike' => 'michael', 'dave' => 'david', 'dan' => 'daniel', 'danny' => 'daniel',
            'tom' => 'thomas', 'tommy' => 'thomas', 'tony' => 'anthony', 'jeff' => 'jeffrey', 'geoff' => 'jeffrey', 'jerry' => 'gerald',
            'joe' => 'joseph', 'chris' => 'christopher', 'matt' => 'matthew', 'andy' => 'andrew', 'nick' => 'nicholas',
            'ken' => 'kenneth', 'kenny' => 'kenneth', 'larry' => 'lawrence', 'ron' => 'ronald', 'ronnie' => 'ronald',
            'don' => 'donald', 'greg' => 'gregory', 'tim' => 'timothy', 'ben' => 'benjamin', 'kathy' => 'kathleen',
            'katie' => 'katherine', 'kate' => 'katherine', 'sue' => 'susan', 'jenny' => 'jennifer', 'jen' => 'jennifer',
            'becky' => 'rebecca', 'debbie' => 'deborah', 'deb' => 'deborah', 'pam' => 'pamela', 'patty' => 'patricia',
            'trish' => 'patricia', 'barb' => 'barbara', 'vicki' => 'victoria', 'mandy' => 'amanda', 'abby' => 'abigail',
            'fred' => 'frederick', 'rick' => 'richard', 'rich' => 'richard', 'dick' => 'richard', 'doug' => 'douglas',
            'josh' => 'joshua', 'zach' => 'zachary', 'ed' => 'edward', 'eddie' => 'edward', 'jon' => 'jonathan',
            'cindy' => 'cynthia', 'sandy' => 'sandra', 'terri' => 'teresa', 'terry' => 'terrence', 'randy' => 'randall',
            'brad' => 'bradley', 'phil' => 'phillip', 'philip' => 'phillip', 'walt' => 'walter',
        );
        $first = $nick[$tokens[0]] ?? $tokens[0];
        return $first . ' ' . $tokens[count($tokens) - 1];
    }
}

if (!function_exists('kop_facility_pages_group_key')) {
    /**
     * What one person's records are grouped by: their person id when the
     * people table knows them (inc/people.php kop_people_group_key: merged
     * names join, separated namesakes split), else the name key.
     */
    function kop_facility_pages_group_key($name, $person_id = 0) {
        return function_exists('kop_people_group_key') ? kop_people_group_key($name, (int) $person_id) : kop_facility_pages_person_key($name);
    }
}

if (!function_exists('kop_facility_pages_map_person_ids')) {
    /** Map node id => personId (stamped by the map build), for the map's person nodes. */
    function kop_facility_pages_map_person_ids() {
        static $memo = null;
        if ($memo !== null) return $memo;
        $memo = array();
        $graph = function_exists('kop_network_map_graph') ? kop_network_map_graph() : null;
        foreach ((array) ($graph['nodes'] ?? array()) as $node) {
            if (($node['kind'] ?? '') === 'person' && !empty($node['personId'])) $memo[(string) $node['id']] = (int) $node['personId'];
        }
        return $memo;
    }
}

if (!function_exists('kop_facility_pages_people_index')) {
    /**
     * person key => [[facility id, facility name, role], ...] from the staff
     * lists of every facility record. Cached with the page index's
     * fingerprint, so an edit to any record rebuilds it.
     */
    function kop_facility_pages_people_index() {
        global $wpdb;
        static $memo = null;
        if ($memo !== null) return $memo;
        $index = kop_facility_pages_index();
        $fingerprint = (string) ($index['fingerprint'] ?? '');
        // A merge or a separation of people (inc/people.php) regroups them.
        if ($fingerprint !== '') $fingerprint .= '|' . (string) get_option('kop_people_version', '');
        $cached = get_transient('kop_facility_pages_people');
        if ($fingerprint !== '' && is_array($cached) && ($cached['fingerprint'] ?? '') === $fingerprint) {
            return $memo = $cached['people'];
        }
        $people = array();
        $rows = $wpdb->get_results("SELECT id, name, json_data FROM facilities_v2 WHERE json_data LIKE '%\"name\"%'", ARRAY_A);
        foreach ((array) $rows as $row) {
            $doc = json_decode((string) $row['json_data'], true);
            if (!is_array($doc) || empty($doc['staff']) || !is_array($doc['staff'])) continue;
            foreach (array('administrator', 'notableStaff') as $k) {
                foreach ((array) ($doc['staff'][$k] ?? array()) as $person) {
                    if (!is_array($person)) continue;
                    $key = kop_facility_pages_group_key($person['name'] ?? '', (int) ($person['personId'] ?? 0));
                    if ($key === '') continue;
                    $role = trim((string) ($person['role'] ?? ''));
                    $people[$key][] = array((int) $row['id'], (string) $row['name'], $role);
                }
            }
        }
        if ($fingerprint !== '') set_transient('kop_facility_pages_people', array('fingerprint' => $fingerprint, 'people' => $people), DAY_IN_SECONDS);
        return $memo = $people;
    }
}

if (!function_exists('kop_facility_pages_map_people')) {
    /**
     * person key => [[place name, role, facility id, kind], ...] from the
     * network map's person nodes and their lines to programs and companies.
     */
    function kop_facility_pages_map_people() {
        static $memo = null;
        if ($memo !== null) return $memo;
        $memo = array();
        $graph = function_exists('kop_network_map_graph') ? kop_network_map_graph() : null;
        if (!$graph) return $memo;
        $nodes = array();
        foreach ($graph['nodes'] as $node) $nodes[(string) $node['id']] = $node;
        foreach ((array) ($graph['edges'] ?? array()) as $edge) {
            foreach (array('source', 'target') as $end) {
                $person = $nodes[(string) $edge[$end]] ?? null;
                $place = $nodes[(string) $edge[$end === 'source' ? 'target' : 'source']] ?? null;
                if (!$person || !$place || ($person['kind'] ?? '') !== 'person' || ($place['kind'] ?? '') === 'person') continue;
                $roles = array_filter(array_map('trim', array_map('strval', (array) ($edge['roles'] ?? array()))), static function ($r) {
                    return $r !== '' && strcasecmp($r, 'affiliated') !== 0 && strcasecmp($r, 'staff') !== 0;
                });
                $names = array_merge(array((string) $person['name']), array_map('strval', (array) ($person['aliases'] ?? array())));
                $pid = (int) ($person['personId'] ?? 0);
                $keys = array();
                foreach ($names as $n) $keys[] = kop_facility_pages_group_key($n, $pid);
                foreach (array_unique(array_filter($keys)) as $key) {
                    $memo[$key][] = array((string) $place['name'], implode(', ', $roles), (int) ($place['facilityId'] ?? 0), (string) ($place['kind'] ?? ''));
                }
            }
        }
        return $memo;
    }
}

if (!function_exists('kop_facility_pages_facility_url_by_name')) {
    /** The facility page for a program named in free text, or ''. One lookup table per request. */
    function kop_facility_pages_facility_url_by_name($name) {
        static $exact = null, $loose = null;
        if ($exact === null) {
            $exact = $loose = array();
            foreach (kop_facility_pages_index()['ids'] ?? array() as $id => $entry) {
                $n = (string) $entry['name'];
                $exact[strtolower($n)] = $exact[strtolower($n)] ?? (int) $id;
                $k = kop_facility_pages_name_key($n);
                if ($k !== '' && !isset($loose[$k])) $loose[$k] = (int) $id;
            }
        }
        $name = trim((string) $name);
        if ($name === '') return '';
        $id = $exact[strtolower($name)] ?? ($loose[kop_facility_pages_name_key($name)] ?? 0);
        return $id ? kop_facility_page_url($id) : '';
    }
}

if (!function_exists('kop_facility_pages_person_career')) {
    /**
     * Where else in the industry a person worked: [{role, place, years, url}],
     * one entry per place, grouped by the person ($person_id, the entry's
     * personId) when the people table knows them, else by the name. Their own pastJobs text ("Role - Place (years);
     * ...") comes first, then the other records that list them as staff and
     * the network map. This program ($facility_id) is left out.
     */
    function kop_facility_pages_person_career($name, $past_jobs, $facility_id, $person_id = 0) {
        $key = kop_facility_pages_group_key($name, $person_id);
        $here = (int) $facility_id;
        $index_ids = kop_facility_pages_index()['ids'] ?? array();
        $here_names = array();
        if ($here && isset($index_ids[$here])) $here_names[] = kop_facility_pages_name_key($index_ids[$here]['name']);
        $out = array();
        $add = static function ($place, $role, $years, $url) use (&$out, $here_names) {
            $place = trim((string) $place);
            if ($place === '') return;
            $pk = kop_facility_pages_name_key($place);
            if ($pk === '' || in_array($pk, $here_names, true)) return;
            if (isset($out[$pk])) {
                if ($out[$pk]['role'] === '' && $role !== '') $out[$pk]['role'] = $role;
                if ($out[$pk]['url'] === '' && $url !== '') $out[$pk]['url'] = $url;
                return;
            }
            $out[$pk] = array('role' => trim((string) $role), 'place' => $place, 'years' => trim((string) $years), 'url' => $url);
        };

        foreach (preg_split('/\s*;\s*/', trim((string) $past_jobs), -1, PREG_SPLIT_NO_EMPTY) as $job) {
            $job = trim(preg_replace('/\s*\[[^\]]*\]\s*/', ' ', $job));
            $job = preg_replace('/^(?:later|then|now|previously|formerly)\s+/i', '', $job);
            $role = '';
            $place = $job;
            $pos = strrpos($job, ' - ');
            if ($pos !== false) {
                $role = trim(substr($job, 0, $pos));
                $place = trim(substr($job, $pos + 3));
            }
            $years = '';
            if (preg_match('/\s*\(([^)]*\d{4}[^)]*)\)\s*$/', $place, $m)) {
                $years = $m[1];
                $place = trim(substr($place, 0, -strlen($m[0])));
            }
            $add($place, $role, $years, kop_facility_pages_facility_url_by_name($place));
        }
        if ($key === '') return array_values($out);

        foreach (kop_facility_pages_people_index()[$key] ?? array() as $hit) {
            list($fid, $fname, $role) = $hit;
            if ($fid === $here) continue;
            // "Director (2008, Woodbury Reports), left": the year stays, the citation and the "left" go.
            $role = trim(preg_replace_callback('/\s*\(([^()]*)\)/', static function ($m) {
                if (!preg_match('/Woodbury|HEAL|wiki|Fornits/i', $m[1])) return $m[0];
                return preg_match('/\d{4}(?:-\d{4})?/', $m[1], $y) ? ' (' . $y[0] . ')' : '';
            }, kop_facility_pages_drop_left($role)), ' ,');
            $add($fname, $role, '', isset($index_ids[$fid]) ? kop_facility_page_url($fid) : '');
        }
        foreach (kop_facility_pages_map_people()[$key] ?? array() as $hit) {
            list($place, $role, $fid, $kind) = $hit;
            if ($fid && $fid === $here) continue;
            $url = '';
            if ($fid && isset($index_ids[$fid])) {
                $url = kop_facility_page_url($fid);
            } elseif ($kind === 'parent' && function_exists('kop_operator_page_url_for_name')) {
                $url = (string) kop_operator_page_url_for_name($place);
            }
            $add($place, $role, '', $url);
        }
        return array_values($out);
    }
}

if (!function_exists('kop_facility_pages_note_sources')) {
    /**
     * Where the facts came from, read off the lines Woodbury Facts leaves in
     * the notes ("Start year: 1998 (r/troubledteens wiki, page "X" (as of
     * 2025-12-18): https://...)"): fact label => [{source, cite, url}].
     */
    function kop_facility_pages_note_sources(array $notes) {
        $by = array(
            'Type: ' => 'Type', 'Start year: ' => 'Operated', 'Closed in ' => 'Operated', 'Ages ' => 'Serves',
            'Serves: ' => 'Serves', 'Capacity: ' => 'Capacity', 'Operator/owner: ' => 'Past operators',
            'Past name: ' => 'formerly', 'Former location: ' => 'former_locations',
        );
        $out = array();
        foreach ($notes as $line) {
            $line = trim((string) $line);
            foreach ($by as $prefix => $key) {
                if (strpos($line, $prefix) !== 0) continue;
                if (!preg_match('#\(((?:Woodbury Reports|HEAL|r/troubledteens wiki|Fornits)[^()]*(?:\([^()]*\)[^()]*)?)\)#', $line, $m)) break;
                $cite = trim(preg_replace('#:?\s*https?://\S+$#', '', $m[1]));
                $url = preg_match('#(https?://\S+?)[).]*$#', $line, $u) ? $u[1] : '';
                $seen = false;
                foreach ($out[$key] ?? array() as $have) {
                    if ($have['cite'] === $cite) $seen = true;
                }
                if (!$seen) $out[$key][] = array('source' => kop_facility_pages_source_label($cite), 'cite' => $cite, 'url' => $url);
                break;
            }
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

if (!function_exists('kop_facility_pages_testimony')) {
    /**
     * Published survivor testimony as [{text, date_label, submitted}]. Only
     * entries an admin marked "OK to publish" (publish === true) are
     * returned. The source stays internal; submitted says it came from a
     * survivor's own submission ("Submitted by a survivor (submission #50)",
     * or the older "Submission #50"), which the caption names.
     */
    function kop_facility_pages_testimony($value) {
        $out = array();
        if (!is_array($value)) return $out;
        foreach ($value as $entry) {
            if (!is_array($entry) || ($entry['publish'] ?? false) !== true) continue;
            $text = trim((string) ($entry['text'] ?? ''));
            if ($text === '') continue;
            $date = trim((string) ($entry['date'] ?? ''));
            $time = $date !== '' ? strtotime($date) : false;
            $out[] = array(
                'text'       => $text,
                'date_label' => $time ? date('F Y', $time) : '',
                'submitted'  => (bool) preg_match('/^(Submitted by a survivor|Submission #\d+)/i', trim((string) ($entry['source'] ?? ''))),
            );
        }
        return $out;
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
        if (kop_facility_pages_testimony($doc['survivorTestimony'] ?? null)) $s[] = 'survivorTestimony';
        if (kop_facility_pages_resources_held($doc['resources'] ?? null)) $s[] = 'resources';
        if (kop_facility_pages_resource_links($doc['resourceLinks'] ?? null)) $s[] = 'resourceLinks';

        $loc = isset($doc['location']) && is_array($doc['location']) ? $doc['location'] : array();
        if (kop_facility_pages_has_value($loc['additionalLocations'] ?? null)) $s[] = 'location.additionalLocations';
        if (kop_facility_pages_has_value($loc['formerLocations'] ?? null)) $s[] = 'location.formerLocations';
        if (!empty($doc['documentFolderId'])) $s[] = 'documentFolderId';

        // Linked records.
        $id = (int) $id;
        if (!empty($links['news'][$id])) $s[] = 'news';
        if (!empty($links['lawsuits'][$id])) $s[] = 'lawsuits';
        if (!empty($links['operators'][$id])) $s[] = 'operator';
        if (!empty($links['network'][$id])) $s[] = 'network';
        if (!empty($links['research'][$id])) $s[] = 'research';
        if (!empty($links['unsilenced'][$id])) $s[] = 'unsilenced';
        if (!empty($links['survivor_sites'][$id])) $s[] = 'survivorSites';

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

if (!function_exists('kop_facility_pages_operator_url')) {
    /**
     * Where a facility page's operator name links: the operator's own page
     * (inc/operator-pages.php) when there is one, else the program index
     * filtered to the name.
     */
    function kop_facility_pages_operator_url($operator_row, $operator_name) {
        if (function_exists('kop_operator_page_url')) {
            $url = $operator_row ? kop_operator_page_url((int) $operator_row['id']) : '';
            if ($url === '' && $operator_name !== '' && function_exists('kop_operator_page_url_for_name')) {
                $url = kop_operator_page_url_for_name($operator_name);
            }
            if ($url !== '') return $url;
        }
        return $operator_name !== '' ? kop_facility_pages_index_search_url($operator_name) : '';
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

if (!function_exists('kop_facility_pages_archive_exempt_domains')) {
    /**
     * Domains whose links stay live: the archives themselves, this site, the
     * places survivors and researchers gather. Shared with the page scripts
     * through js/shared/program-links.js, so PHP and JS apply one list.
     */
    function kop_facility_pages_archive_exempt_domains() {
        return array(
            'archive.org', 'archive.today', 'archive.ph', 'archive.is',
            'kidsoverprofits.org',
            'reddit.com', 'redd.it', 'wikipedia.org', 'wikimedia.org',
            'linktr.ee',
            'facebook.com', 'instagram.com', 'twitter.com', 'x.com',
            'youtube.com', 'youtu.be', 'linkedin.com',
        );
    }
}

if (!function_exists('kop_facility_pages_archive_exempt_host')) {
    /**
     * True for a host that must keep its live link: the archives themselves,
     * this site, the places survivors and researchers gather, and public
     * records. Every other host in a facility's links is taken to belong to
     * the program or its operator. Add a host here when a link turns out to
     * belong to somebody other than the industry.
     */
    function kop_facility_pages_archive_exempt_host($host) {
        $host = strtolower(preg_replace('/^www\./', '', (string) $host));
        if ($host === '') return true;

        foreach (kop_facility_pages_archive_exempt_domains() as $domain) {
            if ($host === $domain || substr($host, -(strlen($domain) + 1)) === '.' . $domain) {
                return true;
            }
        }

        // Public records: the federal and military domains, and the state,
        // county and school portals that publish licensing and inspections.
        if (preg_match('/\.(gov|mil)$/', $host)) return true;
        if (preg_match('/\.(state|co|ci|k12)\.[a-z]{2}\.us$/', $host)) return true;

        return false;
    }
}

if (!function_exists('kop_facility_pages_archive_link')) {
    /**
     * A program's own website, rewritten to an archived snapshot. Sending a
     * reader to the live marketing page hands the program the traffic, and
     * that page changes or disappears, while the snapshot keeps what it said.
     * Wayback resolves /web/<url> to its newest snapshot (and offers to take
     * one when it holds none).
     *
     * Returns url, label, live_url and go_url; live_url and go_url are ''
     * when the link was left alone, which is also when url and label come
     * back unchanged. go_url is the only way the live site is linked: see
     * kop_program_go_url().
     */
    function kop_facility_pages_archive_link($url, $label = '') {
        $url   = trim((string) $url);
        $label = trim((string) $label);
        $out   = array('url' => $url, 'label' => $label, 'live_url' => '', 'go_url' => '');

        if ($url === '' || !preg_match('#^https?://#i', $url)) return $out;
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (kop_facility_pages_archive_exempt_host($host)) return $out;

        $name = $label !== '' ? $label : preg_replace('/^www\./', '', strtolower((string) $host));
        return array(
            'url'      => 'https://web.archive.org/web/' . $url,
            'label'    => $name . ' (archived copy)',
            'live_url' => $url,
            'go_url'   => kop_program_go_url($url),
        );
    }
}

if (!function_exists('kop_facility_pages_video_parse')) {
    /** [provider, id] for a YouTube or Vimeo address, or null. */
    function kop_facility_pages_video_parse($url) {
        $url = trim((string) $url);
        if (preg_match('#^https?://(?:www\.|m\.)?(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^\#]*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})#i', $url, $m)) {
            return array('youtube', $m[1]);
        }
        if (preg_match('#^https?://(?:www\.)?vimeo\.com/(?:video/)?(\d{6,})#i', $url, $m)) {
            return array('vimeo', $m[1]);
        }
        return null;
    }
}

if (!function_exists('kop_facility_pages_videos')) {
    /**
     * Videos for a record's video library: the record's "videos" list
     * ({url, title, source}) plus any YouTube or Vimeo address already in its
     * resourceLinks or profileLinks. Each: {provider, id, title, url, source,
     * thumb}. Deduplicated; embeds play only after a click (templates/facility-page.php).
     */
    function kop_facility_pages_videos($doc) {
        $candidates = array();
        foreach ((array) ($doc['videos'] ?? array()) as $v) {
            if (is_string($v)) $v = array('url' => $v);
            if (is_array($v)) $candidates[] = $v;
        }
        foreach ((array) ($doc['resourceLinks'] ?? array()) as $v) {
            if (is_array($v) && stripos((string) ($v['source'] ?? ''), 'Fornits') !== 0) $candidates[] = $v;
        }
        foreach ((array) ($doc['profileLinks'] ?? array()) as $v) {
            $candidates[] = is_array($v) ? $v : array('url' => $v);
        }
        $out = array();
        foreach ($candidates as $c) {
            $url = trim((string) ($c['url'] ?? ''));
            $p = kop_facility_pages_video_parse($url);
            if (!$p || isset($out[$p[0] . $p[1]])) continue;
            $title = trim((string) ($c['title'] ?? $c['label'] ?? ''));
            if ($title === '' || preg_match('#^https?://#i', $title)) $title = 'Video';
            $out[$p[0] . $p[1]] = array(
                'provider' => $p[0],
                'id'       => $p[1],
                'title'    => $title,
                'url'      => $url,
                'source'   => trim((string) ($c['source'] ?? '')),
                'thumb'    => $p[0] === 'youtube' ? 'https://i.ytimg.com/vi/' . $p[1] . '/hqdefault.jpg' : '',
            );
        }
        return array_values($out);
    }
}

if (!function_exists('kop_facility_pages_resource_links')) {
    /**
     * A record's resourceLinks, grouped by kind in kop_facility_resource_link_kinds()
     * order: [{kind, label, links: [{url, label, live_url, go_url}]}]. They are
     * the project's sources (reports, court records, survivors, reference) and
     * keep their live address; only an 'other' link, which may be the
     * program's own page, is shown as a snapshot like the external links.
     */
    function kop_facility_pages_resource_links($value) {
        if (!is_array($value) || !function_exists('kop_facility_resource_link_list')) return array();
        $kinds = kop_facility_resource_link_kinds();
        $by = array();
        foreach (kop_facility_resource_link_list($value) as $l) {
            $label = $l['label'];
            if ($label === '' || preg_match('#^https?://#i', $label)) {
                $host = wp_parse_url($l['url'], PHP_URL_HOST);
                $label = $host ? preg_replace('/^www\./', '', strtolower($host)) : $l['url'];
            }
            $link = $l['kind'] === 'other'
                ? kop_facility_pages_archive_link($l['url'], $label)
                : array('url' => kop_heal_archive_url($l['url']), 'label' => $label, 'live_url' => '', 'go_url' => '');
            $link['credit'] = kop_facility_pages_resource_link_credit($l['source']);
            $by[$l['kind']][] = $link;
        }
        $out = array();
        foreach ($kinds as $kind => $kind_label) {
            if (!empty($by[$kind])) {
                // Each archive that asked to be credited, once under the group, with how many of its links it holds.
                $credits = array();
                foreach ($by[$kind] as $link) {
                    if ($link['credit']) {
                        $key = $link['credit']['url'];
                        $credits[$key] = $credits[$key] ?? $link['credit'] + array('count' => 0);
                        $credits[$key]['count']++;
                    }
                }
                $out[] = array('kind' => $kind, 'label' => $kind_label, 'links' => $by[$kind], 'credits' => array_values($credits));
            }
        }
        return $out;
    }
}

if (!function_exists('kop_facility_pages_resource_link_credit')) {
    /**
     * The credit a resource link's source asks for, linked: SCIAD NET, to its
     * archived page (owner decision 2026-10-01, added through KOP Tools > Drive
     * Docs; records added before 2026-10-05 carry a longer source label, which
     * the prefix still matches). Null for every other source.
     *
     * @return array{label: string, url: string}|null
     */
    function kop_facility_pages_resource_link_credit($source) {
        if (stripos((string) $source, 'SCIAD NET') === 0) {
            return array('label' => 'SCIAD NET', 'url' => 'https://web.archive.org/web/20221007171605/https://www.sciad.net/');
        }
        return null;
    }
}

// ---------------------------------------------------------------------------
// /go/: the one way a page links a program's live site
// ---------------------------------------------------------------------------
//
// The archived snapshot is always the primary link. A reader who wants the
// live site gets it through /go/?u=<url>, which answers noindex/nofollow,
// forwards with no referrer, and is disallowed in robots.txt, so the program
// gets no ranking and no referral from us. It only forwards to hosts that
// appear in our own records, so it cannot be used as an open redirect.

if (!function_exists('kop_program_go_url')) {
    function kop_program_go_url($url) {
        return home_url('/go/') . '?u=' . rawurlencode(trim((string) $url));
    }
}

if (!function_exists('kop_program_go_hosts')) {
    /**
     * Every host any of our records links to: facilities, operators,
     * referrers and transporters, anywhere in the record, so any link a page
     * draws from our data can go through /go/. Cached for six hours.
     *
     * @return array<string,bool> host (lowercase, no www.) => true
     */
    function kop_program_go_hosts($refresh = false) {
        $cached = $refresh ? false : get_transient('kop_program_go_hosts');
        if (is_array($cached)) return $cached;
        global $wpdb;
        $hosts = array();
        $add = function ($value) use (&$hosts, &$add) {
            if (is_array($value)) {
                foreach ($value as $v) $add($v);
                return;
            }
            if (!is_string($value) || !preg_match('#^https?://#i', trim($value))) return;
            $host = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url(trim($value), PHP_URL_HOST)));
            if ($host !== '') $hosts[$host] = true;
        };
        if (function_exists('kop_v2_tables_ready') && kop_v2_tables_ready()) {
            foreach ((array) $wpdb->get_col("SELECT json_data FROM facilities_v2") as $json) {
                $doc = json_decode((string) $json, true);
                if (is_array($doc)) $add($doc);
            }
            foreach ((array) $wpdb->get_col("SELECT json_data FROM {$wpdb->prefix}kop_operators") as $json) {
                $doc = json_decode((string) $json, true);
                if (is_array($doc)) $add($doc);
            }
        }
        foreach (array('referrers_master', 'transporters_master') as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) continue;
            foreach ((array) $wpdb->get_col("SELECT json_data FROM {$table}") as $json) {
                $doc = json_decode((string) $json, true);
                if (is_array($doc)) $add($doc);
            }
        }
        set_transient('kop_program_go_hosts', $hosts, 6 * HOUR_IN_SECONDS);
        return $hosts;
    }
}

if (!function_exists('kop_program_go_target')) {
    /**
     * The URL /go/ may forward to, or '' when it must not: not http(s), or a
     * host none of our records links to. A miss rebuilds the host list once
     * (at most every ten minutes) so a link added since the last build works.
     */
    function kop_program_go_target($raw) {
        $url = trim((string) $raw);
        if ($url === '' || !preg_match('#^https?://#i', $url) || preg_match('/[\s<>"]/', $url)) return '';
        $host = strtolower(preg_replace('/^www\./', '', (string) wp_parse_url($url, PHP_URL_HOST)));
        if ($host === '') return '';
        $hosts = kop_program_go_hosts();
        if (!isset($hosts[$host]) && !get_transient('kop_program_go_rebuilt')) {
            set_transient('kop_program_go_rebuilt', 1, 10 * MINUTE_IN_SECONDS);
            $hosts = kop_program_go_hosts(true);
        }
        return isset($hosts[$host]) ? $url : '';
    }
}

if (!function_exists('kop_program_go_route')) {
    function kop_program_go_route() {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (rtrim($path, '/') !== rtrim((string) wp_parse_url(home_url('/go/'), PHP_URL_PATH), '/')) return;

        header('X-Robots-Tag: noindex, nofollow', true);
        header('Referrer-Policy: no-referrer', true);
        nocache_headers();

        $target = kop_program_go_target(wp_unslash($_GET['u'] ?? ''));
        if ($target === '') {
            status_header(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo "This link is not one of ours.\n";
            exit;
        }
        // wp_redirect, not wp_safe_redirect: the target is external by design
        // and was checked against our own records above.
        wp_redirect($target, 302, 'Kids Over Profits');
        exit;
    }
    add_action('template_redirect', 'kop_program_go_route', -10);
}

if (!function_exists('kop_program_go_robots')) {
    function kop_program_go_robots($output) {
        return rtrim((string) $output) . "\nDisallow: /go/\n";
    }
    add_filter('robots_txt', 'kop_program_go_robots', 20);
}

if (!function_exists('kop_program_links_register_script')) {
    /**
     * js/shared/program-links.js, registered for any page script to depend
     * on as 'kop-program-links', with the exempt list and the /go/ base.
     */
    function kop_program_links_register_script() {
        $rel = '/js/shared/program-links.js';
        $path = get_stylesheet_directory() . $rel;
        if (!file_exists($path)) return;
        // kop-url-labels (inc/url-labels.php) gives the links words instead of addresses.
        wp_register_script('kop-program-links', get_stylesheet_directory_uri() . $rel, array('kop-url-labels'), filemtime($path), true);
        wp_localize_script('kop-program-links', 'KOP_PROGRAM_LINKS', array(
            'goBase' => home_url('/go/'),
            'exempt' => kop_facility_pages_archive_exempt_domains(),
        ));
    }
    add_action('wp_enqueue_scripts', 'kop_program_links_register_script', 1);
}

if (!function_exists('kop_facility_pages_location_search_url')) {
    /** The directory's location tab filtered to a facility name. Every facility is listed there. */
    function kop_facility_pages_location_search_url($name) {
        $index_url = function_exists('kop_location_index_url') ? kop_location_index_url() : home_url('/tti-program-index/?view=location');
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
        foreach (array('treatmentTypes' => 'Treatment methods', 'philosophy' => 'Program philosophy', 'conditions' => 'Conditions treated') as $k => $label) {
            $items = kop_facility_pages_checklist_items($doc[$k] ?? null, $k);
            if ($items) $practices[] = array('label' => $label, 'items' => $items);
        }
        $incidents = kop_facility_pages_incidents(kop_facility_pages_checklist_items($doc['criticalIncidents'] ?? null, 'criticalIncidents'));
        $staff = kop_facility_pages_add_map_people(kop_facility_pages_staff_items($doc['staff'] ?? null, $facility_id), $facility_id);
        $notes = array_merge(kop_facility_pages_clean_notes($doc['notes'] ?? null), kop_facility_pages_clean_notes($op['notes'] ?? null));
        $fact_sources = kop_facility_pages_note_sources($notes);
        foreach ($fact_sources as $key => $list) {
            $fact_sources[$key] = array_values(array_filter($list, static function ($s) { return !kop_facility_pages_is_own_source($s); }));
            if (!$fact_sources[$key]) unset($fact_sources[$key]);
        }
        $field_notes = kop_facility_pages_field_notes($doc['fieldNotes'] ?? null);
        // Citations that are just us leave and the Woodbury wording goes from the text; the "source" link stays.
        $incidents = kop_facility_pages_tidy_citations($incidents);
        $field_notes = kop_facility_pages_tidy_citations($field_notes);
        foreach ($staff as $group => $people) $staff[$group] = kop_facility_pages_tidy_citations($people);
        $testimony = kop_facility_pages_testimony($doc['survivorTestimony'] ?? null);
        // What survivors and families wrote on the Fornits forum is testimony, not a finding of the record:
        // its incident lines, leads and discussion links leave their sections and join the survivor testimony.
        $forum_incidents = array();
        foreach ($incidents as $i => $inc) {
            if (stripos((string) $inc['cite'], 'Fornits') === 0) {
                $forum_incidents[] = $inc;
                unset($incidents[$i]);
            }
        }
        $forum_incidents = kop_facility_pages_tidy_citations($forum_incidents);
        $incidents = array_values($incidents);
        $forum_leads = array();
        foreach ($notes as $i => $note) {
            if (stripos($note, 'Fornits lead') === 0) {
                $forum_leads[] = $note;
                unset($notes[$i]);
            }
        }
        $notes = array_values($notes);
        $forum_links = array();
        $other_resource_links = array();
        if (is_array($doc['resourceLinks'] ?? null) && function_exists('kop_facility_resource_link_list')) {
            foreach (kop_facility_resource_link_list($doc['resourceLinks']) as $rl) {
                if (stripos((string) $rl['source'], 'Fornits') === 0) {
                    $forum_links[] = array('url' => $rl['url'], 'label' => $rl['label'] !== '' ? $rl['label'] : 'Forum thread');
                } else {
                    $other_resource_links[] = $rl;
                }
            }
        }
        $forum = ($forum_incidents || $forum_leads || $forum_links)
            ? array('incidents' => $forum_incidents, 'leads' => $forum_leads, 'links' => $forum_links)
            : array();
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
            // The program's own site is shown as a snapshot, with the live
            // page offered second (kop_facility_pages_archive_link).
            $profile_links[] = kop_facility_pages_archive_link($url, $label);
        }
        $resources = kop_facility_pages_resources_held($doc['resources'] ?? null);
        $resource_links = kop_facility_pages_resource_links($other_resource_links);

        // ---- Linked records ----------------------------------------------------
        $name_keys = kop_facility_pages_doc_name_keys($doc, $unique_name);
        $news = kop_facility_pages_news($facility_id);
        $research = kop_facility_pages_research($facility_id);
        $lawsuits = kop_facility_pages_lawsuits($facility_id, $name_keys);
        $memorials = kop_facility_pages_memorials($name_keys, $state_name);
        $wiki = kop_facility_pages_wiki($name, $unique_name, $current_name);
        $inspections = kop_facility_pages_inspections($name_keys, $state_code, $state_name, $facility_id);
        $documents = kop_facility_pages_documents($doc, $entry ? $entry['folder'] : 0);
        $unsilenced = function_exists('kop_unsilenced_archive') ? kop_unsilenced_archive('f', $facility_id) : null;
        $survivor_sites = function_exists('kop_survivor_archives') ? kop_survivor_archives('f', $facility_id) : array();

        // ---- Homes of a program (inc/program-homes.php) ------------------------
        // A program record lists its homes and takes in their news, lawsuits
        // and serious findings; a home names its program and the other homes,
        // which then leave the "Same operator" list.
        $program_homes = null;
        $home_of = null;
        if (function_exists('kop_program_homes_homes_of') && kop_program_homes_homes_of($facility_id)) {
            $own_findings = array();
            foreach ((array) ($inspections['violations'] ?? array()) as $v) $own_findings[] = (int) $v['id'];
            $program_homes = kop_program_homes_rollup($facility_id, $state_code, array(
                'news'       => array_map(static function ($n) { return (int) $n['id']; }, $news),
                'lawsuits'   => array_map(static function ($l) { return (int) $l['id']; }, $lawsuits),
                'violations' => $own_findings,
            ));
            $news = array_merge($news, $program_homes['news']);
            usort($news, static function ($a, $b) { return strcmp((string) $b['date'], (string) $a['date']) ?: $b['id'] - $a['id']; });
            $lawsuits = array_merge($lawsuits, $program_homes['lawsuits']);
        } elseif (function_exists('kop_program_homes_for_home')) {
            $home_of = kop_program_homes_for_home($facility_id);
        }
        if ($program_homes || $home_of) {
            $family = array();
            foreach (($program_homes ? $program_homes['homes'] : $home_of['others']) as $h) $family[(string) $h['url']] = true;
            if ($home_of) $family[(string) $home_of['program']['url']] = true;
            $siblings = array_values(array_filter($siblings, static function ($s) use ($family) {
                return $s['url'] === '' || !isset($family[(string) $s['url']]);
            }));
        }

        // ---- Copy ----------------------------------------------------------------
        $summary = kop_facility_pages_summary_sentence($name, $type, $place, $operator_name, $status, $start, $end, $years_text);
        $seo_title = $name . ($place !== '' ? ' (' . $place . ')' : '') . ' | Kids Over Profits';
        $seo_description = $summary;
        // A merged profile post's content is printed on the page; its excerpt is the description it had.
        $profile_post = $entry ? (int) ($entry['profile_post'] ?? 0) : 0;
        $profile = $profile_post > 0 && function_exists('get_post') ? get_post($profile_post) : null;
        if ($profile && trim((string) $profile->post_excerpt) !== '') {
            $seo_description = trim((string) $profile->post_excerpt);
        }
        if (mb_strlen($seo_description) > 155) {
            $seo_description = rtrim(mb_substr($seo_description, 0, 152), " ,;:") . '...';
        }

        $slug = $entry ? $entry['slug'] : kop_facility_page_slug_candidate($name, $state_code, $country);
        $updated = (string) $row['updated_at'];

        // ---- One section per name of a renamed program (inc/facility-eras.php) ----
        // A merged profile post writes its own sections, so its page stays whole.
        $eras = null;
        if (!$profile && function_exists('kop_facility_eras_build')) {
            $violations = (array) ($inspections['violations'] ?? array());
            if (!empty($program_homes['violations'])) $violations = array_merge($violations, $program_homes['violations']);
            $eras = kop_facility_eras_build($facility_id, array(
                'memorials' => $memorials, 'violations' => $violations, 'lawsuits' => $lawsuits,
                'incidents' => $incidents, 'news' => $news, 'staff' => $staff,
            ));
        }

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
            'end_year'      => $end,
            'place'         => $place,
            'city'          => $city,
            'state_code'    => $state_code,
            'state_name'    => $state_name,
            'country'       => $country,
            'hub_name'      => $hub_name,
            'hub_url'       => $hub_url,
            'operator'      => array(
                'name' => $operator_name,
                'url'  => kop_facility_pages_operator_url($operator_row, $operator_name),
            ),
            'siblings'      => $siblings,
            'program_homes' => $program_homes,
            // A company converted into this program: its written history (inc/program-homes-convert.php).
            'program_history' => function_exists('kop_phc_program_history') ? kop_phc_program_history($facility_id) : null,
            'home_of'       => $home_of,
            'addresses'     => $addresses,
            'former_locations' => $former,
            'operated'      => $operated,
            'summary'       => $summary,
            'facts'         => $facts,
            'fact_sources'  => $fact_sources,
            'practices'     => $practices,
            'incidents'     => $incidents,
            'staff'         => $staff,
            'notes'         => $notes,
            'field_notes'   => $field_notes,
            'testimony'     => $testimony,
            'forum'         => $forum,
            'videos'        => kop_facility_pages_videos($doc),
            'profile_links' => $profile_links,
            'resources'     => $resources,
            'resource_links' => $resource_links,
            'news'          => $news,
            'lawsuits'      => $lawsuits,
            'memorials'     => $memorials,
            'eras'          => $eras,
            'wiki'          => $wiki,
            'inspections'   => $inspections,
            'documents'     => $documents,
            'research'      => $research,
            'unsilenced'    => $unsilenced,
            'survivor_sites' => $survivor_sites,
            'network'       => kop_facility_pages_network($facility_id),
            'updated_at'    => $updated,
            'updated_label' => $updated !== '' ? date_i18n(get_option('date_format') ?: 'F j, Y', strtotime($updated) ?: time()) : '',
            'index_url'     => kop_facility_pages_location_search_url($name),
            'lawsuits_url'  => kop_facility_pages_page_url_by_template('page-lawsuits.php', '/lawsuits/'),
            'memorial_url'  => kop_facility_pages_page_url_by_template('page-memorial.php', '/memorial/'),
            'news_url'      => kop_facility_pages_page_url_by_template('page-news-feed.php', '/news/'),
            'wiki_url'      => add_query_arg('search', rawurlencode($name), kop_facility_pages_page_url_by_template('page-wiki-feed.php', '/wiki-feed/')),
            'submit_url'    => add_query_arg('find', rawurlencode($name), home_url('/tti-data-submission/')),
            'seo_title'     => $seo_title,
            'seo_description' => $seo_description,
            'profile_post'  => $profile ? $profile_post : 0,
            'doc'           => $doc,
        );
    }
}

if (!function_exists('kop_facility_pages_research')) {
    /**
     * Research & Reports documents tagged with this facility, newest first.
     * An editor sets the tag on the card at /researchreports/, which stores one
     * kop_research_facilities meta row per facility (inc/research-library.php),
     * so this is a plain meta query.
     */
    function kop_facility_pages_research($facility_id) {
        $facility_id = (int) $facility_id;
        if ($facility_id <= 0 || !defined('KOP_RESEARCH_FACILITY_META')) {
            return array();
        }
        return kop_facility_pages_research_tagged(KOP_RESEARCH_FACILITY_META, array($facility_id), $facility_id);
    }
}

if (!function_exists('kop_facility_pages_research_tagged')) {
    /**
     * Research documents carrying any of $ids under the tag meta $meta_key
     * (a facility's, or a parent company's: kop_operator_pages_research).
     * $cite_id picks the "named on p. N" entry from the seeded page cites,
     * which exist for facilities only; 0 skips them.
     */
    function kop_facility_pages_research_tagged($meta_key, array $ids, $cite_id = 0) {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return array();
        }
        $facility_id = (int) $cite_id;

        $attachments = get_posts(array(
            'post_type'        => 'attachment',
            'post_status'      => 'inherit',
            'posts_per_page'   => 20,
            'orderby'          => 'date',
            'order'            => 'DESC',
            'suppress_filters' => false,
            'meta_query'       => array(
                array(
                    'key'     => $meta_key,
                    'value'   => $ids,
                    'compare' => 'IN',
                ),
            ),
        ));

        $library_url = kop_facility_pages_research_library_url();
        $out = array();
        foreach ((array) $attachments as $attachment) {
            $out[] = kop_facility_pages_research_item($attachment, $facility_id, $library_url);
        }
        return $out;
    }
}

if (!function_exists('kop_facility_pages_research_library_url')) {
    /** The Research & Reports hub, where every tagged document is listed. */
    function kop_facility_pages_research_library_url() {
        $library_url = kop_facility_pages_page_url_by_template('page-hub.php', '/researchreports/');
        if (defined('KOP_RESEARCH_SLUG')) {
            $page = get_page_by_path(KOP_RESEARCH_SLUG);
            if ($page) $library_url = (string) get_permalink($page);
        }
        return $library_url;
    }
}

if (!function_exists('kop_facility_pages_research_item')) {
    /**
     * One tagged research document as {title, url, byline, why, pages,
     * library}. Shared by the facility and operator pages and the program
     * index feed (kop_attach_research_to_projects). $facility_id picks the
     * seeded page cite; 0 skips it.
     */
    function kop_facility_pages_research_item($attachment, $facility_id, $library_url) {
        $facility_id = (int) $facility_id;
        $url = (string) wp_get_attachment_url($attachment->ID);
        $why = trim((string) get_post_meta($attachment->ID, 'kop_research_relevance_note', true));
        // Where in the document this facility is named, from
        // seeds/research-facility-tags.json: {id: {pages, pdf_page}}.
        $cites = get_post_meta($attachment->ID, 'kop_research_facility_pages', true);
        $cite = is_array($cites) && isset($cites[$facility_id]) && is_array($cites[$facility_id]) ? $cites[$facility_id] : array();
        $pages = trim((string) ($cite['pages'] ?? ''));
        if ($url !== '' && !empty($cite['pdf_page'])) $url .= '#page=' . (int) $cite['pdf_page'];
        // The research library's own title and byline for the card, unless
        // an editor has since edited it (inc/research-library.php).
        $title = (string) $attachment->post_title;
        $byline = trim(preg_replace('/^by\s+/i', '', (string) $attachment->post_excerpt));
        if (function_exists('kop_research_library_overrides')) {
            $overrides = kop_research_library_overrides();
            $base = strtolower(pathinfo(basename((string) get_post_meta($attachment->ID, '_wp_attached_file', true)), PATHINFO_FILENAME));
            $override = $overrides['id:' . $attachment->ID] ?? ($overrides[$base] ?? array());
            $edited = defined('KOP_RESEARCH_EDITED_META') && (string) get_post_meta($attachment->ID, KOP_RESEARCH_EDITED_META, true) !== '';
            if (!$edited && isset($override['title'])) $title = (string) $override['title'];
            if (isset($override['byline'])) $byline = (string) $override['byline'];
        }
        return array(
            'title'   => $title,
            'url'     => $url !== '' ? $url : $library_url,
            'byline'  => $byline,
            'why'     => $why,
            'pages'   => $pages === '' ? '' : (preg_match('/[,\-]/', $pages) ? 'pp. ' : 'p. ') . $pages,
            'library' => $library_url,
        );
    }
}

if (!function_exists('kop_facility_pages_news')) {
    /** Published articles linked to the facility by id, newest first. */
    function kop_facility_pages_news($facility_id) {
        global $wpdb;
        if (!kop_facility_pages_table_exists('news_facility_links') || !kop_facility_pages_table_exists('news_submissions')) return array();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT n.id, n.article_title, n.alternate_title, n.publication_name, n.publication_date, n.article_url, n.article_type, n.summary, n.tags, l.link_type
               FROM news_facility_links l
               JOIN news_submissions n ON n.id = l.news_id
              WHERE l.facility_id = %d AND n.status IN ('approved','published')
              ORDER BY n.publication_date DESC, n.id DESC",
            (int) $facility_id
        ), ARRAY_A);
        require_once get_stylesheet_directory() . '/api/news-tags.php';
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
                // Celebrity or Viral tag: listed apart, collapsed (kop_news_is_aside()).
                'aside'     => kop_news_is_aside((string) $r['tags']),
                'image'     => function_exists('kop_news_image') ? kop_news_image((int) $r['id'], (string) $r['article_url']) : null,
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
                'date'       => $date,
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
     * record has none), plus the rows an admin linked to the record at KOP
     * Tools > Inspection Links (inc/inspection-links.php).
     */
    function kop_facility_pages_inspections(array $name_keys, $state_code, $state_name, $facility_id = 0) {
        global $wpdb;
        $linked_ids = ($facility_id && function_exists('kop_inspection_links_for')) ? kop_inspection_links_for($facility_id) : array();
        if (!kop_facility_pages_table_exists('inspection_facilities') || (!$name_keys && !$linked_ids)) return null;
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
        if ($linked_ids) {
            $placeholders = implode(',', array_fill(0, count($linked_ids), '%d'));
            $linked = $wpdb->get_results($wpdb->prepare(
                "SELECT id, state, facility_name, full_address, phone, program_category, program_name, executive_director, bed_capacity, license_exp_date, relicense_visit_date, action FROM inspection_facilities WHERE id IN ($placeholders)",
                $linked_ids
            ), ARRAY_A);
            foreach ((array) $linked as $r) $matched[(int) $r['id']] = $r;
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
            $reports = kop_facility_pages_report_findings($reports, $matched);
        }

        return array(
            'summary'    => $summary,
            'reports'    => $reports,
            'total'      => $total,
            'violations' => kop_facility_pages_violations(array_keys($matched)),
            'page_urls'  => $page_urls,
            'page_url'   => $page_urls ? array_key_first($page_urls) : '',
        );
    }
}

if (!function_exists('kop_facility_pages_report_findings')) {
    /**
     * What each listed report found, in the state's own words: the
     * deficiencies, citations, allegations and non-compliances that the
     * serious-findings scanner reads (kop_ih_extract() in
     * inc/inspection-highlights.php, for the states it supports). Every
     * finding is listed, not only the serious ones an admin approved; those
     * still lead the page under "Serious findings". Adds 'findings' [{text,
     * standard, label}] to each report; reports from other states get none
     * and keep their "Open report" link.
     */
    function kop_facility_pages_report_findings(array $reports, array $matched) {
        global $wpdb;
        if (!$reports || !function_exists('kop_ih_extract') || !function_exists('kop_ih_supported_states')) return $reports;
        $ids = array_map(function ($r) { return (int) $r['id']; }, $reports);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, facility_id, report_id, report_date, categories_json, raw_content FROM inspection_reports WHERE id IN ($placeholders)",
            $ids
        ), ARRAY_A);
        $wpdb->suppress_errors($suppress);
        $by_id = array();
        foreach ((array) $rows as $row) $by_id[(int) $row['id']] = $row;
        $supported = kop_ih_supported_states();
        foreach ($reports as &$report) {
            $report['findings'] = array();
            $row = $by_id[$report['id']] ?? null;
            if (!$row) continue;
            $state = strtoupper(trim((string) ($matched[(int) $row['facility_id']]['state'] ?? '')));
            if (!in_array($state, $supported, true)) continue;
            $seen = array();
            foreach ((array) kop_ih_extract($state, $row) as $f) {
                $text = trim(strip_tags(preg_replace('#<br\s*/?>#i', "\n", (string) ($f['text'] ?? ''))));
                if ($text === '') continue;
                $key = md5(mb_strtolower(preg_replace('/\s+/u', ' ', $text)));
                if (isset($seen[$key])) continue; // scrapers sometimes store one finding twice
                $seen[$key] = true;
                if (mb_strlen($text) > 1500) {
                    $cut = mb_substr($text, 0, 1500);
                    $space = mb_strrpos($cut, ' ');
                    $text = rtrim($space > 1200 ? mb_substr($cut, 0, $space) : $cut, ' ,;:') . ' ...';
                }
                $report['findings'][] = array(
                    'text'     => $text,
                    'standard' => trim((string) ($f['standard'] ?? '')),
                    'label'    => trim((string) ($f['state_label'] ?? '')),
                );
            }
        }
        unset($report);
        return $reports;
    }
}

if (!function_exists('kop_facility_pages_violations')) {
    /**
     * The serious findings state inspectors confirmed at this facility's
     * licensed rows: the inspection_highlights an admin approved
     * (inc/inspection-highlights.php), the worst kinds of harm first and the
     * newest first within them. Pending and rejected rows never show. The
     * scrapers sometimes store one report twice, so a finding text is shown once.
     */
    function kop_facility_pages_violations(array $inspection_ids) {
        global $wpdb;
        $inspection_ids = array_values(array_filter(array_map('intval', $inspection_ids)));
        if (!$inspection_ids || !kop_facility_pages_table_exists('inspection_highlights') || !function_exists('kop_ih_categories')) return array();
        $placeholders = implode(',', array_fill(0, count($inspection_ids), '%d'));
        $suppress = $wpdb->suppress_errors(true);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT h.id, h.state, h.category, h.categories, h.score, h.finding_date, h.excerpt, h.state_label, h.text_hash,
                    r.report_date, r.report_url, r.report_id AS source_report_id
               FROM inspection_highlights h
               JOIN inspection_reports r ON r.id = h.report_id
              WHERE h.facility_id IN ($placeholders) AND h.status = 'approved' AND h.score >= " . (int) kop_ih_min_score(),
            $inspection_ids
        ), ARRAY_A);
        $wpdb->suppress_errors($suppress);
        $categories = kop_ih_categories();
        $severe = kop_ih_severe_score();
        // Plain-language summaries a reviewer approved for the hard-to-read ones (inc/highlight-summaries.php).
        $plain = function_exists('kop_hs_public_summaries') ? kop_hs_public_summaries((array) $rows) : array();
        $out = array();
        foreach ((array) $rows as $r) {
            $hash = (string) $r['text_hash'];
            if ($hash !== '' && isset($out[$hash])) continue;
            $kinds = array();
            foreach (array_unique(array_filter(array_map('trim', explode(',', (string) $r['categories'])))) as $kind) {
                if (isset($categories[$kind])) $kinds[] = $categories[$kind]['label'];
            }
            $date = (string) $r['finding_date'];
            // Some states' text carries their markup ("<br/>"); a line break is all it means.
            $excerpt = trim(strip_tags(preg_replace('#<br\s*/?>#i', "\n", (string) $r['excerpt'])));
            $out[$hash !== '' ? $hash : 'id' . $r['id']] = array(
                'id'         => (int) $r['id'],
                'state'      => strtoupper((string) $r['state']),
                'category'   => (string) $r['category'],
                'label'      => $categories[$r['category']]['label'] ?? 'Serious finding',
                'kinds'      => $kinds,
                'weight'     => (int) ($categories[$r['category']]['weight'] ?? 0),
                'severe'     => (int) $r['score'] >= $severe,
                'date'       => $date,
                'date_label' => $date !== '' ? kop_facility_pages_date_label($date) : trim((string) $r['report_date']),
                'excerpt'    => $excerpt,
                'plain'      => (string) ($plain[(int) $r['id']] ?? ''),
                'short'      => kop_ih_card_excerpt($excerpt, 360),
                'state_label' => trim((string) $r['state_label']),
                'source_url' => kop_ih_source_url($r),
                'full_url'   => function_exists('kop_ih_severe_page_url') ? kop_ih_severe_page_url((int) $r['id']) : '',
            );
        }
        $out = array_values($out);
        usort($out, static function ($a, $b) {
            return ($b['severe'] <=> $a['severe']) ?: ($b['weight'] <=> $a['weight']) ?: strcmp($b['date'], $a['date']) ?: ($b['id'] <=> $a['id']);
        });
        return $out;
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
        // The generated parent company pages ride in the same sitemap.
        if (function_exists('kop_operator_pages_index')) {
            $ops = kop_operator_pages_index();
            foreach ($ops['ids'] as $e) {
                $out[] = array('loc' => kop_operator_pages_url_for_slug($e['slug']), 'mod' => gmdate('Y-m-d H:i:s', (int) $index['built']));
            }
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

if (!function_exists('kop_facility_pages_network')) {
    /**
     * The facility's connections on the network map, for its page:
     * array('map_url', 'groups' => array(array('label', 'items' => array(
     * array('name', 'url', 'role')))) ), or null when the map does not draw it.
     * Grouped as the map's drawer groups them, largest group first; a
     * facility that has its own page links to it, and a rename or an
     * acquisition says which way it went.
     */
    function kop_facility_pages_network($facility_id) {
        if (!function_exists('kop_network_map_facility_connections')) return null;
        $all = kop_network_map_facility_connections();
        $entry = $all[(int) $facility_id] ?? null;
        if (!$entry || empty($entry['links'])) return null;

        $groups = array();
        foreach ($entry['links'] as $link) {
            // People are on the page as staff cards (kop_facility_pages_add_map_people()).
            if (($link['kind'] ?? '') === 'person' && ($link['relation'] ?? '') === '') continue;
            if ($link['relation'] === 'became' || $link['relation'] === 'formerly') {
                $label = 'Names';
            } elseif ($link['relation'] !== '') {
                $label = 'Acquisitions';
            } else {
                $label = function_exists('kop_network_map_label') ? kop_network_map_label($link['category'], 'category') : ucfirst($link['category']);
            }
            $role = $link['role'];
            if ($link['relation'] === 'became') $role = 'Later known as';
            elseif ($link['relation'] === 'formerly') $role = 'Earlier name';
            elseif ($link['relation'] === 'acquired') $role = 'Acquired by this program' . ($role !== '' ? ' (' . $role . ')' : '');
            elseif ($link['relation'] === 'acquired by') $role = 'Acquired this program' . ($role !== '' ? ' (' . $role . ')' : '');
            $url = !empty($link['facilityId']) && (int) $link['facilityId'] !== (int) $facility_id
                ? kop_facility_page_url((int) $link['facilityId'])
                : '';
            $key = $link['node'];
            if (isset($groups[$label]['items'][$key])) {
                $seen = &$groups[$label]['items'][$key];
                if ($role !== '' && stripos($seen['role'], $role) === false) {
                    $seen['role'] = $seen['role'] === '' ? $role : $seen['role'] . '; ' . $role;
                }
                unset($seen);
                continue;
            }
            $groups[$label]['label'] = $label;
            $groups[$label]['items'][$key] = array('name' => $link['name'], 'url' => $url, 'role' => $role);
        }
        foreach ($groups as &$group) {
            $group['items'] = array_values($group['items']);
            usort($group['items'], static function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        }
        unset($group);
        $groups = array_values($groups);
        usort($groups, static function ($a, $b) {
            return count($b['items']) - count($a['items']) ?: strcasecmp($a['label'], $b['label']);
        });
        $map = kop_facility_pages_page_url_by_template('page-network-map.php', '/network-map/');
        return array(
            'map_url' => $map . '#open=' . rawurlencode($entry['node']),
            'groups'  => $groups,
        );
    }
}
