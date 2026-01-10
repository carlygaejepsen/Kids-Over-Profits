<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>API Diagnostic</h1>";

// 1. Check if file exists
if (!file_exists(__DIR__ . '/get-referrers-only.php')) {
    die("CRITICAL: api/get-referrers-only.php does not exist.");
}

// 2. Try to include it (capturing output)
ob_start();
include __DIR__ . '/get-referrers-only.php';
$output = ob_get_clean();

echo "<h2>Raw Output Length: " . strlen($output) . " chars</h2>";

// 3. Check if it's valid JSON
$json = json_decode($output, true);
if (json_last_error() === JSON_ERROR_NONE) {
    echo "<h2 style='color:green'>Valid JSON Received</h2>";
    echo "<pre>" . print_r($json['projects'][0] ?? 'No projects found', true) . "</pre>";
} else {
    echo "<h2 style='color:red'>Invalid JSON</h2>";
    echo "<p>Error: " . json_last_error_msg() . "</p>";
    echo "<h3>Raw Output Snippet:</h3>";
    echo "<pre>" . htmlspecialchars(substr($output, 0, 1000)) . "</pre>";
}
