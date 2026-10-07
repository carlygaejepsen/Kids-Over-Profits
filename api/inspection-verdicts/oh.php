<?php
/**
 * Ohio: js/inspections/states/oh.js report() and countFlagged() for a facility
 * holding one report. Read from the scraper's categories only (the list loads
 * with ?lite=1), so no text is needed.
 */

function kop_iv_oh(array $report) {
    $c = $report['categories'];
    $findings = kop_iv_list($c['findings'] ?? null);
    $n = count($findings) ?: kop_iv_int($c['finding_count'] ?? null);
    $cap = kop_iv_int($c['cap_finding_count'] ?? null);
    $residential = kop_iv_int($c['residential_finding_count'] ?? null);
    $assistance = kop_iv_int($c['assistance_count'] ?? null);
    $stub = kop_iv_truthy($c['stub'] ?? null);

    $badges = array();
    if ($n) {
        $badges[] = kop_iv_badge(kop_iv_plural($n, 'finding'), 'flagged');
        if ($residential) $badges[] = kop_iv_badge($residential . ' residential', 'flagged');
        if ($cap) $badges[] = kop_iv_badge('Corrective action plan needed', 'flagged');
    } elseif ($stub) {
        $badges[] = kop_iv_badge('No findings published', 'neutral');
    } else {
        $badges[] = kop_iv_badge('No findings of noncompliance', 'clean');
    }
    if ($assistance) $badges[] = kop_iv_badge(kop_iv_plural($assistance, 'technical assistance item'), 'neutral');

    $tone = $n > 0 ? 'flagged' : ($stub ? 'neutral' : 'clean');
    return kop_iv_verdict($tone, $n, $badges);
}
