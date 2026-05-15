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

    // 4. QUERY — transporters_master may not exist yet (auto-created on first save)
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'transporters_master'");
    if ($tableCheck && $tableCheck->fetchColumn()) {
        $results = [];
        $stmt = $pdo->query("SELECT * FROM transporters_master");

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
