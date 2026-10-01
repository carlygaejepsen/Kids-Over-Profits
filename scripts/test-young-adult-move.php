<?php
/**
 * Offline check of the first move of young adult programs out of the
 * facility data (kop_ya_maybe_migrate() in inc/young-adult-programs.php).
 *
 *   php scripts/test-young-adult-move.php [--db=tmp/prod.sqlite]
 *
 * The facility tables and wpdl_kop_woodbury_facts are copied from the
 * production mirror into an in-memory database, so the move really runs
 * (facility rows out, programs in, Woodbury items re-pointed) without
 * touching the mirror. Sync the mirror first so it has today's records.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$args = getopt('', array('db::'));
$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($mirror)) {
    fwrite(STDERR, "No mirror at $mirror (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);
$GLOBALS['kop_test_options'] = array();

function get_stylesheet_directory() { return dirname(__DIR__); }
function home_url($p = '') { return 'https://kidsoverprofits.org' . $p; }
function admin_url($p = '') { return 'https://kidsoverprofits.org/wp-admin/' . $p; }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['kop_test_options']) ? $GLOBALS['kop_test_options'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['kop_test_options'][$k] = $v; return true; }
function add_action() {}
function add_filter() {}
function do_action() {}
function apply_filters($t, $v) { return $v; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $s)), '-'); }
function remove_accents($s) { return (string) $s; }
function current_time($t = 'mysql', $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function kop_seed_pdo() { return $GLOBALS['pdo']; }
function kop_v2_with_write_lock(PDO $pdo, callable $fn) {
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Just enough of $wpdb, over the same in-memory database. */
class KOP_YA_Test_Wpdb {
    public $prefix = 'wpdl_';
    public $last_error = '';
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $pdo = $this->pdo;
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args, $pdo) {
            $v = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $v : $pdo->quote((string) $v);
        }, $sql);
    }
    public function get_results($sql, $o = ARRAY_A) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function get_col($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN); }
    public function get_var($sql) { $v = $this->pdo->query($sql)->fetchColumn(); return $v === false ? null : $v; }
    public function query($sql) { return $this->pdo->exec($sql); }
    public function update($table, array $data, array $where) {
        $set = implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($data)));
        $w = implode(' AND ', array_map(function ($c) { return "$c = ?"; }, array_keys($where)));
        $s = $this->pdo->prepare("UPDATE $table SET $set WHERE $w");
        $s->execute(array_merge(array_values($data), array_values($where)));
        return $s->rowCount();
    }
}

/* ---- A writable copy of the tables involved ----------------------------- */

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($mirror) . ' AS m');
foreach (array('facilities_v2', 'wpdl_kop_facility_locations', 'wpdl_kop_operator_facilities', 'wpdl_kop_facility_identity',
    'wpdl_kop_facility_addresses', 'wpdl_kop_woodbury_facts', 'news_facility_links', 'news_facility_candidates',
    'lawsuit_facility_links', 'facility_closure_reports') as $t) {
    $pdo->exec("CREATE TABLE $t AS SELECT * FROM m.$t");
}
$pdo->exec('DETACH DATABASE m');
// Version 3 of the Woodbury Facts table (dbDelta adds these on the site).
$pdo->exec("ALTER TABLE wpdl_kop_woodbury_facts ADD COLUMN ya INTEGER NOT NULL DEFAULT 0");
$pdo->exec("ALTER TABLE wpdl_kop_woodbury_facts ADD COLUMN ya_why TEXT");
update_option('kop_woodbury_facts_db', '3');
// SQLite form of the table kop_ya_install() makes in MySQL.
$pdo->exec("CREATE TABLE young_adult_programs (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_key TEXT NOT NULL UNIQUE,
    other_names TEXT, city TEXT NOT NULL DEFAULT '', state TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '', ages TEXT NOT NULL DEFAULT '',
    program_type TEXT NOT NULL DEFAULT '', run_by TEXT NOT NULL DEFAULT '', opened INTEGER, closed INTEGER, status TEXT NOT NULL DEFAULT 'Unknown',
    notes TEXT, links TEXT, facts TEXT, review TEXT NOT NULL DEFAULT 'approved', source TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
update_option('kop_ya_db', '1');
$GLOBALS['pdo'] = $pdo;
$GLOBALS['wpdb'] = new KOP_YA_Test_Wpdb($pdo);

require_once dirname(__DIR__) . '/inc/facility-store.php';
require_once dirname(__DIR__) . '/inc/facility-migration.php';
require_once dirname(__DIR__) . '/inc/young-adult-programs.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';

$failed = 0;
function check($ok, $what) {
    global $failed;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$ok) {
        $failed++;
    }
}
function rows(PDO $pdo, $sql, array $p = array()) {
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return (int) $s->fetchColumn();
}

/* ---- Before ----------------------------------------------------------------- */

$ids = array_keys(kop_ya_first_moves());
$in = implode(',', $ids);
$docs = array();
foreach ($pdo->query("SELECT id, json_data FROM facilities_v2 WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $docs[(int) $r['id']] = json_decode($r['json_data'], true);
}
echo "Before\n";
check(count($docs) === 7, 'the mirror has all seven records');
foreach ($docs as $fid => $d) {
    check((int) ($d['facilityDetails']['ageRange']['min'] ?? 0) >= 17, "#$fid ages start at 17 or more in the mirror");
}
$total = rows($pdo, 'SELECT COUNT(*) FROM facilities_v2');
$applied = $pdo->query("SELECT pkey, applied_fid, op FROM wpdl_kop_woodbury_facts WHERE status = 'applied' AND applied_fid IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
$waiting = rows($pdo, "SELECT COUNT(*) FROM wpdl_kop_woodbury_facts WHERE status IN ('pending','gone') AND facility_id IN ($in)");
printf("       %d Woodbury items added to them, %d waiting\n", count($applied), $waiting);

/* ---- The move --------------------------------------------------------------- */

echo "The move\n";
kop_ya_maybe_migrate();
$log = get_option('kop_ya_migration_log');
echo '       ' . implode("\n       ", $log['lines']) . "\n";
check(get_option('kop_ya_migrated') === '1', 'marked done');
check(count(preg_grep('/^Moved facility record/', $log['lines'])) === 7, 'all seven moved');
foreach ($ids as $fid) {
    $left = rows($pdo, 'SELECT COUNT(*) FROM facilities_v2 WHERE id = ?', array($fid))
        + rows($pdo, 'SELECT COUNT(*) FROM wpdl_kop_facility_locations WHERE facility_id = ?', array($fid))
        + rows($pdo, 'SELECT COUNT(*) FROM wpdl_kop_facility_identity WHERE facility_id = ?', array($fid))
        + rows($pdo, 'SELECT COUNT(*) FROM wpdl_kop_operator_facilities WHERE facility_id = ?', array($fid));
    check($left === 0, "#$fid is out of the facility tables");
}
check(rows($pdo, 'SELECT COUNT(*) FROM facilities_v2') === $total - 7, 'every other facility is untouched (' . ($total - 7) . ' left)');

echo "The programs\n";
$progs = kop_ya_all($pdo, 'approved');
check(count($progs) === 7, 'seven young adult programs, listed');
$byname = array();
foreach ($progs as $p) {
    $byname[$p['name_key']] = $p;
}
foreach ($docs as $fid => $d) {
    $p = $byname[kop_ya_key($d['identification']['name'])] ?? null;
    if (!$p) {
        check(false, "#$fid became a program");
        continue;
    }
    $ar = $d['facilityDetails']['ageRange'];
    $staff = count((array) ($d['staff']['administrator'] ?? array())) + count((array) ($d['staff']['notableStaff'] ?? array()));
    $facts = kop_ya_facts($p);
    $staff_facts = count(array_filter($facts, function ($f) { return $f['group'] === 'staff'; }));
    check($p['ages'] === $ar['min'] . '-' . $ar['max'] && $p['city'] === (string) ($d['location']['city'] ?? '') && $p['state'] === (string) ($d['location']['state'] ?? ''),
        $p['name'] . ': ages ' . $p['ages'] . ', ' . $p['city'] . ', ' . $p['state']);
    check($staff_facts === $staff, "  all $staff staff came over");
    foreach ((array) ($d['identification']['pastNames'] ?? array()) as $pn) {
        check(in_array($pn, kop_ya_lines($p['other_names']), true), "  past name \"$pn\" kept");
    }
    $cited = count(array_filter($facts, function ($f) { return !empty($f['cites']); }));
    printf("       %d facts (%d citing a Woodbury page), notes: %s\n", count($facts), $cited, $p['notes'] === '' ? '(none)' : str_replace("\n", ' / ', mb_substr($p['notes'], 0, 120)));
}
$fulshear = $byname[kop_ya_key('Fulshear Treatment to Transition – The Ranch')] ?? array('facts' => '[]', 'notes' => '');
$f = array_values(array_filter(kop_ya_facts($fulshear), function ($x) { return strpos($x['label'], 'Operator: InnerChange') === 0; }));
check($f && $f[0]['cites'][0]['label'] === 'July 2009' && (int) $f[0]['cites'][0]['page'] === 7, 'a cited note becomes a fact citing its page (Fulshear: Operator: InnerChange, July 2009 p. 7)');

echo "Woodbury Facts\n";
$moved = 0;
$keyed = 0;
foreach ($applied as $a) {
    $r = $pdo->query('SELECT * FROM wpdl_kop_woodbury_facts WHERE pkey = ' . $pdo->quote($a['pkey']))->fetch(PDO::FETCH_ASSOC);
    $done = json_decode((string) $r['applied'], true);
    $moved += ($r['status'] === 'applied' && (int) $r['applied_fid'] === 0 && ($done['filed'] ?? '') === 'young_adult') ? 1 : 0;
    if ($a['op'] === 'add_staff' && ($done['done']['key'] ?? '') === $a['pkey']) {
        $keyed++;
    }
}
$staff_items = count(array_filter($applied, function ($a) { return $a['op'] === 'add_staff'; }));
check($moved === count($applied), "every added item now points at its program ($moved of " . count($applied) . ')');
check($keyed === $staff_items, "every added person keeps its item's key, so Undo takes them off ($keyed of $staff_items)");
check(rows($pdo, "SELECT COUNT(*) FROM wpdl_kop_woodbury_facts WHERE status IN ('pending','gone') AND facility_id IN ($in)") === 0
    && rows($pdo, "SELECT COUNT(*) FROM wpdl_kop_woodbury_facts WHERE ya = 1 AND match_note LIKE 'Was on facility record%'") === $waiting,
    "the $waiting waiting items moved to the young adult tab");
$one = $pdo->query("SELECT * FROM wpdl_kop_woodbury_facts WHERE status = 'applied' AND op = 'add_staff' AND applied LIKE '%young_adult%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($one) {
    $done = json_decode($one['applied'], true);
    $before = count(kop_ya_facts(kop_ya_get($pdo, $done['id'])));
    kop_ya_remove_fact($pdo, $done['done']);
    check(count(kop_ya_facts(kop_ya_get($pdo, $done['id']))) === $before - 1, 'undoing a moved person takes them off the program');
}

echo "Once only, and the page\n";
update_option('kop_ya_migration_log', null);
kop_ya_maybe_migrate();
check(get_option('kop_ya_migration_log') === null, 'the move runs only once');
ob_start();
kop_ya_render_public();
$html = ob_get_clean();
check(substr_count($html, 'class="kop-ya-program"') === 7, 'seven programs on the page');
check(strpos($html, 'Woodbury Reports, July 2009, p. 7') !== false, 'with their Woodbury sources');

echo $failed ? "\n$failed failed\n" : "\nAll checks passed\n";
exit($failed ? 1 : 0);
