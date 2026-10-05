<?php
/**
 * A renamed program's page, cut into one section per name.
 *
 * A name belongs to the years it had (docs/PLAN.md 3.7): Copper Canyon
 * Academy until 2014, Sedona Sky Academy from 2014. Where the network map
 * joins a program's names by rename lines and the year of each rename is
 * known, the facility page prints "As Copper Canyon Academy" and "As Sedona
 * Sky Academy", earliest first, each with the deaths, serious findings,
 * lawsuits, incidents, news and staff of its own years. What cannot be dated
 * stays in the page's ordinary sections below.
 *
 * The names come from the map's "rebrand" lines; the years from Map Renames
 * (inc/network-renames.php: a saved rename year, a swapped line) over Map
 * Years and graph.json. A rename with no usable year (both names carrying the
 * same years, overlapping years, none at all) leaves the page whole, as does
 * a name that became two or two that became one.
 *
 * The names can be one record or several. Where another name has its own
 * record, that record's items are shown here too, and its page shows this
 * one's: both pages print every name's section. An item goes to the name in
 * use at its date whichever record holds it; an undated one goes to its
 * record's name when every name has a record and the record holds one name.
 *
 * @package KidsOverProfits
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_facility_eras_kinds')) {
    /** The page's dated lists an era section can hold, in the order they print. */
    function kop_facility_eras_kinds() {
        return array('memorials', 'violations', 'lawsuits', 'incidents', 'news', 'staff');
    }
}

if (!function_exists('kop_facility_eras_map')) {
    /**
     * The map's rename lines as the page reads them, held for the request:
     * 'nodes' id => node, 'years' id => array(start|null, end|null),
     * 'next' / 'prev' id => ids, 'cut' "earlier>later" => year a saved
     * rename gives (0 = none), 'operators' id => names, 'facility' facility
     * id => ids of its names on a rename line. Lines the owner skipped at
     * Map Renames are left out.
     */
    function kop_facility_eras_map() {
        static $memo = null;
        if ($memo !== null) return $memo;
        $memo = array('nodes' => array(), 'years' => array(), 'next' => array(), 'prev' => array(), 'cut' => array(), 'operators' => array(), 'facility' => array());
        $graph = function_exists('kop_network_map_graph') ? kop_network_map_graph() : null;
        if (!$graph || !function_exists('kop_network_renames_decisions')) return $memo;

        $decisions = kop_network_renames_decisions();
        $nodes = array();
        foreach ($graph['nodes'] as $n) $nodes[(string) $n['id']] = $n;
        $on_line = array();
        foreach ((array) ($graph['edges'] ?? array()) as $e) {
            $a = (string) $e['source'];
            $b = (string) $e['target'];
            if (!isset($nodes[$a], $nodes[$b])) continue;
            if (!in_array('rebrand', (array) ($e['roles'] ?? array()), true)) {
                // The company is the end of a corporate line that is not a program.
                if (($e['category'] ?? '') !== 'corporate') continue;
                foreach (array(array($a, $b), array($b, $a)) as $pair) {
                    $place = $nodes[$pair[0]];
                    $company = $nodes[$pair[1]];
                    if (($place['kind'] ?? '') !== 'facility' || in_array($company['kind'] ?? '', array('facility', 'person'), true)) continue;
                    $memo['operators'][$pair[0]][(string) $company['name']] = true;
                }
                continue;
            }
            $d = $decisions[$a . '>' . $b] ?? array();
            if (($d['decision'] ?? '') === 'skipped') continue;
            $saved = ($d['decision'] ?? '') === 'saved';
            list($earlier, $later) = ($saved && !empty($d['swapped'])) ? array($b, $a) : array($a, $b);
            $memo['next'][$earlier][] = $later;
            $memo['prev'][$later][] = $earlier;
            $memo['cut'][$earlier . '>' . $later] = $saved ? (int) ($d['year'] ?? 0) : 0;
            $on_line[$a] = $on_line[$b] = true;
        }
        $base = kop_network_renames_base_years($graph);
        $base = array_merge($base, kop_network_renames_apply_years($base, $decisions));
        foreach (array_keys($on_line) as $id) {
            $id = (string) $id;
            $memo['nodes'][$id] = $nodes[$id];
            $memo['years'][$id] = kop_network_renames_parse_years($base[$id] ?? '') ?: array(null, null);
            $memo['operators'][$id] = array_keys($memo['operators'][$id] ?? array());
            $fid = (int) ($nodes[$id]['facilityId'] ?? 0);
            if ($fid > 0) $memo['facility'][$fid][] = $id;
        }
        return $memo;
    }
}

if (!function_exists('kop_facility_eras_chain')) {
    /**
     * The facility's names in order, earliest first, or array() when its
     * page cannot be cut: [{node, name, start, end, cut, facility_id,
     * operators}]. start and end are the name's years (null = not known);
     * cut is the year the name came into use (null for the first), which is
     * what dated items are sorted by.
     */
    function kop_facility_eras_chain($facility_id) {
        $map = kop_facility_eras_map();
        $mine = $map['facility'][(int) $facility_id] ?? array();
        if (!$mine) return array();

        // Every name joined to this record's by rename lines.
        $seen = array();
        $queue = $mine;
        while ($queue) {
            $id = (string) array_shift($queue);
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            foreach (array_merge($map['next'][$id] ?? array(), $map['prev'][$id] ?? array()) as $other) $queue[] = $other;
        }
        if (count($seen) < 2) return array();
        // One line of names only: nothing that split or joined.
        $head = null;
        foreach (array_keys($seen) as $id) {
            $id = (string) $id;
            if (count($map['next'][$id] ?? array()) > 1 || count($map['prev'][$id] ?? array()) > 1) return array();
            if (empty($map['prev'][$id])) {
                if ($head !== null) return array();
                $head = $id;
            }
        }
        if ($head === null) return array();
        $order = array();
        for ($id = $head; $id !== null; $id = isset($map['next'][$id][0]) ? (string) $map['next'][$id][0] : null) {
            if (in_array($id, $order, true)) return array();
            $order[] = $id;
        }
        if (count($order) !== count($seen)) return array();

        $chain = array();
        $last_cut = 0;
        foreach ($order as $i => $id) {
            $years = $map['years'][$id];
            $cut = null;
            if ($i > 0) {
                $before = $order[$i - 1];
                $was = $map['years'][$before];
                $cut = (int) ($map['cut'][$before . '>' . $id] ?? 0);
                if (!$cut) {
                    // No saved rename: the names' own years must say when.
                    if ($was === $years) return array();
                    if ($was[1] && $years[0] && $years[0] < $was[1]) return array();
                    $cut = (int) ($years[0] && $years[0] > (int) $was[0] ? $years[0] : $was[1]);
                }
                if (!$cut || $cut < $last_cut) return array();
                $last_cut = $cut;
                $chain[$i - 1]['end'] = $chain[$i - 1]['end'] ?: $cut;
                // The board gave many earlier names the later name's first year: not a start.
                if ($chain[$i - 1]['start'] && $chain[$i - 1]['start'] >= $cut) $chain[$i - 1]['start'] = null;
            }
            $start = $years[0] && (!$cut || $years[0] >= $cut) ? (int) $years[0] : $cut;
            $chain[] = array(
                'node'        => $id,
                'name'        => trim((string) $map['nodes'][$id]['name']),
                'start'       => $start ?: null,
                'end'         => $years[1] && (!$cut || $years[1] >= $cut) ? (int) $years[1] : null,
                'cut'         => $cut,
                'facility_id' => (int) ($map['nodes'][$id]['facilityId'] ?? 0),
                'operators'   => $map['operators'][$id],
            );
        }
        return $chain;
    }
}

if (!function_exists('kop_facility_eras_years_label')) {
    function kop_facility_eras_years_label($start, $end) {
        $start = (int) $start;
        $end = (int) $end;
        if ($start && $end) return $start === $end ? (string) $start : $start . ' to ' . $end;
        if ($start) return 'from ' . $start;
        if ($end) return 'until ' . $end;
        return '';
    }
}

if (!function_exists('kop_facility_eras_record_items')) {
    /**
     * Another record's dated lists, read as its own page reads them:
     * kind => items, or null when the record is gone.
     */
    function kop_facility_eras_record_items($facility_id) {
        global $wpdb;
        $facility_id = (int) $facility_id;
        $row = $wpdb->get_row($wpdb->prepare('SELECT id, unique_name, json_data FROM facilities_v2 WHERE id = %d', $facility_id), ARRAY_A);
        $doc = $row ? kop_v2_decode($row['json_data']) : null;
        if (!is_array($doc)) return null;
        $loc = is_array($doc['location'] ?? null) ? $doc['location'] : array();
        $state_code = strtoupper(trim((string) ($loc['state'] ?? '')));
        $state_name = ($state_code !== '' && function_exists('kop_state_canonical_name')) ? kop_state_canonical_name($state_code) : '';
        if (strlen($state_name) <= 2) $state_name = '';
        $name_keys = kop_facility_pages_doc_name_keys($doc, (string) $row['unique_name']);
        $inspections = kop_facility_pages_inspections($name_keys, $state_code, $state_name, $facility_id);
        $incidents = array();
        foreach (kop_facility_pages_incidents(kop_facility_pages_checklist_items($doc['criticalIncidents'] ?? null, 'criticalIncidents')) as $inc) {
            // Forum reports are survivor testimony on the record's own page, not incidents.
            if (stripos((string) $inc['cite'], 'Fornits') !== 0) $incidents[] = $inc;
        }
        return array(
            'memorials'  => kop_facility_pages_memorials($name_keys, $state_name),
            'violations' => (array) ($inspections['violations'] ?? array()),
            'lawsuits'   => kop_facility_pages_lawsuits($facility_id, $name_keys),
            'incidents'  => $incidents,
            'news'       => kop_facility_pages_news($facility_id),
            'staff'      => kop_facility_pages_add_map_people(kop_facility_pages_staff_items($doc['staff'] ?? null, $facility_id), $facility_id),
        );
    }
}

if (!function_exists('kop_facility_eras_item_year')) {
    /** The year an item is dated to, 0 when it has none. */
    function kop_facility_eras_item_year($kind, array $item) {
        if ($kind === 'incidents') {
            $y = (int) ($item['year'] ?? 0);
            return $y === 9999 ? 0 : $y;
        }
        $date = (string) ($kind === 'lawsuits' ? ($item['year'] ?? '') : ($item['date'] ?? ''));
        return preg_match('/^(\d{4})/', $date, $m) && (int) $m[1] > 0 ? (int) $m[1] : 0;
    }
}

if (!function_exists('kop_facility_eras_item_text')) {
    /** The words that may name the program, for an item dated to a rename year. */
    function kop_facility_eras_item_text($kind, array $item) {
        $keys = array(
            'memorials' => array('program'),
            'lawsuits'  => array('case_name', 'summary'),
            'incidents' => array('text'),
            'news'      => array('title', 'summary'),
        )[$kind] ?? array();
        $text = '';
        foreach ($keys as $k) $text .= ' ' . (string) ($item[$k] ?? '');
        return strtolower($text);
    }
}

if (!function_exists('kop_facility_eras_place')) {
    /**
     * Which name an item belongs to: the index in $chain, or -1 for none.
     * A dated item goes to the name in use that year; in the year of a
     * rename it goes to the later name unless its words give only the
     * earlier one. An undated item goes to $home, its record's one name.
     */
    function kop_facility_eras_place(array $chain, $year, $text, $home) {
        if (!$year) return $home;
        $at = 0;
        foreach ($chain as $i => $era) {
            if ($i > 0 && $year >= $era['cut']) $at = $i;
        }
        if ($at > 0 && $year === (int) $chain[$at]['cut'] && $text !== '') {
            $later = strpos($text, strtolower($chain[$at]['name'])) !== false;
            $earlier = strpos($text, strtolower($chain[$at - 1]['name'])) !== false;
            if ($earlier && !$later) $at--;
        }
        return $at;
    }
}

if (!function_exists('kop_facility_eras_build')) {
    /**
     * The page's name sections, or null when the page stays whole.
     * $own is this record's lists (kind => items, as kop_facility_page_data()
     * read them). Returns:
     *   'list'   => [{id, name, years, operators, url, memorials, violations,
     *                 lawsuits, incidents, news, staff}], earliest name first
     *   'rest'   => kind => what no name took (the page's ordinary sections)
     *   'totals' => kind => how many the page holds in all (staff: people)
     * Every item carries '_fid', the record it came from.
     */
    function kop_facility_eras_build($facility_id, array $own) {
        $facility_id = (int) $facility_id;
        $chain = kop_facility_eras_chain($facility_id);
        if (count($chain) < 2) return null;

        // Each record's lists, and the one name a record's undated items belong to.
        $records = array($facility_id => $own);
        $names_of = array();
        $every_name_has_a_record = true;
        foreach ($chain as $i => $era) {
            $fid = $era['facility_id'];
            if ($fid <= 0) {
                $every_name_has_a_record = false;
                continue;
            }
            $names_of[$fid][] = $i;
            if (!isset($records[$fid])) {
                $items = kop_facility_eras_record_items($fid);
                if ($items !== null) $records[$fid] = $items;
            }
        }

        $kinds = kop_facility_eras_kinds();
        $list = array();
        foreach ($chain as $i => $era) {
            $fid = $era['facility_id'];
            $entry = array(
                'id'        => 'as-' . sanitize_title($era['name']),
                'name'      => $era['name'],
                'years'     => kop_facility_eras_years_label($era['start'], $era['end']),
                'operators' => $era['operators'],
                'url'       => ($fid > 0 && $fid !== $facility_id) ? kop_facility_page_url($fid) : '',
            );
            foreach ($kinds as $kind) $entry[$kind] = array();
            $list[$i] = $entry;
        }
        $rest = array_fill_keys($kinds, array());
        $seen = array();
        foreach ($records as $fid => $lists) {
            $home = ($every_name_has_a_record && count($names_of[$fid] ?? array()) === 1) ? $names_of[$fid][0] : -1;
            foreach ($kinds as $kind) {
                if ($kind === 'staff') {
                    // Staff lists carry no dates: a record's people stand under its one name.
                    $staff = (array) ($lists['staff'] ?? array());
                    foreach ($staff as $group => $people) {
                        foreach ($people as $person) {
                            $person['_fid'] = $fid;
                            if ($home >= 0) $list[$home]['staff'][$group][] = $person;
                            elseif ($fid === $facility_id) $rest['staff'][$group][] = $person;
                        }
                    }
                    continue;
                }
                foreach ((array) ($lists[$kind] ?? array()) as $item) {
                    $key = $kind . ':' . (isset($item['id']) ? (int) $item['id'] : md5((string) ($item['text'] ?? serialize($item))));
                    if (isset($seen[$key])) continue;
                    $year = kop_facility_eras_item_year($kind, $item);
                    $at = kop_facility_eras_place($chain, $year, kop_facility_eras_item_text($kind, $item), $home);
                    // Another record's undated item with no name to stand under is not this page's.
                    if ($at < 0 && $fid !== $facility_id) continue;
                    $seen[$key] = true;
                    $item['_fid'] = $fid;
                    if ($at >= 0) $list[$at][$kind][] = $item;
                    else $rest[$kind][] = $item;
                }
            }
        }

        $sorts = array(
            'memorials'  => static function ($a, $b) { return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')) ?: $b['id'] - $a['id']; },
            'violations' => static function ($a, $b) { return ($b['severe'] <=> $a['severe']) ?: ($b['weight'] <=> $a['weight']) ?: strcmp((string) $b['date'], (string) $a['date']) ?: ($b['id'] <=> $a['id']); },
            'lawsuits'   => static function ($a, $b) { return strcmp((string) $b['year'], (string) $a['year']) ?: $b['id'] - $a['id']; },
            'incidents'  => static function ($a, $b) { return ($a['year'] <=> $b['year']) ?: ($a['i'] <=> $b['i']); },
            'news'       => static function ($a, $b) { return strcmp((string) $b['date'], (string) $a['date']) ?: $b['id'] - $a['id']; },
        );
        $totals = array_fill_keys($kinds, 0);
        $filled = 0;
        $count = static function ($kind, $items) {
            return $kind === 'staff' ? count($items['administrator'] ?? array()) + count($items['notableStaff'] ?? array()) + count($items['pastTTIJobs'] ?? array()) : count($items);
        };
        foreach ($list as $i => $entry) {
            $has = 0;
            foreach ($kinds as $kind) {
                if (isset($sorts[$kind])) usort($list[$i][$kind], $sorts[$kind]);
                $has += $count($kind, $list[$i][$kind]);
                $totals[$kind] += $kind === 'staff'
                    ? count($list[$i]['staff']['administrator'] ?? array()) + count($list[$i]['staff']['notableStaff'] ?? array())
                    : count($list[$i][$kind]);
            }
            if ($has > 0) $filled++;
        }
        // Nothing dated at all: the "Formerly" line already says what there is to say.
        if ($filled === 0) return null;
        foreach ($kinds as $kind) {
            if (isset($sorts[$kind])) usort($rest[$kind], $sorts[$kind]);
            $totals[$kind] += $kind === 'staff'
                ? count($rest['staff']['administrator'] ?? array()) + count($rest['staff']['notableStaff'] ?? array())
                : count($rest[$kind]);
        }
        return array('list' => array_values($list), 'rest' => $rest, 'totals' => $totals);
    }
}
