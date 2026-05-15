<?php
/**
 * Scan all URLs in a submission via Cloudmersive's website threat-detection API.
 *
 * POST /api/scan-submission-urls.php
 * Body: { "type": "wiki"|"news"|"data", "id": <int> }
 * Auth: WordPress capability `manage_options` (Cloudmersive quota is finite — admin-only).
 *
 * Returns: { success: bool, results: [ { url, clean, threats, cached, error } ], scanned, flagged }
 */

require_once __DIR__ . '/config.php';

// Bootstrap WordPress for capability checks and wp_remote_post.
if (!defined('ABSPATH')) {
    $wp_load = null;
    $current = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $current = dirname($current);
        if (file_exists($current . '/wp-load.php')) {
            $wp_load = $current . '/wp-load.php';
            break;
        }
    }
    if ($wp_load) {
        require_once $wp_load;
    }
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin login required']);
    exit;
}

if (!class_exists('KOP_Url_Scanner')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'KOP_Url_Scanner not loaded (check inc/features.php is included)']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$type = isset($payload['type']) ? strtolower(trim($payload['type'])) : '';
$id = isset($payload['id']) ? (int) $payload['id'] : 0;

if (!in_array($type, ['wiki', 'news', 'data'], true) || $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'type must be wiki|news|data and id must be a positive int']);
    exit;
}

$table = ($type === 'wiki') ? 'wiki_submissions'
       : (($type === 'news') ? 'news_submissions' : 'suggested_edits');

try {
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Submission not found']);
    exit;
}

$urls = kop_extract_urls_from_submission($type, $row);
// Deduplicate while preserving order.
$urls = array_values(array_unique($urls));

$results = [];
$flagged = 0;
foreach ($urls as $url) {
    $result = KOP_Url_Scanner::scan_url($url, $pdo);
    if ($result['clean'] === false) $flagged++;
    $results[] = $result;
}

echo json_encode([
    'success' => true,
    'submission_type' => $type,
    'submission_id' => $id,
    'scanned' => count($results),
    'flagged' => $flagged,
    'results' => $results,
]);

// ----------------------------------------------------------------------------

/**
 * Pull every plausible URL out of a submission row: dedicated url columns,
 * URLs inside json_data, and markdown link targets in wiki markdown bodies.
 */
function kop_extract_urls_from_submission($type, array $row) {
    $urls = [];

    if ($type === 'news' && !empty($row['article_url'])) {
        $urls[] = (string) $row['article_url'];
    }

    if (!empty($row['json_data'])) {
        $decoded = json_decode($row['json_data'], true);
        if (is_array($decoded)) {
            kop_collect_urls_recursive($decoded, $urls);
        }
    }

    foreach (['original_markdown', 'generated_markdown', 'summary'] as $md_field) {
        if (!empty($row[$md_field])) {
            kop_extract_urls_from_text($row[$md_field], $urls);
        }
    }

    return $urls;
}

function kop_collect_urls_recursive($value, array &$urls) {
    if (is_string($value)) {
        kop_extract_urls_from_text($value, $urls);
        return;
    }
    if (is_array($value)) {
        foreach ($value as $v) kop_collect_urls_recursive($v, $urls);
    }
}

/**
 * Find bare http(s) URLs and markdown-style `[text](url)` links in a string.
 */
function kop_extract_urls_from_text($text, array &$urls) {
    if (!is_string($text) || $text === '') return;

    // Bare URLs.
    if (preg_match_all('#https?://[^\s<>"\'\)]+#i', $text, $m)) {
        foreach ($m[0] as $u) {
            $u = rtrim($u, ".,;:!?)]}");
            if ($u !== '') $urls[] = $u;
        }
    }

    // Markdown links: [text](url) — keep only the url portion.
    if (preg_match_all('#\]\((https?://[^\s\)]+)\)#i', $text, $m)) {
        foreach ($m[1] as $u) {
            $u = rtrim($u, ".,;:!?]}");
            if ($u !== '') $urls[] = $u;
        }
    }
}
