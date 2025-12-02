<?php
/**
 * Database Connection Diagnostic Script
 * 
 * Use this to troubleshoot database connection issues on production.
 * Access via: https://kidsoverprofits.org/wp-content/themes/child/api/test-db-connection.php
 * 
 * IMPORTANT: Remove or password-protect this file in production after debugging!
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$diagnostics = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'current_dir' => __DIR__,
    'checks' => []
];

// Check 1: .env file
$env_path = null;
$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/.env')) {
        $env_path = $current . '/.env';
        break;
    }
}
$diagnostics['checks']['env_file'] = [
    'found' => $env_path !== null,
    'path' => $env_path,
    'searched_levels' => 6
];

// Check 2: wp-config.php
$wp_config_path = null;
$current = __DIR__;
$searched_paths = [];
for ($i = 0; $i < 5; $i++) {
    $current = dirname($current);
    $searched_paths[] = $current . '/wp-config.php';
    if (file_exists($current . '/wp-config.php')) {
        $wp_config_path = $current . '/wp-config.php';
        break;
    }
}
$diagnostics['checks']['wp_config'] = [
    'found' => $wp_config_path !== null,
    'path' => $wp_config_path,
    'searched_paths' => $searched_paths
];

// Check 3: config.local.php
$local_config_path = __DIR__ . '/config.local.php';
$diagnostics['checks']['config_local'] = [
    'exists' => file_exists($local_config_path),
    'path' => $local_config_path
];

// Check 4: WordPress constants BEFORE loading wp-config
$diagnostics['checks']['wp_constants_before_load'] = [
    'DB_HOST_defined' => defined('DB_HOST'),
    'DB_NAME_defined' => defined('DB_NAME'),
    'DB_USER_defined' => defined('DB_USER'),
    'DB_PASSWORD_defined' => defined('DB_PASSWORD'),
    'ABSPATH_defined' => defined('ABSPATH')
];

// Check 5: Environment variables BEFORE any loading
$diagnostics['checks']['env_vars_before_load'] = [
    'KOP_DB_HOST' => getenv('KOP_DB_HOST') !== false ? '(set)' : '(not set)',
    'KOP_DB_NAME' => getenv('KOP_DB_NAME') !== false ? '(set)' : '(not set)',
    'KOP_DB_USER' => getenv('KOP_DB_USER') !== false ? '(set)' : '(not set)',
    'KOP_DB_PASS' => getenv('KOP_DB_PASS') !== false ? '(set)' : '(not set)',
    'DB_HOST' => getenv('DB_HOST') !== false ? '(set)' : '(not set)',
    'DB_NAME' => getenv('DB_NAME') !== false ? '(set)' : '(not set)',
    'DB_USER' => getenv('DB_USER') !== false ? '(set)' : '(not set)',
    'DB_PASSWORD' => getenv('DB_PASSWORD') !== false ? '(set)' : '(not set)'
];

// Try loading wp-config.php if it exists
if ($wp_config_path && !defined('ABSPATH')) {
    try {
        // Suppress errors during loading
        $old_error_reporting = error_reporting(0);
        @include_once $wp_config_path;
        error_reporting($old_error_reporting);
        $diagnostics['checks']['wp_config_load'] = [
            'success' => true,
            'error' => null
        ];
    } catch (Exception $e) {
        $diagnostics['checks']['wp_config_load'] = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Check 6: WordPress constants AFTER loading wp-config
$diagnostics['checks']['wp_constants_after_load'] = [
    'DB_HOST_defined' => defined('DB_HOST'),
    'DB_NAME_defined' => defined('DB_NAME'),
    'DB_USER_defined' => defined('DB_USER'),
    'DB_PASSWORD_defined' => defined('DB_PASSWORD'),
    'ABSPATH_defined' => defined('ABSPATH'),
    'DB_HOST_value' => defined('DB_HOST') ? '(has value: ' . strlen(DB_HOST) . ' chars)' : null,
    'DB_NAME_value' => defined('DB_NAME') ? '(has value: ' . strlen(DB_NAME) . ' chars)' : null,
    'DB_USER_value' => defined('DB_USER') ? '(has value: ' . strlen(DB_USER) . ' chars)' : null,
    'DB_PASSWORD_value' => defined('DB_PASSWORD') ? '(has value - hidden)' : null
];

// Check 7: Try to establish actual database connection
$db_host = defined('DB_HOST') ? DB_HOST : null;
$db_name = defined('DB_NAME') ? DB_NAME : null;
$db_user = defined('DB_USER') ? DB_USER : null;
$db_pass = defined('DB_PASSWORD') ? DB_PASSWORD : null;

// Also check for KOP_ prefixed constants
if (!$db_host) $db_host = defined('KOP_DB_HOST') ? KOP_DB_HOST : getenv('KOP_DB_HOST');
if (!$db_name) $db_name = defined('KOP_DB_NAME') ? KOP_DB_NAME : getenv('KOP_DB_NAME');
if (!$db_user) $db_user = defined('KOP_DB_USER') ? KOP_DB_USER : getenv('KOP_DB_USER');
if (!$db_pass) $db_pass = defined('KOP_DB_PASS') ? KOP_DB_PASS : getenv('KOP_DB_PASS');

$diagnostics['checks']['resolved_config'] = [
    'has_host' => !empty($db_host),
    'has_name' => !empty($db_name),
    'has_user' => !empty($db_user),
    'has_pass' => !empty($db_pass),
    'host_length' => $db_host ? strlen($db_host) : 0,
    'name_length' => $db_name ? strlen($db_name) : 0,
    'user_length' => $db_user ? strlen($db_user) : 0
];

if ($db_host && $db_name && $db_user && $db_pass) {
    try {
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Test query
        $stmt = $pdo->query("SELECT 1 as test");
        $result = $stmt->fetch();
        
        $diagnostics['checks']['database_connection'] = [
            'success' => true,
            'test_query' => $result['test'] == 1
        ];
        
        // Check if our tables exist
        $tables_check = [];
        foreach (['facilities_master', 'referrers_master', 'locations_master', 'suggested_edits'] as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            $tables_check[$table] = $stmt->rowCount() > 0;
        }
        $diagnostics['checks']['tables_exist'] = $tables_check;
        
    } catch (PDOException $e) {
        $diagnostics['checks']['database_connection'] = [
            'success' => false,
            'error_code' => $e->getCode(),
            'error_message' => $e->getMessage()
        ];
    }
} else {
    $diagnostics['checks']['database_connection'] = [
        'success' => false,
        'error_message' => 'Missing credentials - cannot attempt connection',
        'missing' => [
            'host' => empty($db_host),
            'name' => empty($db_name),
            'user' => empty($db_user),
            'pass' => empty($db_pass)
        ]
    ];
}

// Summary
$diagnostics['summary'] = [
    'can_find_config' => !empty($wp_config_path) || !empty($env_path) || file_exists($local_config_path),
    'has_all_credentials' => !empty($db_host) && !empty($db_name) && !empty($db_user) && !empty($db_pass),
    'connection_successful' => isset($diagnostics['checks']['database_connection']['success']) && $diagnostics['checks']['database_connection']['success']
];

echo json_encode($diagnostics, JSON_PRETTY_PRINT);
