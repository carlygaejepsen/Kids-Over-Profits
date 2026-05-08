<?php
/**
 * One-time migration: rewrite _wp_page_template post_meta values from
 * "page-X.php" to "templates/page-X.php" so existing pages keep using
 * the templates that were just relocated into the templates/ directory.
 *
 * Usage:
 *   ?dry_run=1   (default)  preview which pages would change
 *   ?write=1                actually run the UPDATE
 *
 * Requires manage_options. Idempotent — re-running after a successful migration
 * is a no-op because every meta value will already start with "templates/".
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

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

$is_cli = php_sapi_name() === 'cli';
$is_admin = function_exists('current_user_can') && current_user_can('manage_options');

if (!$is_cli && !$is_admin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin privileges required']);
    exit;
}

$write_mode = !empty($_GET['write']);

// Templates that previously lived at the theme root and now live in templates/.
$migrate_map = [
    'page-state.php'              => 'templates/page-state.php',
    'page-state-reports.php'      => 'templates/page-state-reports.php',
    'page-data.php'               => 'templates/page-data.php',
    'page-admin-data.php'         => 'templates/page-admin-data.php',
    'page-admin-submissions.php'  => 'templates/page-admin-submissions.php',
    'page-admin-lawsuits.php'     => 'templates/page-admin-lawsuits.php',
    'page-admin-legislation.php'  => 'templates/page-admin-legislation.php',
    'page-news-feed.php'          => 'templates/page-news-feed.php',
    'page-news-processor.php'     => 'templates/page-news-processor.php',
    'page-wiki-feed.php'          => 'templates/page-wiki-feed.php',
    'page-wiki-editor.php'        => 'templates/page-wiki-editor.php',
    'page-tti-program-index.php'  => 'templates/page-tti-program-index.php',
    'page-location-index.php'     => 'templates/page-location-index.php',
    'page-referrer-index.php'     => 'templates/page-referrer-index.php',
];

global $wpdb;

$results = [];
$total_updated = 0;

foreach ($migrate_map as $old => $new) {
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta}
         WHERE meta_key = '_wp_page_template' AND meta_value = %s",
        $old
    ), ARRAY_A);

    $count = is_array($rows) ? count($rows) : 0;

    $page_titles = [];
    if ($count > 0) {
        $ids = array_map('intval', wp_list_pluck($rows, 'post_id'));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $titles_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($placeholders)",
            ...$ids
        ), ARRAY_A);
        foreach ($titles_rows as $tr) {
            $page_titles[] = ['id' => (int)$tr['ID'], 'title' => $tr['post_title']];
        }
    }

    if ($write_mode && $count > 0) {
        $updated = $wpdb->update(
            $wpdb->postmeta,
            ['meta_value' => $new],
            ['meta_key' => '_wp_page_template', 'meta_value' => $old]
        );
        if ($updated !== false) {
            $total_updated += (int)$updated;
        }
    }

    $results[] = [
        'old_path'     => $old,
        'new_path'     => $new,
        'pages_found'  => $count,
        'pages'        => $page_titles,
        'updated'      => $write_mode ? $count : 0,
    ];
}

echo json_encode([
    'success'       => true,
    'mode'          => $write_mode ? 'write' : 'dry_run',
    'total_updated' => $total_updated,
    'results'       => $results,
    'note'          => $write_mode
        ? 'Post meta migrated. Visit affected pages to confirm template renders correctly.'
        : 'Dry-run only — no changes made. Add ?write=1 to commit.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
