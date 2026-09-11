<?php
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

header("Content-Type: text/plain");
$wp_config_path = null;
$current = __DIR__;
for ($i = 0; $i < 10; $i++) {
    echo "Checking for wp-config.php in: $current\n";
    if (file_exists($current . "/wp-config.php")) {
        $wp_config_path = $current . "/wp-config.php";
        echo "FOUND wp-config.php at: $wp_config_path\n";
        break;
    }
    $current = dirname($current);
}

require_once __DIR__ . "/config.php";
echo "Resolved DB_HOST: " . $db_host . "\n";
echo "Resolved DB_NAME: " . $db_name . "\n";
echo "PDO initialized: " . (isset($pdo) ? "YES" : "NO") . "\n";
?>
