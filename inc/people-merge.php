<?php
/**
 * KOP Tools > Merge People: person ids (inc/people.php) that are one person
 * under two names, found automatically and merged with one click.
 *
 * Finding (kop_pmerge_find_pairs, pure, so the test runs it on the mirror):
 *   - a short or nickname first name: "Kris Hayes" / "Kristen Hayes"
 *   - a maiden or married name: "Melinda Heller-Nellos" / "Melinda Nellos"
 *   - a spelling one letter apart: "Paul Ravenscraft" / "Paul Ravenscroft"
 *   - first and last name the wrong way round
 *   - the same last name and first initial at the same program
 *   - the same name under two ids (two board people on the map, or a
 *     Separate; Separate marks its pair "Not the same" itself)
 * A pair is "Likely the same" when the two also share a program or company,
 * else "Worth a look". "Not the same" hides a pair for good (option
 * kop_people_merge_dismissed).
 *
 * Merging (kop_people_merge) leaves the kept id: the other id forwards to it,
 * its names become the kept person's other names, and the sync moves every
 * entry, so the facility pages, company pages and the network map (next
 * rebuild) show one person. Every merge can be undone from the Merged tab
 * (option kop_people_merge_log): the other id is its own person again, with
 * the entries it had.
 *
 * Offline test: php scripts/test-people.php (in-memory copy of tmp/prod.sqlite).
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---- Finding pairs -------------------------------------------------------- */

if (!function_exists('kop_pmerge_tokens')) {
    /** A name's words as the person key reads them, the first one nickname-folded: "Dr. Kris L. Hayes" -> [kris, hayes]. */
    function kop_pmerge_tokens($name) {
        $key = kop_people_key($name);
        if ($key === '') return array();
        $name = preg_replace('/^[A-Za-z][A-Za-z &\/-]{2,40}:\s*/', '', (string) $name);
        $name = preg_replace('/["\x{201C}\x{201D}][^"\x{201C}\x{201D}]*["\x{201C}\x{201D}]|\([^)]*\)/u', ' ', $name);
        $name = preg_replace('/,.*$/', '', $name);
        $name = function_exists('remove_accents') ? remove_accents($name) : $name;
        $drop = array('dr', 'mr', 'mrs', 'ms', 'rev', 'jr', 'sr', 'ii', 'iii', 'iv', 'phd', 'md', 'psyd', 'lcsw', 'lpc', 'lmft', 'rn', 'ma', 'msw', 'edd');
        $tokens = array_values(array_filter(preg_split('/[^a-z\']+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY), static function ($t) use ($drop) {
            return strlen(trim($t, "'")) > 1 && !in_array($t, $drop, true);
        }));
        list($first) = explode(' ', $key);
        if ($tokens) $tokens[0] = $first;
        return $tokens;
    }
}

if (!function_exists('kop_pmerge_find_pairs')) {
    /**
     * @param array $people    id => {name, name_key} (people not merged away)
     * @param array $records   id => [record key, ...] (where each is named)
     * @param array $dismissed "a:b" => anything
     * @return array [{a, b, reason: {code, level, label}}], a < b
     */
    function kop_pmerge_find_pairs(array $people, array $records, array $dismissed = array()) {
        $info = array();
        $by_last = $by_first = array();
        foreach ($people as $id => $p) {
            $parts = explode(' ', (string) $p['name_key']);
            if (count($parts) !== 2) continue;
            $info[$id] = array('first' => $parts[0], 'last' => $parts[1], 'key' => $p['name_key'], 'tokens' => kop_pmerge_tokens($p['name']),
                'records' => array_flip($records[$id] ?? array()));
            $by_last[$parts[1]][] = $id;
            $by_first[$parts[0]][] = $id;
        }
        $out = array();
        $consider = function ($a, $b) use (&$out, $info, $dismissed) {
            if ($a === $b) return;
            if ($a > $b) list($a, $b) = array($b, $a);
            $pk = $a . ':' . $b;
            if (isset($out[$pk]) || isset($dismissed[$pk])) return;
            $x = $info[$a];
            $y = $info[$b];
            $shared = (bool) array_intersect_key($x['records'], $y['records']);
            $reason = null;
            if ($x['key'] === $y['key']) {
                // Two ids for one name: kept apart by the map (two board people) until someone looks.
                $reason = array('code' => 'same', 'level' => 'check', 'label' => 'The same name under two ids');
            } elseif ($x['last'] === $y['last']) {
                $f1 = $x['first'];
                $f2 = $y['first'];
                $short = strlen($f1) < strlen($f2) ? $f1 : $f2;
                $long = $short === $f1 ? $f2 : $f1;
                if (strpos($long, $short) === 0) {
                    $reason = array('code' => 'short', 'level' => $shared ? 'likely' : 'check', 'label' => 'A short form of the first name');
                } elseif (min(strlen($f1), strlen($f2)) >= ($shared ? 3 : 4) && levenshtein($f1, $f2) === 1) {
                    $reason = array('code' => 'spelling', 'level' => $shared ? 'likely' : 'check', 'label' => 'First name spelled one letter apart');
                } elseif ($shared && $f1[0] === $f2[0]) {
                    $reason = array('code' => 'initial', 'level' => 'check', 'label' => 'Same last name and first initial, at the same program');
                }
            } elseif ($x['first'] === $y['first']) {
                $l1 = $x['last'];
                $l2 = $y['last'];
                if ((count($x['tokens']) > 2 && in_array($l2, $x['tokens'], true)) || (count($y['tokens']) > 2 && in_array($l1, $y['tokens'], true))) {
                    $reason = array('code' => 'maiden', 'level' => 'likely', 'label' => 'One last name is part of the other (a maiden or married name)');
                } else {
                    $d = levenshtein($l1, $l2);
                    $len = min(strlen($l1), strlen($l2));
                    if (($d === 1 && $len >= 5) || ($d === 2 && $len >= 8)) {
                        $reason = array('code' => 'spelling', 'level' => $shared ? 'likely' : 'check', 'label' => 'Last name spelled ' . ($d === 1 ? 'one letter' : 'two letters') . ' apart');
                    }
                }
            } elseif ($x['first'] === $y['last'] && $x['last'] === $y['first']) {
                $reason = array('code' => 'swapped', 'level' => 'check', 'label' => 'First and last name the other way round');
            }
            if ($reason) {
                if ($shared) $reason['label'] .= '; both named at the same program or company';
                $out[$pk] = array('a' => $a, 'b' => $b, 'reason' => $reason);
            }
        };
        foreach (array($by_last, $by_first) as $buckets) {
            foreach ($buckets as $ids) {
                $n = count($ids);
                for ($i = 0; $i < $n; $i++) for ($j = $i + 1; $j < $n; $j++) $consider($ids[$i], $ids[$j]);
            }
        }
        foreach ($info as $id => $x) {
            foreach ($by_first[$x['last']] ?? array() as $other) {
                if ($info[$other]['last'] === $x['first']) $consider($id, $other);
            }
        }
        return array_values($out);
    }
}

/* ---- Screen data ---------------------------------------------------------- */

if (!function_exists('kop_pmerge_dismissed')) {
    function kop_pmerge_dismissed() {
        $v = get_option('kop_people_merge_dismissed', array());
        return is_array($v) ? $v : array();
    }
}

if (!function_exists('kop_pmerge_log')) {
    function kop_pmerge_log() {
        $v = get_option('kop_people_merge_log', array());
        return is_array($v) ? $v : array();
    }
}

if (!function_exists('kop_pmerge_record_rows')) {
    /** person id => [{kind, id, name, role, url}] from kop_person_roles, with record names. */
    function kop_pmerge_record_rows(array $opts = array()) {
        $names = array();
        foreach (kop_facility_db_rows('SELECT id, name, state FROM ' . kop_facility_table('facilities', $opts), array(), $opts) as $r) {
            $names['facility' . $r['id']] = $r['name'] . ($r['state'] ? ', ' . $r['state'] : '');
        }
        foreach (kop_facility_db_rows('SELECT id, name FROM ' . kop_facility_table('operators', $opts), array(), $opts) as $r) {
            $names['operator' . $r['id']] = (string) $r['name'];
        }
        $map_url = function_exists('kop_facility_pages_page_url_by_template') ? kop_facility_pages_page_url_by_template('page-network-map.php', '/network-map/') : '';
        $out = array();
        foreach (kop_facility_db_rows('SELECT person_id, record_kind, record_id, list, role, ref FROM ' . kop_people_table('roles', $opts)
            . ' ORDER BY person_id, record_kind, record_id', array(), $opts) as $r) {
            $kind = (string) $r['record_kind'];
            $url = '';
            if ($kind === 'map') {
                $name = 'Network map';
                if ($map_url !== '') $url = $map_url . '#open=' . rawurlencode((string) $r['ref']);
            } else {
                $name = $names[$kind . $r['record_id']] ?? ucfirst($kind) . ' #' . $r['record_id'];
                if ($kind === 'facility' && function_exists('kop_facility_page_url')) $url = (string) kop_facility_page_url((int) $r['record_id']);
            }
            $out[(int) $r['person_id']][] = array('kind' => $kind, 'id' => (int) $r['record_id'], 'name' => $name, 'role' => (string) $r['role'], 'url' => $url);
        }
        return $out;
    }
}

if (!function_exists('kop_pmerge_screen_data')) {
    /**
     * {pairs: [{key, tab, reason, a, b, keep}], merged, dismissed}. Cached for an
     * hour; every action here rebuilds it.
     */
    function kop_pmerge_screen_data($fresh = false, array $opts = array()) {
        if (!$fresh && !$opts) {
            $cached = get_transient('kop_pmerge_screen');
            if (is_array($cached)) return $cached;
        }
        $state = kop_people_load($opts);
        $records = kop_pmerge_record_rows($opts);
        $people = array();
        $keys = array();
        foreach ($state['rows'] as $id => $r) {
            if ($r['merged_into']) continue;
            $people[$id] = $r;
            foreach ($records[$id] ?? array() as $rec) if ($rec['kind'] !== 'map') $keys[$id][] = $rec['kind'] . $rec['id'];
        }
        $side = function ($id) use ($state, $records) {
            $r = $state['rows'][$id] ?? null;
            if (!$r) return array('id' => $id, 'name' => 'Person #' . $id . ' (gone)', 'aliases' => array(), 'records' => array(), 'n' => 0);
            $recs = $records[$id] ?? array();
            return array('id' => $id, 'name' => $r['name'], 'aliases' => kop_people_alias_list($r['aliases']), 'records' => array_slice($recs, 0, 12),
                'n' => count($recs), 'url' => function_exists('kop_people_admin_url') ? kop_people_admin_url(array('person' => $id)) : '');
        };
        $out = array();
        foreach (kop_pmerge_find_pairs($people, $keys, kop_pmerge_dismissed()) as $p) {
            $a = $side($p['a']);
            $b = $side($p['b']);
            $out[] = array('key' => $p['a'] . ':' . $p['b'], 'tab' => $p['reason']['level'], 'reason' => $p['reason']['label'],
                'a' => $a, 'b' => $b, 'keep' => $b['n'] > $a['n'] ? $b['id'] : $a['id']);
        }
        usort($out, function ($x, $y) {
            return strcmp($x['tab'] === 'likely' ? '0' : '1', $y['tab'] === 'likely' ? '0' : '1') ?: strcasecmp($x['a']['name'], $y['a']['name']);
        });
        $merged = array();
        foreach (kop_pmerge_log() as $e) {
            $merged[] = array('id' => $e['id'], 'keep' => $e['keep'], 'drop' => $e['drop'], 'at' => $e['at'], 'by' => $e['by'],
                'moved' => (int) ($e['moved'] ?? 0), 'undone' => !empty($e['undone']), 'canUndo' => empty($e['undone']) && !empty($e['undo']));
        }
        $dis = array();
        foreach (kop_pmerge_dismissed() as $key => $d) {
            list($x, $y) = array_map('intval', explode(':', $key));
            $dis[] = array('key' => $key, 'a' => $side($x), 'b' => $side($y), 'by' => $d['by'] ?? '', 'at' => $d['at'] ?? '');
        }
        $data = array('pairs' => $out, 'merged' => $merged, 'dismissed' => $dis);
        if (!$opts) set_transient('kop_pmerge_screen', $data, HOUR_IN_SECONDS);
        return $data;
    }
}

/* ---- Actions ------------------------------------------------------------- */

if (!function_exists('kop_pmerge_do_merge')) {
    /** Person $drop is person $keep: merge, sync, log. Returns the message. */
    function kop_pmerge_do_merge($keep, $drop, $login, array $opts = array()) {
        $state = kop_people_load($opts);
        $k = $state['rows'][(int) $keep] ?? null;
        $d = $state['rows'][(int) $drop] ?? null;
        if (!$k || !$d) throw new RuntimeException('One of these people is no longer on file. The list has been refreshed.');
        $undo = kop_people_merge((int) $drop, (int) $keep, $opts);
        if (!$undo) throw new RuntimeException('These two are already one person.');
        $sync = kop_people_sync($opts);
        $entry = array(
            'id'    => uniqid('pm', true),
            'keep'  => array('id' => (int) $undo['into'], 'name' => (string) $state['rows'][$undo['into']]['name']),
            'drop'  => array('id' => (int) $drop, 'name' => (string) $d['name']),
            'at'    => gmdate('Y-m-d H:i:s'),
            'by'    => (string) $login,
            'moved' => count($undo['entries']),
            'undo'  => $undo,
        );
        $log = kop_pmerge_log();
        array_unshift($log, $entry);
        foreach ($log as $i => $e) if ($i >= 300) unset($log[$i]['undo']);
        update_option('kop_people_merge_log', array_slice($log, 0, 2000), false);
        return 'Merged: "' . $d['name'] . '" is now ' . $entry['keep']['name'] . ' (#' . $entry['keep']['id'] . ')'
            . ($entry['moved'] ? ', ' . $entry['moved'] . ($entry['moved'] === 1 ? ' entry' : ' entries') . ' moved' : '') . '.';
    }
}

if (!function_exists('kop_pmerge_do_undo')) {
    function kop_pmerge_do_undo($log_id, array $opts = array()) {
        $log = kop_pmerge_log();
        $found = null;
        foreach ($log as $i => $e) if (($e['id'] ?? '') === $log_id) $found = $i;
        if ($found === null || empty($log[$found]['undo'])) throw new RuntimeException('That merge cannot be undone any more.');
        $entry = $log[$found];
        $state = kop_people_load($opts);
        if (($state['rows'][$entry['undo']['from']]['merged_into'] ?? 0) !== (int) $entry['undo']['into']) {
            throw new RuntimeException('Something changed since this merge (it was merged again or undone). Not undone.');
        }
        kop_people_unmerge($entry['undo'], $opts);
        kop_people_sync($opts);
        unset($log[$found]['undo']);
        $log[$found]['undone'] = gmdate('Y-m-d H:i:s');
        update_option('kop_people_merge_log', $log, false);
        return 'Undone: "' . $entry['drop']['name'] . '" (#' . $entry['drop']['id'] . ') is their own person again.';
    }
}

if (!function_exists('kop_pmerge_set_dismissed')) {
    /** Mark a pair "not the same" ($dismiss true) or put it back on the list. Returns the message. */
    function kop_pmerge_set_dismissed($a, $b, $dismiss, $login) {
        $a = (int) $a;
        $b = (int) $b;
        $key = min($a, $b) . ':' . max($a, $b);
        $dis = kop_pmerge_dismissed();
        if ($dismiss) $dis[$key] = array('by' => (string) $login, 'at' => gmdate('Y-m-d H:i:s'));
        else unset($dis[$key]);
        update_option('kop_people_merge_dismissed', $dis, false);
        return $dismiss ? 'Marked not the same. This pair will not be offered again.' : 'Back on the list.';
    }
}

if (!function_exists('kop_pmerge_ajax')) {
    /**
     * POST action=kop_people_merge, nonce, op:
     *   merge      keep, drop (ids, or a name for "any two people")
     *   dismiss    a, b
     *   undismiss  a, b
     *   undo       log
     * Answers with the screen's data, rebuilt.
     */
    function kop_pmerge_ajax() {
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Only an administrator can merge people.'), 403);
        check_ajax_referer('kop_people_merge', 'nonce');
        kop_people_install();
        $op = sanitize_key((string) ($_POST['op'] ?? ''));
        $user = wp_get_current_user();
        $login = $user ? (string) $user->user_login : '';
        try {
            if ($op === 'merge') {
                $keep = kop_people_admin_find(sanitize_text_field(wp_unslash($_POST['keep'] ?? '')), 0);
                $drop = kop_people_admin_find(sanitize_text_field(wp_unslash($_POST['drop'] ?? '')), 0);
                if (is_string($keep)) throw new RuntimeException('Keep: ' . $keep);
                if (is_string($drop)) throw new RuntimeException('Fold in: ' . $drop);
                if ($keep === $drop) throw new RuntimeException('That is the same person.');
                $note = kop_pmerge_do_merge($keep, $drop, $login);
            } elseif ($op === 'undo') {
                $note = kop_pmerge_do_undo((string) ($_POST['log'] ?? ''));
            } elseif ($op === 'dismiss' || $op === 'undismiss') {
                $note = kop_pmerge_set_dismissed((int) ($_POST['a'] ?? 0), (int) ($_POST['b'] ?? 0), $op === 'dismiss', $login);
            } else {
                throw new RuntimeException('Unknown action.');
            }
        } catch (Throwable $e) {
            wp_send_json_error(array('message' => $e->getMessage(), 'data' => kop_pmerge_screen_data(true)), 400);
        }
        wp_send_json_success(array('message' => $note, 'data' => kop_pmerge_screen_data(true)));
    }
    add_action('wp_ajax_kop_people_merge', 'kop_pmerge_ajax');
}

/* ---- Screen --------------------------------------------------------------- */

if (!function_exists('kop_pmerge_menu')) {
    function kop_pmerge_menu() {
        if (!function_exists('kop_tools_parent_slug')) return;
        add_submenu_page(kop_tools_parent_slug(), 'Merge People', 'Merge People', 'manage_options', 'kop-merge-people', 'kop_pmerge_page');
    }
    add_action('admin_menu', 'kop_pmerge_menu', 22);
}

if (!function_exists('kop_pmerge_page')) {
    function kop_pmerge_page() {
        if (!current_user_can('manage_options')) return;
        kop_people_install();
        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('kop_people_merge'),
            'data'    => kop_pmerge_screen_data(),
        );
        ?>
        <div class="wrap kop-pm">
            <h1>Merge duplicate people</h1>
            <p class="kop-pm__intro">
                Each card is two person ids that look like one person: a short form of the first name, a maiden or married
                name, a spelling one letter apart, or the names the other way round. Pick the id to keep and press
                <strong>Merge into one</strong>. The other id forwards to it, its names become other names for the kept person,
                and every record that named them now names the kept person. Their facility pages and company pages show one
                career, and the network map draws one person from its next rebuild. Every merge can be undone from the
                <strong>Merged</strong> tab. Two people who only share a name are split on
                <a href="<?php echo esc_url(function_exists('kop_people_admin_url') ? kop_people_admin_url() : ''); ?>">People</a> (Separate).
            </p>
            <div class="kop-pm__any">
                <strong>Merge any two people:</strong>
                <label>keep <input type="text" class="kop-pm__any-keep" placeholder="Name or #id"></label>
                <label>fold in <input type="text" class="kop-pm__any-drop" placeholder="Name or #id"></label>
                <button type="button" class="button kop-pm__any-go">Merge into one</button>
            </div>
            <div class="kop-pm__bar">
                <div class="kop-pm__tabs" role="tablist">
                    <button type="button" data-tab="likely" aria-selected="true">Likely the same <span></span></button>
                    <button type="button" data-tab="check" aria-selected="false">Worth a look <span></span></button>
                    <button type="button" data-tab="dismissed" aria-selected="false">Not the same <span></span></button>
                    <button type="button" data-tab="merged" aria-selected="false">Merged <span></span></button>
                </div>
                <label for="kop-pm-filter" class="screen-reader-text">Filter</label>
                <input type="search" id="kop-pm-filter" class="kop-pm__filter" placeholder="Filter by name or program">
                <span class="kop-pm__status" role="status" aria-live="polite"></span>
            </div>
            <div class="kop-pm__list"></div>
        </div>
        <style>
            .kop-pm__intro { max-width: 62em; font-size: 14px; }
            .kop-pm__any { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; max-width: 1100px; padding: 10px 12px;
                background: #fff; border: 1px solid #dcdcde; border-radius: 6px; }
            .kop-pm__bar { position: sticky; top: 32px; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; gap: 12px;
                margin: 16px 0; padding: 10px 12px; background: #fff; border: 1px solid #dcdcde; border-radius: 6px; max-width: 1100px; box-sizing: border-box; }
            .kop-pm__tabs { display: flex; flex-wrap: wrap; gap: 4px; }
            .kop-pm__tabs button { padding: 6px 12px; border: 1px solid #c3c4c7; border-radius: 999px; background: #f6f7f7; color: #1d2327; cursor: pointer; font-size: 13px; }
            .kop-pm__tabs button[aria-selected="true"] { background: #000080; border-color: #000080; color: #fff; }
            .kop-pm__tabs span { font-weight: 600; }
            .kop-pm__filter { min-width: 240px; }
            .kop-pm__status { color: #1d7a33; font-weight: 600; }
            .kop-pm__status.is-bad { color: #b32d2e; }
            .kop-pm__list { display: grid; gap: 12px; max-width: 1100px; }
            .kop-pm__card { padding: 14px 16px; background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #33a7b5; border-radius: 6px; }
            .kop-pm__card.is-check { border-left-color: #b26200; }
            .kop-pm__card.is-busy { opacity: .55; pointer-events: none; }
            .kop-pm__why { margin: 0 0 10px; font-weight: 600; color: #1d2327; }
            .kop-pm__pair { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
            @media (max-width: 782px) { .kop-pm__pair { grid-template-columns: 1fr; } }
            .kop-pm__side { display: block; padding: 10px 12px; background: #f6f7f7; border: 2px solid transparent; border-radius: 6px; cursor: pointer; }
            .kop-pm__side.is-keep { border-color: #1d7a33; background: #edf7ef; }
            .kop-pm__pick { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #50575e; }
            .kop-pm__side.is-keep .kop-pm__pick { color: #14532d; }
            .kop-pm__name { margin: 4px 0; font-size: 16px; }
            .kop-pm__line { margin: 2px 0; color: #1d2327; }
            .kop-pm__muted { color: #50575e; }
            .kop-pm__recs { margin: 6px 0 0 18px; padding: 0; }
            .kop-pm__recs li { margin: 2px 0; }
            .kop-pm__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin-top: 12px; }
            .kop-pm__empty { padding: 24px; background: #fff; border: 1px dashed #c3c4c7; border-radius: 6px; text-align: center; }
        </style>
        <script>
        (function () {
            var C = <?php echo wp_json_encode($config); ?>;
            var D = C.data;
            var list = document.querySelector('.kop-pm__list');
            var status = document.querySelector('.kop-pm__status');
            var filter = document.querySelector('.kop-pm__filter');
            var tab = 'likely';
            var keepChoice = {};

            function el(tag, cls, text) {
                var n = document.createElement(tag);
                if (cls) n.className = cls;
                if (text !== undefined) n.textContent = text;
                return n;
            }
            function link(text, url) {
                var a = el('a', '', text); a.href = url; a.target = '_blank'; a.rel = 'noopener'; return a;
            }

            function send(params, card) {
                var body = new FormData();
                body.append('action', 'kop_people_merge');
                body.append('nonce', C.nonce);
                Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
                status.className = 'kop-pm__status';
                status.textContent = params.op === 'merge' ? 'Merging...' : 'Saving...';
                if (card) card.classList.add('is-busy');
                fetch(C.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (res) { return res.json(); })
                    .then(function (json) {
                        var d = json && json.data ? json.data : {};
                        if (d.data) D = d.data;
                        if (!json || !json.success) throw new Error(d.message || 'Not saved.');
                        status.textContent = d.message || 'Saved.';
                        render();
                    })
                    .catch(function (e) {
                        status.className = 'kop-pm__status is-bad';
                        status.textContent = 'Not done: ' + e.message;
                        render();
                    });
            }

            function side(s, chosen, onPick, pickable) {
                var box = el(pickable ? 'label' : 'div', 'kop-pm__side' + (chosen ? ' is-keep' : ''));
                if (pickable) {
                    var pick = el('span', 'kop-pm__pick');
                    var radio = el('input'); radio.type = 'radio'; radio.checked = chosen;
                    radio.addEventListener('change', onPick);
                    pick.appendChild(radio);
                    pick.appendChild(document.createTextNode(chosen ? 'Keep this person' : 'Fold into the other'));
                    box.appendChild(pick);
                }
                box.appendChild(el('h2', 'kop-pm__name', s.name));
                if (s.aliases && s.aliases.length) box.appendChild(el('p', 'kop-pm__line kop-pm__muted', 'Also written: ' + s.aliases.join(', ')));
                var recs = el('ul', 'kop-pm__recs');
                (s.records || []).forEach(function (r) {
                    var li = el('li');
                    if (r.url) li.appendChild(link(r.name, r.url)); else li.appendChild(document.createTextNode(r.name));
                    if (r.role) li.appendChild(el('span', 'kop-pm__muted', ' - ' + r.role));
                    recs.appendChild(li);
                });
                if (s.n > (s.records || []).length) recs.appendChild(el('li', 'kop-pm__muted', 'and ' + (s.n - s.records.length) + ' more'));
                if (!s.n) recs.appendChild(el('li', 'kop-pm__muted', 'No record names this id now'));
                box.appendChild(recs);
                var links = el('p', 'kop-pm__line');
                links.appendChild(el('span', 'kop-pm__muted', 'Person #' + s.id + (s.url ? ' · ' : '')));
                if (s.url) links.appendChild(link('Open', s.url));
                box.appendChild(links);
                return box;
            }

            function pairCard(p) {
                var c = el('article', 'kop-pm__card is-' + p.tab);
                c.appendChild(el('p', 'kop-pm__why', p.reason));
                var keep = keepChoice[p.key] || p.keep;
                var pair = el('div', 'kop-pm__pair');
                [p.a, p.b].forEach(function (s) {
                    pair.appendChild(side(s, s.id === keep, function () { keepChoice[p.key] = s.id; render(); }, true));
                });
                c.appendChild(pair);
                var actions = el('div', 'kop-pm__actions');
                var go = el('button', 'button button-primary', 'Merge into one');
                go.type = 'button';
                go.addEventListener('click', function () {
                    send({ op: 'merge', keep: '#' + keep, drop: '#' + (keep === p.a.id ? p.b.id : p.a.id) }, c);
                });
                actions.appendChild(go);
                var no = el('button', 'button', 'Not the same person');
                no.type = 'button';
                no.addEventListener('click', function () { send({ op: 'dismiss', a: p.a.id, b: p.b.id }, c); });
                actions.appendChild(no);
                var k = keep === p.a.id ? p.a : p.b;
                var d = keep === p.a.id ? p.b : p.a;
                actions.appendChild(el('span', 'kop-pm__muted', 'Keeps "' + k.name + '"; "' + d.name + '" becomes another name for them.'));
                c.appendChild(actions);
                return c;
            }

            function dismissedCard(x) {
                var c = el('article', 'kop-pm__card');
                c.appendChild(el('p', 'kop-pm__why', 'Marked not the same' + (x.by ? ' by ' + x.by : '') + (x.at ? ' on ' + x.at.slice(0, 10) : '')));
                var pair = el('div', 'kop-pm__pair');
                pair.appendChild(side(x.a, false, null, false));
                pair.appendChild(side(x.b, false, null, false));
                c.appendChild(pair);
                var actions = el('div', 'kop-pm__actions');
                var back = el('button', 'button', 'Put back on the list');
                back.type = 'button';
                back.addEventListener('click', function () { send({ op: 'undismiss', a: x.a.id, b: x.b.id }, c); });
                actions.appendChild(back);
                c.appendChild(actions);
                return c;
            }

            function mergedCard(m) {
                var c = el('article', 'kop-pm__card');
                c.appendChild(el('p', 'kop-pm__why', '"' + m.drop.name + '" (#' + m.drop.id + ') merged into "' + m.keep.name + '" (#' + m.keep.id + ')'));
                c.appendChild(el('p', 'kop-pm__line kop-pm__muted', (m.at || '') + (m.by ? ' by ' + m.by : '') +
                    (m.moved ? ' · ' + m.moved + (m.moved === 1 ? ' entry' : ' entries') + ' moved' : '')));
                var actions = el('div', 'kop-pm__actions');
                if (m.undone) {
                    actions.appendChild(el('span', 'kop-pm__muted', 'Undone.'));
                } else if (m.canUndo) {
                    var undo = el('button', 'button', 'Undo');
                    undo.type = 'button';
                    undo.addEventListener('click', function () {
                        if (window.confirm('Split "' + m.drop.name + '" back out into their own person, with the entries they had?')) send({ op: 'undo', log: m.id }, c);
                    });
                    actions.appendChild(undo);
                }
                c.appendChild(actions);
                return c;
            }

            function matches(x, q) {
                if (!q) return true;
                var names = function (s) { return s ? [s.name, s.aliases, (s.records || []).map(function (r) { return r.name; })] : ''; };
                return JSON.stringify([names(x.a), names(x.b), x.keep && x.keep.name, x.drop && x.drop.name]).toLowerCase().indexOf(q) !== -1;
            }

            function render() {
                var counts = { likely: 0, check: 0, dismissed: D.dismissed.length, merged: D.merged.filter(function (m) { return !m.undone; }).length };
                D.pairs.forEach(function (p) { counts[p.tab]++; });
                document.querySelectorAll('.kop-pm__tabs button').forEach(function (b) {
                    var t = b.getAttribute('data-tab');
                    b.querySelector('span').textContent = '(' + counts[t] + ')';
                    b.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                });
                var q = (filter.value || '').trim().toLowerCase();
                list.textContent = '';
                var shown;
                if (tab === 'merged') shown = D.merged.filter(function (x) { return matches(x, q); }).map(mergedCard);
                else if (tab === 'dismissed') shown = D.dismissed.filter(function (x) { return matches(x, q); }).map(dismissedCard);
                else shown = D.pairs.filter(function (p) { return p.tab === tab && matches(p, q); }).map(pairCard);
                if (!shown.length) list.appendChild(el('p', 'kop-pm__empty', tab === 'merged' ? 'No merges yet.' : 'Nothing here.'));
                shown.forEach(function (n) { list.appendChild(n); });
            }

            document.querySelector('.kop-pm__any-go').addEventListener('click', function () {
                var keep = document.querySelector('.kop-pm__any-keep').value.trim();
                var drop = document.querySelector('.kop-pm__any-drop').value.trim();
                if (!keep || !drop) {
                    status.className = 'kop-pm__status is-bad';
                    status.textContent = 'Type both people, by name or #id.';
                    return;
                }
                if (window.confirm('Fold ' + drop + ' into ' + keep + '?')) send({ op: 'merge', keep: keep, drop: drop }, null);
            });
            document.querySelectorAll('.kop-pm__tabs button').forEach(function (b) {
                b.addEventListener('click', function () { tab = b.getAttribute('data-tab'); status.textContent = ''; render(); });
            });
            filter.addEventListener('input', render);
            render();
        })();
        </script>
        <?php
    }
}
