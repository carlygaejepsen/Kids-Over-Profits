<?php
/**
 * scripts/test-review-inbox.php checks for the 'inspection-links' source
 * (inc/review-inbox/inspection-links.php over inc/inspection-links.php):
 * the suggestions of every state in one list, "Same facility" stores the
 * link and moves the pair to Linked, Remove puts it back in the
 * suggestions, "Not this one" keeps it out. A program with homes under it
 * is one record ("Same program"), its homes get no card of their own, and
 * an entry can be linked to a program by hand.
 */

require_once __DIR__ . '/_shared.php';
require_once dirname(__DIR__, 2) . '/inc/inspection-links.php';
require_once dirname(__DIR__, 2) . '/inc/program-homes.php';

if (kop_rinbox_test_wants('inspection-links')) {
    // inspection_reports is too big to copy; the suggestions only count rows in it.
    $GLOBALS['pdo']->exec('CREATE TABLE IF NOT EXISTS inspection_reports (id INTEGER PRIMARY KEY, facility_id INTEGER, report_id TEXT, report_date TEXT, report_url TEXT,
        raw_content TEXT, content_length INTEGER, is_structured INTEGER, summary TEXT, categories_json TEXT, created_at TEXT, updated_at TEXT, featured INTEGER, featured_note TEXT)');
    // Programs and their homes, so a program comes as one card.
    kop_rinbox_test_copy_tables(array('wpdl_kop_program_homes', 'wpdl_kop_program_groups'));
    $GLOBALS['kop_test_options']['kop_program_homes_db'] = KOP_PROGRAM_HOMES_DB_VERSION;
}

function kop_rinbox_test_inspection_links(array $src, array $item, callable $check) {
    $key = $item['key'];
    list($fid, $rid) = array_map('intval', explode('-', $key));
    $states = array();
    foreach (kop_rinbox_ilinks_pairs() as $p) $states[$p['state']] = true;
    $check('inspection-links: one list across states', count($states) > 1, implode(',', array_keys($states)));
    $check('inspection-links: the list is cached', is_array(get_transient('kop_rinbox_inspection_links')));
    $check('inspection-links: record and licensing entry side by side', count($item['compare']['heads'] ?? array()) === 2
        && in_array('Town or address', array_column($item['compare']['rows'], 'label'), true));
    $pairs = kop_rinbox_ilinks_pairs();
    $same = array_keys(array_filter($pairs, function ($p) { return $p['same_town']; }));
    if ($same) {
        $check('inspection-links: a same-town pair starts ticked', kop_rinbox_get_item('inspection-links', $same[0])['selected'] === true);
    }
    $other = array_keys(array_filter($pairs, function ($p) { return !$p['same_town']; }));
    if ($other) {
        $check('inspection-links: another pair starts unticked', kop_rinbox_get_item('inspection-links', $other[0])['selected'] === false);
    }
    // The State filter: only that state's pairs.
    $state = $pairs[$key]['state'];
    $filter = $src['filters'][0] ?? array();
    $check('inspection-links: a State filter with every state that has pairs', ($filter['name'] ?? '') === 'state' && isset($filter['options'][$state]), count($filter['options'] ?? array()) . ' states');
    $res = call_user_func($src['list'], array('view' => 'suggested', 'search' => '', 'offset' => 0, 'limit' => 100, 'filters' => array('state' => $state)));
    $in = count(array_filter($pairs, function ($p) use ($state) { return $p['state'] === $state; }));
    $check('inspection-links: the State filter keeps only that state', $res['total'] === $in && $res['total'] < count($pairs), $state . ': ' . $res['total']);
    $counts = call_user_func($src['view_counts'], array());
    $check('inspection-links: every tab has a count', array_keys($counts) == array_keys($src['views']), json_encode($counts));
    if (!kop_rinbox_test_options_persist()) {
        kop_rinbox_test_skip('inspection-links: link, remove, reject', 'update_option does not store in this harness');
        return;
    }
    $saved = $GLOBALS['kop_test_options'][KOP_INSPECTION_LINKS_OPTION] ?? null;
    $res = call_user_func($src['act'], $key, 'link', array());
    $got = kop_rinbox_get_item('inspection-links', $key);
    $check('inspection-links: same facility links it', in_array($rid, kop_inspection_links_for($fid), true) && $got['status'] === 'linked'
        && !isset(kop_rinbox_ilinks_pairs()[$key]), $res['message']);
    $res = call_user_func($src['act'], $key, 'unlink', array());
    $got = kop_rinbox_get_item('inspection-links', $key);
    $check('inspection-links: remove puts it back in the suggestions', !kop_inspection_links_for($fid) && $got['status'] === 'suggested', $res['message']);
    call_user_func($src['act'], $key, 'reject', array());
    $stored = kop_inspection_links_get();
    $check('inspection-links: not this one keeps it out', in_array($rid, array_map('intval', $stored['rejected'][$fid] ?? array()), true)
        && !isset(kop_rinbox_ilinks_pairs()[$key]));
    $got = kop_rinbox_get_item('inspection-links', $key);
    $check('inspection-links: it is on the Not this one tab', $got['status'] === 'rejected' && $got['actions'][0]['id'] === 'unreject');
    $res = call_user_func($src['act'], $key, 'unreject', array());
    $check('inspection-links: "suggest it again" puts it back', isset(kop_rinbox_ilinks_pairs()[$key])
        && !in_array($rid, array_map('intval', kop_inspection_links_get()['rejected'][$fid] ?? array()), true), $res['message']);

    // Link every same-town pair of one state.
    $want = array();
    foreach (kop_rinbox_ilinks_pairs() as $k => $p) if ($p['same_town'] && $p['state'] === $state) $want[] = $k;
    $before = array_keys(kop_rinbox_ilinks_decided('links'));
    $res = call_user_func($src['tool'], 'link_same_town', array('state' => $state));
    $linked = array_values(array_diff(array_keys(kop_rinbox_ilinks_decided('links')), $before));
    sort($want);
    sort($linked);
    $check('inspection-links: "link every same-town pair" links exactly those of the state', $linked === $want, count($want) . ' pairs; ' . $res['message']);

    // Programs with homes: one card, linked as a unit.
    $map = kop_program_homes_map(true);
    $pairs = kop_rinbox_ilinks_pairs();
    $program_pairs = array_filter($pairs, function ($p) { return !empty($p['record']['homes']); });
    $home_ids = array();
    foreach ($program_pairs as $p) foreach ($p['record']['homes'] as $h) $home_ids[$h['id']] = $p['record']['id'];
    $own_cards = array_filter($pairs, function ($p) use ($home_ids) { return isset($home_ids[$p['record']['id']]); });
    $check('inspection-links: programs with homes come as one card', count($program_pairs) > 0, count($program_pairs) . ' program pairs');
    $check('inspection-links: their homes have no cards of their own', !$own_cards, count($own_cards) . ' home pairs');
    if ($program_pairs) {
        $pk = array_keys($program_pairs)[0];
        $pp = $program_pairs[$pk];
        $item = kop_rinbox_get_item('inspection-links', $pk);
        $check('inspection-links: a program card says "same program"', $item['actions'][0]['label'] === 'Approve: same program', $pp['record']['name']);
        $res = call_user_func($src['act'], $pk, 'link', array());
        $check('inspection-links: same program links the entry to the program record', in_array($pp['row']['id'], kop_inspection_links_for($pp['record']['id']), true), $res['message']);
        call_user_func($src['act'], $pk, 'unlink', array());
        // Any row already shown by a home is never offered to the program.
        $reached = array();
        foreach ($program_pairs as $p) foreach ($p['record']['homes'] as $h) foreach (kop_inspection_links_for($h['id']) as $rid) $reached[] = $p['record']['id'] . '-' . $rid;
        $check('inspection-links: a home\'s linked entries are not offered to its program', !array_intersect($reached, array_keys($program_pairs)));
    }
    $from_home = array_filter($pairs, function ($p) { return !empty($p['home']); });
    if ($from_home) {
        $hk = array_keys($from_home)[0];
        $hp = $from_home[$hk];
        $res = call_user_func($src['act'], $hk, 'link_home', array());
        $check('inspection-links: "only <home>" links the entry to that home', in_array($hp['row']['id'], kop_inspection_links_for($hp['home']['id']), true)
            && !in_array($hp['row']['id'], kop_inspection_links_for($hp['record']['id']), true) && !isset(kop_rinbox_ilinks_pairs()[$hk]), $res['message']);
        kop_inspection_links_save(array('unlink' => array($hp['home']['id'] . '-' . $hp['row']['id'])));
    } else {
        kop_rinbox_test_skip('inspection-links: "only <home>"', 'no entry suggested from a home in the mirror');
    }
    // By hand: a program and any entry, given as the picker's "Name, address #id".
    $pid = (int) array_keys($map['programs'])[0];
    $n = kop_inspection_links_save(array('manual_record' => (string) $pid, 'manual_row' => 'Anything, Somewhere #' . $rid));
    $check('inspection-links: an entry linked to a program by hand', $n === 1 && in_array($rid, kop_inspection_links_for($pid), true));
    kop_inspection_links_save(array('unlink' => array($pid . '-' . $rid)));

    if ($saved === null) unset($GLOBALS['kop_test_options'][KOP_INSPECTION_LINKS_OPTION]);
    else $GLOBALS['kop_test_options'][KOP_INSPECTION_LINKS_OPTION] = $saved;
}
