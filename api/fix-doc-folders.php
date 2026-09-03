<?php
/**
 * Fix document folders — admin tool that repairs facility records whose
 * stored FileBird folder ID points at a folder that no longer exists.
 *
 * Folder merges/consolidation delete folder IDs, but facilities_master rows
 * keep the old documentFolderId (stored by the wiki program picker at the
 * json_data root, under data, and on nested facilities[] entries). Cards
 * carrying a dead ID render an empty "Documents (0)" library.
 *
 * This tool scans every row's json_data for documentFolderId values missing
 * from the live folder table, suggests a replacement matched by name
 * (folder merges keep the surviving folder's name), and rewrites the JSON
 * for the rows you tick. Leaving a target empty CLEARS the stored ID so the
 * frontends fall back to fuzzy name matching.
 *
 * GET previews; nothing is written until Apply. Admin-only.
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

header('Content-Type: text/html; charset=utf-8');

/** Normalize a name for matching: lowercase, letters/digits only, spaced. */
function kop_fdf_norm($text) {
    $t = mb_strtolower((string) $text);
    $t = html_entity_decode($t, ENT_QUOTES);
    $t = str_replace(['-', '_', '.', '/'], ' ', $t);
    $t = preg_replace('/[^a-z0-9\s]/u', ' ', $t);
    return preg_replace('/\s+/', ' ', trim($t));
}

// ---------------------------------------------------------------------------
// Live folders
// ---------------------------------------------------------------------------
$folders = $wpdb->get_results("SELECT id, name, parent FROM {$fbv} WHERE type = 0");
$by_id = [];
$by_norm = [];
foreach ($folders as $f) {
    $by_id[(int) $f->id] = $f;
    $norm = kop_fdf_norm($f->name);
    if ($norm !== '') {
        $by_norm[$norm][] = (int) $f->id;
    }
}

/**
 * Match a name against live folders: exact normalized equality first, then a
 * relaxed whole-word containment (either direction) that is only trusted when
 * it narrows to a single folder — top-level folders preferred, so "Newport"
 * finds "Newport Healthcare" and "Sequel" finds "Sequel TSI" rather than its
 * program subfolders. Returns [folder_id|null, ambiguous].
 */
function kop_fdf_match($try, $by_norm, $by_id) {
    $norm = kop_fdf_norm($try);
    if ($norm === '') {
        return [null, false];
    }
    if (isset($by_norm[$norm])) {
        return [$by_norm[$norm][0], count($by_norm[$norm]) > 1];
    }
    $pattern = '/\b' . preg_quote($norm, '/') . '\b/';
    $candidates = [];
    foreach ($by_norm as $fnorm => $ids) {
        if (preg_match($pattern, $fnorm) || preg_match('/\b' . preg_quote($fnorm, '/') . '\b/', $norm)) {
            foreach ($ids as $id) {
                $candidates[] = $id;
            }
        }
    }
    if (!$candidates) {
        return [null, false];
    }
    $top_level = array_values(array_filter($candidates, static function ($id) use ($by_id) {
        return isset($by_id[$id]) && (int) $by_id[$id]->parent === 0;
    }));
    $pool = $top_level ?: $candidates;
    if (count($pool) === 1) {
        return [$pool[0], count($candidates) > 1];
    }
    return [null, false];
}

function kop_fdf_path($fid, $by_id, $depth = 0) {
    if ($depth > 10 || !isset($by_id[(int) $fid])) {
        return '';
    }
    $f = $by_id[(int) $fid];
    $prefix = ((int) $f->parent !== 0) ? kop_fdf_path($f->parent, $by_id, $depth + 1) : '';
    return ($prefix !== '' ? $prefix . ' / ' : '') . $f->name;
}

/** Best name-ish label for the object holding a documentFolderId. */
function kop_fdf_ctx_name($node) {
    if (!is_array($node)) {
        return '';
    }
    $candidates = [
        $node['identification']['currentName'] ?? '',
        $node['identification']['name'] ?? '',
        $node['identity']['currentName'] ?? '',
        $node['identity']['name'] ?? '',
        $node['currentName'] ?? '',
        $node['name'] ?? '',
    ];
    foreach ($candidates as $c) {
        if (is_string($c) && trim($c) !== '') {
            return trim($c);
        }
    }
    return '';
}

/**
 * Walk json_data collecting every documentFolderId occurrence:
 * [{path, id, ctx}] where path is a human label, ctx the owning object name.
 */
function kop_fdf_scan($node, $path = 'root') {
    $out = [];
    if (!is_array($node)) {
        return $out;
    }
    if (isset($node['documentFolderId']) && (int) $node['documentFolderId'] > 0) {
        $out[] = ['path' => $path, 'id' => (int) $node['documentFolderId'], 'ctx' => kop_fdf_ctx_name($node)];
    }
    foreach ($node as $key => $value) {
        if (!is_array($value)) {
            continue;
        }
        $out = array_merge($out, kop_fdf_scan($value, $path === 'root' ? (string) $key : $path . '.' . $key));
    }
    return $out;
}

/** Replace documentFolderId === $old everywhere in the structure. New id 0 clears it. */
function kop_fdf_replace(&$node, $old, $new, &$changed) {
    if (!is_array($node)) {
        return;
    }
    if (isset($node['documentFolderId']) && (int) $node['documentFolderId'] === (int) $old) {
        if ((int) $new > 0) {
            $node['documentFolderId'] = (int) $new;
        } else {
            unset($node['documentFolderId']);
        }
        $changed++;
    }
    foreach ($node as $key => &$value) {
        if (is_array($value)) {
            kop_fdf_replace($value, $old, $new, $changed);
        }
    }
    unset($value);
}

// ---------------------------------------------------------------------------
// Apply
// ---------------------------------------------------------------------------
$applied = false;
$apply_log = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['do_apply'])
    && check_admin_referer('kop_fdf_apply')) {

    $requests = isset($_POST['ref']) && is_array($_POST['ref']) ? $_POST['ref'] : [];
    // Group ticked refs by row so each row is rewritten once.
    $by_row = [];
    foreach ($requests as $req) {
        if (empty($req['go'])) {
            continue;
        }
        $row_name = (string) ($req['row'] ?? '');
        $old = (int) ($req['old'] ?? 0);
        $new = (int) ($req['target'] ?? 0); // 0 = clear
        if ($row_name === '' || $old <= 0) {
            continue;
        }
        if ($new > 0 && !isset($by_id[$new])) {
            $apply_log[] = "SKIPPED {$row_name} #{$old}: target folder {$new} does not exist.";
            continue;
        }
        $by_row[$row_name][] = ['old' => $old, 'new' => $new];
    }

    $rows_done = 0;
    $refs_done = 0;
    foreach ($by_row as $row_name => $changes) {
        $stmt = $pdo->prepare("SELECT json_data FROM facilities_master WHERE unique_name = ? LIMIT 1");
        $stmt->execute([$row_name]);
        $json_text = $stmt->fetchColumn();
        if ($json_text === false) {
            $apply_log[] = "SKIPPED {$row_name}: row not found.";
            continue;
        }
        $project = json_decode($json_text ?: '{}', true);
        if (!is_array($project)) {
            $apply_log[] = "SKIPPED {$row_name}: json_data did not decode.";
            continue;
        }
        $changed = 0;
        foreach ($changes as $c) {
            kop_fdf_replace($project, $c['old'], $c['new'], $changed);
        }
        if ($changed === 0) {
            $apply_log[] = "SKIPPED {$row_name}: stored IDs changed since preview, nothing matched.";
            continue;
        }
        $upd = $pdo->prepare(
            "UPDATE facilities_master
             SET json_data = ?, updated_at = CURRENT_TIMESTAMP
             WHERE unique_name = ?"
        );
        $upd->execute([json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $row_name]);
        $rows_done++;
        $refs_done += $changed;
        foreach ($changes as $c) {
            $apply_log[] = "{$row_name}: #{$c['old']} " . ($c['new'] > 0 ? "-> #{$c['new']} (" . kop_fdf_path($c['new'], $by_id) . ")" : 'cleared (name matching takes over)');
        }
    }
    $applied = true;
    array_unshift($apply_log, "Updated {$refs_done} folder reference(s) across {$rows_done} row(s).");
}

// ---------------------------------------------------------------------------
// Scan all rows for dead references
// ---------------------------------------------------------------------------
$rows = $pdo->query("SELECT unique_name, json_data FROM facilities_master")->fetchAll();
$dead_refs = [];
$total_refs = 0;
foreach ($rows as $row) {
    $project = json_decode($row['json_data'] ?: '{}', true);
    if (!is_array($project)) {
        continue;
    }
    $refs = kop_fdf_scan($project);
    $total_refs += count($refs);
    // Root and data-level duplicates carry the same ID by design; collapse per
    // (row, id) so one tick fixes both copies.
    $seen = [];
    foreach ($refs as $ref) {
        if (isset($by_id[$ref['id']])) {
            continue;
        }
        $key = $row['unique_name'] . '|' . $ref['id'];
        if (isset($seen[$key])) {
            $seen[$key]['paths'][] = $ref['path'];
            if ($seen[$key]['ctx'] === '' && $ref['ctx'] !== '') {
                $seen[$key]['ctx'] = $ref['ctx'];
            }
            continue;
        }
        $seen[$key] = [
            'row' => $row['unique_name'],
            'old' => $ref['id'],
            'paths' => [$ref['path']],
            'ctx' => $ref['ctx'],
        ];
    }
    foreach ($seen as $ref) {
        // Suggest a live folder whose name matches the owning object. Only
        // record-root references may fall back to the row name — suggesting
        // the operator's folder for a nested facility would file the wrong
        // library on its card.
        $is_root_ref = true;
        foreach ($ref['paths'] as $p) {
            if ($p !== 'root' && $p !== 'data') {
                $is_root_ref = false;
                break;
            }
        }
        $names_to_try = $is_root_ref
            ? array_filter([$ref['ctx'], $ref['row']])
            : array_filter([$ref['ctx']]);
        $suggest = null;
        $ambiguous = false;
        foreach ($names_to_try as $try) {
            list($suggest, $ambiguous) = kop_fdf_match($try, $by_norm, $by_id);
            if ($suggest !== null) {
                break;
            }
        }
        $ref['suggest'] = $suggest;
        $ref['ambiguous'] = $ambiguous;
        $dead_refs[] = $ref;
    }
}
usort($dead_refs, static function ($a, $b) {
    return strcasecmp($a['row'], $b['row']) ?: ($a['old'] <=> $b['old']);
});
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Fix Document Folders</title>
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
.ok { color: #1b7e3c; } .warn { color: #b8860b; } .bad { color: #c0392b; }
button { background: #33A7B5; color: #fff; border: none; border-radius: 6px; padding: 7px 12px; font-weight: 700; font-size: 0.85rem; cursor: pointer; }
.log { background: #fff; border: 1px solid #ccc; padding: 10px 14px; font-family: monospace; font-size: 0.8rem; margin-bottom: 12px; }
.kop-toolbar { position: sticky; top: 0; z-index: 60; background: #F2EEDF; padding: 8px 0 6px; border-bottom: 2px solid #33A7B5; margin-bottom: 8px; box-shadow: 0 4px 8px rgba(0,4,53,0.08); }
.kop-toolbar .bar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 6px; }
input[type=number] { width: 84px; }
small.paths { color: #666; }
</style></head><body>
<h1>Fix Document Folders <small style="font-weight:400">&mdash; repair facility records pointing at deleted FileBird folders</small></h1>

<?php if ($applied): ?>
    <div class="log ok"><?php echo implode('<br>', array_map('esc_html', $apply_log)); ?> <a href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'], '?')); ?>">Rescan.</a></div>
<?php endif; ?>

<div class="log">
    Scanned <?php echo count($rows); ?> facilities_master row(s), <?php echo $total_refs; ?> stored folder reference(s) &mdash;
    <strong><?php echo count($dead_refs); ?></strong> point at folders that no longer exist.
    Suggestions are matched by name against the live folder tree.
    Leave a target empty to CLEAR the stored ID (the index pages then fall back to name matching).
    Nothing changes until Apply.
</div>

<?php if ($dead_refs): ?>
<form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
<?php wp_nonce_field('kop_fdf_apply'); ?>
<div class="kop-toolbar">
    <div class="bar">
        <span><span id="kop-count"></span></span>
        <button type="button" id="kop-tick-suggested" style="background:#000080">Tick rows with a suggestion</button>
        <button type="button" id="kop-tick-nomatch" style="background:#b8860b">Tick no-match rows (clears their stored ID)</button>
        <button type="button" id="kop-untick-all" style="background:#7a7a7a">Untick all</button>
        <button type="submit" name="do_apply" value="1" style="background:#1b7e3c"
            onclick="var n=document.querySelectorAll('tbody input[name$=&quot;[go]&quot;]:checked').length; if(!n){alert('Tick the rows to fix first.');return false;} return window.confirm('Rewrite the folder ID on '+n+' reference(s)?');">
            Apply fixes</button>
        <small>row-click ticks &middot; empty target = clear the stored ID</small>
    </div>
</div>
<table><thead><tr><th></th><th>Program row</th><th>Stored on</th><th>Dead ID</th><th>Suggested live folder</th><th>New folder ID</th></tr></thead><tbody>
<?php foreach ($dead_refs as $i => $ref): ?>
    <tr>
        <td>
            <input type="checkbox" name="ref[<?php echo $i; ?>][go]" value="1" <?php echo $ref['suggest'] !== null ? 'checked' : ''; ?>>
            <input type="hidden" name="ref[<?php echo $i; ?>][row]" value="<?php echo esc_attr($ref['row']); ?>">
            <input type="hidden" name="ref[<?php echo $i; ?>][old]" value="<?php echo (int) $ref['old']; ?>">
        </td>
        <td><?php echo esc_html($ref['row']); ?></td>
        <td>
            <?php echo esc_html($ref['ctx'] !== '' ? $ref['ctx'] : '(record root)'); ?>
            <br><small class="paths"><?php echo esc_html(implode(', ', $ref['paths'])); ?></small>
        </td>
        <td>#<?php echo (int) $ref['old']; ?></td>
        <td>
            <?php if ($ref['suggest'] !== null): ?>
                <span class="ok">#<?php echo (int) $ref['suggest']; ?> <?php echo esc_html(kop_fdf_path($ref['suggest'], $by_id)); ?></span>
                <?php if ($ref['ambiguous']): ?><br><small class="warn">several folders share this name &mdash; check before applying</small><?php endif; ?>
            <?php else: ?>
                <span class="warn">no name match &mdash; pick one or leave empty to clear</span>
            <?php endif; ?>
        </td>
        <td>
            <input type="number" name="ref[<?php echo $i; ?>][target]" min="0"
                value="<?php echo $ref['suggest'] !== null ? (int) $ref['suggest'] : ''; ?>" placeholder="empty = clear">
            <button type="button" class="kop-pick" title="Browse folders">pick</button>
            <div class="kop-picked-name" style="font-size:0.78rem;color:#000080"></div>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table>
</form>
<?php else: ?>
<p class="ok">No dead folder references found &mdash; every stored documentFolderId matches a live folder.</p>
<?php endif; ?>

<link rel="stylesheet" href="<?php echo esc_url(get_stylesheet_directory_uri() . '/css/filebird-folder-browser.css'); ?>">
<script src="<?php echo esc_url(get_stylesheet_directory_uri() . '/js/filebird-folder-browser.js'); ?>"></script>
<script>
(function () {
    var foldersUrl = <?php echo wp_json_encode(rest_url('kop/v1/folders')); ?>;
    var rows = Array.prototype.slice.call(document.querySelectorAll('tbody tr'));
    function check(row) { return row.querySelector('input[name$="[go]"]'); }
    function paint(row) { var c = check(row); if (c) row.classList.toggle('kop-ticked', c.checked); }
    function refresh() {
        var n = rows.filter(function (r) { var c = check(r); return c && c.checked; }).length;
        var el = document.getElementById('kop-count');
        if (el) el.textContent = n + ' ticked.';
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
    var tickSug = document.getElementById('kop-tick-suggested');
    if (tickSug) tickSug.addEventListener('click', function () {
        rows.forEach(function (r) {
            var c = check(r);
            var t = r.querySelector('input[type=number]');
            if (c && t) { c.checked = t.value !== ''; paint(r); }
        });
        refresh();
    });
    var tickNoMatch = document.getElementById('kop-tick-nomatch');
    if (tickNoMatch) tickNoMatch.addEventListener('click', function () {
        rows.forEach(function (r) {
            var c = check(r);
            var t = r.querySelector('input[type=number]');
            if (c && t && t.value === '') { c.checked = true; paint(r); }
        });
        refresh();
    });
    var untick = document.getElementById('kop-untick-all');
    if (untick) untick.addEventListener('click', function () {
        rows.forEach(function (r) { var c = check(r); if (c) { c.checked = false; paint(r); } });
        refresh();
    });
    document.querySelectorAll('.kop-pick').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.KOPFolderBrowser || typeof window.KOPFolderBrowser.open !== 'function') {
                alert('Folder browser failed to load.');
                return;
            }
            var row = btn.closest('tr');
            var input = row.querySelector('input[type=number]');
            var label = row.querySelector('.kop-picked-name');
            window.KOPFolderBrowser.open({ foldersUrl: foldersUrl, currentId: input.value })
                .then(function (res) {
                    if (!res) return;
                    if (res.id === null) {
                        input.value = '';
                        if (label) label.textContent = '(will clear)';
                    } else {
                        input.value = res.id;
                        if (label) label.textContent = '-> ' + res.name;
                        var c = check(row);
                        if (c) c.checked = true;
                    }
                    paint(row);
                    refresh();
                })
                .catch(function () { alert('Folder browser error.'); });
        });
    });
})();
</script>
</body></html>
