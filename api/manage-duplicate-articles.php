<?php
/**
 * Manage duplicate news articles — admin tool.
 *
 * Scans news_submissions for rows that are the SAME ARTICLE recorded more than
 * once and lets an admin clean them up: keep one copy and delete the rest, or
 * delete individual rows. Two rows are flagged as duplicates when they share
 * a normalized article_url (api/url-dedupe.php rules) or a normalized
 * title + outlet (api/news-story-groups.php rules) — the same matching the
 * submit endpoints use to block new duplicates, applied here to rows that got
 * in before that guard existed.
 *
 * Deleting is a soft delete (status = 'deleted'): the row disappears from the
 * feed, indexes, and arc/group counts, but stays restorable below. Cross-outlet
 * coverage of the same event is NOT a duplicate — that's what story groups
 * ("Also covered by") are for.
 *
 * Admin-only. Loads WordPress via config.php.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/url-dedupe.php';
require_once __DIR__ . '/news-story-groups.php';

if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not authorized. Log in to WordPress as an administrator first.';
    exit;
}

header('Content-Type: text/html; charset=utf-8');

$notices = [];
$errors = [];

$kop_dupe_note = static function (int $ofId): string {
    return 'Deleted as duplicate of #' . $ofId . ' (duplicate manager, ' . date('Y-m-d') . ')';
};

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('kop_manage_dupes')) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'keep') {
            // Keep one row of a duplicate group, soft-delete the others.
            $keepId = (int) ($_POST['keep_id'] ?? 0);
            $groupIds = array_values(array_filter(array_map('intval',
                explode(',', (string) ($_POST['group_ids'] ?? '')))));
            if ($keepId <= 0 || !in_array($keepId, $groupIds, true) || count($groupIds) < 2) {
                $errors[] = 'Pick which copy to keep first.';
            } else {
                $dropIds = array_values(array_diff($groupIds, [$keepId]));

                // Don't lose an arc assignment with the deleted copies: if the
                // kept row has no arc and a dropped one does, move it over.
                $ph = implode(',', array_fill(0, count($dropIds), '?'));
                $stmt = $pdo->prepare(
                    "SELECT story_arc_id FROM news_submissions
                     WHERE id IN ($ph) AND story_arc_id IS NOT NULL LIMIT 1");
                $stmt->execute($dropIds);
                $orphanArc = $stmt->fetchColumn();
                if ($orphanArc) {
                    $pdo->prepare(
                        "UPDATE news_submissions SET story_arc_id = ?
                         WHERE id = ? AND story_arc_id IS NULL")
                        ->execute([(int) $orphanArc, $keepId]);
                }

                $stmt = $pdo->prepare(
                    "UPDATE news_submissions
                     SET status = 'deleted',
                         reviewer_notes = TRIM(CONCAT_WS('\n', reviewer_notes, ?))
                     WHERE id IN ($ph) AND status <> 'deleted'");
                $stmt->execute(array_merge([$kop_dupe_note($keepId)], $dropIds));
                $notices[] = 'Kept #' . $keepId . ', deleted ' . $stmt->rowCount()
                    . ' duplicate(s): #' . implode(', #', $dropIds) . '.';
            }
        } elseif ($action === 'delete_one') {
            $articleId = (int) ($_POST['article_id'] ?? 0);
            $dupeOf = (int) ($_POST['dupe_of'] ?? 0);
            $note = $dupeOf > 0
                ? $kop_dupe_note($dupeOf)
                : 'Deleted (duplicate manager, ' . date('Y-m-d') . ')';
            $stmt = $pdo->prepare(
                "UPDATE news_submissions
                 SET status = 'deleted',
                     reviewer_notes = TRIM(CONCAT_WS('\n', reviewer_notes, ?))
                 WHERE id = ? AND status <> 'deleted'");
            $stmt->execute([$note, $articleId]);
            $notices[] = $stmt->rowCount()
                ? "Deleted article #{$articleId}."
                : "Article #{$articleId} not found (or already deleted).";
        } elseif ($action === 'restore') {
            // Restores to 'submitted' (the pre-review state) — re-approve it in
            // the normal review flow if it belongs on the feed.
            $articleId = (int) ($_POST['article_id'] ?? 0);
            $stmt = $pdo->prepare(
                "UPDATE news_submissions SET status = 'submitted'
                 WHERE id = ? AND status = 'deleted'");
            $stmt->execute([$articleId]);
            $notices[] = $stmt->rowCount()
                ? "Restored article #{$articleId} to 'submitted' — re-approve it to put it back on the feed."
                : "Article #{$articleId} is not in the deleted list.";
        } elseif ($action === 'purge') {
            // Permanent removal; only allowed for rows already soft-deleted.
            $articleId = (int) ($_POST['article_id'] ?? 0);
            $stmt = $pdo->prepare(
                "DELETE FROM news_submissions WHERE id = ? AND status = 'deleted'");
            $stmt->execute([$articleId]);
            $notices[] = $stmt->rowCount()
                ? "Permanently removed article #{$articleId}."
                : "Article #{$articleId} is not in the deleted list (soft-delete it first).";
        } elseif ($action === 'rebuild_groups') {
            // Re-cluster "Also covered by" groups after a cleanup, so groups
            // don't keep pointing at deleted rows.
            $result = kop_news_rebuild_story_groups($pdo);
            $notices[] = 'Story groups rebuilt: ' . (int) ($result['articles'] ?? 0)
                . ' article(s) in ' . (int) ($result['groups'] ?? 0) . ' group(s).';
        }
    } catch (PDOException $e) {
        $errors[] = 'Database error: ' . $e->getMessage();
    }

    // fetch()-submitted forms (admin-inline-actions.js) get JSON back instead
    // of a full re-render, so the page doesn't reload under the admin.
    if (!empty($_POST['kop_ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => empty($errors), 'notices' => $notices, 'errors' => $errors]);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Scan for duplicate groups
// ---------------------------------------------------------------------------
// Two live rows are duplicates when they share a normalized URL, or a
// normalized title on the same outlet (either stored title column). Keys are
// merged with union-find so a URL match and a title match chain into one group.
$groups = [];        // list of lists of row references
$deleted_rows = [];
$scan_total = 0;

try {
    $rows = $pdo->query(
        "SELECT id, article_title, alternate_title, publication_name, publication_date,
                article_url, article_type, status, story_arc_id, story_group_id, created_at,
                (summary IS NOT NULL AND summary <> '') AS has_summary,
                (facilities_mentioned IS NOT NULL AND facilities_mentioned <> ''
                 AND facilities_mentioned <> '[]') AS has_facilities
         FROM news_submissions
         WHERE status NOT IN ('rejected', 'deleted')
         ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $scan_total = count($rows);

    $parent = [];
    $find = function ($i) use (&$parent, &$find) {
        while ($parent[$i] !== $i) {
            $parent[$i] = $parent[$parent[$i]]; // path halving
            $i = $parent[$i];
        }
        return $i;
    };
    $keyOwner = []; // dedupe key => first row index seen with it

    foreach ($rows as $i => $row) {
        $parent[$i] = $i;
        $keys = [];
        $u = kop_normalize_url($row['article_url']);
        if ($u !== null) {
            $keys[] = 'u:' . $u;
        }
        $outletKey = kop_news_outlet_key($row['publication_name']);
        foreach (['article_title', 'alternate_title'] as $col) {
            $t = kop_news_normalize_title($row[$col]);
            if ($t !== '') {
                $keys[] = 't:' . $t . '|' . $outletKey;
            }
        }
        foreach ($keys as $k) {
            if (isset($keyOwner[$k])) {
                $parent[$find($i)] = $find($keyOwner[$k]);
            } else {
                $keyOwner[$k] = $i;
            }
        }
    }

    $byRoot = [];
    foreach ($rows as $i => $row) {
        $byRoot[$find($i)][] = $row;
    }
    $statusRank = ['published' => 0, 'approved' => 1, 'submitted' => 2, 'draft' => 3];
    foreach ($byRoot as $members) {
        if (count($members) < 2) {
            continue;
        }
        // Best copy first: highest status, then richest, then oldest.
        usort($members, static function ($a, $b) use ($statusRank) {
            $r = ($statusRank[$a['status']] ?? 9) <=> ($statusRank[$b['status']] ?? 9);
            if ($r !== 0) { return $r; }
            $r = ((int) $b['has_summary'] + (int) $b['has_facilities'])
             <=> ((int) $a['has_summary'] + (int) $a['has_facilities']);
            if ($r !== 0) { return $r; }
            return (int) $a['id'] <=> (int) $b['id'];
        });
        $groups[] = $members;
    }
    // Biggest piles first.
    usort($groups, static function ($a, $b) {
        return count($b) <=> count($a);
    });

    $deleted_rows = $pdo->query(
        "SELECT id, article_title, alternate_title, publication_name, publication_date,
                article_url, reviewer_notes, updated_at
         FROM news_submissions
         WHERE status = 'deleted'
         ORDER BY updated_at DESC, id DESC
         LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $errors[] = 'Could not scan for duplicates: ' . $e->getMessage();
}
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Manage Duplicate Articles</title>
<style>
body { font-family: system-ui, sans-serif; margin: 24px; color: #000435; background: #F2EEDF; }
h1 { font-size: 1.3rem; } h2 { font-size: 1.05rem; margin-top: 28px; }
table { border-collapse: collapse; background: #fff; font-size: 0.85rem; width: 100%; }
th, td { border: 1px solid #ccc; padding: 5px 9px; text-align: left; vertical-align: top; }
th { background: #000080; color: #fff; }
.muted { color: #666; }
.notice { background: #FFF5CB; border: 2px solid #33A7B5; border-radius: 8px; padding: 10px 14px; margin: 10px 0; max-width: 860px; }
.error { background: #fff; border: 2px solid #c0392b; border-radius: 8px; padding: 10px 14px; margin: 10px 0; max-width: 860px; color: #c0392b; }
.group { background: #fff; border: 2px solid #EF9034; border-radius: 8px; padding: 14px 18px; margin: 16px 0; max-width: 1100px; }
.group h3 { margin: 0 0 8px; font-size: 1rem; }
button { background: #000080; color: #fff; border: none; border-radius: 6px; padding: 7px 14px; font-weight: 700; cursor: pointer; margin-top: 8px; }
button.secondary { background: #33A7B5; }
button.danger { background: #c0392b; }
button.small { padding: 3px 8px; font-size: 0.78rem; margin-top: 0; }
.hint { font-size: 0.78rem; color: #666; margin: 2px 0 0; }
form.inline { display: inline; }
.status-published, .status-approved { color: #1b7e3c; font-weight: 600; }
.status-submitted, .status-draft { color: #8a6d1a; }
.suggest { background: #B6E3D4; border-radius: 4px; padding: 0 5px; font-size: 0.75rem; font-weight: 700; }
td.url { max-width: 260px; overflow-wrap: anywhere; font-size: 0.78rem; }
details.deleted-list { margin-top: 10px; max-width: 1100px; }
details.deleted-list summary { cursor: pointer; font-weight: 600; }
</style></head><body>
<h1>Manage Duplicate Articles</h1>
<p style="max-width:860px">Rows below share the <strong>same URL</strong> (ignoring tracking parameters and
http/www variants) or the <strong>same title on the same outlet</strong> — the same rules that now block
duplicates at submit time. Pick the copy to keep in each group; deleting is reversible until you
permanently remove a row from the deleted list at the bottom.
Different outlets covering the same event are <em>not</em> duplicates — they belong in one story group
("Also covered by") instead. Arcs are managed in <a href="manage-story-arcs.php">Manage Ongoing Stories</a>.</p>

<?php foreach ($notices as $n): ?><div class="notice"><?php echo esc_html($n); ?></div><?php endforeach; ?>
<?php foreach ($errors as $e): ?><div class="error"><?php echo esc_html($e); ?></div><?php endforeach; ?>

<h2>Duplicate groups (<?php echo count($groups); ?>)</h2>
<p class="muted"><?php echo (int) $scan_total; ?> live article(s) scanned.</p>
<?php if (!$groups): ?>
    <p class="muted">No duplicates found — every live article has a unique URL and title/outlet.</p>
<?php endif; ?>

<?php foreach ($groups as $gi => $members):
    $ids = array_map(static function ($m) { return (int) $m['id']; }, $members);
    $bestTitle = !empty($members[0]['alternate_title']) ? $members[0]['alternate_title'] : $members[0]['article_title'];
?>
<div class="group">
    <h3><?php echo esc_html($bestTitle); ?>
        <span class="muted">— <?php echo count($members); ?> copies (#<?php echo implode(', #', $ids); ?>)</span></h3>
    <form method="post" data-inline-action data-remove="card"
          data-confirm="Keep the selected copy and delete the other <?php echo count($members) - 1; ?>?">
        <?php wp_nonce_field('kop_manage_dupes'); ?>
        <input type="hidden" name="action" value="keep">
        <input type="hidden" name="group_ids" value="<?php echo esc_attr(implode(',', $ids)); ?>">
        <table><thead><tr>
            <th>Keep</th><th>ID</th><th>Title</th><th>Outlet</th><th>Date</th><th>Status</th>
            <th>Arc</th><th>Data</th><th>URL</th><th>Added</th><th></th>
        </tr></thead><tbody>
        <?php foreach ($members as $mi => $m):
            $mTitle = !empty($m['alternate_title']) ? $m['alternate_title'] : $m['article_title'];
            $rich = [];
            if ($m['has_summary']) { $rich[] = 'summary'; }
            if ($m['has_facilities']) { $rich[] = 'facilities'; }
        ?>
            <tr id="dupe-row-<?php echo (int) $m['id']; ?>">
                <td><input type="radio" name="keep_id" value="<?php echo (int) $m['id']; ?>" <?php echo $mi === 0 ? 'checked' : ''; ?>>
                    <?php if ($mi === 0): ?><span class="suggest">suggested</span><?php endif; ?></td>
                <td><?php echo (int) $m['id']; ?></td>
                <td><?php echo esc_html($mTitle); ?></td>
                <td><?php echo esc_html($m['publication_name'] ?? ''); ?></td>
                <td><?php echo esc_html($m['publication_date'] ?? ''); ?></td>
                <td class="status-<?php echo esc_attr($m['status']); ?>"><?php echo esc_html($m['status']); ?></td>
                <td><?php echo $m['story_arc_id'] ? '#' . (int) $m['story_arc_id'] : '—'; ?></td>
                <td class="muted"><?php echo $rich ? esc_html(implode(', ', $rich)) : '—'; ?></td>
                <td class="url"><?php if (!empty($m['article_url'])): ?>
                    <a href="<?php echo esc_url($m['article_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($m['article_url']); ?></a>
                <?php endif; ?></td>
                <td class="muted"><?php echo esc_html(substr((string) $m['created_at'], 0, 10)); ?></td>
                <td></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <button type="submit" class="danger">Keep selected, delete the rest</button>
    </form>
    <?php foreach ($members as $m): ?>
        <form method="post" class="inline" data-inline-action
              data-remove-target="#dupe-row-<?php echo (int) $m['id']; ?>"
              data-confirm="Delete only #<?php echo (int) $m['id']; ?>?">
            <?php wp_nonce_field('kop_manage_dupes'); ?>
            <input type="hidden" name="action" value="delete_one">
            <input type="hidden" name="article_id" value="<?php echo (int) $m['id']; ?>">
            <input type="hidden" name="dupe_of" value="<?php echo (int) $members[0]['id'] === (int) $m['id'] ? 0 : (int) $members[0]['id']; ?>">
            <button type="submit" class="small secondary">delete only #<?php echo (int) $m['id']; ?></button>
        </form>
    <?php endforeach; ?>
    <p class="hint">If these are different articles that merely share a headline, leave the group alone —
    nothing is deleted until you act.</p>
</div>
<?php endforeach; ?>

<?php if ($groups): ?>
<form method="post" data-inline-action>
    <?php wp_nonce_field('kop_manage_dupes'); ?>
    <input type="hidden" name="action" value="rebuild_groups">
    <button type="submit" class="secondary"
        title="Re-cluster the 'Also covered by' story groups so they stop pointing at deleted rows">
        Rebuild story groups after cleanup</button>
</form>
<p class="hint">Run once when you're done deleting — same as api/rebuild-news-story-groups.php.</p>
<?php endif; ?>

<h2>Deleted articles (<?php echo count($deleted_rows); ?><?php echo count($deleted_rows) === 100 ? ', showing latest 100' : ''; ?>)</h2>
<?php if (!$deleted_rows): ?>
    <p class="muted">Nothing in the deleted list.</p>
<?php else: ?>
<details class="deleted-list">
    <summary>Show deleted articles (restore or permanently remove)</summary>
    <table><thead><tr><th>ID</th><th>Title</th><th>Outlet</th><th>Date</th><th>Notes</th><th>Deleted</th><th></th></tr></thead><tbody>
    <?php foreach ($deleted_rows as $d):
        $dTitle = !empty($d['alternate_title']) ? $d['alternate_title'] : $d['article_title'];
    ?>
        <tr id="del-row-<?php echo (int) $d['id']; ?>">
            <td><?php echo (int) $d['id']; ?></td>
            <td><?php if (!empty($d['article_url'])): ?>
                    <a href="<?php echo esc_url($d['article_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($dTitle); ?></a>
                <?php else: echo esc_html($dTitle); endif; ?></td>
            <td><?php echo esc_html($d['publication_name'] ?? ''); ?></td>
            <td><?php echo esc_html($d['publication_date'] ?? ''); ?></td>
            <td class="muted"><?php echo esc_html($d['reviewer_notes'] ?? ''); ?></td>
            <td class="muted"><?php echo esc_html(substr((string) $d['updated_at'], 0, 10)); ?></td>
            <td>
                <form method="post" class="inline" data-inline-action
                      data-remove-target="#del-row-<?php echo (int) $d['id']; ?>">
                    <?php wp_nonce_field('kop_manage_dupes'); ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="article_id" value="<?php echo (int) $d['id']; ?>">
                    <button type="submit" class="small secondary">restore</button>
                </form>
                <form method="post" class="inline" data-inline-action
                      data-remove-target="#del-row-<?php echo (int) $d['id']; ?>"
                      data-confirm="Permanently remove #<?php echo (int) $d['id']; ?>? This cannot be undone.">
                    <?php wp_nonce_field('kop_manage_dupes'); ?>
                    <input type="hidden" name="action" value="purge">
                    <input type="hidden" name="article_id" value="<?php echo (int) $d['id']; ?>">
                    <button type="submit" class="small danger">remove forever</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table>
</details>
<?php endif; ?>

<script src="admin-inline-actions.js?v=1"></script>
</body></html>
