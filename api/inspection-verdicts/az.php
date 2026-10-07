<?php
/**
 * Arizona: js/inspections/states/az.js readReport(), report() and countFlagged().
 * A deficiency list in categories flags the report; without one the Department's
 * opening sentence (raw_content, RTF hex escapes decoded) says none (clean) or
 * some (flagged). Only the decoding the two regexes can see is ported.
 */

require_once dirname(__DIR__) . '/lib-inspection-text-signals.php';

/** az.js: deficiencies that survive the filter in readReport(). */
function kop_iv_az_deficiency_count(array $cats) {
    $n = 0;
    foreach (kop_iv_list($cats['deficiencies'] ?? null) as $d) {
        if (!is_array($d)) continue;
        if (kop_iv_truthy($d['rule'] ?? '') || kop_iv_truthy($d['evidence'] ?? '') || kop_iv_truthy($d['findings'] ?? '')) $n++;
    }
    return $n;
}

function kop_iv_az_needs_text(array $cats) {
    return kop_iv_az_deficiency_count($cats) === 0;
}

/** az.js clean(): "\'a7" -> the character with that code. */
function kop_iv_az_clean($value) {
    return preg_replace_callback('/\\\'([0-9a-f]{2})/i', static function ($m) {
        return mb_chr(hexdec($m[1]), 'UTF-8');
    }, kop_iv_str($value));
}

function kop_iv_az(array $report) {
    $count = kop_iv_az_deficiency_count($report['categories']);
    if ($count) {
        return kop_iv_verdict('flagged', $count, array(kop_iv_badge(kop_iv_plural($count, 'deficiency', 'deficiencies'), 'flagged')));
    }
    $opening = kop_iv_trim(preg_replace(kop_its_re('\s*\n\s*'), "\n", kop_iv_az_clean($report['raw_content'])));
    if (preg_match(kop_its_re('\bno\s+deficienc(?:y|ies)\s+(?:was|were)\s+(?:found|cited)', 'iu'), $opening)) {
        return kop_iv_verdict('clean', 0, array(kop_iv_badge('No deficiencies', 'clean')));
    }
    if (preg_match(kop_its_re('\bdeficien\w*\s+(?:was|were)\s+(?:found|cited)', 'iu'), $opening)) {
        return kop_iv_verdict('flagged', 1, array(kop_iv_badge('Deficiencies found', 'flagged')));
    }
    return kop_iv_verdict('neutral', 0, array());
}
