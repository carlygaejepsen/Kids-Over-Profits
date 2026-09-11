<?php
/**
 * Database Setup Script
 * Run this once to create the wiki_submissions table
 */

// --- Admin authentication ---
// Ensure WordPress is loaded, then require the capability (same pattern as
// backfill-referrers.php / manage-submissions.php).
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
if (!function_exists('current_user_can') || !(current_user_can('manage_options'))) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}

require_once __DIR__ . '/config.php';

try {
    // Read the SQL file
    $sql_file = __DIR__ . '/create-wiki-submissions-table.sql';
    if (!is_file($sql_file) || !is_readable($sql_file)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'SQL file not found',
            'details' => 'Expected ' . basename($sql_file) . ' alongside this script.'
        ]);
        exit;
    }
    $sql = file_get_contents($sql_file);
    if ($sql === false || trim($sql) === '') {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'SQL file is empty or unreadable']);
        exit;
    }

    // Execute the SQL
    $pdo->exec($sql);

    echo json_encode([
        'success' => true,
        'message' => 'Database table created successfully!'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to create table',
        'details' => $e->getMessage()
    ]);
}
