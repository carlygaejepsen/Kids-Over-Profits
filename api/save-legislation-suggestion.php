<?php
/**
 * Public submission endpoint for legislation.
 *
 * Anyone may POST a bill here; it is stored in the `legislation` table with
 * publication_status = 'pending' and surfaces in the admin Submissions Review
 * queue (type "legislation"), exactly like wiki entries and data-form
 * suggestions. Nothing submitted here is ever public until an admin approves
 * or publishes it — the public tracker only shows approved/published rows.
 *
 * This endpoint can ONLY create pending rows: the public can never set
 * publication_status, edit an existing record, or publish.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/url-dedupe.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

/** Trim/dedupe a list field that may arrive as an array or newline/comma text. */
function leg_sugg_normalize_array($value): array {
    if (is_array($value)) {
        $items = array_map(static function ($v) { return is_string($v) ? trim($v) : $v; }, $value);
    } elseif (is_string($value) && trim($value) !== '') {
        $items = array_map('trim', preg_split('/[\r\n,]+/', $value));
    } else {
        return [];
    }
    return array_values(array_filter($items, static function ($v) { return $v !== '' && $v !== null; }));
}

function leg_sugg_normalize_date($input): ?string {
    if (!$input) return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) return $input;
    $ts = strtotime((string)$input);
    return $ts ? date('Y-m-d', $ts) : null;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
        exit;
    }

    // Honeypot: bots fill hidden fields. Pretend success without storing.
    if (!empty($input['website_hp'])) {
        echo json_encode(['success' => true, 'message' => 'Thank you for your submission.']);
        exit;
    }

    $billTitle = trim((string)($input['bill_title'] ?? ''));
    if ($billTitle === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'A bill title or short description is required.']);
        exit;
    }

    $validChambers = ['house','senate','assembly','joint','federal_house','federal_senate','other','unknown'];
    $chamber = in_array($input['chamber'] ?? '', $validChambers, true) ? $input['chamber'] : 'unknown';

    $validStatuses = ['introduced','in_committee','passed_house','passed_senate','signed','vetoed','dead','enacted','unknown'];
    $status = in_array($input['status'] ?? '', $validStatuses, true) ? $input['status'] : 'unknown';

    $jsonEncode = static function ($value) {
        return json_encode(leg_sugg_normalize_array($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };

    // Who submitted it, plus any free-text note, recorded for the reviewer.
    $submitter = trim((string)($input['submitted_by'] ?? $input['submitter'] ?? ''));
    $submittedBy = $submitter !== '' ? substr($submitter, 0, 255) : 'Public submission';

    $note = trim((string)($input['notes'] ?? $input['submission_notes'] ?? ''));
    $reviewerNotes = $note !== '' ? '[submitter] ' . $note : '';

    // Block duplicate bills by their linked URL (bill text / official tracker).
    // Re-submitting something previously rejected is still allowed.
    $dupUrls = kop_collect_urls($input['full_text_url'] ?? '', $input['official_url'] ?? '');
    if (!empty($dupUrls)) {
        kop_block_if_duplicate(kop_check_url_duplicates($pdo, 'legislation', $dupUrls));
    }

    $fields = [
        'bill_number'        => trim((string)($input['bill_number'] ?? '')),
        'bill_title'         => $billTitle,
        'jurisdiction'       => trim((string)($input['jurisdiction'] ?? '')),
        'chamber'            => $chamber,
        'session_year'       => trim((string)($input['session_year'] ?? '')),
        'bill_type'          => trim((string)($input['bill_type'] ?? '')),
        'sponsors'           => $jsonEncode($input['sponsors'] ?? []),
        'status'             => $status,
        'introduced_date'    => leg_sugg_normalize_date($input['introduced_date'] ?? null),
        'last_action_date'   => leg_sugg_normalize_date($input['last_action_date'] ?? null),
        'last_action_text'   => trim((string)($input['last_action_text'] ?? '')),
        'subject_tags'       => $jsonEncode($input['subject_tags'] ?? []),
        'summary'            => trim((string)($input['summary'] ?? '')),
        'full_text_url'      => trim((string)($input['full_text_url'] ?? '')),
        'official_url'       => trim((string)($input['official_url'] ?? '')),
        'position'           => 'unknown',
        'facilities_affected'=> $jsonEncode($input['facilities_affected'] ?? []),
        'tags'               => $jsonEncode($input['tags'] ?? []),
        'publication_status' => 'pending',
        'submitted_by'       => $submittedBy,
        'reviewer_notes'     => $reviewerNotes,
    ];

    $cols = array_keys($fields);
    $placeholders = array_fill(0, count($cols), '?');
    $sql = "INSERT INTO legislation (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($fields));

    echo json_encode([
        'success' => true,
        'id' => (int)$pdo->lastInsertId(),
        'message' => 'Thank you. Your legislation submission has been received and will be reviewed before it appears on the tracker.',
    ]);
} catch (PDOException $e) {
    error_log('save-legislation-suggestion.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'A database error occurred. Please try again later.']);
}
