<?php
/**
 * Journalists who cover the TTI — internal admin tool.
 *
 * The list is extracted from the bylines on news entries (see
 * api/lib-journalists.php); this page rescans them, keeps contact details,
 * outreach status and notes, merges spellings of one person, and ignores
 * bylines that are not journalists. ?id=N opens one journalist, ?export=csv
 * downloads the list.
 *
 * Internal only: admin-gated here and never exposed through REST, search or
 * a public page. Loads WordPress via config.php.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib-journalists.php';

if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not authorized. Log in to WordPress as an administrator first.';
    exit;
}

$notices = [];
$errors = [];
$states = kop_journalist_outreach_states();
$fields = ['name', 'aliases', 'outlet', 'email', 'phone', 'social', 'website', 'location', 'beat', 'notes'];
$statusList = "'" . implode("','", kop_journalist_article_statuses()) . "'";
$self = strtok($_SERVER['REQUEST_URI'] ?? 'manage-journalists.php', '?');

/** Form values for the editable fields, trimmed. */
function kop_journalist_form_values(array $fields): array {
    $out = [];
    foreach ($fields as $f) {
        $out[$f] = trim((string) wp_unslash($_POST[$f] ?? ''));
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('kop_manage_journalists')) {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($action === 'scan') {
            $t = kop_journalist_sync_all($pdo);
            $notices[] = "Scanned {$t['articles']} news entries: {$t['created']} new journalist(s), {$t['linked']} article link(s).";
        } elseif ($action === 'create') {
            $v = kop_journalist_form_values($fields);
            $key = kop_journalist_name_key($v['name']);
            if ($key === '') {
                $errors[] = 'Name is required.';
            } else {
                $exists = $pdo->prepare("SELECT id FROM journalists WHERE name_key = ?");
                $exists->execute([$key]);
                if ($existing = (int) $exists->fetchColumn()) {
                    $errors[] = "A journalist with that name already exists (#{$existing}).";
                } else {
                    $pdo->prepare("INSERT INTO journalists (name, name_key, source) VALUES (?, ?, 'manual')")
                        ->execute([$v['name'], $key]);
                    $id = (int) $pdo->lastInsertId();
                    $index = null;
                    $ids = $pdo->query("SELECT id FROM news_submissions WHERE author LIKE " . $pdo->quote('%' . $v['name'] . '%'))
                        ->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($ids as $newsId) {
                        kop_journalist_sync_article($pdo, (int) $newsId, $index);
                    }
                    $count = $pdo->prepare("SELECT COUNT(*) FROM journalist_articles WHERE journalist_id = ?");
                    $count->execute([$id]);
                    $linked = (int) $count->fetchColumn();
                    $notices[] = "Added {$v['name']} (#{$id}); linked to {$linked} news entr" . ($linked === 1 ? 'y' : 'ies') . '.';
                }
            }
        } elseif ($action === 'update' && $id > 0) {
            $v = kop_journalist_form_values($fields);
            $key = kop_journalist_name_key($v['name']);
            $outreach = isset($states[$_POST['outreach'] ?? '']) ? $_POST['outreach'] : 'not_contacted';
            if ($key === '') {
                $errors[] = 'Name is required.';
            } else {
                $clash = $pdo->prepare("SELECT id FROM journalists WHERE name_key = ? AND id <> ?");
                $clash->execute([$key, $id]);
                if ($other = (int) $clash->fetchColumn()) {
                    $errors[] = "Another journalist already has that name (#{$other}). Merge them instead.";
                } else {
                    $pdo->prepare(
                        "UPDATE journalists SET name = ?, name_key = ?, aliases = ?, outlet = ?, email = ?, phone = ?,
                                social = ?, website = ?, location = ?, beat = ?, notes = ?, outreach = ?
                         WHERE id = ?"
                    )->execute([
                        $v['name'], $key, $v['aliases'], $v['outlet'], $v['email'], $v['phone'],
                        $v['social'], $v['website'], $v['location'], $v['beat'], $v['notes'], $outreach, $id,
                    ]);
                    $notices[] = 'Saved.';
                }
            }
        } elseif ($action === 'ignore' && $id > 0) {
            $pdo->prepare("UPDATE journalists SET status = 'ignored' WHERE id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM journalist_articles WHERE journalist_id = ?")->execute([$id]);
            $notices[] = "Ignored #{$id}: it stays out of the list and future scans skip the name.";
        } elseif ($action === 'restore' && $id > 0) {
            $pdo->prepare("UPDATE journalists SET status = 'active' WHERE id = ?")->execute([$id]);
            kop_journalist_sync_all($pdo);
            $notices[] = "Restored #{$id} and relinked its articles.";
        } elseif ($action === 'delete' && $id > 0) {
            $pdo->prepare("DELETE FROM journalist_articles WHERE journalist_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM journalists WHERE id = ? AND source = 'manual'")->execute([$id]);
            $notices[] = "Deleted #{$id}.";
            $id = 0;
        } elseif ($action === 'merge' && $id > 0) {
            $into = (int) ($_POST['into_id'] ?? 0);
            kop_journalist_merge($pdo, $into, $id);
            $notices[] = "Merged #{$id} into #{$into}; the old name is kept as an alias.";
            $id = $into;
        }
    } catch (PDOException $e) {
        $errors[] = 'Database error: ' . $e->getMessage()
            . ' (has api/update-schema.php been run since the journalists tables were added?)';
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
    }
    if ($id > 0 && !$errors && in_array($action, ['update', 'merge', 'create', 'restore'], true)) {
        $_GET['id'] = $id;
    }
}

// ---------------------------------------------------------------------------
// Data for the page
// ---------------------------------------------------------------------------
$q = trim((string) wp_unslash($_GET['q'] ?? ''));
$filterOutreach = isset($states[$_GET['outreach'] ?? '']) ? $_GET['outreach'] : '';
$showIgnored = !empty($_GET['ignored']);
$detailId = (int) ($_GET['id'] ?? 0);
$rows = [];
$detail = null;
$articles = [];
$skipped = [];

try {
    $rows = $pdo->query(
        "SELECT j.*, COUNT(n.id) AS article_count, MIN(n.publication_date) AS first_date,
                MAX(n.publication_date) AS last_date,
                GROUP_CONCAT(DISTINCT n.publication_name ORDER BY n.publication_name SEPARATOR '|') AS outlets
         FROM journalists j
         LEFT JOIN journalist_articles ja ON ja.journalist_id = j.id
         LEFT JOIN news_submissions n ON n.id = ja.news_id AND n.status IN ($statusList)
         GROUP BY j.id
         ORDER BY j.status ASC, article_count DESC, j.name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($detailId > 0) {
        foreach ($rows as $r) {
            if ((int) $r['id'] === $detailId) {
                $detail = $r;
            }
        }
        $stmt = $pdo->prepare(
            "SELECT n.id, n.article_title, n.publication_name, n.publication_date, n.article_url, n.status, n.author
             FROM journalist_articles ja JOIN news_submissions n ON n.id = ja.news_id
             WHERE ja.journalist_id = ? ORDER BY n.publication_date DESC, n.id DESC"
        );
        $stmt->execute([$detailId]);
        $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Bylines the scan left out, so nothing disappears silently.
        $index = kop_journalist_index($pdo);
        foreach ($pdo->query("SELECT author FROM news_submissions WHERE status IN ($statusList)") as $a) {
            foreach (kop_journalist_split_byline($a['author']) as $name) {
                if (!isset($index[kop_journalist_name_key($name)])) {
                    $skipped[$name] = ($skipped[$name] ?? 0) + 1;
                }
            }
        }
        arsort($skipped);
    }
} catch (PDOException $e) {
    $errors[] = 'Could not load journalists: ' . $e->getMessage()
        . ' — run api/update-schema.php first to create the journalists tables.';
}

/** Outlet to show: the saved one, else the outlets of their articles. */
function kop_journalist_outlet_label(array $r): string {
    if (trim((string) $r['outlet']) !== '') {
        return $r['outlet'];
    }
    return str_replace('|', ', ', (string) $r['outlets']);
}

if (($_GET['export'] ?? '') === 'csv' && !$errors) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kop-journalists-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'name', 'aliases', 'outlet', 'outlets_from_articles', 'articles', 'first_article', 'last_article',
        'email', 'phone', 'social', 'website', 'location', 'beat', 'outreach', 'notes']);
    foreach ($rows as $r) {
        if ($r['status'] !== 'active') {
            continue;
        }
        fputcsv($out, [$r['id'], $r['name'], $r['aliases'], $r['outlet'], str_replace('|', '; ', (string) $r['outlets']),
            $r['article_count'], $r['first_date'], $r['last_date'], $r['email'], $r['phone'], $r['social'],
            $r['website'], $r['location'], $r['beat'], $states[$r['outreach']] ?? $r['outreach'], $r['notes']]);
    }
    exit;
}

$visible = array_filter($rows, static function ($r) use ($q, $filterOutreach, $showIgnored) {
    if ($r['status'] === 'ignored' && !$showIgnored) {
        return false;
    }
    if ($filterOutreach !== '' && $r['outreach'] !== $filterOutreach) {
        return false;
    }
    if ($q !== '') {
        $hay = strtolower(implode(' ', [$r['name'], $r['aliases'], $r['outlet'], $r['outlets'], $r['email'], $r['beat'], $r['location']]));
        return strpos($hay, strtolower($q)) !== false;
    }
    return true;
});
$activeCount = count(array_filter($rows, static fn($r) => $r['status'] === 'active'));

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="robots" content="noindex, nofollow"><title>Journalists (internal)</title>
<style>
body { font-family: system-ui, sans-serif; margin: 24px; color: #000435; background: #F2EEDF; }
h1 { font-size: 1.3rem; } h2 { font-size: 1.05rem; margin-top: 28px; }
table { border-collapse: collapse; background: #fff; font-size: 0.85rem; width: 100%; }
th, td { border: 1px solid #ccc; padding: 5px 9px; text-align: left; vertical-align: top; }
th { background: #000080; color: #fff; }
.muted { color: #666; }
.notice { background: #FFF5CB; border: 2px solid #33A7B5; border-radius: 8px; padding: 10px 14px; margin: 10px 0; max-width: 860px; }
.error { background: #fff; border: 2px solid #c0392b; border-radius: 8px; padding: 10px 14px; margin: 10px 0; max-width: 860px; color: #c0392b; }
.internal { background: #000435; color: #fff; border-radius: 6px; padding: 6px 12px; display: inline-block; font-size: 0.8rem; font-weight: 700; }
.panel { background: #fff; border: 2px solid #33A7B5; border-radius: 8px; padding: 14px 18px; margin: 16px 0; max-width: 980px; }
.panel.dashed { border: 2px dashed #000080; }
.toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 12px 0; }
.toolbar input[type=text], .toolbar select { width: auto; }
label { display: block; font-weight: 600; margin: 8px 0 2px; font-size: 0.85rem; }
input[type=text], input[type=email], textarea, select { width: 100%; max-width: 640px; padding: 5px 7px; border: 1px solid #bbb; border-radius: 4px; font: inherit; box-sizing: border-box; }
textarea { min-height: 60px; }
button, a.button { background: #000080; color: #fff; border: none; border-radius: 6px; padding: 7px 14px; font-weight: 700; cursor: pointer; margin-top: 8px; text-decoration: none; display: inline-block; font-size: 0.85rem; }
button.secondary, a.button.secondary { background: #33A7B5; }
button.danger { background: #c0392b; }
button.small { padding: 3px 8px; font-size: 0.78rem; margin-top: 0; }
.hint { font-size: 0.78rem; color: #666; margin: 2px 0 0; }
.pill { font-size: 0.75rem; border: 1px solid #33A7B5; border-radius: 10px; padding: 1px 7px; white-space: nowrap; }
.pill.do_not_contact { border-color: #c0392b; color: #c0392b; }
.pill.ignored { border-color: #999; color: #666; }
form.inline { display: inline; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 0 18px; }
@media (max-width: 700px) { body { margin: 12px; } .scroll { overflow-x: auto; } }
</style></head><body>
<h1>Journalists covering the TTI</h1>
<p><span class="internal">Internal only. Not for publication.</span></p>
<p style="max-width:860px">Built from the bylines on our news entries (rejected and deleted entries don't count).
Rescan after importing articles; new entries saved through the news processor link on save.
Add contact details and outreach notes here. Bylines that aren't people (outlets, “Staff”, “Unknown”) are skipped and
listed at the bottom. Use <strong>Ignore</strong> on anything that slipped through and <strong>Merge</strong> for two spellings of one person.</p>

<?php foreach ($notices as $n): ?><div class="notice"><?php echo esc_html($n); ?></div><?php endforeach; ?>
<?php foreach ($errors as $e): ?><div class="error"><?php echo esc_html($e); ?></div><?php endforeach; ?>

<?php if ($detailId > 0): ?>
    <p><a href="<?php echo esc_url($self); ?>">&larr; All journalists</a></p>
    <?php if (!$detail): ?>
        <p class="muted">Journalist #<?php echo $detailId; ?> not found.</p>
    <?php else: $did = (int) $detail['id']; ?>
    <div class="panel">
        <h2 style="margin-top:0"><?php echo esc_html($detail['name']); ?>
            <span class="muted">(#<?php echo $did; ?>, <?php echo esc_html($detail['source']); ?><?php echo $detail['status'] === 'ignored' ? ', ignored' : ''; ?>)</span></h2>
        <p class="muted"><?php echo (int) $detail['article_count']; ?> article(s)<?php
            if ($detail['first_date']) { echo ', ' . esc_html($detail['first_date']) . ' to ' . esc_html($detail['last_date']); } ?>.
            Outlets: <?php echo esc_html(str_replace('|', ', ', (string) $detail['outlets']) ?: 'none'); ?></p>
        <form method="post">
            <?php wp_nonce_field('kop_manage_journalists'); ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?php echo $did; ?>">
            <div class="grid">
                <div>
                    <label>Name</label>
                    <input type="text" name="name" value="<?php echo esc_attr($detail['name']); ?>" required>
                    <label>Current outlet</label>
                    <input type="text" name="outlet" value="<?php echo esc_attr($detail['outlet'] ?? ''); ?>" placeholder="Blank = outlets from their articles">
                    <label>Email</label>
                    <input type="email" name="email" value="<?php echo esc_attr($detail['email'] ?? ''); ?>">
                    <label>Phone</label>
                    <input type="text" name="phone" value="<?php echo esc_attr($detail['phone'] ?? ''); ?>">
                    <label>Website</label>
                    <input type="text" name="website" value="<?php echo esc_attr($detail['website'] ?? ''); ?>">
                    <label>Location</label>
                    <input type="text" name="location" value="<?php echo esc_attr($detail['location'] ?? ''); ?>">
                </div>
                <div>
                    <label>Outreach</label>
                    <select name="outreach">
                        <?php foreach ($states as $val => $lbl): ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected($detail['outreach'], $val); ?>><?php echo esc_html($lbl); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Social profiles (one per line)</label>
                    <textarea name="social"><?php echo esc_textarea($detail['social'] ?? ''); ?></textarea>
                    <label>Beat / what they cover</label>
                    <textarea name="beat"><?php echo esc_textarea($detail['beat'] ?? ''); ?></textarea>
                    <label>Other spellings (one per line)</label>
                    <textarea name="aliases"><?php echo esc_textarea($detail['aliases'] ?? ''); ?></textarea>
                    <p class="hint">Bylines matching these link here too.</p>
                </div>
            </div>
            <label>Notes</label>
            <textarea name="notes" style="max-width:none;min-height:100px"><?php echo esc_textarea($detail['notes'] ?? ''); ?></textarea>
            <button type="submit">Save</button>
        </form>

        <div class="toolbar">
            <form method="post" class="inline">
                <?php wp_nonce_field('kop_manage_journalists'); ?>
                <input type="hidden" name="id" value="<?php echo $did; ?>">
                <?php if ($detail['status'] === 'ignored'): ?>
                    <input type="hidden" name="action" value="restore">
                    <button type="submit" class="secondary">Restore</button>
                <?php else: ?>
                    <input type="hidden" name="action" value="ignore">
                    <button type="submit" class="danger" title="Not a journalist: hide it and keep future scans from re-adding it">Ignore</button>
                <?php endif; ?>
            </form>
            <?php if ($detail['source'] === 'manual'): ?>
            <form method="post" class="inline" onsubmit="return confirm('Delete this journalist?');">
                <?php wp_nonce_field('kop_manage_journalists'); ?>
                <input type="hidden" name="id" value="<?php echo $did; ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="danger">Delete</button>
            </form>
            <?php endif; ?>
            <form method="post" class="inline" onsubmit="return confirm('Merge this journalist into the one selected? This row is removed.');">
                <?php wp_nonce_field('kop_manage_journalists'); ?>
                <input type="hidden" name="id" value="<?php echo $did; ?>">
                <input type="hidden" name="action" value="merge">
                <select name="into_id" style="width:auto;max-width:260px">
                    <?php foreach ($rows as $r): if ((int) $r['id'] === $did || $r['status'] !== 'active') { continue; } ?>
                        <option value="<?php echo (int) $r['id']; ?>"><?php echo esc_html($r['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="secondary">Merge into</button>
            </form>
        </div>
    </div>

    <h2>Articles (<?php echo count($articles); ?>)</h2>
    <div class="scroll"><table><thead><tr><th>ID</th><th>Title</th><th>Outlet</th><th>Date</th><th>Byline</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($articles as $a): ?>
        <tr>
            <td><?php echo (int) $a['id']; ?></td>
            <td><?php if (!empty($a['article_url'])): ?>
                    <a href="<?php echo esc_url($a['article_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($a['article_title']); ?></a>
                <?php else: echo esc_html($a['article_title']); endif; ?></td>
            <td><?php echo esc_html($a['publication_name'] ?? ''); ?></td>
            <td><?php echo esc_html($a['publication_date'] ?? ''); ?></td>
            <td class="muted"><?php echo esc_html($a['author'] ?? ''); ?></td>
            <td><?php echo esc_html($a['status']); ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>

<?php else: ?>
    <div class="toolbar">
        <form method="post" class="inline">
            <?php wp_nonce_field('kop_manage_journalists'); ?>
            <input type="hidden" name="action" value="scan">
            <button type="submit" title="Relink every news entry to its journalists and add new names. Safe to rerun.">Scan news entries</button>
        </form>
        <a class="button secondary" href="<?php echo esc_url(add_query_arg('export', 'csv', $self)); ?>">Download CSV</a>
    </div>

    <form method="get" class="toolbar">
        <input type="text" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Name, outlet, email, beat…" style="max-width:280px">
        <select name="outreach">
            <option value="">Any outreach status</option>
            <?php foreach ($states as $val => $lbl): ?>
                <option value="<?php echo esc_attr($val); ?>" <?php selected($filterOutreach, $val); ?>><?php echo esc_html($lbl); ?></option>
            <?php endforeach; ?>
        </select>
        <label style="display:inline;font-weight:400"><input type="checkbox" name="ignored" value="1" <?php checked($showIgnored); ?>> show ignored</label>
        <button type="submit" class="secondary" style="margin-top:0">Filter</button>
    </form>

    <h2><?php echo count($visible); ?> shown of <?php echo $activeCount; ?> journalists</h2>
    <?php if (!$rows && !$errors): ?>
        <p class="muted">No journalists yet. Press <strong>Scan news entries</strong> to extract them from the news bylines.</p>
    <?php endif; ?>
    <div class="scroll"><table><thead><tr><th>Name</th><th>Outlet(s)</th><th>Articles</th><th>Latest</th><th>Email</th><th>Outreach</th></tr></thead><tbody>
    <?php foreach ($visible as $r): ?>
        <tr>
            <td><a href="<?php echo esc_url(add_query_arg('id', (int) $r['id'], $self)); ?>"><?php echo esc_html($r['name']); ?></a>
                <?php if ($r['status'] === 'ignored'): ?><span class="pill ignored">ignored</span><?php endif; ?></td>
            <td><?php echo esc_html(kop_journalist_outlet_label($r)); ?></td>
            <td><?php echo (int) $r['article_count']; ?></td>
            <td><?php echo esc_html($r['last_date'] ?? ''); ?></td>
            <td><?php echo $r['email'] ? '<a href="mailto:' . esc_attr($r['email']) . '">' . esc_html($r['email']) . '</a>' : ''; ?></td>
            <td><span class="pill <?php echo esc_attr($r['outreach']); ?>"><?php echo esc_html($states[$r['outreach']] ?? $r['outreach']); ?></span></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>

    <h2>Add a journalist</h2>
    <div class="panel dashed">
        <form method="post">
            <?php wp_nonce_field('kop_manage_journalists'); ?>
            <input type="hidden" name="action" value="create">
            <label>Name</label>
            <input type="text" name="name" required placeholder="Jane Doe">
            <button type="submit">Add</button>
            <p class="hint">News entries whose byline names them are linked at once. Fill in contact details on their page.</p>
        </form>
    </div>

    <h2>Skipped bylines (<?php echo count($skipped); ?>)</h2>
    <p class="hint" style="max-width:860px">Byline parts the scan didn't take for a person. If one is a journalist, add it; the next scan links its articles.</p>
    <?php if ($skipped): ?>
    <div class="scroll"><table><thead><tr><th>Byline</th><th>Entries</th><th></th></tr></thead><tbody>
    <?php foreach ($skipped as $name => $n): ?>
        <tr>
            <td><?php echo esc_html($name); ?></td>
            <td><?php echo (int) $n; ?></td>
            <td>
                <form method="post" class="inline">
                    <?php wp_nonce_field('kop_manage_journalists'); ?>
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="name" value="<?php echo esc_attr($name); ?>">
                    <button type="submit" class="small">Add as journalist</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
<?php endif; ?>
</body></html>
