<?php
/**
 * Renames on the network map, reviewed in wp-admin (KOP Data Tools > Map
 * Renames).
 *
 * A program that changed its name is two names on the map joined by a
 * "rebrand" line: Copper Canyon Academy (Aspen) became Sedona Sky Academy
 * when Family Help & Wellness took it over. Each name belongs to its own
 * years, so the timeline shows Copper Canyon in 2005 and Sedona Sky in 2020.
 * The board gave most renamed pairs the site's whole life on both names
 * (Copper Canyon 1998-2014, Sedona Sky from 1998), and drew some lines the
 * wrong way round, so the timeline showed both at once.
 *
 * This screen lists every rename with both names' operators and years and
 * what looks wrong, and asks for the year of the rename (and, where the line
 * is backwards, a swap). The year can be left blank: the rename is then saved
 * as confirmed, with its order, and the year (0) can be added later from the
 * Saved tab; the names' years on the map stay as they were, and the facility
 * pages still give each name its own section (inc/facility-eras.php).
 * A saved rename takes effect at once, as Map Years'
 * decisions do: the earlier name ends in that year and the later one starts
 * in it, over graph.json and over Map Years' accepted years
 * (kop_network_map_year_overrides filter), and a swap reverses the line and
 * moves the "rebranded" colour to the earlier name (renameFlips in the map's
 * config, and the facility page slices). Nothing to rebuild or commit.
 *
 * Decisions: the option kop_network_rename_review, keyed "earlier>later" in
 * the line's direction as the board drew it.
 *
 * @package KidsOverProfits
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_network_renames_parse_years')) {
    /** array(start|null, end|null) from the four forms the map reads; null for none. */
    function kop_network_renames_parse_years($text) {
        $t = trim((string) $text);
        if (preg_match('/^(\d{4})\s*[-\x{2013}]\s*(\d{4})$/u', $t, $m)) return array((int) $m[1], (int) $m[2]);
        if (preg_match('/^from (\d{4})$/', $t, $m)) return array((int) $m[1], null);
        if (preg_match('/^until (\d{4})$/', $t, $m)) return array(null, (int) $m[1]);
        if (preg_match('/^(\d{4})$/', $t, $m)) return array((int) $m[1], (int) $m[1]);
        return null;
    }
}

if (!function_exists('kop_network_renames_base_years')) {
    /** Node id => years before any rename: graph.json with Map Years' accepted years over it. */
    function kop_network_renames_base_years(array $graph) {
        $base = array();
        foreach ($graph['nodes'] ?? array() as $n) $base[(string) $n['id']] = (string) ($n['years'] ?? '');
        if (function_exists('kop_network_years_decisions')) {
            foreach (kop_network_years_decisions() as $id => $d) {
                if (($d['decision'] ?? '') === 'accepted' && !empty($d['years'])) $base[(string) $id] = (string) $d['years'];
            }
        }
        return $base;
    }
}

if (!function_exists('kop_network_renames_candidates')) {
    /**
     * Researched rename years, key => candidate (year, swapped, confidence,
     * sources [{url, quote}], note); scripts/build-rename-candidates.js
     * writes js/data/network/rename-candidates.json. Empty when missing.
     */
    function kop_network_renames_candidates() {
        static $memo = null;
        if ($memo !== null) return $memo;
        $path = trailingslashit(get_stylesheet_directory()) . 'js/data/network/rename-candidates.json';
        $list = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
        $memo = array();
        foreach (is_array($list) ? $list : array() as $c) {
            if (!empty($c['key'])) $memo[(string) $c['key']] = $c;
        }
        return $memo;
    }
}

if (!function_exists('kop_network_renames_decisions')) {
    /** "earlier>later" => array('decision' => saved|skipped, 'year', 'swapped', 'by', 'at'). */
    function kop_network_renames_decisions() {
        $saved = get_option('kop_network_rename_review', array());
        return is_array($saved) ? $saved : array();
    }
}

if (!function_exists('kop_network_renames_list')) {
    /**
     * Every rebrand line in the graph: key, earlier and later node (as the
     * board drew it), with each name's years (Map Years' accepted years
     * first), operators, facility record and status.
     */
    function kop_network_renames_list(array $graph, array $base_years = array()) {
        $nodes = array();
        foreach ($graph['nodes'] ?? array() as $n) $nodes[(string) $n['id']] = $n;
        $operators = array();
        foreach ($graph['edges'] ?? array() as $e) {
            $roles = (array) ($e['roles'] ?? array());
            if (($e['category'] ?? '') !== 'corporate' || in_array('rebrand', $roles, true)) continue;
            $src = $nodes[$e['source']] ?? null;
            $dst = $nodes[$e['target']] ?? null;
            if (!$src || !$dst) continue;
            // The company is the non-facility end of the line.
            list($company, $place) = ($src['kind'] ?? '') === 'facility' && ($dst['kind'] ?? '') !== 'facility' ? array($dst, $src) : array($src, $dst);
            $role = trim(implode(', ', array_filter(array_map('strval', $roles))));
            $operators[(string) $place['id']][] = $company['name'] . ($role !== '' ? ' (' . $role . ')' : '');
        }
        $out = array();
        foreach ($graph['edges'] ?? array() as $e) {
            if (!in_array('rebrand', (array) ($e['roles'] ?? array()), true)) continue;
            $a = $nodes[$e['source']] ?? null;
            $b = $nodes[$e['target']] ?? null;
            if (!$a || !$b) continue;
            $side = function ($n) use ($base_years, $operators) {
                $id = (string) $n['id'];
                $years = $base_years[$id] ?? (string) ($n['years'] ?? '');
                return array(
                    'id' => $id,
                    'name' => (string) $n['name'],
                    'years' => $years,
                    'parsed' => kop_network_renames_parse_years($years),
                    'chain' => (string) ($n['chain'] ?? ''),
                    'operators' => array_values(array_unique($operators[$id] ?? array())),
                    'status' => (string) ($n['status'] ?? ''),
                    'facilityId' => isset($n['facilityId']) ? (int) $n['facilityId'] : 0,
                );
            };
            $out[] = array('key' => $a['id'] . '>' . $b['id'], 'earlier' => $side($a), 'later' => $side($b));
        }
        return $out;
    }
}

if (!function_exists('kop_network_renames_assess')) {
    /**
     * What looks wrong with one rename, and the year to suggest.
     * Returns array('flags' => [...], 'suggest' => int|null, 'why' => string).
     */
    function kop_network_renames_assess(array $r) {
        $e = $r['earlier']['parsed'];
        $l = $r['later']['parsed'];
        $flags = array();
        if (!$e || !$l) {
            $flags[] = 'missing';
        } else {
            if ($e === $l) $flags[] = 'same';
            elseif ($e[1] && $l[0] && $l[0] < $e[1]) $flags[] = 'overlap';
            // The "later" name ended by the time the "earlier" one began
            // (Rebekah Home 1967-2001 drawn after New Beginnings 2001-2015).
            if ($l[1] && $e[0] && $l[1] <= $e[0] && (!$l[0] || $l[0] < $e[0])) $flags[] = 'reversed';
        }
        $suggest = null;
        $why = '';
        if ($e && $e[1] && (!$l || $e !== $l) && !in_array('reversed', $flags, true)) {
            $suggest = $e[1];
            $why = 'the year ' . $r['earlier']['name'] . ' closed';
        } elseif ($l && $l[0] && $e && $e[0] && $l[0] > $e[0]) {
            $suggest = $l[0];
            $why = 'the year ' . $r['later']['name'] . ' opened';
        }
        return array('flags' => $flags, 'suggest' => $suggest, 'why' => $why);
    }
}

if (!function_exists('kop_network_renames_apply_years')) {
    /**
     * Node id => years with every saved rename applied: the earlier name
     * ends in the rename year, the later one starts in it. $base is the
     * graph's years with Map Years' accepted ones over them; only names a
     * rename touches are returned.
     */
    function kop_network_renames_apply_years(array $base, array $decisions) {
        $bounds = array();
        foreach ($decisions as $key => $d) {
            if (($d['decision'] ?? '') !== 'saved' || empty($d['year'])) continue;
            $pair = explode('>', (string) $key, 2);
            if (count($pair) !== 2) continue;
            list($earlier, $later) = !empty($d['swapped']) ? array($pair[1], $pair[0]) : $pair;
            $y = (int) $d['year'];
            $bounds[$earlier]['end'] = $y;
            $bounds[$later]['start'] = $y;
        }
        $out = array();
        foreach ($bounds as $id => $b) {
            $p = kop_network_renames_parse_years($base[$id] ?? '') ?: array(null, null);
            $start = array_key_exists('start', $b) ? $b['start'] : $p[0];
            $end = array_key_exists('end', $b) ? $b['end'] : $p[1];
            // A name opened after its own rename-out year keeps only the end.
            if ($start && $end && $start > $end) $start = null;
            $years = kop_network_renames_format($start, $end);
            if ($years !== '') $out[(string) $id] = $years;
        }
        return $out;
    }
}

if (!function_exists('kop_network_renames_format')) {
    function kop_network_renames_format($start, $end) {
        $a = (int) $start;
        $b = (int) $end;
        if ($a && $b) return $a === $b ? (string) $a : $a . '-' . $b;
        if ($a) return 'from ' . $a;
        if ($b) return 'until ' . $b;
        return '';
    }
}

if (!function_exists('kop_network_map_rename_flips')) {
    /** "source>target" of every line the owner swapped. */
    function kop_network_map_rename_flips() {
        $out = array();
        foreach (kop_network_renames_decisions() as $key => $d) {
            if (($d['decision'] ?? '') === 'saved' && !empty($d['swapped'])) $out[] = (string) $key;
        }
        return $out;
    }
}

if (!function_exists('kop_network_map_apply_rename_flips')) {
    /**
     * graph.json-shaped nodes and edges with the swapped lines reversed, and
     * the "rebranded" colour moved to the name that is now the earlier one.
     * PHP twin of applyRenameFlips() in js/network-map/store.js.
     */
    function kop_network_map_apply_rename_flips(array $nodes, array $edges, array $flips) {
        if (!$flips) return array($nodes, $edges);
        $flip = array_flip($flips);
        $index = array();
        foreach ($nodes as $i => $n) $index[(string) $n['id']] = $i;
        foreach ($edges as $j => $e) {
            $key = $e['source'] . '>' . $e['target'];
            if (!isset($flip[$key]) || !in_array('rebrand', (array) ($e['roles'] ?? array()), true)) continue;
            $edges[$j]['source'] = $e['target'];
            $edges[$j]['target'] = $e['source'];
            $old = $index[$e['source']] ?? null;   // drawn earlier, now the later name
            $new = $index[$e['target']] ?? null;   // now the earlier name
            if ($old !== null && $new !== null && ($nodes[$old]['status'] ?? '') === 'rebranded') {
                $nodes[$old]['status'] = ($nodes[$new]['status'] ?? '') === 'rebranded' ? '' : (string) ($nodes[$new]['status'] ?? '');
                $nodes[$new]['status'] = 'rebranded';
            }
        }
        return array($nodes, $edges);
    }
}

/* ---- The years go over Map Years' ------------------------------------- */

add_filter('kop_network_map_year_overrides', function ($overrides) {
    $decisions = kop_network_renames_decisions();
    if (!$decisions || !function_exists('kop_network_map_graph')) return $overrides;
    $graph = kop_network_map_graph();
    if (!$graph) return $overrides;
    $base = array();
    foreach ($graph['nodes'] as $n) $base[(string) $n['id']] = (string) ($n['years'] ?? '');
    foreach ($overrides as $id => $years) $base[(string) $id] = (string) $years;
    return array_merge($overrides, kop_network_renames_apply_years($base, $decisions));
});

/* ---- Saving ----------------------------------------------------------- */

if (!function_exists('kop_network_renames_ajax')) {
    /**
     * POST action=kop_network_renames_decide, nonce, items: JSON list of
     * {key, decision: save|skip|undo, year (0 = not known), swapped}. Answers with the stored
     * state of each item and the years each name now has on the map.
     */
    function kop_network_renames_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Only an administrator can review renames.'), 403);
        }
        check_ajax_referer('kop_network_renames', 'nonce');
        $items = json_decode(wp_unslash((string) ($_POST['items'] ?? '[]')), true);
        if (!is_array($items) || !$items) {
            wp_send_json_error(array('message' => 'Nothing to save.'), 400);
        }
        $graph = kop_network_map_graph();
        $user = wp_get_current_user();
        $out = kop_network_renames_decide($items, $user ? $user->user_login : '', $graph ?: array());
        $decisions = kop_network_renames_decisions();
        // The years every name now has, for the cards to show.
        $base = kop_network_renames_base_years($graph ?: array());
        $applied = kop_network_renames_apply_years($base, $decisions);
        wp_send_json_success(array('saved' => $out, 'years' => (object) array_merge($base, $applied)));
    }
    add_action('wp_ajax_kop_network_renames_decide', 'kop_network_renames_ajax');
}

if (!function_exists('kop_network_renames_decide')) {
    /**
     * Store decisions: $items [{key, decision: save|skip|undo, year, swapped}],
     * keys as kop_network_renames_list() gives them for $graph. Returns
     * key => {decision, year, swapped} as stored, or {error} for an item refused.
     */
    function kop_network_renames_decide(array $items, $login, array $graph) {
        $keys = array();
        foreach (kop_network_renames_list($graph) as $r) $keys[$r['key']] = true;
        $decisions = kop_network_renames_decisions();
        $this_year = (int) gmdate('Y');
        $out = array();
        foreach ($items as $item) {
            $key = (string) ($item['key'] ?? '');
            if (!isset($keys[$key])) continue;
            $decision = (string) ($item['decision'] ?? '');
            if ($decision === 'undo') {
                unset($decisions[$key]);
                $out[$key] = array('decision' => '');
                continue;
            }
            if ($decision === 'save') {
                // No year is fine: the rename and its order are saved, the year can follow.
                $year = (int) ($item['year'] ?? 0);
                if ($year !== 0 && ($year < 1800 || $year > $this_year)) {
                    $out[$key] = array('error' => 'That year is not between 1800 and ' . $this_year . '. Leave it blank if you do not know it.');
                    continue;
                }
                $decisions[$key] = array('decision' => 'saved', 'year' => $year, 'swapped' => !empty($item['swapped']),
                    'by' => (string) $login, 'at' => gmdate('Y-m-d H:i:s'));
            } elseif ($decision === 'skip') {
                $decisions[$key] = array('decision' => 'skipped', 'year' => 0, 'swapped' => false,
                    'by' => (string) $login, 'at' => gmdate('Y-m-d H:i:s'));
            } else {
                continue;
            }
            $out[$key] = array('decision' => $decisions[$key]['decision'], 'year' => $decisions[$key]['year'], 'swapped' => $decisions[$key]['swapped']);
        }
        update_option('kop_network_rename_review', $decisions, false);
        return $out;
    }
}

/* ---- The review screen ------------------------------------------------ */

if (!function_exists('kop_network_renames_menu')) {
    function kop_network_renames_menu() {
        if (!function_exists('kop_tools_parent_slug')) return;
        add_submenu_page(kop_tools_parent_slug(), 'Map Renames', 'Map Renames', 'manage_options',
            'kop-network-renames', 'kop_network_renames_page');
    }
    add_action('admin_menu', 'kop_network_renames_menu', 22);
}

if (!function_exists('kop_network_renames_page')) {
    function kop_network_renames_page() {
        if (!current_user_can('manage_options')) return;
        $graph = kop_network_map_graph();
        $map = function_exists('kop_network_map_page_url') ? kop_network_map_page_url() : home_url('/network-map/');
        $base = kop_network_renames_base_years($graph ?: array());
        $decisions = kop_network_renames_decisions();
        $research = kop_network_renames_candidates();
        $now = array_merge($base, kop_network_renames_apply_years($base, $decisions));
        $rows = array();
        foreach (kop_network_renames_list($graph ?: array(), $base) as $r) {
            $a = kop_network_renames_assess($r);
            $d = $decisions[$r['key']] ?? array();
            $c = $research[$r['key']] ?? null;
            // A sourced year beats the guess from the board's own years.
            if ($c && !empty($c['year'])) {
                $a['suggest'] = (int) $c['year'];
                $a['why'] = 'research';
            }
            $link = function ($side) use ($map) {
                $side['mapUrl'] = $map . '#open=' . rawurlencode($side['id']);
                $side['pageUrl'] = $side['facilityId'] && function_exists('kop_facility_page_url') ? kop_facility_page_url($side['facilityId']) : '';
                unset($side['parsed']);
                return $side;
            };
            $rows[] = array(
                'key' => $r['key'],
                'earlier' => $link($r['earlier']),
                'later' => $link($r['later']),
                'flags' => $a['flags'],
                'suggest' => $a['suggest'],
                'why' => $a['why'],
                'decision' => (string) ($d['decision'] ?? ''),
                'year' => (int) ($d['year'] ?? 0),
                // Undecided: start the way the research says the names go.
                'swapped' => $d ? !empty($d['swapped']) : !empty($c['swapped']),
                'research' => $c ? array(
                    'year' => !empty($c['year']) ? (int) $c['year'] : null,
                    'swapped' => !empty($c['swapped']),
                    // False when the year is the source's date, not in its words: saved one by one.
                    'yearQuoted' => !isset($c['yearQuoted']) || !empty($c['yearQuoted']),
                    'confidence' => (string) ($c['confidence'] ?? 'low'),
                    'sources' => array_values(array_filter((array) ($c['sources'] ?? array()), function ($x) { return !empty($x['url']); })),
                    'note' => (string) ($c['note'] ?? ''),
                ) : null,
            );
        }
        // Problems first, then the rest, by name.
        // Sourced years first (strongest first), then problems, then by name.
        $rank = array('high' => 0, 'medium' => 1, 'low' => 2);
        $score = function ($r) use ($rank) {
            return $r['research'] && $r['research']['year'] ? $rank[$r['research']['confidence']] ?? 2 : 3;
        };
        usort($rows, function ($x, $y) use ($score) {
            return ($score($x) <=> $score($y)) ?: (count($y['flags']) <=> count($x['flags'])) ?: strcasecmp($x['earlier']['name'], $y['earlier']['name']);
        });
        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kop_network_renames'),
            'rows' => $rows,
            'years' => (object) $now,
        );
        ?>
        <div class="wrap kop-ren">
            <h1>Map renames</h1>
            <p class="kop-ren__intro">
                Each card is a program that changed its name, drawn on the <a href="<?php echo esc_url($map); ?>" target="_blank" rel="noopener">network map</a>
                as two names joined by a line. Each name belongs to its own years and operator, so the timeline shows the name
                it had that year: Copper Canyon Academy under Aspen until the rename, Sedona Sky Academy under Family Help &amp; Wellness after.
                Give the year of the rename and save; the earlier name then ends that year and the later one starts it, on the map at once.
                <strong>If you do not know the year, leave it blank and save anyway</strong>: the rename and its order are kept, and the year can be added later on the Saved tab.
                If the arrow points the wrong way, press <strong>Swap order</strong> first. Everything saves as you click.
            </p>
            <div class="kop-ren__bar">
                <div class="kop-ren__tabs" role="tablist">
                    <button type="button" data-tab="review" aria-selected="true">To review <span></span></button>
                    <button type="button" data-tab="saved" aria-selected="false">Saved <span></span></button>
                    <button type="button" data-tab="skipped" aria-selected="false">Not a rename <span></span></button>
                </div>
                <button type="button" class="button button-primary kop-ren__bulk" hidden></button>
                <span class="kop-ren__status" role="status" aria-live="polite"></span>
            </div>
            <div class="kop-ren__list"></div>
        </div>
        <style>
            .kop-ren__intro { max-width: 62em; font-size: 14px; }
            .kop-ren__bar { position: sticky; top: 32px; z-index: 5; display: flex; flex-wrap: wrap; align-items: center; gap: 12px;
                margin: 16px 0; padding: 10px 12px; background: #fff; border: 1px solid #dcdcde; border-radius: 6px; }
            .kop-ren__tabs { display: flex; flex-wrap: wrap; gap: 4px; }
            .kop-ren__tabs button { padding: 6px 12px; border: 1px solid #c3c4c7; border-radius: 999px; background: #f6f7f7; cursor: pointer; font-size: 13px; }
            .kop-ren__tabs button[aria-selected="true"] { background: #000080; border-color: #000080; color: #fff; }
            .kop-ren__tabs span { font-weight: 600; }
            .kop-ren__status { color: #1d7a33; font-weight: 600; }
            .kop-ren__list { display: grid; gap: 12px; max-width: 1100px; }
            .kop-ren__card { padding: 14px 16px; background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #33a7b5; border-radius: 6px; }
            .kop-ren__card.is-saved { border-left-color: #1d7a33; }
            .kop-ren__card.is-skipped { border-left-color: #8c8f94; opacity: .85; }
            .kop-ren__pair { display: grid; grid-template-columns: 1fr auto 1fr; gap: 12px; align-items: start; }
            @media (max-width: 782px) { .kop-ren__pair { grid-template-columns: 1fr; } .kop-ren__arrow { transform: rotate(90deg); justify-self: start; } }
            .kop-ren__side { padding: 10px 12px; background: #f6f7f7; border-radius: 6px; }
            .kop-ren__label { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #50575e; }
            .kop-ren__name { margin: 2px 0 4px; font-size: 16px; }
            .kop-ren__line { margin: 2px 0; color: #1d2327; }
            .kop-ren__now { font-weight: 600; }
            .kop-ren__arrow { align-self: center; font-size: 22px; color: #000080; }
            .kop-ren__flags { display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0 0; }
            .kop-ren__flag { padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #fff3cd; color: #6b4e00; }
            .kop-ren__flag.is-bad { background: #fbe3e4; color: #7a1c1f; }
            .kop-ren__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin-top: 12px; }
            .kop-ren__actions label { display: inline-flex; align-items: center; gap: 6px; font-weight: 600; }
            .kop-ren__actions input[type="number"] { width: 6.5em; }
            .kop-ren__hint { color: #50575e; }
            .kop-ren__error { color: #b32d2e; font-weight: 600; }
            .kop-ren__done { font-weight: 600; }
            .kop-ren__research { margin: 12px 0 0; padding: 10px 12px; background: #f0f6fc; border-radius: 6px; }
            .kop-ren__research h3 { margin: 0 0 6px; font-size: 13px; }
            .kop-ren__sources { margin: 6px 0; padding: 0; list-style: none; }
            .kop-ren__sources li { margin: 4px 0; padding: 6px 10px; background: #fff; border-radius: 4px; }
            .kop-ren__sources q { font-style: italic; }
            .kop-ren__badge { padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #f0f0f1; }
            .kop-ren__badge.is-high { background: #d7f0dd; color: #14532d; }
            .kop-ren__badge.is-medium { background: #fff3cd; color: #6b4e00; }
            .kop-ren__badge.is-low { background: #fbe3e4; color: #7a1c1f; }
            .kop-ren__empty { padding: 24px; background: #fff; border: 1px dashed #c3c4c7; border-radius: 6px; text-align: center; }
        </style>
        <script>
        (function () {
            var C = <?php echo wp_json_encode($config); ?>;
            var list = document.querySelector('.kop-ren__list');
            var status = document.querySelector('.kop-ren__status');
            var bulk = document.querySelector('.kop-ren__bulk');
            var tab = 'review';
            var rows = C.rows;
            var years = C.years;
            var FLAG = {
                same: ['Both names have the same years', false],
                overlap: ['The later name starts before the earlier one ended', false],
                missing: ['Years missing on one side', false],
                reversed: ['Looks reversed: the "later" name ended before the "earlier" one began', true]
            };

            function el(tag, cls, text) {
                var n = document.createElement(tag);
                if (cls) n.className = cls;
                if (text !== undefined) n.textContent = text;
                return n;
            }
            function tabOf(r) { return r.decision === 'saved' ? 'saved' : r.decision === 'skipped' ? 'skipped' : 'review'; }
            function yearsText(y) {
                if (!y) return 'no years';
                return y.replace(/^(\d{4})-(\d{4})$/, '$1 to $2');
            }

            function save(items) {
                var body = new FormData();
                body.append('action', 'kop_network_renames_decide');
                body.append('nonce', C.nonce);
                body.append('items', JSON.stringify(items));
                status.textContent = 'Saving...';
                fetch(C.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (res) { return res.json(); })
                    .then(function (json) {
                        if (!json || !json.success) throw new Error((json && json.data && json.data.message) || 'Not saved.');
                        var saved = json.data.saved || {};
                        var errors = 0;
                        rows.forEach(function (r) {
                            var s = saved[r.key];
                            if (!s) return;
                            if (s.error) { r.error = s.error; errors++; return; }
                            r.error = '';
                            r.decision = s.decision;
                            r.year = s.year || 0;
                            r.swapped = !!s.swapped;
                        });
                        if (json.data.years) years = json.data.years;
                        status.textContent = errors ? errors + ' not saved; see the red note.' : 'Saved.';
                        render();
                    })
                    .catch(function (e) { status.textContent = 'Not saved: ' + e.message; });
            }

            function side(s, label) {
                var box = el('div', 'kop-ren__side');
                box.appendChild(el('div', 'kop-ren__label', label));
                box.appendChild(el('h2', 'kop-ren__name', s.name));
                box.appendChild(el('p', 'kop-ren__line', 'Years on the board: ' + yearsText(s.years)));
                var now = years[s.id] || '';
                if (now !== s.years) box.appendChild(el('p', 'kop-ren__line kop-ren__now', 'On the map now: ' + yearsText(now)));
                box.appendChild(el('p', 'kop-ren__line', 'Operator: ' + (s.operators.length ? s.operators.join('; ') : (s.chain || 'none recorded'))));
                var links = el('p', 'kop-ren__line');
                var m = el('a', '', 'On the map'); m.href = s.mapUrl; m.target = '_blank'; m.rel = 'noopener';
                links.appendChild(m);
                if (s.pageUrl) {
                    links.appendChild(document.createTextNode(' · '));
                    var p = el('a', '', 'Facility page'); p.href = s.pageUrl; p.target = '_blank'; p.rel = 'noopener';
                    links.appendChild(p);
                }
                box.appendChild(links);
                return box;
            }

            function card(r) {
                var c = el('article', 'kop-ren__card' + (r.decision ? ' is-' + r.decision : ''));
                var swapped = !!r.swapped;
                var first = swapped ? r.later : r.earlier;
                var second = swapped ? r.earlier : r.later;
                var pair = el('div', 'kop-ren__pair');
                pair.appendChild(side(first, 'Earlier name'));
                pair.appendChild(el('div', 'kop-ren__arrow', '→'));
                pair.appendChild(side(second, 'Later name'));
                c.appendChild(pair);

                if (r.flags.length && !r.decision) {
                    var flags = el('div', 'kop-ren__flags');
                    r.flags.forEach(function (f) { flags.appendChild(el('span', 'kop-ren__flag' + (FLAG[f][1] ? ' is-bad' : ''), FLAG[f][0])); });
                    c.appendChild(flags);
                }

                if (r.research) c.appendChild(researchBox(r.research));

                var actions = el('div', 'kop-ren__actions');
                if (r.decision === 'saved') {
                    actions.appendChild(el('span', 'kop-ren__done', r.year
                        ? 'Saved: renamed in ' + r.year + (swapped ? ' (order swapped)' : '') + '. On the map now.'
                        : 'Saved without a year' + (swapped ? ' (order swapped)' : '') + '.'));
                    if (!r.year) {
                        // The year can follow whenever it turns up.
                        var later = el('input'); later.type = 'number'; later.min = 1800; later.max = new Date().getFullYear();
                        later.placeholder = 'year'; later.value = r.suggest || '';
                        var laterLab = el('label', '', 'Renamed in '); laterLab.appendChild(later);
                        actions.appendChild(laterLab);
                        var add = el('button', 'button', 'Add the year');
                        add.type = 'button';
                        add.addEventListener('click', function () {
                            if (!Number(later.value)) { r.error = 'Type the year first.'; render(); return; }
                            save([{ key: r.key, decision: 'save', year: Number(later.value), swapped: swapped }]);
                        });
                        actions.appendChild(add);
                        if (r.error) actions.appendChild(el('span', 'kop-ren__error', r.error));
                    }
                } else if (r.decision === 'skipped') {
                    actions.appendChild(el('span', 'kop-ren__done', 'Marked not a rename. The map is unchanged.'));
                }
                if (r.decision) {
                    var undo = el('button', 'button', 'Undo');
                    undo.type = 'button';
                    undo.addEventListener('click', function () { save([{ key: r.key, decision: 'undo' }]); });
                    actions.appendChild(undo);
                    c.appendChild(actions);
                    return c;
                }

                var year = el('input'); year.type = 'number'; year.min = 1800; year.max = new Date().getFullYear();
                year.placeholder = 'not known'; year.value = r.suggest || '';
                var lab = el('label', '', 'Renamed in '); lab.appendChild(year);
                actions.appendChild(lab);
                actions.appendChild(el('span', 'kop-ren__hint', 'Blank is fine.'));
                var ok = el('button', 'button button-primary', 'Save');
                ok.type = 'button';
                ok.addEventListener('click', function () {
                    save([{ key: r.key, decision: 'save', year: Number(year.value) || 0, swapped: swapped }]);
                });
                actions.appendChild(ok);
                var swap = el('button', 'button', 'Swap order');
                swap.type = 'button';
                swap.addEventListener('click', function () { r.swapped = !r.swapped; render(); });
                actions.appendChild(swap);
                var skip = el('button', 'button-link', 'Not a rename');
                skip.type = 'button';
                skip.addEventListener('click', function () { save([{ key: r.key, decision: 'skip' }]); });
                actions.appendChild(skip);
                if (r.suggest && r.why === 'research') actions.appendChild(el('span', 'kop-ren__hint', 'Year from the sources above; check the quote.'));
                else if (r.suggest && !swapped) actions.appendChild(el('span', 'kop-ren__hint', 'Suggested from ' + r.why + '; check it.'));
                if (r.research && r.research.swapped && !r.decision) actions.appendChild(el('span', 'kop-ren__hint', 'The sources say the board had these the wrong way round, so the order is already swapped.'));
                if (r.flags.indexOf('reversed') !== -1 && !swapped) actions.appendChild(el('span', 'kop-ren__hint', 'Probably drawn backwards: press Swap order.'));
                if (r.error) actions.appendChild(el('span', 'kop-ren__error', r.error));
                c.appendChild(actions);
                return c;
            }

            function researchBox(x) {
                var box = el('div', 'kop-ren__research');
                var h = el('h3', '', x.year ? 'Research: renamed in ' + x.year + ' ' : 'Research: no year found ');
                if (x.year) h.appendChild(el('span', 'kop-ren__badge is-' + x.confidence, x.confidence + ' confidence'));
                box.appendChild(h);
                if (x.sources.length) {
                    var ul = el('ul', 'kop-ren__sources');
                    x.sources.forEach(function (s) {
                        var li = el('li');
                        if (s.quote) { li.appendChild(el('q', '', s.quote)); li.appendChild(document.createTextNode(' ')); }
                        var a = el('a', '', hostOf(s.url)); a.href = s.url; a.target = '_blank'; a.rel = 'noopener nofollow';
                        li.appendChild(a);
                        ul.appendChild(li);
                    });
                    box.appendChild(ul);
                }
                if (x.note) box.appendChild(el('p', 'kop-ren__line', x.note));
                return box;
            }
            function hostOf(url) {
                try { return new URL(url).hostname.replace(/^www\./, ''); } catch (e) { return url; }
            }
            function sure(r) {
                return tabOf(r) === 'review' && r.research && r.research.year && r.research.yearQuoted && r.research.confidence === 'high';
            }

            function render() {
                var counts = { review: 0, saved: 0, skipped: 0 };
                rows.forEach(function (r) { counts[tabOf(r)]++; });
                document.querySelectorAll('.kop-ren__tabs button').forEach(function (b) {
                    var t = b.getAttribute('data-tab');
                    b.querySelector('span').textContent = '(' + counts[t] + ')';
                    b.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                });
                list.textContent = '';
                var shown = rows.filter(function (r) { return tabOf(r) === tab; });
                if (!shown.length) list.appendChild(el('p', 'kop-ren__empty', tab === 'review' ? 'Nothing left to review.' : 'Nothing here.'));
                shown.forEach(function (r) { list.appendChild(card(r)); });
                var high = rows.filter(sure);
                bulk.hidden = tab !== 'review' || !high.length;
                bulk.textContent = 'Save all ' + high.length + ' high-confidence years';
            }

            bulk.addEventListener('click', function () {
                save(rows.filter(sure).map(function (r) {
                    return { key: r.key, decision: 'save', year: r.research.year, swapped: !!r.research.swapped };
                }));
            });

            document.querySelectorAll('.kop-ren__tabs button').forEach(function (b) {
                b.addEventListener('click', function () { tab = b.getAttribute('data-tab'); status.textContent = ''; render(); });
            });
            render();
        })();
        </script>
        <?php
    }
}
