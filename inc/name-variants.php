<?php
/**
 * Every known other name of a person, for the wiki editor's staff check
 * (js/wiki-generation.js: one paragraph per person, a warning for names
 * that may be one person).
 *
 * kop_name_variants_build() is pure, so scripts/test-name-variants.php runs
 * it on tmp/prod.sqlite. Its groups come from:
 *   - the people table (inc/people.php): each person's name, their other
 *     names (aliases) and the names of every id merged into them
 *     (KOP Tools > Merge People)
 *   - the network map's people and their other names (graph.json)
 *   - js/data/people/name-variants-reviewed.json: names checked by hand
 * and its "distinct" pairs from Merge People's "Not the same" and the
 * reviewed file. The nickname table is js/data/people/nicknames.json.
 * Only groups whose names do not already share a key are kept: the editor
 * matches same-key names itself.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_name_variants_key')) {
    /** The editor's name key (staffNameKey() in js/wiki-generation.js): lower case, titles and punctuation gone. */
    function kop_name_variants_key($name) {
        $n = strtolower(trim((string) $name));
        $n = preg_replace('/\b(?:dr|rabbi|rev|mr|mrs|ms|miss)\.?\s+/', '', $n);
        $n = preg_replace('/[^a-z0-9\s]/', '', $n);
        return trim(preg_replace('/\s+/', ' ', $n));
    }
}

if (!function_exists('kop_name_variants_build')) {
    /**
     * @param array $people    rows {id, name, aliases, merged_into}
     * @param array $dismissed "a:b" (person ids) => anything, Merge People's "Not the same"
     * @param array $nodes     network map nodes {kind, name, aliases}
     * @param array $reviewed  name-variants-reviewed.json, decoded
     * @return array {groups: [[name, ...]], distinct: [[name, name]]}
     */
    function kop_name_variants_build(array $people, array $dismissed, array $nodes, array $reviewed) {
        $rows = array();
        foreach ($people as $p) $rows[(int) $p['id']] = $p;
        $root = function ($id) use ($rows) {
            $seen = array();
            while (isset($rows[$id]) && (int) ($rows[$id]['merged_into'] ?? 0) > 0 && !isset($seen[$id])) {
                $seen[$id] = true;
                $id = (int) $rows[$id]['merged_into'];
            }
            return $id;
        };
        $sets = array();
        $add = function ($k, $name) use (&$sets) {
            $name = trim(preg_replace('/\s+/', ' ', (string) $name));
            if ($name !== '' && kop_name_variants_key($name) !== '') $sets[$k][$name] = true;
        };
        foreach ($rows as $id => $p) {
            $k = 'p' . $root($id);
            $add($k, $p['name'] ?? '');
            foreach (preg_split('/\r\n|\r|\n/', (string) ($p['aliases'] ?? '')) as $a) $add($k, $a);
        }
        foreach ($nodes as $i => $n) {
            if (($n['kind'] ?? '') !== 'person') continue;
            $k = !empty($n['personId']) ? 'p' . $root((int) $n['personId']) : 'm' . $i;
            $add($k, $n['name'] ?? '');
            foreach ((array) ($n['aliases'] ?? array()) as $a) $add($k, $a);
        }
        foreach ((array) ($reviewed['same'] ?? array()) as $i => $g) {
            foreach ((array) ($g['names'] ?? array()) as $name) $add('r' . $i, $name);
        }
        // Sets sharing a name key are one person (a reviewed group names someone the table knows).
        $parent = array();
        $find = function ($x) use (&$parent) {
            while (isset($parent[$x]) && $parent[$x] !== $x) $x = $parent[$x];
            return $x;
        };
        $by_key = array();
        foreach ($sets as $k => $names) {
            $parent[$k] = $parent[$k] ?? $k;
            foreach (array_keys($names) as $name) {
                $nk = kop_name_variants_key($name);
                if (isset($by_key[$nk])) $parent[$find($k)] = $find($by_key[$nk]);
                else $by_key[$nk] = $k;
            }
        }
        $merged = array();
        foreach ($sets as $k => $names) {
            foreach (array_keys($names) as $name) $merged[$find($k)][$name] = true;
        }
        $groups = array();
        foreach ($merged as $names) {
            $names = array_keys($names);
            $keys = array_unique(array_map('kop_name_variants_key', $names));
            if (count($keys) < 2) continue;   // one key: the editor already matches these
            sort($names);
            $groups[] = $names;
        }
        usort($groups, function ($a, $b) { return strcmp($a[0], $b[0]); });
        $distinct = array();
        foreach (array_keys($dismissed) as $pair) {
            $ids = array_map('intval', explode(':', (string) $pair));
            if (count($ids) === 2 && isset($rows[$ids[0]], $rows[$ids[1]])) $distinct[] = array((string) $rows[$ids[0]]['name'], (string) $rows[$ids[1]]['name']);
        }
        foreach ((array) ($reviewed['distinct'] ?? array()) as $d) {
            $n = array_values((array) ($d['names'] ?? array()));
            if (count($n) === 2) $distinct[] = array((string) $n[0], (string) $n[1]);
        }
        return array('groups' => $groups, 'distinct' => $distinct);
    }
}

if (!function_exists('kop_name_variants_data')) {
    /** What the wiki editor gets: {groups, distinct, nicknames}, cached until the people table or the files change. */
    function kop_name_variants_data() {
        global $wpdb;
        $dir = get_stylesheet_directory();
        $files = array($dir . '/js/data/people/name-variants-reviewed.json', $dir . '/js/data/people/nicknames.json', $dir . '/js/data/network/graph.json');
        $stamp = md5(get_option('kop_people_version', '') . '|' . implode('|', array_map(function ($f) { return file_exists($f) ? filemtime($f) : 0; }, $files)));
        $cached = get_transient('kop_name_variants');
        if (is_array($cached) && ($cached['stamp'] ?? '') === $stamp) return $cached['data'];
        $people = array();
        $table = $wpdb->prefix . 'kop_people';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $people = (array) $wpdb->get_results("SELECT id, name, aliases, merged_into FROM {$table}", ARRAY_A);
        }
        $graph = function_exists('kop_network_map_graph') ? kop_network_map_graph() : null;
        $read = function ($f) { return file_exists($f) ? (array) json_decode((string) file_get_contents($f), true) : array(); };
        $data = kop_name_variants_build($people, (array) get_option('kop_people_merge_dismissed', array()),
            (array) ($graph['nodes'] ?? array()), $read($files[0]));
        $nick = $read($files[1]);
        $data['nicknames'] = array('same' => $nick['same'] ?? array(), 'maybe' => $nick['maybe'] ?? array());
        set_transient('kop_name_variants', array('stamp' => $stamp, 'data' => $data), DAY_IN_SECONDS);
        return $data;
    }
}
