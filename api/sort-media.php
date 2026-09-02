<?php
/**
 * Sort media — admin tool for re-filing MIS-FILED documents.
 *
 * The organize-uncategorized-media tool only handles files in no folder;
 * this one covers everything already filed:
 *
 *   - Browse by folder, text search, or "likely mis-filed" mode — which
 *     scans the whole library and flags files whose name matches a
 *     DIFFERENT folder than the one they're in.
 *   - Same group workflow: filter → tick (row-click / shift-range) →
 *     move ticked to a folder (existing or newly created) → Apply.
 *   - "Apply moves" REPLACES the file's folder(s); "Add to folder" adds an
 *     EXTRA membership so the file shows up in multiple folders without
 *     being duplicated (the fbv_attachment_folder relation table is
 *     many-to-many — the site's folder feeds list the file everywhere it's
 *     related; only FileBird's own wp-admin UI assumes one folder).
 *   - Deleting is permanent.
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

$SHOW = 300; // rows rendered per view

/** Normalize text for matching: lowercase, letters/digits only, spaced. */
function kop_sm_norm($text) {
    $t = mb_strtolower((string)$text);
    $t = html_entity_decode($t, ENT_QUOTES);
    $t = str_replace(['-', '_', '.', '/'], ' ', $t);
    $t = preg_replace('/[^a-z0-9\s]/u', ' ', $t);
    return preg_replace('/\s+/', ' ', trim($t));
}

/** Generic words that must not drive a folder match on their own. */
function kop_sm_generic() {
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
            'international', 'new', 'old', 'misc', 'other', 'general', 'sort'];
}

// ---------------------------------------------------------------------------
// Folder tree + matcher (same approach as the uncategorized organizer)
// ---------------------------------------------------------------------------
$folders = $wpdb->get_results("SELECT id, name, parent FROM {$fbv} WHERE type = 0");
$by_id = [];
foreach ($folders as $f) {
    $by_id[(int)$f->id] = $f;
}
function kop_sm_path($fid, $by_id, $depth = 0) {
    if ($depth > 10 || !isset($by_id[(int)$fid])) {
        return '';
    }
    $f = $by_id[(int)$fid];
    $prefix = ((int)$f->parent !== 0) ? kop_sm_path($f->parent, $by_id, $depth + 1) : '';
    return ($prefix !== '' ? $prefix . ' / ' : '') . $f->name;
}

$generic = kop_sm_generic();
$matchable = [];
foreach ($folders as $f) {
    $norm = kop_sm_norm($f->name);
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

$custom_rules = kop_sm_resolve_rules($folders, $by_id);

/**
 * Custom filename → folder rules, checked BEFORE name matching. Keys are
 * substrings of the NORMALIZED file text (lowercase, separators become
 * spaces — so "treatment_times" matches 'treatment times'); values are
 * candidate folder names (also normalized), first one that resolves to a
 * folder wins. Healthy folders beat orphaned ones with the same name.
 */
function kop_sm_custom_rules() {
    return [
        // "Treatment Times" is The Ridge (Maine)'s publication.
        'treatment times' => ['the ridge maine', 'the ridge me'],
    ];
}

/** Is the folder's ancestry broken (an orphaned ghost-tree member)? */
function kop_sm_is_orphan($fid, $by_id) {
    $cur = (int)$fid;
    for ($i = 0; $i < 15; $i++) {
        if (!isset($by_id[$cur])) {
            return true; // missing link in the chain
        }
        $parent = (int)$by_id[$cur]->parent;
        if ($parent === 0) {
            return false;
        }
        $cur = $parent;
    }
    return false;
}

/** Resolve the custom rules to folder ids: [substring => [id, label]]. */
function kop_sm_resolve_rules($folders, $by_id) {
    $by_norm = [];
    foreach ($folders as $f) {
        $by_norm[kop_sm_norm($f->name)][] = (int)$f->id;
    }
    $resolved = [];
    foreach (kop_sm_custom_rules() as $needle => $names) {
        foreach ((array)$names as $name) {
            $ids = isset($by_norm[$name]) ? $by_norm[$name] : [];
            // Prefer folders with intact ancestry over ghost-tree orphans.
            $healthy = array_values(array_filter($ids, static function ($fid) use ($by_id) {
                return !kop_sm_is_orphan($fid, $by_id);
            }));
            $pick = $healthy ?: $ids;
            if (count($pick) === 1) {
                $resolved[$needle] = [$pick[0], $by_id[$pick[0]]->name];
                break;
            }
        }
    }
    return $resolved;
}

/** Suggest a folder for file text. Returns [folder_id|null, why]. */
function kop_sm_suggest($fileText, $matchable, $rules = []) {
    // Custom rules win outright.
    foreach ($rules as $needle => $target) {
        if (strpos($fileText, $needle) !== false) {
            return [$target[0], 'rule: “' . $needle . '” → ' . $target[1]];
        }
    }
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
            $bestNames[$best !== null ? $best['norm'] : $m['norm']][] = $m['id'];
        }
    }
    if (!$best) {
        return [null, 'no name match'];
    }
    if (count($bestNames[$best['norm']]) > 1) {
        return [null, 'ambiguous name'];
    }
    return [$best['id'], $bestScore >= 1000 ? 'name match' : 'token match'];
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
$applied = false;
$apply_log = [];

// Move (replace filing) or tag (add an extra folder membership).
$kop_sm_action = null;
if (isset($_POST['do_move'])) {
    $kop_sm_action = 'move';
} elseif (isset($_POST['do_tag'])) {
    $kop_sm_action = 'tag';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && $kop_sm_action
    && check_admin_referer('kop_sm_apply')) {

    $requests = isset($_POST['file']) && is_array($_POST['file']) ? $_POST['file'] : [];
    $wpdb->query('START TRANSACTION');
    try {
        $done = 0;
        foreach ($requests as $att_id => $req) {
            if (empty($req['go'])) {
                continue;
            }
            $att_id = (int)$att_id;
            $target = (int)($req['target'] ?? 0);
            if ($att_id <= 0 || $target <= 0 || !isset($by_id[$target])) {
                continue;
            }
            if (get_post_type($att_id) !== 'attachment') {
                continue;
            }
            if ($kop_sm_action === 'move') {
                // Replace whatever filing the attachment has.
                $wpdb->delete($fbv_rel, ['attachment_id' => $att_id], ['%d']);
                $wpdb->insert($fbv_rel, ['folder_id' => $target, 'attachment_id' => $att_id], ['%d', '%d']);
                $done++;
            } else {
                // Tag: add membership, keep existing ones; skip if present.
                $exists = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$fbv_rel} WHERE attachment_id = %d AND folder_id = %d",
                    $att_id, $target
                ));
                if (!$exists) {
                    $wpdb->insert($fbv_rel, ['folder_id' => $target, 'attachment_id' => $att_id], ['%d', '%d']);
                    $done++;
                }
            }
        }
        $wpdb->query('COMMIT');
        $applied = true;
        $apply_log[] = $kop_sm_action === 'move'
            ? "Moved {$done} file(s)."
            : "Added {$done} file(s) to their extra folder (existing memberships kept).";
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        $apply_log[] = 'FAILED — rolled back: ' . $e->getMessage();
        error_log('sort-media move/tag failed: ' . $e->getMessage());
    }
}

// Confirm ticked files as correctly filed ("not mis-filed"): remember the
// membership set they were approved with, so the mis-filed scan skips them —
// until they move, which invalidates the approval automatically.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['do_confirm'])
    && check_admin_referer('kop_sm_apply')) {

    $requests = isset($_POST['file']) && is_array($_POST['file']) ? $_POST['file'] : [];
    $confirmed = 0;
    foreach ($requests as $att_id => $req) {
        if (empty($req['go'])) {
            continue;
        }
        $att_id = (int)$att_id;
        if ($att_id <= 0 || get_post_type($att_id) !== 'attachment') {
            continue;
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT folder_id FROM {$fbv_rel} WHERE attachment_id = %d ORDER BY folder_id", $att_id
        ));
        update_post_meta($att_id, '_kop_filing_ok', implode(',', array_map('intval', $ids)));
        $confirmed++;
    }
    $applied = true;
    $apply_log[] = "Marked {$confirmed} file(s) as correctly filed — the mis-filed scan will skip them unless they move.";
}

// Permanently delete ticked files.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['do_delete'])
    && check_admin_referer('kop_sm_apply')) {

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

// ---------------------------------------------------------------------------
// View: browse (by folder / search) or "likely mis-filed" scan
// ---------------------------------------------------------------------------
$mode = ($_GET['mode'] ?? '') === 'misfiled' ? 'misfiled' : 'browse';
$q = trim((string)($_GET['q'] ?? ''));
$folder_filter = trim((string)($_GET['folder'] ?? ''));

// One pass over the whole library: id, title, basename, and EVERY folder
// membership (files can belong to several folders — the tagging model).
$lib = $wpdb->get_results(
    "SELECT p.ID, p.post_title, p.post_mime_type,
            GROUP_CONCAT(DISTINCT r.folder_id) AS folder_ids,
            pm.meta_value AS attached_file,
            ok.meta_value AS filing_ok
     FROM {$wpdb->posts} p
     LEFT JOIN {$fbv_rel} r ON r.attachment_id = p.ID
     LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
     LEFT JOIN {$wpdb->postmeta} ok ON ok.post_id = p.ID AND ok.meta_key = '_kop_filing_ok'
     WHERE p.post_type = 'attachment'
     GROUP BY p.ID, p.post_title, p.post_mime_type, pm.meta_value, ok.meta_value
     ORDER BY p.ID DESC"
);
$total_files = count($lib);

$rows = [];
$scanned = 0;
$misfiled_total = 0;
foreach ($lib as $a) {
    $scanned++;
    $curs = $a->folder_ids !== null && $a->folder_ids !== ''
        ? array_map('intval', explode(',', $a->folder_ids))
        : [];
    $basename = $a->attached_file ? basename($a->attached_file) : '';
    $title = $a->post_title !== '' ? $a->post_title : $basename;

    // Browse filters.
    if ($mode === 'browse') {
        if ($folder_filter === 'uncat' && $curs) {
            continue;
        }
        if ($folder_filter !== '' && $folder_filter !== 'uncat' && !in_array((int)$folder_filter, $curs, true)) {
            continue;
        }
        if ($q !== '' && mb_stripos($title . ' ' . $basename, $q) === false) {
            continue;
        }
    }

    $fileText = kop_sm_norm($title . ' ' . $basename);
    list($sug, $why) = kop_sm_suggest($fileText, $matchable, $custom_rules);

    if ($mode === 'misfiled') {
        // Mis-filed = confident suggestion pointing somewhere the file is NOT
        // already filed (files can hold multiple memberships).
        if ($sug === null || !$curs || in_array($sug, $curs, true)) {
            continue;
        }
        // Skip files an admin confirmed as correctly filed — as long as their
        // membership set hasn't changed since the confirmation.
        if ($a->filing_ok !== null) {
            $ok_ids = array_map('intval', array_filter(explode(',', $a->filing_ok), 'strlen'));
            $now_ids = $curs;
            sort($ok_ids);
            sort($now_ids);
            if ($ok_ids === $now_ids) {
                continue;
            }
        }
        if ($q !== '' && mb_stripos($title . ' ' . $basename, $q) === false) {
            continue;
        }
        $misfiled_total++;
        if (count($rows) >= $SHOW) {
            continue; // keep counting, stop collecting
        }
    } elseif (count($rows) >= $SHOW) {
        break;
    }

    $rows[] = [
        'id'    => (int)$a->ID,
        'title' => $title,
        'file'  => $basename,
        'mime'  => $a->post_mime_type,
        'url'   => wp_get_attachment_url($a->ID),
        'curs'  => $curs,
        'sug'   => $sug,
        'why'   => $why,
    ];
}

// ---------------------------------------------------------------------------
// Folder tree for the browser sidebar
// ---------------------------------------------------------------------------
$children = [];
foreach ($folders as $f) {
    $children[(int)$f->parent][] = (int)$f->id;
}
foreach ($children as &$kids) {
    usort($kids, static function ($a, $b) use ($by_id) {
        return strcasecmp($by_id[$a]->name, $by_id[$b]->name);
    });
}
unset($kids);

// Direct file counts per folder.
$fcounts = [];
foreach ($wpdb->get_results("SELECT folder_id, COUNT(*) AS n FROM {$fbv_rel} GROUP BY folder_id") as $r) {
    $fcounts[(int)$r->folder_id] = (int)$r->n;
}
$uncat_count = (int)$wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} p
     LEFT JOIN {$fbv_rel} r ON r.attachment_id = p.ID
     WHERE p.post_type = 'attachment' AND r.attachment_id IS NULL"
);

// Auto-open the tree along the selected folder's ancestry.
$open_set = [];
if ($folder_filter !== '' && $folder_filter !== 'uncat') {
    $cur = (int)$folder_filter;
    for ($i = 0; $i < 15 && $cur; $i++) {
        $open_set[$cur] = true;
        $cur = isset($by_id[$cur]) ? (int)$by_id[$cur]->parent : 0;
    }
}

/** Render the folder tree as nested collapsibles with links. */
function kop_sm_tree($pid, $children, $by_id, $fcounts, $selected, $open_set) {
    if (empty($children[$pid])) {
        return;
    }
    foreach ($children[$pid] as $fid) {
        $name = $by_id[$fid]->name;
        $n = isset($fcounts[$fid]) ? $fcounts[$fid] : 0;
        $is_sel = (string)$fid === (string)$selected;
        $link = '<a class="' . ($is_sel ? 'sel' : '') . '" href="?folder=' . $fid . '">'
            . esc_html($name)
            . ($n ? ' <span class="cnt">(' . $n . ')</span>' : '')
            . '</a>';
        if (!empty($children[$fid])) {
            echo '<details' . (isset($open_set[$fid]) ? ' open' : '') . '><summary>' . $link . '</summary>';
            kop_sm_tree($fid, $children, $by_id, $fcounts, $selected, $open_set);
            echo '</details>';
        } else {
            echo '<div class="leaf">' . $link . '</div>';
        }
    }
}

// Browse mode with nothing selected and no search: prompt instead of a dump.
$need_pick = ($mode === 'browse' && $folder_filter === '' && $q === '');
if ($need_pick) {
    $rows = [];
}
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Sort Media</title>
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
a.tab { display: inline-block; padding: 7px 12px; border-radius: 6px 6px 0 0; background: #ddd; color: #000435; text-decoration: none; font-weight: 700; font-size: 0.85rem; }
a.tab.active { background: #000080; color: #fff; }
.log { background: #fff; border: 1px solid #ccc; padding: 10px 14px; font-family: monospace; font-size: 0.8rem; margin-bottom: 12px; }
input[type=number] { width: 84px; }
td a { color: #000080; }
select, input[type=search], input[type=text] { padding: 6px 9px; border: 1px solid #000080; border-radius: 6px; }
/* Two-pane file browser: folder tree left, files right */
.kop-layout { display: flex; gap: 14px; align-items: flex-start; }
.kop-tree { flex: 0 0 300px; background: #fff; border: 2px solid #33A7B5; border-radius: 8px; padding: 10px 12px; position: sticky; top: 8px; max-height: calc(100vh - 16px); overflow: auto; font-size: 0.84rem; }
.kop-tree a { color: #000435; text-decoration: none; display: inline-block; padding: 2px 5px; border-radius: 4px; }
.kop-tree a:hover { background: #FFF5CB; }
.kop-tree a.sel { background: #33A7B5; color: #fff; }
.kop-tree details { margin-left: 12px; }
.kop-tree > details, .kop-tree > .leaf { margin-left: 0; }
.kop-tree .leaf { margin-left: 12px; }
.kop-tree > .leaf { margin-left: 0; }
.kop-tree summary { cursor: pointer; }
.kop-tree .cnt { color: #888; font-size: 0.75rem; }
.kop-tree .special { display: block; margin-bottom: 4px; font-weight: 700; }
.kop-tree hr { border: none; border-top: 1px dashed #33A7B5; margin: 8px 0; }
.kop-main { flex: 1 1 auto; min-width: 0; }
.kop-toolbar { position: sticky; top: 0; z-index: 60; background: #F2EEDF; padding: 8px 0 6px; border-bottom: 2px solid #33A7B5; margin-bottom: 8px; box-shadow: 0 4px 8px rgba(0,4,53,0.08); }
.kop-toolbar .bar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 6px; }
.kop-toolbar small { color: #555; }
.summary { font-size: 0.85rem; margin-bottom: 6px; }
</style></head><body>
<h1>Sort Media <small style="font-weight:400">— re-file documents that are in the wrong folder</small></h1>

<?php if ($applied): ?>
    <div class="log ok"><?php echo implode('<br>', array_map('esc_html', $apply_log)); ?> <a href="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">Reload this view.</a></div>
<?php elseif ($apply_log): ?>
    <div class="log warn"><?php echo implode('<br>', array_map('esc_html', $apply_log)); ?></div>
<?php endif; ?>

<div class="kop-layout">
<nav class="kop-tree">
    <a class="special <?php echo $mode === 'misfiled' ? 'sel' : ''; ?>" href="?mode=misfiled">⚠ Likely mis-filed</a>
    <a class="special <?php echo $folder_filter === 'uncat' ? 'sel' : ''; ?>" href="?folder=uncat">🗃 Uncategorized <span class="cnt">(<?php echo $uncat_count; ?>)</span></a>
    <hr>
    <?php kop_sm_tree(0, $children, $by_id, $fcounts, $folder_filter, $open_set); ?>
</nav>
<main class="kop-main">

<form method="get" style="margin-bottom:10px">
    <?php if ($mode === 'misfiled'): ?><input type="hidden" name="mode" value="misfiled"><?php endif; ?>
    <?php if ($folder_filter !== ''): ?><input type="hidden" name="folder" value="<?php echo esc_attr($folder_filter); ?>"><?php endif; ?>
    <input type="search" name="q" value="<?php echo esc_attr($q); ?>"
        placeholder="<?php echo $mode === 'misfiled' ? 'Search within mis-filed…' : ($folder_filter !== '' ? 'Search this folder…' : 'Search the whole library…'); ?>" style="width:260px">
    <button type="submit" style="background:#000080">Search</button>
    <?php if ($mode === 'browse' && $folder_filter !== '' && $folder_filter !== 'uncat' && isset($by_id[(int)$folder_filter])): ?>
        <strong style="margin-left:8px">📂 <?php echo esc_html(kop_sm_path((int)$folder_filter, $by_id)); ?></strong>
    <?php endif; ?>
</form>

<?php if ($need_pick): ?>
<div class="log">Pick a folder from the tree on the left (or a special view above it) — or search the whole library.</div>
<?php endif; ?>

<?php if ($mode === 'misfiled'): ?>
<div class="log">Scanned <?php echo $total_files; ?> files —
    <strong><?php echo $misfiled_total; ?></strong> look mis-filed (name matches a different folder than the one they're in).
    <?php if ($misfiled_total > count($rows)): ?>Showing the first <?php echo count($rows); ?>; re-run after applying to see more.<?php endif; ?>
    The suggestion is a guess from the file name — check before applying.</div>
<?php endif; ?>

<?php if ($rows): ?>
<form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
<?php wp_nonce_field('kop_sm_apply'); ?>
<div class="kop-toolbar">
    <div class="summary">
        Showing <?php echo count($rows); ?> file(s) · <span id="kop-count"></span>
        <span id="kop-bulk-name" style="color:#000080;font-weight:600"></span>
    </div>
    <div class="bar">
        <input type="search" id="kop-filter" placeholder="Filter visible rows…" style="width:200px">
        <button type="button" id="kop-tick-visible" style="background:#000080">☑ Tick visible</button>
        <button type="button" id="kop-untick-visible" style="background:#7a7a7a">☐ Untick</button>
        <button type="button" id="kop-bulk-pick">🗂️ Send ticked to folder…</button>
        <button type="submit" name="do_move" value="1" style="background:#1b7e3c"
            onclick="return window.confirm('MOVE the ticked files to their target folders?\n\nThis REPLACES their current folder membership(s).');">
            ✅ Apply moves (replace)</button>
        <button type="submit" name="do_tag" value="1" style="background:#000080"
            onclick="return window.confirm('ADD the ticked files to their target folders?\n\nCurrent folder memberships are KEPT — the file will show up in both places (no duplicate file is created).');">
            🏷️ Add to folder (keep current)</button>
        <?php if ($mode === 'misfiled'): ?>
        <button type="submit" name="do_confirm" value="1" style="background:#b8860b"
            onclick="var n=document.querySelectorAll('tbody input[name$=&quot;[go]&quot;]:checked').length; if(!n){alert('Tick the files that are correctly filed first.');return false;} return window.confirm('Mark '+n+' ticked file(s) as CORRECTLY filed?\n\nThey stay exactly where they are and stop appearing in the mis-filed scan (until they move).');">
            ✔ Not mis-filed (approve as is)</button>
        <?php endif; ?>
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
<table><thead><tr><th></th><th>File</th><th>Currently in</th><th>Suggested</th><th>Move to</th></tr></thead><tbody>
<?php foreach ($rows as $r):
    $ticked = $mode === 'misfiled' && $r['sug'] !== null;
    if ($r['curs']) {
        $cur_label = implode('<br>', array_map(static function ($cid) use ($by_id) {
            return esc_html(kop_sm_path($cid, $by_id));
        }, $r['curs']));
        if (count($r['curs']) > 1) {
            $cur_label .= '<br><small class="ok">(in ' . count($r['curs']) . ' folders)</small>';
        }
    } else {
        $cur_label = '<span class="warn">(uncategorized)</span>';
    }
    $sug_label = $r['sug'] !== null
        ? '<span class="ok">#' . $r['sug'] . ' ' . esc_html(kop_sm_path($r['sug'], $by_id)) . '</span> <small>(' . esc_html($r['why']) . ')</small>'
        : '<span class="warn">' . esc_html($r['why']) . '</span>';
?>
    <tr>
        <td><input type="checkbox" name="file[<?php echo $r['id']; ?>][go]" value="1" <?php echo $ticked ? 'checked' : ''; ?>></td>
        <td>
            <a href="<?php echo esc_url($r['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($r['title']); ?></a>
            <?php if ($r['file'] && $r['file'] !== $r['title']): ?><br><small><?php echo esc_html($r['file']); ?></small><?php endif; ?>
            <br><small><?php echo esc_html($r['mime']); ?> · #<?php echo $r['id']; ?></small>
        </td>
        <td><?php echo $cur_label; ?></td>
        <td><?php echo $sug_label; ?></td>
        <td>
            <input type="number" name="file[<?php echo $r['id']; ?>][target]" min="1"
                value="<?php echo $r['sug'] !== null && !in_array($r['sug'], $r['curs'], true) ? (int)$r['sug'] : ''; ?>" placeholder="folder ID">
            <button type="button" class="kop-pick" title="Browse folders">📁 pick</button>
            <div class="kop-picked-name" style="font-size:0.78rem;color:#000080"></div>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table>
</form>
<?php elseif (!$need_pick): ?>
<p class="ok"><?php echo $mode === 'misfiled' ? 'No likely mis-filed documents found — names agree with their folders.' : 'No files match this view.'; ?></p>
<?php endif; ?>

</main>
</div><!-- /.kop-layout -->

<link rel="stylesheet" href="<?php echo esc_url(get_stylesheet_directory_uri() . '/css/filebird-folder-browser.css'); ?>">
<script src="<?php echo esc_url(get_stylesheet_directory_uri() . '/js/filebird-folder-browser.js'); ?>"></script>
<script>
(function () {
    var foldersUrl = <?php echo wp_json_encode(rest_url('kop/v1/folders')); ?>;
    var foldersAdminApi = <?php echo wp_json_encode(get_stylesheet_directory_uri() . '/api/filebird-folders.php'); ?>;

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

    allRows.forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('a, button, input, select, label')) {
                if (e.target === rowCheck(row)) { paintRow(row); refreshCount(); lastClicked = row; }
                return;
            }
            var check = rowCheck(row);
            if (!check) return;
            if (e.shiftKey && lastClicked && lastClicked !== row) {
                var a = allRows.indexOf(lastClicked), b = allRows.indexOf(row);
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
                ticked.length + ' row(s) → ' + name + ' — review, then Apply moves';
        }
        return ticked.length;
    }

    document.querySelectorAll('.kop-pick').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.KOPFolderBrowser || typeof window.KOPFolderBrowser.open !== 'function') {
                alert('Folder browser failed to load.');
                return;
            }
            var row = btn.closest('tr');
            var input = row.querySelector('input[type=number]');
            var label = row.querySelector('.kop-picked-name');
            var check = rowCheck(row);
            window.KOPFolderBrowser.open({ foldersUrl: foldersUrl, currentId: input.value })
                .then(function (res) {
                    if (!res) return;
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

    var bulkBtn = document.getElementById('kop-bulk-pick');
    if (bulkBtn) {
        bulkBtn.addEventListener('click', function () {
            if (!window.KOPFolderBrowser || typeof window.KOPFolderBrowser.open !== 'function') {
                alert('Folder browser failed to load.');
                return;
            }
            var ticked = allRows.filter(function (r) { var c = rowCheck(r); return c && c.checked; });
            if (!ticked.length) {
                alert('Tick the rows you want to move first.');
                return;
            }
            window.KOPFolderBrowser.open({ foldersUrl: foldersUrl })
                .then(function (res) {
                    if (!res || res.id === null) return;
                    targetTickedRows(res.id, res.name);
                })
                .catch(function () { alert('Folder browser error.'); });
        });
    }

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
                    if (!res) return;
                    nfParent = res.id === null ? { id: 0, name: 'top level' } : { id: parseInt(res.id, 10), name: res.name };
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
                    (n ? ' — ' + n + ' ticked row(s) targeted; review, then Apply moves'
                       : ' — tick rows and use the bulk button, or 📁 pick it per row');
                nfNameInput.value = '';
            }).catch(function (err) {
                nfCreateBtn.disabled = false;
                alert('New folder failed: ' + (err && err.message ? err.message : 'unknown error'));
            });
        });
    }
})();
</script>
</body></html>
