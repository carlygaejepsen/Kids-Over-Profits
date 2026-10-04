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
    $check('map-years: accept offers the researched years to edit, and "still operating"', $accept['id'] === 'accept'
        && array_column($accept['params'], 'name') === array('start', 'end', 'still'),
        $accept['params'][0]['value'] . '-' . $accept['params'][1]['value']);
    $c = kop_network_years_candidates()[$key];
    $quotes = array_filter($item['details'], function ($d) { return !empty($d['url']); });
    $want = count(array_filter((array) ($c['sources'] ?? array()), function ($x) { return !empty($x['url']); }));
    $check('map-years: every source is on the card, linked', count($quotes) === $want, $want . ' sources');
    $counts = call_user_func($src['view_counts'], array());
    $check('map-years: every tab has a count', array_keys($counts) == array_keys($src['views']) && $counts['review'] === call_user_func($src['count']), json_encode($counts));
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

    call_user_func($src['act'], $key, 'accept', array('start' => '1990', 'end' => '2001', 'still' => '1'));
    $check('map-years: "still operating" leaves the closing year off', (kop_network_years_decisions()[$key]['years'] ?? '') === 'from 1990');
    call_user_func($src['act'], $key, 'undo', array());

    // Accept every high-confidence year, then undo them all.
    $high = array();
    foreach (kop_network_years_candidates() as $id => $c) {
        if (($c['confidence'] ?? '') === 'high' && (!empty($c['start']) || !empty($c['end'])) && !isset(kop_network_years_decisions()[$id])) $high[] = (string) $id;
    }
    $res = call_user_func($src['tool'], 'accept_high', array());
    $accepted = array_filter(kop_network_years_decisions(), function ($d) { return ($d['decision'] ?? '') === 'accepted'; });
    $check('map-years: "accept every high-confidence year" accepts them all', count($accepted) === count($high) && $high, count($high) . ' high; ' . $res['message']);
    kop_network_years_decide(array_map(function ($id) { return array('id' => $id, 'decision' => 'undo'); }, $high), 'test');
    $check('map-years: and they can be undone', !array_filter(kop_network_years_decisions(), function ($d) { return ($d['decision'] ?? '') === 'accepted'; }));
}
