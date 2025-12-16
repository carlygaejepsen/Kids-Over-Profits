<?php
/**
 * Fetch a Reddit wiki page and return its markdown.
 *
 * GET parameters:
 *   - slug: the wiki slug (e.g., "alpineacademy")
 *
 * The endpoint will try file_get_contents first and fall back to curl if necessary.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    respondError('Missing slug parameter.', 400);
}

if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) {
    respondError('Invalid slug format.', 400);
}

$url = "https://www.reddit.com/r/troubledteens/wiki/{$slug}.json";
$payload = fetchRedditWikiPage($url);
if (!$payload) {
    respondError('Unable to fetch the wiki entry from Reddit.', 502);
}

$data = json_decode($payload, true);
if (!is_array($data)) {
    respondError('Received malformed response from Reddit.', 502);
}

$markdown = $data['data']['content_md'] ?? '';
respondSuccess($slug, $markdown);

function fetchRedditWikiPage(string $url): ?string
{
    $body = fetchWithFileGetContents($url);
    if ($body !== null) {
        return $body;
    }
    return fetchWithCurl($url);
}

function fetchWithFileGetContents(string $url): ?string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: TTI Wiki Editor/1.0\r\nAccept: application/json\r\n",
            'timeout' => 20
        ]
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }

    global $http_response_header;
    if (!isHttpSuccess($http_response_header ?? [])) {
        return null;
    }

    return $body;
}

function fetchWithCurl(string $url): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_USERAGENT, 'TTI Wiki Editor/1.0');

    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $status >= 400) {
        return null;
    }

    return $body;
}

function isHttpSuccess(array $headers): bool
{
    if (empty($headers)) {
        return false;
    }

    $statusLine = $headers[0];
    return (bool) preg_match('#HTTP/\d+\.\d+\s+2\d\d#', $statusLine);
}

function respondSuccess(string $slug, string $markdown): void
{
    respondJson([
        'success' => true,
        'slug' => $slug,
        'markdown' => $markdown
    ]);
}

function respondError(string $message, int $statusCode = 400): void
{
    respondJson([
        'success' => false,
        'error' => $message
    ], $statusCode);
}

function respondJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
