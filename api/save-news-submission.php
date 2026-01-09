<?php
/**
 * Save News Processor Submission API
 * 
 * Endpoint to save news processor form submissions to the database.
 * 
 * POST /api/save-news-submission.php
 * Body: JSON with form data
 * 
 * Returns: JSON with submission ID and status
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

// Fallback: Load WordPress if not already loaded (e.g. if config.php failed to find it)
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(dirname(__DIR__)))) . '/');
    if (file_exists(ABSPATH . 'wp-config.php')) {
        require_once ABSPATH . 'wp-config.php';
    }
}

try {
    // GET request - retrieve submissions
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = $_GET['id'] ?? null;
        $status = $_GET['status'] ?? null;
        $type = $_GET['type'] ?? null;
        $search = $_GET['search'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $offset = (int)($_GET['offset'] ?? 0);
        
        if ($id) {
            // Get single submission
            $stmt = $pdo->prepare("SELECT * FROM news_submissions WHERE id = ?");
            $stmt->execute([$id]);
            $submission = $stmt->fetch();
            
            if ($submission) {
                $submission['json_data'] = json_decode($submission['json_data'], true);
                $submission['facilities_mentioned'] = json_decode($submission['facilities_mentioned'], true);
                $submission['staff_mentioned'] = json_decode($submission['staff_mentioned'], true);
                $submission['survivors_mentioned'] = json_decode($submission['survivors_mentioned'], true);
                $submission['content_warnings'] = json_decode($submission['content_warnings'], true);
                echo json_encode(['success' => true, 'data' => $submission]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Submission not found']);
            }
            exit;
        }
        
        // List submissions
        $where = [];
        $params = [];
        
        if ($status) {
            $where[] = "status = ?";
            $params[] = $status;
        }
        
        if ($type) {
            $where[] = "article_type = ?";
            $params[] = $type;
        }
        
        if ($search) {
            $where[] = "(article_title LIKE ? OR publication_name LIKE ? OR author LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // Get total count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM news_submissions $whereClause");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();
        
        // Get submissions
        $sql = "SELECT id, article_title, alternate_title, author, publication_name, 
                       publication_date, article_type, status, submitted_by, created_at, updated_at 
                FROM news_submissions $whereClause 
                ORDER BY created_at DESC 
                LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $submissions = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $submissions,
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset
        ]);
        exit;
    }
    
    // POST request - save submission
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }
    
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
        exit;
    }
    
    // Extract key fields
    $articleTitle = $data['title'] ?? $data['article_title'] ?? '';
    $alternateTitle = $data['alternateTitle'] ?? $data['alternate_title'] ?? '';
    $author = $data['author'] ?? '';
    $publicationName = $data['publicationName'] ?? $data['publication_name'] ?? '';
    $publicationDate = $data['publicationDate'] ?? $data['publication_date'] ?? null;
    $articleUrl = $data['url'] ?? $data['article_url'] ?? '';
    $articleType = $data['articleType'] ?? $data['article_type'] ?? 'general';
    $location = $data['location'] ?? $data['article_location'] ?? '';
    
    // Handle arrays - could be string or array
    $tags = $data['tags'] ?? [];
    if (is_string($tags)) {
        $tags = array_filter(array_map('trim', explode("\n", $tags)));
    }

    // Handle arrays - could be string or array
    $facilities = $data['facilities'] ?? [];
    if (is_string($facilities)) {
        $facilities = array_filter(array_map('trim', explode("\n", $facilities)));
    }
    
    $staff = $data['staff'] ?? [];
    if (is_string($staff)) {
        $staff = array_filter(array_map('trim', explode("\n", $staff)));
    }
    
    $survivors = $data['survivors'] ?? [];
    if (is_string($survivors)) {
        $survivors = array_filter(array_map('trim', explode("\n", $survivors)));
    }
    
    $contentWarnings = $data['contentWarnings'] ?? $data['content_warnings'] ?? [];
    $summary = $data['summary'] ?? '';
    $generatedOutput = $data['generatedOutput'] ?? $data['generated_output'] ?? '';
    $status = $data['status'] ?? 'submitted';
    $submittedBy = $data['submittedBy'] ?? $data['submitted_by'] ?? '';
    $submissionNotes = $data['submissionNotes'] ?? $data['submission_notes'] ?? '';
    $submissionId = $data['id'] ?? null;
    
    // Validate required fields
    if (empty($articleTitle)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Article title is required']);
        exit;
    }
    
    // Validate article type
    $validTypes = ['lawsuit', 'event', 'expose', 'arrest', 'closure', 'corporate', 'general'];
    if (!in_array($articleType, $validTypes)) {
        $articleType = 'general';
    }
    
    // Format date - handle empty strings
    if (empty($publicationDate)) {
        $publicationDate = null;
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $publicationDate)) {
        $timestamp = strtotime($publicationDate);
        $publicationDate = $timestamp ? date('Y-m-d', $timestamp) : null;
    }
    
    // Remove meta fields from JSON data to avoid duplication
    $jsonData = $data;
    unset($jsonData['generatedOutput'], $jsonData['generated_output']);
    unset($jsonData['status'], $jsonData['submittedBy'], $jsonData['submitted_by']);
    unset($jsonData['submissionNotes'], $jsonData['submission_notes']);
    unset($jsonData['id']);
    
    if ($submissionId) {
        // Update existing submission
        $sql = "UPDATE news_submissions SET 
                    article_title = ?,
                    alternate_title = ?,
                    author = ?,
                    publication_name = ?,
                    publication_date = ?,
                    article_url = ?,
                    article_type = ?,
                    article_location = ?,
                    tags = ?,
                    facilities_mentioned = ?,
                    staff_mentioned = ?,
                    survivors_mentioned = ?,
                    content_warnings = ?,
                    summary = ?,
                    json_data = ?,
                    generated_output = ?,
                    status = ?,
                    submitted_by = ?,
                    submission_notes = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $articleTitle,
            $alternateTitle,
            $author,
            $publicationName,
            $publicationDate,
            $articleUrl,
            $articleType,
            $location,
            json_encode($tags, JSON_UNESCAPED_UNICODE),
            json_encode($facilities, JSON_UNESCAPED_UNICODE),
            json_encode($staff, JSON_UNESCAPED_UNICODE),
            json_encode($survivors, JSON_UNESCAPED_UNICODE),
            json_encode($contentWarnings, JSON_UNESCAPED_UNICODE),
            $summary,
            json_encode($jsonData, JSON_UNESCAPED_UNICODE),
            $generatedOutput,
            $status,
            $submittedBy,
            $submissionNotes,
            $submissionId
        ]);
        
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Submission not found']);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Submission updated successfully',
            'id' => (int)$submissionId
        ]);
    } else {
        // Create new submission
        $sql = "INSERT INTO news_submissions 
                    (article_title, alternate_title, author, publication_name, publication_date,
                     article_url, article_type, article_location, tags, facilities_mentioned, staff_mentioned,
                     survivors_mentioned, content_warnings, summary, json_data, 
                     generated_output, status, submitted_by, submission_notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $articleTitle,
            $alternateTitle,
            $author,
            $publicationName,
            $publicationDate,
            $articleUrl,
            $articleType,
            $location,
            json_encode($tags, JSON_UNESCAPED_UNICODE),
            json_encode($facilities, JSON_UNESCAPED_UNICODE),
            json_encode($staff, JSON_UNESCAPED_UNICODE),
            json_encode($survivors, JSON_UNESCAPED_UNICODE),
            json_encode($contentWarnings, JSON_UNESCAPED_UNICODE),
            $summary,
            json_encode($jsonData, JSON_UNESCAPED_UNICODE),
            $generatedOutput,
            $status,
            $submittedBy,
            $submissionNotes
        ]);
        
        $newId = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Submission saved successfully',
            'id' => (int)$newId
        ]);
    }
    
} catch (PDOException $e) {
    error_log("News submission error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred',
        'details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("News submission error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred',
        'details' => $e->getMessage()
    ]);
}
