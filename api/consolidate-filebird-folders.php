<?php
/**
 * Consolidate FileBird folders — admin maintenance tool.
 *
 * Targets ORPHANED folder subtrees: folders whose parent id no longer exists
 * in the fbv table (e.g. the ghost tree left behind when a parent folder was
 * deleted — 118 folders pointed at missing id 1692 when this was written).
 * These orphans are invisible in FileBird's UI but still surface through the
 * REST folder feed, duplicating the real tree.
 *
 * For every orphaned folder this tool:
 *   1. Finds the surviving real folder with the same (normalized) name.
 *   2. Moves any attachments to the survivor (skips the folder when no
 *      unambiguous survivor exists — flagged for manual review).
 *   3. Remaps facilities_master json_data documentFolderId references that
 *      point into the orphan tree.
 *   4. Deletes the emptied orphan folders.
 *
 * GET  = dry run (report only, default).
 * POST + nonce + confirm=1 = execute, inside a transaction.
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

if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fbv)) !== $fbv) {
    echo 'FileBird table not found (' . esc_html($fbv) . ').';
    exit;
}

/** Normalize a folder name for duplicate matching. */
function kop_cff_norm($name) {
    $n = mb_strtolower(trim((string)$name));
    $n = html_entity_decode($n, ENT_QUOTES);
    $n = preg_replace('/[^a-z0-9\s]/u', ' ', $n);
    return preg_replace('/\s+/', ' ', trim($n));
}

// ---------------------------------------------------------------------------
// Build the analysis (shared by dry run and execute).
// ---------------------------------------------------------------------------
$folders = $wpdb->get_results("SELECT id, name, parent FROM {$fbv} WHERE type = 0");
$by_id = [];
foreach ($folders as $f) {
    $by_id[(int)$f->id] = $f;
}

// Orphan roots: parent set but missing. Their whole subtrees are orphaned.
$orphan_set = [];
foreach ($folders as $f) {
    $parent = (int)$f->parent;
    if ($parent !== 0 && !isset($by_id[$parent])) {
        $orphan_set[(int)$f->id] = true;
    }
}
// Descend: children of orphans are orphans too (repeat until stable).
do {
    $grew = false;
    foreach ($folders as $f) {
        $id = (int)$f->id;
        $parent = (int)$f->parent;
        if (!isset($orphan_set[$id]) && isset($orphan_set[$parent])) {
            $orphan_set[$id] = true;
            $grew = true;
        }
    }
} while ($grew);

$orphan_ids = array_keys($orphan_set);

// Survivors indexed by normalized name (non-orphan folders only).
$survivors_by_name = [];
foreach ($folders as $f) {
    $id = (int)$f->id;
    if (isset($orphan_set[$id])) {
        continue;
    }
    $survivors_by_name[kop_cff_norm($f->name)][] = $id;
}

// Attachment counts for orphan folders (direct relations only).
$attach_counts = [];
if ($orphan_ids) {
    $in = implode(',', array_map('intval', $orphan_ids));
    $rows = $wpdb->get_results(
        "SELECT folder_id, COUNT(*) AS n FROM {$fbv_rel} WHERE folder_id IN ($in) GROUP BY folder_id"
    );
    foreach ($rows as $r) {
        $attach_counts[(int)$r->folder_id] = (int)$r->n;
    }
}

// facilities_master documentFolderId references into the orphan set.
$facility_refs = []; // orphan_id => [unique_name, ...]
$fac_rows = $wpdb->get_results(
    "SELECT unique_name, json_data FROM facilities_master
     WHERE json_data LIKE '%documentFolderId%'"
);
foreach ($fac_rows as $fr) {
    $decoded = json_decode($fr->json_data, true);
    if (!is_array($decoded)) {
        continue;
    }
    $ref_ids = [];
    if (!empty($decoded['documentFolderId'])) {
        $ref_ids[] = (int)$decoded['documentFolderId'];
    }
    if (!empty($decoded['data']['documentFolderId'])) {
        $ref_ids[] = (int)$decoded['data']['documentFolderId'];
    }
    foreach (array_unique($ref_ids) as $rid) {
        if (isset($orphan_set[$rid])) {
            $facility_refs[$rid][] = $fr->unique_name;
        }
    }
}

// Build the per-folder plan.
$plan = []; // id => [name, files, survivor_id|null, ambiguous(bool), facilities[]]
foreach ($orphan_ids as $oid) {
    $name = isset($by_id[$oid]) ? $by_id[$oid]->name : ('#' . $oid);
    $norm = kop_cff_norm($name);
    $candidates = isset($survivors_by_name[$norm]) ? $survivors_by_name[$norm] : [];
    $files = isset($attach_counts[$oid]) ? $attach_counts[$oid] : 0;
    $needs_home = $files > 0 || isset($facility_refs[$oid]);
    $plan[$oid] = [
        'name'       => $name,
        'files'      => $files,
        'survivor'   => count($candidates) === 1 ? $candidates[0] : null,
        'ambiguous'  => count($candidates) > 1,
        'no_match'   => count($candidates) === 0 && $needs_home,
        'facilities' => isset($facility_refs[$oid]) ? $facility_refs[$oid] : [],
    ];
}

// A folder is deletable if it needs no home (no files, no refs) or has an
// unambiguous survivor to take them. Folders flagged ambiguous/no_match with
// content are kept, and so are their ancestors (to avoid re-orphaning).
$keep = [];
foreach ($plan as $oid => $p) {
    if (($p['files'] > 0 || $p['facilities']) && $p['survivor'] === null) {
        $keep[$oid] = true;
    }
}
// Keep ancestors of kept folders.
do {
    $grew = false;
    foreach (array_keys($keep) as $kid) {
        $parent = isset($by_id[$kid]) ? (int)$by_id[$kid]->parent : 0;
        if ($parent && isset($orphan_set[$parent]) && !isset($keep[$parent])) {
            $keep[$parent] = true;
            $grew = true;
        }
    }
} while ($grew);

$to_delete = array_values(array_diff($orphan_ids, array_keys($keep)));
$moves = [];   // orphan_id => survivor_id (attachment moves)
$remaps = [];  // orphan_id => survivor_id (facility ref remaps)
foreach ($plan as $oid => $p) {
    if ($p['survivor'] !== null && $p['files'] > 0) {
        $moves[$oid] = $p['survivor'];
    }
    if ($p['survivor'] !== null && $p['facilities']) {
        $remaps[$oid] = $p['survivor'];
    }
}

// ---------------------------------------------------------------------------
// Execute?
// ---------------------------------------------------------------------------
$executed = false;
$exec_log = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['confirm'])
    && $_POST['confirm'] === '1'
    && check_admin_referer('kop_cff_execute')) {

    $wpdb->query('START TRANSACTION');
    try {
        // 1. Move attachments (dedupe against relations already on the survivor).
        foreach ($moves as $oid => $sid) {
            $wpdb->query($wpdb->prepare(
                "DELETE af FROM {$fbv_rel} af
                 JOIN {$fbv_rel} s ON s.attachment_id = af.attachment_id AND s.folder_id = %d
                 WHERE af.folder_id = %d",
                $sid, $oid
            ));
            $moved = $wpdb->query($wpdb->prepare(
                "UPDATE {$fbv_rel} SET folder_id = %d WHERE folder_id = %d",
                $sid, $oid
            ));
            $exec_log[] = "Moved {$moved} attachment(s): folder {$oid} → {$sid}";
        }

        // 2. Remap facilities_master documentFolderId references.
        foreach ($remaps as $oid => $sid) {
            foreach ($facility_refs[$oid] as $uname) {
                $json = $wpdb->get_var($wpdb->prepare(
                    'SELECT json_data FROM facilities_master WHERE unique_name = %s', $uname
                ));
                $decoded = json_decode($json, true);
                if (!is_array($decoded)) {
                    continue;
                }
                if ((int)($decoded['documentFolderId'] ?? 0) === $oid) {
                    $decoded['documentFolderId'] = $sid;
                }
                if ((int)($decoded['data']['documentFolderId'] ?? 0) === $oid) {
                    $decoded['data']['documentFolderId'] = $sid;
                }
                $wpdb->update(
                    'facilities_master',
                    ['json_data' => wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                    ['unique_name' => $uname]
                );
                $exec_log[] = "Remapped documentFolderId {$oid} → {$sid} on “{$uname}”";
            }
        }

        // 3. Delete the emptied orphan folders (and any stray relations).
        if ($to_delete) {
            $in = implode(',', array_map('intval', $to_delete));
            $wpdb->query("DELETE FROM {$fbv_rel} WHERE folder_id IN ($in)");
            $deleted = $wpdb->query("DELETE FROM {$fbv} WHERE id IN ($in)");
            $exec_log[] = "Deleted {$deleted} orphaned folder(s)";
        }

        $wpdb->query('COMMIT');
        $executed = true;
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        $exec_log[] = 'FAILED — rolled back: ' . $e->getMessage();
        error_log('consolidate-filebird-folders failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Consolidate FileBird Folders</title>
<style>
body { font-family: system-ui, sans-serif; margin: 24px; color: #000435; background: #F2EEDF; }
h1 { font-size: 1.3rem; } h2 { font-size: 1.05rem; margin-top: 24px; }
table { border-collapse: collapse; background: #fff; font-size: 0.85rem; }
th, td { border: 1px solid #ccc; padding: 5px 9px; text-align: left; vertical-align: top; }
th { background: #000080; color: #fff; }
.ok { color: #1b7e3c; } .warn { color: #b8860b; } .bad { color: #c0392b; }
.summary { background: #FFF5CB; border: 2px solid #33A7B5; border-radius: 8px; padding: 12px 16px; max-width: 720px; }
button { background: #c0392b; color: #fff; border: none; border-radius: 6px; padding: 10px 18px; font-weight: 700; cursor: pointer; }
.log { background: #fff; border: 1px solid #ccc; padding: 10px 14px; font-family: monospace; font-size: 0.8rem; }
</style></head><body>
<h1>Consolidate FileBird Folders</h1>

<?php if ($executed): ?>
    <h2 class="ok">✅ Executed</h2>
    <div class="log"><?php echo implode('<br>', array_map('esc_html', $exec_log)); ?></div>
    <p><a href="">Reload for a fresh dry run</a> — remaining orphans (if any) are ones kept for manual review.</p>
<?php elseif ($exec_log): ?>
    <h2 class="bad">❌ Failed (rolled back)</h2>
    <div class="log"><?php echo implode('<br>', array_map('esc_html', $exec_log)); ?></div>
<?php endif; ?>

<div class="summary">
    <strong>Dry-run summary</strong><br>
    Total folders: <?php echo count($folders); ?><br>
    Orphaned (parent no longer exists): <strong><?php echo count($orphan_ids); ?></strong><br>
    Attachments to move to surviving folders: <strong><?php echo array_sum(array_map(static fn($o) => $plan[$o]['files'], array_keys($moves))); ?></strong> (from <?php echo count($moves); ?> folder(s))<br>
    Program records to remap: <strong><?php echo array_sum(array_map('count', array_column($plan, 'facilities'))); ?></strong><br>
    Folders to delete: <strong><?php echo count($to_delete); ?></strong><br>
    Kept for manual review (content but no clear match): <strong><?php echo count($keep); ?></strong>
</div>

<?php if ($orphan_ids && !$executed): ?>
<form method="post" style="margin:16px 0">
    <?php wp_nonce_field('kop_cff_execute'); ?>
    <input type="hidden" name="confirm" value="1">
    <button type="submit" onclick="return window.confirm('Execute the consolidation shown below? This modifies the live database (inside a transaction).');">
        ⚠️ Execute consolidation
    </button>
</form>
<?php endif; ?>

<h2>Orphaned folders</h2>
<table><thead><tr><th>ID</th><th>Name</th><th>Files</th><th>Referenced by programs</th><th>Action</th></tr></thead><tbody>
<?php foreach ($plan as $oid => $p):
    if ($p['survivor'] !== null) {
        $sname = isset($by_id[$p['survivor']]) ? $by_id[$p['survivor']]->name : '';
        $action = ($p['files'] || $p['facilities'])
            ? "<span class='ok'>merge into #{$p['survivor']} “" . esc_html($sname) . '”, then delete</span>'
            : "<span class='ok'>delete (empty; survivor #{$p['survivor']} exists)</span>";
    } elseif (isset($keep[$oid])) {
        $action = $p['ambiguous']
            ? "<span class='warn'>KEEP — multiple same-name survivors, review manually</span>"
            : "<span class='warn'>KEEP — has content but no surviving folder with this name</span>";
    } else {
        $action = "<span class='ok'>delete (empty)</span>";
    }
?>
    <tr>
        <td><?php echo (int)$oid; ?></td>
        <td><?php echo esc_html($p['name']); ?></td>
        <td><?php echo (int)$p['files']; ?></td>
        <td><?php echo esc_html(implode(', ', $p['facilities'])); ?></td>
        <td><?php echo $action; ?></td>
    </tr>
<?php endforeach; ?>
</tbody></table>
</body></html>
