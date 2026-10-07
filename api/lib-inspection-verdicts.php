<?php
/**
 * What each state's report page (/xx-reports/, js/inspections/states/<st>.js)
 * says about one report, worked out on the server for the state hubs
 * (kop_state_collect_inspection_summaries() in inc/rest-api.php), so a hub
 * card says what the state's own page says: the same badges, the same tone,
 * the same count. Before this the hub read one of six count fields and showed
 * "No findings" for every report a scraper stored any other way.
 *
 * One file per state in api/inspection-verdicts/<st>.php defines
 *   kop_iv_<st>(array $report)              the verdict, see below
 *   kop_iv_<st>_needs_text(array $cats)     optional: true when the verdict
 *                                           reads raw_content (the hub then
 *                                           fetches the text for that row)
 * $report holds the inspection_reports row as api/inspections-read.php sends
 * it: categories (decoded), summary, raw_content (string, '' when not
 * fetched), report_date, report_url, report_id.
 *
 * A verdict is array(
 *   'tone'   => the adapter's report() tone: 'flagged' | 'repeat' | 'clean' | 'neutral',
 *   'count'  => the adapter's countFlagged() for a facility holding only this report,
 *   'badges' => the adapter's report() badges, in order: array(array('text' => .., 'tone' => ..)),
 * ) or null when the adapter drops the row (it shows no report for it).
 *
 * Each port must return exactly what its adapter returns:
 * scripts/test-inspection-verdicts.js runs the real adapters over every report
 * in tmp/prod.sqlite and fails on any difference. Change them together.
 */

if (!function_exists('kop_inspection_verdict')) {

    /** Bumped whenever a reader changes; part of the state hub cache key. */
    function kop_iv_version() {
        return 1;
    }

    /** Loads every state file once; returns the states that have a reader. */
    function kop_iv_states() {
        static $states = null;
        if ($states !== null) return $states;
        $states = array();
        foreach ((array) glob(__DIR__ . '/inspection-verdicts/*.php') as $file) {
            require_once $file;
            $st = strtolower(basename($file, '.php'));
            if (function_exists('kop_iv_' . $st)) $states[] = strtoupper($st);
        }
        return $states;
    }

    /** @return array|null|false false when the state has no reader. */
    function kop_inspection_verdict($state, array $report) {
        $st = strtolower((string) $state);
        if (!in_array(strtoupper($st), kop_iv_states(), true)) return false;
        $report += array('categories' => array(), 'summary' => '', 'raw_content' => '', 'report_date' => '', 'report_url' => '', 'report_id' => '');
        if (!is_array($report['categories'])) $report['categories'] = array();
        $report['raw_content'] = (string) $report['raw_content'];
        return call_user_func('kop_iv_' . $st, $report);
    }

    function kop_inspection_verdict_needs_text($state, array $categories) {
        $st = strtolower((string) $state);
        if (!in_array(strtoupper($st), kop_iv_states(), true)) return false;
        $fn = 'kop_iv_' . $st . '_needs_text';
        return function_exists($fn) ? (bool) $fn($categories) : false;
    }

    // ---- Helpers that behave like the JavaScript the adapters use ----------

    /** report-page.js plural(). */
    function kop_iv_plural($count, $one, $many = null) {
        return $count . ' ' . ((int) $count === 1 ? $one : ($many !== null ? $many : $one . 's'));
    }

    function kop_iv_badge($text, $tone) {
        return array('text' => (string) $text, 'tone' => (string) $tone);
    }

    function kop_iv_verdict($tone, $count, array $badges) {
        return array('tone' => $tone, 'count' => (int) $count, 'badges' => array_values($badges));
    }

    /** parseInt(v, 10) || 0: leading digits of a string, numbers truncated. */
    function kop_iv_int($v) {
        if (is_bool($v) || $v === null || is_array($v)) return 0;
        if (is_int($v)) return $v;
        if (is_float($v)) return is_finite($v) ? (int) $v : 0;
        return preg_match('/^\s*([+-]?\d+)/', (string) $v, $m) ? (int) $m[1] : 0;
    }

    /** page.safeString(): '' for null, else String(v).trim(). */
    function kop_iv_str($v) {
        if ($v === null) return '';
        if (is_bool($v)) return $v ? 'true' : 'false';
        if (is_array($v)) return '';
        return kop_iv_trim((string) $v);
    }

    /** Array.isArray(v) ? v : []. A JSON object decodes to an assoc array: not a list. */
    function kop_iv_list($v) {
        return is_array($v) && ($v === array() || array_keys($v) === range(0, count($v) - 1)) ? $v : array();
    }

    /** JavaScript truthiness of a decoded JSON value. */
    function kop_iv_truthy($v) {
        if (is_string($v)) return $v !== '';
        if (is_array($v)) return true;
        return (bool) $v;
    }

    /** JavaScript's String.prototype.trim() (Unicode spaces included). */
    function kop_iv_trim($s) {
        return preg_replace('/^[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u', '', (string) $s);
    }
}
