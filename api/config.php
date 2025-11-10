<?php
// Database configuration
if (!function_exists('kop_get_db_config_value')) {
    /**
     * Resolve a database configuration value from predefined constants or environment variables.
     *
     * @param array $candidates Ordered list of constant/env names to probe.
     * @return string|null      The resolved value or null when missing.
     */
    function kop_get_db_config_value(array $candidates)
    {
        foreach ($candidates as $name) {
            if (defined($name)) {
                $value = constant($name);
                if ($value !== '') {
                    return $value;
                }
            }

            $envValue = getenv($name);
            if ($envValue !== false && $envValue !== '') {
                return $envValue;
            }
        }

        return null;
    }
}

if (!function_exists('kop_emit_db_config_error')) {
    /**
     * Emit a standardized JSON error response for missing configuration.
     *
     * @param array $missingKeys List of human readable keys that were not resolved.
     * @return void
     */
    function kop_emit_db_config_error(array $missingKeys)
    {
        http_response_code(500);
        header('Content-Type: application/json');
        $missingList = implode(', ', $missingKeys);
        echo json_encode([
            'error' => 'Database configuration missing',
            'details' => "Unable to resolve the following database settings: {$missingList}.",
            'hint' => 'Define the values in wp-config.php or provide matching environment variables before loading api/config.php.'
        ]);
        exit;
    }
}

$db_host = kop_get_db_config_value(['KOP_DB_HOST', 'DB_HOST']);
$db_name = kop_get_db_config_value(['KOP_DB_NAME', 'DB_NAME']);
$db_user = kop_get_db_config_value(['KOP_DB_USER', 'KOP_DB_USERNAME', 'DB_USER', 'DB_USERNAME']);
$db_pass = kop_get_db_config_value(['KOP_DB_PASS', 'KOP_DB_PASSWORD', 'DB_PASS', 'DB_PASSWORD']);

$localConfigPath = __DIR__ . '/config.local.php';
if (file_exists($localConfigPath)) {
    $localConfig = include $localConfigPath;
    if (is_array($localConfig)) {
        $db_host = $db_host ?? ($localConfig['host'] ?? $localConfig['db_host'] ?? null);
        $db_name = $db_name ?? ($localConfig['name'] ?? $localConfig['db_name'] ?? null);
        $db_user = $db_user ?? ($localConfig['user'] ?? $localConfig['username'] ?? $localConfig['db_user'] ?? null);
        $db_pass = $db_pass ?? ($localConfig['pass'] ?? $localConfig['password'] ?? $localConfig['db_pass'] ?? null);
    }
}

$missing = [];

if ($db_host === null) {
    $missing[] = 'host';
}

if ($db_name === null) {
    $missing[] = 'name';
}

if ($db_user === null) {
    $missing[] = 'user';
}

if ($db_pass === null) {
    $missing[] = 'password';
}

if (!empty($missing)) {
    kop_emit_db_config_error($missing);
}

// Backwards-compatible variable names used by older scripts
// Some files expect $host/$dbname/$username/$password — define aliases so both styles work
$host = $db_host;
$dbname = $db_name;
$username = $db_user;
$password = $db_pass;

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed', 'details' => $e->getMessage()]);
    exit;
}
