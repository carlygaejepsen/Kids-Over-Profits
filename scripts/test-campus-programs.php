<?php
/**
 * Separate programs on one campus (inc/campus-programs.php) against the SQLite
 * mirror of production: the suggestions (one street address, one company,
 * never a rename or homes of one program), tying Discover Seven Stars and
 * Elevations RTC (Family Help & Wellness, Syracuse UT), both pages, a third
 * program joining by hand, take off, rename, and exact Undo of each. The
 * tables a tie writes are copied into a temp database; everything else is
 * read from tmp/prod.sqlite attached beside it, so the mirror is never written.
 *
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/test-campus-programs.php [--out=tmp/campus-programs]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('out::'));
$prod = dirname(__DIR__) . '/tmp/prod.sqlite';
$out_dir = $args['out'] ?? (dirname(__DIR__) . '/tmp/campus-programs');
if (!file_exists($prod)) {
    fwrite(STDERR, "No mirror at $prod (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
if (!is_dir($out_dir)) mkdir($out_dir, 0777, true);

$db_path = sys_get_temp_dir() . '/kop-campus-programs-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->exec('ATTACH DATABASE ' . $copy->quote($prod) . ' AS prod');
foreach (array('facilities_v2', 'wpdl_kop_facility_identity', 'wpdl_kop_operator_facilities', 'wpdl_kop_facility_locations') as $t) {
    $sql = $copy->query("SELECT sql FROM prod.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.$t SELECT * FROM prod.$t");
}
$copy->exec('CREATE TABLE wpdl_kop_program_homes (home_id INTEGER PRIMARY KEY, program_id INTEGER NOT NULL, home_name TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$copy->exec('CREATE TABLE wpdl_kop_program_groups (program_id INTEGER PRIMARY KEY, created_record INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by INTEGER NOT NULL DEFAULT 0)');
$copy->exec('CREATE TABLE wpdl_kop_campuses (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by INTEGER NOT NULL DEFAULT 0)');
$copy->exec('CREATE TABLE wpdl_kop_campus_programs (facility_id INTEGER PRIMARY KEY, campus_id INTEGER NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$copy = null;

require __DIR__ . '/kop-test-harness.php';
$GLOBALS['kop_test_wpdb_writes'] = true; // update_option() keeps the log and dismissals
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($prod) . ' AS prod');
$pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($v) {
    if (!is_string($v)) return $v;
    $d = json_decode($v);
    return is_string($d) ? $d : $v;
}, 1);

// The harness answers SHOW TABLES from the main file only; the mirror's tables are attached.
$GLOBALS['wpdb'] = $wpdb = new class($pdo) extends wpdb {
    private $db;
    public function __construct(PDO $pdo) { parent::__construct($pdo); $this->db = $pdo; }
    private function fix($sql) {
        if (preg_match("/^\s*SHOW TABLES LIKE\s+('[^']*')/i", $sql, $m)) {
            return "SELECT name FROM (SELECT name FROM main.sqlite_master WHERE type = 'table' UNION SELECT name FROM prod.sqlite_master WHERE type = 'table') WHERE name LIKE $m[1]";
        }
        return $sql;
    }
    public function get_var($sql, $x = 0, $y = 0) { return parent::get_var($this->fix($sql), $x, $y); }
    public function get_results($sql, $output = OBJECT) { return parent::get_results($this->fix($sql), $output); }
    public function query($sql) { return $this->db->exec($sql); }
    public function insert($table, $data) {
        $cols = array_keys($data);
        $st = $this->db->prepare("INSERT INTO $table (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
        $ok = $st->execute(array_values($data));
        $this->insert_id = (int) $this->db->lastInsertId();
        return $ok ? 1 : false;
    }
};

if (file_exists(dirname(__DIR__) . '/inc/citations.php')) require_once dirname(__DIR__) . '/inc/citations.php';
require_once dirname(__DIR__) . '/inc/operator-history.php';
require_once dirname(__DIR__) . '/inc/program-homes.php';
require_once dirname(__DIR__) . '/inc/facility-merge-match.php';
require_once dirname(__DIR__) . '/inc/campus-programs.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$render = function ($data) {
    $GLOBALS['kop_facility_page'] = $data;
    ob_start();
    include dirname(__DIR__) . '/templates/facility-page.php';
    return ob_get_clean();
};
$snapshot = function () use ($wpdb) {
    return json_encode(array(
        $wpdb->get_results('SELECT facility_id, campus_id FROM wpdl_kop_campus_programs ORDER BY facility_id', ARRAY_A),
        $wpdb->get_results('SELECT id, name FROM wpdl_kop_campuses ORDER BY id', ARRAY_A),
    ));
};

// ---------------------------------------------------------------------------
echo "-- Suggestions --\n";
$prep = function ($id, $name, $street, $ops, $extra = array()) {
    return kop_fmerge_prepare(array('id' => $id, 'ops' => $ops, 'doc' => array_merge_recursive(array(
        'identification' => array('name' => $name), 'location' => array('state' => 'UT', 'city' => 'Provo', 'address' => $street),
    ), $extra)));
};
$fixture = kop_campus_suggest(array(
    $prep(1, 'Hilltop Girls Program', '100 Canyon Road', array(7)),
    $prep(2, 'Hilltop Boys Program', '100 Canyon Rd', array(7)),
    $prep(3, 'Ridge Academy', '100 Canyon Road', array(7)),
    $prep(4, 'Old Name School', '200 Main Street', array(8)),
    $prep(5, 'New Name School', '200 Main Street', array(8), array('identification' => array('pastNames' => array('Old Name School')))),
    $prep(6, 'Lone Program', '300 Oak Lane', array(9)),
    $prep(7, 'Other Company Program', '300 Oak Lane', array(10)),
    $prep(8, 'Acme Home North', '400 Elm Drive', array(11)),
    $prep(9, 'Acme Home South', '400 Elm Drive', array(11)),
), array(7 => 'Hilltop Health'), array(8 => 50, 9 => 50));
$check('one address under one company is suggested, renames, two companies and homes of one program are not',
    count($fixture) === 1 && $fixture[0]['ids'] === array(1, 2, 3) && $fixture[0]['company'] === 'Hilltop Health', json_encode($fixture));

$sugg = kop_campus_suggestions(true);
printf("  %d suggested campuses on the mirror\n", count($sugg));
foreach (array_slice($sugg, 0, 10) as $s) printf("    %s  (%s)\n", implode(' / ', $s['names']), $s['company']);
$fhw = null;
foreach ($sugg as $s) if (in_array(10355, $s['ids'], true) && in_array(10356, $s['ids'], true)) $fhw = $s;
$check('Discover Seven Stars and Elevations RTC are suggested together', (bool) $fhw);

// ---------------------------------------------------------------------------
echo "\n-- Tie --\n";
$empty = $snapshot();
$r = kop_campus_tie(array(10355, 10356), 'Syracuse campus');
$cid = $r['campus'];
$check('the two are on one campus', $cid > 0 && kop_campus_of(10355) === $cid && kop_campus_of(10356) === $cid);
$check('each lists the other', kop_campus_others(10355) === array(10356) && kop_campus_others(10356) === array(10355));
$check('the pair is no longer suggested', !array_filter(kop_campus_suggestions(true), function ($s) { return in_array(10355, $s['ids'], true) && in_array(10356, $s['ids'], true); }));
$fp = kop_campus_cache_key();
$check('the page fingerprint part moves with a tie', $fp !== '-' && $fp !== '0||');

echo "\n-- Pages --\n";
kop_facility_pages_index(true);
$a = kop_facility_page_data(10355);
$check('the page carries its campus', ($a['campus']['name'] ?? '') === 'Syracuse campus' && count($a['campus']['others']) === 1 && $a['campus']['others'][0]['id'] === 10356);
$other_url = $a['campus']['others'][0]['url'];
$check('the campus program leaves "Same operator"', !array_filter($a['siblings'], function ($s) use ($other_url) { return $s['url'] !== '' && $s['url'] === $other_url; }));
$html = $render($a);
file_put_contents($out_dir . '/discover-seven-stars.html', $html);
$check('the header says it shares the campus', strpos($html, 'kop-fp-campus-of') !== false && strpos($html, 'On the Syracuse campus with') !== false);
$check('the page prints the campus section', substr_count($html, 'class="kop-fp-records kop-fp-campus"') === 1 && strpos($html, 'Other programs on the Syracuse campus') !== false);
$check('the jump links name the section', strpos($html, 'Same campus') !== false);
$b = kop_facility_page_data(10356);
$html = $render($b);
file_put_contents($out_dir . '/elevations-rtc.html', $html);
$check('the other page names it back', ($b['campus']['others'][0]['id'] ?? 0) === 10355 && strpos($html, 'kop-fp-campus-section') !== false);
$check('the app gets the campus', strpos(file_get_contents(dirname(__DIR__) . '/inc/mobile-api.php'), "'home_of', 'campus',") !== false);

// ---------------------------------------------------------------------------
echo "\n-- Join, rename, take off, undo --\n";
$two = $snapshot();
$third = (int) $wpdb->get_var("SELECT f.id FROM facilities_v2 f JOIN wpdl_kop_operator_facilities o ON o.facility_id = f.id
    WHERE o.operator_id = (SELECT operator_id FROM wpdl_kop_operator_facilities WHERE facility_id = 10355 LIMIT 1) AND f.id NOT IN (10355, 10356) LIMIT 1");
if (!$third) $third = (int) $wpdb->get_var('SELECT id FROM facilities_v2 WHERE id NOT IN (10355, 10356) ORDER BY id LIMIT 1');
$j = kop_campus_tie(array($third, 10356));
$check('a third program joins the campus it is tied to', $j['campus'] === $cid && count(kop_campus_others(10355)) === 2);
$check('joining keeps the campus name', kop_campus_map()['campuses'][$cid]['name'] === 'Syracuse campus');
kop_campus_undo($j['log']);
$check('undo of the join puts the campus back exactly', $snapshot() === $two);

$rn = kop_campus_rename($cid, 'Family Help & Wellness campus');
$check('rename', kop_campus_map()['campuses'][$cid]['name'] === 'Family Help & Wellness campus');
kop_campus_undo($rn);
$check('undo of the rename', $snapshot() === $two);

// Two campuses tied together become one.
$other_pair = (int) $wpdb->get_var('SELECT id FROM facilities_v2 WHERE id NOT IN (10355, 10356, ' . $third . ') ORDER BY id LIMIT 1 OFFSET 5');
$m1 = kop_campus_tie(array($third, $other_pair), 'Second');
$m2 = kop_campus_tie(array($third, 10355));
$check('tying across two campuses joins them', kop_campus_of($other_pair) === kop_campus_of(10356) && count(kop_campus_map()['campuses']) === 1);
kop_campus_undo($m2['log']);
$check('undo splits them again', count(kop_campus_map()['campuses']) === 2 && kop_campus_of($other_pair) !== kop_campus_of(10356));
kop_campus_undo($m1['log']);
$check('...and undo of the second campus leaves the first as it was', $snapshot() === $two);

$rm = kop_campus_remove(10356);
$check('taking one of two off ends the campus', !kop_campus_of(10355) && !kop_campus_map()['campuses']);
kop_campus_undo($rm);
$check('undo of take off', $snapshot() === $two);
$threw = '';
try { kop_campus_undo($rm); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
$check('an undo cannot run twice', $threw !== '', $threw);

kop_campus_undo($r['log']);
$check('undo of the first tie leaves nothing', $snapshot() === $empty);

// ---------------------------------------------------------------------------
echo "\n-- Homes of one program are not a campus --\n";
$wpdb->insert('wpdl_kop_program_homes', array('home_id' => 10355, 'program_id' => 10356, 'home_name' => 'x'));
kop_program_homes_map(true);
$threw = '';
try { kop_campus_tie(array(10355, 10356)); } catch (RuntimeException $e) { $threw = $e->getMessage(); }
$check('a home and its program are refused', $threw !== '' && $snapshot() === $empty, $threw);
$wpdb->query('DELETE FROM wpdl_kop_program_homes');
kop_program_homes_map(true);

register_shutdown_function(function () use ($db_path) {
    try { @unlink($db_path); } catch (Throwable $e) {}
});
echo "  rendered to $out_dir\n";
echo $failures ? "\n$failures failure(s).\n" : "\nAll checks passed.\n";
exit($failures ? 1 : 0);
