<?php
/**
 * Offline test for kop_apply_media_subfolders() (inc/admin.php) against a
 * copy of the FileBird tables in tmp/prod.sqlite.
 *
 *   php scripts/test-media-subfolders.php
 *
 * Runs the real function through a small $wpdb stand-in over SQLite, then
 * checks: every planned document left its parent for the named subfolder, no
 * document lost or gained a folder, existing subfolders were reused rather
 * than duplicated, and a second run changes nothing. The mirror is never
 * written.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
define('ABSPATH', dirname(__DIR__) . '/');
$root = dirname(__DIR__);

// --- WordPress stand-ins ------------------------------------------------------

function add_action() {}
function add_filter() {}
function apply_filters($tag, $value) { return $value; }
function get_stylesheet_directory() { return $GLOBALS['root']; }
function trailingslashit($p) { return rtrim($p, '/\\') . '/'; }

class KopTestWpdb {
    public $prefix = 'wpdl_';
    public $insert_id = 0;
    public $last_error = '';
    private $pdo;
    function __construct(PDO $pdo) { $this->pdo = $pdo; }
    function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) {
            $v = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $v : $this->pdo->quote((string) $v);
        }, $sql);
    }
    private function sql($sql) {
        if (preg_match("/^SHOW TABLES LIKE '([^']+)'/", $sql, $m)) {
            return "SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . $m[1] . "'";
        }
        if (preg_match('/^SHOW COLUMNS FROM (\w+)/', $sql, $m)) {
            return "SELECT name FROM pragma_table_info('" . $m[1] . "')";
        }
        return str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql);
    }
    function get_var($sql) { $r = $this->pdo->query($this->sql($sql))->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
    function get_col($sql) { return $this->pdo->query($this->sql($sql))->fetchAll(PDO::FETCH_COLUMN); }
    function query($sql) { return $this->pdo->exec($this->sql($sql)); }
    function insert($table, $row, $formats) {
        $cols = implode(', ', array_keys($row));
        $vals = implode(', ', array_map(function ($v) { return is_int($v) ? $v : $this->pdo->quote($v); }, array_values($row)));
        $ok = $this->pdo->exec("INSERT INTO $table ($cols) VALUES ($vals)");
        $this->insert_id = (int) $this->pdo->lastInsertId();
        return $ok === false ? false : 1;
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("ATTACH DATABASE " . $pdo->quote($root . '/tmp/prod.sqlite') . " AS m");
$pdo->exec("CREATE TABLE wpdl_fbv (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, parent INTEGER, type INTEGER, ord INTEGER, created_by INTEGER)");
$pdo->exec("INSERT INTO wpdl_fbv SELECT id, name, parent, type, ord, created_by FROM m.wpdl_fbv");
$pdo->exec("CREATE TABLE wpdl_fbv_attachment_folder (folder_id INTEGER, attachment_id INTEGER, UNIQUE(folder_id, attachment_id))");
$pdo->exec("INSERT OR IGNORE INTO wpdl_fbv_attachment_folder SELECT folder_id, attachment_id FROM m.wpdl_fbv_attachment_folder");
$GLOBALS['wpdb'] = new KopTestWpdb($pdo);

require $root . '/inc/admin.php';

// --- Checks -------------------------------------------------------------------

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if ($ok) { echo "PASS $label\n"; return; }
    $failures++;
    echo "FAIL $label" . ($detail !== '' ? "\n     $detail" : '') . "\n";
}

$spec     = json_decode(file_get_contents($root . '/seeds/media-subfolders.json'), true);
$before   = (int) $pdo->query("SELECT COUNT(*) FROM wpdl_fbv_attachment_folder")->fetchColumn();
$folders0 = (int) $pdo->query("SELECT COUNT(*) FROM wpdl_fbv")->fetchColumn();
$existing = array();
foreach ($spec['folders'] as $e) {
    $id = $pdo->query("SELECT id FROM wpdl_fbv WHERE parent = " . (int) $e['parent'] . " AND LOWER(name) = " . $pdo->quote(strtolower($e['name'])))->fetchColumn();
    if ($id) $existing[] = $e['parent'] . '/' . $e['name'];
}

$done = kop_apply_media_subfolders();
$created = count(array_filter($done, function ($d) { return strpos($d, 'folder:') === 0; }));
echo count($done) . " actions, $created folders created\n";

check('filings are moved, not added or lost', (int) $pdo->query("SELECT COUNT(*) FROM wpdl_fbv_attachment_folder")->fetchColumn() === $before);
check('one new folder per planned subfolder that did not exist', $created === count($spec['folders']) - count($existing),
    "created $created, planned " . count($spec['folders']) . ', existing ' . count($existing));
check('the folder table grew by exactly that', (int) $pdo->query("SELECT COUNT(*) FROM wpdl_fbv")->fetchColumn() === $folders0 + $created);

$misplaced = array();
foreach ($spec['folders'] as $e) {
    $child = $pdo->query("SELECT id FROM wpdl_fbv WHERE parent = " . (int) $e['parent'] . " AND LOWER(name) = " . $pdo->quote(strtolower($e['name'])))->fetchAll(PDO::FETCH_COLUMN);
    if (count($child) !== 1) { $misplaced[] = $e['name'] . ' (' . count($child) . ' folders)'; continue; }
    foreach ($e['attachments'] as $aid) {
        $in_parent = (int) $pdo->query("SELECT COUNT(*) FROM wpdl_fbv_attachment_folder WHERE folder_id = " . (int) $e['parent'] . " AND attachment_id = " . (int) $aid)->fetchColumn();
        $in_child  = (int) $pdo->query("SELECT COUNT(*) FROM wpdl_fbv_attachment_folder WHERE folder_id = " . (int) $child[0] . " AND attachment_id = " . (int) $aid)->fetchColumn();
        if ($in_parent || !$in_child) $misplaced[] = $e['name'] . ':' . $aid;
    }
}
check('every planned document sits in its subfolder and not in the parent', !$misplaced, implode(', ', array_slice($misplaced, 0, 10)));

$again = kop_apply_media_subfolders();
check('a second run does nothing', $again === array(), implode(', ', $again));

echo $failures ? "\nmedia subfolders: $failures FAILURES\n" : "\nmedia subfolders: PASS\n";
exit($failures ? 1 : 0);
