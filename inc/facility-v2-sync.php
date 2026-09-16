<?php
/**
 * Keep the v2 facility model in step with the legacy tables during the bake
 * period (docs/DATA-MODEL-MIGRATION.md, phases 3-4).
 *
 * Until the phase 4 cutover every save still writes the legacy tables
 * (facilities_master, locations_master). v2 is derived from them, never edited
 * directly, so re-deriving is always correct: every 10 minutes WP-cron checks a
 * fingerprint of the legacy tables and, when it changed, rebuilds the plan and
 * applies it (unchanged rows are skipped). Nothing happens until the migration
 * has been applied once from api/migrate-facility-model.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['kop_ten_minutes'])) {
        $schedules['kop_ten_minutes'] = array('interval' => 600, 'display' => 'Every 10 minutes (KOP)');
    }
    return $schedules;
});

add_action('init', function () {
    if (!wp_next_scheduled('kop_facility_v2_sync')) {
        wp_schedule_event(time() + 300, 'kop_ten_minutes', 'kop_facility_v2_sync');
    }
});

add_action('kop_facility_v2_sync', 'kop_facility_v2_sync_run');

if (!function_exists('kop_facility_v2_pdo')) {
    /** A PDO connection from the WordPress DB constants. */
    function kop_facility_v2_pdo() {
        $host = DB_HOST;
        $port = null;
        $socket = null;
        if (strpos($host, ':') !== false) {
            list($host, $extra) = explode(':', $host, 2);
            if (ctype_digit($extra)) $port = (int)$extra;
            else $socket = $extra;
        }
        $dsn = 'mysql:dbname=' . DB_NAME . ';charset=utf8mb4';
        $dsn .= $socket ? ';unix_socket=' . $socket : ';host=' . $host . ($port ? ';port=' . $port : '');
        return new PDO($dsn, DB_USER, DB_PASSWORD, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
    }
}

if (!function_exists('kop_facility_v2_sync_run')) {
    function kop_facility_v2_sync_run() {
        global $wpdb;
        require_once __DIR__ . '/facility-migration.php';
        try {
            $pdo = kop_facility_v2_pdo();
            if (!kop_migration_tables_exist($pdo, $wpdb->prefix)
                || !kop_migration_state_get($pdo, $wpdb->prefix, 'applied', false)) {
                return;   // not migrated yet
            }
            @set_time_limit(300);
            $result = kop_migration_sync($pdo, $wpdb->prefix, false);
            if (empty($result['skipped'])) {
                error_log('kop_facility_v2_sync: ' . json_encode($result));
            }
        } catch (Throwable $e) {
            error_log('kop_facility_v2_sync failed: ' . $e->getMessage());
        }
    }
}
