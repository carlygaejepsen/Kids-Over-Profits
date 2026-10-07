<?php
/**
 * Florida: js/inspections/states/fl.js report() and countFlagged().
 * AHCA surveys count their deficiency list (the placeholder "None" entry and
 * "INITIAL COMMENTS" do not count); DJJ QI and PREA reports read their findings
 * and the text signals (kop_its_fl_djj() in api/lib-inspection-text-signals.php,
 * the same values the lite API sends as report.text_signals). Pagination rows the
 * adapter skips return null. Dates are never printed in a badge.
 */

require_once dirname(__DIR__) . '/lib-inspection-text-signals.php';

/** fl.js flat(): whitespace runs to one space, trimmed. */
function kop_iv_fl_flat($v) {
    if ($v === null || $v === false || $v === '' || $v === 0) return '';
    if (is_array($v)) return '';
    return kop_iv_trim(preg_replace(kop_its_re('\s+'), ' ', (string) $v));
}

/** fl.js parseDate(d).getTime() > 0. */
function kop_iv_fl_date_valid($s) {
    $text = kop_iv_str($s);
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $text, $m)) return (int) $m[3] >= 1970;
    $months = array('january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december');
    if (preg_match('/^([A-Za-z]+)\s+(\d{1,2}),\s*(\d{4})/', $text, $m) && in_array(strtolower($m[1]), $months, true)) {
        return (int) $m[3] >= 1970;
    }
    return false;
}

function kop_iv_fl_is_ahca(array $cats) {
    return (kop_iv_truthy($cats['source'] ?? '') ? $cats['source'] : '') === 'AHCA';
}

function kop_iv_fl_needs_text(array $cats) {
    return !kop_iv_fl_is_ahca($cats);
}

function kop_iv_fl(array $report) {
    $cats = $report['categories'];
    $type = kop_iv_str($cats['report_type'] ?? null);

    if (kop_iv_fl_is_ahca($cats)) {
        if (preg_match('/^\d+\z/', $type) && !kop_iv_fl_date_valid($cats['survey_date'] ?? null)) return null;
        $count = 0;
        foreach (kop_iv_list($cats['deficiencies'] ?? null) as $d) {
            $d = is_array($d) ? $d : array();
            $code = kop_iv_str($d['deficiency'] ?? null);
            $req = kop_iv_fl_flat($d['requirement_description'] ?? null);
            if (preg_match('/^none\z/i', $code) && preg_match('/^(?:none)*\z/i', preg_replace(kop_its_re('\s+'), '', $req))) continue;
            if (preg_match('/^INITIAL COMMENTS\z/i', $req)) continue;
            $count++;
        }
        if ($count) return kop_iv_verdict('flagged', $count, array(kop_iv_badge(kop_iv_plural($count, 'deficiency', 'deficiencies'), 'flagged')));
        return kop_iv_verdict('clean', 0, array(kop_iv_badge('No deficiencies', 'clean')));
    }

    $sig = kop_its_fl_djj($report['raw_content']);
    $findings = kop_iv_list($cats['findings'] ?? null);

    if (strncmp($type, 'QI', 2) === 0) {
        $failed = 0;
        $count = 0;
        foreach ($findings as $f) {
            $rating = kop_iv_str(is_array($f) ? ($f['rating'] ?? null) : null);
            if (!preg_match('/failed|limited/i', $rating)) continue;
            $count++;
            if (preg_match('/failed/i', $rating)) $failed++;
        }
        if ($count) {
            $text = $failed
                ? kop_iv_plural($failed, 'failed indicator') . ($count > $failed ? ', ' . ($count - $failed) . ' limited' : '')
                : kop_iv_plural($count, 'limited indicator');
            return kop_iv_verdict('flagged', $count, array(kop_iv_badge($text, 'flagged')));
        }
        if (!$sig['failed_or_limited'] && $sig['satisfactory']) return kop_iv_verdict('clean', 0, array(kop_iv_badge('Satisfactory', 'clean')));
        return kop_iv_verdict('neutral', 0, array());
    }

    if ($type === 'PREA') {
        $items = 0;
        foreach ($findings as $f) {
            $rating = is_array($f) ? ($f['rating'] ?? '') : '';
            if (kop_iv_truthy($rating) && !is_array($rating) && preg_match('/does not meet/i', (string) $rating)) $items++;
        }
        $notMet = $sig['not_met'];
        $count = $notMet ? $notMet : $items;
        if ($count > 0) return kop_iv_verdict('flagged', $count, array(kop_iv_badge(kop_iv_plural($count, 'standard') . ' not met', 'flagged')));
        if ($notMet === 0) return kop_iv_verdict('clean', 0, array(kop_iv_badge('All standards met', 'clean')));
        return kop_iv_verdict('neutral', 0, array());
    }

    return kop_iv_verdict('neutral', 0, array());
}
