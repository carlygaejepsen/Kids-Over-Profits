<?php
/**
 * SPECIFIC REFERRERS API - Pure Passthrough
 * Returns database rows exactly as they are, no "smart" restructuring.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    $results = [];
    $stmt = $pdo->query("SELECT * FROM referrers_master");
    
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

    echo json_encode(['success' => true, 'projects' => $results]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
