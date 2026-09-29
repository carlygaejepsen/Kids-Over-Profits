<?php
/**
 * Print the network map slices the facility page embed would carry, as JSON
 * on stdout: { "<node id>": slice, ... } for every name on the map that
 * links to a facility record, and { "view:<key>": slice } for every starter
 * view (the history hub's preview), or for the ids and view:<key>s given.
 *
 *   php scripts/network-slices.php [id | view:<key> ...]
 *
 * Reads js/data/network/graph.json and layout.json through
 * kop_network_map_slice_from_graph() in inc/network-map.php, with no
 * WordPress. scripts/test-network-embed.js runs it and holds every slice to
 * what js/network-map/focus.js shows for the same name on the full map.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');
function add_action() {}
function add_filter() {}
function add_shortcode() {}

require ABSPATH . 'inc/network-map.php';

$dir = ABSPATH . 'js/data/network/';
$graph = json_decode((string) file_get_contents($dir . 'graph.json'), true);
$layout = json_decode((string) file_get_contents($dir . 'layout.json'), true);
if (!is_array($graph) || !is_array($layout)) {
    fwrite(STDERR, "graph.json or layout.json is missing or unreadable\n");
    exit(1);
}
$positions = (array) ($layout['positions'] ?? array());

$ids = array_slice($argv, 1);
if (!$ids) {
    foreach ($graph['nodes'] as $node) {
        if (!empty($node['facilityId'])) {
            $ids[] = (string) $node['id'];
        }
    }
    foreach ((array) ($graph['meta']['views'] ?? array()) as $view) {
        $ids[] = 'view:' . $view['key'];
    }
}

$out = array();
foreach ($ids as $id) {
    $out[$id] = strpos($id, 'view:') === 0
        ? kop_network_map_view_slice_from_graph($graph, $positions, substr($id, 5))
        : kop_network_map_slice_from_graph($graph, $positions, $id);
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
