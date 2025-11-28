<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT unique_name, json_data FROM facilities_master WHERE unique_name = 'Acadia'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        echo json_encode(['error' => 'Acadia not found']);
        exit;
    }
    
    // Raw JSON from database
    $raw_json = $row['json_data'];
    
    // Decoded
    $stored = json_decode($raw_json, true);
    
    echo json_encode([
        'raw_json_preview' => substr($raw_json, 0, 500),
        'stored_keys' => array_keys($stored),
        'has_data_key' => isset($stored['data']),
        'has_timestamp_key' => isset($stored['timestamp']),
        'data_keys' => isset($stored['data']) ? array_keys($stored['data']) : 'N/A',
        'data_has_facilities' => isset($stored['data']['facilities']),
        'facilities_count' => isset($stored['data']['facilities']) ? count($stored['data']['facilities']) : 0
    ], JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
