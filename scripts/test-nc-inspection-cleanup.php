<?php
/**
 * api/lib-nc-inspection-cleanup.php on an in-memory copy of the NC inspection
 * rows in tmp/prod.sqlite: dry run changes nothing, apply removes the adult-only
 * rows, leaves one row per facility and licence, keeps every reviewed finding,
 * and a second run finds nothing left to do.
 *
 *   php -d extension=pdo_sqlite scripts/test-nc-inspection-cleanup.php
 */

require_once dirname(__DIR__) . '/api/lib-nc-inspection-cleanup.php';

$root = dirname(__DIR__);
$plan = json_decode(file_get_contents("$root/seeds/nc-inspection-cleanup.json"), true);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("ATTACH '" . str_replace("'", "''", "$root/tmp/prod.sqlite") . "' AS s");
$nc_reports = "SELECT r.id FROM s.inspection_reports r JOIN s.inspection_facilities f ON f.id = r.facility_id WHERE f.state = 'NC'";
$pdo->exec("CREATE TABLE inspection_facilities AS SELECT * FROM s.inspection_facilities WHERE state = 'NC'");
$pdo->exec("CREATE TABLE inspection_reports AS SELECT * FROM s.inspection_reports WHERE id IN ($nc_reports)");
$pdo->exec("CREATE TABLE inspection_highlights AS SELECT * FROM s.inspection_highlights WHERE report_id IN ($nc_reports)");
$pdo->exec("CREATE TABLE inspection_highlight_scans AS SELECT * FROM s.inspection_highlight_scans WHERE report_id IN ($nc_reports)");
$pdo->exec("CREATE TABLE inspection_report_counts AS SELECT * FROM s.inspection_report_counts WHERE report_id IN ($nc_reports)");
$pdo->exec('DETACH s');

$fail = 0;
function check($ok, $what) { global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n"; if (!$ok) $fail++; }
function one(PDO $pdo, $sql) { return $pdo->query($sql)->fetchColumn(); }

$before = array(
    'facilities' => (int) one($pdo, 'SELECT COUNT(*) FROM inspection_facilities'),
    'reviewed'   => $pdo->query("SELECT h.status, r.report_id, h.finding_key FROM inspection_highlights h JOIN inspection_reports r ON r.id = h.report_id WHERE h.status <> 'pending'")->fetchAll(PDO::FETCH_ASSOC),
);
$deleted_ids = array_map(function ($d) { return (int) $d['id']; }, $plan['delete']);

$dry = kop_ncclean_run($pdo, $plan, false);
check(!$dry['skipped'], 'dry run: every plan row matches the mirror (' . implode('; ', $dry['skipped']) . ')');
check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_facilities') === $before['facilities'], 'dry run writes nothing');

$res = kop_ncclean_run($pdo, $plan, true);
print_r($res['stats']);
$after = (int) one($pdo, 'SELECT COUNT(*) FROM inspection_facilities');
check($after === $before['facilities'] - count($plan['delete']) - count($plan['merge']), "rows: {$before['facilities']} -> $after");
check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_facilities WHERE id IN (' . implode(',', $deleted_ids) . ')') === 0, 'adult-only rows gone');
check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_reports WHERE facility_id NOT IN (SELECT id FROM inspection_facilities)') === 0, 'no orphan reports');
check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_highlights WHERE report_id NOT IN (SELECT id FROM inspection_reports)') === 0, 'no orphan findings');
check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_highlights h JOIN inspection_reports r ON r.id = h.report_id WHERE h.facility_id <> r.facility_id') === 0, 'findings follow their report');
check((int) one($pdo, 'SELECT COUNT(*) FROM (SELECT facility_name, program_name FROM inspection_facilities GROUP BY 1, 2 HAVING COUNT(*) > 1)') === 0, 'one row per name and licence');
check((int) one($pdo, "SELECT COUNT(*) FROM inspection_facilities WHERE facility_name = 'Alexander Youth Network - Charlotte Day Treatment'") === 1, 'Charlotte Day Treatment once');
check((int) one($pdo, 'SELECT COUNT(*) FROM (SELECT facility_id, report_id FROM inspection_reports GROUP BY 1, 2 HAVING COUNT(*) > 1)') === 0, 'no report twice on one row');

// Each reviewed finding (report + finding) on a row that stays keeps a review;
// approved stays approved, even where a dropped copy had rejected it.
$want = array();
foreach ($before['reviewed'] as $h) {
    $k = $h['report_id'] . '|' . $h['finding_key'];
    if (!isset($want[$k]) || $h['status'] === 'approved') $want[$k] = $h['status'];
}
$now = array();
foreach ($pdo->query("SELECT r.report_id, h.finding_key, h.status FROM inspection_highlights h JOIN inspection_reports r ON r.id = h.report_id") as $h) {
    $now[$h['report_id'] . '|' . $h['finding_key']] = $h['status'];
}
$gone = $changed_status = 0;
foreach ($want as $k => $status) {
    if (!isset($now[$k])) { $gone++; continue; }
    if ($now[$k] !== $status) $changed_status++;
}
check($changed_status === 0, 'reviewed findings keep their review');
check($gone === 2, "only the reviews on adult-only rows go ($gone: both rejected)");
$approved_before = count(array_filter($want, function ($s) { return $s === 'approved'; }));
check(count(array_filter($now, function ($s) { return $s === 'approved'; })) === $approved_before, "all $approved_before approved findings kept");

$again = kop_ncclean_run($pdo, $plan, false);
check($again['stats']['deleted'] + $again['stats']['merged'] + $again['stats']['relabelled'] === 0, 'second run: nothing left to do');

list($links, $changed) = kop_ncclean_remap_links(array('links' => array(1 => array($deleted_ids[0], 6582, (int) $plan['merge'][0]['drop'])), 'rejected' => array()), $res['removed'], $res['merged']);
check($changed === 2 && $links['links'][1] === array(6582, (int) $plan['merge'][0]['keep']) || $links['links'][1] === array(6582), 'inspection links follow merges, drop deleted rows');

echo $fail ? "$fail failed\n" : "all passed\n";
exit($fail ? 1 : 0);
