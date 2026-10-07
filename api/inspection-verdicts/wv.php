<?php
/**
 * West Virginia: js/inspections/states/wv.js report() and countFlagged().
 * A survey with tag_count > 0 is flagged. Complaint surveys are marked. Clean when
 * no tags are cited (or only corrected tags) or comments say no deficiencies.
 */

function kop_iv_wv(array $report) {
    $cats = $report['categories'];
    $tag_count = kop_iv_int($cats['tag_count'] ?? 0);
    $is_complaint = !empty($cats['is_complaint']);
    $corrected = kop_iv_int($cats['corrected_count'] ?? 0);

    $badges = array();

    if ($is_complaint) {
        $badges[] = kop_iv_badge('Complaint survey', 'neutral');
    }

    if ($tag_count > 0) {
        $tone = 'flagged';
        $badges[] = kop_iv_badge(kop_iv_plural($tag_count, 'deficiency', 'deficiencies') . ' cited', 'flagged');
    } elseif ($corrected > 0) {
        $tone = 'clean';
        $badges[] = kop_iv_badge('Earlier deficiencies corrected', 'clean');
    } else {
        $tone = 'clean';
        $badges[] = kop_iv_badge('No deficiencies cited', 'clean');
    }

    return kop_iv_verdict($tone, $tag_count, $badges);
}
