<?php
/**
 * Phase 1 report: run kop_facility_normalize() over every facility copy in a
 * production dump and report what comes out (docs/DATA-MODEL-MIGRATION.md,
 * phase 1 step 3).
 *
 * Reports
 *   - copies by source (ref row, operator row, location row)
 *   - distinct top-level key sets after normalize (must be exactly one)
 *   - validation violations by rule and severity, with examples
 *   - before/after samples for the rules that rewrite values
 *
 * Input is the base64 TSV dump described in section 1 of the spec:
 *
 *   SELECT CONCAT(id, '\t', REPLACE(TO_BASE64(unique_name), '\n', ''), '\t',
 *                 REPLACE(TO_BASE64(json_data), '\n', ''), '\t', updated_at)
 *   FROM facilities_master;
 *
 * mysql batch mode writes the separator as a literal backslash-t, which is
 * what this parser splits on.
 *
 * Usage:
 *   php scripts/normalize-dump.php --dump /path/to/dump [--samples 5] [--out report.txt]
 *   php scripts/normalize-dump.php --dump /path/to/dump --rule status.vocabulary
 *   php scripts/normalize-dump.php --dump /path/to/dump --emit copies.jsonl
 *
 * --emit writes {raw, opts, doc} per copy for scripts/check-facility-normalizer-parity.js.
 */

require_once __DIR__ . '/../inc/facility-store.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$opts = array('dump' => null, 'samples' => 4, 'out' => null, 'rule' => null, 'emit' => null);
for ($i = 1; $i < $argc; $i++) {
    switch ($argv[$i]) {
        case '--dump':    $opts['dump'] = $argv[++$i]; break;
        case '--samples': $opts['samples'] = max(0, (int)$argv[++$i]); break;
        case '--out':     $opts['out'] = $argv[++$i]; break;
        case '--rule':    $opts['rule'] = $argv[++$i]; break;
        case '--emit':    $opts['emit'] = $argv[++$i]; break;
        case '--help':
        case '-h':
            echo "Usage: php scripts/normalize-dump.php --dump <dir> [--samples N] [--out file] [--rule name]\n";
            exit(0);
        default:
            fwrite(STDERR, "Unknown argument: {$argv[$i]}\n");
            exit(2);
    }
}
if (!$opts['dump'] || !is_dir($opts['dump'])) {
    fwrite(STDERR, "--dump <directory containing facilities_master.tsv and locations_master.tsv> is required\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// Dump reader (shared with scripts/rehearse-migration.php)
// ---------------------------------------------------------------------------

require_once __DIR__ . '/lib-dump-reader.php';

// ---------------------------------------------------------------------------
// Walk every copy
// ---------------------------------------------------------------------------

$stats = array(
    'copies'      => 0,
    'by_source'   => array(),
    'key_sets'    => array(),
    'violations'  => array(),
    'examples'    => array(),
    'changes'     => array(),
    'json_errors' => array(),
);

$sample = function ($bucket, $text) use (&$stats, $opts) {
    if (!isset($stats['changes'][$bucket])) {
        $stats['changes'][$bucket] = array('count' => 0, 'samples' => array());
    }
    $stats['changes'][$bucket]['count']++;
    if (count($stats['changes'][$bucket]['samples']) < $opts['samples']) {
        $stats['changes'][$bucket]['samples'][] = $text;
    }
};

$emit_handle = $opts['emit'] ? fopen($opts['emit'], 'w') : null;

$inspect = function (array $facility, array $ctx) use (&$stats, $opts, $sample, $emit_handle) {
    $doc = kop_facility_normalize($facility, $ctx['opts']);
    if ($emit_handle) {
        fwrite($emit_handle, json_encode(array(
            'source' => $ctx['source'] . ' #' . $ctx['row_id'] . ($ctx['index'] !== null ? '[' . $ctx['index'] . ']' : ''),
            'raw'    => $facility,
            'opts'   => $ctx['opts'],
            'doc'    => json_decode(kop_facility_json_encode($doc)),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
    $stats['copies']++;
    $stats['by_source'][$ctx['source']] = ($stats['by_source'][$ctx['source']] ?? 0) + 1;

    $key_set = implode(',', array_keys($doc));
    $stats['key_sets'][$key_set] = ($stats['key_sets'][$key_set] ?? 0) + 1;

    foreach (kop_facility_validate($doc) as $violation) {
        $key = $violation['severity'] . ' ' . $violation['rule'];
        $stats['violations'][$key] = ($stats['violations'][$key] ?? 0) + 1;
        if (!isset($stats['examples'][$key])) $stats['examples'][$key] = array();
        if (count($stats['examples'][$key]) < $opts['samples']) {
            $stats['examples'][$key][] = sprintf(
                '%s #%s "%s" :: %s - %s',
                $ctx['source'],
                $ctx['row_id'],
                $doc['identification']['name'] !== '' ? $doc['identification']['name'] : '(no name)',
                $violation['path'],
                $violation['message']
            );
        }
    }

    // Before/after for the rules that rewrite values.
    $legacy_status = '';
    if (isset($facility['operatingPeriod']['status'])) {
        $legacy_status = (string)$facility['operatingPeriod']['status'];
    }
    if ($legacy_status !== '' && $legacy_status !== $doc['operatingPeriod']['status']) {
        $sample('status rewritten', sprintf('"%s" -> %s (%s)', $legacy_status, $doc['operatingPeriod']['status'], $doc['identification']['name']));
    }
    if ($legacy_status === '' && isset($facility['operatingPeriod'])) {
        $sample('status blank -> Unknown', $doc['identification']['name']);
    }

    if (is_string($facility['address'] ?? null) && trim($facility['address']) !== '') {
        if ($doc['location']['state'] !== null || $doc['location']['city'] !== '' || $doc['location']['country'] !== null) {
            $sample('address parsed', sprintf(
                '%s -> street="%s" city="%s" state=%s zip=%s',
                trim($facility['address']),
                $doc['location']['street'],
                $doc['location']['city'],
                $doc['location']['state'] === null ? 'null' : $doc['location']['state'],
                $doc['location']['zip'] === '' ? 'null' : $doc['location']['zip']
            ));
        } else {
            $sample('address NOT parsed', trim($facility['address']) . ' (' . $doc['identification']['name'] . ')');
        }
    }
    if (is_array($facility['address'] ?? null)) {
        $sample('address object mapped', json_encode($facility['address']) . ' -> ' . json_encode(array(
            'street' => $doc['location']['street'],
            'city'   => $doc['location']['city'],
            'state'  => $doc['location']['state'],
            'zip'    => $doc['location']['zip'],
        )));
    }

    foreach (array('startYear', 'endYear') as $field) {
        $before = $facility['operatingPeriod'][$field] ?? null;
        if (is_string($before) && trim($before) !== '') {
            $sample($field . ' cast from string', sprintf('"%s" -> %s', $before, var_export($doc['operatingPeriod'][$field], true)));
        }
    }
    $capacity = $facility['facilityDetails']['capacity'] ?? null;
    if (is_string($capacity) && trim($capacity) !== '') {
        $sample('capacity cast from string', sprintf('"%s" -> %s', $capacity, var_export($doc['facilityDetails']['capacity'], true)));
    }
    if (is_array($facility['fieldNotes'] ?? null) && $facility['fieldNotes'] !== array()
        && array_keys($facility['fieldNotes']) === range(0, count($facility['fieldNotes']) - 1)) {
        $sample('fieldNotes list -> map', $doc['identification']['name']);
    }
    if ($doc['legacy'] !== array()) {
        $sample('unmapped keys kept in legacy', implode(',', array_keys($doc['legacy'])) . ' (' . $doc['identification']['name'] . ')');
    }
    $memberships = kop_facility_derive_memberships($doc);
    if (count($memberships) === 1 && $memberships[0]['location_key'] === 'UNKNOWN') {
        $sample('no location derived', sprintf('%s #%s "%s"', $ctx['source'], $ctx['row_id'], $doc['identification']['name']));
    }
};

kop_dump_walk_facilities($opts['dump'], $inspect, function ($message) use (&$stats) {
    $stats['json_errors'][] = $message;
});
if ($emit_handle) fclose($emit_handle);

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

$lines = array();
$out = function ($line = '') use (&$lines) { $lines[] = $line; };

$out('Facility normalize report');
$out('Dump: ' . realpath($opts['dump']));
$out('Run:  ' . gmdate('c'));
$out('');
$out('Copies normalized: ' . $stats['copies']);
foreach ($stats['by_source'] as $source => $count) {
    $out(sprintf('  %-22s %6d', $source, $count));
}
if ($stats['json_errors']) {
    $out('');
    $out('Rows that would not parse as JSON: ' . count($stats['json_errors']));
    foreach (array_slice($stats['json_errors'], 0, 10) as $message) $out('  ' . $message);
}

$out('');
$out('Top-level key sets after normalize: ' . count($stats['key_sets']) . ' (target: 1)');
foreach ($stats['key_sets'] as $key_set => $count) {
    $out(sprintf('  %6d  %s', $count, $key_set));
}

$out('');
if (!$stats['violations']) {
    $out('Validation: no violations.');
} else {
    ksort($stats['violations']);
    $errors = 0;
    foreach ($stats['violations'] as $key => $count) {
        if (strpos($key, 'error ') === 0) $errors += $count;
    }
    $out('Validation violations (errors: ' . $errors . '):');
    foreach ($stats['violations'] as $key => $count) {
        $out(sprintf('  %6d  %s', $count, $key));
        if ($opts['rule'] !== null && strpos($key, $opts['rule']) === false) continue;
        foreach ($stats['examples'][$key] as $example) {
            $out('           ' . $example);
        }
    }
}

$out('');
$out('Value rewrites:');
ksort($stats['changes']);
foreach ($stats['changes'] as $bucket => $info) {
    $out(sprintf('  %6d  %s', $info['count'], $bucket));
    foreach ($info['samples'] as $s) {
        $out('           ' . $s);
    }
}

$report = implode("\n", $lines) . "\n";
if ($opts['out']) {
    @mkdir(dirname($opts['out']), 0777, true);
    file_put_contents($opts['out'], $report);
    echo 'Written to ' . $opts['out'] . "\n";
}
echo $report;
