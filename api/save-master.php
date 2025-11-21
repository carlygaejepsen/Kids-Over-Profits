<?php
/**
 * Save to Master Database API
 *
 * This script handles saving facility data directly to the master database.
 * It should only be accessible to administrators.
 *
 * Expected POST data:
 * - data: The facility data object to save
 * - projectName: The name of the project
 * - action: 'save' or 'delete'
 */

// Set JSON header
header('Content-Type: application/json');

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in JSON response

// Custom error handler to return JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo json_encode([
        'success' => false,
        'error' => "PHP Error: $errstr in $errfile on line $errline"
    ]);
    exit;
});

// Security: Check if user is admin (uncomment when WordPress is available)
// if (!current_user_can('administrator')) {
//     echo json_encode([
//         'success' => false,
//         'error' => 'Unauthorized: Admin access required'
//     ]);
//     exit;
// }

// Get POST data
$input = file_get_contents('php://input');
$request = json_decode($input, true);

if (!$request) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON input'
    ]);
    exit;
}

// Use PDO connection from config.php
// Load this BEFORE extracting variables to avoid WordPress variable conflicts
require_once __DIR__ . '/config.php';

// Set defaults for all expected variables to prevent undefined errors
// These are defined AFTER config.php to prevent WordPress from overwriting them
$action = isset($request['action']) ? $request['action'] : 'save';
$projectName = isset($request['projectName']) ? $request['projectName'] : null;
$newProjectName = isset($request['newProjectName']) ? $request['newProjectName'] : null;
$data = isset($request['data']) ? $request['data'] : null;
$category = isset($request['category']) ? $request['category'] : 'companies';
$currentFacilityIndex = isset($request['currentFacilityIndex']) ? intval($request['currentFacilityIndex']) : 0;
$timestamp = isset($request['timestamp']) ? $request['timestamp'] : date('c');

// Validate project name
if (!$projectName) {
    echo json_encode([
        'success' => false,
        'error' => 'Project name is required'
    ]);
    exit;
}

if ($action === 'rename') {
    if (!$projectName || !$newProjectName) {
        echo json_encode(['success' => false, 'error' => 'Old and new project names are required for rename.']);
        exit;
    }

    try {
        // Check if new project name already exists
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM facilities_master WHERE unique_name = :newProjectName");
        $checkStmt->execute([':newProjectName' => $newProjectName]);
        if ($checkStmt->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => "Project '$newProjectName' already exists."]);
            exit;
        }

        // Perform the rename
        $stmt = $pdo->prepare("UPDATE facilities_master SET unique_name = :newProjectName, updated_at = NOW() WHERE unique_name = :oldProjectName");
        $stmt->execute([':newProjectName' => $newProjectName, ':oldProjectName' => $projectName]);

        echo json_encode(['success' => true, 'message' => "Project '$projectName' renamed to '$newProjectName'."]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to rename project: ' . $e->getMessage()]);
    }
    exit;
}


// Handle action
if ($action === 'delete') {
    try {
        // Try deleting from both tables (referrers and facilities)
        $deletedFromFacilities = false;
        $deletedFromReferrers = false;

        $stmt = $pdo->prepare("DELETE FROM facilities_master WHERE unique_name = :projectName");
        $stmt->execute([':projectName' => $projectName]);
        $deletedFromFacilities = $stmt->rowCount() > 0;

        $stmt = $pdo->prepare("DELETE FROM referrers_master WHERE unique_name = :projectName");
        $stmt->execute([':projectName' => $projectName]);
        $deletedFromReferrers = $stmt->rowCount() > 0;

        if ($deletedFromFacilities || $deletedFromReferrers) {
            $fromTable = $deletedFromReferrers ? 'referrers_master' : 'facilities_master';
            echo json_encode([
                'success' => true,
                'message' => "Project '$projectName' deleted from {$fromTable}"
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Project not found in any database table'
            ]);
        }
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to delete project from database: ' . $e->getMessage()
        ]);
    }
    exit;
}


// Handle save action
if ($action === 'save') {
    if (!$data) {
        echo json_encode([
            'success' => false,
            'error' => 'No data provided to save'
        ]);
        exit;
    }

    // Create the complete project structure with metadata
    $projectStructure = [
        'name' => $projectName,
        'data' => $data,
        'category' => $category,
        'currentFacilityIndex' => $currentFacilityIndex,
        'timestamp' => $timestamp
    ];

    // Encode the complete project structure as a JSON string
    $jsonData = json_encode($projectStructure);

    // Determine which table to use based on category
    $tableName = ($category === 'referrers') ? 'referrers_master' : 'facilities_master';

    // SQL to insert or update the project data based on projectName
    // The identifier column is `unique_name` and the data column is `json_data`.
    $sql = "INSERT INTO {$tableName} (unique_name, json_data, updated_at)
            VALUES (:unique_name, :json_data, NOW())
            ON DUPLICATE KEY UPDATE json_data = :json_data, updated_at = NOW()";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':unique_name', $projectName);
        $stmt->bindValue(':json_data', $jsonData);

        $stmt->execute();
        echo json_encode([
            'success' => true,
            'message' => "Project '$projectName' saved to {$tableName}"
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to save to database: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Unknown action
echo json_encode([
    'success' => false,
    'error' => "Unknown action: $action"
]);
