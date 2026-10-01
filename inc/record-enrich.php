<?php
/**
 * Every hour, fill in a few news and lawsuit rows that arrived as a bare link
 * from KOP Tools > Drive Docs or the browser extension (api/lib-record-enrich.php).
 * A backlog runs at once with api/enrich-imported-records.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('init', function () {
    if (!wp_next_scheduled('kop_enrich_records_hourly')) {
        wp_schedule_event(time() + 300, 'hourly', 'kop_enrich_records_hourly');
    }
});

add_action('kop_enrich_records_hourly', function () {
    if (get_transient('kop_enrich_records_lock')) {
        return;
    }
    set_transient('kop_enrich_records_lock', 1, 10 * MINUTE_IN_SECONDS);
    try {
        require_once get_stylesheet_directory() . '/api/lib-record-enrich.php';
        if (function_exists('kop_ext_load_record_libs')) {
            kop_ext_load_record_libs();
        }
        $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
        if ($pdo) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            @set_time_limit(300);
            kop_enrich_run($pdo, 'news', 6, 150, true);
            kop_enrich_run($pdo, 'lawsuit', 2, 90, true);
        }
    } catch (Throwable $e) {
        error_log('kop record enrich: ' . $e->getMessage());
    } finally {
        delete_transient('kop_enrich_records_lock');
    }
});
