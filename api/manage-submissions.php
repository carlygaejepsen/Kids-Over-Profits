<?php
/**
 * Manage Submissions API (Admin)
 * 
 * Endpoint for admin operations on submissions:
 * - Review and update status (approve/reject)
 * - Delete submissions
 * - Bulk operations
 * 
 * POST /api/manage-submissions.php
 * Body: JSON with action and parameters
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $type = $_GET['type'] ?? 'wiki';
    if (!in_array($type, ['wiki', 'news'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid submission type. Use "wiki" or "news"']);
        exit;
    }

    $table = $type === 'wiki' ? 'wiki_submissions' : 'news_submissions';
    $action = $_GET['action'] ?? 'list';

    if ($action === 'get') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Submission ID is required']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
        $stmt->execute([(int)$id]);
        $submission = $stmt->fetch();

        if (!$submission) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Submission not found']);
            exit;
        }

        $submission['json_data'] = json_decode($submission['json_data'] ?? '', true);
        echo json_encode(['success' => true, 'data' => $submission]);
        exit;
    }

    $status = $_GET['status'] ?? null;
    $search = trim($_GET['search'] ?? '');
    $limit = min(max((int)($_GET['limit'] ?? 100), 1), 200);
    $offset = max((int)($_GET['offset'] ?? 0), 0);

    $where = [];
    $params = [];

    if ($status) {
        $where[] = "status = :status";
        $params[':status'] = $status;
    }

    if ($search) {
        $where[] = "(program_name LIKE :search1 OR city_state LIKE :search2 OR organization LIKE :search3)";
        $params[':search1'] = "%$search%";
        $params[':search2'] = "%$search%";
        $params[':search3'] = "%$search%";
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM $table $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "SELECT id, program_name, city_state, organization, program_type, years_active, status,
                   submitted_by, created_at, updated_at, json_data
            FROM $table $whereClause
            ORDER BY created_at DESC
            LIMIT {$limit} OFFSET {$offset}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $submissions = $stmt->fetchAll();

    foreach ($submissions as &$row) {
        $row['json_data'] = json_decode($row['json_data'] ?? '', true);
    }
    unset($row);

    echo json_encode([
        'success' => true,
        'data' => $submissions,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]);
    exit;
}

try {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON input']);
        exit;
    }
    
    $action = $data['action'] ?? '';
    $type = $data['type'] ?? ''; // 'wiki' or 'news'
    $ids = $data['ids'] ?? ($data['id'] ? [$data['id']] : []);
    
    // Validate type
    if (!in_array($type, ['wiki', 'news'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid submission type. Use "wiki" or "news"']);
        exit;
    }
    
    $table = $type === 'wiki' ? 'wiki_submissions' : 'news_submissions';
    
    switch ($action) {
        case 'approve':
        case 'reject':
        case 'publish':
            if (empty($ids)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No submission IDs provided']);
                exit;
            }
            
            $status = $action === 'approve' ? 'approved' : ($action === 'publish' ? 'published' : 'rejected');
            $reviewerNotes = $data['reviewerNotes'] ?? $data['reviewer_notes'] ?? '';
            $reviewedBy = $data['reviewedBy'] ?? $data['reviewed_by'] ?? '';
            
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE $table SET 
                        status = ?,
                        reviewer_notes = ?,
                        reviewed_by = ?,
                        reviewed_at = CURRENT_TIMESTAMP
                    WHERE id IN ($placeholders)";
            
            $params = array_merge([$status, $reviewerNotes, $reviewedBy], $ids);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $affected = $stmt->rowCount();
            echo json_encode([
                'success' => true,
                'message' => "$affected submission(s) marked as $status",
                'affected' => $affected
            ]);
            break;
            
        case 'delete':
            if (empty($ids)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No submission IDs provided']);
                exit;
            }
            
            // Also delete any attachments
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            $attachmentSql = "DELETE FROM submission_attachments 
                              WHERE submission_type = ? AND submission_id IN ($placeholders)";
            $attachmentParams = array_merge([$type], $ids);
            $attachmentStmt = $pdo->prepare($attachmentSql);
            $attachmentStmt->execute($attachmentParams);
            
            // Delete submissions
            $sql = "DELETE FROM $table WHERE id IN ($placeholders)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($ids);
            
            $affected = $stmt->rowCount();
            echo json_encode([
                'success' => true,
                'message' => "$affected submission(s) deleted",
                'affected' => $affected
            ]);
            break;
            
        case 'update_status':
            if (empty($ids)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No submission IDs provided']);
                exit;
            }
            
            $newStatus = $data['status'] ?? '';
            $validStatuses = ['draft', 'submitted', 'approved', 'published', 'rejected'];
            
            if (!in_array($newStatus, $validStatuses)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid status']);
                exit;
            }
            
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE $table SET status = ? WHERE id IN ($placeholders)";
            $params = array_merge([$newStatus], $ids);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $affected = $stmt->rowCount();
            echo json_encode([
                'success' => true,
                'message' => "$affected submission(s) updated to status: $newStatus",
                'affected' => $affected
            ]);
            break;
            
        case 'stats':
            // Get submission statistics
            $wikiStats = $pdo->query("
                SELECT status, COUNT(*) as count 
                FROM wiki_submissions 
                GROUP BY status
            ")->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $newsStats = $pdo->query("
                SELECT status, COUNT(*) as count 
                FROM news_submissions 
                GROUP BY status
            ")->fetchAll(PDO::FETCH_KEY_PAIR);
            
            $newsTypeStats = $pdo->query("
                SELECT article_type, COUNT(*) as count 
                FROM news_submissions 
                GROUP BY article_type
            ")->fetchAll(PDO::FETCH_KEY_PAIR);
            
            echo json_encode([
                'success' => true,
                'wiki' => [
                    'by_status' => $wikiStats,
                    'total' => array_sum($wikiStats)
                ],
                'news' => [
                    'by_status' => $newsStats,
                    'by_type' => $newsTypeStats,
                    'total' => array_sum($newsStats)
                ]
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action',
                'valid_actions' => ['approve', 'reject', 'publish', 'delete', 'update_status', 'stats']
            ]);
    }
    
} catch (PDOException $e) {
    error_log("Manage submissions error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred',
        'details' => $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Manage submissions error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred',
        'details' => $e->getMessage()
    ]);
}
