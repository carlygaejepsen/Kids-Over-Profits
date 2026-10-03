<?php
/**
 * SPECIFIC TRANSPORTERS API - Resilient Version
 */

// 1. SILENCE EVERYTHING
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$response = ['success' => false, 'projects' => [], 'error' => 'Unknown error'];

try {
    // 2. LOAD CONFIG SAFELY
    $configPath = __DIR__ . '/config.php';
    if (file_exists($configPath)) {
        require_once $configPath;
    }

    // 3. CHECK PDO
    if (!isset($pdo) || !$pdo) {
        // Emergency Fallback: Try to load WP config to get credentials if PDO is missing
        $wpConfig = dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-config.php';
        if (file_exists($wpConfig)) {
            $source = file_get_contents($wpConfig);
            if (preg_match('/define\(\s*["|"]DB_NAME["|"]\s*,\s*["|"](.*?)["|"]\s*\);/', $source, $m)) $dbname = $m[1];
            if (preg_match('/define\(\s*["|"]DB_USER["|"]\s*,\s*["|"](.*?)["|"]\s*\);/', $source, $m)) $dbuser = $m[1];
            if (preg_match('/define\(\s*["|"]DB_PASSWORD["|"]\s*,\s*["|"](.*?)["|"]\s*\);/', $source, $m)) $dbpass = $m[1];
            if (preg_match('/define\(\s*["|"]DB_HOST["|"]\s*,\s*["|"](.*?)["|"]\s*\);/', $source, $m)) $dbhost = $m[1];

            if (isset($dbname, $dbuser, $dbpass, $dbhost)) {
                $dsn = "mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4";
                $pdo = new PDO($dsn, $dbuser, $dbpass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
            }
        }
    }

    if (!isset($pdo)) {
        throw new Exception("Database connection failed. Config not loaded.");
    }

    // WordPress installs may prefix custom tables (for example,
    // wpdl_transporters_master). Prefer the configured prefix, while retaining
    // compatibility with older installs that used the unprefixed table.
    $prefix = isset($table_prefix) && is_string($table_prefix) ? $table_prefix : '';
    $tableCandidates = array_values(array_unique(array_filter([
        $prefix . 'transporters_master',
        'transporters_master',
    ])));
    $tableName = null;
    $tableCheck = $pdo->prepare(
        'SELECT table_name FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :table_name'
    );
    foreach ($tableCandidates as $candidate) {
        $tableCheck->execute([':table_name' => $candidate]);
        if ($tableCheck->fetchColumn()) {
            $tableName = $candidate;
            break;
        }
    }

    // If the WordPress prefix was unavailable, accept a suffix match only
    // when it identifies one table. Never guess between multiple installations.
    if ($tableName === null) {
        $matchingTables = $pdo->query(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND RIGHT(table_name, CHAR_LENGTH('transporters_master')) = 'transporters_master'
             ORDER BY table_name"
        );
        $fallbackTables = array_values(array_filter(
            $matchingTables->fetchAll(PDO::FETCH_COLUMN),
            static function ($candidate) {
                return is_string($candidate) && preg_match('/^[A-Za-z0-9_]+$/', $candidate);
            }
        ));
        if (count($fallbackTables) === 1) {
            $tableName = $fallbackTables[0];
        } elseif (count($fallbackTables) > 1) {
            throw new RuntimeException('Multiple transporter tables match; configure the WordPress table prefix.');
        }
    }

    if ($tableName !== null) {
        $results = [];
        $quotedTableName = '`' . str_replace('`', '``', $tableName) . '`';
        $stmt = $pdo->query("SELECT * FROM {$quotedTableName}");

        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Robust JSON decoding
                $json = $row['json_data'] ?? '{}';
                $decoded = json_decode($json, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // Recover basic info if JSON is bad
                    $decoded = ['name' => $row['unique_name'], 'error' => 'Invalid JSON'];
                }

                $results[] = [
                    'id' => $row['id'],
                    'db_name' => $row['unique_name'],
                    'payload' => $decoded,
                    '_sourceTable' => 'transporters'
                ];
            }
            $response['success'] = true;
            $response['projects'] = $results;
            unset($response['error']); // Clear default error
        } else {
            throw new Exception("Query returned false: " . implode(" ", $pdo->errorInfo()));
        }
    } else {
        // Table not yet provisioned — return empty success
        $response['success'] = true;
        $response['projects'] = [];
        unset($response['error']);
    }

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

// 5. OUTPUT
ob_end_clean(); // Discard any previous text/warnings
echo json_encode($response);
exit;
