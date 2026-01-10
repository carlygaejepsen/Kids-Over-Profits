<?php
/**
 * SPECIFIC REFERRERS API - Pure Passthrough
 * Returns database rows exactly as they are, no "smart" restructuring.
 */

// PREVENT ANY OUTPUT
ob_start();

ini_set('display_errors', 0); // Turn off display errors for production API
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    // Manually define path to config to avoid relative path issues
    $configPath = __DIR__ . '/config.php';
    if (!file_exists($configPath)) {
        throw new Exception("Config file not found at $configPath");
    }
    require_once $configPath;

    if (!isset($pdo)) {
        throw new Exception("Database connection not established (PDO is null)");
    }

    $results = [];
    // Ensure table name is safe/correct
    $stmt = $pdo->query("SELECT * FROM referrers_master");
    
    if (!$stmt) {
        throw new Exception("Query failed: " . implode(" ", $pdo->errorInfo()));
    }
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $json = $row['json_data'];
        $decoded = json_decode($json, true);
        
        // Return exactly what is in the DB, plus ID and Name for convenience
        $results[] = [
            'id' => $row['id'],
            'db_name' => $row['unique_name'], // The name column in SQL
            'payload' => $decoded,            // The JSON column content
            '_sourceTable' => 'referrers'
        ];
    }

    // Clear buffer before outputting JSON
    ob_end_clean();
    echo json_encode(['success' => true, 'projects' => $results]);

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}