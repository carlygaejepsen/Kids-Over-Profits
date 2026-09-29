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
        );
    }
}

if (!function_exists('kop_network_map_flush')) {
    /** Drop the caches. Cheap to rebuild; both are keyed, so this is belt and braces. */
    function kop_network_map_flush() {
        delete_transient('kop_network_map_meta');
        delete_transient('kop_network_map_facility_urls');
        delete_transient('kop_network_map_facility_links');
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

if (!function_exists('kop_network_map_slice_from_graph')) {
    /**
     * The part of the graph the map draws when a name is opened in Focus
     * mode, in graph.json's own shape, so the map's store takes it as it
     * takes the whole file (Phase 4.1, the facility page embed).
     *
     * Which names: the rules of visibleIds() in js/network-map/focus.js for
     * a trail of one, with every filter at its default (all on). The root
     * and everything it touches; everyone a person among those touches;
     * every place those people connect to; and the company that owns each
     * program on it. scripts/test-network-embed.js holds the two to the same
     * answer for every facility on the map, so change them together.
     *
     * A root with more than $cap direct connections keeps the $cap most
     * important; 'more' says how many went. Every edge among the kept names
     * comes along, and each node carries offSlice, how many of its
     * connections the slice left out, so the "+N" on a name says what it
     * says on the full map. Returns null for a name the graph lacks.
     */
    function kop_network_map_slice_from_graph(array $graph, array $positions, $root, $cap = 40) {
        $nodes = array();
        foreach ($graph['nodes'] as $node) {
            $nodes[(string) $node['id']] = $node;
        }
        $root = (string) $root;
        if (!isset($nodes[$root]) || !isset($positions[$root])) {
            return null;
        }
        // Adjacency over the nodes the layout places, as store.hydrate() keeps them.
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
        foreach (array_keys($asked) as $id) {
            if ($kind($id) !== 'person') continue;
            foreach ($adjacent[$id] ?? array() as $link) {
                if ($kind($link['other']) !== 'person') {
                    $asked[$link['other']] = true;
                }
            }
        }
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

        // In graph.json's order, which the store keeps: the layout breaks
        // ties by it, so another order settles the same names elsewhere.
        $out_nodes = array();
        $out_positions = array();
        foreach ($nodes as $id => $node) {
            if (!isset($asked[$id])) continue;
            $off = 0;
            foreach ($adjacent[$id] ?? array() as $link) {
                if (!isset($asked[$link['other']])) $off++;
            }
            if ($off) {
                $node['offSlice'] = $off;
            }
            $out_nodes[] = $node;
            $out_positions[$id] = $positions[$id];
        }
        $out_edges = array();
        foreach ((array) ($graph['edges'] ?? array()) as $edge) {
            if (isset($asked[(string) $edge['source']], $asked[(string) $edge['target']])) {
                $out_edges[] = $edge;
            }
        }
        // The renderer reads the board colours and the chain order from
        // meta; the starter views are the map page's business.
        $meta = (array) ($graph['meta'] ?? array());
        $meta['views'] = array();
        $meta['headline'] = array();
        return array(
            'root'   => $root,
            'more'   => $more,
            'nodes'  => $out_nodes,
            'edges'  => $out_edges,
            'meta'   => $meta,
            'layout' => array('positions' => $out_positions),
        );
    }
}

if (!function_exists('kop_network_map_slice')) {
    /**
     * kop_network_map_slice_from_graph() for the deployed graph, with the
     * profile page URL of every name in it that has one ('urls', node id =>
     * URL, for Ctrl-click). Cached per name against the graph build and the
     * facility page index.
     */
    function kop_network_map_slice($node_id) {
        $key = kop_network_map_cache_key();
        if ($key === '' || (string) $node_id === '') {
            return null;
        }
        if (function_exists('kop_facility_pages_index')) {
            $index = kop_facility_pages_index();
            $key .= ':' . substr((string) ($index['fingerprint'] ?? ''), 0, 12);
        }
        $key .= ':v1';
        $transient = 'kop_nm_slice_' . md5((string) $node_id);
        $cached = get_transient($transient);
        if (is_array($cached) && ($cached['_key'] ?? '') === $key) {
            return $cached['slice'];
        }
        $graph = kop_network_map_graph();
        $slice = $graph ? kop_network_map_slice_from_graph($graph, kop_network_map_layout(), $node_id) : null;
        if ($slice) {
            // Not a PHP array keyed by id: an id that looks like a number
            // would come back renumbered, as the facility URLs once did.
            $urls = function_exists('kop_network_map_facility_urls') ? kop_network_map_facility_urls() : array();
            $slice['urls'] = array();
            foreach ($slice['nodes'] as $node) {
                $fid = (int) ($node['facilityId'] ?? 0);
                if ($fid > 0 && isset($urls[$fid])) {
                    $slice['urls'][] = array((string) $node['id'], $urls[$fid]);
                }
            }
        }
        set_transient($transient, array('_key' => $key, 'slice' => $slice), WEEK_IN_SECONDS);
        return $slice;
    }
}

if (!function_exists('kop_network_map_embed_html')) {
    /**
     * The embed shell for a facility page or profile post: a hidden figure
     * holding the slice as JSON, which js/network-map/embed.js paints and
     * unhides. Without JavaScript, or when the scripts fail, it stays hidden
     * and the list of connections under it is the section. '' when the map
     * does not draw the facility. Sets $GLOBALS['kop_network_embed'] so the
     * footer loads the scripts.
     */
    function kop_network_map_embed_html($facility_id) {
        if (!function_exists('kop_network_map_facility_connections')) return '';
        $all = kop_network_map_facility_connections();
        $entry = $all[(int) $facility_id] ?? null;
        if (!$entry || empty($entry['links'])) return '';
        $slice = kop_network_map_slice($entry['node']);
        if (!$slice || count($slice['nodes']) < 2) return '';
        $GLOBALS['kop_network_embed'] = true;

        $map = function_exists('kop_facility_pages_page_url_by_template')
            ? kop_facility_pages_page_url_by_template('page-network-map.php', '/network-map/')
            : home_url('/network-map/');
        $slice['mapUrl'] = $map;
        $name = (string) $entry['name'];
        $label = sprintf('Map of %s and the names around it on the network map; its connections are listed below.', $name);
        $json = wp_json_encode($slice, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

        ob_start();
        ?>
        <figure class="kop-network-embed" data-kop-network-embed hidden>
            <div class="kop-network-embed__stage">
                <canvas class="kop-network-embed__canvas" role="img" aria-label="<?php echo esc_attr($label); ?>"></canvas>
                <div class="kop-network-embed__zoom">
                    <button type="button" class="kop-network-embed__button" data-kop-embed-zoom="in" aria-label="Zoom in">+</button>
                    <button type="button" class="kop-network-embed__button" data-kop-embed-zoom="out" aria-label="Zoom out">&minus;</button>
                </div>
            </div>
            <figcaption class="kop-network-embed__caption">
                <?php // No count: people who only join two places are drawn as the line between them. ?>
                <span><?php echo esc_html($name); ?> and the names around it<?php
                    if (!empty($slice['more'])) echo ', and ' . (int) $slice['more'] . ' more on the full map';
                ?>. Click a name to open it on the map.</span>
                <span class="kop-network-embed__actions">
                    <button type="button" class="kop-network-embed__button" data-kop-embed-fit>Fit</button>
                    <a class="kop-network-embed__button kop-network-embed__open" href="<?php echo esc_url($map . '#open=' . rawurlencode($entry['node'])); ?>">Open on the full map</a>
                </span>
            </figcaption>
            <script type="application/json" class="kop-network-embed__data"><?php echo $json; ?></script>
        </figure>
        <?php
        return (string) ob_get_clean();
    }
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
