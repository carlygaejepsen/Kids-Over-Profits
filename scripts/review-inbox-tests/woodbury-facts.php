<?php
/**
 * scripts/test-review-inbox.php checks for the 'woodbury-facts' source
 * (inc/review-inbox/woodbury-facts.php over inc/woodbury-facts.php), on the
 * mirror's real proposals. The mirror's copy of the table predates the young
 * adult columns, so they are added here and one program with no record is
 * put on the young adult tab. Adding to a facility record, and Undo (which
 * opens the facility store first), need MySQL and are not run.
 */

require_once dirname(__DIR__, 2) . '/inc/closure-reports.php';
require_once dirname(__DIR__, 2) . '/inc/woodbury-facts.php';
$GLOBALS['kop_test_options']['kop_woodbury_facts_db'] = KOP_WOODBURY_FACTS_DB_VERSION;
$GLOBALS['kop_test_options']['kop_closure_reports_db'] = KOP_CLOSURE_REPORTS_DB_VERSION;

if (!function_exists('sanitize_key')) {
    function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
}

(function () {
    $pdo = $GLOBALS['pdo'];
    $t = 'wpdl_kop_woodbury_facts';
    if (!$pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = '$t'")->fetchColumn()) return;
    $cols = array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('ya', $cols, true)) $pdo->exec("ALTER TABLE $t ADD COLUMN ya INTEGER NOT NULL DEFAULT 0");
    if (!in_array('ya_why', $cols, true)) $pdo->exec("ALTER TABLE $t ADD COLUMN ya_why TEXT NULL");
    $program = $pdo->query("SELECT program FROM $t WHERE status = 'pending' AND facility_id = 0 AND grp <> 'consultant' ORDER BY program DESC LIMIT 1")->fetchColumn();
    if ($program !== false && !(int) $pdo->query("SELECT COUNT(*) FROM $t WHERE ya = 1")->fetchColumn()) {
        $pdo->prepare("UPDATE $t SET ya = 1, ya_why = 'Ages: 18-25' WHERE program = ? AND facility_id = 0 AND status = 'pending'")->execute(array($program));
    }
})();

function kop_rinbox_test_woodbury_facts(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];
    $t = 'wpdl_kop_woodbury_facts';
    $row = function ($k) { return kop_wbf_rows(array($k))[0] ?? null; };
    $pick = function ($sql) use ($pdo) { return (string) $pdo->query($sql)->fetchColumn(); };

    // A staff item: the List choice is the category; a correction keeps the build's version for Reset.
    $staff = $pick("SELECT pkey FROM $t WHERE status = 'pending' AND op = 'add_staff' AND path = 'staff.notableStaff' AND facility_id > 0 ORDER BY id LIMIT 1");
    $before = $row($staff);
    $it = kop_rinbox_get_item('woodbury-facts', $staff);
    $cat = array_values(array_filter($it['fields'], function ($f) { return !empty($f['category']); }));
    $check('woodbury-facts: a staff item\'s list is its category field', count($cat) === 1 && $cat[0]['name'] === 'where' && isset($cat[0]['options']['staff.administrator']));
    $check('woodbury-facts: a matched item adds to its record', $it['actions'][0]['id'] === 'apply' && (int) $it['actions'][0]['params'][0]['value'] === (int) $before['facility_id']);
    $v0 = kop_wbf_row_value($before);
    call_user_func($src['save'], $staff, array('where' => 'staff.administrator'));
    $r = $row($staff);
    $v = kop_wbf_row_value($r);
    $check('woodbury-facts: changing only the list keeps the name and role', $r['path'] === 'staff.administrator' && $v['name'] === $v0['name'] && $v['role'] === $v0['role'], $r['label']);
    call_user_func($src['save'], $staff, array('role' => 'Clinical Director (test)'));
    $r = $row($staff);
    $check('woodbury-facts: save corrects the role and relabels', kop_wbf_row_value($r)['role'] === 'Clinical Director (test)' && strpos($r['label'], 'Clinical Director (test)') !== false, $r['label']);
    $it = kop_rinbox_get_item('woodbury-facts', $staff);
    $check('woodbury-facts: a corrected item offers Reset', in_array('reset', array_column($it['actions'], 'id'), true));
    call_user_func($src['act'], $staff, 'reset', array());
    $r = $row($staff);
    $check('woodbury-facts: Reset puts back what Woodbury said', $r['path'] === $before['path'] && $r['label'] === $before['label'] && $r['value'] === $before['value']);
    try {
        call_user_func($src['save'], $staff, array('name' => ''));
        $check('woodbury-facts: an empty name is refused', false);
    } catch (RuntimeException $e) {
        $check('woodbury-facts: an empty name is refused', true, $e->getMessage());
    }
    $year = $pick("SELECT pkey FROM $t WHERE status = 'pending' AND op = 'set_if_empty' AND path = 'operatingPeriod.startYear' ORDER BY id LIMIT 1");
    if ($year !== '') {
        try {
            call_user_func($src['save'], $year, array('year' => 'nineteen'));
            $check('woodbury-facts: a start year that is not a year is refused', false);
        } catch (RuntimeException $e) {
            $check('woodbury-facts: a start year that is not a year is refused', true, $e->getMessage());
        }
    }

    // No record: add needs a record; create and young adult are offered.
    $none = $pick("SELECT pkey FROM $t WHERE status = 'pending' AND facility_id = 0 AND grp <> 'consultant' AND ya = 0 ORDER BY id LIMIT 1");
    $it = kop_rinbox_get_item('woodbury-facts', $none);
    $ids = array_column($it['actions'], 'id');
    $check('woodbury-facts: a program with no record can be pointed at one, created, or moved to young adult', array_values(array_diff($ids, array('add_person'))) === array('apply', 'create', 'ya_on', 'reject'), implode(',', $ids));
    try {
        call_user_func($src['act'], $none, 'apply', array('facility' => ''));
        $check('woodbury-facts: adding with no record picked is refused', false);
    } catch (RuntimeException $e) {
        $check('woodbury-facts: adding with no record picked is refused', true, $e->getMessage());
    }
    $ya = $pick("SELECT pkey FROM $t WHERE status = 'pending' AND ya = 1 ORDER BY id LIMIT 1");
    $it = kop_rinbox_get_item('woodbury-facts', $ya);
    $check('woodbury-facts: a young adult item is created or filed as one, never a facility', in_array('ya_create', array_column($it['actions'], 'id'), true)
        && !in_array('apply', array_column($it['actions'], 'id'), true));

    // Reject; Undo needs the facility store (MySQL), so it is put back by hand here.
    $m = call_user_func($src['act'], $staff, 'reject', array());
    $check('woodbury-facts: reject', $row($staff)['status'] === 'rejected', $m['message']);
    $it = kop_rinbox_get_item('woodbury-facts', $staff);
    $check('woodbury-facts: a rejected item offers Back to review and no edits', $it['actions'][0]['id'] === 'undo' && !empty($it['fields'][0]['readonly']));
    try {
        call_user_func($src['save'], $staff, array('role' => 'x'));
        $check('woodbury-facts: a rejected item cannot be edited', false);
    } catch (RuntimeException $e) {
        $check('woodbury-facts: a rejected item cannot be edited', true, $e->getMessage());
    }
    try {
        $m = call_user_func($src['act'], $staff, 'undo', array());
        $check('woodbury-facts: back to review', $row($staff)['status'] === 'pending', $m['message']);
    } catch (RuntimeException $e) {
        echo '  (Undo not run offline: ' . $e->getMessage() . ")\n";
    }
    $pdo->prepare("UPDATE $t SET status = ?, applied = ?, reviewed_by = ?, reviewed_at = ?, extra = ?, auto = ? WHERE pkey = ?")
        ->execute(array($before['status'], $before['applied'], $before['reviewed_by'], $before['reviewed_at'], $before['extra'], $before['auto'], $staff));
    $applied = $pick("SELECT pkey FROM $t WHERE status = 'applied' AND applied_fid > 0 ORDER BY id LIMIT 1");
    if ($applied !== '') {
        $it = kop_rinbox_get_item('woodbury-facts', $applied);
        $check('woodbury-facts: an added item offers Undo', kop_rinbox_test_own_actions($it) === array('undo') && $it['facility']['id'] > 0);
    }

    // What the old screen also did.
    $res = call_user_func($src['list'], array('view' => 'records', 'search' => '', 'offset' => 0, 'limit' => 25, 'filters' => array('grp' => 'incident')));
    $grps = array_unique(array_map(function ($it) { return kop_wbf_rows(array($it['key']))[0]['grp']; }, $res['items']));
    $check('woodbury-facts: the kind filter shows only that kind', !$res['items'] || $grps === array('incident'), $res['total'] . ' incidents');
    $counts = call_user_func($src['view_counts'], array('search' => '', 'filters' => array()));
    // Items the record holds already wait under "Already on file", out of every waiting tab.
    $on_file = kop_rinbox_on_file_keys('woodbury-facts');
    $all_pending = (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE status = 'pending'")->fetchColumn();
    $check('woodbury-facts: every tab has its count', count($counts) === 8 && $counts['pending'] + count($on_file) + count(kop_rinbox_conflict_keys('woodbury-facts')) === $all_pending,
        json_encode($counts) . ' + ' . count($on_file) . ' on file + ' . count(kop_rinbox_conflict_keys('woodbury-facts')) . ' in conflict');
    $keys = array_keys($on_file);
    $states = $keys ? $pdo->query("SELECT DISTINCT status FROM $t WHERE pkey IN ('" . implode("','", array_slice($keys, 0, 500)) . "')")->fetchAll(PDO::FETCH_COLUMN) : array();
    $check('woodbury-facts: items the record already holds are found, all waiting ones', $on_file && $states === array('pending'), count($on_file) . ' of ' . $all_pending . ', e.g. ' . json_encode(array_slice($on_file, 0, 3)));
    $page = call_user_func($src['list'], array('view' => 'records', 'search' => '', 'offset' => 0, 'limit' => 100, 'filters' => array()));
    $check('woodbury-facts: they are out of the waiting tabs', !array_intersect(array_column($page['items'], 'key'), $keys)
        && $page['total'] === $counts['records']);
    // Conflicts are always marked: what the record says that disagrees, never ticked, and Add keeps them waiting.
    $doc = array('facilityDetails' => array('type' => 'Wilderness Therapy'), 'operatingPeriod' => array('status' => 'Open', 'endYear' => null),
        'staff' => array('administrator' => array(array('name' => 'Jane Q. Roe', 'role' => 'Executive Director (2001)'))));
    $row = function ($op, $path, $value) { return array('op' => $op, 'path' => $path, 'value' => json_encode($value)); };
    $check('woodbury-facts: conflict rules (other value, open record, other role) and none for the same thing',
        kop_wbf_conflict($doc, $row('set_if_empty', 'facilityDetails.type', 'Boot Camp')) === 'The record has Wilderness Therapy; this says Boot Camp.'
        && kop_wbf_conflict($doc, $row('set_if_empty', 'facilityDetails.type', 'wilderness therapy')) === ''
        && kop_wbf_conflict($doc, $row('set_if_empty', 'facilityDetails.capacity', 40)) === ''
        && strpos(kop_wbf_conflict($doc, $row('set_closed', 'operatingPeriod.status', array('endYear' => 2009))), 'The record says it is open') === 0
        && strpos(kop_wbf_conflict($doc, $row('add_staff', 'staff.notableStaff', array('name' => 'Dr. Jane Roe', 'role' => 'Admissions'))), 'The record lists Jane Q. Roe as Executive Director') === 0
        && kop_wbf_conflict($doc, $row('add_staff', 'staff.notableStaff', array('name' => 'Jane Roe', 'role' => 'executive director'))) === '');
    // A real waiting item whose record now holds another value.
    $live = null;
    foreach ($pdo->query("SELECT * FROM $t WHERE status = 'pending' AND op = 'set_if_empty' AND facility_id > 0 ORDER BY id LIMIT 4000")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $f = kop_on_file_doc($r['facility_id']);
        if ($f && kop_wbf_conflict($f['doc'], $r) !== '') { $live = $r; break; }
    }
    if ($live) {
        $card = kop_rinbox_get_item('woodbury-facts', $live['pkey']);
        $check('woodbury-facts: a card whose record disagrees says Conflict and is not ticked', strpos($card['conflict'], 'The record has') !== false && $card['selected'] === false, $card['conflict']);
        $res = kop_wbf_apply(array($live), (int) $live['facility_id'], 'tester');
        $after = kop_wbf_rows(array($live['pkey']))[0];
        $check('woodbury-facts: Add on a conflict keeps it waiting, the conflict stored, never filed as rejected', $after['status'] === 'pending'
            && strpos((string) $after['conflict'], 'The record has') === 0 && strpos((string) ($res[$live['pkey']]['error'] ?? ''), 'Conflict: ') === 0, json_encode($res));
    } else {
        $check('woodbury-facts: a waiting item with a live conflict exists in the mirror', false);
    }
    $first = kop_rinbox_on_file_list($src, 'woodbury-facts', array('offset' => 0, 'limit' => 5));
    $check('woodbury-facts: the Already on file view lists them, saying what the record has', $first['total'] === count($on_file) && $first['items']
        && $first['items'][0]['details'][0]['label'] === 'Already on file', json_encode($first['items'][0]['details'][0] ?? null));
    $pre = $pick("SELECT pkey FROM $t WHERE status = 'pending' AND preselect = 1 AND facility_id > 0 ORDER BY id LIMIT 1");
    $notpre = $pick("SELECT pkey FROM $t WHERE status = 'pending' AND preselect = 0 AND facility_id > 0 ORDER BY id LIMIT 1");
    $check('woodbury-facts: a checked quote at a sure match starts ticked, others do not',
        ($pre === '' || kop_rinbox_get_item('woodbury-facts', $pre)['selected'] === true) && ($notpre === '' || kop_rinbox_get_item('woodbury-facts', $notpre)['selected'] === false));
    $alt = $pick("SELECT pkey FROM $t WHERE status = 'pending' AND facility_id > 0 AND alternatives LIKE '%\"id\"%' ORDER BY id LIMIT 1");
    if ($alt !== '') {
        $ids = array_column(kop_rinbox_get_item('woodbury-facts', $alt)['actions'], 'id');
        $check('woodbury-facts: the other close names are one-click adds', (bool) preg_grep('/^apply_to_\d+$/', $ids), implode(',', $ids));
    }
    $it = kop_rinbox_get_item('woodbury-facts', $staff);
    $create = array_values(array_filter($it['actions'], function ($a) { return $a['id'] === 'create'; }))[0] ?? array();
    $check('woodbury-facts: a matched item can still go on a new record, with its type and "different place"',
        in_array('type', array_column($create['params'] ?? array(), 'name'), true) && in_array('force', array_column($create['params'] ?? array(), 'name'), true));
    try {
        call_user_func($src['act'], $staff, 'add_person', array('name' => '', 'role' => 'x'));
        $check('woodbury-facts: adding a person needs a name', false);
    } catch (RuntimeException $e) {
        $check('woodbury-facts: adding a person needs a name', true, $e->getMessage());
    }
    try {
        $m = call_user_func($src['act'], $staff, 'add_person', array('name' => 'Testy McTestface', 'role' => 'Night staff', 'pastJobs' => '', 'where' => 'staff.notableStaff'));
        $new = $pdo->query("SELECT * FROM $t WHERE label LIKE 'Testy McTestface%'")->fetch(PDO::FETCH_ASSOC);
        $check('woodbury-facts: "Add this person" adds a waiting staff item citing the same page', $new && $new['status'] === 'pending' && $new['grp'] === 'staff'
            && (int) $new['facility_id'] === (int) $before['facility_id'], $m['message']);
        if ($new) $pdo->prepare("DELETE FROM $t WHERE pkey = ?")->execute(array($new['pkey']));
    } catch (Throwable $e) {
        $check('woodbury-facts: "Add this person"', false, get_class($e) . ': ' . $e->getMessage());
    }
    $check('woodbury-facts: queue tools are "Add the plainly stated items now" and "Load again"', array_column($src['tools'], 'id') === array('auto', 'resync'));
    $m = call_user_func($src['tool'], 'resync', array());
    $check('woodbury-facts: "Load the facts file again" answers', $m['message'] !== '', $m['message']);
}
