<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
define('SKIP_DB_CONNECTION', false); // Ensure DB connects
require_once __DIR__ . '/config.php';

echo "=== DIAGNOSTIC START ===\n";

if (!$pdo) {
    die("PDO Connection Failed.\n");
}

echo "Database: " . $db_name . "\n";

// 1. Check Tables
echo "\n--- Checking Tables ---\n";
try {
    $stmt = $pdo->query("SHOW TABLES LIKE '%referrers%' ");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Found tables: " . implode(", ", $tables) . "\n";
} catch (Exception $e) {
    echo "Error listing tables: " . $e->getMessage() . "\n";
}

// 2. Inspect referrers_master columns
if (in_array('referrers_master', $tables)) {
    echo "\n--- Columns in 'referrers_master' ---\n";
    $stmt = $pdo->query("DESCRIBE referrers_master");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "{$c['Field']} ({$c['Type']})\n";
    }

    // 3. Inspect Data
    echo "\n--- Data in 'referrers_master' ---\n";
    $stmt = $pdo->query("SELECT * FROM referrers_master");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Row count: " . count($rows) . "\n";

    foreach ($rows as $row) {
        echo "\n[Row ID: " . ($row['id'] ?? '?') . "]\n";
        echo "unique_name: " . ($row['unique_name'] ?? 'NULL') . "\n";
        
        if (isset($row['json_data'])) {
            $json = json_decode($row['json_data'], true);
            if ($json === null) {
                echo "JSON ERROR: " . json_last_error_msg() . "\n";
                echo "Raw JSON start: " . substr($row['json_data'], 0, 50) . "...\n";
            } else {
                echo "JSON Valid. Name: " . ($json['name'] ?? 'N/A') . "\n";
                echo "Category: " . ($json['category'] ?? 'N/A') . "\n";
                // Check referrer specific fields
                $hasReferrerConsultants = !empty($json['data']['referrerConsultants']);
                $hasReferrerIndividual = !empty($json['data']['referrerIndividual']);
                echo "Has referrerConsultants: " . ($hasReferrerConsultants ? 'YES' : 'NO') . "\n";
                echo "Has referrerIndividual: " . ($hasReferrerIndividual ? 'YES' : 'NO') . "\n";
            }
        } else {
            echo "CRITICAL: 'json_data' column missing or empty.\n";
        }
    }

} else {
    echo "CRITICAL: 'referrers_master' table not found.\n";
}

echo "\n=== DIAGNOSTIC END ===\n";

