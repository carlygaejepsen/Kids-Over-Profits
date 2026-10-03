<?php
/**
 * scripts/test-review-inbox.php checks for the 'map-years' source
 * (inc/review-inbox/map-years.php over inc/network-years.php): Accept with
 * edited years stores them and they go over the map's years, a year out of
 * range is refused, Reject, and Undo after each.
 */

require_once __DIR__ . '/_shared.php';
require_once dirname(__DIR__, 2) . '/inc/network-years.php';

function kop_rinbox_test_map_years(array $src, array $item, callable $check) {
    $key = $item['key'];
    $accept = $item['actions'][0];
    $check('map-years: accept offers the researched years to edit', $accept['id'] === 'accept' && count($accept['params']) === 2,
        $accept['params'][0]['value'] . '-' . $accept['params'][1]['value']);
    try {
        call_user_func($src['act'], $key, 'accept', array('start' => '1650', 'end' => ''));
        $check('map-years: a year out of range is refused', false);
    } catch (RuntimeException $e) {
        $check('map-years: a year out of range is refused', true, $e->getMessage());
    }
    if (!kop_rinbox_test_options_persist()) {
        kop_rinbox_test_skip('map-years: accept, reject and undo', 'update_option does not store in this harness');
        return;
    }
    $res = call_user_func($src['act'], $key, 'accept', array('start' => '1990', 'end' => '2001'));
    $d = kop_network_years_decisions()[$key] ?? array();
    $got = kop_rinbox_get_item('map-years', $key);
    $check('map-years: accept stores the edited years', ($d['decision'] ?? '') === 'accepted' && $d['years'] === '1990-2001'
        && $got['status'] === 'accepted' && $got['actions'][0]['id'] === 'undo', $res['message']);
    $check('map-years: they go over the map\'s years', kop_network_map_apply_year_overrides(array(array('id' => $key, 'years' => '')), array($key => $d['years']))[0]['years'] === '1990-2001');
    call_user_func($src['act'], $key, 'undo', array());
    $check('map-years: undo', !isset(kop_network_years_decisions()[$key]) && kop_rinbox_get_item('map-years', $key)['status'] === $item['status']);
    call_user_func($src['act'], $key, 'reject', array());
    $check('map-years: reject', (kop_network_years_decisions()[$key]['decision'] ?? '') === 'rejected');
    call_user_func($src['act'], $key, 'undo', array());
    $check('map-years: undo a rejection', !isset(kop_network_years_decisions()[$key]));
}
