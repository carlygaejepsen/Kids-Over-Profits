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
 * A proposal is a list of typed changes ('ops'), each one step on the card
 * that the reviewer ticks or unticks before Approve (only ticked steps are
 * made):
 *   field          a record value: operatingPeriod.status / startYear / endYear /
 *                  yearsOfOperation, identification.currentOperator / currentName,
 *                  location.street / city / state / zip ('from' must still hold)
 *   list_add       an entry added to identification.pastOperators / pastNames /
 *                  otherNames / otherOperators
 *   list_remove    an entry taken off one of those lists
 *   note           a dated sentence added to operatingPeriod.notes
 *   note_remove    one line taken off operatingPeriod.notes or notes ('text')
 *   staff_role     one staff entry's role ('list' administrator|notableStaff,
 *                  'name', 'from', 'to')
 *   merge          record 'drop' folds into 'keep': 'rename' true = the earlier
 *                  name of one program (each name keeps its years; 'year'), false =
 *                  one name twice (or one record is only the company's name)
 *   operator_create a company row ('name', 'fields') and its 'links' [{facility_id,
 *                  relationship}]; a company of that name already on file is linked
 *   map_edge_drop  a network map line taken off ('edge' id; live at once, like
 *                  map years: kop_network_map_edge_drops())
 *   check          something for the owner to look into or decide: shown, never made
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
 *                  name, date, precision, age, program, cause, place, source)
 *   manual         what an admin does by hand (a map node merge, a person split);
 *                  ticking it says it is done
 *
 * Besides its ops, a card gets steps of its own from the records
 * (kop_daudit_steps()): a company's name filed as a program's past or other
 * name ("Three Springs Inc." on Pathway of Madison County) moves to its past
 * companies.
 *
 * 'renames' (beside the ops) says a rename explains why the years disagree:
 * the earlier and later name's records and the year; while both are records
 * of their own the card offers "Merge as a rename" (see Renames below). A name
 * that is only the company's ("Embark Behavioral Health" in Marion, the record
 * of Embark at Flathead Valley under its company) is not a rename: the card
 * says so and offers to fold it in as a duplicate; a name carrying the
 * company's name ("Sequel TSI Courtland") dates the rename by the years that
 * company ran the program (kop_daudit_brand()).
 *
 * Approve checks every ticked step first: a step whose 'from' no longer holds
 * is shown with the value the record has now, unticked; ticked anyway, it is
 * made over that value. Undo puts back exactly what Approve changed and leaves
 * anything edited since. The card's record editor ('save') writes a record's
 * names, status, years and companies directly, each save logged for its own
 * Undo. Decisions: option kop_data_audit_review, key => {decision, by, at,
 * done, picked, merges, edits}; map years: option kop_data_audit_map_years;
 * map lines taken off: kop_data_audit_map_edge_drops.
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

/** The lists a proposal may add to or take from. */
function kop_daudit_list_paths() {
    return array('identification.pastOperators', 'identification.pastNames', 'identification.otherNames', 'identification.otherOperators');
}

/** The op types that change one facility record's document. */
function kop_daudit_record_types() {
    return array('field', 'list_add', 'list_remove', 'note', 'note_remove', 'staff_role');
}

/** The fields a proposal may change on a record, with their check. */
function kop_daudit_field_ok($path, $to) {
    $text = function ($v, $max) { return $v === null || (is_string($v) && trim($v) !== '' && mb_strlen($v) < $max); };
    switch ($path) {
        case 'operatingPeriod.status':
            return in_array($to, kop_daudit_statuses(), true);
        case 'operatingPeriod.startYear':
        case 'operatingPeriod.endYear':
            return $to === null || (is_int($to) && $to >= 1800 && $to <= (int) gmdate('Y'));
        case 'identification.currentOperator':
        case 'identification.currentName':
        case 'location.street':
        case 'location.city':
        case 'location.zip':
            return $text($to, 160);
        case 'operatingPeriod.yearsOfOperation':
            return $text($to, 80);
        case 'location.state':
            return $to === null || (is_string($to) && preg_match('/^[A-Z]{2}$/', $to));
    }
    return false;
}

/** The staff entry of $op ({list, name}) in the document: [index, entry] or [-1, null]. */
function kop_daudit_staff_find(array $doc, array $op) {
    $list = (array) (kop_daudit_get($doc, 'staff.' . (string) ($op['list'] ?? '')) ?: array());
    $hit = array(-1, null);
    foreach ($list as $i => $row) {
        if (!is_array($row) || strcasecmp(trim((string) ($row['name'] ?? '')), trim((string) ($op['name'] ?? ''))) !== 0) continue;
        // One name may be listed twice: the entry with the role researched (or the new one) wins, else the first.
        $role = trim((string) ($row['role'] ?? ''));
        if ($role === trim((string) ($op['from'] ?? '')) || $role === trim((string) ($op['to'] ?? ''))) return array($i, $row);
        if ($hit[0] < 0) $hit = array($i, $row);
    }
    return $hit;
}

/** The note list a note_remove reads: operatingPeriod.notes, or the record's research notes. */
function kop_daudit_note_path(array $op) {
    return ($op['path'] ?? '') === 'notes' ? 'notes' : 'operatingPeriod.notes';
}

/** Whether one record op is already as proposed on the document (nothing left to do). */
function kop_daudit_doc_op_done(array $doc, array $op) {
    $path = (string) ($op['path'] ?? '');
    switch ($op['type']) {
        case 'field':
            return kop_daudit_norm(kop_daudit_get($doc, $path)) === kop_daudit_norm($op['to'] ?? null);
        case 'list_add':
            return kop_daudit_list_has(kop_daudit_list($doc, $path), $op['value'] ?? '');
        case 'list_remove':
            return !kop_daudit_list_has(kop_daudit_list($doc, $path), $op['value'] ?? '');
        case 'note':
            foreach (kop_daudit_list($doc, 'operatingPeriod.notes') as $n) {
                if (is_string($n) && strpos($n, trim((string) $op['text'])) === 0) return true;
            }
            return false;
        case 'note_remove':
            foreach (kop_daudit_list($doc, kop_daudit_note_path($op)) as $n) {
                if (is_string($n) && trim($n) === trim((string) $op['text'])) return false;
            }
            return true;
        case 'staff_role':
            list($i, $row) = kop_daudit_staff_find($doc, $op);
            return $i >= 0 && trim((string) ($row['role'] ?? '')) === trim((string) ($op['to'] ?? ''));
    }
    return false;
}

/**
 * Check a proposal's changes to one record against the document. Returns a
 * list of reasons it cannot be made (empty: it can). A reason with ' is now "'
 * is a value moved since the research; $over (the reviewer ticked the step
 * with the record's value in front of them) lets those through.
 */
function kop_daudit_doc_check(array $doc, array $ops, $over = false) {
    $why = array();
    foreach ($ops as $op) {
        $path = (string) ($op['path'] ?? '');
        if ($op['type'] === 'field') {
            $to = kop_daudit_norm($op['to'] ?? null);
            if (!kop_daudit_field_ok($path, $to)) {
                $why[] = 'The new value for ' . $path . ' is not one the record can hold.';
            } elseif (!$over && kop_daudit_norm(kop_daudit_get($doc, $path)) !== kop_daudit_norm($op['from'] ?? null)
                && kop_daudit_norm(kop_daudit_get($doc, $path)) !== $to) {
                $why[] = kop_daudit_field_label($path) . ' is now "' . kop_daudit_show(kop_daudit_get($doc, $path))
                    . '", not "' . kop_daudit_show($op['from'] ?? null) . '" as when this was researched.';
            }
        } elseif ($op['type'] === 'list_add' || $op['type'] === 'list_remove') {
            if (!in_array($path, kop_daudit_list_paths(), true) || trim((string) ($op['value'] ?? '')) === '') $why[] = 'A list change with no value.';
        } elseif ($op['type'] === 'note' || $op['type'] === 'note_remove') {
            if (trim((string) ($op['text'] ?? '')) === '') $why[] = 'A note with no text.';
        } elseif ($op['type'] === 'staff_role') {
            list($i, $row) = kop_daudit_staff_find($doc, $op);
            $role = trim((string) ($row['role'] ?? ''));
            if (!in_array($op['list'] ?? '', array('administrator', 'notableStaff'), true) || trim((string) ($op['to'] ?? '')) === '') {
                $why[] = 'A staff change with no list or role.';
            } elseif ($i < 0) {
                $why[] = 'No staff entry for ' . ($op['name'] ?? '') . ' is on the record any more.';
            } elseif (!$over && $role !== trim((string) ($op['from'] ?? '')) && $role !== trim((string) $op['to'])) {
                $why[] = $op['name'] . '\'s role is now "' . $role . '", not "' . ($op['from'] ?? '') . '".';
            }
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
        } elseif ($op['type'] === 'list_remove' || $op['type'] === 'note_remove') {
            $lpath = $op['type'] === 'note_remove' ? kop_daudit_note_path($op) : $path;
            $value = trim((string) ($op['type'] === 'note_remove' ? $op['text'] : $op['value']));
            $list = kop_daudit_list($doc, $lpath);
            foreach ($list as $i => $x) {
                $same = $op['type'] === 'note_remove' ? (is_string($x) && trim($x) === $value) : (is_string($x) && strcasecmp(trim($x), $value) === 0);
                if (!$same) continue;
                array_splice($list, $i, 1);
                kop_daudit_set($doc, $lpath, $list);
                $done[] = array('type' => $op['type'], 'path' => $lpath, 'value' => $x, 'at' => $i);
                $changed = true;
                break;
            }
        } elseif ($op['type'] === 'note') {
            $line = trim((string) $op['text']) . ($cite !== '' ? ' (' . $cite . ')' : '');
            $notes = kop_daudit_list($doc, 'operatingPeriod.notes');
            if (in_array($line, $notes, true)) continue;
            $notes[] = $line;
            kop_daudit_set($doc, 'operatingPeriod.notes', $notes);
            $done[] = array('type' => 'note', 'line' => $line);
        } elseif ($op['type'] === 'staff_role') {
            list($i, $row) = kop_daudit_staff_find($doc, $op);
            if ($i < 0 || trim((string) ($row['role'] ?? '')) === trim((string) $op['to'])) continue;
            $spath = 'staff.' . $op['list'];
            $list = (array) kop_daudit_get($doc, $spath);
            $list[$i]['role'] = trim((string) $op['to']);
            kop_daudit_set($doc, $spath, $list);
            $done[] = array('type' => 'staff_role', 'path' => $spath, 'at' => $i, 'name' => (string) $row['name'],
                'before' => (string) ($row['role'] ?? ''), 'value' => trim((string) $op['to']));
            $changed = true;
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
        } elseif ($d['type'] === 'list_remove' || $d['type'] === 'note_remove') {
            $list = kop_daudit_list($doc, $d['path']);
            if (!in_array($d['value'], $list, true)) {
                array_splice($list, min((int) $d['at'], count($list)), 0, array($d['value']));
                kop_daudit_set($doc, $d['path'], $list);
            }
        } elseif ($d['type'] === 'note') {
            $notes = array_values(array_filter(kop_daudit_list($doc, 'operatingPeriod.notes'), function ($x) use ($d) { return $x !== $d['line']; }));
            kop_daudit_set($doc, 'operatingPeriod.notes', $notes);
        } elseif ($d['type'] === 'staff_role') {
            $list = (array) kop_daudit_get($doc, $d['path']);
            $row = $list[$d['at']] ?? null;
            if (is_array($row) && (string) ($row['name'] ?? '') === $d['name'] && trim((string) ($row['role'] ?? '')) === $d['value']) {
                $list[$d['at']]['role'] = $d['before'];
                kop_daudit_set($doc, $d['path'], $list);
            } else {
                $kept[] = $d['name'] . '\'s role';
            }
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
        case 'name':
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
    if (!in_array($column, array('name', 'date_of_death', 'date_precision', 'age', 'program', 'cause_of_death', 'cause_category',
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
        'operatingPeriod.endYear' => 'Closing year', 'operatingPeriod.yearsOfOperation' => 'Years as written',
        'identification.currentOperator' => 'Current operator', 'identification.currentName' => 'Name it uses now',
        'identification.pastOperators' => 'Past operators', 'identification.pastNames' => 'Past names',
        'identification.otherNames' => 'Other names', 'identification.otherOperators' => 'Other operators',
        'location.street' => 'Street', 'location.city' => 'Town', 'location.state' => 'State', 'location.zip' => 'ZIP',
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

/** One change in plain words, for the card. $names: 'rec:<id>', 'node:<id>', 'op:<id>', 'person:<id>' => name; '_main' = the card's record. */
function kop_daudit_op_words(array $op, array $names = array()) {
    $rec = function ($id) use ($names) {
        $id = (int) $id;
        return isset($names['rec:' . $id]) ? $names['rec:' . $id] . ' (#' . $id . ')' : 'record #' . $id;
    };
    // A change to another record than the card's names it first.
    $on = '';
    if (in_array($op['type'], kop_daudit_record_types(), true) && (int) ($op['facility_id'] ?? 0) !== (int) ($names['_main'] ?? 0)) {
        $on = 'On ' . $rec($op['facility_id']) . ': ';
    }
    switch ($op['type']) {
        case 'field':
            return $on . kop_daudit_field_label($op['path']) . ': ' . kop_daudit_show($op['from'] ?? null) . ' -> ' . kop_daudit_show($op['to'] ?? null);
        case 'list_add':
            return $on . 'Add to ' . strtolower(kop_daudit_field_label($op['path'] ?? 'identification.pastOperators')) . ': ' . $op['value'];
        case 'list_remove':
            return $on . 'Take off ' . strtolower(kop_daudit_field_label($op['path'] ?? 'identification.pastOperators')) . ': ' . $op['value'];
        case 'note':
            return $on . 'Add to the notes: ' . $op['text'];
        case 'note_remove':
            return $on . 'Take this line off the notes: "' . $op['text'] . '"';
        case 'staff_role':
            return $on . $op['name'] . '\'s role: ' . kop_daudit_show($op['from'] ?? null) . ' -> ' . $op['to'];
        case 'merge':
            return !empty($op['rename'])
                ? 'Merge as a rename: ' . $rec($op['drop']) . ' is the earlier name of ' . $rec($op['keep'])
                    . (!empty($op['year']) ? ', renamed ' . (int) $op['year'] : '') . ' (each name keeps its own years)'
                : (!empty($op['alias'])
                    ? 'Just another name: ' . $rec($op['drop']) . ' folds into ' . $rec($op['keep']) . ' and its name becomes an other name (not a rename)'
                    : 'Merge as one program: ' . $rec($op['drop']) . ' folds into ' . $rec($op['keep']) . ', which keeps its name');
        case 'operator_create':
            $to = array_map(function ($l) use ($rec) { return $rec($l['facility_id']) . ' (' . ($l['relationship'] ?? 'current') . ')'; }, (array) ($op['links'] ?? array()));
            return 'New company record "' . $op['name'] . '"' . ($to ? ', linked to ' . implode(', ', $to) : '');
        case 'map_edge_drop':
            return 'Take the network map line off: ' . (string) ($op['label'] ?? $op['edge']);
        case 'map_years':
            return 'Network map, ' . ($names['node:' . $op['node']] ?? $op['node']) . ': ' . kop_daudit_show($op['from'] ?? null) . ' -> ' . $op['to'];
        case 'operator_link':
            $co = $names['op:' . $op['operator_id']] ?? ('company #' . $op['operator_id']);
            $where = (int) ($op['facility_id'] ?? 0) !== (int) ($names['_main'] ?? 0) ? $rec($op['facility_id']) : 'this program';
            if (($op['to'] ?? '') === 'remove') return 'Unlink ' . $co . ' from ' . $where . ' (it never ran it)';
            if (($op['to'] ?? '') === 'past') return $co . ' becomes a past company of ' . $where . ' (its page shows it as Transferred)';
            return $co . ' becomes the current company of ' . $where;
        case 'operator_field':
            $co = $names['op:' . $op['operator_id']] ?? ('company #' . $op['operator_id']);
            return $co . ', ' . (kop_daudit_operator_paths()[$op['path']] ?? $op['path']) . ': '
                . kop_daudit_show($op['from'] ?? null) . ' -> ' . kop_daudit_show($op['to'] ?? null);
        case 'links_move':
            $n = count((array) ($op['news_ids'] ?? array()));
            $l = count((array) ($op['lawsuit_ids'] ?? array()));
            return 'Move ' . implode(' and ', array_filter(array($n ? $n . ' article' . ($n === 1 ? '' : 's') : '', $l ? $l . ' lawsuit' . ($l === 1 ? '' : 's') : '')))
                . ' from ' . $rec($op['from_facility']) . ' to ' . $rec($op['to_facility'])
                . (!empty($op['label']) ? ' (' . $op['label'] . ')' : '');
        case 'person_merge':
            return 'Merge ' . ($names['person:' . $op['drop']] ?? '#' . $op['drop']) . ' (#' . $op['drop'] . ') into '
                . ($names['person:' . $op['keep']] ?? '#' . $op['keep']) . ' (#' . $op['keep'] . ')';
        case 'memorial':
            $labels = array('name' => 'Name', 'date_of_death' => 'Date of death', 'date_precision' => 'How exact the date is', 'age' => 'Age',
                'program' => 'Program', 'cause_of_death' => 'Cause of death', 'cause_category' => 'Cause (category)',
                'location' => 'Place', 'source_name' => 'Source', 'source_url' => 'Source link');
            return 'In Loving Memory, ' . ($labels[$op['column']] ?? $op['column']) . ': ' . kop_daudit_show($op['from'] ?? null) . ' -> ' . kop_daudit_show($op['to'] ?? null);
        case 'check':
        case 'manual':
            return (string) $op['text'];
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

/** A company row by name (case and punctuation aside): its id, or 0. */
function kop_daudit_operator_by_name(array $opts, $name) {
    $want = kop_daudit_name_key($name);
    if ($want === '') return 0;
    foreach ($opts['pdo']->query("SELECT id, name FROM `{$opts['prefix']}kop_operators`")->fetchAll(PDO::FETCH_NUM) as $r) {
        if (kop_daudit_name_key($r[1]) === $want) return (int) $r[0];
    }
    return 0;
}

/** Map line ids the audit took off, id => {label, by, at}. */
function kop_daudit_edge_drops() {
    $saved = get_option('kop_data_audit_map_edge_drops', array());
    return is_array($saved) ? $saved : array();
}

/** The map's lines taken off, with the audit's (filter kop_network_map_edge_drops). */
function kop_daudit_edge_drops_filter($drops) {
    foreach (kop_daudit_edge_drops() as $id => $x) $drops[(string) $id] = true;
    return $drops;
}
add_filter('kop_network_map_edge_drops', 'kop_daudit_edge_drops_filter');

/** Whether the network map's built graph has line $id (read before any drop). */
function kop_daudit_edge_exists($id) {
    static $ids = null;
    if ($ids === null) {
        $ids = array();
        $path = function_exists('kop_network_map_file') ? kop_network_map_file('graph.json') : '';
        $g = ($path !== '' && is_readable($path)) ? json_decode((string) file_get_contents($path), true) : null;
        foreach ((array) ($g['edges'] ?? array()) as $e) $ids[(string) ($e['id'] ?? '')] = true;
    }
    return isset($ids[(string) $id]);
}

/** A record id after the merges since (a dropped record is followed to the one it went into). */
function kop_daudit_resolve($id) {
    return function_exists('kop_facility_merge_resolve') ? (int) kop_facility_merge_resolve((int) $id) : (int) $id;
}

/**
 * Everything wrong with making proposal $p now: [] when it can be made.
 * Reads the records and the map; changes nothing. $over: values moved since
 * the research are no reason (the reviewer ticked those steps with the
 * record's value in front of them).
 */
function kop_daudit_check(array $p, array $opts, $over = false) {
    $why = array();
    $by_fid = array();
    foreach ($p['ops'] as $op) {
        if (in_array($op['type'], kop_daudit_record_types(), true)) $by_fid[(int) $op['facility_id']][] = $op;
    }
    foreach ($by_fid as $fid => $ops) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) {
            $now = kop_daudit_resolve($fid);
            $why[] = 'Record #' . $fid . ' is gone' . ($now !== (int) $fid ? ' (merged into #' . $now . ')' : ' (merged or deleted)') . '.';
            continue;
        }
        $why = array_merge($why, kop_daudit_doc_check($stored['doc'], $ops, $over));
    }
    $table = kop_daudit_link_table($opts);
    foreach ($p['ops'] as $op) {
        if ($op['type'] === 'operator_link') {
            $row = kop_daudit_link_row($opts['pdo'], $table, $op['facility_id'], $op['operator_id']);
            $now = $row ? $row['relationship'] : null;
            if (!$over && $now !== ($op['from'] ?? null) && !($now === null && $op['to'] === 'remove') && $now !== $op['to']) {
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
            if (!$over && !kop_daudit_operator_same($now, $op['from'] ?? null) && !kop_daudit_operator_same($now, $op['to'] ?? null)) {
                $why[] = 'The company\'s ' . (kop_daudit_operator_paths()[$path] ?? $path) . ' is now "' . kop_daudit_show($now)
                    . '", not "' . kop_daudit_show($op['from'] ?? null) . '".';
            }
        } elseif ($op['type'] === 'operator_create') {
            if (trim((string) ($op['name'] ?? '')) === '' || mb_strlen((string) $op['name']) > 160) $why[] = 'A new company with no name.';
            foreach ((array) ($op['fields'] ?? array()) as $path => $v) {
                if (!kop_daudit_operator_value_ok((string) $path, $v)) $why[] = 'The new company\'s ' . $path . ' is not a value it can hold.';
            }
            foreach ((array) ($op['links'] ?? array()) as $l) {
                if (!in_array($l['relationship'] ?? 'current', array('current', 'past'), true)) $why[] = 'A company link that is neither current nor past.';
                if (!kop_facility_load((int) ($l['facility_id'] ?? 0), $opts)) $why[] = 'Record #' . (int) ($l['facility_id'] ?? 0) . ' is gone (merged or deleted).';
            }
        } elseif ($op['type'] === 'merge') {
            $keep = (int) ($op['keep'] ?? 0);
            $drop = (int) ($op['drop'] ?? 0);
            if ($keep <= 0 || $drop <= 0 || $keep === $drop) {
                $why[] = 'A merge needs two records.';
            } elseif (kop_daudit_resolve($drop) === kop_daudit_resolve($keep)) {
                // Already one record: nothing to do.
            } elseif (!kop_facility_load($keep, $opts) || !kop_facility_load($drop, $opts)) {
                $why[] = 'Record #' . (kop_facility_load($keep, $opts) ? $drop : $keep) . ' is gone (merged or deleted).';
            } elseif (!empty($op['rename']) && (int) ($op['year'] ?? 0) && ((int) $op['year'] < 1800 || (int) $op['year'] > (int) gmdate('Y'))) {
                $why[] = 'The rename year ' . (int) $op['year'] . ' is not a year.';
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
        } elseif ($op['type'] === 'map_edge_drop') {
            if (!kop_daudit_edge_exists($op['edge'] ?? '')) $why[] = 'The map has no line ' . ($op['edge'] ?? '') . ' any more (the map was rebuilt).';
        } elseif ($op['type'] === 'memorial') {
            if (!kop_daudit_memorial_ok((string) $op['column'], $op['to'] ?? null)) {
                $why[] = 'The new ' . $op['column'] . ' is not a value the memorial can hold.';
                continue;
            }
            list($found, $now) = kop_daudit_memorial_value($opts['pdo'], $op['memorial_id'], (string) $op['column']);
            if (!$found) $why[] = 'Memorial entry #' . (int) $op['memorial_id'] . ' is gone.';
            elseif (!$over && (string) $now !== (string) ($op['from'] ?? '') && (string) $now !== (string) $op['to']) {
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

/** Whether step $op is already as proposed on the site (nothing left to do). */
function kop_daudit_op_done(array $op, array $opts) {
    $t = $op['type'];
    if (in_array($t, kop_daudit_record_types(), true)) {
        $stored = kop_facility_load((int) $op['facility_id'], $opts);
        return $stored ? kop_daudit_doc_op_done($stored['doc'], $op) : false;
    }
    switch ($t) {
        case 'operator_link':
            $row = kop_daudit_link_row($opts['pdo'], kop_daudit_link_table($opts), $op['facility_id'], $op['operator_id']);
            return $op['to'] === 'remove' ? !$row : ($row && $row['relationship'] === $op['to']);
        case 'operator_create':
            $oid = kop_daudit_operator_by_name($opts, $op['name'] ?? '');
            if (!$oid) return false;
            foreach ((array) ($op['links'] ?? array()) as $l) {
                $row = kop_daudit_link_row($opts['pdo'], kop_daudit_link_table($opts), $l['facility_id'], $oid);
                if (!$row || $row['relationship'] !== ($l['relationship'] ?? 'current')) return false;
            }
            return true;
        case 'merge':
            return kop_daudit_resolve((int) $op['drop']) === kop_daudit_resolve((int) $op['keep']);
        case 'map_years':
            return kop_daudit_node_years((string) $op['node']) === (string) $op['to'] || (kop_daudit_map_years()[(string) $op['node']] ?? null) === (string) $op['to'];
        case 'map_edge_drop':
            return isset(kop_daudit_edge_drops()[(string) $op['edge']]);
        case 'memorial':
            list($found, $now) = kop_daudit_memorial_value($opts['pdo'], $op['memorial_id'], (string) $op['column']);
            return $found && (string) $now === (string) $op['to'];
    }
    return false;
}

/**
 * Where each step of proposal $p stands now, index => {state, why}: ready,
 * done (already so on the site), changed (a value moved since the research:
 * why says what the record has now), blocked (cannot be made), check (to look
 * into, never made), manual (by hand: ticking says it is done).
 */
function kop_daudit_step_states(array $p, array $opts) {
    $out = array();
    foreach (kop_daudit_steps($p) as $i => $op) {
        if ($op['type'] === 'check' || $op['type'] === 'manual') {
            $out[$i] = array('state' => $op['type'], 'why' => '');
            continue;
        }
        try {
            if (kop_daudit_op_done($op, $opts)) {
                $out[$i] = array('state' => 'done', 'why' => '');
                continue;
            }
            $split = kop_daudit_check_split(array('ops' => array($op)), $opts);
        } catch (Throwable $e) {
            $out[$i] = array('state' => 'blocked', 'why' => $e->getMessage());
            continue;
        }
        if ($split['blocked']) $out[$i] = array('state' => 'blocked', 'why' => implode(' ', $split['blocked']));
        elseif ($split['changed']) $out[$i] = array('state' => 'changed', 'why' => implode(' ', $split['changed']));
        else $out[$i] = array('state' => 'ready', 'why' => '');
    }
    return $out;
}

/** The steps a plain Approve makes: every ready step (not by-hand, not to-check, not moved since). */
function kop_daudit_default_picked(array $states) {
    $out = array();
    foreach ($states as $i => $s) if ($s['state'] === 'ready') $out[] = (string) $i;
    return $out;
}

/** Each name's years for a rename merge of record $drop into $keep in $year, as kop_fmerge_do_merge() takes them. */
function kop_daudit_merge_rename_args(PDO $pdo, $keep, $drop, $year) {
    $e = kop_daudit_rename_side($pdo, $drop);
    $l = kop_daudit_rename_side($pdo, $keep);
    $y = kop_daudit_rename_years($e, $l, $year);
    $args = array('year' => (int) $year, 'earlier' => $y['earlier'], 'later' => $y['later']);
    // The research may order the names against what the records say: its order is the one asked for.
    if (function_exists('kop_fmerge_rename_of') && function_exists('kop_fmerge_row')) {
        $k = kop_fmerge_row($pdo, $keep);
        $d = kop_fmerge_row($pdo, $drop);
        $is = ($k && $d) ? kop_fmerge_rename_of($k['raw'], $d['raw']) : null;
        if ($is && (int) $is[0] === (int) $keep) $args['reversed'] = true;
    }
    return $args;
}

/**
 * Make the ticked steps of proposal $key ($picked: step indexes; null = every
 * ready step). Returns the message; throws, changing nothing, when a ticked
 * step cannot be made. A ticked step whose 'from' moved since the research is
 * made over the record's value only when $over_changes or it was ticked on
 * the card ($picked given): the card shows the value it replaces. $years:
 * step index => rename year given on the card for a merge with none.
 */
function kop_daudit_apply($key, $reviewer, $over_changes = false, $picked = null, array $years = array()) {
    $p = kop_daudit_proposals()[$key] ?? null;
    if (!$p) throw new RuntimeException('That proposal is not in the list any more.');
    $decisions = kop_daudit_decisions();
    if (($decisions[$key]['decision'] ?? '') === 'applied') throw new RuntimeException('Already approved.');
    $opts = kop_daudit_opts();
    $steps = kop_daudit_steps($p);
    $states = kop_daudit_step_states($p, $opts);
    $from_card = $picked !== null;
    if ($picked === null) {
        $picked = kop_daudit_default_picked($states);
        // A plain Approve (the bulk tool, the Conflicts section) waits when a step moved since, unless told to go over it.
        $moved = array_filter($states, function ($s) { return $s['state'] === 'changed'; });
        if ($moved && $over_changes) {
            foreach ($moved as $i => $s) $picked[] = (string) $i;
        } elseif ($moved) {
            throw new RuntimeException('Nothing was changed: ' . implode(' ', array_column($moved, 'why')));
        }
    }
    $picked = array_values(array_unique(array_map('strval', (array) $picked)));
    $ops = array();
    $marked = array();
    $why = array();
    foreach ($picked as $i) {
        if (!isset($steps[$i])) continue;
        $op = $steps[$i];
        $s = $states[$i]['state'] ?? 'ready';
        if ($s === 'check' || $s === 'done') continue;
        if ($s === 'manual') { $marked[] = $i; continue; }
        if ($s === 'blocked') $why[] = $states[$i]['why'];
        if ($s === 'changed' && !$from_card && !$over_changes) $why[] = $states[$i]['why'];
        if ($op['type'] === 'merge' && !empty($op['rename']) && !(int) ($op['year'] ?? 0)) {
            $op['year'] = (int) ($years[$i] ?? 0);
            if (!$op['year']) $why[] = 'Give the year of the rename for step ' . ((int) preg_replace('/\D/', '', $i) + 1) . '.';
        }
        $ops[$i] = $op;
    }
    if ($why) throw new RuntimeException('Nothing was changed: ' . implode(' ', array_unique($why)));
    if (!$ops && !$marked) {
        $open = array_filter($states, function ($s) { return in_array($s['state'], array('ready', 'changed', 'manual'), true); });
        if ($open) throw new RuntimeException('No change is ticked (or every ticked one is already on the site).');
        // Everything on the card is on the site already (made by hand, or by another card): it is filed as approved.
        $decisions[$key] = kop_daudit_keep_merges($decisions[$key] ?? array(),
            array('decision' => 'applied', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'), 'done' => array(), 'picked' => array(), 'marked' => array()));
        kop_daudit_save_decisions($decisions);
        return 'Everything on this card was already on the site, so nothing changed; it is filed under Approved.';
    }
    $cite = kop_daudit_cite($p);
    $done = array();
    $by_fid = array();
    foreach ($ops as $op) {
        if (in_array($op['type'], kop_daudit_record_types(), true)) $by_fid[(int) $op['facility_id']][] = $op;
    }
    kop_v2_with_write_lock($opts['pdo'], function () use ($by_fid, $opts, $cite, &$done) {
        foreach ($by_fid as $fid => $rops) {
            $stored = kop_facility_load($fid, $opts);
            $doc = $stored['doc'];
            $status_before = kop_daudit_get($doc, 'operatingPeriod.status');
            $changes = kop_daudit_doc_apply($doc, $rops, $cite);
            if (!$changes) continue;
            kop_wbf_save($doc, $opts);
            $done[] = array('type' => 'record', 'facility_id' => $fid, 'changes' => $changes);
            if (kop_daudit_get($doc, 'operatingPeriod.status') !== $status_before) do_action('kop_facility_status_changed', $fid);
        }
    });
    $table = kop_daudit_link_table($opts);
    $map = kop_daudit_map_years();
    $drops = kop_daudit_edge_drops();
    $merges = array();
    foreach ($ops as $i => $op) {
        if ($op['type'] === 'operator_link') {
            $before = kop_daudit_link_row($opts['pdo'], $table, $op['facility_id'], $op['operator_id']);
            $after = $op['to'] === 'remove' ? null : array('relationship' => $op['to'], 'sort_order' => (int) ($before['sort_order'] ?? 0));
            kop_daudit_link_put($opts['pdo'], $table, $op['facility_id'], $op['operator_id'], $after);
            $done[] = array('type' => 'operator_link', 'facility_id' => (int) $op['facility_id'], 'operator_id' => (int) $op['operator_id'], 'before' => $before, 'after' => $after);
        } elseif ($op['type'] === 'operator_create') {
            $oid = kop_daudit_operator_by_name($opts, $op['name']);
            $created = false;
            if (!$oid) {
                $operator = array('name' => trim((string) $op['name']));
                foreach ((array) ($op['fields'] ?? array()) as $path => $v) kop_daudit_set($operator, (string) $path, $v);
                $json = json_encode(array('operator' => $operator), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $opts['pdo']->prepare("INSERT INTO `{$opts['prefix']}kop_operators` (unique_name, name, json_data) VALUES (?, ?, ?)")
                    ->execute(array(trim((string) $op['name']), trim((string) $op['name']), $json));
                $oid = (int) $opts['pdo']->lastInsertId();
                $created = true;
            }
            $links = array();
            foreach ((array) ($op['links'] ?? array()) as $l) {
                $before = kop_daudit_link_row($opts['pdo'], $table, $l['facility_id'], $oid);
                $after = array('relationship' => (string) ($l['relationship'] ?? 'current'), 'sort_order' => (int) ($before['sort_order'] ?? 0));
                if ($before && $before['relationship'] === $after['relationship']) continue;
                kop_daudit_link_put($opts['pdo'], $table, $l['facility_id'], $oid, $after);
                $links[] = array('facility_id' => (int) $l['facility_id'], 'before' => $before, 'after' => $after);
            }
            $done[] = array('type' => 'operator_create', 'operator_id' => $oid, 'created' => $created, 'links' => $links,
                'json' => $created ? $json : '');
            kop_daudit_operator_flush($oid);
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
        } elseif ($op['type'] === 'map_edge_drop') {
            $drops[(string) $op['edge']] = array('label' => (string) ($op['label'] ?? ''), 'key' => (string) $key, 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'));
            $done[] = array('type' => 'map_edge_drop', 'edge' => (string) $op['edge']);
        } elseif ($op['type'] === 'memorial') {
            list(, $before) = kop_daudit_memorial_value($opts['pdo'], $op['memorial_id'], (string) $op['column']);
            kop_daudit_memorial_put($opts['pdo'], $op['memorial_id'], (string) $op['column'], $op['to']);
            $done[] = array('type' => 'memorial', 'memorial_id' => (int) $op['memorial_id'], 'column' => (string) $op['column'], 'before' => $before, 'value' => $op['to']);
        } elseif ($op['type'] === 'person_merge') {
            kop_pmerge_do_merge((int) $op['keep'], (int) $op['drop'], $reviewer);
            $log = kop_pmerge_log();
            $done[] = array('type' => 'person_merge', 'log' => (string) ($log[0]['id'] ?? ''));
        } elseif ($op['type'] === 'merge') {
            $merges[$i] = $op;
        }
    }
    update_option('kop_data_audit_map_years', $map, false);
    update_option('kop_data_audit_map_edge_drops', $drops, false);
    if (array_filter($done, function ($d) { return $d['type'] === 'map_edge_drop'; }) && function_exists('kop_network_map_flush')) kop_network_map_flush();
    // Merges last: every change above was made to both records first, so the kept one carries them.
    $failed = '';
    foreach ($merges as $i => $op) {
        try {
            $done[] = kop_daudit_merge_apply($op, $opts, $reviewer);
        } catch (Throwable $e) {
            $failed = 'Merge ' . (int) $op['drop'] . ' into ' . (int) $op['keep'] . ' was not made: ' . $e->getMessage();
            break;
        }
    }
    $decisions = kop_daudit_decisions();
    $decisions[$key] = kop_daudit_keep_merges($decisions[$key] ?? array(),
        array('decision' => 'applied', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'), 'done' => $done, 'picked' => $picked, 'marked' => $marked));
    kop_daudit_save_decisions($decisions);
    $left = count(array_filter($states, function ($s) { return in_array($s['state'], array('ready', 'changed', 'manual'), true); })) - count($ops) - count($marked);
    return 'Approved: ' . (string) ($p['title'] ?? $key) . ' is corrected on the site now'
        . ($left > 0 ? ' (' . $left . ' unticked step' . ($left === 1 ? '' : 's') . ' left as they were)' : '') . '. Undo is on the Approved tab.'
        . ($failed !== '' ? ' ' . $failed . '; the other changes were made.' : '');
}

/** One merge step: the merge (as a rename when it says so), and its log id for Undo. */
function kop_daudit_merge_apply(array $op, array $opts, $reviewer) {
    if (!function_exists('kop_fmerge_do_merge')) throw new RuntimeException('Merge Duplicates is not loaded.');
    if (function_exists('get_stylesheet_directory')) require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    $pdo = $opts['pdo'];
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $keep = (int) $op['keep'];
    $drop = (int) $op['drop'];
    try {
        if (!empty($op['rename'])) {
            $msg = kop_fmerge_do_merge($pdo, $opts['prefix'], $keep, $drop, (string) $reviewer, kop_daudit_merge_rename_args($pdo, $keep, $drop, (int) $op['year']));
        } else {
            $msg = kop_fmerge_do_merge($pdo, $opts['prefix'], $keep, $drop, (string) $reviewer, null, !empty($op['alias']) ? 'alias' : true);
        }
    } finally {
        if (function_exists('kop_fmerge_flush')) kop_fmerge_flush();
    }
    $log = kop_fmerge_log();
    return array('type' => 'merge', 'keep' => $keep, 'drop' => $drop, 'log' => (string) ($log[0]['id'] ?? ''), 'message' => $msg);
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
        $drops = kop_daudit_edge_drops();
        foreach (array_reverse((array) ($d['done'] ?? array())) as $x) {
            if ($x['type'] === 'merge') {
                if (($x['log'] ?? '') === '') continue;
                if (function_exists('get_stylesheet_directory')) require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
                $opts['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                try {
                    kop_fmerge_do_undo($opts['pdo'], $opts['prefix'], (string) $x['log']);
                } catch (Throwable $e) {
                    $kept[] = 'the merge of #' . (int) $x['drop'] . ' (' . $e->getMessage() . ')';
                } finally {
                    if (function_exists('kop_fmerge_flush')) kop_fmerge_flush();
                }
            } elseif ($x['type'] === 'record') {
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
            } elseif ($x['type'] === 'operator_create') {
                $oid = (int) $x['operator_id'];
                foreach (array_reverse((array) $x['links']) as $l) {
                    $now = kop_daudit_link_row($opts['pdo'], $table, $l['facility_id'], $oid);
                    if (($now['relationship'] ?? null) === ($l['after']['relationship'] ?? null)) kop_daudit_link_put($opts['pdo'], $table, $l['facility_id'], $oid, $l['before']);
                    else $kept[] = 'a company link (edited since)';
                }
                if (!empty($x['created'])) {
                    $st = $opts['pdo']->prepare("SELECT COUNT(*) FROM `{$table}` WHERE operator_id = ?");
                    $st->execute(array($oid));
                    $json = kop_daudit_operator_json($opts, $oid);
                    $same = $json && kop_daudit_operator_same($json, json_decode((string) $x['json'], true));
                    if (!(int) $st->fetchColumn() && $same) {
                        $opts['pdo']->prepare("DELETE FROM `{$opts['prefix']}kop_operators` WHERE id = ?")->execute(array($oid));
                    } elseif ($json) {
                        $kept[] = 'the new company #' . $oid . ' (edited or linked since)';
                    }
                }
                kop_daudit_operator_flush($oid);
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
            } elseif ($x['type'] === 'map_edge_drop') {
                unset($drops[(string) $x['edge']]);
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
        update_option('kop_data_audit_map_edge_drops', $drops, false);
        if (function_exists('kop_network_map_flush')) kop_network_map_flush();
    }
    $decisions = kop_daudit_decisions();
    unset($decisions[$key]);
    $keepers = array_intersect_key($d, array('merges' => 1, 'edits' => 1, 'aliases' => 1));
    if ($keepers) $decisions[$key] = $keepers;
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
    if ($e && $l && $e['id'] !== $l['id']) {
        // No year researched or found: the earlier name's end, else the later one's start when it is after the earlier's.
        if (!$out['year']) $out['year'] = $e['end'] ?: (($l['start'] && $l['start'] > $e['start']) ? $l['start'] : 0);
        $out['after'] = kop_daudit_rename_years($e, $l, $out['year']);
        return $out;
    }
    if ($mine) {
        $out['state'] = 'merged';
        $out['log'] = (string) $mine['log'];
        $out['year'] = (int) $mine['year'];
        $out['how'] = !empty($mine['alias']) ? 'alias' : (!empty($mine['fold']) ? 'fold' : 'rename');
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
            if (($s['how'] ?? '') === 'alias') return 'Merged from this card as one program: the other name is only another name of it, not a rename.';
            if (($s['how'] ?? '') === 'fold') return 'Merged from this card as one program: the company-named record folded in.';
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
    // A decision replaced keeps the card's rename merges and record edits (each with its own Undo).
    if (!empty($old['merges'])) $new['merges'] = $old['merges'];
    if (!empty($old['edits'])) $new['edits'] = $old['edits'];
    if (!empty($old['aliases'])) $new['aliases'] = $old['aliases'];
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

/**
 * Fold the pair $pair of proposal $key together as one program when one of
 * the two names is only the company's (kop_daudit_rename_brands()): the
 * company-named record goes into the other, which keeps its own name and
 * years (a plain merge, not a rename). Returns the message; Undo is
 * kop_daudit_rename_unmerge().
 */
function kop_daudit_rename_fold($key, $pair, $reviewer) {
    $p = kop_daudit_proposals()[$key] ?? null;
    if (!$p) throw new RuntimeException('That proposal is not in the list any more.');
    $r = kop_daudit_renames($p)[(string) $pair] ?? null;
    if (!$r) throw new RuntimeException('That rename is not on this card.');
    $opts = kop_daudit_opts();
    $s = kop_daudit_rename_state($key, $r, $opts['pdo']);
    if ($s['state'] !== 'separate') throw new RuntimeException(kop_daudit_rename_words($s));
    $b = kop_daudit_rename_brands($s, $opts);
    if ($b['company_side'] === '') throw new RuntimeException('Neither name is only the company\'s: merge them as a rename instead.');
    $keep = $b['company_side'] === 'later' ? $s['earlier']['id'] : $s['later']['id'];
    $drop = $b['company_side'] === 'later' ? $s['later']['id'] : $s['earlier']['id'];
    $done = kop_daudit_merge_apply(array('keep' => $keep, 'drop' => $drop), $opts, $reviewer);
    $decisions = kop_daudit_decisions();
    $decisions[$key]['merges'][(string) $pair] = array('log' => $done['log'], 'year' => 0, 'fold' => true,
        'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'));
    if (!$p['ops'] && empty($decisions[$key]['decision'])) {
        $decisions[$key] += array('decision' => 'applied', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'), 'done' => array(), 'by_merge' => true);
    }
    kop_daudit_save_decisions($decisions);
    return $done['message'] . ' Undo is on this card and on Merge Duplicates\' Merged tab.';
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

/* ---- Company names in program names ------------------------------------ */

/*
 * A program is often named after its company. Two kinds:
 *   company  the name is only the company's own ("Embark Behavioral Health",
 *            "Embark Behavioral Health RTC", "Three Springs Inc."): a record
 *            under that name in the same town is the program filed under its
 *            company, not an earlier or later name of it, and a past name like
 *            that is a past company
 *   brand    the company's name with the place ("Sequel TSI Courtland", "Three
 *            Springs of Courtland"): the program carried it while that company
 *            ran it, so the company's years on the program date the name
 * kop_daudit_company_of() tells them apart; kop_daudit_brand() reads the
 * company's years from the records' operator lines ("Sequel TSI (2009-2022)").
 */

function kop_daudit_name_key($s) {
    $s = strtolower(str_replace('&', ' and ', (string) $s));
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/** The words a company or program name may carry that say nothing about which one it is. */
function kop_daudit_generic_words() {
    return array('inc', 'incorporated', 'llc', 'l', 'c', 'corp', 'corporation', 'co', 'company', 'ltd', 'lp', 'llp', 'pllc', 'pc',
        'the', 'rtc', 'residential', 'treatment', 'center', 'centre', 'campus', 'program', 'programs', 'holdings', 'group');
}

/** A name key without those words ("embark behavioral health rtc" -> "embark behavioral health"). */
function kop_daudit_core_key($s) {
    $generic = array_flip(kop_daudit_generic_words());
    $words = array_filter(explode(' ', kop_daudit_name_key($s)), function ($w) use ($generic) { return $w !== '' && !isset($generic[$w]); });
    return implode(' ', $words);
}

/** Whether a name carries a company's legal ending (Inc., LLC, Corp.). */
function kop_daudit_has_legal_suffix($s) {
    return (bool) preg_match('/\b(inc|incorporated|llc|l\.l\.c|corp|corporation|ltd|holdings|lp)\b\.?\s*$/i', trim((string) $s));
}

/** A company line without its years and notes: "Sequel TSI (2009-2022)" -> "Sequel TSI". */
function kop_daudit_company_line_name($s) {
    return trim(preg_replace('/\s*[\(\[].*$/', '', (string) $s));
}

/** Years in a company line: "Sequel TSI (2009-2022)" -> [2009, 2022]; "(from 2014)", "(2014-present)" -> [2014, 0]. */
function kop_daudit_company_line_years($s) {
    if (!preg_match('/[\(\[]([^\)\]]*)[\)\]]/', (string) $s, $m)) return array(0, 0);
    if (preg_match('/\b(1[89]\d\d|20\d\d)\s*(?:-|–|to)\s*(1[89]\d\d|20\d\d)\b/', $m[1], $y)) return array((int) $y[1], (int) $y[2]);
    if (preg_match('/\b(1[89]\d\d|20\d\d)\b/', $m[1], $y)) {
        return preg_match('/until|to\s|-\s*$/i', $m[1]) && !preg_match('/from|since|present|now/i', $m[1]) ? array(0, (int) $y[1]) : array((int) $y[1], 0);
    }
    return array(0, 0);
}

/**
 * The company names that may stand in the names of records $fids: core key =>
 * display name, from every company row (its name and other names) and the
 * records' own operator lines and company links.
 */
function kop_daudit_companies(array $fids, array $opts) {
    static $rows = null;
    $out = array();
    $add = function ($name) use (&$out) {
        $name = kop_daudit_company_line_name($name);
        $k = kop_daudit_core_key($name);
        // One short word ("Aspen", "Embark") is too common to stand for a company alone.
        if ($k !== '' && (strpos($k, ' ') !== false || strlen($k) >= 8) && !isset($out[$k])) $out[$k] = $name;
    };
    if ($rows === null) {
        $rows = array();
        try {
            foreach ($opts['pdo']->query("SELECT id, name, json_data FROM `{$opts['prefix']}kop_operators`")->fetchAll(PDO::FETCH_NUM) as $r) {
                $op = (array) (json_decode((string) $r[2], true)['operator'] ?? array());
                $rows[(int) $r[0]] = array_merge(array((string) $r[1]), array_map('strval', (array) ($op['otherNames'] ?? array())),
                    is_string($op['currentName'] ?? null) && $op['currentName'] !== '' ? array($op['currentName']) : array());
            }
        } catch (Throwable $e) {
            $rows = array();
        }
    }
    foreach ($rows as $names) foreach ($names as $n) $add($n);
    foreach (array_unique(array_map('intval', $fids)) as $fid) {
        $stored = $fid > 0 ? kop_facility_load($fid, $opts) : null;
        if (!$stored) continue;
        $id = (array) ($stored['doc']['identification'] ?? array());
        foreach (array_merge(array((string) ($id['currentOperator'] ?? '')), kop_daudit_list($stored['doc'], 'identification.pastOperators'),
            kop_daudit_list($stored['doc'], 'identification.otherOperators')) as $line) {
            if (is_string($line)) $add($line);
        }
    }
    return $out;
}

/**
 * How a name stands to the companies: ['level' => company|brand, 'company' =>
 * display name, 'key' => core key] or null. Longest company first, so "Sequel
 * TSI" wins over "Sequel".
 */
function kop_daudit_company_of($name, array $companies) {
    $core = kop_daudit_core_key($name);
    if ($core === '') return null;
    uksort($companies, function ($a, $b) { return strlen($b) <=> strlen($a); });
    foreach ($companies as $k => $display) {
        if ($core === $k) return array('level' => 'company', 'company' => $display, 'key' => $k);
        if (strpos($core . ' ', $k . ' ') === 0) return array('level' => 'brand', 'company' => $display, 'key' => $k);
    }
    return null;
}

/**
 * The company a name carries and that company's years on the program, from
 * the records' operator lines: ['level', 'company', 'start', 'end'] or null.
 */
function kop_daudit_brand($name, array $docs, array $companies) {
    $c = kop_daudit_company_of($name, $companies);
    if (!$c) return null;
    $c['start'] = 0;
    $c['end'] = 0;
    foreach ($docs as $doc) {
        foreach (array_merge(kop_daudit_list($doc, 'identification.pastOperators'), array((string) ($doc['identification']['currentOperator'] ?? ''))) as $line) {
            if (!is_string($line)) continue;
            $k = kop_daudit_core_key(kop_daudit_company_line_name($line));
            if ($k === '' || !($k === $c['key'] || strpos($k . ' ', $c['key'] . ' ') === 0 || strpos($c['key'] . ' ', $k . ' ') === 0)) continue;
            list($s, $e) = kop_daudit_company_line_years($line);
            if ($s && (!$c['start'] || $s < $c['start'])) $c['start'] = $s;
            if ($e && $e > $c['end']) $c['end'] = $e;
        }
    }
    return $c;
}

/** The records a card is about: its own, every record its steps change, and its renames' two sides. */
function kop_daudit_card_records(array $p) {
    $ids = array();
    if (!empty($p['facility_id'])) $ids[] = (int) $p['facility_id'];
    foreach ($p['ops'] as $op) {
        foreach (array('facility_id', 'keep', 'drop', 'from_facility', 'to_facility') as $k) {
            if ($op['type'] === 'person_merge' && ($k === 'keep' || $k === 'drop')) continue;
            if (!empty($op[$k])) $ids[] = (int) $op[$k];
        }
        foreach ((array) ($op['links'] ?? array()) as $l) if (!empty($l['facility_id'])) $ids[] = (int) $l['facility_id'];
    }
    foreach ((array) ($p['renames'] ?? array()) as $r) {
        if (!empty($r['earlier'])) $ids[] = (int) $r['earlier'];
        if (!empty($r['later'])) $ids[] = (int) $r['later'];
    }
    return array_values(array_unique(array_filter($ids)));
}

/**
 * The card's steps: its ops (keys 0, 1, ...), then steps the records call for
 * themselves (keys a0, a1, ...): a company's own name filed as a program's past
 * or other name moves to its past companies. Only a name with a company's legal
 * ending, or one no facility record is called, counts: "Three Points Center"
 * is a program as well as its company.
 */
function kop_daudit_steps(array $p) {
    static $memo = array();
    $key = (string) ($p['key'] ?? md5(json_encode($p['ops'])));
    if (isset($memo[$key])) return $memo[$key];
    $steps = array();
    foreach ($p['ops'] as $i => $op) $steps[(string) $i] = $op;
    try {
        $opts = kop_daudit_opts();
    } catch (Throwable $e) {
        return $memo[$key] = $steps;
    }
    $fids = kop_daudit_card_records($p);
    $companies = kop_daudit_companies($fids, $opts);
    // What the research already proposes is not offered twice.
    $have = array();
    foreach ($p['ops'] as $op) {
        if ($op['type'] === 'list_add' || $op['type'] === 'list_remove') {
            $have[$op['type'] . '|' . (int) $op['facility_id'] . '|' . strtolower(trim((string) $op['value']))] = true;
            $have['any|' . (int) $op['facility_id'] . '|' . strtolower(trim((string) $op['value']))] = true;
        }
    }
    $n = 0;
    foreach ($fids as $fid) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) continue;
        $past_ops = kop_daudit_list($stored['doc'], 'identification.pastOperators');
        foreach (array('identification.pastNames', 'identification.otherNames') as $path) {
            foreach (kop_daudit_list($stored['doc'], $path) as $name) {
                if (!is_string($name) || trim($name) === '') continue;
                $c = kop_daudit_company_of($name, $companies);
                if (!$c || $c['level'] !== 'company') continue;
                if (!kop_daudit_has_legal_suffix($name) && kop_daudit_record_named($opts, $name)) continue;
                $why = '"' . $name . '" is the name of a company (' . $c['company'] . '), not a name the program went by.';
                if (!isset($have['list_remove|' . $fid . '|' . strtolower(trim($name))])) {
                    $steps['a' . $n++] = array('type' => 'list_remove', 'facility_id' => $fid, 'path' => $path, 'value' => $name, 'auto' => true, 'why' => $why);
                }
                $listed = isset($have['any|' . $fid . '|' . strtolower(kop_daudit_company_line_name($name))]);
                foreach ($past_ops as $line) if (is_string($line) && kop_daudit_core_key(kop_daudit_company_line_name($line)) === $c['key']) $listed = true;
                if (!$listed) {
                    $steps['a' . $n++] = array('type' => 'list_add', 'facility_id' => $fid, 'path' => 'identification.pastOperators',
                        'value' => kop_daudit_company_line_name($name), 'auto' => true, 'why' => $why);
                }
            }
        }
    }
    return $memo[$key] = $steps;
}

/** Whether a facility record carries this exact name (any state). */
function kop_daudit_record_named(array $opts, $name) {
    $st = $opts['pdo']->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE LOWER(name) = LOWER(?)');
    $st->execute(array(trim((string) $name)));
    return (int) $st->fetchColumn() > 0;
}

/**
 * What the companies in a rename pair's names say (a state from
 * kop_daudit_rename_state() with both sides on file): ['company_side' =>
 * earlier|later|'' (the side that is only the company's name), 'company',
 * 'backwards' (the brands' years put the names the other way round), 'year'
 * (the rename year the brands give), 'lines' => [sentences for the card]].
 */
function kop_daudit_rename_brands(array $s, array $opts) {
    $out = array('company_side' => '', 'company' => '', 'backwards' => false, 'year' => 0, 'lines' => array());
    if (empty($s['earlier']) || empty($s['later'])) return $out;
    $e = $s['earlier'];
    $l = $s['later'];
    $docs = array();
    foreach (array($e['id'], $l['id']) as $id) {
        $stored = kop_facility_load((int) $id, $opts);
        if ($stored) $docs[] = $stored['doc'];
    }
    $companies = kop_daudit_companies(array($e['id'], $l['id']), $opts);
    $be = kop_daudit_brand($e['name'], $docs, $companies);
    $bl = kop_daudit_brand($l['name'], $docs, $companies);
    $span = function ($b) { return $b['start'] && $b['end'] ? $b['start'] . ' to ' . $b['end'] : ($b['start'] ? 'from ' . $b['start'] : ($b['end'] ? 'until ' . $b['end'] : '')); };
    foreach (array('later' => array($bl, $l, $e), 'earlier' => array($be, $e, $l)) as $side => $x) {
        list($b, $me, $other) = $x;
        if (!$b || $b['level'] !== 'company' || kop_daudit_core_key($other['name']) === $b['key']) continue;
        // Only the company that runs (or ran) the other name's record: a program run under its own name
        // (Pathway Family Center) or one that shares its company's name (Three Points Center) is a name of its own.
        if (!isset(kop_daudit_record_companies((int) $other['id'], $opts)[$b['key']])) continue;
        // A company named after its one program (Pathway of Madison County, LLC) gives the program's name, not a
        // company's: only a company that runs other programs too (Embark, Three Springs) has a name of its own.
        $others = array_diff_key(kop_daudit_company_reach($opts)[$b['key']] ?? array(), array($e['id'] => 1, $l['id'] => 1));
        if (count($others) < 2) continue;
        // Years of its own outside the other name's (Three Points Center in Siler City, to 2025, after Auldern
        // closed in 2021): a later name under the company, not the same name filed twice.
        $lo = (int) $other['start'];
        $hi = (int) $other['end'];
        $outside = function ($y) use ($lo, $hi) { return $y && (($lo && $y < $lo) || ($hi && $y > $hi)); };
        if ($outside((int) $me['start']) || $outside((int) $me['end'])) continue;
        $out['company_side'] = $side;
        $out['company'] = $b['company'];
        $out['lines'][] = '"' . $me['name'] . '" is only the name of the company (' . $b['company'] . '), not a name the program went by: record #'
            . $me['id'] . ' is most likely ' . $other['name'] . ' filed under the company that runs it. Fold it into ' . $other['name']
            . ' as one program (it keeps its own name and years) unless the program really was called that.';
        break;
    }
    if (!$out['company_side']) {
        foreach (array(array($be, $e), array($bl, $l)) as $x) {
            list($b, $me) = $x;
            if ($b && $b['level'] === 'brand') {
                $out['lines'][] = '"' . $me['name'] . '" carries the name of ' . $b['company']
                    . ($span($b) !== '' ? ', which ran the program ' . $span($b) : '') . ': the program had that name while ' . $b['company'] . ' ran it.';
            }
        }
        if ($be && $bl && $be['key'] !== $bl['key'] && $be['start'] && $bl['start']) {
            if ($be['start'] > $bl['start']) {
                $out['backwards'] = true;
                $out['year'] = $be['start'];
                $out['lines'][] = 'By those years the order is the other way round: ' . $l['name'] . ' is the earlier name, ' . $e['name']
                    . ' the later one, renamed about ' . $be['start'] . '. Use "Merge the other way round".';
            } else {
                $out['year'] = $bl['start'];
            }
        } elseif ($bl && $bl['level'] === 'brand' && $bl['start']) {
            $out['year'] = $bl['start'];
        } elseif ($be && $be['level'] === 'brand' && $be['end']) {
            $out['year'] = $be['end'];
        }
    }
    return $out;
}

/**
 * Every company and the records it runs or ran: core key => [record id =>
 * true], from every record's operator lines and the company links. Read once
 * an hour (a transient keyed by the record count and newest change).
 */
function kop_daudit_company_reach(array $opts) {
    static $memo = null;
    if ($memo !== null) return $memo;
    $stamp = '';
    try {
        $stamp = implode(':', $opts['pdo']->query('SELECT COUNT(*), MAX(updated_at) FROM facilities_v2')->fetch(PDO::FETCH_NUM));
    } catch (Throwable $e) {
        $stamp = '';
    }
    $cached = function_exists('get_transient') ? get_transient('kop_daudit_company_reach') : false;
    if (is_array($cached) && ($cached['stamp'] ?? null) === $stamp) return $memo = $cached['reach'];
    $reach = array();
    foreach ($opts['pdo']->query('SELECT id, json_data FROM facilities_v2')->fetchAll(PDO::FETCH_NUM) as $r) {
        $doc = json_decode((string) $r[1], true);
        if (!is_array($doc)) continue;
        foreach (array_merge(array((string) ($doc['identification']['currentOperator'] ?? '')), kop_daudit_list($doc, 'identification.pastOperators'),
            kop_daudit_list($doc, 'identification.otherOperators')) as $line) {
            $k = is_string($line) ? kop_daudit_core_key(kop_daudit_company_line_name($line)) : '';
            if ($k !== '') $reach[$k][(int) $r[0]] = 1;
        }
    }
    try {
        foreach ($opts['pdo']->query("SELECT o.name, f.facility_id FROM `{$opts['prefix']}kop_operator_facilities` f JOIN `{$opts['prefix']}kop_operators` o ON o.id = f.operator_id")->fetchAll(PDO::FETCH_NUM) as $r) {
            $k = kop_daudit_core_key($r[0]);
            if ($k !== '') $reach[$k][(int) $r[1]] = 1;
        }
    } catch (Throwable $e) {
        // The records' own lines still count.
    }
    if (function_exists('set_transient')) set_transient('kop_daudit_company_reach', array('stamp' => $stamp, 'reach' => $reach), 3600);
    return $memo = $reach;
}

/** The companies that run or ran record $fid: core key => true, from its operator lines and company links. */
function kop_daudit_record_companies($fid, array $opts) {
    $out = array();
    $stored = kop_facility_load((int) $fid, $opts);
    if ($stored) {
        $doc = $stored['doc'];
        foreach (array_merge(array((string) ($doc['identification']['currentOperator'] ?? '')), kop_daudit_list($doc, 'identification.pastOperators'),
            kop_daudit_list($doc, 'identification.otherOperators')) as $line) {
            $k = is_string($line) ? kop_daudit_core_key(kop_daudit_company_line_name($line)) : '';
            if ($k !== '') $out[$k] = true;
        }
    }
    try {
        $st = $opts['pdo']->prepare("SELECT o.name FROM `{$opts['prefix']}kop_operator_facilities` f JOIN `{$opts['prefix']}kop_operators` o ON o.id = f.operator_id WHERE f.facility_id = ?");
        $st->execute(array((int) $fid));
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
            $k = kop_daudit_core_key($n);
            if ($k !== '') $out[$k] = true;
        }
    } catch (Throwable $e) {
        // The links are a help; the record's own lines still count.
    }
    return $out;
}

/* ---- Another name, not a rename ----------------------------------------- */

/*
 * Some names are only other names of one program (a legal name, a short
 * form, a campus name used side by side), never an earlier or later name.
 * Two ways to say so from a card, each with its own Undo there:
 *   - two records: "Just another name" merges them as one program, the
 *     dropped name filed as an other name (kop_fmerge_do_merge(..., 'alias'))
 *   - a name on a record: "Only another name" moves it from the past names
 *     (or "now called") to the other names (kop_daudit_name_alias())
 * Both mark the map's rebrand line between the two names "Not a rename" at
 * Map Renames, which the facility pages' name sections and Merge Duplicates
 * read, so the pair is never cut or offered as a rename again.
 * Decisions: decisions[key]['aliases'][] = {fid, value, done, map, by, at, undone}.
 */

/** The map's rebrand lines between record $fid's names on the map and the map name $value: "source>target" keys. */
function kop_daudit_alias_lines($fid, $value) {
    $graph = function_exists('kop_network_map_graph') ? kop_network_map_graph() : null;
    if (!$graph) return array();
    $want = kop_daudit_name_key($value);
    $mine = array();
    $named = array();
    foreach ((array) $graph['nodes'] as $n) {
        $id = (string) ($n['id'] ?? '');
        if (kop_daudit_resolve((int) ($n['facilityId'] ?? 0)) === kop_daudit_resolve((int) $fid) && (int) ($n['facilityId'] ?? 0) > 0) $mine[$id] = true;
        if ($want !== '' && kop_daudit_name_key($n['name'] ?? '') === $want) $named[$id] = true;
    }
    $out = array();
    foreach ((array) ($graph['edges'] ?? array()) as $e) {
        if (!in_array('rebrand', (array) ($e['roles'] ?? array()), true)) continue;
        $a = (string) $e['source'];
        $b = (string) $e['target'];
        if ((isset($mine[$a]) && isset($named[$b])) || (isset($mine[$b]) && isset($named[$a]))) $out[] = $a . '>' . $b;
    }
    return $out;
}

/** Mark map lines "Not a rename" at Map Renames; returns key => the decision there before (null = none), for Undo. */
function kop_daudit_skip_lines(array $keys, $reviewer) {
    if (!$keys || !function_exists('kop_network_renames_decisions')) return array();
    $decisions = kop_network_renames_decisions();
    $before = array();
    foreach ($keys as $k) {
        $before[$k] = $decisions[$k] ?? null;
        $decisions[$k] = array('decision' => 'skipped', 'year' => 0, 'swapped' => false, 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'));
    }
    update_option('kop_network_rename_review', $decisions, false);
    return $before;
}

function kop_daudit_unskip_lines(array $before) {
    if (!$before || !function_exists('kop_network_renames_decisions')) return;
    $decisions = kop_network_renames_decisions();
    foreach ($before as $k => $was) {
        if (($decisions[$k]['decision'] ?? '') !== 'skipped') continue;
        if (is_array($was)) $decisions[$k] = $was;
        else unset($decisions[$k]);
    }
    update_option('kop_network_rename_review', $decisions, false);
}

/** The names on the card's records that read as earlier or later names: [fid|name => "Name (on Record #id, past name)"]. */
function kop_daudit_alias_candidates(array $p, array $opts) {
    $out = array();
    foreach (array_slice(kop_daudit_card_records($p), 0, 6) as $fid) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) continue;
        $rec = (string) ($stored['doc']['identification']['name'] ?? ('#' . $fid));
        foreach (kop_daudit_list($stored['doc'], 'identification.pastNames') as $n) {
            if (is_string($n) && trim($n) !== '') $out[$fid . '|' . $n] = $n . ' (past name of ' . $rec . ', #' . $fid . ')';
        }
        $now = trim((string) ($stored['doc']['identification']['currentName'] ?? ''));
        if ($now !== '') $out[$fid . '|' . $now] = $now . ' ("now called" on ' . $rec . ', #' . $fid . ')';
    }
    return $out;
}

/**
 * "Only another name": $pick ("<fid>|<name>") leaves record fid's past names
 * (or "now called") for its other names, and the map's rebrand lines between
 * the record and that name are marked "Not a rename". Returns the message.
 */
function kop_daudit_name_alias($key, $pick, $reviewer) {
    $p = kop_daudit_proposals()[$key] ?? null;
    if (!$p) throw new RuntimeException('That proposal is not in the list any more.');
    $opts = kop_daudit_opts();
    if (!isset(kop_daudit_alias_candidates($p, $opts)[(string) $pick])) throw new RuntimeException('That name is not a past name on this card\'s records any more.');
    list($fid, $value) = explode('|', (string) $pick, 2);
    $fid = (int) $fid;
    $done = array();
    kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $value, $opts, &$done) {
        $stored = kop_facility_load($fid, $opts);
        $doc = $stored['doc'];
        $ops = array(
            array('type' => 'list_remove', 'facility_id' => $fid, 'path' => 'identification.pastNames', 'value' => $value),
            array('type' => 'list_add', 'facility_id' => $fid, 'path' => 'identification.otherNames', 'value' => $value),
        );
        if (strcasecmp(trim((string) ($doc['identification']['currentName'] ?? '')), trim($value)) === 0) {
            $ops[] = array('type' => 'field', 'facility_id' => $fid, 'path' => 'identification.currentName', 'from' => $doc['identification']['currentName'], 'to' => null);
        }
        $done = kop_daudit_doc_apply($doc, $ops, '');
        if ($done) kop_wbf_save($doc, $opts);
    });
    $map = kop_daudit_skip_lines(kop_daudit_alias_lines($fid, $value), $reviewer);
    $decisions = kop_daudit_decisions();
    $decisions[$key]['aliases'][] = array('fid' => $fid, 'value' => $value, 'done' => $done, 'map' => $map,
        'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'));
    kop_daudit_save_decisions($decisions);
    return '"' . $value . '" is now an other name of record #' . $fid . ', not a past name' . ($map ? '; the map\'s rebrand line is marked "Not a rename"' : '')
        . '. "Undo: another name" on this card puts it back.';
}

/** Undo the card's last "Only another name". */
function kop_daudit_name_alias_undo($key) {
    $decisions = kop_daudit_decisions();
    $list = (array) ($decisions[$key]['aliases'] ?? array());
    $n = null;
    foreach ($list as $i => $a) if (empty($a['undone'])) $n = $i;
    if ($n === null) throw new RuntimeException('Nothing to undo.');
    $a = $list[$n];
    $opts = kop_daudit_opts();
    $kept = array();
    kop_v2_with_write_lock($opts['pdo'], function () use ($a, $opts, &$kept) {
        $stored = kop_facility_load((int) $a['fid'], $opts);
        if (!$stored) {
            $kept[] = 'record #' . (int) $a['fid'] . ' (gone)';
            return;
        }
        $doc = $stored['doc'];
        $kept = kop_daudit_doc_undo($doc, (array) $a['done']);
        kop_wbf_save($doc, $opts);
    });
    kop_daudit_unskip_lines((array) $a['map']);
    $decisions[$key]['aliases'][$n]['undone'] = true;
    kop_daudit_save_decisions($decisions);
    return 'Undone: "' . $a['value'] . '" is a past name again' . ($kept ? ' (left as changed since: ' . implode(', ', $kept) . ')' : '') . '.';
}

/**
 * The pairs of records a card could merge as one program with one name only
 * another name of the other: "keep>drop" => words, both ways round, from its
 * renames and its rename-merge steps, while both records are on file.
 */
function kop_daudit_alias_pairs($key, array $p, array $opts) {
    $pairs = array();
    foreach (kop_daudit_renames($p) as $r) $pairs[] = array((int) $r['later'], (int) $r['earlier']);
    foreach (kop_daudit_steps($p) as $op) {
        if ($op['type'] === 'merge' && empty($op['alias'])) $pairs[] = array((int) $op['keep'], (int) $op['drop']);
    }
    $out = array();
    foreach ($pairs as $pr) {
        $a = kop_daudit_rename_side($opts['pdo'], kop_daudit_resolve($pr[0]));
        $b = kop_daudit_rename_side($opts['pdo'], kop_daudit_resolve($pr[1]));
        if (!$a || !$b || $a['id'] === $b['id']) continue;
        foreach (array(array($a, $b), array($b, $a)) as $x) {
            $out[$x[0]['id'] . '>' . $x[1]['id']] = 'Keep ' . $x[0]['name'] . ' (#' . $x[0]['id'] . '); ' . $x[1]['name'] . ' (#' . $x[1]['id'] . ') is another name of it';
        }
    }
    return $out;
}

/**
 * "Just another name": record $drop folds into $keep as one program, its name
 * filed as an other name, never a past one, and the map's rebrand line between
 * them marked "Not a rename" (kop_fmerge_do_merge(..., 'alias')). Undo:
 * kop_daudit_rename_unmerge() with the pair "drop>keep".
 */
function kop_daudit_alias_merge($key, $pick, $reviewer) {
    $p = kop_daudit_proposals()[$key] ?? null;
    if (!$p) throw new RuntimeException('That proposal is not in the list any more.');
    $opts = kop_daudit_opts();
    if (!isset(kop_daudit_alias_pairs($key, $p, $opts)[(string) $pick])) throw new RuntimeException('Those two records are not a pair on this card (or one is merged already).');
    list($keep, $drop) = array_map('intval', explode('>', (string) $pick));
    $done = kop_daudit_merge_apply(array('keep' => $keep, 'drop' => $drop, 'alias' => true), $opts, $reviewer);
    $decisions = kop_daudit_decisions();
    $pair = $drop . '>' . $keep;
    // Filed under the card's rename pair when there is one, so the card reads it as merged.
    foreach (kop_daudit_renames($p) as $k => $r) {
        if (in_array((int) $r['earlier'], array($keep, $drop), true) && in_array((int) $r['later'], array($keep, $drop), true)) $pair = $k;
    }
    $decisions[$key]['merges'][$pair] = array('log' => $done['log'], 'year' => 0, 'alias' => true,
        'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'));
    if (!$p['ops'] && empty($decisions[$key]['decision'])) {
        $decisions[$key] += array('decision' => 'applied', 'by' => (string) $reviewer, 'at' => gmdate('Y-m-d H:i:s'), 'done' => array(), 'by_merge' => true);
    }
    kop_daudit_save_decisions($decisions);
    return $done['message'] . ' Undo is on this card and on the Merged tab of Merge Duplicates.';
}
