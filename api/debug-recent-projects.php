<?php
/**
 * Debug script to check recently updated projects
 * Run this in a browser: http://kids-over-profits.local/wp-content/themes/child/api/debug-recent-projects.php
 */

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config.php';

try {
    // Check all three master tables
    $tables = ['facilities_master', 'referrers_master', 'locations_master'];
    $results = [];
    
    foreach ($tables as $table) {
        // Check if table exists
        $check = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($check->rowCount() === 0) {
            $results[$table] = ['error' => 'Table does not exist'];
            continue;
        }
        
        // Get recent projects
        $stmt = $pdo->query("
            SELECT 
                unique_name,
                LENGTH(json_data) as json_length,
                updated_at,
                CASE 
                    WHEN json_data LIKE '{\"name\":%' THEN 'new_format'
                    WHEN json_data LIKE '{\"data\":%' THEN 'wrapped_format'
                    WHEN json_data LIKE '{\"facilities\":%' THEN 'old_format'
                    ELSE 'unknown_format'
                END as format_type,
                LEFT(json_data, 200) as json_preview
            FROM $table 
            ORDER BY updated_at DESC 
            LIMIT 10
        ");
        
        $projects = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $projects[] = [
                'name' => $row['unique_name'],
                'size' => $row['json_length'],
                'updated' => $row['updated_at'],
                'format' => $row['format_type'],
                'preview' => substr($row['json_preview'], 0, 100) . '...'
            ];
        }
        
        $results[$table] = [
            'count' => count($projects),
            'projects' => $projects
        ];
    }
    
    echo json_encode($results, JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
