<?php
/**
 * Search facilities_master for the facility picker.
 *
 * GET /api/facility-search.php?q=mount[&limit=20]
 *
 * Returns up to `limit` matches, each shaped:
 *   { id, unique_name, state, city, status }
 *
 * Matches against unique_name (case-insensitive LIKE). Public read.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

try {
    $q = trim((string)($_GET['q'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 20);
    if ($limit < 1) $limit = 20;
    if ($limit > 50) $limit = 50;

    if ($q === '') {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $like = '%' . $q . '%';
    $stmt = $pdo->prepare(
        "SELECT id, unique_name, json_data
         FROM facilities_master
         WHERE unique_name LIKE ?
         ORDER BY
           CASE WHEN unique_name LIKE ? THEN 0 ELSE 1 END,
           CHAR_LENGTH(unique_name) ASC,
           unique_name ASC
         LIMIT ?"
    );
    $startsWith = $q . '%';
    $stmt->bindValue(1, $like);
    $stmt->bindValue(2, $startsWith);
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Pull state/city/status out of the JSON for disambiguation.
    $out = array_map(static function ($row) {
        $project = json_decode($row['json_data'] ?? '', true);
        $state = null;
        $city  = null;
        $status = null;

        if (is_array($project)) {
            // Top-level fields the project may carry.
            $operator = isset($project['data']['operator']) && is_array($project['data']['operator'])
                ? $project['data']['operator']
                : null;
            $identification = isset($project['data']['identification']) && is_array($project['data']['identification'])
                ? $project['data']['identification']
                : null;

            // Try identification first (it's the program-level info), then operator.
            foreach ([$identification, $operator] as $src) {
                if (!is_array($src)) continue;
                if (!$state && !empty($src['state'])) $state = $src['state'];
                if (!$state && !empty($src['locationState'])) $state = $src['locationState'];
                if (!$city && !empty($src['city'])) $city = $src['city'];
                if (!$city && !empty($src['locationCity'])) $city = $src['locationCity'];
                if (!$status && !empty($src['status'])) $status = $src['status'];
            }

            // Some projects only have free-form 'location' / 'address' strings; try to
            // pull a "City, ST" out of those when nothing structured was found.
            if (!$state || !$city) {
                foreach ([$identification, $operator] as $src) {
                    if (!is_array($src)) continue;
                    foreach (['location', 'address', 'cityState', 'city_state'] as $k) {
                        if (!empty($src[$k]) && is_string($src[$k])) {
                            if (preg_match('/([^,]+),\s*([A-Z]{2}|[A-Za-z .]+)\s*$/', trim($src[$k]), $m)) {
                                if (!$city)  $city  = trim($m[1]);
                                if (!$state) $state = trim($m[2]);
                            }
                            break 2;
                        }
                    }
                }
            }
        }

        return [
            'id'          => (int)$row['id'],
            'unique_name' => $row['unique_name'],
            'state'       => $state,
            'city'        => $city,
            'status'      => $status,
        ];
    }, $rows);

    echo json_encode(['success' => true, 'data' => $out], JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
