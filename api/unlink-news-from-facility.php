<?php
/**
 * Remove a news <-> facility link.
 *
 * POST body { news_id, facility_id }
 * Admin only.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

if (!defined('ABSPATH')) {
    $current = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $current = dirname($current);
        if (file_exists($current . '/wp-load.php')) {
            require_once $current . '/wp-load.php';
            break;
        }
    }
}

$is_admin = function_exists('current_user_can') && current_user_can('edit_posts');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    if (!$is_admin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Admin authentication required']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    $news_id = (int)($body['news_id'] ?? 0);
    $facility_id = (int)($body['facility_id'] ?? 0);

    if ($news_id <= 0 || $facility_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'news_id and facility_id are required positive integers']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM news_facility_links WHERE news_id = ? AND facility_id = ?");
    $stmt->execute([$news_id, $facility_id]);

    echo json_encode([
        'success' => true,
        'data' => [
            'news_id' => $news_id,
            'facility_id' => $facility_id,
            'rows_deleted' => $stmt->rowCount(),
        ],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
