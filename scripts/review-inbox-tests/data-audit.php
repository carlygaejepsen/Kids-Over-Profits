<?php
/**
 * scripts/test-review-inbox.php checks for the 'data-audit' source
 * (inc/review-inbox/data-audit.php over inc/data-audit.php), on the real
 * proposals in js/data/data-audit/proposals.json and the mirror's records:
 * every proposal is well formed and still fits its record, a record's
 * changes and their Undo give back the same document, a changed record is
 * refused, company links and map years go and come back, and a company's page
 * shows a program it left as Transferred. Saving a record needs MySQL and is
 * not run (as for Woodbury Facts).
 */

require_once dirname(__DIR__, 2) . '/inc/network-years.php';
require_once dirname(__DIR__, 2) . '/inc/data-audit.php';

function kop_rinbox_test_data_audit(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];
    $props = kop_daudit_proposals();
    $doc_of = function ($fid) use ($pdo) {
        $st = $pdo->prepare('SELECT json_data FROM facilities_v2 WHERE id = ?');
        $st->execute(array((int) $fid));
        $js = $st->fetchColumn();
        return $js === false ? null : json_decode((string) $js, true);
    };

    // Every proposal: known op types, fields it may change, values the record can hold, ids that exist.
    $types = array('field', 'list_add', 'list_remove', 'note', 'note_remove', 'staff_role', 'merge', 'operator_create', 'map_edge_drop',
        'map_years', 'operator_link', 'operator_field', 'links_move', 'person_merge', 'memorial', 'check', 'manual');
    $bad = array();
    $stale = array();
    foreach ($props as $key => $p) {
        foreach ($p['ops'] as $op) {
            $t = (string) ($op['type'] ?? '');
            if (!in_array($t, $types, true)) $bad[] = $key . ': type ' . $t;
            if ($t === 'field' && !kop_daudit_field_ok((string) $op['path'], kop_daudit_norm($op['to'] ?? null))) $bad[] = $key . ': ' . $op['path'] . ' = ' . json_encode($op['to']);
            if ($t === 'map_years' && !kop_daudit_valid_years($op['to'] ?? '')) $bad[] = $key . ': map years ' . json_encode($op['to']);
            if ($t === 'memorial' && !kop_daudit_memorial_ok((string) $op['column'], $op['to'] ?? null)) $bad[] = $key . ': memorial ' . $op['column'] . ' = ' . json_encode($op['to']);
            if ($t === 'operator_link' && !in_array($op['to'] ?? '', array('current', 'past', 'remove'), true)) $bad[] = $key . ': link ' . json_encode($op['to']);
            if ($t === 'links_move' && (!(int) ($op['from_facility'] ?? 0) || !(int) ($op['to_facility'] ?? 0) || !kop_daudit_move_ids($op))) $bad[] = $key . ': links_move without records or ids';
            if ($t === 'operator_field' && !kop_daudit_operator_value_ok((string) $op['path'], $op['to'] ?? null)) $bad[] = $key . ': company ' . $op['path'] . ' = ' . json_encode($op['to']);
            if (($t === 'list_add' || $t === 'list_remove') && !in_array($op['path'] ?? '', kop_daudit_list_paths(), true)) $bad[] = $key . ': list ' . json_encode($op['path'] ?? null);
            if ($t === 'merge' && (!(int) ($op['keep'] ?? 0) || !(int) ($op['drop'] ?? 0) || (int) $op['keep'] === (int) $op['drop'])) $bad[] = $key . ': merge without two records';
            if (($t === 'check' || $t === 'manual') && trim((string) ($op['text'] ?? '')) === '') $bad[] = $key . ': ' . $t . ' with no text';
            if ($t === 'operator_create' && trim((string) ($op['name'] ?? '')) === '') $bad[] = $key . ': new company with no name';
        }
        $by_fid = array();
        foreach ($p['ops'] as $op) {
            if (in_array($op['type'], kop_daudit_record_types(), true)) $by_fid[(int) $op['facility_id']][] = $op;
        }
        foreach ($by_fid as $fid => $ops) {
            $doc = $doc_of($fid);
            if ($doc === null) { $bad[] = $key . ': no record #' . $fid; continue; }
            if (kop_daudit_doc_check($doc, $ops)) $stale[] = $key;
        }
    }
    // Company fields and moved links are checked against the mirror the way Approve checks them.
    $why_new = array();
    foreach ($props as $key => $p) {
        $mine = array_values(array_filter($p['ops'], function ($op) { return in_array($op['type'], array('operator_field', 'links_move'), true); }));
        if (!$mine) continue;
        try {
            foreach (kop_daudit_check(array('ops' => $mine), array('pdo' => $pdo, 'prefix' => 'wpdl_')) as $w) $why_new[] = $key . ': ' . $w;
        } catch (Throwable $e) {
            $why_new[] = $key . ': ' . $e->getMessage();
        }
    }
    $check('data-audit: company fields and moved articles still fit the records', !$why_new, implode(' | ', array_slice($why_new, 0, 6)));
    $check('data-audit: every proposal is well formed', !$bad, count($props) . ' proposals' . ($bad ? '; ' . implode(' | ', array_slice($bad, 0, 6)) : ''));
    $check('data-audit: nearly every proposal still fits its record (the rest are refused on Approve)', count($stale) <= max(3, (int) (count($props) / 20)),
        count($stale) . ' changed since: ' . implode(', ', array_slice($stale, 0, 8)));

    // A record's changes and Undo: the document comes back the same.
    $fid = (int) $pdo->query("SELECT id FROM facilities_v2 WHERE status = 'Transferred' ORDER BY id LIMIT 1")->fetchColumn();
    $doc = $doc_of($fid);
    $past = kop_daudit_list($doc, 'identification.pastOperators');
    $ops = array(
        array('type' => 'field', 'facility_id' => $fid, 'path' => 'operatingPeriod.status', 'from' => 'Transferred', 'to' => 'Open'),
        array('type' => 'field', 'facility_id' => $fid, 'path' => 'operatingPeriod.endYear', 'from' => $doc['operatingPeriod']['endYear'] ?? null, 'to' => 2011),
        array('type' => 'list_add', 'facility_id' => $fid, 'path' => 'identification.pastOperators', 'value' => 'Test Company (parent, 1999-2005)'),
        array('type' => 'note', 'facility_id' => $fid, 'text' => 'A test sentence.'),
    );
    if ($past) $ops[] = array('type' => 'list_remove', 'facility_id' => $fid, 'path' => 'identification.pastOperators', 'value' => $past[0]);
    $check('data-audit: the test changes fit the record', !kop_daudit_doc_check($doc, $ops), implode(' ', kop_daudit_doc_check($doc, $ops)));
    $after = $doc;
    $done = kop_daudit_doc_apply($after, $ops, 'Test source: https://example.test/a');
    $check('data-audit: approve changes status, year and past operators and cites the source',
        $after['operatingPeriod']['status'] === 'Open' && (int) $after['operatingPeriod']['endYear'] === 2011
        && kop_daudit_list_has(kop_daudit_list($after, 'identification.pastOperators'), 'Test Company (parent, 1999-2005)')
        && in_array('A test sentence. (Test source: https://example.test/a)', kop_daudit_list($after, 'operatingPeriod.notes'), true),
        count($done) . ' changes');
    $back = $after;
    $kept = kop_daudit_doc_undo($back, $done);
    $norm = function ($d) {
        foreach (array('identification.pastOperators', 'operatingPeriod.notes') as $path) kop_daudit_set($d, $path, kop_daudit_list($d, $path));
        return json_encode($d);
    };
    $check('data-audit: undo gives back the same record', !$kept && $norm($back) === $norm($doc));
    $edited = $after;
    $edited['operatingPeriod']['status'] = 'Closed';
    $back = $edited;
    $kept = kop_daudit_doc_undo($back, $done);
    $check('data-audit: undo leaves a value edited since', $kept === array('operatingPeriod.status') && $back['operatingPeriod']['status'] === 'Closed');
    $doc2 = $doc;
    $doc2['operatingPeriod']['status'] = 'Suspended';
    $check('data-audit: a record changed since the research is refused', (bool) kop_daudit_doc_check($doc2, $ops));
    $check('data-audit: a status the owner retired is refused', (bool) kop_daudit_doc_check($doc, array(
        array('type' => 'field', 'facility_id' => $fid, 'path' => 'operatingPeriod.status', 'from' => 'Transferred', 'to' => 'Transferred'))));

    // Company links: past, then back.
    $row = $pdo->query("SELECT operator_id, facility_id, relationship, sort_order FROM wpdl_kop_operator_facilities WHERE relationship = 'current' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $t = 'wpdl_kop_operator_facilities';
        $before = kop_daudit_link_row($pdo, $t, $row['facility_id'], $row['operator_id']);
        kop_daudit_link_put($pdo, $t, $row['facility_id'], $row['operator_id'], array('relationship' => 'past', 'sort_order' => $before['sort_order']));
        $mid = kop_daudit_link_row($pdo, $t, $row['facility_id'], $row['operator_id']);
        kop_daudit_link_put($pdo, $t, $row['facility_id'], $row['operator_id'], null);
        $gone = kop_daudit_link_row($pdo, $t, $row['facility_id'], $row['operator_id']);
        kop_daudit_link_put($pdo, $t, $row['facility_id'], $row['operator_id'], $before);
        $check('data-audit: a company link becomes past, is removed, and comes back', $mid['relationship'] === 'past' && $gone === null
            && kop_daudit_link_row($pdo, $t, $row['facility_id'], $row['operator_id']) == $before);
    }

    // Company records: the fields a correction may set, and a change that goes and comes back.
    $check('data-audit: company values are checked', kop_daudit_operator_value_ok('headquarters', 'Huntsville, AL')
        && kop_daudit_operator_value_ok('keyStaff.founders', array(array('name' => 'A Person', 'role' => 'co-founder', 'pastJobs' => '')))
        && !kop_daudit_operator_value_ok('keyStaff.founders', array(array('role' => 'no name')))
        && !kop_daudit_operator_value_ok('history', array('x')) && !kop_daudit_operator_value_ok('name', 'Other'));
    $check('data-audit: company values compare by content', kop_daudit_operator_same(array('name' => 'X', 'role' => ''), array('role' => '', 'name' => 'X'))
        && kop_daudit_operator_same('', null) && kop_daudit_operator_same(array(), null) && !kop_daudit_operator_same(array('a'), array('b')));
    kop_rinbox_test_copy_tables(array('wpdl_kop_operators'));
    $oid = (int) $pdo->query('SELECT id FROM wpdl_kop_operators ORDER BY id LIMIT 1')->fetchColumn();
    if ($oid) {
        $oopts = array('pdo' => $pdo, 'prefix' => 'wpdl_');
        $was = kop_daudit_operator_json($oopts, $oid);
        $json = $was;
        kop_daudit_set($json['operator'], 'keyStaff.ceo', 'Test Person');
        kop_daudit_operator_save($oopts, $oid, $json);
        $mid = kop_daudit_get(kop_daudit_operator_json($oopts, $oid)['operator'], 'keyStaff.ceo');
        $p = array('ops' => array(array('type' => 'operator_field', 'operator_id' => $oid, 'path' => 'keyStaff.ceo', 'from' => 'Someone Else', 'to' => 'Another')));
        $why = kop_daudit_check($p, $oopts);
        kop_daudit_operator_save($oopts, $oid, $was);
        $check('data-audit: a company field goes and comes back, and a changed one is refused',
            $mid === 'Test Person' && kop_daudit_operator_json($oopts, $oid) == $was && count($why) === 1, implode(' ', $why));
    }

    // Articles filed under the wrong record move, and come back.
    kop_rinbox_test_copy_tables(array('news_facility_links', 'facilities_v2'));
    $nl = $pdo->query('SELECT news_id, facility_id FROM news_facility_links ORDER BY news_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $to = (int) $pdo->query('SELECT id FROM facilities_v2 WHERE id <> ' . (int) $nl['facility_id'] . ' ORDER BY id LIMIT 1')->fetchColumn();
    if ($nl && $to) {
        $row = kop_daudit_item_link($pdo, 'news', $nl['news_id'], $nl['facility_id']);
        kop_daudit_item_link_drop($pdo, 'news', $nl['news_id'], $nl['facility_id']);
        kop_daudit_item_link_put($pdo, 'news', array('facility_id' => $to) + $row);
        $moved = kop_daudit_item_link($pdo, 'news', $nl['news_id'], $to) && !kop_daudit_item_link($pdo, 'news', $nl['news_id'], $nl['facility_id']);
        kop_daudit_item_link_drop($pdo, 'news', $nl['news_id'], $to);
        kop_daudit_item_link_put($pdo, 'news', $row);
        $check('data-audit: an article moves to another record and comes back whole', $moved && kop_daudit_item_link($pdo, 'news', $nl['news_id'], $nl['facility_id']) == $row);
    }

    // The owner's rule: Transferred on the page of the company a program left, Open elsewhere.
    if (function_exists('kop_operator_program_status')) {
        $check('data-audit: a program a company left shows Transferred on its page', kop_operator_program_status('Open', 'past') === 'Transferred'
            && kop_operator_program_status('Open', 'current') === 'Open' && kop_operator_program_status('Closed', 'past') === 'Closed');
    }

    // Map years go over the map's own.
    if (kop_rinbox_test_options_persist()) {
        update_option('kop_data_audit_map_years', array('test-node-x' => '2007-2019'), false);
        $over = kop_daudit_year_overrides(array('test-node-x' => '1986-2007'));
        $check('data-audit: corrected map years win over Map Years and Map Renames', ($over['test-node-x'] ?? '') === '2007-2019');
        update_option('kop_data_audit_map_years', array(), false);
    }

    // Memorial entries: the values a correction may set, and a change that goes and comes back.
    $check('data-audit: memorial values are checked', kop_daudit_memorial_ok('date_of_death', '2024-02-03') && !kop_daudit_memorial_ok('date_of_death', '2024-02')
        && kop_daudit_memorial_ok('date_precision', 'month') && !kop_daudit_memorial_ok('cause_category', 'asphyxia')
        && kop_daudit_memorial_ok('age', 12) && !kop_daudit_memorial_ok('notes', 'x'));
    kop_rinbox_test_copy_tables(array('memorial_victims'));
    $mid = (int) $pdo->query("SELECT id FROM memorial_victims WHERE publication_status = 'published' ORDER BY id LIMIT 1")->fetchColumn();
    if ($mid) {
        list(, $was) = kop_daudit_memorial_value($pdo, $mid, 'date_precision');
        kop_daudit_memorial_put($pdo, $mid, 'date_precision', 'year');
        list(, $mid_val) = kop_daudit_memorial_value($pdo, $mid, 'date_precision');
        kop_daudit_memorial_put($pdo, $mid, 'date_precision', $was);
        list(, $back) = kop_daudit_memorial_value($pdo, $mid, 'date_precision');
        $check('data-audit: a memorial correction goes and comes back', $mid_val === 'year' && $back === $was);
        try {
            kop_daudit_memorial_value($pdo, $mid, 'publication_status');
            $check('data-audit: a memorial column no correction may touch is refused', false);
        } catch (RuntimeException $e) {
            $check('data-audit: a memorial column no correction may touch is refused', true);
        }
    }

    // Renames: why the years disagree, and the merge right on the card.
    $bad_r = array();
    foreach ($props as $key => $p) {
        foreach ((array) ($p['renames'] ?? array()) as $r) {
            $y = (int) ($r['year'] ?? 0);
            // No year: the records do not say, and the reviewer gives it when merging.
            if ((int) ($r['earlier'] ?? 0) <= 0 || (int) ($r['later'] ?? 0) <= 0 || (int) $r['earlier'] === (int) $r['later']
                || (isset($r['year']) && ($y < 1800 || $y > (int) gmdate('Y')))) {
                $bad_r[] = $key . ': ' . json_encode($r);
            }
        }
    }
    $check('data-audit: every rename names two records (and a year, when it has one)', !$bad_r, implode(' | ', array_slice($bad_r, 0, 6)));
    kop_rinbox_daudit_test_rename($check);
    foreach ($props as $key => $p) {
        if ($p['ops'] || empty($p['renames'])) continue;
        $card = kop_rinbox_get_item('data-audit', (string) $key);
        $ids = array_column($card['actions'], 'id');
        if (!in_array('rename_merge', $ids, true)) continue;
        $check('data-audit: a card holding only a rename offers the merge both ways and "not one program", no Approve',
            array_values(array_diff($ids, array('rename_fold', 'alias_merge', 'name_alias'))) === array('rename_merge', 'rename_merge_reversed', 'reject')
            || array_values(array_diff($ids, array('rename_fold', 'alias_merge', 'name_alias'))) === array('rename_merge_reversed', 'rename_merge', 'reject'), $key . ': ' . implode(',', $ids));
        break;
    }

    // Steps on every card: where each stands now. A step that cannot be made at all is rare (records merged since).
    $opts = array('pdo' => $pdo, 'prefix' => 'wpdl_');
    $blocked = array();
    $nsteps = 0;
    foreach ($props as $key => $p) {
        foreach (kop_daudit_step_states($p, $opts) as $i => $st) {
            $nsteps++;
            if ($st['state'] === 'blocked') $blocked[] = $key . '/' . $i . ': ' . $st['why'];
        }
    }
    $check('data-audit: nearly every step can be made (blocked ones are records merged since)', count($blocked) <= max(5, (int) ($nsteps / 25)),
        $nsteps . ' steps, ' . count($blocked) . ' blocked: ' . implode(' | ', array_slice($blocked, 0, 4)));

    kop_rinbox_daudit_test_companies($check, $opts);
    kop_rinbox_daudit_test_aliases($check, $opts);
    kop_rinbox_daudit_test_steps($check, $opts);

    // KOP_DAUDIT_EXPORT=<file>: a few real cards as JSON, for a look in a browser (scripts/test-review-inbox-ui.py fixtures).
    if (getenv('KOP_DAUDIT_EXPORT')) {
        $cards = array();
        foreach (array('operator_inherited-345', 'sequel_history-13678', 'rename_years-12685-100094', 'sequel_history-10459') as $k) {
            if ($c = kop_rinbox_get_item('data-audit', $k)) $cards[] = $c;
        }
        file_put_contents(getenv('KOP_DAUDIT_EXPORT'), json_encode($cards, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    // The card.
    $own = kop_rinbox_test_own_actions($item);
    $p = $props[$item['key']];
    $check('data-audit: a waiting card offers "Make the ticked changes" and reject', $item['status'] === 'waiting'
        && (array_slice($own, 0, 2) === array('approve', 'reject') || in_array('rename_merge', $own, true)), implode(',', $own));
    $steps = kop_daudit_steps($p);
    $listed = count(array_filter($steps, function ($op) { return $op['type'] !== 'check'; }));
    $checks = count(array_filter($item['details'], function ($d) { return $d['label'] === 'To check'; }));
    $check('data-audit: every step is on the card, to tick or to check', count($item['checklist'] ?? array()) === $listed
        && $checks === count($steps) - $listed, $listed . ' to tick, ' . $checks . ' to check');
    $want = count(array_filter((array) ($p['sources'] ?? array()), function ($s) { return !empty($s['url']); }));
    $check('data-audit: every source is on the card with its quote', count($item['sources']) === $want, $want . ' sources');
    $check('data-audit: the card has the fields of each record to edit, and KOP as it is now', !empty($item['fields']) && !empty($item['compare']['rows']),
        count($item['fields']) . ' fields');
    $counts = call_user_func($src['view_counts'], array());
    $check('data-audit: every tab has a count', array_keys($counts) == array_keys($src['views']) && $counts['waiting'] === call_user_func($src['count']), json_encode($counts));
    if (kop_rinbox_test_options_persist()) {
        call_user_func($src['act'], $item['key'], 'reject', array());
        $got = kop_rinbox_get_item('data-audit', $item['key']);
        $check('data-audit: reject moves it to Rejected with Undo', $got['status'] === 'rejected' && kop_rinbox_test_own_actions($got) === array('undo'));
        call_user_func($src['act'], $item['key'], 'undo', array());
        $check('data-audit: undo a rejection', kop_rinbox_get_item('data-audit', $item['key'])['status'] === $item['status']);
    }
}

/**
 * A card whose years disagree because of a rename (Timberline Academy became
 * Daytona Juvenile Residential Facility in 2016): the card says so, offers
 * "Merge as a rename", merges with each name keeping its years, and its Undo
 * gives facilities_v2 back exactly.
 */
function kop_rinbox_daudit_test_rename(callable $check) {
    $pdo = $GLOBALS['pdo'];
    $key = '';
    foreach (kop_daudit_proposals() as $k => $p) {
        foreach (kop_rinbox_daudit_renames((string) $k, $p) as $r) {
            if ($r['state']['state'] === 'separate' && !$r['found'] && $r['state']['year']) { $key = (string) $k; break 2; }
        }
    }
    if ($key === '') {
        kop_rinbox_test_skip('data-audit: rename merge', 'no researched rename with both records on file in the mirror');
        return;
    }
    $item = kop_rinbox_get_item('data-audit', $key);
    $lines = array_values(array_filter($item['details'], function ($d) { return $d['label'] === 'Renamed'; }));
    $merge = null;
    foreach ($item['actions'] as $a) if ($a['id'] === 'rename_merge') $merge = $a;
    $check('data-audit: the card says a rename explains the years', $lines && strpos($lines[0]['value'], 'became') !== false
        && strpos($lines[0]['value'], 'years on record disagree') !== false, $lines[0]['value'] ?? '');
    $check('data-audit: the card offers "Merge as a rename" with the pair and the year', $merge
        && array_column($merge['params'], 'name') === array('pair', 'year') && $merge['params'][1]['value'] !== '', $key);
    if (!$merge) return;
    if (!kop_rinbox_test_options_persist()) {
        kop_rinbox_test_skip('data-audit: rename merge and undo', 'update_option does not store in this harness');
        return;
    }
    $tables = array('lawsuit_facility_links', 'wpdl_kop_facility_identity', 'wpdl_kop_facility_locations', 'wpdl_fbv',
        'wpdl_fbv_attachment_folder', 'wpdl_kop_media_folder_tags', 'wpdl_kop_folder_links', 'wpdl_kop_migration_state', 'wpdl_kop_people');
    foreach (kop_fmerge_ref_tables('wpdl_') as $r) $tables[] = $r['t'];
    foreach (kop_fmerge_json_tables('wpdl_') as $r) $tables[] = $r['t'];
    kop_rinbox_test_copy_tables($tables);
    $snap = function () use ($pdo) {
        return md5(json_encode($pdo->query('SELECT id, json_data FROM facilities_v2 ORDER BY id')->fetchAll(PDO::FETCH_NUM)));
    };
    $before = $snap();
    $pair = (string) $merge['params'][0]['value'];
    list($e, $l) = array_map('intval', explode('>', $pair));
    $year = (int) $merge['params'][1]['value'];
    try {
        $res = call_user_func(kop_rinbox_source('data-audit')['act'], $key, 'rename_merge', array('pair' => $pair, 'year' => (string) $year));
    } catch (Throwable $ex) {
        $check('data-audit: merge as a rename from the card', false, $ex->getMessage());
        return;
    }
    $gone = !$pdo->query('SELECT COUNT(*) FROM facilities_v2 WHERE id = ' . $e)->fetchColumn();
    $kept = json_decode((string) $pdo->query('SELECT json_data FROM facilities_v2 WHERE id = ' . $l)->fetchColumn(), true);
    $check('data-audit: merge as a rename from the card folds the earlier name into the later one', $gone
        && (int) ($kept['operatingPeriod']['startYear'] ?? 0) >= $year, $res['message']);
    $got = kop_rinbox_get_item('data-audit', $key);
    $ids = array_column($got['actions'], 'id');
    $check('data-audit: the merged card offers its Undo, not the merge', in_array('rename_unmerge', $ids, true) && !in_array('rename_merge', $ids, true), implode(',', $ids));
    call_user_func(kop_rinbox_source('data-audit')['act'], $key, 'rename_unmerge', array('pair' => $pair));
    $check('data-audit: undo of the rename merge gives the records back exactly', $snap() === $before
        && empty(kop_daudit_decisions()[$key]['merges']));

    // The other way round: the record listed as later folds into the one listed as earlier.
    $item = kop_rinbox_get_item('data-audit', $key);
    $flip = null;
    foreach ($item['actions'] as $a) if ($a['id'] === 'rename_merge_reversed') $flip = $a;
    $check('data-audit: the card offers "Merge the other way round" for the same pair', $flip && $flip['params'][0]['value'] === $pair);
    if (!$flip) return;
    try {
        $res = call_user_func(kop_rinbox_source('data-audit')['act'], $key, 'rename_merge_reversed', array('pair' => $pair, 'year' => (string) $year));
    } catch (Throwable $ex) {
        $check('data-audit: merge the other way round', false, $ex->getMessage());
        return;
    }
    $gone = !$pdo->query('SELECT COUNT(*) FROM facilities_v2 WHERE id = ' . $l)->fetchColumn();
    $kept = json_decode((string) $pdo->query('SELECT json_data FROM facilities_v2 WHERE id = ' . $e)->fetchColumn(), true);
    $past = array_map('strtolower', (array) ($kept['identification']['pastNames'] ?? array()));
    $check('data-audit: the other way round keeps the record listed as earlier', $gone && $kept, $res['message']);
    $got = kop_rinbox_get_item('data-audit', $key);
    $ids = array_column($got['actions'], 'id');
    $lines = array_values(array_filter($got['details'], function ($d) { return $d['label'] === 'Renamed'; }));
    $check('data-audit: the card reads the merge the way it was made, with its Undo', in_array('rename_unmerge', $ids, true)
        && !in_array('rename_merge_reversed', $ids, true) && $lines && strpos($lines[0]['value'], 'Merged as a rename from this card') !== false,
        ($lines[0]['value'] ?? '') . ' / ' . implode(',', $ids));
    call_user_func(kop_rinbox_source('data-audit')['act'], $key, 'rename_unmerge', array('pair' => $pair));
    $check('data-audit: undo of the reversed merge gives the records back exactly', $snap() === $before
        && empty(kop_daudit_decisions()[$key]['merges']));
}

/**
 * Company names in program names: a record that is only the company's name
 * (Embark Behavioral Health in Marion, Embark at Flathead Valley's company) is
 * offered as one program, not a rename; one run under its own name (Pathway
 * Family Center) or sharing its company's name (Three Points Center) is a
 * name of its own; a company filed as a past name moves to past companies.
 */
function kop_rinbox_daudit_test_companies(callable $check, array $opts) {
    $check('data-audit: company words come off a name', kop_daudit_core_key('Embark Behavioral Health RTC') === 'embark behavioral health'
        && kop_daudit_core_key('Three Springs, Inc.') === 'three springs' && kop_daudit_core_key('Three Points Center, LLC') === 'three points');
    $companies = array('sequel tsi' => 'Sequel TSI', 'three springs' => 'Three Springs Inc.', 'embark behavioral health' => 'Embark Behavioral Health');
    $a = kop_daudit_company_of('Sequel TSI Courtland', $companies);
    $b = kop_daudit_company_of('Embark Behavioral Health RTC', $companies);
    $check('data-audit: a company\'s brand and a company\'s own name are told apart', ($a['level'] ?? '') === 'brand' && ($a['company'] ?? '') === 'Sequel TSI'
        && ($b['level'] ?? '') === 'company' && kop_daudit_company_of('Embark at Flathead Valley', $companies) === null);
    $check('data-audit: company years are read from operator lines', kop_daudit_company_line_years('Sequel TSI (2009-2022)') === array(2009, 2022)
        && kop_daudit_company_line_years('Brighter Path (from 2022)') === array(2022, 0) && kop_daudit_company_line_name('Sequel TSI (2009-2022)') === 'Sequel TSI');
    $pair = function ($e, $l) use ($opts) {
        $s = kop_daudit_rename_state('test', array('earlier' => $e, 'later' => $l, 'year' => 0), $opts['pdo']);
        return $s['state'] === 'separate' ? kop_daudit_rename_brands($s, $opts) : null;
    };
    $embark = $pair(12685, 100094);
    if ($embark) $check('data-audit: "Embark Behavioral Health" in Marion is the company\'s name for Embark at Flathead Valley', $embark['company_side'] === 'later', json_encode($embark['lines']));
    $pathway = $pair(100123, 9626);
    if ($pathway) $check('data-audit: Pathway Family Center, run under its own name, is a name of its own', $pathway['company_side'] === '', json_encode($pathway['lines']));
    $madison = $pair(9644, 13678);
    if ($madison) $check('data-audit: Pathway of Madison County, a company named after its one program, is the later name of the program', $madison['company_side'] === '', json_encode($madison['lines']));
    $points = $pair(9637, 11939);
    if ($points) $check('data-audit: Three Points Center (NC) is a name of its own, not Auldern\'s company', $points['company_side'] === '', json_encode($points['lines']));
    if (!$embark && !$pathway && !$points) kop_rinbox_test_skip('data-audit: company names on real pairs', 'the pairs are merged or gone in this mirror');
    // KOP_DAUDIT_LIST=1: what the company names say about every rename on every card.
    if (getenv('KOP_DAUDIT_LIST')) {
        foreach (kop_daudit_proposals() as $k => $p) {
            foreach (kop_rinbox_daudit_renames((string) $k, $p) as $r) {
                if ($r['state']['state'] !== 'separate') continue;
                $b = $r['brands'];
                echo '  ' . $k . ' ' . $r['pair'] . ' ' . $r['state']['earlier']['name'] . ' -> ' . $r['state']['later']['name']
                    . ($b['company_side'] ? ' [company name: ' . $b['company_side'] . ']' : '') . ($b['backwards'] ? ' [backwards]' : '')
                    . ($b['year'] ? ' [year ' . $b['year'] . ']' : '') . "
";
            }
        }
    }
    $steps = kop_daudit_steps(array('key' => 'test-company-past-name', 'facility_id' => 13678, 'ops' => array()));
    $auto = array_values(array_filter($steps, function ($op) { return !empty($op['auto']) && $op['type'] === 'list_remove'; }));
    $doc = kop_facility_load(13678, $opts);
    if ($doc && kop_daudit_list_has(kop_daudit_list($doc['doc'], 'identification.pastNames'), 'Three Springs Inc.')) {
        $check('data-audit: a company filed as a past name is offered as a past company', $auto && $auto[0]['value'] === 'Three Springs Inc.', json_encode($auto));
    }
}

/** Steps: the quote link, ticked steps only, and a map line taken off and back. */
function kop_rinbox_daudit_test_steps(callable $check, array $opts) {
    $jump = kop_rinbox_daudit_jump_url('https://example.test/a', 'In 2014, Turn-About Ranch announced that it would no longer be owned by Aspen ... but would');
    $check('data-audit: a source links to its quote in the page', strpos($jump, '#:~:text=In%202014%2C%20Turn-About%20Ranch') !== false, $jump);
    $check('data-audit: a quote read from a search summary links to the page only, and says so',
        kop_rinbox_daudit_jump_url('https://example.test/a', 'Some words here about it (search summary)') === 'https://example.test/a'
        && strpos(kop_rinbox_daudit_reach('Title only; page not opened.'), 'Title only') === 0);
    $quotes = array(array('n' => 1, 'quote' => 'Three Springs bought a ranch.'), array('n' => 2, 'quote' => 'Sunrise Ranch closed in 2014 after the state acted.'));
    $back = kop_rinbox_daudit_backing('Closing year of Sunrise Ranch: 2009 -> 2014', $quotes);
    $check('data-audit: a step names the quote it rests on', strpos($back, 'Source 2: "') === 0 && strpos($back, '2014') !== false, $back);
    $check('data-audit: two shared words are not a quote a step rests on', kop_rinbox_daudit_backing('Add to past names: Three Springs Turning Point', $quotes) === '');
    if (!kop_rinbox_test_options_persist()) {
        kop_rinbox_test_skip('data-audit: ticked steps only', 'update_option does not store in this harness');
        return;
    }
    // A card with a map line to take off and other steps: only the ticked one is made, and Undo takes it back.
    $key = '';
    $idx = '';
    foreach (kop_daudit_proposals() as $k => $p) {
        $states = kop_daudit_step_states($p, $opts);
        foreach (kop_daudit_steps($p) as $i => $op) {
            if ($op['type'] === 'map_edge_drop' && ($states[$i]['state'] ?? '') === 'ready' && count($states) > 1) { $key = (string) $k; $idx = (string) $i; break 2; }
        }
    }
    if ($key === '') {
        kop_rinbox_test_skip('data-audit: ticked steps only', 'no ready map line on any card');
        return;
    }
    $edge = (string) kop_daudit_steps(kop_daudit_proposals()[$key])[$idx]['edge'];
    $msg = call_user_func(kop_rinbox_source('data-audit')['act'], $key, 'approve', array('picked' => array($idx)))['message'];
    $d = kop_daudit_decisions()[$key] ?? array();
    $check('data-audit: only the ticked step is made', ($d['decision'] ?? '') === 'applied' && count($d['done']) === 1 && $d['done'][0]['type'] === 'map_edge_drop'
        && isset(kop_daudit_edge_drops()[$edge]) && isset(kop_daudit_edge_drops_filter(array())[$edge]), $msg);
    $graph_has = function () use ($edge) {
        $path = kop_network_map_file('graph.json');
        $g = json_decode((string) file_get_contents($path), true);
        // What kop_network_map_graph() leaves out (the harness runs no WordPress filters, so the audit's own filter is called).
        $drops = kop_daudit_edge_drops_filter(array());
        foreach ($g['edges'] as $e) if ($e['id'] === $edge && !isset($drops[$edge])) return true;
        return false;
    };
    $check('data-audit: a map line taken off leaves the map\'s lines', !$graph_has());
    call_user_func(kop_rinbox_source('data-audit')['act'], $key, 'undo', array());
    $check('data-audit: undo puts the map line back and the card waits again', $graph_has() && kop_rinbox_get_item('data-audit', $key)['status'] === 'waiting');

    // "One program: fold it in" on a company-named pair, and its Undo.
    $fold_key = '';
    foreach (kop_daudit_proposals() as $k => $p) {
        foreach (kop_rinbox_daudit_renames((string) $k, $p) as $r) {
            if ($r['state']['state'] === 'separate' && $r['brands']['company_side'] !== '' && ($fold_key === '' || strpos($r['pair'], '100094') !== false)) { $fold_key = (string) $k; $pair = $r['pair']; $side = $r['brands']['company_side']; $s = $r['state']; if (strpos($pair, '100094') !== false) break 2; }
        }
    }
    if ($fold_key === '') {
        kop_rinbox_test_skip('data-audit: fold a company-named record', 'no such pair on file');
        return;
    }
    $pdo = $opts['pdo'];
    $tables = array('lawsuit_facility_links', 'wpdl_kop_facility_identity', 'wpdl_kop_facility_locations', 'wpdl_fbv',
        'wpdl_fbv_attachment_folder', 'wpdl_kop_media_folder_tags', 'wpdl_kop_folder_links', 'wpdl_kop_migration_state', 'wpdl_kop_people');
    foreach (kop_fmerge_ref_tables('wpdl_') as $r) $tables[] = $r['t'];
    foreach (kop_fmerge_json_tables('wpdl_') as $r) $tables[] = $r['t'];
    kop_rinbox_test_copy_tables($tables);
    $snap = function () use ($pdo) {
        return md5(json_encode($pdo->query('SELECT id, json_data FROM facilities_v2 ORDER BY id')->fetchAll(PDO::FETCH_NUM)));
    };
    $before = $snap();
    $keep = $side === 'later' ? $s['earlier'] : $s['later'];
    $drop = $side === 'later' ? $s['later'] : $s['earlier'];
    try {
        $res = call_user_func(kop_rinbox_source('data-audit')['act'], $fold_key, 'rename_fold', array('pair' => $pair));
    } catch (Throwable $ex) {
        $check('data-audit: fold a company-named record into the program', false, $ex->getMessage());
        return;
    }
    $kept = json_decode((string) $pdo->query('SELECT json_data FROM facilities_v2 WHERE id = ' . (int) $keep['id'])->fetchColumn(), true);
    $check('data-audit: the company-named record folds into the program, which keeps its name', !$pdo->query('SELECT COUNT(*) FROM facilities_v2 WHERE id = ' . (int) $drop['id'])->fetchColumn()
        && ($kept['identification']['name'] ?? '') === $keep['name'], $res['message']);
    $ids = array_column(kop_rinbox_get_item('data-audit', $fold_key)['actions'], 'id');
    $check('data-audit: the folded card offers its Undo', in_array('rename_unmerge', $ids, true) && !in_array('rename_fold', $ids, true), implode(',', $ids));
    call_user_func(kop_rinbox_source('data-audit')['act'], $fold_key, 'rename_unmerge', array('pair' => $pair));
    $check('data-audit: undo of the fold gives the records back exactly', $snap() === $before);
}

/**
 * Another name, not a rename: two records merge as one program with the name
 * filed as an other name (never a past one), and a past name on one record
 * becomes an other name; both come back exactly with their Undo.
 */
function kop_rinbox_daudit_test_aliases(callable $check, array $opts) {
    $pdo = $opts['pdo'];
    kop_fmerge_load_mfd();
    $keep = array('facility_id' => 1, 'identification' => array('name' => 'Sedona Sky Academy', 'pastNames' => array('Copper Canyon Academy', 'Old Ranch'), 'otherNames' => array()));
    $drop = array('facility_id' => 2, 'identification' => array('name' => 'Copper Canyon Academy', 'pastNames' => array('Sedona Sky Academy')));
    $m = kop_mfd_merge_docs($keep, $drop, array('id' => 2, 'unique_name' => 'Copper Canyon Academy'), null, true);
    $plain = kop_mfd_merge_docs($keep, $drop, array('id' => 2, 'unique_name' => 'Copper Canyon Academy'));
    $check('data-audit: an alternate-name merge takes the name out of the past names', array_values($m['identification']['pastNames']) === array('Old Ranch')
        && in_array('Copper Canyon Academy', $m['identification']['otherNames'], true)
        && in_array('Copper Canyon Academy', $plain['identification']['pastNames'], true), json_encode($m['identification']));
    if (!kop_rinbox_test_options_persist()) {
        kop_rinbox_test_skip('data-audit: another name on real records', 'update_option does not store in this harness');
        return;
    }
    $tables = array('lawsuit_facility_links', 'wpdl_kop_facility_identity', 'wpdl_kop_facility_locations', 'wpdl_fbv',
        'wpdl_fbv_attachment_folder', 'wpdl_kop_media_folder_tags', 'wpdl_kop_folder_links', 'wpdl_kop_migration_state', 'wpdl_kop_people');
    foreach (kop_fmerge_ref_tables('wpdl_') as $r) $tables[] = $r['t'];
    foreach (kop_fmerge_json_tables('wpdl_') as $r) $tables[] = $r['t'];
    kop_rinbox_test_copy_tables($tables);
    $snap = function () use ($pdo) {
        return md5(json_encode($pdo->query('SELECT id, json_data FROM facilities_v2 ORDER BY id')->fetchAll(PDO::FETCH_NUM)));
    };
    // Two records: "Just another name".
    $key = 'rename_years-9628-11222';
    $p = kop_daudit_proposals()[$key] ?? null;
    $pairs = $p ? kop_daudit_alias_pairs($key, $p, $opts) : array();
    if (!$pairs) {
        kop_rinbox_test_skip('data-audit: just another name', $key . ' has no separate pair in this mirror');
    } else {
        $before = $snap();
        $pick = (string) array_key_first($pairs);
        list($keep_id, $drop_id) = array_map('intval', explode('>', $pick));
        $drop_name = (string) $pdo->query('SELECT name FROM facilities_v2 WHERE id = ' . $drop_id)->fetchColumn();
        $res = call_user_func(kop_rinbox_source('data-audit')['act'], $key, 'alias_merge', array('pick' => $pick));
        $kept = json_decode((string) $pdo->query('SELECT json_data FROM facilities_v2 WHERE id = ' . $keep_id)->fetchColumn(), true);
        $past = array_map('strtolower', (array) ($kept['identification']['pastNames'] ?? array()));
        $other = array_map('strtolower', (array) ($kept['identification']['otherNames'] ?? array()));
        $check('data-audit: "Just another name" files the name as an other name, not a past name', !in_array(strtolower($drop_name), $past, true)
            && in_array(strtolower($drop_name), $other, true), $res['message']);
        $item = kop_rinbox_get_item('data-audit', $key);
        $check('data-audit: the card reads it as another name, with its Undo', in_array('rename_unmerge', array_column($item['actions'], 'id'), true)
            && strpos(json_encode($item['details']), 'only another name') !== false);
        call_user_func(kop_rinbox_source('data-audit')['act'], $key, 'rename_unmerge', array('pair' => (string) array_key_first(kop_daudit_decisions()[$key]['merges'])));
        $check('data-audit: undo of "Just another name" gives the records back exactly', $snap() === $before);
        $d = kop_daudit_decisions();
        unset($d[$key]);
        kop_daudit_save_decisions($d);
    }
    // One record: "A past name is only another name".
    $found = null;
    foreach (kop_daudit_proposals() as $k => $q) {
        foreach (kop_daudit_alias_candidates($q, $opts) as $pick => $label) {
            if (strpos($label, '(past name of') !== false) { $found = array((string) $k, (string) $pick); break 2; }
        }
    }
    if (!$found) {
        kop_rinbox_test_skip('data-audit: a past name is only another name', 'no past name on any card');
        return;
    }
    list($k, $pick) = $found;
    list($fid, $value) = explode('|', $pick, 2);
    try {
        $res = call_user_func(kop_rinbox_source('data-audit')['act'], $k, 'name_alias', array('pick' => $pick));
    } catch (Throwable $e) {
        $check('data-audit: a past name becomes another name', false, $e->getMessage());
        return;
    }
    $doc = json_decode((string) $pdo->query('SELECT json_data FROM facilities_v2 WHERE id = ' . (int) $fid)->fetchColumn(), true);
    $check('data-audit: a past name becomes another name', !in_array($value, (array) ($doc['identification']['pastNames'] ?? array()), true)
        && in_array($value, (array) ($doc['identification']['otherNames'] ?? array()), true), $res['message']);
    call_user_func(kop_rinbox_source('data-audit')['act'], $k, 'name_alias_undo', array());
    $doc2 = json_decode((string) $pdo->query('SELECT json_data FROM facilities_v2 WHERE id = ' . (int) $fid)->fetchColumn(), true);
    $check('data-audit: its Undo makes it a past name again', in_array($value, (array) ($doc2['identification']['pastNames'] ?? array()), true)
        && !in_array($value, (array) ($doc2['identification']['otherNames'] ?? array()), true));
    $d = kop_daudit_decisions();
    unset($d[$k]['aliases']);
    if (empty($d[$k])) unset($d[$k]);
    kop_daudit_save_decisions($d);
}
