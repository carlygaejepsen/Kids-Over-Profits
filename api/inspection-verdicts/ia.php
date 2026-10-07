<?php
/**
 * Iowa: js/inspections/states/ia.js report() and countFlagged().
 * A visit with cited > 0 is flagged. Complaint and incident investigations are marked.
 */

function kop_iv_ia_needs_text(array $cats) {
    // Verdict reads only cited, which comes from the state's count (doesn't need text).
    return false;
}

function kop_iv_ia(array $report) {
    $cats = $report['categories'];
    $visit_type = kop_iv_str($cats['visit_type'] ?? 'Survey');
    $is_complaint = !empty($cats['is_complaint']);
    $is_revisit = !empty($cats['is_revisit']);
    $complaint_numbers = kop_iv_list($cats['complaint_numbers'] ?? array());

    // State count: federal + state violations, or form's parsed tags when state's count is 0.
    $fed = kop_iv_int($cats['violations_fed'] ?? 0);
    $st = kop_iv_int($cats['violations_state'] ?? 0);
    $state_count = $fed + $st;
    $cited = kop_iv_int($cats['tag_count'] ?? 0);
    if ($cited === 0) {
        $cited = $state_count;
    }
    $has_pdf = !empty($report['report_url']) && preg_match('/fileName=/i', (string) $report['report_url']);

    // Determine visit group (for badges).
    $group = 'routine';
    if ($is_complaint || count($complaint_numbers) > 0) {
        $group = 'investigation';
    } elseif ($is_revisit || preg_match('/revisit/i', $visit_type)) {
        $group = 'revisit';
    } elseif (preg_match('/^initial/i', $visit_type)) {
        $group = 'initial';
    }

    $badges = array();

    // The visit type already names a complaint or incident visit; a recertification
    // that also investigated complaints gets a mark.
    if ($group === 'investigation' && !preg_match('/complaint|incident/i', $visit_type)) {
        $badges[] = kop_iv_badge('Complaints investigated', 'neutral');
    }

    if ($cited > 0) {
        $tone = 'flagged';
        $badges[] = kop_iv_badge(kop_iv_plural($cited, 'deficiency', 'deficiencies') . ' cited', 'flagged');
    } elseif (!$has_pdf) {
        $tone = 'neutral';
        $badges[] = kop_iv_badge('No report published', 'neutral');
    } else {
        $tone = 'clean';
        $badges[] = kop_iv_badge('No deficiencies cited', 'clean');
    }

    return kop_iv_verdict($tone, $cited, $badges);
}
