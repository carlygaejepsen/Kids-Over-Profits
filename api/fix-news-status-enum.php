<?php
/**
 * Migration: add 'deleted' to news_submissions.status.
 *
 * The live table was created before soft-deletion existed, so its enum is
 * ('draft','submitted','approved','published','rejected') — and the duplicate
 * article manager's soft delete (status='deleted') fails with
 * "1265 Data truncated for column 'status'". Adds the missing value
 * (additive, no data touched). Idempotent — safe to run repeatedly.
 *
 * Admin-only (CLI allowed). Visit once while logged into wp-admin.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

if (php_sapi_name() !== 'cli'
    && (!function_exists('current_user_can') || !current_user_can('manage_options'))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

try {
    $col = $pdo->query("SHOW COLUMNS FROM news_submissions LIKE 'status'")->fetch();
    if (!$col) {
        throw new RuntimeException('news_submissions.status column not found');
    }
    $type = $col['Type'];

    if (stripos($type, "'deleted'") !== false) {
        echo json_encode([
            'success' => true,
            'action'  => 'none',
            'message' => "Already migrated — status is $type",
        ]);
        exit;
    }

    $pdo->exec(
        "ALTER TABLE news_submissions
         MODIFY status ENUM('draft','submitted','approved','published','rejected','deleted')
         NOT NULL DEFAULT 'submitted'"
    );

    $after = $pdo->query("SHOW COLUMNS FROM news_submissions LIKE 'status'")->fetch();
    echo json_encode([
        'success' => true,
        'action'  => 'altered',
        'before'  => $type,
        'after'   => $after['Type'],
        'message' => "Added 'deleted' to news_submissions.status — the duplicate article manager's soft delete now works.",
    ]);
} catch (Throwable $e) {
    error_log('fix-news-status-enum failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Migration failed — see server error log.']);
}
