<?php
/**
 * Backfill FileBird folders for operators and their facilities, read from the
 * facility tables (kop_operators, kop_operator_facilities, facilities_v2).
 *
 * For each operator, uses its stored document folder when that folder still
 * exists, otherwise a top-level folder named after the operator. For each
 * facility the operator currently runs, uses the facility's stored document
 * folder, otherwise a child folder named after the facility.
 *
 * A folder is created only when no folder of that name exists anywhere: the
 * facility pages match folders by name wherever they sit, and the tree
 * already holds most programs two or three times (state folder, parent
 * company folder, the old Google Drive skeleton), mostly as empty copies.
 * Where several share a name, the copy holding the most files is used, the
 * one under the operator first. Safe to run repeatedly. Facilities with no
 * operator are only counted: where their folder belongs (which state
 * folder) is a filing decision this tool cannot make.
 *
 * Browser: /api/backfill-filebird-folders.php?run=1&dry=1
 * Execute: /api/backfill-filebird-folders.php?run=1
 * CLI:     php api/backfill-filebird-folders.php [dry]
 */

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

$is_cli = php_sapi_name() === 'cli';
$dry_run = $is_cli
    ? in_array('dry', $argv ?? [], true)
    : !empty($_GET['dry']);

if (!$is_cli) {
    if (!defined('ABSPATH')) {
        $current = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            $current = dirname($current);
            if (file_exists($current . '/wp-load.php')) {
                require_once $current . '/wp-load.php';
                break;
            }
        }
    }
    if (($_GET['run'] ?? null) !== '1'
        || !function_exists('current_user_can')
        || !current_user_can('manage_options')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Backfill requires ?run=1 and an admin session, or CLI execution.',
        ]);
        exit;
    }
}

global $wpdb;
$folder_table = $wpdb->prefix . 'fbv';

if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $folder_table)) !== $folder_table) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'error' => 'FileBird folders table not found. Is FileBird installed?',
    ]);
    exit;
}

$columns = $wpdb->get_col("SHOW COLUMNS FROM {$folder_table}");
$has_created_by = in_array('created_by', $columns, true);
$has_ord = in_array('ord', $columns, true);

function kop_bff_norm(string $value): string {
    $value = html_entity_decode(trim($value), ENT_QUOTES, 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value);
    return strtolower($value);
}

try {
    // One folder per (parent, name): the copy holding the most files.
    $rel_table = $wpdb->prefix . 'fbv_attachment_folder';
    $has_rel = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $rel_table)) === $rel_table;
    $rows = $has_rel
        ? $wpdb->get_results("SELECT f.id, f.name, f.parent, COUNT(r.attachment_id) AS files
            FROM {$folder_table} f LEFT JOIN {$rel_table} r ON r.folder_id = f.id
            WHERE f.type = 0 GROUP BY f.id, f.name, f.parent ORDER BY files DESC, f.id ASC")
        : $wpdb->get_results("SELECT id, name, parent, 0 AS files FROM {$folder_table} WHERE type = 0 ORDER BY id ASC");
    $folders = [];
    $anywhere = [];
    $folder_ids = [];
    foreach ($rows as $row) {
        $parent = (int)$row->parent;
        $folder_ids[(int)$row->id] = true;
        $key = kop_bff_norm((string)$row->name);
        if (!isset($folders[$parent][$key])) {
            $folders[$parent][$key] = [
                'id' => (int)$row->id,
                'name' => (string)$row->name,
                'parent' => $parent,
            ];
        }
        if (!isset($anywhere[$key])) {
            $anywhere[$key] = (int)$row->id;
        }
    }

    $stats = [
        'operators_scanned' => 0,
        'facilities_scanned' => 0,
        'stored_folder_used' => 0,
        'folders_reused' => 0,
        'found_elsewhere' => 0,
        'folders_created' => 0,
        'skipped_without_name' => 0,
        'facilities_without_operator' => 0,
        'sample_created' => [],
    ];

    $get_or_create = function (string $name, int $parent) use (
        &$folders, &$anywhere, &$stats, $wpdb, $folder_table, $has_created_by, $has_ord, $dry_run
    ): int {
        $key = kop_bff_norm($name);
        if ($key === '') {
            return 0;
        }
        if (isset($folders[$parent][$key])) {
            $stats['folders_reused']++;
            return $folders[$parent][$key]['id'];
        }
        if (isset($anywhere[$key])) {
            $stats['found_elsewhere']++;
            return $anywhere[$key];
        }
        if ($dry_run) {
            static $sentinel = 0;
            $sentinel--;
            $id = $sentinel;
        } else {
            $row = ['name' => $name, 'parent' => $parent, 'type' => 0];
            $formats = ['%s', '%d', '%d'];
            if ($has_created_by) {
                $row['created_by'] = function_exists('get_current_user_id')
                    ? get_current_user_id()
                    : 0;
                $formats[] = '%d';
            }
            if ($has_ord) {
                $row['ord'] = 0;
                $formats[] = '%d';
            }
            if ($wpdb->insert($folder_table, $row, $formats) === false) {
                throw new RuntimeException('Could not create folder: ' . $wpdb->last_error);
            }
            $id = (int)$wpdb->insert_id;
        }
        $folders[$parent][$key] = ['id' => $id, 'name' => $name, 'parent' => $parent];
        $anywhere[$key] = $id;
        $stats['folders_created']++;
        if (count($stats['sample_created']) < 30) {
            $stats['sample_created'][] = ['name' => $name, 'parent' => $parent];
        }
        return $id;
    };

    $t_fac = function_exists('kop_facility_table') ? kop_facility_table('facilities') : 'facilities_v2';
    $t_ops = function_exists('kop_facility_table') ? kop_facility_table('operators') : $wpdb->prefix . 'kop_operators';
    $t_opf = function_exists('kop_facility_table') ? kop_facility_table('operator_facilities') : $wpdb->prefix . 'kop_operator_facilities';
    foreach ([$t_fac, $t_ops, $t_opf] as $table) {
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            throw new RuntimeException("Table {$table} not found; the facility tables are not set up on this site.");
        }
    }

    // A stored folder id counts only while that folder still exists.
    $stored_folder = function ($id) use ($folder_ids): int {
        $id = (int)$id;
        return ($id > 0 && isset($folder_ids[$id])) ? $id : 0;
    };

    $facilities = [];
    foreach ($wpdb->get_results("SELECT id, name, JSON_EXTRACT(json_data, '$.documentFolderId') AS folder FROM {$t_fac}") as $row) {
        $facilities[(int)$row->id] = ['name' => trim((string)$row->name), 'folder' => (int)$row->folder];
    }
    $by_operator = [];
    foreach ($wpdb->get_results("SELECT operator_id, facility_id FROM {$t_opf} WHERE relationship = 'current' ORDER BY sort_order, facility_id") as $row) {
        $by_operator[(int)$row->operator_id][] = (int)$row->facility_id;
    }
    $has_operator = [];

    foreach ($wpdb->get_results("SELECT id, name, document_folder_id FROM {$t_ops} ORDER BY id") as $op) {
        $operator_name = trim((string)$op->name);
        if ($operator_name === '') {
            continue;
        }
        $stats['operators_scanned']++;
        $operator_folder = $stored_folder($op->document_folder_id);
        if ($operator_folder > 0) {
            $stats['stored_folder_used']++;
        } else {
            $operator_folder = $get_or_create($operator_name, 0);
        }

        foreach ($by_operator[(int)$op->id] ?? [] as $facility_id) {
            if (!isset($facilities[$facility_id])) {
                continue;
            }
            $has_operator[$facility_id] = true;
            $stats['facilities_scanned']++;
            $facility = $facilities[$facility_id];
            if ($stored_folder($facility['folder']) > 0) {
                $stats['stored_folder_used']++;
                continue;
            }
            if ($facility['name'] === '') {
                $stats['skipped_without_name']++;
                continue;
            }
            $get_or_create($facility['name'], $operator_folder);
        }
    }
    $stats['facilities_without_operator'] = count(array_diff_key($facilities, $has_operator));
    $stats['without_operator_and_folder'] = 0;
    foreach (array_diff_key($facilities, $has_operator) as $facility) {
        if ($stored_folder($facility['folder']) === 0 && !isset($anywhere[kop_bff_norm($facility['name'])])) {
            $stats['without_operator_and_folder']++;
        }
    }

    echo json_encode([
        'success' => true,
        'dry_run' => (bool)$dry_run,
        'stats' => $stats,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
