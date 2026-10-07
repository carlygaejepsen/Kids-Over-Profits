<?php
/**
 * Michigan: js/inspections/states/mi.js report() and countFlagged() for a
 * facility holding one report. Read from the scraper's categories only, so the
 * list's ?lite=1 rows (no text) give the same verdict.
 */

function kop_iv_mi_established($conclusion) {
    $c = function_exists('mb_strtolower') ? mb_strtolower(kop_iv_str($conclusion), 'UTF-8') : strtolower(kop_iv_str($conclusion));
    return strpos($c, 'established') !== false && strpos($c, 'not established') === false;
}

function kop_iv_mi(array $report) {
    $c = $report['categories'];
    $docType = $c['doc_type'] ?? null;
    $labels = array('special_investigation', 'renewal', 'interim', 'original', 'other');
    $docType = (is_string($docType) && in_array($docType, $labels, true)) ? $docType : 'other';

    $allegations = kop_iv_list($c['allegations'] ?? null);
    $established = kop_iv_int($c['violations_established'] ?? null);
    $repeat = false;
    foreach ($allegations as $a) {
        $conclusion = is_array($a) ? ($a['conclusion'] ?? null) : null;
        if (kop_iv_mi_established($conclusion) && preg_match('/repeat/i', kop_iv_str($conclusion)) === 1) { $repeat = true; break; }
    }
    $cap = kop_iv_truthy($c['cap_required'] ?? null);
    $cited = count(kop_iv_list($c['cited_rules'] ?? null));
    $inspection = $docType !== 'special_investigation';

    $flagged = $established > 0 || ($inspection && ($cap || $cited > 0));

    $badges = array();
    if (!$inspection) {
        if ($established) {
            $badges[] = kop_iv_badge(kop_iv_plural($established, 'violation') . ' established', 'flagged');
            if ($repeat) $badges[] = kop_iv_badge('Repeat violation', 'repeat');
        } elseif ($allegations) {
            $badges[] = kop_iv_badge('No violations established', 'clean');
        }
    } else {
        if ($cited) $badges[] = kop_iv_badge(kop_iv_plural($cited, 'rule') . ' cited', 'flagged');
        if ($cap) $badges[] = kop_iv_badge('Corrective action plan required', 'flagged');
        if (!$badges) $badges[] = kop_iv_badge('In compliance', 'clean');
    }
    $tone = $flagged ? 'flagged' : ($badges && $badges[0]['tone'] === 'clean' ? 'clean' : 'neutral');

    $count = $established ?: ($inspection && $flagged ? max(1, $cited) : 0);
    return kop_iv_verdict($tone, $count, $badges);
}
