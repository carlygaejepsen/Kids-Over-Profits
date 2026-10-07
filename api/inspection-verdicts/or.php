<?php
/**
 * Oregon: js/inspections/states/or.js report() and countFlagged() for a
 * facility holding one report. A substantiated abuse report (kind 'complaint')
 * is always flagged; a site visit is flagged by its New Findings text, or, with
 * no such section, by a checklist item with corrective wording. The regexes are
 * or.js's, run without the u flag so \b stays ASCII as in JavaScript.
 */

const KOP_IV_OR_ISSUE = '/\b(?:program|agency|facility|staff)\s+(?:shall|will|must|needs? to)\s+(?:ensure|complete|update|provide|develop|maintain|post|obtain|document|review|train|implement|correct|repair|replace|revise|create|submit)|\bmust be\b|\bneeding\b|\bdid not\b|\bfailed to\b|\bwas not\b|\bwere not\b|\bnot (?:completed|posted|documented|current|signed|available|present|in place)\b|\bmissing\b|\bout of compliance\b|\bexpired\b|\bcorrective action\b/i';
const KOP_IV_OR_NO_FINDINGS = '/^(?:none|n\/?a|na)\b|^no\s+(?:new\s+)?findings|program had no findings|there were no (?:new )?findings/i';
const KOP_IV_OR_WS = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

/** or.js normalizeWhitespace(). */
function kop_iv_or_norm($value) {
    $s = kop_iv_str($value);
    $s = str_replace(array("\r", "\xC2\xA0"), array("\n", ' '), $s);
    $s = preg_replace('/[ \t]+/', ' ', $s);
    $s = preg_replace('/\n{3,}/', "\n\n", $s);
    return kop_iv_trim($s);
}

function kop_iv_or(array $report) {
    $c = $report['categories'];

    if (($c['kind'] ?? null) === 'complaint') {
        $badges = array(kop_iv_badge('Substantiated', 'flagged'));
        if (($c['harm_resulted'] ?? null) === true) $badges[] = kop_iv_badge('Injury, sexual abuse or death resulted', 'flagged');
        return kop_iv_verdict('flagged', 1, $badges);
    }

    $newFindings = kop_iv_or_norm($c['new_findings'] ?? null);
    $status = 'neutral';
    if ($newFindings !== '') {
        $flat = preg_replace('/' . KOP_IV_OR_WS . '+/u', ' ', $newFindings);
        if ($flat === null) $flat = $newFindings;
        if (preg_match(KOP_IV_OR_NO_FINDINGS, $flat)) $status = 'clean';
        elseif (preg_match(KOP_IV_OR_ISSUE, $flat) || preg_match('/\b4[01][0-9]-[0-9]{3}-[0-9]{4}/', $flat)) $status = 'flagged';
    } else {
        foreach (kop_iv_list($c['findings'] ?? null) as $f) {
            $excerpt = kop_iv_truthy($f) ? (is_array($f) ? ($f['excerpt'] ?? null) : null) : $f;
            if (preg_match(KOP_IV_OR_ISSUE, kop_iv_or_norm($excerpt))) { $status = 'flagged'; break; }
        }
    }

    $badges = array();
    if ($status === 'flagged') $badges[] = kop_iv_badge('Corrections required', 'flagged');
    elseif ($status === 'clean') $badges[] = kop_iv_badge('No new findings', 'clean');
    return kop_iv_verdict($status, $status === 'flagged' ? 1 : 0, $badges);
}
