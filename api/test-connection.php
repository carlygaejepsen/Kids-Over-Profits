<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "Step 1: PHP works\n";

// Test if config.php exists
if (file_exists(__DIR__ . '/config.php')) {
    echo "Step 2: config.php exists\n";
} else {
    die("ERROR: config.php not found at " . __DIR__ . '/config.php');
}

// Try to include config
echo "Step 3: About to include config.php\n";
require_once __DIR__ . '/config.php';
echo "Step 4: config.php loaded\n";

// Check if PDO exists
if ($pdo) {
    echo "Step 5: Database connected!\n";
} else {
    echo "Step 5: PDO is null\n";
}

echo "\nAll steps passed!";
