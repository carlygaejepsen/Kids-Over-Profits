<?php
/**
 * Database Connection Test
 * Visit this file directly to test if database connection works
 */

header('Content-Type: application/json');

// Test 1: Check if .env file exists
$env_path = __DIR__ . '/../.env';
$env_exists = file_exists($env_path);

// Test 2: Try to load config
$config_loaded = false;
$config_error = null;
$pdo = null;

try {
    require_once __DIR__ . '/config.php';
    $config_loaded = true;
} catch (Exception $e) {
    $config_error = $e->getMessage();
}

// Test 3: Try to query facilities_master table
$table_exists = false;
$row_count = 0;
$query_error = null;

if ($pdo) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'facilities_master'");
        $result = $stmt->fetch();
        $table_exists = !empty($result);

        if ($table_exists) {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM facilities_master");
            $result = $stmt->fetch();
            $row_count = $result['count'];
        }
    } catch (PDOException $e) {
        $query_error = $e->getMessage();
    }
}

// Return diagnostic info
echo json_encode([
    'success' => $config_loaded && $table_exists,
    'diagnostics' => [
        'env_file_exists' => $env_exists,
        'env_file_path' => $env_path,
        'config_loaded' => $config_loaded,
        'config_error' => $config_error,
        'table_exists' => $table_exists,
        'row_count' => $row_count,
        'query_error' => $query_error,
        'database_connected' => $pdo !== null,
    ],
    'message' => $config_loaded && $table_exists
        ? "Database connection successful! Found {$row_count} records in facilities_master table."
        : 'Database connection failed. See diagnostics for details.'
], JSON_PRETTY_PRINT);
