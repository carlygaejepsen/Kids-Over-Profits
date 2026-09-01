<?php
/**
 * Organize uncategorized media — admin maintenance tool.
 *
 * Lists media-library attachments that are in NO FileBird folder and suggests
 * a destination by matching the file's title/filename against folder names
 * (deepest, most-specific match wins; folders whose names are only generic
 * words — "Lawsuits", "Handbooks" — never auto-match on their own).
 *
 * GET  = report with suggestions (checkboxes pre-ticked where confident).
 * POST + nonce = file the selected attachments into their folders.
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

global $wpdb;
$fbv = $wpdb->prefix . 'fbv';
$fbv_rel = $wpdb->prefix . 'fbv_attachment_folder';

header('Content-Type: text/html; charset=utf-8');

$BATCH = 300;

/** Normalize text for matching: lowercase, letters/digits only, spaced. */
function kop_oum_norm($text) {
    $t = mb_strtolower((string)$text);
    $t = html_entity_decode($t, ENT_QUOTES);
    // Filenames: break on separators before stripping.
    $t = str_replace(['-', '_', '.', '/'], ' ', $t);
    $t = preg_replace('/[^a-z0-9\s]/u', ' ', $t);
    return preg_replace('/\s+/', ' ', trim($t));
}

/** Generic words that must not drive a folder match on their own. */
function kop_oum_generic() {
    return ['the', 'a', 'an', 'of', 'and', 'for', 'at', 'in', 'inc', 'llc', 'co',
            'lawsuits', 'lawsuit', 'handbooks', 'handbook', 'investigations',
            'investigation', 'reports', 'report', 'records', 'record', 'photos',
            'photo', 'docs', 'documents', 'document', 'corporate', 'staff',
            'legislative', 'legislation', 'history', 'inspections', 'inspection',
            'locations', 'location', 'programs', 'program', 'academy', 'academies',
            'school', 'schools', 'ranch', 'center', 'centre', 'centers', 'camp',
            'residential', 'treatment', 'therapeutic', 'wilderness', 'behavioral',
            'health', 'recovery', 'boarding', 'youth', 'teen', 'teens', 'boys',
            'girls', 'kids', 'children', 'home', 'homes', 'house', 'group',
            'international', 'new', 'old', 'misc', 'other', 'general'];
}

// ---------------------------------------------------------------------------
// Folder tree + matcher
// ---------------------------------------------------------------------------
$folders = $wpdb->get_results("SELECT id, name, parent FROM {$fbv} WHERE type = 0");
$by_id = [];
foreach ($folders as $f) {
    $by_id[(int)$f->id] = $f;
}
function kop_oum_path($fid, $by_id, $depth = 0) {
    if ($depth > 10 || !isset($by_id[(int)$fid])) {
        return '';
    }
    $f = $by_id[(int)$fid];
    $prefix = ((int)$f->parent !== 0) ? kop_oum_path($f->parent, $by_id, $depth + 1) : '';
    return ($prefix !== '' ? $prefix . ' / ' : '') . $f->name;
}

// Matchable folders: normalized name, must carry ≥1 distinctive token.
$generic = kop_oum_generic();
$matchable = [];
foreach ($folders as $f) {
    $norm = kop_oum_norm($f->name);
    if ($norm === '' || strlen($norm) < 4) {
        continue;
    }
    $tokens = array_filter(explode(' ', $norm), static function ($t) use ($generic) {
        return strlen($t) >= 3 && !ctype_digit($t) && !in_array($t, $generic, true);
    });
    if (!$tokens) {
        continue;
    }
    $matchable[] = ['id' => (int)$f->id, 'norm' => $norm, 'tokens' => array_values($tokens)];
}

/**
 * Suggest a folder for a file. Returns [folder_id, why] or [null, reason].
 * Primary: the folder's full normalized name appears in the file text
 * (longest name wins). Fallback: every distinctive token of the folder name
 * appears in the file text. Same-name folders in different places → ambiguous.
 */
function kop_oum_suggest($fileText, $matchable) {
    $best = null;
    $bestScore = 0;
    $bestNames = [];
    foreach ($matchable as $m) {
        $score = 0;
        if (strpos($fileText, $m['norm']) !== false) {
            $score = 1000 + strlen($m['norm']);
        } else {
            $all = true;
            foreach ($m['tokens'] as $t) {
                if (!preg_match('/\b' . preg_quote($t, '/') . '\b/', $fileText)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $score = 100 + strlen(implode(' ', $m['tokens']));
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $m;
            $bestNames = [$m['norm'] => [$m['id']]];
        } elseif ($score === $bestScore && $score > 0) {
            $bestNames[$m['norm']][] = $m['id'];
        }
    }
    if (!$best) {
        return [null, 'no name match'];
    }
    // Ambiguous only when the SAME name matched from multiple folders.
    if (count($bestNames[$best['norm']]) > 1) {
        return [null, 'ambiguous: “' . $best['norm'] . '” exists in ' . count($bestNames[$best['norm']]) . ' places'];
    }
    return [$best['id'], $bestScore >= 1000 ? 'name match' : 'token match'];
}

// ---------------------------------------------------------------------------
// Apply?
// ---------------------------------------------------------------------------
$applied = false;
$apply_log = [];

// Permanently delete the ticked attachments (files + thumbnails + DB rows).
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['do_delete'])
    && check_admin_referer('kop_oum_apply')) {

    $requests = isset($_POST['file']) && is_array($_POST['file']) ? $_POST['file'] : [];
    $deleted = 0;
    $failed = 0;
    foreach ($requests as $att_id => $req) {
        if (empty($req['go'])) {
            continue;
        }
        $att_id = (int)$att_id;
        if ($att_id <= 0 || get_post_type($att_id) !== 'attachment') {
            continue;
        }
        if (wp_delete_attachment($att_id, true)) {
            $deleted++;
        } else {
            $failed++;
        }
    }
    $applied = true;
    $apply_log[] = "Permanently deleted {$deleted} file(s)" . ($failed ? " — {$failed} failed" : '') . '.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['do_file'])
    && check_admin_referer('kop_oum_apply')) {

    $requests = isset($_POST['file']) && is_array($_POST['file']) ? $_POST['file'] : [];
    $wpdb->query('START TRANSACTION');
    try {
        $filed = 0;
        foreach ($requests as $att_id => $req) {
            if (empty($req['go'])) {
                continue;
            }
            $att_id = (int)$att_id;
            $target = (int)($req['target'] ?? 0);
            if ($att_id <= 0 || $target <= 0 || !isset($by_id[$target])) {
                continue;
            }
            // Only file attachments that are still uncategorized.
            $existing = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$fbv_rel} WHERE attachment_id = %d", $att_id
            ));
            if ($existing > 0) {
                continue;
            }
            $wpdb->insert($fbv_rel, ['folder_id' => $target, 'attachment_id' => $att_id], ['%d', '%d']);
            $filed++;
        }
        $wpdb->query('COMMIT');
        $applied = true;
        $apply_log[] = "Filed {$filed} attachment(s).";
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        $apply_log[] = 'FAILED — rolled back: ' . $e->getMessage();
        error_log('organize-uncategorized-media failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Uncategorized attachments + suggestions
// ---------------------------------------------------------------------------
$total_uncat = (int)$wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} p
     LEFT JOIN {$fbv_rel} r ON r.attachment_id = p.ID
     WHERE p.post_type = 'attachment' AND r.attachment_id IS NULL"
);

$attachments = $wpdb->get_results($wpdb->prepare(
    "SELECT p.ID, p.post_title, p.post_mime_type FROM {$wpdb->posts} p
     LEFT JOIN {$fbv_rel} r ON r.attachment_id = p.ID
     WHERE p.post_type = 'attachment' AND r.attachment_id IS NULL
     ORDER BY p.ID DESC
     LIMIT %d", $BATCH
));

$rows = [];
$suggested_count = 0;
foreach ($attachments as $a) {
    $file_meta = get_post_meta($a->ID, '_wp_attached_file', true);
    $basename = $file_meta ? basename($file_meta) : '';
    $fileText = kop_oum_norm($a->post_title . ' ' . $basename);
    list($sug, $why) = kop_oum_suggest($fileText, $matchable);
    if ($sug !== null) {
        $suggested_count++;
    }
    $rows[] = [
        'id'    => (int)$a->ID,
        'title' => $a->post_title !== '' ? $a->post_title : $basename,
        'file'  => $basename,
        'mime'  => $a->post_mime_type,
        'url'   => wp_get_attachment_url($a->ID),
        'sug'   => $sug,
        'why'   => $why,
    ];
}
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Organize Uncategorized Media</title>
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
.summary { font-size: 0.85rem; margin-bottom: 6px; }
button { background: #33A7B5; color: #fff; border: none; border-radius: 6px; padding: 7px 12px; font-weight: 700; font-size: 0.85rem; cursor: pointer; }
.log { background: #fff; border: 1px solid #ccc; padding: 10px 14px; font-family: monospace; font-size: 0.8rem; margin-bottom: 12px; }
input[type=number] { width: 84px; }
td a { color: #000080; }
/* Toolbar stays pinned while the table scrolls — no hunting for buttons. */
.kop-toolbar { position: sticky; top: 0; z-index: 60; background: #F2EEDF; padding: 8px 0 6px; border-bottom: 2px solid #33A7B5; margin-bottom: 8px; box-shadow: 0 4px 8px rgba(0,4,53,0.08); }
.kop-toolbar .bar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 6px; }
.kop-toolbar input[type=search], .kop-toolbar input[type=text] { padding: 6px 9px; border: 1px solid #000080; border-radius: 6px; }
.kop-toolbar small { color: #555; }
</style></head><body>
<h1>Organize Uncategorized Media</h1>

<?php if ($applied): ?>
    <div class="log ok"><?php echo implode('<br>', array_map('esc_html', $apply_log)); ?> <a href="">Reload for the next batch.</a></div>
<?php elseif ($apply_log): ?>
    <div class="log warn"><?php echo implode('<br>', array_map('esc_html', $apply_log)); ?></div>
<?php endif; ?>

<?php if ($rows): ?>
<form method="post">
<?php wp_nonce_field('kop_oum_apply'); ?>
<div class="kop-toolbar">
    <div class="summary">
        <strong><?php echo $total_uncat; ?></strong> uncategorized
        (showing newest <?php echo count($rows); ?>;
        <?php echo $suggested_count; ?> auto-matched &amp; pre-ticked) ·
        <span id="kop-count"></span>
        <span id="kop-bulk-name" style="color:#000080;font-weight:600"></span>
    </div>
    <div class="bar">
        <input type="search" id="kop-filter" placeholder="Filter files… e.g. elan" style="width:220px">
        <button type="button" id="kop-tick-visible" style="background:#000080">☑ Tick visible</button>
        <button type="button" id="kop-untick-visible" style="background:#7a7a7a">☐ Untick</button>
        <button type="button" id="kop-bulk-pick">🗂️ Send ticked to folder…</button>
        <button type="submit" name="do_file" value="1" style="background:#1b7e3c"
            onclick="return window.confirm('File the ticked attachments into their folders?');">
            ✅ Apply filing</button>
        <button type="submit" name="do_delete" value="1" style="background:#7a1f1f"
            onclick="var n=document.querySelectorAll('tbody input[name$=&quot;[go]&quot;]:checked').length; if(!n){alert('Tick the files to delete first.');return false;} return window.confirm('PERMANENTLY delete '+n+' file(s) from the media library?\n\nThis removes the actual files and cannot be undone.');">
            🗑️ Delete ticked</button>
    </div>
    <div class="bar">
        <strong style="font-size:0.85rem">➕ New folder:</strong>
        <input type="text" id="kop-nf-name" placeholder="folder name" style="width:170px">
        <button type="button" id="kop-nf-parent" style="background:#7a7a7a">📁 parent: top level</button>
        <button type="button" id="kop-nf-create" style="background:#EF9034">Create</button>
        <small>row-click ticks · shift-click ranges · nothing changes until Apply/Delete</small>
    </div>
</div>
<table><thead><tr><th></th><th>File</th><th>Suggested folder</th><th>Folder ID</th></tr></thead><tbody>
<?php foreach ($rows as $r):
    $sug_label = $r['sug'] !== null
        ? '<span class="ok">#' . $r['sug'] . ' ' . esc_html(kop_oum_path($r['sug'], $by_id)) . '</span> <small>(' . esc_html($r['why']) . ')</small>'
        : '<span class="warn">' . esc_html($r['why']) . '</span>';
?>
    <tr>
        <td><input type="checkbox" name="file[<?php echo $r['id']; ?>][go]" value="1" <?php echo $r['sug'] !== null ? 'checked' : ''; ?>></td>
        <td>
            <a href="<?php echo esc_url($r['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($r['title']); ?></a>
            <?php if ($r['file'] && $r['file'] !== $r['title']): ?><br><small><?php echo esc_html($r['file']); ?></small><?php endif; ?>
            <br><small><?php echo esc_html($r['mime']); ?> · #<?php echo $r['id']; ?></small>
        </td>
        <td><?php echo $sug_label; ?></td>
        <td>
            <input type="number" name="file[<?php echo $r['id']; ?>][target]" min="1"
                value="<?php echo $r['sug'] !== null ? (int)$r['sug'] : ''; ?>" placeholder="folder ID">
            <button type="button" class="kop-pick" title="Browse folders">📁 pick</button>
            <div class="kop-picked-name" style="font-size:0.78rem;color:#000080"></div>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table>
<p><button type="submit" name="do_file" value="1"
    onclick="return window.confirm('File the ticked attachments into their folders?');">
    📁 Apply filing for ticked rows</button></p>
</form>
<?php else: ?>
<p class="ok">🎉 Nothing uncategorized — the media library is fully filed.</p>
<?php endif; ?>

<link rel="stylesheet" href="<?php echo esc_url(get_stylesheet_directory_uri() . '/css/filebird-folder-browser.css'); ?>">
<script src="<?php echo esc_url(get_stylesheet_directory_uri() . '/js/filebird-folder-browser.js'); ?>"></script>
<script>
// "pick" buttons: open the searchable folder browser and fill the row's
// folder-ID input (and tick the row) with the chosen folder.
(function () {
    var foldersUrl = <?php echo wp_json_encode(rest_url('kop/v1/folders')); ?>;
    var foldersAdminApi = <?php echo wp_json_encode(get_stylesheet_directory_uri() . '/api/filebird-folders.php'); ?>;
    document.querySelectorAll('.kop-pick').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.KOPFolderBrowser || typeof window.KOPFolderBrowser.open !== 'function') {
                alert('Folder browser failed to load.');
                return;
            }
            var row = btn.closest('tr');
            var input = row.querySelector('input[type=number]');
            var label = row.querySelector('.kop-picked-name');
            var check = row.querySelector('input[type=checkbox]');
            window.KOPFolderBrowser.open({ foldersUrl: foldersUrl, currentId: input.value })
                .then(function (res) {
                    if (!res) return; // cancelled
                    if (res.id === null) {
                        input.value = '';
                        if (label) label.textContent = '';
                        if (check) check.checked = false;
                    } else {
                        input.value = res.id;
                        if (label) label.textContent = '→ ' + res.name;
                        if (check) check.checked = true;
                    }
                    paintRow(row);
                    refreshCount();
                })
                .catch(function () { alert('Folder browser error.'); });
        });
    });

    // ---- Group selection helpers ----
    var allRows = Array.prototype.slice.call(document.querySelectorAll('tbody tr'));
    var lastClicked = null;

    function rowCheck(row) { return row.querySelector('input[name$="[go]"]'); }
    function paintRow(row) {
        var c = rowCheck(row);
        if (c) row.classList.toggle('kop-ticked', c.checked);
    }
    function refreshCount() {
        var n = allRows.filter(function (r) { var c = rowCheck(r); return c && c.checked; }).length;
        var el = document.getElementById('kop-count');
        if (el) el.textContent = n + ' ticked.';
    }
    allRows.forEach(paintRow);
    refreshCount();

    // Click anywhere on a row toggles it; shift-click toggles the range from
    // the last clicked row. Clicks on links/inputs/buttons behave normally.
    allRows.forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('a, button, input, select, label')) {
                if (e.target === rowCheck(row)) { paintRow(row); refreshCount(); lastClicked = row; }
                return;
            }
            var check = rowCheck(row);
            if (!check) return;
            if (e.shiftKey && lastClicked && lastClicked !== row) {
                var a = allRows.indexOf(lastClicked);
                var b = allRows.indexOf(row);
                var from = Math.min(a, b), to = Math.max(a, b);
                var state = rowCheck(lastClicked).checked;
                for (var i = from; i <= to; i++) {
                    if (allRows[i].style.display === 'none') continue;
                    var c = rowCheck(allRows[i]);
                    if (c) { c.checked = state; paintRow(allRows[i]); }
                }
            } else {
                check.checked = !check.checked;
                paintRow(row);
            }
            lastClicked = row;
            refreshCount();
        });
    });

    // Filter: hide rows not matching the text (matches filename, title,
    // suggestion — the whole row's text).
    var filter = document.getElementById('kop-filter');
    if (filter) {
        filter.addEventListener('input', function () {
            var q = filter.value.toLowerCase().trim();
            allRows.forEach(function (row) {
                row.style.display = (!q || row.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    function setVisible(state) {
        allRows.forEach(function (row) {
            if (row.style.display === 'none') return;
            var c = rowCheck(row);
            if (c) { c.checked = state; paintRow(row); }
        });
        refreshCount();
    }
    var tickBtn = document.getElementById('kop-tick-visible');
    var untickBtn = document.getElementById('kop-untick-visible');
    if (tickBtn) tickBtn.addEventListener('click', function () { setVisible(true); });
    if (untickBtn) untickBtn.addEventListener('click', function () { setVisible(false); });

    // Apply a folder {id, name} as the target of every ticked row.
    function targetTickedRows(id, name) {
        var ticked = allRows.filter(function (r) { var c = rowCheck(r); return c && c.checked; });
        ticked.forEach(function (row) {
            var input = row.querySelector('input[type=number]');
            var label = row.querySelector('.kop-picked-name');
            input.value = id;
            if (label) label.textContent = '→ ' + name;
        });
        if (ticked.length) {
            document.getElementById('kop-bulk-name').textContent =
                ticked.length + ' row(s) → ' + name + ' — review, then click Apply';
        }
        return ticked.length;
    }

    // New-folder inline form: [name] [parent picker] [Create]. Creates the
    // folder and targets the ticked rows at it.
    var nfParent = { id: 0, name: 'top level' };
    var nfParentBtn = document.getElementById('kop-nf-parent');
    var nfCreateBtn = document.getElementById('kop-nf-create');
    var nfNameInput = document.getElementById('kop-nf-name');
    if (nfParentBtn) {
        nfParentBtn.addEventListener('click', function () {
            if (!window.KOPFolderBrowser || typeof window.KOPFolderBrowser.open !== 'function') {
                alert('Folder browser failed to load.');
                return;
            }
            window.KOPFolderBrowser.open({ foldersUrl: foldersUrl, currentId: nfParent.id || '' })
                .then(function (res) {
                    if (!res) return; // cancelled
                    if (res.id === null) {
                        nfParent = { id: 0, name: 'top level' };
                    } else {
                        nfParent = { id: parseInt(res.id, 10), name: res.name };
                    }
                    nfParentBtn.textContent = '📁 parent: ' + nfParent.name;
                })
                .catch(function () { alert('Folder browser error.'); });
        });
    }
    if (nfCreateBtn) {
        nfCreateBtn.addEventListener('click', function () {
            var name = (nfNameInput.value || '').trim();
            if (!name) {
                alert('Type a name for the new folder first.');
                nfNameInput.focus();
                return;
            }
            nfCreateBtn.disabled = true;
            fetch(foldersAdminApi, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'create', name: name, parent: nfParent.id })
            }).then(function (r) { return r.json(); }).then(function (data) {
                nfCreateBtn.disabled = false;
                if (!data || !data.success || !data.id) {
                    throw new Error((data && data.error) || 'Could not create folder.');
                }
                var fullName = (nfParent.id ? nfParent.name + ' / ' : '') + name;
                var n = targetTickedRows(data.id, fullName);
                document.getElementById('kop-bulk-name').textContent =
                    'Created “' + fullName + '” (#' + data.id + ')' +
                    (n ? ' — ' + n + ' ticked row(s) targeted; review, then click Apply'
                       : ' — tick rows and use the bulk button, or 📁 pick it per row');
                nfNameInput.value = '';
            }).catch(function (err) {
                nfCreateBtn.disabled = false;
                alert('New folder failed: ' + (err && err.message ? err.message : 'unknown error'));
            });
        });
    }

    // Bulk move: pick one folder, set it as the target for every TICKED row.
    var bulkBtn = document.getElementById('kop-bulk-pick');
    if (bulkBtn) {
        bulkBtn.addEventListener('click', function () {
            if (!window.KOPFolderBrowser || typeof window.KOPFolderBrowser.open !== 'function') {
                alert('Folder browser failed to load.');
                return;
            }
            var ticked = Array.prototype.filter.call(
                document.querySelectorAll('input[name$="[go]"]'),
                function (c) { return c.checked; }
            );
            if (!ticked.length) {
                alert('Tick the rows you want to move first (or use tick-all).');
                return;
            }
            window.KOPFolderBrowser.open({ foldersUrl: foldersUrl })
                .then(function (res) {
                    if (!res || res.id === null) return; // cancelled/cleared
                    ticked.forEach(function (check) {
                        var row = check.closest('tr');
                        var input = row.querySelector('input[type=number]');
                        var label = row.querySelector('.kop-picked-name');
                        input.value = res.id;
                        if (label) label.textContent = '→ ' + res.name;
                    });
                    document.getElementById('kop-bulk-name').textContent =
                        ticked.length + ' row(s) → ' + res.name + ' — review, then click Apply';
                })
                .catch(function () { alert('Folder browser error.'); });
        });
    }
})();
</script>
</body></html>
