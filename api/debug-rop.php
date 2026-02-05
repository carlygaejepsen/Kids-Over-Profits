<?php
/**
 * Debug ROP entry retrieval
 * Run this: http://kids-over-profits.local/wp-content/themes/child/api/debug-rop.php
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';

try {
    // Get ROP entry
    $stmt = $pdo->prepare("SELECT * FROM facilities_master WHERE unique_name = 'ROP' OR id = 18");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        echo json_encode(['error' => 'ROP not found in database']);
        exit;
    }
    
    $jsonData = json_decode($row['json_data'], true);
    
    echo json_encode([
        'success' => true,
        'database_row' => [
            'id' => $row['id'],
            'unique_name' => $row['unique_name'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'json_length' => strlen($row['json_data'])
        ],
        'json_structure' => [
            'top_level_keys' => array_keys($jsonData),
            'has_operator' => isset($jsonData['operator']),
            'has_facilities' => isset($jsonData['facilities']),
            'operator_name' => $jsonData['operator']['name'] ?? 'NOT FOUND',
            'facilities_count' => count($jsonData['facilities'] ?? []),
            'first_facility_name' => $jsonData['facilities'][0]['identification']['name'] ?? 'NO FACILITY'
        ],
        'full_json' => $jsonData
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
