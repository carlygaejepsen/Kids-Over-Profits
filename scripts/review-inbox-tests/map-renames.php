<?php
/**
 * scripts/test-review-inbox.php checks for the 'map-renames' source
 * (inc/review-inbox/map-renames.php over inc/network-renames.php): Save with
 * a year and a swap stores both and splits the two names' years at that
 * year, a year out of range is refused, "Not a rename", and Undo after each.
 */

require_once __DIR__ . '/_shared.php';
require_once dirname(__DIR__, 2) . '/inc/network-renames.php';

function kop_rinbox_test_map_renames(array $src, array $item, callable $check) {
    $key = $item['key'];
    $save = $item['actions'][0];
    $check('map-renames: save asks the year and which name came first', $save['id'] === 'save' && $save['params'][1]['type'] === 'select'
        && count($save['params'][1]['options']) === 2, 'suggested ' . $save['params'][0]['value']);
    $labels = array_column($item['compare']['rows'] ?? array(), 'label');
    $check('map-renames: the two names side by side, with the years on the map now', ($item['compare']['heads'] ?? array()) === array('Earlier name', 'Later name')
        && in_array('Name', $labels, true) && in_array('On the map now', $labels, true), implode(', ', $labels));
    $counts = call_user_func($src['view_counts'], array());
    $check('map-renames: every tab has a count', array_keys($counts) == array_keys($src['views']) && $counts['review'] === call_user_func($src['count']), json_encode($counts));
    // A rename with research shows its sources, linked.
    foreach (kop_network_renames_candidates() as $ck => $c) {
        if (empty($c['sources'])) continue;
        $it = kop_rinbox_get_item('map-renames', $ck);
        $check('map-renames: the research\'s sources are on the card, linked', (bool) array_filter($it['details'], function ($d) { return !empty($d['url']); }), $ck);
        break;
    }
    try {
        call_user_func($src['act'], $key, 'save', array('year' => '1700', 'swapped' => '0'));
        $check('map-renames: a year out of range is refused', false);
    } catch (RuntimeException $e) {
        $check('map-renames: a year out of range is refused', true, $e->getMessage());
    }
    if (!kop_rinbox_test_options_persist()) {
        kop_rinbox_test_skip('map-renames: save, skip and undo', 'update_option does not store in this harness');
        return;
    }
    // The year is not needed to save a rename.
    $res = call_user_func($src['act'], $key, 'save', array('year' => '', 'swapped' => '1'));
    $d = kop_network_renames_decisions()[$key] ?? array();
    $check('map-renames: a rename saves with the year left blank, order kept', ($d['decision'] ?? '') === 'saved' && (int) $d['year'] === 0 && !empty($d['swapped']), $res['message']);
    $check('map-renames: saved without a year, the map keeps both names as they were',
        kop_network_renames_apply_years(array(explode('>', $key, 2)[0] => '1990-2000', explode('>', $key, 2)[1] => ''), array($key => $d)) === array());
    $it = kop_rinbox_get_item('map-renames', $key);
    $check('map-renames: its card says it was saved without a year', $it['status'] === 'saved' && strpos($it['status_label'], 'without a year') !== false, $it['status_label']);
    call_user_func($src['act'], $key, 'undo', array());
    $res = call_user_func($src['act'], $key, 'save', array('year' => '2004', 'swapped' => '1'));
    $d = kop_network_renames_decisions()[$key] ?? array();
    $check('map-renames: save stores the year and the swap', ($d['decision'] ?? '') === 'saved' && (int) $d['year'] === 2004 && !empty($d['swapped']), $res['message']);
    list($earlier, $later) = explode('>', $key, 2);
    $years = kop_network_renames_apply_years(array($earlier => '', $later => ''), array($key => $d));
    $check('map-renames: the names\' years split at the rename, the other way round', ($years[$later] ?? '') === 'until 2004' && ($years[$earlier] ?? '') === 'from 2004', json_encode($years));
    $check('map-renames: the item moves to Saved with Undo', kop_rinbox_get_item('map-renames', $key)['status'] === 'saved');
    call_user_func($src['act'], $key, 'undo', array());
    $check('map-renames: undo', !isset(kop_network_renames_decisions()[$key]) && kop_rinbox_get_item('map-renames', $key)['status'] === 'review');
    call_user_func($src['act'], $key, 'skip', array());
    $check('map-renames: not a rename', (kop_network_renames_decisions()[$key]['decision'] ?? '') === 'skipped');
    call_user_func($src['act'], $key, 'undo', array());
    $check('map-renames: undo "not a rename"', !isset(kop_network_renames_decisions()[$key]));

    call_user_func($src['act'], $key, 'save', array('year' => '2004', 'swapped' => '0'));
    $now = kop_rinbox_get_item('map-renames', $key)['compare']['rows'];
    $row = array_values(array_filter($now, function ($r) { return $r['label'] === 'On the map now'; }))[0] ?? array('values' => array());
    $check('map-renames: after saving, the card shows the split years', strpos((string) ($row['values'][0] ?? ''), '2004') !== false && strpos((string) ($row['values'][1] ?? ''), '2004') !== false, json_encode($row['values']));
    call_user_func($src['act'], $key, 'undo', array());

    // Save every high-confidence year, then undo them all.
    $sure = array();
    foreach (kop_rinbox_mren_rows() as $r) {
        if (!isset(kop_network_renames_decisions()[$r['key']]) && kop_rinbox_mren_sure(kop_network_renames_candidates()[$r['key']] ?? null)) $sure[] = $r['key'];
    }
    $res = call_user_func($src['tool'], 'save_sure', array());
    $saved = array_keys(array_filter(kop_network_renames_decisions(), function ($d) { return ($d['decision'] ?? '') === 'saved'; }));
    sort($saved);
    sort($sure);
    $check('map-renames: "save every high-confidence year" saves exactly those', $saved === $sure, count($sure) . ' sure; ' . $res['message']);
    kop_network_renames_decide(array_map(function ($k) { return array('key' => $k, 'decision' => 'undo'); }, $sure), 'test', kop_network_map_graph() ?: array());
    $check('map-renames: and they can be undone', !kop_network_renames_decisions());
}
