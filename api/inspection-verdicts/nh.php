<?php
/**
 * New Hampshire: js/inspections/states/nh.js report() and countFlagged().
 * A visit counts one for every rule not met (categories.items); the badges
 * say how many of the rules reviewed, and how many were founded but already
 * put right. Reads no document text.
 */

function kop_iv_nh(array $report) {
    $cats = $report['categories'];
    $items = kop_iv_list($cats['items'] ?? null);
    $compliance = isset($cats['compliance']) && is_array($cats['compliance']) ? $cats['compliance'] : array();
    $reviewed = kop_iv_int($cats['rules_reviewed'] ?? null) ?: kop_iv_int($compliance['reviewed'] ?? null);
    $n = count($items);

    if ($n) {
        $badges = array(kop_iv_badge($n . ' of ' . kop_iv_plural($reviewed, 'rule') . ' not met', 'flagged'));
        $resolved = 0;
        foreach ($items as $item) {
            $result = is_array($item) ? kop_iv_str($item['result'] ?? null) : '';
            if ($result !== '' && preg_match('/^non-?compliant$/iD', $result) !== 1) $resolved++;
        }
        if ($resolved) $badges[] = kop_iv_badge($resolved . ' founded, problem resolved', 'neutral');
        return kop_iv_verdict('flagged', $n, $badges);
    }
    if ($reviewed) return kop_iv_verdict('clean', 0, array(kop_iv_badge('All ' . kop_iv_plural($reviewed, 'rule') . ' met', 'clean')));
    return kop_iv_verdict('neutral', 0, array(kop_iv_badge('No rules reviewed', 'neutral')));
}
