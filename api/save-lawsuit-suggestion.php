<?php
/**
 * Public submission endpoint for lawsuits.
 *
 * Anyone may POST a court case here; it is stored in the `lawsuits` table with
 * publication_status = 'pending' and surfaces in the admin Submissions Review
 * queue (type "lawsuit"), exactly like wiki entries and data-form suggestions.
 * Nothing submitted here is public until an admin approves or publishes it —
 * the public tracker only shows approved/published rows.
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
function law_sugg_normalize_array($value): array {
    if (is_array($value)) {
        $items = array_map(static function ($v) { return is_string($v) ? trim($v) : $v; }, $value);
    } elseif (is_string($value) && trim($value) !== '') {
        // Newlines only — one-per-line fields; names contain commas ("Doe, Jane").
        $items = array_map('trim', preg_split('/[\r\n]+/', $value));
    } else {
        return [];
    }
    return array_values(array_filter($items, static function ($v) { return $v !== '' && $v !== null; }));
}

function law_sugg_normalize_date($input): ?string {
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

    $caseName = trim((string)($input['case_name'] ?? ''));
    if ($caseName === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'A case name or short description is required.']);
        exit;
    }

    $validStatuses = ['filed','in_progress','settled','dismissed','ruling','appeal','closed','unknown'];
    $status = in_array($input['status'] ?? '', $validStatuses, true) ? $input['status'] : 'unknown';

    $jsonEncode = static function ($value) {
        return json_encode(law_sugg_normalize_array($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };

    $submitter = trim((string)($input['submitted_by'] ?? $input['submitter'] ?? ''));
    $submittedBy = $submitter !== '' ? substr($submitter, 0, 255) : 'Public submission';

    $note = trim((string)($input['notes'] ?? $input['submission_notes'] ?? ''));
    $reviewerNotes = $note !== '' ? '[submitter] ' . $note : '';

    // Block duplicate cases by their source/document URLs, matched exactly and
    // PER FIELD (source_urls only against source_urls, document_urls only against
    // document_urls) so unrelated cases sharing a court/docket page don't collide.
    // Rejected cases also block resubmission.
    kop_block_if_duplicate(kop_check_url_duplicates($pdo, 'lawsuit', [
        'source_urls'   => $input['source_urls'] ?? [],
        'document_urls' => $input['document_urls'] ?? [],
    ]));

    $fields = [
        'case_name'              => $caseName,
        'case_number'            => trim((string)($input['case_number'] ?? '')),
        'court'                  => trim((string)($input['court'] ?? '')),
        'jurisdiction'           => trim((string)($input['jurisdiction'] ?? '')),
        'filing_date'            => law_sugg_normalize_date($input['filing_date'] ?? null),
        'status'                 => $status,
        'plaintiffs'             => $jsonEncode($input['plaintiffs'] ?? []),
        'defendants'             => $jsonEncode($input['defendants'] ?? []),
        'facilities_mentioned'   => $jsonEncode($input['facilities_mentioned'] ?? []),
        'staff_mentioned'        => $jsonEncode($input['staff_mentioned'] ?? []),
        'organizations_mentioned'=> $jsonEncode($input['organizations_mentioned'] ?? []),
        'claims'                 => $jsonEncode($input['claims'] ?? []),
        'outcome'                => trim((string)($input['outcome'] ?? '')),
        'settlement_amount'      => trim((string)($input['settlement_amount'] ?? '')),
        'summary'                => trim((string)($input['summary'] ?? '')),
        'source_urls'            => $jsonEncode($input['source_urls'] ?? []),
        'document_urls'          => $jsonEncode($input['document_urls'] ?? []),
        'tags'                   => $jsonEncode($input['tags'] ?? []),
        'publication_status'     => 'pending',
        'submitted_by'           => $submittedBy,
        'reviewer_notes'         => $reviewerNotes,
    ];

    $cols = array_keys($fields);
    $placeholders = array_fill(0, count($cols), '?');
    $sql = "INSERT INTO lawsuits (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($fields));

    echo json_encode([
        'success' => true,
        'id' => (int)$pdo->lastInsertId(),
        'message' => 'Thank you. Your lawsuit submission has been received and will be reviewed before it appears on the tracker.',
    ]);
} catch (PDOException $e) {
    error_log('save-lawsuit-suggestion.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'A database error occurred. Please try again later.']);
}
