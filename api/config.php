<?php
// Database configuration

// PREVENT ANY OUTPUT - Buffer output to catch warnings/notices
ob_start();

// Load .env file first to make environment variables available
$env_path = null;
$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/.env')) {
        $env_path = $current . '/.env';
        break;
    }
}

if ($env_path) {
    // Load .env file and set environment variables
    $env_lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env_lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) continue;

        // Parse KEY=VALUE lines
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes if present
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }

            // Only set if not already defined
            if (getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
}

// Load WordPress first so we have access to wp-config.php constants
$wp_config_path = null;
$current = __DIR__;
for ($i = 0; $i < 5; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-config.php')) {
        $wp_config_path = $current . '/wp-config.php';
        break;
    }
}

if ($wp_config_path && !defined('ABSPATH')) {
    // Suppress output from wp-config.php if it has any
    include_once $wp_config_path;
}

// Constants for DB connection
$db_host = defined('DB_HOST') ? DB_HOST : getenv('DB_HOST');
$db_name = defined('DB_NAME') ? DB_NAME : getenv('DB_NAME');
$db_user = defined('DB_USER') ? DB_USER : getenv('DB_USER');

// Password resolution - prioritize WP constant, then ENV
$db_pass = null;
if (defined('DB_PASSWORD')) {
    $db_pass = DB_PASSWORD;
} elseif (defined('DB_PASS')) {
    $db_pass = DB_PASS;
} else {
    $db_pass = getenv('DB_PASSWORD');
    if ($db_pass === false) $db_pass = getenv('DB_PASS');
}

// Check for local override
if (file_exists(__DIR__ . '/config.local.php')) {
    include __DIR__ . '/config.local.php';
    if (isset($localConfig)) {
        $db_host = $localConfig['host'] ?? $db_host;
        $db_name = $localConfig['name'] ?? $db_name;
        $db_user = $localConfig['user'] ?? $db_user;
        $db_pass = $localConfig['pass'] ?? $localConfig['password'] ?? $db_pass;
    }
}

// Helper function to get config values safely
if (!function_exists('kop_get_db_config_value')) {
    function kop_get_db_config_value($candidates) {
        foreach ($candidates as $name) {
            if (defined($name)) return constant($name);
            $env = getenv($name);
            if ($env !== false) return $env;
        }
        return null;
    }
}

// Additional resolution for user/host if still null
if ($db_host === null) {
    $db_host = kop_get_db_config_value(['KOP_DB_HOST', 'DB_HOST']);
}
if ($db_name === null) {
    $db_name = kop_get_db_config_value(['KOP_DB_NAME', 'DB_NAME']);
}
if ($db_user === null) {
    $db_user = kop_get_db_config_value(['KOP_DB_USER', 'KOP_DB_USERNAME', 'DB_USER', 'DB_USERNAME']);
}
if ($db_pass === null) {
    $db_pass = kop_get_db_config_value(['KOP_DB_PASS', 'KOP_DB_PASSWORD']);
}

// Clear any buffered output to ensure clean start
ob_end_clean();

$pdo = null;

// Only connect if SKIP_DB_CONNECTION is not set
if (!defined('SKIP_DB_CONNECTION') || !SKIP_DB_CONNECTION) {
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // If we are being included by a script that expects JSON, return JSON error
        // Check if headers have been sent
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }

        error_log("DB Connection Failed: " . $e->getMessage());    
        echo json_encode([
            'error' => 'Database connection failed',
            'details' => 'Please check server error logs for details'
        ]);
        exit;
    }
}

if (!defined('KOP_DB_CONFIG_LOADED')) {
    define('KOP_DB_CONFIG_LOADED', true);
}
