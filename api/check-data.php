<?php
require_once __DIR__ . '/config.php';

echo "=== Checking Database Structure ===\n\n";

// Check facilities_master
$stmt = $pdo->query("SELECT unique_name, json_data FROM facilities_master LIMIT 3");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "=== " . $row['unique_name'] . " ===\n";
    $data = json_decode($row['json_data'], true);
    
    echo "Top-level keys: " . implode(', ', array_keys($data)) . "\n";
    
    if (isset($data['data'])) {
        echo "data.keys: " . implode(', ', array_keys($data['data'])) . "\n";
        if (isset($data['data']['facilities'])) {
            echo "data.facilities count: " . count($data['data']['facilities']) . "\n";
        } else {
            echo "data.facilities: NOT FOUND\n";
        }
    }
    
    if (isset($data['facilities'])) {
        echo "facilities (top-level) count: " . count($data['facilities']) . "\n";
    }
    
    echo "\n";
}
