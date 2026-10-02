<?php
/**
 * Split a saved kop/v1/facilities feed the way inc/directory-feed.php does on
 * the server: <out>/index.json (?view=index) and one p-<md5 key>.json per
 * project (?view=detail). Used by scripts/test-directory-feed.py.
 *
 *   php scripts/directory-feed-split.php <full feed.json> <out dir>
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
if ($argc < 3) {
    fwrite(STDERR, "usage: php scripts/directory-feed-split.php <full feed.json> <out dir>\n");
    exit(2);
}

define('ABSPATH', dirname(__DIR__) . '/');
function wp_json_encode($data) {
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
require dirname(__DIR__) . '/inc/directory-feed.php';

$full = json_decode((string) file_get_contents($argv[1]), true);
if (!is_array($full) || !isset($full['projects']) || !is_array($full['projects'])) {
    fwrite(STDERR, "No projects in {$argv[1]}\n");
    exit(1);
}
$out = rtrim($argv[2], '/\\');
if (!is_dir($out) && !mkdir($out, 0777, true)) {
    fwrite(STDERR, "Cannot create $out\n");
    exit(1);
}
foreach ((array) glob($out . '/*.json') as $old) @unlink($old);

$index = array();
foreach ($full['projects'] as $key => $project) {
    if (!kop_directory_feed_wanted($project)) continue;
    file_put_contents($out . '/' . kop_directory_feed_project_file($key), wp_json_encode($project));
    $index[$key] = kop_directory_feed_slim_project($project);
}
$json = wp_json_encode(array('source' => $full['source'] ?? 'database', 'view' => 'index', 'projects' => (object) $index));
file_put_contents($out . '/index.json', $json);

$full_json = wp_json_encode($full);
printf("projects %d; full feed %.1f MB (%.0f KB gzipped), index %.1f MB (%.0f KB gzipped)\n",
    count($index),
    strlen($full_json) / 1048576, strlen(gzencode($full_json, 6)) / 1024,
    strlen($json) / 1048576, strlen(gzencode($json, 6)) / 1024);
