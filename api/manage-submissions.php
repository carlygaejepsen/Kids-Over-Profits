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
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
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
