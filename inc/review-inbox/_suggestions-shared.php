<?php
/**
 * Helpers for the "Suggestions to check" sources of the review inbox (merges,
 * program homes, map years and renames, inspection and folder links). Defines
 * no source of its own.
 *
 *   kop_rinbox_compare_rows()   a compare table for two (or more) records,
 *                               rows that differ marked, empty rows left out
 *   kop_rinbox_map_rebuild_tool() / kop_rinbox_map_rebuild_run()
 *                               the "Rebuild the map now" tool of KOP Tools >
 *                               Map Rebuild (inc/network-rebuild.php)
 *   kop_rinbox_quote_details()  research sources as card details: the quote,
 *                               linked to where it came from
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_rinbox_compare_rows')) {
    /**
     * $rows: [label => [value per head, ...]] (a value may be a list). Returns
     * {heads, rows: [{label, values, differs}]}: a row with nothing in any
     * column is left out; differs when the columns do not all read the same
     * (case and spacing aside).
     */
    function kop_rinbox_compare_rows(array $heads, array $rows) {
        $out = array();
        foreach ($rows as $label => $values) {
            $values = array_values((array) $values);
            $norm = array();
            $any = false;
            foreach ($values as $i => $v) {
                $text = is_array($v) ? implode(', ', array_filter(array_map('strval', $v), 'strlen')) : trim((string) $v);
                $values[$i] = $text;
                if ($text !== '') $any = true;
                $norm[] = mb_strtolower(preg_replace('/\s+/u', ' ', $text));
            }
            if (!$any) continue;
            $out[] = array('label' => (string) $label, 'values' => $values, 'differs' => count(array_unique($norm)) > 1);
        }
        return array('heads' => array_values($heads), 'rows' => $out);
    }
}

if (!function_exists('kop_rinbox_map_rebuild_tool')) {
    /**
     * The "Rebuild the map now" tool, with the state of the last check in its
     * help, or null when the map rebuild is not installed. $why says what the
     * rebuild is for in this queue.
     */
    function kop_rinbox_map_rebuild_tool($why = '') {
        if (!function_exists('kop_network_rebuild_check') || !defined('KOP_NETWORK_REBUILD_OPTION')) return null;
        $state = get_option(KOP_NETWORK_REBUILD_OPTION, array());
        $state = is_array($state) ? $state : array();
        $when = function ($t) { return $t ? gmdate('M j, Y H:i', (int) $t) . ' UTC' : 'never'; };
        $pending = !empty($state['current']) && $state['current'] !== ($state['built'] ?? '');
        $help = trim($why . ' The map is rebuilt on GitHub by itself within the hour when the records change; this starts a build now (it takes about ten minutes).')
            . ' Last build started: ' . $when($state['dispatched'] ?? 0) . '.'
            . ' Changes waiting: ' . ($pending ? 'yes' : 'no') . '.'
            . (function_exists('kop_network_rebuild_token') && kop_network_rebuild_token() === '' ? ' The GitHub token is missing, so a build cannot start.' : '');
        return array('id' => 'map_rebuild', 'label' => 'Rebuild the map now', 'style' => 'neutral', 'help' => $help,
            'confirm' => 'Start a new build of the network map now? It takes about ten minutes and then goes live by itself.');
    }
}

if (!function_exists('kop_rinbox_map_rebuild_run')) {
    /** Runs the tool: kop_network_rebuild_check(true), as KOP Tools > Map Rebuild's button. */
    function kop_rinbox_map_rebuild_run() {
        if (!function_exists('kop_network_rebuild_check')) throw new RuntimeException('The map rebuild is not installed here.');
        $state = kop_network_rebuild_check(true, 'started by hand from the review inbox');
        if (!empty($state['error'])) throw new RuntimeException($state['error']);
        return array('message' => 'Map build started. It goes live by itself in about ten minutes.');
    }
}

if (!function_exists('kop_rinbox_quote_details')) {
    /** Research sources [{url, quote}] as details [{label, value, url}]: the quote, linked to its page. */
    function kop_rinbox_quote_details(array $sources, $label = 'Source') {
        $out = array();
        $n = 0;
        foreach ($sources as $s) {
            if (empty($s['url'])) continue;
            $n++;
            $host = preg_replace('/^www\./', '', (string) wp_parse_url((string) $s['url'], PHP_URL_HOST));
            $quote = trim((string) ($s['quote'] ?? ''));
            $out[] = array(
                'label' => $label . ' ' . $n,
                'value' => $quote !== '' ? '"' . $quote . '" (' . ($host !== '' ? $host : 'link') . ')' : ($host !== '' ? $host : (string) $s['url']),
                'url'   => (string) $s['url'],
            );
        }
        return $out;
    }
}
