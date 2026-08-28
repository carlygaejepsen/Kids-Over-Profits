<?php
/**
 * Migration: Add facility-link columns to wiki_master and wiki_submissions.
 *
 * Run once:  api/migrate-wiki-facility-link.php?run=1
 *
 * Adds:
 *   facility_unique_name  VARCHAR(255) – the unique_name FK into facilities_master
 *   facility_link_status  ENUM('suggested','confirmed') NULL – NULL means unlinked
 */

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

// Schema changes are admin-only (CLI allowed). A query param is not security.
if (php_sapi_name() !== 'cli'
    && (!function_exists('current_user_can') || !current_user_can('manage_options'))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

if (($_GET['run'] ?? '') !== '1') {
    echo json_encode(['success' => false, 'error' => 'Add ?run=1 to execute this migration.']);
    exit;
}

$results = [];

$alterations = [
    'wiki_master' => [
        "ALTER TABLE `wiki_master`
            ADD COLUMN IF NOT EXISTS `facility_unique_name` VARCHAR(255) DEFAULT NULL
                COMMENT 'FK to facilities_master.unique_name',
            ADD COLUMN IF NOT EXISTS `facility_link_status`
                ENUM('suggested','confirmed') DEFAULT NULL
                COMMENT 'NULL = unlinked, suggested = pending review, confirmed = admin-verified',
            ADD KEY IF NOT EXISTS `facility_link` (`facility_unique_name`)",
    ],
    'wiki_submissions' => [
        "ALTER TABLE `wiki_submissions`
            ADD COLUMN IF NOT EXISTS `facility_unique_name` VARCHAR(255) DEFAULT NULL
                COMMENT 'FK to facilities_master.unique_name',
            ADD COLUMN IF NOT EXISTS `facility_link_status`
                ENUM('suggested','confirmed') DEFAULT NULL
                COMMENT 'NULL = unlinked, suggested = pending review, confirmed = admin-verified',
            ADD KEY IF NOT EXISTS `facility_link` (`facility_unique_name`)",
    ],
];

foreach ($alterations as $table => $sqls) {
    foreach ($sqls as $sql) {
        try {
            $pdo->exec($sql);
            $results[$table] = 'ok';
        } catch (PDOException $e) {
            // MySQL 5.x does not support IF NOT EXISTS on ADD COLUMN.
            // Try the column-by-column fallback.
            $msg = $e->getMessage();
            if (strpos($msg, 'Duplicate column') !== false
                || strpos($msg, '1060') !== false) {
                $results[$table] = 'columns already exist (skipped)';
            } else {
                // Try adding columns one at a time without IF NOT EXISTS
                $fallbackResults = [];
                foreach (['facility_unique_name', 'facility_link_status'] as $col) {
                    try {
                        $colDef = $col === 'facility_unique_name'
                            ? "VARCHAR(255) DEFAULT NULL"
                            : "ENUM('suggested','confirmed') DEFAULT NULL";
                        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $colDef");
                        $fallbackResults[] = "$col added";
                    } catch (PDOException $colEx) {
                        $colMsg = $colEx->getMessage();
                        if (strpos($colMsg, 'Duplicate column') !== false
                            || strpos($colMsg, '1060') !== false) {
                            $fallbackResults[] = "$col already exists";
                        } else {
                            $fallbackResults[] = "$col failed: " . $colMsg;
                        }
                    }
                }
                // Try adding the index
                try {
                    $pdo->exec("ALTER TABLE `$table` ADD KEY `facility_link` (`facility_unique_name`)");
                    $fallbackResults[] = 'index added';
                } catch (PDOException $idxEx) {
                    $fallbackResults[] = 'index skipped (may already exist)';
                }
                $results[$table] = implode('; ', $fallbackResults);
            }
        }
    }
}

echo json_encode(['success' => true, 'results' => $results]);
