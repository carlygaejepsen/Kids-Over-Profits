<?php
/**
 * Wyoming: js/inspections/states/wy.js report() and countFlagged() (the
 * number of flagged reports) for a facility holding one report. Notices and
 * health surveys only; visits are never flagged.
 */

function kop_iv_wy(array $report) {
    $c = $report['categories'];
    $kind = $c['kind'] ?? null;
    $kind = (is_string($kind) && in_array($kind, array('notice', 'visit', 'survey'), true)) ? $kind : 'other';
    $nonCompliance = kop_iv_truthy($c['non_compliance'] ?? null);
    $tags = count(kop_iv_list($c['tags'] ?? null));
    $outcome = kop_iv_str($c['outcome'] ?? null);
    $finding = kop_iv_str($c['finding'] ?? null);

    if ($kind === 'notice') $flagged = $nonCompliance;
    elseif ($kind === 'survey') $flagged = $tags > 0 || $outcome === 'cited';
    else $flagged = false;
    $unread = $kind === 'notice' ? $finding === '' : ($kind === 'survey' ? $outcome === 'unread' : false);

    if ($flagged) $tone = 'flagged';
    elseif ($kind === 'notice' && !$unread) $tone = 'clean';
    elseif ($kind === 'survey' && $outcome === 'clean') $tone = 'clean';
    else $tone = 'neutral';

    $badges = array();
    if ($kind === 'notice') {
        if ($nonCompliance) $badges[] = kop_iv_badge('Evidence supports non-compliance', 'flagged');
        elseif ($unread) $badges[] = kop_iv_badge('Finding not read from the scan', 'neutral');
        else $badges[] = kop_iv_badge('Evidence did not support non-compliance', 'clean');
    } elseif ($kind === 'visit') {
        $badges[] = kop_iv_badge('Handwritten form, not transcribed', 'neutral');
    } elseif ($kind === 'survey') {
        if ($tags) $badges[] = kop_iv_badge(kop_iv_plural($tags, 'deficiency', 'deficiencies') . ' cited', 'flagged');
        elseif ($outcome === 'cited') $badges[] = kop_iv_badge('Deficiencies cited', 'flagged');
        elseif ($outcome === 'clean') $badges[] = kop_iv_badge('No deficiencies cited', 'clean');
        else $badges[] = kop_iv_badge('Findings not read from the scan', 'neutral');
    }
    return kop_iv_verdict($tone, $flagged ? 1 : 0, $badges);
}
