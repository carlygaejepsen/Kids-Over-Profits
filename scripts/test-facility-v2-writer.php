<?php
/**
 * End-to-end test of the admin data form on the v2 model
 * (inc/facility-v2-writer.php, docs/DATA-MODEL-MIGRATION.md phase 4 step 5).
 *
 * DESTRUCTIVE: runs against a throwaway MySQL 8 loaded with a copy of the
 * production facility tables, never against production. It refuses to run on
 * a database named like the production one.
 *
 * Usage:
 *   php -d memory_limit=2G scripts/test-facility-v2-writer.php \
 *       --dsn "mysql:host=127.0.0.1;port=3399;dbname=kop;charset=utf8mb4" --user root [--password x] [--prefix wpdl_]
 *
 * Load the copy first (tables: facilities_master, locations_master,
 * facilities_v2, news_facility_links, lawsuit_facility_links and the
 * {prefix}kop_* tables), e.g. with mysqldump --single-transaction over SSH.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require_once __DIR__ . '/../inc/facility-v2-writer.php';

$args = getopt('', array('dsn:', 'user:', 'password::', 'prefix::'));
if (empty($args['dsn']) || !isset($args['user'])) {
    fwrite(STDERR, "Usage: --dsn <pdo dsn> --user <user> [--password <pw>] [--prefix wpdl_]\n");
    exit(2);
}
if (stripos($args['dsn'], 'kidsover_production') !== false) {
    fwrite(STDERR, "Refusing to run against the production database.\n");
    exit(2);
}
$pdo = new PDO($args['dsn'], $args['user'], $args['password'] ?? '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$prefix = $args['prefix'] ?? 'wpdl_';
$t = kop_migration_tables($prefix);

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
}
function scalar(PDO $pdo, $sql, array $params = array()) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}
/** Table contents fingerprint, independent of physical order. */
function table_sum(PDO $pdo, $table, $columns) {
    return scalar($pdo, "SELECT CONCAT(COUNT(*), ':', COALESCE(SUM(CRC32(CONCAT_WS('|', {$columns}))), 0)) FROM `{$table}`");
}
/** What the browser sends back: the loaded project through JSON. */
function via_json($value) {
    return json_decode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
}
function state_of(PDO $pdo) {
    global $t;
    return array(
        'facilities'  => table_sum($pdo, $t['facilities'], 'id, unique_name, json_data'),
        'memberships' => table_sum($pdo, $t['facility_locations'], 'facility_id, location_key, role, source, needs_review'),
        'operators'   => table_sum($pdo, $t['operators'], 'id, unique_name, name, document_folder_id'),
        'op_join'     => table_sum($pdo, $t['operator_facilities'], 'operator_id, facility_id, relationship, sort_order'),
    );
}
function save_project(PDO $pdo, $prefix, array $project) {
    // The form saves an operator project under its row name, a profile under its location key.
    $name = $project['category'] === 'locations' ? $project['name'] : $project['_uniqueName'];
    return kop_v2_save_form_project($pdo, $prefix, $name, via_json($project['data']), $project['category'], array('timestamp' => $project['timestamp'], 'currentFacilityIndex' => $project['currentFacilityIndex']));
}

// --- 0. Write switch -----------------------------------------------------------
check('write switch starts off', !kop_v2_writes_active($pdo, $prefix, true));
$legacy_before = kop_migration_fingerprint($pdo);
$switch = kop_v2_set_writes($pdo, $prefix, 'v2');
check('write switch turns on', kop_v2_writes_active($pdo, $prefix, true), json_encode($switch['final_sync']));
// A dump reload can shift updated_at (time zone), so the fingerprint may not
// match and the sync runs; it must still find nothing to write.
check('final sync found v2 up to date', !empty($switch['final_sync']['skipped'])
    || ((int)$switch['final_sync']['written'] === 0 && (int)$switch['final_sync']['removed'] === 0));
check('link repoints applied', $switch['link_repoints']['moved'] + $switch['link_repoints']['already'] === count(kop_migration_state_get($pdo, $prefix, 'link_repoints', array())), json_encode($switch['link_repoints']));
check('link repoints idempotent', kop_v2_apply_link_repoints($pdo, $prefix)['moved'] === 0);
$sync = kop_migration_sync($pdo, $prefix, true);
check('sync refuses once writes go to v2', !empty($sync['skipped']) && $sync['reason'] === 'admin saves write the v2 tables');

// --- 1. Load ---------------------------------------------------------------------
$started = microtime(true);
$projects = kop_v2_form_projects($pdo, $prefix);
$load_s = round(microtime(true) - $started, 2);
$ops = array_filter($projects, function ($p) { return $p['_sourceTable'] === 'facilities'; });
$locs = array_filter($projects, function ($p) { return $p['_sourceTable'] === 'locations'; });
check('load: one project per operator', count($ops) === (int)scalar($pdo, "SELECT COUNT(*) FROM `{$t['operators']}`"), count($ops) . " operators, {$load_s}s");
$loc_keys = (int)scalar($pdo, "SELECT COUNT(DISTINCT location_key) FROM `{$t['facility_locations']}` WHERE role <> 'unknown'");
check('load: every location with facilities has a profile', count(array_filter($locs, function ($p) { return count($p['data']['facilities']) > 0; })) === $loc_keys, count($locs) . " profiles");
$referrers = 0;
foreach ($locs as $p) $referrers += count((array)($p['data']['referrerConsultants'] ?? array()));
$stored_referrers = (int)scalar($pdo, "SELECT COALESCE(SUM(JSON_LENGTH(json_data, '$.data.referrerConsultants')), 0) FROM locations_master");
check('load: profile referrer consultants carried over', $referrers === $stored_referrers, "$referrers of $stored_referrers");
$missing_ids = 0;
foreach ($projects as $p) foreach ($p['data']['facilities'] as $f) if (empty($f['facility_id'])) $missing_ids++;
check('load: every facility carries its id', $missing_ids === 0, "$missing_ids without");

// --- 2. Saving every project back unchanged writes nothing ------------------------
$before = state_of($pdo);
$before['locations_master'] = table_sum($pdo, 'locations_master', 'id, unique_name, json_data, updated_at');
$totals = array('created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'unlinked' => 0, 'placed' => 0, 'removed_from_page' => 0);
$started = microtime(true);
$slowest = 0;
foreach ($projects as $key => $p) {
    $s = microtime(true);
    $r = save_project($pdo, $prefix, $p);
    $slowest = max($slowest, microtime(true) - $s);
    foreach ($totals as $k => $v) $totals[$k] += is_array($r[$k] ?? 0) ? count($r[$k]) : (int)($r[$k] ?? 0);
}
$after = state_of($pdo);
$after['locations_master'] = table_sum($pdo, 'locations_master', 'id, unique_name, json_data, updated_at');
check('round trip: no facility created, updated, unlinked or moved',
    $totals['created'] + $totals['updated'] + $totals['unlinked'] + $totals['placed'] + $totals['removed_from_page'] === 0,
    json_encode($totals) . ', ' . round(microtime(true) - $started, 1) . 's total, slowest ' . round($slowest, 2) . 's');
foreach ($before as $k => $v) check("round trip: $k table unchanged", $after[$k] === $v, $v . ' -> ' . $after[$k]);
check('round trip: legacy tables untouched except profile extras', scalar($pdo, "SELECT COUNT(*) FROM facilities_master") > 0);

// Pick an operator with several facilities for the edit tests.
$op_key = null;
foreach ($ops as $k => $p) {
    if (count($p['data']['facilities']) >= 3) { $op_key = $k; break; }
}
$op = $projects[$op_key];
$op_name = $op['_uniqueName'];
echo "     (edit tests use operator '{$op_name}', " . count($op['data']['facilities']) . " facilities)\n";

// --- 3. Edit one facility's address ----------------------------------------------
$edited = $op;
$f0 = $edited['data']['facilities'][0];
$fid0 = (int)$f0['facility_id'];
$edited['data']['facilities'][0]['address'] = '100 Test Rd, Boise, ID 83702';
$edited['data']['facilities'][0]['addressParts'] = array('street' => '100 Test Rd', 'city' => 'Boise', 'state' => 'ID', 'zip' => '83702');
$edited['data']['facilities'][0]['locationDetails']['city'] = 'Boise';
$edited['data']['facilities'][0]['locationDetails']['state'] = 'ID';
$edited['data']['facilities'][0]['location'] = 'Boise, ID';
$r = save_project($pdo, $prefix, $edited);
check('edit: exactly one facility updated', $r['updated'] === 1 && $r['created'] === 0, json_encode(array_intersect_key($r, $totals)));
$doc = kop_facility_load($fid0, array('pdo' => $pdo, 'prefix' => $prefix))['doc'];
check('edit: stored document has the new place', $doc['location']['state'] === 'ID' && $doc['location']['city'] === 'Boise');
check('edit: membership moved to IDAHO', (int)scalar($pdo, "SELECT COUNT(*) FROM `{$t['facility_locations']}` WHERE facility_id = ? AND location_key = 'IDAHO' AND role = 'current'", array($fid0)) === 1);
$kept_legacy = (int)scalar($pdo, "SELECT COUNT(*) FROM `{$t['facility_locations']}` WHERE facility_id = ? AND source = 'legacy_membership'", array($fid0));
echo "     (legacy_membership rows kept on it: $kept_legacy)\n";
$r = save_project($pdo, $prefix, $edited);
check('edit: saving again changes nothing', $r['updated'] === 0 && $r['created'] === 0);
check('edit: provenance kept', $doc['provenance'] === kop_facility_load($fid0, array('pdo' => $pdo, 'prefix' => $prefix))['doc']['provenance']);

// --- 4. New facility ----------------------------------------------------------------
$new = $edited;
$new['data']['facilities'][] = array(
    'identification' => array('name' => 'Writer Test Ranch'),
    'address' => '1 Ranch Rd, Kanab, UT 84741',
    'operatingPeriod' => array('status' => 'Open', 'startYear' => '2001'),
);
$new_index = count($new['data']['facilities']) - 1;
$r = save_project($pdo, $prefix, $new);
$new_id = $r['ids'][$new_index] ?? 0;
check('new: created with an id from 100000 up', $r['created'] === 1 && $new_id >= 100000, "id $new_id");
check('new: id recorded in the identity table', (int)scalar($pdo, "SELECT COUNT(*) FROM `{$t['identity']}` WHERE facility_id = ?", array($new_id)) === 1);
check('new: on the UTAH page', (int)scalar($pdo, "SELECT COUNT(*) FROM `{$t['facility_locations']}` WHERE facility_id = ? AND location_key = 'UTAH'", array($new_id)) === 1);
check('new: joined to the operator in form order', (int)scalar($pdo, "SELECT sort_order FROM `{$t['operator_facilities']}` WHERE facility_id = ?", array($new_id)) === $new_index);
$new_doc = kop_facility_load($new_id, array('pdo' => $pdo, 'prefix' => $prefix));
check('new: provenance names the operator project', $new_doc['doc']['provenance']['sourceProject'] === $op_name && $new_doc['unique_name'] === 'Writer Test Ranch', $new_doc['unique_name']);
check('new: start year typed', $new_doc['doc']['operatingPeriod']['startYear'] === 2001);
$r = save_project($pdo, $prefix, $new);   // browser did not stamp the id
check('new: resave without the id finds the same facility (no duplicate)', $r['created'] === 0 && ($r['ids'][$new_index] ?? 0) === $new_id);
$stamped = $new;
$stamped['data']['facilities'][$new_index]['facility_id'] = $new_id;
$r = save_project($pdo, $prefix, $stamped);
check('new: resave with the id is unchanged', $r['unchanged'] === count($stamped['data']['facilities']));

$other = $stamped;
$other['data']['facilities'][] = array('identification' => array('name' => 'Writer Test Ranch'), 'address' => '9 Other St, Bend, OR 97701');
$r = save_project($pdo, $prefix, $other);
$other_id = $r['ids'][count($other['data']['facilities']) - 1];
check('new: same name in another state is a different facility', $r['created'] === 1 && $other_id !== $new_id,
    kop_facility_load($other_id, array('pdo' => $pdo, 'prefix' => $prefix))['unique_name']);

$blank = $other;
$blank['data']['facilities'][] = array('identification' => array('name' => ''), 'address' => '');
$r = save_project($pdo, $prefix, $blank);
check('new: a blank template entry is skipped', count($r['skipped']) === 1 && $r['created'] === 0 && $r['unlinked'] === 0);

// --- 5. Remove facilities from the operator ------------------------------------------
$removed = $stamped;
array_splice($removed['data']['facilities'], $new_index, 1);
$r = save_project($pdo, $prefix, $removed);
check('remove: unlinked from the operator', $r['unlinked'] === 2, "unlinked {$r['unlinked']} (the test ranch and the Oregon one)");
check('remove: the facility itself is kept', kop_facility_load($new_id, array('pdo' => $pdo, 'prefix' => $prefix)) !== null);

// --- 6. Location profiles -----------------------------------------------------------
$projects = kop_v2_form_projects($pdo, $prefix);
$nevada = $projects['locations_NEVADA'];
$nv_count = count($nevada['data']['facilities']);
$add = $nevada;
$add['data']['facilities'][] = kop_facility_to_legacy(kop_facility_load($new_id, array('pdo' => $pdo, 'prefix' => $prefix))['doc']);
$r = save_project($pdo, $prefix, $add);
check('profile: listing a Utah facility on NEVADA places it there', $r['placed'] === 1 && $r['unchanged'] === $nv_count + 1, json_encode(array_intersect_key($r, $totals)));
$projects = kop_v2_form_projects($pdo, $prefix);
check('profile: reload shows it on both pages', count($projects['locations_NEVADA']['data']['facilities']) === $nv_count + 1
    && in_array($new_id, array_map(function ($f) { return (int)$f['facility_id']; }, $projects['locations_UTAH']['data']['facilities']), true));
$r = save_project($pdo, $prefix, $nevada);
check('profile: taking it off NEVADA removes the manual placement', $r['removed_from_page'] === 1 && !$r['still_on_page']);
$utah = $projects['locations_UTAH'];
$drop = $utah;
$drop['data']['facilities'] = array_values(array_filter($utah['data']['facilities'], function ($f) use ($new_id) { return (int)$f['facility_id'] !== $new_id; }));
$r = save_project($pdo, $prefix, $drop);
check('profile: taking it off UTAH keeps it (its address is in Utah) and says so', $r['removed_from_page'] === 0 && $r['still_on_page'] === array('Writer Test Ranch'), json_encode($r['still_on_page']));
$extra = $utah;
$extra['data']['referrerConsultants'][] = array('name' => 'Writer Test Consultant');
save_project($pdo, $prefix, $extra);
$profiles = kop_v2_location_profile_rows($pdo);
$names = array_map(function ($c) { return is_array($c) ? ($c['name'] ?? '') : $c; }, (array)$profiles['UTAH']['data']['referrerConsultants']);
check('profile: referrer consultants saved to locations_master', in_array('Writer Test Consultant', $names, true));
check('profile: frozen legacy facilities array left alone', count($profiles['UTAH']['data']['facilities']) > 0);

// --- 7. New operator, rename, delete -----------------------------------------------
$r = kop_v2_save_form_project($pdo, $prefix, 'Writer Test Holdings', array(
    'operator' => array('name' => 'Writer Test Holdings'),
    'facilities' => array(kop_facility_to_legacy(kop_facility_load($new_id, array('pdo' => $pdo, 'prefix' => $prefix))['doc'])),
), 'companies', array());
check('operator: new operator numbered from 500000', $r['operator_created'] && $r['operator_id'] >= KOP_V2_FIRST_NEW_OPERATOR_ID, "id {$r['operator_id']}");
check('operator: new operator linked to an existing facility without changing it', $r['unchanged'] === 1 && $r['created'] === 0);
$projects = kop_v2_form_projects($pdo, $prefix);
check('operator: listed on reload', isset($projects['facilities_Writer Test Holdings']) && count($projects['facilities_Writer Test Holdings']['data']['facilities']) === 1);
$rn = kop_v2_rename_operator($pdo, $prefix, 'Writer Test Holdings', $op_name);
check('rename: refuses a taken name', !$rn['renamed']);
$rn = kop_v2_rename_operator($pdo, $prefix, $op_name, $op_name . ' Renamed');
check('rename: renames and updates facility provenance', $rn['renamed'] && $rn['facilities_updated'] >= 1, json_encode($rn));
check('rename: new facility follows', kop_facility_load($new_id, array('pdo' => $pdo, 'prefix' => $prefix))['doc']['provenance']['sourceProject'] === $op_name . ' Renamed');
$del = kop_v2_delete_form_project($pdo, $prefix, 'Writer Test Holdings', false);
check('delete: operator removed, facility kept', $del['deleted'] && $del['facilities_kept'] === 1 && kop_facility_load($new_id, array('pdo' => $pdo, 'prefix' => $prefix)) !== null);
$del = kop_v2_delete_form_project($pdo, $prefix, 'UTAH', true);
check('delete: a profile with facilities is refused', !$del['deleted']);

// --- 8. Suggestion approval (partial save inside the caller's transaction) -----------
$projects = kop_v2_form_projects($pdo, $prefix);
$texas = $projects['locations_TEXAS'];
$tx_count = count($texas['data']['facilities']);
$one = $texas['data']['facilities'][0];
$one['facilityDetails']['capacity'] = 99;
unset($one['facility_id']);   // public suggestions may not carry ids
$before_ops = state_of($pdo)['operators'];
$pdo->beginTransaction();
$r = kop_v2_save_form_project($pdo, $prefix, 'TEXAS', via_json(array('facilities' => array($one))), 'locations', array('partial' => true));
$pdo->commit();
$projects = kop_v2_form_projects($pdo, $prefix);
check('suggestion: one facility updated by name and place', $r['updated'] === 1 && $r['created'] === 0, json_encode(array_intersect_key($r, $totals)));
check('suggestion: nothing else taken off the page', count($projects['locations_TEXAS']['data']['facilities']) === $tx_count && $r['removed_from_page'] === 0);
$cedu = $projects['facilities_' . $op_name . ' Renamed'];
$cedu_count = count($cedu['data']['facilities']);
$cedu_operator = $cedu['data']['operator'];
$pick = $cedu['data']['facilities'][0];
$pick['notes'] = array('suggested note');
$r = kop_v2_save_form_project($pdo, $prefix, $op_name . ' Renamed', via_json(array('facilities' => array($pick))), 'companies', array('partial' => true));
$projects = kop_v2_form_projects($pdo, $prefix);
$after = $projects['facilities_' . $op_name . ' Renamed'];
check('suggestion: operator keeps its other facilities and its operator block', count($after['data']['facilities']) === $cedu_count && $after['data']['operator'] == $cedu_operator && $r['unlinked'] === 0);

// --- 8b. Deploy seeds ----------------------------------------------------------------
$seeds_dir = dirname(__DIR__) . '/seeds/';
$records = json_decode((string)file_get_contents($seeds_dir . 'facility-records.json'), true);
$seed = $records[0];
check('seeds: an already-applied record seed is skipped', !kop_v2_apply_facility_record_seed($pdo, $prefix, $seed), "id {$seed['id']} version {$seed['version']}");
$seed['version'] = 99;
$seed['facility']['notes'] = array('seed test note');
check('seeds: a newer record seed is applied', kop_v2_apply_facility_record_seed($pdo, $prefix, $seed));
$seeded = kop_facility_load((int)$seed['id'], array('pdo' => $pdo, 'prefix' => $prefix))['doc'];
check('seeds: fields merged and version recorded', $seeded['notes'] === array('seed test note') && $seeded['provenance']['kopProfileVersion'] === 99);
check('seeds: the same version is not applied twice', !kop_v2_apply_facility_record_seed($pdo, $prefix, $seed));
$wrong = $seed;
$wrong['version'] = 100;
$wrong['unique_name'] = 'Somebody Else';
check('seeds: a seed naming the wrong row is refused', !kop_v2_apply_facility_record_seed($pdo, $prefix, $wrong));
$aliases = json_decode((string)file_get_contents($seeds_dir . 'operator-aliases.json'), true);
check('seeds: existing operator aliases are not added twice', kop_v2_add_operator_other_names($pdo, $prefix, $aliases[0]['id'], $aliases[0]['unique_name'], $aliases[0]['add_other_names']) === 0);
check('seeds: a new operator alias is added once', kop_v2_add_operator_other_names($pdo, $prefix, $aliases[0]['id'], $aliases[0]['unique_name'], array('Seed Test Alias')) === 1
    && kop_v2_add_operator_other_names($pdo, $prefix, $aliases[0]['id'], $aliases[0]['unique_name'], array('seed test alias')) === 0);
$new_seeds = json_decode((string)file_get_contents($seeds_dir . 'new-facilities.json'), true);
$before_new = state_of($pdo);
foreach ($new_seeds as $entry) {
    $r = kop_v2_save_form_project($pdo, $prefix, $entry['location_name'], array('facilities' => array($entry['facility'])), 'locations', array('partial' => true));
}
check('seeds: re-running the new-facility seeds creates nothing', state_of($pdo)['memberships'] === $before_new['memberships']
    && (int)scalar($pdo, "SELECT COUNT(*) FROM `{$t['facilities']}`") === (int)explode(':', $before_new['facilities'])[0]);

// --- 8c. The row shape the Data Manager, picker and wiki merge edit ------------------
$master_rows = kop_v2_pdo_master_rows($pdo, $prefix);
$expected_rows = (int)scalar($pdo, "SELECT COUNT(*) FROM `{$t['facilities']}`") + (int)scalar($pdo, "SELECT COUNT(*) FROM `{$t['operators']}`");
check('rows: one legacy-shaped row per facility and operator', count($master_rows) === $expected_rows, count($master_rows) . ' of ' . $expected_rows);
$by_name = array();
foreach ($master_rows as $row) $by_name[$row['unique_name']] = $row;
$op_row = kop_v2_pdo_master_row_by_name($pdo, $prefix, $op_name . ' Renamed');
$fac_row = kop_v2_pdo_master_row_by_name($pdo, $prefix, 'Writer Test Ranch');
check('rows: an operator project and a facility are both found by name',
    $op_row && $fac_row && strpos($fac_row['json_data'], '__facility_ref') !== false && strpos($op_row['json_data'], '__facility_ref') === false);

$before = state_of($pdo);
kop_v2_save_legacy_row($pdo, $prefix, $op_row['unique_name'], json_decode($op_row['json_data'], true));
kop_v2_save_legacy_row($pdo, $prefix, $fac_row['unique_name'], json_decode($fac_row['json_data'], true));
check('rows: saving them back unchanged writes nothing', state_of($pdo) === $before);

$edited_row = json_decode($fac_row['json_data'], true);
$edited_row['data']['facility']['identification']['name'] = 'Writer Test Ranch Renamed';
kop_v2_save_legacy_row($pdo, $prefix, $fac_row['unique_name'], $edited_row);
check('rows: editing a facility row updates that facility',
    kop_facility_load($new_id, array('pdo' => $pdo, 'prefix' => $prefix))['doc']['identification']['name'] === 'Writer Test Ranch Renamed');
$edited_row['data']['facility']['identification']['name'] = 'Writer Test Ranch';
kop_v2_save_legacy_row($pdo, $prefix, $fac_row['unique_name'], $edited_row);

// --- 8d. Document folders and name checks (picker, wiki editor) ----------------------
check('folders: set on an operator project', kop_v2_set_document_folder($pdo, $prefix, $op_row['unique_name'], 4242) === 4242
    && (int)scalar($pdo, "SELECT document_folder_id FROM `{$t['operators']}` WHERE unique_name = ?", array($op_row['unique_name'])) === 4242);
check('folders: set on a facility', kop_v2_set_document_folder($pdo, $prefix, 'Writer Test Ranch', 777) === 777
    && kop_facility_load($new_id, array('pdo' => $pdo, 'prefix' => $prefix))['doc']['documentFolderId'] === 777);
check('folders: cleared again', kop_v2_set_document_folder($pdo, $prefix, 'Writer Test Ranch', null) === null
    && kop_facility_load($new_id, array('pdo' => $pdo, 'prefix' => $prefix))['doc']['documentFolderId'] === null);
$missing = false;
try {
    kop_v2_set_document_folder($pdo, $prefix, 'No Such Program Anywhere', 5);
} catch (RuntimeException $e) {
    $missing = true;
}
check('folders: an unknown program is reported', $missing);
check('names: taken and free names are told apart',
    kop_v2_name_taken($pdo, $prefix, 'Writer Test Ranch') && kop_v2_name_taken($pdo, $prefix, $op_row['unique_name'])
    && !kop_v2_name_taken($pdo, $prefix, 'Nothing Called This 12345'));

// --- 9. All or nothing ---------------------------------------------------------------
$before = state_of($pdo);
try {
    kop_v2_with_write_lock($pdo, function () use ($pdo, $prefix) {
        kop_v2_save_facility_entries($pdo, $prefix, array(array('identification' => array('name' => 'Rolled Back Academy'), 'address' => '5 Main St, Provo, UT 84601')), array());
        throw new RuntimeException('simulated failure');
    });
    check('rollback: exception propagates', false);
} catch (RuntimeException $e) {
    check('rollback: a failed save leaves no trace', state_of($pdo) === $before);
}

// --- 10. Switch back -------------------------------------------------------------------
check('facilities_master frozen through every test', kop_v2_frozen_fingerprint($pdo) === $switch['frozen_fingerprint']);
$edits = kop_v2_edits_since_switch($pdo, $prefix);
check('switch back: edits since the switch are counted', $edits >= 3, "$edits");
kop_v2_set_writes($pdo, $prefix, 'legacy');
check('switch back: writes go to the old tables again', !kop_v2_writes_active($pdo, $prefix, true));
check('legacy facility rows never written', scalar($pdo, "SELECT COUNT(*) FROM facilities_master WHERE updated_at > NOW() - INTERVAL 1 HOUR") == 0);

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
