<?php
/**
 * North Carolina: js/inspections/states/nc.js report() and countFlagged().
 * A statement of deficiencies is read from its text (readStatement(), ported
 * as kop_its_nc_statement() in api/lib-inspection-text-signals.php); a plan of
 * correction is the facility's answer and carries no count.
 */

require_once dirname(__DIR__) . '/lib-inspection-text-signals.php';

/** nc.js isStatement(cats.document_type || report.summary). */
function kop_iv_nc_is_statement(array $cats, $summary) {
    $doc = kop_iv_truthy($cats['document_type'] ?? '') ? (string) $cats['document_type'] : (string) $summary;
    return preg_match('/defic|defen|statement/i', $doc) === 1;
}

function kop_iv_nc_needs_text(array $cats) {
    return kop_iv_nc_is_statement($cats, '') || !kop_iv_truthy($cats['document_type'] ?? '');
}

function kop_iv_nc(array $report) {
    $cats = $report['categories'];
    $statement = kop_iv_nc_is_statement($cats, $report['summary']);
    $read = $statement
        ? kop_its_nc_statement($report['raw_content'])
        : array('citations' => 0, 'clean' => false, 'attempted' => false, 'complaint' => array());

    $badges = array();
    $tone = 'neutral';
    if (!$statement) {
        $badges[] = kop_iv_badge('Facility response', 'neutral');
    } elseif ($read['citations']) {
        $tone = 'flagged';
        $badges[] = kop_iv_badge(kop_iv_plural($read['citations'], 'violation'), 'flagged');
    } elseif ($read['clean']) {
        $tone = 'clean';
        $badges[] = kop_iv_badge('No violations', 'clean');
    } elseif ($read['attempted']) {
        $badges[] = kop_iv_badge('Survey not completed', 'neutral');
    }
    if (!empty($read['complaint']['substantiated'])) $badges[] = kop_iv_badge('Complaint substantiated', 'flagged');
    elseif (!empty($read['complaint']['unsubstantiated'])) $badges[] = kop_iv_badge('Complaint unsubstantiated', 'neutral');

    return kop_iv_verdict($tone, $read['citations'], $badges);
}
