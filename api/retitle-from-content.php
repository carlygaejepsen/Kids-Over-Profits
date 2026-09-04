<?php
/**
 * Retitle From Content — admin tool that renames unclear attachment titles
 * using the document's actual contents.
 *
 * Fix Slug Titles humanizes the filename; this tool goes further: it reads
 * the file itself and asks the AI for a real descriptive title
 * ("Provo Canyon School — DHHS Inspection Report (June 2008)"). Every
 * suggestion lands in an editable field and nothing is written until Apply.
 *
 * How each type is read (shared hosting has no pdftotext or tesseract, so
 * the heavy lifting happens in the browser or via a vision model):
 *   PDF        browser extracts the text layer with pdf.js; if the PDF is a
 *              scan with no text layer, the browser renders page 1 to a JPEG
 *              and the server sends it to a Groq vision model (the OCR step)
 *   Images     server downscales with GD and sends to the vision model
 *   DOCX/TXT   server-side text extraction (lawsuit-extraction lib)
 *
 * Only post_title changes — the physical file, its URL, slug, and folders are
 * never touched (renaming files on disk would break every existing link).
 *
 * Three request modes:
 *   GET                  preview page listing attachments with unclear titles
 *   POST action=suggest  AJAX, one attachment: returns {ok, title, basis, note}
 *                        optional client-supplied `text` / `page_image` (b64 JPEG)
 *   POST do_apply        write the reviewed titles for ticked rows
 *
 * Admin-only. Loads WordPress via config.php. Uses GROQ_API_KEY (or the
 * legacy GROK_API_KEY spelling) from .env; GROQ_MODEL / GROQ_VISION_MODEL
 * override the models.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lawsuit-extraction-lib.php'; // kop_resolve_secret, kop_extract_document_text, kop_lawsuit_chunk_text

if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not authorized. Log in to WordPress as an administrator first.';
    exit;
}

$SHOW = 200; // rows rendered per pass

// ---------------------------------------------------------------------------
// Unclear-title detection
// ---------------------------------------------------------------------------

/** Mime types the tool can read (server text, client pdf.js, or vision). */
function kop_rtc_supported_mime($mime) {
    return in_array($mime, [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ], true);
}

/**
 * Title gives a human no idea what the document is: filename slugs from the
 * Google Drive restore, camera/scanner defaults, bare numbers, hash strings,
 * or near-empty strings.
 */
function kop_rtc_is_unclear_title($title) {
    $t = trim((string) $title);
    if (mb_strlen($t) < 4) return true;
    // Sanitized-filename slug with the extension glued on (GDrive restore).
    if (preg_match('/-(pdf|jpe?g|png|gif|webp|bmp|svg|docx?|xlsx?|pptx?|txt|csv|mp[34]|mov|avi|zip)$/i', $t)) return true;
    // Camera / scanner / generic defaults, with optional trailing counter.
    if (preg_match('/^(img|dsc|dscn|dcim|scan|image|photo|picture|screenshot|screen shot|document|doc|file|untitled|unnamed|download|attachment|new document|pdf)[ _\-]*\d*$/i', $t)) return true;
    // Nothing but digits, dates, and separators ("20080612", "06-12-2008 (1)").
    if (preg_match('/^[\d\s._\-()]+$/', $t)) return true;
    // Hex-hash or UUID-ish strings.
    if (preg_match('/^[0-9a-f]{8,}$/i', str_replace('-', '', $t)) && !preg_match('/[g-z]/i', $t)) return true;
    return false;
}

/** SQL WHERE fragment mirroring kop_rtc_is_unclear_title, for the count/list. */
function kop_rtc_unclear_where() {
    return "(
        CHAR_LENGTH(TRIM(post_title)) < 4
        OR post_title REGEXP '-(pdf|jpg|jpeg|png|gif|webp|bmp|svg|doc|docx|xls|xlsx|ppt|pptx|txt|csv|mp3|mp4|mov|avi|zip)$'
        OR post_title REGEXP '^(img|dsc|dscn|dcim|scan|image|photo|picture|screenshot|screen shot|document|doc|file|untitled|unnamed|download|attachment|new document|pdf)[ _-]*[0-9]*$'
        OR post_title REGEXP '^[0-9[:space:]._()-]+$'
    )";
}

// ---------------------------------------------------------------------------
// Groq title generation (text and vision)
// ---------------------------------------------------------------------------

function kop_rtc_groq_key() {
    // Production .env historically carries the GROK_API_KEY spelling — accept
    // both, same as ai-providers.php and the lawsuit endpoints.
    $k = kop_resolve_secret('GROQ_API_KEY');
    return $k !== '' ? $k : kop_resolve_secret('GROK_API_KEY');
}

/** @param string $mode 'text' (basis content/filename) or 'vision' (basis ocr/filename) */
function kop_rtc_title_prompt($mode) {
    $basis = ($mode === 'vision')
        ? '"ocr" (you read it from the page image) or "filename" (image unreadable, title built from filename/folder context)'
        : '"content" (you read it from the document text) or "filename" (text unreadable/too thin, title built from filename/folder context)';
    return <<<PROMPT
You write catalog titles for a Troubled Teen Industry accountability archive. From the document and context above, produce ONE short descriptive title for this file.

Title rules:
- Preferred shape: "Facility or Subject — Document Type (Month Year)". Examples: "Provo Canyon School — State Inspection Report (June 2008)", "Agape Boarding School — Civil Complaint (2022)", "NATSAP Membership Directory (2020)".
- Include the facility/program/organization name whenever the document reveals one; the folder name is a strong hint but the document itself wins if they disagree.
- Say what the document IS (inspection report, license, complaint, incident report, news clipping, letter, brochure, court filing, survey, photograph...).
- Include a date only if the document states one.
- Factual and neutral, never sensationalist. No graphic details. Maximum 90 characters. No quotes around the title.

Return ONLY valid JSON, no prose, no markdown fences:
{"title": "the title", "basis": {$basis}, "note": "at most one short sentence on what the document is"}
PROMPT;
}

/** Shared Groq chat call. $content is a string or a content-part array. */
function kop_rtc_groq_chat($model, $content, $max_tokens = 300) {
    $key = kop_rtc_groq_key();
    if ($key === '') {
        return ['ok' => false, 'error' => 'Groq API key not configured. Add GROQ_API_KEY to .env (GROK_API_KEY also accepted).'];
    }
    $body = json_encode([
        'model'       => $model,
        'messages'    => [['role' => 'user', 'content' => $content]],
        'temperature' => 0.2,
        'max_tokens'  => $max_tokens,
    ]);
    if ($body === false) {
        return ['ok' => false, 'error' => 'json_encode failed: ' . json_last_error_msg()];
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) return ['ok' => false, 'error' => 'Network error: ' . $curl_err];
    $decoded = json_decode($response, true);
    if ($http_code !== 200) {
        $msg = $decoded['error']['message'] ?? "HTTP {$http_code}";
        if ($http_code === 429) $msg = 'Groq rate limit — wait a moment and retry.';
        if ($http_code === 401) $msg = 'Groq API key is invalid. Check GROQ_API_KEY in .env.';
        return ['ok' => false, 'error' => 'Groq error: ' . $msg];
    }

    $raw = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
    $raw = preg_replace('/^```json\s*/i', '', $raw);
    $raw = preg_replace('/```\s*$/', '', $raw);
    $parsed = json_decode($raw, true);
    if (!is_array($parsed) || trim((string) ($parsed['title'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'Could not parse AI response. Preview: ' . substr($raw, 0, 160)];
    }
    return [
        'ok'    => true,
        'title' => mb_substr(trim(preg_replace('/\s+/', ' ', $parsed['title'])), 0, 140),
        'basis' => in_array($parsed['basis'] ?? '', ['content', 'ocr', 'filename'], true) ? $parsed['basis'] : 'content',
        'note'  => mb_substr(trim((string) ($parsed['note'] ?? '')), 0, 200),
    ];
}

function kop_rtc_suggest_from_text($context_header, $doc_text) {
    $doc_text = kop_lawsuit_chunk_text($doc_text, 1)['chunks'][0] ?? '';
    $model = getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b';
    $result = kop_rtc_groq_chat(
        $model,
        $context_header . "\n\nDOCUMENT TEXT (may be truncated):\n" . $doc_text . "\n\n" . kop_rtc_title_prompt('text')
    );
    if ($result['ok'] && $result['basis'] === 'ocr') $result['basis'] = 'content';
    return $result;
}

/** Groq keeps retiring model IDs, so vision is resolved, not hard-coded. */
function kop_rtc_model_missing_error($error) {
    foreach (['does not exist', 'decommissioned', 'has been deprecated', 'model_not_found', 'not found'] as $needle) {
        if (stripos($error, $needle) !== false) return true;
    }
    return false;
}

/** Static candidates: env override, last known-good, then recent Groq vision IDs. */
function kop_rtc_vision_model_candidates() {
    $candidates = [];
    $env = getenv('GROQ_VISION_MODEL');
    if ($env) $candidates[] = $env;
    $cached = get_transient('kop_rtc_vision_model');
    if (is_string($cached) && $cached !== '') $candidates[] = $cached;
    return array_values(array_unique(array_merge($candidates, [
        'meta-llama/llama-4-maverick-17b-128e-instruct',
        'meta-llama/llama-4-scout-17b-16e-instruct',
        'qwen/qwen3-vl-32b-instruct',
    ])));
}

/** Ask Groq which models this account can use and keep the vision-looking ones. */
function kop_rtc_discover_vision_models() {
    $key = kop_rtc_groq_key();
    if ($key === '') return [];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.groq.com/openai/v1/models');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $key]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $response = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode((string) $response, true);
    $ids = [];
    foreach (($decoded['data'] ?? []) as $m) {
        $id = (string) ($m['id'] ?? '');
        if ($id !== '' && preg_match('/(vl|vision|llama-4|maverick|scout|pixtral)/i', $id)) {
            $ids[] = $id;
        }
    }
    return $ids;
}

function kop_rtc_suggest_from_image($context_header, $jpeg_b64) {
    $content = [
        ['type' => 'text', 'text' => $context_header . "\n\nAttached is an image of the document's first page (or the image attachment itself). Read it.\n\n" . kop_rtc_title_prompt('vision')],
        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $jpeg_b64]],
    ];

    $tried = [];
    $models = kop_rtc_vision_model_candidates();
    // Two passes: known candidates, then whatever the account's live model
    // list says looks vision-capable.
    for ($pass = 0; $pass < 2; $pass++) {
        foreach ($models as $model) {
            if (in_array($model, $tried, true)) continue;
            $tried[] = $model;
            $result = kop_rtc_groq_chat($model, $content);
            if ($result['ok']) {
                set_transient('kop_rtc_vision_model', $model, WEEK_IN_SECONDS);
                if ($result['basis'] === 'content') $result['basis'] = 'ocr';
                return $result;
            }
            if (!kop_rtc_model_missing_error($result['error'])) {
                return $result; // real failure (rate limit, bad key, network) — stop probing
            }
        }
        if ($pass === 0) $models = kop_rtc_discover_vision_models();
    }
    return ['ok' => false, 'error' => 'No working Groq vision model (tried: ' . implode(', ', $tried)
        . '). Set GROQ_VISION_MODEL in .env to a current vision model from console.groq.com/docs/models.'];
}

/** Downscale an image file with GD and return a base64 JPEG ('' on failure). */
function kop_rtc_image_b64_from_file($path) {
    if (!function_exists('imagecreatefromstring')) return '';
    $raw = @file_get_contents($path);
    if (!$raw) return '';
    $img = @imagecreatefromstring($raw);
    if (!$img) return '';
    if (function_exists('imagepalettetotruecolor')) @imagepalettetotruecolor($img);
    $w = imagesx($img);
    $h = imagesy($img);
    $max = 1568;
    if (max($w, $h) > $max && function_exists('imagescale')) {
        $scaled = ($w >= $h)
            ? imagescale($img, $max)
            : imagescale($img, (int) round($w * $max / $h), $max);
        if ($scaled !== false) { imagedestroy($img); $img = $scaled; }
    }
    ob_start();
    imagejpeg($img, null, 82);
    $jpg = ob_get_clean();
    imagedestroy($img);
    return $jpg ? base64_encode($jpg) : '';
}

/** FileBird folder names for one attachment (regular folders + extra tags). */
function kop_rtc_folder_names($att_id) {
    global $wpdb;
    $fbv     = $wpdb->prefix . 'fbv';
    $fbv_rel = $wpdb->prefix . 'fbv_attachment_folder';
    $names   = [];
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fbv)) === $fbv) {
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT f.name FROM {$fbv_rel} af JOIN {$fbv} f ON f.id = af.folder_id WHERE af.attachment_id = %d",
            $att_id
        ));
    }
    $tags = $wpdb->prefix . 'kop_media_folder_tags';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tags)) === $tags) {
        $extra = $wpdb->get_col($wpdb->prepare(
            "SELECT f.name FROM {$tags} t JOIN {$fbv} f ON f.id = t.folder_id WHERE t.attachment_id = %d",
            $att_id
        ));
        $names = array_merge($names, $extra ?: []);
    }
    return array_values(array_unique(array_filter(array_map('trim', $names))));
}

// ---------------------------------------------------------------------------
// AJAX: suggest a title for one attachment
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'suggest') {
    header('Content-Type: application/json; charset=utf-8');
    if (!check_ajax_referer('kop_rtc', '_wpnonce', false)) {
        echo json_encode(['ok' => false, 'error' => 'Session expired — reload the page.']);
        exit;
    }
    $att_id = (int) ($_POST['id'] ?? 0);
    if ($att_id <= 0 || get_post_type($att_id) !== 'attachment') {
        echo json_encode(['ok' => false, 'error' => 'Not an attachment.']);
        exit;
    }
    $mime = (string) get_post_mime_type($att_id);
    if (!kop_rtc_supported_mime($mime)) {
        echo json_encode(['ok' => false, 'error' => 'Unsupported type ' . $mime . ' — edit the title by hand.']);
        exit;
    }

    // Client-supplied material (browser pdf.js): extracted text and/or a
    // rendered JPEG of page 1 when the PDF turned out to be a scan.
    $client_text = trim(wp_unslash((string) ($_POST['text'] ?? '')));
    $image_b64   = (string) ($_POST['page_image'] ?? '');
    if ($image_b64 !== '') {
        $image_b64 = preg_replace('/^data:image\/\w+;base64,/', '', $image_b64);
        if (strlen($image_b64) > 8 * 1024 * 1024 || base64_decode($image_b64, true) === false) {
            $image_b64 = '';
        }
    }

    $path     = get_attached_file($att_id);
    $has_file = $path && file_exists($path);
    $filename = $path ? wp_basename($path) : basename((string) wp_get_attachment_url($att_id));
    $folders  = kop_rtc_folder_names($att_id);
    $context  = "CONTEXT:\nFilename: {$filename}\nCurrent title: " . get_post_field('post_title', $att_id, 'raw')
              . ($folders ? "\nFileBird folder(s): " . implode(', ', $folders) : '');

    $text = $client_text;

    // Server-side fallbacks when the browser sent nothing usable.
    if (strlen($text) < 40 && $image_b64 === '') {
        if (!$has_file) {
            echo json_encode(['ok' => false, 'error' => 'File missing on disk (broken upload?).']);
            exit;
        }
        if (strpos($mime, 'image/') === 0) {
            $image_b64 = kop_rtc_image_b64_from_file($path);
            if ($image_b64 === '') {
                echo json_encode(['ok' => false, 'error' => 'Could not read image (GD unavailable or corrupt file).']);
                exit;
            }
        } else {
            $text = kop_extract_document_text($path, $mime);
        }
    }

    if (strlen(trim($text)) >= 40) {
        $result = kop_rtc_suggest_from_text($context, $text);
    } elseif ($image_b64 !== '') {
        $context .= "\n(No text layer was extractable — this is likely a scan; read the page image.)";
        $result = kop_rtc_suggest_from_image($context, $image_b64);
    } else {
        $result = ['ok' => false, 'error' => 'No readable text and no page image — for PDFs make sure the browser step ran (do not block cdnjs.cloudflare.com).'];
    }
    echo json_encode($result);
    exit;
}

// ---------------------------------------------------------------------------
// Apply reviewed titles
// ---------------------------------------------------------------------------
$applied = false;
$apply_log = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['do_apply'])
    && check_admin_referer('kop_rtc_apply')) {

    $requests = isset($_POST['row']) && is_array($_POST['row']) ? $_POST['row'] : [];
    $done = 0;
    $skipped = 0;
    foreach ($requests as $att_id => $req) {
        if (empty($req['go'])) continue;
        $att_id = (int) $att_id;
        $new_title = sanitize_text_field(wp_unslash((string) ($req['title'] ?? '')));
        $new_title = mb_substr(trim($new_title), 0, 180);
        if ($att_id <= 0 || get_post_type($att_id) !== 'attachment' || $new_title === '') {
            $skipped++;
            continue;
        }
        if ($new_title === get_post_field('post_title', $att_id, 'raw')) {
            $skipped++;
            continue;
        }
        $result = wp_update_post(['ID' => $att_id, 'post_title' => $new_title], true);
        if (is_wp_error($result)) $skipped++; else $done++;
    }
    $applied = true;
    $apply_log[] = "Retitled {$done} attachment(s)." . ($skipped ? " Skipped {$skipped} (unticked, empty, unchanged, or update failed)." : '');
}

// ---------------------------------------------------------------------------
// Preview
// ---------------------------------------------------------------------------
header('Content-Type: text/html; charset=utf-8');
global $wpdb;

$scope = (($_GET['scope'] ?? '') === 'all') ? 'all' : 'unclear';
$q     = trim((string) ($_GET['q'] ?? ''));

$where = "post_type = 'attachment'";
if ($scope === 'unclear') {
    $where .= ' AND ' . kop_rtc_unclear_where();
}
$total_unclear = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND " . kop_rtc_unclear_where()
);

$sql = "SELECT ID, post_title, post_mime_type FROM {$wpdb->posts} WHERE {$where}";
$params = [];
if ($q !== '') {
    $sql .= ' AND post_title LIKE %s';
    $params[] = '%' . $wpdb->esc_like($q) . '%';
}
$sql .= ' ORDER BY post_title ASC LIMIT ' . (int) $SHOW;
$rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);

$preview = [];
foreach ($rows as $r) {
    if ($scope === 'unclear' && !kop_rtc_is_unclear_title($r->post_title)) {
        continue; // PHP-side check is the authority; SQL is just the prefilter
    }
    $preview[] = [
        'id'        => (int) $r->ID,
        'title'     => $r->post_title,
        'mime'      => $r->post_mime_type,
        'url'       => wp_get_attachment_url($r->ID),
        'supported' => kop_rtc_supported_mime($r->post_mime_type),
        'folders'   => kop_rtc_folder_names((int) $r->ID),
    ];
}
$ajax_nonce = wp_create_nonce('kop_rtc');
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Retitle From Content</title>
<style>
body { font-family: system-ui, sans-serif; margin: 24px; color: #000435; background: #F2EEDF; }
h1 { font-size: 1.15rem; margin: 0 0 8px; }
table { border-collapse: collapse; background: #fff; font-size: 0.82rem; width: 100%; }
th, td { border: 1px solid #ccc; padding: 3px 7px; text-align: left; vertical-align: top; }
th { background: #000080; color: #fff; }
tbody tr:hover { background: #FFF5CB; }
tbody tr.kop-ticked { background: #B6E3D4; }
tbody tr.kop-err { background: #FE808822; }
input[name$="[go]"] { transform: scale(1.4); margin: 3px; }
.ok { color: #1b7e3c; } .warn { color: #b8860b; } .err { color: #a33; }
button { background: #33A7B5; color: #fff; border: none; border-radius: 6px; padding: 7px 12px; font-weight: 700; font-size: 0.85rem; cursor: pointer; }
button:disabled { opacity: 0.5; cursor: default; }
.log { background: #fff; border: 1px solid #ccc; padding: 10px 14px; font-family: monospace; font-size: 0.8rem; margin-bottom: 12px; }
.kop-toolbar { position: sticky; top: 0; z-index: 60; background: #F2EEDF; padding: 8px 0 6px; border-bottom: 2px solid #33A7B5; margin-bottom: 8px; box-shadow: 0 4px 8px rgba(0,4,53,0.08); }
.kop-toolbar .bar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 6px; }
input[type=search], input.new-title { padding: 6px 9px; border: 1px solid #000080; border-radius: 6px; }
input.new-title { width: 100%; box-sizing: border-box; font-weight: 600; color: #1b7e3c; }
td a { color: #000080; }
.old-title { font-family: monospace; color: #7a1f1f; word-break: break-word; }
.basis { font-size: 0.72rem; color: #555; }
.folders { font-size: 0.72rem; color: #000080; }
</style></head><body>
<h1>Retitle From Content <small style="font-weight:400">&mdash; AI titles for unclear attachments, from the document text (with OCR for scans)</small></h1>

<?php if ($applied): ?>
    <div class="log ok"><?php echo implode('<br>', array_map('esc_html', $apply_log)); ?> <a href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'], '?')); ?>">Reload for the next batch.</a></div>
<?php endif; ?>

<div class="log">
    <strong><?php echo $total_unclear; ?></strong> attachment(s) currently have unclear titles
    (filename slugs, scanner defaults, bare numbers, hashes).
    Showing up to <?php echo (int) $SHOW; ?> per pass.
    Workflow: tick rows, click <em>Suggest titles</em> to read each document and draft a title,
    edit anything you like, then <em>Apply</em>. Only the title changes &mdash; the file, its URL,
    and its folders stay exactly where they are.
    PDFs are read in your browser (scans get vision OCR of page 1); images go to the vision
    model; DOCX/TXT are read on the server. Anything else can still be retitled by hand.
</div>

<form method="get" style="margin-bottom:10px">
    <input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Filter by title..." style="width:260px">
    <label style="margin-left:8px"><input type="radio" name="scope" value="unclear" <?php checked($scope, 'unclear'); ?>> unclear titles only</label>
    <label><input type="radio" name="scope" value="all" <?php checked($scope, 'all'); ?>> all attachments</label>
    <button type="submit" style="background:#000080">Filter</button>
</form>

<?php if ($preview): ?>
<form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
<?php wp_nonce_field('kop_rtc_apply'); ?>
<div class="kop-toolbar">
    <div class="bar">
        <span>Showing <?php echo count($preview); ?> row(s) &middot; <span id="kop-count"></span></span>
        <button type="button" id="kop-tick-all" style="background:#000080">Tick all</button>
        <button type="button" id="kop-untick-all" style="background:#7a7a7a">Untick all</button>
        <button type="button" id="kop-suggest" style="background:#EF9034">Suggest titles for ticked rows</button>
        <button type="submit" name="do_apply" value="1" style="background:#1b7e3c"
            onclick="return window.kopConfirmApply(this);">Apply titles</button>
        <span id="kop-progress" class="warn"></span>
    </div>
</div>
<table><thead><tr><th></th><th style="width:32%">Current title</th><th style="width:40%">New title</th><th>File</th></tr></thead><tbody>
<?php foreach ($preview as $p): ?>
    <tr data-id="<?php echo $p['id']; ?>" data-mime="<?php echo esc_attr($p['mime']); ?>"
        data-supported="<?php echo $p['supported'] ? 1 : 0; ?>" data-url="<?php echo esc_url($p['url']); ?>">
        <td><input type="checkbox" name="row[<?php echo $p['id']; ?>][go]" value="1"></td>
        <td>
            <div class="old-title"><?php echo esc_html($p['title']); ?></div>
            <?php if ($p['folders']): ?><div class="folders"><?php echo esc_html(implode(' / ', $p['folders'])); ?></div><?php endif; ?>
        </td>
        <td>
            <input type="text" class="new-title" name="row[<?php echo $p['id']; ?>][title]" value="" placeholder="<?php echo $p['supported'] ? 'awaiting suggestion or type one' : 'no auto-read for this type - type a title'; ?>">
            <div class="basis"></div>
        </td>
        <td>
            <a href="<?php echo esc_url($p['url']); ?>" target="_blank" rel="noopener">view</a>
            <small><?php echo esc_html($p['mime']); ?> &middot; #<?php echo $p['id']; ?></small>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table>
</form>
<script>
(function () {
    var NONCE = <?php echo json_encode($ajax_nonce); ?>;
    var PDFJS_VER = '3.11.174';
    var rows = Array.prototype.slice.call(document.querySelectorAll('tbody tr'));
    function check(row) { return row.querySelector('input[type=checkbox]'); }
    function titleInput(row) { return row.querySelector('input.new-title'); }
    function paint(row) { var c = check(row); if (c) row.classList.toggle('kop-ticked', c.checked); }
    function refresh() {
        var n = rows.filter(function (r) { var c = check(r); return c && c.checked; }).length;
        document.getElementById('kop-count').textContent = n + ' ticked.';
    }
    rows.forEach(function (row) {
        paint(row);
        row.addEventListener('click', function (e) {
            if (e.target.closest('a, button, input')) {
                if (e.target === check(row)) { paint(row); refresh(); }
                return;
            }
            var c = check(row);
            if (!c) return;
            c.checked = !c.checked;
            paint(row);
            refresh();
        });
    });
    refresh();
    document.getElementById('kop-tick-all').addEventListener('click', function () {
        rows.forEach(function (r) { var c = check(r); if (c) { c.checked = true; paint(r); } });
        refresh();
    });
    document.getElementById('kop-untick-all').addEventListener('click', function () {
        rows.forEach(function (r) { var c = check(r); if (c) { c.checked = false; paint(r); } });
        refresh();
    });

    // Apply only writes rows that are ticked AND have a title typed in.
    window.kopConfirmApply = function () {
        var ready = rows.filter(function (r) {
            var c = check(r), t = titleInput(r);
            return c && c.checked && t && t.value.trim() !== '';
        }).length;
        if (!ready) { alert('Tick rows and fill in (or suggest) their new titles first.'); return false; }
        return window.confirm('Write ' + ready + ' new attachment title(s)?');
    };

    // ------------------------------------------------------------------
    // Browser-side PDF reading: pdf.js extracts the text layer; if the
    // PDF is a scan with no text layer, render page 1 to a JPEG for the
    // server's vision-model OCR step.
    // ------------------------------------------------------------------
    var pdfjsReady = null;
    function loadPdfJs() {
        if (pdfjsReady) return pdfjsReady;
        pdfjsReady = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/' + PDFJS_VER + '/pdf.min.js';
            s.onload = function () {
                window.pdfjsLib.GlobalWorkerOptions.workerSrc =
                    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/' + PDFJS_VER + '/pdf.worker.min.js';
                resolve(window.pdfjsLib);
            };
            s.onerror = function () { reject(new Error('pdf.js failed to load from cdnjs')); };
            document.head.appendChild(s);
        });
        return pdfjsReady;
    }

    async function readPdfInBrowser(url) {
        var pdfjs = await loadPdfJs();
        var doc = await pdfjs.getDocument({ url: url }).promise;
        try {
            var text = '';
            var pages = Math.min(doc.numPages, 5);
            for (var i = 1; i <= pages; i++) {
                var page = await doc.getPage(i);
                var tc = await page.getTextContent();
                text += tc.items.map(function (it) { return it.str; }).join(' ') + '\n';
                if (text.length > 12000) break;
            }
            text = text.replace(/\s+/g, ' ').trim();
            if (text.length >= 200) return { text: text };

            // Scan (or near-empty text layer): render page 1 for OCR.
            var page1 = await doc.getPage(1);
            var vp = page1.getViewport({ scale: 1 });
            var scale = Math.min(2.5, 1568 / Math.max(vp.width, vp.height));
            var vp2 = page1.getViewport({ scale: scale });
            var canvas = document.createElement('canvas');
            canvas.width = Math.ceil(vp2.width);
            canvas.height = Math.ceil(vp2.height);
            await page1.render({ canvasContext: canvas.getContext('2d'), viewport: vp2 }).promise;
            var dataUrl = canvas.toDataURL('image/jpeg', 0.82);
            return { text: text, image: dataUrl.split(',')[1] };
        } finally {
            doc.destroy();
        }
    }

    async function suggestOne(row, setStatus) {
        var body = new URLSearchParams();
        body.set('action', 'suggest');
        body.set('id', row.dataset.id);
        body.set('_wpnonce', NONCE);

        if (row.dataset.mime === 'application/pdf') {
            try {
                setStatus('reading PDF');
                var read = await readPdfInBrowser(row.dataset.url);
                if (read.text && read.text.length >= 200) {
                    body.set('text', read.text);
                } else if (read.image) {
                    setStatus('OCR');
                    body.set('page_image', read.image);
                    if (read.text) body.set('text', read.text);
                }
            } catch (e) {
                // Server-side extraction is the fallback; carry on with a bare request.
            }
        }

        var r;
        try {
            var res = await fetch(window.location.pathname, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            r = await res.json();
        } catch (e) {
            r = { ok: false, error: 'request failed' };
        }

        var basis = row.querySelector('.basis');
        if (r.ok) {
            titleInput(row).value = r.title;
            basis.textContent = 'from ' + r.basis + (r.note ? ' - ' + r.note : '');
            row.classList.remove('kop-err');
            return true;
        }
        basis.textContent = r.error || 'failed';
        row.classList.add('kop-err');
        return false;
    }

    var suggestBtn = document.getElementById('kop-suggest');
    var progress = document.getElementById('kop-progress');
    suggestBtn.addEventListener('click', function () {
        var queue = rows.filter(function (r) {
            var c = check(r), t = titleInput(r);
            return c && c.checked && r.dataset.supported === '1' && t && t.value.trim() === '';
        });
        if (!queue.length) {
            alert('Tick some rows first (supported rows without a title yet).');
            return;
        }
        suggestBtn.disabled = true;
        var total = queue.length, done = 0, failed = 0;
        function report(extra) {
            progress.textContent = 'Suggesting... ' + done + '/' + total
                + (failed ? ' (' + failed + ' failed)' : '') + (extra ? ' - ' + extra : '');
        }
        report();

        function worker() {
            var row = queue.shift();
            if (!row) return Promise.resolve();
            return suggestOne(row, report).then(function (ok) {
                if (!ok) failed++;
                done++;
                report();
                return worker();
            });
        }
        Promise.all([worker(), worker(), worker()]).then(function () {
            suggestBtn.disabled = false;
            progress.textContent = 'Done: ' + (done - failed) + ' suggested'
                + (failed ? ', ' + failed + ' failed (red rows)' : '') + '. Review, edit, then Apply.';
        });
    });
})();
</script>
<?php else: ?>
<p class="ok">No attachments match this view<?php echo $q !== '' ? ' (try clearing the filter)' : ($scope === 'unclear' ? ' - every title looks readable' : ''); ?>.</p>
<?php endif; ?>

</body></html>
