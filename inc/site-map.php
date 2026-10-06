<?php
/**
 * /site-map/: every public page of the site on one page, for readers.
 *
 * The XML sitemaps (Yoast's, plus facility-sitemap.xml from
 * inc/facility-pages.php) are for search engines. This page is the reader's
 * copy: the sections (hub pages) with the articles read under each
 * (kop_article_parents()), the directories and tools, the state and country
 * pages, the inspection report pages, the posts by category, and every
 * generated /facility/ and /operator/ page A to Z. A filter box at the top
 * narrows all of it as you type (js/site-map.js) and hands the words to the
 * site search when nothing here matches.
 *
 *   routing      /site-map/ is a route, not a WordPress page (like /operator/),
 *                so it exists without anyone creating it; /sitemap/ 301s here
 *   sections     a page is placed by its template (kop_site_map_template_sections()),
 *                a slug can be placed by hand (kop_site_map_slug_sections());
 *                admin tools, private, draft and password pages are never listed
 *   cache        kop_site_map_data() is one transient, rebuilt when a post or page
 *                changes or the facility / operator indexes are rebuilt
 *   quick links  kop_site_map_quick_links(): the most used destinations, shown
 *                at the top of this page, in the search popup before anything
 *                is typed (js/global-search.js) and on the 404 page (404.php)
 *
 * php scripts/test-site-map.php renders the page offline.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_SITE_MAP_REWRITE_VERSION')) {
    define('KOP_SITE_MAP_REWRITE_VERSION', '1');
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

if (!function_exists('kop_site_map_base')) {
    function kop_site_map_base() {
        return apply_filters('kop_site_map_base', 'site-map');
    }
}

if (!function_exists('kop_site_map_url')) {
    function kop_site_map_url() {
        return home_url('/' . kop_site_map_base() . '/');
    }
}

if (!function_exists('kop_site_map_register_rewrite')) {
    function kop_site_map_register_rewrite() {
        add_rewrite_rule('^' . preg_quote(kop_site_map_base(), '#') . '/?$', 'index.php?kop_site_map=1', 'top');
        if (get_option('kop_site_map_rewrite') !== KOP_SITE_MAP_REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option('kop_site_map_rewrite', KOP_SITE_MAP_REWRITE_VERSION);
        }
    }
    add_action('init', 'kop_site_map_register_rewrite', 30);
}

if (!function_exists('kop_site_map_query_vars')) {
    function kop_site_map_query_vars($vars) {
        $vars[] = 'kop_site_map';
        return $vars;
    }
    add_filter('query_vars', 'kop_site_map_query_vars');
}

if (!function_exists('kop_site_map_is_request')) {
    function kop_site_map_is_request($query = null) {
        if ($query === null) {
            return function_exists('get_query_var') && (string) get_query_var('kop_site_map') === '1';
        }
        return (string) $query->get('kop_site_map') === '1';
    }
}

if (!function_exists('kop_site_map_is_page')) {
    /** True while /site-map/ is being rendered. */
    function kop_site_map_is_page() {
        return !empty($GLOBALS['kop_site_map']) && is_array($GLOBALS['kop_site_map']);
    }
}

if (!function_exists('kop_site_map_pre_get_posts')) {
    /** No post query var, so WordPress would load the blog home; skip it. */
    function kop_site_map_pre_get_posts($query) {
        if (is_admin() || !$query->is_main_query() || !kop_site_map_is_request($query)) return;
        $query->is_home = false;
        $query->is_posts_page = false;
        $query->set('posts_per_page', 1);
        $query->set('no_found_rows', true);
    }
    add_action('pre_get_posts', 'kop_site_map_pre_get_posts');
}

if (!function_exists('kop_site_map_posts_pre_query')) {
    function kop_site_map_posts_pre_query($posts, $query) {
        if (is_admin() || !$query->is_main_query() || !kop_site_map_is_request($query)) return $posts;
        return array();
    }
    add_filter('posts_pre_query', 'kop_site_map_posts_pre_query', 10, 2);
}

if (!function_exists('kop_site_map_pre_handle_404')) {
    function kop_site_map_pre_handle_404($preempt, $query) {
        return kop_site_map_is_request($query) ? true : $preempt;
    }
    add_filter('pre_handle_404', 'kop_site_map_pre_handle_404', 10, 2);
}

if (!function_exists('kop_site_map_route')) {
    function kop_site_map_route() {
        if (!kop_site_map_is_request()) return;
        $GLOBALS['kop_site_map'] = kop_site_map_data();
        status_header(200);
    }
    add_action('template_redirect', 'kop_site_map_route', 0);
}

if (!function_exists('kop_site_map_redirect_aliases')) {
    /** The addresses people type for a site map, sent to this one. */
    function kop_site_map_redirect_aliases($map) {
        foreach (array('sitemap', 'site-index') as $alias) {
            if (!isset($map[$alias])) $map[$alias] = '/' . kop_site_map_base() . '/';
        }
        return $map;
    }
    add_filter('kop_redirect_map', 'kop_site_map_redirect_aliases');
}

if (!function_exists('kop_site_map_template_include')) {
    function kop_site_map_template_include($template) {
        if (!kop_site_map_is_page()) return $template;
        $own = get_stylesheet_directory() . '/templates/site-map.php';
        return file_exists($own) ? $own : $template;
    }
    add_filter('template_include', 'kop_site_map_template_include', 99);
}

if (!function_exists('kop_site_map_body_class')) {
    function kop_site_map_body_class($classes) {
        if (kop_site_map_is_page()) $classes[] = 'kop-site-map-page';
        return $classes;
    }
    add_filter('body_class', 'kop_site_map_body_class');
}

if (!function_exists('kop_site_map_enqueue')) {
    function kop_site_map_enqueue() {
        $dir = get_stylesheet_directory();
        $uri = get_stylesheet_directory_uri();
        // The 404 page reuses the quick-link tiles.
        if (!kop_site_map_is_page() && !is_404()) return;
        $css = $dir . '/css/site-map.css';
        if (file_exists($css)) {
            wp_enqueue_style('kop-site-map', $uri . '/css/site-map.css', array('kop-colors'), filemtime($css));
        }
        if (!kop_site_map_is_page()) return;
        $js = $dir . '/js/site-map.js';
        if (file_exists($js)) {
            wp_enqueue_script('kop-site-map', $uri . '/js/site-map.js', array(), filemtime($js), true);
        }
    }
    add_action('wp_enqueue_scripts', 'kop_site_map_enqueue', 20);
}

if (!function_exists('kop_site_map_document_title')) {
    function kop_site_map_document_title($title) {
        return kop_site_map_is_page() ? 'Site map | ' . get_bloginfo('name') : $title;
    }
    add_filter('pre_get_document_title', 'kop_site_map_document_title', 20);
    add_filter('wpseo_title', 'kop_site_map_document_title', 20);
    add_filter('wpseo_opengraph_title', 'kop_site_map_document_title', 20);
}

if (!function_exists('kop_site_map_meta_description')) {
    function kop_site_map_meta_description($desc) {
        return kop_site_map_is_page() ? kop_site_map_summary() : $desc;
    }
    add_filter('wpseo_metadesc', 'kop_site_map_meta_description', 20);
    add_filter('wpseo_opengraph_desc', 'kop_site_map_meta_description', 20);
}

if (!function_exists('kop_site_map_canonical')) {
    function kop_site_map_canonical($url) {
        return kop_site_map_is_page() ? kop_site_map_url() : $url;
    }
    add_filter('wpseo_canonical', 'kop_site_map_canonical', 20);
    add_filter('wpseo_opengraph_url', 'kop_site_map_canonical', 20);
}

if (!function_exists('kop_site_map_robots')) {
    function kop_site_map_robots($robots) {
        return kop_site_map_is_page() ? 'index, follow' : $robots;
    }
    add_filter('wpseo_robots', 'kop_site_map_robots', 20);
}

if (!function_exists('kop_site_map_summary')) {
    function kop_site_map_summary() {
        return 'Every public page of Kids Over Profits in one place: the sections and their articles, the directories and tools, state and country pages, inspection reports, posts, and every facility and parent company page from A to Z.';
    }
}

// ---------------------------------------------------------------------------
// Where each page goes
// ---------------------------------------------------------------------------

if (!function_exists('kop_site_map_section_titles')) {
    /** Section key => array(title, one line saying what is in it), in page order. */
    function kop_site_map_section_titles() {
        return apply_filters('kop_site_map_section_titles', array(
            'hubs'        => array('Sections', 'Each section gathers the pages, records and articles on one subject. Articles are listed under the section they are read in.'),
            'records'     => array('Directories and tools', 'Look up programs, the companies behind them, the people who run them and how they connect.'),
            'inspections' => array('Inspection reports', 'What state licensing agencies found when they inspected programs, state by state.'),
            'places'      => array('States and countries', 'Everything on file for one place: its programs, laws, reports and news.'),
            'sources'     => array('News, court records and documents', 'The primary sources: news coverage, lawsuits, bills and the document archive.'),
            'reference'   => array('Guides and reference', 'Where to report abuse, what the industry\'s words mean, and the data behind the site.'),
            'reading'     => array('More articles', ''),
            'profiles'    => array('Written program profiles', 'Programs with a profile written by our researchers.'),
            'posts'       => array('Posts by category', ''),
            'outside'     => array('Outside the troubled teen industry', 'Not troubled teen industry programs: institutional abuse of Indigenous children in government and church schools. Each page says where its history meets the troubled teen industry\'s and where it does not.'),
            'take-part'   => array('Take part', 'Send us what you know, volunteer or support the work.'),
            'about'       => array('About and legal', ''),
            'other'       => array('More pages', ''),
            'facilities'  => array('Facility pages, A to Z', 'Every program with its own page. Each lists its names, owners, staff, inspection findings, lawsuits, news and documents.'),
            'operators'   => array('Parent company pages, A to Z', 'Every company with its own page: the programs it has run, its history and the documents filed under it.'),
        ));
    }
}

if (!function_exists('kop_site_map_template_sections')) {
    /**
     * Page template file => section key, or 'hide'. A page with a template
     * not listed here goes to 'other'; one with an admin template (any
     * page-admin-*.php) is always hidden.
     */
    function kop_site_map_template_sections() {
        return apply_filters('kop_site_map_template_sections', array(
            'page-home.php'                    => 'hide',
            'page-hub.php'                     => 'hubs',
            'page-article.php'                 => 'reading',
            'page-tti-program-index.php'       => 'records',
            'page-location-index.php'          => 'records',
            'page-network-map.php'             => 'records',
            'page-referrer-index.php'          => 'records',
            'page-transporter-index.php'       => 'records',
            'page-provider-index.php'          => 'records',
            // Programs for adults 18+ are part of the industry (often the next
            // step after a teen program), so they sit with the directories.
            'page-young-adult-programs.php'    => 'records',
            // Not the TTI: its own labelled section.
            'page-indian-boarding-schools.php' => 'outside',
            'page-memorial.php'                => 'records',
            'page-inspection-reports.php'      => 'inspections',
            'page-severe-reports.php'          => 'inspections',
            'page-state-reports.php'           => 'inspections',
            'page-state.php'                   => 'places',
            'page-country.php'                 => 'places',
            'page-news-feed.php'               => 'sources',
            'page-wiki-feed.php'               => 'sources',
            'page-lawsuits.php'                => 'sources',
            'page-legislation.php'             => 'sources',
            'page-document-archive.php'        => 'sources',
            'page-document-folder.php'         => 'sources',
            'page-legal-document.php'          => 'sources',
            'page-report-abuse.php'            => 'reference',
            'page-glossary.php'                => 'reference',
            'page-faq.php'                     => 'reference',
            'page-open-data.php'               => 'reference',
            'single-facility-profile.php'      => 'profiles',
            'single-person-profile.php'        => 'profiles',
            'page-data.php'                    => 'take-part',
            'page-submit-lawsuit.php'          => 'take-part',
            'page-submit-legislation.php'      => 'take-part',
            'page-volunteers.php'              => 'take-part',
            'page-utility.php'                 => 'take-part',
            'page-privacy-policy.php'          => 'about',
            'page-terms-of-service.php'        => 'about',
            // Working tools, not reading.
            'page-news-processor.php'          => 'hide',
            'page-wiki-editor.php'             => 'hide',
        ));
    }
}

if (!function_exists('kop_site_map_slug_sections')) {
    /** Page slug => section key or 'hide', ahead of the template. */
    function kop_site_map_slug_sections() {
        return apply_filters('kop_site_map_slug_sections', array(
            'no-access'  => 'hide',
            'links'      => 'reference',
            'contact'    => 'about',
            'about'      => 'about',
            'about-us'   => 'about',
            'our-team'   => 'about',
            'mission'    => 'about',
        ));
    }
}

if (!function_exists('kop_site_map_section_for')) {
    /** The section key a page goes in, or 'hide'. */
    function kop_site_map_section_for($slug, $template) {
        $slug = (string) $slug;
        $template = basename((string) $template);
        $by_slug = kop_site_map_slug_sections();
        if (isset($by_slug[$slug])) return $by_slug[$slug];
        // Retired pages kept published behind a redirect.
        if (function_exists('kop_redirect_map')) {
            $redirects = kop_redirect_map();
            if (isset($redirects[$slug])) return 'hide';
        }
        if (strpos($template, 'page-admin-') === 0) return 'hide';
        $by_template = kop_site_map_template_sections();
        if ($template !== '' && $template !== 'default' && isset($by_template[$template])) return $by_template[$template];
        return 'other';
    }
}

if (!function_exists('kop_site_map_hub_order')) {
    /** Hub slugs in the order kop_template_assignments() lists them. */
    function kop_site_map_hub_order() {
        $order = array();
        if (function_exists('kop_template_assignments')) {
            foreach (kop_template_assignments() as $slug => $spec) {
                $template = is_array($spec) ? ($spec['template'] ?? '') : $spec;
                if ($template === 'page-hub.php') $order[] = $slug;
            }
        }
        return $order;
    }
}

// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------

if (!function_exists('kop_site_map_cache_key')) {
    /** Changes whenever a published post or page, or a generated page index, does. */
    function kop_site_map_cache_key() {
        global $wpdb;
        $posts = $wpdb->get_row(
            "SELECT COUNT(*) AS n, MAX(post_modified_gmt) AS m FROM {$wpdb->posts}
              WHERE post_type IN ('page', 'post') AND post_status = 'publish'",
            ARRAY_A
        );
        $fac = function_exists('kop_facility_pages_index') ? kop_facility_pages_index() : array();
        $ops = function_exists('kop_operator_pages_index') ? kop_operator_pages_index() : array();
        return md5(json_encode(array(
            $posts,
            $fac['fingerprint'] ?? '',
            $ops['fingerprint'] ?? '',
            KOP_SITE_MAP_REWRITE_VERSION,
        )));
    }
}

if (!function_exists('kop_site_map_data')) {
    function kop_site_map_data($force = false) {
        $key = kop_site_map_cache_key();
        if (!$force) {
            $cached = get_transient('kop_site_map_data');
            if (is_array($cached) && ($cached['key'] ?? '') === $key) return $cached;
        }
        $data = kop_site_map_build();
        $data['key'] = $key;
        set_transient('kop_site_map_data', $data, DAY_IN_SECONDS);
        return $data;
    }
}

if (!function_exists('kop_site_map_flush')) {
    function kop_site_map_flush() {
        delete_transient('kop_site_map_data');
        delete_transient('kop_site_map_quick_links');
    }
    add_action('save_post_page', 'kop_site_map_flush');
    add_action('save_post_post', 'kop_site_map_flush');
    add_action('trashed_post', 'kop_site_map_flush');
    add_action('deleted_post', 'kop_site_map_flush');
}

if (!function_exists('kop_site_map_item')) {
    function kop_site_map_item($title, $url, $note = '') {
        return array(
            'title'    => html_entity_decode(wp_strip_all_tags((string) $title), ENT_QUOTES, 'UTF-8'),
            'url'      => (string) $url,
            'note'     => (string) $note,
            'children' => array(),
        );
    }
}

if (!function_exists('kop_site_map_public_pages')) {
    /** Published, unprotected pages: slug => array(id, title, url, template, order). */
    function kop_site_map_public_pages() {
        $front = (int) get_option('page_on_front');
        $out = array();
        $pages = get_posts(array(
            'post_type'        => 'page',
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'orderby'          => 'title',
            'order'            => 'ASC',
            'has_password'     => false,
            'suppress_filters' => false,
        ));
        foreach ((array) $pages as $p) {
            if ((int) $p->ID === $front || $p->post_password !== '') continue;
            $out[$p->post_name] = array(
                'id'       => (int) $p->ID,
                'title'    => get_the_title($p),
                'url'      => get_permalink($p),
                'template' => (string) get_page_template_slug($p),
                'order'    => (int) $p->menu_order,
            );
        }
        return $out;
    }
}

if (!function_exists('kop_site_map_build')) {
    /**
     * array('sections' => array(key => array(title, intro, items, letters)),
     * 'quick' => quick links, 'counts' => array(pages, facilities, operators)).
     * An item is array(title, url, note, children); 'letters' (the A to Z
     * sections) is letter => items.
     */
    function kop_site_map_build() {
        $titles = kop_site_map_section_titles();
        $sections = array();
        foreach ($titles as $k => $t) {
            $sections[$k] = array('title' => $t[0], 'intro' => $t[1], 'items' => array(), 'letters' => array());
        }

        $pages = kop_site_map_public_pages();
        $merged = function_exists('kop_facility_pages_merged_profile_posts') ? array_flip(array_map('intval', kop_facility_pages_merged_profile_posts())) : array();
        $parents = function_exists('kop_article_parents') ? kop_article_parents() : array();
        $placed = array();

        // Sections, each with its articles under it in reading order.
        $hubs = array();
        foreach ($pages as $slug => $p) {
            if (kop_site_map_section_for($slug, $p['template']) === 'hubs') $hubs[$slug] = $p;
        }
        $order = array_flip(kop_site_map_hub_order());
        uksort($hubs, static function ($a, $b) use ($order, $hubs) {
            $oa = $order[$a] ?? PHP_INT_MAX;
            $ob = $order[$b] ?? PHP_INT_MAX;
            return $oa <=> $ob ?: strcasecmp($hubs[$a]['title'], $hubs[$b]['title']);
        });
        $children_of = array();
        foreach ($parents as $child => $parent) $children_of[$parent][] = $child;
        $walk = function ($slug, $depth) use (&$walk, &$children_of, &$pages, &$placed) {
            $out = array();
            if ($depth > 4) return $out;
            foreach ($children_of[$slug] ?? array() as $child) {
                if (!isset($pages[$child]) || isset($placed[$child])) continue;
                $placed[$child] = true;
                $item = kop_site_map_item($pages[$child]['title'], $pages[$child]['url']);
                $item['children'] = $walk($child, $depth + 1);
                $out[] = $item;
            }
            return $out;
        };
        foreach ($hubs as $slug => $p) {
            $placed[$slug] = true;
        }
        foreach ($hubs as $slug => $p) {
            $item = kop_site_map_item($p['title'], $p['url']);
            $item['children'] = $walk($slug, 1);
            $sections['hubs']['items'][] = $item;
        }

        // Generated pages that are not WordPress pages.
        if (function_exists('kop_operator_pages_base')) {
            $sections['records']['items'][] = kop_site_map_item('Parent companies', home_url('/' . kop_operator_pages_base() . '/'), 'Who owns and runs the programs');
        }

        foreach ($pages as $slug => $p) {
            if (isset($placed[$slug])) continue;
            $section = kop_site_map_section_for($slug, $p['template']);
            if ($section === 'hide' || $section === 'hubs' || !isset($sections[$section])) continue;
            if ($section === 'profiles' && isset($merged[$p['id']])) continue;
            $sections[$section]['items'][] = kop_site_map_item($p['title'], $p['url']);
        }
        foreach (array('records', 'inspections', 'places', 'sources', 'reference', 'reading', 'profiles', 'outside', 'take-part', 'about', 'other') as $k) {
            usort($sections[$k]['items'], static function ($a, $b) {
                return strnatcasecmp(kop_site_map_sort_name($a['title']), kop_site_map_sort_name($b['title']));
            });
        }

        // Posts, under each category.
        $categories = function_exists('get_categories') ? get_categories(array('hide_empty' => true, 'orderby' => 'name')) : array();
        foreach ((array) $categories as $cat) {
            $item = kop_site_map_item($cat->name, get_category_link($cat->term_id), $cat->count . ' ' . ($cat->count === 1 ? 'post' : 'posts'));
            $posts = get_posts(array(
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'cat'            => (int) $cat->term_id,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'has_password'   => false,
                'no_found_rows'  => true,
            ));
            foreach ((array) $posts as $post) {
                $item['children'][] = kop_site_map_item(get_the_title($post), get_permalink($post), get_the_date('Y', $post));
            }
            $sections['posts']['items'][] = $item;
        }

        // Every generated facility and company page, A to Z.
        $facility_count = 0;
        if (function_exists('kop_facility_pages_index')) {
            $index = kop_facility_pages_index();
            $list = array();
            foreach ((array) ($index['ids'] ?? array()) as $e) {
                $list[] = kop_site_map_item($e['name'], kop_facility_pages_url_for_slug($e['slug']), kop_site_map_slug_place($e['name'], $e['slug']));
            }
            $facility_count = count($list);
            $sections['facilities']['letters'] = kop_site_map_by_letter($list);
        }
        $operator_count = 0;
        if (function_exists('kop_operator_pages_index')) {
            $ops = kop_operator_pages_index();
            $list = array();
            foreach ((array) ($ops['ids'] ?? array()) as $e) {
                $list[] = kop_site_map_item($e['display'], kop_operator_pages_url_for_slug($e['slug']));
            }
            $operator_count = count($list);
            $sections['operators']['letters'] = kop_site_map_by_letter($list);
        }

        foreach ($sections as $k => $s) {
            if (!$s['items'] && !$s['letters']) unset($sections[$k]);
        }

        return array(
            'built'    => time(),
            'sections' => $sections,
            'quick'    => kop_site_map_build_quick_links($pages),
            'counts'   => array('pages' => count($pages), 'facilities' => $facility_count, 'operators' => $operator_count),
        );
    }
}

if (!function_exists('kop_site_map_sort_name')) {
    /** "The Ridge" sorts under R. */
    function kop_site_map_sort_name($name) {
        $name = trim((string) $name);
        $short = preg_replace('/^(the|a|an)\s+/i', '', $name);
        return $short !== '' ? $short : $name;
    }
}

if (!function_exists('kop_site_map_letter')) {
    /** The A to Z heading a name goes under; '#' for a digit or anything else. */
    function kop_site_map_letter($name) {
        $name = kop_site_map_sort_name($name);
        if (function_exists('remove_accents')) $name = remove_accents($name);
        $c = strtoupper(substr(ltrim($name, " \t\"'“‘(["), 0, 1));
        return ($c >= 'A' && $c <= 'Z') ? $c : '#';
    }
}

if (!function_exists('kop_site_map_by_letter')) {
    /** Items grouped letter => items, A to Z with '#' last, each sorted by name. */
    function kop_site_map_by_letter(array $items) {
        usort($items, static function ($a, $b) {
            return strnatcasecmp(kop_site_map_sort_name($a['title']), kop_site_map_sort_name($b['title']))
                ?: strcmp($a['note'], $b['note']);
        });
        $out = array();
        foreach ($items as $item) {
            $out[kop_site_map_letter($item['title'])][] = $item;
        }
        uksort($out, static function ($a, $b) {
            if ($a === '#') return 1;
            if ($b === '#') return -1;
            return strcmp($a, $b);
        });
        return $out;
    }
}

if (!function_exists('kop_site_map_slug_place')) {
    /**
     * The state code a facility page's slug carries ("hyde-school-ct" -> "CT";
     * kop_facility_page_slug_candidate()), so two programs of one name tell
     * apart. '' for a country or when the slug is not built from the name.
     */
    function kop_site_map_slug_place($name, $slug) {
        $prefix = sanitize_title((string) $name) . '-';
        $slug = (string) $slug;
        if ($prefix === '-' || strpos($slug, $prefix) !== 0) return '';
        $rest = explode('-', substr($slug, strlen($prefix)));
        $code = strtoupper($rest[0] ?? '');
        if (strlen($code) !== 2 || !ctype_alpha($code)) return '';
        if (function_exists('kop_state_canonical_name')) {
            // A two-letter word that is not a state is the start of a city.
            $state = kop_state_canonical_name($code);
            return (is_string($state) && strcasecmp($state, $code) !== 0) ? $state : '';
        }
        return $code;
    }
}

// ---------------------------------------------------------------------------
// Quick links
// ---------------------------------------------------------------------------

if (!function_exists('kop_site_map_quick_specs')) {
    /**
     * The most used destinations, in order: a page found by template (the
     * first published page using it) or by slug, or a fixed path.
     */
    function kop_site_map_quick_specs() {
        return apply_filters('kop_site_map_quick_specs', array(
            array('label' => 'Facility directory',  'note' => 'Every program, by company or by place', 'template' => 'page-tti-program-index.php', 'icon' => 'building'),
            array('label' => 'Parent companies',    'note' => 'Who owns and runs the programs',        'path' => '/operator/',                  'icon' => 'landmark'),
            array('label' => 'Network map',         'note' => 'How programs, owners and staff connect', 'template' => 'page-network-map.php',      'icon' => 'globe'),
            array('label' => 'Inspection reports',  'note' => 'What state inspectors found',           'template' => 'page-inspection-reports.php', 'icon' => 'clipboard'),
            array('label' => 'News',                'note' => 'Coverage of the industry',              'template' => 'page-news-feed.php',        'icon' => 'newspaper'),
            array('label' => 'Lawsuits',            'note' => 'Court cases against programs',          'template' => 'page-lawsuits.php',         'icon' => 'scale'),
            array('label' => 'Document archive',    'note' => 'Records, reports and filings on file',  'template' => 'page-document-archive.php', 'icon' => 'archive'),
            array('label' => 'Report abuse',        'note' => 'Where to report, state by state',       'template' => 'page-report-abuse.php',     'icon' => 'shield'),
            array('label' => 'Glossary',            'note' => 'The industry\'s words, explained',      'template' => 'page-glossary.php',         'icon' => 'book'),
        ));
    }
}

if (!function_exists('kop_site_map_build_quick_links')) {
    /** array(array(label, note, url, icon)) for the specs whose page is published. */
    function kop_site_map_build_quick_links(array $pages) {
        $by_template = array();
        foreach ($pages as $slug => $p) {
            $t = basename($p['template']);
            if ($t !== '' && !isset($by_template[$t])) $by_template[$t] = $p['url'];
        }
        $out = array();
        foreach (kop_site_map_quick_specs() as $spec) {
            $url = '';
            if (!empty($spec['template'])) {
                $url = $by_template[$spec['template']] ?? '';
            } elseif (!empty($spec['slug'])) {
                $url = isset($pages[$spec['slug']]) ? $pages[$spec['slug']]['url'] : '';
            } elseif (!empty($spec['path'])) {
                $url = home_url($spec['path']);
            }
            if ($url === '') continue;
            $out[] = array(
                'label' => (string) $spec['label'],
                'note'  => (string) ($spec['note'] ?? ''),
                'url'   => $url,
                'icon'  => (string) ($spec['icon'] ?? ''),
            );
        }
        return $out;
    }
}

if (!function_exists('kop_site_map_quick_links')) {
    /**
     * The quick links, from their own small transient so the search popup
     * (on every page) never builds the whole site map.
     */
    function kop_site_map_quick_links() {
        $cached = get_transient('kop_site_map_quick_links');
        if (is_array($cached)) return $cached;
        $links = kop_site_map_build_quick_links(kop_site_map_public_pages());
        set_transient('kop_site_map_quick_links', $links, 12 * HOUR_IN_SECONDS);
        return $links;
    }
}

if (!function_exists('kop_site_map_render_quick_links')) {
    /** The quick-link tiles (site map and 404 page). */
    function kop_site_map_render_quick_links(array $links) {
        if (!$links) return;
        echo '<ul class="kop-sm-quick">';
        foreach ($links as $l) {
            $icon = ($l['icon'] !== '' && function_exists('kop_icon')) ? kop_icon($l['icon'], array('class' => 'kop-sm-quick__icon')) : '';
            echo '<li><a class="kop-sm-quick__link" href="' . esc_url($l['url']) . '">' . $icon
                . '<span class="kop-sm-quick__text"><span class="kop-sm-quick__label">' . esc_html($l['label']) . '</span>'
                . ($l['note'] !== '' ? '<span class="kop-sm-quick__note">' . esc_html($l['note']) . '</span>' : '')
                . '</span></a></li>';
        }
        echo '</ul>';
    }
}

// ---------------------------------------------------------------------------
// Rendering helpers
// ---------------------------------------------------------------------------

if (!function_exists('kop_site_map_render_items')) {
    /** A nested list; every link carries its words in data-sm for the filter. */
    function kop_site_map_render_items(array $items, $class = 'kop-sm-list') {
        if (!$items) return;
        echo '<ul class="' . esc_attr($class) . '">';
        foreach ($items as $item) {
            $words = $item['title'] . ($item['note'] !== '' ? ' ' . $item['note'] : '');
            echo '<li data-sm="' . esc_attr(kop_site_map_fold($words)) . '">';
            echo '<a href="' . esc_url($item['url']) . '">' . esc_html($item['title']) . '</a>';
            if ($item['note'] !== '') echo ' <span class="kop-sm-note">' . esc_html($item['note']) . '</span>';
            if (!empty($item['children'])) kop_site_map_render_items($item['children'], 'kop-sm-list kop-sm-list--sub');
            echo '</li>';
        }
        echo '</ul>';
    }
}

if (!function_exists('kop_site_map_fold')) {
    /** Lower case, no accents: what the filter compares (js/site-map.js fold()). */
    function kop_site_map_fold($text) {
        $text = html_entity_decode((string) $text, ENT_QUOTES, 'UTF-8');
        if (function_exists('remove_accents')) $text = remove_accents($text);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}

if (!function_exists('kop_site_map_letter_id')) {
    function kop_site_map_letter_id($section, $letter) {
        return 'kop-sm-' . $section . '-' . ($letter === '#' ? 'num' : strtolower($letter));
    }
}

// ---------------------------------------------------------------------------
// The 404 page: what the address looked like it was after
// ---------------------------------------------------------------------------

if (!function_exists('kop_site_map_404_phrase')) {
    /** "/facility/hyde-school-ct/" -> "hyde school ct": the words in the address. */
    function kop_site_map_404_phrase($path) {
        $path = (string) wp_parse_url((string) $path, PHP_URL_PATH);
        $parts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        if (!$parts) return '';
        // A missing image, script or upload is not a page anyone was after,
        // and is not worth a database search.
        if (preg_match('/^wp-/i', $parts[0])) return '';
        $last = rawurldecode(end($parts));
        if (preg_match('/\.[a-z0-9]{2,5}$/i', $last) && !preg_match('/\.(php|html?|aspx?)$/i', $last)) return '';
        $last = preg_replace('/\.(php|html?|aspx?)$/i', '', $last);
        $words = preg_replace('/[\s\-_+.]+/', ' ', $last);
        $words = trim(preg_replace('/[^\p{L}\p{N} \']+/u', '', $words));
        // A page number or an id on its own says nothing.
        if ($words === '' || ctype_digit(str_replace(' ', '', $words))) return '';
        return mb_substr($words, 0, 80);
    }
}

if (!function_exists('kop_site_map_404_suggestions')) {
    /**
     * Up to $limit array(title, url, note) the address may have meant:
     * facility and company pages by name (dropping trailing words, so a
     * state code or a page number does not spoil the match), then pages
     * and posts.
     */
    function kop_site_map_404_suggestions($phrase, $limit = 6) {
        $phrase = trim((string) $phrase);
        if (mb_strlen($phrase) < 3) return array();
        $out = array();
        $seen = array();
        $add = function ($title, $url, $note) use (&$out, &$seen) {
            if ($url === '' || isset($seen[$url])) return;
            $seen[$url] = true;
            $out[] = array('title' => html_entity_decode((string) $title, ENT_QUOTES, 'UTF-8'), 'url' => $url, 'note' => (string) $note);
        };

        if (function_exists('kop_v2_search') && function_exists('kop_search_v2_result_url')) {
            $words = explode(' ', $phrase);
            $tries = 0;
            while ($words && !$out && $tries++ < 3) {
                $try = implode(' ', $words);
                if (mb_strlen($try) < 3) break;
                $found = kop_v2_search($try, 4, 2, 0);
                foreach (array_merge($found['facilities'] ?? array(), $found['operators'] ?? array()) as $r) {
                    $note = $r['kind'] === 'operator' ? 'Parent company' : trim((string) ($r['location'] ?? ''));
                    $add($r['display'], kop_search_v2_result_url($r), $note);
                }
                array_pop($words);
            }
        }

        if (count($out) < $limit) {
            $posts = get_posts(array(
                'post_type'      => array('page', 'post'),
                'post_status'    => 'publish',
                's'              => $phrase,
                'posts_per_page' => $limit,
                'has_password'   => false,
                'no_found_rows'  => true,
            ));
            foreach ((array) $posts as $p) {
                if (kop_site_map_section_for($p->post_name, get_page_template_slug($p)) === 'hide' && $p->post_type === 'page') continue;
                $add(get_the_title($p), get_permalink($p), $p->post_type === 'post' ? 'Post' : 'Page');
            }
        }
        return array_slice($out, 0, $limit);
    }
}
