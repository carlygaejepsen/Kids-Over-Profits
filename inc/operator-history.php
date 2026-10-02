<?php
/**
 * The hub parts of an operator page (/operator/<slug>/, inc/operator-pages.php):
 * its history, the people who ran it, the documents filed under its programs,
 * and the /operator/ index of every company.
 *
 * History comes from three places:
 *   - the written history on the record (operator.history, a list of
 *     paragraphs, with operator.historySources and operator.historyStatus).
 *     A draft is shown to admins only, under a banner; an admin publishes it
 *     with the pencil (inline edit, "operator:<id>:history"). Drafts arrive
 *     from seeds/operator-histories.json, which fills a record whose history
 *     is empty and never touches one a person has written or published.
 *   - a timeline built from what the database already holds: the years the
 *     network map gives for each program it ran ("operated 2009-2021"), the
 *     programs' own opening and closing years where the map has none, the
 *     year it was founded, lawsuits filed and deaths on record.
 *   - the network map's leadership, admissions and clinical links to the
 *     company (founders, chief executives, vice presidents), each with the
 *     rest of their career in the industry.
 *
 * Guarded with function_exists so scripts/test-operator-pages.php can load it
 * next to WordPress stubs.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_OPERATOR_HISTORY_SEED_VERSION')) {
    define('KOP_OPERATOR_HISTORY_SEED_VERSION', '1');
}

// ---------------------------------------------------------------------------
// The company on the network map
// ---------------------------------------------------------------------------

if (!function_exists('kop_operator_history_map_node')) {
    /** The map node (parent, association, church...) named by any of $names, or null. */
    function kop_operator_history_map_node(array $names) {
        if (!function_exists('kop_network_map_graph')) return null;
        $graph = kop_network_map_graph();
        if (!$graph) return null;
        $want = array();
        foreach ($names as $n) {
            $k = kop_facility_pages_name_key($n);
            if ($k !== '') $want[$k] = true;
        }
        foreach ($graph['nodes'] as $node) {
            $kind = $node['kind'] ?? '';
            if ($kind === 'facility' || $kind === 'person') continue;
            foreach (array_merge(array((string) ($node['name'] ?? '')), (array) ($node['aliases'] ?? array())) as $c) {
                if (isset($want[kop_facility_pages_name_key($c)])) return $node;
            }
        }
        return null;
    }
}

if (!function_exists('kop_operator_history_map_links')) {
    /** [{node, category, role, direction}] for every map line touching $node_id. */
    function kop_operator_history_map_links($node_id) {
        $graph = function_exists('kop_network_map_graph') ? kop_network_map_graph() : null;
        if (!$graph || $node_id === '') return array();
        $nodes = array();
        foreach ($graph['nodes'] as $n) $nodes[$n['id']] = $n;
        $out = array();
        foreach ($graph['edges'] as $e) {
            if ($e['source'] !== $node_id && $e['target'] !== $node_id) continue;
            $other = $e['source'] === $node_id ? $e['target'] : $e['source'];
            if (!isset($nodes[$other])) continue;
            // The board export wrote en dashes as U+FFFD.
            $role = trim(str_replace(array("\xEF\xBF\xBD", "\xE2\x80\x93", "\xE2\x80\x94"), '-', implode(', ', (array) ($e['roles'] ?? array()))));
            $out[] = array(
                'node'      => $nodes[$other],
                'category'  => (string) ($e['category'] ?? ''),
                'role'      => preg_replace('/\s*-\s*/', '-', $role),
                // 'acquirer' means this company bought the other end.
                'direction' => ($e['direction'] ?? 'none') === 'acquirer' && $e['source'] !== $node_id ? 'acquired-by' : (string) ($e['direction'] ?? 'none'),
            );
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// Timeline
// ---------------------------------------------------------------------------

if (!function_exists('kop_operator_history_years')) {
    /** "operated 2009-2021" => [2009, 2021]; "operated (2016)" => [2016, 0]; else [0, 0]. */
    function kop_operator_history_years($role) {
        if (preg_match('/\b(1[89]\d\d|20\d\d)\s*-\s*(1[89]\d\d|20\d\d)\b/', $role, $m)) return array((int) $m[1], (int) $m[2]);
        if (preg_match('/\b(1[89]\d\d|20\d\d)\b/', $role, $m)) return array((int) $m[1], 0);
        return array(0, 0);
    }
}

if (!function_exists('kop_operator_history_timeline')) {
    /**
     * Year => list of {kind, text, items: [{name, url}]}, oldest first. Events
     * of one kind in one year are one line ("Began running 4 programs: ...").
     *
     * $ctx: founded, facilities (operator page rows), links (map links),
     * lawsuits, memorials.
     */
    function kop_operator_history_timeline(array $ctx) {
        $events = array();
        $add = static function ($year, $kind, $text, $item = null) use (&$events) {
            $year = (int) $year;
            if ($year < 1800 || $year > (int) gmdate('Y') + 1) return;
            if (!isset($events[$year][$kind])) $events[$year][$kind] = array('kind' => $kind, 'text' => $text, 'items' => array());
            if ($item) $events[$year][$kind]['items'][kop_facility_pages_name_key($item['name']) . '|' . count($events[$year][$kind]['items'])] = $item;
        };

        if (preg_match('/\b(1[89]\d\d|20\d\d)\b/', (string) ($ctx['founded'] ?? ''), $m)) {
            $add($m[1], 'founded', 'Founded');
        }

        // The years the map gives for each program it ran win over the
        // program's own years, which can predate the company.
        $by_key = array();
        foreach ((array) ($ctx['facilities'] ?? array()) as $f) $by_key[kop_facility_pages_name_key($f['name'])] = $f;
        $map_years = array();
        foreach ((array) ($ctx['links'] ?? array()) as $l) {
            if ($l['category'] !== 'corporate' || ($l['node']['kind'] ?? '') !== 'facility') continue;
            list($from, $to) = kop_operator_history_years($l['role']);
            $name = (string) $l['node']['name'];
            $fid = (int) ($l['node']['facilityId'] ?? 0);
            $url = $fid > 0 && function_exists('kop_facility_page_url') ? kop_facility_page_url($fid) : '';
            $item = array('name' => $name, 'url' => $url);
            $map_years[kop_facility_pages_name_key($name)] = true;
            if ($l['direction'] === 'acquirer') {
                if ($from) $add($from, 'acquired', 'Acquired', $item);
                continue;
            }
            if (!$from) continue;
            $add($from, 'began', 'Began running', $item);
            if ($to) $add($to, 'ended', 'Stopped running', $item);
        }
        foreach ($by_key as $k => $f) {
            if (isset($map_years[$k])) continue;
            $item = array('name' => $f['name'], 'url' => $f['has_page'] ? $f['url'] : '');
            if (!empty($f['start_year'])) $add($f['start_year'], 'opened', 'Opened', $item);
            if (!empty($f['end_year'])) $add($f['end_year'], 'closed', 'Closed', $item);
        }

        foreach ((array) ($ctx['lawsuits'] ?? array()) as $l) {
            if ($l['year'] !== '') $add($l['year'], 'lawsuit', 'Sued', array('name' => $l['case_name'], 'url' => $ctx['lawsuits_url'] ?? ''));
        }
        foreach ((array) ($ctx['memorials'] ?? array()) as $m) {
            if (preg_match('/\b(1[89]\d\d|20\d\d)\b/', $m['date_label'], $y)) {
                $who = $m['name'] . ($m['age'] !== '' ? ', ' . $m['age'] : '') . ', at ' . $m['program'];
                $add($y[1], 'death', 'Died', array('name' => $who, 'url' => $m['kop_url']));
            }
        }

        ksort($events);
        $order = array('founded', 'acquired', 'began', 'opened', 'lawsuit', 'death', 'ended', 'closed');
        $out = array();
        foreach ($events as $year => $kinds) {
            $rows = array();
            foreach ($order as $kind) {
                if (!isset($kinds[$kind])) continue;
                $e = $kinds[$kind];
                $e['items'] = array_values($e['items']);
                $n = count($e['items']);
                if ($n > 1 && in_array($kind, array('began', 'opened', 'ended', 'closed', 'acquired'), true)) {
                    $e['text'] .= ' ' . $n . ' programs';
                } elseif ($n > 1 && $kind === 'lawsuit') {
                    $e['text'] = $n . ' lawsuits filed';
                } elseif ($kind === 'lawsuit') {
                    $e['text'] = 'Lawsuit filed';
                } elseif ($kind === 'death') {
                    $e['text'] = $n > 1 ? $n . ' deaths on record' : 'A death on record';
                }
                $rows[] = $e;
            }
            $out[] = array('year' => (int) $year, 'events' => $rows);
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// People
// ---------------------------------------------------------------------------

if (!function_exists('kop_operator_history_people')) {
    /**
     * The people the map ties to the company: {leaders: [...], others: [...]}.
     * Leaders are founders, owners, chief officers, presidents and vice
     * presidents, with their other industry jobs; others are a name and a role.
     */
    function kop_operator_history_people(array $links, array $my_keys) {
        $leaders = array();
        $others = array();
        foreach ($links as $l) {
            if (($l['node']['kind'] ?? '') !== 'person') continue;
            $name = trim((string) $l['node']['name']);
            if ($name === '') continue;
            $role = $l['role'];
            $is_leader = $l['category'] === 'leadership'
                || preg_match('/\b(founder|co-?founder|owner|ceo|cfo|coo|chief|president|chair|director)\b/i', $role);
            $row = array('name' => $name, 'role' => $role, 'career' => array());
            if ($is_leader) {
                $leaders[] = $row;
            } else {
                $others[] = $row;
            }
        }
        $rank = static function ($r) {
            if (preg_match('/founder/i', $r['role'])) return 0;
            if (preg_match('/\bowner\b/i', $r['role'])) return 1;
            if (preg_match('/\b(ceo|president|chief executive)\b/i', $r['role'])) return 2;
            if (preg_match('/\bchief\b|\bc[fo]o\b/i', $r['role'])) return 3;
            if (preg_match('/vice president|\bvp\b/i', $r['role'])) return 4;
            return 5;
        };
        usort($leaders, static function ($a, $b) use ($rank) {
            return $rank($a) - $rank($b) ?: strcasecmp($a['name'], $b['name']);
        });
        usort($others, static function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

        // Their other jobs, without this company under its other names.
        if (function_exists('kop_facility_pages_person_career')) {
            foreach ($leaders as &$p) {
                $jobs = array();
                foreach (kop_facility_pages_person_career($p['name'], '', 0) as $job) {
                    if (isset($my_keys[kop_facility_pages_name_key($job['place'])])) continue;
                    $jobs[] = $job;
                }
                $p['career'] = array_slice($jobs, 0, 8);
            }
            unset($p);
        }
        return array('leaders' => $leaders, 'others' => $others);
    }
}

// ---------------------------------------------------------------------------
// Documents filed under its programs
// ---------------------------------------------------------------------------

if (!function_exists('kop_operator_history_folder_counts')) {
    /** FileBird folder id => documents in it (hidden PDF previews left out). An hour's cache. */
    function kop_operator_history_folder_counts() {
        static $memo = null;
        if ($memo !== null) return $memo;
        $cached = get_transient('kop_operator_folder_counts');
        if (is_array($cached)) return $memo = $cached;
        $memo = array();
        if (function_exists('kop_get_filebird_folders') && function_exists('kop_attach_folder_file_counts')) {
            $folders = kop_get_filebird_folders();
            if ($folders) {
                foreach (kop_attach_folder_file_counts($folders) as $f) {
                    $n = (int) ($f['files'] ?? 0);
                    if ($n > 0) $memo[(int) $f['id']] = $n;
                }
            }
        }
        set_transient('kop_operator_folder_counts', $memo, HOUR_IN_SECONDS);
        return $memo;
    }
}

if (!function_exists('kop_operator_history_program_documents')) {
    /**
     * Its programs that have documents on file: [{name, url, count}], most
     * documents first, and the total. The company's own folder is left out
     * (it is printed in full above).
     */
    function kop_operator_history_program_documents(array $facilities, $own_folder = 0) {
        $ids = function_exists('kop_facility_pages_index') ? (kop_facility_pages_index()['ids'] ?? array()) : array();
        $counts = kop_operator_history_folder_counts();
        $rows = array();
        $total = 0;
        $seen = array();
        foreach ($facilities as $f) {
            $entry = $ids[(int) $f['id']] ?? null;
            $folder = $entry ? (int) ($entry['folder'] ?? 0) : 0;
            if ($folder <= 0 || $folder === (int) $own_folder || isset($seen[$folder])) continue;
            $n = (int) ($counts[$folder] ?? 0);
            if ($n <= 0) continue;
            $seen[$folder] = true;
            $url = kop_facility_page_url((int) $f['id']);
            if ($url === '') continue;
            $rows[] = array('name' => $f['name'], 'url' => $url . '#documents', 'count' => $n);
            $total += $n;
        }
        usort($rows, static function ($a, $b) { return $b['count'] - $a['count'] ?: strcasecmp($a['name'], $b['name']); });
        return array('programs' => $rows, 'total' => $total);
    }
}

// ---------------------------------------------------------------------------
// Written history
// ---------------------------------------------------------------------------

if (!function_exists('kop_operator_history_can_see_drafts')) {
    function kop_operator_history_can_see_drafts() {
        if (function_exists('kop_ie_can')) return (bool) kop_ie_can();
        return function_exists('current_user_can') && current_user_can('manage_options');
    }
}

if (!function_exists('kop_operator_history_written')) {
    /**
     * {status, paragraphs, sources: [{label, url}]} when the record has a
     * history the viewer may read, else null. Drafts are for admins only.
     */
    function kop_operator_history_written(array $op, $can_see_drafts = null) {
        $paras = array();
        foreach ((array) ($op['history'] ?? array()) as $p) {
            $p = trim((string) $p);
            if ($p !== '') $paras[] = $p;
        }
        if (!$paras) return null;
        $status = strtolower(trim((string) ($op['historyStatus'] ?? '')));
        if ($status !== 'published') {
            $status = 'draft';
            if ($can_see_drafts === null) $can_see_drafts = kop_operator_history_can_see_drafts();
            if (!$can_see_drafts) return null;
        }
        $sources = array();
        foreach ((array) ($op['historySources'] ?? array()) as $s) {
            if (is_array($s)) {
                $label = trim((string) ($s['label'] ?? ''));
                $url = trim((string) ($s['url'] ?? ''));
            } else {
                $parts = array_map('trim', explode('|', (string) $s, 2));
                $label = $parts[0];
                $url = $parts[1] ?? '';
                if ($url === '' && preg_match('#^https?://#i', $label)) { $url = $label; $label = ''; }
            }
            if ($label === '' && $url === '') continue;
            $sources[] = array('label' => $label !== '' ? $label : $url, 'url' => preg_match('#^(https?://|/)#i', $url) ? $url : '');
        }
        return array('status' => $status, 'paragraphs' => $paras, 'sources' => $sources);
    }
}

if (!function_exists('kop_operator_history_paragraph_html')) {
    /** A paragraph escaped, with [words](https://...) made into links. */
    function kop_operator_history_paragraph_html($text) {
        $out = '';
        $pos = 0;
        $text = (string) $text;
        if (preg_match_all('/\[([^\]]+)\]\(((?:https?:\/\/|\/)[^)\s]+)\)/', $text, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $out .= esc_html(substr($text, $pos, $hit[0][1] - $pos));
                $url = $hit[2][0];
                $external = preg_match('#^https?://#i', $url) && strpos($url, (string) home_url('/')) !== 0;
                $out .= '<a href="' . esc_url($url) . '"' . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . esc_html($hit[1][0]) . '</a>';
                $pos = $hit[0][1] + strlen($hit[0][0]);
            }
        }
        return $out . esc_html(substr($text, $pos));
    }
}

// ---------------------------------------------------------------------------
// Drafts from seeds/operator-histories.json
// ---------------------------------------------------------------------------

if (!function_exists('kop_operator_history_apply_seed')) {
    /**
     * Fills the history of each company named in the seed whose record has
     * none, as a draft. Returns [name => 'filled'|'kept'|'not found'].
     */
    function kop_operator_history_apply_seed($path = '') {
        global $wpdb;
        if ($path === '') $path = get_stylesheet_directory() . '/seeds/operator-histories.json';
        $seed = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
        $report = array();
        if (!is_array($seed) || empty($seed['companies']) || !function_exists('kop_operator_pages_index')) return $report;
        $index = kop_operator_pages_index(true);
        $table = $wpdb->prefix . 'kop_operators';
        foreach ($seed['companies'] as $c) {
            $name = trim((string) ($c['name'] ?? ''));
            $id = (int) ($index['names'][kop_facility_pages_name_key($name)] ?? 0);
            if ($id <= 0 || empty($c['history'])) { $report[$name] = 'not found'; continue; }
            $json = json_decode((string) $wpdb->get_var($wpdb->prepare("SELECT json_data FROM `{$table}` WHERE id = %d", $id)), true);
            if (!is_array($json) || !is_array($json['operator'] ?? null)) { $report[$name] = 'not found'; continue; }
            $op = $json['operator'];
            $has = array_filter(array_map('trim', array_map('strval', (array) ($op['history'] ?? array()))), 'strlen');
            if ($has || trim((string) ($op['historyStatus'] ?? '')) !== '') { $report[$name] = 'kept'; continue; }
            $op['history'] = array_values(array_map('strval', (array) $c['history']));
            $op['historySources'] = array();
            foreach ((array) ($c['sources'] ?? array()) as $s) {
                $op['historySources'][] = trim((string) ($s['label'] ?? '')) . ' | ' . trim((string) ($s['url'] ?? ''));
            }
            $op['historyStatus'] = 'draft';
            $json['operator'] = $op;
            $wpdb->update($table, array('json_data' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), array('id' => $id));
            $report[$name] = 'filled';
        }
        if (function_exists('kop_operator_pages_flush_index')) kop_operator_pages_flush_index();
        return $report;
    }
}

if (!function_exists('kop_operator_history_maybe_seed')) {
    function kop_operator_history_maybe_seed() {
        if (get_option('kop_operator_history_seed') === KOP_OPERATOR_HISTORY_SEED_VERSION) return;
        if (!file_exists(get_stylesheet_directory() . '/seeds/operator-histories.json')) return;
        update_option('kop_operator_history_seed', KOP_OPERATOR_HISTORY_SEED_VERSION);
        kop_operator_history_apply_seed();
    }
    add_action('admin_init', 'kop_operator_history_maybe_seed');
}

// ---------------------------------------------------------------------------
// The /operator/ index
// ---------------------------------------------------------------------------

if (!function_exists('kop_operator_history_is_major')) {
    /** Five or more programs, a published history, or a large place on the map. */
    function kop_operator_history_is_major(array $row) {
        return $row['programs'] >= 5 || $row['has_history'] || $row['map_degree'] >= 20;
    }
}

if (!function_exists('kop_operator_history_index_rows')) {
    /**
     * Every company page: [{id, name, url, programs, open, states, years,
     * status, has_history, map_degree, major}], majors first by size. Read
     * from the tables in two queries, so the index never builds whole pages.
     */
    function kop_operator_history_index_rows() {
        global $wpdb;
        $index = kop_operator_pages_index();
        if (empty($index['ids'])) return array();
        $ops = $wpdb->prefix . 'kop_operators';
        $ofc = $wpdb->prefix . 'kop_operator_facilities';

        $records = array();
        foreach ((array) $wpdb->get_results("SELECT id, json_data FROM `{$ops}`", ARRAY_A) as $r) {
            $records[(int) $r['id']] = kop_operator_pages_decode($r['json_data']);
        }
        $facilities = array();
        if (kop_facility_pages_table_exists($ofc)) {
            $rows = $wpdb->get_results(
                "SELECT DISTINCT ofc.operator_id, f.id, f.state, f.country, f.status, f.start_year, f.end_year
                   FROM `{$ofc}` ofc JOIN facilities_v2 f ON f.id = ofc.facility_id",
                ARRAY_A
            );
            foreach ((array) $rows as $r) $facilities[(int) $r['operator_id']][(int) $r['id']] = $r;
        }

        $out = array();
        foreach ($index['ids'] as $id => $entry) {
            $programs = array();
            $op = $records[$id] ?? array();
            foreach ($entry['members'] as $m) {
                foreach ($facilities[(int) $m] ?? array() as $fid => $f) $programs[$fid] = $f;
            }
            $open = 0;
            $places = array();
            $first = 0;
            $last = 0;
            foreach ($programs as $f) {
                if (strcasecmp(trim((string) $f['status']), 'Open') === 0) $open++;
                $place = trim((string) ($f['state'] ?: ($f['country'] !== 'United States' ? $f['country'] : '')));
                if ($place !== '') $places[strtoupper($place)] = true;
                if ((int) $f['start_year'] > 0 && (!$first || (int) $f['start_year'] < $first)) $first = (int) $f['start_year'];
                foreach (array($f['start_year'], $f['end_year']) as $y) if ((int) $y > $last) $last = (int) $y;
            }
            $node = kop_operator_history_map_node(array_merge(array($entry['name'], $entry['display']), (array) ($op['otherNames'] ?? array())));
            $written = kop_operator_history_written($op, false);
            $founded = preg_match('/\b(1[89]\d\d|20\d\d)\b/', (string) ($op['founded'] ?? ''), $fm) ? (int) $fm[1] : 0;
            $row = array(
                'id'          => (int) $id,
                'name'        => $entry['display'],
                'url'         => kop_operator_pages_url_for_slug($entry['slug']),
                'programs'    => count($programs),
                'open'        => $open,
                'states'      => count($places),
                'years'       => $founded ? 'Founded ' . $founded : ($first ? 'Programs from ' . $first : ''),
                'status'      => trim((string) ($op['status'] ?? '')),
                'has_history' => $written !== null,
                'lede'        => $written ? wp_trim_words(wp_strip_all_tags(preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $written['paragraphs'][0])), 40) : '',
                'map_degree'  => (int) ($node['degree'] ?? 0),
            );
            $row['major'] = kop_operator_history_is_major($row);
            $out[] = $row;
        }
        usort($out, static function ($a, $b) {
            return ((int) $b['major'] - (int) $a['major']) ?: ($b['programs'] - $a['programs']) ?: strcasecmp($a['name'], $b['name']);
        });
        return $out;
    }
}

if (!function_exists('kop_operator_history_index_url')) {
    function kop_operator_history_index_url() {
        return home_url('/' . kop_operator_pages_base() . '/');
    }
}
