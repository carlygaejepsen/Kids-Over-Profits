<?php
/**
 * Test if WordPress REST API endpoint works
 * This simulates what the JavaScript is calling
 */

header('Content-Type: application/json');

// Load WordPress
$wp_load_path = __DIR__ . '/../wp-load.php';

if (!file_exists($wp_load_path)) {
    echo json_encode([
        'error' => 'WordPress not found',
        'wp_load_path' => $wp_load_path,
        'hint' => 'This might not be in the WordPress root directory'
    ], JSON_PRETTY_PRINT);
    exit;
}

require_once $wp_load_path;

// Try to call the function that the REST API uses
$result = kop_get_facilities_projects_from_database();

if (is_wp_error($result)) {
    echo json_encode([
        'success' => false,
        'error' => $result->get_error_message(),
        'error_code' => $result->get_error_code()
    ], JSON_PRETTY_PRINT);
} else {
    echo json_encode([
        'success' => true,
        'source' => $result['source'] ?? 'unknown',
        'project_count' => count($result['projects'] ?? []),
        'projects' => array_keys($result['projects'] ?? [])
    ], JSON_PRETTY_PRINT);
}
