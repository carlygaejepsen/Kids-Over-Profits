<?php
// Save facility data suggestions to the suggested_edits table
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['data']) || !isset($input['reason'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing data or reason']);
        exit;
    }
    
    $data = $input['data'];
    $reason = trim($input['reason']);
    
    if (empty($reason)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Reason is required']);
        exit;
    }
    
    // Validate that we have some meaningful data
    if (empty($data['operator']['name']) && empty($data['facilities'][0]['identification']['name'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please provide at least an operator name or facility name']);
        exit;
    }
    
    // Generate a master_id for tracking - now prioritizes explicit project name
    $master_id = '';
    
    // First priority: explicit project name from our form
    if (!empty($input['projectName'])) {
        $master_id = $input['projectName'];
    }
    // Second priority: project name from metadata
    elseif (!empty($input['metadata']['actualProjectName'])) {
        $master_id = $input['metadata']['actualProjectName'];
    }
    // Third priority: project name from data object
    elseif (!empty($data['projectName'])) {
        $master_id = $data['projectName'];
    }
    elseif (!empty($data['name'])) {
        $master_id = $data['name'];
    }
    // Fallback to old logic only if no project name is provided
    else {
        if (!empty($data['operator']['name'])) {
            $master_id = $data['operator']['name'];
        }
        if (!empty($data['facilities'][0]['identification']['name'])) {
            if ($master_id) {
                $master_id .= ' - ' . $data['facilities'][0]['identification']['name'];
            } else {
                $master_id = $data['facilities'][0]['identification']['name'];
            }
        }
    }
    
    // Sanitize master_id (remove special characters, limit length)
    $master_id = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $master_id);
    $master_id = substr($master_id, 0, 255);
    
    // Get submitter IP
    $submitter_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $submitter_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
        $submitter_ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    
    // Prepare the JSON data for storage
    $json_data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    // Insert into suggested_edits table
    $stmt = $pdo->prepare("
        INSERT INTO suggested_edits (
            master_id, 
            edited_json_data, 
            reason, 
            submitter_ip, 
            status,
            created_at
        ) VALUES (?, ?, ?, ?, 'pending', NOW())
    ");
    
    $stmt->execute([
        $master_id,
        $json_data,
        $reason,
        $submitter_ip
    ]);
    
    $suggestion_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Suggestion submitted successfully',
        'suggestion_id' => $suggestion_id,
        'master_id' => $master_id
    ]);

} catch (PDOException $e) {
    error_log('save-suggestion.php PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Database error occurred'
    ]);
} catch (Exception $e) {
    error_log('save-suggestion.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'An error occurred while saving the suggestion'
    ]);
}
?>