<?php
/**
 * Import the legacy WordPress news posts (the four news categories the old
 * /news/ page listed) and the entries of the hand-written 2024 index
 * (/news-2/) into news_submissions, the table the news feed reads. The work
 * is api/lib-news-post-import.php; the theme also runs it once on deploy
 * (kop_apply_news_post_import() in inc/admin.php), so this endpoint is for
 * previewing or re-running it.
 *
 * Idempotent: an imported post is marked in json_data.source_post_id, and an
 * article URL already in the feed is never added twice.
 *
 * Requires ?run=1 from a logged-in admin, or CLI. Options:
 *   ?dry=1              preview without writing (CLI: "dry")
 *   ?status=approved    status to write (default approved)
 *
 *   Preview: /wp-content/themes/child/api/import-news-posts.php?run=1&dry=1
 *   Import:  /wp-content/themes/child/api/import-news-posts.php?run=1
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib-news-post-import.php';

$is_cli  = php_sapi_name() === 'cli';
$dry_run = $is_cli ? in_array('dry', $argv ?? [], true) : !empty($_GET['dry']);
$run_ok  = $is_cli || (($_GET['run'] ?? null) === '1');
$status  = isset($_GET['status']) ? preg_replace('/[^a-z]/', '', strtolower($_GET['status'])) : 'approved';
if (!in_array($status, ['approved', 'published', 'submitted', 'draft'], true)) $status = 'approved';

if (!defined('ABSPATH')) {
    $current = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $current = dirname($current);
        if (file_exists($current . '/wp-load.php')) { require_once $current . '/wp-load.php'; break; }
    }
}

if (!$is_cli) {
    $is_admin = function_exists('current_user_can') && current_user_can('manage_options');
    if (!$run_ok || !$is_admin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Requires ?run=1 and an admin session, or CLI execution.']);
        exit;
    }
}

if (!function_exists('get_posts') || !isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'WordPress or the database is not available.']);
    exit;
}

try {
    $stats = kop_news_post_import($pdo, kop_news_post_import_sources(), ['dry' => $dry_run, 'status' => $status]);
    echo json_encode(['success' => true, 'dry_run' => $dry_run, 'write_status' => $status, 'stats' => $stats],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
