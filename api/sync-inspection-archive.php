<?php
/**
 * Copy the inspection report PDFs the scrapers archive in the Drive FileBird
 * folder onto this site, so the state report pages can offer "Archived copy"
 * next to the state's own link. States take reports down (WA and NC links
 * already 404), and the Drive folder itself is not public.
 *
 *   From      The scraper folders at the top of the Drive FileBird folder
 *             (report_store.py in the tools repo writes them).
 *   To        wp-content/uploads/inspection-reports/<state>/, outside the media
 *             library: no attachment rows, previews or FileBird folders.
 *   Index     <state>/index.json maps each file's lookup key (see
 *             kop_inspection_archive_key()) to its file name. The report pages
 *             load it and link only files the site has. Rebuilt on every apply
 *             run from what is on disk.
 *   Skipped   Anything that is not a PDF, over --max-mb, or already here at the
 *             same size. Nothing is ever deleted.
 *
 * CLI only:
 *   php api/sync-inspection-archive.php                       report only
 *   php api/sync-inspection-archive.php apply                 copy (2000 per run)
 *   php api/sync-inspection-archive.php apply --only=nc --limit=500 --minutes=25
 *
 * Meant for cron: each run copies up to --limit files within --minutes and the
 * next run carries on. A lock file stops two runs overlapping.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$opts = array('limit' => 2000, 'minutes' => 25, 'max-mb' => 60, 'only' => '');
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(limit|minutes|max-mb|only)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[1] === 'only' ? strtolower(trim($m[2])) : max(0, (int)$m[2]);
    }
}
$apply = in_array('apply', $argv, true);
set_time_limit(0);

// Drive folder (top of the FileBird folder) => state directory on the site.
$FOLDERS = array(
    'ar_pdfs'       => 'ar',
    'fl_pdfs'       => 'fl',
    'mi_pdfs'       => 'mi',
    'nc_pdfs'       => 'nc',
    'or_pdfs'       => 'or',
    'ut_checklists' => 'ut',
    'wa_pdfs'       => 'wa',
);

$lock = fopen(sys_get_temp_dir() . '/kop-sync-inspection-archive.lock', 'c');
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

/**
 * The lookup key for an archived report: the last path segment of a URL or file
 * name, without query, percent-decoded, anything outside [A-Za-z0-9._-] made
 * "_", lowercased. js/inspections/report-page.js (archiveKey) must match.
 */
function kop_inspection_archive_key($name) {
    $name = preg_replace('/[?#].*$/s', '', (string)$name);
    $name = substr($name, strrpos('/' . $name, '/'));
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', rawurldecode($name));
    return strtolower($name);
}

/** The files directly in a Drive folder, every page. */
function kop_sia_children($folder_id, $folders_only = false) {
    $out = array();
    $page = null;
    do {
        $query = array(
            'q'        => "'" . $folder_id . "' in parents and trashed = false"
                . ($folders_only ? " and mimeType = 'application/vnd.google-apps.folder'" : ''),
            'fields'   => 'nextPageToken, files(id, name, mimeType, md5Checksum, size)',
            'pageSize' => '1000',
        );
        if ($page) {
            $query['pageToken'] = $page;
        }
        $res = kop_sd_drive_get('files', $query);
        foreach ((array)($res['files'] ?? array()) as $f) {
            $out[] = $f;
        }
        $page = $res['nextPageToken'] ?? null;
    } while ($page);
    return $out;
}

/** Rewrite <dir>/index.json from the PDFs in the directory. */
function kop_sia_write_index($dir) {
    $files = array();
    foreach (glob($dir . '/*.pdf') ?: array() as $path) {
        $name = basename($path);
        $files[kop_inspection_archive_key($name)] = $name;
        // Utah checklists are looked up by checklist id alone: the page knows
        // the id but not the state's facility number in the file name.
        if (preg_match('/_checklist_(\d+)\.pdf$/i', $name, $m)) {
            $files['checklist_' . $m[1]] = $name;
        }
    }
    ksort($files);
    $json = json_encode(array('generated' => gmdate('c'), 'files' => $files), JSON_UNESCAPED_SLASHES);
    $tmp = $dir . '/index.json.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $dir . '/index.json')) {
        @unlink($tmp);
        throw new RuntimeException('could not write ' . $dir . '/index.json');
    }
    return count($files);
}

$started = time();
$uploads = wp_upload_dir();
$base = trailingslashit($uploads['basedir']) . 'inspection-reports';
$counts = array();
$lines = array();
$copied = 0;
$budget_hit = '';
$bump = function ($state, $what, $detail = '') use (&$counts, &$lines) {
    $counts[$state][$what] = ($counts[$state][$what] ?? 0) + 1;
    if ($detail !== '') {
        $lines[] = array('state' => $state, 'result' => $what . ': ' . $detail);
    }
};

try {
    $root = njfb_cloud_get_main_folder('google_drive');
    if (!$root) throw new RuntimeException('FileBird Cloud has not made its Drive folder yet.');
    $top = array();
    foreach (kop_sia_children($root, true) as $f) {
        $top[strtolower($f['name'])] = $f['id'];
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

foreach ($FOLDERS as $folder => $state) {
    if ($opts['only'] !== '' && $opts['only'] !== $state) continue;
    if (!isset($top[$folder])) {
        $counts[$state]['no Drive folder'] = 1;
        continue;
    }
    $dir = $base . '/' . $state;
    if ($apply && !is_dir($dir) && !wp_mkdir_p($dir)) {
        fwrite(STDERR, "could not create {$dir}\n");
        exit(1);
    }
    foreach (glob($dir . '/*.part') ?: array() as $stale) {
        @unlink($stale);
    }

    try {
        $files = kop_sia_children($top[$folder]);
    } catch (Throwable $e) {
        $bump($state, 'failed', 'listing ' . $folder . ': ' . $e->getMessage());
        continue;
    }

    foreach ($files as $f) {
        if ($f['mimeType'] !== 'application/pdf' || !preg_match('/\.pdf$/i', $f['name'])) {
            continue;
        }
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $f['name']);
        if ($name === '' || $name[0] === '.') {
            $bump($state, 'skipped', $f['name']);
            continue;
        }
        $dest = $dir . '/' . $name;
        $size = (int)($f['size'] ?? 0);
        if (is_file($dest) && filesize($dest) === $size) {
            $bump($state, 'already here');
            continue;
        }
        if ($size > $opts['max-mb'] * 1024 * 1024) {
            $bump($state, 'too big', $f['name'] . ' (' . round($size / 1048576) . ' MB)');
            continue;
        }
        if (!$apply) {
            $bump($state, 'to copy');
            continue;
        }
        if ($copied >= $opts['limit'] || time() - $started > $opts['minutes'] * 60) {
            $budget_hit = $budget_hit ?: ($copied >= $opts['limit'] ? "reached --limit={$opts['limit']}" : "reached --minutes={$opts['minutes']}");
            $bump($state, 'left for the next run');
            continue;
        }

        $part = $dest . '.part';
        try {
            kop_sd_download($f['id'], $part);
            $md5 = $f['md5Checksum'] ?? '';
            if ($md5 !== '' && md5_file($part) !== $md5) throw new RuntimeException('download was incomplete (md5 differs)');
            $head = (string)file_get_contents($part, false, null, 0, 4);
            if ($head !== '%PDF') throw new RuntimeException('not a PDF');
            if (!@rename($part, $dest)) throw new RuntimeException('could not move the file into place');
            @chmod($dest, 0644);
            $copied++;
            $bump($state, 'copied');
        } catch (Throwable $e) {
            $bump($state, 'failed', $f['name'] . ': ' . $e->getMessage());
        } finally {
            if (file_exists($part)) @unlink($part);
        }
    }

    if ($apply) {
        try {
            $counts[$state]['index entries'] = kop_sia_write_index($dir);
        } catch (Throwable $e) {
            $bump($state, 'failed', $e->getMessage());
        }
    }
}

ksort($counts);
echo json_encode(array(
    'applied' => $apply,
    'seconds' => time() - $started,
    'counts'  => $counts,
    'stopped' => $budget_hit,
    'items'   => $lines,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
