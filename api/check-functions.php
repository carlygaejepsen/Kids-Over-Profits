<?php
/**
 * Check what facility form script functions.php is trying to load
 */

// Load WordPress
$wp_load_path = __DIR__ . '/../wp-load.php';

if (!file_exists($wp_load_path)) {
    die('WordPress not found at: ' . $wp_load_path);
}

require_once $wp_load_path;

// Get the functions.php content
$functions_path = get_stylesheet_directory() . '/functions.php';
$functions_content = file_get_contents($functions_path);

// Find the line with facility-form
preg_match('/\$script_relative_path = \'(.+?)\';/', $functions_content, $matches);

header('Content-Type: application/json');
echo json_encode([
    'functions_php_path' => $functions_path,
    'script_relative_path_in_code' => $matches[1] ?? 'NOT FOUND',
    'expected_file_path' => get_stylesheet_directory() . ($matches[1] ?? '/js/facility-form.v3.js'),
    'file_exists' => file_exists(get_stylesheet_directory() . ($matches[1] ?? '/js/facility-form.v3.js')),
    'functions_php_modified' => date('Y-m-d H:i:s', filemtime($functions_path))
], JSON_PRETTY_PRINT);
