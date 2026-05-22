<?php
/**
 * API - Extract Lawsuit Details from Uploaded Complaint
 *
 * Accepts a complaint PDF (or other supported document), saves it to the
 * WordPress media library, optionally moves it into a FileBird folder, then
 * sends it to Groq for structured extraction. The returned JSON matches
 * the lawsuit form schema so the admin UI can populate the form directly.
 *
 * POST multipart/form-data
 *   complaint            (file)   required - the document to extract from
 *   filebird_folder_id   (int)    optional - folder to move the attachment into
 *
 * Requires edit_posts capability (admin/contributor).
 */

header('Content-Type: application/json');

set_time_limit(0);  // chunked extraction can take well over 30s on shared hosting

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

if (!function_exists('kop_resolve_secret')) {
    function kop_resolve_secret($name) {
        $v = getenv($name);
        if ($v !== false && $v !== '') return $v;
        if (!empty($_ENV[$name])) return $_ENV[$name];
        if (!empty($_SERVER[$name])) return $_SERVER[$name];
        if (defined($name)) {
            $c = constant($name);
            if (!empty($c)) return $c;
        }
        return '';
    }
}

// --- auth + method -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!function_exists('current_user_can') || !current_user_can('edit_posts')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin privileges required']);
    exit;
}

if (empty($_FILES['complaint']) || ($_FILES['complaint']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $php_err = $_FILES['complaint']['error'] ?? UPLOAD_ERR_NO_FILE;
    $messages = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload_max_filesize.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form-level size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded. Please choose a complaint PDF.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'Server failed to write the uploaded file to disk.',
    ];
    echo json_encode(['success' => false, 'error' => $messages[$php_err] ?? "Upload failed (code {$php_err})."]);
    exit;
}

$folder_id = isset($_POST['filebird_folder_id']) && $_POST['filebird_folder_id'] !== ''
    ? (int)$_POST['filebird_folder_id']
    : 0;

// --- groq config check (do early so we don't save a file we can't use) ------
$groq_key = kop_resolve_secret('GROQ_API_KEY') ?: kop_resolve_secret('GROK_API_KEY');
if ($groq_key === '') {
    echo json_encode([
        'success' => false,
        'error'   => 'Groq API key is not configured. Add GROQ_API_KEY to your .env file. Free key at https://console.groq.com/keys',
    ]);
    exit;
}

// --- move file to uploads dir (not yet registered as an attachment) ---------
$upload = wp_handle_upload($_FILES['complaint'], [
    'test_form' => false,
    'mimes' => [
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

// --- extract text from document ---------------------------------------------
$doc_text = kop_extract_document_text($file_path, $file_type);
if (trim($doc_text) === '') {
    @unlink($file_path);
    echo json_encode(['success' => false, 'error' => 'Could not extract text from the uploaded document. Make sure it is a digital (not scanned) PDF.']);
    exit;
}

// Sanitize to valid UTF-8 — raw PDF extraction picks up binary garbage from
// font tables that causes json_encode() to silently return false, sending an
// empty body to Groq ("unexpected end of JSON input").
$doc_text = str_replace("\0", '', $doc_text);
$doc_text = iconv('UTF-8', 'UTF-8//IGNORE', $doc_text) ?: $doc_text;
$doc_text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $doc_text);

// --- call Groq ---------------------------------------------------------------
const KOP_GROQ_SINGLE_LIMIT = 20000;
const KOP_GROQ_CHUNK_SIZE   = 60000;
const KOP_GROQ_OVERLAP      = 500;
const KOP_GROQ_MAX_CHUNKS   = 6;

if (strlen($doc_text) <= KOP_GROQ_SINGLE_LIMIT) {
    $result = kop_groq_call($groq_key, 'llama-3.3-70b-versatile', $doc_text);
    if (!$result['ok']) {
        @unlink($file_path);
        echo json_encode(['success' => false, 'error' => $result['error']]);
        exit;
    }
    $raw_extracted = $result['data'];
} else {
    $chunks = [];
    $offset = 0;
    $len    = strlen($doc_text);
    while ($offset < $len && count($chunks) < KOP_GROQ_MAX_CHUNKS) {
        $chunks[] = substr($doc_text, $offset, KOP_GROQ_CHUNK_SIZE);
        $offset  += KOP_GROQ_CHUNK_SIZE - KOP_GROQ_OVERLAP;
    }

    $chunk_results = [];
    $chunk_errors  = [];
    foreach ($chunks as $i => $chunk) {
        if ($i > 0) sleep(2);
        $result = kop_groq_call($groq_key, 'llama-3.1-8b-instant', $chunk, $i + 1, count($chunks));
        if ($result['ok']) {
            $chunk_results[] = $result['data'];
        } else {
            $chunk_errors[] = 'Chunk ' . ($i + 1) . ': ' . $result['error'];
            error_log('Groq chunk ' . ($i + 1) . ' failed: ' . $result['error']);
        }
    }

    if (empty($chunk_results)) {
        @unlink($file_path);
        echo json_encode(['success' => false, 'error' => 'All Groq chunk requests failed. First error: ' . ($chunk_errors[0] ?? 'unknown')]);
        exit;
    }
    $raw_extracted = kop_merge_chunk_extractions($chunk_results);
}

// --- extraction succeeded — now register the file in the media library ------
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

// --- normalize & merge attachment URL into document_urls -------------------
$extracted = kop_normalize_lawsuit_extraction($raw_extracted);
$extracted['document_urls'] = array_values(array_unique(array_merge(
    $extracted['document_urls'] ?? [],
    [$file_url]
)));
$extracted['publication_status'] = 'draft';

echo json_encode([
    'success'    => true,
    'data'       => $extracted,
    'attachment' => ['id' => $attachment_id, 'url' => $file_url],
]);
exit;


/**
 * Make one Groq chat-completions request and return the parsed extraction.
 *
 * @param string $key       Groq API key
 * @param string $model     e.g. 'llama-3.3-70b-versatile' or 'llama-3.1-8b-instant'
 * @param string $text      Document text to extract from
 * @param int    $chunk_num 1-based chunk index (used in the prompt context hint)
 * @param int    $total     Total number of chunks
 * @return array ['ok' => bool, 'data' => array|null, 'error' => string]
 */
function kop_groq_call(string $key, string $model, string $text, int $chunk_num = 1, int $total = 1): array {
    $prompt  = kop_lawsuit_extraction_prompt();
    $context = ($total > 1)
        ? "NOTE: This is chunk {$chunk_num} of {$total} of a longer document. Extract whatever fields you can find in this portion.\n\n"
        : '';

    $body = json_encode([
        'model'    => $model,
        'messages' => [
            ['role' => 'user', 'content' => "{$context}DOCUMENT TEXT:\n{$text}\n\n{$prompt}"],
        ],
        'temperature' => 0.1,
        'max_tokens'  => 4096,
    ]);

    if ($body === false) {
        return ['ok' => false, 'data' => null, 'error' => 'json_encode failed: ' . json_last_error_msg()];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.groq.com/openai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        return ['ok' => false, 'data' => null, 'error' => "Network error: {$curl_err}"];
    }

    $decoded = json_decode($response, true);

    if ($http_code !== 200) {
        $msg = $decoded['error']['message'] ?? "HTTP {$http_code}";
        if ($http_code === 429) $msg = 'Groq rate limit exceeded — please wait a moment and try again.';
        if ($http_code === 401) $msg = 'Groq API key is invalid. Check GROQ_API_KEY in your .env file.';
        return ['ok' => false, 'data' => null, 'error' => "Groq API error: {$msg}"];
    }

    $raw_text = $decoded['choices'][0]['message']['content'] ?? '';
    if ($raw_text === '') {
        return ['ok' => false, 'data' => null, 'error' => 'Groq returned an empty response.'];
    }

    // Strip any markdown fences Llama may have added
    $raw_text = preg_replace('/^```json\s*/i', '', trim($raw_text));
    $raw_text = preg_replace('/```\s*$/', '', $raw_text);

    $parsed = json_decode($raw_text, true);
    if (!is_array($parsed)) {
        return ['ok' => false, 'data' => null, 'error' => 'Could not parse Groq response as JSON. Preview: ' . substr($raw_text, 0, 200)];
    }

    return ['ok' => true, 'data' => $parsed, 'error' => ''];
}

/**
 * Merge per-chunk extraction results into one record.
 *
 * For scalar fields the first non-empty value wins — case metadata is usually
 * on the first pages. For array fields we union all values and deduplicate.
 */
function kop_merge_chunk_extractions(array $chunks): array {
    $string_fields = ['case_name', 'case_number', 'court', 'jurisdiction', 'filing_date', 'outcome', 'settlement_amount', 'summary'];
    $array_fields  = ['plaintiffs', 'defendants', 'facilities_mentioned', 'staff_mentioned', 'organizations_mentioned', 'claims', 'tags', 'source_urls', 'document_urls'];

    $merged = [];

    foreach ($string_fields as $f) {
        foreach ($chunks as $chunk) {
            $v = trim((string)($chunk[$f] ?? ''));
            if ($v !== '') { $merged[$f] = $v; break; }
        }
    }

    foreach ($array_fields as $f) {
        $all = [];
        foreach ($chunks as $chunk) {
            $v = $chunk[$f] ?? [];
            if (is_array($v)) {
                foreach ($v as $item) {
                    if (is_string($item) && trim($item) !== '') $all[] = trim($item);
                }
            }
        }
        $merged[$f] = array_values(array_unique($all));
    }

    return $merged;
}


/**
 * Dispatch to the right text extractor based on MIME type.
 */
function kop_extract_document_text(string $path, string $mime_type): string {
    if ($mime_type === 'text/plain') {
        return (string)@file_get_contents($path);
    }
    if ($mime_type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
        return kop_extract_docx_text($path);
    }
    return kop_extract_pdf_text($path);
}

/**
 * Extract text from a PDF.
 *
 * Tries pdftotext (poppler-utils) via exec first — available on many cPanel
 * hosts. Falls back to a pure-PHP regex pass that reads BT…ET content streams;
 * this works reliably for digitally-created PDFs (which all e-filed court
 * documents are) but not for scanned images.
 */
function kop_extract_pdf_text(string $path): string {
    // pdftotext path: try which, then common locations
    $candidates = ['pdftotext', '/usr/bin/pdftotext', '/usr/local/bin/pdftotext'];
    foreach ($candidates as $bin) {
        $which = trim((string)@shell_exec('which ' . escapeshellarg($bin) . ' 2>/dev/null'));
        $exe   = ($which !== '') ? $which : $bin;
        if (@is_executable($exe)) {
            $out = @shell_exec(escapeshellarg($exe) . ' -enc UTF-8 -nopgbrk ' . escapeshellarg($path) . ' - 2>/dev/null');
            if ($out !== null && strlen(trim($out)) > 50) {
                return $out;
            }
            break;
        }
    }

    // Pure-PHP fallback
    $raw = @file_get_contents($path);
    if (!$raw) return '';

    // Decompress any FlateDecode streams so we can read the content operators
    if (function_exists('gzuncompress')) {
        $raw = preg_replace_callback('/stream\r?\n(.*?)\r?\nendstream/s', static function ($m) {
            $d = @gzuncompress($m[1]);
            return 'stream' . "\n" . ($d !== false ? $d : $m[1]) . "\nendstream";
        }, $raw);
    }

    $text = '';
    // Match every BT…ET text block
    if (preg_match_all('/BT\s*(.*?)\s*ET/s', $raw, $blocks, PREG_SET_ORDER)) {
        foreach ($blocks as $block) {
            $b = $block[1];
            // (string) Tj
            preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)\s*Tj/s', $b, $tj, PREG_SET_ORDER);
            foreach ($tj as $m) {
                $text .= kop_pdf_unescape($m[1]) . ' ';
            }
            // [(string) kern (string)…] TJ
            preg_match_all('/\[([^\]]*)\]\s*TJ/s', $b, $TJ, PREG_SET_ORDER);
            foreach ($TJ as $m) {
                preg_match_all('/\(([^)\\\\]*(?:\\\\.[^)\\\\]*)*)\)/', $m[1], $ss, PREG_SET_ORDER);
                foreach ($ss as $s) {
                    $text .= kop_pdf_unescape($s[1]);
                }
                $text .= ' ';
            }
        }
    }
    return trim(preg_replace('/[ \t]{2,}/', ' ', $text));
}

function kop_pdf_unescape(string $s): string {
    return str_replace(
        ['\\n', '\\r', '\\t', '\\(', '\\)', '\\\\'],
        ["\n",  "\r",  "\t",  '(',   ')',   '\\'],
        $s
    );
}

/**
 * Extract text from a DOCX by unzipping and stripping word/document.xml.
 */
function kop_extract_docx_text(string $path): string {
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!$xml) return '';
    $xml = str_replace(['</w:p>', '<w:br/>', '</w:r>'], ["\n", ' ', ' '], $xml);
    return trim(strip_tags($xml));
}


/**
 * Prompt template for complaint extraction. Mirrors the lawsuit form schema in
 * page-admin-lawsuits.php so the response can be dropped directly into the
 * form fields.
 */
function kop_lawsuit_extraction_prompt() {
    return <<<PROMPT
You are an expert legal-research assistant. Read the attached legal complaint or court filing and extract structured information for a Troubled Teen Industry accountability database.

Apply trauma-sensitive language: factual and neutral, never sensationalist, no graphic injury details, never describe suicide methods.

Return ONLY valid JSON matching this exact schema (omit fields you can't determine):

{
  "case_name": "e.g. Doe v. Provo Canyon School",
  "case_number": "docket number as printed on the filing",
  "court": "full court name as printed, e.g. 'United States District Court for the District of Utah'",
  "jurisdiction": "Federal OR the US state name (e.g. 'Utah'). Federal courts always = Federal.",
  "filing_date": "YYYY-MM-DD",
  "plaintiffs": ["list of named plaintiffs, one per entry"],
  "defendants": ["list of named defendants, one per entry"],
  "facilities_mentioned": ["TTI facilities/programs named in the complaint"],
  "staff_mentioned": ["individual staff, owners, or therapists named"],
  "organizations_mentioned": ["parent companies, referrers, accreditation bodies, etc."],
  "claims": ["one short slug per claim, e.g. 'physical_abuse', 'wrongful_death', 'fraud', 'negligence', 'sexual_abuse', 'civil_rights_violation', 'breach_of_contract'"],
  "summary": "3-5 sentence factual summary of the allegations using neutral language",
  "tags": ["3-6 short keyword tags, e.g. 'wilderness therapy', 'restraint', 'class action'"]
}

Rules:
- Use exactly the field names above.
- Arrays must be JSON arrays of strings, not comma-joined strings.
- Use ISO dates (YYYY-MM-DD). If only a month/year is shown, leave filing_date blank.
- If a value cannot be determined from the document, omit the field or use an empty string/array — do NOT guess.
- Do not include legal citations or paragraph numbers in the summary.
- Return ONLY the JSON object, no prose, no markdown fences.
PROMPT;
}

/**
 * Coerce the LLM's output to the shape the form expects. Mostly defensive:
 * fields the model might return as strings get split into arrays, dates get
 * normalized to YYYY-MM-DD, jurisdiction case is harmonized.
 */
function kop_normalize_lawsuit_extraction(array $raw) {
    $array_fields = [
        'plaintiffs', 'defendants', 'facilities_mentioned', 'staff_mentioned',
        'organizations_mentioned', 'claims', 'tags', 'source_urls', 'document_urls',
    ];
    $string_fields = ['case_name', 'case_number', 'court', 'jurisdiction', 'summary', 'outcome', 'settlement_amount'];

    $out = [];
    foreach ($array_fields as $f) {
        $v = $raw[$f] ?? [];
        if (is_string($v)) {
            $v = array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', $v))));
        } elseif (is_array($v)) {
            $v = array_values(array_filter(array_map(static function($x) {
                return is_string($x) ? trim($x) : '';
            }, $v), static function($x) { return $x !== ''; }));
        } else {
            $v = [];
        }
        $out[$f] = $v;
    }
    foreach ($string_fields as $f) {
        $v = $raw[$f] ?? '';
        $out[$f] = is_string($v) ? trim($v) : '';
    }

    // Normalize filing_date to YYYY-MM-DD.
    $filing = $raw['filing_date'] ?? '';
    if (is_string($filing) && $filing !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filing)) {
            $ts = strtotime($filing);
            $filing = $ts ? date('Y-m-d', $ts) : '';
        }
    } else {
        $filing = '';
    }
    $out['filing_date'] = $filing;

    // Title-case jurisdiction so it matches the dropdown options ("Utah", not "utah").
    if ($out['jurisdiction'] !== '') {
        if (strcasecmp($out['jurisdiction'], 'federal') === 0) {
            $out['jurisdiction'] = 'Federal';
        } else {
            $out['jurisdiction'] = ucwords(strtolower($out['jurisdiction']));
        }
    }

    return $out;
}
