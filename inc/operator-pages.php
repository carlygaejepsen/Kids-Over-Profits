<?php
/**
 * Operator pages: a public profile for every parent company in
 * {prefix}kop_operators.
 *
 * URL: /operator/<slug>/ (kop_operator_pages_base()). Rendered from the
 * database on each request by templates/operator-page.php, like the
 * generated facility pages (inc/facility-pages.php), whose helpers and
 * stylesheet this reuses. Nothing is written to wp_posts.
 *
 * What a page holds: the company's facts, the parent companies above it and
 * the ones it owns, every facility it has run (linked to that facility's
 * page), news that names it, lawsuits and deaths on record at its programs,
 * its document folder, and a link into the network map.
 *
 * Two records with the same name (a migration left Rite of Passage twice)
 * share one page: the record with the most on file is canonical and the
 * other's facilities are folded in.
 *
 * Entry points for other modules:
 *   kop_operator_page_url($operator_id)
 *   kop_operator_page_url_for_name($name)
 *
 * Guarded with function_exists so scripts/test-operator-pages.php can load
 * it next to WordPress stubs.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_OPERATOR_PAGES_REWRITE_VERSION')) {
    define('KOP_OPERATOR_PAGES_REWRITE_VERSION', '1');
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

if (!function_exists('kop_operator_pages_base')) {
    function kop_operator_pages_base() {
        return apply_filters('kop_operator_pages_base', 'operator');
    }
}

if (!function_exists('kop_operator_pages_register_rewrite')) {
    function kop_operator_pages_register_rewrite() {
        $base = kop_operator_pages_base();
        add_rewrite_rule('^' . preg_quote($base, '#') . '/([^/]+)/?$', 'index.php?kop_operator=$matches[1]', 'top');
        if (get_option('kop_operator_pages_rewrite') !== KOP_OPERATOR_PAGES_REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option('kop_operator_pages_rewrite', KOP_OPERATOR_PAGES_REWRITE_VERSION);
        }
    }
    add_action('init', 'kop_operator_pages_register_rewrite', 30);
}

if (!function_exists('kop_operator_pages_query_vars')) {
    function kop_operator_pages_query_vars($vars) {
        $vars[] = 'kop_operator';
        return $vars;
    }
    add_filter('query_vars', 'kop_operator_pages_query_vars');
}

if (!function_exists('kop_operator_pages_requested_slug')) {
    function kop_operator_pages_requested_slug() {
        if (!function_exists('get_query_var')) return '';
        $slug = get_query_var('kop_operator');
        return is_string($slug) ? trim($slug) : '';
    }
}

if (!function_exists('kop_operator_pages_is_page')) {
    /** True while an operator page is being rendered. */
    function kop_operator_pages_is_page() {
        return !empty($GLOBALS['kop_operator_page']) && is_array($GLOBALS['kop_operator_page']);
    }
}

if (!function_exists('kop_operator_pages_pre_get_posts')) {
    /** No post query var, so WordPress would load the blog home; skip it. */
    function kop_operator_pages_pre_get_posts($query) {
        if (is_admin() || !$query->is_main_query()) return;
        if (trim((string) $query->get('kop_operator')) === '') return;
        $query->is_home = false;
        $query->is_posts_page = false;
        $query->set('posts_per_page', 1);
        $query->set('no_found_rows', true);
    }
    add_action('pre_get_posts', 'kop_operator_pages_pre_get_posts');
}

if (!function_exists('kop_operator_pages_posts_pre_query')) {
    function kop_operator_pages_posts_pre_query($posts, $query) {
        if (is_admin() || !$query->is_main_query()) return $posts;
        if (trim((string) $query->get('kop_operator')) === '') return $posts;
        return array();
    }
    add_filter('posts_pre_query', 'kop_operator_pages_posts_pre_query', 10, 2);
}

if (!function_exists('kop_operator_pages_pre_handle_404')) {
    function kop_operator_pages_pre_handle_404($preempt, $query) {
        if (trim((string) $query->get('kop_operator')) !== '') return true;
        return $preempt;
    }
    add_filter('pre_handle_404', 'kop_operator_pages_pre_handle_404', 10, 2);
}

if (!function_exists('kop_operator_pages_route')) {
    /** Resolve the slug: render, 301 to the canonical slug, or 404. */
    function kop_operator_pages_route() {
        $slug = kop_operator_pages_requested_slug();
        if ($slug === '') return;

        $index = kop_operator_pages_index();
        $key = strtolower($slug);
        $id = 0;
        if (ctype_digit($key)) {
            $id = (int) $key;
        } elseif (isset($index['slugs'][$key])) {
            $id = (int) $index['slugs'][$key];
        }
        if ($id > 0 && isset($index['alias_of'][$id])) {
            $id = (int) $index['alias_of'][$id];
        }

        if ($id > 0 && isset($index['ids'][$id])) {
            $entry = $index['ids'][$id];
            if ($slug !== $entry['slug']) {
                wp_safe_redirect(kop_operator_pages_url_for_slug($entry['slug']), 301);
                exit;
            }
            $data = kop_operator_page_data($id);
            if ($data) {
                $GLOBALS['kop_operator_page'] = $data;
                status_header(200);
                return;
            }
        }

        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }
    add_action('template_redirect', 'kop_operator_pages_route', 0);
}

if (!function_exists('kop_operator_pages_template_include')) {
    function kop_operator_pages_template_include($template) {
        if (!kop_operator_pages_is_page()) return $template;
        $own = get_stylesheet_directory() . '/templates/operator-page.php';
        return file_exists($own) ? $own : $template;
    }
    add_filter('template_include', 'kop_operator_pages_template_include', 99);
}

if (!function_exists('kop_operator_pages_body_class')) {
    function kop_operator_pages_body_class($classes) {
        if (kop_operator_pages_is_page()) $classes[] = 'kop-operator-page';
        return $classes;
    }
    add_filter('body_class', 'kop_operator_pages_body_class');
}

if (!function_exists('kop_operator_pages_enqueue')) {
    /** The facility profile stylesheet: the operator page uses its classes. */
    function kop_operator_pages_enqueue() {
        if (!kop_operator_pages_is_page()) return;
        $theme_dir = get_stylesheet_directory();
        $theme_uri = get_stylesheet_directory_uri();
        if (function_exists('kop_enqueue_shared_facility_ui')) {
            kop_enqueue_shared_facility_ui();
        }
        $css = $theme_dir . '/css/facility-profile.css';
        if (file_exists($css)) {
            wp_enqueue_style('kop-facility-profile', $theme_uri . '/css/facility-profile.css', array('kop-colors'), filemtime($css));
        }
        $doc_css = $theme_dir . '/css/document-library.css';
        if (file_exists($doc_css) && !empty($GLOBALS['kop_operator_page']['documents']['html'])) {
            wp_enqueue_style('kop-document-library-style', $theme_uri . '/css/document-library.css', array('kop-colors'), filemtime($doc_css));
        }
        $js = $theme_dir . '/js/submit-info.js';
        if (file_exists($js)) {
            wp_enqueue_script('kop-submit-info', $theme_uri . '/js/submit-info.js', array(), filemtime($js), true);
        }
    }
    add_action('wp_enqueue_scripts', 'kop_operator_pages_enqueue', 20);
}

if (!function_exists('kop_operator_pages_document_title')) {
    function kop_operator_pages_document_title($title) {
        return kop_operator_pages_is_page() ? $GLOBALS['kop_operator_page']['seo_title'] : $title;
    }
    add_filter('pre_get_document_title', 'kop_operator_pages_document_title', 20);
    add_filter('wpseo_title', 'kop_operator_pages_document_title', 20);
    add_filter('wpseo_opengraph_title', 'kop_operator_pages_document_title', 20);
}

if (!function_exists('kop_operator_pages_meta_description')) {
    function kop_operator_pages_meta_description($desc) {
        return kop_operator_pages_is_page() ? $GLOBALS['kop_operator_page']['summary'] : $desc;
    }
    add_filter('wpseo_metadesc', 'kop_operator_pages_meta_description', 20);
    add_filter('wpseo_opengraph_desc', 'kop_operator_pages_meta_description', 20);
}

if (!function_exists('kop_operator_pages_canonical')) {
    function kop_operator_pages_canonical($url) {
        return kop_operator_pages_is_page() ? $GLOBALS['kop_operator_page']['url'] : $url;
    }
    add_filter('wpseo_canonical', 'kop_operator_pages_canonical', 20);
    add_filter('wpseo_opengraph_url', 'kop_operator_pages_canonical', 20);
}

if (!function_exists('kop_operator_pages_robots')) {
    function kop_operator_pages_robots($robots) {
        return kop_operator_pages_is_page() ? 'index, follow, max-image-preview:large' : $robots;
    }
    add_filter('wpseo_robots', 'kop_operator_pages_robots', 20);
}

// ---------------------------------------------------------------------------
// Index
// ---------------------------------------------------------------------------

if (!function_exists('kop_operator_pages_decode')) {
    /** The operator block of a kop_operators row's json_data, or array(). */
    function kop_operator_pages_decode($json) {
        $doc = json_decode((string) $json, true);
        return (is_array($doc) && isset($doc['operator']) && is_array($doc['operator'])) ? $doc['operator'] : array();
    }
}

if (!function_exists('kop_operator_pages_display_name')) {
    /** "Universal Health Services (UHS)" -> "Universal Health Services". */
    function kop_operator_pages_display_name($name) {
        $name = trim((string) $name);
        $short = trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', $name));
        return $short !== '' ? $short : $name;
    }
}

if (!function_exists('kop_operator_pages_abbreviation')) {
    /** "Universal Health Services (UHS)" -> "UHS"; '' when there is none. */
    function kop_operator_pages_abbreviation($name) {
        return preg_match('/\(([A-Z][A-Za-z0-9.&]{1,11})\)\s*$/u', (string) $name, $m) ? $m[1] : '';
    }
}

if (!function_exists('kop_operator_pages_fingerprint')) {
    function kop_operator_pages_fingerprint() {
        global $wpdb;
        $ops = $wpdb->prefix . 'kop_operators';
        $ofc = $wpdb->prefix . 'kop_operator_facilities';
        if (!kop_facility_pages_table_exists($ops)) return '';
        $a = $wpdb->get_row("SELECT COUNT(*) AS n, MAX(updated_at) AS u FROM `{$ops}`", ARRAY_A);
        $b = kop_facility_pages_table_exists($ofc) ? (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$ofc}`") : 0;
        return md5(json_encode(array($a, $b, KOP_OPERATOR_PAGES_REWRITE_VERSION)));
    }
}

if (!function_exists('kop_operator_pages_index')) {
    /**
     * {fingerprint, ids: {id: {slug, name, display, members[]}},
     *  slugs: {slug: id}, alias_of: {duplicate id: canonical id},
     *  names: {name key: id}}. Cached in a transient until the operator
     * tables change.
     */
    function kop_operator_pages_index($force = false) {
        static $memo = null;
        if (!$force && $memo !== null) return $memo;
        $fingerprint = kop_operator_pages_fingerprint();
        if (!$force) {
            $cached = get_transient('kop_operator_pages_index');
            if (is_array($cached) && ($cached['fingerprint'] ?? '') === $fingerprint) {
                return $memo = $cached;
            }
        }
        $memo = kop_operator_pages_build_index($fingerprint);
        set_transient('kop_operator_pages_index', $memo, DAY_IN_SECONDS);
        return $memo;
    }
}

if (!function_exists('kop_operator_pages_build_index')) {
    function kop_operator_pages_build_index($fingerprint) {
        global $wpdb;
        $index = array('fingerprint' => $fingerprint, 'ids' => array(), 'slugs' => array(), 'alias_of' => array(), 'names' => array());
        $ops = $wpdb->prefix . 'kop_operators';
        $ofc = $wpdb->prefix . 'kop_operator_facilities';
        if (!kop_facility_pages_table_exists($ops)) return $index;

        $counts = array();
        if (kop_facility_pages_table_exists($ofc)) {
            foreach ((array) $wpdb->get_results("SELECT operator_id, COUNT(*) AS n FROM `{$ofc}` GROUP BY operator_id", ARRAY_A) as $r) {
                $counts[(int) $r['operator_id']] = (int) $r['n'];
            }
        }
        $rows = $wpdb->get_results("SELECT id, name, json_data FROM `{$ops}` ORDER BY id", ARRAY_A);

        // Same name, same company: group, and let the fullest record lead.
        $groups = array();
        foreach ((array) $rows as $r) {
            $key = kop_facility_pages_name_key(kop_operator_pages_display_name($r['name']));
            if ($key === '') continue;
            $groups[$key][] = array(
                'id'    => (int) $r['id'],
                'name'  => trim((string) $r['name']),
                'score' => ($counts[(int) $r['id']] ?? 0) * 100000 + strlen((string) $r['json_data']),
                'op'    => kop_operator_pages_decode($r['json_data']),
            );
        }

        $taken = array();
        foreach ($groups as $key => $members) {
            usort($members, function ($a, $b) {
                return $b['score'] - $a['score'] ?: $a['id'] - $b['id'];
            });
            $lead = $members[0];
            $display = kop_operator_pages_display_name($lead['name']);
            $slug = sanitize_title($display);
            if ($slug === '' || isset($taken[$slug])) $slug = trim($slug . '-' . $lead['id'], '-');
            $taken[$slug] = true;

            $member_ids = array();
            foreach ($members as $m) {
                $member_ids[] = $m['id'];
                if ($m['id'] !== $lead['id']) $index['alias_of'][$m['id']] = $lead['id'];
            }
            $index['ids'][$lead['id']] = array(
                'slug'    => $slug,
                'name'    => $lead['name'],
                'display' => $display,
                'members' => $member_ids,
            );
            $index['slugs'][$slug] = $lead['id'];

            // Every name a reader might use for it.
            $names = array($lead['name'], $display, kop_operator_pages_abbreviation($lead['name']));
            foreach ($members as $m) {
                foreach ((array) ($m['op']['otherNames'] ?? array()) as $n) $names[] = $n;
                if (!empty($m['op']['currentName'])) $names[] = $m['op']['currentName'];
            }
            foreach ($names as $n) {
                $nk = kop_facility_pages_name_key($n);
                if ($nk !== '' && !isset($index['names'][$nk])) $index['names'][$nk] = $lead['id'];
            }
        }
        return $index;
    }
}

if (!function_exists('kop_operator_pages_flush_index')) {
    function kop_operator_pages_flush_index() {
        delete_transient('kop_operator_pages_index');
    }
}

// ---------------------------------------------------------------------------
// Links
// ---------------------------------------------------------------------------

if (!function_exists('kop_operator_pages_url_for_slug')) {
    function kop_operator_pages_url_for_slug($slug) {
        return home_url('/' . kop_operator_pages_base() . '/' . $slug . '/');
    }
}

if (!function_exists('kop_operator_page_url')) {
    /** The page for a kop_operators id (a duplicate record resolves to its canonical page), or ''. */
    function kop_operator_page_url($operator_id) {
        $operator_id = (int) $operator_id;
        if ($operator_id <= 0) return '';
        $index = kop_operator_pages_index();
        if (isset($index['alias_of'][$operator_id])) $operator_id = (int) $index['alias_of'][$operator_id];
        return isset($index['ids'][$operator_id]) ? kop_operator_pages_url_for_slug($index['ids'][$operator_id]['slug']) : '';
    }
}

if (!function_exists('kop_operator_page_url_for_name')) {
    /**
     * The page for an operator named in free text ("UHS", "Three Springs
     * Inc.", "WWASPS"), matched on the normalized name, its abbreviation and
     * its other names. '' when no operator has that name.
     */
    function kop_operator_page_url_for_name($name) {
        $key = kop_facility_pages_name_key($name);
        if ($key === '') return '';
        $index = kop_operator_pages_index();
        if (isset($index['names'][$key])) return kop_operator_page_url($index['names'][$key]);
        // "Three Springs" for "Three Springs Inc.", "Straight" for "Straight Inc."
        $trimmed = trim(preg_replace('/\b(inc|llc|corp|corporation|company|co|ltd)\b\.?$/', '', $key));
        foreach ($index['names'] as $nk => $id) {
            $nk_trimmed = trim(preg_replace('/\b(inc|llc|corp|corporation|company|co|ltd)\b\.?$/', '', $nk));
            if ($nk_trimmed !== '' && $nk_trimmed === $trimmed) return kop_operator_page_url($id);
        }
        return '';
    }
}

if (!function_exists('kop_operator_pages_network_url')) {
    /** The network map opened on this company, or '' when the map does not draw it. */
    function kop_operator_pages_network_url(array $names) {
        if (!function_exists('kop_network_map_graph')) return '';
        $graph = kop_network_map_graph();
        if (!$graph) return '';
        $want = array();
        foreach ($names as $n) {
            $k = kop_facility_pages_name_key($n);
            if ($k !== '') $want[$k] = true;
        }
        foreach ($graph['nodes'] as $node) {
            if (($node['kind'] ?? '') === 'facility' || ($node['kind'] ?? '') === 'person') continue;
            $candidates = array_merge(array((string) ($node['name'] ?? '')), (array) ($node['aliases'] ?? array()));
            foreach ($candidates as $c) {
                if (isset($want[kop_facility_pages_name_key($c)])) {
                    $page = function_exists('kop_facility_pages_page_url_by_template')
                        ? kop_facility_pages_page_url_by_template('page-network-map.php', '/network-map/')
                        : home_url('/network-map/');
                    return $page . '#open=' . rawurlencode((string) $node['id']);
                }
            }
        }
        return '';
    }
}

// ---------------------------------------------------------------------------
// View model
// ---------------------------------------------------------------------------

if (!function_exists('kop_operator_pages_people')) {
    /** "Name, role" lines from a keyStaff list. */
    function kop_operator_pages_people($list) {
        $out = array();
        foreach ((array) $list as $p) {
            if (is_string($p)) {
                if (trim($p) !== '') $out[] = trim($p);
                continue;
            }
            if (!is_array($p)) continue;
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '') continue;
            $role = trim((string) ($p['role'] ?? ''));
            $out[] = $role !== '' ? $name . ', ' . $role : $name;
        }
        return $out;
    }
}

if (!function_exists('kop_operator_page_data')) {
    /** Everything templates/operator-page.php prints, or null. */
    function kop_operator_page_data($operator_id) {
        global $wpdb;
        $index = kop_operator_pages_index();
        $operator_id = (int) $operator_id;
        if (!isset($index['ids'][$operator_id])) return null;
        $entry = $index['ids'][$operator_id];
        $ops = $wpdb->prefix . 'kop_operators';
        $ofc = $wpdb->prefix . 'kop_operator_facilities';

        $members = array_map('intval', $entry['members']);
        $in = implode(',', $members);
        $rows = $wpdb->get_results("SELECT id, name, json_data, document_folder_id, updated_at FROM `{$ops}` WHERE id IN ({$in})", ARRAY_A);
        $lead = null;
        $folder = 0;
        $updated = '';
        foreach ((array) $rows as $r) {
            if ((int) $r['id'] === $operator_id) $lead = $r;
            if (!$folder && !empty($r['document_folder_id'])) $folder = (int) $r['document_folder_id'];
            if ((string) $r['updated_at'] > $updated) $updated = (string) $r['updated_at'];
        }
        if (!$lead) return null;
        $op = kop_operator_pages_decode($lead['json_data']);
        $name = $entry['display'];

        // ---- Facts ---------------------------------------------------------
        $text = function ($v) { return trim((string) $v); };
        $facts = array();
        $add = function ($label, $value) use (&$facts) {
            if (is_array($value)) {
                $value = array_values(array_filter(array_map('trim', array_map('strval', $value)), 'strlen'));
                if (!$value) return;
            } elseif (trim((string) $value) === '') {
                return;
            }
            $facts[] = array('label' => $label, 'value' => $value);
        };
        $hq = $text($op['headquarters'] ?? '');
        if ($hq === '') $hq = trim($text($op['headquartersCity'] ?? '') . ', ' . $text($op['headquartersState'] ?? ''), ', ');
        $loc = $text($op['location'] ?? '');
        if ($loc === '') $loc = trim($text($op['locationCity'] ?? '') . ', ' . $text($op['locationState'] ?? ''), ', ');
        $add('Headquarters', $hq !== '' ? $hq : $loc);
        $add('Founded', $op['founded'] ?? '');
        $add('Operating', $op['operatingPeriod'] ?? '');
        $staff = is_array($op['keyStaff'] ?? null) ? $op['keyStaff'] : array();
        $add('Chief executive', $staff['ceo'] ?? '');
        $add('Founders', kop_operator_pages_people($staff['founders'] ?? array()));
        $add('Executives', kop_operator_pages_people($staff['keyExecutives'] ?? array()));
        $add('Owners', kop_facility_pages_text_items($op['owners'] ?? null));
        $add('Investors', kop_facility_pages_text_items($op['investors'] ?? null));

        $status = $text($op['status'] ?? '');
        $aka = array_values(array_unique(array_filter(array_map('trim', array_map('strval', (array) ($op['otherNames'] ?? array()))), 'strlen')));
        $abbr = kop_operator_pages_abbreviation($entry['name']);
        if ($abbr !== '' && !in_array($abbr, $aka, true)) array_unshift($aka, $abbr);

        // ---- Parents and subsidiaries --------------------------------------
        $parents = array();
        foreach ((array) ($op['parentCompanies'] ?? array()) as $p) {
            $p = trim((string) $p);
            if ($p === '') continue;
            $parents[] = array('name' => $p, 'url' => kop_operator_page_url_for_name($p));
        }
        $subsidiaries = array();
        $my_keys = array();
        foreach (array_merge(array($entry['name'], $name, $abbr), $aka) as $n) {
            $k = kop_facility_pages_name_key($n);
            if ($k !== '') $my_keys[$k] = true;
        }
        foreach ((array) $wpdb->get_results("SELECT id, name, json_data FROM `{$ops}` ORDER BY name", ARRAY_A) as $r) {
            if (in_array((int) $r['id'], $members, true) || isset($index['alias_of'][(int) $r['id']])) continue;
            $o = kop_operator_pages_decode($r['json_data']);
            foreach ((array) ($o['parentCompanies'] ?? array()) as $p) {
                if (isset($my_keys[kop_facility_pages_name_key($p)])) {
                    $subsidiaries[] = array('name' => kop_operator_pages_display_name($r['name']), 'url' => kop_operator_page_url((int) $r['id']));
                    break;
                }
            }
        }

        // ---- Facilities ----------------------------------------------------
        $facilities = array();
        $facility_ids = array();
        $states = array();
        $open = 0;
        if (kop_facility_pages_table_exists($ofc)) {
            $frows = $wpdb->get_results(
                "SELECT DISTINCT f.id, f.name, f.city, f.state, f.country, f.status, f.start_year, f.end_year
                   FROM `{$ofc}` ofc JOIN facilities_v2 f ON f.id = ofc.facility_id
                  WHERE ofc.operator_id IN ({$in})
                  ORDER BY f.name, f.id",
                ARRAY_A
            );
            foreach ((array) $frows as $r) {
                $fid = (int) $r['id'];
                if (isset($facility_ids[$fid])) continue;
                $facility_ids[$fid] = true;
                $state = ($r['state'] && function_exists('kop_state_canonical_name')) ? (string) kop_state_canonical_name($r['state']) : (string) $r['state'];
                $country = (string) $r['country'];
                $place = trim(($r['city'] ? $r['city'] . ', ' : '') . ($state !== '' ? $state : ($country !== '' && $country !== 'United States' ? $country : '')), ', ');
                if ($state !== '') $states[$state] = true;
                elseif ($country !== '' && $country !== 'United States') $states[$country] = true;
                $fstatus = trim((string) $r['status']);
                if (strcasecmp($fstatus, 'Open') === 0) $open++;
                $years = '';
                if ($r['start_year'] || $r['end_year']) {
                    $years = trim(($r['start_year'] ?: '?') . ($r['end_year'] ? '-' . $r['end_year'] : (strcasecmp($fstatus, 'Open') === 0 ? '-present' : '')));
                }
                $url = kop_facility_page_url($fid);
                $facilities[] = array(
                    'id'     => $fid,
                    'name'   => (string) $r['name'],
                    'place'  => $place,
                    'status' => $fstatus,
                    'years'  => $years,
                    'url'    => $url !== '' ? $url : kop_facility_pages_location_search_url((string) $r['name']),
                    'has_page' => $url !== '',
                );
            }
        }
        // Open programs first, then the rest; alphabetical within each.
        usort($facilities, function ($a, $b) {
            $ao = strcasecmp($a['status'], 'Open') === 0 ? 0 : 1;
            $bo = strcasecmp($b['status'], 'Open') === 0 ? 0 : 1;
            return $ao - $bo ?: strcasecmp($a['name'], $b['name']);
        });

        // ---- News: linked to the company, or to one of its facilities --------
        $news = array();
        $seen_news = array();
        $links_table = $wpdb->prefix . 'kop_operator_links';
        if (kop_facility_pages_table_exists('news_submissions')) {
            $news_ids = array();
            if (kop_facility_pages_table_exists($links_table)) {
                foreach ((array) $wpdb->get_col("SELECT link_id FROM `{$links_table}` WHERE link_kind = 'news' AND operator_id IN ({$in})") as $nid) {
                    $news_ids[(int) $nid] = 'company';
                }
            }
            if ($facility_ids && kop_facility_pages_table_exists('news_facility_links')) {
                $fin = implode(',', array_keys($facility_ids));
                foreach ((array) $wpdb->get_col("SELECT news_id FROM news_facility_links WHERE facility_id IN ({$fin})") as $nid) {
                    if (!isset($news_ids[(int) $nid])) $news_ids[(int) $nid] = 'facility';
                }
            }
            if ($news_ids) {
                $nin = implode(',', array_map('intval', array_keys($news_ids)));
                $nrows = $wpdb->get_results(
                    "SELECT id, article_title, alternate_title, publication_name, publication_date, article_url, article_type, summary
                       FROM news_submissions WHERE id IN ({$nin}) AND status IN ('approved','published')
                      ORDER BY publication_date DESC, id DESC",
                    ARRAY_A
                );
                foreach ((array) $nrows as $r) {
                    if (isset($seen_news[(int) $r['id']])) continue;
                    $seen_news[(int) $r['id']] = true;
                    $news[] = array(
                        'title'      => trim((string) ($r['alternate_title'] ?: $r['article_title'])),
                        'outlet'     => trim((string) $r['publication_name']),
                        'date_label' => $r['publication_date'] ? kop_facility_pages_date_label($r['publication_date']) : '',
                        'url'        => (string) $r['article_url'],
                        'type'       => trim((string) $r['article_type']),
                        'summary'    => trim((string) $r['summary']),
                        'about'      => $news_ids[(int) $r['id']],
                    );
                }
            }
        }

        // ---- Lawsuits at its programs, or naming the company ----------------
        $lawsuits = array();
        if (kop_facility_pages_table_exists('lawsuits')) {
            $linked = array();
            if ($facility_ids && kop_facility_pages_table_exists('lawsuit_facility_links')) {
                $fin = implode(',', array_keys($facility_ids));
                foreach ((array) $wpdb->get_col("SELECT lawsuit_id FROM lawsuit_facility_links WHERE facility_id IN ({$fin})") as $lid) {
                    $linked[(int) $lid] = true;
                }
            }
            $lrows = $wpdb->get_results(
                "SELECT id, case_name, case_number, court, filing_date, status, outcome, summary, defendants, organizations_mentioned
                   FROM lawsuits WHERE publication_status IN ('approved','published')
                  ORDER BY filing_date DESC, id DESC",
                ARRAY_A
            );
            foreach ((array) $lrows as $r) {
                $hit = isset($linked[(int) $r['id']]);
                if (!$hit) {
                    foreach (array('defendants', 'organizations_mentioned') as $col) {
                        foreach (kop_facility_pages_mentioned_names($r[$col]) as $n) {
                            if (isset($my_keys[kop_facility_pages_name_key($n)])) { $hit = true; break 2; }
                        }
                    }
                }
                if (!$hit) continue;
                $lawsuits[] = array(
                    'case_name'   => trim((string) $r['case_name']),
                    'case_number' => trim((string) $r['case_number']),
                    'court'       => trim((string) $r['court']),
                    'year'        => $r['filing_date'] ? substr((string) $r['filing_date'], 0, 4) : '',
                    'status'      => ucfirst(str_replace('_', ' ', trim((string) $r['status']))),
                    'outcome'     => trim((string) $r['outcome']),
                    'summary'     => trim((string) $r['summary']),
                );
            }
        }

        // ---- Deaths on record at its programs (exact program name only) -----
        $memorials = array();
        if ($facilities && kop_facility_pages_table_exists('memorial_victims')) {
            $fkeys = array();
            foreach ($facilities as $f) $fkeys[kop_facility_pages_name_key($f['name'])] = $f['name'];
            $mrows = $wpdb->get_results(
                "SELECT id, name, age, program, date_of_death, date_precision, cause_of_death, kop_url
                   FROM memorial_victims WHERE publication_status = 'published'
                  ORDER BY date_of_death DESC, id DESC",
                ARRAY_A
            );
            foreach ((array) $mrows as $r) {
                $rk = kop_facility_pages_name_key($r['program']);
                if ($rk === '' || !isset($fkeys[$rk])) continue;
                $date = trim((string) $r['date_of_death']);
                $ts = $date !== '' ? strtotime($date) : false;
                $precision = trim((string) $r['date_precision']);
                $label = $date === '' ? '' : ($precision === 'year' ? ($ts ? date('Y', $ts) : substr($date, 0, 4)) : ($precision === 'month' ? ($ts ? date('F Y', $ts) : $date) : ($ts ? date('F j, Y', $ts) : $date)));
                $memorials[] = array(
                    'name'       => trim((string) $r['name']),
                    'age'        => ($r['age'] === null || $r['age'] === '') ? '' : (string) (int) $r['age'],
                    'program'    => $fkeys[$rk],
                    'date_label' => $label,
                    'cause'      => trim((string) $r['cause_of_death']),
                    'kop_url'    => trim((string) $r['kop_url']),
                );
            }
        }

        // ---- Websites, notes, documents -----------------------------------------
        $websites = array();
        foreach ((array) ($op['websites'] ?? array()) as $u) {
            $u = trim((string) $u);
            if ($u === '') continue;
            $websites[] = preg_match('#^https?://web\.archive\.org/#i', $u)
                ? array('url' => $u, 'label' => 'Archived website', 'live_url' => '', 'go_url' => '')
                : kop_facility_pages_archive_link($u);
        }
        $notes = array_merge(
            kop_facility_pages_text_items($op['notes'] ?? null),
            kop_facility_pages_text_items($op['fieldNotes'] ?? null)
        );
        $documents = kop_facility_pages_documents(array('documentFolderId' => $folder), $folder);

        // ---- Summary ---------------------------------------------------------
        $n = count($facilities);
        $k = count($states);
        $first = $name . ' is a parent company'
            . ($status !== '' && strcasecmp($status, 'Active') !== 0 ? ' (' . strtolower($status) . ')' : '')
            . ($hq !== '' ? ' based in ' . $hq : ($loc !== '' ? ' based in ' . $loc : ''))
            . '.';
        if ($n > 0) {
            $second = 'Kids Over Profits has records of ' . $n . ' ' . ($n === 1 ? 'program' : 'programs') . ' it has run'
                . ($k > 1 ? ' in ' . $k . ' states and countries' : ($k === 1 ? ' in ' . array_keys($states)[0] : ''))
                . ($open > 0 ? ', ' . $open . ' of them open' : '')
                . '.';
        } else {
            $second = 'No programs are linked to it in the database yet.';
        }
        $summary = $first . ' ' . $second;

        $url = kop_operator_pages_url_for_slug($entry['slug']);
        return array(
            'id'            => $operator_id,
            'name'          => $name,
            'full_name'     => $entry['name'],
            'url'           => $url,
            'aka'           => $aka,
            'current_name'  => $text($op['currentName'] ?? ''),
            'status'        => $status,
            'status_class'  => sanitize_html_class(strtolower($status)),
            'facts'         => $facts,
            'parents'       => $parents,
            'subsidiaries'  => $subsidiaries,
            'facilities'    => $facilities,
            'open_count'    => $open,
            'place_count'   => $k,
            'news'          => $news,
            'lawsuits'      => $lawsuits,
            'memorials'     => $memorials,
            'websites'      => $websites,
            'notes'         => $notes,
            'documents'     => $documents,
            'network_url'   => kop_operator_pages_network_url(array_merge(array($entry['name'], $name, $abbr), $aka)),
            'summary'       => $summary,
            'seo_title'     => $name . ' | Parent company profile | Kids Over Profits',
            'updated_label' => $updated !== '' ? date_i18n(get_option('date_format') ?: 'F j, Y', strtotime($updated) ?: time()) : '',
            'lawsuits_url'  => kop_facility_pages_page_url_by_template('page-lawsuits.php', '/lawsuits/'),
            'submit_url'    => home_url('/tti-data-submission/'),
        );
    }
}
