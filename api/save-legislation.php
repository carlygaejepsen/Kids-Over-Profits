<?php
/**
 * Admin-only save/list endpoint for legislation.
 *
 * GET  ?id=N         -> single record
 * GET  ?jurisdiction=Utah&publication_status=published[&limit=...&offset=...]
 * POST JSON body     -> create or update (when id is present)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

if (!defined('ABSPATH')) {
    $current = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $current = dirname($current);
        if (file_exists($current . '/wp-load.php')) {
            require_once $current . '/wp-load.php';
            break;
        }
    }
}

$is_admin = function_exists('current_user_can') && current_user_can('edit_posts');

function legislation_decode_json_fields(array $row): array {
    $jsonFields = ['sponsors', 'subject_tags', 'facilities_affected', 'tags'];
    foreach ($jsonFields as $field) {
        if (isset($row[$field]) && is_string($row[$field])) {
            $decoded = json_decode($row[$field], true);
            $row[$field] = is_array($decoded) ? $decoded : [];
        }
    }
    return $row;
}

function legislation_normalize_array($value): array {
    if (is_array($value)) {
        return array_values(array_filter(array_map(static function ($v) {
            return is_string($v) ? trim($v) : $v;
        }, $value), static function ($v) {
            return $v !== '' && $v !== null;
        }));
    }
    if (is_string($value) && trim($value) !== '') {
        // Newlines only — these are one-per-line fields, and names legitimately
        // contain commas ("Doe, Jane" must not become two entries).
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $value))));
    }
    return [];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if ($id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM legislation WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Not found']);
                exit;
            }
            if (!$is_admin && $row['publication_status'] !== 'published') {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Not found']);
                exit;
            }
            echo json_encode(['success' => true, 'data' => legislation_decode_json_fields($row)]);
            exit;
        }

        $jurisdiction = isset($_GET['jurisdiction']) ? trim($_GET['jurisdiction']) : '';
        $statusFilter = isset($_GET['publication_status']) ? trim($_GET['publication_status']) : '';
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $where = [];
        $params = [];

        if ($jurisdiction !== '') {
            $where[] = 'jurisdiction = ?';
            $params[] = $jurisdiction;
        }

        if ($statusFilter !== '') {
            if (!$is_admin && $statusFilter !== 'published') {
                $statusFilter = 'published';
            }
            $where[] = 'publication_status = ?';
            $params[] = $statusFilter;
        } elseif (!$is_admin) {
            $where[] = 'publication_status = ?';
            $params[] = 'published';
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT * FROM legislation $whereClause ORDER BY introduced_date DESC, created_at DESC LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = array_map('legislation_decode_json_fields', $stmt->fetchAll());

        echo json_encode(['success' => true, 'data' => $rows, 'count' => count($rows)]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    if (!$is_admin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Contributor privileges or higher required']);
        exit;
    }

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    $billTitle = trim((string)($data['bill_title'] ?? ''));
    if ($billTitle === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'bill_title is required']);
        exit;
    }

    $validChambers = ['house','senate','assembly','joint','federal_house','federal_senate','other','unknown'];
    $chamber = in_array($data['chamber'] ?? '', $validChambers, true) ? $data['chamber'] : 'unknown';

    $validStatuses = ['introduced','in_committee','passed_house','passed_senate','signed','vetoed','dead','enacted','unknown'];
    $status = in_array($data['status'] ?? '', $validStatuses, true) ? $data['status'] : 'unknown';

    $validPositions = ['support','oppose','neutral','watch','unknown'];
    $position = in_array($data['position'] ?? '', $validPositions, true) ? $data['position'] : 'unknown';

    $validPubStatuses = ['draft','pending','approved','published','rejected'];
    $pubStatus = in_array($data['publication_status'] ?? '', $validPubStatuses, true)
        ? $data['publication_status']
        : 'draft';

    $normalizeDate = static function ($input) {
        if (!$input) return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) return $input;
        $ts = strtotime((string)$input);
        return $ts ? date('Y-m-d', $ts) : null;
    };

    $jsonEncode = static function ($value) {
        return json_encode(legislation_normalize_array($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };

    $fields = [
        'bill_number'         => trim((string)($data['bill_number'] ?? '')),
        'bill_title'          => $billTitle,
        'jurisdiction'        => trim((string)($data['jurisdiction'] ?? '')),
        'chamber'             => $chamber,
        'session_year'        => trim((string)($data['session_year'] ?? '')),
        'bill_type'           => trim((string)($data['bill_type'] ?? '')),
        'sponsors'            => $jsonEncode($data['sponsors'] ?? []),
        'status'              => $status,
        'introduced_date'     => $normalizeDate($data['introduced_date'] ?? null),
        'last_action_date'    => $normalizeDate($data['last_action_date'] ?? null),
        'last_action_text'    => trim((string)($data['last_action_text'] ?? '')),
        'subject_tags'        => $jsonEncode($data['subject_tags'] ?? []),
        'summary'             => trim((string)($data['summary'] ?? '')),
        'full_text_url'       => trim((string)($data['full_text_url'] ?? '')),
        'official_url'        => trim((string)($data['official_url'] ?? '')),
        'position'            => $position,
        'facilities_affected' => $jsonEncode($data['facilities_affected'] ?? []),
        'tags'                => $jsonEncode($data['tags'] ?? []),
        'filebird_folder_id'  => isset($data['filebird_folder_id']) && $data['filebird_folder_id'] !== '' ? (int)$data['filebird_folder_id'] : null,
        'publication_status'  => $pubStatus,
        'reviewer_notes'      => trim((string)($data['reviewer_notes'] ?? '')),
    ];

    $submittedBy = trim((string)($data['submitted_by'] ?? ''));

    $id = isset($data['id']) ? (int)$data['id'] : 0;

    if ($id > 0) {
        $set = [];
        $params = [];
        foreach ($fields as $col => $val) {
            $set[] = "`$col` = ?";
            $params[] = $val;
        }
        // Preserve provenance: the admin form never sends submitted_by, so
        // only overwrite it when the caller explicitly provided a value —
        // otherwise publishing a public submission would replace the original
        // submitter with the admin's username.
        if ($submittedBy !== '') {
            $set[] = "`submitted_by` = ?";
            $params[] = $submittedBy;
        }
        // Stamp published_at on first publish only; never re-stamp on later
        // edits and never clear it on a status change.
        if ($pubStatus === 'published') {
            $set[] = "`published_at` = COALESCE(`published_at`, ?)";
            $params[] = date('Y-m-d H:i:s');
        }
        $params[] = $id;
        $sql = "UPDATE legislation SET " . implode(', ', $set) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'id' => $id, 'updated' => true]);
    } else {
        $fields['submitted_by'] = $submittedBy !== '' ? $submittedBy : (wp_get_current_user()->user_login ?? '');
        $fields['published_at'] = ($pubStatus === 'published') ? date('Y-m-d H:i:s') : null;
        $cols = array_keys($fields);
        $placeholders = array_fill(0, count($cols), '?');
        $sql = "INSERT INTO legislation (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($fields));
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'created' => true]);
    }
} catch (PDOException $e) {
    error_log("Legislation save error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error', 'details' => $e->getMessage()]);
}
