<?php
/**
 * Link news <-> facility.
 *
 * Methods:
 *   POST  body { news_id, facility_id, link_type? }    -> create or upsert a link
 *   GET   ?facility_id=N                               -> list linked articles for a facility
 *   GET   ?news_id=N                                   -> list linked facilities for an article
 *
 * Writes require an admin user (current_user_can('edit_posts')).
 * GETs are public.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/news-mentions.php';

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
$current_user_login = function_exists('wp_get_current_user') ? (wp_get_current_user()->user_login ?: null) : null;

$VALID_LINK_TYPES = ['mentioned', 'primary', 'related'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $facility_id = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0;
        $news_id = isset($_GET['news_id']) ? (int)$_GET['news_id'] : 0;

        if ($facility_id > 0) {
            $sql = "SELECT n.id, n.article_title, n.alternate_title, n.author, n.publication_name,
                           n.publication_date, n.article_url, n.article_type, n.summary,
                           n.facilities_mentioned, n.status,
                           l.link_type, l.created_at AS linked_at
                    FROM news_facility_links l
                    JOIN news_submissions n ON n.id = l.news_id
                    WHERE l.facility_id = ?
                      " . ($is_admin ? "" : "AND n.status IN ('approved','published')") . "
                    ORDER BY n.publication_date DESC, n.created_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$facility_id]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $r['facilities_mentioned'] = kop_normalize_facility_mentions($r['facilities_mentioned'] ?? '[]');
                $r['display_title'] = !empty($r['alternate_title']) ? $r['alternate_title'] : $r['article_title'];
            }
            echo json_encode(['success' => true, 'data' => $rows]);
            exit;
        }

        if ($news_id > 0) {
            $sql = "SELECT f.id, f.unique_name, l.link_type, l.created_at AS linked_at
                    FROM news_facility_links l
                    JOIN facilities_master f ON f.id = l.facility_id
                    WHERE l.news_id = ?
                    ORDER BY f.unique_name ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$news_id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide facility_id or news_id']);
        exit;
    }

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
    $link_type = isset($body['link_type']) ? strtolower(trim($body['link_type'])) : 'mentioned';

    if ($news_id <= 0 || $facility_id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'news_id and facility_id are required positive integers']);
        exit;
    }

    if (!in_array($link_type, $VALID_LINK_TYPES, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'link_type must be one of: ' . implode(', ', $VALID_LINK_TYPES)]);
        exit;
    }

    $check = $pdo->prepare("SELECT 1 FROM news_submissions WHERE id = ?");
    $check->execute([$news_id]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'news_id not found']);
        exit;
    }

    $check = $pdo->prepare("SELECT 1 FROM facilities_master WHERE id = ?");
    $check->execute([$facility_id]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'facility_id not found']);
        exit;
    }

    $sql = "INSERT INTO news_facility_links (news_id, facility_id, link_type, created_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE link_type = VALUES(link_type)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$news_id, $facility_id, $link_type, $current_user_login]);

    echo json_encode([
        'success' => true,
        'data' => [
            'news_id' => $news_id,
            'facility_id' => $facility_id,
            'link_type' => $link_type,
            'rows_affected' => $stmt->rowCount(),
        ],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
