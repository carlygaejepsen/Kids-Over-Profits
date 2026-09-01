<?php
/**
 * Manage ongoing story arcs — admin tool.
 *
 * Create/edit the "big ongoing stories" featured on the news feed (a lawsuit
 * with months of developments, a facility's closure saga), review each arc's
 * member articles, attach/detach articles manually, and scan the archive to
 * auto-attach articles matching an arc's terms.
 *
 * Admin-only. Loads WordPress via config.php.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/news-story-arcs.php';

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('kop_manage_arcs')) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($title === '') {
                $errors[] = 'Title is required.';
            } else {
                $slug = kop_news_arc_slug($title);
                $stmt = $pdo->prepare(
                    "INSERT INTO news_story_arcs (title, slug, description, match_terms, display_order)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $title,
                    $slug,
                    trim((string) ($_POST['description'] ?? '')),
                    trim((string) ($_POST['match_terms'] ?? '')),
                    (int) ($_POST['display_order'] ?? 0),
                ]);
                $arcId = (int) $pdo->lastInsertId();
                $attached = kop_news_arc_scan($pdo, $arcId);
                $notices[] = "Created arc “{$title}” (slug: {$slug}) — {$attached} existing article(s) auto-attached.";
            }
        } elseif ($action === 'update') {
            $arcId = (int) ($_POST['arc_id'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            if ($arcId <= 0 || $title === '') {
                $errors[] = 'Arc id and title are required.';
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE news_story_arcs
                     SET title = ?, description = ?, match_terms = ?, status = ?, display_order = ?
                     WHERE id = ?"
                );
                $stmt->execute([
                    $title,
                    trim((string) ($_POST['description'] ?? '')),
                    trim((string) ($_POST['match_terms'] ?? '')),
                    ($_POST['status'] ?? 'active') === 'archived' ? 'archived' : 'active',
                    (int) ($_POST['display_order'] ?? 0),
                    $arcId,
                ]);
                $notices[] = "Updated arc #{$arcId}.";
            }
        } elseif ($action === 'scan') {
            $arcId = (int) ($_POST['arc_id'] ?? 0);
            $attached = kop_news_arc_scan($pdo, $arcId);
            $notices[] = "Scan complete: {$attached} article(s) attached to arc #{$arcId}.";
        } elseif ($action === 'attach') {
            $arcId = (int) ($_POST['arc_id'] ?? 0);
            $articleId = (int) ($_POST['article_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE news_submissions SET story_arc_id = ? WHERE id = ?");
            $stmt->execute([$arcId, $articleId]);
            $notices[] = $stmt->rowCount()
                ? "Attached article #{$articleId} to arc #{$arcId}."
                : "Article #{$articleId} not found (or already attached).";
        } elseif ($action === 'detach') {
            $articleId = (int) ($_POST['article_id'] ?? 0);
            $pdo->prepare("UPDATE news_submissions SET story_arc_id = NULL WHERE id = ?")
                ->execute([$articleId]);
            $notices[] = "Detached article #{$articleId}.";
        }
    } catch (PDOException $e) {
        $errors[] = 'Database error: ' . $e->getMessage()
            . ' (has api/update-schema.php been run since the arcs migration was added?)';
    }
}

// ---------------------------------------------------------------------------
// Data for the page
// ---------------------------------------------------------------------------
$arcs = [];
$members = [];   // arc_id => rows
$search_results = [];
$search_q = trim((string) ($_GET['q'] ?? ''));

try {
    $arcs = $pdo->query(
        "SELECT a.*,
                (SELECT COUNT(*) FROM news_submissions s
                 WHERE s.story_arc_id = a.id AND s.status NOT IN ('rejected','deleted')) AS article_count
         FROM news_story_arcs a
         ORDER BY a.status ASC, a.display_order ASC, a.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($arcs) {
        $ids = array_column($arcs, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, article_title, alternate_title, publication_name, publication_date,
                    article_url, status, story_arc_id
             FROM news_submissions
             WHERE story_arc_id IN ($ph) AND status NOT IN ('rejected','deleted')
             ORDER BY publication_date DESC, id DESC"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $members[(int) $row['story_arc_id']][] = $row;
        }
    }

    if ($search_q !== '') {
        $stmt = $pdo->prepare(
            "SELECT id, article_title, alternate_title, publication_name, publication_date, status, story_arc_id
             FROM news_submissions
             WHERE status NOT IN ('rejected','deleted')
               AND (article_title LIKE ? OR alternate_title LIKE ? OR summary LIKE ?)
             ORDER BY publication_date DESC, id DESC
             LIMIT 50"
        );
        $like = '%' . $search_q . '%';
        $stmt->execute([$like, $like, $like]);
        $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $errors[] = 'Could not load arcs: ' . $e->getMessage()
        . ' — run api/update-schema.php first to create the news_story_arcs table.';
}

$arc_titles = [];
foreach ($arcs as $a) {
    $arc_titles[(int) $a['id']] = $a['title'];
}
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Manage Ongoing Stories</title>
<style>
body { font-family: system-ui, sans-serif; margin: 24px; color: #000435; background: #F2EEDF; }
h1 { font-size: 1.3rem; } h2 { font-size: 1.05rem; margin-top: 28px; }
table { border-collapse: collapse; background: #fff; font-size: 0.85rem; width: 100%; }
th, td { border: 1px solid #ccc; padding: 5px 9px; text-align: left; vertical-align: top; }
th { background: #000080; color: #fff; }
.ok { color: #1b7e3c; } .bad { color: #c0392b; } .muted { color: #666; }
.notice { background: #FFF5CB; border: 2px solid #33A7B5; border-radius: 8px; padding: 10px 14px; margin: 10px 0; max-width: 860px; }
.error { background: #fff; border: 2px solid #c0392b; border-radius: 8px; padding: 10px 14px; margin: 10px 0; max-width: 860px; color: #c0392b; }
.arc { background: #fff; border: 2px solid #33A7B5; border-radius: 8px; padding: 14px 18px; margin: 16px 0; max-width: 980px; }
.arc.archived { border-color: #aaa; opacity: 0.8; }
.arc h3 { margin: 0 0 4px; font-size: 1rem; }
.arc .slug { font-family: monospace; font-size: 0.8rem; color: #000080; }
label { display: block; font-weight: 600; margin: 8px 0 2px; font-size: 0.85rem; }
input[type=text], input[type=number], textarea, select { width: 100%; max-width: 640px; padding: 5px 7px; border: 1px solid #bbb; border-radius: 4px; font: inherit; }
textarea { min-height: 60px; }
button { background: #000080; color: #fff; border: none; border-radius: 6px; padding: 7px 14px; font-weight: 700; cursor: pointer; margin-top: 8px; }
button.secondary { background: #33A7B5; }
button.danger { background: #c0392b; }
button.small { padding: 3px 8px; font-size: 0.78rem; margin-top: 0; }
.hint { font-size: 0.78rem; color: #666; margin: 2px 0 0; }
details.members { margin-top: 10px; }
details.members summary { cursor: pointer; font-weight: 600; }
form.inline { display: inline; }
.new-arc { background: #fff; border: 2px dashed #000080; border-radius: 8px; padding: 14px 18px; margin: 16px 0; max-width: 980px; }
</style></head><body>
<h1>Manage Ongoing Stories</h1>
<p style="max-width:860px">Ongoing stories (arcs) are the big multi-month narratives featured at the top of the
<a href="/news/">news feed</a> — e.g. a lawsuit and everything that follows it, or a facility's closure.
Articles attach automatically when they contain one of an arc's <strong>match terms</strong>
(checked against title, summary, and facility names), or manually below.
This is separate from the automatic "Also covered by" grouping of same-event coverage.</p>

<?php foreach ($notices as $n): ?><div class="notice"><?php echo esc_html($n); ?></div><?php endforeach; ?>
<?php foreach ($errors as $e): ?><div class="error"><?php echo esc_html($e); ?></div><?php endforeach; ?>

<h2>Existing arcs (<?php echo count($arcs); ?>)</h2>
<?php if (!$arcs): ?>
    <p class="muted">No arcs yet — create the first one below (e.g. “Fuller v. Hyde School”).</p>
<?php endif; ?>

<?php foreach ($arcs as $arc): $aid = (int) $arc['id']; ?>
<div class="arc <?php echo $arc['status'] === 'archived' ? 'archived' : ''; ?>">
    <h3><?php echo esc_html($arc['title']); ?>
        <span class="muted">— <?php echo (int) $arc['article_count']; ?> article(s), <?php echo esc_html($arc['status']); ?></span></h3>
    <div class="slug">?story=<?php echo esc_html($arc['slug']); ?></div>

    <form method="post">
        <?php wp_nonce_field('kop_manage_arcs'); ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="arc_id" value="<?php echo $aid; ?>">
        <label>Title</label>
        <input type="text" name="title" value="<?php echo esc_attr($arc['title']); ?>" required>
        <label>Description (shown on the news page)</label>
        <textarea name="description"><?php echo esc_textarea($arc['description'] ?? ''); ?></textarea>
        <label>Match terms (one per line — new articles containing one are auto-attached)</label>
        <textarea name="match_terms" placeholder="hyde school&#10;fuller v. hyde"><?php echo esc_textarea($arc['match_terms'] ?? ''); ?></textarea>
        <p class="hint">Keep terms specific: “hyde school”, not “school”. Checked case-insensitively against title, summary, and facility names.</p>
        <label>Status / order</label>
        <select name="status" style="max-width:160px">
            <option value="active" <?php echo $arc['status'] === 'active' ? 'selected' : ''; ?>>active (featured)</option>
            <option value="archived" <?php echo $arc['status'] === 'archived' ? 'selected' : ''; ?>>archived (hidden from featured)</option>
        </select>
        <input type="number" name="display_order" value="<?php echo (int) $arc['display_order']; ?>" style="max-width:90px" title="Lower = higher on the page">
        <br>
        <button type="submit">Save changes</button>
    </form>
    <form method="post" class="inline">
        <?php wp_nonce_field('kop_manage_arcs'); ?>
        <input type="hidden" name="action" value="scan">
        <input type="hidden" name="arc_id" value="<?php echo $aid; ?>">
        <button type="submit" class="secondary" title="Attach unassigned archive articles matching the saved terms">Scan archive for matches</button>
    </form>

    <details class="members">
        <summary>Member articles (<?php echo count($members[$aid] ?? []); ?>)</summary>
        <table><thead><tr><th>ID</th><th>Title</th><th>Outlet</th><th>Date</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach (($members[$aid] ?? []) as $m):
            $mTitle = !empty($m['alternate_title']) ? $m['alternate_title'] : $m['article_title'];
        ?>
            <tr>
                <td><?php echo (int) $m['id']; ?></td>
                <td><?php if (!empty($m['article_url'])): ?>
                        <a href="<?php echo esc_url($m['article_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($mTitle); ?></a>
                    <?php else: echo esc_html($mTitle); endif; ?></td>
                <td><?php echo esc_html($m['publication_name'] ?? ''); ?></td>
                <td><?php echo esc_html($m['publication_date'] ?? ''); ?></td>
                <td><?php echo esc_html($m['status']); ?></td>
                <td>
                    <form method="post" class="inline">
                        <?php wp_nonce_field('kop_manage_arcs'); ?>
                        <input type="hidden" name="action" value="detach">
                        <input type="hidden" name="article_id" value="<?php echo (int) $m['id']; ?>">
                        <button type="submit" class="danger small">detach</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </details>
</div>
<?php endforeach; ?>

<h2>Create a new arc</h2>
<div class="new-arc">
    <form method="post">
        <?php wp_nonce_field('kop_manage_arcs'); ?>
        <input type="hidden" name="action" value="create">
        <label>Title</label>
        <input type="text" name="title" placeholder="Fuller v. Hyde School" required>
        <label>Description (shown on the news page)</label>
        <textarea name="description" placeholder="A former student's 2026 lawsuit against Hyde School in Bath, Maine, alleging…"></textarea>
        <label>Match terms (one per line)</label>
        <textarea name="match_terms" placeholder="hyde school&#10;fuller v. hyde"></textarea>
        <label>Display order (lower = higher on the page)</label>
        <input type="number" name="display_order" value="0" style="max-width:90px">
        <br>
        <button type="submit">Create arc</button>
        <p class="hint">On creation the archive is scanned once and matching unassigned articles attach automatically.</p>
    </form>
</div>

<h2>Find &amp; attach articles manually</h2>
<form method="get" style="max-width:640px">
    <input type="text" name="q" value="<?php echo esc_attr($search_q); ?>" placeholder="Search titles and summaries…">
    <button type="submit" class="secondary">Search</button>
</form>
<?php if ($search_q !== ''): ?>
    <p class="muted"><?php echo count($search_results); ?> result(s) for “<?php echo esc_html($search_q); ?>” (max 50)</p>
    <?php if ($search_results): ?>
    <table><thead><tr><th>ID</th><th>Title</th><th>Outlet</th><th>Date</th><th>Status</th><th>Current arc</th><th>Attach to…</th></tr></thead><tbody>
    <?php foreach ($search_results as $r):
        $rTitle = !empty($r['alternate_title']) ? $r['alternate_title'] : $r['article_title'];
        $currentArc = $r['story_arc_id'] ? ($arc_titles[(int) $r['story_arc_id']] ?? ('#' . $r['story_arc_id'])) : '—';
    ?>
        <tr>
            <td><?php echo (int) $r['id']; ?></td>
            <td><?php echo esc_html($rTitle); ?></td>
            <td><?php echo esc_html($r['publication_name'] ?? ''); ?></td>
            <td><?php echo esc_html($r['publication_date'] ?? ''); ?></td>
            <td><?php echo esc_html($r['status']); ?></td>
            <td><?php echo esc_html($currentArc); ?></td>
            <td>
                <form method="post" class="inline">
                    <?php wp_nonce_field('kop_manage_arcs'); ?>
                    <input type="hidden" name="action" value="attach">
                    <input type="hidden" name="article_id" value="<?php echo (int) $r['id']; ?>">
                    <select name="arc_id" style="max-width:220px">
                        <?php foreach ($arcs as $a): ?>
                            <option value="<?php echo (int) $a['id']; ?>"><?php echo esc_html($a['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="small">attach</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
<?php endif; ?>

</body></html>
