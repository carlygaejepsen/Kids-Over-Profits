<?php
/**
 * Offline check of the inspection rankings (inc/inspection-rollup.php)
 * against the SQLite mirror of production (tmp/prod.sqlite).
 *
 *   - the state-verdict rules on hand-made reports, state by state;
 *   - every report in the mirror counted into an in-memory table (the mirror
 *     is attached read-only; nothing is written to it);
 *   - the rollup: approved findings all land, a company counts a site once,
 *     and a record takes the same inspection rows its facility page lists;
 *   - --list prints the worst companies and facilities for a look.
 *
 * Usage (Local's bundled PHP):
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/test-inspection-rollup.php [--db=tmp/prod.sqlite] [--list]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'list'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$list = isset($args['list']);
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/inspection-rollup.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "\n     " . $detail) . "\n";
    if (!$ok) $failures++;
};

// ---------------------------------------------------------------------------
// Rules, on hand-made reports
// ---------------------------------------------------------------------------

$cases = array(
    array('TX high risk, re-cited', 'TX', array('Standard Risk Level' => 'High', 'Correction Evaluation Result' => 'Re-cited'), '',
        array('cited' => 1, 'citations' => 1, 'high_risk' => 1, 'repeat' => 1)),
    array('TX "Medium High" is not high', 'TX', array('Standard Risk Level' => 'Medium High', 'Correction Evaluation Result' => 'Compliance met'), '',
        array('cited' => 1, 'citations' => 1)),
    array('CA substantiated complaint', 'CA', array('report_type' => 'Complaint Investigation', 'complaint_status' => 'SUBSTANTIATED'), '',
        array('complaints' => 1, 'substantiated' => 1, 'cited' => 1)),
    array('CA unsubstantiated complaint', 'CA', array('report_type' => 'Complaint Investigation', 'complaint_status' => 'unsubstantiated'), '',
        array('complaints' => 1)),
    array('CA evaluation with two deficiencies', 'CA', array('report_type' => 'Facility Evaluation', 'deficiencies' => array(array('section_cited' => '87061'), array('section_cited' => '80065'))), '',
        array('citations' => 2, 'cited' => 1)),
    array('CA clean evaluation', 'CA', array('report_type' => 'Facility Evaluation'), '', array()),
    array('MI special investigation, 2 violations', 'MI', array('doc_type' => 'special_investigation', 'violations_established' => '2'), '',
        array('citations' => 2, 'complaints' => 1, 'substantiated' => 1, 'cited' => 1)),
    array('MI special investigation, none established', 'MI', array('doc_type' => 'special_investigation', 'violations_established' => 0), '',
        array('complaints' => 1)),
    array('MI renewal with a violation', 'MI', array('doc_type' => 'renewal', 'violations_established' => 1), '',
        array('citations' => 1, 'cited' => 1)),
    array('OK substantiated complaint, one item of two upheld', 'OK', array('kind' => 'complaint', 'finding' => 'Substantiated',
        'items' => array(array('finding' => 'Substantiated'), array('finding' => 'Unsubstantiated'))), '',
        array('citations' => 1, 'complaints' => 1, 'substantiated' => 1, 'cited' => 1)),
    array('OK visit, two items', 'OK', array('kind' => 'visit', 'items' => array(array('nrs' => true), array('nrs' => false))), '',
        array('citations' => 2, 'cited' => 1)),
    array('OR substantiated abuse report', 'OR', array('kind' => 'complaint', 'finding' => 'Substantiated', 'abuse_types' => array('Neglect')), '',
        array('complaints' => 1, 'substantiated' => 1)),
    array('OR site visit with checklist findings', 'OR', array('report_type' => 'Unannounced', 'finding_count' => 4, 'findings' => array(array('rule' => '413-215-0076'))), '',
        array()),
    array('UT findings count', 'UT', array('Findings Count' => '3'), '', array('citations' => 3, 'cited' => 1)),
    array('UT clean', 'UT', array('Findings Count' => '0'), '', array()),
    array('AZ complaint, one repeat of two', 'AZ', array('inspection_type' => 'Complaint;Compliance (Annual)', 'deficiencies' => array(
        array('evidence' => 'failed to ensure', 'findings' => 'This is a repeat deficiency from the compliance inspection.'),
        array('evidence' => 'failed to ensure', 'findings' => 'Record review.'))), '',
        array('citations' => 2, 'repeat' => 1, 'complaints' => 1, 'cited' => 1)),
    array('FL AHCA complaint, deficiency "None"', 'FL', array('source' => 'AHCA', 'report_type' => 'Complaint', 'deficiencies' => array(array('deficiency' => 'None'))), '',
        array('complaints' => 1)),
    array('FL AHCA, two deficiencies', 'FL', array('source' => 'AHCA', 'report_type' => 'Standard', 'deficiencies' => array(array('deficiency' => 'C0027'), array('deficiency' => 'C0038'))), '',
        array('citations' => 2, 'cited' => 1)),
    array('FL DJJ QI, failed + limited of three', 'FL', array('source' => 'DJJ', 'report_type' => 'QI Residential', 'findings' => array(
        array('rating' => 'Failed Compliance'), array('rating' => 'Limited Compliance'), array('rating' => 'Satisfactory Compliance'))), '',
        array('citations' => 2, 'cited' => 1)),
    array('FL DJJ PREA is not counted', 'FL', array('source' => 'DJJ', 'report_type' => 'PREA', 'findings' => array(array('rating' => 'Failed Compliance'))), '',
        array()),
    array('NC complaint statement of deficiency', 'NC', array('document_type' => 'Statement of Deficieny', 'inspection_type' => 'MHLCS Annual and Complaint'), '',
        array('cited' => 1, 'complaints' => 1)),
    array('NC plan of correction is not a citation', 'NC', array('document_type' => 'Plan of Correction', 'inspection_type' => 'MHLCS Complaint'), '',
        array()),
    array('MN maltreatment determined', 'MN', array('doc_type' => 'Maltreatment Finding'), "Disposition: Maltreatment was determined.\nConclusions: ...",
        array('complaints' => 1, 'substantiated' => 1)),
    array('MN maltreatment not determined', 'MN', array('doc_type' => 'Maltreatment Finding'), "Disposition: Maltreatment was not determined.",
        array('complaints' => 1)),
    array('MN correction order, two violations, one repeat', 'MN', array('doc_type' => 'Correction Order'),
        "1. Violation: x\nRule Violated: part 1.\nRepeat Violation: This is a repeat violation.\n2. Violation: y",
        array('cited' => 1, 'citations' => 2, 'repeat' => 1)),
    array('GA complaint survey', 'GA', array('survey_type' => 'Licensure Complaint'), '', array('complaints' => 1)),
    array('WA counts nothing (scraper unreliable)', 'WA', array('violation_count' => 39), '', array()),
);
foreach ($cases as $c) {
    list($label, $state, $data, $raw, $want) = $c;
    $got = kop_irl_report_counts($state, $data, $raw);
    ksort($got);
    ksort($want);
    $check('rule: ' . $label, $got === $want, 'got ' . json_encode($got) . ', want ' . json_encode($want));
}

$years = array(
    array('10/02/2023', array(), 2023),
    array('2003-01-06', array(), 2003),
    array('', array('survey_date' => '01/04/2013'), 2013),
    array('', array('fiscal_year' => 'FY23-24'), 2024),
    array('', array(), null),
);
foreach ($years as $y) {
    $check('year of ' . json_encode(array($y[0], $y[1])), kop_irl_report_year($y[0], $y[1]) === $y[2], 'got ' . var_export(kop_irl_report_year($y[0], $y[1]), true));
}

// ---------------------------------------------------------------------------
// Count every report in the mirror into an in-memory table
// ---------------------------------------------------------------------------

$mem = new PDO('sqlite::memory:');
$mem->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// Unqualified names resolve to the in-memory database first, so the counts table is written there.
$mem->exec('ATTACH DATABASE ' . $mem->quote(realpath($db_path)) . ' AS m');
$mirror_has_counts = (bool) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'inspection_report_counts'")->fetchColumn();

$t0 = microtime(true);
$total_counted = 0;
do {
    $r = kop_irl_count_batch($mem, 20000, 600);
    $total_counted += $r['counted'];
} while ($r['counted'] > 0 && $r['remaining'] > 0);
$secs = round(microtime(true) - $t0, 1);
$reports = (int) $mem->query('SELECT COUNT(*) FROM m.inspection_reports')->fetchColumn();
echo "     counted $total_counted reports in {$secs}s\n";
$check('every report counted', $r['remaining'] === 0 && $total_counted === $reports, "counted $total_counted of $reports, {$r['remaining']} left");
$check('nothing written to the mirror', !$mirror_has_counts && !(bool) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'inspection_report_counts'")->fetchColumn());
$again = kop_irl_count_batch($mem, 100, 5);
$check('a second run counts nothing', $again['counted'] === 0);

$undated = (int) $mem->query('SELECT COUNT(*) FROM inspection_report_counts WHERE year IS NULL')->fetchColumn();
$check('nearly every report has a year', $undated < $reports * 0.02, "$undated undated");

// ---------------------------------------------------------------------------
// Rollup
// ---------------------------------------------------------------------------

$links = array();
$t0 = microtime(true);
$rollup = kop_irl_build($mem, 'wpdl_', $links);
echo '     rollup built in ' . round(microtime(true) - $t0, 1) . 's: ' . count($rollup['sites']) . ' sites, '
    . count($rollup['records']) . ' records, ' . count($rollup['operators']) . " companies\n";

$by_state = kop_irl_rows($rollup, 'state');
$sum = function (array $rows, $k) { return array_sum(array_map(function ($r) use ($k) { return $r['t'][$k] ?? 0; }, $rows)); };
$approved = (int) $mem->query("SELECT COUNT(*) FROM m.inspection_highlights h JOIN m.inspection_facilities f ON f.id = h.facility_id WHERE h.status = 'approved'")->fetchColumn();
$check('every approved finding is counted once', $sum($by_state, 'f') === $approved, 'rollup ' . $sum($by_state, 'f') . ", table $approved");
$pending = (int) $mem->query("SELECT COUNT(*) FROM m.inspection_highlights h JOIN m.inspection_facilities f ON f.id = h.facility_id WHERE h.status = 'pending'")->fetchColumn();
$check('every pending finding is counted once', $sum($by_state, 'p') === $pending, 'rollup ' . $sum($by_state, 'p') . ", table $pending");

$ca_rows = (int) $mem->query("SELECT COUNT(*) FROM m.inspection_reports r JOIN m.inspection_facilities f ON f.id = r.facility_id WHERE f.state = 'CA'")->fetchColumn();
$ca = array_values(array_filter($by_state, function ($r) { return $r['id'] === 'CA'; }));
$ca_reports = $ca ? (int) $ca[0]['t']['reports'] : 0;
$check('California reports stored twice count once', $ca_reports > 0 && $ca_reports < $ca_rows * 0.8, "$ca_reports of $ca_rows rows");

$ga = array_values(array_filter($by_state, function ($r) { return $r['id'] === 'GA'; }));
$check('a state without citations shows a dash, not 0', $ga && empty($ga[0]['has']['citations']) && !empty($ga[0]['has']['complaints']));

// A company counts a site once even when two of its records take it.
$dupe_ok = true;
foreach ($rollup['operators'] as $oid => $o) {
    $sids = array();
    foreach ($o['recs'] as $fid) foreach ($rollup['records'][$fid]['sites'] as $sid) $sids[$sid] = true;
    $row = kop_irl_rows(array('sites' => $rollup['sites'], 'records' => $rollup['records'], 'operators' => array($oid => $o)), 'operator');
    $want = 0;
    foreach (array_keys($sids) as $sid) foreach ($rollup['sites'][$sid]['y'] as $b) $want += $b['reports'] ?? 0;
    if ($row && (int) ($row[0]['t']['reports'] ?? 0) !== $want) { $dupe_ok = false; break; }
}
$check('a company counts each licensed site once', $dupe_ok);

// A record takes the same inspection rows its facility page lists.
$sample = array_slice(array_keys($rollup['records']), 0, 400);
$mismatch = array();
$state_names = kop_state_abbrev_to_name();
foreach ($sample as $fid) {
    $row = $pdo->query('SELECT unique_name, state, json_data FROM facilities_v2 WHERE id = ' . (int) $fid)->fetch(PDO::FETCH_ASSOC);
    $doc = json_decode((string) $row['json_data'], true);
    $st = strtoupper(trim((string) $row['state']));
    $page = kop_facility_pages_inspections(kop_facility_pages_doc_name_keys(is_array($doc) ? $doc : array(), $row['unique_name']), $st, $state_names[$st] ?? $st, 0);
    $page_sites = array();
    foreach ($page['reports'] ?? array() as $rep) {
        $page_sites[(int) $pdo->query('SELECT facility_id FROM inspection_reports WHERE id = ' . (int) $rep['id'])->fetchColumn()] = true;
    }
    // The rollup keeps only sites holding something, so compare within those. The page lists its
    // 25 newest reports: with more, every site it shows must be in the rollup; with fewer, the same sites.
    $page_sites = array_values(array_intersect(array_keys($page_sites), array_keys($rollup['sites'])));
    $mine = $rollup['records'][$fid]['sites'];
    sort($page_sites);
    sort($mine);
    $ok = ($page['total'] ?? 0) > 25 ? !array_diff($page_sites, $mine) : $page_sites === $mine;
    if (!$ok) $mismatch[] = "#$fid " . $row['unique_name'] . ': page ' . json_encode($page_sites) . ', rollup ' . json_encode($mine);
}
$check('records take the inspection rows their pages list (' . count($sample) . ' records)', !$mismatch, implode("\n     ", array_slice($mismatch, 0, 8)));

$since = kop_irl_rows($rollup, 'state', '', (int) date('Y') - 2);
$check('a year filter counts less', $sum($since, 'reports') < $sum($by_state, 'reports') && $sum($since, 'reports') > 0);

$sorted = kop_irl_sort(kop_irl_rows($rollup, 'record', 'TX'), 'high_risk');
$check('sorted worst first', count($sorted) > 1 && ($sorted[0]['t']['high_risk'] ?? 0) >= ($sorted[1]['t']['high_risk'] ?? 0));
$check('a state filter keeps one state', !array_filter($sorted, function ($r) { return $r['states'] !== array('TX'); }));
$sets = array();
foreach (kop_irl_rows($rollup, 'record') as $r) {
    if ($r['kind'] !== 'record') continue;
    $s = $rollup['records'][$r['id']]['sites'];
    sort($s);
    $sets[] = implode(',', $s);
}
$check('records holding the same licensed sites are one row', count($sets) === count(array_unique($sets)));

// ---------------------------------------------------------------------------
// The admin screen, every view, written to tmp/inspection-rankings/
// ---------------------------------------------------------------------------

if (!function_exists('selected')) {
    function selected($a, $b, $echo = true) { return (string) $a === (string) $b ? ' selected="selected"' : ''; }
}
if (!function_exists('checked')) {
    function checked($a, $b = true, $echo = true) { return $a == $b ? ' checked="checked"' : ''; }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field() { echo '<input type="hidden" name="_wpnonce" value="test">'; }
}
$out_dir = dirname(__DIR__) . '/tmp/inspection-rankings';
if (!is_dir($out_dir)) mkdir($out_dir, 0777, true);
$views = array(
    'companies'        => array('by' => 'operator'),
    'tx-high-risk'     => array('by' => 'record', 'st' => 'TX', 'sort' => 'high_risk'),
    'ca-substantiated' => array('by' => 'record', 'st' => 'CA', 'sort' => 'substantiated', 'since' => (string) ((int) date('Y') - 4)),
    'deaths'           => array('by' => 'record', 'sort' => 'f.death', 'pending' => '0'),
    'states'           => array('by' => 'state', 'sort' => 'reports'),
);
foreach ($views as $name => $get) {
    ob_start();
    try {
        kop_irl_render_page($mem, 'wpdl_', $get);
        $html = ob_get_clean();
        file_put_contents("$out_dir/$name.html", '<!doctype html><meta charset="utf-8"><title>' . $name . '</title>' . $html);
        $rows_drawn = substr_count($html, '<tr><td>');
        $check("screen renders: $name ($rows_drawn rows)", $rows_drawn > 0 && strpos($html, 'Inspection Rankings') !== false);
    } catch (Throwable $e) {
        ob_end_clean();
        $check("screen renders: $name", false, $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}
$deaths = file_get_contents("$out_dir/deaths.html");
$check('pending counts hidden when asked', strpos($deaths, 'pending</span>') === false);
$companies = file_get_contents("$out_dir/companies.html");
$check('company names link to their pages', strpos($companies, '/operator/') !== false);
$check('a measure a state does not publish is a dash', strpos($companies, 'Not published by this state') !== false);

// ---------------------------------------------------------------------------
// A look
// ---------------------------------------------------------------------------

if ($list) {
    $show = function ($title, array $rows, array $cols, $n = 12) {
        echo "\n== $title\n";
        foreach (array_slice($rows, 0, $n) as $i => $r) {
            $bits = array();
            foreach ($cols as $c) $bits[] = $c . '=' . ($c === 'rate' ? kop_irl_rate($r) : ($r['t'][$c] ?? 0));
            printf("%3d. %-48s %-10s %s\n", $i + 1, mb_substr($r['name'], 0, 48), implode(',', $r['states']), implode(' ', $bits));
        }
    };
    $cols = array('f', 'f.death', 'f.physical_abuse', 'f.sexual_abuse', 'p', 'reports', 'citations', 'substantiated');
    $show('States', kop_irl_sort($by_state, 'reports'), $cols, 20);
    $show('Companies by approved findings', kop_irl_sort(kop_irl_rows($rollup, 'operator'), 'f'), $cols);
    $show('Companies by approved findings + pending', kop_irl_sort(kop_irl_rows($rollup, 'operator'), 'p'), $cols);
    $show('Texas facilities by high-risk citations', kop_irl_sort(kop_irl_rows($rollup, 'record', 'TX'), 'high_risk'), array('f', 'reports', 'high_risk', 'repeat'));
    $show('California facilities by substantiated complaints', kop_irl_sort(kop_irl_rows($rollup, 'record', 'CA'), 'substantiated'), array('f', 'f.death', 'reports', 'complaints', 'substantiated'));
    $show('Michigan facilities by violations', kop_irl_sort(kop_irl_rows($rollup, 'record', 'MI'), 'citations'), array('reports', 'citations', 'complaints', 'substantiated'));
    $show('Facilities by deaths (approved)', kop_irl_sort(kop_irl_rows($rollup, 'record'), 'f.death'), array('f.death', 'f', 'p.death'));
    echo "\n     JSON size of the rollup: " . round(strlen(json_encode($rollup)) / 1024) . " KB\n";
}

echo "\n" . ($failures ? "$failures FAILED" : 'All passed') . "\n";
exit($failures ? 1 : 0);
