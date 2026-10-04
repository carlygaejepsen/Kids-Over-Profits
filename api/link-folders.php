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
require_once __DIR__ . '/facility-aliases.php';

if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not authorized. Log in to WordPress as an administrator first.';
    exit;
}

global $wpdb;
// The link, dismissal and suggestion logic lives in inc/folder-links.php, shared
// with the review inbox (inc/review-inbox/folder-links.php).
require_once get_stylesheet_directory() . '/inc/folder-links.php';
$kop_lf_tables = kop_folder_links_tables();
$fbv = $kop_lf_tables['fbv'];

header('Content-Type: text/html; charset=utf-8');

if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $fbv)) !== $fbv) {
    echo 'FileBird table not found (' . esc_html($fbv) . ').';
    exit;
}

kop_folder_links_install();

// ---------------------------------------------------------------------------
// Folder lookups
// ---------------------------------------------------------------------------
$by_id = kop_folder_links_folders();
$folders = array_values($by_id);

/** Full "Parent / Child" path for a folder id. */
function kop_lf_path($fid, $by_id, $depth = 0) {
    return kop_folder_links_path($fid, $by_id, $depth);
}

function kop_lf_pair_key($a, $b) {
    return kop_folder_links_pair_key($a, $b);
}

// Direct file counts (FileBird filings + theme tags) per folder.
$fcounts = kop_folder_links_file_counts();

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
$log = [];
$log_ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('kop_lf_apply')) {
    $a = (int)($_POST['folder_a'] ?? 0);
    $b = (int)($_POST['folder_b'] ?? 0);

    if (isset($_POST['do_link'])) {
        // Any number of folders (folder_ids[]); the old two-field form still works.
        $picked = array_map('intval', (array)($_POST['folder_ids'] ?? []));
        if (!$picked && $a > 0 && $b > 0) $picked = [$a, $b];
        $res = kop_folder_links_link($picked, (string)($_POST['note'] ?? ''), $by_id);
        $log_ok = $res['ok'];
        $log = array_merge($log, $res['messages']);
    } elseif (isset($_POST['do_dismiss'])) {
        $res = kop_folder_links_dismiss($a, $b, $by_id);
        $log_ok = $res['ok'];
        $log[] = $res['message'];
    } elseif (isset($_POST['do_restore_dismissal'])) {
        $res = kop_folder_links_restore($a, $b);
        $log_ok = $res['ok'];
        $log[] = $res['message'];
    } elseif (isset($_POST['do_unlink'])) {
        $res = kop_folder_links_unlink($a, $b);
        $log_ok = $res['ok'];
        $log[] = $res['message'];
    }
}

// ---------------------------------------------------------------------------
// Current links
// ---------------------------------------------------------------------------
$links = kop_folder_links_rows('links');
$dismissals = kop_folder_links_rows('dismissals');

// Suggest pairs whose folder names are known names or aliases of one facility.
$suggestions = kop_folder_links_suggestions($by_id, 100);

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
button { background: #24757F; color: #fff; border: none; border-radius: 6px; padding: 7px 12px; font-weight: 700; font-size: 0.85rem; cursor: pointer; }
button.pick { background: #000080; }
button.danger { background: #7a1f1f; padding: 4px 9px; font-size: 0.78rem; }
.log { background: #fff; border: 1px solid #ccc; padding: 10px 14px; font-family: monospace; font-size: 0.8rem; margin-bottom: 12px; }
.addbox { background: #fff; border: 2px solid #33A7B5; border-radius: 8px; padding: 12px 16px; margin-bottom: 8px; }
.addbox .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 8px; }
.addbox .picked { font-weight: 600; color: #000080; }
.addbox .picked-list { list-style: none; margin: 0 0 8px; padding: 0; }
.addbox .picked-list li { display: flex; gap: 10px; align-items: center; padding: 4px 0; font-weight: 600; color: #000080; }
.addbox .picked-list li .cnt { font-weight: 400; }
.addbox input[type=text] { padding: 6px 9px; border: 1px solid #000080; border-radius: 6px; width: 320px; }
.group { color: #555; font-size: 0.78rem; }
.cnt { color: #4A5568; font-size: 0.78rem; }
a { color: #000080; }
</style></head><body>
<h1>Link Folders <small style="font-weight:400">&mdash; legacy and current names for the same facility</small></h1>
<p class="help">A link declares two or more folders to be the <strong>same facility</strong> under different
names (a rename, a rebrand, an operator change). Every facility document feed then shows the merged
contents under every linked folder &mdash; filing a new document under any of the names makes it appear in all,
with no per-file copying. Links are stored as pairs and merged transitively, so linking three folders at
once creates a single group. Folders that share the exact same name are merged automatically and never
need a link. To manage which files are in which folder, use
<a href="sort-media.php">Sort Media</a>.</p>

<?php if ($log): ?>
    <div class="log <?php echo $log_ok ? 'ok' : 'warn'; ?>"><?php echo implode('<br>', array_map('esc_html', $log)); ?></div>
<?php endif; ?>

<h2>Add a link</h2>
<form method="post" class="addbox" id="kop-lf-form">
    <?php wp_nonce_field('kop_lf_apply'); ?>
    <ul class="picked-list" id="kop-lf-list"></ul>
    <div class="row">
        <button type="button" class="pick" id="kop-lf-add">Add folder</button>
        <span class="picked" id="kop-lf-empty">(pick two or more folders)</span>
    </div>
    <div class="row">
        <input type="text" name="note" maxlength="255" placeholder="note, e.g. renamed 2014; same campus">
        <button type="submit" name="do_link" value="1" id="kop-lf-submit">Link folders</button>
    </div>
</form>

<?php if ($suggestions): ?>
<h2>Suggested links (<?php echo count($suggestions); ?>)</h2>
<p class="help">These pairs match two known names or aliases for the same facility. Review each one before linking. If the names only happen to be related or are distinct programs, mark the pair as an alternate name to remove it without merging their document feeds.</p>
<table><thead><tr><th>Folders</th><th>Known as</th><th>Basis</th><th></th></tr></thead><tbody>
<?php foreach ($suggestions as $suggestion): ?>
    <tr>
        <td><?php echo esc_html($suggestion['a_name']); ?> <span class="cnt">#<?php echo (int)$suggestion['a']; ?></span><br>
            <?php echo esc_html($suggestion['b_name']); ?> <span class="cnt">#<?php echo (int)$suggestion['b']; ?></span></td>
        <td class="group"><?php echo esc_html(implode(' / ', $suggestion['matched_names'])); ?></td>
        <td><?php echo esc_html($suggestion['basis']); ?></td>
        <td><button type="button" class="use-suggestion" data-a="<?php echo (int)$suggestion['a']; ?>"
            data-b="<?php echo (int)$suggestion['b']; ?>" data-a-name="<?php echo esc_attr($suggestion['a_name']); ?>"
            data-b-name="<?php echo esc_attr($suggestion['b_name']); ?>">Use</button>
            <form method="post" style="display:inline;margin:0 0 0 4px">
                <?php wp_nonce_field('kop_lf_apply'); ?>
                <input type="hidden" name="folder_a" value="<?php echo (int)$suggestion['a']; ?>">
                <input type="hidden" name="folder_b" value="<?php echo (int)$suggestion['b']; ?>">
                <button type="submit" name="do_dismiss" value="1" class="danger"
                    onclick="return window.confirm('Mark these as alternate names, not a rebrand? They will stay separate and leave the suggestion list.');">
                    Alternate name</button>
            </form>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<h2>Dismissed alternate names (<?php echo count($dismissals); ?>)</h2>
<?php if (!$dismissals): ?>
<p class="warn">No alternate-name dismissals.</p>
<?php else: ?>
<table><thead><tr><th>Folders</th><th>Dismissed</th><th></th></tr></thead><tbody>
<?php foreach ($dismissals as $dismissal):
    $a = (int)$dismissal->folder_a;
    $b = (int)$dismissal->folder_b;
?>
    <tr>
        <td><?php echo esc_html(kop_lf_path($a, $by_id)); ?><br><?php echo esc_html(kop_lf_path($b, $by_id)); ?></td>
        <td><?php echo esc_html($dismissal->created_at); ?></td>
        <td>
            <form method="post" style="margin:0">
                <?php wp_nonce_field('kop_lf_apply'); ?>
                <input type="hidden" name="folder_a" value="<?php echo $a; ?>">
                <input type="hidden" name="folder_b" value="<?php echo $b; ?>">
                <button type="submit" name="do_restore_dismissal" value="1" class="danger">Restore</button>
            </form>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>

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

    var list = document.getElementById('kop-lf-list');
    var empty = document.getElementById('kop-lf-empty');
    var form = document.getElementById('kop-lf-form');

    function pickedIds() {
        return Array.prototype.map.call(list.querySelectorAll('input[name="folder_ids[]"]'), function (i) { return i.value; });
    }
    function refresh() {
        empty.hidden = list.children.length > 0;
    }
    function addFolder(id, name) {
        id = String(id);
        if (pickedIds().indexOf(id) !== -1) return;
        var li = document.createElement('li');
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'folder_ids[]';
        input.value = id;
        var label = document.createElement('span');
        label.textContent = name + ' ';
        var cnt = document.createElement('span');
        cnt.className = 'cnt';
        cnt.textContent = '#' + id;
        label.appendChild(cnt);
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'danger';
        remove.textContent = 'Remove';
        remove.addEventListener('click', function () { li.remove(); refresh(); });
        li.appendChild(input);
        li.appendChild(label);
        li.appendChild(remove);
        list.appendChild(li);
        refresh();
    }
    function clearFolders() {
        list.innerHTML = '';
        refresh();
    }

    document.getElementById('kop-lf-add').addEventListener('click', function () {
        if (!window.KOPFolderBrowser || typeof window.KOPFolderBrowser.open !== 'function') {
            alert('Folder browser failed to load.');
            return;
        }
        window.KOPFolderBrowser.open({ foldersUrl: foldersUrl, currentId: '' })
            .then(function (res) {
                if (!res || res.id === null) return;
                addFolder(res.id, res.name);
            })
            .catch(function () { alert('Folder browser error.'); });
    });

    form.addEventListener('submit', function (e) {
        var n = pickedIds().length;
        if (n < 2) {
            e.preventDefault();
            alert('Pick at least two folders first.');
            return;
        }
        if (!window.confirm('Link these ' + n + ' folders as the same facility?\n\nFacility document feeds will show the merged contents under all of their names.')) {
            e.preventDefault();
        }
    });

    document.querySelectorAll('.use-suggestion').forEach(function (button) {
        button.addEventListener('click', function () {
            clearFolders();
            addFolder(button.dataset.a, button.dataset.aName);
            addFolder(button.dataset.b, button.dataset.bName);
            form.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });
    refresh();
})();
</script>
</body></html>
