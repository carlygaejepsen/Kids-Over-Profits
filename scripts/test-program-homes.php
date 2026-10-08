<?php
/**
 * Homes of a program (inc/program-homes.php) against the SQLite mirror of
 * production: the suggestions, grouping Newport Academy's California homes
 * under a new program record, the program page, a home page, the company
 * page, and Undo. The tables a grouping writes (facilities_v2, the identity
 * and membership tables, the two program tables) are copied into a temp
 * database; everything else is read from tmp/prod.sqlite attached beside it,
 * so the mirror is never written.
 *
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/test-program-homes.php [--out=tmp/program-homes]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('out::'));
$prod = dirname(__DIR__) . '/tmp/prod.sqlite';
$out_dir = $args['out'] ?? (dirname(__DIR__) . '/tmp/program-homes');
if (!file_exists($prod)) {
    fwrite(STDERR, "No mirror at $prod (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
if (!is_dir($out_dir)) mkdir($out_dir, 0777, true);

$db_path = sys_get_temp_dir() . '/kop-program-homes-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->exec('ATTACH DATABASE ' . $copy->quote($prod) . ' AS prod');
foreach (array('facilities_v2', 'wpdl_kop_facility_identity', 'wpdl_kop_operator_facilities', 'wpdl_kop_facility_locations') as $t) {
    $sql = $copy->query("SELECT sql FROM prod.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.$t SELECT * FROM prod.$t");
}
// On production these columns are generated from json_data; here a trigger fills them.
$copy->exec("CREATE TRIGGER facilities_v2_cols AFTER INSERT ON facilities_v2 BEGIN
    UPDATE facilities_v2 SET name = json_extract(NEW.json_data, '$.identification.name'),
        name_key = json_extract(NEW.json_data, '$.identification.nameKey'),
        state = json_extract(NEW.json_data, '$.location.state'), city = json_extract(NEW.json_data, '$.location.city'),
        country = json_extract(NEW.json_data, '$.location.country'), status = json_extract(NEW.json_data, '$.operatingPeriod.status'),
        facility_type = json_extract(NEW.json_data, '$.facilityDetails.type'),
        start_year = json_extract(NEW.json_data, '$.operatingPeriod.startYear'), end_year = json_extract(NEW.json_data, '$.operatingPeriod.endYear')
    WHERE id = NEW.id; END");
$copy->exec('CREATE TABLE wpdl_kop_program_homes (home_id INTEGER PRIMARY KEY, program_id INTEGER NOT NULL, home_name TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$copy->exec('CREATE TABLE wpdl_kop_program_groups (program_id INTEGER PRIMARY KEY, created_record INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, created_by INTEGER NOT NULL DEFAULT 0)');
$copy = null;

require __DIR__ . '/kop-test-harness.php';
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($prod) . ' AS prod');
// MySQL functions the facility store uses.
$pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($v) {
    if (!is_string($v)) return $v;
    $d = json_decode($v);
    return is_string($d) ? $d : $v;
}, 1);

// The harness answers SHOW TABLES from the main file only; the mirror's tables
// are attached. It also has no insert().
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
        return $st->execute(array_values($data)) ? 1 : false;
    }
};

// The template's source links, when that module is there.
if (file_exists(dirname(__DIR__) . '/inc/citations.php')) require_once dirname(__DIR__) . '/inc/citations.php';
require_once dirname(__DIR__) . '/inc/operator-history.php';
require_once dirname(__DIR__) . '/inc/program-homes.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$render = function ($template, $global, $data) {
    $GLOBALS[$global] = $data;
    ob_start();
    include dirname(__DIR__) . '/templates/' . $template;
    return ob_get_clean();
};

// ---------------------------------------------------------------------------
echo "-- Names --\n";
foreach (array(
    "Newport Academy \u{2013} Acre"                      => array('Newport Academy', 'Acre'),
    'Intercept Health- Autumn House'                     => array('Intercept Health', 'Autumn House'),
    "Paradigm Treatment Centers \u{2013} Malibu \u{2013} Birdview" => array('Paradigm Treatment Centers', "Malibu \u{2013} Birdview"),
    'Grafton - 920 Frederick Avenue Group Home'          => array('Grafton', '920 Frederick Avenue Group Home'),
    'Co-Op House'                                        => array('', ''),
    'Red Rock Canyon School'                             => array('', ''),
) as $name => $want) {
    $got = kop_program_homes_split_name($name);
    $check("split '$name'", $got === $want, implode(' | ', $got));
}

// ---------------------------------------------------------------------------
echo "\n-- Suggestions --\n";
$rows = kop_program_homes_rows();
$sugg = kop_program_homes_suggest($rows);
$homes_total = array_sum(array_map(function ($s) { return count($s['homes']); }, $sugg));
printf("  %d suggested groups covering %d records\n", count($sugg), $homes_total);
$check('there are suggestions', count($sugg) > 50);
$newport = null;
foreach ($sugg as $s) if ($s['program_name'] === 'Newport Academy' && $s['state'] === 'CA') $newport = $s;
$check('Newport Academy in California is suggested with its homes', $newport && count($newport['homes']) >= 20, $newport ? count($newport['homes']) . ' homes' : 'missing');
$warned = array_filter($sugg, function ($s) { return $s['warnings']; });
printf("  %d groups carry a warning\n", count($warned));
foreach (array_slice($warned, 0, 3) as $s) printf("    %s, %s: %s\n", $s['program_name'], $s['state'], implode(' / ', $s['warnings']));
$named = kop_program_homes_suggest(array(
    array('id' => 1, 'name' => "Acme Youth \u{2013} North", 'state' => 'TX', 'city' => '', 'status' => 'Open', 'operator' => 'Acme Youth, Inc.'),
    array('id' => 2, 'name' => "Acme Youth \u{2013} South", 'state' => 'TX', 'city' => '', 'status' => 'Open', 'operator' => 'Acme Youth, Inc.'),
));
$check('a group named for its company carries a warning', $named && count($named[0]['warnings']) === 1, $named ? implode(' / ', $named[0]['warnings']) : 'none');
foreach (array_slice($sugg, 0, 8) as $s) printf("  %3d  %s, %s%s\n", count($s['homes']), $s['program_name'], $s['state'], $s['existing'] ? ' (existing record #' . $s['existing']['id'] . ')' : '');
// Groups the dash misses (kop_program_homes_prefix_groups()).
foreach (array(
    array('Boys Republic Graves Cottage STRTP', 2, array('Boys Republic', 'Graves Cottage STRTP')),
    array("Georgia Sheriffs\u{2019} Boys Ranch", 2, array("Georgia Sheriffs", 'Boys Ranch')),
    array('Three Springs of Marion', 2, array('Three Springs', 'Marion')),
    array('Home Court Advantage Inc IV', 3, array('Home Court Advantage', 'IV')),
) as $c) {
    $got = kop_program_homes_cut($c[0], $c[1]);
    $got[0] = kop_program_homes_tidy_name($got[0]);
    $check("cut '{$c[0]}'", $got === $c[2], implode(' | ', $got));
}
$more = kop_program_homes_suggest(array(
    array('id' => 1, 'name' => 'Echelon 1', 'state' => 'NC', 'city' => 'A', 'status' => 'Open', 'operator' => ''),
    array('id' => 2, 'name' => 'Echelon 3', 'state' => 'NC', 'city' => 'B', 'status' => 'Open', 'operator' => ''),
    array('id' => 3, 'name' => 'ROP ATCS Baker House', 'state' => 'CA', 'city' => 'A', 'status' => 'Open', 'operator' => ''),
    array('id' => 4, 'name' => 'ROP ATCS Joann House', 'state' => 'CA', 'city' => 'B', 'status' => 'Open', 'operator' => ''),
    array('id' => 5, 'name' => 'Dimondale', 'state' => 'CA', 'city' => 'Carson', 'status' => 'Open', 'operator' => ''),
    array('id' => 6, 'name' => 'Dimondale', 'state' => 'CA', 'city' => 'Gardena', 'status' => 'Open', 'operator' => ''),
    array('id' => 7, 'name' => 'Victor Cullen Center', 'state' => 'MD', 'city' => '', 'status' => 'Open', 'operator' => ''),
    array('id' => 8, 'name' => 'Victor Cullen Academy', 'state' => 'MD', 'city' => '', 'status' => 'Open', 'operator' => ''),
    array('id' => 9, 'name' => 'Santa Clara Juvenile Hall', 'state' => 'CA', 'city' => '', 'status' => 'Open', 'operator' => ''),
    array('id' => 10, 'name' => 'Santa Cruz Juvenile Hall', 'state' => 'CA', 'city' => '', 'status' => 'Open', 'operator' => ''),
));
$reasons = array();
foreach ($more as $s) $reasons[$s['program_name']] = $s['reason'] . ':' . implode(',', array_column($s['homes'], 'home_name'));
ksort($reasons);
$check('numbered, house-named and same-named homes are suggested; two programs and two juvenile halls are not', $reasons === array(
    'Dimondale' => 'same name:Carson,Gardena', 'Echelon' => 'numbered:1,3', 'ROP ATCS' => 'house names:Baker House,Joann House'), json_encode($reasons));
$by_reason = array();
foreach ($sugg as $s) $by_reason[$s['reason']] = ($by_reason[$s['reason']] ?? 0) + 1;
printf("  by reason: %s\n", json_encode($by_reason));
$check('every reason has a label', !array_diff(array_keys($by_reason), array_keys(kop_program_homes_reasons())));
$check('a dismissed group is not suggested', !array_filter(kop_program_homes_suggest($rows, array(), array($newport['key'] => 1)), function ($s) use ($newport) { return $s['key'] === $newport['key']; }));

// ---------------------------------------------------------------------------
echo "\n-- Grouping --\n";
$opts = array('pdo' => $pdo, 'prefix' => 'wpdl_', 'skip_memberships' => true);
$homes = array();
foreach ($newport['homes'] as $h) $homes[$h['id']] = $h['home_name'];
$before = (int) $wpdb->get_var('SELECT COUNT(*) FROM facilities_v2');
$pid = kop_program_homes_group($homes, $newport['existing']['id'] ?? 0, 'Newport Academy', $opts);
$check('grouping returns a program record', $pid > 0, "#$pid");
$created = (int) $wpdb->get_var('SELECT COUNT(*) FROM facilities_v2') === $before + 1;
$check('a new program record was made (none existed)', $created || !empty($newport['existing']));
$prow = $wpdb->get_row("SELECT name, state, status FROM facilities_v2 WHERE id = $pid", ARRAY_A);
$check('the program record is in California, named for the program', $prow && $prow['state'] === 'CA' && $prow['name'] === 'Newport Academy', json_encode($prow));
$check('the company that runs the homes runs the program', (int) $wpdb->get_var("SELECT COUNT(*) FROM wpdl_kop_operator_facilities WHERE facility_id = $pid") === 1);
$check('every home points at the program', count(kop_program_homes_homes_of($pid)) === count($homes));
$first_home = (int) array_key_first($homes);
$check('a home knows its program', (kop_program_homes_program_of($first_home)[0] ?? 0) === $pid);
$check('the group is no longer suggested', !array_filter(kop_program_homes_suggest(kop_program_homes_rows(), kop_program_homes_grouped_ids()), function ($s) use ($newport) { return $s['key'] === $newport['key']; }));

// ---------------------------------------------------------------------------
echo "\n-- Pages --\n";
kop_facility_pages_index(true);
$check('the program record has a page', kop_facility_page_url($pid) !== '', kop_facility_page_url($pid));
$pdata = kop_facility_page_data($pid);
$check('the program page lists its homes', $pdata && count($pdata['program_homes']['homes'] ?? array()) === count($homes));
printf("  program page: %d homes, %d inspection reports, %d serious findings, %d news, %d lawsuits from its homes\n",
    count($pdata['program_homes']['homes']), $pdata['program_homes']['reports'], count($pdata['program_homes']['violations']),
    count($pdata['program_homes']['news']), count($pdata['program_homes']['lawsuits']));
$html = $render('facility-page.php', 'kop_facility_page', $pdata);
file_put_contents($out_dir . '/program.html', $html);
$check('the program page prints its Homes section', strpos($html, 'kop-fp-homes-section') !== false && substr_count($html, 'class="kop-fp-records kop-fp-homes"') === 1);
$check('the program page says it is a program of homes', strpos($html, 'A program of ' . count($homes) . ' licensed homes') !== false);
if ($pdata['program_homes']['violations']) $check('findings from its homes name the home', strpos($html, 'kop-fp-at-home') !== false);
$check('its own homes are not under "Same operator"', !array_filter($pdata['siblings'], function ($s) use ($pdata) {
    foreach ($pdata['program_homes']['homes'] as $h) if ($h['url'] !== '' && $h['url'] === $s['url']) return true;
    return false;
}));

$hdata = kop_facility_page_data($first_home);
$check('a home page names its program', ($hdata['home_of']['program']['id'] ?? 0) === $pid && $hdata['home_of']['count'] === count($homes));
$html = $render('facility-page.php', 'kop_facility_page', $hdata);
file_put_contents($out_dir . '/home.html', $html);
$check('a home page prints "One of N homes of"', strpos($html, 'One of ' . count($homes) . ' homes of') !== false);
$check('a home page lists the other homes', count($hdata['home_of']['others']) === count($homes) - 1);

$op_index = kop_operator_pages_index(true);
$op_id = (int) ($op_index['names'][kop_facility_pages_name_key('Newport Healthcare')] ?? 0);
if ($op_id) {
    $odata = kop_operator_page_data($op_id);
    $tree = array_values(array_filter($odata['program_tree'], function ($f) use ($pid) { return (int) $f['id'] === $pid; }));
    $listed = count(array_filter($odata['facilities'], function ($f) use ($homes) { return isset($homes[(int) $f['id']]); }));
    $check('the company page folds every home it lists under the program', $tree && count($tree[0]['homes']) === $listed, ($tree ? count($tree[0]['homes']) : 0) . " of $listed listed homes (" . count($homes) . ' in the program)');
    $nested = 0;
    foreach ($odata['program_tree'] as $f) $nested += count($f['homes']);
    $check('no home is listed twice on the company page', count($odata['program_tree']) + $nested === count($odata['facilities']) + ($tree && !array_filter($odata['facilities'], function ($f) use ($pid) { return (int) $f['id'] === $pid; }) ? 1 : 0));
    $html = $render('operator-page.php', 'kop_operator_page', $odata);
    file_put_contents($out_dir . '/company.html', $html);
    $check('the company page prints the homes folded away', strpos($html, 'class="kop-op-homes"') !== false);
} else {
    $check('Newport Healthcare has a company page', false);
}

// ---------------------------------------------------------------------------
echo "\n-- Undo --\n";
$r = kop_program_homes_undo($pid);
$check('undo removes the record this tool made', $r === 'removed' && !(int) $wpdb->get_var("SELECT COUNT(*) FROM facilities_v2 WHERE id = $pid"), $r);
$check('undo unties every home', !kop_program_homes_homes_of($pid) && !kop_program_homes_program_of($first_home));
$check('undo leaves no company link behind', !(int) $wpdb->get_var("SELECT COUNT(*) FROM wpdl_kop_operator_facilities WHERE facility_id = $pid"));

// An existing record used as the program stays on undo.
$keep = null;
foreach ($sugg as $s) if ($s['existing']) { $keep = $s; break; }
if ($keep) {
    $h2 = array();
    foreach ($keep['homes'] as $h) $h2[$h['id']] = $h['home_name'];
    $pid2 = kop_program_homes_group($h2, $keep['existing']['id'], $keep['program_name'], $opts);
    $check('an existing record can be the program', $pid2 === $keep['existing']['id'], $keep['program_name']);
    $check('undo keeps a record it did not make', kop_program_homes_undo($pid2) === 'kept' && (int) $wpdb->get_var("SELECT COUNT(*) FROM facilities_v2 WHERE id = $pid2") === 1);
}

register_shutdown_function(function () use ($db_path) {
    try { @unlink($db_path); } catch (Throwable $e) {}
});
echo "  rendered to $out_dir\n";
echo $failures ? "\n$failures failure(s).\n" : "\nAll checks passed.\n";
exit($failures ? 1 : 0);
