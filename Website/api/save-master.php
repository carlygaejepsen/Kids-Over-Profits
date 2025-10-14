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

$action = $request['action'] ?? 'save';
$projectName = $request['projectName'] ?? null;
$data = $request['data'] ?? null;

// Validate project name
if (!$projectName) {
    echo json_encode([
        'success' => false,
        'error' => 'Project name is required'
    ]);
    exit;
}


// Use PDO connection from config.php
require_once __DIR__ . '/../api/config.php';


// Handle action
if ($action === 'delete') {
    try {
        $stmt = $pdo->prepare("DELETE FROM facilities_master WHERE projectName = :projectName");
        $stmt->execute([':projectName' => $projectName]);
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true,
                'message' => "Project '$projectName' deleted from master database"
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Project not found in master database'
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

    // Assume $data is an associative array with columns matching the table
    $columns = array_keys($data);
    $placeholders = ':' . implode(', :', $columns);
    $update = [];
    foreach ($columns as $col) {
        $update[] = "$col = VALUES($col)";
    }
    $sql = "INSERT INTO facilities_master (" . implode(',', $columns) . ") VALUES (" . implode(',', array_map(function($c){return ':' . $c;}, $columns)) . ") "+
           "ON DUPLICATE KEY UPDATE " . implode(',', $update);

    try {
        $stmt = $pdo->prepare($sql);
        foreach ($data as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        echo json_encode([
            'success' => true,
            'message' => "Project '$projectName' saved to master database"
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
