<?php
/**
 * Offline test for essential reporting (inc/featured-reporting.php,
 * inc/featured-reporting-admin.php, templates/featured-reporting.php).
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-featured-reporting.php [--db=tmp/prod.sqlite] [--out=tmp/featured-reporting]
 *
 * WordPress is stubbed by scripts/kop-test-harness.php over the mirror, which is
 * only read: picks are written to an in-memory copy of news_submissions,
 * news_facility_links and facilities_v2. Checks the byline rule, picking and its
 * refusals, the note, exact undo, the cards and strip, the pencil, picks first
 * and flagged on a real facility page (and its company page), the app's
 * payloads, and the page; writes previews to --out. Exit code 1 on any FAIL.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'out::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$out_dir = $args['out'] ?? (dirname(__DIR__) . '/tmp/featured-reporting');
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
if (!is_dir($out_dir)) mkdir($out_dir, 0777, true);

// The records database the picks are written to: in memory, filled from the mirror.
$rec = new PDO('sqlite::memory:');
$rec->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$rec->exec('ATTACH DATABASE ' . $rec->quote($db_path) . ' AS m');
$rec->exec('CREATE TABLE news_submissions AS SELECT * FROM m.news_submissions');
$rec->exec('CREATE TABLE news_facility_links AS SELECT * FROM m.news_facility_links');
$rec->exec('CREATE TABLE facilities_v2 AS SELECT id, name FROM m.facilities_v2');
$rec->exec('DETACH DATABASE m');
$GLOBALS['kop_test_rec'] = $rec;
function kop_seed_pdo() { return $GLOBALS['kop_test_rec']; }
function kop_ie_field($name, $label, $type, $value, $extra = array()) {
    return array_merge(array('name' => $name, 'label' => $label, 'type' => $type, 'value' => $value), $extra);
}
function kop_ie_who() { return 'Test Admin'; }
function shortcode_atts($defaults, $atts) { return array_merge($defaults, array_intersect_key((array) $atts, $defaults)); }

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/operator-pages.php';
require_once dirname(__DIR__) . '/inc/operator-history.php';
require_once dirname(__DIR__) . '/inc/mobile-api.php';
require_once dirname(__DIR__) . '/inc/featured-reporting.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$throws = function ($fn) {
    try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
    return '';
};
// Options (the log and the revision) are kept only while this is set; $wpdb never writes here.
$GLOBALS['kop_test_wpdb_writes'] = true;
$GLOBALS['wpdb_guard'] = $wpdb;

// ---------------------------------------------------------------------------
echo "-- Byline --\n";
$check('a name is kept', kop_feat_byline('Jessica Schreifels', 'Salt Lake Tribune') === 'Jessica Schreifels');
$check('"By " is dropped', kop_feat_byline('By Riley Board', 'Central Maine') === 'Riley Board');
$check('the outlet as author is left out', kop_feat_byline('12 News', '12 News') === '' && kop_feat_byline('The Boston Globe', 'Boston Globe') === '');
$check('staff and wire credits are left out', kop_feat_byline('Associated Press', 'KSL') === '' && kop_feat_byline('Staff', 'Deseret News') === '');
$check('two reporters stay one line', kop_feat_byline('Mike Beaudet and Kevin Rothstein', 'WCVB') === 'Mike Beaudet and Kevin Rothstein');

// ---------------------------------------------------------------------------
echo "-- Before anything is picked --\n";
$check('no table: nothing is picked', kop_feat_map() === array());
$check('no table: no strip', kop_feat_strip_html() === '');
kop_feat_install($rec);
$check('empty table: nothing is picked', kop_feat_map(true) === array() && kop_feat_map() === array());

// Articles to pick: one linked to a facility that has a page, two others, one rejected.
$linked = 0;
foreach ($rec->query("SELECT l.news_id, l.facility_id FROM news_facility_links l JOIN news_submissions n ON n.id = l.news_id
                       WHERE n.status = 'approved' AND n.author <> '' AND n.archive_url <> '' ORDER BY n.publication_date DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (kop_facility_page_url((int) $r['facility_id']) !== '') { $linked = (int) $r['news_id']; $linked_facility = (int) $r['facility_id']; break; }
}
if (!$linked) {
    foreach ($rec->query("SELECT l.news_id, l.facility_id FROM news_facility_links l JOIN news_submissions n ON n.id = l.news_id WHERE n.status = 'approved' ORDER BY n.publication_date DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (kop_facility_page_url((int) $r['facility_id']) !== '') { $linked = (int) $r['news_id']; $linked_facility = (int) $r['facility_id']; break; }
    }
}
$check('an approved article linked to a facility page exists', $linked > 0);
$others = array_map('intval', $rec->query("SELECT id FROM news_submissions WHERE status = 'approved' AND id <> $linked AND publication_date IS NOT NULL AND publication_date <> '' AND author <> '' ORDER BY publication_date ASC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN));
// An archived copy beside the article (the mirror may predate the archive_url split).
$rec->exec("UPDATE news_submissions SET archive_url = 'https://web.archive.org/web/20261009000000/' || article_url WHERE id = $linked AND (archive_url IS NULL OR archive_url = '')");
$rejected = (int) $rec->query("SELECT id FROM news_submissions WHERE status = 'rejected' LIMIT 1")->fetchColumn();
list($b, $c) = $others;
echo "  picking $linked (facility $linked_facility), $b, $c; refusing $rejected\n";

// ---------------------------------------------------------------------------
echo "-- Picking --\n";
$note_a = "Months of records requests showed <b>what the state knew</b>.\n\nThe program closed the next spring.";
$e = kop_feat_set($rec, $linked, $note_a, 'Owner');
$check('pick with a note', $e && $e['action'] === 'feature' && $e['before'] === null);
$check('pick without a note', ($e2 = kop_feat_set($rec, $b, '', 'Owner')) && $e2['action'] === 'feature');
$check('third pick', (bool) kop_feat_set($rec, $c, 'Named the company behind it.', 'Owner'));
$check('a rejected article is refused', $rejected === 0 || strpos($throws(function () use ($rec, $rejected) { kop_feat_set($rec, $rejected, '', 'Owner'); }), 'Only an approved article') === 0);
$check('a missing article is refused', $throws(function () use ($rec) { kop_feat_set($rec, 99999999, '', 'Owner'); }) === 'That article is gone.');
$check('a note over 1,200 characters is refused', strpos($throws(function () use ($rec, $b) { kop_feat_set($rec, $b, str_repeat('word ', 300), 'Owner'); }), 'keep it under 1,200') !== false);
$check('the same note again changes nothing', kop_feat_set($rec, $c, 'Named the company behind it.', 'Owner') === null);
$map = kop_feat_map();
$check('three picks', count($map) === 3 && isset($map[$linked], $map[$b], $map[$c]));
$check('the note is stored as written', $map[$linked]['note'] === $note_a);
$rec->exec("UPDATE news_featured SET featured_at = '2026-01-0' || (CASE news_id WHEN $linked THEN 1 WHEN $b THEN 2 ELSE 3 END) || ' 10:00:00'");
kop_feat_map(true);

// ---------------------------------------------------------------------------
echo "-- Articles for the readers --\n";
$items = kop_feat_items('published');
$dates = array_column($items, 'date');
$sorted = $dates; rsort($sorted);
$check('page order: newest article first', $dates === $sorted);
$picked = array_column(kop_feat_items('picked'), 'id');
$check('strip order: newest pick first', $picked === array($c, $b, $linked), implode(',', $picked));
$ia = null;
foreach ($items as $it) if ($it['id'] === $linked) $ia = $it;
$check('the item carries the note, byline and archive copy', $ia && $ia['note'] === $note_a && $ia['byline'] !== '' && $ia['archive'] !== '', $ia ? $ia['byline'] : '');
$check('the item names its program with a link to its page', $ia && $ia['facilities'] && $ia['facilities'][0]['url'] === kop_facility_page_url($linked_facility));
$check('limit', count(kop_feat_items('picked', 2)) === 2);
$first = kop_feat_first(array(array('id' => 1), array('id' => $b), array('id' => 2), array('id' => $linked)));
$check('picks first, order otherwise kept', array_column($first, 'id') === array($b, $linked, 1, 2));

$card = kop_feat_card_html($ia);
$check('card: headline links the article', strpos($card, 'href="' . esc_url($ia['url']) . '"') !== false && strpos($card, esc_html($ia['title'])) !== false);
$check('card: archived copy beside it', strpos($card, 'kop-archived-link') !== false);
$check('card: byline', strpos($card, 'By ' . esc_html($ia['byline'])) !== false);
$check('card: the note is escaped, in two paragraphs', strpos($card, '&lt;b&gt;what the state knew&lt;/b&gt;') !== false && substr_count($card, '<p>') >= 2 && strpos($card, '<b>') === false);
$check('card: about its program', strpos($card, 'kop-feat-card__about') !== false);
$check('card: no pencil for a reader', strpos($card, 'data-kop-edit') === false);

$flag = kop_feat_flag_html($linked);
$check('flag: label links the page, note follows', strpos($flag, 'href="' . esc_url(kop_feat_url()) . '"') !== false && strpos($flag, 'kop-feat-note') !== false);
$check('flag: a picked article with no note has no note box', strpos(kop_feat_flag_html($b), 'kop-feat-note') === false && kop_feat_flag_html($b) !== '');
$check('flag: nothing for an article not picked', kop_feat_flag_html(1) === '' || isset($map[1]));

$strip = kop_feat_strip_html(array('limit' => 3));
$check('strip: three cards and the link to the page', substr_count($strip, 'class="kop-feat-card"') === 3 && strpos($strip, 'Why we picked these') !== false);
$check('strip: fewer cards than picks says how many', strpos(kop_feat_strip_html(array('limit' => 2)), 'All 3 picks') !== false);
$check('shortcode', substr_count(kop_feat_shortcode(array('limit' => '1')), 'class="kop-feat-card"') === 1);

// ---------------------------------------------------------------------------
echo "-- Undo --\n";
$e_note = kop_feat_set($rec, $linked, 'A shorter note.', 'Owner');
$check('changing the note is logged with the old one', $e_note['action'] === 'note' && $e_note['before']['note'] === $note_a);
$check('the pick date stays when the note changes', kop_feat_get($linked)['featured_at'] === '2026-01-01 10:00:00');
$e_rm = kop_feat_remove($rec, $b, 'Owner');
$check('take off', $e_rm && !kop_feat_get($b));
$check('take off something not picked', kop_feat_remove($rec, $b, 'Owner') === null);
kop_feat_undo($rec, $e_rm['id'], 'Owner');
$check('undo a take-off puts it back with its date', ($g = kop_feat_get($b)) && $g['featured_at'] === '2026-01-02 10:00:00');
$check('an undone change cannot be undone twice', $throws(function () use ($rec, $e_rm) { kop_feat_undo($rec, $e_rm['id'], 'Owner'); }) === 'That change was already undone.');
$check('an older change waits for the newer one', strpos($throws(function () use ($rec, $e) { kop_feat_undo($rec, $e['id'], 'Owner'); }), 'undo the newer change first') !== false);
kop_feat_undo($rec, $e_note['id'], 'Owner');
$check('undo the note brings the old note back', kop_feat_get($linked)['note'] === $note_a);
kop_feat_undo($rec, $e['id'], 'Owner');
$check('undo the pick takes it off', !kop_feat_get($linked));
kop_feat_set($rec, $linked, $note_a, 'Owner');
$rec->exec("UPDATE news_featured SET featured_at = '2026-01-01 10:00:00' WHERE news_id = $linked");
kop_feat_map(true);
$check('the log keeps every change (3 picks, a note, a take-off, a pick again)', count(kop_feat_log()) === 6, count(kop_feat_log()) . ' entries');

// ---------------------------------------------------------------------------
echo "-- The pencil --\n";
$form = kop_feat_ie_load(array((string) $linked));
$check('dialog: ticked, with the note', $form['fields'][0]['value'] === true && $form['fields'][1]['value'] === $note_a);
$unpicked = (int) $rec->query('SELECT id FROM news_submissions WHERE id NOT IN (SELECT news_id FROM news_featured) LIMIT 1')->fetchColumn();
$uform = kop_feat_ie_load(array((string) $unpicked));
$check('dialog: unticked and empty for an article not picked', $uform['fields'][0]['value'] === false && $uform['fields'][1]['value'] === '');
$r = kop_feat_ie_save(array((string) $c), array('featured' => false, 'note' => 'x'));
$check('unticking takes it off', strpos($r['message'], 'Taken off') === 0 && !kop_feat_get($c));
$r = kop_feat_ie_save(array((string) $c), array('featured' => true, 'note' => 'Named the company behind it.'));
$check('ticking picks it', strpos($r['message'], 'Featured') === 0 && kop_feat_get($c)['note'] === 'Named the company behind it.');
$r = kop_feat_ie_save(array((string) $c), array('featured' => true, 'note' => 'Named the company behind it.'));
$check('saving unchanged says so', $r['message'] === 'No change.');
$rec->exec("UPDATE news_featured SET featured_at = '2026-01-03 10:00:00' WHERE news_id = $c");
kop_feat_map(true);

// ---------------------------------------------------------------------------
echo "-- A facility page --\n";
$GLOBALS['kop_test_wpdb_writes'] = false;   // the page code reads the mirror only
$data = kop_facility_page_data($linked_facility);
$GLOBALS['kop_facility_page'] = $data;
ob_start();
include dirname(__DIR__) . '/templates/facility-page.php';
$html = ob_get_clean();
file_put_contents($out_dir . '/facility-' . $data['slug'] . '.html', str_replace('</head>', '<link rel="stylesheet" href="../../css/featured-reporting.css"></head>', $html));
$check('the article is flagged with its note', strpos($html, 'kop-fp-news-item is-featured') !== false && strpos($html, '&lt;b&gt;what the state knew') !== false, $data['name']);
if (preg_match('/<section class="kop-fp-section[^"]*" id="news">(.*?)<\/section>/s', $html, $m)) {
    preg_match_all('/<li class="kop-fp-news-item( is-featured)?"/', $m[1], $lis);
    $check('picked first in the news section', isset($lis[1][0]) && $lis[1][0] === ' is-featured', count($lis[1]) . ' articles');
} else {
    $check('the page has a news section', false);
}

echo "-- A company page --\n";
// The first company page (by news linked to its programs) listing several articles; the last one gets picked.
$odata = null;
$candidates = $wpdb->get_col("SELECT DISTINCT o.id FROM wpdl_kop_operators o ORDER BY o.id LIMIT 400");
foreach ($candidates as $oid) {
    if (kop_operator_page_url((int) $oid) === '') continue;
    $try = kop_operator_page_data((int) $oid);
    $main = array_values(array_filter((array) ($try['news'] ?? array()), function ($n) { return empty($n['aside']); }));
    if (count($main) >= 3) { $odata = $try; $op_pick = (int) end($main)['id']; break; }
}
if ($odata) {
    $GLOBALS['kop_test_wpdb_writes'] = true;
    $op_entry = kop_feat_set($rec, $op_pick, 'Picked for the company page test.', 'Owner');
    $GLOBALS['kop_test_wpdb_writes'] = false;
    $GLOBALS['kop_operator_page'] = $odata;
    ob_start();
    include dirname(__DIR__) . '/templates/operator-page.php';
    $ohtml = ob_get_clean();
    file_put_contents($out_dir . '/operator-' . sanitize_title($odata['name']) . '.html', str_replace('</head>', '<link rel="stylesheet" href="../../css/featured-reporting.css"></head>', $ohtml));
    $news_at = strpos($ohtml, 'id="news"');
    $check('company page: the pick is flagged with its note', strpos($ohtml, '<li class="is-featured">', $news_at) !== false && strpos($ohtml, 'Picked for the company page test.') !== false, $odata['name']);
    $check('company page: listed first', strpos($ohtml, '<li class="is-featured">', $news_at) < strpos($ohtml, '<li>', $news_at));
    $GLOBALS['kop_test_wpdb_writes'] = true;
    kop_feat_undo($rec, $op_entry['id'], 'Owner');
    $GLOBALS['kop_test_wpdb_writes'] = false;
} else {
    echo "  (no company page lists three articles; skipped)\n";
}

// ---------------------------------------------------------------------------
echo "-- The app --\n";
$cardm = kop_mobile_news_card(array('id' => $linked, 'title' => 'x', 'url' => 'https://example.test/a'));
$check('news card: featured {note, byline, page_url}', is_array($cardm['featured']) && $cardm['featured']['note'] === $note_a && $cardm['featured']['byline'] === $ia['byline'] && $cardm['featured']['page_url'] === kop_feat_url());
$check('news card: null when not picked', kop_mobile_news_card(array('id' => 1, 'title' => 'x'))['featured'] === (isset(kop_feat_map()[1]) ? kop_mobile_news_card(array('id' => 1))['featured'] : null));
$payload = kop_mobile_facility_payload($data);
$check('facility payload: picks first', ($payload['news'][0]['id'] ?? 0) === $linked && $payload['news'][0]['featured'] !== null);
$feed = kop_mobile_news_feed(array());
$check('feed: the newest picks above the list', array_column($feed['featured'], 'id') === array($c, $b, $linked) && $feed['featured_total'] === 3);
$check('feed: each pick carries its flag', $feed['featured'][2]['featured']['note'] === $note_a);
$only = kop_mobile_news_feed(array('featured' => '1', 'per_page' => 50));
$check('feed ?featured=1: only the picks, no strip', $only['total'] === 3 && count($only['items']) === 3 && $only['featured'] === array());
$check('feed page 2: no strip', kop_mobile_news_feed(array('page' => 2))['featured'] === array());
$check('the private columns stay out', strpos(json_encode($feed), 'reviewer_notes') === false && strpos(json_encode($feed), 'submitted_by') === false);

// ---------------------------------------------------------------------------
echo "-- The page --\n";
$GLOBALS['kop_featured_page'] = kop_feat_page_data();
ob_start();
include dirname(__DIR__) . '/templates/featured-reporting.php';
$phtml = ob_get_clean();
$preview = str_replace('</head>', '<link rel="stylesheet" href="../../css/site-map.css"><link rel="stylesheet" href="../../css/featured-reporting.css"></head>', $phtml);
file_put_contents($out_dir . '/essential-reporting.html', $preview);
$check('page: every pick as a card', substr_count($phtml, 'class="kop-feat-card"') === 3);
$years = array_keys($GLOBALS['kop_featured_page']['years']);
$check('page: a heading per year', substr_count($phtml, '<section class="kop-feat-page__year"') === count($years), implode(', ', $years));
$check('page: links back to the news feed', strpos($phtml, 'news feed</a>') !== false);
$check('page: no admin hint for a reader', strpos($phtml, 'KOP Tools') === false);
$strip_preview = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><link rel="stylesheet" href="../../css/colors.css"><link rel="stylesheet" href="../../css/featured-reporting.css"></head><body style="background:#e8eef3;padding:16px">'
    . kop_feat_strip_html(array('limit' => 3)) . '</body></html>';
file_put_contents($out_dir . '/strip.html', $strip_preview);

echo "\nPreviews in $out_dir\n";
echo $failures ? "\n$failures FAILED\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
