<?php
/**
 * Re-cluster all news_submissions into cross-outlet story groups.
 *
 * POST (no body needed). Requires an admin user (current_user_can('edit_posts')).
 * Run once after the story_group_id migration (api/update-schema.php), or any
 * time to repair drift — day-to-day grouping happens automatically on save via
 * kop_news_assign_story_group().
 *
 * Response: { success, articles, groups, grouped_articles }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/news-story-groups.php';

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

if (!function_exists('current_user_can') || !current_user_can('edit_posts')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    set_time_limit(300); // the pairwise pass over the full archive can be slow
    $stats = kop_news_rebuild_story_groups($pdo);
    echo json_encode(['success' => true] + $stats);
} catch (PDOException $e) {
    error_log('rebuild-news-story-groups.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
}
