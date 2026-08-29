<?php
/**
 * Pre-submit duplicate-URL check (public, read-only).
 *
 * Lets a form ask "does an entry already exist for this URL?" BEFORE running an
 * AI generation or submitting, so the user can be warned and blocked instead of
 * creating a duplicate. Mirrors exactly the matching rules the submit endpoints
 * enforce server-side (api/url-dedupe.php).
 *
 * Request (GET or POST/JSON):
 *   type        : 'news' | 'legislation' | 'lawsuit'   (required)
 *   url         : a single URL                          (url or urls required)
 *   urls        : array of URLs (or newline string)
 *   title       : news only — article title, checked (with outlet) against
 *   outlet      : news only — publication name          existing titles
 *   exclude_id  : id of the record being edited, so it doesn't match itself
 *
 * Response: { success, blocked: bool, duplicates: [ {id,status,title,url} ] }
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
require_once __DIR__ . '/url-dedupe.php';
require_once __DIR__ . '/news-story-groups.php';

// Accept JSON body or form/query params.
$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}
$param = static function (string $key) use ($body) {
    if (array_key_exists($key, $body)) {
        return $body[$key];
    }
    return $_REQUEST[$key] ?? null;
};

$type = (string) ($param('type') ?? '');
if (!kop_url_dedupe_config($type)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing type. Use news, legislation, or lawsuit.']);
    exit;
}

// Gather URLs from `url` (scalar) and/or `urls` (array or newline string).
$rawUrls = [];
$single = $param('url');
if (is_string($single)) {
    $rawUrls[] = $single;
}
$multi = $param('urls');
if (is_array($multi)) {
    foreach ($multi as $u) {
        if (is_string($u)) {
            $rawUrls[] = $u;
        }
    }
} elseif (is_string($multi) && $multi !== '') {
    foreach (preg_split('/[\r\n]+/', $multi) as $u) {
        $rawUrls[] = $u;
    }
}

$excludeId = (int) ($param('exclude_id') ?? 0);

// Preferred form: a per-field map keyed by DB column, e.g.
// { "full_text_url": "…", "official_url": "…" }. Each field is matched only
// against the same column, exactly. Falls back to the flat url/urls list
// (matched against every column) for single-URL types like news.
$fields = $param('fields');

try {
    if (is_array($fields) && kop_is_assoc($fields)) {
        $duplicates = kop_check_url_duplicates($pdo, $type, $fields);
    } else {
        $normalized = kop_collect_urls(...$rawUrls);
        $duplicates = kop_check_url_duplicates($pdo, $type, $normalized);
    }

    // News also matches on identical title + outlet (URL variants of one
    // article), mirroring the guard in save-news-submission.php.
    $checkTitle = $param('title');
    if ($type === 'news' && is_string($checkTitle) && trim($checkTitle) !== '') {
        $outlet = $param('outlet');
        $seen = array_column($duplicates, null, 'id');
        foreach (kop_news_find_title_duplicates($pdo, $checkTitle, is_string($outlet) ? $outlet : '') as $d) {
            if (!isset($seen[$d['id']])) {
                $duplicates[] = $d;
            }
        }
    }

    if ($excludeId > 0) {
        $duplicates = array_values(array_filter($duplicates, static function ($d) use ($excludeId) {
            return (int) $d['id'] !== $excludeId;
        }));
    }

    echo json_encode([
        'success'    => true,
        'blocked'    => !empty($duplicates),
        'duplicates' => $duplicates,
    ]);
} catch (PDOException $e) {
    error_log('check-duplicate-url.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
}
