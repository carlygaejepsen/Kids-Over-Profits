<?php
/**
 * Pennsylvania: js/inspections/states/pa.js report() and countFlagged().
 * A document counts when the scraper set categories.counts_as_violation; the
 * count is its citation count, at least 1. Reads no document text.
 */

function kop_iv_pa(array $report) {
    $cats = $report['categories'];
    $kind = $cats['kind'] ?? null;
    if (!is_string($kind) || !in_array($kind, array('citation', 'followup', 'sanction', 'clean', 'licence', 'waiver', 'other'), true)) $kind = 'other';
    $counted = kop_iv_truthy($cats['counts_as_violation'] ?? null);
    $n = kop_iv_int($cats['citation_count'] ?? null) ?: count(kop_iv_list($cats['citations'] ?? null));
    $repeat = kop_iv_int($cats['repeat_count'] ?? null);

    $badges = array();
    if ($kind === 'sanction') {
        $badges[] = kop_iv_badge('Licence action', 'flagged');
        if ($n) $badges[] = kop_iv_badge(kop_iv_plural($n, 'citation'), 'flagged');
    } elseif ($kind === 'citation') {
        $badges[] = kop_iv_badge($n ? kop_iv_plural($n, 'citation') : 'Citations', 'flagged');
    } elseif ($kind === 'followup') {
        if ($counted) $badges[] = kop_iv_badge(kop_iv_plural($n, 'citation'), 'flagged');
        $badges[] = kop_iv_badge('Plan of correction verified', 'neutral');
    } elseif ($kind === 'clean') {
        $badges[] = kop_iv_badge('No citations', 'clean');
    } elseif ($kind === 'licence') {
        $badges[] = kop_iv_badge('Licence issued', 'neutral');
    } elseif ($kind === 'waiver') {
        $badges[] = kop_iv_badge('Waiver granted', 'neutral');
    }
    if ($repeat) $badges[] = kop_iv_badge(kop_iv_plural($repeat, 'repeat violation'), 'repeat');

    $tone = $counted ? 'flagged' : ($kind === 'clean' ? 'clean' : 'neutral');
    return kop_iv_verdict($tone, $counted ? max(1, $n) : 0, $badges);
}
