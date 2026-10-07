<?php
/**
 * Idaho: js/inspections/states/id.js report() and countFlagged().
 * Statement with deficiencies is flagged; repeats return tone 'repeat';
 * no-deficiencies letters are clean.
 */

function kop_iv_id(array $report) {
    $cats = $report['categories'];
    $kind = $cats['kind'] ?? '';

    $kind = ($kind === 'deficiencies' ? 'deficiencies' : 'no_deficiencies');

    $count = kop_iv_int($cats['deficiency_count'] ?? 0);
    if ($count === 0 && isset($cats['deficiencies']) && is_array($cats['deficiencies'])) {
        $count = count($cats['deficiencies']);
    }

    $repeat_count = kop_iv_int($cats['repeat_count'] ?? 0);

    if ($kind === 'no_deficiencies') {
        $tone = 'clean';
        $badges = array(kop_iv_badge('No deficiencies', 'clean'));
    } else {
        $tone = $repeat_count ? 'repeat' : 'flagged';
        $badges = array(kop_iv_badge(kop_iv_plural($count, 'deficiency', 'deficiencies'), 'flagged'));
        if ($repeat_count) {
            $badges[] = kop_iv_badge(
                $repeat_count === 1 ? 'Repeat deficiency' : $repeat_count . ' repeat deficiencies',
                'repeat'
            );
        }
    }

    return kop_iv_verdict($tone, $count, $badges);
}
