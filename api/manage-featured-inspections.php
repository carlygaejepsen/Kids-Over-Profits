<?php
/**
 * Manage featured inspection reports — admin tool.
 *
 * Curates the "inspections that demand attention" block on the home page:
 * search the scraped inspection_reports (by facility name and state), feature
 * a report with a one-line note explaining why it matters, edit notes, and
 * unfeature. The home page shows nothing until at least one report is
 * featured, so this is safe to leave empty.
 *
 * Requires the featured/featured_note columns (api/update-schema.php).
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

$notices = [];
$errors = [];

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('kop_manage_featured')) {
    $action = $_POST['action'] ?? '';
    $reportId = (int) ($_POST['report_id'] ?? 0);
    try {
        if ($action === 'feature' || $action === 'note') {
            $note = trim((string) ($_POST['note'] ?? ''));
            $stmt = $pdo->prepare(
                "UPDATE inspection_reports SET featured = 1, featured_note = ? WHERE id = ?");
            $stmt->execute([$note !== '' ? $note : null, $reportId]);
            $notices[] = $stmt->rowCount() || $action === 'note'
                ? ($action === 'feature' ? "Featured report #{$reportId}." : "Updated the note on report #{$reportId}.")
                : "Report #{$reportId} not found.";
        } elseif ($action === 'unfeature') {
            $stmt = $pdo->prepare(
                "UPDATE inspection_reports SET featured = 0 WHERE id = ? AND featured = 1");
            $stmt->execute([$reportId]);
            $notices[] = $stmt->rowCount()
                ? "Unfeatured report #{$reportId}."
                : "Report #{$reportId} was not featured.";
        }
    } catch (PDOException $e) {
        $errors[] = 'Database error: ' . $e->getMessage()
            . ' (has api/update-schema.php been run since the featured-inspections migration was added?)';
    }

    if (!empty($_POST['kop_ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => empty($errors), 'notices' => $notices, 'errors' => $errors]);
        exit;
    }
}

// ---------------------------------------------------------------------------
// Data for the page
// ---------------------------------------------------------------------------
$featured = [];
$search_results = [];
$states = [];
$q = trim((string) ($_GET['q'] ?? ''));
$state_q = strtoupper(trim((string) ($_GET['state'] ?? '')));

try {
    $featured = $pdo->query(
        "SELECT r.id, r.report_date, r.report_url, r.featured_note, r.summary,
                f.facility_name, f.state
         FROM inspection_reports r
         JOIN inspection_facilities f ON f.id = r.facility_id
         WHERE r.featured = 1
         ORDER BY r.report_date DESC, r.id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $states = $pdo->query(
        "SELECT DISTINCT state FROM inspection_facilities ORDER BY state ASC"
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($q !== '' || $state_q !== '') {
        $where = [];
        $params = [];
        if ($q !== '') {
            $where[] = "(f.facility_name LIKE ? OR f.program_name LIKE ?)";
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }
        if ($state_q !== '') {
            $where[] = "f.state = ?";
            $params[] = $state_q;
        }
        $stmt = $pdo->prepare(
            "SELECT r.id, r.report_date, r.report_url, r.featured, r.featured_note, r.summary,
                    f.facility_name, f.state
             FROM inspection_reports r
             JOIN inspection_facilities f ON f.id = r.facility_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY r.report_date DESC, r.id DESC
             LIMIT 50"
        );
        $stmt->execute($params);
        $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $errors[] = 'Could not load inspection data: ' . $e->getMessage()
        . ' - run api/update-schema.php first.';
}

$kop_excerpt = static function ($text, $len = 160) {
    $t = trim((string) $text);
    if (mb_strlen($t) <= $len) {
        return $t;
    }
    return mb_substr($t, 0, $len) . '…';
};
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Manage Featured Inspections</title>
<style>
body { font-family: system-ui, sans-serif; margin: 24px; color: #000435; background: #F2EEDF; }
h1 { font-size: 1.3rem; } h2 { font-size: 1.05rem; margin-top: 28px; }
table { border-collapse: collapse; background: #fff; font-size: 0.85rem; width: 100%; }
th, td { border: 1px solid #ccc; padding: 5px 9px; text-align: left; vertical-align: top; }
th { background: #000080; color: #fff; }
.muted { color: #666; }
.notice { background: #FFF5CB; border: 2px solid #33A7B5; border-radius: 8px; padding: 10px 14px; margin: 10px 0; max-width: 860px; }
.error { background: #fff; border: 2px solid #c0392b; border-radius: 8px; padding: 10px 14px; margin: 10px 0; max-width: 860px; color: #c0392b; }
button { background: #000080; color: #fff; border: none; border-radius: 6px; padding: 6px 12px; font-weight: 700; cursor: pointer; }
button.secondary { background: #33A7B5; }
button.danger { background: #c0392b; }
button.small { padding: 3px 8px; font-size: 0.78rem; }
input[type=text], input[type=search], select { padding: 6px 9px; border: 1px solid #bbb; border-radius: 4px; font: inherit; }
input.note { width: 100%; max-width: 420px; }
form.inline { display: inline; }
.hint { font-size: 0.78rem; color: #666; margin: 2px 0 0; }
.badge { display: inline-block; background: #FE8088; color: #000435; border-radius: 999px; padding: 1px 8px; font-size: 0.72rem; font-weight: 700; }
</style></head><body>
<h1>Manage Featured Inspections</h1>
<p style="max-width:860px">Reports featured here appear in the home page's "inspections that demand attention"
block, with your one-line note as the explanation. Feature sparingly - a handful of recent, serious
reports. The home page shows the latest four featured reports by report date.</p>

<?php foreach ($notices as $n): ?><div class="notice"><?php echo esc_html($n); ?></div><?php endforeach; ?>
<?php foreach ($errors as $e): ?><div class="error"><?php echo esc_html($e); ?></div><?php endforeach; ?>

<h2>Currently featured (<?php echo count($featured); ?>)</h2>
<?php if (!$featured): ?>
    <p class="muted">Nothing featured yet - search below and feature a report.</p>
<?php else: ?>
<table><thead><tr><th>Facility</th><th>State</th><th>Report date</th><th>Note (shown on the home page)</th><th></th></tr></thead><tbody>
<?php foreach ($featured as $r): ?>
    <tr id="feat-row-<?php echo (int) $r['id']; ?>">
        <td><?php if (!empty($r['report_url'])): ?>
                <a href="<?php echo esc_url($r['report_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($r['facility_name']); ?></a>
            <?php else: echo esc_html($r['facility_name']); endif; ?></td>
        <td><?php echo esc_html($r['state']); ?></td>
        <td><?php echo esc_html($r['report_date'] ?? ''); ?></td>
        <td>
            <form method="post" class="inline" data-inline-action>
                <?php wp_nonce_field('kop_manage_featured'); ?>
                <input type="hidden" name="action" value="note">
                <input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>">
                <input type="text" class="note" name="note" value="<?php echo esc_attr($r['featured_note'] ?? ''); ?>"
                       placeholder="Why this report matters, in one line">
                <button type="submit" class="small secondary">save note</button>
            </form>
        </td>
        <td>
            <form method="post" class="inline" data-inline-action
                  data-remove-target="#feat-row-<?php echo (int) $r['id']; ?>">
                <?php wp_nonce_field('kop_manage_featured'); ?>
                <input type="hidden" name="action" value="unfeature">
                <input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>">
                <button type="submit" class="small danger">unfeature</button>
            </form>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<h2>Find reports to feature</h2>
<form method="get" style="margin-bottom:10px">
    <input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Facility name…" style="width:240px">
    <select name="state">
        <option value="">Any state</option>
        <?php foreach ($states as $s): ?>
            <option value="<?php echo esc_attr($s); ?>" <?php echo $s === $state_q ? 'selected' : ''; ?>><?php echo esc_html($s); ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="secondary">Search</button>
</form>

<?php if ($q !== '' || $state_q !== ''): ?>
    <p class="muted"><?php echo count($search_results); ?> report(s), newest first (max 50)</p>
    <?php if ($search_results): ?>
    <table><thead><tr><th>Facility</th><th>State</th><th>Report date</th><th>Summary</th><th>Feature with note</th></tr></thead><tbody>
    <?php foreach ($search_results as $r): ?>
        <tr>
            <td><?php if (!empty($r['report_url'])): ?>
                    <a href="<?php echo esc_url($r['report_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($r['facility_name']); ?></a>
                <?php else: echo esc_html($r['facility_name']); endif; ?>
                <?php if (!empty($r['featured'])): ?> <span class="badge">featured</span><?php endif; ?></td>
            <td><?php echo esc_html($r['state']); ?></td>
            <td><?php echo esc_html($r['report_date'] ?? ''); ?></td>
            <td class="muted"><?php echo esc_html($kop_excerpt($r['summary'] ?? '')); ?></td>
            <td>
                <form method="post" class="inline" data-inline-action>
                    <?php wp_nonce_field('kop_manage_featured'); ?>
                    <input type="hidden" name="action" value="feature">
                    <input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>">
                    <input type="text" class="note" name="note" value="<?php echo esc_attr($r['featured_note'] ?? ''); ?>"
                           placeholder="Why this report matters, in one line" style="max-width:280px">
                    <button type="submit" class="small">feature</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
<?php endif; ?>

<script src="admin-inline-actions.js?v=1"></script>
</body></html>
