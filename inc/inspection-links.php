<?php
/**
 * Links between facility records and state inspection rows whose names do
 * not match.
 *
 * Facility pages pick up a state's inspection rows by name key
 * (kop_facility_pages_inspections() in inc/facility-pages.php). Some states
 * license each building on its own: Pennsylvania lists "Adelphoi Village:
 * Benet" where the record is "Adelphoi Benet Home", so the name rule finds
 * almost nothing there. Links an admin approves at KOP Tools > Inspection
 * Links are added to the name matches.
 *
 * Suggestions: a record and an inspection row in the same state where every
 * distinguishing word of one of the record's names is in the row's name.
 * They are only suggestions (that rule also pairs "Circle C Youth Center"
 * with "Woods Services - Sandalwood Circle"); a pair in the same town is
 * ticked in advance, the rest wait for a tick.
 *
 * Stored in the option kop_inspection_links:
 *   {links: {facility id: [inspection_facilities id, ...]},
 *    rejected: {facility id: [inspection_facilities id, ...]}, updated: time}
 *
 * A program with homes under it (inc/program-homes.php) is suggested as one
 * card and can be linked as a unit; any entry can also be linked by hand.
 *
 * Tested by scripts/test-inspection-links.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_INSPECTION_LINKS_OPTION = 'kop_inspection_links';

// Words that say what a place is, not which one it is.
const KOP_INSPECTION_LINK_GENERIC = array(
    'THE', 'INC', 'LLC', 'LP', 'CORP', 'CO', 'OF', 'AND', 'FOR', 'IN', 'AT', 'A', 'AN', 'ON', 'TO',
    'PA', 'PENNSYLVANIA', 'HOME', 'HOMES', 'HOUSE', 'CENTER', 'CENTRE', 'SERVICES', 'SERVICE',
    'PROGRAM', 'PROGRAMS', 'RESIDENTIAL', 'TREATMENT', 'SCHOOL', 'SCHOOLS', 'FACILITY', 'BLDG',
    'BUILDING', 'COTTAGE', 'YOUTH', 'CHILDREN', 'CHILDRENS', 'FAMILY', 'FAMILIES', 'GROUP', 'UNIT',
);

function kop_inspection_links_get() {
    $value = get_option(KOP_INSPECTION_LINKS_OPTION, array());
    if (!is_array($value)) $value = array();
    return array(
        'links'    => is_array($value['links'] ?? null) ? $value['links'] : array(),
        'rejected' => is_array($value['rejected'] ?? null) ? $value['rejected'] : array(),
        'updated'  => (int) ($value['updated'] ?? 0),
    );
}

/** inspection_facilities ids linked to a facility record. */
function kop_inspection_links_for($facility_id) {
    $all = kop_inspection_links_get();
    return array_values(array_unique(array_map('intval', (array) ($all['links'][(int) $facility_id] ?? array()))));
}

/** Changes whenever a link is saved; part of kop_facility_pages_fingerprint(). */
function kop_inspection_links_cache_key() {
    $all = kop_inspection_links_get();
    return (string) $all['updated'] . ':' . count($all['links']);
}

/** Distinguishing words of a name, upper case. */
function kop_inspection_link_words($name) {
    $name = strtoupper(html_entity_decode((string) $name, ENT_QUOTES, 'UTF-8'));
    $name = preg_replace("/['\x{2019}]S\b/u", '', $name);
    preg_match_all('/[A-Z0-9]+/', $name, $m);
    // "Middle Creek I" is not "Middle Creek IV", and "Abraxas I" is "Abraxas 1".
    $roman = array('I' => '1', 'II' => '2', 'III' => '3', 'IV' => '4', 'V' => '5', 'VI' => '6', 'VII' => '7', 'VIII' => '8');
    $out = array();
    foreach ($m[0] as $w) {
        if (isset($roman[$w])) $w = $roman[$w];
        if ((strlen($w) > 1 || ctype_digit($w)) && !in_array($w, KOP_INSPECTION_LINK_GENERIC, true)) $out[$w] = true;
    }
    return array_keys($out);
}

/**
 * Program records and their homes in this list of records (Program Homes,
 * inc/program-homes.php): program id => [home ids]. Only programs whose own
 * record is in the list; a home whose program is elsewhere stays on its own.
 */
function kop_inspection_link_groups(array $records) {
    if (!function_exists('kop_program_homes_map')) return array();
    $map = kop_program_homes_map();
    $here = array();
    foreach ($records as $rec) $here[(int) $rec['id']] = true;
    $out = array();
    foreach ((array) ($map['programs'] ?? array()) as $pid => $homes) {
        if (!isset($here[(int) $pid])) continue;
        $homes = array_values(array_filter(array_map('intval', (array) $homes), function ($h) use ($here) { return isset($here[$h]); }));
        if ($homes) $out[(int) $pid] = $homes;
    }
    return $out;
}

/**
 * Suggested links for one state.
 *
 * A program with homes under it is one card: the rows suggested for the
 * program or for any of its homes, less the rows any of them already reaches
 * (by name or by a link: the program page shows its homes' reports). Linking
 * a row to the program puts it on the program's page as a unit; a row first
 * suggested for one home names that home ('home'), which can take it instead.
 *
 * @param array      $records [{id, name, names: [..], city, status}]
 * @param array      $rows    [{id, facility_name, full_address, reports}]
 * @param array      $stored  kop_inspection_links_get()
 * @param int        $max     records with more candidates than this are skipped
 *                            (a word such as "Abraxas" alone fits every unit);
 *                            a program may have $max per member
 * @param array|null $groups  program id => [home ids]; null = kop_inspection_link_groups()
 * @return array [{record, candidates: [{row, same_town, home}]}], records sorted by
 *               name; a program's record carries 'homes' => [{id, name}]
 */
function kop_inspection_link_suggestions(array $records, array $rows, array $stored, $max = 12, $groups = null) {
    $row_words = array();
    $row_keys = array();
    foreach ($rows as $row) {
        $row_words[(int) $row['id']] = array_flip(kop_inspection_link_words($row['facility_name']));
        $row_keys[(int) $row['id']] = function_exists('kop_facility_pages_name_key') ? kop_facility_pages_name_key($row['facility_name']) : '';
    }
    $by_id = array();
    foreach ($records as $rec) $by_id[(int) $rec['id']] = $rec;
    if ($groups === null) $groups = kop_inspection_link_groups($records);
    $home_of = array();
    foreach ($groups as $pid => $homes) foreach ($homes as $h) $home_of[(int) $h] = (int) $pid;

    $ids_of = function ($which, $fid) use ($stored) {
        return array_map('intval', (array) ($stored[$which][(int) $fid] ?? array()));
    };
    // Rows a record already shows: its links and the rows its names match.
    $reached = function ($rec) use ($rows, $row_keys, $ids_of) {
        $out = array_flip($ids_of('links', $rec['id']));
        $name_keys = array();
        foreach ((array) ($rec['names'] ?? array($rec['name'])) as $n) {
            $k = function_exists('kop_facility_pages_name_key') ? kop_facility_pages_name_key($n) : '';
            if ($k !== '') $name_keys[] = $k;
        }
        if (!$name_keys) return $out;
        foreach ($rows as $row) {
            $rk = $row_keys[(int) $row['id']];
            if ($rk === '') continue;
            foreach ($name_keys as $fk) {
                if ($fk === $rk || kop_facility_pages_key_matches($fk, $rk)) { $out[(int) $row['id']] = true; break; }
            }
        }
        return $out;
    };
    // Rows every distinguishing word of one of the record's names is in.
    $fits = function ($rec) use ($rows, $row_words) {
        $out = array();
        foreach ($rows as $row) {
            $rid = (int) $row['id'];
            foreach ((array) ($rec['names'] ?? array($rec['name'])) as $n) {
                $words = kop_inspection_link_words($n);
                if (!$words) continue;
                $all = true;
                foreach ($words as $w) {
                    if (!isset($row_words[$rid][$w])) { $all = false; break; }
                }
                if ($all) { $out[] = $row; break; }
            }
        }
        return $out;
    };
    $in_town = function ($rec, $row) {
        $town = trim((string) ($rec['city'] ?? ''));
        return $town !== '' && stripos((string) $row['full_address'], $town) !== false;
    };

    $out = array();
    foreach ($records as $rec) {
        $fid = (int) $rec['id'];
        if (isset($home_of[$fid])) continue;  // on its program's card
        $members = array($rec);
        foreach ($groups[$fid] ?? array() as $h) $members[] = $by_id[$h];
        $skip = array_flip($ids_of('rejected', $fid));
        foreach ($members as $m) $skip += $reached($m);
        $candidates = array();
        foreach ($members as $m) {
            $mid = (int) $m['id'];
            $m_rejected = array_flip($ids_of('rejected', $mid));
            foreach ($fits($m) as $row) {
                $rid = (int) $row['id'];
                if (isset($skip[$rid]) || isset($m_rejected[$rid])) continue;
                if (isset($candidates[$rid])) continue;
                $candidates[$rid] = array('row' => $row, 'same_town' => false,
                    'home' => $mid === $fid ? null : array('id' => $mid, 'name' => (string) $m['name']));
            }
        }
        foreach ($candidates as &$c) {
            foreach ($members as $m) {
                if ($in_town($m, $c['row'])) { $c['same_town'] = true; break; }
            }
        }
        unset($c);
        if ($candidates && count($candidates) <= $max * count($members)) {
            if (count($members) > 1) {
                $rec['homes'] = array();
                foreach (array_slice($members, 1) as $m) $rec['homes'][] = array('id' => (int) $m['id'], 'name' => (string) $m['name']);
            }
            $out[] = array('record' => $rec, 'candidates' => array_values($candidates));
        }
    }
    usort($out, function ($a, $b) { return strcasecmp($a['record']['name'], $b['record']['name']); });
    return $out;
}

/** facilities_v2 records of a state, with their past and other names. */
function kop_inspection_link_records($state) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT id, name, city, status, json_data FROM facilities_v2 WHERE state = %s ORDER BY name', $state
    ), ARRAY_A);
    $out = array();
    foreach ((array) $rows as $r) {
        $doc = json_decode((string) $r['json_data'], true);
        $ident = is_array($doc['identification'] ?? null) ? $doc['identification'] : array();
        $names = array((string) $r['name']);
        foreach (array('otherNames', 'pastNames') as $k) {
            foreach ((array) ($ident[$k] ?? array()) as $n) {
                if (is_array($n)) $n = $n['name'] ?? '';
                if (is_string($n) && trim($n) !== '') $names[] = trim($n);
            }
        }
        $out[] = array('id' => (int) $r['id'], 'name' => (string) $r['name'], 'names' => array_values(array_unique($names)),
                       'city' => (string) $r['city'], 'status' => (string) $r['status']);
    }
    return $out;
}

/** inspection_facilities rows of a state with their report counts. */
function kop_inspection_link_rows($state) {
    global $wpdb;
    return (array) $wpdb->get_results($wpdb->prepare(
        'SELECT f.id, f.facility_name, f.full_address, f.program_name, COUNT(r.id) AS reports
           FROM inspection_facilities f LEFT JOIN inspection_reports r ON r.facility_id = f.id
          WHERE f.state = %s GROUP BY f.id ORDER BY f.facility_name', $state
    ), ARRAY_A);
}

/* ---- Facility pages ------------------------------------------------------ */

// A record with approved links has inspections even when no name matches.
add_filter('kop_facility_page_signals', function ($signals, $doc, $id) {
    if (!in_array('inspections', $signals, true) && kop_inspection_links_for($id)) {
        $signals[] = 'inspections';
    }
    return $signals;
}, 10, 3);

/* ---- Review screen ------------------------------------------------------- */

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'Inspection Links', 'Inspection Links', 'manage_options',
        'kop-inspection-links', 'kop_render_inspection_links_page');
}, 21);

function kop_inspection_links_save($post) {
    $stored = kop_inspection_links_get();
    $changed = 0;
    foreach ((array) ($post['decide'] ?? array()) as $pair => $choice) {
        if (!preg_match('/^(\d+)-(\d+)$/', (string) $pair, $m)) continue;
        $fid = (int) $m[1];
        $rid = (int) $m[2];
        // "home:<id>": a program card's row linked to the home it was suggested for.
        if (preg_match('/^home:(\d+)$/', (string) $choice, $h)) {
            $fid = (int) $h[1];
            $choice = 'link';
        }
        if ($choice === 'link') {
            $stored['links'][$fid] = array_values(array_unique(array_merge((array) ($stored['links'][$fid] ?? array()), array($rid))));
            $changed++;
        } elseif ($choice === 'reject') {
            $stored['rejected'][$fid] = array_values(array_unique(array_merge((array) ($stored['rejected'][$fid] ?? array()), array($rid))));
            $changed++;
        }
    }
    // Linked by hand: any record (a program links as a unit) and any licensing row,
    // the row given as its id or as the picker's "Name, address #id".
    $manual_fid = (int) ($post['manual_record'] ?? 0);
    $manual_rid = preg_match('/(?:^|#)(\d+)\s*$/', trim((string) ($post['manual_row'] ?? '')), $m) ? (int) $m[1] : 0;
    if ($manual_fid > 0 && $manual_rid > 0) {
        $stored['links'][$manual_fid] = array_values(array_unique(array_merge(array_map('intval', (array) ($stored['links'][$manual_fid] ?? array())), array($manual_rid))));
        $stored['rejected'][$manual_fid] = array_values(array_diff(array_map('intval', (array) ($stored['rejected'][$manual_fid] ?? array())), array($manual_rid)));
        if (!$stored['rejected'][$manual_fid]) unset($stored['rejected'][$manual_fid]);
        $changed++;
    }
    foreach ((array) ($post['unlink'] ?? array()) as $pair) {
        if (!preg_match('/^(\d+)-(\d+)$/', (string) $pair, $m)) continue;
        $fid = (int) $m[1];
        $stored['links'][$fid] = array_values(array_diff(array_map('intval', (array) ($stored['links'][$fid] ?? array())), array((int) $m[2])));
        if (!$stored['links'][$fid]) unset($stored['links'][$fid]);
        $changed++;
    }
    // "Not this one" taken back (the review inbox's Undo): the pair can be suggested again.
    foreach ((array) ($post['unreject'] ?? array()) as $pair) {
        if (!preg_match('/^(\d+)-(\d+)$/', (string) $pair, $m)) continue;
        $fid = (int) $m[1];
        $stored['rejected'][$fid] = array_values(array_diff(array_map('intval', (array) ($stored['rejected'][$fid] ?? array())), array((int) $m[2])));
        if (!$stored['rejected'][$fid]) unset($stored['rejected'][$fid]);
        $changed++;
    }
    if ($changed) {
        $stored['updated'] = time();
        update_option(KOP_INSPECTION_LINKS_OPTION, $stored, false);
        if (function_exists('kop_facility_pages_flush_index')) kop_facility_pages_flush_index();
    }
    return $changed;
}

function kop_render_inspection_links_page() {
    if (!current_user_can('manage_options')) wp_die('Not allowed.');
    global $wpdb;
    $states = (array) $wpdb->get_col('SELECT DISTINCT state FROM inspection_facilities ORDER BY state');
    $state = strtoupper(sanitize_text_field($_REQUEST['state'] ?? 'PA'));
    if (!in_array($state, $states, true)) $state = $states ? $states[0] : 'PA';

    $notice = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        check_admin_referer('kop_inspection_links');
        $post = wp_unslash($_POST);
        $bad = '';
        if (trim((string) ($post['manual_record'] ?? '')) !== '' || trim((string) ($post['manual_row'] ?? '')) !== '') {
            $mf = (int) ($post['manual_record'] ?? 0);
            $mr = preg_match('/(?:^|#)(\d+)\s*$/', trim((string) ($post['manual_row'] ?? '')), $mm) ? (int) $mm[1] : 0;
            if (!$mf || !$wpdb->get_var($wpdb->prepare('SELECT id FROM facilities_v2 WHERE id = %d', $mf))) $bad = 'Pick the facility record or program to link.';
            elseif (!$mr || !$wpdb->get_var($wpdb->prepare('SELECT id FROM inspection_facilities WHERE id = %d', $mr))) $bad = 'Pick the licensing entry from the list.';
            if ($bad !== '') unset($post['manual_record'], $post['manual_row']);
        }
        $n = kop_inspection_links_save($post);
        $notice = $n ? sprintf('Saved %d decision%s. The facility pages show the linked reports now.', $n, $n === 1 ? '' : 's') : 'Nothing to save.';
        if ($bad !== '') $notice = $bad . ($n ? ' ' . $notice : '');
    }

    $stored = kop_inspection_links_get();
    $records = kop_inspection_link_records($state);
    $rows = kop_inspection_link_rows($state);
    $rows_by_id = array();
    foreach ($rows as $r) $rows_by_id[(int) $r['id']] = $r;
    $suggestions = kop_inspection_link_suggestions($records, $rows, $stored);
    $tracker = function_exists('kop_state_inspection_page_map') ? kop_state_inspection_page_map() : array();
    $state_name = function_exists('kop_state_canonical_name') ? kop_state_canonical_name($state) : $state;

    echo '<div class="wrap kop-inspection-links"><h1>Inspection Links</h1>';
    echo '<p>Facility pages show a state&#8217;s inspection reports when the names match. Where the state licenses each building on its own, the names rarely match: link them here. Ticked pairs are in the same town. Press <strong>Save</strong> and the facility pages show the reports at once.</p>';
    if ($notice) echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';

    echo '<form method="get"><input type="hidden" name="page" value="kop-inspection-links"><label>State: <select name="state" onchange="this.form.submit()">';
    foreach ($states as $s) echo '<option value="' . esc_attr($s) . '"' . selected($s, $state, false) . '>' . esc_html($s) . '</option>';
    echo '</select></label></form>';

    echo '<form method="post">';
    wp_nonce_field('kop_inspection_links');
    echo '<input type="hidden" name="state" value="' . esc_attr($state) . '">';

    if (!$suggestions) {
        echo '<p>No suggestions left for ' . esc_html($state_name) . '.</p>';
    } else {
        echo '<h2>' . count($suggestions) . ' record' . (count($suggestions) === 1 ? '' : 's') . ' with suggested links</h2>';
        echo '<p>A program with homes under it is one card: linking an entry to the program puts its reports on the program&#8217;s page as a unit, beside its homes&#8217; reports. An entry first suggested for one home can go to that home instead.</p>';
        foreach ($suggestions as $s) {
            $rec = $s['record'];
            $homes = (array) ($rec['homes'] ?? array());
            $url = function_exists('kop_facility_page_url') ? kop_facility_page_url($rec['id']) : '';
            echo '<div class="kop-il-card" style="background:#fff;border:1px solid #c3c4c7;padding:10px 14px;margin:10px 0;max-width:980px">';
            echo '<h3 style="margin:4px 0">' . ($url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($rec['name']) . '</a>' : esc_html($rec['name']))
                . ' <span style="font-weight:normal;color:#50575e">' . esc_html(implode(' / ', array_filter(array($homes ? 'program, ' . count($homes) . ' home' . (count($homes) === 1 ? '' : 's') : '', $rec['city'], $rec['status'])))) . '</span></h3>';
            if ($homes) {
                echo '<p style="margin:2px 0 8px;color:#50575e">Homes: ' . esc_html(implode(', ', array_map(function ($h) { return $h['name']; }, $homes))) . '</p>';
            }
            echo '<table class="widefat striped"><tbody>';
            foreach ($s['candidates'] as $c) {
                $row = $c['row'];
                $pair = (int) $rec['id'] . '-' . (int) $row['id'];
                $default = $c['same_town'] ? 'link' : 'later';
                $choices = array('link' => $homes ? 'Same program' : 'Same facility');
                if (!empty($c['home'])) $choices['home:' . (int) $c['home']['id']] = 'Only ' . $c['home']['name'];
                $choices += array('reject' => 'Not this one', 'later' => 'Decide later');
                echo '<tr><td style="width:46%"><strong>' . esc_html($row['facility_name']) . '</strong><br><span style="color:#50575e">'
                    . esc_html($row['full_address']) . ' &middot; ' . (int) $row['reports'] . ' reports</span></td><td>';
                foreach ($choices as $value => $label) {
                    echo '<label style="margin-right:14px;display:inline-block"><input type="radio" name="decide[' . esc_attr($pair) . ']" value="' . esc_attr($value) . '"'
                        . checked($default, $value, false) . '> ' . esc_html($label) . '</label>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }

    // Any entry to any record, programs included.
    echo '<h2>Link an entry by hand</h2><p>For an entry no card suggests. Pick the facility record, or the program to link the entry to the program as a unit, and the licensing entry.</p>';
    echo '<datalist id="kop-il-rows">';
    foreach ($rows as $r) {
        echo '<option value="' . esc_attr(trim($r['facility_name'] . ($r['full_address'] !== '' ? ', ' . $r['full_address'] : '')) . ' #' . (int) $r['id']) . '"></option>';
    }
    echo '</datalist>';
    echo '<p><label>Record or program: ' . (function_exists('kop_facility_finder_field') ? kop_facility_finder_field('manual_record') : '<input type="number" min="1" name="manual_record" style="width:90px">') . '</label></p>';
    echo '<p><label>Licensing entry in ' . esc_html($state_name) . ': <input type="text" name="manual_row" list="kop-il-rows" style="width:520px;max-width:100%" placeholder="Type part of the name"></label></p>';

    $linked = array();
    foreach ($stored['links'] as $fid => $ids) {
        foreach ((array) $ids as $rid) {
            if (isset($rows_by_id[(int) $rid])) $linked[] = array((int) $fid, $rows_by_id[(int) $rid]);
        }
    }
    if ($linked) {
        $names = array();
        foreach ($records as $r) $names[$r['id']] = $r['name'];
        $missing = array_diff(array_unique(array_map(function ($l) { return $l[0]; }, $linked)), array_keys($names));
        if ($missing) {
            foreach ((array) $wpdb->get_results('SELECT id, name FROM facilities_v2 WHERE id IN (' . implode(',', array_map('intval', $missing)) . ')', ARRAY_A) as $r) $names[(int) $r['id']] = $r['name'];
        }
        echo '<h2>Linked in ' . esc_html($state_name) . ' (' . count($linked) . ')</h2><table class="widefat striped" style="max-width:980px"><tbody>';
        foreach ($linked as $l) {
            list($fid, $row) = $l;
            $pair = $fid . '-' . (int) $row['id'];
            $n_homes = function_exists('kop_program_homes_homes_of') ? count(kop_program_homes_homes_of($fid)) : 0;
            echo '<tr><td>' . esc_html($names[$fid] ?? ('#' . $fid)) . ($n_homes ? ' <span style="color:#50575e">(program, ' . $n_homes . ' home' . ($n_homes === 1 ? '' : 's') . ')</span>' : '') . '</td><td>' . esc_html($row['facility_name']) . '</td>'
                . '<td><label><input type="checkbox" name="unlink[]" value="' . esc_attr($pair) . '"> Remove</label></td></tr>';
        }
        echo '</tbody></table>';
    }
    submit_button('Save');
    echo '</form>';
    if (isset($tracker[$state_name])) {
        echo '<p><a href="' . esc_url(home_url('/' . $tracker[$state_name] . '/')) . '" target="_blank" rel="noopener">' . esc_html($state_name) . ' inspection reports</a></p>';
    }
    echo '</div>';
}
