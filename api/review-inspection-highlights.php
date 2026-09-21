<?php
/**
 * Review inspection highlights - admin tool.
 *
 * api/scan-inspection-highlights.php queues the serious findings it reads out
 * of the inspection reports; this is where a person confirms the reading.
 * Each candidate names a facility and describes harm, so nothing is shown on
 * the site until it is approved here. The most recent severe findings lead,
 * since those are what the site shows once approved; the excerpt is the
 * state's own words, and the full report opens underneath it.
 *
 * Admin-only. Loads WordPress via config.php.
 */

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/inc/inspection-highlights.php';

if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not authorized. Log in to WordPress as an administrator first.';
    exit;
}

$categories = kop_ih_categories();
$statuses = array('pending', 'approved', 'rejected');

// ---------------------------------------------------------------------------
// The full text of one report, for the panel under a candidate
// ---------------------------------------------------------------------------
if (isset($_GET['report'])) {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare('SELECT raw_content, categories_json FROM inspection_reports WHERE id = ?');
    $stmt->execute(array((int) $_GET['report']));
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    $text = '';
    if ($r) {
        $data = json_decode((string) $r['categories_json'], true);
        foreach ((array) $data as $k => $v) {
            if (is_scalar($v) && trim((string) $v) !== '') $text .= $k . ': ' . trim((string) $v) . "\n\n";
        }
        if (trim((string) $r['raw_content']) !== '') $text .= "Report text as scraped:\n\n" . trim((string) $r['raw_content']);
    }
    echo json_encode(array('success' => (bool) $r, 'text' => $text));
    exit;
}

// ---------------------------------------------------------------------------
// Decisions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('kop_review_highlights')) {
    header('Content-Type: application/json');
    $id = (int) ($_POST['id'] ?? 0);
    $decision = (string) ($_POST['decision'] ?? '');
    $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
    if (!in_array($decision, $statuses, true)) {
        echo json_encode(array('success' => false, 'error' => 'Unknown decision.'));
        exit;
    }
    try {
        $user = wp_get_current_user();
        $pending = $decision === 'pending';
        $stmt = $pdo->prepare('UPDATE inspection_highlights SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?');
        $stmt->execute(array(
            $decision, $note !== '' ? $note : null,
            $pending ? null : $user->user_login, $pending ? null : gmdate('Y-m-d H:i:s'), $id,
        ));
        echo json_encode(array('success' => true, 'status' => $decision));
    } catch (PDOException $e) {
        echo json_encode(array('success' => false, 'error' => $e->getMessage()));
    }
    exit;
}

// ---------------------------------------------------------------------------
// The queue
// ---------------------------------------------------------------------------
header('Content-Type: text/html; charset=utf-8');

$status = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : 'pending';
$state = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($_GET['state'] ?? '')));
$category = isset($categories[$_GET['category'] ?? '']) ? $_GET['category'] : '';
$min = max(0, min(100, (int) ($_GET['min'] ?? 0)));
$q = trim((string) ($_GET['q'] ?? ''));
// Recent severe findings lead by default: they are the ones the site shows.
$sort = ($_GET['sort'] ?? '') === 'worst' ? 'worst' : 'recent';
$order = $sort === 'worst'
    ? 'h.score DESC, h.finding_date DESC, h.id DESC'
    : '(h.score >= ' . (int) kop_ih_severe_score() . ') DESC, (h.finding_date IS NULL) ASC, h.finding_date DESC, h.score DESC, h.id DESC';
$page = max(1, (int) ($_GET['paged'] ?? 1));
$per_page = 40;

$rows = array();
$total = 0;
$counts = array();
$error = '';
try {
    // Creates the tables when missing and brings an older table up to date.
    kop_ih_ensure_tables($pdo);
    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM inspection_highlights GROUP BY status') as $r) $counts[$r['status']] = (int) $r['n'];

    $where = array('h.status = ?');
    $params = array($status);
    if ($state !== '') { $where[] = 'h.state = ?'; $params[] = $state; }
    if ($category !== '') { $where[] = 'FIND_IN_SET(?, h.categories)'; $params[] = $category; }
    if ($min > 0) { $where[] = 'h.score >= ?'; $params[] = $min; }
    if ($q !== '') { $where[] = 'f.facility_name LIKE ?'; $params[] = '%' . $q . '%'; }
    $sql_where = implode(' AND ', $where);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inspection_highlights h JOIN inspection_facilities f ON f.id = h.facility_id WHERE $sql_where");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT h.*, f.facility_name, r.report_date, r.report_url, r.report_id AS source_report_id
        FROM inspection_highlights h
        JOIN inspection_facilities f ON f.id = h.facility_id
        JOIN inspection_reports r ON r.id = h.report_id
        WHERE $sql_where ORDER BY $order LIMIT " . (int) $per_page . ' OFFSET ' . (int) (($page - 1) * $per_page));
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Could not read the highlights: ' . $e->getMessage();
}

$kop_link = static function (array $change) {
    $args = array_merge($_GET, $change);
    unset($args['report']);
    return '?' . http_build_query(array_filter($args, static function ($v) { return $v !== '' && $v !== null; }));
};
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Review Inspection Highlights</title>
<style>
body { font-family: system-ui, sans-serif; margin: 24px; color: #000435; background: #F2EEDF; }
h1 { font-size: 1.3rem; }
.muted { color: #666; }
.error { background: #fff; border: 2px solid #c0392b; border-radius: 8px; padding: 10px 14px; margin: 10px 0; max-width: 860px; color: #c0392b; }
.tabs a { display: inline-block; padding: 6px 12px; margin-right: 6px; border-radius: 6px; background: #fff; color: #000080; text-decoration: none; font-weight: 700; }
.tabs a.on { background: #000080; color: #fff; }
form.filters { margin: 14px 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
input[type=text], input[type=search], input[type=number], select { padding: 6px 9px; border: 1px solid #bbb; border-radius: 4px; font: inherit; }
button { background: #000080; color: #fff; border: none; border-radius: 6px; padding: 6px 12px; font-weight: 700; cursor: pointer; }
button.secondary { background: #33A7B5; }
button.danger { background: #c0392b; }
button.plain { background: #fff; color: #000080; border: 1px solid #000080; }
.card { background: #fff; border: 1px solid #ccc; border-left: 6px solid #33A7B5; border-radius: 8px; padding: 12px 16px; margin: 12px 0; max-width: 980px; }
.card.s90 { border-left-color: #c0392b; } .card.s70 { border-left-color: #EF9034; }
.card.done { opacity: 0.55; }
.head { display: flex; flex-wrap: wrap; gap: 8px 14px; align-items: baseline; }
.score { font-size: 1.25rem; font-weight: 800; }
.pill { display: inline-block; border: 2px solid #FE8088; border-radius: 999px; padding: 1px 9px; font-size: 0.78rem; font-weight: 700; }
.pill.state { border-color: #33A7B5; }
.pill.severe { border-color: #c0392b; color: #c0392b; }
blockquote { margin: 10px 0; padding: 8px 14px; background: #FFF5CB; border-left: 4px solid #EF9034; line-height: 1.5; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 8px; }
.actions input { flex: 1 1 260px; max-width: 420px; }
pre.full { white-space: pre-wrap; background: #F2EEDF; padding: 10px; border-radius: 6px; max-height: 420px; overflow: auto; font: 0.82rem/1.5 system-ui, sans-serif; }
.pager a { margin-right: 10px; }
</style></head><body>
<h1>Review inspection highlights</h1>
<p class="muted">Candidates read out of the inspection reports by the parser. Nothing here is on the site until it is approved. Approved findings scoring <?php echo (int) kop_ih_severe_score(); ?> or more appear on the home page and the inspection reports hub, most recent first. The excerpt is the state's own wording; open the full report before approving, because the parser cannot tell who did what to whom.</p>

<?php if ($error): ?><div class="error"><?php echo esc_html($error); ?></div><?php endif; ?>

<p class="tabs">
<?php foreach ($statuses as $s): ?>
    <a class="<?php echo $s === $status ? 'on' : ''; ?>" href="<?php echo esc_attr($kop_link(array('status' => $s, 'paged' => ''))); ?>"><?php echo esc_html(ucfirst($s)); ?> (<?php echo (int) ($counts[$s] ?? 0); ?>)</a>
<?php endforeach; ?>
</p>

<form class="filters" method="get">
    <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
    <input type="search" name="q" placeholder="Facility name" value="<?php echo esc_attr($q); ?>">
    <select name="state"><option value="">All states</option>
        <?php foreach (kop_ih_supported_states() as $s): ?><option value="<?php echo esc_attr($s); ?>"<?php echo $s === $state ? ' selected' : ''; ?>><?php echo esc_html($s); ?></option><?php endforeach; ?>
    </select>
    <select name="category"><option value="">All categories</option>
        <?php foreach ($categories as $key => $cat): ?><option value="<?php echo esc_attr($key); ?>"<?php echo $key === $category ? ' selected' : ''; ?>><?php echo esc_html($cat['label']); ?></option><?php endforeach; ?>
    </select>
    <select name="sort">
        <option value="recent"<?php echo $sort === 'recent' ? ' selected' : ''; ?>>Most recent severe first</option>
        <option value="worst"<?php echo $sort === 'worst' ? ' selected' : ''; ?>>Worst first</option>
    </select>
    <label>Score at least <input type="number" name="min" min="0" max="100" step="5" value="<?php echo (int) $min; ?>" style="width:70px"></label>
    <button type="submit">Filter</button>
    <span class="muted"><?php echo (int) $total; ?> shown by these filters</span>
</form>

<?php foreach ($rows as $row):
    $source = kop_ih_source_url($row);
    $when = $row['finding_date'] ? date_i18n('F j, Y', strtotime($row['finding_date'] . ' 12:00:00')) : $row['report_date'];
    $severe = (int) $row['score'] >= kop_ih_severe_score();
    $band = $row['score'] >= 90 ? 's90' : ($row['score'] >= 70 ? 's70' : '');
?>
<div class="card <?php echo $band; ?>" data-id="<?php echo (int) $row['id']; ?>" data-report="<?php echo (int) $row['report_id']; ?>">
    <div class="head">
        <span class="score"><?php echo (int) $row['score']; ?></span>
        <strong><?php echo esc_html($row['facility_name']); ?></strong>
        <span class="muted"><?php echo esc_html($row['state']); ?> &middot; <?php echo esc_html($when); ?></span>
        <?php if ($severe): ?><span class="pill severe">Severe: shown on the site once approved</span><?php endif; ?>
        <?php if ($row['state_label']): ?><span class="pill state"><?php echo esc_html($row['state_label']); ?></span><?php endif; ?>
        <?php foreach (explode(',', $row['categories']) as $key): if (isset($categories[$key])): ?><span class="pill"><?php echo esc_html($categories[$key]['label']); ?></span><?php endif; endforeach; ?>
        <?php if ($row['corrected_on_site']): ?><span class="muted">corrected at the inspection</span><?php endif; ?>
    </div>
    <?php if ($row['standard']): ?><div class="muted" style="margin-top:4px">Cited: <?php echo esc_html($row['standard']); ?></div><?php endif; ?>
    <blockquote><?php echo esc_html($row['excerpt']); ?></blockquote>
    <?php if ($row['status'] !== 'pending'): ?><div class="muted"><?php echo esc_html(ucfirst($row['status'])); ?> by <?php echo esc_html($row['reviewed_by']); ?>, <?php echo esc_html($row['reviewed_at']); ?> UTC<?php echo $row['review_note'] ? ': ' . esc_html($row['review_note']) : ''; ?></div><?php endif; ?>
    <div class="actions">
        <button type="button" class="plain" data-act="full">Full report</button>
        <?php if ($source): ?><a href="<?php echo esc_url($source); ?>" target="_blank" rel="noopener">State source</a><?php endif; ?>
        <input type="text" class="note" placeholder="Note (optional)" value="<?php echo esc_attr((string) $row['review_note']); ?>">
        <?php if ($row['status'] !== 'approved'): ?><button type="button" class="secondary" data-act="approved">Approve</button><?php endif; ?>
        <?php if ($row['status'] !== 'rejected'): ?><button type="button" class="danger" data-act="rejected">Reject</button><?php endif; ?>
        <?php if ($row['status'] !== 'pending'): ?><button type="button" class="plain" data-act="pending">Back to pending</button><?php endif; ?>
    </div>
    <pre class="full" hidden></pre>
</div>
<?php endforeach; ?>

<?php if (!$rows && !$error): ?><p class="muted">Nothing here.</p><?php endif; ?>

<p class="pager">
    <?php if ($page > 1): ?><a href="<?php echo esc_attr($kop_link(array('paged' => $page - 1))); ?>">Previous</a><?php endif; ?>
    <?php if ($page * $per_page < $total): ?><a href="<?php echo esc_attr($kop_link(array('paged' => $page + 1))); ?>">Next</a><?php endif; ?>
</p>

<script>
(function () {
    var nonce = <?php echo json_encode(wp_create_nonce('kop_review_highlights')); ?>;
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-act]');
        if (!btn) return;
        var card = btn.closest('.card');
        var act = btn.getAttribute('data-act');
        if (act === 'full') {
            var pre = card.querySelector('pre.full');
            if (!pre.hidden) { pre.hidden = true; return; }
            pre.hidden = false;
            if (pre.textContent) return;
            pre.textContent = 'Loading...';
            fetch('?report=' + card.getAttribute('data-report'), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { pre.textContent = j.success ? j.text : 'Report not found.'; })
                .catch(function () { pre.textContent = 'Could not load the report.'; });
            return;
        }
        var body = new FormData();
        body.append('_wpnonce', nonce);
        body.append('id', card.getAttribute('data-id'));
        body.append('decision', act);
        body.append('note', card.querySelector('.note').value);
        btn.disabled = true;
        fetch(location.pathname, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j.success) { alert(j.error || 'Could not save.'); btn.disabled = false; return; }
                card.classList.add('done');
                card.querySelector('.actions').innerHTML = '<span class="muted">Marked ' + j.status + '.</span>';
            })
            .catch(function () { alert('Could not save.'); btn.disabled = false; });
    });
})();
</script>
</body></html>
