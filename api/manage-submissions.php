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
    if (!in_array($type, ['wiki', 'news', 'data'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid submission type. Use "wiki", "news", or "data"']);
        exit;
    }

    $table = $type === 'wiki' ? 'wiki_submissions' : ($type === 'news' ? 'news_submissions' : 'suggested_edits');
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

        $jsonKey = ($type === 'data') ? 'edited_json_data' : 'json_data';
        $submission['json_data'] = json_decode($submission[$jsonKey] ?? '', true);
        if ($type === 'data') {
            $submission['program_name'] = $submission['master_id'];
            $submission['submitted_by'] = $submission['submitter_ip'] ?? 'Anonymous';
            $submission['submission_notes'] = $submission['reason'] ?? '';
        }

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
        // suggested_edits uses 'pending' for unreviewed rows (schema enum
        // ('pending','approved','rejected')); wiki/news tables use 'submitted'.
        // Translate the admin UI's canonical "Pending Review" value so the
        // same filter works across all three types.
        if ($type === 'data' && $status === 'submitted') {
            $status = 'pending';
        }
        $where[] = "status = :status";
        $params[':status'] = $status;
    }

    if ($search) {
        if ($type === 'data') {
            // suggested_edits stores the facility identifier in master_id (a
            // sanitized program-name string, not a numeric FK)
            $where[] = "(master_id LIKE :search)";
            $params[':search'] = "%$search%";
        } elseif ($type === 'news') {
            $where[] = "(article_title LIKE :search1 OR publication_name LIKE :search2 OR author LIKE :search3)";
            $params[':search1'] = "%$search%";
            $params[':search2'] = "%$search%";
            $params[':search3'] = "%$search%";
        } else {
            $where[] = "(program_name LIKE :search1 OR city_state LIKE :search2 OR organization LIKE :search3)";
            $params[':search1'] = "%$search%";
            $params[':search2'] = "%$search%";
            $params[':search3'] = "%$search%";
        }
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM $table $whereClause");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    if ($type === 'data') {
        // suggested_edits real columns: id, master_id, edited_json_data,
        // reason, submitter_ip, status, created_at. Alias to the names the
        // admin JS expects so news/wiki/data rendering can share the
        // submission shape downstream.
        $sql = "SELECT id, master_id as program_name, status,
                       submitter_ip as submitted_by, created_at,
                       edited_json_data as json_data,
                       reason as submission_notes
                FROM $table $whereClause
                ORDER BY created_at DESC
                LIMIT {$limit} OFFSET {$offset}";
    } elseif ($type === 'news') {
        $sql = "SELECT id, article_title as program_name, publication_name as organization, 
                       article_type as program_type, author as submitted_by, 
                       publication_date as years_active, status, created_at, updated_at, json_data
                FROM $table $whereClause
                ORDER BY created_at DESC
                LIMIT {$limit} OFFSET {$offset}";
    } else {
        $sql = "SELECT id, program_name, city_state, organization, program_type, years_active, status,
                       submitted_by, created_at, updated_at, json_data
                FROM $table $whereClause
                ORDER BY created_at DESC
                LIMIT {$limit} OFFSET {$offset}";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $submissions = $stmt->fetchAll();

    foreach ($submissions as &$row) {
        $row['json_data'] = json_decode($row['json_data'] ?? '', true);
        if ($type === 'data' && empty($row['submitted_by'])) {
             $row['submitted_by'] = 'Anonymous';
        }
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
    if (!in_array($type, ['wiki', 'news', 'data'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid submission type. Use "wiki", "news", or "data"']);
        exit;
    }
    
    $table = $type === 'wiki' ? 'wiki_submissions' : ($type === 'news' ? 'news_submissions' : 'suggested_edits');
    
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
            
            if ($type === 'data') {
                // suggested_edits table has limited columns
                $sql = "UPDATE $table SET 
                            status = ?
                        WHERE id IN ($placeholders)";
                $params = array_merge([$status], $ids);
            } else {
                $sql = "UPDATE $table SET 
                            status = ?,
                            reviewer_notes = ?,
                            reviewed_by = ?,
                            reviewed_at = CURRENT_TIMESTAMP
                        WHERE id IN ($placeholders)";
                $params = array_merge([$status, $reviewerNotes, $reviewedBy], $ids);
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $affected = $stmt->rowCount();

            // NEW: If wiki submissions were approved/published, update wiki_master table
            if ($type === 'wiki' && ($status === 'approved' || $status === 'published')) {
                foreach ($ids as $id) {
                    try {
                        // Fetch the submission data
                        $fetchStmt = $pdo->prepare("SELECT program_name, city_state, organization, program_type, years_active, json_data, generated_markdown FROM wiki_submissions WHERE id = ?");
                        $fetchStmt->execute([$id]);
                        $sub = $fetchStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($sub) {
                            // Generate a simple slug if none exists in JSON data
                            $jsonData = json_decode($sub['json_data'], true);
                            $slug = $jsonData['slug'] ?? strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $sub['program_name'])));
                            
                            // Upsert into wiki_master
                            $masterSql = "INSERT INTO wiki_master 
                                            (slug, program_name, city_state, organization, program_type, years_active, json_data, markdown, last_updated_by, updated_at)
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                                          ON DUPLICATE KEY UPDATE 
                                            program_name = VALUES(program_name),
                                            city_state = VALUES(city_state),
                                            organization = VALUES(organization),
                                            program_type = VALUES(program_type),
                                            years_active = VALUES(years_active),
                                            json_data = VALUES(json_data),
                                            markdown = VALUES(markdown),
                                            last_updated_by = VALUES(last_updated_by),
                                            updated_at = CURRENT_TIMESTAMP";
                            
                            $masterStmt = $pdo->prepare($masterSql);
                            $masterStmt->execute([
                                $slug,
                                $sub['program_name'],
                                $sub['city_state'],
                                $sub['organization'],
                                $sub['program_type'],
                                $sub['years_active'],
                                $sub['json_data'],
                                $sub['generated_markdown'],
                                $reviewedBy ?: 'admin'
                            ]);
                        }
                    } catch (PDOException $e) {
                        error_log("Failed to update wiki_master for ID $id: " . $e->getMessage());
                        // We don't fail the whole request, but we log it
                    }
                }
            }

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

        case 'update_markdown':
            // Update the generated markdown for a wiki submission
            if ($type !== 'wiki') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Markdown updates only supported for wiki submissions']);
                exit;
            }

            $id = $data['id'] ?? null;
            $generatedMarkdown = $data['generated_markdown'] ?? null;

            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Submission ID is required']);
                exit;
            }

            if ($generatedMarkdown === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Generated markdown is required']);
                exit;
            }

            $sql = "UPDATE wiki_submissions SET
                        generated_markdown = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$generatedMarkdown, $id]);

            if ($stmt->rowCount() > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Markdown updated successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'No submission found with that ID or no changes made'
                ]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action',
                'valid_actions' => ['approve', 'reject', 'publish', 'delete', 'update_status', 'update_markdown', 'stats']
            ]);
    }
    
} catch (PDOException $e) {
    error_log("Manage submissions error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred',
        'details' => $e->getMessage(),
        'query_error' => true
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
