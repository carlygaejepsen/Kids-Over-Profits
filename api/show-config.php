<?php
/**
 * Show what's in config.php - for debugging
 */

header('Content-Type: text/plain');

$config_path = __DIR__ . '/config.php';

echo "Config file path: " . $config_path . "\n";
echo "File exists: " . (file_exists($config_path) ? 'YES' : 'NO') . "\n";
echo "File readable: " . (is_readable($config_path) ? 'YES' : 'NO') . "\n";
echo "File size: " . filesize($config_path) . " bytes\n";
echo "\n";
echo "=== FILE CONTENTS ===\n";
echo file_get_contents($config_path);
