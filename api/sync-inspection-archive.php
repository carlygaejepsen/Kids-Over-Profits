<?php
/**
 * Index the inspection report PDFs the scrapers archive in the Drive FileBird
 * folder, so the state report pages can offer "Archived copy" next to the
 * state's own link. States take reports down (WA and NC links already 404).
 * The PDFs stay in Drive: the site keeps only the index and links each report
 * to its Drive page.
 *
 *   From      The scraper folders at the top of the Drive FileBird folder
 *             (report_store.py in the tools repo writes them).
 *   Sharing   A folder is indexed only when it is shared "Anyone with the
 *             link" (its files inherit that). A private folder is reported
 *             and its old index removed, so no page links a file visitors
 *             cannot open.
 *   Index     wp-content/uploads/inspection-reports/<state>/index.json maps
 *             each file's lookup key (see kop_inspection_archive_key()) to its
 *             Drive URL. js/inspections/report-page.js and
 *             kop_state_ut_inspection_details() read it.
 *
 * CLI only:
 *   php api/sync-inspection-archive.php                  report only
 *   php api/sync-inspection-archive.php apply            write the indexes
 *   php api/sync-inspection-archive.php apply --only=ut
 *
 * Meant for nightly cron. Listing Drive is all it does, so a run takes a
 * minute or two. --limit, --minutes and --max-mb from the copying version are
 * accepted and ignored.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$opts = array('only' => '');
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--only=(.*)$/', $arg, $m)) {
        $opts['only'] = strtolower(trim($m[1]));
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
    'nh_pdfs'       => 'nh',
    'or_pdfs'       => 'or',
    'pa_pdfs'       => 'pa',
    'ut_checklists' => 'ut',
    'wa_pdfs'       => 'wa',
    'wv_pdfs'       => 'wv',
    'wy_pdfs'       => 'wy',
    'id_pdfs'       => 'id',
    'me_pdfs'       => 'me',
    'oh_pdfs'       => 'oh',
    'ia_pdfs'       => 'ia',
    'md_pdfs'       => 'md',
    'sd_pdfs'       => 'sd',
    'va_pdfs'       => 'va',
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
            'fields'   => 'nextPageToken, files(id, name, mimeType)',
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

/** Whether anyone with the link can open the folder (and so the files in it). */
function kop_sia_link_shared($folder_id) {
    $res = kop_sd_drive_get('files/' . $folder_id, array('fields' => 'permissions(type, role)'));
    foreach ((array)($res['permissions'] ?? array()) as $p) {
        if (($p['type'] ?? '') === 'anyone') return true;
    }
    return false;
}

/** The index for one folder's PDFs: lookup key => Drive URL. */
function kop_sia_index_entries(array $files) {
    $out = array();
    foreach ($files as $f) {
        if (($f['mimeType'] ?? '') !== 'application/pdf' || !preg_match('/\.pdf$/i', $f['name'])) {
            continue;
        }
        $url = 'https://drive.google.com/file/d/' . rawurlencode($f['id']) . '/view';
        $out[kop_inspection_archive_key($f['name'])] = $url;
        // Utah checklists are looked up by checklist id alone: the page knows
        // the id but not the state's facility number in the file name.
        if (preg_match('/_checklist_(\d+)\.pdf$/i', $f['name'], $m)) {
            $out['checklist_' . $m[1]] = $url;
        }
    }
    ksort($out);
    return $out;
}

function kop_sia_write_index($dir, array $entries) {
    if (!is_dir($dir) && !wp_mkdir_p($dir)) {
        throw new RuntimeException('could not create ' . $dir);
    }
    $json = json_encode(array('generated' => gmdate('c'), 'files' => $entries), JSON_UNESCAPED_SLASHES);
    $tmp = $dir . '/index.json.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $dir . '/index.json')) {
        @unlink($tmp);
        throw new RuntimeException('could not write ' . $dir . '/index.json');
    }
}

$started = time();
$uploads = wp_upload_dir();
$base = trailingslashit($uploads['basedir']) . 'inspection-reports';
$counts = array();

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
    $dir = $base . '/' . $state;
    if (!isset($top[$folder])) {
        $counts[$state] = 'no Drive folder';
        continue;
    }
    try {
        if (!kop_sia_link_shared($top[$folder])) {
            $counts[$state] = 'not shared: set ' . $folder . ' to "Anyone with the link" in Drive';
            if ($apply && is_file($dir . '/index.json')) @unlink($dir . '/index.json');
            continue;
        }
        $entries = kop_sia_index_entries(kop_sia_children($top[$folder]));
        if ($apply) kop_sia_write_index($dir, $entries);
        $counts[$state] = count($entries) . ' index entries' . ($apply ? ' written' : '');
    } catch (Throwable $e) {
        $counts[$state] = 'failed: ' . $e->getMessage();
    }
}

ksort($counts);
echo json_encode(array(
    'applied' => $apply,
    'seconds' => time() - $started,
    'counts'  => $counts,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
