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
    $types = array('field', 'list_add', 'list_remove', 'note', 'map_years', 'operator_link', 'operator_field', 'links_move', 'person_merge', 'memorial', 'manual');
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
        }
        $by_fid = array();
        foreach ($p['ops'] as $op) {
            if (in_array($op['type'], array('field', 'list_add', 'list_remove', 'note'), true)) $by_fid[(int) $op['facility_id']][] = $op;
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
            $ids === array('rename_merge', 'rename_merge_reversed', 'reject'), $key . ': ' . implode(',', $ids));
        break;
    }

    // The card.
    $own = kop_rinbox_test_own_actions($item);
    $check('data-audit: a waiting card offers approve and reject', in_array($item['status'], array('high', 'review'), true) && array_slice($own, 0, 2) === array('approve', 'reject'), implode(',', $own));
    $p = $props[$item['key']];
    $changes = array_filter($item['details'], function ($d) { return strpos($d['label'], 'Change ') === 0; });
    $check('data-audit: every change is on the card in words', count($changes) === count($p['ops']));
    $linked = array_filter($item['details'], function ($d) { return !empty($d['url']); });
    $want = count(array_filter((array) ($p['sources'] ?? array()), function ($s) { return !empty($s['url']); }));
    $check('data-audit: every source is on the card, linked', count($linked) === $want, $want . ' sources');
    $counts = call_user_func($src['view_counts'], array());
    $check('data-audit: every tab has a count', array_keys($counts) == array_keys($src['views']) && $counts['high'] + $counts['review'] === call_user_func($src['count']), json_encode($counts));
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
