<?php
/**
 * Check for Duplicate News Submissions
 * 
 * Checks if a URL has already been submitted to prevent duplicate processing
 * 
 * POST /api/check-news-duplicate.php
 * Body: JSON with url
 * 
 * Returns: JSON with duplicate status and existing submission details if found
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

// Load config
require_once __DIR__ . '/config.php';

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || empty($data['url'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'URL is required']);
    exit;
}

$url = trim($data['url']);

// Normalize URL for comparison (remove trailing slashes, convert to lowercase)
$normalizedUrl = rtrim(strtolower($url), '/');

try {
    // Check news_submissions table
    $stmt = $pdo->prepare("
        SELECT 
            id,
            article_data,
            submitter_email,
            submission_notes,
            created_at,
            status
        FROM news_submissions 
        WHERE LOWER(TRIM(TRAILING '/' FROM JSON_UNQUOTE(JSON_EXTRACT(article_data, '$.url')))) = :url
        ORDER BY created_at DESC
        LIMIT 1
    ");
    
    $stmt->execute(['url' => $normalizedUrl]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Parse article data
        $articleData = json_decode($existing['article_data'], true);
        
        echo json_encode([
            'success' => true,
            'isDuplicate' => true,
            'existing' => [
                'id' => $existing['id'],
                'title' => $articleData['title'] ?? 'Untitled',
                'author' => $articleData['author'] ?? '',
                'publicationName' => $articleData['publicationName'] ?? '',
                'publicationDate' => $articleData['publicationDate'] ?? '',
                'submittedAt' => $existing['created_at'],
                'submitterEmail' => $existing['submitter_email'],
                'status' => $existing['status'] ?? 'pending',
                'summary' => $articleData['summary'] ?? ''
            ]
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'isDuplicate' => false
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Duplicate check error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error checking for duplicates'
    ]);
}
