<?php
/**
 * Offline checks for document types (inc/doc-types.php, its Review inbox
 * source and the /documents/ template) against the production mirror.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-doc-types.php [--db=tmp/prod.sqlite] [--list] [--untyped]
 *
 * The two document type tables are TEMP tables, so every rule run, change,
 * undo and AI suggestion really writes and is read back while the mirror is
 * never changed. The AI and the PDF text are stand-ins.
 *   --list     prints a sample of what the rules typed, ten per type
 *   --untyped  prints 60 documents the rules could not type (for new rules)
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'list', 'untyped'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($db_path)) {
    fwrite(STDERR, "Need $db_path (scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
function number_format_i18n($n) { return number_format((float) $n); }
function wp_unslash($v) { return $v; }
function kop_ie_field($name, $label, $type, $value, $extra = array()) {
    return array_merge(array('name' => $name, 'label' => $label, 'type' => $type, 'value' => $value), $extra);
}
$GLOBALS['kop_test_rinbox'] = array();
function kop_rinbox_register($key, callable $build) { $GLOBALS['kop_test_rinbox'][$key] = $build; }
function kop_rinbox_reviewer() { return 'Test Admin'; }

require __DIR__ . '/kop-test-harness.php';

$pdo->exec('CREATE TEMP TABLE wpdl_kop_doc_types (attachment_id INTEGER NOT NULL, type TEXT NOT NULL, source TEXT NOT NULL DEFAULT \'\', note TEXT NOT NULL DEFAULT \'\', created_by TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL, PRIMARY KEY (attachment_id, type))');
$pdo->exec('CREATE TEMP TABLE wpdl_kop_doc_type_suggestions (attachment_id INTEGER PRIMARY KEY, status TEXT NOT NULL, types TEXT NOT NULL DEFAULT \'\', why TEXT, provider TEXT NOT NULL DEFAULT \'\', rules_version TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL, reviewed_by TEXT NOT NULL DEFAULT \'\', reviewed_at TEXT)');
// Writes reach only those two TEMP tables: nothing else in this test writes.
$GLOBALS['kop_test_wpdb_writes'] = true;
$GLOBALS['kop_test_options'] = array();

require_once dirname(__DIR__) . '/inc/document-archive.php';
require_once dirname(__DIR__) . '/inc/doc-types.php';
require_once dirname(__DIR__) . '/inc/review-inbox/doc-types.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? '  (' . $detail . ')' : '') . "\n";
    if (!$ok) $failures++;
};
$throws = function ($fn) {
    try { $fn(); } catch (Throwable $e) { return $e->getMessage(); }
    return '';
};
$count = function ($sql) use ($pdo) { return (int) $pdo->query($sql)->fetchColumn(); };

// ---------------------------------------------------------------------------
echo "Types\n";
$types = kop_dt_types();
$check('every type has label, plural, group, desc', count(array_filter($types, function ($t) { return $t['label'] && $t['plural'] && $t['group'] && $t['desc']; })) === count($types));
foreach (array('student-handbook', 'inspection-report', 'enrollment', 'news-clipping', 'legal-filing') as $asked) {
    $check("the owner's example type is there: $asked", isset($types[$asked]));
}
$check('clean_types keeps known slugs in list order, once', kop_dt_clean_types(array('legal-filing', 'nope', 'Enrollment', 'legal-filing')) === array('enrollment', 'legal-filing'));

// ---------------------------------------------------------------------------
echo "Rules on made-up titles (no folder)\n";
$cases = array(
    array('Turning Winds Academic Institute 2011-2012 NATSAP Profile', 'natsap-profile'),
    array('sorensons ranch school natsap 2020', 'natsap-profile'),
    array('Deposition of Jean Courtney', 'deposition'),
    array('John Stupak Sequel LinkedIn', 'staff-profile'),
    array('Embark at San Martin - Unsubstantiated Complaint Investigation Report (August 2024)', 'inspection-report'),
    array('2004 Island View Police Report Death', 'incident-report'),
    array('SequelCare of AZ Articles of Amendment 2022', 'business-record'),
    array('Uinta Academy Parent Handbook 2008', 'parent-handbook'),
    array('Devereux Employee Handbook', 'staff-manual'),
    array('Island View Handbook', 'student-handbook'),
    array('Levels and Privileges midwest academy', 'student-handbook'),
    array('Dorm Parent manual midwest academy', 'staff-manual'),
    array('Lon Woodbury Expert Report', 'legal-filing'),
    array('doe v hyde woodstock motion deposition plaintiff expert', 'legal-filing'),
    array('Excerpt of Deposition of Brandon Courtney 2', 'deposition'),
    array('Agape Boarding School - Admission Application', 'enrollment'),
    array('hodges v glenholme ruling', 'legal-filing'),
    array('Lakeside Settlement', 'legal-filing'),
    array('Asheville Academy Voluntarily Surrender License', 'inspection-report'),
    array('Notice_of_Inspection', 'inspection-report'),
    array('Accelerated Christian Education - Thesis (May 2005)', 'research'),
    array('pillars of hope website 2005', 'website-capture'),
    array('spring creek lodge girls enrollment agreement 2', 'enrollment'),
    array('Tuition Agreement Exhibit A', 'enrollment'),
    array('Hyde School - Answer to Second Amended Complaint (August 2026)', 'legal-filing'),
    array('Chamberlain DLC Settlement Agreement', 'legal-filing'),
    array('Washington HB 1815 good', 'legislation'),
    array('NATSAP Member List 2010', 'directory'),
    array('Copy of woodbury april 2010', 'woodbury'),
    array('motivate counseling discovery ranch', null),
    array('Friends of Seguin Island Fall 2006', null),
);
foreach ($cases as $c) {
    $got = kop_dt_classify(array('title' => $c[0], 'file' => '', 'folders' => array()));
    $check('"' . $c[0] . '" -> ' . ($c[1] ?? 'nothing'), ($got['type'] ?? null) === $c[1], $got ? $got['type'] . ': ' . $got['why'] : 'no rule');
}
$check('a Woodbury page cut by the scan is a Woodbury page', (kop_dt_classify(array('title' => 'Mentions p 4', 'file' => '', 'folders' => array(), 'woodbury' => true))['type'] ?? '') === 'woodbury');
$check('a dorm parent manual is for staff', kop_dt_handbook_kind('Dorm Parent manual') === 'staff-manual');

echo "Rules on real folders\n";
$folder_id = function ($regex) {
    foreach (kop_dt_folders() as $id => $f) if (preg_match($regex, $f['name'])) return $id;
    return 0;
};
$folder_cases = array(
    array('/^va_pdfs$/', 'inspection-report'),
    array('/^Enrollment Materials$/', 'enrollment'),
    array('/^Lawsuits$/', 'legal-filing'),
    array('/^Discovery & Depositions$/', 'deposition'),
    array('/^News Clippings$/', 'news-clipping'),
    array('/^Newsletters$/', 'newsletter'),
    array('/^Property Records$/', 'business-record'),
    array('/^Program Websites/', 'website-capture'),
    array('/^Woodbury Reports Mentions$/', 'woodbury'),
    array('/^Parent & Family Handbooks$/', 'parent-handbook'),
);
foreach ($folder_cases as $c) {
    $fid = $folder_id($c[0]);
    if (!$fid) { $check('folder ' . $c[0] . ' is in the mirror', false); continue; }
    $got = kop_dt_classify(array('title' => 'Untitled scan 7', 'file' => 'scan7.pdf', 'folders' => array($fid)));
    $check('a plain-titled file in ' . trim($c[0], '/^$') . ' -> ' . $c[1], ($got['type'] ?? null) === $c[1], $got ? $got['why'] : 'no rule');
}
$fid = $folder_id('/^Lawsuits$/');
$got = kop_dt_classify(array('title' => 'Kailana 2011-2012 NATSAP Profile', 'file' => '', 'folders' => array($fid)));
$check('a strong title wins over the folder (NATSAP profile filed under Lawsuits)', ($got['type'] ?? '') === 'natsap-profile');
$hb = $folder_id('/^Handbooks$/');
if ($hb) {
    $got = kop_dt_classify(array('title' => 'CCA Parent Manual 2010', 'file' => '', 'folders' => array($hb)));
    $check('the Handbooks folder settles the reader from the title (parent)', ($got['type'] ?? '') === 'parent-handbook');
}
$pg = $folder_id('/^Parent Guides$/');
if ($pg) {
    $got = kop_dt_classify(array('title' => 'Pillars of Hope Parent Agreement 2007', 'file' => '', 'folders' => array($pg)));
    $check('a parent agreement filed with parent guides is an enrollment document', ($got['type'] ?? '') === 'enrollment');
}

// ---------------------------------------------------------------------------
echo "Rule run over every document (TEMP tables)\n";
$total_docs = $count("SELECT COUNT(*) FROM wpdl_posts p WHERE p.post_type = 'attachment' AND " . kop_dt_doc_mimes_sql());
$t0 = microtime(true);
$dry = kop_dt_run_rules(false);
printf("  %d documents; the rules type %d (%.0f%%), %d left for the AI, in %.1fs\n", $total_docs, $dry['typed'], 100 * $dry['typed'] / max(1, $total_docs), $dry['left'], microtime(true) - $t0);
foreach ($dry['by_type'] as $slug => $n) printf("    %-20s %5d\n", $slug, $n);
$check('a dry run writes nothing', $count('SELECT COUNT(*) FROM wpdl_kop_doc_types') === 0 && $count('SELECT COUNT(*) FROM wpdl_kop_doc_type_suggestions') === 0);
$check('the rules type at least half the documents', $dry['typed'] * 2 >= $total_docs, $dry['typed'] . ' of ' . $total_docs);
if (isset($args['list'])) {
    $per = array();
    foreach ($dry['sample'] as $s) {
        if (($per[$s[2]] = ($per[$s[2]] ?? 0) + 1) > 10) continue;
        printf("    %-18s #%-6d %-70s %s\n", $s[2], $s[0], mb_substr($s[1], 0, 70), $s[3]);
    }
}

$run = kop_dt_run_rules(true);
$check('the run types what the dry run said', $run['typed'] === $dry['typed'] && $count('SELECT COUNT(DISTINCT attachment_id) FROM wpdl_kop_doc_types') === $dry['typed']);
$check('every rule row says why', $count("SELECT COUNT(*) FROM wpdl_kop_doc_types WHERE note = ''") === 0);
$check('the rest wait for the AI (norule)', $count("SELECT COUNT(*) FROM wpdl_kop_doc_type_suggestions WHERE status = 'norule'") === $dry['left']);
$check('the run is one log entry', count(kop_dt_log()) === 1 && count(kop_dt_log()[0]['changes']) === $run['typed']);
$again = kop_dt_run_rules(true);
$check('a second run finds nothing new', $again['typed'] === 0 && $again['left'] === 0);
if (isset($args['untyped'])) {
    $ids = $pdo->query("SELECT attachment_id FROM wpdl_kop_doc_type_suggestions WHERE status = 'norule' ORDER BY RANDOM() LIMIT 60")->fetchAll(PDO::FETCH_COLUMN);
    foreach (kop_dt_load_docs($ids) as $d) printf("    #%-6d %-60s | %s\n", $d['id'], mb_substr($d['title'], 0, 60), mb_substr(kop_dt_folder_path($d), 0, 70));
}

echo "Undo a rule run\n";
$counts_before = kop_dt_counts();
$r = kop_dt_undo(kop_dt_log()[0]['id'], 'Test Admin');
$check('undo restores every document', $r['restored'] === $run['typed'] && $r['skipped'] === 0, $r['restored'] . ' restored');
$check('nothing is typed after the undo', $count('SELECT COUNT(*) FROM wpdl_kop_doc_types') === 0);
$check('undone documents wait for the AI, not the rules', kop_dt_run_rules(true)['typed'] === 0);
$check('undoing twice is refused', $throws(function () { kop_dt_undo(kop_dt_log()[0]['id']); }) !== '');
// Back to typed for the rest: drop the rows the undo made and run again.
$pdo->exec("DELETE FROM wpdl_kop_doc_type_suggestions WHERE why = 'A rule run was undone'");
kop_dt_run_rules(true);
$check('typed again after clearing', kop_dt_counts()['enrollment'] === $counts_before['enrollment']);

// ---------------------------------------------------------------------------
echo "The pencil\n";
$doc = (int) $pdo->query("SELECT attachment_id FROM wpdl_kop_doc_types WHERE type = 'enrollment' LIMIT 1")->fetchColumn();
$fields = kop_dt_pencil_fields($doc);
$check('the pencil offers every type, ticked as now', count($fields[0]['options']) === count($types) && $fields[0]['value'] === array('enrollment'));
$msg = kop_dt_pencil_save($doc, array('doc_types' => array('enrollment', 'student-handbook')));
$check('ticking a second type saves both', kop_dt_types_for($doc) === array('student-handbook', 'enrollment'), $msg);
$check('saving it unchanged changes nothing', kop_dt_pencil_save($doc, array('doc_types' => array('student-handbook', 'enrollment'))) === '');
$check('a dialog without the field leaves the types', kop_dt_pencil_save($doc, array('title' => 'x')) === '' && count(kop_dt_types_for($doc)) === 2);
kop_dt_pencil_save($doc, array('doc_types' => array()));
$check('unticking every type clears it for good', kop_dt_types_for($doc) === array() && kop_dt_sugg_get($doc)['status'] === 'cleared');
$check('cleared documents are never typed again by the rules', kop_dt_run_rules(true)['typed'] === 0);
$log = kop_dt_log();
kop_dt_undo($log[0]['id'], 'Test Admin');
$check('undo of the clear puts both types back and lifts the clear', kop_dt_types_for($doc) === array('student-handbook', 'enrollment') && kop_dt_sugg_get($doc) === null);
kop_dt_undo($log[1]['id'], 'Test Admin');
$check('undo of the second type leaves enrollment', kop_dt_types_for($doc) === array('enrollment'));
$tile = kop_dt_tile_attr($doc);
$check('the tile carries its types for the filter', $tile === ' data-doc-types="enrollment"');
$check('the tile chip names it', strpos(kop_dt_tile_chips($doc), 'Enrollment document') !== false);

// ---------------------------------------------------------------------------
echo "AI suggestions (stand-in AI)\n";
$GLOBALS['kop_dt_text'] = function ($id) { return $id % 2 ? 'STUDENT HANDBOOK. Level One: you may not speak to peers.' : ''; };
$answers = array(
    '{"types": ["student-handbook"], "why": "A rulebook for students."}',
    'Sure! {"types": ["legal-filing", "not-a-type"], "why": "A court complaint."}',
    '{"types": [], "why": "unclear: a blank scan"}',
    'no JSON at all',
);
$GLOBALS['kop_dt_ai'] = function ($prompt) use (&$answers) {
    if (strpos($prompt, 'student-handbook:') === false) throw new RuntimeException('the prompt lacks the type list');
    return array_shift($answers) ?? '{"types": ["other"], "why": "x"}';
};
$waiting = $count("SELECT COUNT(*) FROM wpdl_kop_doc_type_suggestions WHERE status = 'norule'");
$b = kop_dt_ai_batch(4);
$check('the batch asks four and stores three', $b['asked'] === 3 && $b['errors'] === 1, json_encode($b));
$check('two guesses wait, one could not tell', $b['pending'] === 2 && $b['unclear'] === 1);
$check('unknown slugs from the AI are dropped', $count("SELECT COUNT(*) FROM wpdl_kop_doc_type_suggestions WHERE types LIKE '%not-a-type%'") === 0);
$check('nothing is typed by the AI alone', $count("SELECT COUNT(*) FROM wpdl_kop_doc_types WHERE source = 'ai'") === 0);
$check('the left count goes down', $b['left'] === $waiting - 3);
$check('a document with no text says so', $count("SELECT COUNT(*) FROM wpdl_kop_doc_type_suggestions WHERE status IN ('pending','unclear') AND why LIKE '%title and folder only%'") >= 1);

echo "Review inbox\n";
$src = call_user_func($GLOBALS['kop_test_rinbox']['doc-types']);
$vc = call_user_func($src['view_counts']);
$check('the queue counts its views', $vc['pending'] === 2 && $vc['unclear'] === 1 && $vc['rule'] > 0, json_encode($vc));
$list = call_user_func($src['list'], array('view' => 'pending', 'search' => '', 'limit' => 10, 'offset' => 0));
$check('AI guesses list both', $list['total'] === 2 && count($list['items']) === 2);
$item = $list['items'][0];
$ids = array_column($item['actions'], 'id');
$check('a guess offers approve, leave untyped, ask again, set another', $ids === array('approve', 'reject', 'reask', 'other'), implode(',', $ids));
$key = (int) $item['key'];
$res = call_user_func($src['act'], $item['key'], 'approve', array());
$after = kop_dt_types_for($key);
$check('approve types it as guessed', $after && kop_dt_sugg_get($key)['status'] === 'approved', $res['message']);
call_user_func($src['act'], $item['key'], 'restore', $res['undo']['params']);
$check('the logged Undo puts it back to waiting, untyped', kop_dt_types_for($key) === array() && kop_dt_sugg_get($key)['status'] === 'pending');
$res = call_user_func($src['act'], $item['key'], 'other', array('type' => 'news-clipping'));
$check('"Set this type instead" types it as picked', kop_dt_types_for($key) === array('news-clipping'));
call_user_func($src['act'], $item['key'], 'restore', $res['undo']['params']);
$check('and Undo takes it back', kop_dt_types_for($key) === array());
$check('"Set this type instead" needs a type', $throws(function () use ($src, $item) { call_user_func($src['act'], $item['key'], 'other', array('type' => '')); }) !== '');
$rule = call_user_func($src['list'], array('view' => 'rule', 'search' => '', 'limit' => 3, 'offset' => 0));
$ritem = $rule['items'][0];
$check('typed-by-rule items say why', strpos($ritem['text'], 'Why: ') !== false, $ritem['text']);
$rkey = (int) $ritem['key'];
$was = kop_dt_types_for($rkey);
$res = call_user_func($src['act'], $ritem['key'], 'wrong', array());
$check('"Wrong" takes the type off and lets the AI read it', kop_dt_types_for($rkey) === array() && kop_dt_sugg_get($rkey)['status'] === 'norule');
call_user_func($src['act'], $ritem['key'], 'restore', $res['undo']['params']);
$check('Undo puts the rule type back', kop_dt_types_for($rkey) === $was && kop_dt_sugg_get($rkey) === null);
$res = call_user_func($src['act'], $ritem['key'], 'ok', array());
$check('"Right" takes it off the rule list', $count("SELECT COUNT(*) FROM wpdl_kop_doc_types WHERE attachment_id = $rkey AND source = 'rule'") === 0);
call_user_func($src['act'], $ritem['key'], 'restore', $res['undo']['params']);
$check('Undo puts it back on the list', $count("SELECT COUNT(*) FROM wpdl_kop_doc_types WHERE attachment_id = $rkey AND source = 'rule'") > 0);

// ---------------------------------------------------------------------------
echo "Reader pages\n";
$t0 = microtime(true);
$items = kop_dt_page_items('enrollment');
$on_pages = count(array_filter($items, function ($i) { return $i['place']['kind'] === 'facility'; }));
printf("  enrollment: %d documents, %d on a program's page, in %.1fs\n", count($items), $on_pages, microtime(true) - $t0);
$check('enrollment documents are listed', count($items) > 10);
$check('most are placed on a program page', $on_pages * 2 >= count($items), "$on_pages of " . count($items));
$check('placed ones carry a state and a page link', !array_filter($items, function ($i) { return $i['place']['kind'] === 'facility' && $i['place']['url'] === ''; }));
$check('every item has a file link', !array_filter($items, function ($i) { return $i['url'] === ''; }));
$private = kop_dt_private_folder_ids();
$check('the private folders are known', count($private) > 0);
$insp = kop_dt_page_items('inspection-report');
$check('state report folders read as "XX state reports"', (bool) array_filter($insp, function ($i) { return preg_match('/^[A-Z]{2} state reports$/', $i['place']['name']); }));
$check('a year is found in titles', (bool) array_filter($items, function ($i) { return $i['year'] !== ''; }));

$render = function ($type) {
    $GLOBALS['kop_doc_types_page'] = kop_dt_page_data($type);
    ob_start();
    include dirname(__DIR__) . '/templates/document-types.php';
    return ob_get_clean();
};
$html = $render('_index');
$check('the index lists the types with counts', strpos($html, 'Enrollment documents') !== false && strpos($html, '/documents/enrollment/') !== false);
$html = $render('enrollment');
$check('a type page has its title, filter and documents', strpos($html, '<h1 class="entry-title">Enrollment documents</h1>') !== false
    && strpos($html, 'kop-dt-filter') !== false && substr_count($html, 'class="kop-dt-doc"') === count($items));
$out_dir = dirname(__DIR__) . '/tmp/document-types';
if (!is_dir($out_dir)) mkdir($out_dir, 0777, true);
file_put_contents($out_dir . '/enrollment.html', $html);
file_put_contents($out_dir . '/index.html', $render('_index'));
echo "  wrote tmp/document-types/index.html and enrollment.html\n";

echo "\n" . ($failures ? "$failures FAILED\n" : "All passed.\n");
exit($failures ? 1 : 0);
