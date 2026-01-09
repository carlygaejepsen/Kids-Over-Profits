<?php
/**
 * SPECIFIC REFERRERS API
 * Bypasses all complex logic to return ONLY referrers_master data.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    $results = [];
    // We query the table directly. No prefixes, no fallback loops.
    $stmt = $pdo->query("SELECT * FROM referrers_master");
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $data = json_decode($row['json_data'], true);
        
        // If JSON is broken, we still return the row with basic info
        if (json_last_error() !== JSON_ERROR_NONE) {
            $data = [
                'name' => $row['unique_name'],
                'is_corrupt' => true
            ];
        }

        $results[] = [
            'id' => $row['id'],
            'name' => $row['unique_name'],
            'data' => $data,
            '_sourceTable' => 'referrers'
        ];
    }

    echo json_encode([
        'success' => true,
        'count' => count($results),
        'projects' => $results // Keeping key name for compatibility
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
