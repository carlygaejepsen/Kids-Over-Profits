<?php
/**
 * Network map data access.
 *
 * The map page needs two things from PHP that the browser cannot work out
 * for itself: the filter vocabulary (kinds, edge categories, chains, board
 * regions) so the filter rail is real HTML before any script runs, and the
 * profile URL for every facility the graph could link to.
 *
 * Both are derived from js/data/network/graph.json, which is 750 KB, so both
 * are cached in transients. The graph cache is keyed on the file's mtime and
 * size; the URL map is additionally keyed on the facility page index's own
 * fingerprint, so publishing a facility page invalidates it.
 *
 * @package KidsOverProfits
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_network_map_file')) {
    /** Absolute path to one of the network data files. */
    function kop_network_map_file($name) {
        return trailingslashit(get_stylesheet_directory()) . 'js/data/network/' . $name;
    }
}

if (!function_exists('kop_network_map_url')) {
    /** Cache-busted URL for one of the network data files, or '' if absent. */
    function kop_network_map_url($name) {
        $path = kop_network_map_file($name);
        if (!file_exists($path)) {
            return '';
        }
        return add_query_arg(
            'v',
            (string) filemtime($path),
            trailingslashit(get_stylesheet_directory_uri()) . 'js/data/network/' . $name
        );
    }
}

if (!function_exists('kop_network_map_graph')) {
    /**
     * The decoded graph, or null when it is missing or unreadable. Held for
     * the rest of the request: the template and the enqueue block both want
     * it and it is not cheap to decode twice.
     */
    function kop_network_map_graph() {
        static $graph = null;
        static $tried = false;
        if ($tried) {
            return $graph;
        }
        $tried = true;

        $path = kop_network_map_file('graph.json');
        if (!file_exists($path)) {
            return $graph = null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || empty($decoded['nodes'])) {
            return $graph = null;
        }
        return $graph = $decoded;
    }
}

if (!function_exists('kop_network_map_cache_key')) {
    /** Identifies a build of graph.json without decoding it. */
    function kop_network_map_cache_key() {
        $path = kop_network_map_file('graph.json');
        if (!file_exists($path)) {
            return '';
        }
        return substr(md5(filemtime($path) . ':' . filesize($path)), 0, 12);
    }
}

if (!function_exists('kop_network_map_meta')) {
    /**
     * The filter vocabulary and counts, as the build script recorded them.
     * Shape: kinds, categories, chains, regions, counts, sourceHash. Returns
     * empty arrays when the graph is missing so the template can render an
     * honest "data not built yet" state rather than fatal.
     */
    function kop_network_map_meta() {
        $empty = array(
            'kinds'      => array(),
            'categories' => array(),
            'chains'     => array(),
            'regions'    => array(),
            'counts'     => array(),
            'sourceHash' => '',
        );

        $key = kop_network_map_cache_key();
        if ($key === '') {
            return $empty;
        }

        $cached = get_transient('kop_network_map_meta');
        if (is_array($cached) && ($cached['_key'] ?? '') === $key) {
            unset($cached['_key']);
            return $cached;
        }

        $graph = kop_network_map_graph();
        if (!$graph || empty($graph['meta'])) {
            return $empty;
        }
        $meta = $graph['meta'];

        $out = array(
            'kinds'      => isset($meta['kinds']) ? (array) $meta['kinds'] : array(),
            'categories' => isset($meta['categories']) ? (array) $meta['categories'] : array(),
            'chains'     => isset($meta['chains']) ? (array) $meta['chains'] : array(),
            'regions'    => isset($meta['regions']) ? (array) $meta['regions'] : array(),
            'counts'     => isset($meta['counts']) ? (array) $meta['counts'] : array(),
            'sourceHash' => isset($meta['sourceHash']) ? (string) $meta['sourceHash'] : '',
        );

        set_transient('kop_network_map_meta', array_merge($out, array('_key' => $key)), WEEK_IN_SECONDS);
        return $out;
    }
}

if (!function_exists('kop_network_map_facility_urls')) {
    /**
     * Facility id => profile URL, for every graph node that links to a
     * facilities_v2 record with a page. Ids with no page are left out
     * entirely, so the drawer can treat "present" as "safe to link" and fall
     * back to a directory search otherwise.
     */
    function kop_network_map_facility_urls() {
        if (!function_exists('kop_facility_page_url')) {
            return array();
        }

        $key = kop_network_map_cache_key();
        if ($key === '') {
            return array();
        }
        // Publishing or unpublishing a facility page changes the index
        // fingerprint, which has to invalidate this map even though
        // graph.json has not moved.
        if (function_exists('kop_facility_pages_index')) {
            $index = kop_facility_pages_index();
            $key .= ':' . substr((string) ($index['fingerprint'] ?? ''), 0, 12);
        }

        $cached = get_transient('kop_network_map_facility_urls');
        if (is_array($cached) && ($cached['_key'] ?? '') === $key) {
            unset($cached['_key']);
            return $cached;
        }

        $graph = kop_network_map_graph();
        if (!$graph) {
            return array();
        }

        $urls = array();
        foreach ($graph['nodes'] as $node) {
            $id = isset($node['facilityId']) ? (int) $node['facilityId'] : 0;
            if ($id <= 0 || isset($urls[$id])) {
                continue;
            }
            $url = kop_facility_page_url($id);
            if ($url !== '') {
                $urls[$id] = $url;
            }
        }

        set_transient('kop_network_map_facility_urls', array_merge($urls, array('_key' => $key)), DAY_IN_SECONDS);
        return $urls;
    }
}

if (!function_exists('kop_network_map_config')) {
    /**
     * Everything the map scripts need that only PHP knows: where the data
     * files are (cache-busted), and which facilities have a profile page to
     * link to. Localised as KOP_NETWORK_CONFIG.
     */
    function kop_network_map_config() {
        // The location index, not the program index: it lists every facility.
        $directory = function_exists('kop_facility_pages_page_url_by_template')
            ? kop_facility_pages_page_url_by_template('page-location-index.php', '/location-index/')
            : home_url('/location-index/');

        return array(
            'graphUrl'     => kop_network_map_url('graph.json'),
            'layoutUrl'    => kop_network_map_url('layout.json'),
            'directoryUrl' => $directory,
            // The memorial, linked from the drawer beside a death count.
            'memorialUrl'  => function_exists('kop_facility_pages_page_url_by_template')
                ? kop_facility_pages_page_url_by_template('page-memorial.php', '/memorial/')
                : home_url('/memorial/'),
            // Facility id => profile URL. Ids absent from this map have no
            // page, so the drawer sends those to a directory search instead.
            'facilityUrls' => (object) kop_network_map_facility_urls(),
        );
    }
}

if (!function_exists('kop_network_map_flush')) {
    /** Drop the caches. Cheap to rebuild; both are keyed, so this is belt and braces. */
    function kop_network_map_flush() {
        delete_transient('kop_network_map_meta');
        delete_transient('kop_network_map_facility_urls');
    }
    add_action('kop_facility_v2_sync', 'kop_network_map_flush', 20);
}

if (!function_exists('kop_network_map_label')) {
    /**
     * Display label for a filter value. The stored vocabulary is lowercase
     * machine words ("parent", "corporate"); this is the only place that
     * decides how they read to a visitor.
     *
     * Kinds and categories share the key "other" and mean different things by
     * it, so the caller says which vocabulary it is reading from.
     */
    function kop_network_map_label($value, $context = 'kind') {
        $kinds = array(
            'person'      => 'People',
            'facility'    => 'Facilities',
            'parent'      => 'Parent companies',
            'association' => 'Trade groups',
            'government'  => 'Government bodies',
            'church'      => 'Churches',
            'other'       => 'Other',
        );
        $categories = array(
            'corporate'  => 'Ownership',
            'family'     => 'Family',
            'survivor'   => 'Survivor account',
            'referral'   => 'Referral',
            'board'      => 'Board member',
            'leadership' => 'Leadership',
            'clinical'   => 'Clinical staff',
            'admissions' => 'Admissions',
            'staff'      => 'Other staff',
            'other'      => 'Other connection',
            'unknown'    => 'Unrecorded',
        );
        $map = $context === 'category' ? $categories : $kinds;
        if (isset($map[$value])) {
            return $map[$value];
        }
        return ucfirst(str_replace('-', ' ', (string) $value));
    }
}
