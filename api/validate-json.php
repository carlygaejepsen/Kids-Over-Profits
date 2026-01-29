<?php
/**
 * Diagnostic tool to validate JSON data integrity for projects
 * Access via: http://kids-over-profits.local/wp-content/themes/child/api/validate-json.php?project=PROJECT_NAME
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';

$projectName = isset($_GET['project']) ? $_GET['project'] : null;
$table = isset($_GET['table']) ? $_GET['table'] : 'facilities_master';

if (!$projectName) {
    echo json_encode([
        'error' => 'Missing project parameter',
        'usage' => 'Add ?project=PROJECT_NAME to the URL',
        'example' => '?project=ASPEN%20EDUCATION%20GROUP&table=facilities_master'
    ], JSON_PRETTY_PRINT);
    exit;
}

try {
    // Check if table exists
    $checkTable = $pdo->query("SHOW TABLES LIKE '$table'");
    if ($checkTable->rowCount() === 0) {
        echo json_encode([
            'error' => "Table '$table' does not exist",
            'available_tables' => ['facilities_master', 'referrers_master', 'locations_master']
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    // Get the project data
    $stmt = $pdo->prepare("SELECT unique_name, json_data, updated_at FROM $table WHERE unique_name = :name");
    $stmt->bindValue(':name', $projectName);
    $stmt->execute();
    
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        echo json_encode([
            'error' => 'Project not found',
            'project_name' => $projectName,
            'table' => $table,
            'suggestion' => 'Try one of the recent projects listed below',
            'recent_projects' => []
        ], JSON_PRETTY_PRINT);
        
        // Show recent projects as hint
        $recent = $pdo->query("SELECT unique_name FROM $table ORDER BY updated_at DESC LIMIT 10");
        $projects = [];
        while ($p = $recent->fetch(PDO::FETCH_ASSOC)) {
            $projects[] = $p['unique_name'];
        }
        
        $data = json_decode(file_get_contents('php://output'), true);
        $data['recent_projects'] = $projects;
        file_put_contents('php://output', json_encode($data, JSON_PRETTY_PRINT));
        exit;
    }
    
    // Validate the JSON
    $jsonData = $row['json_data'];
    $jsonLength = strlen($jsonData);
    
    // Try to decode the JSON
    $decoded = json_decode($jsonData, true);
    $jsonError = json_last_error();
    $jsonErrorMsg = json_last_error_msg();
    
    $validation = [
        'project_name' => $row['unique_name'],
        'table' => $table,
        'updated_at' => $row['updated_at'],
        'json_size_bytes' => $jsonLength,
        'json_valid' => ($jsonError === JSON_ERROR_NONE),
        'json_error' => $jsonErrorMsg,
    ];
    
    if ($jsonError === JSON_ERROR_NONE && is_array($decoded)) {
        // Check the format
        $hasDataProperty = isset($decoded['data']);
        $hasNameProperty = isset($decoded['name']);
        $hasCategoryProperty = isset($decoded['category']);
        $hasFacilitiesAtRoot = isset($decoded['facilities']);
        $hasFacilitiesInData = isset($decoded['data']['facilities']);
        
        $format = 'unknown';
        if ($hasDataProperty && $hasNameProperty) {
            $format = 'new_format (correct)';
        } elseif ($hasFacilitiesAtRoot) {
            $format = 'old_format (should still work)';
        }
        
        $validation['format'] = $format;
        $validation['structure'] = [
            'has_data_property' => $hasDataProperty,
            'has_name_property' => $hasNameProperty,
            'has_category_property' => $hasCategoryProperty,
            'has_facilities_at_root' => $hasFacilitiesAtRoot,
            'has_facilities_in_data' => $hasFacilitiesInData,
        ];
        
        // Count facilities
        if ($hasFacilitiesInData && is_array($decoded['data']['facilities'])) {
            $validation['facility_count'] = count($decoded['data']['facilities']);
        } elseif ($hasFacilitiesAtRoot && is_array($decoded['facilities'])) {
            $validation['facility_count'] = count($decoded['facilities']);
        }
        
        // Sample the data (first 500 chars)
        $validation['json_sample'] = substr($jsonData, 0, 500) . '...';
        
        // Check if it would be returned by the REST API
        $validation['would_display_on_index'] = ($jsonError === JSON_ERROR_NONE && 
                                                   !empty($decoded) && 
                                                   ($hasFacilitiesInData || $hasFacilitiesAtRoot));
        
        $validation['status'] = 'OK';
        
    } else {
        $validation['status'] = 'ERROR';
        $validation['json_sample'] = substr($jsonData, 0, 500);
    }
    
    echo json_encode($validation, JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
