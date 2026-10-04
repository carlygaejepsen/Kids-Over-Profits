<?php
/**
 * scripts/test-review-inbox.php checks for the 'inspection-links' source
 * (inc/review-inbox/inspection-links.php over inc/inspection-links.php):
 * the suggestions of every state in one list, "Same facility" stores the
 * link and moves the pair to Linked, Remove puts it back in the
 * suggestions, "Not this one" keeps it out.
 */

require_once __DIR__ . '/_shared.php';
require_once dirname(__DIR__, 2) . '/inc/inspection-links.php';

if (kop_rinbox_test_wants('inspection-links')) {
    // inspection_reports is too big to copy; the suggestions only count rows in it.
    $GLOBALS['pdo']->exec('CREATE TABLE IF NOT EXISTS inspection_reports (id INTEGER PRIMARY KEY, facility_id INTEGER, report_id TEXT, report_date TEXT, report_url TEXT,
        raw_content TEXT, content_length INTEGER, is_structured INTEGER, summary TEXT, categories_json TEXT, created_at TEXT, updated_at TEXT, featured INTEGER, featured_note TEXT)');
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
    if ($saved === null) unset($GLOBALS['kop_test_options'][KOP_INSPECTION_LINKS_OPTION]);
    else $GLOBALS['kop_test_options'][KOP_INSPECTION_LINKS_OPTION] = $saved;
}
