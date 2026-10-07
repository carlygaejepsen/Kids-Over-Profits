<?php
/**
 * Montana: js/inspections/states/mt.js report() and countFlagged() for a
 * facility holding one survey. The row's categories are the survey object
 * ({Header, Issues}) the static file holds; the verdict needs only readSurvey()'s
 * rule list: one rule per Issues[].Rule part, repeats from the row or the title.
 * A survey with no Header is dropped (null).
 */

function kop_iv_mt(array $report) {
    $raw = $report['categories'];
    if (!kop_iv_truthy($raw['Header'] ?? null)) return null;

    $rules = 0;
    $repeats = 0;
    foreach (kop_iv_list($raw['Issues'] ?? null) as $i) {
        if (!is_array($i)) continue;
        $rule = kop_iv_str($i['Rule'] ?? null);
        if ($rule === '') continue;
        $parts = preg_split('/\s+(?=[0-9]+\.[0-9]+\.[0-9]+-[0-9]+\s)/u', $rule);
        if ($parts === false) $parts = array($rule);
        foreach ($parts as $part) {
            $text = kop_iv_str($part);
            if (preg_match('/^([0-9]+\.[0-9]+\.[0-9]+)(?:-([0-9]+))?\s*([^\n\r\x{2028}\x{2029}]*)$/Du', $text, $m)) {
                $colon = strpos($m[3], ':');
                $topic = $colon === false ? '' : kop_iv_str(substr($m[3], $colon + 1));
            } else {
                $topic = $text;
            }
            $rules++;
            $r = $i['Repeat Deficiency'] ?? null;
            if ($r === true || $r === 'Yes' || preg_match('/\s+repeat\s+deficiency\b[^\n\r\x{2028}\x{2029}]*$/Diu', $topic)) $repeats++;
        }
    }

    $badges = array();
    if ($rules) $badges[] = kop_iv_badge(kop_iv_plural($rules, 'deficiency', 'deficiencies'), 'flagged');
    if ($repeats) $badges[] = kop_iv_badge(kop_iv_plural($repeats, 'repeat'), 'repeat');
    return kop_iv_verdict($rules ? 'flagged' : 'neutral', $rules, $badges);
}
