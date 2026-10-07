<?php
/**
 * Georgia: js/inspections/states/ga.js report() and countFlagged().
 * The verdict is the number of cited rules in the Statement of Deficiencies
 * (parseStatement(): one block per tag line, the opening 0000 and closing 9999
 * blocks not counted) and whether any block's evidence says "previously cited".
 * The evidence is the lines after the block's "This Requirement is not met as
 * evidenced by:" line; reflow() only joins them with spaces, which cannot
 * change a match of "previously cited", so the lines are joined directly.
 */

require_once dirname(__DIR__) . '/lib-inspection-text-signals.php';

function kop_iv_ga_needs_text(array $cats) {
    return true;
}

/** ga.js statementText(): the text, or '' when it is too short or the viewer's export widget. */
function kop_iv_ga_text($raw) {
    $raw = kop_iv_str($raw);
    if (kop_its_js_length($raw) < 400) return '';
    if (preg_match('/Export to the selected format|Generating report/iu', $raw)) return '';
    return $raw;
}

/** ga.js parseStatement(): array of array('tag' =>, 'repeat' =>) for every block. */
function kop_iv_ga_blocks($text) {
    $chrome = kop_its_re('^(?:TAG|NUMBER|SUMMARY OF STATEMENT OF DEFICIENCIES PLAN OF CORRECTION|\d{1,2}\/\d{1,2}\/\d{4} \d{1,2}:\d{2}:\d{2} [AP]M \d+|Completed Date\s*:\s*_*)$', 'uD');
    $tagLine = kop_its_re('^(\d{4})\s+Severity\s*:\s*(\S+)\s+Survey Type\(s\)\s*:', 'u');
    $notMet = 'This Requirement is not met as evidenced by:';

    $blocks = array();
    $cur = null;
    foreach (explode("\n", str_replace("\r", '', $text)) as $line) {
        $line = kop_iv_trim($line);
        if ($line === '' || preg_match($chrome, $line)) continue;
        if (preg_match($tagLine, $line, $m)) {
            $blocks[] = array('tag' => $m[1], 'lines' => array());
            $cur = count($blocks) - 1;
        } elseif ($cur !== null) {
            $blocks[$cur]['lines'][] = $line;
        }
    }
    $out = array();
    foreach ($blocks as $b) {
        $repeat = false;
        $at = array_search($notMet, $b['lines'], true);
        if ($at !== false) {
            $evidence = implode(' ', array_slice($b['lines'], $at + 1));
            $repeat = preg_match('/previously cited/iu', $evidence) === 1;
        }
        $out[] = array('tag' => $b['tag'], 'repeat' => $repeat);
    }
    return $out;
}

function kop_iv_ga(array $report) {
    $text = kop_iv_ga_text($report['raw_content']);
    $count = 0;
    $repeat = false;
    if ($text !== '') {
        foreach (kop_iv_ga_blocks($text) as $b) {
            if ($b['tag'] === '0000' || $b['tag'] === '9999') continue;
            $count++;
            if ($b['repeat']) $repeat = true;
        }
    }
    $badges = array();
    if ($text === '') {
        $tone = 'neutral';
        $badges[] = kop_iv_badge('See official report', 'neutral');
    } elseif ($count) {
        $tone = 'flagged';
        $badges[] = kop_iv_badge(kop_iv_plural($count, 'violation'), 'flagged');
        if ($repeat) $badges[] = kop_iv_badge('Repeat', 'repeat');
    } else {
        $tone = 'clean';
        $badges[] = kop_iv_badge('No violations', 'clean');
    }
    return kop_iv_verdict($tone, $count, $badges);
}
