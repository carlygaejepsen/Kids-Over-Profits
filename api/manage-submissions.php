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
require_once __DIR__ . '/sync-wiki-facilities.php';
require_once __DIR__ . '/lib-suggested-edits.php';

// --- Admin authentication ---
// This endpoint reads and mutates submissions (approve/reject/publish/delete),
// so it is admin-only. Ensure WordPress is loaded, then require the capability.
if (!function_exists('current_user_can')) {
    $kop_wp = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $kop_wp = dirname($kop_wp);
        if (file_exists($kop_wp . '/wp-load.php')) {
            require_once $kop_wp . '/wp-load.php';
            break;
        }
    }
}
if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}

/**
 * Per-type submission schema.
 *
 * wiki/news/data each live in their own staging table and track review state in
 * a `status` column. Legislation and lawsuits, by contrast, live in their own
 * single content table and track lifecycle in `publication_status` — the
 * canonical "unreviewed" value there is `pending` (not `submitted`), and those
 * tables have no reviewed_by/reviewed_at columns. This map lets the queue treat
 * all five types uniformly. Returns null for an unknown type.
 */
function kop_submission_schema($type) {
    switch ($type) {
        case 'wiki':
            return ['table' => 'wiki_submissions', 'status_col' => 'status', 'pending' => 'submitted', 'title_col' => 'program_name', 'reviewer_meta' => true,  'record' => false];
        case 'news':
            return ['table' => 'news_submissions', 'status_col' => 'status', 'pending' => 'submitted', 'title_col' => 'article_title', 'reviewer_meta' => true,  'record' => false];
        case 'data':
            return ['table' => 'suggested_edits',  'status_col' => 'status', 'pending' => 'pending',   'title_col' => 'master_id',    'reviewer_meta' => false, 'record' => false];
        case 'legislation':
            return ['table' => 'legislation',       'status_col' => 'publication_status', 'pending' => 'pending', 'title_col' => 'bill_title', 'reviewer_meta' => false, 'record' => true];
        case 'lawsuit':
            return ['table' => 'lawsuits',          'status_col' => 'publication_status', 'pending' => 'pending', 'title_col' => 'case_name',  'reviewer_meta' => false, 'record' => true];
        default:
            return null;
    }
}

/**
 * Decode any JSON-array/object text columns so the admin "form data" view shows
 * structured values instead of raw JSON strings.
 */
function kop_decode_record_json(array $row): array {
    $out = [];
    foreach ($row as $k => $v) {
        if (is_string($v) && $v !== '' && ($v[0] === '[' || $v[0] === '{')) {
            $decoded = json_decode($v, true);
            $out[$k] = ($decoded !== null) ? $decoded : $v;
        } else {
            $out[$k] = $v;
        }
    }
    return $out;
}

/**
 * Map a legislation/lawsuit content row into the generic submission shape the
 * admin queue JS expects (program_name / city_state / status / submitted_by /
 * submission_notes / json_data).
 */
function kop_map_record_submission(array $row, array $schema): array {
    $titleCol = $schema['title_col'];
    $mapped = $row;
    $mapped['program_name']     = $row[$titleCol] ?? '';
    $mapped['city_state']       = $row['jurisdiction'] ?? '';
    $mapped['status']           = $row['publication_status'] ?? '';
    $mapped['submitted_by']     = (isset($row['submitted_by']) && $row['submitted_by'] !== '') ? $row['submitted_by'] : 'Anonymous';
    $mapped['submission_notes'] = $row['reviewer_notes'] ?? '';
    $mapped['json_data']        = kop_decode_record_json($row);
    return $mapped;
}

/**
 * Per-type whitelist of fields an admin may edit from the submission viewer's
 * structured editor. Returns null for types with no structured editor (wiki
 * uses its own markdown editor).
 *
 *   cols       - columns that may be written directly
 *   json_array - subset of cols stored as a JSON array (normalized on save)
 *   enums      - col => [allowed values]; values outside the list are ignored
 *
 * For 'data' the edit surface is the raw edited_json_data blob (plus reason).
 */
function kop_editable_fields($type) {
    switch ($type) {
        case 'legislation':
            return [
                'cols' => ['bill_number','bill_title','jurisdiction','chamber','session_year','bill_type','sponsors','status','introduced_date','last_action_date','last_action_text','subject_tags','summary','full_text_url','official_url','position','facilities_affected','tags','reviewer_notes'],
                'json_array' => ['sponsors','subject_tags','facilities_affected','tags'],
                'enums' => [
                    'chamber'  => ['house','senate','assembly','joint','federal_house','federal_senate','other','unknown'],
                    'status'   => ['introduced','in_committee','passed_house','passed_senate','signed','vetoed','dead','enacted','unknown'],
                    'position' => ['support','oppose','neutral','watch','unknown'],
                ],
            ];
        case 'lawsuit':
            return [
                'cols' => ['case_name','case_number','court','jurisdiction','filing_date','status','plaintiffs','defendants','facilities_mentioned','staff_mentioned','organizations_mentioned','claims','outcome','settlement_amount','summary','source_urls','document_urls','tags','reviewer_notes'],
                'json_array' => ['plaintiffs','defendants','facilities_mentioned','staff_mentioned','organizations_mentioned','claims','source_urls','document_urls','tags'],
                'enums' => [
                    'status' => ['filed','in_progress','settled','dismissed','ruling','appeal','closed','unknown'],
                ],
            ];
        case 'news':
            return [
                'cols' => ['article_title','alternate_title','author','publication_name','publication_date','article_url','article_type','facilities_mentioned','staff_mentioned','survivors_mentioned','content_warnings','summary','reviewer_notes'],
                'json_array' => ['facilities_mentioned','staff_mentioned','survivors_mentioned','content_warnings'],
                'enums' => [
                    'article_type' => ['lawsuit','event','expose','arrest','closure','corporate','general'],
                ],
            ];
        case 'data':
            return ['cols' => ['edited_json_data','reason'], 'json_array' => [], 'enums' => []];
        default:
            return null; // wiki has its own editor
    }
}

/** Trim/dedupe a list that may arrive as an array or newline-separated text. */
function kop_normalize_list($value): array {
    if (is_array($value)) {
        $items = array_map(static function ($v) { return is_string($v) ? trim($v) : $v; }, $value);
    } elseif (is_string($value) && trim($value) !== '') {
        $items = array_map('trim', preg_split('/[\r\n]+/', $value));
    } else {
        return [];
    }
    return array_values(array_filter($items, static function ($v) { return $v !== '' && $v !== null; }));
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $type = $_GET['type'] ?? 'wiki';
    $schema = kop_submission_schema($type);
    if (!$schema) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid submission type. Use "wiki", "news", "data", "legislation", or "lawsuit"']);
        exit;
    }

    $table = $schema['table'];
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

        if ($schema['record']) {
            echo json_encode(['success' => true, 'data' => kop_map_record_submission($submission, $schema)]);
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
        // Tables vary in how they name the "unreviewed" state: suggested_edits
        // and the legislation/lawsuits content tables use 'pending'; wiki/news
        // use 'submitted'. Translate the admin UI's canonical "Pending Review"
        // value, and target each type's own status column.
        if ($status === 'submitted' && $schema['pending'] !== 'submitted') {
            $status = $schema['pending'];
        }
        $where[] = $schema['status_col'] . " = :status";
        $params[':status'] = $status;
    }

    if ($search) {
        if ($type === 'data') {
            // suggested_edits stores the facility identifier in master_id (a
            // sanitized program-name string, not a numeric FK)
            $where[] = "(master_id LIKE :search)";
            $params[':search'] = "%$search%";
        } elseif ($type === 'legislation') {
            $where[] = "(bill_title LIKE :search1 OR bill_number LIKE :search2 OR jurisdiction LIKE :search3)";
            $params[':search1'] = "%$search%";
            $params[':search2'] = "%$search%";
            $params[':search3'] = "%$search%";
        } elseif ($type === 'lawsuit') {
            $where[] = "(case_name LIKE :search1 OR case_number LIKE :search2 OR jurisdiction LIKE :search3)";
            $params[':search1'] = "%$search%";
            $params[':search2'] = "%$search%";
            $params[':search3'] = "%$search%";
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

    if ($type === 'legislation' || $type === 'lawsuit') {
        // Single content table — pull the whole row and map it to the generic
        // submission shape in PHP (see kop_map_record_submission below).
        $sql = "SELECT * FROM $table $whereClause
                ORDER BY created_at DESC
                LIMIT {$limit} OFFSET {$offset}";
    } elseif ($type === 'data') {
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
        // Select the real news columns (the JS card reads article_title,
        // publication_name, author, article_type, and the duplicate map needs
        // article_url) plus the generic aliases shared with wiki/data rows.
        $sql = "SELECT id, article_title, publication_name, author, article_type, article_url,
                       article_title as program_name, publication_name as organization,
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
        if ($schema['record']) {
            $row = kop_map_record_submission($row, $schema);
            continue;
        }
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
    $type = $data['type'] ?? ''; // wiki | news | data | legislation | lawsuit
    $ids = $data['ids'] ?? (isset($data['id']) && $data['id'] ? [$data['id']] : []);

    // Validate type
    $schema = kop_submission_schema($type);
    if (!$schema) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid submission type. Use "wiki", "news", "data", "legislation", or "lawsuit"']);
        exit;
    }

    $table = $schema['table'];
    
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
            // Legislation and lawsuits go live the moment they're approved —
            // there's no separate publish step in those workflows, so approving
            // publishes (and stamps published_at via the record branch below).
            if ($action === 'approve' && in_array($type, ['legislation', 'lawsuit'], true)) {
                $status = 'published';
            }
            $reviewerNotes = $data['reviewerNotes'] ?? $data['reviewer_notes'] ?? '';
            $reviewedBy = $data['reviewedBy'] ?? $data['reviewed_by'] ?? '';

            if ($type === 'data' && $action !== 'reject') {
                // Approving a suggested edit is more than a status flip: the
                // edited JSON must be merged into the master tables. The shared
                // library handles the merge + status update per submission.
                // ('publish' is treated as approve — suggested_edits has no
                // 'published' status.)
                $wp_prefix = isset($table_prefix) && is_string($table_prefix) ? $table_prefix : '';
                $applied = 0;
                $errors = [];
                foreach ($ids as $sid) {
                    $result = kop_apply_suggested_edit($pdo, (int) $sid, $wp_prefix);
                    if ($result['success']) {
                        $applied++;
                    } else {
                        $errors[] = 'ID ' . (int) $sid . ': ' . $result['error'];
                    }
                }

                $response = [
                    'success' => empty($errors),
                    'message' => "$applied submission(s) approved and applied to master records",
                    'affected' => $applied
                ];
                if (!empty($errors)) {
                    $response['error'] = implode('; ', $errors);
                    $response['errors'] = $errors;
                }
                echo json_encode($response);
                break;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            if ($schema['record']) {
                // legislation / lawsuits: lifecycle lives in publication_status.
                // These tables have no reviewed_by/reviewed_at columns, so append
                // any reviewer note to the existing notes log rather than relying
                // on the wiki/news column set, and stamp published_at on publish.
                $statusCol = $schema['status_col'];
                $set = "$statusCol = ?";
                $upParams = [$status];
                if ($reviewerNotes !== '') {
                    $set .= ", reviewer_notes = TRIM(LEADING '\n' FROM CONCAT(COALESCE(reviewer_notes, ''), '\n[review] ', ?))";
                    $upParams[] = $reviewerNotes;
                }
                if ($status === 'published') {
                    $set .= ", published_at = NOW()";
                }
                $sql = "UPDATE $table SET $set WHERE id IN ($placeholders)";
                $params = array_merge($upParams, $ids);
            } elseif ($type === 'data') {
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
                        $fetchStmt = $pdo->prepare("SELECT program_name, city_state, organization, program_type, years_active, json_data, generated_markdown, facility_unique_name FROM wiki_submissions WHERE id = ?");
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

                    // Wiki → Facilities merge: fill blank fields in facilities_master
                    // from the newly-approved wiki entry (no overwrite).
                    try {
                        $wikiRow = $pdo->prepare(
                            "SELECT * FROM wiki_master WHERE slug = ? LIMIT 1"
                        );
                        // Use the same slug the upsert above keyed on: prefer
                        // the submission's own slug from json_data, fall back
                        // to the name-derived one. Recomputing from the name
                        // alone missed entries saved under a custom slug.
                        $mergeJson = isset($sub['json_data']) ? json_decode($sub['json_data'], true) : null;
                        $slug = (is_array($mergeJson) && !empty($mergeJson['slug']))
                            ? $mergeJson['slug']
                            : strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $sub['program_name'] ?? '')));
                        $wikiRow->execute([$slug]);
                        $wikiEntry = $wikiRow->fetch(PDO::FETCH_ASSOC);
                        if ($wikiEntry) {
                            // Carry the submission's facility link into the merge so it
                            // follows the explicit link rather than name-matching.
                            if (empty($wikiEntry['facility_unique_name']) && !empty($sub['facility_unique_name'])) {
                                $wikiEntry['facility_unique_name'] = $sub['facility_unique_name'];
                            }
                            $mergeResult = kop_merge_wiki_into_facility($pdo, $wikiEntry);
                            if ($mergeResult['merged']) {
                                error_log("Wiki→Facility merge for '{$sub['program_name']}': {$mergeResult['reason']}");
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Wiki→Facility merge failed for ID $id: " . $e->getMessage());
                        // Non-fatal – do not interrupt the approval response
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
            // suggested_edits.status is a strict enum — statuses valid for
            // other tables (draft/published) would error or truncate there.
            $validStatuses = ($type === 'data')
                ? ['submitted', 'pending', 'approved', 'rejected']
                : ['draft', 'submitted', 'pending', 'approved', 'published', 'rejected'];

            if (!in_array($newStatus, $validStatuses)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid status']);
                exit;
            }

            // Map the UI's canonical "submitted" to whatever the target table
            // calls its unreviewed state, and write to that table's status column.
            if ($newStatus === 'submitted' && $schema['pending'] !== 'submitted') {
                $newStatus = $schema['pending'];
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE $table SET {$schema['status_col']} = ? WHERE id IN ($placeholders)";
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
            // legislation / lawsuits: single content table keyed on publication_status.
            if ($schema['record']) {
                $byStatus = $pdo->query(
                    "SELECT {$schema['status_col']} AS status, COUNT(*) AS count
                     FROM {$schema['table']} GROUP BY {$schema['status_col']}"
                )->fetchAll(PDO::FETCH_KEY_PAIR);
                echo json_encode([
                    'success' => true,
                    'stats' => ['by_status' => $byStatus, 'total' => array_sum($byStatus)],
                ]);
                break;
            }

            // data: suggested_edits keyed on status. Without this branch the
            // JS falls through to the wiki counts below.
            if ($type === 'data') {
                $byStatus = $pdo->query(
                    "SELECT status, COUNT(*) as count
                     FROM {$schema['table']} GROUP BY status"
                )->fetchAll(PDO::FETCH_KEY_PAIR);
                echo json_encode([
                    'success' => true,
                    'stats' => ['by_status' => $byStatus, 'total' => array_sum($byStatus)],
                ]);
                break;
            }

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
                // rowCount() is 0 both when the row doesn't exist AND when the
                // new markdown is identical to the stored value — only the
                // former is an error.
                $check = $pdo->prepare("SELECT id FROM wiki_submissions WHERE id = ? LIMIT 1");
                $check->execute([$id]);
                if ($check->fetchColumn()) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Markdown unchanged'
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'error' => 'No submission found with that ID'
                    ]);
                }
            }
            break;

        case 'update_fields':
            // Structured-editor save: write whitelisted fields for a single
            // submission. Lifecycle (publication_status / status) is NOT editable
            // here — that stays with approve/reject/publish.
            $id = $data['id'] ?? ($ids[0] ?? null);
            $fields = $data['fields'] ?? null;
            if (!$id || !is_array($fields)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Submission id and fields are required']);
                exit;
            }

            $editable = kop_editable_fields($type);
            if (!$editable) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Structured editing is not supported for this type']);
                exit;
            }

            $set = [];
            $params = [];
            foreach ($fields as $col => $val) {
                if (!in_array($col, $editable['cols'], true)) {
                    continue; // not editable
                }
                if ($col === 'edited_json_data') {
                    // data submissions: accept an object/array or JSON string,
                    // re-encode so we store canonical JSON (and reject garbage).
                    if (is_string($val)) {
                        $decoded = json_decode($val, true);
                        if ($decoded === null && trim($val) !== 'null') {
                            http_response_code(400);
                            echo json_encode(['success' => false, 'error' => 'edited_json_data is not valid JSON']);
                            exit;
                        }
                        $val = json_encode($decoded);
                    } else {
                        $val = json_encode($val);
                    }
                } elseif (in_array($col, $editable['json_array'], true)) {
                    $val = json_encode(kop_normalize_list($val), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } elseif (isset($editable['enums'][$col])) {
                    if (!in_array($val, $editable['enums'][$col], true)) {
                        continue; // ignore invalid enum value
                    }
                } elseif (preg_match('/_date$/', $col) && (is_null($val) || $val === '')) {
                    $val = null; // empty DATE -> NULL, not '0000-00-00'
                } elseif (is_array($val)) {
                    $val = json_encode($val);
                }
                $set[] = "`$col` = ?";
                $params[] = $val;
            }

            if (!$set) {
                echo json_encode(['success' => false, 'error' => 'No editable fields provided']);
                break;
            }

            $params[] = (int)$id;
            $sql = "UPDATE $table SET " . implode(', ', $set) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            echo json_encode([
                'success' => true,
                'message' => 'Submission updated',
                'affected' => $stmt->rowCount()
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action',
                'valid_actions' => ['approve', 'reject', 'publish', 'delete', 'update_status', 'update_fields', 'update_markdown', 'stats']
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
