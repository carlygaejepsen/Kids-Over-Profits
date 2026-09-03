<?php
/**
 * Fix slug titles — admin tool that humanizes attachment titles left over
 * from the May–June 2026 FileBird Google Drive restore.
 *
 * That import set post_title to the full sanitized filename, extension
 * included (e.g. "woodbury-june-2008-1-pdf", "ya-pacific-quest-natsap-2020-pdf").
 * This tool finds titles matching that pattern and rewrites them to a
 * readable form: extension suffix and single-digit dedupe counters stripped,
 * hyphens/underscores to spaces, title case with known acronyms restored
 * ("Woodbury June 2008", "YA Pacific Quest NATSAP 2020").
 *
 * GET shows a preview (nothing is written). Ticked rows are applied via
 * POST. Only post_title changes — files, slugs, and folders are untouched.
 *
 * Admin-only. Loads WordPress via config.php.
 */

require_once __DIR__ . '/config.php';

if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not authorized. Log in to WordPress as an administrator first.';
    exit;
}

header('Content-Type: text/html; charset=utf-8');

$SHOW = 500; // rows rendered per pass

/** Title looks like a sanitized filename with the extension glued on. */
function kop_fst_is_slug_title($title) {
    return (bool) preg_match('/-(pdf|jpe?g|png|gif|webp|bmp|svg|docx?|xlsx?|pptx?|txt|csv|mp[34]|mov|avi|zip)$/i', trim((string) $title));
}

/** Acronyms and initialisms to restore to caps after title-casing. */
function kop_fst_acronyms() {
    return ['natsap', 'wwasp', 'wwasps', 'tti', 'uhs', 'rtc', 'cedu', 'llc',
            'inc', 'sb', 'hb', 'gao', 'dhhs', 'dcfs', 'cps', 'faq', 'irs',
            'sec', 'ceo', 'cfo', 'usa', 'us', 'uk', 'ii', 'iii', 'iv',
            'ya', 'aba', 'ptsd', 'adhd', 'natsap'];
}

/** Rewrite one slug title into a readable one. */
function kop_fst_humanize($title) {
    $t = trim((string) $title);
    // Drop the extension suffix.
    $t = preg_replace('/-(pdf|jpe?g|png|gif|webp|bmp|svg|docx?|xlsx?|pptx?|txt|csv|mp[34]|mov|avi|zip)$/i', '', $t);
    // Drop a single-digit dedupe counter left at the end ("...-1", "...-2").
    $t = preg_replace('/-[1-9]$/', '', $t);
    // Separators to spaces.
    $t = str_replace(['-', '_'], ' ', $t);
    $t = preg_replace('/\s+/', ' ', trim($t));
    if ($t === '') {
        return $title; // never blank a title
    }
    // Case each word: known acronyms go to caps, fully-lowercase words get
    // title case, and words that already carry uppercase (SB710, ZambiaUPDATE,
    // McNamara) are left exactly as they are.
    $acronyms = kop_fst_acronyms();
    $words = explode(' ', $t);
    foreach ($words as &$w) {
        if (in_array(mb_strtolower($w), $acronyms, true)) {
            $w = mb_strtoupper($w);
        } elseif (!preg_match('/[A-Z]/', $w)) {
            $w = function_exists('mb_convert_case')
                ? mb_convert_case($w, MB_CASE_TITLE, 'UTF-8')
                : ucfirst($w);
        }
    }
    unset($w);
    return implode(' ', $words);
}

// ---------------------------------------------------------------------------
// Apply
// ---------------------------------------------------------------------------
$applied = false;
$apply_log = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['do_apply'])
    && check_admin_referer('kop_fst_apply')) {

    $requests = isset($_POST['row']) && is_array($_POST['row']) ? $_POST['row'] : [];
    $done = 0;
    $skipped = 0;
    foreach ($requests as $att_id => $req) {
        if (empty($req['go'])) {
            continue;
        }
        $att_id = (int) $att_id;
        if ($att_id <= 0 || get_post_type($att_id) !== 'attachment') {
            $skipped++;
            continue;
        }
        $current = get_post_field('post_title', $att_id, 'raw');
        // Recompute from the live title so a stale form can't clobber edits
        // made since the preview rendered.
        if (!kop_fst_is_slug_title($current)) {
            $skipped++;
            continue;
        }
        $new_title = kop_fst_humanize($current);
        if ($new_title === '' || $new_title === $current) {
            $skipped++;
            continue;
        }
        $result = wp_update_post(array(
            'ID' => $att_id,
            'post_title' => $new_title,
        ), true);
        if (is_wp_error($result)) {
            $skipped++;
        } else {
            $done++;
        }
    }
    $applied = true;
    $apply_log[] = "Renamed {$done} attachment(s)." . ($skipped ? " Skipped {$skipped} (already fixed, edited since preview, or update failed)." : '');
}

// ---------------------------------------------------------------------------
// Preview
// ---------------------------------------------------------------------------
global $wpdb;

$total_slug = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts}
     WHERE post_type = 'attachment'
       AND post_title REGEXP '-(pdf|jpg|jpeg|png|gif|webp|bmp|svg|doc|docx|xls|xlsx|ppt|pptx|txt|csv|mp3|mp4|mov|avi|zip)$'"
);

$q = trim((string) ($_GET['q'] ?? ''));
$sql = "SELECT ID, post_title, post_mime_type FROM {$wpdb->posts}
        WHERE post_type = 'attachment'
          AND post_title REGEXP '-(pdf|jpg|jpeg|png|gif|webp|bmp|svg|doc|docx|xls|xlsx|ppt|pptx|txt|csv|mp3|mp4|mov|avi|zip)$'";
$params = [];
if ($q !== '') {
    $sql .= " AND post_title LIKE %s";
    $params[] = '%' . $wpdb->esc_like($q) . '%';
}
$sql .= " ORDER BY post_title ASC LIMIT " . (int) $SHOW;
$rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);

$preview = [];
foreach ($rows as $r) {
    $new_title = kop_fst_humanize($r->post_title);
    if ($new_title === $r->post_title) {
        continue;
    }
    $preview[] = [
        'id' => (int) $r->ID,
        'old' => $r->post_title,
        'new' => $new_title,
        'mime' => $r->post_mime_type,
        'url' => wp_get_attachment_url($r->ID),
    ];
}
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Fix Slug Titles</title>
<style>
body { font-family: system-ui, sans-serif; margin: 24px; color: #000435; background: #F2EEDF; }
h1 { font-size: 1.15rem; margin: 0 0 8px; }
table { border-collapse: collapse; background: #fff; font-size: 0.82rem; width: 100%; }
th, td { border: 1px solid #ccc; padding: 3px 7px; text-align: left; vertical-align: top; }
th { background: #000080; color: #fff; }
tbody tr { cursor: pointer; }
tbody tr:hover { background: #FFF5CB; }
tbody tr.kop-ticked { background: #B6E3D4; }
input[name$="[go]"] { transform: scale(1.4); margin: 3px; }
.ok { color: #1b7e3c; } .warn { color: #b8860b; }
button { background: #33A7B5; color: #fff; border: none; border-radius: 6px; padding: 7px 12px; font-weight: 700; font-size: 0.85rem; cursor: pointer; }
.log { background: #fff; border: 1px solid #ccc; padding: 10px 14px; font-family: monospace; font-size: 0.8rem; margin-bottom: 12px; }
.kop-toolbar { position: sticky; top: 0; z-index: 60; background: #F2EEDF; padding: 8px 0 6px; border-bottom: 2px solid #33A7B5; margin-bottom: 8px; box-shadow: 0 4px 8px rgba(0,4,53,0.08); }
.kop-toolbar .bar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 6px; }
input[type=search] { padding: 6px 9px; border: 1px solid #000080; border-radius: 6px; }
td a { color: #000080; }
.old-title { font-family: monospace; color: #7a1f1f; }
.new-title { font-weight: 600; color: #1b7e3c; }
</style></head><body>
<h1>Fix Slug Titles <small style="font-weight:400">&mdash; humanize filename-slug attachment titles from the Google Drive restore</small></h1>

<?php if ($applied): ?>
    <div class="log ok"><?php echo implode('<br>', array_map('esc_html', $apply_log)); ?> <a href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'], '?')); ?>">Reload for the next batch.</a></div>
<?php endif; ?>

<div class="log">
    <strong><?php echo $total_slug; ?></strong> attachment(s) currently have filename-slug titles
    (title ends in -pdf, -jpg, and so on).
    Showing up to <?php echo (int) $SHOW; ?> per pass &mdash; apply, then reload for the next batch.
    Nothing changes until you click Apply; only the title is rewritten (file, URL, and folders stay put).
</div>

<form method="get" style="margin-bottom:10px">
    <input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Filter by title..." style="width:260px">
    <button type="submit" style="background:#000080">Filter</button>
</form>

<?php if ($preview): ?>
<form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
<?php wp_nonce_field('kop_fst_apply'); ?>
<div class="kop-toolbar">
    <div class="bar">
        <span>Showing <?php echo count($preview); ?> row(s) &middot; <span id="kop-count"></span></span>
        <button type="button" id="kop-tick-all" style="background:#000080">Tick all</button>
        <button type="button" id="kop-untick-all" style="background:#7a7a7a">Untick all</button>
        <button type="submit" name="do_apply" value="1" style="background:#1b7e3c"
            onclick="var n=document.querySelectorAll('tbody input:checked').length; if(!n){alert('Tick the rows to rename first.');return false;} return window.confirm('Rename '+n+' attachment title(s)?');">
            Apply renames</button>
        <small>row-click ticks &middot; nothing changes until Apply</small>
    </div>
</div>
<table><thead><tr><th></th><th>Current title</th><th>New title</th><th>File</th></tr></thead><tbody>
<?php foreach ($preview as $p): ?>
    <tr>
        <td><input type="checkbox" name="row[<?php echo $p['id']; ?>][go]" value="1" checked></td>
        <td class="old-title"><?php echo esc_html($p['old']); ?></td>
        <td class="new-title"><?php echo esc_html($p['new']); ?></td>
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
    var rows = Array.prototype.slice.call(document.querySelectorAll('tbody tr'));
    function check(row) { return row.querySelector('input[type=checkbox]'); }
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
})();
</script>
<?php else: ?>
<p class="ok">No slug titles match this view<?php echo $q !== '' ? ' (try clearing the filter)' : ' - all clean'; ?>.</p>
<?php endif; ?>

</body></html>
