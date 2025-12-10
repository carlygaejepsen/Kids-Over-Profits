<?php
/**
 * Save Wiki Editor Submission API
 * 
 * Endpoint to save wiki editor form submissions to the database.
 * 
 * POST /api/save-wiki-submission.php
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

try {
    // GET request - retrieve submissions
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = $_GET['id'] ?? null;
        $status = $_GET['status'] ?? null;
        $search = $_GET['search'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $offset = (int)($_GET['offset'] ?? 0);
        
        if ($id) {
            // Get single submission
            $stmt = $pdo->prepare("SELECT * FROM wiki_submissions WHERE id = ?");
            $stmt->execute([$id]);
            $submission = $stmt->fetch();
            
            if ($submission) {
                $submission['json_data'] = json_decode($submission['json_data'], true);
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
            $where[] = "status = :status";
            $params[':status'] = $status;
        }

        if ($search) {
            $where[] = "(program_name LIKE :search1 OR city_state LIKE :search2)";
            $params[':search1'] = "%$search%";
            $params[':search2'] = "%$search%";
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get total count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM wiki_submissions $whereClause");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        // Get submissions - add limit/offset to params
        $sql = "SELECT id, program_name, city_state, program_type, years_active, status,
                       submitted_by, created_at, updated_at, original_markdown
                FROM wiki_submissions $whereClause
                ORDER BY created_at DESC
                LIMIT :limit OFFSET :offset";

        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

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
    $programName = $data['programName'] ?? $data['program_name'] ?? '';
    $cityState = $data['cityState'] ?? $data['city_state'] ?? '';
    $programType = $data['programType'] ?? $data['program_type'] ?? '';
    $yearsActive = $data['yearsActive'] ?? $data['years_active'] ?? '';
    $generatedMarkdown = $data['generatedMarkdown'] ?? $data['generated_markdown'] ?? '';
    $status = $data['status'] ?? 'submitted';
    $submittedBy = $data['submittedBy'] ?? $data['submitted_by'] ?? '';
    $submissionNotes = $data['submissionNotes'] ?? $data['submission_notes'] ?? '';
    $originalMarkdown = $data['originalMarkdown'] ?? $data['original_markdown'] ?? '';
    $submissionId = $data['id'] ?? null;
    
    // Validate required fields
    if (empty($programName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Program name is required']);
        exit;
    }
    
    // Remove meta fields from JSON data to avoid duplication
    $jsonData = $data;
    unset($jsonData['generatedMarkdown'], $jsonData['generated_markdown']);
    unset($jsonData['originalMarkdown'], $jsonData['original_markdown']);
    unset($jsonData['status'], $jsonData['submittedBy'], $jsonData['submitted_by']);
    unset($jsonData['submissionNotes'], $jsonData['submission_notes']);
    unset($jsonData['id']);
    
    if ($submissionId) {
        // Update existing submission
        $sql = "UPDATE wiki_submissions SET 
                    program_name = ?,
                    city_state = ?,
                    program_type = ?,
                    years_active = ?,
                    json_data = ?,
                    generated_markdown = ?,
                    original_markdown = ?,
                    status = ?,
                    submitted_by = ?,
                    submission_notes = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $programName,
            $cityState,
            $programType,
            $yearsActive,
            json_encode($jsonData, JSON_UNESCAPED_UNICODE),
            $generatedMarkdown,
            $originalMarkdown,
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
        $sql = "INSERT INTO wiki_submissions 
                    (program_name, city_state, program_type, years_active, json_data, 
                     generated_markdown, original_markdown, status, submitted_by, submission_notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $programName,
            $cityState,
            $programType,
            $yearsActive,
            json_encode($jsonData, JSON_UNESCAPED_UNICODE),
            $generatedMarkdown,
            $originalMarkdown,
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
    error_log("Wiki submission error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred',
        'details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Wiki submission error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred',
        'details' => $e->getMessage()
    ]);
}
