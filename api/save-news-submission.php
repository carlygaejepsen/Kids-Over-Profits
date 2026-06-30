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
require_once __DIR__ . '/news-mentions.php';
require_once __DIR__ . '/facility-aliases.php';
require_once __DIR__ . '/url-dedupe.php';

// Fallback: Load WordPress if not already loaded (e.g. if config.php failed to find it)
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(dirname(dirname(dirname(__DIR__)))) . '/');
    if (file_exists(ABSPATH . 'wp-config.php')) {
        require_once ABSPATH . 'wp-config.php';
    }
}

/**
 * Sync news_facility_links rows for a given news submission based on the
 * normalized facilities_mentioned list.
 *
 *   - Deletes any existing links whose facility_id is no longer in the list.
 *   - Inserts new links for facility_ids that aren't already linked.
 *
 * Mentions that arrive without a facility_id (free-text or AI-extracted names)
 * are resolved by name against facilities_master - matching unique_name, the
 * operating company's name, and the curated "Other Names / Former Names" aliases
 * via the shared index in api/facility-aliases.php. This is the same resolution
 * the retroactive backfill uses, so new articles link operators and renamed
 * facilities the same way. A name with no confident match stays text-only.
 */
function kop_sync_news_facility_links(PDO $pdo, int $newsId, array $normalizedMentions, ?string $createdBy = null): void {
    $desiredIds = [];
    $unresolvedNames = [];
    foreach ($normalizedMentions as $m) {
        if (!empty($m['facility_id'])) {
            $desiredIds[(int)$m['facility_id']] = true;
        } elseif (!empty($m['name'])) {
            $unresolvedNames[$m['name']] = true;
        }
    }

    if (!empty($unresolvedNames)) {
        // Built once per request and cached; the index decodes facilities_master
        // so we avoid rebuilding it if this runs again in the same save.
        static $aliasIndex = null;
        if ($aliasIndex === null) {
            $aliasIndex = kop_build_facility_alias_index($pdo);
        }
        foreach (array_keys($unresolvedNames) as $name) {
            $fid = kop_resolve_name_to_facility((string)$name, $aliasIndex);
            if ($fid !== null) {
                $desiredIds[$fid] = true;
            }
        }
    }

    $desiredIds = array_keys($desiredIds);

    if (empty($desiredIds)) {
        $stmt = $pdo->prepare("DELETE FROM news_facility_links WHERE news_id = ?");
        $stmt->execute([$newsId]);
    } else {
        $placeholders = implode(',', array_fill(0, count($desiredIds), '?'));
        $stmt = $pdo->prepare(
            "DELETE FROM news_facility_links WHERE news_id = ? AND facility_id NOT IN ($placeholders)"
        );
        $stmt->execute(array_merge([$newsId], $desiredIds));

        $ins = $pdo->prepare(
            "INSERT IGNORE INTO news_facility_links (news_id, facility_id, link_type, created_by)
             VALUES (?, ?, 'mentioned', ?)"
        );
        foreach ($desiredIds as $fid) {
            $ins->execute([$newsId, $fid, $createdBy]);
        }
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
                $submission['facilities_mentioned'] = kop_normalize_facility_mentions($submission['facilities_mentioned']);
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

    // Handle arrays - could be string (newline-separated), array of strings,
    // or array of {name, facility_id} objects. Normalize to the object shape.
    $facilities = $data['facilities'] ?? $data['facilities_mentioned'] ?? [];
    if (is_string($facilities)) {
        $facilities = array_filter(array_map('trim', explode("\n", $facilities)));
    }
    $facilities = kop_normalize_facility_mentions($facilities);
    
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
            // No rows changed could mean "not found" OR "no actual field changed".
            // Verify the row exists; only 404 when truly missing.
            $check = $pdo->prepare("SELECT 1 FROM news_submissions WHERE id = ?");
            $check->execute([$submissionId]);
            if (!$check->fetchColumn()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Submission not found']);
                exit;
            }
        }

        kop_sync_news_facility_links($pdo, (int)$submissionId, $facilities, $submittedBy ?: null);

        echo json_encode([
            'success' => true,
            'message' => 'Submission updated successfully',
            'id' => (int)$submissionId
        ]);
    } else {
        // Block duplicate article submissions by URL (the strongest news
        // identity key). Re-submitting something previously rejected/deleted is
        // still allowed.
        $dupUrls = kop_collect_urls($articleUrl);
        if (!empty($dupUrls)) {
            kop_block_if_duplicate(kop_check_url_duplicates($pdo, 'news', $dupUrls));
        }

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
        
        $newId = (int)$pdo->lastInsertId();

        kop_sync_news_facility_links($pdo, $newId, $facilities, $submittedBy ?: null);

        echo json_encode([
            'success' => true,
            'message' => 'Submission saved successfully',
            'id' => $newId
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
