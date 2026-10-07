<?php
/**
 * Oklahoma: js/inspections/states/ok.js report() and countFlagged().
 * A visit counts one for every non-compliance, a complaint one for every
 * requirement found not met (categories.items); an attempted visit says
 * nothing; an item the state marks NRS makes the tone a repeat. Reads no
 * document text.
 */

function kop_iv_ok(array $report) {
    $cats = $report['categories'];
    $complaint = ($cats['kind'] ?? null) === 'complaint';
    $items = kop_iv_list($cats['items'] ?? null);
    $n = count($items);
    $nrs = 0;
    $extra = 0;
    foreach ($items as $item) {
        $item = is_array($item) ? $item : array();
        if (kop_iv_truthy($item['nrs'] ?? null)) $nrs++;
        if (preg_match('/course of investigation/i', kop_iv_str($item['finding'] ?? null)) === 1) $extra++;
    }
    $attempted = !$complaint && preg_match('/^attempted$/iD', kop_iv_str($cats['visit_type'] ?? null)) === 1;
    $flagged = $complaint || $n > 0;

    if ($nrs) $tone = 'repeat';
    elseif ($flagged) $tone = 'flagged';
    else $tone = $attempted ? 'neutral' : 'clean';

    if ($complaint) {
        $badges = array(kop_iv_badge(kop_iv_plural($n, 'requirement') . ' not met', 'flagged'));
        if ($extra) $badges[] = kop_iv_badge($extra . ' found during the investigation', 'flagged');
    } elseif ($attempted) {
        $badges = array(kop_iv_badge('Not carried out', 'neutral'));
    } elseif (!$n) {
        $badges = array(kop_iv_badge('No non-compliances observed', 'clean'));
    } else {
        $badges = array(kop_iv_badge(kop_iv_plural($n, 'non-compliance'), 'flagged'));
        if ($nrs) $badges[] = kop_iv_badge($nrs . ' numerous, repeated or serious', 'repeat');
    }
    return kop_iv_verdict($tone, $n, $badges);
}
