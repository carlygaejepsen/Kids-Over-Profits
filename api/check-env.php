<?php
/**
 * Check what's in the .env file
 */

header('Content-Type: application/json');

$env_path = __DIR__ . '/../.env';

if (!file_exists($env_path)) {
    echo json_encode([
        'error' => '.env file does not exist',
        'expected_path' => $env_path
    ], JSON_PRETTY_PRINT);
    exit;
}

// Read and parse .env file
$env_lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$env_values = [];

foreach ($env_lines as $line) {
    // Skip comments
    if (strpos(trim($line), '#') === 0) {
        continue;
    }

    // Parse KEY=VALUE pairs
    if (strpos($line, '=') !== false) {
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, '"\'');

        // Mask password for security (show only first 2 and last 2 chars)
        if ($key === 'DB_PASS' && strlen($value) > 4) {
            $masked = substr($value, 0, 2) . '***' . substr($value, -2);
            $env_values[$key] = $masked . ' (masked)';
        } else {
            $env_values[$key] = $value;
        }
    }
}

echo json_encode([
    'env_file_path' => $env_path,
    'file_exists' => true,
    'file_readable' => is_readable($env_path),
    'file_size' => filesize($env_path) . ' bytes',
    'parsed_values' => $env_values,
    'raw_content_preview' => substr(file_get_contents($env_path), 0, 200) . '...'
], JSON_PRETTY_PRINT);
