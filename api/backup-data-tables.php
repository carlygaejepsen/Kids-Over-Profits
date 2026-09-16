<?php
/**
 * Phase 0 backup for the facility data model migration
 * (docs/DATA-MODEL-MIGRATION.md, phase 0 step 3).
 *
 * Copies facilities_master, locations_master and referrers_master into
 * dated `_bak_<stamp>` tables so every later phase has a rollback point.
 * Writes to production are blocked over SSH, so this ships as an admin-only
 * endpoint the site owner opens in a browser:
 *
 *   https://kidsoverprofits.org/wp-content/themes/child/api/backup-data-tables.php
 *   ... ?stamp=20260916            create the backups (default stamp: today)
 *   ... ?stamp=20260916&replace=1  drop and recreate a stamp that already exists
 *   ... (no args)                  dry run: report what would be created
 *
 * CREATE TABLE ... SELECT copies rows but not indexes; that is fine for a
 * restore source. The row count of each copy is verified against the original
 * before the response reports success.
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';

// --- Admin authentication (same pattern as api/save-master.php) -------------
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

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No database connection (api/config.php).']);
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$prefix = (isset($table_prefix) && is_string($table_prefix)) ? $table_prefix : '';

/** Resolve a base table name to the prefixed or unprefixed table that exists. */
function kop_backup_resolve_table(PDO $pdo, $base, $prefix) {
    $candidates = $prefix ? [$prefix . $base, $base] : [$base];
    foreach ($candidates as $candidate) {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :t');
        $stmt->execute([':t' => $candidate]);
        if ($stmt->fetchColumn()) {
            return $candidate;
        }
    }
    return null;
}

function kop_backup_table_exists(PDO $pdo, $name) {
    $stmt = $pdo->prepare('SHOW TABLES LIKE :t');
    $stmt->execute([':t' => $name]);
    return (bool)$stmt->fetchColumn();
}

function kop_backup_quote_ident($name) {
    return '`' . str_replace('`', '``', $name) . '`';
}

$stamp = isset($_GET['stamp']) ? preg_replace('/[^0-9]/', '', (string)$_GET['stamp']) : '';
if ($stamp === '') {
    $stamp = date('Ymd');
}
$replace = !empty($_GET['replace']);
$dry_run = !isset($_GET['stamp']) && !$replace;

$bases = ['facilities_master', 'locations_master', 'referrers_master'];
$report = [];
$ok = true;

foreach ($bases as $base) {
    $source = kop_backup_resolve_table($pdo, $base, $prefix);
    $entry = [
        'table'  => $base,
        'source' => $source,
        'backup' => null,
        'rows'   => null,
        'status' => null,
    ];

    if ($source === null) {
        $entry['status'] = 'source table not found';
        $ok = false;
        $report[] = $entry;
        continue;
    }

    $backup = $source . '_bak_' . $stamp;
    $entry['backup'] = $backup;

    try {
        $source_rows = (int)$pdo->query('SELECT COUNT(*) FROM ' . kop_backup_quote_ident($source))->fetchColumn();
        $entry['rows'] = ['source' => $source_rows];

        if ($dry_run) {
            $entry['status'] = kop_backup_table_exists($pdo, $backup)
                ? 'dry run: backup already exists'
                : 'dry run: would create';
            $report[] = $entry;
            continue;
        }

        if (kop_backup_table_exists($pdo, $backup)) {
            if (!$replace) {
                $entry['rows']['backup'] = (int)$pdo->query('SELECT COUNT(*) FROM ' . kop_backup_quote_ident($backup))->fetchColumn();
                $entry['status'] = 'already exists (pass replace=1 to recreate)';
                $report[] = $entry;
                continue;
            }
            $pdo->exec('DROP TABLE ' . kop_backup_quote_ident($backup));
        }

        $pdo->exec(
            'CREATE TABLE ' . kop_backup_quote_ident($backup) .
            ' SELECT * FROM ' . kop_backup_quote_ident($source)
        );

        $backup_rows = (int)$pdo->query('SELECT COUNT(*) FROM ' . kop_backup_quote_ident($backup))->fetchColumn();
        $entry['rows']['backup'] = $backup_rows;

        if ($backup_rows !== $source_rows) {
            $entry['status'] = 'ROW COUNT MISMATCH - backup is not trustworthy';
            $ok = false;
        } else {
            $entry['status'] = 'created';
        }
    } catch (Throwable $e) {
        $entry['status'] = 'error: ' . $e->getMessage();
        $ok = false;
    }

    $report[] = $entry;
}

echo json_encode([
    'success' => $ok,
    'dry_run' => $dry_run,
    'stamp'   => $stamp,
    'tables'  => $report,
    'note'    => $dry_run
        ? 'Dry run only. Re-open with ?stamp=' . $stamp . ' to create the backups.'
        : 'Keep these tables until phase 5 of docs/DATA-MODEL-MIGRATION.md is signed off.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
