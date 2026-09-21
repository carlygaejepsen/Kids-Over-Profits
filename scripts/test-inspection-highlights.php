<?php
/**
 * Offline check of the inspection highlights parser (inc/inspection-highlights.php,
 * docs/FIX-PLAN-2026-09.md item 14).
 *
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite -d memory_limit=2048M \
 *       scripts/test-inspection-highlights.php [--db=tmp/prod.sqlite] [--report=<file.md>] [--samples=8]
 *
 * Part 1 runs fixed cases through the rules and needs no database. Part 2
 * (when the SQLite mirror from scripts/sync-prod-sqlite.py is present) runs
 * the scanner over every supported report as a dry run, then into a scratch
 * copy of the two highlight tables to check that a second run changes nothing
 * and that a reviewed row survives. The mirror itself is opened read-only.
 * --report writes the distribution and the top candidates to a file for a
 * person to read.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/inc/inspection-highlights.php';

$args = getopt('', array('db::', 'report::', 'samples::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$report_path = $args['report'] ?? '';
$samples = (int) ($args['samples'] ?? 8);

$failures = 0;
$checks = 0;
function check($ok, $label) {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "FAIL  $label\n"; }
}

// ---------------------------------------------------------------------------
// Part 1: the rules
// ---------------------------------------------------------------------------

$sentence_cases = array(
    // sentence, categories expected (exactly)
    array('A child in care died after being restrained by two staff.', array('death')),
    array('No deaths or serious injuries were reported during the review period.', array()),
    array('The child was not hospitalized and no injuries were found.', array()),
    array('Staff punched a resident in the face during an argument.', array('physical_abuse')),
    array('Staff restrained the child in a prone hold, resulting in a fractured wrist.', array('restraint_injury')),
    array('Staff restrained the child for ten minutes.', array()),
    array('The child absconded from the facility and police were called.', array('missing', 'police')),
    array('Failure to supervise could result in serious injury or death.', array()),
    array('The operation must report a death within 24 hours.', array()),
    array('Staff engaged in a sexual relationship with a 16-year-old resident.', array('sexual_abuse')),
    array('The resident denied any sexual contact with staff.', array()),
    array('The child was transported to the hospital by ambulance after swallowing batteries.', array('self_harm', 'hospitalization')),
    array('Staff failed to seek medical treatment for a child with a broken arm for three days.', array('medical_neglect')),
    array('The deadline for the deadbolt repair was extended.', array()),
    array('Dr. Smith reviewed the file. The child ran away on 3/4/2024.', array('missing')),
    // Noise the first dry run against the mirror turned up.
    array('LPA met with Paige Woodard, Deputy Director, and discussed the above allegation.', array()),
    array('LPA observed sufficient food supply, including fresh fruits, can goods, and staples.', array()),
    array('Missing from policy', array()),
    array('LPA reviewed the Stockton Police Department report and facility records.', array()),
    array('Law enforcement was called to the facility and arrested S1.', array('police')),
    array('S1 failed to provide supervision, protection, and care of the clients.', array()),
    array('C1 told S1 that he hoped she and her grandson died.', array()),
    array('Two children in care were missing from the operation for 15 minutes.', array('missing')),
    array('The caregiver signature was missing on two medication logs.', array()),
    array('A child in care was reported missing at 3 AM.', array('missing')),
);
foreach ($sentence_cases as $case) {
    $got = array();
    foreach (kop_ih_split_sentences($case[0]) as $s) $got = array_merge($got, array_keys(kop_ih_match_sentence($s)));
    sort($got);
    $want = $case[1];
    sort($want);
    check($got === $want, 'sentence: "' . $case[0] . '" expected [' . implode(',', $want) . '] got [' . implode(',', $got) . ']');
}

check(count(kop_ih_split_sentences('Dr. Smith met Mr. Jones at 9 a.m. on Monday. They left.')) === 2, 'abbreviations do not end a sentence');

// Texas
$tx = array('id' => 1, 'facility_id' => 1, 'categories_json' => json_encode(array(
    'Standard Number / Description' => '748.685(a)(4) - Caregiver responsibility',
    'Standard Risk Level' => 'High', 'Corrected at Inspection' => 'No',
    'Deficiency Narrative' => 'A child in care absconded and was later hospitalized after an overdose.',
)));
$c = kop_ih_candidates('TX', $tx);
check(count($c) === 1 && $c[0]['category'] === 'self_harm', 'TX: worst category wins');
check($c && $c[0]['score'] === 75, 'TX: High risk keeps the full score (65 + 2 extra categories)');
check($c && $c[0]['state_label'] === 'Risk level: High' && $c[0]['corrected_on_site'] === false, 'TX: label and corrected flag');
check($c && $c[0]['excerpt'] === 'A child in care absconded and was later hospitalized after an overdose.', 'TX: excerpt is verbatim');
$tx_low = $tx;
$tx_low['categories_json'] = str_replace('"High"', '"Low"', $tx['categories_json']);
$c_low = kop_ih_candidates('TX', $tx_low);
check($c_low && $c_low[0]['score'] === 30, 'TX: Low risk scales the score down');
$tx_none = $tx;
$tx_none['categories_json'] = json_encode(array('Standard Risk Level' => 'High', 'Deficiency Narrative' => 'Two smoke detectors had no batteries.'));
check(kop_ih_candidates('TX', $tx_none) === array(), 'TX: a High citation with no harm is not queued');

// California
$ca_text = '13On 9-27-24 LPA conducted an unannounced inspection. Staff physically assaulted client in care. '
    . 'The preponderance of the evidence has been met. Therefore, these allegations are Substantiated. '
    . 'Facility is being cited for violation of Section 87072(c)(1) and 80075(b). '
    . 'SubstantiatedEstimated Days of Completion: SUPERVISORS NAME: A Person LICENSING EVALUATOR NAME: B Person';
$ca = array('id' => 2, 'facility_id' => 2, 'categories_json' => json_encode(array(
    'report_type' => 'Complaint Investigation', 'complaint_status' => 'unsubstantiated', 'investigation_findings' => $ca_text,
)));
$c = kop_ih_candidates('CA', $ca);
check(count($c) === 1 && $c[0]['state_label'] === 'Substantiated', 'CA: outcome read from the text, not the scraped status');
check($c && $c[0]['category'] === 'physical_abuse' && $c[0]['score'] === 80, 'CA: substantiated physical abuse scores 80');
check($c && $c[0]['excerpt'] === 'Staff physically assaulted client in care.', 'CA: excerpt is the matching sentence only');
check($c && strpos($c[0]['standard'], '87072(c)(1)') !== false && strpos($c[0]['standard'], '80075(b)') !== false, 'CA: sections cited are collected');
check($c && strpos($c[0]['excerpt'], 'SUPERVISORS') === false, 'CA: form boilerplate is stripped');

$ca_unsub = $ca;
$ca_unsub['categories_json'] = json_encode(array('complaint_status' => 'unsubstantiated',
    'investigation_findings' => 'The complaint alleged that staff hit a minor. There is not a preponderance of evidence. Therefore, the allegations are UNSUBSTANTIATED.'));
check(kop_ih_candidates('CA', $ca_unsub) === array(), 'CA: an unsubstantiated complaint is not queued');

$ca_mixed = $ca;
$ca_mixed['categories_json'] = json_encode(array('complaint_status' => 'unsubstantiated',
    'investigation_findings' => 'Staff slapped a client in care. This allegation is Substantiated. The allegation that staff withheld food is Unsubstantiated.'));
$c = kop_ih_candidates('CA', $ca_mixed);
check(count($c) === 1 && $c[0]['state_label'] === 'Partly substantiated' && $c[0]['score'] === 64, 'CA: a mixed report is queued at 0.8');

$ca_eval = array('id' => 3, 'facility_id' => 3, 'categories_json' => json_encode(array(
    'report_type' => 'Facility Evaluation', 'narrative' => 'LPA toured the facility. All bedrooms were clean. No deficiencies were cited.')));
check(kop_ih_candidates('CA', $ca_eval) === array(), 'CA: an evaluation with nothing cited is not queued');

// Store: idempotent, and a reviewed row is never touched.
$mem = new PDO('sqlite::memory:');
$mem->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
kop_ih_ensure_tables($mem);
$cands = kop_ih_candidates('TX', $tx);
$first = kop_ih_store($mem, 'TX', $tx, $cands);
$second = kop_ih_store($mem, 'TX', $tx, $cands);
check($first['added'] === 1 && $second['added'] === 0 && $second['refreshed'] === 1, 'store: a second run adds nothing');
$tx_twin = array('id' => 9, 'facility_id' => 1) + $tx;
$twin = kop_ih_store($mem, 'TX', $tx_twin, kop_ih_candidates('TX', $tx_twin));
check($twin['duplicate'] === 1 && $twin['added'] === 0, 'store: the same finding under a second report id of one facility is not queued twice');
$mem->exec("UPDATE inspection_highlights SET status = 'rejected', reviewed_by = 'tester', score = 1");
$third = kop_ih_store($mem, 'TX', $tx, $cands);
$row = $mem->query('SELECT status, score, reviewed_by FROM inspection_highlights')->fetch(PDO::FETCH_ASSOC);
check($third['kept'] === 1 && $row['status'] === 'rejected' && (int) $row['score'] === 1, 'store: a rejected row is left as the reviewer left it');
$fourth = kop_ih_store($mem, 'TX', $tx, array());
check($fourth['kept'] === 1 && (int) $mem->query('SELECT COUNT(*) FROM inspection_highlights')->fetchColumn() === 1, 'store: a reviewed row survives the rules no longer producing it');
$mem->exec("UPDATE inspection_highlights SET status = 'pending'");
$fifth = kop_ih_store($mem, 'TX', $tx, array());
check($fifth['dropped'] === 1, 'store: a pending row the rules no longer produce is removed');

echo "Rules: $checks checks, $failures failed.\n";

// ---------------------------------------------------------------------------
// Part 2: the mirror
// ---------------------------------------------------------------------------

if (!file_exists($db_path)) {
    echo "No mirror at $db_path (run scripts/sync-prod-sqlite.py); skipping the dry run.\n";
    exit($failures ? 1 : 0);
}

$pdo = new PDO('sqlite:file:' . str_replace('\\', '/', realpath($db_path)) . '?mode=ro', null, null, array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$started = microtime(true);
$dry = kop_ih_scan($pdo, 1000000, false);
$seconds = round(microtime(true) - $started, 1);
$cands = $dry['candidates'];
usort($cands, static function ($a, $b) { return $b['score'] <=> $a['score'] ?: $b['report_row'] <=> $a['report_row']; });

$by_state = array(); $by_cat = array(); $by_band = array('90-100' => 0, '70-89' => 0, '50-69' => 0, '30-49' => 0);
$labels = array();
foreach ($cands as $c) {
    $by_state[$c['state']] = ($by_state[$c['state']] ?? 0) + 1;
    $by_cat[$c['category']] = ($by_cat[$c['category']] ?? 0) + 1;
    $labels[$c['state'] . ' / ' . $c['state_label']] = ($labels[$c['state'] . ' / ' . $c['state_label']] ?? 0) + 1;
    $band = $c['score'] >= 90 ? '90-100' : ($c['score'] >= 70 ? '70-89' : ($c['score'] >= 50 ? '50-69' : '30-49'));
    $by_band[$band]++;
    check(trim($c['excerpt']) !== '' && strlen($c['excerpt']) <= 4000, 'mirror: excerpt present and bounded (report ' . $c['report_row'] . ')');
    check(!preg_match('/SUPERVISORS NAME|Estimated Days of Completion/', $c['excerpt']), 'mirror: no form boilerplate in excerpt (report ' . $c['report_row'] . ')');
}
arsort($by_cat); arsort($labels);

$lines = array();
$lines[] = "Scanned {$dry['scanned']} reports in {$seconds}s; " . count($cands) . ' candidates at score ' . kop_ih_min_score() . ' or more.';
$lines[] = 'By state: ' . json_encode($by_state);
$lines[] = 'By score: ' . json_encode($by_band);
$lines[] = 'By worst category: ' . json_encode($by_cat);
$lines[] = 'By state signal: ' . json_encode($labels);
echo implode("\n", $lines) . "\n";

if ($report_path !== '') {
    $cats = kop_ih_categories();
    $md = "# Inspection highlights: dry run against the mirror\n\n" . implode("\n\n", $lines) . "\n";
    $md .= "\n## Top $samples per category\n";
    foreach (array_keys($cats) as $key) {
        $md .= "\n### {$cats[$key]['label']}\n\n";
        $n = 0;
        foreach ($cands as $c) {
            if ($c['category'] !== $key) continue;
            $md .= "- **{$c['score']}** {$c['state']} | {$c['facility_name']} | {$c['report_date']} | {$c['state_label']} | report row {$c['report_row']}"
                . ($c['corrected_on_site'] ? ' | corrected on site' : '') . "\n  > " . $c['excerpt'] . "\n";
            if (++$n >= $samples) break;
        }
    }
    // A random slice as well, so the weak end of the queue is visible too.
    mt_srand(14);
    $md .= "\n## Random $samples from the whole queue\n\n";
    $keys = $cands ? (array) array_rand($cands, min($samples * 3, count($cands))) : array();
    foreach ($keys as $k) {
        $c = $cands[$k];
        $md .= "- **{$c['score']}** {$c['category']} | {$c['state']} | {$c['facility_name']} | {$c['state_label']} | report row {$c['report_row']}\n  > " . $c['excerpt'] . "\n";
    }
    file_put_contents($report_path, $md);
    echo "Wrote $report_path\n";
}

echo "Total: $checks checks, $failures failed.\n";
exit($failures ? 1 : 0);
