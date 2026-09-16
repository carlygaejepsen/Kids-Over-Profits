<?php
/**
 * Phase 2: offline rehearsal of the whole facility data model migration
 * (docs/DATA-MODEL-MIGRATION.md section 7, phase 2).
 *
 * Reads a production dump, builds the migration plan with the same code the
 * production endpoint uses (inc/facility-migration.php), and writes the plan
 * plus the reports the site owner signs off on. Touches no database.
 *
 * Usage:
 *   php -d memory_limit=1G scripts/rehearse-migration.php \
 *       --dump <dir> --baseline tmp/baseline-2026-09-16.json [--out tmp/rehearsal]
 *
 * <dir> holds facilities_master.tsv and locations_master.tsv (see
 * scripts/lib-dump-reader.php for the query) and optionally links.b64: one
 * base64 JSON object per line with kind news|lawsuit|wiki|address (queries in
 * docs/DATA-MODEL-MIGRATION.md section 10.6).
 *
 * Output (in --out):
 *   facilities.jsonl          the v2 rows, one per line
 *   operators.jsonl           rows for {prefix}kop_operators
 *   operator_facilities.tsv   rows for {prefix}kop_operator_facilities
 *   operator_links.tsv        rows for {prefix}kop_operator_links (news/lawsuits about an operator)
 *   facility_locations.tsv    rows for {prefix}kop_facility_locations
 *   link_repoints.tsv         news/lawsuit/wiki links that move to another facility id at cutover
 *   conflicts.tsv             facility_id, field, value_kept, value_dropped, sources
 *   identity_splits.tsv       old id, new ids, names, states, links repointed
 *   review.tsv                every row needing a human decision, with a reason code
 *   diff-vs-baseline.txt      per page: facilities missing, removed on purpose, added
 *   summary.txt               counts and the gate result
 *
 * New facility ids start at 100000 here, as on production. Production keeps
 * them stable across runs in {prefix}kop_facility_identity; offline they are
 * assigned in a stable order, so they match a first production apply.
 */

require_once __DIR__ . '/../inc/facility-migration.php';
require_once __DIR__ . '/lib-dump-reader.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

$args = array('dump' => null, 'baseline' => null, 'out' => __DIR__ . '/../tmp/rehearsal');
for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--dump':     $args['dump'] = $argv[++$i]; break;
        case '--baseline': $args['baseline'] = $argv[++$i]; break;
        case '--out':      $args['out'] = $argv[++$i]; break;
        case '--help':
        case '-h':
            echo "Usage: php scripts/rehearse-migration.php --dump <dir> --baseline <file> [--out <dir>]\n";
            exit(0);
        default:
            fwrite(STDERR, "Unknown argument: {$argv[$i]}\n");
            exit(2);
    }
}
if (!$args['dump'] || !is_dir($args['dump'])) {
    fwrite(STDERR, "--dump <dir> is required\n");
    exit(2);
}
if (!$args['baseline'] || !is_file($args['baseline'])) {
    fwrite(STDERR, "--baseline <file from scripts/snapshot-location-pages.js> is required\n");
    exit(2);
}
if (!is_dir($args['out']) && !mkdir($args['out'], 0777, true)) {
    fwrite(STDERR, "Cannot create output directory {$args['out']}\n");
    exit(2);
}

$started = microtime(true);
$dir = rtrim($args['dump'], '/\\');

// ---------------------------------------------------------------------------
// Load the dump
// ---------------------------------------------------------------------------

$load = function ($file) {
    $rows = array();
    kop_dump_rows($file, function (array $row) use (&$rows) {
        $rows[] = array('id' => $row['id'], 'unique_name' => $row['unique_name'], 'json' => $row['json'], 'updated_at' => $row['updated_at']);
    });
    usort($rows, function ($a, $b) { return $a['id'] - $b['id']; });
    return $rows;
};

$input = array(
    'facility_rows' => $load($dir . '/facilities_master.tsv'),
    'location_rows' => $load($dir . '/locations_master.tsv'),
);
if (is_file($dir . '/links.b64')) {
    $input['links'] = array();
    foreach (file($dir . '/links.b64', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $link = json_decode(base64_decode($line), true);
        if (is_array($link)) $input['links'][] = $link;
    }
}
$next_id = kop_migration_first_new_id();
$input['allocate_id'] = function ($key, $unique_name) use (&$next_id) {
    return $next_id++;
};

$plan = kop_migration_build_plan($input);

$baseline = json_decode(file_get_contents($args['baseline']), true);
if (!is_array($baseline) || empty($baseline['pages'])) {
    fwrite(STDERR, "Baseline file has no pages\n");
    exit(1);
}
$diff = kop_migration_diff_baseline($plan, $baseline);

// ---------------------------------------------------------------------------
// Write outputs
// ---------------------------------------------------------------------------

function rh_cell($value) {
    if (is_array($value) || is_object($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } elseif ($value === null) {
        $value = '';
    } elseif (is_bool($value)) {
        $value = $value ? 'true' : 'false';
    }
    return str_replace(array("\t", "\r", "\n"), ' ', (string)$value);
}

function rh_write_tsv($path, array $header, array $rows) {
    $fh = fopen($path, 'w');
    fwrite($fh, implode("\t", $header) . "\n");
    foreach ($rows as $row) {
        $cells = array();
        foreach ($header as $column) $cells[] = rh_cell($row[$column] ?? '');
        fwrite($fh, implode("\t", $cells) . "\n");
    }
    fclose($fh);
}

$out = rtrim($args['out'], '/\\');

$fh = fopen($out . '/facilities.jsonl', 'w');
foreach ($plan['docs'] as $doc) fwrite($fh, kop_facility_json_encode($doc) . "\n");
fclose($fh);

$fh = fopen($out . '/operators.jsonl', 'w');
foreach ($plan['operators'] as $row) fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
fclose($fh);

rh_write_tsv($out . '/operator_facilities.tsv', array('operator_id', 'facility_id', 'relationship', 'sort_order'), $plan['operator_facilities']);
rh_write_tsv($out . '/operator_links.tsv', array('link_kind', 'link_id', 'operator_id', 'operator_name', 'link_type', 'title'), $plan['operator_links']);
rh_write_tsv($out . '/facility_locations.tsv', array('facility_id', 'location_key', 'role', 'source', 'needs_review', 'review_reason'), $plan['memberships']);
rh_write_tsv($out . '/link_repoints.tsv', array('link_kind', 'link_id', 'from_facility_id', 'to_facility_id', 'reason'), $plan['link_repoints']);
rh_write_tsv($out . '/conflicts.tsv', array('facility_id', 'field', 'value_kept', 'value_dropped', 'sources'), $plan['conflicts']);

$split_rows = array();
$splits = $plan['splits'];
ksort($splits);
foreach ($splits as $old_id => $ids) {
    $names = array();
    $places = array();
    foreach ($ids as $id) {
        $d = $plan['docs'][$id];
        $names[] = $id . ':' . $d['identification']['name'];
        $places[] = $id . ':' . trim(($d['location']['city'] !== '' ? $d['location']['city'] . ', ' : '') . ($d['location']['state'] ?? ($d['location']['country'] ?? '?')));
    }
    $split_rows[] = array(
        'old_id'          => $old_id,
        'new_ids'         => implode(',', array_slice($ids, 1)),
        'names'           => implode(' | ', $names),
        'states'          => implode(' | ', $places),
        'links_repointed' => implode(' ; ', $plan['split_link_notes'][$old_id] ?? array()),
    );
}
rh_write_tsv($out . '/identity_splits.tsv', array('old_id', 'new_ids', 'names', 'states', 'links_repointed'), $split_rows);

$review = $plan['review'];
usort($review, function ($a, $b) {
    return strcmp($a['reason'], $b['reason']) ?: ((int)$a['facility_id'] <=> (int)$b['facility_id']);
});
rh_write_tsv($out . '/review.tsv', array('facility_id', 'reason', 'detail'), $review);

file_put_contents($out . '/diff-vs-baseline.txt', implode("\n", $diff['lines']) . "\n");

// Summary.
$by_origin = array_count_values($plan['origins']);
$review_counts = array();
foreach ($review as $r) $review_counts[$r['reason']] = ($review_counts[$r['reason']] ?? 0) + 1;
ksort($review_counts);
$membership_counts = array();
foreach ($plan['memberships'] as $m) {
    $k = $m['role'] . ' / ' . $m['source'] . ($m['needs_review'] ? ' (review)' : '');
    $membership_counts[$k] = ($membership_counts[$k] ?? 0) + 1;
}
ksort($membership_counts);
$validation_errors = $review_counts['validation_error'] ?? 0;
$floor = $plan['stats']['ref_rows'] + count($plan['splits']);

$summary = array();
$summary[] = 'Facility data model migration - offline rehearsal';
$summary[] = 'Run ' . gmdate('c') . ' in ' . round(microtime(true) - $started, 1) . 's, peak memory ' . round(memory_get_peak_usage(true) / 1048576) . ' MB';
$summary[] = 'Dump ' . realpath($args['dump']);
$summary[] = '';
$summary[] = 'GATE (zero loss): ' . ($diff['pass'] ? 'PASS' : 'FAIL (' . $diff['missing'] . ' missing)');
$summary[] = 'Count floor: ' . count($plan['docs']) . ' facility rows >= ' . $plan['stats']['ref_rows'] . ' ref rows + ' . count($plan['splits']) . ' split ids: '
    . (count($plan['docs']) >= $floor ? 'PASS' : 'FAIL');
$summary[] = 'Validation errors in migrated documents: ' . $validation_errors;
if ($plan['json_errors']) $summary[] = 'Unparseable rows: ' . count($plan['json_errors']);
$summary[] = '';
$summary[] = 'Copies read: ' . $plan['stats']['copies'];
$summary[] = 'Copy resolution: ' . json_encode($plan['stats']['resolution']);
$summary[] = 'Facility rows by origin:';
foreach ($by_origin as $k => $v) $summary[] = sprintf('  %-28s %5d', $k, $v);
$summary[] = 'Identity splits: ' . count($plan['splits']) . ' ids';
$summary[] = 'Field conflicts: ' . count($plan['conflicts']);
$summary[] = 'Operators: ' . count($plan['operators']) . ', operator-facility links: ' . count($plan['operator_facilities']);
$summary[] = 'Memberships: ' . count($plan['memberships']);
foreach ($membership_counts as $k => $v) $summary[] = sprintf('  %-48s %5d', $k, $v);
$summary[] = 'Wrong-page placements removed (old free-text matcher): ' . $diff['fixed'];
$summary[] = 'Links: ' . json_encode($plan['stats']['links']) . ', operator links: ' . count($plan['operator_links']) . ', repoints at cutover: ' . count($plan['link_repoints']);
$summary[] = 'Review rows by reason:';
foreach ($review_counts as $k => $v) $summary[] = sprintf('  %-28s %5d', $k, $v);
$summary[] = '';
$summary[] = 'New facility ids start at ' . kop_migration_first_new_id() . '.';

file_put_contents($out . '/summary.txt', implode("\n", $summary) . "\n");
echo implode("\n", $summary) . "\n";
exit($diff['pass'] ? 0 : 1);
