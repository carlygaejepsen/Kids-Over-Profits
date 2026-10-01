<?php
/**
 * Facility names for free-text lists: suggestions as you type, and links.
 *
 * Two public, read-only routes (nothing private leaves the server):
 *
 *   GET  kop/v1/facility-suggest?q=provo
 *        Real facility records matching the phrase, current names first, then
 *        past/other names ("Formerly X" / "Also known as X"), each with its
 *        city and state so same-named places can be told apart. Reuses
 *        kop_v2_search() (inc/facility-v2-readers.php). Backs the data forms'
 *        "facilityref" autocomplete (js/autocomplete.js): Refers young people
 *        to (providerDetails.ttiReferrals), knownReferrals, facilitiesReferred.
 *
 *   POST kop/v1/facility-links   {"names": ["Provo Canyon", ...]}
 *        name => /facility/<slug>/ URL for each name that resolves to exactly
 *        one facility record that has a generated page. One call per page
 *        load (js/provider-index.js), never one per name. The rules are in
 *        kop_facility_link_resolve(); a name that matches several records, or
 *        none, or a record without a page, gets no link.
 *
 * Stored lists stay arrays of plain strings.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/facility-suggest', array(
        'methods'             => 'GET',
        'callback'            => 'kop_facility_suggest_rest',
        'permission_callback' => '__return_true',
        'args'                => array(
            'q' => array('required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
        ),
    ));
    register_rest_route('kop/v1', '/facility-links', array(
        'methods'             => 'POST',
        'callback'            => 'kop_facility_links_rest',
        'permission_callback' => '__return_true',
    ));
});

if (!function_exists('kop_facility_suggest')) {
    /**
     * Up to $limit facility records for a phrase of 3+ characters:
     * [{name, place, status, hint}], where hint is "Formerly X" / "Also known
     * as X" for a past/other-name hit and '' for a current-name hit.
     */
    function kop_facility_suggest($phrase, $limit = 8) {
        $phrase = trim((string) preg_replace('/\s+/u', ' ', (string) $phrase));
        if (mb_strlen($phrase) < 3 || mb_strlen($phrase) > 100) return array();
        $limit = max(1, min(15, (int) $limit));

        $cache_key = 'kop_fsug_' . md5(mb_strtolower($phrase) . '|' . $limit);
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;

        $found = kop_v2_search($phrase, $limit, 0, 0);
        $out = array();
        foreach ((array) ($found['facilities'] ?? array()) as $f) {
            $location = (string) ($f['location'] ?? '');
            $status = '';
            if (preg_match('/\s*\(([^()]*)\)\s*$/', $location, $m)) {
                $status = $m[1];
                $location = trim(substr($location, 0, -strlen($m[0])));
            }
            $out[] = array(
                'name'   => (string) $f['display'],
                'place'  => $location,
                'status' => $status,
                'hint'   => function_exists('kop_v2_search_alias_hint') ? kop_v2_search_alias_hint($f) : '',
            );
        }
        set_transient($cache_key, $out, 10 * MINUTE_IN_SECONDS);
        return $out;
    }
}

if (!function_exists('kop_facility_suggest_rest')) {
    function kop_facility_suggest_rest($request) {
        $q = trim((string) $request->get_param('q'));
        $res = new WP_REST_Response(array('query' => $q, 'items' => kop_facility_suggest($q)), 200);
        $res->header('Cache-Control', 'public, max-age=300');
        return $res;
    }
}

// ---------------------------------------------------------------------------
// Links
// ---------------------------------------------------------------------------

if (!function_exists('kop_facility_link_split_list')) {
    /**
     * One list entry as the names it holds. An entry with two or more commas is
     * a pasted list ("Cinnamon Hills,  Copper Hills,  Provo Canyon"), the shape
     * the Billings Clinic record was entered in, and is split on the commas;
     * anything else is one name. js/provider-index.js splits the same way.
     */
    function kop_facility_link_split_list($entry) {
        $entry = trim((string) $entry);
        if ($entry === '') return array();
        if (substr_count($entry, ',') < 2) return array($entry);
        $parts = preg_split('/\s*,\s*/u', $entry);
        return array_values(array_filter(array_map('trim', $parts), 'strlen'));
    }
}

if (!function_exists('kop_facility_link_key')) {
    /** Case- and punctuation-insensitive key: "St. Mary's Ranch" == "st marys ranch". */
    function kop_facility_link_key($name) {
        $name = trim((string) $name);
        if ($name === '') return '';
        $key = kop_facility_pages_name_key($name);
        return trim(preg_replace('/\s+/', ' ', (string) $key));
    }
}

if (!function_exists('kop_facility_link_name_ids')) {
    /**
     * name key => [facilities_v2 ids] over every record's name, name_key,
     * unique_name, identification.name/currentName and pastNames/otherNames.
     * Built once per request (one pass over facilities_v2).
     */
    function kop_facility_link_name_ids($force = false) {
        global $wpdb;
        static $map = null;
        if ($map !== null && !$force) return $map;
        $map = array();
        $rows = $wpdb->get_results("SELECT id, name, name_key, unique_name, json_data FROM facilities_v2 ORDER BY id", ARRAY_A);
        foreach ((array) $rows as $row) {
            $id = (int) $row['id'];
            $names = array($row['name'], $row['name_key'], $row['unique_name']);
            $doc = kop_v2_decode($row['json_data']);
            if (is_array($doc)) {
                $ident = isset($doc['identification']) && is_array($doc['identification']) ? $doc['identification'] : array();
                $names[] = $ident['name'] ?? '';
                $names[] = $ident['currentName'] ?? '';
                foreach (array('pastNames', 'otherNames') as $field) {
                    foreach (kop_v2_search_name_list($ident[$field] ?? null) as $n) $names[] = $n;
                }
            }
            foreach ($names as $n) {
                if (!is_string($n)) continue;
                $key = kop_facility_link_key($n);
                if ($key === '') continue;
                $map[$key][$id] = true;
            }
        }
        foreach ($map as $key => $ids) $map[$key] = array_keys($ids);
        return $map;
    }
}

if (!function_exists('kop_facility_link_resolve')) {
    /**
     * Resolve free-text names to facility pages. Returns name =>
     * {status, url, matches}, status being:
     *   linked     exactly one record carries the name and it has a page
     *   ambiguous  two or more records carry it: never guessed
     *   nopage     exactly one record, which has no generated page
     *   unmatched  no record carries it
     * `url` is set only for "linked". Results are cached for a day, keyed by
     * the page index's fingerprint, so a record change or a new page rebuilds
     * them.
     */
    function kop_facility_link_resolve(array $names) {
        $clean = array();
        foreach ($names as $n) {
            if (!is_string($n)) continue;
            $n = trim($n);
            if ($n === '' || mb_strlen($n) > 200) continue;
            $clean[$n] = true;
        }
        $clean = array_slice(array_keys($clean), 0, 300);
        if (!$clean) return array();

        $keys = array_map('kop_facility_link_key', $clean);
        sort($keys);
        $fingerprint = function_exists('kop_facility_pages_fingerprint') ? kop_facility_pages_fingerprint() : '';
        $cache_key = 'kop_flr_' . md5($fingerprint . '|' . implode('|', $keys));
        $cached = $fingerprint !== '' ? get_transient($cache_key) : false;
        if (is_array($cached) && isset($cached['byKey'])) {
            $by_key = $cached['byKey'];
        } else {
            $map = kop_facility_link_name_ids();
            $by_key = array();
            foreach ($clean as $n) {
                $key = kop_facility_link_key($n);
                if ($key === '' || isset($by_key[$key])) continue;
                $ids = isset($map[$key]) ? $map[$key] : array();
                if (!$ids) {
                    $by_key[$key] = array('status' => 'unmatched', 'url' => '', 'matches' => 0);
                } elseif (count($ids) > 1) {
                    $by_key[$key] = array('status' => 'ambiguous', 'url' => '', 'matches' => count($ids));
                } else {
                    $url = kop_facility_page_url((int) $ids[0]);
                    $by_key[$key] = array('status' => $url !== '' ? 'linked' : 'nopage', 'url' => $url, 'matches' => 1);
                }
            }
            if ($fingerprint !== '') set_transient($cache_key, array('byKey' => $by_key), DAY_IN_SECONDS);
        }

        $out = array();
        foreach ($clean as $n) {
            $key = kop_facility_link_key($n);
            $out[$n] = isset($by_key[$key]) ? $by_key[$key] : array('status' => 'unmatched', 'url' => '', 'matches' => 0);
        }
        return $out;
    }
}

if (!function_exists('kop_facility_links_rest')) {
    function kop_facility_links_rest($request) {
        $body = $request->get_json_params();
        $names = is_array($body) && isset($body['names']) && is_array($body['names']) ? $body['names'] : array();
        $links = array();
        // Every name's status too (linked / ambiguous / nopage / unmatched):
        // the data forms mark names to re-pick from the suggestions.
        $statuses = array();
        foreach (kop_facility_link_resolve($names) as $name => $r) {
            if ($r['status'] === 'linked') $links[$name] = $r['url'];
            $statuses[$name] = $r['status'];
        }
        return new WP_REST_Response(array('links' => (object) $links, 'statuses' => (object) $statuses), 200);
    }
}
