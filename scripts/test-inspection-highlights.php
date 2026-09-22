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
    // Child on child is not queued as abuse; what an adult did is.
    array('Due to staff not being aware, one child was put in a choke hold by another child.', array()),
    array('A child inappropriately touched another child by grabbing them in the private area.', array()),
    array('C1 engaged in sexual intercourse with C2 in the facility bathroom.', array()),
    array('Inappropriate sexual contact occurred between two residents.', array()),
    array('Staff failed to stop a peer-on-peer assault in the day room.', array()),
    array('Two children were able to engage in consensual inappropriate sexual contact with each other.', array()),
    array('CCL received an allegation that Client #1 (C1) was sexually assaulted by Client #2 while in care.', array()),
    array('Child 1 (C1) and Child (C2) engaged in inappropriate sexual behaviors while overnight staff slept.', array()),
    array('Staff did not follow the plan, resulting in Client #2 (C2) and Client #3 (C3) physically assaulting (C1).', array()),
    array('Staff failed to intervene when C1 hit C2.', array()),
    array('Residents and staff reported multiple incidents where the child hit younger peers.', array()),
    array('S1 physically assaulted C1 and C2.', array('physical_abuse')),
    array('CCL received an allegation that Client #1 (C1) (see LIC811, dated 12/16/2021) was sexually assaulted by Client #2 while in care.', array()),
    array('Staff failed to separate two children after a child was hit twice by another child.', array()),
    array('The operation failed to report sexual abuse against a child in care by another resident.', array()),
    array('All residents interviewed stated they have either been hit by the staff or witnessed the staff hit another resident.', array('physical_abuse')),
    array('Staff member S1 was seen on video hitting C1 in the day room.', array('physical_abuse')),
    array('Staff sexually abused a child in care.', array('sexual_abuse')),
    array('On video, a staff member was seen shoving a child to prevent them from going into the room of a peer.', array('physical_abuse')),
    array('A child in care was subjected to physical abuse by a operation staff member.', array('physical_abuse')),
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

// A runaway on its own is not queued; one that ends in a death or a serious injury is.
$tx_run = static function ($narrative) use ($tx) {
    $row = $tx;
    $row['categories_json'] = json_encode(array('Standard Risk Level' => 'High', 'Deficiency Narrative' => $narrative));
    return kop_ih_candidates('TX', $row);
};
check($tx_run('The child ran away from the operation on 01/03/22 at 11:30pm.') === array(), 'TX: a runaway alone is not queued');
check($tx_run('The child absconded from the facility and police were called to search for him.') === array(), 'TX: a runaway with the police searching is still a runaway alone');
check($tx_run('The child ran away and returned the next day with no injuries.') === array(), 'TX: a runaway who came back unhurt is not queued');
$c = $tx_run('The child ran away and was hit by a car, suffering a fractured leg.');
check(count($c) === 1 && $c[0]['category'] === 'missing', 'TX: a runaway with a serious injury is queued');
$c = $tx_run('The child ran away from the operation. The child was found deceased two days later.');
check(count($c) === 1 && $c[0]['category'] === 'death', 'TX: a runaway who died is queued as a death');
$c = $tx_run('The child ran away from the operation and was later taken to the hospital by ambulance.');
check(count($c) === 1 && $c[0]['category'] === 'hospitalization', 'TX: a runaway who ended up in hospital is queued');

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

// A report that substantiates one allegation and not another: only the substantiated one counts.
$ca_mixed = static function ($findings) use ($ca) {
    $row = $ca;
    $row['categories_json'] = json_encode(array('complaint_status' => 'unsubstantiated', 'investigation_findings' => $findings));
    return kop_ih_candidates('CA', $row);
};
$c = $ca_mixed('Staff slapped a client in care. This allegation is Substantiated. The allegation that staff withheld food is Unsubstantiated.');
check(count($c) === 1 && $c[0]['state_label'] === 'Substantiated (one of several allegations)' && $c[0]['score'] === 80, 'CA: a mixed report is queued at full score for the substantiated allegation');
check($c && $c[0]['excerpt'] === 'Staff slapped a client in care.', 'CA: a mixed report quotes the harm, not the verdict');
check($ca_mixed('Staff slapped a client in care. This allegation is Unsubstantiated. Staff withheld food from clients. This allegation is Substantiated.') === array(),
    'CA: a mixed report whose harm was the unsubstantiated allegation is not queued');
check($ca_mixed('The allegation that staff physically abused a client in care is Substantiated. The allegation that staff withheld food is Unsubstantiated.') !== array(),
    'CA: the verdict may sit in the same sentence as the harm');
check($ca_mixed('Staff slapped a client in care. LPA reviewed the file. LPA interviewed C1. LPA interviewed S1. This allegation is Substantiated. The food allegation is Unsubstantiated.') === array(),
    'CA: a verdict more than three sentences after the harm does not cover it');
check($ca_mixed('The allegation that staff sexually abused a minor in care cannot be substantiated because the staff did not work that shift.') === array(),
    'CA: "cannot be substantiated" is unsubstantiated');
check($ca_mixed('Interviews did not substantiate the allegation that staff hit a youth in care.') === array(),
    'CA: "did not substantiate" is unsubstantiated');
check($ca_mixed('Staff physically abused a client in care. Based on the interviews the allegation is inconclusive.') === array(),
    'CA: an inconclusive complaint is not queued');
$ca_inc = $ca;
$ca_inc['categories_json'] = json_encode(array('complaint_status' => 'inconclusive',
    'investigation_findings' => 'Staff physically abused a client in care. The evidence gathered did not settle the matter.'));
check(kop_ih_candidates('CA', $ca_inc) === array(), 'CA: a complaint the state filed as inconclusive is not queued');

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

// Dates: the reports table holds them as text, in several forms.
$date_cases = array(
    '10/02/2023' => '2023-10-02', '3/23/25' => '2025-03-23', 'April 25, 2025' => '2025-04-25',
    '9/13/2023 - 9/14/2023' => '2023-09-13', '2024-07-01 00:00:00' => '2024-07-01', 'Sept. 3, 2021' => '2021-09-03',
    '' => null, 'unknown' => null, '13/45/2023' => null, '01/01/1900' => null, '01/01/2999' => null,
);
foreach ($date_cases as $text => $want) {
    check(kop_ih_parse_date($text) === $want, 'date: "' . $text . '" expected ' . var_export($want, true) . ' got ' . var_export(kop_ih_parse_date($text), true));
}
kop_ih_store($mem, 'TX', $tx, $cands);
check($mem->query('SELECT finding_date FROM inspection_highlights WHERE report_id = 1')->fetchColumn() === null, 'store: a report with no date leaves finding_date empty');
$tx_dated = array('id' => 21, 'facility_id' => 5, 'report_date' => '10/02/2023') + $tx;
kop_ih_store($mem, 'TX', $tx_dated, kop_ih_candidates('TX', $tx_dated));
check($mem->query('SELECT finding_date FROM inspection_highlights WHERE report_id = 21')->fetchColumn() === '2023-10-02', 'store: the report date is saved in a form that sorts');

// What the site shows: approved and severe only, most recent first.
$site = new PDO('sqlite::memory:');
$site->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
kop_ih_ensure_tables($site);
$site->exec('CREATE TABLE inspection_facilities (id INTEGER PRIMARY KEY, state TEXT, facility_name TEXT)');
$site->exec('CREATE TABLE inspection_reports (id INTEGER PRIMARY KEY, facility_id INTEGER, report_id TEXT, report_date TEXT, report_url TEXT)');
$site->exec("INSERT INTO inspection_facilities VALUES (1, 'TX', 'Example Ranch')");
$site_rows = array(
    // id, report date, narrative, risk level, status
    array(11, '01/15/2020', 'A child in care died after being restrained by two staff.', 'High', 'approved'),
    array(12, '06/01/2026', 'Staff punched a resident in the face during an argument.', 'High', 'approved'),
    array(13, 'March 3, 2025', 'Staff engaged in a sexual relationship with a 16-year-old resident.', 'High', 'approved'),
    array(14, '08/01/2026', 'Staff slapped a resident during an argument.', 'High', 'pending'),
    array(15, '07/01/2026', 'The child was taken to the hospital by ambulance after a fall.', 'High', 'approved'),
    array(16, 'unknown', 'A child in care died in a vehicle accident while staff drove.', 'High', 'approved'),
);
foreach ($site_rows as $sr) {
    $site->prepare('INSERT INTO inspection_reports VALUES (?, 1, ?, ?, ?)')->execute(array($sr[0], 'r' . $sr[0], $sr[1], ''));
    $report = array('id' => $sr[0], 'facility_id' => 1, 'report_date' => $sr[1],
        'categories_json' => json_encode(array('Standard Risk Level' => $sr[3], 'Deficiency Narrative' => $sr[2])));
    kop_ih_store($site, 'TX', $report, kop_ih_candidates('TX', $report));
    $site->prepare('UPDATE inspection_highlights SET status = ? WHERE report_id = ?')->execute(array($sr[4], $sr[0]));
}
$shown = $site->query(kop_ih_recent_severe_sql(10))->fetchAll(PDO::FETCH_ASSOC);
$order = array_map(static function ($r) { return (int) $r['report_id']; }, $shown);
check($order === array(12, 13, 11, 16), 'site: approved severe findings, most recent first, undated last; got [' . implode(',', $order) . ']');
check(!in_array(14, $order, true), 'site: a pending finding is never shown, however recent');
check(!in_array(15, $order, true), 'site: an approved finding below the severe score is not highlighted');
check(count($site->query(kop_ih_recent_severe_sql(2))->fetchAll()) === 2, 'site: the limit holds');
// The Severe Reports page and the tracker flags: all of them, not the newest few.
$run = static function (array $q) use ($site) {
    $stmt = $site->prepare($q[0]);
    $stmt->execute($q[1]);
    return array_map(static function ($r) { return (int) $r['report_id']; }, $stmt->fetchAll(PDO::FETCH_ASSOC));
};
check($run(kop_ih_severe_query()) === array(12, 13, 11, 16), 'severe page: every approved severe finding, most recent first');
check($run(kop_ih_severe_query('tx')) === array(12, 13, 11, 16) && $run(kop_ih_severe_query('CA')) === array(), 'severe page: the state filter');
check($run(kop_ih_severe_query('', 'death')) === array(11, 16), 'severe page: the category filter');
check($run(kop_ih_severe_query('', 'physical_abuse')) === array(12), 'severe page: a category matches a whole entry of the list');
check($run(kop_ih_severe_query('', 'not-a-category')) === array(12, 13, 11, 16), 'severe page: an unknown category filters nothing');
check($run(kop_ih_severe_query('', '', 2, 1)) === array(13, 11), 'severe page: limit and offset page through the list');
check(kop_ih_flag_needle("Staff  punched a Resident.\nIn the face. [...] Later text.") === 'staffpunchedaresident.intheface.', 'flag needle: the first run of the excerpt, lower-cased, spaces removed');
check(mb_strlen(kop_ih_flag_needle(str_repeat('abcdefghij ', 40))) === 160, 'flag needle: capped at 160 characters');

check(kop_ih_card_excerpt(str_repeat('word ', 100), 50) === 'word word word word word word word word word word [...]', 'site: a long excerpt is cut at a word and the cut is marked');

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
