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
}
