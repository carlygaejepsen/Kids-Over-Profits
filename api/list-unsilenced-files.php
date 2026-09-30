<?php
/**
 * List every file in Unsilenced's public program archive (Google Drive
 * folders, one per state, see js/data/unsilenced/roots.json) with its Drive
 * md5 and size, so the build can tell which of their documents KOP already
 * holds a copy of. Read-only: nothing is downloaded, imported or written to
 * the database.
 *
 * Uses FileBird Cloud's Google connection (lib-filebird-drive.php), the same
 * one sync-drive-documents.php uses; Drive API reads are free.
 *
 *   Writes    ~/kop-import/unsilenced/files.jsonl   one line per file:
 *             {id, name, mime, md5, size, parent, path}
 *             ~/kop-import/unsilenced/queue.json    folders still to list
 *   Resumes   each run lists folders for --minutes, then stops; the next run
 *             carries on from queue.json. "restart" starts over.
 *
 * CLI only:
 *   php api/list-unsilenced-files.php probe              check the connection can read the archive
 *   php api/list-unsilenced-files.php --minutes=25
 *   php api/list-unsilenced-files.php restart --minutes=25
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$opts = array('minutes' => 25);
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--minutes=(\d+)$/', $arg, $m)) $opts['minutes'] = max(1, (int)$m[1]);
}
$probe   = in_array('probe', $argv, true);
$restart = in_array('restart', $argv, true);
set_time_limit(0);

$lock = fopen(sys_get_temp_dir() . '/kop-list-unsilenced-files.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another run is still going.\n");
    exit(0);
}

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH')) {
    fwrite(STDERR, "wp-load.php not found\n");
    exit(2);
}
if (!function_exists('njfb_cloud_get_settings_by_key') || !class_exists('NjFbCloud\\Src\\Classes\\GoogleDriveApi')) {
    fwrite(STDERR, "FileBird Cloud isn't active, so there is no Google connection to use.\n");
    exit(2);
}
require_once __DIR__ . '/lib-filebird-drive.php';

$roots_file = dirname(__DIR__) . '/js/data/unsilenced/roots.json';
$roots = json_decode((string)@file_get_contents($roots_file), true);
if (!is_array($roots) || empty($roots['roots'])) {
    fwrite(STDERR, "No root folders in js/data/unsilenced/roots.json\n");
    exit(2);
}

$out_dir = rtrim(getenv('HOME') ?: dirname(ABSPATH), '/') . '/kop-import/unsilenced';
if (!is_dir($out_dir) && !mkdir($out_dir, 0755, true)) {
    fwrite(STDERR, "Can't create {$out_dir}\n");
    exit(2);
}
$queue_file = $out_dir . '/queue.json';
$files_file = $out_dir . '/files.jsonl';

function kop_lu_children($folder_id) {
    $out = array();
    $page = null;
    do {
        $query = array(
            'q'                         => "'" . $folder_id . "' in parents and trashed = false",
            'fields'                    => 'nextPageToken, files(id, name, mimeType, md5Checksum, size)',
            'pageSize'                  => '1000',
            'supportsAllDrives'         => 'true',
            'includeItemsFromAllDrives' => 'true',
        );
        if ($page) $query['pageToken'] = $page;
        $res = kop_sd_drive_get('files', $query);
        foreach ((array)($res['files'] ?? array()) as $f) $out[] = $f;
        $page = $res['nextPageToken'] ?? null;
    } while ($page);
    return $out;
}

if ($probe) {
    $state = array_key_first($roots['roots']);
    try {
        $kids = kop_lu_children($roots['roots'][$state]);
    } catch (Exception $e) {
        fwrite(STDERR, 'Drive refused: ' . $e->getMessage() . "\n");
        exit(1);
    }
    $folders = count(array_filter($kids, function ($f) { return $f['mimeType'] === 'application/vnd.google-apps.folder'; }));
    echo "{$state}: " . count($kids) . " entries ({$folders} folders) readable.\n";
    exit($kids ? 0 : 1);
}

if ($restart || !file_exists($queue_file)) {
    $queue = array();
    foreach ($roots['roots'] as $state => $id) $queue[] = array('id' => $id, 'path' => array($state));
    @unlink($files_file);
} else {
    $queue = json_decode((string)file_get_contents($queue_file), true);
    if (!is_array($queue)) {
        fwrite(STDERR, "queue.json is unreadable - run with restart\n");
        exit(2);
    }
}
if (!$queue) {
    echo "Done already: {$files_file}\n";
    exit(0);
}

$started = time();
$fh = fopen($files_file, 'a');
$listed = 0;
$written = 0;
while ($queue && time() - $started < $opts['minutes'] * 60) {
    $folder = $queue[0];
    try {
        $kids = kop_lu_children($folder['id']);
    } catch (Exception $e) {
        fwrite(STDERR, 'Skipped ' . implode(' / ', $folder['path']) . ': ' . $e->getMessage() . "\n");
        $kids = null;
    }
    array_shift($queue);
    if ($kids === null) continue;
    foreach ($kids as $f) {
        if ($f['mimeType'] === 'application/vnd.google-apps.folder') {
            $queue[] = array('id' => $f['id'], 'path' => array_merge($folder['path'], array($f['name'])));
            continue;
        }
        fwrite($fh, json_encode(array(
            'id'     => $f['id'],
            'name'   => $f['name'],
            'mime'   => $f['mimeType'],
            'md5'    => $f['md5Checksum'] ?? '',
            'size'   => isset($f['size']) ? (int)$f['size'] : 0,
            'parent' => $folder['id'],
            'path'   => $folder['path'],
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        $written++;
    }
    $listed++;
    // Saved as it goes, so a killed run loses one folder at most.
    if ($listed % 25 === 0) file_put_contents($queue_file, json_encode($queue));
}
fclose($fh);
file_put_contents($queue_file, json_encode($queue));
echo "Listed {$listed} folders, {$written} files this run; " . count($queue) . " folders left.\n";
echo $queue ? "Run again to carry on.\n" : "Done: {$files_file}\n";
