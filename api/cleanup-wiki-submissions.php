<?php
/**
 * Wiki Submissions Cleanup (Admin, dry-run first)
 *
 * Collapses duplicate wiki submissions and purges discarded ones:
 *   1. PURGE   — every row whose status is 'rejected' or 'deleted'.
 *   2. DEDUPE  — among the remaining live rows, group by linked facility
 *                (facility_unique_name) or by program name + city/state, keep
 *                the single most-advanced row in each group, and delete the
 *                other deletable copies.
 *
 * Approved and published rows are NEVER deleted (even as duplicate "extras").
 *
 * Safety:
 *   - Admin-only (manage_options).
 *   - GET shows a DRY RUN (no changes) describing exactly what would be deleted.
 *   - Deletion happens only on POST with a valid nonce, inside a transaction.
 *
 * URL: /wp-content/themes/child/api/cleanup-wiki-submissions.php
 */

require_once __DIR__ . '/config.php';

// --- Load WordPress + require admin ---------------------------------------
if (!function_exists('current_user_can')) {
    $kop_wp = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $kop_wp = dirname($kop_wp);
        if (file_exists($kop_wp . '/wp-load.php')) {
            require_once $kop_wp . '/wp-load.php';
            break;
        }
    }
}
if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><p style="font-family:sans-serif">Admin access required.</p>';
    exit;
}

// --- Helpers ---------------------------------------------------------------
function kop_norm(?string $s): string {
    return strtolower(trim(preg_replace('/\s+/', ' ', (string) $s)));
}

// Most-advanced status wins when picking the keeper in a duplicate group.
$STATUS_RANK = ['published' => 5, 'approved' => 4, 'submitted' => 3, 'pending' => 3, 'draft' => 2, 'rejected' => 1, 'deleted' => 0];
$PROTECTED   = ['approved', 'published']; // never deletable
$DISCARDED   = ['rejected', 'deleted'];   // always purged

// Does the optional facility link column exist on this install?
$hasFacilityCol = false;
try {
    $hasFacilityCol = $pdo->query("SHOW COLUMNS FROM wiki_submissions LIKE 'facility_unique_name'")->rowCount() > 0;
} catch (PDOException $e) {
    $hasFacilityCol = false;
}

$cols = 'id, program_name, city_state, organization, status, created_at, updated_at'
      . ($hasFacilityCol ? ', facility_unique_name' : '');
$rows = $pdo->query("SELECT $cols FROM wiki_submissions")->fetchAll(PDO::FETCH_ASSOC);

$groupKey = static function (array $r) use ($hasFacilityCol): ?string {
    if ($hasFacilityCol) {
        $fun = kop_norm($r['facility_unique_name'] ?? '');
        if ($fun !== '') {
            return 'fac:' . $fun;
        }
    }
    $name = kop_norm($r['program_name'] ?? '');
    if ($name === '') {
        return null; // nothing to group on
    }
    return 'name:' . $name . '|' . kop_norm($r['city_state'] ?? '');
};

// --- Build the plan --------------------------------------------------------
$purge = [];
$live  = [];
foreach ($rows as $r) {
    if (in_array($r['status'], $DISCARDED, true)) {
        $purge[] = $r;
    } else {
        $live[] = $r;
    }
}

$groups = [];
foreach ($live as $r) {
    $k = $groupKey($r);
    if ($k === null) {
        continue; // ungroupable live row — leave it alone
    }
    $groups[$k][] = $r;
}

$dupGroups = [];   // groups that actually collapse (keeper + deletions)
$dupDelete = [];
foreach ($groups as $k => $grp) {
    if (count($grp) < 2) {
        continue;
    }
    usort($grp, static function ($a, $b) use ($STATUS_RANK) {
        $ra = $STATUS_RANK[$a['status']] ?? 0;
        $rb = $STATUS_RANK[$b['status']] ?? 0;
        if ($ra !== $rb) return $rb - $ra;                 // higher rank first
        $ta = strtotime($a['updated_at'] ?: $a['created_at'] ?: '1970-01-01');
        $tb = strtotime($b['updated_at'] ?: $b['created_at'] ?: '1970-01-01');
        if ($ta !== $tb) return $tb - $ta;                 // newer first
        return (int) $b['id'] - (int) $a['id'];            // higher id first
    });
    $keeper = $grp[0];
    $deletions = [];
    foreach (array_slice($grp, 1) as $r) {
        if (in_array($r['status'], $PROTECTED, true)) {
            continue; // never delete approved/published, even as a duplicate
        }
        $deletions[] = $r;
        $dupDelete[] = $r;
    }
    if ($deletions) {
        $dupGroups[] = ['keeper' => $keeper, 'deletions' => $deletions];
    }
}

// Final delete set (ids), de-duplicated.
$deleteIds = [];
foreach (array_merge($purge, $dupDelete) as $r) {
    $deleteIds[(int) $r['id']] = true;
}
$deleteIds = array_keys($deleteIds);

// --- Perform deletion (POST + nonce only) ----------------------------------
$done = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'delete'
    && wp_verify_nonce($_POST['_nonce'] ?? '', 'kop_cleanup_wiki')) {

    if (empty($deleteIds)) {
        $done = ['deleted' => 0, 'message' => 'Nothing to delete.'];
    } else {
        try {
            $pdo->beginTransaction();
            foreach (array_chunk($deleteIds, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                // Remove attachments first (FK-safe), then the submissions.
                $pdo->prepare("DELETE FROM submission_attachments WHERE submission_type = 'wiki' AND submission_id IN ($ph)")->execute($chunk);
                $pdo->prepare("DELETE FROM wiki_submissions WHERE id IN ($ph)")->execute($chunk);
            }
            $pdo->commit();
            $done = ['deleted' => count($deleteIds), 'message' => 'Cleanup complete.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('cleanup-wiki-submissions.php error: ' . $e->getMessage());
            http_response_code(500);
            $done = ['deleted' => 0, 'error' => 'Deletion failed and was rolled back: ' . $e->getMessage()];
        }
    }
}

// --- Render ----------------------------------------------------------------
$nonce = wp_create_nonce('kop_cleanup_wiki');
$totalRows = count($rows);
$purgeCount = count($purge);
$dupCount = count($dupDelete);
$deleteCount = count($deleteIds);
$keptCount = $totalRows - $deleteCount;
$esc = static function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Wiki Submissions Cleanup</title>
<style>
    body { font-family: system-ui, -apple-system, sans-serif; max-width: 900px; margin: 30px auto; padding: 0 16px; color: #0f172a; }
    h1 { font-size: 1.4rem; }
    .cards { display: flex; gap: 12px; flex-wrap: wrap; margin: 18px 0; }
    .card { flex: 1 1 120px; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; background: #f8fafc; }
    .card .n { font-size: 1.6rem; font-weight: 700; }
    .card .l { font-size: 0.8rem; color: #475569; }
    .danger { color: #b91c1c; }
    .ok { color: #15803d; }
    table { border-collapse: collapse; width: 100%; margin: 8px 0 22px; font-size: 0.86rem; }
    th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
    th { color: #475569; font-weight: 600; }
    .keep { color: #15803d; font-weight: 600; }
    .del { color: #b91c1c; }
    .pill { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 0.72rem; background: #e2e8f0; }
    .btn { background: #b91c1c; color: #fff; border: none; border-radius: 8px; padding: 10px 18px; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
    .btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .note { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; padding: 10px 14px; font-size: 0.86rem; }
    details { margin: 10px 0; }
    summary { cursor: pointer; font-weight: 600; }
</style>
</head>
<body>
<h1>🧹 Wiki Submissions Cleanup</h1>

<?php if ($done !== null): ?>
    <?php if (!empty($done['error'])): ?>
        <p class="danger"><strong>✗ <?php echo $esc($done['error']); ?></strong></p>
    <?php else: ?>
        <p class="ok"><strong>✓ <?php echo $esc($done['message']); ?> Deleted <?php echo (int) $done['deleted']; ?> row(s).</strong></p>
    <?php endif; ?>
    <p><a href="<?php echo $esc(strtok($_SERVER['REQUEST_URI'], '?')); ?>">Re-run dry run</a></p>
<?php else: ?>

<p class="note">This is a <strong>dry run</strong> — nothing has been deleted yet. Review the plan below, then use the button at the bottom to apply it.
Approved and published rows are never deleted.</p>

<div class="cards">
    <div class="card"><div class="n"><?php echo $totalRows; ?></div><div class="l">total rows</div></div>
    <div class="card"><div class="n ok"><?php echo $keptCount; ?></div><div class="l">kept</div></div>
    <div class="card"><div class="n danger"><?php echo $deleteCount; ?></div><div class="l">to delete</div></div>
    <div class="card"><div class="n"><?php echo $purgeCount; ?></div><div class="l">discarded (purged)</div></div>
    <div class="card"><div class="n"><?php echo $dupCount; ?></div><div class="l">duplicate extras</div></div>
</div>

<details open>
    <summary>Duplicate groups to collapse (<?php echo count($dupGroups); ?>)</summary>
    <?php if (!$dupGroups): ?>
        <p>None.</p>
    <?php else: ?>
        <table>
            <tr><th>Keep</th><th>Delete (duplicates)</th></tr>
            <?php foreach ($dupGroups as $g): $k = $g['keeper']; ?>
                <tr>
                    <td class="keep">#<?php echo (int) $k['id']; ?> <?php echo $esc($k['program_name']); ?>
                        <span class="pill"><?php echo $esc($k['status']); ?></span><br>
                        <small><?php echo $esc($k['city_state']); ?></small></td>
                    <td class="del">
                        <?php foreach ($g['deletions'] as $d): ?>
                            #<?php echo (int) $d['id']; ?> <span class="pill"><?php echo $esc($d['status']); ?></span><br>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</details>

<details>
    <summary>Discarded rows to purge — rejected / deleted (<?php echo $purgeCount; ?>)</summary>
    <?php if (!$purge): ?>
        <p>None.</p>
    <?php else: ?>
        <table>
            <tr><th>ID</th><th>Program</th><th>Status</th></tr>
            <?php foreach ($purge as $r): ?>
                <tr><td>#<?php echo (int) $r['id']; ?></td><td><?php echo $esc($r['program_name']); ?></td>
                    <td><span class="pill"><?php echo $esc($r['status']); ?></span></td></tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</details>

<form method="post" onsubmit="return confirm('Permanently delete <?php echo $deleteCount; ?> wiki submission row(s)? This cannot be undone.');">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="_nonce" value="<?php echo $esc($nonce); ?>">
    <button class="btn" type="submit" <?php echo $deleteCount ? '' : 'disabled'; ?>>
        Delete <?php echo $deleteCount; ?> row(s)
    </button>
</form>

<?php endif; ?>
</body>
</html>
