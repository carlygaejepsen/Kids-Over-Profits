<?php
/**
 * Connecticut: js/inspections/states/ct.js readReport(), report() and isFlagged().
 * Field visit forms are read from their "Areas of regulatory non-compliance"
 * section, letters from the sentences DCF uses; the badge counts the citations
 * (citationItems() in the adapter, only how many are needed here).
 */

require_once dirname(__DIR__) . '/lib-inspection-text-signals.php';

/** The adapter's last regex match (with its byte offset), or null. */
function kop_iv_ct_last_match($re, $text) {
    if (!preg_match_all($re, $text, $m, PREG_OFFSET_CAPTURE) || empty($m[0])) return null;
    return end($m[0]);
}

/** ct.js sectionAfter(): the text after a heading up to the next heading or signature. */
function kop_iv_ct_section_after($text, $match) {
    if ($match === null) return null;
    $rest = substr($text, $match[1] + strlen($match[0]));
    $re = kop_its_re('\n[ \t]*(?:corrective\s+actions?\s+implemented|corrections\s+implemented|recommendations?\s*:|list\s+of\s+areas|areas?\s+of\s+regulatory\s+non|a\s+copy\s+of\s+this\s+summary|regulatory\s+consultant\b|cc:)|\n[ \t]*[A-Z][A-Za-z.\'-]+(?:[ \t]+[A-Z][A-Za-z.,\'-]+){0,4}[ \t]*(?:\t|[ ]{2,})[ \t_]*(?:date:?)?[ \t_]*\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}', 'iu');
    if (preg_match($re, $rest, $m, PREG_OFFSET_CAPTURE)) return substr($rest, 0, $m[0][1]);
    return $rest;
}

/** ct.js flat(). */
function kop_iv_ct_flat($text) {
    $s = kop_iv_str($text);
    $s = preg_replace('/[\x{2022}\x{f0b7}\t]/u', ' ', $s);
    $s = preg_replace('/_{3,}/u', ' ', $s);
    $s = preg_replace(kop_its_re('\s+'), ' ', $s);
    return kop_iv_trim($s);
}

function kop_iv_ct_needs_text(array $cats) {
    return true;
}

/** @return array(outcome, citation count) */
function kop_iv_ct_read($text) {
    if (preg_match(kop_its_re('Field\s+Visit\s+Reporting\s+Form|DCF-3034|TIME\s+OF\s+VISIT|List\s+of\s+Areas\s*\/\s*Topics', 'iu'), $text)) {
        return kop_iv_ct_read_form($text);
    }
    // readLetter()
    if (preg_match(kop_its_re('^\s*DCF\s+Corrective\s+Action\s+Plan\b', 'iu'), $text)) return array('neutral', 0);
    if (preg_match(kop_its_re('\bno\s+areas?\s+of\s+(?:regulatory\s+)?non-?compliance\s+were\s+(?:identified|found|noted)', 'iu'), $text)) return array('clean', 0);
    if (preg_match(kop_its_re('\bin\s+compliance\s+with\s+all\s+applicable\s+regulatory\s+provisions\s+except\b|\bplease\s+review\s+(?:the\s+)?(?:areas?|sections?)\s+(?:of\s+(?:regulatory\s+)?non-?compliance\s+)?identified|\b(?:below\s+are|listed\s+below\s+are|the\s+following\s+are)\b[^.]*\bareas?\s+of\s+(?:regulatory\s+)?non-?compliance|\bfollowing\s+areas?\s+(?:shall|must|should)\s+be\s+addressed|\bareas?\s+of\s+(?:regulatory\s+)?non-?compliance\s+(?:which|that)\s+were\s+identified', 'iu'), $text)) return array('flagged', 0);
    if (preg_match(kop_its_re('\b(?:determined|found)\s+(?:that\s+)?(?:your\s+)?(?:agency\'?s?\s+)?(?:program|facility|agency)?\s*(?:is|was|to\s+be)\s+in\s+(?:full\s+|substantial\s+)?compliance\b|\ball\s+areas\s+of\s+the\s+program\s+(?:are|were)\s+in\s+compliance\b|\bdemonstrated\s+continued\s+compliance\b', 'iu'), $text)) return array('clean', 0);
    return array('neutral', 0);
}

function kop_iv_ct_read_form($text) {
    $ncMatch = kop_iv_ct_last_match(kop_its_re('areas?\s+of\s+regulatory\s+non[-\s]?compliance\s+identified[^:\n]*:?', 'iu'), $text);
    if ($ncMatch === null) {
        $ncMatch = kop_iv_ct_last_match(kop_its_re('(?:^|\n)[ \t\x{2022}?-]*areas?\s+of\s+regulatory\s+non[-\s]?compliance[^:\n]*:?', 'iu'), $text);
    }
    $ncRaw = kop_iv_ct_section_after($text, $ncMatch);
    $nc = null;
    $ncFlat = null;
    if ($ncRaw !== null) {
        $nc = preg_replace(kop_its_re('please\s+submit\s+a[^.]*?(?:plan|\(rcp\)|\(sdp\))[^.]*?within\s+\d+\s+days[^.]*\.(?:\s*the\s+[^.]*?must\s+be\s+submitted[^.]*\.)?', 'iu'), ' ', $ncRaw);
        $nc = preg_replace(kop_its_re('-?\s*\bno\s+(?:sdp|rcp|(?:licensing\s+)?regulat\w*\s+compliance\s+plan|service\s+development\s+plan|plan\s+of\s+correction)(?:\s+(?:is\s+)?required)?(?:\s+following\s+this\s+licensing\s+visit)?(?:\s+from\s+(?:the\s+)?(?:last|previous)\s+visit)?\s*[.:-]?', 'iu'), ' ', $nc);
        $ncFlat = preg_replace(kop_its_re('^[\s.:;-]+|[\s.:;-]+$', 'uD'), '', kop_iv_ct_flat($nc));
    }

    $SAYS_NONE = kop_its_re('^[o?\x{2022}\x{f0b7}\-\s]*(?:n\/?a|none|not\s+applicable|not\s+at\s+the\s+time|nothing\s+(?:was\s+)?(?:identified|noted|observed)|no\s+(?:regulatory\s+)?(?:deficienc\w+|non-?compliance|areas?|concerns?|issues?|violations?|citations?)|there\s+were\s+no\s+(?:regulatory\s+)?(?:citations?|deficienc\w+|violations?))\b', 'iu');
    $CITES = kop_its_re('\bsec(?:tion|\.)\s+\d+[a-z]?\s*-\s*\d+|\b17[a-z]?\s*-\s*\d+(?:\s*-\s*\d+)?\b|\b\d{2}[a-z]-\d+|(?:^|\s)\d{2,3}\.?\s+[A-Z][a-z]+(?:\s+[a-z]+){0,4}[.,:]', 'u');
    $SIGNATURE_ONLY = kop_its_re('^(?:[A-Z][A-Za-z.\'-]*[\s,]*){1,4}(?:LCSW|MSW|LMSW)?[\s,]*(?:[Dd]ate:?)?[\s_]*(?:\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}|[A-Z][a-z]+\s+\d{1,2},?\s+\d{4})?[\s_]*$', 'uD');

    if ($ncFlat === null && preg_match(kop_its_re('\bno\s+(?:licensing\s+)?regulat\w*\s+compliance\s+plan\s+is\s+required\b', 'iu'), $text)) return array('clean', 0);
    if ($ncFlat === null) return array('neutral', 0);
    if ($ncFlat === '' || preg_match($SIGNATURE_ONLY, $ncFlat)) return array('clean', 0);
    $cites = preg_match($CITES, $ncFlat) === 1;
    if (preg_match($SAYS_NONE, $ncFlat) && !$cites) return array('clean', 0);
    if ($cites) {
        // citationItems(): one item per citation that opens an item
        $items = (int) preg_match_all(kop_its_re('(^|\n|[.;]\s+)[ \t\x{2022}?o-]*((?:section|sec\.)\s*)?(\d{2}[a-z]?\s*-\s*\d+(?:\s*-\s*\d+)?)\b[ \t.:-]*', 'iu'), $nc);
        return array('flagged', $items);
    }
    if (count(preg_split(kop_its_re('\s+'), $ncFlat)) < 6) return array('neutral', 0);
    return array('flagged', 0);
}

function kop_iv_ct(array $report) {
    $text = kop_iv_str($report['raw_content']);
    $text = str_replace("\r", '', $text);
    $text = preg_replace('/[\x{0}-\x{8}\x{b}\x{c}\x{e}-\x{1f}\x{f000}-\x{f8ff}]/u', ' ', $text);
    list($outcome, $count) = kop_iv_ct_read($text);

    $badges = array();
    if ($outcome === 'flagged') $badges[] = kop_iv_badge($count ? kop_iv_plural($count, 'citation') : 'Non-compliance found', 'flagged');
    elseif ($outcome === 'clean') $badges[] = kop_iv_badge('No non-compliance', 'clean');

    return kop_iv_verdict($outcome, $outcome === 'flagged' ? 1 : 0, $badges);
}
