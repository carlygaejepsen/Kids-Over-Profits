<?php
/**
 * Program wiki entries brought up to date from KOP (docs/PLAN.md 3.12).
 *
 * Step 1, links: each current wiki entry (one row per Reddit page, the newest)
 * is tied to its facilities_v2 record through wiki_submissions
 * .facility_unique_name. kop_wiki_upd_candidates() finds the records it may be
 * (name, then past/other names, inside the entry's states); the review inbox
 * source inc/review-inbox/wiki-links.php links them, one clear match at a
 * time or all at once, with Undo (option kop_wiki_link_log).
 *
 * Step 2, gaps: kop_wiki_upd_gaps() compares an entry's markdown with
 * kop_facility_page_data() and lists what the record has that the entry does
 * not mention: closure, names, operator, news, lawsuits, deaths, approved
 * serious findings, incidents, staff. Candidates, not verdicts: the drafting
 * pass reads the entry and drops what it already says in other words. A
 * record that contradicts the entry (open vs closed, other years) is a
 * 'conflict', never an update. Public data only: kop_facility_page_data()
 * shows only what a facility page shows; survivor posts (forum) and
 * testimony are left out here. scripts/wiki-gaps.php runs it offline.
 *
 * wiki_submissions and facilities_v2 are read through kop_seed_pdo().
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The records DB. */
function kop_wiki_upd_pdo() {
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO) throw new RuntimeException('The records database is not reachable.');
    return $pdo;
}

/* ---- Entries -------------------------------------------------------------- */

/** wiki_submissions id => Reddit page, from the last live compare (scripts/reddit-wiki-live.py). */
function kop_wiki_upd_pages() {
    static $pages = null;
    if ($pages !== null) return $pages;
    $pages = array();
    $file = get_stylesheet_directory() . '/js/data/reddit-wiki/live-compare.json';
    $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
    foreach ((array) ($data['rows'] ?? array()) as $id => $r) {
        if (!empty($r['page'])) $pages[(int) $id] = strtolower((string) $r['page']);
    }
    return $pages;
}

/** The page an entry came from: the live compare's pairing, else the import note's file name. */
function kop_wiki_upd_page_of(array $row) {
    $pages = kop_wiki_upd_pages();
    if (isset($pages[(int) $row['id']])) return $pages[(int) $row['id']];
    $j = json_decode((string) ($row['json_data'] ?? ''), true);
    if (is_array($j) && !empty($j['sourceSlug'])) return 'index/' . strtolower(trim((string) $j['sourceSlug'], '/'));
    $notes = (string) ($row['submission_notes'] ?? '');
    if (stripos($notes, 'bulk uploaded from file:') === 0) {
        $f = preg_replace('/\.md$/i', '', basename(trim(substr($notes, strlen('bulk uploaded from file:')))));
        $f = rtrim(explode('#', $f, 2)[0], '_');
        $f = strtolower(str_replace('_', '/', $f));
        return strpos($f, '/') === false ? 'index/' . $f : $f;
    }
    return '';
}

/** The entry's text: an imported row's original (Reddit's text), an edited row's generated markdown. */
function kop_wiki_upd_markdown(array $row) {
    $by = strtolower(trim((string) ($row['submitted_by'] ?? '')));
    $notes = strtolower(trim((string) ($row['submission_notes'] ?? '')));
    $import = in_array($by, array('bulk-upload', 'batch-import-script', 'reimport-regenerated', 'import', 'system'), true)
        || strpos($notes, 'batch imported') === 0 || strpos($notes, 'bulk') === 0 || ($by === '' && $notes === '');
    $orig = trim((string) ($row['original_markdown'] ?? ''));
    $gen = trim((string) ($row['generated_markdown'] ?? ''));
    return $import ? ($orig !== '' ? $orig : $gen) : ($gen !== '' ? $gen : $orig);
}

/** 'list' (a state's or a company's list of programs), 'operator' (a company), 'other' (a topic or a person) or 'program'. */
function kop_wiki_upd_kind(array $row, $page) {
    $name = trim((string) ($row['program_name'] ?? ''));
    if (preg_match('/^(active|closed)\b.*\bprograms\b/i', $name) || preg_match('#(^|/)active-programs\b#', $page)) return 'list';
    $j = json_decode((string) ($row['json_data'] ?? ''), true);
    if (is_array($j) && in_array(strtolower((string) ($j['entryType'] ?? '')), array('organization', 'operator', 'company'), true)) return 'operator';
    if (preg_match('/\b(list|programs)\b/i', (string) ($row['program_type'] ?? ''))) return 'operator';
    // Topic and person pages: no years and no place ("Teen Help", "Sue Scheff**-*Florida", the FAQ).
    list($start, $end) = kop_wiki_upd_years($row);
    if ($start === null && $end === null && !kop_wiki_upd_states($row)) return 'other';
    return 'program';
}

/**
 * The current entries: approved/published rows, the newest per Reddit page.
 * id => row + page, kind, markdown, older (ids of the page's older rows).
 */
function kop_wiki_upd_entries(PDO $pdo = null) {
    $pdo = $pdo ?: kop_wiki_upd_pdo();
    $rows = $pdo->query("SELECT id, program_name, city_state, organization, program_type, years_active, json_data,
            original_markdown, generated_markdown, status, submitted_by, submission_notes, updated_at,
            facility_unique_name, facility_link_status
          FROM wiki_submissions WHERE status IN ('approved','published') ORDER BY updated_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $out = array();
    $by_page = array();
    foreach ($rows as $r) {
        $page = kop_wiki_upd_page_of($r);
        $key = $page !== '' ? $page : 'row:' . $r['id'];
        if (isset($by_page[$key])) {
            $out[$by_page[$key]]['older'][] = (int) $r['id'];
            continue;
        }
        $by_page[$key] = (int) $r['id'];
        $r['id'] = (int) $r['id'];
        $r['page'] = $page;
        $r['kind'] = kop_wiki_upd_kind($r, $page);
        $r['older'] = array();
        $out[$r['id']] = $r;
    }
    ksort($out);
    return $out;
}

/** Two-letter states named in an entry's place ("Sandpoint, ID/Heron, MT" -> ID, MT). */
function kop_wiki_upd_states(array $row) {
    $place = (string) ($row['city_state'] ?? '');
    if (!preg_match('/[a-z]/i', $place)) {
        $md = kop_wiki_upd_markdown($row);
        $first = trim((string) strtok($md, "\n"));
        $place = preg_replace('/^#+\s*\*\*.*?\*\*\s*(\([^)]*\))?/u', '', $first);
    }
    $names = function_exists('kop_state_abbrev_to_name') ? kop_state_abbrev_to_name() : array();
    $out = array();
    if (preg_match_all('/(?:,|\/)\s*([A-Z]{2})\b/', $place, $m)) {
        foreach ($m[1] as $s) if (!$names || isset($names[$s])) $out[$s] = true;
    }
    foreach ($names as $code => $name) {
        if (stripos($place, $name) !== false) $out[$code] = true;
    }
    return array_keys($out);
}

/** [start, end] from years_active or the header: end is an int, 'present' or null (not given). */
function kop_wiki_upd_years(array $row, $md = null) {
    $ya = trim((string) ($row['years_active'] ?? ''));
    if ($ya === '') {
        $first = trim((string) strtok($md ?? kop_wiki_upd_markdown($row), "\n"));
        if (preg_match('/\*\*\s*\(([^)]*)\)/u', $first, $m)) $ya = $m[1];
    }
    // The opening year is the one before the dash ("?-2017" has none).
    $start = preg_match('/^[^\d\-–—]*(\d{4})/u', $ya, $m) ? (int) $m[1] : null;
    $end = null;
    if (preg_match('/[-–—]\s*(\d{4})\s*$/u', $ya, $m)) $end = (int) $m[1];
    elseif (preg_match('/present|current|now|open/i', $ya)) $end = 'present';
    return array($start, $end);
}

/* ---- Step 1: links -------------------------------------------------------- */

/** Name key for matching, as the facility store keys names. */
function kop_wiki_upd_key($name) {
    $name = preg_replace('/\s*\([^)]*\)\s*$/u', '', trim((string) $name));
    return function_exists('kop_facility_name_key') ? kop_facility_name_key($name) : trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($name)));
}

/**
 * name key => [[facility id, 'name'|'past'|'other'|'current']], for every
 * facilities_v2 record and its alternate names. Built once per request.
 */
function kop_wiki_upd_name_index(PDO $pdo) {
    static $index = null;
    if ($index !== null) return $index;
    $index = array('keys' => array(), 'records' => array(), 'by_state' => array());
    foreach ($pdo->query('SELECT id, unique_name, name, state, city, status, json_data FROM facilities_v2')->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $id = (int) $f['id'];
        $doc = json_decode((string) $f['json_data'], true);
        $ident = is_array($doc['identification'] ?? null) ? $doc['identification'] : array();
        $index['by_state'][strtoupper((string) $f['state'])][$id] = kop_wiki_upd_tokens($f['name'] ?: $f['unique_name']);
        $index['records'][$id] = array('id' => $id, 'unique_name' => (string) $f['unique_name'], 'name' => (string) ($f['name'] ?: $f['unique_name']),
            'state' => strtoupper((string) $f['state']), 'city' => (string) $f['city'], 'status' => (string) $f['status']);
        $names = array('name' => array($f['name'], $f['unique_name']));
        foreach (array('past' => 'pastNames', 'other' => 'otherNames') as $kind => $field) {
            $list = $ident[$field] ?? array();
            $names[$kind] = is_array($list) ? $list : preg_split('/\s*[;,]\s*/', (string) $list);
        }
        $names['current'] = array($ident['currentName'] ?? '');
        foreach ($names as $kind => $list) {
            foreach ((array) $list as $n) {
                if (!is_string($n)) continue;
                $k = kop_wiki_upd_key($n);
                if ($k === '' || mb_strlen($k) < 4) continue;
                $index['keys'][$k][$id] = $index['keys'][$k][$id] ?? $kind;
            }
        }
    }
    return $index;
}

/** operator name key => operator name, from {prefix}kop_operators. */
function kop_wiki_upd_operator_index(PDO $pdo) {
    static $ops = null;
    if ($ops !== null) return $ops;
    $ops = array();
    $prefix = isset($GLOBALS['wpdb']->prefix) ? $GLOBALS['wpdb']->prefix : 'wpdl_';
    try {
        foreach ($pdo->query("SELECT id, name, json_data FROM {$prefix}kop_operators")->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $names = array($o['name']);
            $doc = json_decode((string) $o['json_data'], true);
            $op = is_array($doc['operator'] ?? null) ? $doc['operator'] : array();
            foreach (array('pastNames', 'previousNames', 'formerNames', 'otherNames', 'aliases') as $f) {
                foreach ((array) ($op[$f] ?? array()) as $n) if (is_string($n)) $names[] = $n;
            }
            foreach ($names as $n) {
                $k = kop_wiki_upd_key($n);
                if ($k !== '' && !isset($ops[$k])) $ops[$k] = (string) $o['name'];
            }
        }
    } catch (Throwable $e) {
        // No operators table on this copy.
    }
    return $ops;
}

/** Names an entry goes by: the program name, its header name, and slash-separated parts ("Lifelines/Teen Lifelines"). */
function kop_wiki_upd_entry_names(array $row) {
    $names = array(trim((string) $row['program_name']));
    $first = trim((string) strtok(kop_wiki_upd_markdown($row), "\n"));
    if (preg_match('/^#+\s*\*\*(.+?)\*\*/u', $first, $m)) $names[] = trim($m[1]);
    foreach ($names as $n) {
        if (strpos($n, '/') !== false) foreach (explode('/', $n) as $part) $names[] = trim($part);
        if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/u', $n, $m)) { $names[] = $m[1]; $names[] = $m[2]; }
    }
    return array_values(array_unique(array_filter($names, function ($n) { return mb_strlen($n) >= 4; })));
}

/**
 * The records an entry may be, best first:
 * [{id, unique_name, name, place, status, reason, score}]. A program entry
 * matches records by name or alternate name; when the entry names its
 * states, only records in them count (a record with no state counts too,
 * lower). An operator entry matches companies: [{unique_name (the company
 * name), name, reason, score, operator: true}].
 */
function kop_wiki_upd_candidates(array $entry, PDO $pdo = null) {
    $pdo = $pdo ?: kop_wiki_upd_pdo();
    $names = kop_wiki_upd_entry_names($entry);
    if ($entry['kind'] === 'operator' || $entry['kind'] === 'other') {
        $ops = kop_wiki_upd_operator_index($pdo);
        $out = array();
        foreach ($names as $n) {
            $k = kop_wiki_upd_key($n);
            if (isset($ops[$k]) && !isset($out[$ops[$k]])) {
                $out[$ops[$k]] = array('id' => 0, 'unique_name' => $ops[$k], 'name' => $ops[$k], 'place' => '', 'status' => '',
                    'reason' => 'company name', 'score' => 100, 'operator' => true);
            }
        }
        if ($out || $entry['kind'] === 'operator') return array_values($out);
    }
    if ($entry['kind'] !== 'program' && $entry['kind'] !== 'other') return array();
    $index = kop_wiki_upd_name_index($pdo);
    if ($entry['kind'] === 'other') {
        // A page with no years or place: only a record of exactly its name.
        $out = array();
        foreach ($names as $n) {
            foreach ($index['keys'][kop_wiki_upd_key($n)] ?? array() as $id => $how) {
                if ($how !== 'name' || isset($out[$id])) continue;
                $rec = $index['records'][$id];
                $out[$id] = array('id' => $id, 'unique_name' => $rec['unique_name'], 'name' => $rec['name'],
                    'place' => trim($rec['city'] . ($rec['city'] !== '' && $rec['state'] !== '' ? ', ' : '') . $rec['state']),
                    'status' => $rec['status'], 'reason' => 'same name (the entry gives no place)', 'score' => 100);
            }
        }
        return array_values($out);
    }
    $states = kop_wiki_upd_states($entry);
    $city_words = strtolower((string) $entry['city_state']);
    $labels = array('name' => 'same name', 'past' => 'a past name of the record', 'other' => 'another name of the record', 'current' => 'the record\'s current name');
    $base = array('name' => 100, 'current' => 90, 'past' => 80, 'other' => 70);
    $out = array();
    foreach ($names as $i => $n) {
        $k = kop_wiki_upd_key($n);
        foreach ($index['keys'][$k] ?? array() as $id => $how) {
            $rec = $index['records'][$id];
            $score = $base[$how] - ($i > 0 ? 5 : 0);
            $where = '';
            if ($states) {
                if ($rec['state'] === '') { $score -= 30; $where = 'record has no state'; }
                elseif (!in_array($rec['state'], $states, true)) continue;
                else $where = 'same state';
            }
            if ($rec['city'] !== '' && $city_words !== '' && strpos($city_words, strtolower($rec['city'])) !== false) { $score += 10; $where = 'same town'; }
            if (!isset($out[$id]) || $out[$id]['score'] < $score) {
                $out[$id] = array('id' => $id, 'unique_name' => $rec['unique_name'], 'name' => $rec['name'],
                    'place' => trim($rec['city'] . ($rec['city'] !== '' && $rec['state'] !== '' ? ', ' : '') . $rec['state']),
                    'status' => $rec['status'], 'reason' => $labels[$how] . ($where !== '' ? ', ' . $where : ''), 'score' => $score);
            }
        }
    }
    // Names that differ: every distinctive word of the entry's name in a record of
    // its states ("Telos RTC" -> Telos Academy, "Spring Creek Lodge" -> Spring Creek
    // Lodge Academy), or one distinctive word shared in the same town.
    if ($states) {
        foreach ($names as $i => $n) {
            $want = array_values(array_diff(kop_wiki_upd_tokens($n), kop_wiki_upd_tokens($entry['city_state'])));
            if (!$want) continue;
            foreach ($states as $st) {
                foreach ($index['by_state'][$st] ?? array() as $id => $tokens) {
                    if (isset($out[$id])) continue;
                    $rec = $index['records'][$id];
                    $town = $rec['city'] !== '' && $city_words !== '' && strpos($city_words, strtolower($rec['city'])) !== false;
                    $tokens = array_diff($tokens, kop_wiki_upd_tokens($rec['city']));
                    $all = !array_diff($want, $tokens);
                    $some = (bool) array_intersect($want, $tokens);
                    if ($all) $score = $town ? 75 : 65;
                    elseif ($some && $town) $score = 55;
                    else continue;
                    $out[$id] = array('id' => $id, 'unique_name' => $rec['unique_name'], 'name' => $rec['name'],
                        'place' => trim($rec['city'] . ($rec['city'] !== '' ? ', ' : '') . $rec['state']), 'status' => $rec['status'],
                        'reason' => ($all ? 'every word of the name' : 'a word of the name') . ($town ? ', same town' : ', same state'), 'score' => $score - ($i > 0 ? 5 : 0));
                }
            }
        }
    }
    uasort($out, function ($a, $b) { return $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']); });
    return array_values($out);
}

/** Words that set a name apart: not generic program words, three letters or more. */
function kop_wiki_upd_tokens($name) {
    static $generic = null;
    if ($generic === null) {
        $generic = array_flip(array('the', 'and', 'for', 'inc', 'llc', 'rtc', 'rtf', 'tbs', 'academy', 'academies', 'school', 'schools',
            'ranch', 'ranches', 'center', 'centre', 'centers', 'program', 'programs', 'camp', 'camps', 'institute', 'residential',
            'treatment', 'therapeutic', 'therapy', 'wilderness', 'behavioral', 'behavioural', 'health', 'healthcare', 'recovery',
            'boarding', 'christian', 'youth', 'teen', 'teens', 'boys', 'girls', 'kids', 'children', 'childrens', 'adolescent',
            'adolescents', 'juvenile', 'home', 'homes', 'house', 'group', 'ministries', 'ministry', 'hospital', 'services',
            'solutions', 'care', 'facility', 'international', 'education', 'educational', 'emotional', 'growth', 'family',
            'families', 'expeditions', 'transitions', 'lodge', 'village', 'mountain', 'valley', 'river', 'creek',
            'springs', 'spring', 'lake', 'canyon', 'ridge', 'pines', 'oaks', 'new', 'north', 'south', 'east', 'west', 'life',
            'learning', 'leadership', 'alternatives', 'alternative', 'baptist', 'girl', 'boy', 'women', 'young', 'adult', 'adults'));
    }
    $out = array();
    foreach (explode(' ', kop_wiki_upd_key($name)) as $t) {
        if (strlen($t) >= 3 && !ctype_digit($t) && !isset($generic[$t])) $out[$t] = true;
    }
    return array_keys($out);
}

/**
 * Where an entry stands: 'linked' (has a link), 'clear' (one candidate, or
 * one same-name candidate clearly ahead), 'choose' (several), 'none', or
 * 'skip' (a list page, or set aside on the review screen).
 */
function kop_wiki_upd_link_state(array $entry, array $cands) {
    if (trim((string) $entry['facility_unique_name']) !== '') return 'linked';
    if ($entry['kind'] === 'list') return 'skip';
    $log = kop_wiki_upd_link_log();
    if (($log[$entry['id']]['decision'] ?? '') === 'skip') return 'skip';
    if (!$cands) return $entry['kind'] === 'other' ? 'skip' : 'none';
    if (count($cands) === 1 && $cands[0]['score'] >= 70) return 'clear';
    if (count($cands) > 1 && $cands[0]['score'] >= 100 && $cands[0]['score'] - $cands[1]['score'] >= 20) return 'clear';
    return 'choose';
}

/** wiki id => {decision: link|skip, name, prev_name, prev_status, by, at}: what the review screen did, for Undo. */
function kop_wiki_upd_link_log() {
    $log = get_option('kop_wiki_link_log', array());
    return is_array($log) ? $log : array();
}

/**
 * Link an entry to a record (or company) name as 'suggested', or set it aside
 * ($unique_name = '' and $decision 'skip'). Logged for Undo.
 */
function kop_wiki_upd_link(PDO $pdo, $wiki_id, $unique_name, $by, $decision = 'link') {
    $wiki_id = (int) $wiki_id;
    $st = $pdo->prepare('SELECT facility_unique_name, facility_link_status FROM wiki_submissions WHERE id = ?');
    $st->execute(array($wiki_id));
    $prev = $st->fetch(PDO::FETCH_ASSOC);
    if (!$prev) throw new RuntimeException('That wiki entry is gone.');
    if ($decision === 'link') {
        if (trim((string) $unique_name) === '') throw new RuntimeException('Pick a record.');
        // updated_at stays as it was: the live compare reads it as "edited here".
        $pdo->prepare("UPDATE wiki_submissions SET facility_unique_name = ?, facility_link_status = 'suggested', updated_at = updated_at WHERE id = ?")
            ->execute(array((string) $unique_name, $wiki_id));
    }
    $log = kop_wiki_upd_link_log();
    $log[$wiki_id] = array('decision' => $decision, 'name' => (string) $unique_name,
        'prev_name' => $prev['facility_unique_name'], 'prev_status' => $prev['facility_link_status'],
        'by' => (string) $by, 'at' => gmdate('c'));
    update_option('kop_wiki_link_log', $log, false);
}

/** Undo the review screen's decision on an entry: the link it had before comes back. */
function kop_wiki_upd_unlink(PDO $pdo, $wiki_id) {
    $wiki_id = (int) $wiki_id;
    $log = kop_wiki_upd_link_log();
    if (!isset($log[$wiki_id])) throw new RuntimeException('Nothing to undo for this entry.');
    $d = $log[$wiki_id];
    if ($d['decision'] === 'link') {
        $pdo->prepare('UPDATE wiki_submissions SET facility_unique_name = ?, facility_link_status = ?, updated_at = updated_at WHERE id = ?')
            ->execute(array($d['prev_name'], $d['prev_status'], $wiki_id));
    }
    unset($log[$wiki_id]);
    update_option('kop_wiki_link_log', $log, false);
}

/** The facilities_v2 id an entry is linked to, or 0 (no link, or a company). */
function kop_wiki_upd_facility_id(array $entry, PDO $pdo = null) {
    $un = trim((string) $entry['facility_unique_name']);
    if ($un === '') return 0;
    $pdo = $pdo ?: kop_wiki_upd_pdo();
    $st = $pdo->prepare('SELECT id FROM facilities_v2 WHERE unique_name = ? ORDER BY id LIMIT 1');
    $st->execute(array($un));
    return (int) $st->fetchColumn();
}

/* ---- Step 2: gaps --------------------------------------------------------- */

/** Text reduced to space-padded words, for "does the entry mention X". */
function kop_wiki_upd_words($text) {
    $t = str_replace('\\', '', (string) $text);
    $t = preg_replace('#https?://\S+#', ' ', $t);
    $t = mb_strtolower($t);
    $t = preg_replace("/['’]/u", '', $t);
    $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t);
    return ' ' . trim($t) . ' ';
}

/** A URL reduced for comparison: no scheme, www, trailing slash, fragment or Reddit's backslashes. */
function kop_wiki_upd_url_key($url) {
    $u = strtolower(trim(str_replace('\\', '', (string) $url)));
    $u = preg_replace('#^https?://(www\.|m\.|amp\.)?#', '', $u);
    $u = preg_replace('/[#].*$/', '', $u);
    $u = preg_replace('/[?&](utm_[a-z]+|fbclid|gclid)=[^&]*/', '', $u);
    return rtrim($u, '/?&');
}

/** Every URL in an entry, as url keys. */
function kop_wiki_upd_url_keys($md) {
    $out = array();
    if (preg_match_all('#https?://[^\s)\]>"]+#i', str_replace('\\', '', (string) $md), $m)) {
        foreach ($m[0] as $u) $out[kop_wiki_upd_url_key(rtrim($u, '.,;'))] = true;
    }
    return $out;
}

/** True when the entry's words contain the phrase's words in order. */
function kop_wiki_upd_mentions($words, $phrase) {
    $p = trim(kop_wiki_upd_words($phrase));
    return $p !== '' && mb_strpos($words, ' ' . $p . ' ') !== false;
}

/** A person's name in the entry, with or without middle names and suffixes ("Alec Sanford Lansing" = "Alec Lansing"). */
function kop_wiki_upd_mentions_person($words, $name) {
    if (kop_wiki_upd_mentions($words, $name)) return true;
    $parts = preg_split('/\s+/', trim(preg_replace('/,?\s+(jr|sr|ii|iii|iv)\.?$/i', '', trim((string) $name))));
    return count($parts) >= 3 && kop_wiki_upd_mentions($words, $parts[0] . ' ' . end($parts));
}

/** The live site's address for a page URL built offline or on a staging host. */
function kop_wiki_upd_live_url($url) {
    return preg_replace('#^https?://[^/]+#', 'https://kidsoverprofits.org', (string) $url);
}

/**
 * What the record has that the entry does not mention. $page is
 * kop_facility_page_data() for the linked record. Each gap:
 * {kind, text (what to add, in a sentence of plain facts), source_url,
 *  source_label, date, conflict (bool), detail (kind-specific)}.
 */
function kop_wiki_upd_gaps(array $entry, array $page, PDO $pdo = null) {
    $md = kop_wiki_upd_markdown($entry);
    $words = kop_wiki_upd_words($md);
    $urls = kop_wiki_upd_url_keys($md);
    $kop_page = kop_wiki_upd_live_url($page['url'] ?? '');
    $gaps = array();
    $add = function ($kind, $text, $url, $label, $date = '', array $detail = array(), $conflict = false) use (&$gaps) {
        $gaps[] = array('kind' => $kind, 'text' => $text, 'source_url' => $url, 'source_label' => $label,
            'date' => (string) $date, 'conflict' => (bool) $conflict, 'detail' => $detail);
    };
    $has_url = function ($url) use ($urls) {
        return $url !== '' && isset($urls[kop_wiki_upd_url_key($url)]);
    };

    // An entry about an earlier name of the record (Integrity House RTC, now
    // Havenwood Academy): it learns the later name, and only what is dated in
    // its own years; the record's status, operator and later items belong to
    // the later name's entry.
    list($w_start, $w_end) = kop_wiki_upd_years($entry, $md);
    $era = kop_wiki_upd_era($entry, $page);
    $until = $era ? ($era['end'] ?: (is_int($w_end) ? $w_end : 0)) : 0;
    $in_era = function ($date) use ($era, $until) {
        if (!$era || !$until) return true;
        return !preg_match('/(\d{4})/', (string) $date, $m) || (int) $m[1] <= $until;
    };
    if ($era && !kop_wiki_upd_mentions($words, $page['name'])) {
        $add('name', 'Later operated as ' . $page['name'] . ($era['next_from'] ? ' (from ' . $era['next_from'] . ')' : '') . '.', $kop_page, 'KOP facility page', '',
            array('name' => $page['name'], 'how' => 'later'));
    }

    // Status and years.
    $status = (string) ($page['status'] ?? '');
    $k_end = (int) ($page['end_year'] ?? 0);
    $closure = kop_wiki_upd_closure_source((int) $page['id'], $pdo);
    if ($era) {
        // Not this name's status.
    } elseif (strcasecmp($status, 'Closed') === 0) {
        if ($w_end === 'present' || $w_end === null) {
            $add('closure', $page['name'] . ' closed' . ($k_end ? ' in ' . $k_end : '') . '.',
                $closure['url'] ?: $kop_page, $closure['label'] ?: 'KOP facility page', $k_end ?: '',
                array('entry_years' => (string) $entry['years_active'], 'end_year' => $k_end, 'quote' => $closure['quote']));
        } elseif ($k_end && $w_end !== $k_end) {
            $add('closure', 'The entry gives ' . $w_end . ' as the closing year; KOP\'s record says ' . $k_end . '.',
                $closure['url'] ?: $kop_page, $closure['label'] ?: 'KOP facility page', $k_end,
                array('entry_years' => (string) $entry['years_active'], 'end_year' => $k_end), true);
        }
    } elseif (strcasecmp($status, 'Open') === 0 && is_int($w_end)) {
        $add('closure', 'The entry says it closed in ' . $w_end . '; KOP\'s record lists it as open.', $kop_page, 'KOP facility page', '',
            array('entry_years' => (string) $entry['years_active'], 'status' => $status), true);
    }

    // Names: past names, other names, the current name, and the eras' years.
    $eras = array();
    foreach ((array) ($page['eras']['list'] ?? array()) as $era) {
        if (is_array($era) && !empty($era['name'])) $eras[kop_wiki_upd_key($era['name'])] = $era;
    }
    $named = array();
    foreach ($era ? array() : array('formerly' => 'Formerly called', 'aka' => 'Also known as') as $field => $lead) {
        foreach ((array) ($page[$field] ?? array()) as $n) {
            $n = is_array($n) ? (string) ($n['name'] ?? '') : (string) $n;
            if ($n === '' || isset($named[kop_wiki_upd_key($n)]) || kop_wiki_upd_mentions($words, $n) || mb_strlen($n) < 4) continue;
            $named[kop_wiki_upd_key($n)] = true;
            $era = $eras[kop_wiki_upd_key($n)] ?? null;
            $years = $era ? trim((string) ($era['years'] ?? '')) : '';
            $add('name', $lead . ' ' . $n . ($years !== '' ? ' (' . $years . ')' : '') . '.', $kop_page, 'KOP facility page', '',
                array('name' => $n, 'how' => $field));
        }
    }
    $current = trim((string) ($page['current_name'] ?? ''));
    if ($current !== '' && !$era && !kop_wiki_upd_mentions($words, $current)) {
        $add('name', 'Now operating as ' . $current . '.', $kop_page, 'KOP facility page', '', array('name' => $current, 'how' => 'current'));
    }

    // Operator.
    $op = trim((string) ($page['operator']['name'] ?? ''));
    $op_name = trim(preg_replace('/^new legal name:\s*/i', '', $op));
    if ($op_name !== '' && !$era) {
        $short = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $op_name));
        $acr = preg_match('/\(([^)]+)\)\s*$/', $op_name, $m) ? $m[1] : '';
        if (!kop_wiki_upd_mentions($words, $short) && ($acr === '' || !kop_wiki_upd_mentions($words, $acr))) {
            $add('operator', 'Operated by ' . $op_name . '.', kop_wiki_upd_live_url($page['operator']['url'] ?? '') ?: $kop_page,
                'KOP company page', '', array('operator' => $op_name));
        }
    }

    // News.
    foreach ((array) ($page['news'] ?? array()) as $n) {
        $url = (string) ($n['url'] ?? '');
        if ($url === '' || $has_url($url) || !$in_era($n['date'] ?? '')) continue;
        if (!empty($n['title']) && mb_strlen($n['title']) > 20 && kop_wiki_upd_mentions($words, $n['title'])) continue;
        $add('news', (string) $n['title'], $url, trim((string) ($n['outlet'] ?? '')), (string) ($n['date'] ?? ''),
            array('summary' => (string) ($n['summary'] ?? ''), 'link_type' => (string) ($n['link_type'] ?? ''), 'type' => (string) ($n['type'] ?? '')));
    }

    // Lawsuits (no addresses of their own: the KOP lawsuits page cites them).
    foreach ((array) ($page['lawsuits'] ?? array()) as $l) {
        if (!$in_era($l['year'] ?? '')) continue;
        $case = trim((string) ($l['case_number'] ?? ''));
        if ($case !== '' && kop_wiki_upd_mentions($words, $case)) continue;
        $title = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', (string) ($l['case_name'] ?? '')));
        $party = trim((string) preg_split('/\s+v\.?\s+/i', $title)[0]);
        $generic = preg_match('/^(a |an |the |second |first |third |two |three |several |\w+ )?(family|families|parents?|students?|former students?|plaintiffs?|survivors?|minor|doe|john doe|jane doe)\b/i', $party);
        if ($party !== '' && !$generic && mb_strlen($party) >= 6 && kop_wiki_upd_mentions($words, $party)) continue;
        $add('lawsuit', (string) ($l['case_name'] ?? ''), kop_wiki_upd_live_url(home_url('/lawsuits/')) . '#lawsuit-' . (int) $l['id'],
            'KOP lawsuits', (string) ($l['year'] ?? ''),
            array('summary' => (string) ($l['summary'] ?? ''), 'status' => (string) ($l['status'] ?? ''), 'outcome' => (string) ($l['outcome'] ?? ''),
                'court' => (string) ($l['court'] ?? ''), 'case_number' => $case, 'mentions_year' => !empty($l['year']) && strpos($words, ' ' . $l['year'] . ' ') !== false));
    }

    // Deaths (the memorial's published entries).
    foreach ((array) ($page['memorials'] ?? array()) as $v) {
        $name = trim((string) ($v['name'] ?? ''));
        if ($name === '' || kop_wiki_upd_mentions_person($words, $name) || !$in_era($v['date_label'] ?? '')) continue;
        $add('death', $name . ($v['date_label'] ?? '' ? ' died ' . $v['date_label'] : '') . ($v['cause'] ?? '' ? ' (' . $v['cause'] . ')' : '') . '.',
            (string) ($v['kop_url'] ?? '') ?: (string) ($v['source_url'] ?? ''), 'KOP memorial', (string) ($v['date_label'] ?? ''),
            array('source_name' => (string) ($v['source_name'] ?? ''), 'source_url' => (string) ($v['source_url'] ?? ''), 'category' => (string) ($v['category'] ?? '')));
    }

    // Approved serious inspection findings.
    foreach ((array) ($page['inspections']['violations'] ?? array()) as $f) {
        $date = (string) ($f['date'] ?? '');
        if (!$in_era($date)) continue;
        $ym = $date !== '' ? strtolower(date('F Y', strtotime($date))) : '';
        if ($ym !== '' && strpos($words, ' ' . $ym . ' ') !== false && strpos($words, ' inspect') !== false) continue;
        $add('finding', (string) ($f['label'] ?? '') . ': ' . (string) ($f['short'] ?? $f['excerpt'] ?? ''),
            kop_wiki_upd_live_url($f['full_url'] ?? ''), 'State inspection report (' . ($f['state'] ?? '') . ')', $date,
            array('category' => (string) ($f['category'] ?? ''), 'state_label' => (string) ($f['state_label'] ?? ''),
                'report_url' => (string) ($f['source_url'] ?? ''), 'weight' => (int) ($f['weight'] ?? 0)));
    }

    // The record's own incidents, when they cite a source the entry lacks.
    foreach ((array) ($page['incidents'] ?? array()) as $i) {
        $url = (string) ($i['url'] ?? '');
        if ($url === '' || $has_url($url) || !$in_era($i['year'] ?? '')) continue;
        $add('incident', trim((string) ($i['when'] ?? '') . ': ' . (string) ($i['text'] ?? ''), ': '), $url,
            (string) ($i['cite'] ?? '') ?: (string) ($i['source'] ?? ''), (string) ($i['year'] ?? ''), array('kind' => (string) ($i['kind'] ?? '')));
    }

    // Staff named on the record and not in the entry.
    foreach ((array) ($page['staff'] ?? array()) as $group => $people) {
        foreach ((array) $people as $p) {
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '' || mb_strlen($name) < 5 || strpos($name, ' ') === false || kop_wiki_upd_mentions_person($words, $name)) continue;
            // Leaders as 'staff'; others only when they worked elsewhere in the industry ('staff_other').
            $lead = (bool) preg_match('/found|owner|director|ceo|president|chief|principal|administrator|headmaster|executive|superintendent|chair/i', (string) ($p['role'] ?? ''));
            if (!$lead && empty($p['career'])) continue;
            $add($lead ? 'staff' : 'staff_other', $name . (!empty($p['role']) ? ' (' . $p['role'] . ')' : ''),
                kop_wiki_upd_live_url((string) ($p['url'] ?? '')) ?: $kop_page, (string) ($p['cite'] ?? '') ?: (string) ($p['source'] ?? '') ?: 'KOP facility page', '',
                array('group' => (string) $group, 'role' => (string) ($p['role'] ?? ''),
                    'other_roles' => array_values(array_filter(array_map(function ($c) { return trim(($c['role'] ?? '') . ', ' . ($c['place'] ?? ''), ', '); }, (array) ($p['career'] ?? array()))))));
        }
    }
    return $gaps;
}

/**
 * When the entry is about an earlier name of the record: {name, end (last
 * year under that name, 0 unknown), next_from (first year of the record's
 * name)}, from the page's name sections when it has them. Null otherwise.
 */
function kop_wiki_upd_era(array $entry, array $page) {
    $own = kop_wiki_upd_key($page['name'] ?? '');
    $past = array();
    foreach ((array) ($page['formerly'] ?? array()) as $n) {
        $n = is_array($n) ? (string) ($n['name'] ?? '') : (string) $n;
        if ($n !== '') $past[kop_wiki_upd_key($n)] = $n;
    }
    $hit = '';
    foreach (kop_wiki_upd_entry_names($entry) as $n) {
        $k = kop_wiki_upd_key($n);
        if ($k === $own) return null;
        if (isset($past[$k])) $hit = $k;
    }
    if ($hit === '') return null;
    $end = 0;
    $next_from = '';
    foreach ((array) ($page['eras']['list'] ?? array()) as $era) {
        $years = (string) ($era['years'] ?? '');
        if (kop_wiki_upd_key($era['name'] ?? '') === $hit && preg_match_all('/\d{4}/', $years, $m)) $end = (int) end($m[0]);
        if (kop_wiki_upd_key($era['name'] ?? '') === $own && preg_match('/\d{4}/', $years, $m)) $next_from = $m[0];
    }
    return array('name' => $past[$hit], 'end' => $end, 'next_from' => $next_from);
}

/** The article that confirmed a closure (facility_closure_reports, applied), if any: {url, label, quote}. */
function kop_wiki_upd_closure_source($facility_id, PDO $pdo = null) {
    $none = array('url' => '', 'label' => '', 'quote' => '');
    try {
        $pdo = $pdo ?: kop_wiki_upd_pdo();
        $st = $pdo->prepare("SELECT r.quote, n.article_url, n.article_title, n.publication_name, n.publication_date
              FROM facility_closure_reports r JOIN news_submissions n ON n.id = r.news_id
             WHERE r.facility_id = ? AND r.status IN ('applied','already') AND r.target_status = 'Closed'
             ORDER BY r.status = 'applied' DESC, n.publication_date DESC LIMIT 1");
        $st->execute(array((int) $facility_id));
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $none;
    }
    if (!$r) return $none;
    return array('url' => (string) $r['article_url'],
        'label' => trim($r['publication_name'] . ($r['publication_date'] ? ', ' . substr($r['publication_date'], 0, 10) : '')),
        'quote' => (string) $r['quote']);
}
