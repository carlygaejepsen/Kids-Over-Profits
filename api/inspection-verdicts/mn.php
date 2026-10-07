<?php
/**
 * Minnesota: js/inspections/states/mn.js report() and countFlagged().
 * The document is read from its text: not retrieved (too short or a bot-check
 * page), its type from the heading (classifyDocument()), the violations cited
 * (extractViolations(), counted and checked for a repeat note), the maltreatment
 * disposition and any fine amount. Fields the verdict does not use (report
 * number, incident date, reported, actions) are not ported.
 */

require_once dirname(__DIR__) . '/lib-inspection-text-signals.php';

function kop_iv_mn_needs_text(array $cats) {
    return true;
}

/** The first $n UTF-16 units of $s (JavaScript's slice(0, n)). */
function kop_iv_mn_head($s, $n) {
    $u = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
    return strlen($u) <= $n * 2 ? $s : mb_convert_encoding(substr($u, 0, $n * 2), 'UTF-8', 'UTF-16LE');
}

/** The last $n UTF-16 units of $s. */
function kop_iv_mn_tail($s, $n) {
    $u = mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
    return strlen($u) <= $n * 2 ? $s : mb_convert_encoding(substr($u, -$n * 2), 'UTF-8', 'UTF-16LE');
}

/** mn.js flat(): whitespace collapsed to single spaces, trimmed. */
function kop_iv_mn_flat($text) {
    $r = preg_replace(kop_its_re('\s+', 'u'), ' ', (string) $text);
    return kop_iv_trim($r === null ? '' : $r);
}

function kop_iv_mn_classify($text, $fallback) {
    $head = kop_iv_mn_head((string) $text, 600);
    if (preg_match('/MALTREATMENT INVESTIGATION MEMORANDUM/iu', $head)) return 'Maltreatment Investigation';
    if (preg_match('/ORDER TO PAY A FINE/iu', $head)) return 'Fine Order';
    if (preg_match('/TEMPORARY IMMEDIATE SUSPENSION/iu', $head)) return 'Temporary Immediate Suspension';
    if (preg_match('/ORDER (?:OF|EXTENDING A CURRENT) CONDITIONAL LICENSE/iu', $head)) return 'Conditional License Order';
    if (preg_match('/Summary of Settlement Agreement/iu', $head)) return 'Settlement Agreement';
    if (preg_match('/LICENSE REVOCATION|REVOCATION OF LICENSE|ORDER OF LICENSE REVO/iu', $head)) return 'License Revocation';
    if (preg_match('/CORRECTION ORDER/iu', $head)) return 'Correction Order';
    if (preg_match('/NOTICE OF .*VIOLATION/iu', $head)) return 'Notice of Violation';
    if (preg_match('/maltreatment/iu', $fallback)) return 'Maltreatment Investigation';
    return $fallback !== '' ? $fallback : 'Document';
}

function kop_iv_mn_not_retrieved($text) {
    $s = kop_iv_mn_flat($text);
    if (kop_its_js_length($s) < 300) return true;
    return preg_match('/^PO Box 64242.*Veteran Friendly Employer/iu', $s) === 1
        || preg_match('/Verifying your browser/iu', kop_iv_mn_head($s, 200)) === 1;
}

/** mn.js grab(): the first group of $pattern over the flat text, flat again, or ''. */
function kop_iv_mn_grab($text, $pattern) {
    return preg_match($pattern, kop_iv_mn_flat($text), $m) ? kop_iv_mn_flat($m[1]) : '';
}

/** mn.js extractViolations(), as array(array('repeat' => bool)) (one entry per violation kept). */
function kop_iv_mn_violations($text) {
    $body = kop_iv_mn_flat($text);
    if (preg_match(kop_its_re('Written Response|YOUR RIGHT|Legal authority|Request for Reconsideration', 'iu'), $body, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 0) {
        $body = substr($body, 0, $m[0][1]);
    }
    $starts = array();
    if (preg_match_all(kop_its_re('(?:\b\d{1,2}\.\s*)?\bViolation:\s', 'u'), $body, $all, PREG_OFFSET_CAPTURE)) {
        foreach ($all[0] as $hit) {
            $before = kop_iv_mn_tail(substr($body, 0, $hit[1]), 7);
            if (preg_match(kop_its_re('Repeat\s*$', 'iuD'), $before)) continue;
            $starts[] = $hit[1];
        }
    }
    $labels = '/(?:(?:Rules?|Statutes?) Violated|Citation|Statutes?|Corrective Action (?:Required|Ordered)|Repeat Violation):/iu';
    $out = array();
    foreach ($starts as $i => $start) {
        $chunk = $i + 1 < count($starts) ? substr($body, $start, $starts[$i + 1] - $start) : substr($body, $start);
        $chunk = preg_replace(kop_its_re('^(?:\d{1,2}\.\s*)?Violation:\s*', 'u'), '', $chunk, 1);
        $chunk = preg_replace(kop_its_re('\s+[A-Z][A-Z ,&\/-]{3,}\s*$', 'uD'), '', $chunk, 1);
        $chunk = kop_iv_trim($chunk);
        $ruleM = preg_match(kop_its_re('(?:(?:Rules?|Statutes?) Violated|Citation|Statutes?):\s*([\s\S]+?)(?=Corrective Action (?:Required|Ordered):|Repeat Violation:|$)', 'iuD'), $chunk);
        $corrM = preg_match(kop_its_re('Corrective Action (?:Required|Ordered):\s*([\s\S]+?)(?=Repeat Violation:|$)', 'iuD'), $chunk);
        $repeatM = preg_match(kop_its_re('Repeat Violation:\s*([\s\S]+)$', 'iuD'), $chunk, $rm);
        $violation = preg_match($labels, $chunk, $lm, PREG_OFFSET_CAPTURE) ? substr($chunk, 0, $lm[0][1]) : $chunk;
        if (kop_iv_trim($violation) === '' || (!$ruleM && !$corrM)) continue;
        $out[] = array('repeat' => $repeatM && kop_iv_trim($rm[1]) !== '');
    }
    return $out;
}

function kop_iv_mn(array $report) {
    $cats = $report['categories'];
    $text = (string) $report['raw_content'];
    $missing = kop_iv_mn_not_retrieved($text);
    $type = $missing ? 'Document' : kop_iv_mn_classify($text, kop_iv_str($cats['doc_type'] ?? null));
    $violations = $missing ? array() : kop_iv_mn_violations($text);
    $isMalt = $type === 'Maltreatment Investigation';

    $disposition = $isMalt ? kop_iv_mn_grab($text, kop_its_re('Disposition[:\s]+(.+?)(?=\s+(?:License Number|Investigator|Program Type))', 'iu')) : '';
    $fine = '';
    if (preg_match('/Fine|Settlement|Conditional/u', $type)) {
        $fine = kop_iv_mn_grab($text, kop_its_re('(?:fine in the amount of|amount of the fine is|fine of)\s*\$?([\d,]+(?:\.\d+)?)', 'iu'));
    }

    $positive = preg_replace(kop_its_re('maltreatment\s+not\s+determined', 'iu'), '', $disposition);
    $determined = preg_match(kop_its_re('maltreatment\s+determined|substantiated', 'iu'), $positive) === 1;
    $adverse = in_array($type, array('Correction Order', 'Notice of Violation', 'Fine Order', 'Conditional License Order',
        'License Revocation', 'Temporary Immediate Suspension', 'Settlement Agreement'), true);
    $tone = $missing ? 'neutral' : ($adverse ? 'flagged' : ($isMalt && $determined ? 'flagged' : 'neutral'));

    $badges = array();
    if ($missing) {
        $badges[] = kop_iv_badge('Not retrieved', 'neutral');
    } elseif ($isMalt) {
        if ($determined) $badges[] = kop_iv_badge('Maltreatment determined', 'flagged');
        elseif (preg_match('/inconclusive/iu', $disposition)) $badges[] = kop_iv_badge('Inconclusive', 'neutral');
        elseif (preg_match(kop_its_re('not\s+determined', 'iu'), $disposition)) $badges[] = kop_iv_badge('Not determined', 'neutral');
    } elseif ($violations) {
        $badges[] = kop_iv_badge(kop_iv_plural(count($violations), 'violation'), 'flagged');
        foreach ($violations as $v) {
            if ($v['repeat']) { $badges[] = kop_iv_badge('Repeat', 'repeat'); break; }
        }
    } elseif ($adverse) {
        $badges[] = kop_iv_badge('Licensing action', 'flagged');
    }
    if ($fine !== '') $badges[] = kop_iv_badge('$' . $fine . ' fine', 'flagged');

    return kop_iv_verdict($tone, $tone === 'flagged' ? max(1, count($violations)) : 0, $badges);
}
