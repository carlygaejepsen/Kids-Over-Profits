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
 *   person_merge   two person ids are one person (KOP Tools > Merge People)
 *   memorial       one column of an In Loving Memory entry (memorial_victims:
 *                  date, precision, age, program, cause, place, source)
 *   manual         what an admin does by hand; approving marks it done
 *
 * Approve checks every 'from' first and changes nothing when one no longer
 * holds; Undo puts back exactly what Approve changed and leaves anything
 * edited since. Decisions: option kop_data_audit_review, key => {decision,
 * by, at, done}; map years: option kop_data_audit_map_years.
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
    if (is_array($v)) return implode('; ', array_map('strval', $v));
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

/** Make proposal $key's changes. Returns the message; throws when any 'from' no longer holds. */
function kop_daudit_apply($key, $reviewer) {
    $p = kop_daudit_proposals()[$key] ?? null;
    if (!$p) throw new RuntimeException('That proposal is not in the list any more.');
    $decisions = kop_daudit_decisions();
    if (($decisions[$key]['decision'] ?? '') === 'applied') throw new RuntimeException('Already approved.');
    $opts = kop_daudit_opts();
    $why = kop_daudit_check($p, $opts);
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
    $decisions[$key] = array('decision' => 'applied', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'), 'done' => $done);
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
    kop_daudit_save_decisions($decisions);
    return 'Undone' . ($kept ? '; left as edited since: ' . implode(', ', $kept) : '') . '. It is waiting for review again.';
}

function kop_daudit_reject($key, $reviewer) {
    if (!isset(kop_daudit_proposals()[$key])) throw new RuntimeException('That proposal is not in the list any more.');
    $decisions = kop_daudit_decisions();
    if (($decisions[$key]['decision'] ?? '') === 'applied') throw new RuntimeException('Approved already: undo it first.');
    $decisions[$key] = array('decision' => 'rejected', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'), 'done' => array());
    kop_daudit_save_decisions($decisions);
    return 'Rejected. KOP keeps what it had.';
}
