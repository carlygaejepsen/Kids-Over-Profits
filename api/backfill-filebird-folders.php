<?php
/**
 * Backfill FileBird folders for operators and facilities in the master tables.
 *
 * For each operator, creates a top-level folder named after the operator. For
 * each nested facility/program, creates a child folder named after the
 * facility. Existing folders with the same name under the same parent are
 * reused, so this is safe to run repeatedly.
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

function kop_bff_name($value): string {
    return is_string($value) ? trim($value) : '';
}

function kop_bff_operator_name(array $data): string {
    $operator = $data['operator'] ?? null;
    if (is_string($operator)) {
        return kop_bff_name($operator);
    }
    if (is_array($operator)) {
        foreach (['name', 'currentName', 'displayName'] as $key) {
            $name = kop_bff_name($operator[$key] ?? '');
            if ($name !== '') {
                return $name;
            }
        }
    }
    foreach (['operatorName', 'currentOperator', 'company', 'organization'] as $key) {
        $name = kop_bff_name($data[$key] ?? '');
        if ($name !== '') {
            return $name;
        }
    }
    return '';
}

function kop_bff_facility_name(array $facility): string {
    $identification = $facility['identification'] ?? null;
    if (is_array($identification)) {
        foreach (['name', 'currentName', 'displayName'] as $key) {
            $name = kop_bff_name($identification[$key] ?? '');
            if ($name !== '') {
                return $name;
            }
        }
    }
    foreach (['name', 'program_name', 'programName', 'facility_name'] as $key) {
        $name = kop_bff_name($facility[$key] ?? '');
        if ($name !== '') {
            return $name;
        }
    }
    return '';
}

function kop_bff_facility_operator(array $facility): string {
    return kop_bff_operator_name($facility);
}

function kop_bff_unwrap(array $payload): array {
    if (isset($payload['data']) && is_array($payload['data'])) {
        $inner = $payload['data'];
        $guard = 0;
        while (isset($inner['data']) && is_array($inner['data'])
            && !isset($inner['operator']) && !isset($inner['facilities'])
            && $guard++ < 2) {
            $inner = $inner['data'];
        }
        $payload['data'] = $inner;
    }
    return $payload;
}

function kop_bff_data(array $payload): array {
    if (isset($payload['data']) && is_array($payload['data'])) {
        return $payload['data'];
    }
    return $payload;
}

try {
    $folders = [];
    $rows = $wpdb->get_results("SELECT id, name, parent FROM {$folder_table} WHERE type = 0");
    foreach ($rows as $row) {
        $parent = (int)$row->parent;
        $folders[$parent][kop_bff_norm((string)$row->name)] = [
            'id' => (int)$row->id,
            'name' => (string)$row->name,
            'parent' => $parent,
        ];
    }

    $stats = [
        'operators_scanned' => 0,
        'facilities_scanned' => 0,
        'folders_reused' => 0,
        'folders_created' => 0,
        'skipped_without_name' => 0,
        'sample_created' => [],
    ];

    $get_or_create = function (string $name, int $parent) use (
        &$folders, &$stats, $wpdb, $folder_table, $has_created_by, $has_ord, $dry_run
    ): int {
        $key = kop_bff_norm($name);
        if ($key === '') {
            return 0;
        }
        if (isset($folders[$parent][$key])) {
            $stats['folders_reused']++;
            return $folders[$parent][$key]['id'];
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
        $stats['folders_created']++;
        if (count($stats['sample_created']) < 30) {
            $stats['sample_created'][] = ['name' => $name, 'parent' => $parent];
        }
        return $id;
    };

    foreach (['facilities_master', 'locations_master'] as $table) {
        $rows = $wpdb->get_results("SELECT unique_name, json_data FROM {$table}");
        foreach ($rows as $row) {
            $payload = json_decode((string)$row->json_data, true);
            if (!is_array($payload) || !empty($payload['__facility_ref'])) {
                continue;
            }
            $payload = kop_bff_unwrap($payload);
            $data = kop_bff_data($payload);
            $project_operator = kop_bff_operator_name($data);
            if ($project_operator === '' && $table === 'facilities_master') {
                $project_operator = kop_bff_name((string)$row->unique_name);
            }

            $operator_folder = 0;
            if ($project_operator !== '') {
                $operator_folder = $get_or_create($project_operator, 0);
            }

            $facilities = $data['facilities'] ?? $payload['facilities'] ?? [];
            if (!is_array($facilities)) {
                $facilities = [];
            }
            if ($project_operator !== '') {
                $stats['operators_scanned']++;
            }

            foreach ($facilities as $facility) {
                if (!is_array($facility)) {
                    continue;
                }
                $stats['facilities_scanned']++;
                $facility_name = kop_bff_facility_name($facility);
                if ($facility_name === '') {
                    $stats['skipped_without_name']++;
                    continue;
                }
                $operator = kop_bff_facility_operator($facility);
                if ($operator === '') {
                    $operator = $project_operator;
                }
                $parent = $operator === $project_operator && $operator_folder > 0
                    ? $operator_folder
                    : ($operator !== '' ? $get_or_create($operator, 0) : 0);
                $get_or_create($facility_name, $parent);
            }
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
