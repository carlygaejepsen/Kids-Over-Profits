<?php
/**
 * Nevada: js/inspections/states/nv.js report() and isFlagged().
 * Nevada licensing records list inspection dates but not findings,
 * so nothing can be flagged as violation. Always returns neutral tone.
 */

function kop_iv_nv(array $report) {
    $cats = $report['categories'];
    $badges = array();

    // Add badges for complaint and grade, like the adapter does in report().
    $reason = kop_iv_str($cats['inspection_reason'] ?? '');
    $is_complaint = (bool) preg_match('/^Complaint\s*-/i', $reason);
    if ($is_complaint) {
        $badges[] = kop_iv_badge('Complaint', 'neutral');
    }

    $grade = kop_iv_str($cats['grade'] ?? '');
    if ($grade && !preg_match('/^(n\/a|null|none)$/i', $grade)) {
        $badges[] = kop_iv_badge('Grade ' . $grade, 'neutral');
    }

    // No findings in the data; isFlagged always returns false.
    return kop_iv_verdict('neutral', 0, $badges);
}
