<?php
/**
 * Maryland: js/inspections/states/md.js report() and countFlagged().
 * A report with citation_count > 0 is flagged (citations that may present safety risks
 * are shown separately in badges). No text reading needed.
 */

function kop_iv_md(array $report) {
    $cats = $report['categories'];

    // Count citations from all blocks.
    $safety = kop_iv_list($cats['safety_citations'] ?? array());
    $other = kop_iv_list($cats['other_citations'] ?? array());
    $unrated = kop_iv_list($cats['unrated_citations'] ?? array());

    $safety_count = count($safety);
    $other_count = count($other);
    $unrated_count = count($unrated);
    $citation_count = $safety_count + $other_count + $unrated_count;

    $badges = array();

    if ($safety_count > 0) {
        $badges[] = kop_iv_badge($safety_count . ' may present safety risks', 'flagged');
    }
    if ($other_count > 0) {
        $badges[] = kop_iv_badge($other_count . ' cited, not an imminent risk', 'neutral');
    }
    if ($unrated_count > 0) {
        $badges[] = kop_iv_badge(kop_iv_plural($unrated_count, 'citation') . ', not rated', 'flagged');
    }
    if ($citation_count === 0) {
        $badges[] = kop_iv_badge('No COMAR violations', 'clean');
    }

    // Count corrective action plans.
    $cap_count = 0;
    foreach (array_merge($safety, $other, $unrated) as $c) {
        $status = kop_iv_str($c['status'] ?? '');
        if (preg_match('/\bcap\b/i', $status)) {
            $cap_count++;
        }
    }
    if ($cap_count > 0) {
        $badges[] = kop_iv_badge(kop_iv_plural($cap_count, 'corrective action plan'), 'neutral');
    }

    $tone = ($citation_count > 0) ? 'flagged' : 'clean';

    // countFlagged() adds safety citations / 1000 to sort facilities; the hub
    // counts violations, so the whole number (the harness compares floors).
    return kop_iv_verdict($tone, $citation_count, $badges);
}
