<?php
/**
 * Virginia: js/inspections/states/va.js report() and countFlagged().
 * A VDSS inspection counts one for every violation its page lists; a DBHDS
 * report one for every corrective action plan row rated N or NS. The agency is categories.source (every stored report has
 * one; without it the VDSS keys decide). Reads no document text.
 */

/** va.js normalizeComp(): "N N" -> "N", "ns" -> "NS". */
function kop_iv_va_comp($value) {
    $tokens = preg_split('/[\s,\/]+/', strtoupper(kop_iv_str($value)));
    foreach (array('NS', 'N', 'ND', 'C') as $want) {
        if (in_array($want, $tokens, true)) return $want;
    }
    return '';
}

function kop_iv_va(array $report) {
    $cats = $report['categories'];
    $source = $cats['source'] ?? null;
    if ($source !== 'dbhds' && $source !== 'vdss') {
        $source = (array_key_exists('violations', $cats) || array_key_exists('violation_count', $cats) || array_key_exists('listed_with_violations', $cats)) ? 'vdss' : 'dbhds';
    }

    if ($source === 'vdss') {
        $n = max(count(kop_iv_list($cats['violations'] ?? null)), kop_iv_int($cats['violation_count'] ?? null));
        $listed = kop_iv_truthy($cats['listed_with_violations'] ?? null);
        $badges = array(kop_iv_badge('VDSS', 'neutral'));
        if ($n) {
            $badges[] = kop_iv_badge(kop_iv_plural($n, 'violation'), 'flagged');
            $tone = 'flagged';
        } elseif ($listed) {
            $badges[] = kop_iv_badge('Violations listed, none read', 'neutral');
            $tone = 'neutral';
        } else {
            $badges[] = kop_iv_badge('No violations', 'clean');
            $tone = 'clean';
        }
        return kop_iv_verdict($tone, $n, $badges);
    }

    $rows = kop_iv_list($cats['citations'] ?? null);
    $cited = 0;
    foreach ($rows as $c) {
        $comp = kop_iv_va_comp(is_array($c) ? ($c['comp'] ?? null) : null);
        if ($comp === 'N' || $comp === 'NS') $cited++;
    }
    $citedCount = count($rows) ? $cited : kop_iv_int($cats['citation_count'] ?? null);
    $noViolation = ($cats['no_violation'] ?? null) === true || ($cats['result'] ?? null) === 'no_violation';
    $badges = array(kop_iv_badge('DBHDS', 'neutral'));
    if ($citedCount > 0) {
        $badges[] = kop_iv_badge(kop_iv_plural($citedCount, 'standard') . ' not met', 'flagged');
        $tone = 'flagged';
    } elseif ($noViolation) {
        $badges[] = kop_iv_badge('No violation', 'clean');
        $tone = 'clean';
    } elseif (count($rows)) {
        $badges[] = kop_iv_badge('No standard rated N or NS', 'clean');
        $tone = 'clean';
    } elseif (kop_iv_truthy($cats['has_cap'] ?? null)) {
        $badges[] = kop_iv_badge('Plan posted, not read', 'neutral');
        $tone = 'neutral';
    } else {
        $badges[] = kop_iv_badge('No plan posted', 'neutral');
        $tone = 'neutral';
    }
    return kop_iv_verdict($tone, $citedCount, $badges);
}
