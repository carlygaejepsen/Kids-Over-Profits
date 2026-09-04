<?php
/**
 * API - Extract Lawsuit Details from an Uploaded Complaint (PUBLIC)
 *
 * Public counterpart of extract-lawsuit-from-document.php, used by the
 * Submit Lawsuit page to autofill the suggestion form. Same three-action
 * protocol (the browser orchestrates the pacing so each HTTP request stays
 * short-lived on shared hosting):
 *
 * POST action=upload   multipart  complaint (file)
 *   → {success, job_id, total_chunks, doc_chars}
 * POST action=chunk    JSON       {job_id, chunk_index}
 *   → {success, chunk_index, total_chunks}
 * POST action=finalize JSON       {job_id}
 *   → {success, data: {...form fields...}, document_url}
 *
 * Differences from the admin endpoint:
 *   - No login required; instead each IP is limited to a few extraction jobs
 *     per hour (Groq quota + upload abuse protection).
 *   - Upload capped at 10 MB and 4 chunks (~48k chars) — enough for the
 *     caption, parties, and claims of a typical complaint.
 *   - The uploaded file is NOT registered in the media library and no
 *     FileBird folder is assigned. Its URL is returned in document_urls so it
 *     rides along with the pending submission for the reviewer.
 */

header('Content-Type: application/json');
set_time_limit(120);

// --- bootstrap ---------------------------------------------------------------
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

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once __DIR__ . '/lawsuit-extraction-lib.php';

const KOP_PUBJOB_PREFIX     = 'kop_lawsuit_pubjob_';
const KOP_PUB_MAX_CHUNKS    = 4;
const KOP_PUB_MAX_BYTES     = 10 * 1024 * 1024;  // 10 MB
const KOP_PUB_JOBS_PER_HOUR = 5;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// --- route -------------------------------------------------------------------
$action = $_POST['action'] ?? (json_decode(file_get_contents('php://input'), true)['action'] ?? '');

match ($action) {
    'upload'   => kop_pub_action_upload(),
    'chunk'    => kop_pub_action_chunk(),
    'finalize' => kop_pub_action_finalize(),
    default    => (function () {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action. Expected upload, chunk, or finalize.']);
        exit;
    })(),
};
exit;


/** Per-IP job throttle: true if this IP may start another extraction job. */
function kop_pub_rate_limit_ok(): bool {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '') return true;
    $key = 'kop_lawsuit_pubrate_' . md5($ip);
    $count = (int)get_transient($key);
    if ($count >= KOP_PUB_JOBS_PER_HOUR) return false;
    set_transient($key, $count + 1, HOUR_IN_SECONDS);
    return true;
}


// =============================================================================
// Action: upload
// =============================================================================
function kop_pub_action_upload(): void {
    $groq_key = kop_resolve_secret('GROQ_API_KEY') ?: kop_resolve_secret('GROK_API_KEY');
    if ($groq_key === '') {
        echo json_encode(['success' => false, 'error' => 'Automatic extraction is not available right now. Please fill in the form manually.']);
        exit;
    }

    if (!kop_pub_rate_limit_ok()) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many extraction requests from your connection. Please wait an hour and try again, or fill in the form manually.']);
        exit;
    }

    if (empty($_FILES['complaint']) || ($_FILES['complaint']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $php_err  = $_FILES['complaint']['error'] ?? UPLOAD_ERR_NO_FILE;
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload size limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form-level size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded file to disk.',
        ];
        echo json_encode(['success' => false, 'error' => $messages[$php_err] ?? "Upload failed (code {$php_err})."]);
        exit;
    }

    if (($_FILES['complaint']['size'] ?? 0) > KOP_PUB_MAX_BYTES) {
        echo json_encode(['success' => false, 'error' => 'File is larger than 10 MB. Please upload a smaller document (the complaint itself, not full exhibits).']);
        exit;
    }

    $upload = wp_handle_upload($_FILES['complaint'], [
        'test_form' => false,
        'mimes'     => [
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt'  => 'text/plain',
        ],
    ]);

    if (isset($upload['error'])) {
        echo json_encode(['success' => false, 'error' => 'Upload rejected: ' . $upload['error']]);
        exit;
    }

    $doc_text = kop_extract_document_text($upload['file'], $upload['type']);

    // Scanned PDFs have no text layer. The browser OCRs them with tesseract.js
    // and re-uploads with the recognized text in client_text; prefer the real
    // text layer when one exists.
    if (trim($doc_text) === '' && isset($_POST['client_text'])) {
        $doc_text = mb_substr((string)$_POST['client_text'], 0, 60000, 'UTF-8');
    }

    if (trim($doc_text) === '') {
        @unlink($upload['file']);
        echo json_encode([
            'success'    => false,
            'error'      => 'Could not extract text from the document.',
            'error_code' => 'no_text',  // tells the browser to try OCR and re-upload
        ]);
        exit;
    }

    $chunked = kop_lawsuit_chunk_text($doc_text, KOP_PUB_MAX_CHUNKS);

    $job_id = wp_generate_uuid4();
    set_transient(KOP_PUBJOB_PREFIX . $job_id, [
        'chunks'    => $chunked['chunks'],
        'file_path' => $upload['file'],
        'file_url'  => $upload['url'],
        'total'     => count($chunked['chunks']),
        'results'   => [],
    ], 30 * MINUTE_IN_SECONDS);

    echo json_encode([
        'success'      => true,
        'job_id'       => $job_id,
        'total_chunks' => count($chunked['chunks']),
        'doc_chars'    => $chunked['length'],
    ]);
}


// =============================================================================
// Action: chunk
// =============================================================================
function kop_pub_action_chunk(): void {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $job_id      = trim((string)($input['job_id'] ?? ''));
    $chunk_index = (int)($input['chunk_index'] ?? -1);

    if ($job_id === '' || $chunk_index < 0) {
        echo json_encode(['success' => false, 'error' => 'Missing job_id or chunk_index.']);
        exit;
    }

    $job = get_transient(KOP_PUBJOB_PREFIX . $job_id);
    if (!$job) {
        echo json_encode(['success' => false, 'error' => 'Job not found or expired. Please re-upload the document.']);
        exit;
    }

    $total = $job['total'];
    if ($chunk_index >= $total) {
        echo json_encode(['success' => false, 'error' => "chunk_index {$chunk_index} out of range (total: {$total})."]);
        exit;
    }

    $groq_key = kop_resolve_secret('GROQ_API_KEY') ?: kop_resolve_secret('GROK_API_KEY');
    $model    = kop_lawsuit_pick_model($total);
    $result   = kop_groq_call($groq_key, $model, $job['chunks'][$chunk_index], $chunk_index + 1, $total);

    if (!$result['ok']) {
        echo json_encode(['success' => false, 'error' => $result['error']]);
        exit;
    }

    $job['results'][$chunk_index] = $result['data'];
    set_transient(KOP_PUBJOB_PREFIX . $job_id, $job, 30 * MINUTE_IN_SECONDS);

    echo json_encode(['success' => true, 'chunk_index' => $chunk_index, 'total_chunks' => $total]);
}


// =============================================================================
// Action: finalize
// =============================================================================
function kop_pub_action_finalize(): void {
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $job_id = trim((string)($input['job_id'] ?? ''));

    if ($job_id === '') {
        echo json_encode(['success' => false, 'error' => 'Missing job_id.']);
        exit;
    }

    $job = get_transient(KOP_PUBJOB_PREFIX . $job_id);
    if (!$job) {
        echo json_encode(['success' => false, 'error' => 'Job not found or expired. Please re-upload the document.']);
        exit;
    }

    delete_transient(KOP_PUBJOB_PREFIX . $job_id);

    $results = array_values(array_filter($job['results']));
    if (empty($results)) {
        @unlink($job['file_path']);
        echo json_encode(['success' => false, 'error' => 'No text could be extracted from the document. Please fill in the form manually.']);
        exit;
    }

    $raw = (count($results) === 1) ? $results[0] : kop_merge_chunk_extractions($results);

    $extracted = kop_normalize_lawsuit_extraction($raw);
    // Keep the uploaded complaint with the submission so the reviewer can read it.
    $extracted['document_urls'] = array_values(array_unique(array_merge(
        $extracted['document_urls'] ?? [], [$job['file_url']]
    )));

    echo json_encode([
        'success'      => true,
        'data'         => $extracted,
        'document_url' => $job['file_url'],
    ]);
}
