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
 * Suggested links for one state.
 *
 * @param array $records [{id, name, names: [..], city, status}]
 * @param array $rows    [{id, facility_name, full_address, reports}]
 * @param array $stored  kop_inspection_links_get()
 * @param int   $max     records with more candidates than this are skipped
 *                       (a word such as "Abraxas" alone fits every unit)
 * @return array [{record, candidates: [{row, same_town}]}], records sorted by name
 */
function kop_inspection_link_suggestions(array $records, array $rows, array $stored, $max = 12) {
    $row_words = array();
    foreach ($rows as $row) {
        $row_words[(int) $row['id']] = array_flip(kop_inspection_link_words($row['facility_name']));
    }
    $out = array();
    foreach ($records as $rec) {
        $fid = (int) $rec['id'];
        $linked = array_map('intval', (array) ($stored['links'][$fid] ?? array()));
        $rejected = array_map('intval', (array) ($stored['rejected'][$fid] ?? array()));
        $name_keys = array();
        foreach ((array) ($rec['names'] ?? array($rec['name'])) as $n) {
            $k = function_exists('kop_facility_pages_name_key') ? kop_facility_pages_name_key($n) : '';
            if ($k !== '') $name_keys[] = $k;
        }
        $candidates = array();
        foreach ($rows as $row) {
            $rid = (int) $row['id'];
            if (in_array($rid, $linked, true) || in_array($rid, $rejected, true)) continue;
            // Already reached by the name rule: nothing to link.
            if ($name_keys && function_exists('kop_facility_pages_name_key')) {
                $rk = kop_facility_pages_name_key($row['facility_name']);
                $hit = false;
                foreach ($name_keys as $fk) {
                    if ($rk !== '' && ($fk === $rk || kop_facility_pages_key_matches($fk, $rk))) { $hit = true; break; }
                }
                if ($hit) continue;
            }
            foreach ((array) ($rec['names'] ?? array($rec['name'])) as $n) {
                $words = kop_inspection_link_words($n);
                if (!$words) continue;
                $all = true;
                foreach ($words as $w) {
                    if (!isset($row_words[$rid][$w])) { $all = false; break; }
                }
                if ($all) {
                    $town = trim((string) ($rec['city'] ?? ''));
                    $candidates[] = array(
                        'row'       => $row,
                        'same_town' => $town !== '' && stripos((string) $row['full_address'], $town) !== false,
                    );
                    break;
                }
            }
        }
        if ($candidates && count($candidates) <= $max) {
            $out[] = array('record' => $rec, 'candidates' => $candidates);
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
        if ($choice === 'link') {
            $stored['links'][$fid] = array_values(array_unique(array_merge((array) ($stored['links'][$fid] ?? array()), array($rid))));
            $changed++;
        } elseif ($choice === 'reject') {
            $stored['rejected'][$fid] = array_values(array_unique(array_merge((array) ($stored['rejected'][$fid] ?? array()), array($rid))));
            $changed++;
        }
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
        $n = kop_inspection_links_save(wp_unslash($_POST));
        $notice = $n ? sprintf('Saved %d decision%s. The facility pages show the linked reports now.', $n, $n === 1 ? '' : 's') : 'Nothing to save.';
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
        foreach ($suggestions as $s) {
            $rec = $s['record'];
            $url = function_exists('kop_facility_page_url') ? kop_facility_page_url($rec['id']) : '';
            echo '<div class="kop-il-card" style="background:#fff;border:1px solid #c3c4c7;padding:10px 14px;margin:10px 0;max-width:980px">';
            echo '<h3 style="margin:4px 0">' . ($url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($rec['name']) . '</a>' : esc_html($rec['name']))
                . ' <span style="font-weight:normal;color:#50575e">' . esc_html(implode(' / ', array_filter(array($rec['city'], $rec['status'])))) . '</span></h3>';
            echo '<table class="widefat striped"><tbody>';
            foreach ($s['candidates'] as $c) {
                $row = $c['row'];
                $pair = (int) $rec['id'] . '-' . (int) $row['id'];
                $default = $c['same_town'] ? 'link' : 'later';
                echo '<tr><td style="width:46%"><strong>' . esc_html($row['facility_name']) . '</strong><br><span style="color:#50575e">'
                    . esc_html($row['full_address']) . ' &middot; ' . (int) $row['reports'] . ' reports</span></td><td>';
                foreach (array('link' => 'Same facility', 'reject' => 'Not this one', 'later' => 'Decide later') as $value => $label) {
                    echo '<label style="margin-right:14px"><input type="radio" name="decide[' . esc_attr($pair) . ']" value="' . esc_attr($value) . '"'
                        . checked($default, $value, false) . '> ' . esc_html($label) . '</label>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }

    $linked = array();
    foreach ($stored['links'] as $fid => $ids) {
        foreach ((array) $ids as $rid) {
            if (isset($rows_by_id[(int) $rid])) $linked[] = array((int) $fid, $rows_by_id[(int) $rid]);
        }
    }
    if ($linked) {
        $names = array();
        foreach ($records as $r) $names[$r['id']] = $r['name'];
        echo '<h2>Linked in ' . esc_html($state_name) . ' (' . count($linked) . ')</h2><table class="widefat striped" style="max-width:980px"><tbody>';
        foreach ($linked as $l) {
            list($fid, $row) = $l;
            $pair = $fid . '-' . (int) $row['id'];
            echo '<tr><td>' . esc_html($names[$fid] ?? ('#' . $fid)) . '</td><td>' . esc_html($row['facility_name']) . '</td>'
                . '<td><label><input type="checkbox" name="unlink[]" value="' . esc_attr($pair) . '"> Remove</label></td></tr>';
        }
        echo '</tbody></table>';
    }
    if ($suggestions || $linked) submit_button('Save');
    echo '</form>';
    if (isset($tracker[$state_name])) {
        echo '<p><a href="' . esc_url(home_url('/' . $tracker[$state_name] . '/')) . '" target="_blank" rel="noopener">' . esc_html($state_name) . ' inspection reports</a></p>';
    }
    echo '</div>';
}
