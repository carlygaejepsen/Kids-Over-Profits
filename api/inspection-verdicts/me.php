<?php
/**
 * Maine: js/inspections/states/me.js report() and countFlagged() for a facility
 * holding one report. A survey of an adult program is hidden by the page until
 * "Include adult programs" is chosen, so it has no verdict here (null).
 */

function kop_iv_me(array $report) {
    $c = $report['categories'];
    if (kop_iv_truthy($c['adult_program'] ?? null)) return null;

    $type = kop_iv_str($c['inspection_type'] ?? null);
    $outcome = function_exists('mb_strtoupper') ? mb_strtoupper(kop_iv_str($c['outcome'] ?? null), 'UTF-8') : strtoupper(kop_iv_str($c['outcome'] ?? null));
    $deficiencies = kop_iv_list($c['deficiencies'] ?? null);
    $count = kop_iv_int($c['deficiency_count'] ?? null) ?: count($deficiencies);

    $waived = preg_match('/waived/i', $type) === 1;
    $accepted = $outcome === 'ACCEPTED PLAN OF CORRECTION';
    $flagged = $accepted || $count > 0;

    if ($flagged) $tone = 'flagged';
    elseif ($waived) $tone = 'neutral';
    else $tone = $outcome === 'NO DEFICIENCIES' ? 'clean' : 'neutral';

    $badges = array();
    if ($deficiencies) {
        $badges[] = kop_iv_badge(kop_iv_plural($count, 'deficiency', 'deficiencies'), 'flagged');
    } elseif ($accepted) {
        $badges[] = kop_iv_badge('Deficiencies cited', 'flagged');
    } elseif ($waived) {
        $badges[] = kop_iv_badge('Survey waived', 'neutral');
    } elseif ($outcome === 'NO DEFICIENCIES') {
        $badges[] = kop_iv_badge('No deficiencies', 'clean');
    }

    return kop_iv_verdict($tone, $flagged ? max($count, 1) : 0, $badges);
}
