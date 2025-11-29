<?php
// Simple test to check Acadia data structure
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT json_data FROM facilities_master WHERE unique_name = 'Acadia'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        die(json_encode(['error' => 'Acadia not found']));
    }
    
    $stored = json_decode($row['json_data'], true);
    
    // Same logic as get-master-data.php
    $hasNestedFacilities = isset($stored['data']['facilities']) && is_array($stored['data']['facilities']);
    $hasNestedOperator = isset($stored['data']['operator']) && is_array($stored['data']['operator']);
    $hasRootFacilities = isset($stored['facilities']) && is_array($stored['facilities']);
    $hasRootOperator = isset($stored['operator']) && is_array($stored['operator']);
    
    $isNewFormat = $hasNestedFacilities || $hasNestedOperator;
    
    if ($isNewFormat) {
        $stored['name'] = $stored['name'] ?? 'Acadia';
        $stored['category'] = $stored['category'] ?? 'companies';
        $stored['currentFacilityIndex'] = $stored['currentFacilityIndex'] ?? 0;
        $stored['timestamp'] = $stored['timestamp'] ?? date('c');
        $result = $stored;
    } else {
        $result = [
            'name' => 'Acadia',
            'data' => $stored,
            'timestamp' => $stored['timestamp'] ?? date('c'),
            'currentFacilityIndex' => $stored['currentFacilityIndex'] ?? 0,
            'category' => $stored['category'] ?? 'companies'
        ];
    }
    
    echo json_encode([
        'debug' => [
            'hasNestedFacilities' => $hasNestedFacilities,
            'hasNestedOperator' => $hasNestedOperator,
            'hasRootFacilities' => $hasRootFacilities,
            'hasRootOperator' => $hasRootOperator,
            'isNewFormat' => $isNewFormat,
            'storedKeys' => array_keys($stored),
            'resultKeys' => array_keys($result),
            'resultDataKeys' => isset($result['data']) ? array_keys($result['data']) : 'N/A',
            'facilitiesCount' => $result['data']['facilities'] ? count($result['data']['facilities']) : 0
        ],
        'result' => $result
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
