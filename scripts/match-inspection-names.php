<?php
/**
 * Which of a state's facility records a scraper's facility names will reach.
 *
 * The generated /facility/<slug>/ pages pick up inspection facilities by name
 * key within the state (kop_facility_pages_inspections()). Before a new state
 * is posted, run its scraper's --out file through the same matching against
 * facilities_v2 in tmp/prod.sqlite:
 *
 *   php scripts/match-inspection-names.php --state=MI --file=<scraper --out json>
 *
 * Prints the matched pairs, the scraped names no record reaches, the state's
 * records no scraped name reaches, and for each unmatched scraped name the
 * closest record names (word overlap), which are the near misses to review.
 * Nothing is renamed or written.
 */

$opts = getopt('', array('state:', 'file:', 'db::'));
$state = strtoupper((string) ($opts['state'] ?? ''));
$file = (string) ($opts['file'] ?? '');
if ($state === '' || $file === '' || !is_readable($file)) {
    fwrite(STDERR, "Usage: php scripts/match-inspection-names.php --state=XX --file=<scraper --out json> [--db=tmp/prod.sqlite]\n");
    exit(2);
}
$db_path = (string) ($opts['db'] ?? dirname(__DIR__) . '/tmp/prod.sqlite');
if (!is_readable($db_path)) {
    fwrite(STDERR, "No SQLite mirror at $db_path (python scripts/sync-prod-sqlite.py)\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';

$data = json_decode(file_get_contents($file), true);
$scraped = array();
foreach (($data['facilities'] ?? array()) as $f) {
    $name = trim((string) ($f['facility_info']['facility_name'] ?? ''));
    if ($name !== '') $scraped[$name] = kop_facility_pages_name_key($name);
}

$pdo = new PDO('sqlite:' . $db_path);
$rows = $pdo->prepare('SELECT id, unique_name, name, json_data, status FROM facilities_v2 WHERE state = ?');
$rows->execute(array($state));
$records = array();
foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $doc = json_decode((string) $r['json_data'], true) ?: array();
    $records[] = array(
        'id' => (int) $r['id'],
        'name' => (string) ($r['name'] ?: $r['unique_name']),
        'status' => (string) $r['status'],
        'keys' => kop_facility_pages_doc_name_keys($doc, (string) $r['unique_name']),
    );
}

$matched = array();
$record_hit = array();
foreach ($scraped as $name => $rk) {
    foreach ($records as $i => $rec) {
        foreach ($rec['keys'] as $fk) {
            if ($fk === $rk || kop_facility_pages_key_matches($fk, $rk)) {
                $matched[$name][] = $rec;
                $record_hit[$i] = true;
                break;
            }
        }
    }
}

$words = function ($key) {
    $stop = array('the', 'of', 'and', 'for', 'inc', 'llc', 'center', 'home', 'homes', 'services', 'youth', 'county', 'mi', 'michigan');
    return array_values(array_diff(array_unique(preg_split('/\s+/', (string) $key)), $stop, array('')));
};

printf("%s: %d scraped facilities, %d facilities_v2 records\n", $state, count($scraped), count($records));
printf("scraped names that reach a record: %d\nrecords reached: %d\n\n", count($matched), count($record_hit));

echo "== Matched ==\n";
foreach ($matched as $name => $recs) {
    echo "  $name  ->  " . implode('; ', array_map(function ($r) { return $r['name'] . ' [#' . $r['id'] . ']'; }, $recs)) . "\n";
}

echo "\n== Scraped names no record reaches (closest records) ==\n";
foreach ($scraped as $name => $rk) {
    if (isset($matched[$name])) continue;
    $mine = $words($rk);
    $scored = array();
    foreach ($records as $rec) {
        $best = 0;
        foreach ($rec['keys'] as $fk) $best = max($best, count(array_intersect($mine, $words($fk))));
        if ($best > 0) $scored[] = array($best, $rec);
    }
    usort($scored, function ($a, $b) { return $b[0] <=> $a[0]; });
    $near = array_map(function ($s) { return $s[1]['name'] . ' [#' . $s[1]['id'] . ']'; }, array_slice($scored, 0, 3));
    echo "  $name" . ($near ? '  ~  ' . implode('; ', $near) : '') . "\n";
}

echo "\n== Records no scraped name reaches ==\n";
foreach ($records as $i => $rec) {
    if (!isset($record_hit[$i])) echo '  ' . $rec['name'] . ' [#' . $rec['id'] . '] ' . $rec['status'] . "\n";
}
