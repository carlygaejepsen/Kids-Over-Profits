<?php
/**
 * /how-to-use-this-site/: where to start, for a reader new to the site.
 *
 * The main destinations grouped by what a reader came to do (look up a
 * program, records of harm, get help or take action, understand the
 * industry, written for you), each with a line saying what is there, then
 * how to search. The site map (/site-map/, inc/site-map.php) stays the list
 * of every page; this page is the short guide and links to it.
 *
 *   routing   a route, not a WordPress page (like /site-map/ and /operator/),
 *             so it exists without anyone creating it; /how-to-use/ 301s here
 *   links     kop_how_to_use_groups(), hand-picked: add a new main page there.
 *             A link whose page is not published is dropped
 *   cache     the resolved groups are one transient, cleared when a page is saved
 *
 * php scripts/test-site-map.php renders it offline too.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_HOW_TO_USE_REWRITE_VERSION')) {
    define('KOP_HOW_TO_USE_REWRITE_VERSION', '1');
}

if (!function_exists('kop_how_to_use_base')) {
    function kop_how_to_use_base() {
        return apply_filters('kop_how_to_use_base', 'how-to-use-this-site');
    }
}

if (!function_exists('kop_how_to_use_url')) {
    function kop_how_to_use_url() {
        return home_url('/' . kop_how_to_use_base() . '/');
    }
}

if (!function_exists('kop_how_to_use_register_rewrite')) {
    function kop_how_to_use_register_rewrite() {
        add_rewrite_rule('^' . preg_quote(kop_how_to_use_base(), '#') . '/?$', 'index.php?kop_how_to_use=1', 'top');
        if (get_option('kop_how_to_use_rewrite') !== KOP_HOW_TO_USE_REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option('kop_how_to_use_rewrite', KOP_HOW_TO_USE_REWRITE_VERSION);
        }
    }
    add_action('init', 'kop_how_to_use_register_rewrite', 30);
}

if (!function_exists('kop_how_to_use_query_vars')) {
    function kop_how_to_use_query_vars($vars) {
        $vars[] = 'kop_how_to_use';
        return $vars;
    }
    add_filter('query_vars', 'kop_how_to_use_query_vars');
}

if (!function_exists('kop_how_to_use_is_request')) {
    function kop_how_to_use_is_request($query = null) {
        if ($query === null) {
            return function_exists('get_query_var') && (string) get_query_var('kop_how_to_use') === '1';
        }
        return (string) $query->get('kop_how_to_use') === '1';
    }
}

if (!function_exists('kop_how_to_use_is_page')) {
    /** True while /how-to-use-this-site/ is being rendered. */
    function kop_how_to_use_is_page() {
        return isset($GLOBALS['kop_how_to_use']) && is_array($GLOBALS['kop_how_to_use']);
    }
}

if (!function_exists('kop_how_to_use_pre_get_posts')) {
    /** No post query var, so WordPress would load the blog home; skip it. */
    function kop_how_to_use_pre_get_posts($query) {
        if (is_admin() || !$query->is_main_query() || !kop_how_to_use_is_request($query)) return;
        $query->is_home = false;
        $query->is_posts_page = false;
        $query->set('posts_per_page', 1);
        $query->set('no_found_rows', true);
    }
    add_action('pre_get_posts', 'kop_how_to_use_pre_get_posts');
}

if (!function_exists('kop_how_to_use_posts_pre_query')) {
    function kop_how_to_use_posts_pre_query($posts, $query) {
        if (is_admin() || !$query->is_main_query() || !kop_how_to_use_is_request($query)) return $posts;
        return array();
    }
    add_filter('posts_pre_query', 'kop_how_to_use_posts_pre_query', 10, 2);
}

if (!function_exists('kop_how_to_use_pre_handle_404')) {
    function kop_how_to_use_pre_handle_404($preempt, $query) {
        return kop_how_to_use_is_request($query) ? true : $preempt;
    }
    add_filter('pre_handle_404', 'kop_how_to_use_pre_handle_404', 10, 2);
}

if (!function_exists('kop_how_to_use_route')) {
    function kop_how_to_use_route() {
        if (!kop_how_to_use_is_request()) return;
        $GLOBALS['kop_how_to_use'] = kop_how_to_use_data();
        status_header(200);
    }
    add_action('template_redirect', 'kop_how_to_use_route', 0);
}

if (!function_exists('kop_how_to_use_redirect_aliases')) {
    function kop_how_to_use_redirect_aliases($map) {
        foreach (array('how-to-use', 'start-here') as $alias) {
            if (!isset($map[$alias])) $map[$alias] = '/' . kop_how_to_use_base() . '/';
        }
        return $map;
    }
    add_filter('kop_redirect_map', 'kop_how_to_use_redirect_aliases');
}

if (!function_exists('kop_how_to_use_template_include')) {
    function kop_how_to_use_template_include($template) {
        if (!kop_how_to_use_is_page()) return $template;
        $own = get_stylesheet_directory() . '/templates/how-to-use.php';
        return file_exists($own) ? $own : $template;
    }
    add_filter('template_include', 'kop_how_to_use_template_include', 99);
}

if (!function_exists('kop_how_to_use_body_class')) {
    function kop_how_to_use_body_class($classes) {
        if (kop_how_to_use_is_page()) $classes[] = 'kop-how-to-use-page';
        return $classes;
    }
    add_filter('body_class', 'kop_how_to_use_body_class');
}

if (!function_exists('kop_how_to_use_enqueue')) {
    /** The site map's panel and list styles, plus this page's groups. */
    function kop_how_to_use_enqueue() {
        if (!kop_how_to_use_is_page()) return;
        $dir = get_stylesheet_directory();
        $uri = get_stylesheet_directory_uri();
        foreach (array('site-map', 'how-to-use') as $name) {
            $css = $dir . '/css/' . $name . '.css';
            if (file_exists($css)) {
                wp_enqueue_style('kop-' . $name, $uri . '/css/' . $name . '.css', array('kop-colors'), filemtime($css));
            }
        }
    }
    add_action('wp_enqueue_scripts', 'kop_how_to_use_enqueue', 20);
}

if (!function_exists('kop_how_to_use_summary')) {
    function kop_how_to_use_summary() {
        return 'Where to start on Kids Over Profits: looking up a program, records of harm, getting help, understanding the troubled teen industry, and how to search the site.';
    }
}

if (!function_exists('kop_how_to_use_document_title')) {
    function kop_how_to_use_document_title($title) {
        return kop_how_to_use_is_page() ? 'How to use this site | ' . get_bloginfo('name') : $title;
    }
    add_filter('pre_get_document_title', 'kop_how_to_use_document_title', 20);
    add_filter('wpseo_title', 'kop_how_to_use_document_title', 20);
    add_filter('wpseo_opengraph_title', 'kop_how_to_use_document_title', 20);
}

if (!function_exists('kop_how_to_use_meta_description')) {
    function kop_how_to_use_meta_description($desc) {
        return kop_how_to_use_is_page() ? kop_how_to_use_summary() : $desc;
    }
    add_filter('wpseo_metadesc', 'kop_how_to_use_meta_description', 20);
    add_filter('wpseo_opengraph_desc', 'kop_how_to_use_meta_description', 20);
}

if (!function_exists('kop_how_to_use_canonical')) {
    function kop_how_to_use_canonical($url) {
        return kop_how_to_use_is_page() ? kop_how_to_use_url() : $url;
    }
    add_filter('wpseo_canonical', 'kop_how_to_use_canonical', 20);
    add_filter('wpseo_opengraph_url', 'kop_how_to_use_canonical', 20);
}

if (!function_exists('kop_how_to_use_robots')) {
    function kop_how_to_use_robots($robots) {
        return kop_how_to_use_is_page() ? 'index, follow' : $robots;
    }
    add_filter('wpseo_robots', 'kop_how_to_use_robots', 20);
}

// ---------------------------------------------------------------------------
// The groups
// ---------------------------------------------------------------------------

if (!function_exists('kop_how_to_use_groups')) {
    /**
     * Each group: title, links. A link is a page 'slug' (dropped when that page
     * is not published) or a route 'path', with an optional 'query'.
     */
    function kop_how_to_use_groups() {
        return apply_filters('kop_how_to_use_groups', array(
            array('title' => 'Look up a program', 'links' => array(
                array('label' => 'Facility directory', 'slug' => 'tti-program-index', 'note' => 'Every program we track, grouped by the company that runs it'),
                array('label' => 'Programs by state or country', 'slug' => 'tti-program-index', 'query' => array('view' => 'location'), 'note' => 'The same programs, by where they are'),
                array('label' => 'Parent companies', 'path' => '/operator/', 'note' => 'The companies that own and run programs, and their histories'),
                array('label' => 'Network map', 'slug' => 'network-map', 'note' => 'How programs, owners, staff and referrers connect'),
                array('label' => 'Educational consultants and referrers', 'slug' => 'referrers-educational-consultants', 'note' => 'Who sends young people to programs'),
                array('label' => 'Youth transport companies', 'slug' => 'youth-transport-companies', 'note' => 'The companies hired to take young people to programs'),
                array('label' => 'Young adult programs', 'slug' => 'young-adult-programs', 'note' => 'Programs for people 18 and older, run by the same industry'),
            )),
            array('title' => 'Records of harm', 'links' => array(
                array('label' => 'Inspection reports', 'slug' => 'inspection-reports', 'note' => 'What state inspectors found, state by state'),
                array('label' => 'Serious findings', 'slug' => 'severe-reports', 'note' => 'Deaths, assaults and other serious findings from inspections'),
                array('label' => 'In Loving Memory', 'slug' => 'in-loving-memory', 'note' => "Young people who died in the industry's care"),
                array('label' => 'Lawsuits', 'slug' => 'lawsuits', 'note' => 'Court cases against programs and their staff'),
                array('label' => 'News feed', 'slug' => 'tti-news-feed', 'note' => 'News coverage of programs, newest first'),
                array('label' => 'Document archive', 'slug' => 'document-archive', 'note' => 'Records, reports and court filings on file'),
            )),
            array('title' => 'Get help or take action', 'links' => array(
                array('label' => 'Report abuse', 'slug' => 'report-abuse', 'note' => 'Where to report a program or a therapist, state by state'),
                array('label' => 'Resources', 'slug' => 'resources', 'note' => 'Support groups and help for survivors and families'),
                array('label' => 'Add or correct a program', 'slug' => 'tti-data-submission', 'note' => 'Tell us what you know about a program'),
                array('label' => 'Send documents anonymously', 'slug' => 'anon-submit', 'note' => 'An encrypted upload that does not record who sent it'),
                array('label' => 'Volunteer', 'slug' => 'volunteer', 'note' => 'Research, data entry, writing and outreach'),
                array('label' => 'Donate', 'slug' => 'donate', 'note' => 'Support the work'),
            )),
            array('title' => 'Understand the industry', 'links' => array(
                array('label' => 'History', 'slug' => 'history', 'note' => 'How the troubled teen industry came to be'),
                array('label' => 'Law & Policy', 'slug' => 'law-policy', 'note' => 'Lawsuits and bills, and what they mean'),
                array('label' => 'Legislation tracker', 'slug' => 'legislative-efforts', 'note' => 'Bills to regulate programs, as they move'),
                array('label' => 'Research & Reports', 'slug' => 'researchreports', 'note' => 'Studies and government reports, summarized'),
                array('label' => 'How the industry manages its reputation', 'slug' => 'reputation-management', 'note' => 'Marketing, review sites and new names for old programs'),
                array('label' => 'Glossary', 'slug' => 'glossary', 'note' => "The industry's words, explained"),
                array('label' => 'Frequently asked questions', 'slug' => 'faq', 'note' => 'What counts as the industry, where it came from, how to help'),
            )),
            array('title' => 'Written for you', 'links' => array(
                array('label' => 'Survivors', 'slug' => 'survivors', 'note' => 'If you went through a program'),
                array('label' => 'Family and friends', 'slug' => 'families', 'note' => 'If someone you love is in a program, or was'),
                array('label' => 'Advocates', 'slug' => 'advocates', 'note' => 'Organizing against the industry'),
                array('label' => 'Journalists', 'slug' => 'journalists', 'note' => 'Records and sources for reporting'),
                array('label' => 'Where are the kids?', 'slug' => 'where-are-the-kids', 'note' => 'Young people sent to programs, state by state'),
            )),
        ));
    }
}

if (!function_exists('kop_how_to_use_build')) {
    /** array('groups' => array(array(title, items))) with every link resolved. */
    function kop_how_to_use_build(array $pages) {
        $groups = array();
        foreach (kop_how_to_use_groups() as $group) {
            $items = array();
            foreach ((array) ($group['links'] ?? array()) as $spec) {
                $url = '';
                if (!empty($spec['slug'])) {
                    $url = isset($pages[$spec['slug']]) ? $pages[$spec['slug']]['url'] : '';
                } elseif (!empty($spec['path'])) {
                    $url = home_url($spec['path']);
                }
                if ($url === '') continue;
                if (!empty($spec['query'])) $url = add_query_arg($spec['query'], $url);
                $items[] = kop_site_map_item($spec['label'], $url, (string) ($spec['note'] ?? ''));
            }
            if ($items) $groups[] = array('title' => (string) $group['title'], 'items' => $items);
        }
        return array('groups' => $groups);
    }
}

if (!function_exists('kop_how_to_use_data')) {
    function kop_how_to_use_data() {
        $cached = get_transient('kop_how_to_use_data');
        if (is_array($cached) && ($cached['v'] ?? '') === KOP_HOW_TO_USE_REWRITE_VERSION) return $cached;
        $data = kop_how_to_use_build(kop_site_map_public_pages());
        $data['v'] = KOP_HOW_TO_USE_REWRITE_VERSION;
        set_transient('kop_how_to_use_data', $data, DAY_IN_SECONDS);
        return $data;
    }
}

if (!function_exists('kop_how_to_use_flush')) {
    function kop_how_to_use_flush($post_id = 0) {
        if ($post_id && get_post_type($post_id) !== 'page') return;
        delete_transient('kop_how_to_use_data');
    }
    add_action('save_post', 'kop_how_to_use_flush');
    add_action('deleted_post', 'kop_how_to_use_flush');
}
