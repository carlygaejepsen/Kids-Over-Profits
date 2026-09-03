<?php
/**
 * Link folders — admin tool for marking two FileBird folders as the SAME
 * facility under different names.
 *
 * The classic case: a program renamed by its operator, filed once under the
 * old brand and once under the new one — e.g. "Viewpoint Center" and "Aspen
 * Institute for Behavioral Assessment", each nested under its own parent org.
 * A link makes every facility document feed show the UNION of both folders'
 * contents (plus their same-name duplicates elsewhere in the tree), so files
 * filed under either name are findable under both. Links are transitive:
 * A-B plus B-C merges all three.
 *
 * Links live in the theme's own {prefix}kop_folder_links table — FileBird's
 * UI can neither see nor destroy them. Read by kop_get_linked_folder_ids() /
 * kop_get_equivalent_folder_ids() in inc/database.php, which feed the
 * facility doc tree and the kop/v1/folder-content REST path (merge=name).
 *
 * This is NAME equivalence for folders only. Physical-address identity is a
 * separate system (api/manage-addresses.php) — a shared address SUGGESTS a
 * link, but only an admin decision creates one here.
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
$tags_tbl = $wpdb->prefix . 'kop_media_folder_tags';
$links_tbl = $wpdb->prefix . 'kop_folder_links';

header('Content-Type: text/html; charset=utf-8');

if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fbv)) !== $fbv) {
    echo 'FileBird table not found (' . esc_html($fbv) . ').';
    exit;
}

// Idempotent. Rows are stored with folder_a < folder_b so a pair can only
// exist once regardless of the order it was picked in.
$wpdb->query("CREATE TABLE IF NOT EXISTS {$links_tbl} (
    folder_a BIGINT UNSIGNED NOT NULL,
    folder_b BIGINT UNSIGNED NOT NULL,
    note VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (folder_a, folder_b),
    KEY idx_b (folder_b)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ---------------------------------------------------------------------------
// Folder lookups
// ---------------------------------------------------------------------------
$folders = $wpdb->get_results("SELECT id, name, parent FROM {$fbv} WHERE type = 0");
$by_id = [];
foreach ($folders as $f) {
    $by_id[(int)$f->id] = $f;
}

/** Full "Parent / Child" path for a folder id. */
function kop_lf_path($fid, $by_id, $depth = 0) {
    if ($depth > 10 || !isset($by_id[(int)$fid])) {
        return '(deleted folder #' . (int)$fid . ')';
    }
    $f = $by_id[(int)$fid];
    $prefix = ((int)$f->parent !== 0) ? kop_lf_path($f->parent, $by_id, $depth + 1) . ' / ' : '';
    return $prefix . $f->name;
}

// Direct file counts (FileBird filings + theme tags) per folder.
$fcounts = [];
foreach ($wpdb->get_results("SELECT folder_id, COUNT(*) AS n FROM {$fbv_rel} GROUP BY folder_id") as $r) {
    $fcounts[(int)$r->folder_id] = (int)$r->n;
}
if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tags_tbl)) === $tags_tbl) {
    foreach ($wpdb->get_results("SELECT folder_id, COUNT(*) AS n FROM {$tags_tbl} GROUP BY folder_id") as $r) {
        $fcounts[(int)$r->folder_id] = ($fcounts[(int)$r->folder_id] ?? 0) + (int)$r->n;
    }
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
$log = [];
$log_ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('kop_lf_apply')) {
    $a = (int)($_POST['folder_a'] ?? 0);
    $b = (int)($_POST['folder_b'] ?? 0);

    if (isset($_POST['do_link'])) {
        $note = sanitize_text_field((string)($_POST['note'] ?? ''));
        if ($a <= 0 || $b <= 0 || !isset($by_id[$a]) || !isset($by_id[$b])) {
            $log[] = 'Pick two existing folders first.';
        } elseif ($a === $b) {
            $log[] = 'A folder cannot be linked to itself.';
        } elseif (strcasecmp(trim($by_id[$a]->name), trim($by_id[$b]->name)) === 0) {
            $log[] = 'Those folders share the same name — feeds already merge them automatically, no link needed.';
        } else {
            $lo = min($a, $b);
            $hi = max($a, $b);
            $ins = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$links_tbl} (folder_a, folder_b, note) VALUES (%d, %d, %s)",
                $lo, $hi, $note
            ));
            $log_ok = (bool)$ins;
            $log[] = $ins
                ? 'Linked "' . $by_id[$a]->name . '" and "' . $by_id[$b]->name . '" — both folders now show the merged contents in facility document feeds.'
                : 'Those folders are already linked.';
        }
    } elseif (isset($_POST['do_unlink'])) {
        $deleted = $wpdb->delete($links_tbl, ['folder_a' => min($a, $b), 'folder_b' => max($a, $b)], ['%d', '%d']);
        $log_ok = (bool)$deleted;
        $log[] = $deleted ? 'Link removed.' : 'That link no longer exists.';
    }
}

// ---------------------------------------------------------------------------
// Current links
// ---------------------------------------------------------------------------
$links = $wpdb->get_results("SELECT folder_a, folder_b, note, created_at FROM {$links_tbl} ORDER BY created_at DESC");

/** Total direct files across an equivalence group. */
function kop_lf_group_count($ids, $fcounts) {
    $n = 0;
    foreach ((array)$ids as $fid) {
        $n += $fcounts[(int)$fid] ?? 0;
    }
    return $n;
}
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Link Folders</title>
<style>
body { font-family: system-ui, sans-serif; margin: 24px; color: #000435; background: #F2EEDF; max-width: 1100px; }
h1 { font-size: 1.15rem; margin: 0 0 4px; }
h2 { font-size: 1rem; margin: 22px 0 8px; }
p.help { font-size: 0.85rem; color: #333; max-width: 850px; }
table { border-collapse: collapse; background: #fff; font-size: 0.84rem; width: 100%; }
th, td { border: 1px solid #ccc; padding: 5px 8px; text-align: left; vertical-align: top; }
th { background: #000080; color: #fff; }
.ok { color: #1b7e3c; } .warn { color: #b8860b; }
button { background: #33A7B5; color: #fff; border: none; border-radius: 6px; padding: 7px 12px; font-weight: 700; font-size: 0.85rem; cursor: pointer; }
button.pick { background: #000080; }
button.danger { background: #7a1f1f; padding: 4px 9px; font-size: 0.78rem; }
.log { background: #fff; border: 1px solid #ccc; padding: 10px 14px; font-family: monospace; font-size: 0.8rem; margin-bottom: 12px; }
.addbox { background: #fff; border: 2px solid #33A7B5; border-radius: 8px; padding: 12px 16px; margin-bottom: 8px; }
.addbox .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 8px; }
.addbox .picked { font-weight: 600; color: #000080; }
.addbox input[type=text] { padding: 6px 9px; border: 1px solid #000080; border-radius: 6px; width: 320px; }
.group { color: #555; font-size: 0.78rem; }
.cnt { color: #888; font-size: 0.78rem; }
a { color: #000080; }
</style></head><body>
<h1>Link Folders <small style="font-weight:400">&mdash; legacy and current names for the same facility</small></h1>
<p class="help">A link declares two folders to be the <strong>same facility</strong> under different
names (a rename, a rebrand, an operator change). Every facility document feed then shows the merged
contents under both folders &mdash; filing a new document under either name makes it appear in both,
with no per-file copying. Folders that share the exact same name are merged automatically and never
need a link. To manage which files are in which folder, use
<a href="sort-media.php">Sort Media</a>.</p>

<?php if ($log): ?>
    <div class="log <?php echo $log_ok ? 'ok' : 'warn'; ?>"><?php echo implode('<br>', array_map('esc_html', $log)); ?></div>
<?php endif; ?>

<h2>Add a link</h2>
<form method="post" class="addbox" id="kop-lf-form">
    <?php wp_nonce_field('kop_lf_apply'); ?>
    <input type="hidden" name="folder_a" id="kop-lf-a" value="">
    <input type="hidden" name="folder_b" id="kop-lf-b" value="">
    <div class="row">
        <button type="button" class="pick" id="kop-lf-pick-a">Pick folder A</button>
        <span class="picked" id="kop-lf-name-a">(none)</span>
    </div>
    <div class="row">
        <button type="button" class="pick" id="kop-lf-pick-b">Pick folder B</button>
        <span class="picked" id="kop-lf-name-b">(none)</span>
    </div>
    <div class="row">
        <input type="text" name="note" maxlength="255" placeholder="note, e.g. renamed 2014; same campus">
        <button type="submit" name="do_link" value="1"
            onclick="return window.confirm('Link these two folders as the same facility?\n\nFacility document feeds will show the merged contents under both names.');">
            Link folders</button>
    </div>
</form>

<h2>Existing links (<?php echo count($links); ?>)</h2>
<?php if (!$links): ?>
<p class="warn">No links yet.</p>
<?php else: ?>
<table><thead><tr><th>Folder A</th><th>Folder B</th><th>Merged group</th><th>Note</th><th>Created</th><th></th></tr></thead><tbody>
<?php foreach ($links as $l):
    $a = (int)$l->folder_a;
    $b = (int)$l->folder_b;
    $group_ids = function_exists('kop_get_equivalent_folder_ids') ? kop_get_equivalent_folder_ids($a) : [$a, $b];
    $group_names = [];
    foreach ($group_ids as $gid) {
        $group_names[] = esc_html(kop_lf_path($gid, $by_id))
            . ' <span class="cnt">(' . (int)($fcounts[$gid] ?? 0) . ')</span>';
    }
?>
    <tr>
        <td><?php echo esc_html(kop_lf_path($a, $by_id)); ?> <span class="cnt">#<?php echo $a; ?></span></td>
        <td><?php echo esc_html(kop_lf_path($b, $by_id)); ?> <span class="cnt">#<?php echo $b; ?></span></td>
        <td class="group"><?php echo implode('<br>', $group_names); ?>
            <br><strong><?php echo kop_lf_group_count($group_ids, $fcounts); ?> direct files merged</strong></td>
        <td><?php echo esc_html($l->note); ?></td>
        <td><?php echo esc_html($l->created_at); ?></td>
        <td>
            <form method="post" style="margin:0">
                <?php wp_nonce_field('kop_lf_apply'); ?>
                <input type="hidden" name="folder_a" value="<?php echo $a; ?>">
                <input type="hidden" name="folder_b" value="<?php echo $b; ?>">
                <button type="submit" name="do_unlink" value="1" class="danger"
                    onclick="return window.confirm('Remove this link? The folders keep their own files; feeds stop merging them.');">
                    Unlink</button>
            </form>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<link rel="stylesheet" href="<?php echo esc_url(get_stylesheet_directory_uri() . '/css/filebird-folder-browser.css'); ?>">
<script src="<?php echo esc_url(get_stylesheet_directory_uri() . '/js/filebird-folder-browser.js'); ?>"></script>
<script>
(function () {
    var foldersUrl = <?php echo wp_json_encode(rest_url('kop/v1/folders')); ?>;

    function wirePicker(btnId, inputId, nameId) {
        var btn = document.getElementById(btnId);
        if (!btn) return;
        btn.addEventListener('click', function () {
            if (!window.KOPFolderBrowser || typeof window.KOPFolderBrowser.open !== 'function') {
                alert('Folder browser failed to load.');
                return;
            }
            var input = document.getElementById(inputId);
            window.KOPFolderBrowser.open({ foldersUrl: foldersUrl, currentId: input.value })
                .then(function (res) {
                    if (!res) return;
                    var label = document.getElementById(nameId);
                    if (res.id === null) {
                        input.value = '';
                        label.textContent = '(none)';
                    } else {
                        input.value = res.id;
                        label.textContent = res.name + ' (#' + res.id + ')';
                    }
                })
                .catch(function () { alert('Folder browser error.'); });
        });
    }
    wirePicker('kop-lf-pick-a', 'kop-lf-a', 'kop-lf-name-a');
    wirePicker('kop-lf-pick-b', 'kop-lf-b', 'kop-lf-name-b');
})();
</script>
</body></html>
