<?php
/**
 * Import documents from a directory on the server into the media library and
 * file them in FileBird folders.
 *
 * The files are copied to the server by hand (scp); they are never committed,
 * since the repository is public and most sources are third-party articles.
 * A manifest.json in the same directory says what each file is:
 *
 *   [
 *     {
 *       "file": "Some article (Paper, 1999-05-07).pdf",
 *       "title": "Some article (Paper, 1999-05-07)",     optional, defaults to the file name
 *       "folder": "Facility Name",                        FileBird folder, created when missing
 *       "parent": "ARIZONA",                              optional parent folder ("" = top level)
 *       "source_url": "https://..."                       optional, kept as _kop_source_url
 *     }
 *   ]
 *
 * Safe to run again: a file whose md5 already belongs to an attachment is
 * filed in the folder (if it is not there yet) instead of being imported twice.
 * A folder of the same name under the same parent is reused; when several
 * exist, the one that already holds files wins.
 *
 * CLI only:  php api/import-documents.php <dir> [apply]
 * Without "apply" it reports what it would do.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$dir   = isset($argv[1]) ? rtrim($argv[1], '/') : '';
$apply = in_array('apply', $argv, true);
if ($dir === '' || !is_dir($dir) || !is_readable($dir . '/manifest.json')) {
    fwrite(STDERR, "Usage: php api/import-documents.php <dir with manifest.json> [apply]\n");
    exit(2);
}
$manifest = json_decode((string)file_get_contents($dir . '/manifest.json'), true);
if (!is_array($manifest) || !$manifest) {
    fwrite(STDERR, "manifest.json is empty or not valid JSON\n");
    exit(2);
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

global $wpdb;
$fbv     = $wpdb->prefix . 'fbv';
$fbv_rel = $wpdb->prefix . 'fbv_attachment_folder';
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fbv)) !== $fbv) {
    fwrite(STDERR, "FileBird folders table not found\n");
    exit(2);
}
$fbv_columns = $wpdb->get_col("SHOW COLUMNS FROM {$fbv}");

/** Folder of this name under this parent: the copy holding files first, then the oldest. 0 when none. */
function kop_id_find_folder($name, $parent) {
    global $wpdb, $fbv, $fbv_rel;
    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT f.id FROM {$fbv} f
         LEFT JOIN {$fbv_rel} r ON r.folder_id = f.id
         LEFT JOIN {$wpdb->posts} p ON p.ID = r.attachment_id
         WHERE f.type = 0 AND f.parent = %d AND LOWER(f.name) = LOWER(%s)
         GROUP BY f.id ORDER BY COUNT(p.ID) DESC, f.id ASC LIMIT 1",
        $parent, $name
    ));
}

function kop_id_create_folder($name, $parent) {
    global $wpdb, $fbv, $fbv_columns;
    $row = array('name' => $name, 'parent' => $parent, 'type' => 0);
    $formats = array('%s', '%d', '%d');
    if (in_array('created_by', $fbv_columns, true)) { $row['created_by'] = 0; $formats[] = '%d'; }
    if (in_array('ord', $fbv_columns, true))        { $row['ord'] = 0;        $formats[] = '%d'; }
    if ($wpdb->insert($fbv, $row, $formats) === false) {
        throw new RuntimeException('Could not create folder "' . $name . '": ' . $wpdb->last_error);
    }
    return (int)$wpdb->insert_id;
}

$report = array();
$created_folders = array();   // "parent\x1fname" => id, so a dry run counts a folder once
foreach ($manifest as $n => $item) {
    $file   = isset($item['file']) ? basename((string)$item['file']) : '';
    $path   = $dir . '/' . $file;
    $folder = trim((string)($item['folder'] ?? ''));
    $parent = trim((string)($item['parent'] ?? ''));
    $line   = array('file' => $file);
    try {
        if ($file === '' || !is_file($path)) throw new RuntimeException('file not found');
        if ($folder === '')                  throw new RuntimeException('no folder named');
        $type = wp_check_filetype($file);
        if (empty($type['type']))            throw new RuntimeException('file type not allowed');

        // --- folder ----------------------------------------------------------
        $parent_id = 0;
        if ($parent !== '') {
            $parent_id = kop_id_find_folder($parent, 0);
            if (!$parent_id) throw new RuntimeException('parent folder "' . $parent . '" does not exist');
        }
        $fkey = $parent_id . "\x1f" . strtolower($folder);
        $folder_id = isset($created_folders[$fkey]) ? $created_folders[$fkey] : kop_id_find_folder($folder, $parent_id);
        if (!$folder_id) {
            $folder_id = $apply ? kop_id_create_folder($folder, $parent_id) : -1;
            $created_folders[$fkey] = $folder_id;
            $line['folder_created'] = true;
        }
        $line['folder'] = ($parent !== '' ? $parent . ' / ' : '') . $folder . ' (#' . $folder_id . ')';

        // --- attachment ------------------------------------------------------
        $md5 = md5_file($path);
        $attachment_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'attachment'
             WHERE m.meta_key IN ('_kop_import_md5', 'mdd_hash') AND m.meta_value = %s ORDER BY m.post_id ASC LIMIT 1",
            $md5
        ));
        if ($attachment_id) {
            $line['attachment'] = $attachment_id . ' (already in the library)';
        } elseif ($apply) {
            $upload = wp_upload_bits($file, null, (string)file_get_contents($path));
            if (!empty($upload['error'])) throw new RuntimeException('upload failed: ' . $upload['error']);
            $title = trim((string)($item['title'] ?? ''));
            if ($title === '') $title = preg_replace('/\.[^.]+$/', '', $file);
            $attachment_id = wp_insert_attachment(array(
                'post_mime_type' => $type['type'],
                'post_title'     => $title,
                'post_content'   => '',
                'post_status'    => 'inherit',
            ), $upload['file']);
            if (is_wp_error($attachment_id) || !$attachment_id) {
                @unlink($upload['file']);
                throw new RuntimeException('could not register the attachment');
            }
            wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));
            update_post_meta($attachment_id, '_kop_import_md5', $md5);
            if (!empty($item['source_url'])) {
                update_post_meta($attachment_id, '_kop_source_url', esc_url_raw((string)$item['source_url']));
            }
            $line['attachment'] = $attachment_id . ' (imported)';
            $line['url'] = wp_get_attachment_url($attachment_id);
        } else {
            $line['attachment'] = 'would import';
        }

        // --- file it ---------------------------------------------------------
        if ($apply && $attachment_id && $folder_id > 0) {
            $there = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$fbv_rel} WHERE attachment_id = %d AND folder_id = %d", $attachment_id, $folder_id
            ));
            if (!$there) {
                $elsewhere = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$fbv_rel} WHERE attachment_id = %d", $attachment_id
                ));
                if ($elsewhere) {
                    $line['filed'] = 'left where it is (already in another folder)';
                } else {
                    $wpdb->insert($fbv_rel, array('folder_id' => $folder_id, 'attachment_id' => $attachment_id), array('%d', '%d'));
                    $line['filed'] = true;
                }
            }
        }
    } catch (Throwable $e) {
        $line['error'] = $e->getMessage();
    }
    $report[] = $line;
}

if ($apply) {
    delete_transient('kop_hidden_preview_ids');
}
echo json_encode(array('applied' => $apply, 'items' => $report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
