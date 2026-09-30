<?php
/**
 * Offline checks for Woodbury Facts (inc/woodbury-facts.php) against the
 * production mirror in tmp/prod.sqlite.
 *
 *   php scripts/test-woodbury-facts.php [--db=tmp/prod.sqlite] [--facts=C:/tmp/kop-woodbury/pending/facts.json]
 *
 * Every proposal for an existing record is applied to that record's real
 * document, which then goes through the same normalize step the save uses and
 * must pass the facility validator with each addition still in place. Undo
 * must then give back the document exactly as it was. The MySQL writes and
 * locks are not exercised here.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'facts::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$facts_path = $args['facts'] ?? 'C:/tmp/kop-woodbury/pending/facts.json';
if (!file_exists($db_path) || !file_exists($facts_path)) {
    fwrite(STDERR, "Need $db_path (scripts/sync-prod-sqlite.py) and $facts_path (scripts/woodbury-facts.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

$normalize = function (array $doc) {
    $out = kop_facility_normalize(kop_facility_to_legacy($doc), array(
        'facility_id' => $doc['facility_id'] ?? null,
        'unique_name' => $doc['provenance']['uniqueName'] ?? '',
    ));
    $out['provenance'] = $doc['provenance'] ?? $out['provenance'];
    return $out;
};
$get = function (array $doc, $path) {
    foreach (explode('.', $path) as $p) {
        if (!is_array($doc) || !array_key_exists($p, $doc)) return null;
        $doc = $doc[$p];
    }
    return $doc;
};

echo "-- Person keys match the build's --\n";
$check('titles and credentials dropped', kop_wbf_person_key('Dr. Jane M. Doe, PhD') === 'jane doe');
$check('one word is no key', kop_wbf_person_key('Madonna') === '');
$check('hyphenated first name and Jr. keep first and last', kop_wbf_person_key('Mary-Ann Smith Jr.') === 'mary smith');

$data = json_decode(file_get_contents($facts_path), true);
$by = array();
foreach ($data['proposals'] as $p) {
    if ((int) $p['facility_id'] > 0) {
        $by[(int) $p['facility_id']][] = array(
            'pkey' => $p['key'], 'op' => $p['op'], 'path' => $p['path'], 'value' => json_encode($p['value']),
            'label' => $p['label'], 'evidence' => json_encode($p['evidence']),
            'extra' => json_encode(array_intersect_key($p, array('person' => 1, 'career' => 1, 'note_line' => 1))),
        );
    }
}
printf("  %d proposals on %d records\n", array_sum(array_map('count', $by)), count($by));

echo "-- Apply, save shape, undo on every record --\n";
$stmt = $pdo->prepare('SELECT json_data FROM facilities_v2 WHERE id = ?');
$applied = $refused = $invalid = $lost = $undo_bad = $twice_bad = 0;
$examples = array();
foreach ($by as $fid => $rows) {
    $stmt->execute(array($fid));
    $doc = json_decode((string) $stmt->fetchColumn(), true);
    if (!is_array($doc)) {
        $examples[] = "#$fid missing";
        continue;
    }
    $base = $normalize($doc);
    $work = $base;
    $done = array();
    foreach ($rows as $r) {
        try {
            $trial = $work;
            $d = kop_wbf_doc_apply($trial, $r);
            $work = $trial;
            $done[] = array($r, $d);
            $applied++;
        } catch (RuntimeException $e) {
            $refused++;
        }
    }
    $saved = $normalize($work);
    $errors = array_filter(kop_facility_validate($saved), function ($v) { return $v['severity'] === 'error'; });
    if ($errors) {
        $invalid++;
        $examples[] = "#$fid invalid: " . reset($errors)['message'];
    }
    foreach ($done as list($r, $d)) {
        $v = json_decode($r['value'], true);
        $at = $get($saved, $d['at'] ?? $r['path']);
        $ok = true;
        if ($r['op'] === 'add_staff') {
            $ok = (bool) array_filter((array) $at, function ($s) use ($v) { return is_array($s) && kop_wbf_person_key($s['name'] ?? '') === kop_wbf_person_key($v['name']); });
        } elseif ($r['op'] === 'add_list') {
            $ok = kop_wbf_list_has((array) $at, is_array($v) ? $v : (string) $v);
        } elseif ($r['op'] === 'set_if_empty') {
            $ok = $at == $v;
        } elseif ($r['op'] === 'set_closed') {
            $ok = ($saved['operatingPeriod']['status'] ?? '') === 'Closed';
        }
        if (!$ok) {
            $lost++;
            if (count($examples) < 12) $examples[] = "#$fid lost after save: {$r['path']} " . substr($r['value'], 0, 80);
        }
        // Applying the same thing again must be refused (except staff merges, which fill gaps).
        if (in_array($r['op'], array('add_list', 'set_if_empty'), true)) {
            try {
                $again = $saved;
                kop_wbf_doc_apply($again, $r);
                $twice_bad++;
            } catch (RuntimeException $e) {
            }
        }
    }
    foreach (array_reverse($done) as list($r, $d)) {
        kop_wbf_doc_undo($saved, $d);
    }
    $back = $normalize($saved);
    if (json_encode($back) !== json_encode($base)) {
        $undo_bad++;
        if (count($examples) < 12) $examples[] = "#$fid undo left a difference";
    }
}
printf("  applied %d, refused as already on the record %d\n", $applied, $refused);
$check('every changed record passes the validator', $invalid === 0, "$invalid records");
$check('every addition survives the save normalizer', $lost === 0, "$lost lost");
$check('the same addition twice is refused', $twice_bad === 0, "$twice_bad accepted twice");
$check('undo gives back the document exactly', $undo_bad === 0, "$undo_bad records differ");
foreach (array_slice($examples, 0, 12) as $e) echo "    $e\n";

echo "-- Educational consultants: flag, then undo, on a copy of referrers_master --\n";
$cons = array_values(array_filter($data['proposals'], function ($p) { return ($p['group'] ?? '') === 'consultant'; }));
printf("  %d consultants flagged by the build, %d with a record\n", count($cons),
    count(array_filter($cons, function ($p) { return !empty($p['value']['referrer_id']); })));
$types = array_values(array_unique(array_map(function ($p) { return $p['value']; },
    array_filter($data['proposals'], function ($p) { return $p['path'] === 'facilityDetails.type'; }))));
$agreed = array('Residential Treatment Center', 'Psychiatric Residential Treatment Facility', 'Therapeutic Boarding School',
    'Wilderness Therapy', 'Boot Camp', 'Therapeutic Group Home', 'Group Home', 'Transitional Living Program',
    'Substance Abuse Treatment', 'Maternity Home', 'Fundamentalist Religious Home', 'Specialty Boarding School',
    'Juvenile Detention Facility', 'Juvenile Correctional Facility', 'Juvenile Justice RTC', 'Other');
$check('every proposed facility type is an agreed type', !array_diff($types, $agreed), implode(', ', array_diff($types, $agreed)));

$mem = new PDO('sqlite::memory:');
$mem->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mem->sqliteCreateFunction('NOW', function () { return gmdate('Y-m-d H:i:s'); }, 0);
$mem->exec('CREATE TABLE referrers_master (id INTEGER PRIMARY KEY AUTOINCREMENT, unique_name TEXT, json_data TEXT, updated_at TEXT)');
$ins = $mem->prepare('INSERT INTO referrers_master (id, unique_name, json_data) VALUES (?, ?, ?)');
foreach ($pdo->query('SELECT id, unique_name, json_data FROM referrers_master') as $row) {
    $ins->execute(array($row['id'], $row['unique_name'], $row['json_data']));
}
$GLOBALS['kop_test_referrers_pdo'] = $mem;
if (!function_exists('kop_closure_pdo')) {
    function kop_closure_pdo() { return $GLOBALS['kop_test_referrers_pdo']; }
}
if (!function_exists('kop_resolve_table_name')) {
    function kop_resolve_table_name(PDO $pdo, $base, $prefix = '') { return $base; }
}
if (!function_exists('kop_ensure_master_table')) {
    function kop_ensure_master_table(PDO $pdo, $table) {}
}
if (!function_exists('kop_wbc_create_consultant')) {
    // The real one also makes a FileBird folder; here only the row.
    function kop_wbc_create_consultant(array $r, array $f, PDO $pdo) {
        $person = array('fullName' => $f['name'], 'role' => 'Educational Consultant', 'state' => $f['state'], 'pastTTIJobs' => array(), 'notes' => '');
        $pdo->prepare('INSERT INTO referrers_master (unique_name, json_data) VALUES (?, ?)')
            ->execute(array($f['name'], json_encode(array('name' => $f['name'], 'data' => array('referrerIndividual' => $person, 'referrerConsultants' => array($person))))));
        return array('id' => (int) $pdo->lastInsertId());
    }
}
$flagged = $bad_flag = $bad_undo = 0;
$cex = array();
foreach ($cons as $p) {
    $r = array('pkey' => $p['key'], 'grp' => 'consultant', 'op' => $p['op'], 'value' => json_encode($p['value']),
        'label' => $p['label'], 'evidence' => json_encode($p['evidence']), 'extra' => '{}');
    try {
        $done = kop_wbf_consultant_apply($r);
    } catch (Throwable $e) {
        $bad_flag++;
        $cex[] = $p['value']['name'] . ': ' . $e->getMessage();
        continue;
    }
    $flagged++;
    $st = $mem->prepare('SELECT json_data FROM referrers_master WHERE id = ?');
    $st->execute(array($done['referrer_id']));
    $doc = json_decode($st->fetchColumn(), true);
    $key = kop_wbf_person_key($p['value']['name']);
    $entries = kop_wbf_consultant_entries($doc['data'], $key);
    $orgs = array();
    foreach ($entries as $e) {
        foreach ((array) ($e['pastTTIJobs'] ?? array()) as $j) {
            $orgs[] = strtolower(is_array($j) ? ($j['organization'] ?? '') : $j);
        }
    }
    $want = array_map(function ($j) { return strtolower($j['organization']); }, $p['value']['jobs']);
    if (!$entries || array_diff($want, $orgs) || empty($entries[0]['formerIndustryStaff'])) {
        $bad_flag++;
        $cex[] = $p['value']['name'] . ': jobs or flag missing after apply';
    }
    $r['applied'] = json_encode($done);
    kop_wbf_consultant_undo($r);
    $st->execute(array($done['referrer_id']));
    if ($st->fetchColumn() !== $done['before']) {
        $bad_undo++;
    }
}
printf("  flagged %d\n", $flagged);
$check('every consultant gets their jobs and the former-staff flag', $bad_flag === 0, "$bad_flag failed");
$check('undo gives back the consultant record exactly', $bad_undo === 0, "$bad_undo differ");
foreach (array_slice($cex, 0, 8) as $e) echo "    $e\n";

echo $failures ? "\n$failures FAILED\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
