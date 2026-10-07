<?php
/**
 * Writes one JSON line per report of a state in tmp/prod.sqlite: the row as
 * api/inspections-read.php sends it (full text plus the lite fields) and the
 * PHP verdict (api/lib-inspection-verdicts.php). Read by
 * scripts/test-inspection-verdicts.js, which runs the state's real adapter on
 * the same row and compares.
 *
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/dump-inspection-verdicts.php <out.jsonl> --state=PA [--db=tmp/prod.sqlite] [--ids=1,2] [--limit=N]
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");

require dirname(__DIR__) . '/api/lib-inspection-text-signals.php';
require dirname(__DIR__) . '/api/lib-inspection-verdicts.php';

$out = $argv[1] ?? '';
$db = dirname(__DIR__) . '/tmp/prod.sqlite';
$state = '';
$ids = array();
$limit = 0;
foreach ($argv as $a) {
    if (strpos($a, '--db=') === 0) $db = substr($a, 5);
    if (strpos($a, '--state=') === 0) $state = strtoupper(substr($a, 8));
    if (strpos($a, '--ids=') === 0) $ids = array_filter(array_map('intval', explode(',', substr($a, 6))));
    if (strpos($a, '--limit=') === 0) $limit = (int) substr($a, 8);
}
if ($out === '' || $state === '' || !is_file($db)) {
    fwrite(STDERR, "Usage: dump-inspection-verdicts.php <out.jsonl> --state=XX [--db=tmp/prod.sqlite] (no mirror at $db?)\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = "SELECT r.*, f.facility_name, f.full_address, f.phone, f.program_category, f.program_name,
               f.executive_director, f.bed_capacity, f.license_exp_date, f.relicense_visit_date, f.action
        FROM inspection_reports r JOIN inspection_facilities f ON f.id = r.facility_id
        WHERE f.state = ?" . ($ids ? ' AND r.id IN (' . implode(',', $ids) . ')' : '') . ' ORDER BY r.id'
        . ($limit > 0 ? ' LIMIT ' . $limit : '');
$stmt = $pdo->prepare($sql);
$stmt->execute(array($state));
$fh = fopen($out, 'wb');
$n = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $categories = json_decode((string) $row['categories_json'], true);
    if (!is_array($categories)) $categories = array();
    $raw = (string) $row['raw_content'];
    $report = array(
        'report_id'      => $row['report_id'],
        'report_date'    => $row['report_date'],
        'report_url'     => $row['report_url'],
        'raw_content'    => $raw,
        'content_length' => (int) $row['content_length'],
        'is_structured'  => (bool) $row['is_structured'],
        'summary'        => $row['summary'],
        'categories'     => $categories,
        'row_id'         => (int) $row['id'],
        'has_text'       => kop_its_trim($raw) !== '',
        'text_signals'   => in_array($state, kop_its_states(), true) ? kop_inspection_text_signals($state, $raw) : null,
    );
    $info = array();
    foreach (array('facility_name', 'full_address', 'phone', 'program_category', 'program_name', 'executive_director',
                   'bed_capacity', 'license_exp_date', 'relicense_visit_date', 'action') as $k) {
        $info[$k] = $row[$k];
    }
    $php = kop_inspection_verdict($state, $report);
    fwrite($fh, json_encode(array(
        'id'            => (int) $row['id'],
        'state'         => $state,
        'facility_info' => $info,
        'report'        => $report,
        'php'           => $php,
        'needs_text'    => kop_inspection_verdict_needs_text($state, $categories),
    ), JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE) . "\n");
    $n++;
}
fclose($fh);
fwrite(STDERR, "$state: $n reports dumped\n");
