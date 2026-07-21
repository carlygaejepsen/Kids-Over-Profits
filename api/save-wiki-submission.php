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

        // A specific status can always be requested explicitly (including
        // 'deleted' or 'rejected' to review those queues).
        if ($status) {
            $where[] = "status = :status";
            $params[':status'] = $status;
        } else {
            // Default: exclude deleted entries AND declined submissions — a
            // rejected update must not keep appearing in the wiki editor's
            // entry browser / program-linker list.
            $where[] = "status NOT IN ('deleted', 'rejected')";
        }

        if ($search) {
            $where[] = "(program_name LIKE :search1 OR city_state LIKE :search2 OR organization LIKE :search3)";
            $params[':search1'] = "%$search%";
            $params[':search2'] = "%$search%";
            $params[':search3'] = "%$search%";
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get total count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM wiki_submissions $whereClause");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        // Get submissions - use direct integer values for LIMIT/OFFSET (PDO can't bind these as named params)
        $sql = "SELECT id, program_name, city_state, organization, program_type, years_active, status,
                       submitted_by, created_at, updated_at, original_markdown, json_data
                FROM wiki_submissions $whereClause
                ORDER BY created_at DESC
                LIMIT $limit OFFSET $offset";

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
    // years_active is varchar(100); free-text longer than that would make the
    // INSERT/UPDATE throw under strict SQL mode.
    $yearsActive = mb_substr((string)($data['yearsActive'] ?? $data['years_active'] ?? ''), 0, 100);
    $generatedMarkdown = $data['generatedMarkdown'] ?? $data['generated_markdown'] ?? '';
    $status = $data['status'] ?? 'submitted';
    $submittedBy = $data['submittedBy'] ?? $data['submitted_by'] ?? '';
    $submissionNotes = $data['submissionNotes'] ?? $data['submission_notes'] ?? '';
    $originalMarkdown = $data['originalMarkdown'] ?? $data['original_markdown'] ?? '';
    $submissionId = $data['id'] ?? null;
    $organization = $data['organization'] ?? $data['organizationName'] ?? $data['org'] ?? null;

    // Program-index linkage: every non-draft submission must be tied to a
    // facilities_master record by its unique_name (the program's unique ID).
    // The wiki editor's program picker supplies this.
    $facilityUniqueName = trim((string)($data['facilityUniqueName'] ?? $data['facility_unique_name'] ?? ''));
    $documentFolderIdRaw = $data['documentFolderId'] ?? $data['document_folder_id'] ?? null;

    // Make sure the link columns exist (idempotent; safe on every save).
    foreach (['facility_unique_name' => "VARCHAR(255) DEFAULT NULL",
              'facility_link_status' => "ENUM('suggested','confirmed') DEFAULT NULL"] as $col => $def) {
        try {
            $pdo->exec("ALTER TABLE wiki_submissions ADD COLUMN `$col` $def");
        } catch (PDOException $colEx) {
            // 1060 = duplicate column; already migrated. Anything else re-throws.
            if (strpos($colEx->getMessage(), 'Duplicate column') === false
                && strpos($colEx->getMessage(), '1060') === false) {
                throw $colEx;
            }
        }
    }

    if ($status === 'deleted' && !current_user_can('manage_options')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'You do not have permission to delete entries'
        ]);
        exit;
    }

    // Deletions arrive as {id, status:'deleted'} with no content fields.
    // Handle them as a status-only update: the content validation below would
    // reject the empty programName, and the full-column UPDATE would blank
    // every other field of the record.
    if ($status === 'deleted') {
        if (!$submissionId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Submission ID is required to delete']);
            exit;
        }

        $checkStmt = $pdo->prepare("SELECT id FROM wiki_submissions WHERE id = ? LIMIT 1");
        $checkStmt->execute([(int)$submissionId]);
        if (!$checkStmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Submission not found']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE wiki_submissions SET status = 'deleted', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([(int)$submissionId]);

        echo json_encode([
            'success' => true,
            'message' => 'Entry deleted',
            'id' => (int)$submissionId
        ]);
        exit;
    }

    // DEBUG: Log if originalMarkdown is being received
    error_log("Save Wiki Submission - originalMarkdown length: " . strlen($originalMarkdown));
    
    // Validate required fields
    if (empty($programName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Program name is required']);
        exit;
    }

    // Require a linked program for anything that is actually being submitted.
    // Drafts and deletions are exempt so work-in-progress can still be saved.
    $linkExempt = in_array($status, ['draft', 'deleted'], true);
    if (!$linkExempt && $facilityUniqueName === '') {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'error'   => 'A matching program index entry is required. Pick or create one in the program selector before submitting.',
            'code'    => 'facility_link_required'
        ]);
        exit;
    }

    // Remove meta fields from JSON data to avoid duplication
    $jsonData = $data;
    unset($jsonData['generatedMarkdown'], $jsonData['generated_markdown']);
    unset($jsonData['originalMarkdown'], $jsonData['original_markdown']);
    unset($jsonData['status'], $jsonData['submittedBy'], $jsonData['submitted_by']);
    unset($jsonData['submissionNotes'], $jsonData['submission_notes']);
    unset($jsonData['organization'], $jsonData['organizationName'], $jsonData['org']);
    unset($jsonData['id']);
    unset($jsonData['facilityUniqueName'], $jsonData['facility_unique_name']);
    unset($jsonData['documentFolderId'], $jsonData['document_folder_id']);

    // 'suggested' = picked but pending admin confirmation. Unlinked drafts stay NULL.
    $facilityLinkStatus = $facilityUniqueName !== '' ? 'suggested' : null;
    $facilityUniqueNameForDb = $facilityUniqueName !== '' ? $facilityUniqueName : null;
    
    // If the picker supplied a document-library folder ID, store it on the
    // linked program (facilities_master) so the program index surfaces that
    // library by ID. Only write when a positive ID was provided so we never
    // clobber an existing folder link with a blank.
    if ($facilityUniqueNameForDb !== null
        && $documentFolderIdRaw !== null && $documentFolderIdRaw !== ''
        && (int)$documentFolderIdRaw > 0) {
        try {
            $facStmt = $pdo->prepare("SELECT json_data FROM facilities_master WHERE unique_name = ? LIMIT 1");
            $facStmt->execute([$facilityUniqueNameForDb]);
            $facJson = $facStmt->fetchColumn();
            if ($facJson !== false) {
                $facProject = json_decode($facJson ?: '{}', true);
                if (!is_array($facProject)) {
                    $facProject = [];
                }
                if (!isset($facProject['data']) || !is_array($facProject['data'])) {
                    $facProject['data'] = [];
                }
                // Store inside data (passed through by the REST layer) and at root.
                $facProject['documentFolderId'] = (int)$documentFolderIdRaw;
                $facProject['data']['documentFolderId'] = (int)$documentFolderIdRaw;
                $facUpd = $pdo->prepare(
                    "UPDATE facilities_master
                     SET json_data = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE unique_name = ?"
                );
                $facUpd->execute([
                    json_encode($facProject, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $facilityUniqueNameForDb
                ]);
            }
        } catch (PDOException $docEx) {
            // Non-fatal: the wiki submission still saves even if the doc-folder
            // write fails. Surface in logs only.
            error_log('Wiki submission doc-folder link failed: ' . $docEx->getMessage());
        }
    }

    if ($submissionId) {
        // Update existing submission
        $sql = "UPDATE wiki_submissions SET 
                    program_name = ?,
                    city_state = ?,
                    organization = ?,
                    program_type = ?,
                    years_active = ?,
                    json_data = ?,
                    generated_markdown = ?,
                    original_markdown = ?,
                    status = ?,
                    submitted_by = ?,
                    submission_notes = ?,
                    facility_unique_name = ?,
                    facility_link_status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $programName,
            $cityState,
            $organization,
            $programType,
            $yearsActive,
            json_encode($jsonData, JSON_UNESCAPED_UNICODE),
            $generatedMarkdown,
            $originalMarkdown,
            $status,
            $submittedBy,
            $submissionNotes,
            $facilityUniqueNameForDb,
            $facilityLinkStatus,
            $submissionId
        ]);
        
        if ($stmt->rowCount() === 0) {
            // 0 affected rows also happens when nothing changed (PDO counts
            // CHANGED rows) — only report 404 if the row truly doesn't exist.
            $check = $pdo->prepare("SELECT id FROM wiki_submissions WHERE id = ? LIMIT 1");
            $check->execute([$submissionId]);
            if (!$check->fetchColumn()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Submission not found']);
                exit;
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Submission updated successfully',
            'id' => (int)$submissionId
        ]);
    } else {
        // Create new submission
        $sql = "INSERT INTO wiki_submissions
                    (program_name, city_state, organization, program_type, years_active, json_data,
                     generated_markdown, original_markdown, status, submitted_by, submission_notes,
                     facility_unique_name, facility_link_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $programName,
            $cityState,
            $organization,
            $programType,
            $yearsActive,
            json_encode($jsonData, JSON_UNESCAPED_UNICODE),
            $generatedMarkdown,
            $originalMarkdown,
            $status,
            $submittedBy,
            $submissionNotes,
            $facilityUniqueNameForDb,
            $facilityLinkStatus
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
