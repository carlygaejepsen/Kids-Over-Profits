<?php
/**
 * The glossary in SQL (inc/glossary-store.php) and its editor, offline, on an
 * in-memory SQLite database (scripts/lib-glossary-test-db.php).
 *
 * - The import of js/data/glossary/glossary.md gives exactly the data the
 *   markdown build gives (and, while it is still in the repo, the committed
 *   glossary.json the page used to read).
 * - The old editor's saved changes (kop_glossary_edits) are applied before
 *   the import, and the option is retired.
 * - Through the editor's own POST handler: add, edit, rename (new #id), move,
 *   a broken **reference** refused, a referenced entry refused deletion,
 *   delete and Undo (same id back), Undo of an add, Undo refused after a
 *   later change; sections and groups added, renamed, moved, deleted.
 * - The open data export has glossary.json's shape.
 *
 * Usage: php -d extension=pdo_sqlite -d extension=mbstring scripts/test-glossary-store.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

function kop_facility_page_url_for_name() { return ''; }
function add_query_arg($k, $v = null, $u = null) { return (string) $u; }

require __DIR__ . '/lib-glossary-test-db.php';

$failures = 0;
function check($ok, $label) {
    global $failures;
    if (!$ok) {
        $failures++;
        echo "FAIL  $label\n";
    }
    return $ok;
}

/** First path where two decoded structures differ, or ''. */
function first_diff($a, $b, $path = '') {
    if (is_array($a) && is_array($b)) {
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            if (!array_key_exists($k, $a) || !array_key_exists($k, $b)) {
                return "$path/$k missing on one side";
            }
            $d = first_diff($a[$k], $b[$k], "$path/$k");
            if ($d !== '') {
                return $d;
            }
        }
        return '';
    }
    return $a === $b ? '' : "$path: " . var_export($a, true) . ' vs ' . var_export($b, true);
}

/** The page data without the store's own keys (row ids, container paths). */
function plain($data) {
    $strip = function ($node) use (&$strip) {
        unset($node['key']);
        foreach ($node['entries'] as $i => $e) {
            unset($node['entries'][$i]['key'], $node['entries'][$i]['container'], $node['entries'][$i]['source']);
        }
        foreach ($node['groups'] as $i => $g) {
            $node['groups'][$i] = $strip($g);
        }
        return $node;
    };
    foreach ($data['sections'] as $i => $s) {
        $data['sections'][$i] = $strip($s);
    }
    return $data;
}

/** POST through the editor's handler. */
function post(array $fields) {
    $_POST = $fields;
    $_REQUEST = $fields;
    return kop_glossary_editor_handle_post();
}

function data_now() {
    return kop_glossary_data();
}

function entry_by_anchor($anchor) {
    foreach (kop_glossary_all_entries(data_now()) as $e) {
        if ($e['id'] === $anchor) {
            return $e;
        }
    }
    return null;
}

function snapshot_fields($anchor) {
    $state = kop_glossary_store_load();
    $id = kop_glossary_entry_id_for_anchor($state, $anchor);
    $f = kop_glossary_snapshot_fields($state['entries'][$id]);
    return array(
        'kop_ge_do' => 'save', 'kop_ge_entry' => $anchor,
        'kop_ge_term' => $f['term'], 'kop_ge_note' => $f['note'], 'kop_ge_aka' => $f['aka_list'],
        'kop_ge_text' => $f['text'], 'kop_ge_used' => $f['used'], 'kop_ge_reported' => $f['reported'],
        'kop_ge_node' => $f['node_id'],
    );
}

$root = dirname(__DIR__);
$md = file_get_contents($root . '/js/data/glossary/glossary.md');

/* ---- Import parity -------------------------------------------------------- */

$data = data_now();
check(is_array($data), 'the tables fill from glossary.md on first read');
$from_md = kop_glossary_build($md);
check(!$from_md['errors'], 'glossary.md builds: ' . implode('; ', $from_md['errors']));
$d = first_diff(plain($data), $from_md['data']);
check($d === '', "data from the tables == data from glossary.md ($d)");
$json_path = $root . '/js/data/glossary/glossary.json';
if (is_readable($json_path)) {
    $d = first_diff(plain($data), json_decode(file_get_contents($json_path), true));
    check($d === '', "data from the tables == the committed glossary.json ($d)");
}
$imported = get_option('kop_glossary_imported');
check(is_array($imported) && $imported['entries'] === (int) $data['count'], 'the import is recorded with its entry count');

/* Every entry survives the form unchanged: no change, no log row. */
$state = kop_glossary_store_load();
$unchanged = 0;
foreach ($state['entries'] as $id => $e) {
    $made = kop_glossary_fields_to_snapshot(kop_glossary_snapshot_fields($e));
    $made['snap']['anchor'] = $e['anchor'];
    if ($made['errors'] || kop_glossary_snapshot($made['snap']) !== kop_glossary_snapshot($e)) {
        check(false, 'entry "' . $e['term'] . '" does not survive the form unchanged');
    } else {
        $unchanged++;
    }
}
check($unchanged === count($state['entries']), "all $unchanged entries round-trip through the form");

/* ---- Entries through the editor ------------------------------------------ */

$shared = 0;
foreach ($state['nodes'] as $nid => $n) {
    if ($n['parent_id'] === 0 && stripos($n['title'], 'Shared Terms') === 0) {
        $shared = $nid;
    }
}
check($shared > 0, 'the Shared Terms section is there');
$rev = (int) get_option('kop_glossary_rev');

$r = post(array('kop_ge_do' => 'save', 'kop_ge_entry' => '', 'kop_ge_term' => 'Test Lockdown', 'kop_ge_note' => '',
    'kop_ge_aka' => "Test Hold\nTest, Restraint", 'kop_ge_text' => 'a made-up term for the test. See **Bust**.',
    'kop_ge_used' => "Spring Ridge Academy\nStraight, Inc. (as \"lockdown\")", 'kop_ge_reported' => '', 'kop_ge_node' => $shared));
check(empty($r['errors']), 'add a term: ' . implode('; ', $r['errors'] ?? array()));
check((int) get_option('kop_glossary_rev') > $rev, 'a save bumps the revision (cache cleared)');
$e = entry_by_anchor('test-lockdown');
check($e !== null, 'the new term is on the page as #test-lockdown');
check($e && $e['text'] === 'A made-up term for the test. See **Bust**.', 'its definition is capitalised on the page');
check($e && $e['aka'] === array('Test Hold', 'Test, Restraint'), 'an also-called name may hold a comma now');
check($e && count($e['used']) === 2 && $e['used'][1]['program'] === 'Straight, Inc.' && $e['used'][1]['note'] === 'as "lockdown"', 'a tag with a comma in the program name and a note');
$counts = array();
foreach (data_now()['programs'] as $p) {
    $counts[$p['slug']] = $p['count'];
}
check(isset($counts['spring-ridge-academy']), 'the program list counts the new tag');

$r = post(array('kop_ge_do' => 'save', 'kop_ge_entry' => '', 'kop_ge_term' => 'Test Broken', 'kop_ge_note' => '',
    'kop_ge_aka' => '', 'kop_ge_text' => 'Points at **No Such Entry Anywhere**.', 'kop_ge_used' => '', 'kop_ge_reported' => '', 'kop_ge_node' => $shared));
check(!empty($r['errors']) && strpos(implode(' ', $r['errors']), 'does not name an entry') !== false, 'a reference to no entry is refused');
check(entry_by_anchor('test-broken') === null, 'and nothing is saved');

$r = post(array('kop_ge_do' => 'save', 'kop_ge_entry' => '', 'kop_ge_term' => 'Test Pointer', 'kop_ge_note' => '',
    'kop_ge_aka' => '', 'kop_ge_text' => 'Points at **Test Hold**.', 'kop_ge_used' => '', 'kop_ge_reported' => '', 'kop_ge_node' => $shared));
check(empty($r['errors']), 'a reference by an also-called name saves');
check((data_now()['refs']['test hold'] ?? '') === 'test-lockdown', 'and resolves to the entry');

$r = post(array('kop_ge_do' => 'delete', 'kop_ge_entry' => 'test-lockdown'));
check(!empty($r['errors']), 'an entry another one links to cannot be deleted');

/* Rename: new #id, the reference by aka still resolves. */
$f = snapshot_fields('test-lockdown');
$f['kop_ge_term'] = 'Test Lockup';
$r = post($f);
check(empty($r['errors']), 'rename saves: ' . implode('; ', $r['errors'] ?? array()));
check(entry_by_anchor('test-lockup') !== null && entry_by_anchor('test-lockdown') === null, 'a renamed term gets a new #id');

/* Edit, then Undo; Undo refused after a later change. */
$f = snapshot_fields('test-lockup');
$f['kop_ge_text'] = 'An edited definition. See **Bust**.';
post($f);
$recent = kop_glossary_store_recent(5);
check($recent[0]['action'] === 'edit' && $recent[0]['term'] === 'Test Lockup', 'the edit is logged');
$f['kop_ge_text'] = 'A second edit.';
post($f);
$r = post(array('kop_ge_do' => 'undo', 'kop_ge_log' => $recent[0]['id']));
check(!empty($r['errors']), 'Undo of an older change is refused once the entry changed again');
$recent = kop_glossary_store_recent(5);
$r = post(array('kop_ge_do' => 'undo', 'kop_ge_log' => $recent[0]['id']));
check(empty($r['errors']), 'Undo of the latest change works');
check(entry_by_anchor('test-lockup')['text'] === 'An edited definition. See **Bust**.', 'and puts the text back');

/* Delete + Undo keeps the row id. */
post(array('kop_ge_do' => 'delete', 'kop_ge_entry' => 'test-pointer'));
$state = kop_glossary_store_load();
check(kop_glossary_entry_id_for_anchor($state, 'test-pointer') === 0, 'delete removes the entry');
$recent = kop_glossary_store_recent(1);
$deleted_id = (int) $recent[0]['entry_id'];
$r = post(array('kop_ge_do' => 'undo', 'kop_ge_log' => $recent[0]['id']));
check(empty($r['errors']), 'Undo of a delete works');
$state = kop_glossary_store_load();
check(kop_glossary_entry_id_for_anchor($state, 'test-pointer') === $deleted_id, 'and the entry comes back with its old id');

/* Move into a group, then a same-term entry gets a qualified #id. */
$group = 0;
foreach ($state['nodes'] as $nid => $n) {
    if ($n['anchor'] === 'hyde-school') {
        $group = $nid;
    }
}
$f = snapshot_fields('test-lockup');
$f['kop_ge_node'] = $group;
post($f);
check(entry_by_anchor('test-lockup')['container'] === array('Program-Specific Terms', 'Hyde School') || count(entry_by_anchor('test-lockup')['container']) === 2, 'an entry moves into a group');
$r = post(array('kop_ge_do' => 'save', 'kop_ge_entry' => '', 'kop_ge_term' => 'Test Lockup', 'kop_ge_note' => 'second',
    'kop_ge_aka' => '', 'kop_ge_text' => 'Another one.', 'kop_ge_used' => '', 'kop_ge_reported' => '', 'kop_ge_node' => $shared));
check(empty($r['errors']), 'a second entry with the same term saves');
check(entry_by_anchor('test-lockup-second') !== null && entry_by_anchor('test-lockup') !== null, 'it gets #term-qualifier and the first keeps its #id');

/* Undo of an add deletes; then clean up the test terms. */
$recent = kop_glossary_store_recent(1);
$r = post(array('kop_ge_do' => 'undo', 'kop_ge_log' => $recent[0]['id']));
check(empty($r['errors']) && entry_by_anchor('test-lockup-second') === null, 'Undo of an add removes the entry');
post(array('kop_ge_do' => 'delete', 'kop_ge_entry' => 'test-pointer'));
$r = post(array('kop_ge_do' => 'delete', 'kop_ge_entry' => 'test-lockup'));
check(empty($r['errors']), 'with nothing linking to it, the entry deletes');
check(data_now()['count'] === $data['count'], 'back to the imported entry count');

/* ---- Sections and groups -------------------------------------------------- */

$r = post(array('kop_ge_do' => 'node_save', 'kop_ge_node' => 0, 'kop_ge_parent' => $shared, 'kop_ge_title' => 'Test Group', 'kop_ge_sources' => 'A handbook', 'kop_ge_notes' => "First note.\n\nSee **Bust**."));
check(empty($r['errors']), 'add a group: ' . implode('; ', $r['errors'] ?? array()));
$state = kop_glossary_store_load();
$tg = 0;
foreach ($state['nodes'] as $nid => $n) {
    if ($n['title'] === 'Test Group') {
        $tg = $nid;
    }
}
check($tg > 0 && $state['nodes'][$tg]['notes'] === array('First note.', 'See **Bust**.'), 'its notes are two paragraphs');
$r = post(array('kop_ge_do' => 'node_save', 'kop_ge_node' => $tg, 'kop_ge_title' => 'Test Group', 'kop_ge_sources' => '', 'kop_ge_notes' => 'See **Nothing Called This**.'));
check(!empty($r['errors']), 'a note with a broken reference is refused');
post(array('kop_ge_do' => 'node_save', 'kop_ge_node' => $tg, 'kop_ge_title' => 'Test Group Renamed', 'kop_ge_sources' => '', 'kop_ge_notes' => ''));
$state = kop_glossary_store_load();
check($state['nodes'][$tg]['anchor'] === 'test-group-renamed', 'a renamed group gets a new #id');
$r = post(array('kop_ge_do' => 'save', 'kop_ge_entry' => '', 'kop_ge_term' => 'Test In Group', 'kop_ge_note' => '',
    'kop_ge_aka' => '', 'kop_ge_text' => 'x.', 'kop_ge_used' => '', 'kop_ge_reported' => '', 'kop_ge_node' => $tg));
$r = post(array('kop_ge_do' => 'node_delete', 'kop_ge_node' => $tg));
check(!empty($r['errors']), 'a group with entries cannot be deleted');
post(array('kop_ge_do' => 'delete', 'kop_ge_entry' => 'test-in-group'));
$before = array_keys(array_filter(kop_glossary_store_load()['nodes'], function ($n) use ($shared) { return $n['parent_id'] === $shared; }));
post(array('kop_ge_do' => 'node_up', 'kop_ge_node' => $tg));
$after = array_keys(array_filter(kop_glossary_store_load()['nodes'], function ($n) use ($shared) { return $n['parent_id'] === $shared; }));
check(count($before) < 2 || $before !== $after, 'Move up changes the order');
$r = post(array('kop_ge_do' => 'node_delete', 'kop_ge_node' => $tg));
check(empty($r['errors']) && !isset(kop_glossary_store_load()['nodes'][$tg]), 'an empty group deletes');

$r = post(array('kop_ge_do' => 'meta_save', 'kop_ge_title' => 'TTI Glossary', 'kop_ge_intro' => implode("\n\n", data_now()['intro'])));
check(empty($r['errors']), 'the introduction saves');
$d = first_diff(plain(data_now()), array_merge($from_md['data'], array('updated' => data_now()['updated'])));
check($d === '', "after every change is undone or deleted, the data matches glossary.md again ($d)");

/* ---- Open data export ------------------------------------------------------- */

$tmp = tempnam(sys_get_temp_dir(), 'kopgl');
check(kop_glossary_store_export_file($tmp), 'the export writes');
$exported = json_decode(file_get_contents($tmp), true);
@unlink($tmp);
check(first_diff(array_keys($exported), array_keys($from_md['data'])) === '' && !isset($exported['sections'][0]['key']), 'the export has the old glossary.json shape');

/* ---- Import with the old editor's overlay ----------------------------------- */

foreach (array('nodes', 'entries', 'aliases', 'tags', 'log') as $t) {
    $GLOBALS['wpdb']->query('DELETE FROM ' . kop_glossary_table($t));
}
$paras = kop_glossary_paragraphs($md);
$bust = null;
foreach ($paras as $p) {
    if (strpos($p, '**Bust**') === 0) {
        $bust = $p;
    }
}
update_option('kop_glossary_edits', array('ops' => array(
    array('id' => 'a', 'target' => $bust, 'markdown' => kop_glossary_parse_entry($bust, true)['head'] . 'Edited live.' . kop_glossary_parse_entry($bust, true)['tags_raw'], 'container' => null, 'date' => '2099-01-01'),
)));
delete_option('kop_glossary_imported');
$errors = kop_glossary_store_import();
check(!$errors, 'import with an overlay: ' . implode('; ', $errors));
$e = entry_by_anchor('bust');
check($e && $e['text'] === 'Edited live.', 'the old editor\'s live edit is in the imported entry');
check(get_option('kop_glossary_edits') === false && get_option('kop_glossary_edits_imported') !== false, 'the overlay option is retired, a copy kept');
check(data_now()['updated'] === '2099-01-01', 'the updated date follows the overlay');

echo $failures ? "\n$failures failure(s).\n" : "OK: {$data['count']} entries imported, edits, undo, sections and the overlay import checked.\n";
exit($failures ? 1 : 0);
