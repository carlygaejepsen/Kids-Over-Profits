<?php
/**
 * API - Extract Lawsuit Details from Uploaded Complaint
 *
 * Three-action protocol so each HTTP request stays short-lived (no long
 * sleeping on shared hosting). The browser orchestrates the pacing.
 *
 * POST action=upload   multipart  complaint (file), filebird_folder_id (int)
 *   → {success, job_id, total_chunks, doc_chars}
 *   Extracts text, splits into chunks, stores in a 30-min WP transient.
 *   Does NOT register the attachment yet (avoids duplicates on retry).
 *
 * POST action=chunk    JSON       {job_id, chunk_index}
 *   → {success, chunk_index, total_chunks}
 *   Calls the AI (Groq and Gemini in turn) for one chunk and stores the result in its own transient.
 *
 * POST action=finalize JSON       {job_id}
 *   → {success, data: {...form fields...}, attachment: {id, url}}
 *   Merges all chunk results, registers the attachment, cleans up transients.
 *
 * Requires edit_posts capability.
 */

header('Content-Type: application/json');
set_time_limit(120);  // each individual action is fast; 120s is generous

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
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// Shared helpers: kop_resolve_secret, text extraction, chunking, AI call,
// merge/normalize, extraction prompt.
require_once __DIR__ . '/lawsuit-extraction-lib.php';

// --- auth --------------------------------------------------------------------
if (!function_exists('current_user_can') || !current_user_can('edit_posts')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin privileges required']);
    exit;
}

// --- route -------------------------------------------------------------------
$action = $_POST['action'] ?? (json_decode(file_get_contents('php://input'), true)['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

match ($action) {
    'upload'   => kop_action_upload(),
    'chunk'    => kop_action_chunk(),
    'finalize' => kop_action_finalize(),
    default    => (function () {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Unknown action. Expected upload, chunk, or finalize."]);
        exit;
    })(),
};
exit;


// =============================================================================
// Action: upload
// =============================================================================
function kop_action_upload(): void {
    if (!kop_ai_alternating_providers()) {
        echo json_encode(['success' => false, 'error' => 'No AI key configured. Add GROQ_API_KEY (https://console.groq.com/keys) or GEMINI_API_KEY (https://aistudio.google.com/app/apikey) to your .env file.']);
        exit;
    }

    if (empty($_FILES['complaint']) || ($_FILES['complaint']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $php_err  = $_FILES['complaint']['error'] ?? UPLOAD_ERR_NO_FILE;
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form-level size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded file to disk.',
        ];
        echo json_encode(['success' => false, 'error' => $messages[$php_err] ?? "Upload failed (code {$php_err})."]);
        exit;
    }

    $folder_id = isset($_POST['filebird_folder_id']) && $_POST['filebird_folder_id'] !== ''
        ? (int)$_POST['filebird_folder_id'] : 0;

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

    $file_path = $upload['file'];
    $file_url  = $upload['url'];
    $file_type = $upload['type'];

    // Extract and sanitize text
    $doc_text = kop_extract_document_text($file_path, $file_type);
    if (trim($doc_text) === '') {
        @unlink($file_path);
        echo json_encode(['success' => false, 'error' => 'Could not extract text from the document. Make sure it is a digital (not scanned) PDF.']);
        exit;
    }

    $chunked = kop_lawsuit_chunk_text($doc_text, 10);
    $chunks  = $chunked['chunks'];
    $len     = $chunked['length'];

    $job_id = wp_generate_uuid4();

    set_transient('kop_lawsuit_job_' . $job_id, [
        'chunks'      => $chunks,
        'file_path'   => $file_path,
        'file_url'    => $file_url,
        'file_type'   => $file_type,
        'folder_id'   => $folder_id,
        'total'       => count($chunks),
        'results'     => [],
    ], 30 * MINUTE_IN_SECONDS);

    echo json_encode([
        'success'      => true,
        'job_id'       => $job_id,
        'total_chunks' => count($chunks),
        'doc_chars'    => $len,
        'wait_ms'      => kop_ai_pace_ms(),
    ]);
}


// =============================================================================
// Action: chunk
// =============================================================================
function kop_action_chunk(): void {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $job_id      = trim((string)($input['job_id'] ?? ''));
    $chunk_index = (int)($input['chunk_index'] ?? -1);

    if ($job_id === '' || $chunk_index < 0) {
        echo json_encode(['success' => false, 'error' => 'Missing job_id or chunk_index.']);
        exit;
    }

    $job = get_transient('kop_lawsuit_job_' . $job_id);
    if (!$job) {
        echo json_encode(['success' => false, 'error' => 'Job not found or expired. Please re-upload the document.']);
        exit;
    }

    $total = $job['total'];
    if ($chunk_index >= $total) {
        echo json_encode(['success' => false, 'error' => "chunk_index {$chunk_index} out of range (total: {$total})."]);
        exit;
    }

    $result = kop_lawsuit_ai_call($job['chunks'][$chunk_index], $chunk_index + 1, $total);

    if (!$result['ok']) {
        echo json_encode(['success' => false, 'error' => $result['error']]);
        exit;
    }

    // Persist this chunk's result back into the job transient
    $job['results'][$chunk_index] = $result['data'];
    set_transient('kop_lawsuit_job_' . $job_id, $job, 30 * MINUTE_IN_SECONDS);

    echo json_encode(['success' => true, 'chunk_index' => $chunk_index, 'total_chunks' => $total]);
}


// =============================================================================
// Action: finalize
// =============================================================================
function kop_action_finalize(): void {
    $input  = json_decode(file_get_contents('php://input'), true) ?: [];
    $job_id = trim((string)($input['job_id'] ?? ''));

    if ($job_id === '') {
        echo json_encode(['success' => false, 'error' => 'Missing job_id.']);
        exit;
    }

    $job = get_transient('kop_lawsuit_job_' . $job_id);
    if (!$job) {
        echo json_encode(['success' => false, 'error' => 'Job not found or expired. Please re-upload the document.']);
        exit;
    }

    delete_transient('kop_lawsuit_job_' . $job_id);

    $file_path = $job['file_path'];
    $file_url  = $job['file_url'];
    $file_type = $job['file_type'];
    $folder_id = $job['folder_id'];
    $results   = array_values(array_filter($job['results']));

    if (empty($results)) {
        @unlink($file_path);
        echo json_encode(['success' => false, 'error' => 'No chunks were successfully extracted. Please try again.']);
        exit;
    }

    $raw = (count($results) === 1) ? $results[0] : kop_merge_chunk_extractions($results);

    // Register attachment now that we know extraction succeeded
    $attachment_id = 0;
    if (file_exists($file_path)) {
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $file_type,
            'post_title'     => preg_replace('/\.[^.]+$/', '', basename($file_path)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ], $file_path);

        if (!is_wp_error($attachment_id) && $attachment_id) {
            wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $file_path));

            if ($folder_id > 0) {
                $fb_key = kop_resolve_secret('FILEBIRD_API_KEY');
                if ($fb_key !== '') {
                    $resp = wp_remote_post(home_url('/wp-json/filebird/public/v1/folder/set-folder'), [
                        'headers' => ['Content-Type' => 'application/json', 'X-Api-Key' => $fb_key],
                        'body'    => json_encode(['folder_id' => $folder_id, 'ids' => [$attachment_id]]),
                        'timeout' => 15,
                    ]);
                    if (is_wp_error($resp)) {
                        error_log('FileBird set-folder failed: ' . $resp->get_error_message());
                    }
                }
            }
        }
    }

    $extracted = kop_normalize_lawsuit_extraction($raw);
    $extracted['document_urls'] = array_values(array_unique(array_merge(
        $extracted['document_urls'] ?? [], [$file_url]
    )));
    $extracted['publication_status'] = 'draft';

    echo json_encode([
        'success'    => true,
        'data'       => $extracted,
        'attachment' => ['id' => $attachment_id, 'url' => $file_url],
    ]);
}
