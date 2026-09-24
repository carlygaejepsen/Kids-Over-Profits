<?php
/**
 * Bring the PDFs and Word documents in the Drive FileBird folder into the media
 * library, filed in the FileBird folder that matches where they sit in Drive.
 *
 * FileBird Cloud only ever imports images: its sync asks Drive for PNG, JPEG,
 * GIF, WebP and SVG and nothing else (imageMimes() in its GoogleDrive.php), so a
 * document dropped in "FileBird Cloud - kidsoverprofits.org" never reached the
 * site. This does the documents, reusing FileBird Cloud's own Google connection
 * (the njfb_cloud_settings token and the Drive folder it created).
 *
 *   Where it goes   The Drive path is followed down the site's own folder tree
 *                   (not the "Google Drive" mirror FileBird Cloud keeps). When the
 *                   path doesn't line up, a folder elsewhere with the same name is
 *                   used if exactly one copy of it is in use. A name used by several
 *                   live folders is reported and skipped rather than guessed. A
 *                   subfolder the site doesn't have yet is created under the part
 *                   of the path that did match.
 *   Already there   Matched by md5 (Drive's md5Checksum against _kop_import_md5 and
 *                   Media Deduper's mdd_hash), so nothing is downloaded twice. A
 *                   document the library has in another folder is also shown in
 *                   this one through the kop_media_folder_tags table.
 *   Left out        The scrapers' caches (Inspections, nc_pdfs, ...), which the
 *                   site serves through its inspection pages, and anything over
 *                   --max-mb.
 *
 * CLI only:
 *   php api/sync-drive-documents.php                      report only
 *   php api/sync-drive-documents.php apply                import (50 per run)
 *   php api/sync-drive-documents.php apply --limit=200 --minutes=30 --only="Hyde"
 *
 * Meant for cron: each run imports up to --limit documents within --minutes and
 * the next run carries on. A lock file stops two runs overlapping.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$opts = array('limit' => 50, 'minutes' => 20, 'max-mb' => 60, 'only' => '');
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(limit|minutes|max-mb|only)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[1] === 'only' ? trim($m[2]) : max(0, (int)$m[2]);
    }
}
$apply = in_array('apply', $argv, true);
// The host's CLI php stops scripts after 600 s, and a batch of PDF previews
// takes longer than that. --minutes is the budget instead.
set_time_limit(0);

// Folders at the top of the Drive FileBird folder that are the scrapers' output
// (see backup_reports.py in the tools repo), plus the doc finder's holding pen.
$SKIP_TOP = array('inspections', 'nc_pdfs', 'nc_ocr', 'ar_pdfs', 'fl_pdfs', 'or_pdfs', 'wa_pdfs',
                  'ut_checklists', 'doc finder inbox');
$DOC_MIMES = array(
    'application/pdf'                                                         => 'pdf',
    'application/msword'                                                      => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
);

$lock = fopen(sys_get_temp_dir() . '/kop-sync-drive-documents.lock', 'c');
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
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

if (!function_exists('njfb_cloud_get_settings_by_key') || !class_exists('NjFbCloud\\Src\\Classes\\GoogleDriveApi')) {
    fwrite(STDERR, "FileBird Cloud isn't active, so there is no Google connection to use.\n");
    exit(2);
}

global $wpdb;
$fbv     = $wpdb->prefix . 'fbv';
$fbv_rel = $wpdb->prefix . 'fbv_attachment_folder';
$tags    = $wpdb->prefix . 'kop_media_folder_tags';
$has_tags = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tags)) === $tags);
$fbv_columns = $wpdb->get_col("SHOW COLUMNS FROM {$fbv}");
$started = time();

// ---------------------------------------------------------------------------
// Google Drive, through FileBird Cloud's connection
// ---------------------------------------------------------------------------
function kop_sd_token() {
    $token = njfb_cloud_get_settings_by_key('google_drive', 'token', array());
    if (!is_array($token) || empty($token['refresh_token'])) {
        throw new RuntimeException('FileBird Cloud has no Google Drive connection - connect it under FileBird > Cloud.');
    }
    if (\NjFbCloud\Src\Classes\GoogleDriveApi::isExpired($token)) {
        $fresh = \NjFbCloud\Src\Classes\GoogleDriveApi::refreshToken($token['refresh_token']);
        if (!is_array($fresh) || empty($fresh['access_token'])) {
            throw new RuntimeException('Google refused to refresh the token - reconnect under FileBird > Cloud.');
        }
        $fresh = njfb_cloud_append_refresh_token('google_drive', $fresh);
        njfb_cloud_set_settings_by_key('google_drive', 'token', $fresh);
        $token = $fresh;
    }
    return $token['access_token'];
}

function kop_sd_drive_get($path, $query) {
    static $token = null, $token_at = 0;
    if ($token === null || time() - $token_at > 1800) {
        $token = kop_sd_token();
        $token_at = time();
    }
    $url = add_query_arg(array_map('rawurlencode', $query), 'https://www.googleapis.com/drive/v3/' . $path);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $res = wp_remote_get($url, array('timeout' => 60, 'headers' => array('Authorization' => 'Bearer ' . $token)));
        $code = is_wp_error($res) ? 0 : (int)wp_remote_retrieve_response_code($res);
        if ($code === 200) {
            return json_decode(wp_remote_retrieve_body($res), true);
        }
        if ($code === 401) {
            $token = kop_sd_token();
            $token_at = time();
        }
        sleep(2 * $attempt);
    }
    throw new RuntimeException('Drive API ' . $path . ' failed: '
        . (is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_body($res)));
}

/** Every file under a Drive folder: [ ['id','name','mimeType','md5Checksum','size','path' => [folder names]] ]. */
function kop_sd_list($folder_id, array $path, array $skip_top, $only) {
    $out = array();
    $page = null;
    do {
        $query = array(
            'q'         => "'" . $folder_id . "' in parents and trashed = false",
            'fields'    => 'nextPageToken, files(id, name, mimeType, md5Checksum, size)',
            'pageSize'  => '1000',
        );
        if ($page) {
            $query['pageToken'] = $page;
        }
        $res = kop_sd_drive_get('files', $query);
        foreach ((array)($res['files'] ?? array()) as $f) {
            if ($f['mimeType'] === 'application/vnd.google-apps.folder') {
                if (!$path) {
                    if (in_array(strtolower($f['name']), $skip_top, true)) continue;
                    if ($only !== '' && strcasecmp($f['name'], $only) !== 0) continue;
                }
                $out = array_merge($out, kop_sd_list($f['id'], array_merge($path, array($f['name'])), $skip_top, $only));
            } elseif ($path || $only === '') {
                $f['path'] = $path;
                $out[] = $f;
            }
        }
        $page = $res['nextPageToken'] ?? null;
    } while ($page);
    return $out;
}

function kop_sd_download($file_id, $dest) {
    $token = kop_sd_token();
    $res = wp_remote_get('https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id) . '?alt=media', array(
        'timeout'  => 600,
        'stream'   => true,
        'filename' => $dest,
        'headers'  => array('Authorization' => 'Bearer ' . $token),
    ));
    if (is_wp_error($res)) {
        throw new RuntimeException('download failed: ' . $res->get_error_message());
    }
    if ((int)wp_remote_retrieve_response_code($res) !== 200) {
        throw new RuntimeException('download failed: HTTP ' . wp_remote_retrieve_response_code($res));
    }
}

// ---------------------------------------------------------------------------
// The site's folder tree
// ---------------------------------------------------------------------------
function kop_sd_norm($name) {
    return strtolower(trim(preg_replace('/\s+/', ' ', html_entity_decode((string)$name, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
}

$folders = array();      // id => [id, parent, norm, files (in this folder and below)]
$children = array();     // parent id => [ids]
foreach ($wpdb->get_results(
    "SELECT f.id, f.parent, f.type, f.name, COUNT(p.ID) AS n FROM {$fbv} f
     LEFT JOIN {$fbv_rel} r ON r.folder_id = f.id
     LEFT JOIN {$wpdb->posts} p ON p.ID = r.attachment_id
     GROUP BY f.id", ARRAY_A) as $row) {
    $folders[(int)$row['id']] = array('id' => (int)$row['id'], 'parent' => (int)$row['parent'], 'type' => (int)$row['type'],
                                      'norm' => kop_sd_norm($row['name']), 'files' => (int)$row['n']);
    $children[(int)$row['parent']][] = (int)$row['id'];
}
// Leave out FileBird Cloud's own mirror of Drive (its root is the one non-zero type).
$excluded = array();
$stack = array();
foreach ($folders as $f) {
    if ($f['type'] !== 0) $stack[] = $f['id'];
}
while ($stack) {
    $id = array_pop($stack);
    $excluded[$id] = true;
    foreach ($children[$id] ?? array() as $kid) $stack[] = $kid;
}
// Files held at or below each folder, so an empty duplicate never wins.
function kop_sd_total($id) {
    global $folders, $children;
    static $memo = array();
    if (!isset($memo[$id])) {
        $memo[$id] = $folders[$id]['files'];
        foreach ($children[$id] ?? array() as $kid) $memo[$id] += kop_sd_total($kid);
    }
    return $memo[$id];
}
$by_name = array();
foreach ($folders as $f) {
    if (!isset($excluded[$f['id']]) && $f['type'] === 0) $by_name[$f['norm']][] = $f['id'];
}

function kop_sd_child($parent, $norm) {
    global $folders, $children, $excluded;
    $best = 0;
    foreach ($children[$parent] ?? array() as $kid) {
        if (isset($excluded[$kid]) || $folders[$kid]['norm'] !== $norm) continue;
        if (!$best || kop_sd_total($kid) > kop_sd_total($best)) $best = $kid;
    }
    return $best;
}

function kop_sd_ancestor_names($id) {
    global $folders;
    $names = array();
    while (isset($folders[$id]) && $folders[$id]['parent']) {
        $id = $folders[$id]['parent'];
        $names[] = $folders[$id]['norm'] ?? '';
    }
    return $names;
}

/**
 * [folder id or 0, segments still to create, note]. A folder id of 0 with
 * nothing to create means the path can't be placed without guessing.
 */
function kop_sd_resolve(array $path) {
    global $by_name;
    $norms = array_map('kop_sd_norm', $path);
    if (!$norms) return array(0, array(), 'file sits at the top of the Drive folder');

    // 1. The Drive path followed down from the top of the site's tree.
    $id = 0;
    $depth = 0;
    foreach ($norms as $n) {
        $next = kop_sd_child($id, $n);
        if (!$next) break;
        $id = $next;
        $depth++;
    }
    if ($depth === count($norms)) return array($id, array(), 'same path');

    // 2. The deepest Drive folder name, wherever the site keeps it.
    $last = end($norms);
    $cands = $by_name[$last] ?? array();
    $live = array_values(array_filter($cands, function ($c) { return kop_sd_total($c) > 0; }));
    $pool = $live ?: $cands;
    if (count($pool) > 1) {
        $drive_parents = array_slice($norms, 0, -1);
        $pool = array_values(array_filter($pool, function ($c) use ($drive_parents) {
            return (bool)array_intersect(kop_sd_ancestor_names($c), $drive_parents);
        }));
    }
    if (count($pool) === 1) return array($pool[0], array(), 'same name');
    if (count($pool) > 1 || count($live) > 1) return array(0, array(), 'ambiguous: "' . end($path) . '" is several folders on the site');

    // 3. New to the site: create what's missing under the part that matched.
    return array($id, array_slice($path, $depth), $id ? 'new subfolder' : 'new top-level folder');
}

function kop_sd_create_folder($name, $parent) {
    global $wpdb, $fbv, $fbv_columns, $folders, $children;
    $row = array('name' => $name, 'parent' => $parent, 'type' => 0);
    $formats = array('%s', '%d', '%d');
    if (in_array('created_by', $fbv_columns, true)) { $row['created_by'] = 0; $formats[] = '%d'; }
    if (in_array('ord', $fbv_columns, true))        { $row['ord'] = 0;        $formats[] = '%d'; }
    if ($wpdb->insert($fbv, $row, $formats) === false) {
        throw new RuntimeException('could not create folder "' . $name . '": ' . $wpdb->last_error);
    }
    $id = (int)$wpdb->insert_id;
    $folders[$id] = array('id' => $id, 'parent' => $parent, 'type' => 0, 'norm' => kop_sd_norm($name), 'files' => 0);
    $children[$parent][] = $id;
    return $id;
}

// ---------------------------------------------------------------------------
// What the library already holds
// ---------------------------------------------------------------------------
$by_md5 = array();
$by_drive_id = array();
foreach ($wpdb->get_results(
    "SELECT m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m
     JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'attachment'
     WHERE m.meta_key IN ('_kop_import_md5', 'mdd_hash', '_kop_drive_file_id')", ARRAY_A) as $row) {
    if ($row['meta_key'] === '_kop_drive_file_id') {
        $by_drive_id[$row['meta_value']] = (int)$row['post_id'];
    } elseif (!isset($by_md5[$row['meta_value']])) {
        $by_md5[$row['meta_value']] = (int)$row['post_id'];
    }
}

/** Put an attachment in a folder: its FileBird folder if it has none, a tag otherwise. */
function kop_sd_file_in($attachment_id, $folder_id) {
    global $wpdb, $fbv_rel, $tags, $has_tags;
    $folder_now = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT folder_id FROM {$fbv_rel} WHERE attachment_id = %d LIMIT 1", $attachment_id));
    if ($folder_now === $folder_id) return 'already there';
    if (!$folder_now) {
        $wpdb->insert($fbv_rel, array('folder_id' => $folder_id, 'attachment_id' => $attachment_id), array('%d', '%d'));
        return 'filed';
    }
    if (!$has_tags) return 'left in its folder';
    $added = $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$tags} (folder_id, attachment_id) VALUES (%d, %d)", $folder_id, $attachment_id));
    return $added ? 'also shown here' : 'already shown here';
}

// ---------------------------------------------------------------------------
// The run
// ---------------------------------------------------------------------------
try {
    $root = njfb_cloud_get_main_folder('google_drive');
    if (!$root) throw new RuntimeException('FileBird Cloud has not made its Drive folder yet.');
    $files = kop_sd_list($root, array(), $SKIP_TOP, $opts['only']);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$counts = array();
$lines = array();
$imported = 0;
$budget_hit = '';
$made = array();   // path key => folder id, so a dry run counts each new folder once
$upload_dir = wp_upload_dir();

foreach ($files as $f) {
    $ext = $DOC_MIMES[$f['mimeType']] ?? '';
    if ($ext === '') continue;
    $label = implode('/', $f['path']) . '/' . $f['name'];
    $bump = function ($what, $detail = '') use (&$counts, &$lines, $label) {
        $counts[$what] = ($counts[$what] ?? 0) + 1;
        if ($detail !== '' || $what !== 'already in the library') {
            $lines[] = array('file' => $label, 'result' => $what . ($detail !== '' ? ': ' . $detail : ''));
        }
    };

    list($folder_id, $to_make, $how) = kop_sd_resolve($f['path']);
    if (!$folder_id && !$to_make) {
        $bump('not placed', $how);
        continue;
    }
    if ($to_make) {
        $key = implode("\x1f", array_map('kop_sd_norm', $f['path']));
        if (isset($made[$key])) {
            $folder_id = $made[$key];
        } elseif ($apply) {
            foreach ($to_make as $seg) $folder_id = kop_sd_create_folder($seg, $folder_id);
            $made[$key] = $folder_id;
            $counts['folders created'] = ($counts['folders created'] ?? 0) + count($to_make);
        } else {
            $made[$key] = -1;
            $folder_id = -1;
            $counts['folders to create'] = ($counts['folders to create'] ?? 0) + count($to_make);
        }
    }

    $md5 = $f['md5Checksum'] ?? '';
    $have = $by_drive_id[$f['id']] ?? ($md5 !== '' ? ($by_md5[$md5] ?? 0) : 0);
    if ($have) {
        if ($apply && $folder_id > 0) {
            $result = kop_sd_file_in($have, $folder_id);
            $bump('already in the library', $result === 'already there' || $result === 'already shown here' ? '' : $result);
        } else {
            $bump('already in the library');
        }
        continue;
    }
    if ((int)($f['size'] ?? 0) > $opts['max-mb'] * 1024 * 1024) {
        $bump('too big', round($f['size'] / 1048576) . ' MB');
        continue;
    }
    if (!$apply) {
        $bump('to import', $how);
        continue;
    }
    if ($imported >= $opts['limit'] || time() - $started > $opts['minutes'] * 60) {
        $budget_hit = $budget_hit ?: ($imported >= $opts['limit'] ? "reached --limit={$opts['limit']}" : "reached --minutes={$opts['minutes']}");
        $counts['left for the next run'] = ($counts['left for the next run'] ?? 0) + 1;
        continue;
    }

    $tmp = wp_tempnam($f['name']);
    try {
        kop_sd_download($f['id'], $tmp);
        $got = md5_file($tmp);
        if ($md5 !== '' && $got !== $md5) throw new RuntimeException('download was incomplete (md5 differs)');
        if (isset($by_md5[$got])) {                      // arrived under another Drive id
            kop_sd_file_in($by_md5[$got], $folder_id);
            $bump('already in the library', 'same content as #' . $by_md5[$got]);
            continue;
        }
        $filename = wp_unique_filename($upload_dir['path'], sanitize_file_name($f['name']));
        $dest = trailingslashit($upload_dir['path']) . $filename;
        if (!@rename($tmp, $dest) && !@copy($tmp, $dest)) throw new RuntimeException('could not move the file into uploads');
        @chmod($dest, 0644);
        $type = wp_check_filetype($filename);
        $attachment_id = wp_insert_attachment(array(
            'guid'           => trailingslashit($upload_dir['url']) . $filename,   // as media_handle_upload does
            'post_mime_type' => $type['type'] ?: $f['mimeType'],
            'post_title'     => preg_replace('/\.[^.]+$/', '', $f['name']),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ), $dest);
        if (is_wp_error($attachment_id) || !$attachment_id) {
            @unlink($dest);
            throw new RuntimeException('could not register the attachment');
        }
        // Recorded and filed before the slow part, so a run that dies making the
        // preview still leaves an attachment the next run recognises.
        update_post_meta($attachment_id, '_kop_import_md5', $got);
        update_post_meta($attachment_id, '_kop_drive_file_id', $f['id']);
        $by_md5[$got] = $attachment_id;
        $by_drive_id[$f['id']] = $attachment_id;
        kop_sd_file_in($attachment_id, $folder_id);
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $dest));
        $imported++;
        $bump('imported', '#' . $attachment_id . ' (' . $how . ')');
    } catch (Throwable $e) {
        $bump('failed', $e->getMessage());
    } finally {
        if (file_exists($tmp)) @unlink($tmp);
    }
}

if ($apply && ($imported || !empty($counts['folders created']))) {
    delete_transient('kop_hidden_preview_ids');
}
if ($apply) {
    // The first batches went in with the attachment page as their guid; give
    // them the file's address like every other upload.
    $fixed = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->posts} p
         JOIN {$wpdb->postmeta} d ON d.post_id = p.ID AND d.meta_key = '_kop_drive_file_id'
         JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_wp_attached_file'
         SET p.guid = CONCAT(%s, a.meta_value)
         WHERE p.guid NOT LIKE %s",
        trailingslashit($upload_dir['baseurl']), '%/wp-content/uploads/%'
    ));
    if ($fixed) $counts['guids fixed'] = (int)$fixed;
}
ksort($counts);
echo json_encode(array(
    'applied'  => $apply,
    'seconds'  => time() - $started,
    'counts'   => $counts,
    'stopped'  => $budget_hit,
    'items'    => $lines,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
