<?php
/**
 * Show what's in config.php - for debugging
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

header('Content-Type: text/plain');

$config_path = __DIR__ . '/config.php';

echo "Config file path: " . $config_path . "\n";
echo "File exists: " . (file_exists($config_path) ? 'YES' : 'NO') . "\n";
echo "File readable: " . (is_readable($config_path) ? 'YES' : 'NO') . "\n";
echo "File size: " . filesize($config_path) . " bytes\n";
echo "\n";
echo "=== FILE CONTENTS ===\n";
echo file_get_contents($config_path);
