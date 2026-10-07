<?php
/**
 * scripts/test-review-inbox.php checks for the 'fornits' source
 * (inc/review-inbox/fornits.php over inc/fornits.php).
 *
 * {prefix}kop_fornits_topics and _items are not in the mirror: they are made
 * here with the columns of kop_fornits_ensure_tables() and one made-up thread
 * with an item of every kind, tied to a real facility. Adding to a facility
 * record needs MySQL (write lock, facilities_v2 save) and is not run; sending
 * a lead to the news queue and taking it back is.
 */

require_once dirname(__DIR__, 2) . '/inc/closure-reports.php';
require_once dirname(__DIR__, 2) . '/inc/source-submissions.php';
require_once dirname(__DIR__, 2) . '/inc/drive-docs.php';
require_once dirname(__DIR__, 2) . '/inc/woodbury-facts.php';
require_once dirname(__DIR__, 2) . '/inc/fornits.php';
$GLOBALS['kop_test_options']['kop_fornits_db'] = KOP_FORNITS_DB_VERSION;
$GLOBALS['kop_test_options']['kop_closure_reports_db'] = KOP_CLOSURE_REPORTS_DB_VERSION;

if (!function_exists('sanitize_key')) {
    function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
}
if (!function_exists('get_post_status')) {
    function get_post_status($id = null) { return false; }
}
if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link($id = 0, $context = '') { return ''; }
}

(function () {
    $pdo = $GLOBALS['pdo'];
    $pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_fornits_topics (
        topic_id INTEGER PRIMARY KEY, board_name TEXT NOT NULL DEFAULT '', title TEXT NULL, url TEXT NOT NULL DEFAULT '',
        started TEXT NOT NULL DEFAULT '', last_post TEXT NOT NULL DEFAULT '', n_posts INTEGER NOT NULL DEFAULT 0, facilities TEXT NULL,
        mentions TEXT NULL, prio INTEGER NOT NULL DEFAULT 1, batch TEXT NOT NULL DEFAULT '', line INTEGER NOT NULL DEFAULT 0,
        read_status TEXT NOT NULL DEFAULT 'pending', read_note TEXT NULL, summary TEXT NULL, headline TEXT NOT NULL DEFAULT '',
        categories TEXT NOT NULL DEFAULT '', importance INTEGER NOT NULL DEFAULT 0, tries INTEGER NOT NULL DEFAULT 0,
        read_at TEXT NULL, created_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_fornits_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT, pkey TEXT NOT NULL UNIQUE, topic_id INTEGER NOT NULL, kind TEXT NOT NULL,
        facility_id INTEGER NOT NULL DEFAULT 0, also TEXT NULL, how TEXT NOT NULL DEFAULT '', label TEXT NULL, value TEXT NULL,
        quote TEXT NULL, quote_found INTEGER NOT NULL DEFAULT 0, context TEXT NULL, summary TEXT NULL,
        categories TEXT NOT NULL DEFAULT '', importance INTEGER NOT NULL DEFAULT 0, post_n INTEGER NOT NULL DEFAULT 0,
        author TEXT NOT NULL DEFAULT '', post_date TEXT NOT NULL DEFAULT '', preselect INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'pending', applied TEXT NULL, applied_fid INTEGER NOT NULL DEFAULT 0,
        reviewed_by TEXT NULL, reviewed_at TEXT NULL, created_at TEXT NOT NULL)");
    if ((int) $pdo->query('SELECT COUNT(*) FROM wpdl_kop_fornits_items')->fetchColumn()) return;
    $fid = (int) $pdo->query("SELECT id FROM facilities_v2 WHERE name <> '' ORDER BY id LIMIT 1")->fetchColumn();
    $tid = 991001;
    $post = 'I was there in 2004. Staff member John Example ran the seclusion room and kept kids in it for days at a time. '
        . 'There was an article about it here https://example-news.test/2004/seclusion-room and nobody did anything.';
    $pdo->prepare("INSERT INTO wpdl_kop_fornits_topics (topic_id, board_name, title, url, started, last_post, n_posts, facilities,
        read_status, summary, headline, categories, importance, created_at) VALUES (?, 'Program Survivors', 'Anyone else there in 2004?',
        ?, '2004-03-01', '2005-01-02', 3, '[]', 'read', 'Former students describe seclusion.', 'Former students describe the seclusion room',
        'survivor_account,restraint_seclusion', 3, '2026-10-01 09:00:00')")->execute(array($tid, 'https://www.fornits.com/phpbb/index.php?topic=' . $tid . '.0'));
    $items = array(
        array('link', '{"url":"https://www.fornits.com/phpbb/index.php?topic=991001.0","board":"Program Survivors"}', 'Fornits: Former students describe the seclusion room (2004-2005, 3 posts)', 'I was there in 2004.', 1, 3, 'pending', null, 0),
        array('staff', '{"person":"John Example","role":"seclusion room staff","years":"2004"}', 'John Example, seclusion room staff (2004)', 'Staff member John Example ran the seclusion room', 1, 2, 'pending', null, 0),
        array('incident', '{"category":"seclusion","year":"2004","summary":"Students kept in seclusion for days"}', 'Seclusion: Students kept in seclusion for days', 'kept kids in it for days at a time', 1, 2, 'pending', null, 0),
        array('testimony', '{"who":"survivor","years":"2004","summary":"Describes the seclusion room"}', 'Survivor account (2004): Describes the seclusion room', 'I was there in 2004. Staff member John Example ran the seclusion room and kept kids in it for days at a time.', 1, 3, 'pending', null, 0),
        array('lead', '{"type":"news","year":"2004","summary":"Article about the seclusion room","url":"https://example-news.test/2004/seclusion-room"}', 'News: Article about the seclusion room', 'There was an article about it here', 1, 2, 'pending', null, 0),
        array('staff', '{"person":"Jane Sample","role":"director","years":""}', 'Jane Sample, director', 'Jane Sample was the director', 1, 1, 'applied', '{"via":"wbf","op":"add_staff"}', $fid),
        array('incident', '{"category":"abuse","year":"","summary":"Shouting"}', 'Abuse: Shouting', 'they shouted', 0, 0, 'rejected', '{"reason":"Skipped"}', 0),
    );
    $ins = $pdo->prepare("INSERT INTO wpdl_kop_fornits_items (pkey, topic_id, kind, facility_id, also, how, label, value, quote, quote_found,
        context, summary, categories, importance, post_n, author, post_date, preselect, status, applied, applied_fid, reviewed_by, reviewed_at, created_at)
        VALUES (?, ?, ?, ?, '[]', 'board', ?, ?, ?, ?, ?, ?, 'survivor_account,restraint_seclusion', ?, ?, 'member1', '2005-03-12T10:00:00', 1, ?, ?, ?, ?, ?, ?)");
    foreach ($items as $i => $it) {
        $decided = $it[6] !== 'pending';
        $ins->execute(array(kop_fornits_pkey($it[0] . '|' . $tid . '|' . $i), $tid, $it[0], $fid, $it[2], $it[1], $it[3], $it[4], $post,
            $it[0] === 'link' ? 'Says the seclusion room was used for days.' : '', $it[5], $i, $it[6], $it[7], $it[8],
            $decided ? 'someone' : null, $decided ? '2026-10-02 10:00:00' : null, '2026-10-01 09:00:0' . $i));
    }
})();

function kop_rinbox_test_fornits(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];
    $key = function ($kind, $status = 'pending') use ($pdo) {
        $st = $pdo->prepare('SELECT pkey FROM wpdl_kop_fornits_items WHERE kind = ? AND status = ? ORDER BY id LIMIT 1');
        $st->execute(array($kind, $status));
        return (string) $st->fetchColumn();
    };
    $row = function ($k) { return kop_fornits_rows(array($k))[0] ?? null; };

    // Tags are the reading's categories, plus free tags kept apart.
    $link = $key('link');
    // The shared checks above set this item's tags; put the reading's back.
    $pdo->prepare('UPDATE wpdl_kop_fornits_items SET categories = ? WHERE pkey = ?')->execute(array('survivor_account,restraint_seclusion', $link));
    $read = kop_rinbox_tags_for('fornits', array($link));
    $check('fornits: the reading\'s categories show as tags', array_slice($read[$link] ?? array(), 0, 2) === array('survivor account', 'restraint seclusion'), json_encode($read));
    kop_rinbox_set_tags('fornits', $link, array('Abuse allegation', 'death', 'Follow up'));
    $r = $row($link);
    $read = kop_rinbox_tags_for('fornits', array($link));
    $check('fornits: category tags go in the categories column, other tags apart', $r['categories'] === 'abuse_allegation,death'
        && ($read[$link] ?? array()) === array('abuse allegation', 'death', 'Follow up'), $r['categories'] . ' ' . json_encode($read));
    $check('fornits: importance is the one category field', count(array_filter(kop_rinbox_get_item('fornits', $link)['fields'], function ($f) { return !empty($f['category']); })) === 1);

    // Editing.
    $staff = $key('staff');
    call_user_func($src['save'], $staff, array('person' => 'John  Q. Example', 'role' => 'night staff', 'importance' => '3'));
    $r = $row($staff);
    $v = json_decode($r['value'], true);
    $check('fornits: save corrects a staff item and its label', $v['person'] === 'John Q. Example' && $v['role'] === 'night staff'
        && $r['label'] === 'John Q. Example, night staff (2004)' && (int) $r['importance'] === 3, $r['label']);
    try {
        call_user_func($src['save'], $staff, array('person' => 'John'));
        $check('fornits: a one-word name is refused', false);
    } catch (RuntimeException $e) {
        $check('fornits: a one-word name is refused', true, $e->getMessage());
    }
    try {
        call_user_func($src['save'], $key('incident'), array('year' => 'the 90s'));
        $check('fornits: a year that is not a year is refused', false);
    } catch (RuntimeException $e) {
        $check('fornits: a year that is not a year is refused', true, $e->getMessage());
    }
    $testimony = $key('testimony');
    call_user_func($src['save'], $testimony, array('quote' => 'Words that are not in the post at all, made up here'));
    $check('fornits: an edited account is checked against the post again', (int) $row($testimony)['quote_found'] === 0);
    call_user_func($src['save'], $testimony, array('quote' => 'Staff member John Example ran the seclusion room and kept kids in it'));
    $check('fornits: ...and found when it is there', (int) $row($testimony)['quote_found'] === 1);

    // Skip and back.
    $incident = $key('incident');
    call_user_func($src['act'], $incident, 'reject', array());
    $check('fornits: skip', $row($incident)['status'] === 'rejected');
    $m = call_user_func($src['act'], $incident, 'undo', array());
    $check('fornits: back to review', $row($incident)['status'] === 'pending', $m['message']);
    try {
        call_user_func($src['act'], $staff, 'move', array('to' => 'news'));
        $check('fornits: only a lead can be moved', false);
    } catch (RuntimeException $e) {
        $check('fornits: only a lead can be moved', true, $e->getMessage());
    }

    // A lead: its destinations are moves; sending it to the news queue and Undo.
    $lead = $key('lead');
    $it = kop_rinbox_get_item('fornits', $lead);
    $check('fornits: a lead with a news link defaults to the news queue', strpos($it['actions'][0]['label'], 'News queue') !== false
        && array_column($it['moves'], 'id') === array('lawsuit', 'closed', 'note'));
    try {
        $m = call_user_func($src['act'], $lead, 'apply', array());
        $r = $row($lead);
        $done = json_decode((string) $r['applied'], true) ?: array();
        $st = $pdo->prepare('SELECT status FROM news_submissions WHERE id = ?');
        $st->execute(array((int) ($done['id'] ?? 0)));
        $check('fornits: the lead is in the news queue', $r['status'] === 'applied' && ($done['via'] ?? '') === 'queue' && $st->fetchColumn() === 'submitted', $m['message']);
        $m = call_user_func($src['act'], $lead, 'undo', array());
        $st->execute(array((int) $done['id']));
        $check('fornits: Undo takes the lead out of the news queue', $row($lead)['status'] === 'pending' && $st->fetchColumn() === 'deleted', $m['message']);
    } catch (Throwable $e) {
        $check('fornits: lead to the news queue and Undo', false, get_class($e) . ': ' . $e->getMessage());
    }
    $applied = kop_rinbox_get_item('fornits', $key('staff', 'applied'));
    $check('fornits: an added item offers Undo', $applied['actions'][0]['id'] === 'undo' && $applied['status_label'] === 'Added');

    // What the old screen also did.
    $q = function ($view, array $filters) { return array('view' => $view, 'search' => '', 'offset' => 0, 'limit' => 25, 'filters' => $filters); };
    $n = function ($view, array $filters) use ($src, $q) { return call_user_func($src['list'], $q($view, $filters))['total']; };
    // The tag checks above changed some items' categories: count from the table, FIND_IN_SET done by hand.
    $has = function ($cat) use ($pdo) {
        $c = 0;
        foreach ($pdo->query("SELECT categories FROM wpdl_kop_fornits_items WHERE status = 'pending'")->fetchAll(PDO::FETCH_COLUMN) as $cats) {
            $c += in_array($cat, explode(',', (string) $cats), true) ? 1 : 0;
        }
        return $c;
    };
    $check('fornits: the category filter', $n('pending', array('cat' => 'death')) === $has('death') && $has('death') > 0
        && $n('pending', array('cat' => 'restraint_seclusion')) === $has('restraint_seclusion') && $n('pending', array('cat' => 'legal')) === 0,
        $n('pending', array('cat' => 'death')) . '/' . $has('death') . ' ' . $n('pending', array('cat' => 'restraint_seclusion')) . '/' . $has('restraint_seclusion'));
    $check('fornits: the importance filter', $n('pending', array('min' => '3')) === 3 && $n('pending', array('min' => 'read')) === 5);
    $check('fornits: the kind filter on the Added tab', $n('applied', array('kind' => 'staff')) === 1 && $n('applied', array('kind' => 'lead')) === 0);
    $counts = call_user_func($src['view_counts'], $q('pending', array()));
    $check('fornits: every tab has its count', $counts['pending'] === 5 && $counts['staff'] === 1 && $counts['applied'] === 1 && $counts['rejected'] === 1, json_encode($counts));

    // A thread already in a record's Materials and links is on file; the made-up ones are not.
    $pdo = $GLOBALS['pdo'];
    $rec = null;
    foreach ($pdo->query("SELECT id, json_data FROM facilities_v2 WHERE json_data LIKE '%resourceLinks%' LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $l = (json_decode((string) $f['json_data'], true) ?: array())['resourceLinks'][0]['url'] ?? '';
        if ($l !== '') { $rec = array((int) $f['id'], $l); break; }
    }
    $pdo->prepare("INSERT INTO wpdl_kop_fornits_items (pkey, topic_id, kind, facility_id, label, value, status, created_at) VALUES (?, 1, 'link', ?, 'On file test', ?, 'pending', ?)")
        ->execute(array(str_repeat('ab', 16), $rec[0], json_encode(array('url' => $rec[1] . '/')), gmdate('Y-m-d H:i:s')));
    $found = call_user_func($src['on_file']);
    $keys = array_map('strval', array_keys($found));
    $states = $pdo->query("SELECT DISTINCT status FROM wpdl_kop_fornits_items WHERE pkey IN ('" . implode("','", $keys) . "')")->fetchAll(PDO::FETCH_COLUMN);
    $check('fornits: a thread the record lists already is on file, and only waiting items are', isset($found[str_repeat('ab', 16)]) && $states === array('pending'),
        count($found) . ' on file, e.g. ' . json_encode(array_slice($found, 0, 2)));
    $pdo->exec("DELETE FROM wpdl_kop_fornits_items WHERE pkey = '" . str_repeat('ab', 16) . "'");
    $it = kop_rinbox_get_item('fornits', $staff);
    $check('fornits: the most important items start ticked', $it['selected'] === true);
    $check('fornits: Add has an optional Record box (bulk still works)', ($it['actions'][0]['params'][0]['type'] ?? '') === 'facility' && !empty($it['actions'][0]['params'][0]['optional']));
    $check('fornits: the whole post and the board show on the card', in_array('Whole post', array_column($it['details'], 'label'), true)
        && in_array('Board', array_column($it['details'], 'label'), true));
    $lead_it = kop_rinbox_get_item('fornits', $lead);
    $note = array_values(array_filter($lead_it['moves'], function ($m) { return $m['id'] === 'note'; }))[0] ?? array();
    $check('fornits: a lead going on a record asks which record', ($note['params'][0]['type'] ?? '') === 'facility');
    $fids = $pdo->query("SELECT id FROM facilities_v2 WHERE name <> '' ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    try {
        call_user_func($src['act'], $lead, 'apply', array('facility' => (string) $fids[1]));
        $r = $row($lead);
        $check('fornits: Add with another record names that record', $r['status'] === 'applied' && (int) $r['applied_fid'] === (int) $fids[1]);
        call_user_func($src['act'], $lead, 'undo', array());
    } catch (Throwable $e) {
        $check('fornits: Add with another record', false, get_class($e) . ': ' . $e->getMessage());
    }
    $check('fornits: queue tools are Read more, Check AI keys and progress', array_column($src['tools'], 'id') === array('read_now', 'check_ai', 'status'));
    $m = call_user_func($src['tool'], 'status', array());
    $check('fornits: the progress line counts the topics', strpos($m['message'], 'Topics loaded: 1') === 0, $m['message']);
}
