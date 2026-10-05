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
require_once dirname(__DIR__) . '/inc/facility-finder.php';
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
$applied = $refused = $invalid = $lost = $undo_bad = $twice_bad = $unsourced = 0;
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
            $mine = array_filter((array) $at, function ($s) use ($v) { return is_array($s) && kop_wbf_person_key($s['name'] ?? '') === kop_wbf_person_key($v['name']); });
            $ok = (bool) $mine;
            // The entry names where it came from, through the save normalizer.
            if ($mine && (trim((string) (reset($mine)['source'] ?? '')) === '' || !preg_match('#^https?://#', (string) (reset($mine)['sourceUrl'] ?? '')))) {
                $unsourced++;
                if (count($examples) < 12) $examples[] = "#$fid staff without a source: " . $v['name'];
            }
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
$check('every staff entry added or filled names its source and link', $unsourced === 0, "$unsourced without");
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
    'Substance Abuse Treatment', 'Eating Disorder Treatment Center', 'Maternity Home', 'Fundamentalist Religious Home', 'Specialty Boarding School',
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

echo "-- Edit before adding --\n";
// Every item sent back through its own Edit form unchanged gives the same value and label.
$no_form = $value_diff = $label_diff = 0;
$ex = array();
foreach ($data['proposals'] as $p) {
    $r = array('pkey' => $p['key'], 'op' => $p['op'], 'path' => $p['path'], 'value' => json_encode($p['value']), 'label' => $p['label'],
        'extra' => '{}', 'status' => 'pending');
    $fields = kop_wbf_edit_fields($r);
    if (!$fields) {
        $no_form++;
        if (count($ex) < 10) $ex[] = "no form: {$p['op']} {$p['path']}";
        continue;
    }
    $f = array();
    foreach ($fields as $fd) $f[$fd[0]] = $fd[3];
    try {
        list($v, $path) = kop_wbf_edited_value($r, $f);
    } catch (RuntimeException $e) {
        $value_diff++;
        if (count($ex) < 10) $ex[] = "refused as read: {$p['path']} " . $e->getMessage() . ' ' . substr(json_encode($p['value']), 0, 80);
        continue;
    }
    if (json_encode($v) !== json_encode($p['value']) || $path !== $p['path']) {
        $value_diff++;
        if (count($ex) < 10) $ex[] = "value changed: {$p['path']} " . substr(json_encode($p['value']), 0, 90) . ' -> ' . substr(json_encode($v), 0, 90);
    }
    if (kop_wbf_label_for($r, $v) !== $p['label']) {
        $label_diff++;
        if (count($ex) < 10) $ex[] = "label: \"{$p['label']}\" -> \"" . kop_wbf_label_for($r, $v) . '"';
    }
}
$check('every item has an Edit form', $no_form === 0, "$no_form without");
$check('saving an item unchanged keeps its value', $value_diff === 0, "$value_diff differ");
$check('saving an item unchanged keeps its label', $label_diff === 0, "$label_diff differ");
foreach ($ex as $e) echo "    $e\n";

// Bad input is refused with a reason.
$refuse = function ($op, $path, $value, $f) {
    try {
        kop_wbf_edited_value(array('op' => $op, 'path' => $path, 'value' => json_encode($value), 'label' => ''), $f);
        return false;
    } catch (RuntimeException $e) {
        return $e->getMessage() !== '';
    }
};
$check('a year that is not a year is refused', $refuse('set_if_empty', 'operatingPeriod.startYear', 2006, array('year' => '206')));
$check('ages the wrong way round are refused', $refuse('set_if_empty', 'facilityDetails.ageRange', array('min' => 13, 'max' => 18), array('min' => '18', 'max' => '12')));
$check('a gender outside the three is refused', $refuse('set_if_empty', 'facilityDetails.gender', 'Male', array('gender' => 'Boys')));
$check('a staff member needs a name', $refuse('add_staff', 'staff.notableStaff', array('name' => 'A B', 'role' => '', 'pastJobs' => ''), array('name' => ' ')));
$check('an empty note is refused', $refuse('add_list', 'notes', 'Owner: X', array('text' => '')));
$check('a job line needs a program', $refuse('consultant_jobs', 'referrer', array('name' => 'A B', 'jobs' => array()), array('name' => 'A B', 'jobs' => 'Director |  | 2001')));

// A corrected staff member, moved to the other list, applies and passes the validator.
$staff = null;
foreach ($by as $fid => $rows) {
    foreach ($rows as $row) {
        if ($row['op'] === 'add_staff' && $row['path'] === 'staff.notableStaff') {
            $staff = array($fid, $row);
            break 2;
        }
    }
}
if ($staff) {
    list($fid, $row) = $staff;
    list($v, $path) = kop_wbf_edited_value($row, array('name' => 'Corrected Person-Name', 'role' => 'Clinical Director (2009, Woodbury Reports)',
        'pastJobs' => '', 'where' => 'staff.administrator'));
    $as_read = $row;
    $row['value'] = json_encode($v);
    $row['path'] = $path;
    $stmt->execute(array($fid));
    $doc = $normalize(json_decode((string) $stmt->fetchColumn(), true));
    kop_wbf_doc_apply($doc, $row);
    $saved = $normalize($doc);
    $errors = array_filter(kop_facility_validate($saved), function ($v) { return $v['severity'] === 'error'; });
    $names = array_map(function ($s) { return is_array($s) ? ($s['name'] ?? '') : $s; }, (array) ($saved['staff']['administrator'] ?? array()));
    $check('a corrected, moved staff member lands on the chosen list', in_array('Corrected Person-Name', $names, true) && !$errors, "facility #$fid");
    $check('the label follows the correction', preg_replace('/ \(died [^()]*\)$/', '', kop_wbf_label_for($as_read, $v)) === 'Corrected Person-Name, Clinical Director (2009, Woodbury Reports)');
}
$died = array('op' => 'add_staff', 'path' => 'staff.administrator', 'label' => 'Jack Eckerd, Founder (2004, Woodbury Reports) (died 2004-05-19)',
    'value' => json_encode(array('name' => 'Jack Eckerd', 'role' => 'Founder (2004, Woodbury Reports)', 'pastJobs' => '')));
$check('a corrected role keeps "died"', kop_wbf_label_for($died, array('name' => 'Jack Eckerd', 'role' => 'Owner', 'pastJobs' => ''))
    === 'Jack Eckerd, Owner (died 2004-05-19)');
$owner = array('op' => 'add_list', 'path' => 'identification.pastOperators', 'label' => 'Operator/owner: UHS (2005-12-20)', 'value' => json_encode('UHS'));
$check('a corrected owner keeps its date', kop_wbf_label_for($owner, 'Universal Health Services') === 'Operator/owner: Universal Health Services (2005-12-20)');
$check('plain text drops the date and citation',
    kop_wbf_plain_text('2006-09-12: Injury: A boy was hurt. (Woodbury Reports, October 2006, p. 29)') === 'Injury: A boy was hurt.');

echo "-- Another source: HEAL's archived pages --\n";
$heal_ev = array(array('issue' => 'heal-tcut', 'label' => 'staff list for Teen Challenge of Utah', 'number' => '', 'issue_id' => 0, 'page' => 1,
    'url' => 'https://web.archive.org/web/20120517072958/http://www.heal-online.org/tcut.htm', 'quote' => 'Ken Summers | Executive Director',
    'found' => true, 'pub' => 'HEAL', 'cite' => 'HEAL, staff list for Teen Challenge of Utah (archived 2012-05-17)', 'date' => '2012-05'));
$heal_row = array('label' => 'Ken Summers, Executive Director (2012, HEAL)', 'evidence' => json_encode($heal_ev));
$check('a HEAL item cites HEAL and its archived copy', kop_wbf_cite($heal_row)
    === 'HEAL, staff list for Teen Challenge of Utah (archived 2012-05-17): https://web.archive.org/web/20120517072958/http://www.heal-online.org/tcut.htm');
$check('the screen names the archived copy, not a page number', kop_wbf_ev_label($heal_ev[0]) === 'HEAL, staff list for Teen Challenge of Utah (archived 2012-05-17)');
$hsrc = kop_wbf_create_source($heal_ev);
$check('a record created from it names HEAL', ($hsrc['cite'] ?? '') === $heal_ev[0]['cite'] && ($hsrc['url'] ?? '') === $heal_ev[0]['url']);
$check('plain text drops a HEAL citation',
    kop_wbf_plain_text('Owner: Teen Challenge (HEAL, staff list for X (archived 2012-05-17))') === 'Owner: Teen Challenge');

echo "-- Another person in the same words --\n";
// The AJAX handlers keep a field's case: "pastJobs", "endYear" once came through as "pastjobs".
$posted = array();
foreach (array('name' => 'Pat Q. Smith', 'role' => 'Therapist', 'pastJobs' => 'Aspen Ranch', 'where' => 'staff.administrator') as $k => $v) {
    $posted[preg_replace('/[^A-Za-z]/', '', (string) $k)] = $v;
}
$shape = array('op' => 'add_staff', 'path' => 'staff.notableStaff', 'value' => 'null', 'label' => '');
list($pv, $ppath) = kop_wbf_edited_value($shape, $posted);
$check('past jobs survive the posted field names', $pv['pastJobs'] === 'Aspen Ranch');
$check('the chosen list is kept', $ppath === 'staff.administrator');
$check('the label names the person and role', kop_wbf_label_for($shape, $pv) === 'Pat Q. Smith, Therapist');
$fid = (int) array_key_first($by);
$stmt->execute(array($fid));
$doc = $normalize(json_decode((string) $stmt->fetchColumn(), true));
$before = $doc;
$done = kop_wbf_doc_apply($doc, array('op' => 'add_staff', 'path' => $ppath, 'value' => json_encode($pv), 'label' => '', 'extra' => '{}', 'evidence' => '[]'));
$saved = $normalize($doc);
$names = array_map(function ($s) { return is_array($s) ? ($s['name'] ?? '') : $s; }, (array) ($saved['staff']['administrator'] ?? array()));
$errors = array_filter(kop_facility_validate($saved), function ($v) { return $v['severity'] === 'error'; });
$check('a hand-added person lands on the record', in_array('Pat Q. Smith', $names, true) && !$errors, "facility #$fid");
kop_wbf_doc_undo($doc, $done);
$check('and undo takes them off again', json_encode($normalize($doc)) === json_encode($normalize($before)));
$first_row = array_merge(array('grp' => 'staff'), $by[$fid][0]);
$check('a staff item offers "Another person"', strpos(kop_wbf_person_form($first_row), 'kop-wbf-addperson') !== false);
$check('a consultant item does not', kop_wbf_person_form(array_merge($first_row, array('grp' => 'consultant'))) === '');

echo "-- Another record, or a new one, from any card --\n";
$card = array('facility_id' => 5, 'program' => 'Aspen Ranch', 'program_as_written' => 'Aspen Ranch Utah', 'place' => 'Loa, Utah',
    'alternatives' => json_encode(array(array('id' => 5, 'name' => 'Matched', 'state' => 'UT'), array('id' => 7, 'name' => 'Other One', 'state' => 'UT'))));
$html = kop_wbf_other_record($card, false);
$check('a matched card can create a program record', strpos($html, 'data-act="create"') !== false && strpos($html, 'kop-wbf-ctype') !== false);
$check('the create starts from the name as Woodbury wrote it', strpos($html, 'value="Aspen Ranch Utah"') !== false);
$check('the create starts from the place', strpos($html, 'value="Loa"') !== false && strpos($html, 'value="Utah"') !== false);
$check('the current match is not offered as "another record"', strpos($html, 'Matched') === false && strpos($html, 'Other One') !== false);
$check('it has the facility finder', strpos($html, 'data-kop-facility-finder') !== false);
foreach (array('facility', 'consultant', 'person', 'provider') as $k) {
    $check("it can create a record of kind $k", strpos($html, '<option value="' . $k . '">') !== false);
}
$filed = array('applied' => json_encode(array('filed' => 'consultant', 'who' => 'firm', 'id' => 12, 'name' => 'Aldrich & Co')));
$check('an item filed on a consultant says where it went', kop_wbf_filed_on($filed) === 'the educational consultant record "Aldrich & Co" (#12)');
$check('an item added to a facility is not "filed"', kop_wbf_filed_on(array('applied' => json_encode(array('op' => 'add_staff')))) === '');

echo $failures ? "\n$failures FAILED\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
