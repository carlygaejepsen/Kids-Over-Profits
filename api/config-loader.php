<?php
/**
 * Secure configuration loader for Kids Over Profits
 * Reads database credentials from .env file or WordPress constants
 */

// Prevent direct access
if (!defined('ABSPATH') && !defined('KOP_CONFIG_LOADER')) {
    define('KOP_CONFIG_LOADER', true);
}

/**
 * Load environment variables from .env file
 */
function kop_load_env_file($file_path) {
    if (!file_exists($file_path)) {
        return false;
    }

    $lines = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            $value = trim($value, "\"'");

            // Set as environment variable if not already set
            if (!getenv($key)) {
                putenv("$key=$value");
            }

            // Populate superglobals for compatibility
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
            }
            if (!isset($_SERVER[$key])) {
                $_SERVER[$key] = $value;
            }
        }
    }

    return true;
}

// Try to load .env file from multiple locations
$possible_paths = [
    __DIR__ . '/.env',
    __DIR__ . '/../.env',
    dirname(__DIR__) . '/.env',
];

foreach ($possible_paths as $path) {
    if (kop_load_env_file($path)) {
        break;
    }
}

// Get database configuration with fallback priority:
// 1. WordPress constants (if available)
// 2. Environment variables from .env
// 3. Server environment variables
// 4. Fail with error

function kop_get_config($key, $default = null) {
    $alternate_keys = [
        'DB_PASS' => ['DB_PASSWORD'],
        'DB_USER' => ['DB_USERNAME'],
    ];

    $lookup_keys = array_merge([$key], $alternate_keys[$key] ?? []);

    // Try WordPress constants first (custom prefixed and native)
    $constant_candidates = [];
    foreach ($lookup_keys as $lookup_key) {
        $constant_candidates[] = 'KOP_' . $lookup_key;
        $constant_candidates[] = $lookup_key;
    }

    foreach ($constant_candidates as $constant_name) {
        if (defined($constant_name)) {
            return constant($constant_name);
        }
    }

    // Try environment variables
    foreach ($lookup_keys as $lookup_key) {
        $env_value = getenv($lookup_key);
        if ($env_value !== false) {
            return $env_value;
        }
    }

    // Try $_ENV superglobal
    foreach ($lookup_keys as $lookup_key) {
        if (isset($_ENV[$lookup_key])) {
            return $_ENV[$lookup_key];
        }
    }

    // Try $_SERVER superglobal
    foreach ($lookup_keys as $lookup_key) {
        if (isset($_SERVER[$lookup_key])) {
            return $_SERVER[$lookup_key];
        }
    }

    // Return default or throw error
    if ($default !== null) {
        return $default;
    }

    throw new Exception("Configuration key '$key' not found");
}

// Set database configuration variables
try {
    $db_host = kop_get_config('DB_HOST', 'localhost');
    $db_name = kop_get_config('DB_NAME');
    $db_user = kop_get_config('DB_USER');
    $db_pass = kop_get_config('DB_PASS');

    // Backwards-compatible aliases
    $host = $db_host;
    $dbname = $db_name;
    $username = $db_user;
    $password = $db_pass;

    // Create PDO connection
    $pdo = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

} catch (Exception $e) {
    http_response_code(500);

    // Don't expose configuration details in production
    $error_message = 'Database configuration error';

    // Log detailed error server-side
    error_log('[Kids Over Profits] Config error: ' . $e->getMessage());

    echo json_encode([
        'error' => $error_message,
        'details' => defined('WP_DEBUG') && WP_DEBUG ? $e->getMessage() : 'Check server logs'
    ]);
    exit;
}
