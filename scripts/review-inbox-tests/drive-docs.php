<?php
/**
 * scripts/test-review-inbox.php checks for the 'drive-docs' source
 * (inc/review-inbox/drive-docs.php over inc/drive-docs.php).
 *
 * {prefix}kop_gdoc_links is not in the mirror: it is made here with the
 * columns of kop_gdl_ensure_table() and a few rows like the real ones, tied
 * to real facilities. Adding to a facility record needs MySQL (write lock,
 * facilities_v2 save) and is not run; sending a link to the news queue and
 * taking it back is.
 */

require_once dirname(__DIR__, 2) . '/inc/closure-reports.php';
require_once dirname(__DIR__, 2) . '/inc/source-submissions.php';
require_once dirname(__DIR__, 2) . '/inc/drive-docs.php';
$GLOBALS['kop_test_options']['kop_gdoc_links_db'] = KOP_GDOC_LINKS_DB_VERSION;
$GLOBALS['kop_test_options']['kop_closure_reports_db'] = KOP_CLOSURE_REPORTS_DB_VERSION;

// WordPress functions the queues call that the harness does not stub.
if (!function_exists('sanitize_key')) {
    function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($v) { return $v; }
}
if (!function_exists('esc_sql')) {
    function esc_sql($s) { return str_replace("'", "''", (string) $s); }
}
if (!function_exists('get_post_status')) {
    function get_post_status($id = null) { return false; }
}
if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link($id = 0, $context = '') { return ''; }
}

(function () {
    $pdo = $GLOBALS['pdo'];
    $pdo->exec('CREATE TABLE IF NOT EXISTS wpdl_kop_gdoc_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT, pkey TEXT NOT NULL UNIQUE, url TEXT NOT NULL, original TEXT NULL,
        domain TEXT NOT NULL DEFAULT \'\', kind TEXT NOT NULL DEFAULT \'\', label TEXT NULL, facility_id INTEGER NOT NULL DEFAULT 0,
        facility_how TEXT NOT NULL DEFAULT \'\', also_named TEXT NULL, operator_name TEXT NOT NULL DEFAULT \'\',
        source_doc TEXT NOT NULL DEFAULT \'\', source TEXT NOT NULL DEFAULT \'\', rhash TEXT NOT NULL DEFAULT \'\', seen TEXT NULL,
        status TEXT NOT NULL DEFAULT \'pending\', applied TEXT NULL, applied_fid INTEGER NOT NULL DEFAULT 0,
        reviewed_by TEXT NULL, reviewed_at TEXT NULL, created_at TEXT NOT NULL)');
    if ((int) $pdo->query('SELECT COUNT(*) FROM wpdl_kop_gdoc_links')->fetchColumn()) return;
    $fids = $pdo->query("SELECT id FROM facilities_v2 WHERE name <> '' ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    $seen = function ($doc, $text) {
        return json_encode(array(array('doc' => $doc, 'tab' => 'Notes', 'heading' => 'Staff', 'text' => $text)));
    };
    $rows = array(
        array('https://example-news.test/2004/school-closes', 'example-news.test', 'news', 'School under investigation, 2004', (int) $fids[0], 'name in text', '', 'Facility notes', 'gdocs', 'pending', null, 0),
        array('https://example-licensing.test/report/77', 'example-licensing.test', 'inspection', 'Licensing report 77', (int) $fids[0], 'folder (close name)', '', 'Facility notes', 'gdocs', 'pending', null, 0),
        array('https://example-people.test/profile/x', 'example-people.test', 'people', 'Staff profile', 0, '', 'Example Holdings', 'Company notes', 'gdocs', 'pending', null, 0),
        array('https://example-ref.test/a', 'example-ref.test', 'reference', 'Reference page', (int) $fids[1], 'name in text', '', 'Facility notes', 'heal', 'applied', '{"target":"resource","url":"https://example-ref.test/a"}', (int) $fids[1]),
        array('https://example-social.test/b', 'example-social.test', 'social', 'Survivor post', (int) $fids[1], 'name in text', '', 'Facility notes', 'wiki', 'rejected', '{"reason":"Already on the record."}', 0),
    );
    $ins = $pdo->prepare('INSERT INTO wpdl_kop_gdoc_links (pkey, url, original, domain, kind, label, facility_id, facility_how, also_named,
        operator_name, source_doc, source, rhash, seen, status, applied, applied_fid, reviewed_by, reviewed_at, created_at)
        VALUES (?, ?, \'\', ?, ?, ?, ?, ?, \'[]\', ?, ?, ?, \'\', ?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as $i => $r) {
        $decided = $r[9] !== 'pending';
        $ins->execute(array(kop_gdl_pkey($r[0]), $r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8],
            $seen($r[7], 'Words around link ' . $i . ' https://x.test/'), $r[9], $r[10], $r[11],
            $decided ? 'someone' : null, $decided ? '2026-10-01 10:00:00' : null, '2026-10-0' . ($i + 1) . ' 09:00:00'));
    }
})();

function kop_rinbox_test_drive_docs(array $src, array $item, callable $check) {
    $row = function ($key) { return kop_gdl_rows(array($key))[0] ?? null; };
    $by_kind = function ($kind) {
        return $GLOBALS['pdo']->query("SELECT pkey FROM wpdl_kop_gdoc_links WHERE kind = '$kind'")->fetchColumn();
    };
    $news = $by_kind('news');
    $res = kop_rinbox_get_item('drive-docs', $news);
    $check('drive-docs: news defaults to the news queue, other targets are moves',
        strpos($res['actions'][0]['label'], 'News queue') !== false && in_array('resource', array_column($res['moves'], 'id'), true)
        && !in_array('news', array_column($res['moves'], 'id'), true));
    $cat = array_values(array_filter($res['fields'], function ($f) { return !empty($f['category']); }));
    $check('drive-docs: kind is the one category field', count($cat) === 1 && $cat[0]['name'] === 'kind');

    // Edit.
    $lic = $by_kind('inspection');
    $before = $row($lic);
    call_user_func($src['save'], $lic, array('label' => '  Licensing   report 77 (2003) ', 'kind' => 'government'));
    $after = $row($lic);
    $check('drive-docs: save relabels and recategorises', $after['label'] === 'Licensing report 77 (2003)' && $after['kind'] === 'government');
    try {
        call_user_func($src['save'], $lic, array('kind' => 'nonsense'));
        $check('drive-docs: an unknown kind is refused', false);
    } catch (RuntimeException $e) {
        $check('drive-docs: an unknown kind is refused', true, $e->getMessage());
    }
    $people = $by_kind('people');
    call_user_func($src['save'], $people, array('facility_id' => (string) $before['facility_id']));
    $p = $row($people);
    $check('drive-docs: picking a facility makes it a sure match', (int) $p['facility_id'] === (int) $before['facility_id'] && kop_gdl_sure_match($p));
    call_user_func($src['save'], $people, array('facility_id' => ''));
    try {
        call_user_func($src['act'], $people, 'apply', array());
        $check('drive-docs: a facility link with no facility is refused', false);
    } catch (RuntimeException $e) {
        $check('drive-docs: a facility link with no facility is refused', strpos($e->getMessage(), 'facility') !== false, $e->getMessage());
    }
    try {
        call_user_func($src['save'], $people, array('facility_id' => '999999999'));
        $check('drive-docs: a facility that does not exist is refused', false);
    } catch (RuntimeException $e) {
        $check('drive-docs: a facility that does not exist is refused', true, $e->getMessage());
    }

    // Skip and back.
    $m = call_user_func($src['act'], $lic, 'reject', array());
    $check('drive-docs: skip', $row($lic)['status'] === 'rejected', $m['message']);
    $m = call_user_func($src['act'], $lic, 'undo', array());
    $check('drive-docs: back to review', $row($lic)['status'] === 'pending', $m['message']);

    // Move to the news queue (the queue path of kop_gdl_apply) and Undo it.
    try {
        $m = call_user_func($src['act'], $lic, 'move', array('to' => 'news'));
        $r = $row($lic);
        $done = json_decode((string) $r['applied'], true) ?: array();
        $st = $GLOBALS['pdo']->prepare('SELECT status, article_url FROM news_submissions WHERE id = ?');
        $st->execute(array((int) ($done['id'] ?? 0)));
        $news_row = $st->fetch(PDO::FETCH_ASSOC);
        $check('drive-docs: "Move to" the news queue adds a waiting news item', $r['status'] === 'applied' && ($done['target'] ?? '') === 'news'
            && $news_row && $news_row['status'] === 'submitted' && $news_row['article_url'] === $r['url'], $m['message']);
        $m = call_user_func($src['act'], $lic, 'undo', array());
        $st->execute(array((int) $done['id']));
        $check('drive-docs: Undo takes it out of the news queue', $row($lic)['status'] === 'pending' && $st->fetchColumn() === 'deleted', $m['message']);
    } catch (Throwable $e) {
        $check('drive-docs: "Move to" the news queue and Undo', false, get_class($e) . ': ' . $e->getMessage());
    }
    try {
        call_user_func($src['act'], $lic, 'move', array('to' => 'elsewhere'));
        $check('drive-docs: an unknown destination is refused', false);
    } catch (RuntimeException $e) {
        $check('drive-docs: an unknown destination is refused', true, $e->getMessage());
    }
    $applied = kop_rinbox_get_item('drive-docs', $by_kind('reference'));
    $check('drive-docs: an added link offers Undo and no edits', $applied['actions'][0]['id'] === 'undo' && !empty($applied['fields'][0]['readonly']));
    try {
        call_user_func($src['save'], $by_kind('reference'), array('label' => 'x'));
        $check('drive-docs: an added link cannot be edited', false);
    } catch (RuntimeException $e) {
        $check('drive-docs: an added link cannot be edited', true, $e->getMessage());
    }
    $GLOBALS['pdo']->prepare('UPDATE wpdl_kop_gdoc_links SET label = ?, kind = ? WHERE pkey = ?')->execute(array($before['label'], $before['kind'], $lic));

    // What the old screen also did: kind filter, tab counts, sure matches ticked, another record, "Add all", reload.
    $res = call_user_func($src['list'], array('view' => 'pending', 'search' => '', 'offset' => 0, 'limit' => 25, 'filters' => array('kind' => 'news')));
    $check('drive-docs: the kind filter shows only that kind', $res['total'] === 1 && $res['items'][0]['key'] === $news, (string) $res['total']);
    $counts = call_user_func($src['view_counts'], array('view' => 'pending', 'search' => '', 'filters' => array()));
    $check('drive-docs: every tab has its count', $counts['pending'] === 3 && $counts['facility'] === 2 && $counts['company'] === 1
        && $counts['applied'] === 1 && $counts['rejected'] === 1, json_encode($counts));
    $check('drive-docs: a sure match starts ticked, a close name does not',
        kop_rinbox_get_item('drive-docs', $news)['selected'] === true && kop_rinbox_get_item('drive-docs', $lic)['selected'] === false);
    $apply = kop_rinbox_get_item('drive-docs', $news)['actions'][0];
    $check('drive-docs: Add has an optional Record box (bulk still works)', ($apply['params'][0]['type'] ?? '') === 'facility' && !empty($apply['params'][0]['optional']));
    $moves = kop_rinbox_get_item('drive-docs', $news)['moves'];
    $res_move = array_values(array_filter($moves, function ($m) { return $m['id'] === 'resource'; }))[0] ?? array();
    $check('drive-docs: moving to a facility page asks for the record', ($res_move['params'][0]['type'] ?? '') === 'facility');
    $fids = $GLOBALS['pdo']->query("SELECT id FROM facilities_v2 WHERE name <> '' ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    try {
        $m = call_user_func($src['act'], $news, 'apply', array('facility' => (string) $fids[1]));
        $r = $row($news);
        $check('drive-docs: Add with another record names that record', $r['status'] === 'applied' && (int) $r['applied_fid'] === (int) $fids[1], $m['message']);
        call_user_func($src['act'], $news, 'undo', array());
        $check('drive-docs: ...and Undo puts it back', $row($news)['status'] === 'pending');
    } catch (Throwable $e) {
        $check('drive-docs: Add with another record', false, get_class($e) . ': ' . $e->getMessage());
    }
    $tools = array_column($src['tools'], 'id');
    $check('drive-docs: queue tools are "Add every sure match" and "Load again"', $tools === array('add_all', 'resync'));
    try {
        call_user_func($src['tool'], 'add_all', array('facility' => ''));
        $check('drive-docs: "Add every sure match" needs the facility', false);
    } catch (RuntimeException $e) {
        $check('drive-docs: "Add every sure match" needs the facility', true, $e->getMessage());
    }
    try {
        $m = call_user_func($src['tool'], 'add_all', array('facility' => (string) $fids[0], 'kind' => '', 'source' => ''));
        $check('drive-docs: "Add every sure match" adds the sure ones only', $row($news)['status'] === 'applied' && $row($lic)['status'] === 'pending', $m['message']);
        call_user_func($src['act'], $news, 'undo', array());
        $m = call_user_func($src['tool'], 'add_all', array('facility' => (string) $fids[0], 'kind' => 'inspection', 'source' => ''));
        $check('drive-docs: ...under a kind filter, nothing else', $row($news)['status'] === 'pending', $m['message']);
    } catch (Throwable $e) {
        $check('drive-docs: "Add every sure match"', false, get_class($e) . ': ' . $e->getMessage());
    }
    $m = call_user_func($src['tool'], 'resync', array());
    $check('drive-docs: "Load the links files again" answers', $m['message'] !== '', $m['message']);
}
