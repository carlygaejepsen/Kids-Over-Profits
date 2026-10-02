<?php
/**
 * Offline checks for person ids (inc/people.php) against the production mirror.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-people.php [--db=tmp/prod.sqlite] [--list]
 *
 * facilities_v2 and kop_operators are copied into an in-memory database, so
 * the mirror is never changed. The first sync must give every staff entry
 * with a two-word name an id (one id per name key across records) and change
 * nothing else in any document; the second must change nothing. Then
 * Separate, Same person as, a renamed entry and a renamed person are run on
 * real people. --list prints the people named on the most records.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$args = getopt('', array('db::', 'list'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($db_path)) {
    fwrite(STDERR, "Need $db_path (scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

define('ABSPATH', dirname(__DIR__) . '/');
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
function remove_accents($s) { return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $s); }
$GLOBALS['wpdb'] = (object) array('prefix' => 'wpdl_');
require dirname(__DIR__) . '/inc/facility-store.php';
require dirname(__DIR__) . '/inc/facility-pages.php';
require dirname(__DIR__) . '/inc/people.php';

$prefix = 'wpdl_';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($db_path) . ' AS src');
foreach (array('facilities_v2', $prefix . 'kop_operators') as $t) {
    $pdo->exec($pdo->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn());
    $pdo->exec("INSERT INTO main.`$t` SELECT * FROM src.`$t`");
}
$pdo->exec('DETACH DATABASE src');
// The MySQL tables of kop_people_install(), in SQLite.
$pdo->exec("CREATE TABLE {$prefix}kop_people (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_key TEXT NOT NULL,
    aliases TEXT, merged_into INTEGER, notes TEXT, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE {$prefix}kop_person_roles (record_kind TEXT NOT NULL, record_id INTEGER NOT NULL, list TEXT NOT NULL,
    position INTEGER NOT NULL, person_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT '', role TEXT,
    PRIMARY KEY (record_kind, record_id, list, position))");
$opts = array('pdo' => $pdo, 'prefix' => $prefix);

$fails = 0;
function check($ok, $what) {
    global $fails;
    if (!$ok) $fails++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
}
function docs(PDO $pdo) {
    $out = array();
    foreach ($pdo->query('SELECT id, json_data FROM facilities_v2')->fetchAll(PDO::FETCH_NUM) as $r) $out[(int) $r[0]] = json_decode($r[1], true);
    return $out;
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

$before = docs($pdo);

echo "First sync\n";
$t0 = microtime(true);
$s = kop_people_sync($opts);
printf("  %d entries on %d facilities, %d people, %d entries given an id, %d docs written, %d names with no key, %d links (%.1fs)\n",
    $s['entries'], $s['facilities'], $s['created'], $s['stamped'], $s['docs_written'], $s['unkeyed'], $s['roles'], microtime(true) - $t0);
$after = docs($pdo);
$other = 0;
$keys = array();
$missing = 0;
$normal = 0;
foreach ($after as $id => $doc) {
    if (strip_ids($doc) != $before[$id]) $other++;
    foreach (array('administrator', 'notableStaff') as $l) {
        foreach ((array) ($doc['staff'][$l] ?? array()) as $e) {
            if (!is_array($e)) continue;
            $k = kop_people_key($e['name'] ?? '');
            if ($k === '') continue;
            if (empty($e['personId'])) { $missing++; continue; }
            $keys[$k][(int) $e['personId']] = true;
        }
        // The normalizer keeps the id.
        if (kop_facility_person_list($doc['staff'][$l] ?? array()) != array_values(array_filter((array) ($doc['staff'][$l] ?? array()), 'is_array'))) $normal++;
    }
}
check($s['created'] > 500 && $s['stamped'] > 1000, 'people created and entries stamped');
check($missing === 0, "every two-word name has an id ($missing missing)");
check($other === 0, "nothing else changed in any document ($other changed)");
check(count(array_filter($keys, function ($ids) { return count($ids) > 1; })) === 0, 'one id per name key across records');
check($normal === 0, "kop_facility_person_list() keeps personId ($normal lists differ)");
$ops = (int) $pdo->query("SELECT COUNT(*) FROM {$prefix}kop_person_roles WHERE record_kind = 'operator'")->fetchColumn();
check($ops > 0, "company founders and executives linked ($ops)");

echo "Second sync\n";
$s2 = kop_people_sync($opts);
check($s2['created'] === 0 && $s2['stamped'] === 0 && $s2['docs_written'] === 0, 'changes nothing');
check($s2['roles'] === $s['roles'], 'same links');

// A person named on 2+ facilities.
$multi = $pdo->query("SELECT person_id, COUNT(*) n FROM {$prefix}kop_person_roles WHERE record_kind = 'facility'
    GROUP BY person_id HAVING n > 1 ORDER BY n DESC")->fetchAll(PDO::FETCH_ASSOC);
check(count($multi) > 50, count($multi) . ' people named on more than one facility');
$pid = (int) $multi[0]['person_id'];
$roles = kop_people_roles_of($pid, $opts);
$sep = $roles[count($roles) - 1];
$name = $pdo->query("SELECT name FROM {$prefix}kop_people WHERE id = $pid")->fetchColumn();
echo "Separate: one of $name's (#$pid) " . count($roles) . " entries\n";
$new = kop_people_separate($sep['record_id'], $sep['list'], $sep['position'], $opts);
kop_people_sync($opts);
check($new > 0 && entry_id($pdo, $sep['record_id'], $sep['list'], $sep['position']) === $new, 'the entry keeps its new id after a sync');
check(entry_id($pdo, $roles[0]['record_id'], $roles[0]['list'], $roles[0]['position']) === $pid, 'the other entries keep the old id');

echo "Same person as\n";
check(kop_people_merge($new, $pid, $opts), 'merge');
kop_people_sync($opts);
check(entry_id($pdo, $sep['record_id'], $sep['list'], $sep['position']) === $pid, 'the entry moves back to the kept id');
check(kop_people_resolve(kop_people_load($opts), $new) === $pid, 'the joined id forwards');

echo "Renamed entry\n";
$doc = json_decode($pdo->query('SELECT json_data FROM facilities_v2 WHERE id = ' . (int) $sep['record_id'])->fetchColumn(), true);
$doc['staff'][$sep['list']][$sep['position']]['name'] = 'Zebulon Testperson';
$pdo->prepare('UPDATE facilities_v2 SET json_data = ? WHERE id = ?')->execute(array(kop_facility_json_encode($doc), $sep['record_id']));
$s3 = kop_people_sync($opts);
$now = entry_id($pdo, $sep['record_id'], $sep['list'], $sep['position']);
check($now !== $pid && $s3['created'] === 1, 'an entry renamed to someone else gets their id');

echo "Renamed person\n";
$p2 = (int) $multi[1]['person_id'];
$row = kop_people_load($opts)['rows'][$p2];
$pdo->prepare("UPDATE {$prefix}kop_people SET name = ?, name_key = ?, aliases = ? WHERE id = ?")
    ->execute(array('Qwerty ' . $row['name'], kop_people_key('Qwerty ' . $row['name']), $row['name'], $p2));
$s4 = kop_people_sync($opts);
$r2 = kop_people_roles_of($p2, $opts);
check(count($r2) === (int) $multi[1]['n'] + ($ops ? (int) $pdo->query("SELECT COUNT(*) FROM {$prefix}kop_person_roles WHERE record_kind = 'operator' AND person_id = $p2")->fetchColumn() : 0)
    && $s4['stamped'] === 0, 'a renamed person keeps their entries (old name kept as an other name)');

if (isset($args['list'])) {
    echo "\nNamed on the most records:\n";
    foreach ($pdo->query("SELECT p.id, p.name, COUNT(*) n FROM {$prefix}kop_person_roles r JOIN {$prefix}kop_people p ON p.id = r.person_id
        GROUP BY p.id ORDER BY n DESC LIMIT 25")->fetchAll(PDO::FETCH_NUM) as $r) printf("  #%-5d %-32s %d\n", $r[0], $r[1], $r[2]);
}

echo $fails ? "\n$fails FAILED\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
