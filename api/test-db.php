<?php
/**
 * Direct API test - bypasses WordPress
 */
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain');

echo "=== API DATABASE TEST ===\n\n";

try {
    // Query facilities_master (no prefix - same as get-master-data.php)
    $stmt = $pdo->prepare("SELECT unique_name, json_data FROM facilities_master LIMIT 5");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($results) . " records\n\n";
    
    foreach ($results as $row) {
        $name = $row['unique_name'];
        $stored = json_decode($row['json_data'], true);
        
        echo "=== $name ===\n";
        echo "Top-level keys: " . implode(', ', array_keys($stored)) . "\n";
        
        // Check structure
        $hasNestedFacilities = isset($stored['data']['facilities']) && is_array($stored['data']['facilities']);
        $hasRootFacilities = isset($stored['facilities']) && is_array($stored['facilities']);
        
        if ($hasNestedFacilities) {
            echo "Format: NEW (data.facilities)\n";
            echo "Facility count: " . count($stored['data']['facilities']) . "\n";
        } elseif ($hasRootFacilities) {
            echo "Format: OLD (root facilities)\n";
            echo "Facility count: " . count($stored['facilities']) . "\n";
        } else {
            echo "Format: UNKNOWN - no facilities found\n";
        }
        echo "\n";
    }
    
    // Check total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM facilities_master");
    $countStmt->execute();
    $total = $countStmt->fetchColumn();
    echo "Total records in facilities_master: $total\n\n";
    
    // Check specifically for Acadia
    echo "=== ACADIA SPECIFIC ===\n";
    $acadiaStmt = $pdo->prepare("SELECT unique_name, json_data FROM facilities_master WHERE unique_name = 'Acadia'");
    $acadiaStmt->execute();
    $acadia = $acadiaStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($acadia) {
        $data = json_decode($acadia['json_data'], true);
        echo "Found Acadia!\n";
        echo "Keys: " . implode(', ', array_keys($data)) . "\n";
        
        if (isset($data['data'])) {
            echo "data subkeys: " . implode(', ', array_keys($data['data'])) . "\n";
            if (isset($data['data']['facilities'])) {
                echo "data.facilities count: " . count($data['data']['facilities']) . "\n";
            }
        }
        if (isset($data['facilities'])) {
            echo "root facilities count: " . count($data['facilities']) . "\n";
        }
    } else {
        echo "Acadia NOT FOUND\n";
    }
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
