<?php
/**
 * AI News Article Processor API (Enhanced with Comprehensive Error Handling & Retry Logic)
 *
 * Endpoint to process news articles using AI (Ollama, Groq, Gemini, etc.)
 *
 * POST /api/process-news-ai.php
 * Body: JSON with url or articleText
 *
 * Returns: JSON with extracted article data
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Load config.php which handles .env and wp-config loading
define('SKIP_DB_CONNECTION', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/news-tags.php';
require_once __DIR__ . '/lib-article-fetch.php';
require_once __DIR__ . '/ai-providers.php';

// Enable detailed error logging (set to false in production if too verbose)
define('AI_DEBUG_LOGGING', true);
require_once __DIR__ . '/lib-news-ai.php';

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
    exit;
}

$url = $data['url'] ?? '';
$articleText = $data['articleText'] ?? '';
$provider = $data['provider'] ?? 'auto';
$customInstructions = $data['customInstructions'] ?? '';

if (empty($url) && empty($articleText)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Either URL or article text is required']);
    exit;
}

try {
    // Load API keys from environment variables (set via .env file loaded in config.php)
    $apiKeys = [
        'claude' => getenv('ANTHROPIC_API_KEY'),
        'groq' => getenv('GROQ_API_KEY') ?: getenv('GROK_API_KEY'),
        'gemini' => getenv('GEMINI_API_KEY'),
        'huggingface' => getenv('HUGGINGFACE_API_KEY')
    ];

    // Fetch article content if URL is provided
    $content = $articleText;
    if (empty($content) && !empty($url)) {
        $content = fetchArticleContent($url);
        if (empty($content)) {
            throw new Exception('Could not fetch article content from URL');
        }
        // "Website Disabled", a 404 notice: too little to be the article, and
        // the AI writes a summary from the headline alone when given it.
        if (mb_strlen(trim($content)) < 300) {
            throw new Exception('The page at this URL has no article text (' . mb_strlen(trim($content)) . ' characters); paste the article text instead');
        }
    }

    // Truncate content to ~20,000 bytes to avoid token limits (approx 5k tokens).
    // Cut on a UTF-8 character boundary: a byte-level substr() can split a
    // multi-byte character, json_encode() then fails on the malformed string
    // and curl posts an empty body, which Groq rejects with
    // "failed to unmarshal JSON: unexpected end of JSON input".
    if (strlen($content) > 20000) {
        $content = mb_strcut($content, 0, 20000, 'UTF-8') . "... [truncated]";
    }
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
    }

    // 'auto' (the default): Groq and Gemini take turns, and when the one
    // whose turn it is fails, the other reads the same article. The thrown
    // message joins both errors, so "rate limit" still shows when one was out.
    $order = $provider === 'auto' ? kop_ai_turn_order() : [$provider];
    if (!$order) {
        throw new Exception('No AI key configured. Add GROQ_API_KEY or GEMINI_API_KEY to your .env file.');
    }
    $errors = [];
    $result = null;
    foreach ($order as $p) {
        try {
            $result = processNewsWith($p, $apiKeys, $content, $url, $customInstructions);
            $provider = $p;
            break;
        } catch (Exception $e) {
            error_log("AI processing error [$p]: " . $e->getMessage());
            $errors[] = $e->getMessage();
        }
    }
    if ($result === null) {
        throw new Exception(implode(' | ', $errors));
    }

    echo json_encode([
        'success' => true,
        'data' => $result,
        'provider' => $provider
    ]);

} catch (Exception $e) {
    error_log("AI processing error [$provider]: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
