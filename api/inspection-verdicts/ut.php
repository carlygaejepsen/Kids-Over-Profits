<?php
/**
 * Utah: js/inspections/states/ut.js report() and countFlagged() for a database
 * row. The findings are the lines of raw_content that read "R380-80-5(4): Rule
 * title <em dash> Finding text" (readApiReport(); a continuation line only
 * extends a finding's text, so only the finding lines are counted). A row with
 * no usable "Inspection Date" (mm/dd/yyyy, else report_date) is dropped by
 * merge(). The data files the page also loads are not on the hub.
 */

require_once dirname(__DIR__) . '/lib-inspection-text-signals.php';

function kop_iv_ut_needs_text(array $cats) {
    return true;
}

function kop_iv_ut(array $report) {
    $cats = $report['categories'];
    $date = kop_iv_truthy($cats['Inspection Date'] ?? '') ? $cats['Inspection Date'] : $report['report_date'];
    if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/D', kop_iv_str($date))) return null;

    $finding = kop_its_re('^(R\d[A-Za-z0-9_.()\-]*):\s*([^\n\r\x{2028}\x{2029}]*?)\s+\x{2014}\s+[^\n\r\x{2028}\x{2029}]*$', 'uD');
    $count = 0;
    foreach (explode("\n", kop_iv_str($report['raw_content'])) as $line) {
        if (preg_match($finding, kop_iv_trim($line))) $count++;
    }
    $badge = $count ? kop_iv_badge(kop_iv_plural($count, 'finding'), 'flagged') : kop_iv_badge('No findings', 'clean');
    return kop_iv_verdict($count ? 'flagged' : 'clean', $count, array($badge));
}
