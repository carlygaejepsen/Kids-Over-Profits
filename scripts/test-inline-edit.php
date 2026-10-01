<?php
/**
 * Offline checks for edit in place (inc/inline-edit.php) against the
 * production mirror in tmp/prod.sqlite.
 *
 *   php scripts/test-inline-edit.php [--db=tmp/prod.sqlite] [--limit=N]
 *
 * Every facility's "every field" dialog is sent back unchanged, the way
 * js/inline-edit.js sends it, and the saved record must come out exactly as a
 * save with no edit would. Then real edits (names, staff rows, checklists,
 * numbers, ownership) must land and pass the facility validator, and bad
 * input must be refused. Settings text overrides and the public markup (no
 * pencils for visitors) are checked too. The MySQL write and lock are not
 * exercised here.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'limit::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$limit = isset($args['limit']) ? (int) $args['limit'] : 0;
if (!file_exists($db_path)) {
    fwrite(STDERR, "Need $db_path (scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() { return false; }
}
require_once dirname(__DIR__) . '/inc/inline-edit.php';

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

/** A field's value as the dialog posts it back untouched. */
$as_posted = function (array $f) {
    switch ($f['type']) {
        case 'items':
        case 'checks':
            return array_values($f['value']);
        case 'rows':
            $cols = $f['columns'];
            $types = $f['column_types'] ?? array();
            $out = array();
            foreach ($f['value'] as $row) {
                $r = array('__i' => (string) $row['__i']);
                foreach ($cols as $k => $l) {
                    $x = $row[$k] ?? '';
                    $r[$k] = ($types[$k] ?? '') === 'bool' ? ($x === true || $x === '1' || $x === 'true') : (is_bool($x) ? ($x ? '1' : '') : (string) $x);
                }
                $out[] = $r;
            }
            return $out;
        default:
            return (string) $f['value'];
    }
};

/** Apply posted values as kop_ie_facility_save() does, without the database. */
$apply = 'kop_ie_facility_apply_values';

$first_diff = function ($a, $b, $path = '') use (&$first_diff) {
    if (is_array($a) && is_array($b)) {
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            if (!array_key_exists($k, $a) || !array_key_exists($k, $b)) return $path . '.' . $k . ' (missing on one side)';
            $d = $first_diff($a[$k], $b[$k], $path . '.' . $k);
            if ($d !== '') return $d;
        }
        return '';
    }
    return $a === $b ? '' : $path . ': ' . json_encode($a) . ' vs ' . json_encode($b);
};

echo "-- Every facility, every field, sent back unchanged --\n";
$sql = 'SELECT id, json_data FROM facilities_v2 ORDER BY id' . ($limit > 0 ? ' LIMIT ' . $limit : '');
$total = $changed = $errors = 0;
$examples = array();
$groups = array_keys(kop_ie_facility_groups());
foreach ($pdo->query($sql) as $row) {
    $doc = json_decode((string) $row['json_data'], true);
    if (!is_array($doc)) continue;
    $total++;
    try {
        $fields = kop_ie_facility_group_fields($doc, 'all');
        $values = array();
        foreach ($fields as $f) {
            $values[$f['name']] = $as_posted($f);
        }
        $after = $normalize($apply($doc, 'all', $values));
        $before = $normalize($doc);
        $diff = $first_diff($before, $after);
        if ($diff !== '') {
            $changed++;
            if (count($examples) < 8) $examples[] = '#' . $row['id'] . ' ' . $diff;
        }
        // Each group on its own too (a pencil opens one group).
        foreach ($groups as $g) {
            $vals = array();
            foreach (kop_ie_facility_group_fields($doc, $g) as $f) $vals[$f['name']] = $as_posted($f);
            if ($first_diff($before, $normalize($apply($doc, $g, $vals))) !== '') {
                $changed++;
                if (count($examples) < 8) $examples[] = '#' . $row['id'] . " group $g";
            }
        }
    } catch (Throwable $e) {
        $errors++;
        if (count($examples) < 8) $examples[] = '#' . $row['id'] . ' ' . $e->getMessage();
    }
}
$check("$total records round-trip unchanged", $changed === 0 && $errors === 0, $changed . ' changed, ' . $errors . ' errors' . ($examples ? ': ' . implode('; ', $examples) : ''));

echo "-- Real edits land --\n";
$fid = 0;
foreach ($pdo->query("SELECT id, json_data FROM facilities_v2 WHERE json_data LIKE '%notableStaff%' AND json_data LIKE '%pastNames%' ORDER BY id") as $row) {
    $d = json_decode((string) $row['json_data'], true);
    if (!empty($d['staff']['notableStaff'][0]['name']) && !empty($d['identification']['pastNames'])) {
        $fid = (int) $row['id'];
        break;
    }
}
$check('a record with staff and past names exists', $fid > 0, "#$fid");
if ($fid) {
    $doc = json_decode((string) $pdo->query('SELECT json_data FROM facilities_v2 WHERE id = ' . $fid)->fetchColumn(), true);
    $vals = array();
    foreach (kop_ie_facility_group_fields($doc, 'all') as $f) $vals[$f['name']] = $as_posted($f);
    $vals['identification.name'] = 'Renamed Test Academy';
    $past = kop_ie_lines($vals['identification.pastNames']);
    $dropped = array_shift($past);
    $vals['identification.pastNames'] = implode("\n", array_merge($past, array('Brand New Past Name')));
    $vals['staff.notableStaff'][] = array('__i' => '', 'name' => 'Jane Example', 'role' => 'Clinical director', 'pastJobs' => 'Elsewhere Ranch');
    $kept_person = $vals['staff.notableStaff'][0];
    $vals['staff.notableStaff'][0]['role'] = 'Edited role';
    $vals['treatmentTypes'][] = 'hasEquineTherapy';
    $vals['facilityDetails.capacity'] = '48';
    $vals['facilityDetails.isPrivatelyOwned'] = 'yes';
    $vals['operatingPeriod.endYear'] = '2019';
    $vals['operatingPeriod.status'] = 'Closed';
    $vals['location.state'] = 'ut';
    $out = $normalize($apply($doc, 'all', $vals));
    $check('name changed', $out['identification']['name'] === 'Renamed Test Academy');
    $check('past name removed and one added', !in_array($dropped, $out['identification']['pastNames'], true) && in_array('Brand New Past Name', $out['identification']['pastNames'], true));
    $staff = $out['staff']['notableStaff'];
    $last = end($staff);
    $check('staff row added', $last['name'] === 'Jane Example' && $last['role'] === 'Clinical director' && $last['pastJobs'] === 'Elsewhere Ranch');
    $check('staff row edited, its name kept', $staff[0]['role'] === 'Edited role' && $staff[0]['name'] === $kept_person['name']);
    $check('checklist ticked', ($out['treatmentTypes']['hasEquineTherapy'] ?? null) === true);
    $check('capacity is a number', $out['facilityDetails']['capacity'] === 48);
    $check('ownership yes -> true', $out['facilityDetails']['isPrivatelyOwned'] === true);
    $check('closed in 2019', $out['operatingPeriod']['status'] === 'Closed' && (int) $out['operatingPeriod']['endYear'] === 2019);
    $check('state changed and upper-cased', $out['location']['state'] === 'UT', (string) $out['location']['state']);
    $check('address line rebuilt from its parts', strpos($out['location']['raw'], 'UT') !== false && $out['location']['text'] === '', $out['location']['raw']);

    // An extra address's street edited: its line follows.
    $doc5 = $doc;
    $doc5['location']['additionalLocations'] = array(array('raw' => '1 Old Rd, Provo, UT 84601', 'street' => '1 Old Rd', 'city' => 'Provo', 'state' => 'UT', 'zip' => '84601', 'country' => 'United States'));
    $f5 = null;
    foreach (kop_ie_facility_group_fields($doc5, 'location') as $f) if ($f['name'] === 'location.additionalLocations') $f5 = $f;
    $check('raw line is not a column', $f5 && !isset($f5['columns']['raw']));
    $rows = $as_posted($f5);
    $rows[0]['street'] = '9 New Ave';
    $out5 = $normalize($apply($doc5, 'location', array('location.additionalLocations' => $rows)));
    $alt = $out5['location']['additionalLocations'][0] ?? array();
    $check('extra address street edited sticks', ($alt['street'] ?? '') === '9 New Ave' && strpos($alt['raw'] ?? '', '9 New Ave') === 0, json_encode($alt));
    $bad = array_filter(kop_facility_validate($out), function ($v) { return $v['severity'] === 'error'; });
    $check('edited record passes the validator', !$bad, $bad ? json_encode(array_values($bad)[0]) : '');

    // Removing every staff row and every note.
    $vals2 = array('staff.notableStaff' => array(), 'staff.administrator' => array(), 'notes' => array());
    $out2 = $normalize($apply($doc, 'staff', $vals2 + array()));
    $check('staff cleared', !$out2['staff']['notableStaff'] && !$out2['staff']['administrator']);
    $vals3 = array('facilityDetails.isPrivatelyOwned' => '');
    $out3 = $normalize($apply($doc, 'details', $vals3));
    $check('ownership cleared -> null', $out3['facilityDetails']['isPrivatelyOwned'] === null);

    // Unticking keeps the key, false.
    $doc4 = $doc;
    $doc4['philosophy'] = array('has12Steps' => true, 'customPhilosophy' => array('Something else'));
    $out4 = $normalize($apply($doc4, 'practices', array('philosophy' => array())));
    $check('unticked becomes false, custom entries kept', ($out4['philosophy']['has12Steps'] ?? null) === false && !empty($out4['philosophy']['customPhilosophy']));
}

echo "-- Every parent company sent back unchanged --\n";
$ops = $changed = 0;
$examples = array();
foreach ($pdo->query('SELECT id, json_data FROM wpdl_kop_operators ORDER BY id') as $row) {
    $json = json_decode((string) $row['json_data'], true);
    $op = $json['operator'] ?? array();
    $ops++;
    $vals = array();
    foreach (kop_ie_operator_form($op) as $f) $vals[$f['name']] = $as_posted($f);
    $diff = $first_diff($op, kop_ie_operator_apply_values($op, $vals));
    if ($diff !== '') {
        $changed++;
        if (count($examples) < 5) $examples[] = '#' . $row['id'] . ' ' . $diff;
    }
}
$check("$ops company records round-trip unchanged", $ops > 0 && $changed === 0, $changed . ' changed' . ($examples ? ': ' . implode('; ', $examples) : ''));
$op = array('keyStaff' => array('ceo' => 'A', 'founders' => array(array('name' => 'Old', 'role' => 'Founder', 'pastJobs' => ''))), 'otherNames' => array());
$vals = array();
foreach (kop_ie_operator_form($op) as $f) $vals[$f['name']] = $as_posted($f);
$vals['keyStaff.ceo'] = 'New Boss';
$vals['otherNames'] = "Short Name\nOther Name";
$vals['keyStaff.founders'][0]['pastJobs'] = 'Somewhere';
$out = kop_ie_operator_apply_values($op, $vals);
$check('company edits land', $out['keyStaff']['ceo'] === 'New Boss' && $out['otherNames'] === array('Short Name', 'Other Name')
    && $out['keyStaff']['founders'][0]['name'] === 'Old' && $out['keyStaff']['founders'][0]['pastJobs'] === 'Somewhere');

echo "-- Every referrer record sent back unchanged --\n";
$refs = $changed = 0;
$examples = array();
foreach ($pdo->query('SELECT id, json_data FROM referrers_master ORDER BY id') as $row) {
    $json = json_decode((string) $row['json_data'], true);
    if (!is_array($json)) continue;
    $refs++;
    $vals = array();
    foreach (kop_ie_referrer_form($json) as $f) $vals[$f['name']] = $as_posted($f);
    $diff = $first_diff($json, kop_ie_referrer_apply_values($json, $vals));
    if ($diff !== '') {
        $changed++;
        if (count($examples) < 5) $examples[] = '#' . $row['id'] . ' ' . $diff;
    }
}
$check("$refs referrer records round-trip unchanged", $refs > 0 && $changed === 0, $changed . ' changed' . ($examples ? ': ' . implode('; ', $examples) : ''));
$r = array('data' => array('referrerAgency' => array('name' => 'A'), 'referrerConsultants' => array(array('fullName' => 'Jo Doe', 'pastTTIJobs' => array(), 'isIndependent' => false))));
$vals = array();
foreach (kop_ie_referrer_form($r) as $f) $vals[$f['name']] = $as_posted($f);
$vals['data.referrerConsultants.0.formerIndustryStaff'] = true;
$vals['data.referrerConsultants.0.pastTTIJobs'] = array(array('__i' => '', 'role' => 'Admissions', 'organization' => 'Some Ranch', 'employer' => ''));
$vals['data.referrerAgency.name'] = 'B';
$out = kop_ie_referrer_apply_values($r, $vals);
$c0 = $out['data']['referrerConsultants'][0];
$check('referrer edits land', $out['data']['referrerAgency']['name'] === 'B' && $c0['formerIndustryStaff'] === true && $c0['pastTTIJobs'][0]['organization'] === 'Some Ranch' && $c0['isIndependent'] === false);

echo "-- Memorial and story arc rows sent back unchanged (in-memory copy) --\n";
$mem = new PDO('sqlite::memory:');
$mem->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (array('memorial_victims', 'news_story_arcs') as $tbl) {
    $sqlc = $pdo->query("SELECT sql FROM sqlite_master WHERE name = '$tbl'")->fetchColumn();
    if (!$sqlc) continue;
    $mem->exec($sqlc);
    foreach ($pdo->query("SELECT * FROM $tbl")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cols = array_keys($r);
        $mem->prepare("INSERT INTO $tbl (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($r));
    }
}
if (!function_exists('kop_seed_pdo')) {
    function kop_seed_pdo() { return $GLOBALS['kop_test_mem_pdo']; }
}
$GLOBALS['kop_test_mem_pdo'] = $mem;
foreach (array('memorial' => 'memorial_victims', 'arc' => 'news_story_arcs') as $key => $tbl) {
    $before = $mem->query("SELECT * FROM $tbl ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $errs = array();
    foreach ($before as $r) {
        try {
            $spec = kop_ie_row_load(array($key, $r['id']));
            $vals = array();
            foreach ($spec['fields'] as $f) $vals[$f['name']] = $as_posted($f);
            kop_ie_row_save(array($key, $r['id']), $vals);
        } catch (Throwable $e) {
            if (count($errs) < 3) $errs[] = '#' . $r['id'] . ' ' . $e->getMessage();
        }
    }
    $after = $mem->query("SELECT * FROM $tbl ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $diff = $first_diff($before, $after);
    $check(count($before) . " $tbl rows round-trip unchanged", !$errs && $diff === '', implode('; ', $errs) . $diff);
}
$id = (int) $mem->query('SELECT id FROM memorial_victims ORDER BY id LIMIT 1')->fetchColumn();
if ($id) {
    kop_ie_row_save(array('memorial', $id), array('age' => '15', 'date_of_death' => '1998-03-01', 'cause_category' => 'restraint'));
    $r = $mem->query("SELECT age, date_of_death, cause_category FROM memorial_victims WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    $check('memorial edit lands', (int) $r['age'] === 15 && $r['date_of_death'] === '1998-03-01' && $r['cause_category'] === 'restraint');
    $bad = function ($v) use ($id) { try { kop_ie_row_save(array('memorial', $id), $v); return false; } catch (RuntimeException $e) { return true; } };
    $check('bad date, cause or empty name refused', $bad(array('date_of_death' => '1998-02-31')) && $bad(array('cause_category' => 'murder')) && $bad(array('name' => '  ')));
}

echo "-- Bad input refused --\n";
$refused = function ($group, $values) use ($doc, $apply) {
    try {
        $apply($doc, $group, $values);
        return false;
    } catch (RuntimeException $e) {
        return true;
    }
};
$check('a year of 3020 refused', $refused('status', array('operatingPeriod.endYear' => '3020')));
$check('a capacity of "lots" refused', $refused('details', array('facilityDetails.capacity' => 'lots')));
$check('an unknown status refused', $refused('status', array('operatingPeriod.status' => 'Gone')));
$check('a former location year refused', $refused('location', array('location.formerLocations' => array(array('__i' => '', 'city' => 'Provo', 'fromYear' => 'soon')))));

echo "-- Settings text --\n";
$cfg = array('standfirst' => 'One.', 'updated' => 'kop_hub_law_policy_updated_at', 'actions' => array(array('label' => 'Go', 'slug' => 'x', 'url' => 'https://a.test/')),
    'reading_notes' => array('overview' => 'Note.'));
$strings = kop_ie_cfg_strings($cfg);
$check('every text line listed, slugs and callbacks left out',
    array_keys($strings) === array('standfirst', 'actions.0.label', 'actions.0.url', 'reading_notes.overview'), implode(', ', array_keys($strings)));
$check('labels read as a path', kop_ie_cfg_label('actions.0.label') === 'Actions › #1 › Label');
$cfg2 = $cfg;
kop_ie_set_path($cfg2, 'reading_notes.overview', 'Changed.');
$check('a nested line can be replaced', $cfg2['reading_notes']['overview'] === 'Changed.' && $cfg2['standfirst'] === 'One.');

echo "-- Visitors see no pencils --\n";
$check('kop_ie_attr is empty for a visitor', kop_ie_attr('pt:faq:intro', 'Intro') === '');
require_once dirname(__DIR__) . '/inc/page-text.php';
$html = kop_page_text_section_html(array('key' => 'intro', 'label' => 'Intro', 'style' => 'plain', 'heading' => 'Hi', 'body' => 'Text.'), 'kop-faq', 'faq');
$check('page text section has no edit marker', strpos($html, 'data-kop-edit') === false && strpos($html, '<section id="kop-faq-intro" aria-labelledby') === 0, substr($html, 0, 80));

ob_start();
kop_ie_html_start('t:block', 'Block');
echo '<section><h2>Hi</h2></section>';
kop_ie_html_end();
$check('a template block prints as written for a visitor', ob_get_clean() === '<section><h2>Hi</h2></section>');
$check('a template line prints as written for a visitor', kop_text('t:line', 'Default line') === 'Default line');

// The legal pages, rendered as a visitor sees them: every section, no markers.
foreach (array('templates/page-privacy-policy.php' => 8, 'templates/page-terms-of-service.php' => 10) as $tpl => $sections) {
    if (!function_exists('wp_enqueue_style')) { function wp_enqueue_style() {} }
    if (!function_exists('antispambot')) { function antispambot($s) { return $s; } }
    ob_start();
    include dirname(__DIR__) . '/' . $tpl;
    $html = ob_get_clean();
    $check(basename($tpl) . ' renders every section, no markers',
        substr_count($html, '<section>') === $sections && substr_count($html, '</section>') === $sections && strpos($html, 'data-kop-edit') === false && strpos($html, 'kop-ie-block') === false,
        substr_count($html, '<section>') . ' sections');
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
