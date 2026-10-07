<?php
/**
 * South Dakota: js/inspections/states/sd.js report() and the report count of
 * isFlagged (the adapter has no countFlagged). A licensing study counts when a
 * section is listed as not met, a corrective action or compliance plan always,
 * an inspection form when an item is answered No; a scanned form whose answers
 * were not read is neutral. Reads no document text.
 */

function kop_iv_sd(array $report) {
    $cats = $report['categories'];
    $kind = $cats['kind'] ?? null;
    if (!is_string($kind) || !in_array($kind, array('licensing_study', 'corrective_action_plan', 'inspection'), true)) $kind = 'other';

    if ($kind === 'licensing_study') {
        $n = count(kop_iv_list($cats['not_met'] ?? null));
        $flagged = $n > 0;
        $badges = $n
            ? array(kop_iv_badge(kop_iv_plural($n, 'section') . ' not met', 'flagged'))
            : array(kop_iv_badge('No section marked not met', 'clean'));
        $tone = $flagged ? 'flagged' : 'clean';
    } elseif ($kind === 'corrective_action_plan') {
        $n = count(kop_iv_list($cats['items'] ?? null));
        $flagged = true;
        $badges = array(kop_iv_badge($n ? kop_iv_plural($n, 'finding') : 'Finding of noncompliance', 'flagged'));
        $status = kop_iv_str($cats['status'] ?? null);
        if ($status !== '') $badges[] = kop_iv_badge('Status: ' . $status, 'neutral');
        $tone = 'flagged';
    } elseif ($kind === 'inspection') {
        $result = kop_iv_str($cats['result'] ?? null);
        $unread = !($result === 'failed_items' || $result === 'all_met');
        $n = $unread ? 0 : count(kop_iv_list($cats['failed'] ?? null));
        $flagged = $n > 0;
        if ($unread) {
            $badges = array(kop_iv_badge(kop_iv_truthy($cats['scanned'] ?? null) ? 'Scanned form, answers not read' : 'Answers not read', 'neutral'));
            $tone = 'neutral';
        } elseif ($flagged) {
            $badges = array(kop_iv_badge(kop_iv_plural($n, 'item') . ' answered No', 'flagged'));
            $tone = 'flagged';
        } else {
            $badges = array(kop_iv_badge('No item answered No', 'clean'));
            $tone = 'clean';
        }
    } else {
        $flagged = false;
        $badges = array();
        $tone = 'neutral';
    }
    return kop_iv_verdict($tone, $flagged ? 1 : 0, $badges);
}
