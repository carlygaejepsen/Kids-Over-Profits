<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

echo "=== DEBUG START ===\n\n";

echo "1. Loading config.php...\n";
require_once __DIR__ . '/config.php';
echo "   Config loaded. PDO exists: " . ($pdo ? 'YES' : 'NO') . "\n\n";

echo "2. Checking ABSPATH...\n";
$calculated_abspath = dirname(dirname(dirname(dirname(__DIR__)))) . '/';
echo "   Calculated ABSPATH: $calculated_abspath\n";
echo "   wp-config.php at ABSPATH: " . (file_exists($calculated_abspath . 'wp-config.php') ? 'EXISTS' : 'NOT FOUND') . "\n\n";

echo "3. Trying referrers_master query...\n";
try {
    $stmt = $pdo->prepare("SELECT unique_name FROM referrers_master LIMIT 5");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "   SUCCESS! Found " . count($results) . " rows:\n";
    foreach ($results as $row) {
        echo "   - " . $row['unique_name'] . "\n";
    }
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== DEBUG END ===\n";
