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
    } catch (PDOException $e) {
        // Table doesn't exist yet, that's fine - continue without it
    }

    // Merge all result sets
    $results = array_merge($facilitiesResults, $referrersResults, $locationsResults);

    $projects = [];
    foreach ($results as $row) {
        // Decode the stored JSON
        $stored = json_decode($row['json_data'], true);

        // DEBUG: Log Acadia structure
        if ($row['unique_name'] === 'Acadia') {
            error_log('ACADIA DEBUG - stored keys: ' . json_encode(array_keys($stored)));
            error_log('ACADIA DEBUG - has data: ' . (isset($stored['data']) ? 'yes' : 'no'));
            error_log('ACADIA DEBUG - has timestamp: ' . (isset($stored['timestamp']) ? 'yes' : 'no'));
            if (isset($stored['data'])) {
                error_log('ACADIA DEBUG - data keys: ' . json_encode(array_keys($stored['data'])));
                error_log('ACADIA DEBUG - data.facilities exists: ' . (isset($stored['data']['facilities']) ? 'yes' : 'no'));
            }
        }

        // Check if this is the new format (with metadata) or old format (just data)
        if (isset($stored['data']) && isset($stored['timestamp'])) {
            // New format: already has the complete project structure
            // Ensure name field is set correctly
            $stored['name'] = $stored['name'] ?? $row['unique_name'];
            // Ensure category is set
            if (!isset($stored['category'])) {
                $stored['category'] = 'companies'; // Default category
            }
            // Ensure currentFacilityIndex is set
            if (!isset($stored['currentFacilityIndex'])) {
                $stored['currentFacilityIndex'] = 0;
            }
            $projects[$row['unique_name']] = $stored;
        } else {
            // Old format: only has data, need to reconstruct
            $projects[$row['unique_name']] = [
                'name' => $row['unique_name'],
                'data' => $stored,
                'timestamp' => $stored['timestamp'] ?? date('c'),
                'currentFacilityIndex' => $stored['currentFacilityIndex'] ?? 0,
                'category' => $stored['category'] ?? 'companies'  // Default for legacy projects
            ];
        }
    }

    echo json_encode(['success' => true, 'projects' => $projects]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>