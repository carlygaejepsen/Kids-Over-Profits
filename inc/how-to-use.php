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
    define('KOP_HOW_TO_USE_REWRITE_VERSION', '2');
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
     * Each group: title, intro, links. A link is a page 'slug' (dropped when that
     * page is not published) or a route 'path', with an optional 'query'; its
     * note says what is on that page. Counts are written "more than" so they
     * stay true as the records grow.
     */
    function kop_how_to_use_groups() {
        return apply_filters('kop_how_to_use_groups', array(
            array('title' => 'Look up a program', 'intro' => 'For when you have a name: a program, the company behind it, or the consultant who recommended it.', 'links' => array(
                array('label' => 'Facility directory', 'slug' => 'tti-program-index', 'note' => 'Every program on record, open and closed, grouped under the company that runs or ran it. Filter by status, or sort by the number of inspection reports; each name opens the program\'s own page.'),
                array('label' => 'Where are the kids?', 'slug' => 'where-are-the-kids', 'note' => 'A page for every state and for the countries programs operate in: the programs there, the inspection reports that state publishes, its laws and its news.'),
                array('label' => 'Parent companies', 'path' => '/operator/', 'note' => 'The companies and religious organizations that own and run programs, such as Universal Health Services, Sequel TSI, Aspen Education Group and Teen Challenge. Each one\'s page lists what it has run and where, its history year by year, the people who ran it, and the lawsuits and deaths on record across its programs.'),
                array('label' => 'Network map', 'slug' => 'network-map', 'note' => 'More than 1,400 programs, companies and people, and the documented connections between them: who owned what, which staff moved from one program to the next, and which programs changed names. A timeline shows the years each was operating.'),
                array('label' => 'Educational consultants and referrers', 'slug' => 'referrers-educational-consultants', 'note' => 'The paid advisors who recommend programs to parents. Each listing gives the programs they have referred to, where they worked before, the trade groups they belong to and any legal history on record.'),
                array('label' => 'Youth transport companies', 'slug' => 'youth-transport-companies', 'note' => 'The escort companies parents hire to take a young person from home to a program, with what is on file about each.'),
                array('label' => 'Young adult programs', 'slug' => 'young-adult-programs', 'note' => 'Transitional living, wilderness and therapeutic programs for people 18 and older. Many are run by the same companies and staff as the teen programs, or take young people straight from them.'),
            )),
            array('title' => 'Records of harm', 'intro' => 'What licensing agencies, courts, reporters and families have documented, each with its source.', 'links' => array(
                array('label' => 'Inspection reports', 'slug' => 'inspection-reports', 'note' => 'More than 80,000 state licensing inspections from more than 25 states, gathered so they can be read without filing a records request. Each state\'s tracker lists the facilities it licenses, every visit on record and the violations inspectors wrote up.'),
                array('label' => 'Serious findings', 'slug' => 'severe-reports', 'note' => 'The most serious of those findings: deaths, sexual and physical abuse, restraints that injured a child, suicide attempts and medical neglect. Each quote is the agency\'s own wording, checked against its report by a person before it is listed.'),
                array('label' => 'In Loving Memory', 'slug' => 'in-loving-memory', 'note' => 'More than 200 young people who died in programs, reform homes and detention centers, with the program, the date, the cause of death and the reporting. Filter by state, decade or cause.'),
                array('label' => 'Lawsuits', 'slug' => 'lawsuits', 'note' => 'Court cases against programs, their owners and staff: what was claimed, where it was filed and where it stands, filterable by status, court and claim.'),
                array('label' => 'News feed', 'slug' => 'tti-news-feed', 'note' => 'Coverage of the industry as it comes out: arrests, closures, lawsuits and investigations. Long-running stories are collected article by article.'),
                array('label' => 'Document archive', 'slug' => 'document-archive', 'note' => 'More than 4,800 documents: Senate and GAO investigations, court filings, research, and the programs\' own handbooks, brochures and newsletters, filed under the program or company they concern. Free to read and download.'),
            )),
            array('title' => 'Get help or take action', 'intro' => 'If something happened to you or someone you know, or you have something to add to the record.', 'links' => array(
                array('label' => 'Report abuse', 'slug' => 'report-abuse', 'note' => 'Who takes a report in every state: the board that licenses a therapist, the agency that licenses the program, child protection and the police. They do not reliably share reports with each other, so the page says which to tell and what each can do.'),
                array('label' => 'Resources', 'slug' => 'resources', 'note' => 'Crisis lines that answer around the clock, survivor support groups, advocacy organizations and further reading, grouped by what you need.'),
                array('label' => 'Add or correct a program', 'slug' => 'tti-data-submission', 'note' => 'Send what you know about a program, company, consultant or transport company. A person reviews every submission before it is added.'),
                array('label' => 'Send documents anonymously', 'slug' => 'anon-submit', 'note' => 'Upload records or photos without an account. Files are encrypted the moment they reach the server, and only the site owner holds the key that opens them.'),
                array('label' => 'Volunteer', 'slug' => 'volunteer', 'note' => 'Research, data entry and news tracking, or send in the lawsuits, bills and articles we are missing.'),
                array('label' => 'Donate', 'slug' => 'donate', 'note' => 'Kids Over Profits is a 501(c)(3) nonprofit. Donations pay for records requests, document storage and hosting.'),
            )),
            array('title' => 'Understand the industry', 'intro' => 'Where the industry came from, how it works and the laws that govern it.', 'links' => array(
                array('label' => 'History', 'slug' => 'history', 'note' => 'Timelines and essays tracing the industry from older institutions for controlling children, through juvenile justice reform and corporate ownership, to the survivors now organizing against it.'),
                array('label' => 'Law & Policy', 'slug' => 'law-policy', 'note' => 'The newest lawsuits and bills side by side, with links to both trackers.'),
                array('label' => 'Legislation tracker', 'slug' => 'legislative-efforts', 'note' => 'Bills to regulate programs in Congress and the states: each one\'s status, sponsors and last action, a plain summary, and whether Kids Over Profits supports or opposes it.'),
                array('label' => 'Research & Reports', 'slug' => 'researchreports', 'note' => 'Government audits, academic studies and investigations of youth residential treatment, each with notes on what it found and the programs it names.'),
                array('label' => 'How the industry manages its reputation', 'slug' => 'reputation-management', 'note' => 'How programs shape what a parent finds online: instructions written for AI chatbots, "independent" directories run by the programs\' own marketing firm, and one program\'s 2,500 near-identical pages, one for nearly every town.'),
                array('label' => 'Glossary', 'slug' => 'glossary', 'note' => 'More than 450 words programs use for their methods, taken from handbooks, staff manuals, state records and survivor accounts, each with the programs where it was used.'),
                array('label' => 'Frequently asked questions', 'slug' => 'faq', 'note' => 'What counts as the troubled teen industry, where it came from, what happens in programs and how to help, answered briefly with links to the records.'),
            )),
            array('title' => 'Written for you', 'intro' => 'Pages written with one kind of reader in mind.', 'links' => array(
                array('label' => 'Survivors', 'slug' => 'survivors', 'note' => 'Support groups, a survivor-written guide to common after-effects, legal options and places to tell your story.'),
                array('label' => 'Family and friends', 'slug' => 'families', 'note' => 'How to support someone who survived a program: what to say, what to do and what to avoid.'),
                array('label' => 'Advocates', 'slug' => 'advocates', 'note' => 'The research, history and program data most useful for organizing against the industry, in one place.'),
                array('label' => 'Journalists', 'slug' => 'journalists', 'note' => 'Guidance on trauma-informed reporting and interviewing survivors, the sources we use, and how to reach us for interviews or reprints.'),
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
            if ($items) $groups[] = array('title' => (string) $group['title'], 'intro' => (string) ($group['intro'] ?? ''), 'items' => $items);
        }
        // The "in danger now" line at the top of the page.
        $urgent = array();
        foreach (array('report' => 'report-abuse', 'help' => 'resources') as $key => $slug) {
            $urgent[$key] = isset($pages[$slug]) ? $pages[$slug]['url'] : '';
        }
        return array('groups' => $groups, 'urgent' => $urgent);
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
