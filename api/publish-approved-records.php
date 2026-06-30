<?php
/**
 * Publish Approved Legislation & Lawsuits (Admin, dry-run first)
 *
 * One-time maintenance: moves every legislation/lawsuit row currently sitting
 * in publication_status = 'approved' to 'published' (stamping published_at).
 * This backfills the new "approve also publishes" behaviour for records that
 * were approved before that change.
 *
 * Safety:
 *   - Admin-only (manage_options).
 *   - GET shows a DRY RUN (counts only, no changes).
 *   - The update runs only on POST with a valid nonce.
 *
 * URL: /wp-content/themes/child/api/publish-approved-records.php
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

$TABLES = ['legislation' => 'bill_title', 'lawsuits' => 'case_name'];

// Current counts of approved rows per table.
$counts = [];
foreach ($TABLES as $table => $titleCol) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM `$table` WHERE publication_status = 'approved'");
    $counts[$table] = (int) $stmt->fetchColumn();
}
$totalApproved = array_sum($counts);

// --- Perform the update (POST + nonce only) --------------------------------
$done = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'publish'
    && wp_verify_nonce($_POST['_nonce'] ?? '', 'kop_publish_approved')) {

    try {
        $pdo->beginTransaction();
        $updated = [];
        foreach (array_keys($TABLES) as $table) {
            // COALESCE keeps any existing timestamp; approved rows have none.
            $stmt = $pdo->prepare(
                "UPDATE `$table`
                    SET publication_status = 'published',
                        published_at = COALESCE(published_at, NOW())
                  WHERE publication_status = 'approved'"
            );
            $stmt->execute();
            $updated[$table] = $stmt->rowCount();
        }
        $pdo->commit();
        $done = ['updated' => $updated, 'total' => array_sum($updated)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('publish-approved-records.php error: ' . $e->getMessage());
        http_response_code(500);
        $done = ['error' => 'Update failed and was rolled back: ' . $e->getMessage()];
    }
}

$nonce = wp_create_nonce('kop_publish_approved');
$esc = static function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Publish Approved Records</title>
<style>
    body { font-family: system-ui, -apple-system, sans-serif; max-width: 640px; margin: 30px auto; padding: 0 16px; color: #0f172a; }
    h1 { font-size: 1.3rem; }
    table { border-collapse: collapse; width: 100%; margin: 16px 0; }
    th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eee; }
    .n { font-weight: 700; }
    .ok { color: #15803d; }
    .danger { color: #b91c1c; }
    .btn { background: #1e40af; color: #fff; border: none; border-radius: 8px; padding: 10px 18px; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
    .btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .note { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 14px; font-size: 0.88rem; }
</style>
</head>
<body>
<h1>📤 Publish Approved Legislation &amp; Lawsuits</h1>

<?php if ($done !== null): ?>
    <?php if (!empty($done['error'])): ?>
        <p class="danger"><strong>✗ <?php echo $esc($done['error']); ?></strong></p>
    <?php else: ?>
        <p class="ok"><strong>✓ Published <?php echo (int) $done['total']; ?> record(s).</strong></p>
        <ul>
            <?php foreach ($done['updated'] as $table => $n): ?>
                <li><?php echo $esc($table); ?>: <?php echo (int) $n; ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <p><a href="<?php echo $esc(strtok($_SERVER['REQUEST_URI'], '?')); ?>">Re-check</a></p>
<?php else: ?>

<p class="note">This is a <strong>dry run</strong> — nothing has changed yet. It will move every <em>approved</em> legislation/lawsuit record to <em>published</em> and stamp its publish date.</p>

<table>
    <tr><th>Table</th><th>Approved (will publish)</th></tr>
    <?php foreach ($counts as $table => $n): ?>
        <tr><td><?php echo $esc($table); ?></td><td class="n"><?php echo (int) $n; ?></td></tr>
    <?php endforeach; ?>
    <tr><td><strong>Total</strong></td><td class="n"><?php echo (int) $totalApproved; ?></td></tr>
</table>

<form method="post" onsubmit="return confirm('Publish <?php echo (int) $totalApproved; ?> approved record(s)?');">
    <input type="hidden" name="action" value="publish">
    <input type="hidden" name="_nonce" value="<?php echo $esc($nonce); ?>">
    <button class="btn" type="submit" <?php echo $totalApproved ? '' : 'disabled'; ?>>
        Publish <?php echo (int) $totalApproved; ?> record(s)
    </button>
</form>

<?php endif; ?>
</body>
</html>
