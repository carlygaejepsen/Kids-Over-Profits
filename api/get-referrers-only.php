<?php
/**
 * SPECIFIC REFERRERS API - standardized
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
        $data = json_decode($row['json_data'], true);
        
        // Flatten if double-wrapped
        if (isset($data['data']) && (isset($data['data']['referrerConsultants']) || isset($data['data']['referrerIndividual']))) {
            $inner = $data['data'];
            unset($data['data']);
            $data = array_merge($data, $inner);
        }

        $results[] = [
            'id' => $row['id'],
            'unique_name' => $row['unique_name'],
            'raw_data' => $data, // Flattened data
            '_sourceTable' => 'referrers'
        ];
    }

    echo json_encode(['success' => true, 'projects' => $results]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}