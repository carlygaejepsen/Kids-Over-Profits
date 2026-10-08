<?php
/**
 * Offline checks for KOP Tools > Merge Duplicates (inc/facility-merge.php,
 * inc/facility-merge-match.php) against the production mirror.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-facility-merge.php [--db=tmp/prod.sqlite] [--list]
 *
 * Every table a merge touches is copied, with its keys, into an in-memory
 * database, so the mirror is never changed. For a sample of real pairs it
 * merges, checks that nothing points at the dropped record any more, that the
 * kept record holds both records' facts and documents, then undoes the merge
 * and checks every table is back exactly as it was. --list prints every pair
 * the screen would offer.
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
define('HOUR_IN_SECONDS', 3600);
// Just enough WordPress for the screen's data (options and transients in memory).
$GLOBALS['kop_test_options'] = array();
function add_action() {}
function get_option($k, $d = false) { return $GLOBALS['kop_test_options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['kop_test_options'][$k] = $v; return true; }
function get_transient($k) { return false; }
function set_transient() { return true; }
function delete_transient() { return true; }
function kop_seed_pdo() { return $GLOBALS['pdo']; }
$GLOBALS['wpdb'] = (object) array('prefix' => 'wpdl_');
require dirname(__DIR__) . '/inc/facility-store.php';
require dirname(__DIR__) . '/inc/facility-merge.php';

$prefix = 'wpdl_';
$pdo = $GLOBALS["pdo"] = new PDO("sqlite::memory:");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("ATTACH DATABASE " . $pdo->quote($db_path) . " AS src");

$tables = array('facilities_v2', 'news_submissions', 'lawsuits', 'suggested_edits', 'wiki_submissions');
foreach (kop_fmerge_ref_tables($prefix) as $r) $tables[] = $r['t'];
foreach (kop_fmerge_json_tables($prefix) as $r) $tables[] = $r['t'];
$tables = array_merge($tables, array($prefix . 'kop_facility_identity', $prefix . 'fbv', $prefix . 'fbv_attachment_folder',
    $prefix . 'kop_media_folder_tags', $prefix . 'kop_folder_links', $prefix . 'options', $prefix . 'kop_operators'));
$copied = array();
foreach (array_unique($tables) as $t) {
    $sql = $pdo->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn();
    if (!$sql) {
        echo "  (no $t in the mirror)\n";
        continue;
    }
    $pdo->exec($sql);
    $pdo->exec("INSERT INTO main.`$t` SELECT * FROM src.`$t`");
    $copied[] = $t;
}
$pdo->exec('DETACH DATABASE src');

$fails = 0;
function check($ok, $what) {
    global $fails;
    if (!$ok) $fails++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
}

// ---- Matching --------------------------------------------------------------
$ops = array();
foreach ($pdo->query("SELECT facility_id, operator_id FROM {$prefix}kop_operator_facilities")->fetchAll(PDO::FETCH_NUM) as $o) $ops[(int) $o[0]][] = (int) $o[1];
$rows = $pdo->query('SELECT id, unique_name, json_data FROM facilities_v2')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$r) $r['ops'] = $ops[(int) $r['id']] ?? array();
unset($r);
$t0 = microtime(true);
$pairs = kop_fmerge_find_pairs($rows);
printf("Matching: %d pairs from %d records in %.1fs\n", count($pairs), count($rows), microtime(true) - $t0);
$names = array();
foreach ($rows as $r) {
    $d = json_decode($r['json_data'], true);
    $names[(int) $r['id']] = (string) ($d['identification']['name'] ?? '');
}
$has = function ($a, $b) use ($pairs) {
    foreach ($pairs as $p) if (($p['a'] === $a && $p['b'] === $b) || ($p['a'] === $b && $p['b'] === $a)) return true;
    return false;
};
$find = function ($name) use ($names) { return array_keys($names, $name, true); };
// Known cases from the 2026-10-02 mirror; skipped when a record is gone.
foreach (array(
    array('Rosewood Youth Academy', 'CSI – Rosewood Youth Academy', true),
    array('Highlands Youth Academy', 'Highland Youth Academy', true),
    array('Piney Ridge Treatment Center', 'Piney Ridge Center', false), // Fayetteville, AR and Waynesville, MO: two programs
    array('Copper Canyon Academy', 'Sedona Sky Academy', false),      // a renamed program
    array('Forward In Life', 'Forward In Life II', false),              // two homes
    array('RMBHS – Opal House', 'RMBHS – Plata House', false),          // sibling houses
    array('Three Springs of Osceola', 'Sequel TSI Kissimmee', false),   // name era under another company
) as $case) {
    list($x, $y, $want) = $case;
    $a = $find($x);
    $b = $find($y);
    if (!$a || !$b) { echo "  skip '$x' / '$y' (not in this mirror)\n"; continue; }
    $found = false;
    foreach ($a as $i) foreach ($b as $j) if ($has($i, $j)) $found = true;
    check($found === $want, ($want ? 'offers ' : 'never offers ') . "$x / $y");
}
if (isset($args['list'])) {
    foreach ($pairs as $p) printf("  %-6s %-60s #%d %s  <>  #%d %s\n", $p['reason']['level'], $p['reason']['label'], $p['a'], $names[$p['a']], $p['b'], $names[$p['b']]);
}

// ---- Merging ---------------------------------------------------------------
function snapshot(PDO $pdo, array $tables) {
    $out = array();
    foreach ($tables as $t) {
        $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
        $lines = array();
        foreach ($rows as $r) $lines[] = json_encode($r);
        sort($lines);
        $out[$t] = md5(implode("\n", $lines)) . ' (' . count($rows) . ')';
    }
    return $out;
}
$folders = kop_fmerge_folder_rows($pdo, $prefix);
$folder_of = function ($id) use ($pdo, $folders) {
    $d = json_decode((string) $pdo->query('SELECT json_data FROM facilities_v2 WHERE id = ' . (int) $id)->fetchColumn(), true);
    if (!empty($d['documentFolderId'])) return (int) $d['documentFolderId'];
    $name = mb_strtolower(trim((string) ($d['identification']['name'] ?? '')));
    $best = 0;
    $most = 0;
    foreach ($folders as $f) {
        if (mb_strtolower(trim($f['name'])) !== $name) continue;
        $n = (int) $pdo->query("SELECT COUNT(*) FROM wpdl_fbv_attachment_folder WHERE folder_id = {$f['id']}")->fetchColumn();
        if ($n > $most) { $most = $n; $best = $f['id']; }
    }
    return $best;
};
$attachments = function (array $group) use ($pdo, $prefix, $folders) {
    $children = array();
    foreach ($folders as $f) $children[$f['parent']][] = $f['id'];
    $ids = array();
    $q = $group;
    while ($q) { $i = array_shift($q); if (isset($ids[$i])) continue; $ids[$i] = 1; foreach ($children[$i] ?? array() as $c) $q[] = $c; }
    $in = implode(',', array_keys($ids)) ?: '0';
    $a = $pdo->query("SELECT attachment_id FROM {$prefix}fbv_attachment_folder WHERE folder_id IN ($in) UNION SELECT attachment_id FROM {$prefix}kop_media_folder_tags WHERE folder_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $a);
};

// Pairs with something to move first: links, documents on both sides.
$score = function ($p) use ($pdo, $folder_of) {
    $s = 0;
    foreach (array($p['a'], $p['b']) as $id) {
        $s += (int) $pdo->query("SELECT COUNT(*) FROM news_facility_links WHERE facility_id = $id")->fetchColumn();
        if ($folder_of($id)) $s += 5;
    }
    return $s;
};
$sample = array_filter($pairs, function ($p) { return $p['reason']['code'] !== 'address'; });
usort($sample, function ($x, $y) use ($score) { return $score($y) <=> $score($x); });
$sample = array_slice($sample, 0, 4);
// And pairs whose libraries are different folders, so files really move.
$separate = 0;
foreach ($pairs as $p) {
    if ($separate >= 3) break;
    $fa = $folder_of($p['a']);
    $fb = $folder_of($p['b']);
    if (!$fa || !$fb || array_intersect(kop_fmerge_folder_group($pdo, $prefix, $fa, $folders), kop_fmerge_folder_group($pdo, $prefix, $fb, $folders))) continue;
    $sample[] = $p;
    $separate++;
}
echo "  ($separate pairs with two separate libraries)\n";

$before = snapshot($pdo, $copied);
foreach ($sample as $p) {
    $keep = $p['a'];
    $drop = $p['b'];
    echo "\nMerge #$drop {$names[$drop]} into #$keep {$names[$keep]}\n";
    $fk = $folder_of($keep);
    $fd = $folder_of($drop);
    $docs_before = array_unique(array_merge(
        $fk ? $attachments(kop_fmerge_folder_group($pdo, $prefix, $fk, $folders)) : array(),
        $fd ? $attachments(kop_fmerge_folder_group($pdo, $prefix, $fd, $folders)) : array()
    ));
    $keep_doc = json_decode((string) $pdo->query("SELECT json_data FROM facilities_v2 WHERE id = $keep")->fetchColumn(), true);
    $drop_doc = json_decode((string) $pdo->query("SELECT json_data FROM facilities_v2 WHERE id = $drop")->fetchColumn(), true);
    $news = $pdo->query("SELECT COUNT(DISTINCT news_id) FROM news_facility_links WHERE facility_id IN ($keep, $drop)")->fetchColumn();

    $pdo->beginTransaction();
    $entry = kop_fmerge_execute($pdo, $prefix, $keep, $drop, array('folder_keep' => $fk, 'folder_drop' => $fd));
    $pdo->commit();
    echo '  moved: ' . json_encode($entry['report']) . "\n";

    check((int) $pdo->query("SELECT COUNT(*) FROM facilities_v2 WHERE id = $drop")->fetchColumn() === 0, 'the dropped record is gone');
    $left = array();
    foreach (kop_fmerge_ref_tables($prefix) as $ref) {
        if (!in_array($ref['t'], $copied, true) || $ref['k'] !== 'id') continue;
        $w = !empty($ref['where']) ? ' AND ' . $ref['where'] : '';
        $n = (int) $pdo->query("SELECT COUNT(*) FROM `{$ref['t']}` WHERE `{$ref['c']}` = '$drop'$w")->fetchColumn();
        if ($n) $left[] = "{$ref['t']}.{$ref['c']} ($n)";
    }
    $n = in_array($prefix . "kop_fornits_items", $copied, true) ? (int) $pdo->query("SELECT COUNT(*) FROM {$prefix}kop_fornits_items WHERE facility_id = $drop")->fetchColumn() : 0;
    if ($n) $left[] = "fornits_items ($n)";
    foreach (array('news_submissions', 'lawsuits') as $t) {
        foreach ($pdo->query("SELECT facilities_mentioned FROM $t WHERE facilities_mentioned LIKE '%$drop%'")->fetchAll(PDO::FETCH_COLUMN) as $j) {
            if (preg_match('/"facility_id":"?' . $drop . '\b/', (string) $j)) $left[] = "$t.facilities_mentioned";
        }
    }
    check(!$left, 'nothing points at #' . $drop . ($left ? ': ' . implode(', ', $left) : ''));
    check((int) $pdo->query("SELECT COUNT(DISTINCT news_id) FROM news_facility_links WHERE facility_id = $keep")->fetchColumn() === (int) $news, "the kept record has all $news articles");

    $merged = json_decode((string) $pdo->query("SELECT json_data FROM facilities_v2 WHERE id = $keep")->fetchColumn(), true);
    $names_now = array_map('kop_facility_name_key', array_merge(array($merged['identification']['name']), (array) ($merged['identification']['otherNames'] ?? array())));
    check(in_array(kop_facility_name_key($drop_doc['identification']['name']), $names_now, true), 'the dropped name is one of its names');
    $lost = array();
    foreach (array('staff', 'credentials', 'notes', 'resources') as $k) {
        $was = json_encode($drop_doc[$k] ?? null);
        if ($was === 'null' || $was === '[]' || $was === '{}') continue;
        foreach ((array) $drop_doc[$k] as $kk => $v) {
            if (is_array($v) && $v && !isset($merged[$k][$kk])) $lost[] = "$k.$kk";
        }
    }
    check(!$lost, 'the kept document holds the dropped one\'s sections' . ($lost ? ': lost ' . implode(', ', $lost) : ''));
    $folders = kop_fmerge_folder_rows($pdo, $prefix);
    $fnow = (int) ($merged['documentFolderId'] ?? 0);
    $docs_after = $fnow ? $attachments(kop_fmerge_folder_group($pdo, $prefix, $fnow, $folders)) : array();
    $missing = array_diff($docs_before, $docs_after);
    check(!$missing, count($docs_before) . ' documents, all in the kept library (' . count($docs_after) . ')' . ($missing ? '; missing ' . implode(',', array_slice($missing, 0, 5)) : ''));

    $pdo->beginTransaction();
    kop_fmerge_undo($pdo, $prefix, json_decode(json_encode($entry), true));
    $pdo->commit();
    $folders = kop_fmerge_folder_rows($pdo, $prefix);
    $after = snapshot($pdo, $copied);
    $diff = array();
    foreach ($before as $t => $h) if ($after[$t] !== $h) $diff[] = "$t {$h} -> {$after[$t]}";
    check(!$diff, 'Undo puts every table back' . ($diff ? ":\n         " . implode("\n         ", $diff) : ''));
    if ($diff) $before = $after;
}

// ---- The screen's data -----------------------------------------------------
echo "\nScreen\n";
$data = kop_fmerge_screen_data(true);
$tabs = array();
foreach ($data['pairs'] as $p) $tabs[$p['tab']] = ($tabs[$p['tab']] ?? 0) + 1;
echo '  tabs: ' . json_encode($tabs) . "\n";
check(count($data['pairs']) === count($pairs), count($data['pairs']) . ' cards, one per pair');
$with_company = 0;
foreach ($data['pairs'] as $p) if ($p['a']['companies'] || $p['b']['companies']) $with_company++;
check($with_company > 0, "$with_company cards name a company");
$bad = array_filter($data['pairs'], function ($p) { return $p['keep'] !== $p['a']['id'] && $p['keep'] !== $p['b']['id']; });
check(!$bad, 'every card suggests one of its two records to keep');
check(json_encode($data) !== false, 'the data encodes as JSON (' . round(strlen(json_encode($data)) / 1024) . ' KB)');
foreach (array_slice($data['pairs'], 0, 4) as $p) {
    printf("  %s: keep #%d | %s (%s) <> %s (%s)\n", $p['reason'], $p['keep'], $p['a']['name'], implode('; ', $p['a']['companies']), $p['b']['name'], implode('; ', $p['b']['companies']));
}

echo $fails ? "\n$fails FAILED\n" : "\nAll passed.\n";
exit($fails ? 1 : 0);
