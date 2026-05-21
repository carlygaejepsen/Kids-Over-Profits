<?php
/**
 * Search news_submissions for the admin facility linker.
 *
 * GET /api/news-search.php?q=raid[&limit=20]
 *
 * Returns up to `limit` matches, each shaped:
 *   { id, display_title, article_title, alternate_title, publication_name,
 *     publication_date, article_type, article_url, status }
 *
 * Matches against article_title / alternate_title (case-insensitive LIKE).
 * Public GET; non-admin callers only see approved/published rows.
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
    $q = trim((string)($_GET['q'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 20);
    if ($limit < 1) $limit = 20;
    if ($limit > 50) $limit = 50;

    if ($q === '') {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $like = '%' . $q . '%';
    $sql = "SELECT id, article_title, alternate_title, publication_name, publication_date,
                   article_type, article_url, status
            FROM news_submissions
            WHERE (article_title LIKE ? OR alternate_title LIKE ? OR publication_name LIKE ?)
              " . ($is_admin ? "" : "AND status IN ('approved','published')") . "
            ORDER BY publication_date DESC, created_at DESC
            LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $like);
    $stmt->bindValue(2, $like);
    $stmt->bindValue(3, $like);
    $stmt->bindValue(4, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['display_title'] = !empty($r['alternate_title']) ? $r['alternate_title'] : $r['article_title'];
    }

    echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
