<?php
/**
 * FileBird Folder Manager API (admin-only)
 *
 * In-page create / rename / move / delete of FileBird folders, backing the
 * folder manager on the Data Manager page. Writes directly to the FileBird
 * `{prefix}fbv` table (the same table the theme already reads from), since the
 * theme's folder features all read the table directly.
 *
 * Endpoints
 * ---------
 * GET  ?action=list
 *      Return [{id, name, parent}] for every folder (type = 0).
 *
 * POST {action:"create", name, parent?}        -> {id}
 * POST {action:"rename", id, name}
 * POST {action:"move",   id, parent}           (parent 0 = top level)
 * POST {action:"delete", id}                   children + files move up to the
 *                                               deleted folder's parent
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

// Ensure WordPress (and $wpdb) are available, then require admin.
if (!function_exists('current_user_can')) {
    $kop_wp = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $kop_wp = dirname($kop_wp);
        if (file_exists($kop_wp . '/wp-load.php')) {
            require_once $kop_wp . '/wp-load.php';
            break;
        }
    }
}
if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}

global $wpdb;
$table  = $wpdb->prefix . 'fbv';
$attach = $wpdb->prefix . 'fbv_attachment_folder';

// FileBird must be installed.
if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'FileBird folders table not found. Is FileBird installed?']);
    exit;
}

// Discover which optional columns this FileBird version has.
$columns = $wpdb->get_col("SHOW COLUMNS FROM $table");
$hasCreatedBy = in_array('created_by', $columns, true);
$hasOrd       = in_array('ord', $columns, true);

/** All folder ids in the subtree rooted at $id (including $id). Guards moves. */
function kop_fb_descendants($wpdb, $table, $id) {
    $ids = [(int)$id];
    $frontier = [(int)$id];
    $guard = 0;
    while ($frontier && $guard++ < 1000) {
        $in = implode(',', array_map('intval', $frontier));
        $children = $wpdb->get_col("SELECT id FROM $table WHERE parent IN ($in)");
        $children = array_map('intval', $children);
        $new = array_diff($children, $ids);
        if (!$new) break;
        $ids = array_merge($ids, $new);
        $frontier = $new;
    }
    return $ids;
}

/** Fetch one folder row or null. */
function kop_fb_get($wpdb, $table, $id) {
    return $wpdb->get_row($wpdb->prepare("SELECT id, name, parent FROM $table WHERE id = %d", $id), ARRAY_A);
}

try {
    // ---- GET list ----
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $rows = $wpdb->get_results("SELECT id, name, parent FROM $table WHERE type = 0 ORDER BY name ASC", ARRAY_A);
        $rows = array_map(function ($r) {
            return ['id' => (int)$r['id'], 'name' => $r['name'], 'parent' => (int)$r['parent']];
        }, $rows ?: []);
        echo json_encode(['success' => true, 'folders' => $rows]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        exit;
    }
    $action = $input['action'] ?? '';

    // ---- create ----
    if ($action === 'create') {
        $name   = trim((string)($input['name'] ?? ''));
        $parent = (int)($input['parent'] ?? 0);
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Folder name is required']);
            exit;
        }
        if ($parent > 0 && !kop_fb_get($wpdb, $table, $parent)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Parent folder not found']);
            exit;
        }

        $data    = ['name' => $name, 'parent' => $parent, 'type' => 0];
        $formats = ['%s', '%d', '%d'];
        if ($hasCreatedBy) { $data['created_by'] = get_current_user_id(); $formats[] = '%d'; }
        if ($hasOrd)       { $data['ord'] = 0; $formats[] = '%d'; }

        $ok = $wpdb->insert($table, $data, $formats);
        if ($ok === false) {
            throw new RuntimeException('Insert failed: ' . $wpdb->last_error);
        }
        echo json_encode(['success' => true, 'id' => (int)$wpdb->insert_id, 'name' => $name, 'parent' => $parent]);
        exit;
    }

    // ---- rename ----
    if ($action === 'rename') {
        $id   = (int)($input['id'] ?? 0);
        $name = trim((string)($input['name'] ?? ''));
        if (!$id || $name === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'id and name are required']);
            exit;
        }
        if (!kop_fb_get($wpdb, $table, $id)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Folder not found']);
            exit;
        }
        $wpdb->update($table, ['name' => $name], ['id' => $id], ['%s'], ['%d']);
        echo json_encode(['success' => true, 'id' => $id, 'name' => $name]);
        exit;
    }

    // ---- move ----
    if ($action === 'move') {
        $id     = (int)($input['id'] ?? 0);
        $parent = (int)($input['parent'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'id is required']);
            exit;
        }
        if (!kop_fb_get($wpdb, $table, $id)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Folder not found']);
            exit;
        }
        if ($parent === $id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'A folder cannot be its own parent']);
            exit;
        }
        if ($parent > 0) {
            if (!kop_fb_get($wpdb, $table, $parent)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Destination folder not found']);
                exit;
            }
            // Prevent moving a folder into one of its own descendants.
            $descendants = kop_fb_descendants($wpdb, $table, $id);
            if (in_array($parent, $descendants, true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Cannot move a folder into itself or one of its subfolders']);
                exit;
            }
        }
        $wpdb->update($table, ['parent' => $parent], ['id' => $id], ['%d'], ['%d']);
        echo json_encode(['success' => true, 'id' => $id, 'parent' => $parent]);
        exit;
    }

    // ---- delete ----
    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'id is required']);
            exit;
        }
        $row = kop_fb_get($wpdb, $table, $id);
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Folder not found']);
            exit;
        }
        $parent = (int)$row['parent'];

        // Reparent direct children up to this folder's parent so nothing is orphaned.
        $wpdb->update($table, ['parent' => $parent], ['parent' => $id], ['%d'], ['%d']);

        // Move any attachments in this folder up to the parent (FileBird keeps the
        // file in the Media Library; only the folder association changes).
        if ($wpdb->get_var("SHOW TABLES LIKE '$attach'") === $attach) {
            $attachCols = $wpdb->get_col("SHOW COLUMNS FROM $attach");
            if (in_array('folder_id', $attachCols, true)) {
                $wpdb->update($attach, ['folder_id' => $parent], ['folder_id' => $id], ['%d'], ['%d']);
            }
        }

        $wpdb->delete($table, ['id' => $id], ['%d']);
        echo json_encode(['success' => true, 'id' => $id, 'reparented_to' => $parent]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Unknown action '$action'. Use: create, rename, move, delete"]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
