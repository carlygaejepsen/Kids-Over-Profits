<?php
/**
 * Hawaii: js/inspections/states/hi.js report() and countFlagged().
 * Statement with deficiencies is flagged; no_deficiencies is clean; unread is neutral.
 */

function kop_iv_hi(array $report) {
    $cats = $report['categories'];
    $kind = kop_iv_str($cats['kind'] ?? '');

    if ($kind !== 'deficiencies' && $kind !== 'no_deficiencies') {
        $kind = 'unread';
    }

    $count = kop_iv_int($cats['deficiency_count'] ?? 0);
    if ($count === 0 && isset($cats['deficiencies']) && is_array($cats['deficiencies'])) {
        $count = count($cats['deficiencies']);
    }

    if ($kind === 'deficiencies') {
        $tone = 'flagged';
        $badges = array(kop_iv_badge(kop_iv_plural($count, 'deficiency', 'deficiencies'), 'flagged'));
        if (!empty($cats['ocr'])) {
            $badges[] = kop_iv_badge('Scanned', 'neutral');
        }
    } elseif ($kind === 'no_deficiencies') {
        $tone = 'clean';
        $badges = array(kop_iv_badge('No deficiencies', 'clean'));
    } else {
        $tone = 'neutral';
        $badges = array(kop_iv_badge('Not read', 'neutral'));
    }

    return kop_iv_verdict($tone, $count, $badges);
}
