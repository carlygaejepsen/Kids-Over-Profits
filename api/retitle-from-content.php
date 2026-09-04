<?php
/**
 * Retitle From Content — admin tool that renames unclear attachment titles
 * using the document's actual contents.
 *
 * Fix Slug Titles humanizes the filename; this tool goes further: it extracts
 * the text of the file (PDF, DOCX, TXT), sends it to the AI with the filename
 * and FileBird folder names as context, and proposes a real descriptive title
 * ("Provo Canyon School — DHHS Inspection Report (June 2008)"). Every
 * suggestion lands in an editable field and nothing is written until Apply.
 *
 * Only post_title changes — the physical file, its URL, slug, and folders are
 * never touched (renaming files on disk would break every existing link).
 *
 * Three request modes:
 *   GET               preview page listing attachments with unclear titles
 *   POST action=suggest  AJAX, one attachment: extract text, ask Groq, return JSON
 *   POST do_apply     write the reviewed titles for ticked rows
 *
 * Admin-only. Loads WordPress via config.php. Uses GROQ_API_KEY from .env.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lawsuit-extraction-lib.php'; // kop_resolve_secret, kop_extract_document_text

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

/** Mime types we can pull text out of (kop_extract_document_text). */
function kop_rtc_extractable_mime($mime) {
    return in_array($mime, [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
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
// Groq title generation
// ---------------------------------------------------------------------------

function kop_rtc_title_prompt() {
    return <<<PROMPT
You write catalog titles for a Troubled Teen Industry accountability archive. From the document text and context above, produce ONE short descriptive title for this file.

Title rules:
- Preferred shape: "Facility or Subject — Document Type (Month Year)". Examples: "Provo Canyon School — State Inspection Report (June 2008)", "Agape Boarding School — Civil Complaint (2022)", "NATSAP Membership Directory (2020)".
- Include the facility/program/organization name whenever the document reveals one; the folder name is a strong hint but the document text wins if they disagree.
- Say what the document IS (inspection report, license, complaint, incident report, news clipping, letter, brochure, court filing, survey...).
- Include a date only if the document states one.
- Factual and neutral, never sensationalist. No graphic details. Maximum 90 characters. No quotes around the title.
- If the text is unreadable or too thin to tell what the document is, build the best title you can from the filename and folder context and set basis to "filename".

Return ONLY valid JSON, no prose, no markdown fences:
{"title": "the title", "basis": "content" or "filename", "note": "at most one short sentence on what the document is"}
PROMPT;
}

function kop_rtc_groq_suggest($context_header, $doc_text) {
    $key = kop_resolve_secret('GROQ_API_KEY');
    if ($key === '') {
        return ['ok' => false, 'error' => 'GROQ_API_KEY is not configured in .env.'];
    }

    $doc_text = kop_lawsuit_chunk_text($doc_text, 1)['chunks'][0] ?? '';
    $model = getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b';

    $body = json_encode([
        'model'    => $model,
        'messages' => [[
            'role'    => 'user',
            'content' => $context_header . "\n\nDOCUMENT TEXT (may be truncated):\n" . $doc_text . "\n\n" . kop_rtc_title_prompt(),
        ]],
        'temperature' => 0.2,
        'max_tokens'  => 300,
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
        'basis' => ($parsed['basis'] ?? '') === 'filename' ? 'filename' : 'content',
        'note'  => mb_substr(trim((string) ($parsed['note'] ?? '')), 0, 200),
    ];
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
    $mime = get_post_mime_type($att_id);
    if (!kop_rtc_extractable_mime((string) $mime)) {
        echo json_encode(['ok' => false, 'error' => 'No text extraction for ' . $mime . ' — edit the title by hand.']);
        exit;
    }
    $path = get_attached_file($att_id);
    if (!$path || !file_exists($path)) {
        echo json_encode(['ok' => false, 'error' => 'File missing on disk (broken upload?).']);
        exit;
    }

    $text = kop_extract_document_text($path, (string) $mime);
    $filename = wp_basename($path);
    $folders  = kop_rtc_folder_names($att_id);
    $context  = "CONTEXT:\nFilename: {$filename}\nCurrent title: " . get_post_field('post_title', $att_id, 'raw')
              . ($folders ? "\nFileBird folder(s): " . implode(', ', $folders) : '');

    if (strlen(trim($text)) < 40) {
        $context .= "\n(The file yielded no readable text — likely a scanned image PDF.)";
    }

    $result = kop_rtc_groq_suggest($context, $text);
    if ($result['ok'] && strlen(trim($text)) < 40 && $result['basis'] === 'content') {
        $result['basis'] = 'filename';
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
        'id'          => (int) $r->ID,
        'title'       => $r->post_title,
        'mime'        => $r->post_mime_type,
        'url'         => wp_get_attachment_url($r->ID),
        'extractable' => kop_rtc_extractable_mime($r->post_mime_type),
        'folders'     => kop_rtc_folder_names((int) $r->ID),
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
<h1>Retitle From Content <small style="font-weight:400">&mdash; AI titles for unclear attachments, from the document text</small></h1>

<?php if ($applied): ?>
    <div class="log ok"><?php echo implode('<br>', array_map('esc_html', $apply_log)); ?> <a href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'], '?')); ?>">Reload for the next batch.</a></div>
<?php endif; ?>

<div class="log">
    <strong><?php echo $total_unclear; ?></strong> attachment(s) currently have unclear titles
    (filename slugs, scanner defaults, bare numbers, hashes).
    Showing up to <?php echo (int) $SHOW; ?> per pass.
    Workflow: tick rows, click <em>Suggest titles</em> to read each document and draft a title,
    edit anything you like, then <em>Apply</em>. Only the title changes &mdash; the file, its URL,
    and its folders stay exactly where they are. PDF, DOCX, and TXT get content extraction;
    other types can still be retitled by hand.
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
    <tr data-id="<?php echo $p['id']; ?>" data-extractable="<?php echo $p['extractable'] ? 1 : 0; ?>">
        <td><input type="checkbox" name="row[<?php echo $p['id']; ?>][go]" value="1"></td>
        <td>
            <div class="old-title"><?php echo esc_html($p['title']); ?></div>
            <?php if ($p['folders']): ?><div class="folders"><?php echo esc_html(implode(' / ', $p['folders'])); ?></div><?php endif; ?>
        </td>
        <td>
            <input type="text" class="new-title" name="row[<?php echo $p['id']; ?>][title]" value="" placeholder="<?php echo $p['extractable'] ? 'awaiting suggestion or type one' : 'no text extraction for this type - type a title'; ?>">
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

    // Suggest titles: small worker pool over the ticked, extractable,
    // still-empty rows. One POST per attachment so shared-hosting time
    // limits never bite.
    var suggestBtn = document.getElementById('kop-suggest');
    var progress = document.getElementById('kop-progress');
    suggestBtn.addEventListener('click', function () {
        var queue = rows.filter(function (r) {
            var c = check(r), t = titleInput(r);
            return c && c.checked && r.dataset.extractable === '1' && t && t.value.trim() === '';
        });
        if (!queue.length) {
            alert('Tick some rows first (PDF/DOCX/TXT rows without a title yet).');
            return;
        }
        suggestBtn.disabled = true;
        var total = queue.length, done = 0, failed = 0;
        progress.textContent = 'Reading documents... 0/' + total;

        function one(row) {
            var body = new URLSearchParams();
            body.set('action', 'suggest');
            body.set('id', row.dataset.id);
            body.set('_wpnonce', NONCE);
            return fetch(window.location.pathname, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(function (res) { return res.json(); })
            .catch(function () { return { ok: false, error: 'request failed' }; })
            .then(function (r) {
                var basis = row.querySelector('.basis');
                if (r.ok) {
                    titleInput(row).value = r.title;
                    basis.textContent = 'from ' + r.basis + (r.note ? ' - ' + r.note : '');
                    row.classList.remove('kop-err');
                } else {
                    failed++;
                    basis.textContent = r.error || 'failed';
                    row.classList.add('kop-err');
                }
                done++;
                progress.textContent = 'Reading documents... ' + done + '/' + total + (failed ? ' (' + failed + ' failed)' : '');
            });
        }

        var workers = [];
        for (var w = 0; w < 3; w++) {
            workers.push((function next() {
                var row = queue.shift();
                if (!row) return Promise.resolve();
                return one(row).then(next);
            })());
        }
        Promise.all(workers).then(function () {
            suggestBtn.disabled = false;
            progress.textContent = 'Done: ' + (done - failed) + ' suggested' + (failed ? ', ' + failed + ' failed (red rows)' : '') + '. Review, edit, then Apply.';
        });
    });
})();
</script>
<?php else: ?>
<p class="ok">No attachments match this view<?php echo $q !== '' ? ' (try clearing the filter)' : ($scope === 'unclear' ? ' - every title looks readable' : ''); ?>.</p>
<?php endif; ?>

</body></html>
