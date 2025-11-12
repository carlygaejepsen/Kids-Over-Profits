<?php
require_once __DIR__ . '/config.php';

// This script fetches all records from the master data table
header('Content-Type: application/json');

try {
    $stmt = $pdo->prepare("SELECT unique_name, json_data FROM facilities_master");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $projects = [];
    foreach ($results as $row) {
        // Decode the stored JSON
        $stored = json_decode($row['json_data'], true);

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