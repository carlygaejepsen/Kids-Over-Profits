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
        // We need to reconstruct the project structure that the JavaScript expects
        $project_data = json_decode($row['json_data'], true);
        $projects[$row['unique_name']] = [
            'name' => $row['unique_name'],
            'data' => $project_data,
            'timestamp' => $project_data['timestamp'] ?? date('c'), // Fallback timestamp
            'currentFacilityIndex' => $project_data['currentFacilityIndex'] ?? 0
        ];
    }

    echo json_encode(['success' => true, 'projects' => $projects]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>