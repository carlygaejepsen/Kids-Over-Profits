<?php
/**
 * Washington: js/inspections/states/wa.js readRecord(), report() and isFlagged().
 * The outcome is read from the document text (an enforcement action needs none):
 * "no deficiencies were identified" is clean, "deficiencies were identified", a
 * Statement of Deficiencies or deficiency-table rows with a WAC rule are flagged.
 *
 * Not ported: markMisattached(), which compares a facility's records that share
 * one document. A record whose own case number is missing from a document
 * another record owns shows "No document on file" (neutral, no count) on the
 * page; here, alone, it reads the borrowed text, so the hub shows that
 * document's verdict for it. Records the scraper already marked
 * (categories.document_owner_case) are handled.
 */

require_once dirname(__DIR__) . '/lib-inspection-text-signals.php';

/** wa.js deficiencyRows(): how many table rows cite a WAC rule. */
function kop_iv_wa_rows($text) {
    $lines = explode("\n", (string) $text);
    $rowRe = kop_its_re('^(\d{4})\s+([A-Z][A-Za-z ,\/&()-]{3,60}?)(?:\s{2,}|\s+Based on|\s+(?:Staff|Record|Observation|Interview)|$)', 'uD');
    $wacRe = kop_its_re('WAC\s+\d{3}-\d{3}-\d{4}(?:\([0-9a-z]+\))*', 'u');
    $seen = array();
    $n = 0;
    foreach ($lines as $i => $line) {
        if (!preg_match($rowRe, $line, $m) || isset($seen[$m[1]])) continue;
        $nearby = implode(' ', array_slice($lines, $i, 3));
        if (!preg_match($wacRe, $nearby)) continue;
        $seen[$m[1]] = true;
        $n++;
    }
    return $n;
}

function kop_iv_wa_needs_text(array $cats) {
    return kop_iv_str($cats['report_category'] ?? null) !== 'enforcement';
}

function kop_iv_wa(array $report) {
    $cats = $report['categories'];
    $text = (string) $report['raw_content'];
    $category = kop_iv_str($cats['report_category'] ?? null);
    $misattached = kop_iv_str($cats['document_owner_case'] ?? null) !== '';

    $rows = 0;
    $outcome = 'neutral';
    if ($category === 'enforcement') {
        $outcome = 'flagged';
    } else {
        $rows = kop_iv_wa_rows($text);
        if (preg_match(kop_its_re('\bno\s+(?:current\s+)?deficienc(?:y|ies)\s+(?:was|were)\s+(?:identified|found|cited|noted)', 'iu'), $text)) $outcome = 'clean';
        elseif ($rows || preg_match(kop_its_re('\bdeficienc(?:y|ies)\s+(?:was|were)\s+(?:identified|found|cited|noted)|statement of deficienc', 'iu'), $text)) $outcome = 'flagged';
    }

    $badges = array();
    if ($misattached) $badges[] = kop_iv_badge('No document on file', 'neutral');
    elseif ($category === 'enforcement') $badges[] = kop_iv_badge('Enforcement action', 'flagged');
    elseif ($outcome === 'flagged') $badges[] = kop_iv_badge($rows ? kop_iv_plural($rows, 'deficiency', 'deficiencies') : 'Deficiencies found', 'flagged');
    elseif ($outcome === 'clean') $badges[] = kop_iv_badge('No deficiencies', 'clean');

    return kop_iv_verdict($misattached ? 'neutral' : $outcome, $outcome === 'flagged' ? 1 : 0, $badges);
}
