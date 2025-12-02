<?php
require_once __DIR__ . '/config.php';

// This script fetches all records from the master data table
header('Content-Type: application/json');

try {
    // Tables are stored without WordPress prefix in this database
    $prefix = '';

    // Query all three master tables: facilities, referrers, and locations
    $stmt1 = $pdo->prepare("SELECT unique_name, json_data FROM {$prefix}facilities_master");
    $stmt1->execute();
    $facilitiesResults = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    $stmt2 = $pdo->prepare("SELECT unique_name, json_data FROM {$prefix}referrers_master");
    $stmt2->execute();
    $referrersResults = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // locations_master is optional - may not exist yet
    $locationsResults = [];
    try {
        $stmt3 = $pdo->prepare("SELECT unique_name, json_data FROM {$prefix}locations_master");
        $stmt3->execute();
        $locationsResults = $stmt3->fetchAll(PDO::FETCH_ASSOC);
        // Mark location results with their source table
        foreach ($locationsResults as &$row) {
            $row['_source_table'] = 'locations';
        }
    } catch (PDOException $e) {
        // Table doesn't exist yet, that's fine - continue without it
    }

    // Mark source tables for proper categorization
    foreach ($facilitiesResults as &$row) {
        $row['_source_table'] = 'facilities';
    }
    foreach ($referrersResults as &$row) {
        $row['_source_table'] = 'referrers';
    }

    // Merge all result sets
    $results = array_merge($facilitiesResults, $referrersResults, $locationsResults);

    $projects = [];
    foreach ($results as $row) {
        // Decode the stored JSON
        $stored = json_decode($row['json_data'], true);

        // Detect format by checking where facilities/operator live:
        // NEW format: { name, data: { operator, facilities }, category?, timestamp? }
        // OLD format: { operator, facilities, ... } (at root level)
        
        $hasNestedFacilities = isset($stored['data']['facilities']) && is_array($stored['data']['facilities']);
        $hasNestedOperator = isset($stored['data']['operator']) && is_array($stored['data']['operator']);
        $hasRootFacilities = isset($stored['facilities']) && is_array($stored['facilities']);
        $hasRootOperator = isset($stored['operator']) && is_array($stored['operator']);
        
        // It's NEW format if data contains facilities OR operator
        $isNewFormat = $hasNestedFacilities || $hasNestedOperator;
        
        // Determine category based on source table if not explicitly set
        $defaultCategory = 'companies';
        if (isset($row['_source_table'])) {
            if ($row['_source_table'] === 'locations') {
                $defaultCategory = 'locations';
            } elseif ($row['_source_table'] === 'referrers') {
                $defaultCategory = 'referrers';
            }
        }

        if ($isNewFormat) {
            // New format: data is already nested correctly, just pass through
            $stored['name'] = $stored['name'] ?? $row['unique_name'];
            $stored['category'] = $stored['category'] ?? $defaultCategory;
            $stored['currentFacilityIndex'] = $stored['currentFacilityIndex'] ?? 0;
            $stored['timestamp'] = $stored['timestamp'] ?? date('c');
            $projects[$row['unique_name']] = $stored;
        } else {
            // Old format: facilities/operator at root, need to wrap in 'data'
            $projects[$row['unique_name']] = [
                'name' => $row['unique_name'],
                'data' => $stored,
                'timestamp' => $stored['timestamp'] ?? date('c'),
                'currentFacilityIndex' => $stored['currentFacilityIndex'] ?? 0,
                'category' => $stored['category'] ?? $defaultCategory
            ];
        }
    }

    echo json_encode(['success' => true, 'projects' => $projects]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>