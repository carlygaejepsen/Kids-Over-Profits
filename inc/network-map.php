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

if (!function_exists('kop_network_map_status_bucket')) {
    /** PHP twin of statusBucket() in js/network-map/store.js. */
    function kop_network_map_status_bucket($raw) {
        $s = strtolower(trim((string) $raw));
        if ($s === '') return 'unknown';
        if (strpos($s, 'open') === 0) return 'open';
        if ($s === 'rebranded') return 'rebranded';
        if (strpos($s, 'closed') === 0 || strpos($s, 'rebrand') !== false) return 'closed';
        return 'unknown';
    }
}

if (!function_exists('kop_network_map_status_overrides')) {
    /**
     * Facility id => 'closed' for every map name the board draws as open or
     * unrecorded whose facility is Closed in facilities_v2. graph.json is
     * built offline from the Miro board, so without this a facility closed
     * since the last build (a confirmed closure report, inc/closure-reports.php,
     * or an edit in the data form) would stay open on the map. One way only:
     * the board's closed and rebranded names are never reopened.
     *
     * Keyed on the graph build and facilities_v2's row count and last update,
     * so a status change shows on the next request.
     */
    function kop_network_map_status_overrides() {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }
        global $wpdb;
        $key = kop_network_map_cache_key();
        if ($key === '' || !function_exists('kop_facility_pages_table_exists') || !kop_facility_pages_table_exists('facilities_v2')) {
            return $memo = array();
        }
        $stamp = $wpdb->get_row('SELECT COUNT(*), MAX(updated_at) FROM facilities_v2', ARRAY_N);
        $key .= ':' . md5(implode('|', array_map('strval', (array) $stamp))) . ':v1';

        $cached = get_transient('kop_network_map_status_overrides');
        if (is_array($cached) && ($cached['_key'] ?? '') === $key) {
            return $memo = $cached['map'];
        }

        $ids = array();
        $graph = kop_network_map_graph();
        foreach ($graph ? $graph['nodes'] : array() as $node) {
            $fid = (int) ($node['facilityId'] ?? 0);
            $bucket = kop_network_map_status_bucket($node['status'] ?? '');
            if ($fid > 0 && ($bucket === 'open' || $bucket === 'unknown')) {
                $ids[$fid] = true;
            }
        }
        $map = array();
        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            foreach ((array) $wpdb->get_col("SELECT id FROM facilities_v2 WHERE status = 'Closed' AND id IN ({$in})") as $id) {
                $map[(int) $id] = 'closed';
            }
        }
        set_transient('kop_network_map_status_overrides', array('_key' => $key, 'map' => $map), DAY_IN_SECONDS);
        return $memo = $map;
    }
}

if (!function_exists('kop_network_map_apply_status_overrides')) {
    /** graph.json-shaped nodes with the overrides written into 'status'. */
    function kop_network_map_apply_status_overrides(array $nodes, array $overrides) {
        if (!$overrides) {
            return $nodes;
        }
        foreach ($nodes as $i => $node) {
            $fid = (int) ($node['facilityId'] ?? 0);
            if ($fid > 0 && isset($overrides[$fid])) {
                $nodes[$i]['status'] = $overrides[$fid];
            }
        }
        return $nodes;
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
            'views'      => array(),
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
            // Starter views, key and label only: the ids stay in graph.json.
            'views'      => array_map(static function ($view) {
                return array('key' => (string) ($view['key'] ?? ''), 'label' => (string) ($view['label'] ?? ''));
            }, isset($meta['views']) ? (array) $meta['views'] : array()),
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
        // Bumped when the shape of the cached array changes, so a stale
        // entry is not served until it expires.
        $key .= ':v2';

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

        // Not array_merge: it renumbers integer keys, and the keys are the
        // facility ids. The cached copy came back as 0, 1, 2..., so every
        // request after the first found no id it looked up and the drawer
        // never linked a profile (found on the live page, 2026-09-21).
        $cached = $urls;
        $cached['_key'] = $key;
        set_transient('kop_network_map_facility_urls', $cached, DAY_IN_SECONDS);
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
            // Facility id => status, for facilities closed since graph.json
            // was built; store.js writes them over the file's own status.
            'statusOverrides' => (object) kop_network_map_status_overrides(),
        );
    }
}

if (!function_exists('kop_network_map_flush')) {
    /** Drop the caches. Cheap to rebuild; both are keyed, so this is belt and braces. */
    function kop_network_map_flush() {
        delete_transient('kop_network_map_meta');
        delete_transient('kop_network_map_facility_urls');
        delete_transient('kop_network_map_facility_links');
        delete_transient('kop_network_map_status_overrides');
    }
    add_action('kop_facility_v2_sync', 'kop_network_map_flush', 20);
    add_action('kop_facility_status_changed', 'kop_network_map_flush', 20);
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
            'membership' => 'Member',
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

if (!function_exists('kop_network_map_facility_connections')) {
    /**
     * What the map records about each facility record it names, keyed by
     * facilities_v2 id: the node it is drawn as and every connection it has,
     *
     *   id => array('node' => 'provo-canyon-school', 'name' => ...,
     *               'links' => array(array('name', 'kind', 'node', 'facilityId',
     *                                      'category', 'role', 'relation'), ...))
     *
     * relation is '' for an ordinary line, or 'became', 'formerly',
     * 'acquired', 'acquired by' for the two directed kinds. The facility
     * pages read it twice: a facility on the map qualifies for a page, and
     * the page lists these connections. Cached against the graph build.
     */
    function kop_network_map_facility_connections() {
        static $memo = null;
        if ($memo !== null) {
            return $memo;
        }
        $key = kop_network_map_cache_key();
        if ($key === '') {
            return $memo = array();
        }
        $key .= ':v2';
        $cached = get_transient('kop_network_map_facility_links');
        if (is_array($cached) && ($cached['_key'] ?? '') === $key && isset($cached['map'])) {
            return $memo = $cached['map'];
        }

        $graph = kop_network_map_graph();
        if (!$graph) {
            return $memo = array();
        }
        $nodes = array();
        foreach ($graph['nodes'] as $node) {
            $nodes[(string) $node['id']] = $node;
        }
        $map = array();
        $node_for = array();
        // Several map names can be one record (a program and its tracks, two
        // spellings); the record gets every one of their connections, and the
        // first name is the one the page links to on the map.
        foreach ($nodes as $id => $node) {
            $fid = isset($node['facilityId']) ? (int) $node['facilityId'] : 0;
            if ($fid <= 0) {
                continue;
            }
            if (!isset($map[$fid])) {
                $map[$fid] = array('node' => $id, 'name' => (string) $node['name'], 'links' => array());
            }
            $node_for[$id] = $fid;
        }
        foreach ((array) ($graph['edges'] ?? array()) as $edge) {
            foreach (array('source', 'target') as $end) {
                $here = (string) $edge[$end];
                if (!isset($node_for[$here])) {
                    continue;
                }
                $there = (string) $edge[$end === 'source' ? 'target' : 'source'];
                if (!isset($nodes[$there])) {
                    continue;
                }
                $other = $nodes[$there];
                if (isset($node_for[$there]) && $node_for[$there] === $node_for[$here]) {
                    continue; // two names for the same record
                }
                $relation = '';
                $direction = (string) ($edge['direction'] ?? 'none');
                if ($direction === 'renamed') {
                    $relation = $end === 'source' ? 'became' : 'formerly';
                } elseif ($direction === 'acquirer') {
                    $relation = $end === 'source' ? 'acquired' : 'acquired by';
                }
                $roles = array_filter(array_map('strval', (array) ($edge['roles'] ?? array())), static function ($r) {
                    return trim($r) !== '' && strcasecmp(trim($r), 'affiliated') !== 0;
                });
                $map[$node_for[$here]]['links'][] = array(
                    'name'       => (string) $other['name'],
                    'kind'       => (string) ($other['kind'] ?? ''),
                    'node'       => $there,
                    'facilityId' => isset($other['facilityId']) ? (int) $other['facilityId'] : 0,
                    'category'   => (string) ($edge['category'] ?? 'unknown'),
                    'role'       => implode(' / ', $roles),
                    'relation'   => $relation,
                );
            }
        }
        set_transient('kop_network_map_facility_links', array('_key' => $key, 'map' => $map), WEEK_IN_SECONDS);
        return $memo = $map;
    }
}

if (!function_exists('kop_network_map_layout')) {
    /** The decoded layout.json positions, id => {x, y, r}; held for the request. */
    function kop_network_map_layout() {
        static $positions = null;
        if ($positions !== null) {
            return $positions;
        }
        $path = kop_network_map_file('layout.json');
        $decoded = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
        return $positions = (is_array($decoded) && isset($decoded['positions'])) ? (array) $decoded['positions'] : array();
    }
}

if (!function_exists('kop_network_map_slice_index')) {
    /**
     * The graph's nodes by id and its adjacency, over the nodes the layout
     * places (store.hydrate() drops the rest). The slice functions share it.
     */
    function kop_network_map_slice_index(array $graph, array $positions) {
        $nodes = array();
        foreach ($graph['nodes'] as $node) {
            $nodes[(string) $node['id']] = $node;
        }
        $adjacent = array();
        foreach ((array) ($graph['edges'] ?? array()) as $edge) {
            $s = (string) $edge['source'];
            $t = (string) $edge['target'];
            if (!isset($nodes[$s], $nodes[$t], $positions[$s], $positions[$t])) {
                continue;
            }
            $adjacent[$s][] = array('other' => $t, 'edge' => $edge, 'outgoing' => true);
            $adjacent[$t][] = array('other' => $s, 'edge' => $edge, 'outgoing' => false);
        }
        return array($nodes, $adjacent);
    }
}

if (!function_exists('kop_network_map_slice_opened')) {
    /**
     * The names the map shows around one opened name: visibleIds() in
     * js/network-map/focus.js for a trail of one, every filter at its
     * default (all on). The root and everything it touches; everyone a
     * person among those touches; every place those people connect to; and
     * the company that owns each program on it. With more than $cap direct
     * connections, only the $cap most important stay and $more says how
     * many went. id => true.
     */
    function kop_network_map_slice_opened(array $nodes, array $adjacent, $root, $cap, &$more) {
        $kind = static function ($id) use ($nodes) {
            return (string) ($nodes[$id]['kind'] ?? '');
        };
        $first = array();
        foreach ($adjacent[$root] ?? array() as $link) {
            $first[$link['other']] = true;
        }
        $more = 0;
        if (count($first) > $cap) {
            $ranked = array_keys($first);
            usort($ranked, static function ($a, $b) use ($nodes) {
                $ia = (float) ($nodes[$a]['importance'] ?? 0);
                $ib = (float) ($nodes[$b]['importance'] ?? 0);
                return $ib <=> $ia ?: strcmp($a, $b);
            });
            $more = count($ranked) - $cap;
            $first = array_fill_keys(array_slice($ranked, 0, $cap), true);
        }

        $asked = array($root => true) + $first;
        // People open out by one step (a snapshot, so a person brought by a
        // person does not bring more)...
        foreach (array_keys($asked) as $id) {
            if ($kind($id) !== 'person') continue;
            foreach ($adjacent[$id] ?? array() as $link) {
                $asked[$link['other']] = true;
            }
        }
        // ...and nobody is on the map without their places.
        kop_network_map_slice_with_places($nodes, $adjacent, $asked);
        // The company that owns each program, one step up.
        foreach (array_keys($asked) as $id) {
            if ($kind($id) !== 'facility') continue;
            foreach ($adjacent[$id] ?? array() as $link) {
                $edge = $link['edge'];
                $direction = (string) ($edge['direction'] ?? 'none');
                if ($kind($link['other']) !== 'parent' || ($edge['category'] ?? '') !== 'corporate' || $direction === 'renamed') continue;
                if ($link['outgoing'] && $direction !== 'none') continue;
                $asked[$link['other']] = true;
            }
        }
        if ($more) {
            // A capped root's dropped connections stay off the stage even
            // where a person or an owner would have brought them.
            foreach ($adjacent[$root] as $link) {
                if (!isset($first[$link['other']])) unset($asked[$link['other']]);
            }
        }
        return $asked;
    }
}

if (!function_exists('kop_network_map_slice_with_places')) {
    /** withTheirPlaces() in focus.js: every place and company the people in $set connect to. */
    function kop_network_map_slice_with_places(array $nodes, array $adjacent, array &$set) {
        foreach (array_keys($set) as $id) {
            if (($nodes[$id]['kind'] ?? '') !== 'person') continue;
            foreach ($adjacent[$id] ?? array() as $link) {
                if (($nodes[$link['other']]['kind'] ?? '') !== 'person') {
                    $set[$link['other']] = true;
                }
            }
        }
    }
}

if (!function_exists('kop_network_map_slice_pack')) {
    /**
     * A set of names as graph.json-shaped data the map's store takes as it
     * takes the whole file: the nodes in graph.json's order (the layout
     * breaks ties by it, so another order settles the same names
     * elsewhere), every edge among them, their layout positions, and meta
     * with $views as its only starter views. Each node carries offSlice,
     * how many of its connections the slice left out, which focus.js adds
     * to the "+N" so a name says what it says on the full map.
     */
    function kop_network_map_slice_pack(array $graph, array $nodes, array $adjacent, array $positions, array $ids, array $views) {
        $out_nodes = array();
        $out_positions = array();
        foreach ($nodes as $id => $node) {
            if (!isset($ids[$id]) || !isset($positions[$id])) continue;
            $off = 0;
            foreach ($adjacent[$id] ?? array() as $link) {
                if (!isset($ids[$link['other']])) $off++;
            }
            if ($off) {
                $node['offSlice'] = $off;
            }
            $out_nodes[] = $node;
            $out_positions[$id] = $positions[$id];
        }
        $out_edges = array();
        foreach ((array) ($graph['edges'] ?? array()) as $edge) {
            if (isset($out_positions[(string) $edge['source']], $out_positions[(string) $edge['target']])) {
                $out_edges[] = $edge;
            }
        }
        // The renderer reads the board colours and the chain order from meta.
        $meta = (array) ($graph['meta'] ?? array());
        $meta['views'] = $views;
        $meta['headline'] = array();
        return array(
            'nodes'  => $out_nodes,
            'edges'  => $out_edges,
            'meta'   => $meta,
            'layout' => array('positions' => $out_positions),
        );
    }
}

if (!function_exists('kop_network_map_slice_from_graph')) {
    /**
     * The part of the graph the map draws when a name is opened in Focus
     * mode (Phase 4.1, the facility page embed): 'root', 'more' (names the
     * cap left out) and graph.json's own shape. scripts/test-network-embed.js
     * holds it to what focus.js shows for the same name on the full map, for
     * every facility on the map, so change the two together. Null for a
     * name the graph or the layout lacks.
     */
    function kop_network_map_slice_from_graph(array $graph, array $positions, $root, $cap = 40) {
        $root = (string) $root;
        list($nodes, $adjacent) = kop_network_map_slice_index($graph, $positions);
        if (!isset($nodes[$root]) || !isset($positions[$root])) {
            return null;
        }
        $more = 0;
        $ids = kop_network_map_slice_opened($nodes, $adjacent, $root, $cap, $more);
        return array('root' => $root, 'more' => $more)
            + kop_network_map_slice_pack($graph, $nodes, $adjacent, $positions, $ids, array());
    }
}

if (!function_exists('kop_network_map_view_slice_from_graph')) {
    /**
     * The board a starter view opens on (the history hub's preview, 4.4):
     * 'view' and graph.json's shape, the view as meta's only view so the
     * store opens on it. As visibleIds() with no trail: the view's own
     * names and the places of the people among them, and when the view is
     * opened on one organisation (root), everything opening it brings.
     * Held to focus.js for every view by scripts/test-network-embed.js.
     */
    function kop_network_map_view_slice_from_graph(array $graph, array $positions, $key) {
        $view = null;
        foreach ((array) ($graph['meta']['views'] ?? array()) as $candidate) {
            if (($candidate['key'] ?? '') === (string) $key) $view = $candidate;
        }
        if (!$view) {
            return null;
        }
        list($nodes, $adjacent) = kop_network_map_slice_index($graph, $positions);
        $ids = array();
        $root = (string) ($view['root'] ?? '');
        if ($root !== '' && isset($nodes[$root], $positions[$root])) {
            $more = 0;
            $ids = kop_network_map_slice_opened($nodes, $adjacent, $root, PHP_INT_MAX, $more);
        }
        $opening = array();
        foreach ((array) ($view['ids'] ?? array()) as $id) {
            if (isset($nodes[$id], $positions[$id])) $opening[$id] = true;
        }
        if (!$opening && !$ids) {
            return null;
        }
        kop_network_map_slice_with_places($nodes, $adjacent, $opening);
        $ids += $opening;
        return array('view' => (string) $key)
            + kop_network_map_slice_pack($graph, $nodes, $adjacent, $positions, $ids, array($view));
    }
}

if (!function_exists('kop_network_map_cached_slice')) {
    /**
     * A slice for the deployed graph, cached against the graph build and the
     * facility page index, with the profile page URL of every name in it
     * that has one ('urls', [id, URL] pairs for Ctrl-click; not an array
     * keyed by id, which PHP would renumber for an id that looks like a
     * number). $kind is 'node' or 'view'.
     */
    function kop_network_map_cached_slice($kind, $key) {
        $cache_key = kop_network_map_cache_key();
        if ($cache_key === '' || (string) $key === '') {
            return null;
        }
        if (function_exists('kop_facility_pages_index')) {
            $index = kop_facility_pages_index();
            $cache_key .= ':' . substr((string) ($index['fingerprint'] ?? ''), 0, 12);
        }
        $overrides = kop_network_map_status_overrides();
        $cache_key .= ':' . substr(md5(wp_json_encode($overrides)), 0, 8) . ':v2';
        $transient = 'kop_nm_slice_' . md5($kind . ':' . $key);
        $cached = get_transient($transient);
        if (is_array($cached) && ($cached['_key'] ?? '') === $cache_key) {
            return $cached['slice'];
        }
        $graph = kop_network_map_graph();
        $slice = null;
        if ($graph) {
            $slice = $kind === 'view'
                ? kop_network_map_view_slice_from_graph($graph, kop_network_map_layout(), $key)
                : kop_network_map_slice_from_graph($graph, kop_network_map_layout(), $key);
        }
        if ($slice) {
            $slice['nodes'] = kop_network_map_apply_status_overrides($slice['nodes'], $overrides);
            $urls = function_exists('kop_network_map_facility_urls') ? kop_network_map_facility_urls() : array();
            $slice['urls'] = array();
            foreach ($slice['nodes'] as $node) {
                $fid = (int) ($node['facilityId'] ?? 0);
                if ($fid > 0 && isset($urls[$fid])) {
                    $slice['urls'][] = array((string) $node['id'], $urls[$fid]);
                }
            }
        }
        set_transient($transient, array('_key' => $cache_key, 'slice' => $slice), WEEK_IN_SECONDS);
        return $slice;
    }
}

if (!function_exists('kop_network_map_slice')) {
    /** The facility page slice for one map name; see kop_network_map_slice_from_graph(). */
    function kop_network_map_slice($node_id) {
        return kop_network_map_cached_slice('node', (string) $node_id);
    }
}

if (!function_exists('kop_network_map_view_slice')) {
    /** A starter view's slice; see kop_network_map_view_slice_from_graph(). */
    function kop_network_map_view_slice($key) {
        return kop_network_map_cached_slice('view', (string) $key);
    }
}

if (!function_exists('kop_network_map_page_url')) {
    function kop_network_map_page_url() {
        return function_exists('kop_facility_pages_page_url_by_template')
            ? kop_facility_pages_page_url_by_template('page-network-map.php', '/network-map/')
            : home_url('/network-map/');
    }
}

if (!function_exists('kop_network_map_embed_shell')) {
    /**
     * The embed's markup, shared by a facility page and the preview
     * shortcode: a figure holding the slice as JSON, which
     * js/network-map/embed.js paints. The canvas stage is hidden until the
     * first paint. With a still ($still_html), the figure shows the still
     * until then, and after a failure; without one, the whole figure is
     * hidden until then. Sets $GLOBALS['kop_network_embed'] so the footer
     * loads the scripts.
     */
    function kop_network_map_embed_shell(array $slice, $label, $caption_html, $open_url, $still_html = '') {
        $GLOBALS['kop_network_embed'] = true;
        $slice['mapUrl'] = kop_network_map_page_url();
        $json = wp_json_encode($slice, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $still = trim((string) $still_html) !== '';
        ob_start();
        ?>
        <figure class="kop-network-embed<?php echo $still ? ' kop-network-embed--still' : ''; ?>" data-kop-network-embed<?php echo $still ? '' : ' hidden'; ?>>
            <?php if ($still) : ?>
            <div class="kop-network-embed__still"><?php echo $still_html; ?></div>
            <?php endif; ?>
            <div class="kop-network-embed__stage"<?php echo $still ? ' hidden' : ''; ?>>
                <canvas class="kop-network-embed__canvas" role="img" aria-label="<?php echo esc_attr($label); ?>"></canvas>
                <div class="kop-network-embed__zoom">
                    <button type="button" class="kop-network-embed__button" data-kop-embed-zoom="in" aria-label="Zoom in">+</button>
                    <button type="button" class="kop-network-embed__button" data-kop-embed-zoom="out" aria-label="Zoom out">&minus;</button>
                </div>
            </div>
            <figcaption class="kop-network-embed__caption">
                <span><?php echo $caption_html; ?></span>
                <span class="kop-network-embed__actions">
                    <button type="button" class="kop-network-embed__button" data-kop-embed-fit hidden>Fit</button>
                    <a class="kop-network-embed__button kop-network-embed__open" href="<?php echo esc_url($open_url); ?>">Open on the full map</a>
                </span>
            </figcaption>
            <script type="application/json" class="kop-network-embed__data"><?php echo $json; ?></script>
        </figure>
        <?php
        return (string) ob_get_clean();
    }
}

if (!function_exists('kop_network_map_embed_html')) {
    /**
     * The map for a facility page or profile post's network section, or ''
     * when the map does not draw the facility. Without JavaScript, or when
     * the scripts fail, it stays hidden and the list under it is the
     * section.
     */
    function kop_network_map_embed_html($facility_id) {
        if (!function_exists('kop_network_map_facility_connections')) return '';
        $all = kop_network_map_facility_connections();
        $entry = $all[(int) $facility_id] ?? null;
        if (!$entry || empty($entry['links'])) return '';
        $slice = kop_network_map_slice($entry['node']);
        if (!$slice || count($slice['nodes']) < 2) return '';

        $name = (string) $entry['name'];
        // No count: people who only join two places are drawn as the line
        // between them, so the names drawn are fewer than the names held.
        $caption = esc_html($name) . ' and the names around it'
            . (!empty($slice['more']) ? ', and ' . (int) $slice['more'] . ' more on the full map' : '')
            . '. Click a name to open it on the map.';
        return kop_network_map_embed_shell(
            $slice,
            sprintf('Map of %s and the names around it on the network map; its connections are listed below.', $name),
            $caption,
            kop_network_map_page_url() . '#open=' . rawurlencode($entry['node'])
        );
    }
}

if (!function_exists('kop_network_map_preview_shortcode')) {
    /**
     * [kop_network_preview view="historical" caption="..."]<still>[/kop_network_preview]
     *
     * A starter view of the map, live, on a content page (the history hub,
     * Phase 4.4). The enclosed content is the still picture of the view: it
     * shows until the map has painted and stays if it cannot, and being in
     * the post content it is also the image Yoast picks for sharing. A
     * click on a name opens it on the full map.
     */
    function kop_network_map_preview_shortcode($atts, $content = '') {
        $atts = shortcode_atts(array('view' => 'historical', 'caption' => ''), (array) $atts, 'kop_network_preview');
        $key = sanitize_key($atts['view']);
        $slice = kop_network_map_view_slice($key);
        if (!$slice) {
            return (string) $content;
        }
        $label = '';
        foreach ($slice['meta']['views'] as $view) {
            $label = (string) ($view['label'] ?? '');
        }
        $caption = $atts['caption'] !== ''
            ? esc_html($atts['caption'])
            : esc_html(sprintf('The %s view of the network map.', $label !== '' ? $label : $key));
        return kop_network_map_embed_shell(
            $slice,
            sprintf('The network map\'s %s view; a click on a name opens it on the full map.', $label !== '' ? $label : $key),
            $caption . ' Click a name to open it on the map.',
            kop_network_map_page_url() . '#view=' . rawurlencode($key),
            (string) $content
        );
    }
    if (function_exists('add_shortcode')) {
        add_shortcode('kop_network_preview', 'kop_network_map_preview_shortcode');
    }
}

if (!function_exists('kop_network_map_preview_style')) {
    /** The embed stylesheet in the head of a page whose content carries the preview. */
    function kop_network_map_preview_style() {
        if (!is_singular()) return;
        $post = get_queried_object();
        if ($post && isset($post->post_content) && has_shortcode($post->post_content, 'kop_network_preview')) {
            kop_network_map_embed_style();
        }
    }
    add_action('wp_enqueue_scripts', 'kop_network_map_preview_style', 20);
}

if (!function_exists('kop_network_map_enqueue_embed')) {
    /**
     * The map's own modules for a page that printed an embed, under the
     * handles and URLs the map page uses, so a browser that has seen the map
     * has them cached. Runs early in the footer, after the shell is printed;
     * WordPress still prints scripts enqueued there.
     */
    function kop_network_map_enqueue_embed() {
        if (empty($GLOBALS['kop_network_embed'])) return;
        $dir = get_stylesheet_directory();
        $uri = get_stylesheet_directory_uri();
        $deps = array();
        foreach (array(
            'kop-d3-force'         => '/js/vendor/d3-force.bundle.min.js',
            'kop-network-store'    => '/js/network-map/store.js',
            'kop-network-canvas'   => '/js/network-map/canvas.js',
            'kop-network-viewport' => '/js/network-map/viewport.js',
            'kop-network-focus'    => '/js/network-map/focus.js',
            'kop-network-embed'    => '/js/network-map/embed.js',
        ) as $handle => $rel) {
            if (!file_exists($dir . $rel)) return;
            wp_enqueue_script($handle, $uri . $rel, $deps, filemtime($dir . $rel), true);
            $deps[] = $handle;
        }
    }
    add_action('wp_footer', 'kop_network_map_enqueue_embed', 5);
}

if (!function_exists('kop_network_map_embed_style')) {
    /** The embed's stylesheet, for the head of a page that may carry one. */
    function kop_network_map_embed_style() {
        $rel = '/css/network-embed.css';
        $path = get_stylesheet_directory() . $rel;
        if (file_exists($path)) {
            wp_enqueue_style('kop-network-embed', get_stylesheet_directory_uri() . $rel, array('kop-colors'), filemtime($path));
        }
    }
}
