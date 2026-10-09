<?php
/**
 * Offline checks for person ids (inc/people.php) and Merge People
 * (inc/people-merge.php) against the production mirror.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-people.php [--db=tmp/prod.sqlite] [--list] [--no-build]
 *
 * The tables the people sync and the map build read are copied into
 * tmp/test-people/people.sqlite, so the mirror is never changed.
 *   - the first sync gives every staff entry with a two-word name and every
 *     map person an id (one id per name key) and changes nothing else in any
 *     document; the second changes nothing
 *   - the pair finder: each rule on made-up names, then the real list
 *   - a merge from the screen moves the entries, and Undo puts every
 *     document and person row back exactly
 *   - Separate, Same person as, a renamed entry and a renamed person
 *   - personKey() in the map build matches kop_people_key() on every name
 *   - (unless --no-build) a map build from the copy stamps personId on the
 *     person nodes and draws two merged map people as one, into tmp/ only
 * --list prints the pairs Merge People would offer.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$args = getopt('', array('db::', 'list', 'no-build'));
$root = dirname(__DIR__);
$db_path = $args['db'] ?? ($root . '/tmp/prod.sqlite');
if (!file_exists($db_path)) {
    fwrite(STDERR, "Need $db_path (scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

define('ABSPATH', $root . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
$GLOBALS['kop_test_options'] = array();
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function get_option($k, $d = false) { return $GLOBALS['kop_test_options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['kop_test_options'][$k] = $v; return true; }
function get_transient($k) { return false; }
function set_transient() { return true; }
function delete_transient() { return true; }
function home_url($p = '') { return 'https://example.test/' . ltrim($p, '/'); }
function get_page_by_path() { return null; }
function get_stylesheet_directory() { return dirname(__DIR__); }
function remove_accents($s) { return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $s); }
$GLOBALS['wpdb'] = (object) array('prefix' => 'wpdl_');
require $root . '/inc/facility-store.php';
require $root . '/inc/facility-pages.php';
require $root . '/inc/people.php';
require $root . '/inc/people-merge.php';

$prefix = 'wpdl_';
$dir = $root . '/tmp/test-people';
if (!is_dir($dir)) mkdir($dir, 0777, true);
$file = $dir . '/people.sqlite';
if (file_exists($file)) unlink($file);
$pdo = new PDO('sqlite:' . $file);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA synchronous = OFF');
$pdo->exec('PRAGMA journal_mode = MEMORY');
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($db_path) . ' AS src');
foreach (array('facilities_v2', $prefix . 'kop_operators', $prefix . 'kop_operator_facilities', 'memorial_victims', 'referrers_master',
    $prefix . 'transporters_master', 'providers_master', 'lawsuits', 'young_adult_programs', 'wiki_submissions', 'journalists') as $t) {
    $sql = $pdo->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn();
    if (!$sql) { echo "  (no $t in the mirror)
"; continue; }
    $pdo->exec($sql);
    $pdo->exec("INSERT INTO main.`$t` SELECT * FROM src.`$t`");
}
$pdo->exec('DETACH DATABASE src');
// The MySQL tables of kop_people_install(), in SQLite.
$pdo->exec("CREATE TABLE {$prefix}kop_people (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_key TEXT NOT NULL,
    aliases TEXT, merged_into INTEGER, notes TEXT, pool TEXT NOT NULL DEFAULT 'industry', created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE {$prefix}kop_person_roles (record_kind TEXT NOT NULL, record_id INTEGER NOT NULL, list TEXT NOT NULL,
    position INTEGER NOT NULL, person_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT '', role TEXT, ref TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (record_kind, record_id, list, position))");
$graph = json_decode(file_get_contents($root . '/js/data/network/graph.json'), true);
// The deployed graph carries the site's person ids; this table starts empty, so the nodes start without them.
foreach ($graph['nodes'] as $i => $n) unset($graph['nodes'][$i]['personId']);
$opts = array('pdo' => $pdo, 'prefix' => $prefix, 'graph' => $graph);

$fails = 0;
function check($ok, $what) {
    global $fails;
    if (!$ok) $fails++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
}
function docs(PDO $pdo) {
    $out = array();
    foreach ($pdo->query('SELECT id, json_data FROM facilities_v2')->fetchAll(PDO::FETCH_NUM) as $r) $out[(int) $r[0]] = $r[1];
    return $out;
}
function people_rows(PDO $pdo) {
    return $pdo->query('SELECT id, name, name_key, aliases, merged_into FROM wpdl_kop_people ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}
function strip_ids($doc) {
    foreach (array('administrator', 'notableStaff') as $l) {
        if (empty($doc['staff'][$l]) || !is_array($doc['staff'][$l])) continue;
        foreach ($doc['staff'][$l] as $i => $e) if (is_array($e)) unset($doc['staff'][$l][$i]['personId']);
    }
    return $doc;
}
function entry_id($pdo, $fid, $list, $pos) {
    $doc = json_decode($pdo->query('SELECT json_data FROM facilities_v2 WHERE id = ' . (int) $fid)->fetchColumn(), true);
    return (int) ($doc['staff'][$list][$pos]['personId'] ?? 0);
}

// The mirror's documents carry the site's person ids; start from documents without them, as the first sync did.
$upd = $pdo->prepare('UPDATE facilities_v2 SET json_data = ? WHERE id = ?');
foreach (docs($pdo) as $id => $json) {
    $doc = json_decode($json, true);
    $bare = is_array($doc) ? strip_ids($doc) : $doc;
    if ($bare != $doc) $upd->execute(array(kop_facility_json_encode($bare), $id));
}
$before = docs($pdo);

// ---- Sync ---------------------------------------------------------------------
echo "First sync\n";
$t0 = microtime(true);
$s = kop_people_sync($opts);
printf("  %d entries on %d facilities, %d map people, %d people, %d entries given an id, %d docs written, %d names with no key, %d links (%.1fs)\n",
    $s['entries'], $s['facilities'], $s['map'], $s['created'], $s['stamped'], $s['docs_written'], $s['unkeyed'], $s['roles'], microtime(true) - $t0);
$after = docs($pdo);
$other = 0;
$keys = array();
$missing = 0;
$normal = 0;
foreach ($after as $id => $json) {
    $doc = json_decode($json, true);
    if (strip_ids($doc) != json_decode($before[$id], true)) $other++;
    foreach (array('administrator', 'notableStaff') as $l) {
        foreach ((array) ($doc['staff'][$l] ?? array()) as $e) {
            if (!is_array($e)) continue;
            $k = kop_people_key($e['name'] ?? '');
            if ($k === '') continue;
            if (empty($e['personId'])) { $missing++; continue; }
            $keys[$k][(int) $e['personId']] = true;
        }
        if (kop_facility_person_list($doc['staff'][$l] ?? array()) != array_values(array_filter((array) ($doc['staff'][$l] ?? array()), 'is_array'))) $normal++;
    }
}
$map_people = 0;
foreach ($graph['nodes'] as $n) if (($n['kind'] ?? '') === 'person' && kop_people_key($n['name']) !== '') $map_people++;
check($s['created'] > 1000 && $s['stamped'] > 1000, 'people created and entries stamped');
check($missing === 0, "every two-word name has an id ($missing missing)");
check($other === 0, "nothing else changed in any document ($other changed)");
check(count(array_filter($keys, function ($ids) { return count($ids) > 1; })) === 0, 'one id per name key across records');
check($normal === 0, "kop_facility_person_list() keeps personId ($normal lists differ)");
$ops = (int) $pdo->query("SELECT COUNT(*) FROM {$prefix}kop_person_roles WHERE record_kind = 'operator'")->fetchColumn();
check($ops > 0, "company founders and executives linked ($ops)");
check($s['map'] >= $map_people, "every map person has an id ({$s['map']} of $map_people with two-word names)");
$both = (int) $pdo->query("SELECT COUNT(DISTINCT m.person_id) FROM {$prefix}kop_person_roles m JOIN {$prefix}kop_person_roles f
    ON f.person_id = m.person_id AND f.record_kind = 'facility' WHERE m.record_kind = 'map'")->fetchColumn();
check($both > 50, "$both map people are the same ids as facility staff");

echo "Second sync\n";
$s2 = kop_people_sync($opts);
check($s2['created'] === 0 && $s2['stamped'] === 0 && $s2['docs_written'] === 0, 'changes nothing' . ($s2['created'] || $s2['stamped'] || $s2['docs_written'] ? ' ' . json_encode($s2) : ''));
check($s2['roles'] === $s['roles'], 'same links');

// ---- Everyone else the records name ----------------------------------------------
echo "Other records\n";
$pool_of = array();
foreach ($pdo->query("SELECT id, pool FROM {$prefix}kop_people")->fetchAll(PDO::FETCH_NUM) as $r) $pool_of[(int) $r[0]] = $r[1];
$want = array('consultant:consultant' => 'industry', 'transport:personnel' => 'industry', 'lawsuit:staff' => 'industry', 'lawsuit:plaintiff' => 'harmed',
    'memorial:victim' => 'harmed', 'youngadult:staff' => 'industry', 'wiki:staff' => 'industry', 'journalist:byline' => 'press');
$by_slot = array();
$wrong_pool = 0;
foreach ($pdo->query("SELECT record_kind, list, person_id FROM {$prefix}kop_person_roles")->fetchAll(PDO::FETCH_NUM) as $r) {
    $slot = $r[0] . ':' . $r[1];
    $by_slot[$slot] = ($by_slot[$slot] ?? 0) + 1;
    $expect = $want[$slot] ?? 'industry';
    if (($pool_of[(int) $r[2]] ?? '') !== $expect) $wrong_pool++;
}
foreach ($want as $slot => $pool) check(!empty($by_slot[$slot]), sprintf('%s named (%d)', $slot, $by_slot[$slot] ?? 0));
check($wrong_pool === 0, 'every name has an id in its own pool (' . $wrong_pool . ' not)');
// A journalist or a memorial name that is also a staff member's is two ids.
$shared = (int) $pdo->query("SELECT COUNT(*) FROM {$prefix}kop_people a JOIN {$prefix}kop_people b ON a.name_key = b.name_key AND a.id < b.id
    AND a.pool <> b.pool")->fetchColumn();
$crossed = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT person_id FROM {$prefix}kop_person_roles GROUP BY person_id
    HAVING SUM(record_kind = 'journalist') > 0 AND SUM(record_kind IN ('facility', 'operator', 'map')) > 0)")->fetchColumn();
check($crossed === 0, "no journalist shares an id with staff ($shared names in two pools)");
// Robert W. and Robert B. Lichfield are father and son.
$w = $pdo->query("SELECT DISTINCT person_id FROM {$prefix}kop_person_roles WHERE name LIKE 'Robert W.%Lichfield'")->fetchAll(PDO::FETCH_COLUMN);
$b = $pdo->query("SELECT DISTINCT person_id FROM {$prefix}kop_person_roles WHERE name LIKE 'Robert B.%Lichfield'")->fetchAll(PDO::FETCH_COLUMN);
check($w && $b && count($w) === 1 && count($b) === 1 && $w !== $b, 'Robert W. and Robert B. Lichfield are two people, each one id');
$st = array('rows' => array(), 'by_key' => array(), 'keys' => array(), 'initials' => array());
$st['rows'][1] = array('id' => 1, 'name' => 'Robert B. Lichfield', 'name_key' => 'robert lichfield', 'aliases' => '', 'merged_into' => 0, 'pool' => 'industry');
$st['rows'][2] = array('id' => 2, 'name' => 'Robert W. Lichfield', 'name_key' => 'robert lichfield', 'aliases' => '', 'merged_into' => 0, 'pool' => 'industry');
kop_people_index_row($st, 1);
kop_people_index_row($st, 2);
check(kop_people_find($st, 'Robert W. Lichfield') === 2 && kop_people_find($st, 'Robert B Lichfield') === 1 && kop_people_find($st, 'Robert Lichfield') === 1
    && kop_people_find($st, 'Robert Lichfield', 'press') === 0, 'a middle initial picks the person, none takes the lowest id, another pool finds nobody');
$fake_pairs = kop_pmerge_find_pairs(array(1 => array('name' => 'Robert B. Lichfield', 'name_key' => 'robert lichfield'),
    2 => array('name' => 'Robert W. Lichfield', 'name_key' => 'robert lichfield')), array());
check(!$fake_pairs, 'Merge People does not offer two middle initials as one person');
// What reads as a name.
$yes = array('Sonny Faaootoa', 'Siuta S. Faaootoa', "John J. O'Connor", 'Gwendolyn Lockwood Hales', 'Mary Ann McMahan-McWhorter', 'C. Richard Wyatt',
    'Frederic H. Yeomans IV', "Ja\u{2019}Ceon Terry", 'Dr. Matthew L. Israel');
$no = array('Provo Canyon School', 'Jane Doe 1', 'FNU Bell', 'Kathy [LNU]', 'Teamvestment L.L.C.', 'United States of America', 'Anonymous survivors',
    'Golden Ark Enterprises', 'LMSW-CASAC', 'Unknown', '(Unnamed boy)', 'Class of boys placed at Provo Canyon School', 'Hawkins');
$bad = array();
foreach ($yes as $n) if (!kop_people_is_person_name($n)) $bad[] = "not: $n";
foreach ($no as $n) if (kop_people_is_person_name($n)) $bad[] = "is: $n";
check(!$bad, 'person names told from companies and stand-ins' . ($bad ? ' (' . implode('; ', $bad) . ')' : ''));
$split = kop_people_split_list('Oliver Whitcomb and Amy Clifford, on behalf of an anonymous survivor');
check($split === array('Oliver Whitcomb', 'Amy Clifford'), 'a party list splits into its people: ' . implode(' | ', $split));
check(kop_people_split_list('The Estate of Jason Britt') === array('Jason Britt'), 'an estate is the person it was');
check(kop_people_split_named('Bobby Tredinnick, LMSW-CASAC (CEO)') === array('Bobby Tredinnick', 'CEO'), 'a personnel line gives name and role');
$owners = $pdo->query("SELECT name FROM {$prefix}kop_person_roles WHERE list IN ('owners', 'owner')")->fetchAll(PDO::FETCH_COLUMN);
check(!array_intersect($owners, array('Twin Oaks', 'Vision Quest', 'New Passages', 'Western Maine', 'Catholic Charities')), 'company and region owners get no id (' . implode(', ', $owners) . ')');

// ---- Pair finder ---------------------------------------------------------------
echo "Pair finder\n";
$fake = array();
$names = array(1 => 'Kris Hayes', 2 => 'Kristen Hayes', 3 => 'Melinda Heller-Nellos', 4 => 'Melinda Heller', 5 => 'Paul Ravenscraft',
    6 => 'Paul Ravenscroft', 7 => 'Hayes Kris', 8 => 'John Smith', 9 => 'Jane Smith', 10 => 'Mary Jones', 11 => 'Mary Jones', 12 => 'Shawn Ward', 13 => 'Shaun Ward');
foreach ($names as $id => $n) $fake[$id] = array('name' => $n, 'name_key' => kop_people_key($n));
$recs = array(8 => array('facility1'), 9 => array('facility1'), 12 => array('facility2'), 13 => array('facility2'));
$found = array();
foreach (kop_pmerge_find_pairs($fake, $recs) as $p) $found[$p['a'] . ':' . $p['b']] = $p['reason']['code'] . '/' . $p['reason']['level'];
check(($found['1:2'] ?? '') === 'short/check', 'short first name ' . ($found['1:2'] ?? 'none'));
check(($found['3:4'] ?? '') === 'maiden/likely', 'maiden or married name ' . ($found['3:4'] ?? 'none'));
check(($found['5:6'] ?? '') === 'spelling/check', 'last name one letter apart ' . ($found['5:6'] ?? 'none'));
check(($found['1:7'] ?? '') === 'swapped/check', 'swapped names ' . ($found['1:7'] ?? 'none'));
check(($found['8:9'] ?? '') === 'initial/check', 'same initial at the same program ' . ($found['8:9'] ?? 'none'));
check(($found['10:11'] ?? '') === 'same/check', 'the same name under two ids ' . ($found['10:11'] ?? 'none'));
check(($found['12:13'] ?? '') === 'spelling/likely', 'one letter apart at the same program is likely ' . ($found['12:13'] ?? 'none'));
$keys_left = array_map(function ($p) { return $p['a'] . ':' . $p['b']; }, kop_pmerge_find_pairs($fake, $recs, array('1:2' => 1)));
check(!in_array('1:2', $keys_left, true), 'a dismissed pair is not offered');

$t0 = microtime(true);
$screen = kop_pmerge_screen_data(true, $opts);
$tabs = array('likely' => 0, 'check' => 0);
foreach ($screen['pairs'] as $p) $tabs[$p['tab']]++;
printf("  real records: %d likely, %d worth a look (%.1fs)\n", $tabs['likely'], $tabs['check'], microtime(true) - $t0);
check($tabs['likely'] + $tabs['check'] > 0, 'the screen finds pairs in the real records');
if (isset($args['list'])) {
    foreach ($screen['pairs'] as $p) {
        printf("  [%s] %s (#%d, %d) / %s (#%d, %d): %s\n", $p['tab'], $p['a']['name'], $p['a']['id'], $p['a']['n'], $p['b']['name'], $p['b']['id'], $p['b']['n'], $p['reason']);
    }
}

// ---- Merge from the screen, then Undo ------------------------------------------
$pair = null;
foreach ($screen['pairs'] as $p) {
    if ($p['a']['n'] && $p['b']['n'] && count(array_filter($p['a']['records'], function ($r) { return $r['kind'] === 'facility'; }))
        && count(array_filter($p['b']['records'], function ($r) { return $r['kind'] === 'facility'; }))) { $pair = $p; break; }
}
check($pair !== null, 'a pair with staff entries on both sides');
if ($pair) {
    $keep = $pair['keep'];
    $drop = $keep === $pair['a']['id'] ? $pair['b']['id'] : $pair['a']['id'];
    echo "Merge: #$drop into #$keep\n";
    $docs0 = docs($pdo);
    $people0 = people_rows($pdo);
    $msg = kop_pmerge_do_merge($keep, $drop, 'test', $opts);
    echo "  $msg\n";
    check((int) $pdo->query("SELECT COUNT(*) FROM {$prefix}kop_person_roles WHERE person_id = $drop")->fetchColumn() === 0, 'nothing names the dropped id');
    check(kop_people_resolve(kop_people_load($opts), $drop) === $keep, 'the dropped id forwards to the kept one');
    $s3 = kop_people_sync($opts);
    check($s3['stamped'] === 0 && $s3['created'] === 0, 'a sync after the merge changes nothing');
    $log = kop_pmerge_log();
    echo kop_pmerge_do_undo($log[0]['id'], $opts) . "\n";
    check(docs($pdo) === $docs0, 'Undo puts every document back exactly');
    check(people_rows($pdo) === $people0, 'Undo puts every person row back exactly');
    $threw = false;
    try { kop_pmerge_do_undo($log[0]['id'], $opts); } catch (RuntimeException $e) { $threw = true; }
    check($threw, 'an undone merge cannot be undone twice');
}

// ---- Separate, Same person as, renames -------------------------------------------
$multi = $pdo->query("SELECT person_id, COUNT(*) n FROM {$prefix}kop_person_roles WHERE record_kind = 'facility'
    GROUP BY person_id HAVING n > 1 ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
check(count($multi) > 50, count($multi) . ' people named on more than one facility');
$pid = (int) $multi[0]['person_id'];
$roles = array_values(array_filter(kop_people_roles_of($pid, $opts), function ($r) { return $r['record_kind'] === 'facility'; }));
$sep = $roles[count($roles) - 1];
$name = $pdo->query("SELECT name FROM {$prefix}kop_people WHERE id = $pid")->fetchColumn();
echo "Separate: one of $name's (#$pid) " . count($roles) . " entries\n";
$new = kop_people_separate($sep['record_id'], $sep['list'], $sep['position'], $opts);
kop_people_sync($opts);
check($new > 0 && entry_id($pdo, $sep['record_id'], $sep['list'], $sep['position']) === $new, 'the entry keeps its new id after a sync');
check(entry_id($pdo, $roles[0]['record_id'], $roles[0]['list'], $roles[0]['position']) === $pid, 'the other entries keep the old id');
check(isset(kop_pmerge_dismissed()[min($pid, $new) . ':' . max($pid, $new)]), 'Separate marks the pair not the same');
$GLOBALS['wpdb'] = (object) array('prefix' => 'wpdl_');

echo "Same person as\n";
check((bool) kop_people_merge($new, $pid, $opts), 'merge');
kop_people_sync($opts);
check(entry_id($pdo, $sep['record_id'], $sep['list'], $sep['position']) === $pid, 'the entry moves back to the kept id');
check(kop_people_resolve(kop_people_load($opts), $new) === $pid, 'the joined id forwards');

echo "Renamed entry\n";
$doc = json_decode($pdo->query('SELECT json_data FROM facilities_v2 WHERE id = ' . (int) $sep['record_id'])->fetchColumn(), true);
$doc['staff'][$sep['list']][$sep['position']]['name'] = 'Zebulon Testperson';
$pdo->prepare('UPDATE facilities_v2 SET json_data = ? WHERE id = ?')->execute(array(kop_facility_json_encode($doc), $sep['record_id']));
$s4 = kop_people_sync($opts);
check(entry_id($pdo, $sep['record_id'], $sep['list'], $sep['position']) !== $pid && $s4['created'] === 1, 'an entry renamed to someone else gets their id');

echo "Renamed person\n";
$p2 = (int) $multi[1]['person_id'];
$n_before = count(kop_people_roles_of($p2, $opts));
$row = kop_people_load($opts)['rows'][$p2];
$pdo->prepare("UPDATE {$prefix}kop_people SET name = ?, name_key = ?, aliases = ? WHERE id = ?")
    ->execute(array('Qwerty ' . $row['name'], kop_people_key('Qwerty ' . $row['name']), $row['name'], $p2));
$s5 = kop_people_sync($opts);
check(count(kop_people_roles_of($p2, $opts)) === $n_before && $s5['stamped'] === 0, 'a renamed person keeps their entries (old name kept as an other name)');
$pdo->prepare("UPDATE {$prefix}kop_people SET name = ?, name_key = ?, aliases = ? WHERE id = ?")->execute(array($row['name'], $row['name_key'], $row['aliases'], $p2));

// ---- The map build's personKey() matches kop_people_key() ---------------------------
echo "personKey parity\n";
$all = array();
foreach (docs($pdo) as $json) {
    $d = json_decode($json, true);
    foreach (array('administrator', 'notableStaff') as $l) foreach ((array) ($d['staff'][$l] ?? array()) as $e) if (is_array($e)) $all[] = (string) ($e['name'] ?? '');
}
foreach ($graph['nodes'] as $n) if (($n['kind'] ?? '') === 'person') $all = array_merge($all, array($n['name']), (array) ($n['aliases'] ?? array()));
$all = array_values(array_unique(array_filter($all, function ($n) { return preg_match('/^[\x20-\x7E]*$/', $n); })));
file_put_contents($dir . '/names.json', json_encode($all));
$js = shell_exec('node ' . escapeshellarg($root . '/scripts/person-key.js') . ' ' . escapeshellarg($dir . '/names.json'));
$js = json_decode((string) $js, true);
$diff = array();
foreach ($all as $i => $n) if (!is_array($js) || ($js[$i] ?? null) !== kop_people_key($n)) $diff[] = $n . ' => php "' . kop_people_key($n) . '", js "' . ($js[$i] ?? '?') . '"';
check(is_array($js) && !$diff, count($all) . ' names, ' . count($diff) . ' differ' . ($diff ? ': ' . implode('; ', array_slice($diff, 0, 5)) : ''));

// ---- A map build from the copy ------------------------------------------------------
if (!isset($args['no-build'])) {
    echo "Map build\n";
    // Two map people merged into one: the build must draw them as one node.
    // Two board people (not added by the build), each named on its own lines.
    $board = array();
    foreach ($graph['nodes'] as $n) if (($n['kind'] ?? '') === 'person' && empty($n['addedFrom']) && $n['degree'] > 1) $board[$n['id']] = true;
    $mp = array();
    foreach ($pdo->query("SELECT person_id, ref, name FROM {$prefix}kop_person_roles WHERE record_kind = 'map' ORDER BY position")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($board[$r['ref']]) && kop_people_key($r['name']) !== '') $mp[] = $r;
        if (count($mp) === 2) break;
    }
    kop_people_merge((int) $mp[1]['person_id'], (int) $mp[0]['person_id'], $opts);
    $pdo = null;
    $out = $dir . '/graph.json';
    $graph_md5 = md5_file($root . '/js/data/network/graph.json');
    putenv('KOP_NETWORK_SQLITE=' . $file);
    putenv('KOP_NETWORK_GRAPH_OUT=' . $out);
    putenv('KOP_NETWORK_QA_OUT=' . $dir . '/network-qa.md');
    $t0 = microtime(true);
    exec('node ' . escapeshellarg($root . '/scripts/build-network-graph.js') . ' 2>&1', $lines, $code);
    printf("  built in %.0fs (exit %d)\n", microtime(true) - $t0, $code);
    if ($code) echo '  ' . implode("\n  ", array_slice($lines, -10)) . "\n";
    $g = json_decode((string) @file_get_contents($out), true);
    $persons = array_filter($g['nodes'] ?? array(), function ($n) { return $n['kind'] === 'person'; });
    $with = array_filter($persons, function ($n) { return !empty($n['personId']); });
    check($code === 0 && count($with) > 0.9 * count($persons), count($with) . ' of ' . count($persons) . ' person nodes carry personId');
    $ids = array_map(function ($n) { return $n['id']; }, $g['nodes'] ?? array());
    check(in_array($mp[0]['ref'], $ids, true) && !in_array($mp[1]['ref'], $ids, true), 'two merged map people are one node (' . $mp[1]['name'] . ' -> ' . $mp[0]['name'] . ')');
    $dangling = 0;
    $set = array_flip($ids);
    foreach ($g['edges'] ?? array() as $e) if (!isset($set[$e['source']], $set[$e['target']])) $dangling++;
    check($dangling === 0, "every line ends at a node ($dangling do not)");
    check(md5_file($root . '/js/data/network/graph.json') === $graph_md5, 'js/data/network/graph.json untouched (the build wrote tmp/test-people/)');
}

echo $fails ? "\n$fails FAILED\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
