<?php
/**
 * API - Extract Lawsuit Details from Uploaded Complaint
 *
 * Accepts a complaint PDF (or other supported document), saves it to the
 * WordPress media library, optionally moves it into a FileBird folder, then
 * sends it to Gemini 2.0 for structured extraction. The returned JSON matches
 * the lawsuit form schema so the admin UI can populate the form directly.
 *
 * POST multipart/form-data
 *   complaint            (file)   required - the document to extract from
 *   filebird_folder_id   (int)    optional - folder to move the attachment into
 *
 * Requires edit_posts capability (admin/contributor).
 */

header('Content-Type: application/json');

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

// --- gemini config check (do early so we don't save a file we can't use) ----
$gemini_key = kop_resolve_secret('GEMINI_API_KEY');
if ($gemini_key === '') {
    echo json_encode([
        'success' => false,
        'error'   => 'Gemini API key is not configured. Add GEMINI_API_KEY to your .env file. Get a free key at https://aistudio.google.com/app/apikey',
    ]);
    exit;
}

// --- save the file to the media library ------------------------------------
// We commit the upload BEFORE extracting so the source document is preserved
// even if extraction fails (the admin can still fill the form by hand with
// the document accessible in the FileBird folder).
$upload = wp_handle_upload($_FILES['complaint'], [
    'test_form' => false,
    // Lawsuits accept PDFs and the common Office formats; the validator
    // returns an error if the type is outside this whitelist.
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

$attachment_id = wp_insert_attachment([
    'post_mime_type' => $file_type,
    'post_title'     => preg_replace('/\.[^.]+$/', '', basename($file_path)),
    'post_content'   => '',
    'post_status'    => 'inherit',
], $file_path);

if (is_wp_error($attachment_id) || !$attachment_id) {
    echo json_encode(['success' => false, 'error' => 'Failed to register attachment in media library.']);
    exit;
}

wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $file_path));

// Move into FileBird folder if requested. FileBird Pro exposes a REST endpoint
// for this; if it fails (no API key, plugin missing, version mismatch) we
// just log and continue — the file is still in the media library.
if ($folder_id > 0) {
    $fb_key = kop_resolve_secret('FILEBIRD_API_KEY');
    if ($fb_key !== '') {
        $resp = wp_remote_post(home_url('/wp-json/filebird/public/v1/folder/set-folder'), [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Api-Key'    => $fb_key,
            ],
            'body'    => json_encode(['folder_id' => $folder_id, 'ids' => [$attachment_id]]),
            'timeout' => 15,
        ]);
        if (is_wp_error($resp)) {
            error_log('FileBird set-folder failed: ' . $resp->get_error_message());
        }
    }
}

// --- extract via Gemini -----------------------------------------------------
// Gemini accepts PDF/DOCX/TXT inline as base64. For files >20MB the docs
// recommend the Files API instead, but most complaints are well under that.
$max_inline_bytes = 20 * 1024 * 1024;
$file_bytes = @file_get_contents($file_path);
$file_size  = strlen($file_bytes ?: '');

if ($file_bytes === false || $file_size === 0) {
    echo json_encode([
        'success' => false,
        'error'   => 'Could not read uploaded file for extraction.',
        'attachment' => ['id' => $attachment_id, 'url' => $file_url],
    ]);
    exit;
}

if ($file_size > $max_inline_bytes) {
    echo json_encode([
        'success' => false,
        'error'   => 'File is larger than 20MB. The document was saved to the media library, but extraction was skipped — please fill the form manually.',
        'attachment' => ['id' => $attachment_id, 'url' => $file_url],
    ]);
    exit;
}

$prompt = kop_lawsuit_extraction_prompt();

$request_body = [
    'contents' => [[
        'parts' => [
            ['inlineData' => [
                'mimeType' => $file_type,
                'data'     => base64_encode($file_bytes),
            ]],
            ['text' => $prompt],
        ],
    ]],
    'generationConfig' => [
        'temperature'        => 0.1,
        'maxOutputTokens'    => 4096,
        'responseMimeType'   => 'application/json',
    ],
    // Loosen the default safety thresholds — complaints describe abuse, which
    // routinely trips BLOCK_MEDIUM_AND_ABOVE. BLOCK_ONLY_HIGH is the safest
    // we can set without disabling the filter entirely.
    'safetySettings' => [
        ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_ONLY_HIGH'],
        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_ONLY_HIGH'],
    ],
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-lite:generateContent?key={$gemini_key}");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_body));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($curl_err) {
    echo json_encode([
        'success' => false,
        'error'   => "Network error talking to Gemini: {$curl_err}",
        'attachment' => ['id' => $attachment_id, 'url' => $file_url],
    ]);
    exit;
}

$decoded = json_decode($response, true);

if ($http_code !== 200) {
    $api_err = $decoded['error']['message'] ?? "Gemini returned HTTP {$http_code}";
    echo json_encode([
        'success' => false,
        'error'   => "Gemini API error: {$api_err}",
        'attachment' => ['id' => $attachment_id, 'url' => $file_url],
    ]);
    exit;
}

// Detect content-filter blocks before trying to read .text.
if (!empty($decoded['promptFeedback']['blockReason'])) {
    echo json_encode([
        'success' => false,
        'error'   => "Gemini blocked the document for safety reasons ({$decoded['promptFeedback']['blockReason']}). The file was saved — please fill the form manually.",
        'attachment' => ['id' => $attachment_id, 'url' => $file_url],
    ]);
    exit;
}

$generated_text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
if ($generated_text === '') {
    echo json_encode([
        'success' => false,
        'error'   => 'Gemini returned an empty response.',
        'attachment' => ['id' => $attachment_id, 'url' => $file_url],
    ]);
    exit;
}

// Strip markdown fences if the model wrapped the JSON anyway (responseMimeType
// usually prevents this, but Gemini occasionally regresses).
$generated_text = preg_replace('/^```json\s*/', '', trim($generated_text));
$generated_text = preg_replace('/```\s*$/', '', $generated_text);

$extracted = json_decode($generated_text, true);
if (!is_array($extracted)) {
    echo json_encode([
        'success' => false,
        'error'   => 'Could not parse Gemini response as JSON.',
        'raw'     => substr($generated_text, 0, 500),
        'attachment' => ['id' => $attachment_id, 'url' => $file_url],
    ]);
    exit;
}

// --- normalize & merge attachment URL into document_urls -------------------
$extracted = kop_normalize_lawsuit_extraction($extracted);
$extracted['document_urls'] = array_values(array_unique(array_merge(
    $extracted['document_urls'] ?? [],
    [$file_url]
)));
// We don't have confidence the LLM correctly identified federal vs state, so
// leave jurisdiction blank for the admin to confirm if Gemini didn't fill it.
$extracted['publication_status'] = 'draft';

echo json_encode([
    'success'    => true,
    'data'       => $extracted,
    'attachment' => ['id' => $attachment_id, 'url' => $file_url],
]);
exit;


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
