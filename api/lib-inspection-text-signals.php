<?php
/**
 * What a state's report page reads from a report's full text when the list
 * loads, worked out on the server so the list can be sent without the text
 * (api/inspections-read.php?lite=1). The full text is then fetched only for a
 * report someone opens.
 *
 * Each function here is a port of the JavaScript that reads the same thing in
 * the state adapter, and must return exactly what it returns:
 *   NC  readStatement()     js/inspections/states/nc.js
 *   FL  djjTextSignals()    js/inspections/states/fl.js
 * scripts/test-inspection-text-signals.js runs both over every NC and FL
 * report in tmp/prod.sqlite and fails on any difference. Change them together.
 *
 * Porting notes: JavaScript's \s also matches non-breaking and other Unicode
 * spaces, so the patterns use KOP_ITS_S in its place; JavaScript's \b and
 * case-insensitive matching are ASCII-word based, which PCRE's /u without UCP
 * also is. String lengths are counted in UTF-16 units, as JavaScript does.
 */

if (!defined('KOP_ITS_S_CHARS')) {
    // JavaScript's \s: ASCII whitespace plus the Unicode space separators.
    define('KOP_ITS_S_CHARS', '\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}');
    define('KOP_ITS_S', '[' . KOP_ITS_S_CHARS . ']');
}

/** Bumped whenever a function below changes; part of the lite cache key. */
function kop_its_version() {
    return 1;
}

/** States whose page reads signals from the text at load. */
function kop_its_states() {
    return array('NC', 'FL');
}

function kop_inspection_text_signals($state, $text) {
    $text = (string) $text;
    switch ($state) {
        case 'NC': return kop_its_nc_statement($text);
        case 'FL': return kop_its_fl_djj($text);
    }
    return null;
}

/** JavaScript's String.prototype.trim(). */
function kop_its_trim($s) {
    return preg_replace('/^' . KOP_ITS_S . '+|' . KOP_ITS_S . '+$/u', '', $s);
}

/** JavaScript's str.length (UTF-16 code units). */
function kop_its_js_length($s) {
    return intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
}

/**
 * A JavaScript pattern body as a PCRE pattern, with each \s widened to
 * JavaScript's set: a bracketed class outside [...], bare characters inside
 * one (so [\s\S] and [a-z,\s-] stay single classes).
 */
function kop_its_re($body, $flags = 'u') {
    $out = '';
    $inClass = false;
    $len = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        $c = $body[$i];
        if ($c === '\\' && $i + 1 < $len) {
            $next = $body[$i + 1];
            $out .= $next === 's' ? ($inClass ? KOP_ITS_S_CHARS : KOP_ITS_S) : $c . $next;
            $i++;
            continue;
        }
        if ($c === '[' && !$inClass) $inClass = true;
        elseif ($c === ']' && $inClass) $inClass = false;
        $out .= $c;
    }
    return '/' . $out . '/' . $flags;
}

// ---- North Carolina: readStatement() ---------------------------------------

function kop_its_nc_statement($text) {
    $NOT_MET    = kop_its_re('(?:Rule|Requirement|Standard|Statute)\s+(?:is|are|was)\s+not\s+met\s+as\s+evidenced\s+by', 'iu');
    $SAID_NONE  = kop_its_re('\bno\s+deficienc(?:y|ies)\s+(?:was|were)\s+(?:cited|found|identified)', 'iu');
    $SAID_CITED = kop_its_re('\bdeficienc(?:y|ies)\s+(?:was|were)\s+cited', 'iu');
    $ATTEMPTED  = kop_its_re('survey\s+was\s+attempted', 'iu');

    $citations = (int) preg_match_all($NOT_MET, $text);
    $saysNone = preg_match($SAID_NONE, $text) === 1;
    if (!$citations && !$saysNone && preg_match($SAID_CITED, $text) === 1) {
        $citations = 1;
    }
    return array(
        'citations' => $citations,
        'clean'     => !$citations && $saysNone,
        'attempted' => !$citations && preg_match($ATTEMPTED, $text) === 1,
        'complaint' => kop_its_nc_complaint($text),
        'opening'   => kop_its_nc_opening($text),
    );
}

function kop_its_nc_complaint($text) {
    $re = kop_its_re('complaints?\s+(?:\([^)]{0,80}\)\s*)?(?:was|were|is|are)\s+(un)?substantiated|allegations?\s+(?:\([^)]{0,80}\)\s*)?(?:was|were)\s+(un)?substantiated|(un)?substantiated\s+complaint', 'iu');
    $found = array('substantiated' => false, 'unsubstantiated' => false);
    if (preg_match_all($re, $text, $sets, PREG_SET_ORDER)) {
        foreach ($sets as $m) {
            if (!empty($m[1]) || !empty($m[2]) || !empty($m[3])) {
                $found['unsubstantiated'] = true;
            } else {
                $found['substantiated'] = true;
            }
        }
    }
    return $found;
}

function kop_its_nc_opening($text) {
    $OPENING = kop_its_re('\b(?:An?|The)\s+(?:[a-z,\s-]{0,60})?survey\s+was\s+(?:completed|attempted|conducted)[\s\S]{0,900}?(?:deficienc(?:y|ies)\s+(?:was|were)\s+(?:cited|found|identified)\.|(?=\n\s*\n\s*This facility is licensed))', 'iu');
    $FORM_CHROME = '/PREFIX|\(X\d\)|STATEMENT OF DEFICIENCIES|Division of Health Service|PROVIDER\'S PLAN/iu';
    if (preg_match($OPENING, $text, $m) !== 1) {
        return '';
    }
    $sentence = preg_replace(kop_its_re('\s*\n\s*'), ' ', $m[0]);
    $sentence = kop_its_trim(preg_replace(kop_its_re('\s+'), ' ', $sentence));
    if (preg_match($FORM_CHROME, $sentence) === 1 || kop_its_js_length($sentence) > 700) {
        return '';
    }
    return $sentence;
}

// ---- Florida DJJ: djjTextSignals() -----------------------------------------

function kop_its_fl_djj($text) {
    $t = kop_its_trim(preg_replace(kop_its_re('\s+'), ' ', $text));   // flat()
    $DATE = '([A-Za-z]+\\s+\\d{1,2},\\s*\\d{4})';
    $date = '';
    foreach (array('Date of Final Audit Report:\s*', 'Date of Report:?\s*', 'Date of Interim Audit Report:\s*', 'Date of facility visit:\s*') as $lead) {
        if (preg_match(kop_its_re($lead . $DATE, 'iu'), $t, $m) === 1) {
            $date = $m[1];
            break;
        }
    }
    $num = static function ($body) use ($t) {
        return preg_match(kop_its_re($body, 'iu'), $t, $m) === 1 ? (int) $m[1] : null;
    };
    $period = preg_match(kop_its_re('SPEP Review Period:\s*([A-Za-z]+ \d{1,2}, \d{4}\s*-\s*[A-Za-z]+ \d{1,2}, \d{4})', 'iu'), $t, $pm) === 1 ? $pm[1] : '';
    $name = preg_match(kop_its_re('Name of (?:facility|program):\s*(.{3,80}?)\s+(?:Physical|Mailing)\s+address', 'iu'), $t, $nm) === 1 ? $nm[1] : '';
    return array(
        'failed_or_limited' => preg_match('/Failed Compliance|Limited Compliance/iu', $text) === 1,
        'satisfactory'      => preg_match('/Satisfactory Compliance/iu', $text) === 1,
        'prea_date'         => $date,
        'exceeded'          => $num('Number of Standards Exceeded:\s*(\d+)'),
        'met'               => $num('Number of Standards Met:\s*(\d+)'),
        'not_met'           => $num('Number of Standards Not Met:\s*(\d+)'),
        'spep_period'       => $period,
        'facility_name'     => $name,
    );
}
