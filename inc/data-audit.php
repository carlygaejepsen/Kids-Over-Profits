<?php
/**
 * Data audit: corrections to KOP's facility data found by checking it against
 * outside sources (docs/PLAN.md 3.13).
 *
 * scripts/data-audit.py flags data that looks wrong (a renamed program's map
 * name ending the year its earlier name did, a status its years contradict,
 * an operator the program left before a sale, one person under two
 * spellings); research agents check each flag against news, filings and
 * licensing records; the proposals, each with its quotes and links, are in
 * js/data/data-audit/proposals.json. The owner approves or rejects each in the
 * review inbox (queue "Data audit", inc/review-inbox/data-audit.php).
 *
 * A proposal is a list of typed changes ('ops'):
 *   field          a record value: operatingPeriod.status / startYear / endYear,
 *                  identification.currentOperator ('from' must still hold)
 *   list_add       an entry added to identification.pastOperators
 *   list_remove    an entry taken off identification.pastOperators
 *   note           a dated sentence added to operatingPeriod.notes
 *   map_years      a network map name's years (over graph.json, Map Years and
 *                  Map Renames, like theirs: live at once, no rebuild)
 *   operator_link  a company's link to the program: current, past or removed
 *                  ({prefix}kop_operator_facilities; a past link makes the
 *                  company's page show the program as Transferred)
 *   operator_field one field of a company record (json_data.operator.<path>,
 *                  the fields kop_daudit_operator_paths() lists; 'from' is
 *                  the whole value as researched, 'to' the whole new value)
 *   links_move     articles and lawsuits filed under the wrong record move to
 *                  the right one (news_facility_links, lawsuit_facility_links:
 *                  'from_facility', 'to_facility', 'news_ids', 'lawsuit_ids')
 *   person_merge   two person ids are one person (KOP Tools > Merge People)
 *   memorial       one column of an In Loving Memory entry (memorial_victims:
 *                  date, precision, age, program, cause, place, source)
 *   manual         what an admin does by hand; approving marks it done
 *
 * 'renames' (beside the ops) says a rename explains why the years disagree:
 * the earlier and later name's records and the year; while both are records
 * of their own the card offers "Merge as a rename" (see Renames below).
 *
 * Approve checks every 'from' first and changes nothing when one no longer
 * holds; Undo puts back exactly what Approve changed and leaves anything
 * edited since. Decisions: option kop_data_audit_review, key => {decision,
 * by, at, done, merges}; map years: option kop_data_audit_map_years.
 *
 * @package KidsOverProfits
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Status values a proposal may set (the owner's rule of 2026-10-06: a program under a new company is Open). */
function kop_daudit_statuses() {
    return array('Open', 'Closed', 'Suspended', 'Unknown');
}

/** The proposals, key => proposal. */
function kop_daudit_proposals() {
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = array();
    $file = get_stylesheet_directory() . '/js/data/data-audit/proposals.json';
    $list = is_readable($file) ? json_decode((string) file_get_contents($file), true) : array();
    foreach ((array) $list as $p) {
        if (is_array($p) && !empty($p['key']) && is_array($p['ops'] ?? null)) $memo[(string) $p['key']] = $p;
    }
    return $memo;
}

/** key => {decision: applied|rejected, by, at, done}. */
function kop_daudit_decisions() {
    $saved = get_option('kop_data_audit_review', array());
    return is_array($saved) ? $saved : array();
}

function kop_daudit_save_decisions(array $decisions) {
    update_option('kop_data_audit_review', $decisions, false);
}

/* ---- Map years -------------------------------------------------------- */

/** Node id => years the audit set. */
function kop_daudit_map_years() {
    $saved = get_option('kop_data_audit_map_years', array());
    return is_array($saved) ? $saved : array();
}

/** The map's year overrides with the audit's corrections over them. */
function kop_daudit_year_overrides($overrides) {
    foreach (kop_daudit_map_years() as $id => $years) {
        if ((string) $years !== '') $overrides[(string) $id] = (string) $years;
    }
    return $overrides;
}
// After Map Years and Map Renames (priority 10): a researched correction wins.
add_filter('kop_network_map_year_overrides', 'kop_daudit_year_overrides', 20);

/** A map name's years as the map shows them now, before this audit's own. */
function kop_daudit_node_years($id) {
    $years = '';
    if (function_exists('kop_network_map_graph')) {
        foreach ((array) ((kop_network_map_graph() ?: array())['nodes'] ?? array()) as $n) {
            if ((string) ($n['id'] ?? '') === (string) $id) $years = (string) ($n['years'] ?? '');
        }
    }
    $mine = kop_daudit_map_years();
    if (function_exists('kop_network_map_year_overrides')) {
        $over = kop_network_map_year_overrides();
        if (isset($over[$id]) && !isset($mine[$id])) $years = (string) $over[$id];
    }
    return $years;
}

/** "1986-2007", "from 1989", "until 2004" or "1998": the forms the map reads. */
function kop_daudit_valid_years($text) {
    return (bool) preg_match('/^(?:\d{4}|\d{4}-\d{4}|from \d{4}|until \d{4})$/', (string) $text);
}

/* ---- A record's document ---------------------------------------------- */

function kop_daudit_get(array $doc, $path) {
    foreach (explode('.', $path) as $part) {
        if (!is_array($doc) || !array_key_exists($part, $doc)) return null;
        $doc = $doc[$part];
    }
    return $doc;
}

function kop_daudit_set(array &$doc, $path, $value) {
    $ref = &$doc;
    foreach (explode('.', $path) as $part) {
        if (!isset($ref[$part]) || !is_array($ref)) {
            if (!is_array($ref)) $ref = array();
            if (!array_key_exists($part, $ref)) $ref[$part] = null;
        }
        $ref = &$ref[$part];
    }
    $ref = $value;
}

/** The same value, as the record holds it: years as numbers, empty as null, text trimmed. */
function kop_daudit_norm($v) {
    if ($v === null || $v === '' || $v === array()) return null;
    if (is_numeric($v) && preg_match('/^\d{4}$/', trim((string) $v))) return (int) $v;
    return is_string($v) ? trim($v) : $v;
}

function kop_daudit_list(array $doc, $path) {
    $v = kop_daudit_get($doc, $path);
    if (is_array($v)) return array_values($v);
    $v = trim((string) $v);
    return $v === '' ? array() : array($v);
}

function kop_daudit_list_has(array $list, $value) {
    foreach ($list as $x) {
        if (is_string($x) && strcasecmp(trim($x), trim((string) $value)) === 0) return true;
    }
    return false;
}

/** The fields a proposal may change on a record, with their check. */
function kop_daudit_field_ok($path, $to) {
    switch ($path) {
        case 'operatingPeriod.status':
            return in_array($to, kop_daudit_statuses(), true);
        case 'operatingPeriod.startYear':
        case 'operatingPeriod.endYear':
            return $to === null || (is_int($to) && $to >= 1800 && $to <= (int) gmdate('Y'));
        case 'identification.currentOperator':
            return $to === null || (is_string($to) && trim($to) !== '' && mb_strlen($to) < 160);
    }
    return false;
}

/**
 * Check a proposal's changes to one record against the document. Returns a
 * list of reasons it cannot be made (empty: it can).
 */
function kop_daudit_doc_check(array $doc, array $ops) {
    $why = array();
    foreach ($ops as $op) {
        $path = (string) ($op['path'] ?? '');
        if ($op['type'] === 'field') {
            $to = kop_daudit_norm($op['to'] ?? null);
            if (!kop_daudit_field_ok($path, $to)) {
                $why[] = 'The new value for ' . $path . ' is not one the record can hold.';
            } elseif (kop_daudit_norm(kop_daudit_get($doc, $path)) !== kop_daudit_norm($op['from'] ?? null)
                && kop_daudit_norm(kop_daudit_get($doc, $path)) !== $to) {
                $why[] = kop_daudit_field_label($path) . ' is now "' . kop_daudit_show(kop_daudit_get($doc, $path))
                    . '", not "' . kop_daudit_show($op['from'] ?? null) . '" as when this was researched.';
            }
        } elseif ($op['type'] === 'list_add' || $op['type'] === 'list_remove') {
            if ($path !== 'identification.pastOperators' || trim((string) ($op['value'] ?? '')) === '') $why[] = 'A list change with no value.';
        } elseif ($op['type'] === 'note') {
            if (trim((string) ($op['text'] ?? '')) === '') $why[] = 'A note with no text.';
        }
    }
    return $why;
}

/**
 * Make a proposal's changes to one record. $cite is the source line every
 * change leaves in the notes. Returns what was done, for Undo.
 */
function kop_daudit_doc_apply(array &$doc, array $ops, $cite) {
    $done = array();
    $changed = false;
    foreach ($ops as $op) {
        $path = (string) ($op['path'] ?? '');
        if ($op['type'] === 'field') {
            $before = kop_daudit_get($doc, $path);
            $to = kop_daudit_norm($op['to'] ?? null);
            if (kop_daudit_norm($before) === $to) continue;
            kop_daudit_set($doc, $path, $to);
            $done[] = array('type' => 'field', 'path' => $path, 'before' => $before, 'value' => $to);
            $changed = true;
        } elseif ($op['type'] === 'list_add') {
            $list = kop_daudit_list($doc, $path);
            if (kop_daudit_list_has($list, $op['value'])) continue;
            $list[] = trim((string) $op['value']);
            kop_daudit_set($doc, $path, $list);
            $done[] = array('type' => 'list_add', 'path' => $path, 'value' => trim((string) $op['value']));
            $changed = true;
        } elseif ($op['type'] === 'list_remove') {
            $list = kop_daudit_list($doc, $path);
            foreach ($list as $i => $x) {
                if (is_string($x) && strcasecmp(trim($x), trim((string) $op['value'])) === 0) {
                    array_splice($list, $i, 1);
                    kop_daudit_set($doc, $path, $list);
                    $done[] = array('type' => 'list_remove', 'path' => $path, 'value' => $x, 'at' => $i);
                    $changed = true;
                    break;
                }
            }
        } elseif ($op['type'] === 'note') {
            $line = trim((string) $op['text']) . ($cite !== '' ? ' (' . $cite . ')' : '');
            $notes = kop_daudit_list($doc, 'operatingPeriod.notes');
            if (in_array($line, $notes, true)) continue;
            $notes[] = $line;
            kop_daudit_set($doc, 'operatingPeriod.notes', $notes);
            $done[] = array('type' => 'note', 'line' => $line);
        }
    }
    // A change with no note of its own still says where it came from.
    if ($changed && !array_filter($done, function ($d) { return $d['type'] === 'note'; }) && $cite !== '') {
        $line = 'Corrected from outside sources (data audit): ' . $cite;
        $notes = kop_daudit_list($doc, 'operatingPeriod.notes');
        if (!in_array($line, $notes, true)) {
            $notes[] = $line;
            kop_daudit_set($doc, 'operatingPeriod.notes', $notes);
            $done[] = array('type' => 'note', 'line' => $line);
        }
    }
    return $done;
}

/** Take back what kop_daudit_doc_apply() did, leaving values edited since. Returns the paths left alone. */
function kop_daudit_doc_undo(array &$doc, array $done) {
    $kept = array();
    foreach (array_reverse($done) as $d) {
        if ($d['type'] === 'field') {
            if (kop_daudit_norm(kop_daudit_get($doc, $d['path'])) === kop_daudit_norm($d['value'])) {
                kop_daudit_set($doc, $d['path'], $d['before']);
            } else {
                $kept[] = $d['path'];
            }
        } elseif ($d['type'] === 'list_add') {
            $list = array_values(array_filter(kop_daudit_list($doc, $d['path']), function ($x) use ($d) {
                return !(is_string($x) && strcasecmp(trim($x), $d['value']) === 0);
            }));
            kop_daudit_set($doc, $d['path'], $list);
        } elseif ($d['type'] === 'list_remove') {
            $list = kop_daudit_list($doc, $d['path']);
            if (!kop_daudit_list_has($list, $d['value'])) {
                array_splice($list, min((int) $d['at'], count($list)), 0, array($d['value']));
                kop_daudit_set($doc, $d['path'], $list);
            }
        } elseif ($d['type'] === 'note') {
            $notes = array_values(array_filter(kop_daudit_list($doc, 'operatingPeriod.notes'), function ($x) use ($d) { return $x !== $d['line']; }));
            kop_daudit_set($doc, 'operatingPeriod.notes', $notes);
        }
    }
    return $kept;
}

/** The source line a change leaves in the record's notes: the first source's title and link. */
function kop_daudit_cite(array $p) {
    foreach ((array) ($p['sources'] ?? array()) as $s) {
        if (empty($s['url'])) continue;
        $title = trim((string) ($s['title'] ?? ''));
        $date = trim((string) ($s['date'] ?? ''));
        return trim(($title !== '' ? $title : 'Source') . ($date !== '' ? ', ' . $date : '') . ': ' . $s['url']);
    }
    return '';
}

/* ---- Memorial entries -------------------------------------------------- */

/** The columns of memorial_victims a proposal may correct, with their check. */
function kop_daudit_memorial_ok($column, $to) {
    switch ($column) {
        case 'date_of_death':
            return is_string($to) && preg_match('/^(19|20)\d\d-\d\d-\d\d$/', $to) && $to <= gmdate('Y-m-d');
        case 'date_precision':
            return in_array($to, array('day', 'month', 'year', 'unknown'), true);
        case 'age':
            return $to === null || (is_int($to) && $to >= 0 && $to <= 30);
        case 'cause_category':
            return in_array($to, array('restraint', 'suicide', 'medical_neglect', 'accident', 'drowning', 'escape_attempt',
                'violence', 'overdose', 'exposure', 'other', 'unknown'), true);
        case 'program':
        case 'cause_of_death':
        case 'location':
        case 'source_name':
            return is_string($to) && trim($to) !== '' && mb_strlen($to) < 255;
        case 'source_url':
            return is_string($to) && preg_match('#^https?://#', $to);
    }
    return false;
}

function kop_daudit_memorial_value(PDO $pdo, $id, $column) {
    if (!in_array($column, array('date_of_death', 'date_precision', 'age', 'program', 'cause_of_death', 'cause_category',
        'location', 'source_name', 'source_url'), true)) {
        throw new RuntimeException('Not a memorial column a correction may change.');
    }
    $st = $pdo->prepare("SELECT `{$column}` FROM memorial_victims WHERE id = ?");
    $st->execute(array((int) $id));
    $v = $st->fetchColumn();
    if ($v === false) return array(false, null);
    return array(true, $column === 'age' && $v !== null ? (int) $v : $v);
}

function kop_daudit_memorial_put(PDO $pdo, $id, $column, $value) {
    $pdo->prepare("UPDATE memorial_victims SET `{$column}` = ?, updated_at = ? WHERE id = ?")
        ->execute(array($value, gmdate('Y-m-d H:i:s'), (int) $id));
}

/* ---- Company records --------------------------------------------------- */

/** The company fields a proposal may set (the pencil's fields, kop_ie_operator_fields(), less the history and the name). */
function kop_daudit_operator_paths() {
    return array(
        'headquarters' => 'Headquarters', 'headquartersCity' => 'Headquarters city', 'headquartersState' => 'Headquarters state',
        'founded' => 'Founded', 'operatingPeriod' => 'Operating', 'status' => 'Status', 'currentName' => 'Now known as',
        'keyStaff.ceo' => 'Chief executive', 'keyStaff.founders' => 'Founders', 'keyStaff.keyExecutives' => 'Executives',
        'owners' => 'Owners', 'investors' => 'Investors', 'parentCompanies' => 'Parent companies',
        'otherNames' => 'Other names', 'websites' => 'Websites', 'notes' => 'Notes',
    );
}

/** A company value as text or a list of strings or of {name, role, pastJobs} rows. */
function kop_daudit_operator_value_ok($path, $to) {
    if (!isset(kop_daudit_operator_paths()[$path])) return false;
    if ($to === null || is_string($to)) return $to === null || mb_strlen($to) < 400;
    if (!is_array($to) || array_values($to) !== $to) return false;
    foreach ($to as $x) {
        if (is_string($x)) continue;
        if (!is_array($x) || !is_string($x['name'] ?? null) || trim($x['name']) === '') return false;
    }
    return true;
}

/** Two company values are the same (empty = empty, keys in any order). */
function kop_daudit_operator_same($a, $b) {
    $canon = function ($v) use (&$canon) {
        if ($v === null || $v === '' || $v === array()) return null;
        if (is_string($v)) return trim($v);
        if (is_array($v)) {
            if (array_values($v) !== $v) ksort($v);
            return array_map($canon, $v);
        }
        return $v;
    };
    return wp_json_encode($canon($a)) === wp_json_encode($canon($b));
}

/** The company's stored JSON, or null when it is gone. */
function kop_daudit_operator_json(array $opts, $id) {
    $st = $opts['pdo']->prepare("SELECT json_data FROM `{$opts['prefix']}kop_operators` WHERE id = ?");
    $st->execute(array((int) $id));
    $raw = $st->fetchColumn();
    if ($raw === false) return null;
    $json = json_decode((string) $raw, true);
    return is_array($json) && is_array($json['operator'] ?? null) ? $json : null;
}

function kop_daudit_operator_save(array $opts, $id, array $json) {
    $opts['pdo']->prepare("UPDATE `{$opts['prefix']}kop_operators` SET json_data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute(array(json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $id));
}

/** The company pages read the index and their own cached page: both start over. */
function kop_daudit_operator_flush($id) {
    if (function_exists('kop_operator_pages_flush_index')) kop_operator_pages_flush_index();
    if (function_exists('kop_ie_purge_url') && function_exists('kop_operator_page_url')) kop_ie_purge_url((string) kop_operator_page_url((int) $id));
}

/* ---- Articles and lawsuits filed under the wrong record ---------------- */

/** table => id column, for the link tables links_move may change. */
function kop_daudit_link_tables() {
    return array('news' => array('news_facility_links', 'news_id'), 'lawsuit' => array('lawsuit_facility_links', 'lawsuit_id'));
}

/** Each of the op's ids as [kind, id]. */
function kop_daudit_move_ids(array $op) {
    $out = array();
    foreach ((array) ($op['news_ids'] ?? array()) as $id) $out[] = array('news', (int) $id);
    foreach ((array) ($op['lawsuit_ids'] ?? array()) as $id) $out[] = array('lawsuit', (int) $id);
    return $out;
}

/** The whole link row of item $id to facility $fid, or null. */
function kop_daudit_item_link(PDO $pdo, $kind, $id, $fid) {
    list($t, $col) = kop_daudit_link_tables()[$kind];
    $st = $pdo->prepare("SELECT * FROM `{$t}` WHERE `{$col}` = ? AND facility_id = ?");
    $st->execute(array((int) $id, (int) $fid));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function kop_daudit_item_link_put(PDO $pdo, $kind, array $row) {
    list($t) = kop_daudit_link_tables()[$kind];
    $cols = array_keys($row);
    $pdo->prepare("INSERT INTO `{$t}` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($row));
}

function kop_daudit_item_link_drop(PDO $pdo, $kind, $id, $fid) {
    list($t, $col) = kop_daudit_link_tables()[$kind];
    $pdo->prepare("DELETE FROM `{$t}` WHERE `{$col}` = ? AND facility_id = ?")->execute(array((int) $id, (int) $fid));
}

/** The facility pages count links, so a move also marks both records changed (kop_facility_pages_fingerprint()). */
function kop_daudit_touch_records(PDO $pdo, array $ids) {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if ($ids) $pdo->prepare('UPDATE facilities_v2 SET updated_at = CURRENT_TIMESTAMP WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')->execute($ids);
}

/* ---- Words for the cards ---------------------------------------------- */

function kop_daudit_field_label($path) {
    $labels = array(
        'operatingPeriod.status' => 'Status', 'operatingPeriod.startYear' => 'Opening year',
        'operatingPeriod.endYear' => 'Closing year', 'identification.currentOperator' => 'Current operator',
        'identification.pastOperators' => 'Past operators',
    );
    return $labels[$path] ?? $path;
}

function kop_daudit_show($v) {
    if ($v === null || $v === '' || $v === array()) return 'none';
    if (is_array($v)) {
        // A company's people are {name, role, pastJobs} rows: "Name (role)".
        return implode('; ', array_map(function ($x) {
            if (!is_array($x)) return (string) $x;
            $role = trim((string) ($x['role'] ?? ''));
            return trim((string) ($x['name'] ?? '')) . ($role !== '' ? ' (' . $role . ')' : '');
        }, $v));
    }
    return (string) $v;
}

/** One change in plain words, for the card. */
function kop_daudit_op_words(array $op, array $names = array()) {
    switch ($op['type']) {
        case 'field':
            return kop_daudit_field_label($op['path']) . ': ' . kop_daudit_show($op['from'] ?? null) . ' -> ' . kop_daudit_show($op['to'] ?? null);
        case 'list_add':
            return 'Add past operator: ' . $op['value'];
        case 'list_remove':
            return 'Take off past operators: ' . $op['value'];
        case 'note':
            return 'Add to the notes: ' . $op['text'];
        case 'map_years':
            return 'Network map, ' . ($names['node:' . $op['node']] ?? $op['node']) . ': ' . kop_daudit_show($op['from'] ?? null) . ' -> ' . $op['to'];
        case 'operator_link':
            $co = $names['op:' . $op['operator_id']] ?? ('company #' . $op['operator_id']);
            if (($op['to'] ?? '') === 'remove') return 'Unlink ' . $co . ' from this program (it never ran it)';
            if (($op['to'] ?? '') === 'past') return $co . ' becomes a past company of this program (its page shows it as Transferred)';
            return $co . ' becomes the program\'s current company';
        case 'operator_field':
            $co = $names['op:' . $op['operator_id']] ?? ('company #' . $op['operator_id']);
            return $co . ', ' . (kop_daudit_operator_paths()[$op['path']] ?? $op['path']) . ': '
                . kop_daudit_show($op['from'] ?? null) . ' -> ' . kop_daudit_show($op['to'] ?? null);
        case 'links_move':
            $n = count((array) ($op['news_ids'] ?? array()));
            $l = count((array) ($op['lawsuit_ids'] ?? array()));
            return 'Move ' . implode(' and ', array_filter(array($n ? $n . ' article' . ($n === 1 ? '' : 's') : '', $l ? $l . ' lawsuit' . ($l === 1 ? '' : 's') : '')))
                . ' from record #' . (int) $op['from_facility'] . ' to record #' . (int) $op['to_facility']
                . (!empty($op['label']) ? ' (' . $op['label'] . ')' : '');
        case 'person_merge':
            return 'Merge ' . ($names['person:' . $op['drop']] ?? '#' . $op['drop']) . ' (#' . $op['drop'] . ') into '
                . ($names['person:' . $op['keep']] ?? '#' . $op['keep']) . ' (#' . $op['keep'] . ')';
        case 'memorial':
            $labels = array('date_of_death' => 'Date of death', 'date_precision' => 'How exact the date is', 'age' => 'Age',
                'program' => 'Program', 'cause_of_death' => 'Cause of death', 'cause_category' => 'Cause (category)',
                'location' => 'Place', 'source_name' => 'Source', 'source_url' => 'Source link');
            return 'In Loving Memory, ' . ($labels[$op['column']] ?? $op['column']) . ': ' . kop_daudit_show($op['from'] ?? null) . ' -> ' . kop_daudit_show($op['to'] ?? null);
        case 'manual':
            return 'By hand: ' . $op['text'];
    }
    return '';
}

/* ---- Approve and Undo -------------------------------------------------- */

function kop_daudit_opts() {
    if (!function_exists('kop_wbf_opts')) throw new RuntimeException('The facility store is not loaded.');
    return kop_wbf_opts();
}

function kop_daudit_link_table(array $opts) {
    return $opts['prefix'] . 'kop_operator_facilities';
}

function kop_daudit_link_row(PDO $pdo, $table, $fid, $oid) {
    $st = $pdo->prepare("SELECT relationship, sort_order FROM `{$table}` WHERE facility_id = ? AND operator_id = ?");
    $st->execute(array((int) $fid, (int) $oid));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function kop_daudit_link_put(PDO $pdo, $table, $fid, $oid, $row) {
    $pdo->prepare("DELETE FROM `{$table}` WHERE facility_id = ? AND operator_id = ?")->execute(array((int) $fid, (int) $oid));
    if ($row) {
        $pdo->prepare("INSERT INTO `{$table}` (operator_id, facility_id, relationship, sort_order) VALUES (?, ?, ?, ?)")
            ->execute(array((int) $oid, (int) $fid, (string) $row['relationship'], (int) ($row['sort_order'] ?? 0)));
    }
}

/**
 * Everything wrong with making proposal $p now: [] when it can be made.
 * Reads the records and the map; changes nothing.
 */
function kop_daudit_check(array $p, array $opts) {
    $why = array();
    $by_fid = array();
    foreach ($p['ops'] as $op) {
        if (in_array($op['type'], array('field', 'list_add', 'list_remove', 'note'), true)) $by_fid[(int) $op['facility_id']][] = $op;
    }
    foreach ($by_fid as $fid => $ops) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) {
            $why[] = 'Record #' . $fid . ' is gone (merged or deleted).';
            continue;
        }
        $why = array_merge($why, kop_daudit_doc_check($stored['doc'], $ops));
    }
    $table = kop_daudit_link_table($opts);
    foreach ($p['ops'] as $op) {
        if ($op['type'] === 'operator_link') {
            $row = kop_daudit_link_row($opts['pdo'], $table, $op['facility_id'], $op['operator_id']);
            $now = $row ? $row['relationship'] : null;
            if ($now !== ($op['from'] ?? null) && !($now === null && $op['to'] === 'remove') && $now !== $op['to']) {
                $why[] = 'The company link is now "' . ($now ?? 'none') . '", not "' . ($op['from'] ?? 'none') . '".';
            }
        } elseif ($op['type'] === 'operator_field') {
            $path = (string) ($op['path'] ?? '');
            if (!kop_daudit_operator_value_ok($path, $op['to'] ?? null)) {
                $why[] = 'The new company ' . $path . ' is not a value the record can hold.';
                continue;
            }
            $json = kop_daudit_operator_json($opts, $op['operator_id']);
            if (!$json) {
                $why[] = 'Company #' . (int) $op['operator_id'] . ' is gone.';
                continue;
            }
            $now = kop_daudit_get($json['operator'], $path);
            if (!kop_daudit_operator_same($now, $op['from'] ?? null) && !kop_daudit_operator_same($now, $op['to'] ?? null)) {
                $why[] = 'The company\'s ' . (kop_daudit_operator_paths()[$path] ?? $path) . ' is now "' . kop_daudit_show($now)
                    . '", not "' . kop_daudit_show($op['from'] ?? null) . '".';
            }
        } elseif ($op['type'] === 'links_move') {
            foreach (array('from_facility', 'to_facility') as $k) {
                if (!kop_facility_load((int) ($op[$k] ?? 0), $opts)) $why[] = 'Record #' . (int) ($op[$k] ?? 0) . ' is gone (merged or deleted).';
            }
            $lost = array();
            foreach (kop_daudit_move_ids($op) as $x) {
                if (!kop_daudit_item_link($opts['pdo'], $x[0], $x[1], $op['from_facility'])
                    && !kop_daudit_item_link($opts['pdo'], $x[0], $x[1], $op['to_facility'])) $lost[] = $x[0] . ' #' . $x[1];
            }
            if ($lost) $why[] = 'These are not filed under record #' . (int) $op['from_facility'] . ' any more: ' . implode(', ', $lost) . '.';
        } elseif ($op['type'] === 'map_years') {
            if (!kop_daudit_valid_years($op['to'] ?? '')) $why[] = 'The map years "' . ($op['to'] ?? '') . '" are not in a form the map reads.';
        } elseif ($op['type'] === 'memorial') {
            if (!kop_daudit_memorial_ok((string) $op['column'], $op['to'] ?? null)) {
                $why[] = 'The new ' . $op['column'] . ' is not a value the memorial can hold.';
                continue;
            }
            list($found, $now) = kop_daudit_memorial_value($opts['pdo'], $op['memorial_id'], (string) $op['column']);
            if (!$found) $why[] = 'Memorial entry #' . (int) $op['memorial_id'] . ' is gone.';
            elseif ((string) $now !== (string) ($op['from'] ?? '') && (string) $now !== (string) $op['to']) {
                $why[] = 'In the memorial entry, ' . $op['column'] . ' is now "' . kop_daudit_show($now) . '", not "' . kop_daudit_show($op['from'] ?? null) . '".';
            }
        } elseif ($op['type'] === 'person_merge') {
            $state = function_exists('kop_people_load') ? kop_people_load() : array('rows' => array());
            foreach (array('keep', 'drop') as $k) {
                $row = $state['rows'][(int) $op[$k]] ?? null;
                if (!$row) $why[] = 'Person #' . (int) $op[$k] . ' is not on file.';
                elseif (!empty($row['merged_into'])) $why[] = 'Person #' . (int) $op[$k] . ' was already merged.';
            }
        }
    }
    return array_values(array_unique($why));
}

/**
 * kop_daudit_check() split in two: ['changed' => the record moved since the
 * research (a value is not the 'from' any more: a conflict the reviewer can
 * settle), 'blocked' => what no choice can get past (a record gone, a value
 * the record cannot hold)].
 */
function kop_daudit_check_split(array $p, array $opts) {
    $out = array('changed' => array(), 'blocked' => array());
    foreach (kop_daudit_check($p, $opts) as $why) {
        $out[preg_match('/ is now "/', $why) ? 'changed' : 'blocked'][] = $why;
    }
    return $out;
}

/**
 * Make proposal $key's changes. Returns the message; throws when any 'from' no
 * longer holds, unless $over_changes (the Conflicts section's "Use this value":
 * the record moved since the research and the reviewer chose the proposal's value).
 */
function kop_daudit_apply($key, $reviewer, $over_changes = false) {
    $p = kop_daudit_proposals()[$key] ?? null;
    if (!$p) throw new RuntimeException('That proposal is not in the list any more.');
    $decisions = kop_daudit_decisions();
    if (($decisions[$key]['decision'] ?? '') === 'applied') throw new RuntimeException('Already approved.');
    $opts = kop_daudit_opts();
    $split = kop_daudit_check_split($p, $opts);
    $why = $over_changes ? $split['blocked'] : array_merge($split['changed'], $split['blocked']);
    if ($why) throw new RuntimeException('Nothing was changed: ' . implode(' ', $why));
    $cite = kop_daudit_cite($p);
    $done = array();
    $by_fid = array();
    foreach ($p['ops'] as $op) {
        if (in_array($op['type'], array('field', 'list_add', 'list_remove', 'note'), true)) $by_fid[(int) $op['facility_id']][] = $op;
    }
    kop_v2_with_write_lock($opts['pdo'], function () use ($by_fid, $opts, $cite, &$done) {
        foreach ($by_fid as $fid => $ops) {
            $stored = kop_facility_load($fid, $opts);
            $doc = $stored['doc'];
            $status_before = kop_daudit_get($doc, 'operatingPeriod.status');
            $changes = kop_daudit_doc_apply($doc, $ops, $cite);
            if (!$changes) continue;
            kop_wbf_save($doc, $opts);
            $done[] = array('type' => 'record', 'facility_id' => $fid, 'changes' => $changes);
            if (kop_daudit_get($doc, 'operatingPeriod.status') !== $status_before) do_action('kop_facility_status_changed', $fid);
        }
    });
    $table = kop_daudit_link_table($opts);
    $map = kop_daudit_map_years();
    foreach ($p['ops'] as $op) {
        if ($op['type'] === 'operator_link') {
            $before = kop_daudit_link_row($opts['pdo'], $table, $op['facility_id'], $op['operator_id']);
            $after = $op['to'] === 'remove' ? null : array('relationship' => $op['to'], 'sort_order' => (int) ($before['sort_order'] ?? 0));
            kop_daudit_link_put($opts['pdo'], $table, $op['facility_id'], $op['operator_id'], $after);
            $done[] = array('type' => 'operator_link', 'facility_id' => (int) $op['facility_id'], 'operator_id' => (int) $op['operator_id'], 'before' => $before, 'after' => $after);
        } elseif ($op['type'] === 'links_move') {
            $moved = array();
            foreach (kop_daudit_move_ids($op) as $x) {
                $row = kop_daudit_item_link($opts['pdo'], $x[0], $x[1], $op['from_facility']);
                if (!$row) continue;
                // Already under the right record too: the wrong link only goes.
                $had = (bool) kop_daudit_item_link($opts['pdo'], $x[0], $x[1], $op['to_facility']);
                kop_daudit_item_link_drop($opts['pdo'], $x[0], $x[1], $op['from_facility']);
                if (!$had) kop_daudit_item_link_put($opts['pdo'], $x[0], array('facility_id' => (int) $op['to_facility']) + $row);
                $moved[] = array('kind' => $x[0], 'row' => $row, 'added' => !$had);
            }
            kop_daudit_touch_records($opts['pdo'], array($op['from_facility'], $op['to_facility']));
            $done[] = array('type' => 'links_move', 'from' => (int) $op['from_facility'], 'to' => (int) $op['to_facility'], 'moved' => $moved);
        } elseif ($op['type'] === 'operator_field') {
            $oid = (int) $op['operator_id'];
            kop_v2_with_write_lock($opts['pdo'], function () use ($op, $oid, $opts, &$done) {
                $json = kop_daudit_operator_json($opts, $oid);
                $before = kop_daudit_get($json['operator'], (string) $op['path']);
                if (kop_daudit_operator_same($before, $op['to'] ?? null)) return;
                kop_daudit_set($json['operator'], (string) $op['path'], $op['to'] ?? null);
                kop_daudit_operator_save($opts, $oid, $json);
                $done[] = array('type' => 'operator_field', 'operator_id' => $oid, 'path' => (string) $op['path'], 'before' => $before, 'value' => $op['to'] ?? null);
            });
            kop_daudit_operator_flush($oid);
        } elseif ($op['type'] === 'map_years') {
            $done[] = array('type' => 'map_years', 'node' => (string) $op['node'], 'before' => $map[$op['node']] ?? null, 'value' => (string) $op['to']);
            $map[(string) $op['node']] = (string) $op['to'];
        } elseif ($op['type'] === 'memorial') {
            list(, $before) = kop_daudit_memorial_value($opts['pdo'], $op['memorial_id'], (string) $op['column']);
            kop_daudit_memorial_put($opts['pdo'], $op['memorial_id'], (string) $op['column'], $op['to']);
            $done[] = array('type' => 'memorial', 'memorial_id' => (int) $op['memorial_id'], 'column' => (string) $op['column'], 'before' => $before, 'value' => $op['to']);
        } elseif ($op['type'] === 'person_merge') {
            kop_pmerge_do_merge((int) $op['keep'], (int) $op['drop'], $reviewer);
            $log = kop_pmerge_log();
            $done[] = array('type' => 'person_merge', 'log' => (string) ($log[0]['id'] ?? ''));
        }
    }
    update_option('kop_data_audit_map_years', $map, false);
    $decisions[$key] = kop_daudit_keep_merges($decisions[$key] ?? array(),
        array('decision' => 'applied', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'), 'done' => $done));
    kop_daudit_save_decisions($decisions);
    return 'Approved: ' . (string) ($p['title'] ?? $key) . ' is corrected on the site now. Undo is on the Approved tab.';
}

/** Put back what Approve changed. Returns the message. */
function kop_daudit_undo($key) {
    $decisions = kop_daudit_decisions();
    $d = $decisions[$key] ?? null;
    if (!$d) throw new RuntimeException('Nothing to undo.');
    $kept = array();
    if (($d['decision'] ?? '') === 'applied') {
        $opts = kop_daudit_opts();
        $table = kop_daudit_link_table($opts);
        $map = kop_daudit_map_years();
        foreach (array_reverse((array) ($d['done'] ?? array())) as $x) {
            if ($x['type'] === 'record') {
                kop_v2_with_write_lock($opts['pdo'], function () use ($x, $opts, &$kept) {
                    $stored = kop_facility_load((int) $x['facility_id'], $opts);
                    if (!$stored) {
                        $kept[] = 'record #' . (int) $x['facility_id'] . ' (gone)';
                        return;
                    }
                    $doc = $stored['doc'];
                    $status_before = kop_daudit_get($doc, 'operatingPeriod.status');
                    foreach (kop_daudit_doc_undo($doc, $x['changes']) as $path) $kept[] = kop_daudit_field_label($path) . ' (edited since)';
                    kop_wbf_save($doc, $opts);
                    if (kop_daudit_get($doc, 'operatingPeriod.status') !== $status_before) do_action('kop_facility_status_changed', (int) $x['facility_id']);
                });
            } elseif ($x['type'] === 'operator_link') {
                $now = kop_daudit_link_row($opts['pdo'], $table, $x['facility_id'], $x['operator_id']);
                if (($now['relationship'] ?? null) === ($x['after']['relationship'] ?? null)) {
                    kop_daudit_link_put($opts['pdo'], $table, $x['facility_id'], $x['operator_id'], $x['before']);
                } else {
                    $kept[] = 'a company link (edited since)';
                }
            } elseif ($x['type'] === 'links_move') {
                $tables = kop_daudit_link_tables();
                foreach ($x['moved'] as $m) {
                    $col = $tables[$m['kind']][1];
                    $id = (int) $m['row'][$col];
                    if ($m['added']) kop_daudit_item_link_drop($opts['pdo'], $m['kind'], $id, $x['to']);
                    if (!kop_daudit_item_link($opts['pdo'], $m['kind'], $id, $x['from'])) kop_daudit_item_link_put($opts['pdo'], $m['kind'], $m['row']);
                }
                kop_daudit_touch_records($opts['pdo'], array($x['from'], $x['to']));
            } elseif ($x['type'] === 'operator_field') {
                kop_v2_with_write_lock($opts['pdo'], function () use ($x, $opts, &$kept) {
                    $json = kop_daudit_operator_json($opts, $x['operator_id']);
                    if (!$json || !kop_daudit_operator_same(kop_daudit_get($json['operator'], $x['path']), $x['value'])) {
                        $kept[] = 'the company\'s ' . (kop_daudit_operator_paths()[$x['path']] ?? $x['path']) . ' (edited since)';
                        return;
                    }
                    kop_daudit_set($json['operator'], $x['path'], $x['before']);
                    kop_daudit_operator_save($opts, $x['operator_id'], $json);
                });
                kop_daudit_operator_flush($x['operator_id']);
            } elseif ($x['type'] === 'map_years') {
                if (($map[$x['node']] ?? null) === $x['value']) {
                    if ($x['before'] === null) unset($map[$x['node']]);
                    else $map[$x['node']] = $x['before'];
                }
            } elseif ($x['type'] === 'memorial') {
                list(, $now) = kop_daudit_memorial_value($opts['pdo'], $x['memorial_id'], $x['column']);
                if ((string) $now === (string) $x['value']) kop_daudit_memorial_put($opts['pdo'], $x['memorial_id'], $x['column'], $x['before']);
                else $kept[] = 'the memorial entry (' . $x['column'] . ', edited since)';
            } elseif ($x['type'] === 'person_merge' && $x['log'] !== '') {
                try {
                    kop_pmerge_do_undo($x['log']);
                } catch (RuntimeException $e) {
                    $kept[] = 'the person merge (' . $e->getMessage() . ')';
                }
            }
        }
        update_option('kop_data_audit_map_years', $map, false);
    }
    unset($decisions[$key]);
    if (!empty($d['merges'])) $decisions[$key] = array('merges' => $d['merges']);
    kop_daudit_save_decisions($decisions);
    return 'Undone' . ($kept ? '; left as edited since: ' . implode(', ', $kept) : '') . '. It is waiting for review again.';
}

function kop_daudit_reject($key, $reviewer) {
    if (!isset(kop_daudit_proposals()[$key])) throw new RuntimeException('That proposal is not in the list any more.');
    $decisions = kop_daudit_decisions();
    if (($decisions[$key]['decision'] ?? '') === 'applied') throw new RuntimeException('Approved already: undo it first.');
    $decisions[$key] = kop_daudit_keep_merges($decisions[$key] ?? array(),
        array('decision' => 'rejected', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'), 'done' => array()));
    kop_daudit_save_decisions($decisions);
    return 'Rejected. KOP keeps what it had.';
}

/* ---- Renames ------------------------------------------------------------ */

/*
 * A rename is often why a record's years disagree with the sources: the later
 * name's record carries the opening year of the earlier name, or the earlier
 * name's record never ends. A proposal names it in 'renames':
 *   [{earlier: <record id>, later: <record id>, year: <rename year>, why: ""}]
 * Proposals without one get the rename pairs Merge Duplicates finds for their
 * record (pastNames, the map's rename lines). The card says which name had
 * which years, and while both names are records of their own it offers "Merge
 * as a rename" right there (kop_fmerge_do_merge() with the year; exact Undo
 * through the merge log). Merges made from a card: decisions[key]['merges']
 * ['earlier>later'] = {log, year, by, at}, kept through Approve, Reject and
 * their Undo.
 */

/** The proposal's rename pairs, 'earlier>later' => {earlier, later, year, why, found}: researched ones, then found ones. */
function kop_daudit_renames(array $p) {
    $out = array();
    foreach ((array) ($p['renames'] ?? array()) as $r) {
        $e = (int) ($r['earlier'] ?? 0);
        $l = (int) ($r['later'] ?? 0);
        if ($e <= 0 || $l <= 0 || $e === $l) continue;
        $out[$e . '>' . $l] = array('earlier' => $e, 'later' => $l, 'year' => (int) ($r['year'] ?? 0),
            'why' => (string) ($r['why'] ?? ''), 'found' => false);
    }
    $fid = (int) ($p['facility_id'] ?? 0);
    if ($fid > 0) {
        foreach (kop_daudit_found_renames() as $r) {
            $e = (int) ($r['earlier']['id'] ?? 0);
            $l = (int) ($r['later']['id'] ?? 0);
            if ($e !== $fid && $l !== $fid) continue;
            if (isset($out[$e . '>' . $l]) || isset($out[$l . '>' . $e])) continue;
            $out[$e . '>' . $l] = array('earlier' => $e, 'later' => $l, 'year' => (int) ($r['year'] ?? 0),
                'why' => (string) ($r['why'] ?? ''), 'found' => true);
        }
    }
    return $out;
}

/** Merge Duplicates' renamed pairs (kop_fmerge_screen_data(), cached an hour there). */
function kop_daudit_found_renames() {
    static $memo = null;
    if ($memo !== null) return $memo;
    $memo = array();
    if (!function_exists('kop_fmerge_screen_data')) return $memo;
    try {
        $memo = (array) (kop_fmerge_screen_data()['renames'] ?? array());
    } catch (Throwable $e) {
        $memo = array();
    }
    return $memo;
}

/** One record's name and years, or null when it is not on file. */
function kop_daudit_rename_side(PDO $pdo, $id) {
    $st = $pdo->prepare('SELECT json_data FROM facilities_v2 WHERE id = ?');
    $st->execute(array((int) $id));
    $js = $st->fetchColumn();
    if ($js === false) return null;
    $doc = json_decode((string) $js, true) ?: array();
    $op = (array) ($doc['operatingPeriod'] ?? array());
    $yr = function ($v) { return (is_numeric($v) && (int) $v > 0) ? (int) $v : 0; };
    return array('id' => (int) $id, 'name' => (string) ($doc['identification']['name'] ?? ('Record #' . $id)),
        'start' => $yr($op['startYear'] ?? null), 'end' => $yr($op['endYear'] ?? null));
}

/**
 * Where a rename pair stands: {state: separate | merged (from this card, with
 * its log id) | merged_elsewhere | gone, earlier, later (sides, null when
 * gone), year, after (each name's years once merged, while separate)}.
 */
function kop_daudit_rename_state($key, array $r, PDO $pdo) {
    $resolve = function ($id) { return function_exists('kop_facility_merge_resolve') ? kop_facility_merge_resolve($id) : (int) $id; };
    // Merged the other way round from this card: the pair reads in that order.
    $mine = kop_daudit_decisions()[$key]['merges'][$r['earlier'] . '>' . $r['later']] ?? null;
    if ($mine && !empty($mine['reversed'])) list($r['earlier'], $r['later']) = array($r['later'], $r['earlier']);
    $e = kop_daudit_rename_side($pdo, $r['earlier']);
    // A chain (Three Springs -> Sequel TSI -> Brighter Path) merges in any order: a later name merged on is followed.
    $l = kop_daudit_rename_side($pdo, $resolve($r['later']));
    $out = array('earlier' => $e, 'later' => $l, 'earlier_id' => (int) $r['earlier'], 'later_id' => (int) $r['later'],
        'year' => (int) $r['year'], 'log' => '', 'state' => 'separate');
    if ($e && $l) {
        // No year researched or found: the earlier name's end, else the later one's start when it is after the earlier's.
        if (!$out['year']) $out['year'] = $e['end'] ?: (($l['start'] && $l['start'] > $e['start']) ? $l['start'] : 0);
        $out['after'] = kop_daudit_rename_years($e, $l, $out['year']);
        return $out;
    }
    if ($mine) {
        $out['state'] = 'merged';
        $out['log'] = (string) $mine['log'];
        $out['year'] = (int) $mine['year'];
    } else {
        $out['state'] = ($l && $resolve($r['earlier']) === $l['id']) ? 'merged_elsewhere' : 'gone';
    }
    return $out;
}

/** Each name's years after a rename merge in $year, as kop_fmerge_execute() is given them. */
function kop_daudit_rename_years(array $e, array $l, $year) {
    $year = (int) $year;
    return array(
        'earlier' => array('start' => ($e['start'] && (!$year || $e['start'] <= $year)) ? $e['start'] : null, 'end' => $year ?: null),
        'later'   => array('start' => ($l['start'] && $l['start'] >= $year) ? $l['start'] : ($year ?: null), 'end' => $l['end'] ?: null),
    );
}

function kop_daudit_years_text(array $y) {
    $s = (int) ($y['start'] ?? 0);
    $e = (int) ($y['end'] ?? 0);
    if ($s && $e) return $s === $e ? (string) $s : $s . ' to ' . $e;
    if ($s) return 'from ' . $s;
    if ($e) return 'until ' . $e;
    return 'no years';
}

/** The card's line about one rename (a state from kop_daudit_rename_state()). */
function kop_daudit_rename_words(array $s) {
    $e = $s['earlier'];
    $l = $s['later'];
    $en = $e ? $e['name'] . ' (#' . $e['id'] . ')' : 'record #' . $s['earlier_id'];
    $ln = $l ? $l['name'] . ' (#' . $l['id'] . ')' : 'record #' . $s['later_id'];
    $when = $s['year'] ? 'in ' . $s['year'] : '(year not known)';
    switch ($s['state']) {
        case 'merged':
            return $en . ' became ' . $ln . ' ' . $when . '. Merged as a rename from this card: each name keeps its own years.';
        case 'merged_elsewhere':
            return $en . ' became ' . $ln . ' ' . $when . '. Already merged into one program.';
        case 'gone':
            return 'Renamed ' . $when . ', but one of the two records is no longer on file (merged or deleted): ' . $en . ', ' . $ln . '.';
    }
    $line = $en . ' became ' . $ln . ' ' . $when . '. That is why the years on record disagree: '
        . $e['name'] . ' has ' . kop_daudit_years_text($e) . ', ' . $l['name'] . ' has ' . kop_daudit_years_text($l) . '.';
    if ($s['year']) {
        $line .= ' Merged as a rename, ' . $e['name'] . ' keeps ' . kop_daudit_years_text($s['after']['earlier']) . ' and '
            . $l['name'] . ' runs ' . kop_daudit_years_text($s['after']['later']) . '.';
    }
    return $line;
}

/** A replaced decision keeps the card's rename merges. */
function kop_daudit_keep_merges(array $old, array $new) {
    if (!empty($old['merges'])) $new['merges'] = $old['merges'];
    return $new;
}

/**
 * Merge the pair $pair ('earlier>later') of proposal $key as a rename in
 * $year (0 = the researched or found year); $reversed = the pair the other
 * way round (the 'later' record became the 'earlier' one's name). Returns
 * the message.
 */
function kop_daudit_rename_merge($key, $pair, $year, $reviewer, $reversed = false) {
    $p = kop_daudit_proposals()[$key] ?? null;
    if (!$p) throw new RuntimeException('That proposal is not in the list any more.');
    $r = kop_daudit_renames($p)[(string) $pair] ?? null;
    if (!$r) throw new RuntimeException('That rename is not on this card.');
    if ($reversed) list($r['earlier'], $r['later']) = array($r['later'], $r['earlier']);
    if (!function_exists('kop_fmerge_do_merge')) throw new RuntimeException('Merge Duplicates is not loaded.');
    if (function_exists('get_stylesheet_directory')) require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    $opts = kop_daudit_opts();
    $pdo = $opts['pdo'];
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $s = kop_daudit_rename_state($key, $r, $pdo);
    if ($s['state'] !== 'separate') throw new RuntimeException(kop_daudit_rename_words($s));
    $year = (int) $year ?: (int) $s['year'];
    if (!$year) throw new RuntimeException('Give the year of the rename.');
    $y = kop_daudit_rename_years($s['earlier'], $s['later'], $year);
    try {
        $msg = kop_fmerge_do_merge($pdo, $opts['prefix'], $s['later']['id'], $s['earlier']['id'], (string) $reviewer,
            array('year' => $year, 'earlier' => $y['earlier'], 'later' => $y['later'], 'reversed' => (bool) $reversed));
    } finally {
        if (function_exists('kop_fmerge_flush')) kop_fmerge_flush();
    }
    $log = kop_fmerge_log();
    $decisions = kop_daudit_decisions();
    $decisions[$key]['merges'][(string) $pair] = array('log' => (string) ($log[0]['id'] ?? ''), 'year' => $year,
        'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'));
    if ($reversed) $decisions[$key]['merges'][(string) $pair]['reversed'] = true;
    if (!$p['ops'] && empty($decisions[$key]['decision'])) {
        // A card holding only the rename: the merge approves it.
        $decisions[$key] += array('decision' => 'applied', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'), 'done' => array(), 'by_merge' => true);
    }
    kop_daudit_save_decisions($decisions);
    return $msg . ' Undo is on this card and on Merge Duplicates\' Merged tab.';
}

/** Undo a rename merge made from proposal $key's card. Returns the message. */
function kop_daudit_rename_unmerge($key, $pair) {
    $decisions = kop_daudit_decisions();
    $m = $decisions[$key]['merges'][(string) $pair] ?? null;
    if (!$m || empty($m['log'])) throw new RuntimeException('This card made no merge to undo.');
    if (function_exists('get_stylesheet_directory')) require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    $opts = kop_daudit_opts();
    $opts['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    try {
        $msg = kop_fmerge_do_undo($opts['pdo'], $opts['prefix'], (string) $m['log']);
    } finally {
        if (function_exists('kop_fmerge_flush')) kop_fmerge_flush();
    }
    unset($decisions[$key]['merges'][(string) $pair]);
    if (empty($decisions[$key]['merges'])) {
        unset($decisions[$key]['merges']);
        if (!empty($decisions[$key]['by_merge'])) unset($decisions[$key]);
    }
    if (empty($decisions[$key])) unset($decisions[$key]);
    kop_daudit_save_decisions($decisions);
    return $msg;
}
