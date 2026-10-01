<?php
/**
 * Offline checks for young adult programs (inc/young-adult-programs.php) and
 * their tab on Woodbury Facts (inc/woodbury-facts.php).
 *
 *   php scripts/test-young-adult-programs.php [--db=tmp/prod.sqlite] [--facts=C:/tmp/kop-woodbury/pending/facts.json]
 *
 * The program table is made in an in-memory database; nothing is written to
 * the mirror. Every no-record proposal in facts.json is turned into a fact,
 * one program's whole card is added to a record and undone, and the result
 * must be the record exactly as it was. The public listing is rendered.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'facts::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$facts_path = $args['facts'] ?? 'C:/tmp/kop-woodbury/pending/facts.json';
if (!file_exists($db_path) || !file_exists($facts_path)) {
    fwrite(STDERR, "Need $db_path (scripts/sync-prod-sqlite.py) and $facts_path (scripts/woodbury-facts.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/facility-finder.php';
require_once dirname(__DIR__) . '/inc/young-adult-programs.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

$ya = new PDO('sqlite::memory:');
$ya->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ya->exec("CREATE TABLE young_adult_programs (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_key TEXT NOT NULL UNIQUE,
    other_names TEXT, city TEXT NOT NULL DEFAULT '', state TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '', ages TEXT NOT NULL DEFAULT '',
    program_type TEXT NOT NULL DEFAULT '', run_by TEXT NOT NULL DEFAULT '', opened INTEGER, closed INTEGER, status TEXT NOT NULL DEFAULT 'Unknown',
    notes TEXT, links TEXT, facts TEXT, review TEXT NOT NULL DEFAULT 'approved', source TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
function kop_seed_pdo() { return $GLOBALS['kop_test_ya_pdo']; }
$GLOBALS['kop_test_ya_pdo'] = $ya;
$strip = function ($row) { unset($row['updated_at']); return $row; };

echo "-- Records --\n";
$id = kop_ya_save($ya, array('name' => 'Echo Springs Transition Study Center', 'state' => 'Utah', 'city' => 'Moab', 'ages' => '18-24'), 0, 'tester');
$p = kop_ya_get($ya, $id);
$check('a program is saved', $p && $p['name'] === 'Echo Springs Transition Study Center' && $p['review'] === 'approved' && $p['facts'] === '[]');
$check('a US state is kept as its code', $p['state'] === 'UT', $p['state']);
$refused = false;
try {
    kop_ya_save($ya, array('name' => 'echo springs transition study center'), 0, 'tester');
} catch (RuntimeException $e) {
    $refused = true;
}
$check('the same name twice is refused', $refused);
kop_ya_save($ya, array_merge($p, array('other_names' => 'Echo Springs')), $id, 'tester');
$check('found by an other name', (int) (kop_ya_find_by_name($ya, 'Echo Springs')['id'] ?? 0) === $id);

echo "-- Facts and exact undo --\n";
$before = $strip(kop_ya_get($ya, $id));
$done = kop_ya_add_fact($ya, $id, array('key' => 'k1', 'group' => 'history', 'label' => 'Start year: 2004', 'cites' => array()),
    array('opened' => 2004, 'ages' => '17-26', 'other_names' => 'Echo Springs Study', 'run_by' => 'Cooper Partners'));
$p = kop_ya_get($ya, $id);
$check('an empty field is filled', (int) $p['opened'] === 2004 && $p['run_by'] === 'Cooper Partners');
$check('a field with a value is left alone', $p['ages'] === '18-24');
$check('an other name is added as a line', in_array('Echo Springs Study', kop_ya_lines($p['other_names']), true));
$refused = false;
try {
    kop_ya_add_fact($ya, $id, array('key' => 'k1', 'group' => 'history', 'label' => 'x', 'cites' => array()));
} catch (RuntimeException $e) {
    $refused = true;
}
$check('the same fact twice is refused', $refused);
kop_ya_remove_fact($ya, $done);
$check('undo gives back the record exactly', $strip(kop_ya_get($ya, $id)) == $before);

echo "-- Every no-record proposal as a fact --\n";
$data = json_decode(file_get_contents($facts_path), true);
$by = array();
$bad = 0;
$examples = array();
foreach ($data['proposals'] as $pp) {
    if ((int) $pp['facility_id'] > 0 || $pp['group'] === 'consultant') {
        continue;
    }
    $r = array('pkey' => $pp['key'], 'grp' => $pp['group'], 'op' => $pp['op'], 'path' => $pp['path'], 'value' => json_encode($pp['value']),
        'label' => $pp['label'], 'evidence' => json_encode($pp['evidence']), 'status' => 'pending', 'extra' => '{}');
    list($fact, $fill) = kop_wbf_ya_fact($r);
    if ($fact['key'] === '' || trim($fact['label']) === '' || count($fact['cites']) > 3 || ($fact['cites'] && !preg_match('#^https?://#', $fact['cites'][0]['url']))) {
        $bad++;
        $examples[] = $pp['label'];
    }
    foreach ($fill as $col => $v) {
        if (!in_array($col, kop_ya_fillable(), true)) {
            $bad++;
            $examples[] = "fills $col";
        }
    }
    $by[$pp['program']][] = $r;
}
$check('every item becomes a fact with its sources', $bad === 0, $bad . ' bad' . ($examples ? ': ' . implode('; ', array_slice($examples, 0, 3)) : ''));
printf("  %d items for %d programs\n", array_sum(array_map('count', $by)), count($by));
$ya_flag = 0;
foreach ($data['proposals'] as $pp) {
    $ya_flag += !empty($pp['young_adult']) ? 1 : 0;
}
printf("  %d items the build marks as young adult\n", $ya_flag);

uasort($by, function ($a, $b) { return count($b) - count($a); });
$program = array_key_first($by);
$card = $by[$program];
$id2 = kop_ya_save($ya, array('name' => $program, 'state' => 'UT'), 0, 'tester');
$before = $strip(kop_ya_get($ya, $id2));
$dones = array();
$refused = 0;
foreach ($card as $r) {
    list($fact, $fill) = kop_wbf_ya_fact($r);
    try {
        $dones[] = kop_ya_add_fact($ya, $id2, $fact, $fill);
    } catch (RuntimeException $e) {
        $refused++;
    }
}
$check("a whole card goes on a program ($program, " . count($card) . ' items)', count(kop_ya_facts(kop_ya_get($ya, $id2))) === count($card) && !$refused);
foreach (array_reverse($dones) as $d) {
    kop_ya_remove_fact($ya, $d);
}
$check('and undoing every item gives back the program exactly', $strip(kop_ya_get($ya, $id2)) == $before);

echo "-- The page --\n";
$closed = array('pkey' => 'c1', 'grp' => 'history', 'op' => 'set_closed', 'path' => 'operatingPeriod', 'value' => json_encode(array('endYear' => 2011)),
    'label' => 'Closed in 2011 (sets status Closed, end year 2011)', 'evidence' => json_encode(array(array('label' => 'May 2011', 'number' => '', 'page' => 4,
        'url' => 'https://kidsoverprofits.org/wp-content/uploads/w.pdf#page=4', 'quote' => 'closed', 'found' => true))), 'status' => 'pending', 'extra' => '{}');
list($fact, $fill) = kop_wbf_ya_fact($closed);
kop_ya_add_fact($ya, $id, $fact, $fill);
$hidden = kop_ya_save($ya, array('name' => 'Hidden Program', 'review' => 'pending'), 0, 'tester');
ob_start();
kop_ya_render_public();
$html = ob_get_clean();
$check('a listed program is on the page', strpos($html, 'Echo Springs Transition Study Center') !== false);
$check('a hidden program is not', strpos($html, 'Hidden Program') === false);
$check('a closing fills status and year', strpos($html, '2004') === false && strpos($html, 'Closed 2011') !== false);
$check('a fact shows without the review screen wording', strpos($html, 'Closed in 2011') !== false && strpos($html, 'sets status') === false);
$check('a fact links its Woodbury page', strpos($html, 'href="https://kidsoverprofits.org/wp-content/uploads/w.pdf#page=4"') !== false
    && strpos($html, 'Woodbury Reports, May 2011, p. 4') !== false);
$check('programs are grouped by state', strpos($html, 'id="kop-ya-utah"') !== false || strpos($html, 'id="kop-ya-ut"') !== false);

echo "-- The review card --\n";
$panel = kop_wbf_ya_panel(array('program' => 'Echo Springs', 'program_as_written' => 'Echo Springs', 'place' => 'Moab, Utah', 'ya_why' => 'Ages: 18-24'));
$check('the matching program is picked already', (bool) preg_match('/<option value="' . $id . '" selected>/', $panel));
$check('a new one can be created, ages filled in', strpos($panel, 'data-act="ya_create"') !== false && strpos($panel, 'value="18-24"') !== false);
$check('the build says why, "described as" only when it is not ages or a name',
    strpos(kop_wbf_ya_panel(array('program' => 'X', 'program_as_written' => 'X', 'place' => '', 'ya_why' => 'transition program for young adults')), 'value="transition program for young adults"') !== false);
$check('a choice made by hand wins over the build', kop_wbf_ya_for('Nobody', true) === 1 && kop_wbf_ya_for('Nobody', false) === 0);

echo $failures ? "\n$failures FAILED\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
