<?php
/**
 * Emergency Fix: Re-categorize a project to ensure it displays
 * Usage: http://kids-over-profits.local/wp-content/themes/child/api/fix-category.php?project=PROJECT_NAME&category=companies
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';

$projectName = isset($_GET['project']) ? $_GET['project'] : null;
$newCategory = isset($_GET['category']) ? $_GET['category'] : 'companies';
$table = isset($_GET['table']) ? $_GET['table'] : 'facilities_master';

if (!$projectName) {
    echo json_encode([
        'error' => 'Missing parameters',
        'usage' => 'fix-category.php?project=PROJECT_NAME&category=companies&table=facilities_master',
        'valid_categories' => ['companies', 'operators', 'locations', 'referrers']
    ], JSON_PRETTY_PRINT);
    exit;
}

if (!in_array($newCategory, ['companies', 'operators', 'locations', 'referrers'])) {
    echo json_encode([
        'error' => 'Invalid category',
        'valid_categories' => ['companies', 'operators', 'locations', 'referrers']
    ], JSON_PRETTY_PRINT);
    exit;
}

try {
    // Get the current project data
    $stmt = $pdo->prepare("SELECT json_data FROM $table WHERE unique_name = :name");
    $stmt->bindValue(':name', $projectName);
    $stmt->execute();
    
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        echo json_encode([
            'error' => 'Project not found',
            'project' => $projectName,
            'table' => $table
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    // Decode the JSON
    $data = json_decode($row['json_data'], true);
    
    if (!is_array($data)) {
        echo json_encode([
            'error' => 'Invalid JSON in database',
            'project' => $projectName
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    // Store old category
    $oldCategory = isset($data['category']) ? $data['category'] : 'unknown';
    
    // Update the category
    $data['category'] = $newCategory;
    
    // Re-encode and save
    $newJson = json_encode($data);
    
    $updateStmt = $pdo->prepare("UPDATE $table SET json_data = :json_data, updated_at = NOW() WHERE unique_name = :name");
    $updateStmt->bindValue(':json_data', $newJson);
    $updateStmt->bindValue(':name', $projectName);
    $updateStmt->execute();
    
    echo json_encode([
        'success' => true,
        'project' => $projectName,
        'table' => $table,
        'old_category' => $oldCategory,
        'new_category' => $newCategory,
        'message' => "Category updated successfully. The project should now display on the TTI index if category is 'companies' or 'operators'."
    ], JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
